<?php
require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Giao Việc';
$activePage   = 'tasks';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   Load helpers
   ══════════════════════════════════════════════════ */

// Chapters of this mangaka's series (for top dropdown)
$stmt = $db->prepare(
    "SELECT c.id, c.chapter_number, c.title, c.status, c.deadline,
            s.id AS series_id, s.title AS series_title
     FROM chapters c
     JOIN series  s ON s.id = c.series_id
     WHERE s.mangaka_id = ?
     ORDER BY s.title ASC, c.chapter_number DESC"
);
$stmt->execute([$uid]);
$allChapters = $stmt->fetchAll();

// All assistants (for dropdown)
$stmt = $db->prepare("SELECT id, username FROM users WHERE role = 'assistant' ORDER BY username");
$stmt->execute();
$assistants = $stmt->fetchAll();

// Selected chapter (from GET param)
$selectedChapterId = (int)($_GET['chapter_id'] ?? 0);
$selectedPageId    = (int)($_GET['page_id']    ?? 0);

// Pages of selected chapter
$pages = [];
if ($selectedChapterId) {
    $stmt = $db->prepare(
        "SELECT id, page_number, original_file, composite_file, status
         FROM pages WHERE chapter_id = ? ORDER BY page_number ASC"
    );
    $stmt->execute([$selectedChapterId]);
    $pages = $stmt->fetchAll();
}

// Tasks list: filtered by chapter + optional status
$filterChapter = (int)($_GET['filter_chapter'] ?? 0);
$filterStatus  = $_GET['filter_status'] ?? '';

$tasksQuery = "
    SELECT t.id, t.task_type, t.description, t.region_data, t.status,
           t.due_date, t.file_result, t.created_at,
           u.username AS assigned_to_name,
           p.page_number, p.id AS page_id,
           c.chapter_number, c.id AS chapter_id, c.title AS chapter_title,
           s.title AS series_title
    FROM tasks t
    JOIN users   u ON u.id = t.assigned_to
    JOIN pages   p ON p.id = t.page_id
    JOIN chapters c ON c.id = p.chapter_id
    JOIN series  s ON s.id = c.series_id
    WHERE t.assigned_by = ?
";
$params = [$uid];
if ($filterChapter) { $tasksQuery .= " AND c.id = ?"; $params[] = $filterChapter; }
if ($filterStatus)  { $tasksQuery .= " AND t.status = ?"; $params[] = $filterStatus; }
$tasksQuery .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($tasksQuery);
$stmt->execute($params);
$taskList = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   Label maps
   ══════════════════════════════════════════════════ */
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
$pageStatusLabels = [
    'pending'     => ['Chờ xử lý', 'badge-gray'],
    'in_progress' => ['Đang làm',  'badge-blue'],
    'approved'    => ['Đã duyệt',  'badge-green'],
    'revision'    => ['Cần sửa',   'badge-red'],
];

?>

<style>
/* ── Page-specific styles ── */
.tasks-layout {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 22px;
    align-items: start;
}
.tasks-left { min-width: 0; }
.tasks-right {}

/* Chapter / page selector */
.selector-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.selector-bar label { font-size:.78rem; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-muted); }

/* Page grid thumbnails */
.page-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 10px;
    margin-bottom: 22px;
}
.page-thumb {
    aspect-ratio: 2/3;
    border-radius: 8px;
    border: 2px solid var(--border);
    background: var(--bg-input);
    overflow: hidden;
    cursor: pointer;
    position: relative;
    transition: border-color .2s, transform .15s;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
}
.page-thumb:hover  { border-color: rgba(230,57,70,.4); transform: translateY(-2px); }
.page-thumb.active { border-color: var(--red); box-shadow: 0 0 0 2px rgba(230,57,70,.3); }
.page-thumb img    { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.page-thumb-label  {
    position: relative; z-index:1;
    background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
    width: 100%; text-align: center;
    font-size:.7rem; font-weight:700; padding:3px 0; letter-spacing:.3px;
}
.page-thumb-status {
    position: absolute; top:5px; right:5px; z-index:2;
    font-size:.58rem; padding:2px 6px;
}
.page-thumb-taskcount {
    position: absolute; top:5px; left:5px; z-index:2;
    background: var(--red); color:#fff;
    font-size:.6rem; font-weight:700; padding:1px 5px; border-radius:100px;
}

/* Page + region canvas wrapper */
.canvas-wrapper {
    position: relative;
    display: inline-block;
    max-width: 100%;
    user-select: none;
    cursor: crosshair;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--border);
    background: #0a0a12;
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
    cursor: crosshair;
}
.region-box {
    position: absolute;
    border-width: 2px;
    border-style: solid;
    border-radius: 4px;
    pointer-events: none;
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
}
.region-label {
    font-size:.6rem; font-weight:700; padding:2px 6px; letter-spacing:.4px;
    text-transform:uppercase; border-radius:0 0 4px 0; pointer-events:none;
    white-space: nowrap; max-width: 100px; overflow: hidden; text-overflow: ellipsis;
}
.draw-rect {
    position: absolute;
    border: 2px dashed #E63946;
    background: rgba(230,57,70,.12);
    border-radius: 3px;
    pointer-events: none;
}
.canvas-hint {
    position: absolute; bottom: 8px; right: 10px;
    font-size:.65rem; color:rgba(255,255,255,.3); letter-spacing:.3px;
    pointer-events: none;
}

/* Task form panel */
.task-form-panel {
    display: none;
    animation: slideIn .2s ease;
}
.task-form-panel.visible { display: block; }
@keyframes slideIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

.region-preview-box {
    background: var(--bg-input); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 14px;
    font-size:.8rem; color:var(--text-muted);
    margin-bottom: 16px;
    display: flex; align-items:center; gap:10px;
}
.region-preview-box .region-indicator {
    width:36px; height:36px; border-radius:4px;
    border: 2px solid var(--red); background: rgba(230,57,70,.12);
    flex-shrink:0;
}

/* Task list */
.task-row-region {
    width: 32px; height: 20px;
    border-radius: 3px; border-width: 2px; border-style: solid;
    flex-shrink: 0;
}
.task-actions { display:flex; gap:6px; flex-wrap:wrap; }
.result-thumb {
    width: 48px; height: 32px; object-fit:cover;
    border-radius:4px; border:1px solid var(--border);
    cursor:pointer; transition: transform .15s;
}
.result-thumb:hover { transform:scale(1.1); }

/* Filter bar */
.filter-bar {
    display: flex; align-items:center; gap:12px;
    background: var(--bg-card); border:1px solid var(--border);
    border-radius: var(--radius); padding: 14px 18px;
    margin-bottom: 18px; flex-wrap: wrap;
}
.filter-bar label { font-size:.78rem; font-weight:700; color:var(--text-muted); white-space:nowrap; }

/* Modal */
.mf-modal-bg {
    position: fixed; inset:0; z-index:9000;
    background: rgba(0,0,0,.7); backdrop-filter:blur(4px);
    display:none; align-items:center; justify-content:center;
    padding: 20px;
}
.mf-modal-bg.open { display:flex; animation: fadeIn .15s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.mf-modal {
    background: var(--bg-card); border:1px solid var(--border);
    border-radius: var(--radius); max-width:480px; width:100%;
    overflow: hidden;
    animation: slideIn .2s ease;
}
.mf-modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding: 16px 20px; border-bottom:1px solid var(--border);
}
.mf-modal-header h3 { font-size:.95rem; font-weight:700; }
.mf-modal-body { padding:20px; }
.mf-modal-footer {
    display:flex; justify-content:flex-end; gap:8px;
    padding:14px 20px; border-top:1px solid var(--border);
    background: rgba(0,0,0,.2);
}

/* Toast */
.mf-toast-wrap {
    position:fixed; bottom:24px; right:24px; z-index:9999;
    display:flex; flex-direction:column; gap:8px; pointer-events:none;
}
.mf-toast {
    padding:12px 18px; border-radius:10px; font-size:.85rem; font-weight:600;
    box-shadow: 0 8px 32px rgba(0,0,0,.4); pointer-events:none;
    animation: toastIn .25s ease;
    max-width: 300px;
}
@keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
.mf-toast.success { background:#10b981; color:#fff; }
.mf-toast.error   { background:#E63946; color:#fff; }

/* Lightbox */
#lightbox {
    position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,.9);backdrop-filter:blur(8px);
    display:none;align-items:center;justify-content:center;
    cursor:zoom-out;
}
#lightbox.open { display:flex; }
#lightbox img { max-width:90vw; max-height:90vh; border-radius:8px; }

/* Empty state */
.empty-state {
    text-align:center; padding:40px 20px; color:var(--text-muted);
}
.empty-state .empty-icon { font-size:3rem; margin-bottom:12px; }
.empty-state p { font-size:.9rem; }

/* Responsive */
@media (max-width:1200px) {
    .tasks-layout { grid-template-columns:1fr; }
    .tasks-right  { display:none; }
}
</style>

<!-- Page header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Giao Việc</span>
    </div>
    <h1>Giao Việc Theo Vùng Trang</h1>
    <p>Chọn trang → khoanh vùng → giao việc cho trợ lý</p>
</div>

<!-- Toast container -->
<div class="mf-toast-wrap" id="toastWrap"></div>

<!-- Lightbox -->
<div id="lightbox" onclick="this.classList.remove('open')">
    <img id="lightboxImg" src="" alt="Kết quả">
</div>

<?php
// Encode data for JS
$jsPages = json_encode(array_map(fn($p) => [
    'id'         => $p['id'],
    'num'        => $p['page_number'],
    'file'       => !empty($p['original_file'])  ? BASE_URL . $p['original_file']  : null,
    'composite'  => !empty($p['composite_file']) ? BASE_URL . $p['composite_file'] : null,
    'status'     => $p['status'],
], $pages));

// Tasks per page (for overlay rendering) - only for selected chapter
$pageTasks = [];
if ($selectedChapterId) {
    $stmt2 = $db->prepare(
        "SELECT t.id, t.task_type, t.region_data, t.status, t.description,
                u.username AS assistant_name
         FROM tasks t
         JOIN pages p ON p.id = t.page_id
         JOIN users u ON u.id = t.assigned_to
         WHERE p.chapter_id = ? AND t.assigned_by = ?"
    );
    $stmt2->execute([$selectedChapterId, $uid]);
    foreach ($stmt2->fetchAll() as $t) {
        $pageTasks[$t['page_id']][] = $t;
    }
}
$jsPageTasks = json_encode($pageTasks);
$jsTaskTypeColors = json_encode([
    'background' => '#10b981',
    'shading'    => '#3b82f6',
    'effects'    => '#8b5cf6',
    'lettering'  => '#f59e0b',
    'cleanup'    => '#E63946',
]);
?>

<div class="tasks-layout">
<!-- ═══════════════════ LEFT COLUMN ═══════════════════ -->
<div class="tasks-left">

    <!-- ── Section 1: Chapter & Page selector ── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
            <div>
                <p class="card-title">Chọn Chương & Trang</p>
                <p class="card-subtitle">Chọn chapter, sau đó click vào thumbnail trang để làm việc</p>
            </div>
        </div>

        <!-- Chapter dropdown -->
        <div class="selector-bar">
            <label for="chapterSelect">Chương:</label>
            <form method="GET" action="" id="chapterForm" style="display:contents;">
                <select id="chapterSelect" name="chapter_id" class="form-control" style="max-width:340px;"
                        onchange="this.form.submit()">
                    <option value="">— Chọn chương —</option>
                    <?php
                    $lastSeries = null;
                    foreach ($allChapters as $ch):
                        if ($lastSeries !== $ch['series_title']):
                            if ($lastSeries !== null) echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($ch['series_title']) . '">';
                            $lastSeries = $ch['series_title'];
                        endif;
                    ?>
                    <option value="<?= $ch['id'] ?>"
                        <?= $ch['id'] == $selectedChapterId ? 'selected' : '' ?>>
                        Chương <?= $ch['chapter_number'] ?> — <?= htmlspecialchars($ch['title']) ?>
                    </option>
                    <?php endforeach;
                    if ($lastSeries !== null) echo '</optgroup>'; ?>
                </select>
                <?php if ($selectedPageId): ?>
                <input type="hidden" name="page_id" value="<?= $selectedPageId ?>">
                <?php endif; ?>
            </form>
        </div>

        <?php if ($selectedChapterId && empty($pages)): ?>
        <div class="empty-state" style="padding:20px 0;">
            <div class="empty-icon">📄</div>
            <p>Chương này chưa có trang nào được upload.</p>
        </div>
        <?php elseif (!empty($pages)): ?>

        <!-- Page thumbnails grid -->
        <div class="page-grid" id="pageGrid">
            <?php
            // Count tasks per page (for badge)
            $taskCountPerPage = [];
            foreach ($pageTasks as $pid => $tasks) {
                $taskCountPerPage[$pid] = count($tasks);
            }
            foreach ($pages as $pg):
                [$stLabel, $stClass] = $pageStatusLabels[$pg['status']] ?? ['?', 'badge-gray'];
                $isActive = ($pg['id'] == $selectedPageId);
                $taskCnt  = $taskCountPerPage[$pg['id']] ?? 0;
            ?>
            <div class="page-thumb <?= $isActive ? 'active' : '' ?>"
                 id="thumb-<?= $pg['id'] ?>"
                 onclick="selectPage(<?= $pg['id'] ?>)"
                 title="Trang <?= $pg['page_number'] ?>">
                <?php if (!empty($pg['original_file'])): ?>
                <img src="<?= htmlspecialchars(BASE_URL . $pg['original_file']) ?>" alt="Trang <?= $pg['page_number'] ?>" loading="lazy">
                <?php else: ?>
                <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:1.5rem;">📃</div>
                <?php endif; ?>
                <span class="badge <?= $stClass ?> page-thumb-status"><?= $stLabel ?></span>
                <?php if ($taskCnt > 0): ?>
                <span class="page-thumb-taskcount"><?= $taskCnt ?></span>
                <?php endif; ?>
                <div class="page-thumb-label">Trang <?= $pg['page_number'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Section 2: Canvas / region drawing ── -->
    <div class="card" id="canvasCard" style="margin-bottom:20px;<?= $selectedPageId ? '' : 'display:none;' ?>">
        <div class="card-header">
            <div>
                <p class="card-title" id="canvasTitle">Khoanh Vùng Giao Việc</p>
                <p class="card-subtitle">Kéo chuột để vẽ vùng → điền form bên phải để giao việc</p>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary btn-sm" onclick="clearDraw()" title="Xóa vùng đang vẽ">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.49"/></svg>
                    Xóa vùng
                </button>
            </div>
        </div>

        <div class="canvas-wrapper" id="canvasWrapper">
            <img id="pageImage" src="" alt="Trang truyện">
            <div class="canvas-overlay" id="canvasOverlay"
                 onmousedown="startDraw(event)"
                 onmousemove="moveDraw(event)"
                 onmouseup="endDraw(event)">
                <!-- Existing region boxes rendered by JS -->
                <div id="regionBoxesContainer"></div>
                <!-- Live draw rect -->
                <div class="draw-rect" id="drawRect" style="display:none;"></div>
            </div>
            <span class="canvas-hint">🖱 Kéo để vẽ vùng</span>
        </div>
    </div>

    <!-- ── Section 3: Task list ── -->
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Danh Sách Nhiệm Vụ Đã Giao</p>
                <p class="card-subtitle">Duyệt kết quả hoặc yêu cầu sửa lại</p>
            </div>
            <span class="badge badge-gray"><?= count($taskList) ?> task</span>
        </div>

        <!-- Filter bar -->
        <form method="GET" class="filter-bar">
            <?php if ($selectedChapterId): ?>
            <input type="hidden" name="chapter_id" value="<?= $selectedChapterId ?>">
            <?php if ($selectedPageId): ?>
            <input type="hidden" name="page_id" value="<?= $selectedPageId ?>">
            <?php endif; ?>
            <?php endif; ?>

            <label>Lọc chương:</label>
            <select name="filter_chapter" class="form-control" style="max-width:220px;">
                <option value="">Tất cả</option>
                <?php foreach ($allChapters as $ch): ?>
                <option value="<?= $ch['id'] ?>" <?= $filterChapter == $ch['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ch['series_title']) ?> · Ch.<?= $ch['chapter_number'] ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label>Trạng thái:</label>
            <select name="filter_status" class="form-control" style="max-width:160px;">
                <option value="">Tất cả</option>
                <?php foreach ($taskStatusLabels as $val => [$lbl,]) : ?>
                <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">Lọc</button>
        </form>

        <?php if (empty($taskList)): ?>
        <div class="empty-state">
            <div class="empty-icon">🎨</div>
            <p>Chưa có task nào được giao.<br>Chọn trang, khoanh vùng và điền form để giao việc.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vùng</th>
                        <th>Trang / Chương</th>
                        <th>Loại</th>
                        <th>Trợ lý</th>
                        <th>Deadline</th>
                        <th>Trạng thái</th>
                        <th>Kết quả</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taskList as $task):
                        [$typeLabel, $typeColor, $typeBg] = $taskTypeLabels[$task['task_type']] ?? ['?','#888','rgba(128,128,128,.1)'];
                        [$stLabel,   $stClass]             = $taskStatusLabels[$task['status']]  ?? ['?','badge-gray'];
                        $region = json_decode($task['region_data'] ?? '{}', true);
                        $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] !== 'approved';
                    ?>
                    <tr id="task-row-<?= $task['id'] ?>">
                        <!-- Region indicator -->
                        <td>
                            <div class="task-row-region"
                                 style="border-color:<?= $typeColor ?>;background:<?= $typeBg ?>;"
                                 title="x:<?= $region['x'] ?? '?' ?>% y:<?= $region['y'] ?? '?' ?>% w:<?= $region['w'] ?? '?' ?>% h:<?= $region['h'] ?? '?' ?>%">
                            </div>
                        </td>
                        <!-- Page / chapter -->
                        <td>
                            <span class="font-bold">Trang <?= $task['page_number'] ?></span>
                            <div class="text-xs text-muted">
                                Ch.<?= $task['chapter_number'] ?> — <?= htmlspecialchars($task['chapter_title']) ?>
                            </div>
                            <div class="text-xs text-muted truncate" style="max-width:120px;">
                                <?= htmlspecialchars($task['series_title']) ?>
                            </div>
                        </td>
                        <!-- Type badge -->
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:100px;font-size:.72rem;font-weight:700;background:<?= $typeBg ?>;color:<?= $typeColor ?>;white-space:nowrap;">
                                <?= $typeLabel ?>
                            </span>
                            <?php if ($task['description']): ?>
                            <div class="text-xs text-muted mt-8" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($task['description']) ?>">
                                <?= htmlspecialchars(mb_substr($task['description'], 0, 40)) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <!-- Assistant -->
                        <td class="text-sm"><?= htmlspecialchars($task['assigned_to_name']) ?></td>
                        <!-- Deadline -->
                        <td>
                            <?php if ($task['due_date']): ?>
                            <span class="badge <?= $isOverdue ? 'badge-red' : 'badge-gray' ?>">
                                <?= date('d/m/Y', strtotime($task['due_date'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <!-- Status -->
                        <td><span class="badge <?= $stClass ?>"><?= $stLabel ?></span></td>
                        <!-- Result file -->
                        <td>
                            <?php if ($task['file_result']): ?>
                            <img class="result-thumb"
                                 src="<?= htmlspecialchars(BASE_URL . $task['file_result']) ?>"
                                 alt="Kết quả"
                                 onclick="openLightbox('<?= htmlspecialchars(BASE_URL . $task['file_result']) ?>')"
                                 title="Click để xem">
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">Chưa có</span>
                            <?php endif; ?>
                        </td>
                        <!-- Actions -->
                        <td>
                            <div class="task-actions">
                                <?php if ($task['status'] === 'submitted'): ?>
                                <button class="btn btn-sm btn-secondary"
                                        style="color:#34d399;border-color:rgba(16,185,129,.3);"
                                        onclick="reviewTask(<?= $task['id'] ?>, 'approve')">
                                    ✓ Duyệt
                                </button>
                                <button class="btn btn-sm btn-danger"
                                        onclick="reviewTask(<?= $task['id'] ?>, 'revision')">
                                    ↩ Sửa lại
                                </button>
                                <?php elseif ($task['status'] === 'approved'): ?>
                                <span class="badge badge-green">Hoàn thành ✓</span>
                                <?php else: ?>
                                <button class="btn btn-sm btn-ghost"
                                        onclick="reviewTask(<?= $task['id'] ?>, 'revision')"
                                        title="Yêu cầu sửa">
                                    ↩
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.tasks-left -->

<!-- ═══════════════════ RIGHT COLUMN (sticky form) ═══════════════════ -->
<div class="tasks-right" id="tasksSidebar">

    <!-- Waiting state -->
    <div class="card" id="waitingState" style="text-align:center;padding:40px 24px;">
        <div style="font-size:3rem;margin-bottom:14px;">🖊️</div>
        <p style="font-weight:700;margin-bottom:8px;">Chọn trang & khoanh vùng</p>
        <p class="text-muted" style="font-size:.85rem;line-height:1.6;">
            Chọn chapter và thumbnail trang bên trái,<br>
            sau đó kéo chuột trên ảnh để vẽ vùng cần giao việc.
        </p>
    </div>

    <!-- Task assignment form (shown after region is drawn) -->
    <div class="card task-form-panel" id="taskFormPanel">
        <div class="card-header">
            <div>
                <p class="card-title">Giao Việc</p>
                <p class="card-subtitle" id="formPageLabel">Trang —</p>
            </div>
            <button class="btn btn-ghost btn-sm btn-icon" onclick="clearDraw()" title="Xóa vùng">✕</button>
        </div>

        <!-- Region preview -->
        <div class="region-preview-box" id="regionPreview">
            <div class="region-indicator" id="regionIndicator" style="border-color:var(--red);"></div>
            <div>
                <div style="font-weight:700;font-size:.82rem;margin-bottom:2px;">Vùng đã chọn</div>
                <div id="regionCoords" style="font-size:.75rem;color:var(--text-muted);">—</div>
            </div>
        </div>

        <form id="taskAssignForm" onsubmit="submitTask(event)">
            <input type="hidden" id="fieldPageId"    name="page_id"    value="">
            <input type="hidden" id="fieldRegionX"   name="region_x"   value="">
            <input type="hidden" id="fieldRegionY"   name="region_y"   value="">
            <input type="hidden" id="fieldRegionW"   name="region_w"   value="">
            <input type="hidden" id="fieldRegionH"   name="region_h"   value="">

            <div class="form-group">
                <label class="form-label">Trợ lý *</label>
                <select name="assigned_to" id="fieldAssistant" class="form-control" required>
                    <option value="">— Chọn trợ lý —</option>
                    <?php foreach ($assistants as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Loại công việc *</label>
                <select name="task_type" id="fieldTaskType" class="form-control" required
                        onchange="updateTypeColor(this.value)">
                    <option value="">— Chọn loại —</option>
                    <option value="background">🟢 Phông nền (Background)</option>
                    <option value="shading">🔵 Đổ bóng (Shading)</option>
                    <option value="effects">🟣 Hiệu ứng (Effects)</option>
                    <option value="lettering">🟡 Chữ/Thoại (Lettering)</option>
                    <option value="cleanup">🔴 Đi nét (Cleanup)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Mô tả chi tiết</label>
                <textarea name="description" id="fieldDesc" class="form-control"
                          rows="3" placeholder="Yêu cầu cụ thể cho trợ lý..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Deadline</label>
                <input type="date" name="due_date" id="fieldDueDate" class="form-control"
                       min="<?= date('Y-m-d') ?>">
            </div>

            <button type="submit" class="btn btn-primary" id="submitTaskBtn" style="width:100%;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Giao Việc
            </button>
        </form>
    </div>

</div><!-- /.tasks-right -->
</div><!-- /.tasks-layout -->

<!-- ── Review Modal ── -->
<div class="mf-modal-bg" id="reviewModal">
    <div class="mf-modal">
        <div class="mf-modal-header">
            <h3 id="reviewModalTitle">Duyệt / Yêu cầu sửa</h3>
            <button class="btn btn-ghost btn-sm btn-icon" onclick="closeModal()">✕</button>
        </div>
        <div class="mf-modal-body">
            <input type="hidden" id="reviewTaskId" value="">
            <input type="hidden" id="reviewAction"  value="">
            <div class="form-group">
                <label class="form-label" id="reviewCommentLabel">Comment (tùy chọn)</label>
                <textarea id="reviewComment" class="form-control" rows="3"
                          placeholder="Nhận xét cho trợ lý..."></textarea>
            </div>
            <div id="reviewApproveNote" style="display:none;" class="alert alert-success">
                <span>✓</span> Sau khi duyệt, trạng thái trang sẽ được cập nhật thành <strong>Đã duyệt</strong>.
            </div>
            <div id="reviewRevisionNote" style="display:none;" class="alert alert-warning">
                <span>↩</span> Trợ lý sẽ nhận thông báo cần sửa lại. Task sẽ về trạng thái <strong>Cần sửa lại</strong>.
            </div>
        </div>
        <div class="mf-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
            <button class="btn btn-primary" id="confirmReviewBtn" onclick="confirmReview()">Xác nhận</button>
        </div>
    </div>
</div>

<!-- ════════════════════════ JAVASCRIPT ════════════════════════ -->
<script>
/* ── Data from PHP ── */
const PAGES      = <?= $jsPages ?>;
const PAGE_TASKS = <?= $jsPageTasks ?>;
const TYPE_COLORS= <?= $jsTaskTypeColors ?>;
const BASE       = <?= json_encode(BASE_URL) ?>;
const CHAPTER_ID = <?= $selectedChapterId ?: 0 ?>;

/* ── State ── */
let selectedPageId = <?= $selectedPageId ?: 0 ?>;
let drawing = false, startX = 0, startY = 0;
let region  = null; // {x,y,w,h} in %

/* ── Page selector ── */
function selectPage(pid) {
    selectedPageId = pid;
    // Update thumb active state
    document.querySelectorAll('.page-thumb').forEach(t => t.classList.remove('active'));
    const thumb = document.getElementById('thumb-' + pid);
    if (thumb) thumb.classList.add('active');

    const pg = PAGES.find(p => p.id == pid);
    if (!pg) return;

    // Show canvas card
    document.getElementById('canvasCard').style.display = '';
    document.getElementById('canvasTitle').textContent = 'Trang ' + pg.num + ' — Khoanh Vùng Giao Việc';
    document.getElementById('formPageLabel').textContent = 'Trang ' + pg.num;
    document.getElementById('fieldPageId').value = pid;

    // Set image
    const img = document.getElementById('pageImage');
    if (pg.file) {
        img.src = pg.file;
        img.style.display = 'block';
    } else {
        img.src = '';
        img.style.display = 'none';
    }

    // Render existing region boxes
    renderRegionBoxes(pid);
    clearDraw();

    // Scroll to canvas
    document.getElementById('canvasCard').scrollIntoView({behavior:'smooth', block:'start'});

    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('chapter_id', CHAPTER_ID);
    url.searchParams.set('page_id', pid);
    window.history.replaceState({}, '', url);
}

/* ── Render existing task regions ── */
function renderRegionBoxes(pid) {
    const container = document.getElementById('regionBoxesContainer');
    container.innerHTML = '';
    const tasks = PAGE_TASKS[pid] || [];
    tasks.forEach(t => {
        const r = t.region_data;
        if (!r) return;
        const color = TYPE_COLORS[t.task_type] || '#888';
        const box = document.createElement('div');
        box.className = 'region-box';
        box.style.cssText = `
            left:${r.x}%; top:${r.y}%; width:${r.w}%; height:${r.h}%;
            border-color:${color}; background:${color}1a;
        `;
        box.title = `[${t.task_type}] ${t.description || ''} — ${t.assistant_name} (${t.status})`;
        const lbl = document.createElement('div');
        lbl.className = 'region-label';
        lbl.style.cssText = `background:${color};color:#fff;`;
        lbl.textContent = t.task_type;
        box.appendChild(lbl);
        container.appendChild(box);
    });
}

/* ── Canvas drawing ── */
function getRelPos(e) {
    const wrap = document.getElementById('canvasOverlay');
    const rect = wrap.getBoundingClientRect();
    return {
        x: Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))  * 100,
        y: Math.max(0, Math.min(1, (e.clientY - rect.top)  / rect.height)) * 100,
    };
}

function startDraw(e) {
    if (!selectedPageId) return;
    e.preventDefault();
    drawing = true;
    const pos = getRelPos(e);
    startX = pos.x; startY = pos.y;
    const dr = document.getElementById('drawRect');
    dr.style.display = 'block';
    dr.style.left  = startX + '%';
    dr.style.top   = startY + '%';
    dr.style.width = '0';
    dr.style.height= '0';
}

function moveDraw(e) {
    if (!drawing) return;
    const pos = getRelPos(e);
    const x = Math.min(startX, pos.x);
    const y = Math.min(startY, pos.y);
    const w = Math.abs(pos.x - startX);
    const h = Math.abs(pos.y - startY);
    const dr = document.getElementById('drawRect');
    dr.style.left   = x + '%';
    dr.style.top    = y + '%';
    dr.style.width  = w + '%';
    dr.style.height = h + '%';
}

function endDraw(e) {
    if (!drawing) return;
    drawing = false;
    const pos = getRelPos(e);
    const x = parseFloat((Math.min(startX, pos.x)).toFixed(2));
    const y = parseFloat((Math.min(startY, pos.y)).toFixed(2));
    const w = parseFloat((Math.abs(pos.x - startX)).toFixed(2));
    const h = parseFloat((Math.abs(pos.y - startY)).toFixed(2));

    if (w < 2 || h < 2) { clearDraw(); return; } // too small, ignore

    region = {x, y, w, h};
    document.getElementById('fieldRegionX').value = x;
    document.getElementById('fieldRegionY').value = y;
    document.getElementById('fieldRegionW').value = w;
    document.getElementById('fieldRegionH').value = h;
    document.getElementById('regionCoords').textContent =
        `X:${x.toFixed(1)}% Y:${y.toFixed(1)}% — ${w.toFixed(1)}% × ${h.toFixed(1)}%`;

    // Show form
    document.getElementById('waitingState').style.display = 'none';
    document.getElementById('taskFormPanel').classList.add('visible');

    // On mobile, scroll to form
    if (window.innerWidth < 1200) {
        document.getElementById('taskFormPanel').scrollIntoView({behavior:'smooth'});
    }
}

function clearDraw() {
    region = null;
    drawing = false;
    const dr = document.getElementById('drawRect');
    dr.style.display = 'none';
    document.getElementById('fieldRegionX').value = '';
    document.getElementById('fieldRegionY').value = '';
    document.getElementById('fieldRegionW').value = '';
    document.getElementById('fieldRegionH').value = '';
    document.getElementById('regionCoords').textContent = '—';
    document.getElementById('taskFormPanel').classList.remove('visible');
    document.getElementById('waitingState').style.display = '';
}

function updateTypeColor(type) {
    const color = TYPE_COLORS[type] || '#E63946';
    document.getElementById('regionIndicator').style.borderColor = color;
    document.getElementById('regionIndicator').style.background  = color + '22';
}

/* ── Submit task form ── */
async function submitTask(e) {
    e.preventDefault();
    if (!region) { showToast('Vui lòng khoanh vùng trước!', 'error'); return; }
    if (!selectedPageId) { showToast('Chọn trang trước!', 'error'); return; }

    const form = document.getElementById('taskAssignForm');
    const data = new FormData(form);
    data.append('action', 'create_task');

    const btn = document.getElementById('submitTaskBtn');
    btn.disabled = true;
    btn.textContent = 'Đang giao…';

    try {
        const res  = await fetch(BASE + 'api/tasks.php', {method:'POST', body: data});
        const json = await res.json();
        if (json.success) {
            showToast('Đã giao việc thành công!', 'success');
            form.reset();
            clearDraw();
            // Reload after 800ms to show new data
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(json.message || 'Có lỗi xảy ra!', 'error');
        }
    } catch(err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Giao Việc';
    }
}

/* ── Review actions ── */
let _reviewTaskId = null, _reviewAction = null;

function reviewTask(taskId, action) {
    _reviewTaskId = taskId;
    _reviewAction = action;
    document.getElementById('reviewTaskId').value = taskId;
    document.getElementById('reviewAction').value  = action;
    document.getElementById('reviewComment').value = '';
    document.getElementById('reviewApproveNote').style.display  = action === 'approve'   ? '' : 'none';
    document.getElementById('reviewRevisionNote').style.display = action === 'revision'  ? '' : 'none';
    document.getElementById('reviewModalTitle').textContent      = action === 'approve' ? '✓ Duyệt nhiệm vụ' : '↩ Yêu cầu sửa lại';
    document.getElementById('confirmReviewBtn').style.background = action === 'approve' ? '#10b981' : '#E63946';
    document.getElementById('reviewModal').classList.add('open');
}

function closeModal() {
    document.getElementById('reviewModal').classList.remove('open');
}

async function confirmReview() {
    const comment = document.getElementById('reviewComment').value.trim();
    const data = new FormData();
    data.append('action',  'review_task');
    data.append('task_id', _reviewTaskId);
    data.append('review',  _reviewAction);
    data.append('comment', comment);

    const btn = document.getElementById('confirmReviewBtn');
    btn.disabled = true;
    btn.textContent = '…';

    try {
        const res  = await fetch(BASE + 'api/tasks.php', {method:'POST', body: data});
        const json = await res.json();
        if (json.success) {
            closeModal();
            showToast(
                _reviewAction === 'approve' ? 'Đã duyệt nhiệm vụ!' : 'Đã yêu cầu sửa lại!',
                'success'
            );
            setTimeout(() => window.location.reload(), 700);
        } else {
            showToast(json.message || 'Lỗi!', 'error');
        }
    } catch(err) {
        showToast('Lỗi kết nối!', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Xác nhận';
    }
}

/* ── Lightbox ── */
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}

/* ── Toast ── */
function showToast(msg, type='success') {
    const wrap  = document.getElementById('toastWrap');
    const toast = document.createElement('div');
    toast.className = 'mf-toast ' + type;
    toast.textContent = msg;
    wrap.appendChild(toast);
    setTimeout(() => { toast.style.opacity='0'; toast.style.transform='translateX(20px)'; toast.style.transition='.3s'; }, 2500);
    setTimeout(() => toast.remove(), 2900);
}

/* ── Init: if page already selected, render it ── */
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($selectedPageId): ?>
    selectPage(<?= $selectedPageId ?>);
    <?php endif; ?>
});

/* Close review modal on backdrop click */
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
