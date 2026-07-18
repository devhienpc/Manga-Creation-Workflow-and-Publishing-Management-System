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
// 2. TỔNG KẾT CHỐT LƯƠNG TRỢ LÝ ĐÃ ĐƯỢC CHUYỂN QUA AJAX API
// ══════════════════════════════════════════════════

// ══════════════════════════════════════════════════
// 3. LOAD NỘI DUNG LAYOUT
// ══════════════════════════════════════════════════
$extraCss     = 'assets/css/editor-ui.css';
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

// Tải tham số tháng/năm lọc lương
$salaryMonth = isset($_GET['salary_month']) ? (int)$_GET['salary_month'] : (int)date('n');
$salaryYear  = isset($_GET['salary_year']) ? (int)$_GET['salary_year'] : (int)date('Y');

// Lấy danh sách preview lương trợ lý cho tháng/năm được chọn
$salaryPreview = [];
try {
    $previewQuery = $db->prepare("
        SELECT 
            u_as.id AS assistant_id,
            u_as.username AS assistant_name,
            u_as.avatar AS assistant_avatar,
            u_ma.id AS mangaka_id,
            u_ma.username AS mangaka_name,
            COUNT(DISTINCT p.id) AS approved_pages,
            COALESCE(MAX(sr.status), 'pending') AS salary_status,
            COALESCE(MAX(sr.gross_amount), 0) AS paid_amount
        FROM tasks t
        JOIN pages p ON t.page_id = p.id
        JOIN chapters c ON p.chapter_id = c.id
        JOIN series s ON c.series_id = s.id
        JOIN users u_as ON t.assigned_to = u_as.id
        JOIN users u_ma ON s.mangaka_id = u_ma.id
        LEFT JOIN salary_records sr ON sr.assistant_id = u_as.id 
            AND sr.mangaka_id = u_ma.id 
            AND sr.month = :sr_month 
            AND sr.year = :sr_year
        WHERE t.status = 'approved'
          AND MONTH(COALESCE(t.approved_at, t.created_at)) = :w_month
          AND YEAR(COALESCE(t.approved_at, t.created_at)) = :w_year
        GROUP BY u_as.id, u_as.username, u_as.avatar, u_ma.id, u_ma.username
        ORDER BY u_as.username ASC
    ");
    $previewQuery->execute([
        ':sr_month' => $salaryMonth,
        ':sr_year'  => $salaryYear,
        ':w_month'  => $salaryMonth,
        ':w_year'   => $salaryYear
    ]);
    $salaryPreview = $previewQuery->fetchAll();
} catch (\Throwable $e) {
    error_log("Lỗi truy vấn preview lương: " . $e->getMessage());
}

$defaultRate = 250000;
try {
    $st = $db->prepare("SELECT value_text FROM settings WHERE key_name = 'default_assistant_rate'");
    $st->execute();
    $val = $st->fetchColumn();
    if ($val !== false) $defaultRate = (float)$val;
} catch (\Throwable $e) {}

$assistantsList = $db->query("SELECT id, username FROM users WHERE role = 'assistant' ORDER BY username ASC")->fetchAll();

$pageStatusLabels = [
    'pending'     => ['Chờ xử lý', 'badge-gray'],
    'in_progress' => ['Đang vẽ',  'badge-blue'],
    'approved'    => ['Đã duyệt',  'badge-green'],
    'revision'    => ['Cần sửa',   'badge-red'],
];

$taskTypeNames = [
    'background' => '<i class="fi fi-rr-picture" style="color:#10b981; margin-right:6px;"></i> Vẽ phông nền (Background)',
    'shading'    => '<i class="fi fi-rr-draw-square" style="color:#3b82f6; margin-right:6px;"></i> Đổ bóng (Shading)',
    'effects'    => '<i class="fi fi-rr-magic-wand" style="color:#8b5cf6; margin-right:6px;"></i> Hiệu ứng (Effects)',
    'lettering'  => '<i class="fi fi-rr-comment-alt" style="color:#f59e0b; margin-right:6px;"></i> Chữ/Thoại (Lettering)',
    'cleanup'    => '<i class="fi fi-rr-paint-brush" style="color:#ef4444; margin-right:6px;"></i> Đi nét (Cleanup)',
];
?>

<!-- Thông báo Flash chốt lương -->
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?> mb-24" data-auto-dismiss="5000">
    <?= $flashType === 'error' ? '?' : '?' ?> <?= $flashMsg ?>
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
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.reload()" style="display:inline-flex; align-items:center; gap:6px;">
                    <i class="fi fi-rr-refresh"></i> Làm mới
                </button>
                <!-- Export to CSV button -->
                <a href="?chapter_id=<?= $chapterId ?>&export=csv" class="btn btn-secondary btn-sm" style="color:#fbbf24; border-color:rgba(251,191,36,.2); display:inline-flex; align-items:center; gap:6px;">
                    <i class="fi fi-rr-download"></i> Xuất báo cáo CSV
                </a>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($chapterId <= 0): ?>
    <div class="card" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
        <i class="fi fi-rr-chart-histogram" style="font-size:3rem; color:var(--text-dim); display:block; margin-bottom:12px;"></i>
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
   PHẦN 3: TÍNH LƯƠNG THÁNG CHO TRỢ LÝ SYSTEM (MONTHLY PAYOUT)
   ══════════════════════════════════════════════════ -->
<div class="card" style="padding: 24px; margin-top: 24px; border: 1px solid var(--border);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom: 20px;">
        <div>
            <p class="card-title" style="font-size:1.1rem; font-weight:700; color:#fbbf24; display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <i class="fi fi-rr-usd-circle" style="color:#fbbf24; margin-right:8px;"></i> Tính Lương Trợ Lý (Salary Payout Engine)
            </p>
            <p class="card-subtitle" style="margin-bottom:0;">Tính lương thực tế dựa trên số trang đã duyệt (Approved) trong tháng.</p>
        </div>
        
        <!-- Bộ lọc Tháng/Năm -->
        <form method="GET" action="" style="display:flex; gap:10px; align-items:center;">
            <!-- Giữ lại bộ lọc cũ của trang nếu có -->
            <?php if ($seriesId > 0): ?><input type="hidden" name="series_id" value="<?= $seriesId ?>"><?php endif; ?>
            <?php if ($chapterId > 0): ?><input type="hidden" name="chapter_id" value="<?= $chapterId ?>"><?php endif; ?>
            
            <select name="salary_month" class="form-control" style="width:130px; padding:6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $salaryMonth ? 'selected' : '' ?>>Tháng <?= sprintf('%02d', $m) ?></option>
                <?php endfor; ?>
            </select>
            <input type="number" name="salary_year" class="form-control" style="width:100px; padding:6px 12px; font-size:0.85rem;" value="<?= $salaryYear ?>" min="2020" onchange="this.form.submit()">
            <button type="submit" class="btn btn-primary" style="padding:6px 14px; font-size:0.8rem; font-weight:700; display:none;">Lọc</button>
        </form>
    </div>

    <!-- Bảng preview chốt lương -->
    <div style="overflow-x:auto; margin:0 -24px; border-top: 1px solid var(--border);">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid var(--border); font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">
                    <th style="padding:12px 24px; text-align:left;">Trợ lý</th>
                    <th style="padding:12px 24px; text-align:left;">Họa sĩ chi trả</th>
                    <th style="padding:12px 24px; text-align:center;">Số trang đã duyệt</th>
                    <th style="padding:12px 24px; text-align:right;">Đơn giá mặc định</th>
                    <th style="padding:12px 24px; text-align:right;">Lương dự kiến</th>
                    <th style="padding:12px 24px; text-align:center;">Trạng thái</th>
                    <th style="padding:12px 24px; text-align:center;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salaryPreview)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:48px; color:var(--text-muted);">
                            <i class="fi fi-rr-envelope-open" style="font-size:2rem; display:block; margin-bottom:10px; opacity:0.35;"></i>
                            Không có nhiệm vụ/trang vẽ nào được duyệt hoàn thành trong tháng <?= sprintf('%02d', $salaryMonth) ?>/<?= $salaryYear ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $hasUnpaid = false;
                    foreach ($salaryPreview as $sp): 
                        $grossAmt = $sp['approved_pages'] * $defaultRate;
                        $isPaid = $sp['salary_status'] === 'paid';
                        if (!$isPaid) $hasUnpaid = true;
                    ?>
                        <tr class="salary-row" 
                            data-assistant="<?= $sp['assistant_id'] ?>" 
                            data-mangaka="<?= $sp['mangaka_id'] ?>"
                            data-status="<?= $sp['salary_status'] ?>"
                            style="border-bottom:1px solid rgba(255,255,255,0.03); font-size:0.85rem;">
                            
                            <!-- Trợ lý -->
                            <td style="padding:12px 24px;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img src="<?= $sp['assistant_avatar'] ? avatarImageUrl($sp['assistant_avatar']) : (BASE_URL . 'assets/images/default-avatar.png') ?>" 
                                         style="width:28px; height:28px; border-radius:50%; object-fit:cover; background:var(--bg-input);">
                                    <span style="font-weight:600; color:#fff;"><?= htmlspecialchars($sp['assistant_name']) ?></span>
                                </div>
                            </td>
                            
                            <!-- Mangaka -->
                            <td style="padding:12px 24px; color:var(--text-muted);">
                                <?= htmlspecialchars($sp['mangaka_name']) ?>
                            </td>
                            
                            <!-- Trang đã duyệt -->
                            <td style="padding:12px 24px; text-align:center; font-weight:700; color:#fff;">
                                <?= $sp['approved_pages'] ?> trang
                            </td>
                            
                            <!-- Đơn giá -->
                            <td style="padding:12px 24px; text-align:right; font-family:'SF Mono',monospace; color:var(--text-muted);">
                                <?= format_money($defaultRate) ?> ₫
                            </td>
                            
                            <!-- Lương dự kiến -->
                            <td style="padding:12px 24px; text-align:right; font-family:'SF Mono',monospace; font-weight:700; color:#10b981;">
                                <?= format_money($grossAmt) ?> ₫
                            </td>
                            
                            <!-- Trạng thái -->
                            <td style="padding:12px 24px; text-align:center;">
                                <?php if ($isPaid): ?>
                                    <span style="background:rgba(16,185,129,0.12); color:#10b981; font-size:0.72rem; font-weight:800; padding:2px 8px; border-radius:4px;">ĐÃ THANH TOÁN</span>
                                <?php elseif ($sp['salary_status'] === 'insufficient_funds'): ?>
                                    <span style="background:rgba(239,68,68,0.12); color:#ef4444; font-size:0.72rem; font-weight:800; padding:2px 8px; border-radius:4px;">MANGAKA THIẾU TIỀN</span>
                                <?php else: ?>
                                    <span style="background:rgba(245,158,11,0.12); color:#f59e0b; font-size:0.72rem; font-weight:800; padding:2px 8px; border-radius:4px;">CHƯA THANH TOÁN</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Thao tác -->
                            <td style="padding:12px 24px; text-align:center;">
                                <?php if ($isPaid): ?>
                                    <button class="btn btn-secondary" style="padding:4px 8px; font-size:0.75rem; opacity:0.5;" disabled>Đã trả</button>
                                <?php else: ?>
                                    <button class="btn btn-primary" 
                                            style="padding:4px 10px; font-size:0.75rem; font-weight:700; background:#fbbf24; border-color:#fbbf24; color:#1a1a2e;"
                                            onclick="paySalary(<?= $sp['assistant_id'] ?>, <?= $sp['mangaka_id'] ?>, <?= $defaultRate ?>, this)">
                                        Tính lương
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($salaryPreview) && $hasUnpaid): ?>
        <div style="margin-top:20px; display:flex; justify-content:flex-end;">
            <button id="btnPayAll" class="btn btn-primary" style="background:#10b981; border-color:#10b981; font-weight:700; display:inline-flex; align-items:center; gap:8px;" onclick="payAllSalaries()">
                <i class="fi fi-rr-bank"></i> Tính lương cho tất cả trợ lý
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Toast chốt lương và Script xử lý -->
<div id="salaryToast" style="position: fixed; top: 24px; right: 24px; z-index: 10000; padding: 14px 24px; border-radius: 8px; font-size: .85rem; font-weight: 700; color: #fff; opacity: 0; transform: translateY(-20px); transition: all .35s ease; pointer-events: none; box-shadow: 0 10px 30px rgba(0,0,0,0.5);"></div>

<script>
function showSalaryToast(msg, type = 'success') {
    const t = document.getElementById('salaryToast');
    t.textContent = msg;
    t.style.background = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#f59e0b');
    t.style.opacity = '1';
    t.style.transform = 'translateY(0)';
    t.style.pointerEvents = 'auto';
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateY(-20px)';
        t.style.pointerEvents = 'none';
    }, 4500);
}

async function paySalary(assistantId, mangakaId, rate, btn) {
    if (!confirm('Xác nhận tính toán và chuyển khoản thanh toán lương cho trợ lý này từ ví họa sĩ?')) return;
    
    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = '⏱...';
    
    try {
        const res = await fetch(BASE_URL + 'api/finance/salary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'calculate',
                assistant_id: assistantId,
                mangaka_id: mangakaId,
                month: <?= $salaryMonth ?>,
                year: <?= $salaryYear ?>,
                rate_per_page: rate
            })
        });
        const json = await res.json();
        
        if (json.success) {
            showSalaryToast(json.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showSalaryToast(json.message || 'Thanh toán thất bại', 'error');
            btn.disabled = false;
            btn.textContent = oldText;
        }
    } catch (e) {
        showSalaryToast('Lỗi kết nối máy chủ', 'error');
        btn.disabled = false;
        btn.textContent = oldText;
    }
}

async function payAllSalaries() {
    const rows = document.querySelectorAll('.salary-row[data-status="pending"], .salary-row[data-status="insufficient_funds"]');
    if (rows.length === 0) {
        showSalaryToast('Không có trợ lý nào cần thanh toán.', 'warning');
        return;
    }
    
    if (!confirm('Xác nhận chốt chuyển khoản lương cho TẤT CẢ trợ lý trong danh sách có nhiệm vụ chưa thanh toán?')) return;
    
    const btn = document.getElementById('btnPayAll');
    btn.disabled = true;
    btn.textContent = 'Đang thanh toán tất cả...';
    
    // Gom nhóm các mangaka_id duy nhất
    const mangakaIds = new Set();
    rows.forEach(r => {
        mangakaIds.add(r.dataset.mangaka);
    });
    
    let paidCount = 0;
    let failedCount = 0;
    
    for (const mId of mangakaIds) {
        try {
            const res = await fetch(BASE_URL + 'api/finance/salary.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'calculate_all',
                    mangaka_id: mId,
                    month: <?= $salaryMonth ?>,
                    year: <?= $salaryYear ?>,
                    rate_per_page: <?= $defaultRate ?>
                })
            });
            const json = await res.json();
            if (json.success) {
                paidCount += json.paid;
                failedCount += json.failed;
            } else {
                failedCount++;
            }
        } catch (e) {
            failedCount++;
        }
    }
    
    showSalaryToast(`Thanh toán hoàn tất! Đã trả lương cho ${paidCount} trợ lý, thất bại ${failedCount}.`, paidCount > 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), 2000);
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
