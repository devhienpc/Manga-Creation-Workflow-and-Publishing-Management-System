<?php
/**
 * editor/progress.php
 * Theo dõi tiến độ sáng tác (Tiến độ Studio) của từng bộ truyện/chương,
 * xuất báo cáo CSV và thực hiện tổng kết chốt lương trợ lý cuối tháng.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Bảo vệ vai trò: Chỉ dành cho Biên tập viên (Editor)
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['EDITOR']) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$db  = getDB();
$uid = getCurrentUser()['id'];

// ══════════════════════════════════════════════════
// 1. XỬ LÝ XUẤT BÁO CÁO CSV (NẾU CÓ ?export=csv)
// ══════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $chapterId = (int)($_GET['chapter_id'] ?? 0);
    
    // Lấy thông tin chapter và series
    $stmt = $db->prepare("
        SELECT c.chapter_number, c.title AS chapter_title, s.title AS series_title
        FROM chapters c
        JOIN series s ON s.id = c.series_id
        WHERE c.id = ?
    ");
    $stmt->execute([$chapterId]);
    $chapterInfo = $stmt->fetch();
    
    if (!$chapterInfo) {
        die('Không tìm thấy chương truyện hợp lệ để xuất báo cáo.');
    }
    
    // Lấy danh sách trang
    $stmt = $db->prepare("
        SELECT id, page_number, status
        FROM pages
        WHERE chapter_id = ?
        ORDER BY page_number ASC
    ");
    $stmt->execute([$chapterId]);
    $pages = $stmt->fetchAll();
    
    // Thiết lập headers tải file CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tien_do_chuong_' . $chapterInfo['chapter_number'] . '_' . date('Ymd_His') . '.csv"');
    
    // Ghi BOM cho Excel tương thích tiếng Việt UTF-8
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['BÁO CÁO TIẾN ĐỘ CHƯƠNG TRUYỆN (STUDIO PROGRESS)']);
    fputcsv($output, ['Bộ truyện', $chapterInfo['series_title']]);
    fputcsv($output, ['Chương truyện', 'Chương ' . $chapterInfo['chapter_number'] . ': ' . $chapterInfo['chapter_title']]);
    fputcsv($output, ['Ngày xuất báo cáo', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, ['Trang số', 'Trạng thái trang', 'Số nhiệm vụ hoàn thành', 'Tổng số nhiệm vụ', 'Danh sách trợ lý tham gia']);
    
    $statusLabels = [
        'pending'     => 'Chờ xử lý',
        'in_progress' => 'Đang vẽ',
        'approved'    => 'Đã duyệt',
        'revision'    => 'Cần sửa lại',
    ];

    foreach ($pages as $p) {
        // Query các nhiệm vụ của trang này
        $tStmt = $db->prepare("
            SELECT t.task_type, t.status, u.username AS assistant_name
            FROM tasks t
            JOIN users u ON u.id = t.assigned_to
            WHERE t.page_id = ?
        ");
        $tStmt->execute([$p['id']]);
        $tasks = $tStmt->fetchAll();
        
        $totalTasks = count($tasks);
        $doneTasks = count(array_filter($tasks, fn($t) => $t['status'] === 'approved'));
        
        $assistants = array_unique(array_column($tasks, 'assistant_name'));
        $assistantsStr = implode(', ', $assistants);
        
        $stText = $statusLabels[$p['status']] ?? $p['status'];
        
        fputcsv($output, [
            'Trang ' . $p['page_number'],
            $stText,
            $doneTasks,
            $totalTasks,
            $assistantsStr ?: 'Chưa giao'
        ]);
    }
    
    fclose($output);
    exit();
}

// ══════════════════════════════════════════════════
// 2. XỬ LÝ POST TỔNG KẾT CHỐT LƯƠNG TRỢ LÝ
// ══════════════════════════════════════════════════
$flashMsg = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize_earnings') {
    $assistantId = (int)($_POST['assistant_id'] ?? 0);
    $month       = (int)($_POST['month'] ?? 0);
    $year        = (int)($_POST['year'] ?? 0);
    $ratePerPage = (float)($_POST['rate_per_page'] ?? 0);
    
    if ($assistantId <= 0 || $month < 1 || $month > 12 || $year < 2000 || $ratePerPage <= 0) {
        $flashMsg = 'Vui lòng nhập đầy đủ thông tin chốt lương hợp lệ.';
        $flashType = 'error';
    } else {
        // Đếm số trang đã hoàn thành ( approved ) của trợ lý này trong tháng/năm đó
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT page_id) 
            FROM tasks 
            WHERE assigned_to = ? 
              AND status = 'approved' 
              AND MONTH(created_at) = ? 
              AND YEAR(created_at) = ?
        ");
        $stmt->execute([$assistantId, $month, $year]);
        $approvedPagesCount = (int)$stmt->fetchColumn();
        
        $totalEarnings = $approvedPagesCount * $ratePerPage;
        
        try {
            $db->beginTransaction();

            // Lưu/Cập nhật thu nhập
            $saveStmt = $db->prepare("
                INSERT INTO earnings (assistant_id, month, year, approved_pages, rate_per_page, total)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    approved_pages = VALUES(approved_pages),
                    rate_per_page = VALUES(rate_per_page),
                    total = VALUES(total)
            ");
            $saveStmt->execute([
                $assistantId,
                $month,
                $year,
                $approvedPagesCount,
                $ratePerPage,
                $totalEarnings
            ]);
            
            // Lấy tên Trợ lý gửi thông báo
            $asStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $asStmt->execute([$assistantId]);
            $assistantName = $asStmt->fetchColumn();
            
            // Gửi thông báo cho trợ lý nhận lương
            $notifMsg = "Ban biên tập đã tổng kết & chốt thanh toán thu nhập Tháng $month/$year của bạn: " . number_format($totalEarnings) . " đ (cho $approvedPagesCount trang vẽ hoàn thành).";
            $notif = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'earnings', ?, 'assistant/earnings.php')");
            $notif->execute([$assistantId, $notifMsg]);

            $db->commit();

            $flashMsg = "Đã hoàn tất chốt lương cho trợ lý <strong>" . htmlspecialchars($assistantName) . "</strong>: " . number_format($totalEarnings) . " đ ($approvedPagesCount trang * " . number_format($ratePerPage) . " đ/trang).";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $db->rollBack();
            $flashMsg = 'Lỗi khi lưu bảng lương: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// ══════════════════════════════════════════════════
// 3. LOAD NỘI DUNG LAYOUT
// ══════════════════════════════════════════════════
require_once __DIR__ . '/../includes/layout.php';

// Các tham số lọc từ GET
$seriesId  = (int)($_GET['series_id'] ?? 0);
$chapterId = (int)($_GET['chapter_id'] ?? 0);

// Load toàn bộ series hệ thống
$seriesList = $db->query("SELECT id, title FROM series ORDER BY title ASC")->fetchAll();

// Load chapters của series được chọn
$chaptersList = [];
if ($seriesId > 0) {
    $cStmt = $db->prepare("SELECT id, chapter_number, title FROM chapters WHERE series_id = ? ORDER BY chapter_number DESC");
    $cStmt->execute([$seriesId]);
    $chaptersList = $cStmt->fetchAll();
}

// Chi tiết tiến độ của chương được chọn
$pages = [];
$totalPages = 0;
$approvedPages = 0;
$breakdown = [
    'background' => ['done' => 0, 'total' => 0],
    'shading'    => ['done' => 0, 'total' => 0],
    'effects'    => ['done' => 0, 'total' => 0],
    'lettering'  => ['done' => 0, 'total' => 0],
    'cleanup'    => ['done' => 0, 'total' => 0],
];

if ($chapterId > 0) {
    // 3.1. Lưới các trang truyện
    $pStmt = $db->prepare("SELECT id, page_number, original_file, composite_file, status FROM pages WHERE chapter_id = ? ORDER BY page_number ASC");
    $pStmt->execute([$chapterId]);
    $pages = $pStmt->fetchAll();
    $totalPages = count($pages);
    $approvedPages = count(array_filter($pages, fn($p) => $p['status'] === 'approved'));

    // 3.2. Bảng phân tách tasks breakdown
    $tStmt = $db->prepare("
        SELECT t.task_type, t.status 
        FROM tasks t
        JOIN pages p ON p.id = t.page_id
        WHERE p.chapter_id = ?
    ");
    $tStmt->execute([$chapterId]);
    $chapterTasks = $tStmt->fetchAll();
    
    foreach ($chapterTasks as $t) {
        $type = $t['task_type'];
        if (isset($breakdown[$type])) {
            $breakdown[$type]['total']++;
            if ($t['status'] === 'approved') {
                $breakdown[$type]['done']++;
            }
        }
    }
}

// Tải danh sách trợ lý để dùng cho form chốt lương
$assistantsList = $db->query("SELECT id, username FROM users WHERE role = 'assistant' ORDER BY username ASC")->fetchAll();

$pageStatusLabels = [
    'pending'     => ['Chờ xử lý', 'badge-gray'],
    'in_progress' => ['Đang vẽ',  'badge-blue'],
    'approved'    => ['Đã duyệt',  'badge-green'],
    'revision'    => ['Cần sửa',   'badge-red'],
];

$taskTypeNames = [
    'background' => '🟢 Vẽ phông nền (Background)',
    'shading'    => '🔵 Đổ bóng (Shading)',
    'effects'    => '🟣 Hiệu ứng (Effects)',
    'lettering'  => '🟡 Chữ/Thoại (Lettering)',
    'cleanup'    => '🔴 Đi nét (Cleanup)',
];
?>

<!-- Thông báo Flash chốt lương -->
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?> mb-24" data-auto-dismiss="5000">
    <?= $flashType === 'error' ? '✕' : '✓' ?> <?= $flashMsg ?>
    <button class="alert-close" style="margin-left:auto; background:none; border:none; color:inherit; cursor:pointer;">×</button>
</div>
<?php endif; ?>

<!-- Thanh lọc chọn Bộ truyện & Chương -->
<div class="card mb-24" style="padding: 16px 20px;">
    <form method="GET" action="" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
        <!-- Chọn bộ truyện -->
        <div style="display:flex; align-items:center; gap:8px;">
            <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Bộ truyện:</label>
            <select name="series_id" class="form-control" style="width:220px; padding: 6px 12px; font-size:0.85rem;" onchange="window.location.href='?series_id='+this.value">
                <option value="">— Chọn bộ truyện —</option>
                <?php foreach ($seriesList as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $seriesId === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Chọn chương truyện -->
        <?php if ($seriesId > 0): ?>
            <div style="display:flex; align-items:center; gap:8px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Chương:</label>
                <select name="chapter_id" class="form-control" style="width:200px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                    <option value="">— Chọn chương —</option>
                    <?php foreach ($chaptersList as $ch): ?>
                        <option value="<?= $ch['id'] ?>" <?= $chapterId === (int)$ch['id'] ? 'selected' : '' ?>>
                            Chương <?= $ch['chapter_number'] ?>: <?= htmlspecialchars($ch['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <?php if ($chapterId > 0): ?>
            <div style="margin-left:auto; display:flex; gap:8px;">
                <!-- Manual refresh button -->
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.reload()">
                    🔄 Làm mới
                </button>
                <!-- Export to CSV button -->
                <a href="?chapter_id=<?= $chapterId ?>&export=csv" class="btn btn-secondary btn-sm" style="color:#fbbf24; border-color:rgba(251,191,36,.2);">
                    📥 Xuất báo cáo CSV
                </a>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($chapterId <= 0): ?>
    <div class="card" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
        <span style="font-size:3rem;">📊</span>
        <p style="margin-top:10px;">Vui lòng chọn bộ truyện và chương truyện ở trên để theo dõi tiến độ chi tiết.</p>
    </div>
<?php else: ?>
    <!-- ══════════════════════════════════════════════════
       CHI TIẾT TIẾN ĐỘ CHƯƠNG TRUYỆN ĐÃ CHỌN
       ══════════════════════════════════════════════════ -->
    <div class="grid-2 gap-24" style="grid-template-columns: 1.3fr 1fr; align-items: start; margin-bottom: 24px;">
        
        <!-- CỘT TRÁI: LƯỚI TRANG TRUYỆN (PAGE GRID) -->
        <div class="card" style="padding: 20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <div>
                    <p class="card-title" style="font-size:1.05rem; font-weight:700;">Lưới Tiến Độ Các Trang</p>
                    <p class="card-subtitle">Trực quan hóa trạng thái từng trang vẽ của chương</p>
                </div>
                <div class="text-xs text-muted" id="refreshTimer">
                    Tự động làm mới trong: 60s
                </div>
            </div>

            <?php if (empty($pages)): ?>
                <div style="text-align:center; padding: 40px 10px; color:var(--text-muted);">
                    Trang chưa được tải lên cho chương này.
                </div>
            <?php else: ?>
                <!-- Grid trang truyện -->
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap:12px;">
                    <?php foreach ($pages as $p):
                        [$pgLabel, $pgClass] = $pageStatusLabels[$p['status']] ?? ['?', 'badge-gray'];
                        // Lấy ảnh gốc hoặc composite hiển thị làm cover
                        $pgCover = $p['composite_file'] ?: $p['original_file'];
                    ?>
                        <div style="aspect-ratio:2/3; border-radius:8px; border: 2px solid var(--border); background:var(--bg-input); overflow:hidden; position:relative; display:flex; flex-direction:column; justify-content:flex-end;">
                            <?php if ($pgCover): ?>
                                <img src="<?= BASE_URL . htmlspecialchars($pgCover) ?>" alt="page" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div style="display:flex; align-items:center; justify-content:center; height:100%; color:var(--text-dim); font-size:1.5rem;">📃</div>
                            <?php endif; ?>
                            
                            <!-- Badges -->
                            <span class="badge <?= $pgClass ?>" style="position:absolute; top:6px; right:6px; font-size:0.6rem; padding:2px 6px; z-index:2;"><?= $pgLabel ?></span>
                            <div style="position:relative; z-index:2; background:rgba(0,0,0,0.65); padding:4px 0; text-align:center; font-size:0.7rem; font-weight:700; width:100%;">
                                Trang <?= $p['page_number'] ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- CỘT PHẢI: PROGRESS BAR & TASKS BREAKDOWN -->
        <div>
            <!-- Chapter Progress Bar Card -->
            <div class="card mb-24" style="padding: 20px;">
                <p class="card-title" style="font-size:1.02rem; font-weight:700; margin-bottom:12px;">Tiến Độ Chương Truyện</p>
                <?php 
                    $pct = $totalPages > 0 ? round(($approvedPages / $totalPages) * 100) : 0;
                ?>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-size:0.85rem;">
                    <span class="font-bold"><?= $approvedPages ?>/<?= $totalPages ?> trang hoàn thành</span>
                    <span style="color:#10b981; font-weight:800;"><?= $pct ?>%</span>
                </div>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar" style="width: <?= $pct ?>%; background:#10b981;"></div>
                </div>
            </div>

            <!-- Tasks Breakdown Card -->
            <div class="card" style="padding:0; overflow:hidden;">
                <div class="card-header" style="padding: 16px 20px; border-bottom:1px solid var(--border)">
                    <p class="card-title" style="font-size:0.98rem; font-weight:700;">Phân Rã Trạng Thái Nhiệm Vụ (Breakdown)</p>
                </div>
                <div class="table-wrap">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border); font-size:0.8rem;">
                                <th style="padding:10px 14px;">Loại công việc</th>
                                <th style="padding:10px 14px; text-align:right;">Hoàn thành / Tổng</th>
                                <th style="padding:10px 14px; text-align:right;">Tiến trình</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($breakdown as $type => $count):
                                $tdPct = $count['total'] > 0 ? round(($count['done'] / $count['total']) * 100) : 0;
                            ?>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03); font-size:0.85rem;">
                                    <td style="padding:10px 14px;" class="font-bold">
                                        <?= $taskTypeNames[$type] ?? $type ?>
                                    </td>
                                    <td style="padding:10px 14px; text-align:right;">
                                        <?= $count['done'] ?> / <?= $count['total'] ?>
                                    </td>
                                    <td style="padding:10px 14px; text-align:right; font-weight:700; color:<?= $tdPct === 100 ? '#10b981' : '#3b82f6' ?>;">
                                        <?= $tdPct ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
   PHẦN 3: FORM CHỐT LƯƠNG TRỢ LÝ CUỐI THÁNG (END OF MONTH)
   ══════════════════════════════════════════════════ -->
<div class="card" style="padding: 24px; max-width: 720px;">
    <p class="card-title" style="font-size:1.05rem; font-weight:700; color:#fbbf24; display:flex; align-items:center; gap:8px;">
        💰 Chốt Lương Trợ Lý Cuối Tháng (End-of-Month Payout)
    </p>
    <p class="card-subtitle mb-16">Tính toán tổng kết số trang đã duyệt và ghi nhận bảng thu nhập chuyển khoản</p>
    
    <form method="POST" action="" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
        <input type="hidden" name="action" value="finalize_earnings">

        <!-- Chọn trợ lý -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Trợ lý nhận lương *</label>
            <select name="assistant_id" class="form-control" required>
                <option value="">— Chọn trợ lý —</option>
                <?php foreach ($assistantsList as $as): ?>
                    <option value="<?= $as['id'] ?>"><?= htmlspecialchars($as['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Đơn giá/Trang -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Đơn giá chốt / trang (VND) *</label>
            <input type="number" name="rate_per_page" class="form-control" placeholder="Ví dụ: 300000" min="1000" required>
        </div>

        <!-- Chọn Tháng -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Tháng chốt *</label>
            <select name="month" class="form-control" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>>Tháng <?= sprintf('%02d', $m) ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Chọn Năm -->
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Năm chốt *</label>
            <input type="number" name="year" class="form-control" value="<?= date('Y') ?>" min="2020" required>
        </div>

        <button type="submit" class="btn btn-primary" style="grid-column: span 2; margin-top:10px;">
            🏦 Tổng Kết & Chốt Lương
        </button>
    </form>
</div>

<!-- JS tự động refresh mỗi 60 giây -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    let timeLeft = 60;
    const timerEl = document.getElementById('refreshTimer');
    
    if (timerEl) {
        const interval = setInterval(() => {
            timeLeft--;
            timerEl.textContent = `Tự động làm mới trong: ${timeLeft}s`;
            if (timeLeft <= 0) {
                clearInterval(interval);
                window.location.reload();
            }
        }, 1000);
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
