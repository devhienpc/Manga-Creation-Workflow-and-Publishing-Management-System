<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Yêu cầu đăng nhập & kiểm tra quyền trước khi xử lý POST
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['MANGAKA']) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$db  = getDB();
$currentUser = getCurrentUser();
$uid = $currentUser['id'];

/* ════════════════════════════════════════════════
   HANDLE POST ACTIONS
   ════════════════════════════════════════════════ */
$flashMsg   = '';
$flashType  = 'success';
$action     = $_POST['action'] ?? '';

/* ── Helper: safe file upload ── */
function uploadFile(
    string $fieldName,
    string $destDir,
    array  $allowedMime,
    int    $maxBytes = 10_000_000
): array {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'err' => 'Chưa chọn file.', 'path' => null];
    }
    $f = $_FILES[$fieldName];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'err' => 'Lỗi upload: code ' . $f['error'], 'path' => null];
    }
    if ($f['size'] > $maxBytes) {
        return ['ok' => false, 'err' => 'File vượt quá giới hạn ' . round($maxBytes / 1_048_576, 1) . ' MB.', 'path' => null];
    }
    // Kiểm tra MIME thực sự bằng finfo (không tin $_FILES['type'])
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        return ['ok' => false, 'err' => "Loại file không được phép ($mime).", 'path' => null];
    }
    // Lấy extension từ MIME
    $extMap = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];
    $ext      = $extMap[$mime] ?? 'bin';
    $filename = uniqid('mg_', true) . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    if (!is_dir(dirname($destPath))) {
        mkdir(dirname($destPath), 0755, true);
    }
    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
        return ['ok' => false, 'err' => 'Không thể lưu file.', 'path' => null];
    }
    return ['ok' => true, 'err' => null, 'path' => $filename];
}

/* ── Action: Create new series ── */
if ($action === 'create_series' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title']    ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $genres   = $_POST['genre']         ?? [];
    $schedule = $_POST['publish_schedule'] ?? 'weekly';
    $genres   = is_array($genres) ? $genres : [];
    $genreStr = implode(', ', array_map('htmlspecialchars', $genres));

    if (empty($title)) {
        $flashMsg  = 'Vui lòng nhập tên bộ truyện.';
        $flashType = 'error';
    } else {
        // Upload cover
        $coverPath = null;
        $up = uploadFile(
            'cover_image',
            UPLOAD_PATH . 'covers',
            ['image/jpeg', 'image/png', 'image/webp'],
            5_000_000
        );
        if (!$up['ok'] && isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $flashMsg  = 'Lỗi ảnh bìa: ' . $up['err'];
            $flashType = 'error';
        } else {
            $coverPath = $up['path'] ? 'covers/' . $up['path'] : null;

            $stmt = $db->prepare(
                "INSERT INTO series (mangaka_id, title, description, genre, cover_image, publish_schedule, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())"
            );
            $stmt->execute([$uid, $title, $desc, $genreStr, $coverPath, $schedule]);
            $newId    = $db->lastInsertId();
            $flashMsg = "Bộ truyện <strong>" . htmlspecialchars($title) . "</strong> đã được tạo thành công!";

            // Auto-redirect to detail
            header('Location: ' . BASE_URL . 'mangaka/series.php?detail=' . $newId . '&flash=created');
            exit();
        }
    }
}

/* ── Action: Create new chapter ── */
if ($action === 'create_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $seriesId      = (int) ($_POST['series_id'] ?? 0);
    $chapterNumber = (int) ($_POST['chapter_number'] ?? 0);
    $chapterTitle  = trim($_POST['title'] ?? '');
    $deadline      = $_POST['deadline'] ?? null;
    if (empty($deadline)) $deadline = null;

    // Verify series belongs to this mangaka
    $check = $db->prepare("SELECT id FROM series WHERE id = ? AND mangaka_id = ?");
    $check->execute([$seriesId, $uid]);
    if (!$check->fetch()) {
        $flashMsg  = 'Bộ truyện không hợp lệ.';
        $flashType = 'error';
    } elseif ($chapterNumber <= 0 || empty($chapterTitle)) {
        $flashMsg  = 'Vui lòng nhập đầy đủ số chương và tên chương.';
        $flashType = 'error';
    } else {
        // Check if chapter number already exists for this series
        $chkNum = $db->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
        $chkNum->execute([$seriesId, $chapterNumber]);
        if ($chkNum->fetch()) {
            $flashMsg  = "Chương số $chapterNumber đã tồn tại trong bộ truyện này.";
            $flashType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Insert chapter
                $insCh = $db->prepare("INSERT INTO chapters (series_id, chapter_number, title, status, deadline, created_at) VALUES (?, ?, ?, 'planning', ?, NOW())");
                $insCh->execute([$seriesId, $chapterNumber, $chapterTitle, $deadline]);
                $chapterId = (int) $db->lastInsertId();

                // Auto-create 3 placeholder pages for this chapter to enable immediate workflow testing
                $insPg = $db->prepare("INSERT INTO pages (chapter_id, page_number, original_file, composite_file, status) VALUES (?, ?, NULL, NULL, 'pending')");
                for ($p = 1; $p <= 3; $p++) {
                    $insPg->execute([$chapterId, $p]);
                }

                $db->commit();
                $flashMsg  = "Đã tạo thành công Chương $chapterNumber: $chapterTitle (kèm 3 trang vẽ nháp).";
                $flashType = 'success';
            } catch (\Throwable $e) {
                $db->rollBack();
                $flashMsg  = 'Lỗi khi tạo chương mới: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

/* ── Action: Submit manuscript ── */
if ($action === 'submit_manuscript' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $seriesId  = (int) ($_POST['series_id']  ?? 0);
    $chapterId = (int) ($_POST['chapter_id'] ?? 0);
    $notes     = trim($_POST['notes']         ?? '');

    // Verify series belongs to this mangaka
    $check = $db->prepare("SELECT id FROM series WHERE id = ? AND mangaka_id = ?");
    $check->execute([$seriesId, $uid]);
    if (!$check->fetch()) {
        $flashMsg  = 'Bộ truyện không hợp lệ.';
        $flashType = 'error';
    } elseif ($chapterId <= 0) {
        $flashMsg  = 'Vui lòng chọn chương tương ứng cho bản thảo.';
        $flashType = 'error';
    } else {
        $up = uploadFile(
            'manuscript_file',
            UPLOAD_PATH . 'manuscripts',
            ['application/pdf', 'application/zip', 'application/x-zip-compressed'],
            50_000_000
        );
        if (!$up['ok']) {
            $flashMsg  = 'Lỗi upload bản thảo: ' . $up['err'];
            $flashType = 'error';
        } else {
            $filePath = 'manuscripts/' . $up['path'];

            // Get latest version number for this series+chapter
            $vStmt = $db->prepare(
                "SELECT COALESCE(MAX(version), 0) + 1 AS next_version
                 FROM manuscripts WHERE series_id = ? AND chapter_id = ?"
            );
            $vStmt->execute([$seriesId, $chapterId]);
            $nextVer = (int) $vStmt->fetchColumn();
            if ($nextVer < 1) $nextVer = 1;

            $db->beginTransaction();
            try {
                // Insert manuscript
                $mStmt = $db->prepare(
                    "INSERT INTO manuscripts (series_id, chapter_id, file_path, version, submitted_by, status, submitted_at)
                     VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
                );
                $mStmt->execute([
                    $seriesId,
                    $chapterId,
                    $filePath,
                    $nextVer,
                    $uid
                ]);
                $manuscriptId = (int) $db->lastInsertId();

                // Insert submission to board
                $sStmt = $db->prepare(
                    "INSERT INTO submissions (series_id, manuscript_id, submitted_by, status, board_notes, submitted_at)
                     VALUES (?, ?, ?, 'pending', ?, NOW())"
                );
                $sStmt->execute([$seriesId, $manuscriptId, $uid, $notes]);

                // Update series status to 'submitted' if currently draft
                $db->prepare(
                    "UPDATE series SET status = 'submitted' WHERE id = ? AND status = 'draft'"
                )->execute([$seriesId]);

                $db->commit();
                $flashMsg = 'Bản thảo đã được nộp thành công! Ban biên tập sẽ xem xét sớm.';
            } catch (\Throwable $e) {
                $db->rollBack();
                // Remove uploaded file if DB failed
                @unlink(UPLOAD_PATH . 'manuscripts/' . $up['path']);
                $flashMsg  = 'Lỗi khi lưu bản thảo: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

// Render layout giao diện (sau khi xử lý xong các hành động POST / Redirect)
$pageTitle    = 'Bộ truyện của tôi';
$activePage   = 'series';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

/* ════════════════════════════════════════════════
   GET DATA
   ════════════════════════════════════════════════ */
// Flash from redirect
if (isset($_GET['flash'])) {
    if ($_GET['flash'] === 'created' && empty($flashMsg)) {
        $flashMsg = 'Bộ truyện mới đã được tạo thành công!';
    }
}

// Filter & sort params
$filterStatus = $_GET['status'] ?? '';
$sortBy       = in_array($_GET['sort'] ?? '', ['created_at', 'title', 'status']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$detailId     = (int) ($_GET['detail'] ?? 0);

// Build series query
$whereParts = ['s.mangaka_id = ?'];
$params     = [$uid];
if ($filterStatus && in_array($filterStatus, ['draft', 'submitted', 'approved', 'publishing', 'cancelled'])) {
    $whereParts[] = 's.status = ?';
    $params[]     = $filterStatus;
}
$where = implode(' AND ', $whereParts);

$seriesList = $db->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM chapters c WHERE c.series_id = s.id) AS chapter_count,
            (SELECT rank_position FROM votes v
             WHERE v.series_id = s.id
             ORDER BY v.created_at DESC LIMIT 1) AS latest_rank,
            (SELECT reader_votes FROM votes v2
             WHERE v2.series_id = s.id
             ORDER BY v2.created_at DESC LIMIT 1) AS latest_votes
     FROM series s
     WHERE $where
     ORDER BY s.$sortBy $sortDir"
);
$seriesList->execute($params);
$series = $seriesList->fetchAll();

// Detail: chapters for selected series
$detailSeries   = null;
$detailChapters = [];
if ($detailId > 0) {
    $dStmt = $db->prepare("SELECT * FROM series WHERE id = ? AND mangaka_id = ?");
    $dStmt->execute([$detailId, $uid]);
    $detailSeries = $dStmt->fetch();

    if ($detailSeries) {
        $cStmt = $db->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM pages p WHERE p.chapter_id = c.id) AS total_pages,
                    (SELECT COUNT(*) FROM pages p2 WHERE p2.chapter_id = c.id AND p2.status = 'approved') AS approved_pages
             FROM chapters c
             WHERE c.series_id = ?
             ORDER BY c.chapter_number ASC"
        );
        $cStmt->execute([$detailId]);
        $detailChapters = $cStmt->fetchAll();
    }
}

// Chapters for submission modal (dropdown)
$allChapters = $db->prepare(
    "SELECT c.id, c.chapter_number, c.title, s.id AS series_id, s.title AS series_title
     FROM chapters c
     JOIN series s ON s.id = c.series_id
     WHERE s.mangaka_id = ?
       AND c.status NOT IN ('published')
     ORDER BY s.title, c.chapter_number"
);
$allChapters->execute([$uid]);
$chaptersForModal = $allChapters->fetchAll();

/* ── Helpers ── */
$statusConfig = [
    'draft'      => ['Bản nháp',      'badge-gray',   '📝'],
    'submitted'  => ['Đã nộp',        'badge-blue',   '📤'],
    'approved'   => ['Đã duyệt',      'badge-purple', '✅'],
    'publishing' => ['Đang xuất bản', 'badge-green',  '🔥'],
    'cancelled'  => ['Đã hủy',        'badge-red',    '✕'],
];

$chapterStatusConfig = [
    'planning'    => ['Lên kế hoạch', 'badge-gray',   0],
    'in_progress' => ['Đang vẽ',      'badge-blue',   40],
    'review'      => ['Chờ duyệt',    'badge-yellow', 80],
    'approved'    => ['Đã duyệt',     'badge-purple', 95],
    'published'   => ['Đã xuất bản',  'badge-green',  100],
];

$genreOptions = [
    'Action','Adventure','Comedy','Drama','Fantasy','Horror',
    'Isekai','Mystery','Romance','Sci-Fi','Seinen','Shonen',
    'Shoujo','Slice of Life','Sports','Supernatural','Thriller',
];

$scheduleLabels = ['weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng'];

// Sort toggle helper
function sortUrl(string $field): string {
    $cur = $_GET['sort'] ?? 'created_at';
    $dir = $field === $cur ? (($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc') : 'desc';
    $params = array_merge($_GET, ['sort' => $field, 'dir' => $dir]);
    unset($params['detail']);
    return '?' . http_build_query($params);
}
?>

<?php if (!empty($flashMsg)): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?> mb-16" data-auto-dismiss="5000">
    <?= $flashType === 'error' ? '✕' : '✓' ?> <?= $flashMsg ?>
    <button class="alert-close" style="margin-left:auto;background:none;border:none;color:inherit;cursor:pointer;font-size:1.1rem">×</button>
</div>
<?php endif; ?>

<!-- ── Toolbar ── -->
<div class="card mb-24" style="padding:16px 20px;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">

        <!-- Status filter -->
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1">
            <?php if ($sortBy): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>"><?php endif; ?>
            <label style="font-size:.8rem;font-weight:600;color:var(--text-muted);white-space:nowrap">Lọc:</label>
            <?php
            $filterOptions = ['' => 'Tất cả', 'draft' => 'Nháp', 'submitted' => 'Đã nộp', 'approved' => 'Đã duyệt', 'publishing' => 'Đang đăng', 'cancelled' => 'Đã hủy'];
            foreach ($filterOptions as $val => $label):
                $isActive = $filterStatus === $val;
            ?>
                <a href="?status=<?= urlencode($val) ?>&sort=<?= urlencode($sortBy) ?>&dir=<?= urlencode($sortDir === 'ASC' ? 'asc' : 'desc') ?>"
                   class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-secondary' ?>"
                   style="<?= $isActive ? '' : 'font-weight:500' ?>">
                    <?= $label ?>
                    <?php if ($val): ?>
                        <span style="margin-left:3px;opacity:.7;font-size:.7rem">(<?= count(array_filter($series, fn($s) => $s['status'] === $val)) ?>)</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </form>

        <button onclick="document.getElementById('createModal').classList.add('open')"
                class="btn btn-primary" style="white-space:nowrap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tạo bộ truyện mới
        </button>

        <button onclick="document.getElementById('submitModal').classList.add('open')"
                class="btn btn-secondary" style="white-space:nowrap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Nộp bản thảo
        </button>
    </div>
</div>

<!-- ── Two-column layout: list + detail ── -->
<div style="display:grid;grid-template-columns:<?= $detailSeries ? '1fr 1.1fr' : '1fr' ?>;gap:20px;align-items:start;">

    <!-- Series list -->
    <div>
        <?php if (empty($series)): ?>
        <div class="card" style="text-align:center;padding:60px 20px;">
            <div style="font-size:3rem;margin-bottom:16px">📚</div>
            <p style="font-size:1.1rem;font-weight:700;margin-bottom:8px">Chưa có bộ truyện nào</p>
            <p class="text-muted text-sm">Hãy bắt đầu sáng tác hành trình của bạn!</p>
            <button onclick="document.getElementById('createModal').classList.add('open')"
                    class="btn btn-primary" style="margin:20px auto 0;max-width:200px">
                Tạo bộ truyện đầu tiên
            </button>
        </div>

        <?php else: ?>

        <!-- Sort bar -->
        <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
            <span class="text-xs text-muted" style="font-weight:600;">Sắp xếp:</span>
            <a href="<?= sortUrl('created_at') ?>" class="btn btn-sm btn-ghost text-xs <?= $sortBy === 'created_at' ? 'btn-secondary' : '' ?>">Mới nhất</a>
            <a href="<?= sortUrl('title') ?>"      class="btn btn-sm btn-ghost text-xs <?= $sortBy === 'title'      ? 'btn-secondary' : '' ?>">Tên A-Z</a>
            <a href="<?= sortUrl('status') ?>"     class="btn btn-sm btn-ghost text-xs <?= $sortBy === 'status'     ? 'btn-secondary' : '' ?>">Trạng thái</a>
            <span class="text-xs text-muted ml-auto"><?= count($series) ?> bộ truyện</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($series as $s):
                [$stLabel, $stClass, $stIcon] = $statusConfig[$s['status']] ?? ['?', 'badge-gray', '?'];
                $isSelected = ($detailId === (int)$s['id']);
                $coverUrl   = $s['cover_image']
                    ? BASE_URL . 'assets/uploads/' . $s['cover_image']
                    : null;
            ?>
            <div class="card" style="padding:16px;cursor:pointer;<?= $isSelected ? 'border-color:var(--red);box-shadow:0 0 0 1px var(--red)' : '' ?>"
                 onclick="window.location='?<?= http_build_query(array_merge($_GET, ['detail' => $s['id']])) ?>'">

                <div style="display:flex;gap:14px;align-items:flex-start">
                    <!-- Cover thumbnail -->
                    <div style="width:56px;height:78px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--bg-input);border:1px solid var(--border)">
                        <?php if ($coverUrl): ?>
                            <img src="<?= htmlspecialchars($coverUrl) ?>" alt="cover"
                                 style="width:100%;height:100%;object-fit:cover">
                        <?php else: ?>
                            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.5rem">📖</div>
                        <?php endif; ?>
                    </div>

                    <!-- Info -->
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
                            <h3 style="font-size:.95rem;font-weight:700;margin:0"><?= htmlspecialchars($s['title']) ?></h3>
                            <span class="badge <?= $stClass ?>"><?= $stIcon ?> <?= $stLabel ?></span>
                        </div>
                        <p class="text-xs text-muted truncate" style="margin-bottom:8px"><?= htmlspecialchars($s['genre'] ?: 'Chưa phân loại') ?></p>

                        <div style="display:flex;gap:16px;flex-wrap:wrap">
                            <span class="text-xs text-muted">
                                <strong style="color:var(--text)"><?= $s['chapter_count'] ?></strong> chương
                            </span>
                            <span class="text-xs text-muted">
                                <?= htmlspecialchars($scheduleLabels[$s['publish_schedule']] ?? $s['publish_schedule']) ?>
                            </span>
                            <?php if ($s['latest_rank']): ?>
                            <span class="text-xs" style="color:#f59e0b">
                                🏆 #<?= $s['latest_rank'] ?>
                                <?php if ($s['latest_votes']): ?>
                                    <span class="text-muted">(<?= number_format($s['latest_votes']) ?> phiếu)</span>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            <span class="text-xs text-muted ml-auto">
                                <?= date('d/m/Y', strtotime($s['created_at'])) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Arrow -->
                    <div style="color:var(--text-dim);flex-shrink:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Series Detail Panel ── -->
    <?php if ($detailSeries): ?>
    <div>
        <div class="card" style="position:sticky;top:calc(var(--header-h) + 20px)">
            <!-- Header with close -->
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px">
                <?php
                $coverUrl = $detailSeries['cover_image']
                    ? BASE_URL . 'assets/uploads/' . $detailSeries['cover_image']
                    : null;
                [$stLabel, $stClass] = $statusConfig[$detailSeries['status']] ?? ['?', 'badge-gray'];
                ?>
                <div style="width:70px;height:100px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--bg-input);border:1px solid var(--border)">
                    <?php if ($coverUrl): ?>
                        <img src="<?= htmlspecialchars($coverUrl) ?>" alt="cover" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2rem">📖</div>
                    <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                    <h2 style="font-size:1.1rem;font-weight:800;margin-bottom:6px;line-height:1.3">
                        <?= htmlspecialchars($detailSeries['title']) ?>
                    </h2>
                    <span class="badge <?= $stClass ?> mb-8"><?= $stLabel ?></span>
                    <p class="text-xs text-muted"><?= htmlspecialchars($detailSeries['genre'] ?: 'Chưa phân loại') ?></p>
                    <?php if ($detailSeries['description']): ?>
                        <p class="text-sm text-muted" style="margin-top:8px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
                            <?= htmlspecialchars($detailSeries['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['detail' => null]), fn($v) => $v !== null)) ?>"
                   style="color:var(--text-dim);flex-shrink:0" title="Đóng">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </a>
            </div>

            <!-- Stats row -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
                <div style="background:var(--bg-input);border-radius:8px;padding:12px;text-align:center">
                    <p style="font-size:1.4rem;font-weight:800"><?= count($detailChapters) ?></p>
                    <p class="text-xs text-muted">Chương</p>
                </div>
                <div style="background:var(--bg-input);border-radius:8px;padding:12px;text-align:center">
                    <?php $publishedCount = count(array_filter($detailChapters, fn($c) => $c['status'] === 'published')); ?>
                    <p style="font-size:1.4rem;font-weight:800;color:var(--green)"><?= $publishedCount ?></p>
                    <p class="text-xs text-muted">Đã đăng</p>
                </div>
                <div style="background:var(--bg-input);border-radius:8px;padding:12px;text-align:center">
                    <p style="font-size:1.4rem;font-weight:800;color:#f59e0b">
                        <?= htmlspecialchars($scheduleLabels[$detailSeries['publish_schedule']] ?? '?') ?>
                    </p>
                    <p class="text-xs text-muted">Lịch đăng</p>
                </div>
            </div>

            <div class="divider" style="margin:16px 0"></div>

            <!-- Chapter timeline -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                <p style="font-size:.9rem;font-weight:700">Danh sách chương</p>
                <span class="badge badge-gray"><?= count($detailChapters) ?> chương</span>
            </div>

            <?php if (empty($detailChapters)): ?>
                <p class="text-sm text-muted" style="text-align:center;padding:20px">Chưa có chương nào.</p>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;max-height:380px;overflow-y:auto;padding-right:4px">
                <?php foreach ($detailChapters as $ch):
                    [$chLabel, $chClass, $progress] = $chapterStatusConfig[$ch['status']] ?? ['?','badge-gray',0];
                    $totalPages   = (int)$ch['total_pages'];
                    $approvedPages= (int)$ch['approved_pages'];
                    $pct          = $totalPages > 0 ? round($approvedPages / $totalPages * 100) : $progress;

                    // Override progress with page completion if data exists
                    if ($totalPages > 0) $pct = round($approvedPages / $totalPages * 100);
                    else $pct = $progress;
                ?>
                <div style="padding:12px;background:var(--bg-input);border-radius:8px;border:1px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px">
                        <div style="min-width:0">
                            <span style="font-size:.8rem;font-weight:700">Ch.<?= $ch['chapter_number'] ?></span>
                            <span class="text-xs text-muted" style="margin-left:6px"><?= htmlspecialchars($ch['title']) ?></span>
                        </div>
                        <span class="badge <?= $chClass ?>" style="flex-shrink:0"><?= $chLabel ?></span>
                    </div>

                    <!-- Progress bar -->
                    <div class="progress" style="height:4px">
                        <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:4px">
                        <span class="text-xs text-muted">
                            <?php if ($totalPages > 0): ?>
                                <?= $approvedPages ?>/<?= $totalPages ?> trang
                            <?php else: ?>
                                Chưa phân trang
                            <?php endif; ?>
                        </span>
                        <span class="text-xs text-muted">
                            <?php if ($ch['deadline']): ?>
                                <?= date('d/m', strtotime($ch['deadline'])) ?>
                            <?php else: ?>
                                Chưa có deadline
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                <button onclick="openCreateChapterModal(<?= $detailSeries['id'] ?>)"
                        class="btn btn-secondary btn-sm" style="flex:1">
                    ➕ Thêm chương
                </button>
                <button onclick="openSubmitModal(<?= $detailSeries['id'] ?>)"
                        class="btn btn-primary btn-sm" style="flex:1">
                    📤 Nộp bản thảo
                </button>
                <a href="<?= BASE_URL ?>mangaka/tasks.php?series=<?= $detailSeries['id'] ?>"
                   class="btn btn-secondary btn-sm" style="flex:1">
                    🎨 Giao task trợ lý
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════
     MODAL: Tạo bộ truyện mới
     ════════════════════════════════════════════════ -->
<div id="createModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:580px">
        <div class="modal-header">
            <h2>📚 Tạo bộ truyện mới</h2>
            <button onclick="document.getElementById('createModal').classList.remove('open')" class="modal-close">×</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="createSeriesForm">
            <input type="hidden" name="action" value="create_series">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tên bộ truyện <span style="color:var(--red)">*</span></label>
                    <input type="text" name="title" class="form-control" required maxlength="150"
                           placeholder="Nhập tên bộ truyện...">
                </div>

                <div class="form-group">
                    <label class="form-label">Mô tả / Nội dung tóm tắt</label>
                    <textarea name="description" class="form-control" rows="3"
                              style="resize:vertical"
                              placeholder="Giới thiệu ngắn về nội dung bộ truyện..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Thể loại (chọn nhiều)</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:8px">
                        <?php foreach ($genreOptions as $g): ?>
                        <label style="cursor:pointer">
                            <input type="checkbox" name="genre[]" value="<?= htmlspecialchars($g) ?>"
                                   style="display:none" class="genre-cb">
                            <span class="genre-chip"><?= htmlspecialchars($g) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Lịch đăng</label>
                        <select name="publish_schedule" class="form-control">
                            <option value="weekly">📅 Hàng tuần</option>
                            <option value="monthly">🗓️ Hàng tháng</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Ảnh bìa (JPG/PNG/WebP, ≤5MB)</label>
                        <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp"
                               class="form-control" id="coverInput" onchange="previewCover(this)">
                    </div>
                </div>

                <!-- Cover preview -->
                <div id="coverPreview" style="display:none;margin-top:12px;text-align:center">
                    <img id="coverImg" src="" alt="preview"
                         style="max-height:120px;border-radius:8px;border:1px solid var(--border)">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('createModal').classList.remove('open')"
                        class="btn btn-secondary">Hủy</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tạo bộ truyện
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════
     MODAL: Tạo chương mới
     ════════════════════════════════════════════════ -->
<div id="createChapterModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <h2>➕ Tạo chương mới</h2>
            <button onclick="document.getElementById('createChapterModal').classList.remove('open')" class="modal-close">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="create_chapter">
            <input type="hidden" name="series_id" id="chapterModalSeriesId" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Số chương *</label>
                    <input type="number" name="chapter_number" class="form-control" min="1" required placeholder="Ví dụ: 1, 2, 3...">
                </div>

                <div class="form-group">
                    <label class="form-label">Tên chương *</label>
                    <input type="text" name="title" class="form-control" required placeholder="Ví dụ: Khởi đầu cuộc hành trình...">
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Hạn chót hoàn thành (Deadline)</label>
                    <input type="date" name="deadline" class="form-control">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('createChapterModal').classList.remove('open')"
                        class="btn btn-secondary">Hủy</button>
                <button type="submit" class="btn btn-primary">
                    💾 Tạo chương
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════
     MODAL: Nộp bản thảo
     ════════════════════════════════════════════════ -->
<div id="submitModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:520px">
        <div class="modal-header">
            <h2>📤 Nộp bản thảo cho Ban biên tập</h2>
            <button onclick="document.getElementById('submitModal').classList.remove('open')" class="modal-close">×</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_manuscript">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Bộ truyện <span style="color:var(--red)">*</span></label>
                    <select name="series_id" class="form-control" id="submitSeriesSelect" onchange="filterChapters(this.value)" required>
                        <option value="">— Chọn bộ truyện —</option>
                        <?php foreach ($series as $s):
                            if (in_array($s['status'], ['cancelled'])) continue;
                        ?>
                            <option value="<?= $s['id'] ?>"
                                <?= $detailId === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Chương tương ứng <span style="color:var(--red)">*</span></label>
                    <select name="chapter_id" class="form-control" id="submitChapterSelect" required>
                        <option value="">— Chọn chương truyện —</option>
                        <?php foreach ($chaptersForModal as $ch): ?>
                            <option value="<?= $ch['id'] ?>" data-series="<?= $ch['series_id'] ?>">
                                <?= htmlspecialchars($ch['series_title']) ?> — Chương <?= $ch['chapter_number'] ?>: <?= htmlspecialchars($ch['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">File bản thảo (PDF/ZIP, ≤50MB) <span style="color:var(--red)">*</span></label>
                    <input type="file" name="manuscript_file" accept=".pdf,.zip" class="form-control" required>
                    <p class="text-xs text-muted" style="margin-top:6px">Chấp nhận file PDF hoặc ZIP chứa ảnh trang truyện.</p>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Ghi chú cho Ban biên tập</label>
                    <textarea name="notes" class="form-control" rows="3" style="resize:vertical"
                              placeholder="Ví dụ: Chương này thử nghiệm phong cách vẽ mới, mong hội đồng chú ý phần cảnh nền..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('submitModal').classList.remove('open')"
                        class="btn btn-secondary">Hủy</button>
                <button type="submit" class="btn btn-primary">
                    📤 Nộp bản thảo
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════
     MODAL STYLES + JS
     ════════════════════════════════════════════════ -->
<style>
.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(4px);
    z-index: 500;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-backdrop.open {
    display: flex;
    animation: fadeIn .15s ease;
}
@keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }

.modal-box {
    width: 100%;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 24px 80px rgba(0,0,0,.6);
    animation: slideUp .2s ease;
    overflow: hidden;
}
@keyframes slideUp { from { opacity:0;transform:translateY(20px) } to { opacity:1;transform:translateY(0) } }

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
}
.modal-header h2 { font-size: 1rem; font-weight: 700; margin: 0; }
.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 1.4rem;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 6px;
    transition: color .15s, background .15s;
}
.modal-close:hover { color: var(--red); background: var(--red-subtle); }

.modal-body  { padding: 20px 24px; max-height: 65vh; overflow-y: auto; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Genre chips */
.genre-chip {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: .75rem;
    font-weight: 600;
    background: rgba(255,255,255,.05);
    border: 1px solid var(--border);
    color: var(--text-muted);
    cursor: pointer;
    transition: all .15s;
    user-select: none;
}
.genre-cb:checked + .genre-chip {
    background: rgba(230,57,70,.15);
    border-color: rgba(230,57,70,.4);
    color: var(--red);
}
</style>

<script>
// Open create chapter modal
function openCreateChapterModal(seriesId) {
    document.getElementById('chapterModalSeriesId').value = seriesId;
    document.getElementById('createChapterModal').classList.add('open');
}

// Open submit modal and pre-select series
function openSubmitModal(seriesId) {
    document.getElementById('submitModal').classList.add('open');
    if (seriesId) {
        const sel = document.getElementById('submitSeriesSelect');
        if (sel) { sel.value = seriesId; filterChapters(seriesId); }
    }
}

// Filter chapter dropdown by selected series
function filterChapters(seriesId) {
    const opts = document.querySelectorAll('#submitChapterSelect option[data-series]');
    opts.forEach(opt => {
        opt.hidden = seriesId && opt.dataset.series !== String(seriesId);
    });
    document.getElementById('submitChapterSelect').value = '';
}

// Cover image preview
function previewCover(input) {
    const preview = document.getElementById('coverPreview');
    const img     = document.getElementById('coverImg');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.style.display = 'none';
    }
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', e => {
        if (e.target === backdrop) backdrop.classList.remove('open');
    });
});

// ESC to close modals
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    }
});

// Open create modal if flash=created with detail
<?php if (isset($_GET['flash']) && $_GET['flash'] === 'created'): ?>
// Flash from redirect — do nothing, flash message already shown
<?php endif; ?>

// Auto-open submit if ?submit=1
<?php if (isset($_GET['submit'])): ?>
document.addEventListener('DOMContentLoaded', () => openSubmitModal(<?= (int)($_GET['series'] ?? 0) ?>));
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
