<?php
/**
 * mangaka/manuscripts.php
 * Trình xem ghi chú bản thảo dành cho Họa sĩ (Mangaka) - Chỉ đọc.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Ghi chú bản thảo';
$activePage   = 'series';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

$flashMsg = '';
$flashType = 'success';

// Nhận ID bản thảo được chọn từ tham số GET để mở Annotation View
$selectedManuscriptId = (int)($_GET['manuscript_id'] ?? 0);

/* ══════════════════════════════════════════════════
   1. DANH SÁCH BỘ TRUYỆN ĐỂ ĐIỀN VÀO FILTER
   ══════════════════════════════════════════════════ */
$seriesList = $db->prepare("SELECT id, title FROM series WHERE mangaka_id = ? ORDER BY title ASC");
$seriesList->execute([$uid]);
$seriesList = $seriesList->fetchAll();

/* ══════════════════════════════════════════════════
   2. TRUY VẤN BẢN THẢO (DANH SÁCH CHÍNH VỚI BỘ LỌC)
   ══════════════════════════════════════════════════ */
$filterSeries = (int)($_GET['filter_series'] ?? 0);
$filterStatus = $_GET['filter_status'] ?? '';

$mQuery = "
    SELECT m.id, m.version, m.status, m.file_path, m.submitted_at, 
           c.chapter_number, c.title AS chapter_title, c.id AS chapter_id,
           s.title AS series_title, s.id AS series_id,
           u.username AS mangaka_name
    FROM manuscripts m
    JOIN chapters c ON c.id = m.chapter_id
    JOIN series s ON s.id = m.series_id
    JOIN users u ON u.id = m.submitted_by
    WHERE s.mangaka_id = ?
";
$params = [$uid];

if ($filterSeries > 0) {
    $mQuery .= " AND m.series_id = ?";
    $params[] = $filterSeries;
}
if ($filterStatus !== '') {
    $mQuery .= " AND m.status = ?";
    $params[] = $filterStatus;
}
$mQuery .= " ORDER BY m.submitted_at DESC";

$stmt = $db->prepare($mQuery);
$stmt->execute($params);
$manuscripts = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   3. CHI TIẾT BẢN THẢO & TRANG TRUYỆN ĐƯỢC CHỌN DUYỆT
   ══════════════════════════════════════════════════ */
$manuscriptDetail = null;
$pages = [];
$selectedPageId = (int)($_GET['page_id'] ?? 0);

if ($selectedManuscriptId > 0) {
    $dStmt = $db->prepare(
        "SELECT m.id, m.version, m.status, m.file_path, m.chapter_id, m.series_id,
                c.chapter_number, c.title AS chapter_title,
                s.title AS series_title
         FROM manuscripts m
         JOIN chapters c ON c.id = m.chapter_id
         JOIN series s ON s.id = m.series_id
         WHERE m.id = ?"
    );
    $dStmt->execute([$selectedManuscriptId]);
    $manuscriptDetail = $dStmt->fetch();

    if ($manuscriptDetail && $manuscriptDetail['chapter_id']) {
        // Lấy danh sách trang của chương này
        $pStmt = $db->prepare(
            "SELECT id, page_number, original_file, composite_file, status 
             FROM pages 
             WHERE chapter_id = ? 
             ORDER BY page_number ASC"
        );
        $pStmt->execute([$manuscriptDetail['chapter_id']]);
        $pages = $pStmt->fetchAll();

        // Mặc định chọn trang đầu tiên nếu chưa chọn trang cụ thể
        if ($selectedPageId <= 0 && !empty($pages)) {
            $selectedPageId = (int)$pages[0]['id'];
        }
    }
}

// Lấy danh sách annotations của trang đang được chọn duyệt
$annotations = [];
if ($selectedManuscriptId > 0 && $selectedPageId > 0) {
    $aStmt = $db->prepare(
        "SELECT a.id, a.x_pos, a.y_pos, a.width, a.height, a.comment, a.status,
                u.username AS editor_name, a.created_at
         FROM annotations a
         JOIN users u ON u.id = a.editor_id
         WHERE a.manuscript_id = ? AND a.page_id = ?
         ORDER BY a.created_at ASC"
    );
    $aStmt->execute([$selectedManuscriptId, $selectedPageId]);
    $annotations = $aStmt->fetchAll();
}

$manuscriptStatusLabels = [
    'pending'   => ['Chờ duyệt',  'badge-gray'],
    'reviewing' => ['Đang duyệt', 'badge-blue'],
    'approved'  => ['Đã duyệt',   'badge-green'],
    'rejected'  => ['Từ chối',    'badge-red'],
];

$annotationStatusLabels = [
    'open'     => ['Cần sửa', 'badge-yellow'],
    'resolved' => ['Đã sửa',  'badge-green'],
];
?>

<style>
/* Style Layout Đọc Duyệt */
.manuscript-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 22px;
    align-items: start;
}
.manuscript-left { min-width: 0; }
.manuscript-right { position: sticky; top: calc(var(--header-h) + 20px); }

/* Khung ảnh canvas đọc duyệt */
.canvas-wrapper {
    position: relative;
    display: inline-block;
    max-width: 100%;
    user-select: none;
    cursor: crosshair;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--border);
    background: #05050c;
    width: 100%;
}
.canvas-wrapper img {
    display: block;
    width: 100%;
    height: auto;
    pointer-events: none;
}
.canvas-overlay {
    position: absolute;
    inset: 0;
}
.annotation-box {
    position: absolute;
    border: 2px solid #fbbf24;
    background: rgba(251, 191, 36, 0.12);
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s;
}
.annotation-box:hover {
    background: rgba(251, 191, 36, 0.25);
    box-shadow: 0 0 10px rgba(251, 191, 36, 0.4);
}
.annotation-box.resolved {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.05);
    opacity: 0.55;
    cursor: default;
}
.annotation-box.resolved:hover {
    background: rgba(16, 185, 129, 0.1);
    box-shadow: none;
}
.annotation-num {
    position: absolute;
    top: -10px; left: -10px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #fbbf24;
    color: #0b0b16;
    font-size: 0.72rem;
    font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.annotation-box.resolved .annotation-num {
    background: #10b981;
    color: #fff;
}
.draw-box {
    position: absolute;
    border: 2px dashed #fbbf24;
    background: rgba(251, 191, 36, 0.08);
    border-radius: 4px;
    pointer-events: none;
}

/* Thống kê / Danh sách chú thích */
.annotation-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.annotation-card {
    padding: 12px 14px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.85rem;
    position: relative;
    transition: border-color 0.2s;
}
.annotation-card:hover { border-color: rgba(251, 191, 36, 0.3); }
.annotation-card.resolved { opacity: 0.65; border-color: rgba(255,255,255,0.03); }

/* Vòng lặp thumbnails trang */
.page-thumb-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(75px, 1fr));
    gap: 8px;
    margin-bottom: 20px;
}
.page-thumb-item {
    aspect-ratio: 2/3;
    border-radius: 6px;
    border: 2px solid var(--border);
    background: var(--bg-input);
    cursor: pointer;
    overflow: hidden;
    position: relative;
    transition: all 0.15s;
    display: flex; flex-direction: column; justify-content: flex-end;
}
.page-thumb-item img {
    position: absolute; inset:0; width:100%; height:100%; object-fit:cover;
}
.page-thumb-item:hover { border-color: rgba(251,191,36,.4); }
.page-thumb-item.active { border-color: #fbbf24; box-shadow: 0 0 8px rgba(251,191,36,0.3); }
.page-thumb-label {
    position: relative; z-index:1;
    background: rgba(0,0,0,.6); font-size:0.65rem; text-align:center;
    padding: 2px 0; font-weight:700; width:100%;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Xem ghi chú bản thảo</span>
    </div>
    <h1>Xem Ghi Chú Bản Thảo</h1>
    <p>Xem các lỗi cần sửa và ghi chú từ Biên tập viên trên các trang truyện của bạn.</p>
</div>



<?php if ($manuscriptDetail === null): ?>
    <!-- ══════════════════════════════════════════════════
       PHẦN 1: DANH SÁCH BẢN THẢO (KHI CHƯA CHỌN CHI TIẾT)
       ══════════════════════════════════════════════════ -->
    <!-- Thanh lọc -->
    <div class="card mb-24" style="padding: 16px 20px;">
        <form method="GET" action="" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Bộ truyện:</label>
                <select name="filter_series" class="form-control" style="width:200px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                    <option value="">— Tất cả —</option>
                    <?php foreach ($seriesList as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filterSeries === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Trạng thái:</label>
                <select name="filter_status" class="form-control" style="width:160px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                    <option value="">— Tất cả —</option>
                    <?php foreach ($manuscriptStatusLabels as $val => [$lbl,]): ?>
                        <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($filterSeries > 0 || $filterStatus !== ''): ?>
                <a href="manuscripts.php" class="btn btn-secondary btn-sm" style="margin-left:auto;">Xóa bộ lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bảng danh sách bản thảo -->
    <div class="card" style="padding:0; overflow:hidden;">
        <?php if (empty($manuscripts)): ?>
            <div style="text-align:center; padding: 60px 20px; color:var(--text-muted);">
                <span style="font-size:3rem;">📖</span>
                <p style="margin-top:10px;">Không tìm thấy bản thảo nào phù hợp.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                            <th style="padding:14px 18px;">Tác phẩm / Chương</th>
                            <th style="padding:14px 18px;">Họa sĩ nộp</th>
                            <th style="padding:14px 18px; text-align:center;">Phiên bản</th>
                            <th style="padding:14px 18px;">Ngày nộp</th>
                            <th style="padding:14px 18px;">Trạng thái</th>
                            <th style="padding:14px 18px; text-align:right;">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manuscripts as $m):
                            [$stLabel, $stClass] = $manuscriptStatusLabels[$m['status']] ?? ['?', 'badge-gray'];
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                <td style="padding:14px 18px;">
                                    <div class="font-bold" style="font-size:0.9rem;"><?= htmlspecialchars($m['series_title']) ?></div>
                                    <div class="text-xs text-muted">Chương <?= $pm['chapter_number'] ?? $m['chapter_number'] ?> · <?= htmlspecialchars($m['chapter_title']) ?></div>
                                </td>
                                <td style="padding:14px 18px; font-size:0.85rem;">
                                    <?= htmlspecialchars($m['mangaka_name']) ?>
                                </td>
                                <td style="padding:14px 18px; text-align:center;">
                                    <span class="badge badge-gray" style="font-size:0.72rem; font-weight:700;">v<?= $m['version'] ?></span>
                                </td>
                                <td style="padding:14px 18px; font-size:0.82rem; color:var(--text-muted);">
                                    <?= date('d/m/Y H:i', strtotime($m['submitted_at'])) ?>
                                </td>
                                <td style="padding:14px 18px;">
                                    <span class="badge <?= $stClass ?>" style="font-size:0.75rem; padding: 4px 10px;"><?= $stLabel ?></span>
                                </td>
                                <td style="padding:14px 18px; text-align:right;">
                                    <a href="?manuscript_id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Xem đọc duyệt</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ══════════════════════════════════════════════════
       PHẦN 2: ANNOTATION VIEW (KHI ĐÃ CHỌN CHI TIẾT BẢN THẢO)
       ══════════════════════════════════════════════════ -->
    <div class="manuscript-layout">
        
        <!-- LEFT COLUMN: CANVAS TRÌNH BÀY TRANG -->
        <div class="manuscript-left">
            <div class="card" style="padding: 20px;">
                <!-- Header thông tin chương -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 16px; gap:12px; flex-wrap:wrap;">
                    <div>
                        <a href="manuscripts.php" class="link" style="font-size:0.8rem; font-weight:700; margin-bottom:4px; display:inline-block;">
                            ← Quay lại danh sách
                        </a>
                        <h2 style="font-size:1.1rem; font-weight:800; color:#fff;">
                            <?= htmlspecialchars($manuscriptDetail['series_title']) ?>
                        </h2>
                        <p class="text-xs text-muted" style="margin-top:2px;">
                            Chương <?= $manuscriptDetail['chapter_number'] ?>: <?= htmlspecialchars($manuscriptDetail['chapter_title']) ?> (Bản thảo v<?= $manuscriptDetail['version'] ?>)
                        </p>
                    </div>
                    
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <?php 
                        $mgkPageUrls = [];
                        if (!empty($pages)) {
                            foreach ($pages as $pg) {
                                if (!empty($pg['original_file'])) {
                                    $mgkPageUrls[] = normalizeFilePath($pg['original_file']);
                                }
                            }
                        } elseif (!empty($manuscriptDetail['file_path'])) {
                            $fp = $manuscriptDetail['file_path'];
                            if (strpos($fp, '[') === 0) {
                                $decoded = json_decode($fp, true);
                                if (is_array($decoded)) $mgkPageUrls = normalizePageUrls($decoded);
                            } else {
                                $mgkPageUrls[] = normalizeFilePath($fp);
                            }
                        }
                        $encodedMgkUrls = htmlspecialchars(json_encode($mgkPageUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $mgkTitleStr = htmlspecialchars($manuscriptDetail['series_title'] . ' - Chương ' . $manuscriptDetail['chapter_number'], ENT_QUOTES, 'UTF-8');
                        $mgkZipStr = htmlspecialchars('BanThao_Chuong' . $manuscriptDetail['chapter_number'], ENT_QUOTES, 'UTF-8');
                        ?>
                        
                        <?php if (!empty($mgkPageUrls)): ?>
                            <button onclick="openWebtoonReader('<?= $encodedMgkUrls ?>', '<?= $mgkTitleStr ?>', '<?= $mgkZipStr ?>')" class="btn btn-primary btn-sm" style="background:linear-gradient(135deg, #6366f1, #8b5cf6); border:none; box-shadow: 0 4px 12px rgba(99,102,241,0.3);">
                                📖 Xem Bản Thảo (Webtoon)
                            </button>
                            <button onclick="downloadZip('<?= $encodedMgkUrls ?>', '<?= $mgkZipStr ?>')" class="btn btn-secondary btn-sm">
                                📥 Tải file bản thảo (ZIP)
                            </button>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(manuscriptUrl($manuscriptDetail['file_path'])) ?>" download class="btn btn-secondary btn-sm">
                                📥 Tải file bản thảo
                            </a>
                        <?php endif; ?>

                        <?php if ($manuscriptDetail['status'] === 'rejected'): ?>
                            <button onclick="document.getElementById('defenseModal').classList.add('open')" class="btn btn-primary btn-sm">
                                🛡️ Gửi giải trình
                            </button>
                            
                            <!-- Modal Đơn Giải Trình -->
                            <div class="modal-backdrop" id="defenseModal">
                                <div class="modal-box" style="max-width: 480px;">
                                    <div class="modal-header">
                                        <h3>Bảo vệ tác phẩm</h3>
                                        <button type="button" class="modal-close" onclick="document.getElementById('defenseModal').classList.remove('open')">×</button>
                                    </div>
                                    <form action="<?= BASE_URL ?>mangaka/defense.php" method="POST" style="margin:0">
                                        <input type="hidden" name="action" value="create_defense">
                                        <input type="hidden" name="chapter_id" value="<?= $manuscriptDetail['chapter_id'] ?>">
                                        <input type="hidden" name="manuscript_id" value="<?= $manuscriptDetail['id'] ?>">
                                        <div class="modal-body">
                                            <div class="form-group">
                                                <label class="form-label">Lý do giải trình *</label>
                                                <textarea name="reason" class="form-control" rows="4" 
                                                          placeholder="Nhập lý do tại sao bạn cho rằng bản thảo này không nên bị từ chối..." required></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('defenseModal').classList.remove('open')">Hủy</button>
                                            <button type="submit" class="btn btn-primary">Gửi đơn</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Selector Trang Thumbnails -->
                <?php if (empty($pages)): ?>
                    <div class="alert alert-warning">
                        Chương truyện này chưa có trang nào được họa sĩ upload lên hệ thống. Không thể tạo ghi chú trên trang.
                    </div>
                <?php else: ?>
                    <div class="page-thumb-grid">
                        <?php foreach ($pages as $pg):
                            $isPageActive = ($pg['id'] == $selectedPageId);
                            $pgCover = $pg['original_file'] ? BASE_URL . $pg['original_file'] : null;
                        ?>
                            <div class="page-thumb-item <?= $isPageActive ? 'active' : '' ?>"
                                 onclick="window.location.href='?manuscript_id=<?= $selectedManuscriptId ?>&page_id=<?= $pg['id'] ?>'">
                                <?php if ($pgCover): ?>
                                    <img src="<?= htmlspecialchars($pgCover) ?>" alt="p<?= $pg['page_number'] ?>" loading="lazy">
                                <?php endif; ?>
                                <span class="page-thumb-label">Trang <?= $pg['page_number'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Canvas Vẽ Annotation -->
                    <?php 
                        $activePageObj = array_values(array_filter($pages, fn($p) => $p['id'] == $selectedPageId))[0] ?? null;
                    ?>
                    <?php if ($activePageObj && $activePageObj['original_file']): ?>
                        <div class="canvas-wrapper" id="canvasWrapper">
                            <img id="annotationImage" src="<?= BASE_URL . htmlspecialchars($activePageObj['original_file']) ?>" alt="Trang duyệt">
                            
                            <div class="canvas-overlay" id="canvasOverlay">
                                
                                <!-- Render danh sách Annotations hiện có -->
                                <?php 
                                $num = 1;
                                foreach ($annotations as $ann): 
                                    $isResolved = ($ann['status'] === 'resolved');
                                ?>
                                    <div class="annotation-box <?= $isResolved ? 'resolved' : '' ?>" 
                                         style="left: <?= $ann['x_pos'] ?>%; top: <?= $ann['y_pos'] ?>%; width: <?= $ann['width'] ?>%; height: <?= $ann['height'] ?>%;"
                                         title="Nhận xét: <?= htmlspecialchars($ann['comment']) ?> (Bởi: <?= htmlspecialchars($ann['editor_name']) ?>)">
                                        <span class="annotation-num"><?= $num++ ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">Trang này chưa có ảnh gốc tải lên.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: SIDEBAR DANH SÁCH CHÚ THÍCH -->
        <div class="manuscript-right">
            <!-- Thống kê Annotations hiện có -->
            <div class="card">
                <div class="card-header" style="padding:0; margin-bottom:16px;">
                    <p class="card-title" style="font-size:0.95rem; font-weight:700;">Ghi Chú Đọc Duyệt Trang</p>
                    <p class="card-subtitle">Chi tiết các lỗi và nhận xét từ Biên tập viên</p>
                </div>

                <?php if (empty($annotations)): ?>
                    <div style="text-align:center; padding: 40px 10px; color:var(--text-muted); font-size:0.85rem;" id="emptyAnnotations">
                        ✨ Trang này chưa có ghi chú chỉnh sửa nào.
                    </div>
                <?php else: ?>
                    <div class="annotation-list" id="annotationList">
                        <?php 
                        $num = 1;
                        foreach ($annotations as $ann): 
                            $isResolved = ($ann['status'] === 'resolved');
                        ?>
                            <div class="annotation-card <?= $isResolved ? 'resolved' : '' ?>" id="card-ann-<?= $ann['id'] ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <span class="badge <?= $isResolved ? 'badge-green' : 'badge-yellow' ?>" style="font-size:0.65rem; padding:2px 6px;">
                                        #<?= $num++ ?> · <?= $isResolved ? 'Đã sửa' : 'Cần sửa' ?>
                                    </span>
                                    <span class="text-xs text-muted"><?= htmlspecialchars($ann['editor_name']) ?></span>
                                </div>
                                <p style="line-height:1.5; color:#fff; word-break:break-word; font-size:0.85rem;">
                                    <?= htmlspecialchars($ann['comment']) ?>
                                </p>
                                
                                
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
