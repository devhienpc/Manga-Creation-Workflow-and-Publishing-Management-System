<?php
/**
 * editor/dashboard.php
 * Trang Dashboard dành riêng cho Biên tập viên (Editor).
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Dashboard Biên Tập Viên';
$activePage   = 'dashboard';
$allowedRoles = [ROLES['EDITOR']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. ĐẾM SỐ BẢN THẢO CHỜ DUYỆT (PENDING & REVIEWING)
   ══════════════════════════════════════════════════ */
$stmt = $db->query("SELECT COUNT(*) FROM manuscripts WHERE status IN ('pending', 'reviewing')");
$pendingManuscriptsCount = (int)$stmt->fetchColumn();

/* ══════════════════════════════════════════════════
   2. DANH SÁCH BẢN THẢO ĐANG CHỜ REVIEW
   ══════════════════════════════════════════════════ */
$stmt = $db->query(
    "SELECT m.id, m.version, m.status, m.submitted_at, 
            c.chapter_number, c.title AS chapter_title,
            s.title AS series_title,
            u.username AS mangaka_name
     FROM manuscripts m
     JOIN chapters c ON c.id = m.chapter_id
     JOIN series s ON s.id = m.series_id
     JOIN users u ON u.id = m.submitted_by
     WHERE m.status IN ('pending', 'reviewing')
     ORDER BY m.submitted_at DESC
     LIMIT 5"
);
$pendingManuscripts = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   3. DANH SÁCH CÁC BỘ TRUYỆN ĐANG QUẢN LÝ (SERIES LIST)
   ══════════════════════════════════════════════════ */
$stmt = $db->query(
    "SELECT s.id, s.title, s.genre, s.status, 
            u.username AS mangaka_name,
            (SELECT COUNT(*) FROM chapters c WHERE c.series_id = s.id) AS total_chapters
     FROM series s
     JOIN users u ON u.id = s.mangaka_id
     ORDER BY s.created_at DESC"
);
$seriesList = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   4. DEADLINE CHƯƠNG TRUYỆN GẦN NHẤT
   ══════════════════════════════════════════════════ */
$stmt = $db->query(
    "SELECT c.id, c.chapter_number, c.title, c.deadline, c.status, 
            s.title AS series_title
     FROM chapters c
     JOIN series s ON s.id = c.series_id
     WHERE c.status != 'published' AND c.deadline IS NOT NULL
     ORDER BY c.deadline ASC
     LIMIT 5"
);
$upcomingDeadlines = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   5. TIẾN ĐỘ STUDIO (TASKS ASSISTANT CHƯA XONG)
   ══════════════════════════════════════════════════ */
$stmt = $db->query(
    "SELECT t.id, t.task_type, t.status, t.due_date, 
            p.page_number, c.chapter_number, s.title AS series_title,
            u.username AS assistant_name
     FROM tasks t
     JOIN pages p ON p.id = t.page_id
     JOIN chapters c ON c.id = p.chapter_id
     JOIN series s ON s.id = c.series_id
     JOIN users u ON u.id = t.assigned_to
     WHERE t.status != 'approved'
     ORDER BY t.due_date ASC, t.created_at DESC
     LIMIT 5"
);
$activeStudioTasks = $stmt->fetchAll();

/* Maps nhãn loại và trạng thái */
$seriesStatusConfig = [
    'draft'      => ['Bản nháp',      'badge-gray',   '📝'],
    'submitted'  => ['Đã nộp',        'badge-blue',   '📤'],
    'approved'   => ['Đã duyệt',      'badge-purple', '✅'],
    'publishing' => ['Đang xuất bản', 'badge-green',  '🔥'],
    'cancelled'  => ['Đã hủy',        'badge-red',    '✕'],
];

$chapterStatusConfig = [
    'planning'    => ['Lên kế hoạch', 'badge-gray'],
    'in_progress' => ['Đang vẽ',      'badge-blue'],
    'review'      => ['Chờ duyệt',    'badge-yellow'],
    'approved'    => ['Đã duyệt',     'badge-purple'],
    'published'   => ['Đã xuất bản',  'badge-green'],
];

$manuscriptStatusLabels = [
    'pending'   => ['Chờ duyệt',  'badge-gray'],
    'reviewing' => ['Đang duyệt', 'badge-blue'],
    'approved'  => ['Đã duyệt',   'badge-green'],
    'rejected'  => ['Từ chối',    'badge-red'],
];

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
        <span class="current">Dashboard Biên Tập Viên</span>
    </div>
    <h1>Xin chào, Biên tập viên <?= htmlspecialchars($currentUser['username']) ?>!</h1>
    <p>Giám sát tiến độ sáng tác của họa sĩ, quản lý bản thảo và điều phối tiến độ studio.</p>
</div>

<!-- Thẻ số lượng bản thảo cần duyệt -->
<div class="stat-grid grid-3 mb-24" style="grid-template-columns: 1fr 1fr 1fr;">
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Bản thảo chờ duyệt</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#fbbf24;"><?= $pendingManuscriptsCount ?></div>
        </div>
        <div class="stat-icon" style="color:#fbbf24; font-size:1.8rem; opacity:0.8;">📤</div>
    </div>
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Tổng số bộ truyện hệ thống</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px;"><?= count($seriesList) ?></div>
        </div>
        <div class="stat-icon" style="color:var(--red); font-size:1.8rem; opacity:0.8;">📚</div>
    </div>
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Tasks Studio Đang Vẽ</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#60a5fa;"><?= count($activeStudioTasks) ?></div>
        </div>
        <div class="stat-icon" style="color:#60a5fa; font-size:1.8rem; opacity:0.8;">🎨</div>
    </div>
</div>

<div class="grid-2 gap-24" style="grid-template-columns: 1.25fr 1fr; align-items: start; margin-bottom: 24px;">
    <!-- CỘT TRÁI: DANH SÁCH BẢN THẢO CHỜ REVIEW -->
    <div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom: 24px;">
            <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border)">
                <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                    <div>
                        <p class="card-title" style="font-size:1.05rem; font-weight:700">Bản Thảo Chờ Kiểm Duyệt</p>
                        <p class="card-subtitle">Các bản thảo vừa được họa sĩ nộp cần đọc duyệt</p>
                    </div>
                    <a href="<?= BASE_URL ?>editor/manuscripts.php" class="btn btn-secondary btn-sm">Xem tất cả</a>
                </div>
            </div>

            <?php if (empty($pendingManuscripts)): ?>
                <div style="text-align:center; padding: 40px 20px; color:var(--text-muted);">
                    <span style="font-size:2.5rem;">📄</span>
                    <p style="margin-top:10px;">Không có bản thảo nào đang chờ kiểm duyệt.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                                <th style="padding:12px 18px;">Bộ truyện / Chương</th>
                                <th style="padding:12px 18px;">Phiên bản</th>
                                <th style="padding:12px 18px;">Họa sĩ nộp</th>
                                <th style="padding:12px 18px;">Thời gian</th>
                                <th style="padding:12px 18px; text-align:right;">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingManuscripts as $pm):
                                [$stLabel, $stClass] = $manuscriptStatusLabels[$pm['status']] ?? ['?', 'badge-gray'];
                            ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                    <td style="padding:12px 18px;">
                                        <div class="font-bold" style="font-size:0.88rem;"><?= htmlspecialchars($pm['series_title']) ?></div>
                                        <div class="text-xs text-muted">Chương <?= $pm['chapter_number'] ?> · <?= htmlspecialchars($pm['chapter_title']) ?></div>
                                    </td>
                                    <td style="padding:12px 18px;">
                                        <span class="badge badge-gray" style="font-size:0.7rem; font-weight:700;">v<?= $pm['version'] ?></span>
                                    </td>
                                    <td style="padding:12px 18px; font-size:0.85rem; color:var(--text-muted);">
                                        <?= htmlspecialchars($pm['mangaka_name']) ?>
                                    </td>
                                    <td style="padding:12px 18px; font-size:0.8rem; color:var(--text-muted);">
                                        <?= date('d/m H:i', strtotime($pm['submitted_at'])) ?>
                                    </td>
                                    <td style="padding:12px 18px; text-align:right;">
                                        <a href="<?= BASE_URL ?>editor/manuscripts.php?manuscript_id=<?= $pm['id'] ?>" class="btn btn-ghost btn-sm" style="padding:4px 8px;">Đọc duyệt</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Series quản lý -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border)">
                <p class="card-title" style="font-size:1.05rem; font-weight:700">Giám Sát Danh Sách Truyện tranh</p>
                <p class="card-subtitle">Tất cả các bộ truyện đang vận hành trên hệ thống</p>
            </div>
            
            <div class="table-wrap">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                            <th style="padding:12px 18px;">Tên tác phẩm</th>
                            <th style="padding:12px 18px;">Thể loại</th>
                            <th style="padding:12px 18px;">Họa sĩ sáng tác</th>
                            <th style="padding:12px 18px; text-align:right;">Số chương</th>
                            <th style="padding:12px 18px; text-align:right;">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seriesList as $s):
                            [$stLabel, $stClass, $stIcon] = $seriesStatusConfig[$s['status']] ?? ['?', 'badge-gray', ''];
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                <td style="padding:12px 18px;" class="font-bold">
                                    <?= htmlspecialchars($s['title']) ?>
                                </td>
                                <td style="padding:12px 18px; font-size:0.82rem; color:var(--text-muted);">
                                    <?= htmlspecialchars($s['genre']) ?>
                                </td>
                                <td style="padding:12px 18px; font-size:0.85rem;">
                                    <?= htmlspecialchars($s['mangaka_name']) ?>
                                </td>
                                <td style="padding:12px 18px; text-align:right; font-weight:700;">
                                    <?= $s['total_chapters'] ?>
                                </td>
                                <td style="padding:12px 18px; text-align:right;">
                                    <span class="badge <?= $stClass ?>" style="font-size:0.7rem; padding: 3px 8px;"><?= $stIcon ?> <?= $stLabel ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- CỘT PHẢI: DEADLINE & TIẾN ĐỘ STUDIO -->
    <div>
        <!-- Section: Deadline Chương Truyện -->
        <div class="card" style="padding: 20px; margin-bottom:24px;">
            <p class="card-title" style="font-size:1.05rem; font-weight:700; color:#f59e0b; display:flex; align-items:center; gap:8px;">
                ⏱️ Hạn Chót Chương Truyện
            </p>
            <p class="card-subtitle mb-16">Thời hạn hoàn thành bản thảo chương của các bộ truyện</p>

            <?php if (empty($upcomingDeadlines)): ?>
                <div style="text-align:center; padding: 20px 10px; color:var(--text-muted); font-size:0.85rem;">
                    Không có chương truyện nào sắp đến deadline.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($upcomingDeadlines as $ch):
                        [$cLabel, $cClass] = $chapterStatusConfig[$ch['status']] ?? ['?', 'badge-gray'];
                        $isOverdue = strtotime($ch['deadline']) < time();
                    ?>
                        <div style="padding:12px; background:rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius:8px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div style="min-width:0;">
                                    <strong style="font-size:0.85rem; color:#fff;" class="truncate"><?= htmlspecialchars($ch['series_title']) ?></strong>
                                    <div class="text-xs text-muted" style="margin-top:2px;">Chương <?= $ch['chapter_number'] ?> · <?= htmlspecialchars($ch['title']) ?></div>
                                </div>
                                <span class="badge <?= $cClass ?>" style="font-size:0.65rem; padding: 2px 6px; flex-shrink:0;"><?= $cLabel ?></span>
                            </div>
                            <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                                <span class="text-xs text-muted">Hạn chót:</span>
                                <span class="badge <?= $isOverdue ? 'badge-red' : 'badge-gray' ?>" style="font-size:0.72rem; font-weight:700;">
                                    <?= date('d/m/Y', strtotime($ch['deadline'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Studio Progress (Assistant tasks) -->
        <div class="card" style="padding: 20px;">
            <p class="card-title" style="font-size:1.05rem; font-weight:700; color:#60a5fa; display:flex; align-items:center; gap:8px;">
                🎨 Theo Dõi Tiến Độ Studio
            </p>
            <p class="card-subtitle mb-16">Các nhiệm vụ trợ lý đang làm chưa hoàn thành</p>

            <?php if (empty($activeStudioTasks)): ?>
                <div style="text-align:center; padding: 20px 10px; color:var(--text-muted); font-size:0.85rem;">
                    Studio đang trống, chưa phân công task.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($activeStudioTasks as $st):
                        [$typeLabel, $typeColor, $typeBg] = $taskTypeLabels[$st['task_type']] ?? ['?', '#fff', 'rgba(255,255,255,.1)'];
                        [$stLabel, $stClass] = $taskStatusLabels[$st['status']] ?? ['?', 'badge-gray'];
                    ?>
                        <div style="padding:12px; background:rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius:8px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div style="min-width:0;">
                                    <strong style="font-size:0.82rem; color:#fff;" class="truncate"><?= htmlspecialchars($st['series_title']) ?></strong>
                                    <div class="text-xs text-muted" style="margin-top:2px;">Chương <?= $st['chapter_number'] ?> · Trang <?= $st['page_number'] ?></div>
                                </div>
                                <span class="badge <?= $stClass ?>" style="font-size:0.65rem; padding: 2px 6px; flex-shrink:0;"><?= $stLabel ?></span>
                            </div>
                            <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <div style="display:inline-flex; align-items:center; padding:1px 6px; border-radius:100px; font-size:0.65rem; font-weight:700; background:<?= $typeBg ?>; color:<?= $typeColor ?>;">
                                    <?= $typeLabel ?>
                                </div>
                                <span class="text-xs text-muted" style="font-weight:600; color:#fff;">Trợ lý: <?= htmlspecialchars($st['assistant_name']) ?></span>
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
