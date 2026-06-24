<?php
/**
 * assistant/dashboard.php
 * Trang Dashboard dành riêng cho Trợ lý Manga (Assistant).
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Dashboard Trợ Lý';
$activePage   = 'dashboard';
$allowedRoles = [ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

// Lấy thông tin thời gian hiện tại
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');

/* ══════════════════════════════════════════════════
   1. ĐẾM SỐ TASKS ĐANG PENDING & IN_PROGRESS
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM tasks 
     WHERE assigned_to = ? AND status IN ('pending', 'in_progress', 'revision')"
);
$stmt->execute([$uid]);
$activeTasksCount = (int)$stmt->fetchColumn();

/* ══════════════════════════════════════════════════
   2. ĐẾM SỐ TRANG ĐÃ HOÀN THÀNH TRONG THÁNG NÀY
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT COUNT(DISTINCT page_id) FROM tasks 
     WHERE assigned_to = ? 
       AND status = 'approved' 
       AND MONTH(created_at) = ? 
       AND YEAR(created_at) = ?"
);
$stmt->execute([$uid, $currentMonth, $currentYear]);
$completedPagesMonth = (int)$stmt->fetchColumn();

/* ══════════════════════════════════════════════════
   3. TRUY VẤN THU NHẬP THÁNG HIỆN TẠI (PREVIEW)
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT total FROM earnings 
     WHERE assistant_id = ? AND month = ? AND year = ?"
);
$stmt->execute([$uid, $currentMonth, $currentYear]);
$monthlyEarnings = $stmt->fetchColumn();

if ($monthlyEarnings !== false) {
    $earningsPreview = (float)$monthlyEarnings;
    $isOfficialEarnings = true;
} else {
    // Tạm tính dựa trên số trang hoàn thành * 250,000đ
    $fallbackRate = 250000;
    $earningsPreview = $completedPagesMonth * $fallbackRate;
    $isOfficialEarnings = false;
}

/* ══════════════════════════════════════════════════
   4. TRUY VẤN DEADLINE SẮP TỚI TRONG 7 NGÀY
   ══════════════════════════════════════════════════ */
// Lấy các task chưa duyệt có deadline trong 7 ngày tới
$stmt = $db->prepare(
    "SELECT t.id, t.task_type, t.due_date, t.status, 
            p.page_number, c.chapter_number, s.title AS series_title
     FROM tasks t
     JOIN pages p ON p.id = t.page_id
     JOIN chapters c ON c.id = p.chapter_id
     JOIN series s ON s.id = c.series_id
     WHERE t.assigned_to = ? 
       AND t.status IN ('pending', 'in_progress', 'revision')
       AND t.due_date IS NOT NULL
       AND t.due_date >= CURRENT_DATE()
       AND t.due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
     ORDER BY t.due_date ASC"
);
$stmt->execute([$uid]);
$upcomingDeadlines = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   5. DANH SÁCH 5 TASKS MỚI NHẤT ĐƯỢC GIAO
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT t.id, t.task_type, t.status, t.due_date, 
            p.page_number, c.chapter_number, s.title AS series_title,
            u.username AS mangaka_name
     FROM tasks t
     JOIN pages p ON p.id = t.page_id
     JOIN chapters c ON c.id = p.chapter_id
     JOIN series s ON s.id = c.series_id
     JOIN users u ON u.id = t.assigned_by
     WHERE t.assigned_to = ?
     ORDER BY t.created_at DESC
     LIMIT 5"
);
$stmt->execute([$uid]);
$latestTasks = $stmt->fetchAll();

/* Maps nhãn loại và trạng thái */
$taskTypeLabels = [
    'background' => ['Phông nền', '#10b981', 'rgba(16,185,129,.12)'],
    'shading'    => ['Đổ bóng',   '#3b82f6', 'rgba(59,130,246,.12)'],
    'effects'    => ['Hiệu ứng',  '#8b5cf6', 'rgba(139,92,246,.12)'],
    'lettering'  => ['Chữ/Thoại', '#f59e0b', 'rgba(245,158,11,.12)'],
    'cleanup'    => ['Đi nét',    '#E63946', 'rgba(230,57,70,.12)'],
];

$taskStatusLabels = [
    'pending'     => ['Chờ làm',    'badge-gray'],
    'in_progress' => ['Đang làm',   'badge-blue'],
    'submitted'   => ['Chờ duyệt',  'badge-yellow'],
    'approved'    => ['Đã duyệt',   'badge-green'],
    'revision'    => ['Cần sửa lại','badge-red'],
];
?>

<div class="page-header">
    <div class="breadcrumb">
        <span class="current">Dashboard Trợ Lý</span>
    </div>
    <h1>Chào mừng trở lại, <?= htmlspecialchars($currentUser['username']) ?>!</h1>
    <p>Hôm nay bạn có nhiệm vụ gì mới? Hãy kiểm tra tiến độ vẽ bên dưới.</p>
</div>

<!-- 3 Statistics Cards -->
<div class="stat-grid grid-3 mb-24">
    <!-- Active Tasks Count -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Nhiệm vụ đang thực hiện</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px;"><?= $activeTasksCount ?></div>
        </div>
        <div class="stat-icon" style="color:#60a5fa; font-size:1.8rem; opacity:0.8;">📋</div>
    </div>
    <!-- Completed Pages Count -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Trang hoàn thành (Tháng <?= $currentMonth ?>)</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#34d399;"><?= $completedPagesMonth ?></div>
        </div>
        <div class="stat-icon" style="color:#34d399; font-size:1.8rem; opacity:0.8;">🎨</div>
    </div>
    <!-- Monthly Earnings Preview -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">
                Thu nhập dự kiến (Tháng <?= $currentMonth ?>)
            </p>
            <div class="stat-number" style="font-size: 1.9rem; font-weight:800; margin-top:8px; color:#fbbf24;">
                <?= number_format($earningsPreview) ?> đ
            </div>
            <p class="text-xs text-muted mt-8" style="font-style: italic;">
                <?= $isOfficialEarnings ? '✓ Dữ liệu chính thức' : '* Ước tính (250Kđ/trang)' ?>
            </p>
        </div>
        <div class="stat-icon" style="color:#fbbf24; font-size:1.8rem; opacity:0.8;">💰</div>
    </div>
</div>

<div class="grid-2 gap-24" style="grid-template-columns: 1.2fr 1fr; align-items: start;">
    <!-- LEFT COLUMN: TASK LIST SHORTCUTS -->
    <div>
        <!-- Section: Latest Assigned Tasks -->
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: 24px;">
            <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border)">
                <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                    <div>
                        <p class="card-title" style="font-size:1.05rem; font-weight:700">Nhiệm Vụ Mới Được Giao</p>
                        <p class="card-subtitle">5 nhiệm vụ cập nhật gần nhất</p>
                    </div>
                    <a href="<?= BASE_URL ?>assistant/tasks.php" class="btn btn-secondary btn-sm">Xem tất cả</a>
                </div>
            </div>

            <?php if (empty($latestTasks)): ?>
                <div style="text-align:center; padding: 40px 20px; color:var(--text-muted);">
                    <span style="font-size:2.5rem;">📄</span>
                    <p style="margin-top:10px;">Bạn chưa được phân công nhiệm vụ nào.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                                <th style="padding:12px 18px;">Truyện / Trang</th>
                                <th style="padding:12px 18px;">Loại</th>
                                <th style="padding:12px 18px;">Họa sĩ</th>
                                <th style="padding:12px 18px;">Trạng thái</th>
                                <th style="padding:12px 18px; text-align:right;">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latestTasks as $lt):
                                [$typeLabel, $typeColor, $typeBg] = $taskTypeLabels[$lt['task_type']] ?? ['?', '#fff', 'rgba(255,255,255,.1)'];
                                [$stLabel, $stClass] = $taskStatusLabels[$lt['status']] ?? ['?', 'badge-gray'];
                            ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                    <td style="padding:12px 18px;">
                                        <div class="font-bold" style="font-size:0.88rem;"><?= htmlspecialchars($lt['series_title']) ?></div>
                                        <div class="text-xs text-muted">Chương <?= $lt['chapter_number'] ?> · Trang <?= $lt['page_number'] ?></div>
                                    </td>
                                    <td style="padding:12px 18px;">
                                        <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:0.7rem; font-weight:700; background:<?= $typeBg ?>; color:<?= $typeColor ?>; white-space:nowrap;">
                                            <?= $typeLabel ?>
                                        </span>
                                    </td>
                                    <td style="padding:12px 18px; font-size:0.85rem; color:var(--text-muted);">
                                        <?= htmlspecialchars($lt['mangaka_name']) ?>
                                    </td>
                                    <td style="padding:12px 18px;">
                                        <span class="badge <?= $stClass ?>" style="font-size:0.7rem; padding: 3px 8px;"><?= $stLabel ?></span>
                                    </td>
                                    <td style="padding:12px 18px; text-align:right;">
                                        <a href="<?= BASE_URL ?>assistant/tasks.php?task_id=<?= $lt['id'] ?>" class="btn btn-ghost btn-sm" style="padding:4px 8px;">Chi tiết</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COLUMN: DEADLINE WARNINGS -->
    <div>
        <!-- Deadline alert panel -->
        <div class="card" style="padding: 20px 24px;">
            <p class="card-title" style="font-size:1.05rem; font-weight:700; color:var(--red); display:flex; align-items:center; gap:8px;">
                🚨 Hạn Nộp Sắp Tới (7 ngày)
            </p>
            <p class="card-subtitle mb-16">Các nhiệm vụ cần ưu tiên hoàn thành gấp để tránh trễ hạn</p>

            <?php if (empty($upcomingDeadlines)): ?>
                <div style="text-align:center; padding: 30px 10px; background: rgba(255,255,255,0.02); border-radius: 12px; border:1px dashed var(--border); color:var(--text-muted); font-size:0.85rem;">
                    🟢 Hiện tại không có deadline khẩn cấp nào trong 7 ngày tới.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($upcomingDeadlines as $ud):
                        $daysLeft = (int)round((strtotime($ud['due_date']) - time()) / 86400);
                        if ($daysLeft < 0) {
                            $daysText = 'Quá hạn ' . abs($daysLeft) . ' ngày';
                        } elseif ($daysLeft === 0) {
                            $daysText = 'Hạn nộp hôm nay!';
                        } else {
                            $daysText = 'Còn ' . $daysLeft . ' ngày';
                        }
                        
                        [$typeLabel, $typeColor, $typeBg] = $taskTypeLabels[$ud['task_type']] ?? ['?', '#fff', 'rgba(255,255,255,.1)'];
                    ?>
                        <div style="padding:14px; background: rgba(230, 57, 70, 0.05); border: 1px solid rgba(230, 57, 70, 0.2); border-radius: 10px; display:flex; justify-content:space-between; align-items:center; gap:12px;">
                            <div style="min-width:0;">
                                <div class="font-bold" style="font-size:0.88rem; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:180px;">
                                    <?= htmlspecialchars($ud['series_title']) ?>
                                </div>
                                <div class="text-xs text-muted" style="margin-top:2px;">
                                    Chương <?= $ud['chapter_number'] ?> · Trang <?= $ud['page_number'] ?>
                                </div>
                                <div style="margin-top:6px; display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:0.68rem; font-weight:700; background:<?= $typeBg ?>; color:<?= $typeColor ?>;">
                                    <?= $typeLabel ?>
                                </div>
                            </div>
                            <div style="text-align:right; flex-shrink:0;">
                                <span class="badge badge-red" style="font-size:0.75rem; padding: 4px 10px; font-weight:800; display:block; text-align:center; margin-bottom:5px;">
                                    <?= $daysText ?>
                                </span>
                                <span class="text-xs text-muted"><?= date('d/m/Y', strtotime($ud['due_date'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
