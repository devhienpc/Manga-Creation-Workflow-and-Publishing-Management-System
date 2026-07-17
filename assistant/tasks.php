<?php
/**
 * assistant/tasks.php
 * Giao diện nhiệm vụ vẽ dành cho Trợ lý Manga (Assistant).
 * Tasks gom nhóm theo Series → Page. Nộp 1 file chung cho tất cả tasks trên 1 trang.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['ASSISTANT']) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Không có quyền truy cập.']);
        exit();
    }
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$db  = getDB();
$currentUser = getCurrentUser();
$uid = $currentUser['id'];

// ── AJAX: get_stats ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_stats') {
    header('Content-Type: application/json; charset=utf-8');
    $countTodo = (int)$db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status IN ('pending','in_progress')")->execute([$uid]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    // Use individual queries for reliability
    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status IN ('pending','in_progress')");
    $stmt->execute([$uid]);
    $countTodo = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status='revision'");
    $stmt->execute([$uid]);
    $countRevision = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status='submitted'");
    $stmt->execute([$uid]);
    $countSubmitted = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status='approved' AND MONTH(approved_at)=MONTH(NOW()) AND YEAR(approved_at)=YEAR(NOW())");
    $stmt->execute([$uid]);
    $countApproved = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'todo' => $countTodo, 'revision' => $countRevision, 'submitted' => $countSubmitted, 'approved' => $countApproved]);
    exit();
}

$pageTitle    = 'Nhiệm vụ của tôi';
$activePage   = 'tasks';
$allowedRoles = [ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';
?>

<style>
/* ── Assistant Tasks — scoped styles using project design tokens ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
}
.stat-icon.todo     { background: var(--red-subtle); color: var(--red); }
.stat-icon.revision { background: rgba(245,158,11,0.12); color: var(--yellow); }
.stat-icon.waiting  { background: rgba(59,130,246,0.12); color: var(--blue); }
.stat-icon.done     { background: rgba(16,185,129,0.12); color: var(--green); }
.stat-val { font-size: 1.5rem; font-weight: 700; color: var(--text); margin: 0; }
.stat-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.4px; margin: 0; }

/* Filter bar */
.filter-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.filter-btn {
    padding: 7px 14px;
    border-radius: 100px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 0.82rem; font-weight: 600;
    cursor: pointer;
    transition: all var(--dur) var(--ease);
    display: flex; align-items: center; gap: 5px;
}
.filter-btn:hover { background: var(--bg-hover); color: var(--text); }
.filter-btn.active {
    background: var(--red);
    border-color: var(--red);
    color: #fff;
    box-shadow: 0 4px 12px var(--red-glow);
}
.search-box { position: relative; width: 280px; }
@media (max-width: 768px) { .search-box { width: 100%; } }
.search-box input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 100px;
    padding: 7px 14px 7px 36px;
    font-size: 0.82rem;
}
.search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-dim); }

/* Series group */
.series-group {
    margin-bottom: 24px;
}
.series-header h4 {
    color: var(--text);
    font-size: 1rem;
    font-weight: 700;
    padding: 10px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius) var(--radius) 0 0;
    margin: 0;
}

/* Page group */
.page-group {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-top: none;
    padding: 16px;
    border-left: 4px solid var(--border);
}
.page-group:last-child { border-radius: 0 0 var(--radius) var(--radius); }
.page-group.page-revision { border-left-color: var(--yellow); }
.page-group.page-approved { border-left-color: var(--green); }
.page-group.page-submitted { border-left-color: var(--blue); }

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
.page-label { font-size: 0.8rem; color: var(--text-muted); }
.page-number { font-size: 0.9rem; font-weight: 700; color: var(--text); margin-left: 10px; }

/* Task rows */
.task-rows { display: flex; flex-direction: column; gap: 8px; }
.task-row {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    transition: all var(--dur) var(--ease);
}
.task-row:hover {
    background: rgba(255,255,255,0.03);
    border-color: rgba(255,255,255,0.12);
}
.task-row.status-revision { border-left: 3px solid var(--yellow); }
.task-row.status-approved { border-left: 3px solid var(--green); opacity: 0.8; }

.task-row-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}
.task-type-label { font-weight: 600; font-size: 0.85rem; color: var(--text); }
.task-row-desc { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 6px; }
.task-row-actions { display: flex; gap: 8px; margin-top: 6px; }
.task-row-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 6px; }

/* Revision feedback */
.revision-feedback {
    background: rgba(245,158,11,0.06);
    border: 1px solid rgba(245,158,11,0.15);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
    margin: 6px 0;
    font-size: 0.82rem;
    color: var(--text);
    font-style: italic;
}
.feedback-meta { display: block; margin-top: 4px; font-style: normal; color: var(--yellow); font-size: 0.75rem; font-weight: 600; }

.submitted-label { font-size: 0.8rem; color: var(--yellow); font-weight: 500; }
.approved-label { font-size: 0.8rem; color: var(--green); font-weight: 500; }

/* Deadline tags */
.deadline-tag { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); }
.deadline-tag.overdue { color: var(--red); }
.deadline-tag.urgent, .deadline-tag.warning { color: var(--yellow); }

/* Page-level submit form */
.page-submit-form {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
.submit-form-header {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 10px;
}
.upload-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius-sm);
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all var(--dur) var(--ease);
}
.upload-zone:hover { border-color: var(--red); background: var(--red-subtle); }
.upload-icon { font-size: 1.5rem; color: var(--text-dim); display: block; margin-bottom: 6px; }
.upload-text { color: var(--text); font-size: 0.85rem; margin: 0 0 4px 0; }
.upload-zone small { color: var(--text-dim); font-size: 0.75rem; }
.file-input-hidden { display: none; }
.page-old-file { margin-top: 8px; }
.mt-2 { margin-top: 8px; }

/* History */
.history-box { margin-top: 8px; padding-left: 12px; border-left: 2px solid var(--border); }
.history-item { padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.78rem; }
.history-header { display: flex; justify-content: space-between; margin-bottom: 2px; }
.history-note { color: var(--text-muted); font-style: italic; }
.history-review { color: var(--yellow); padding-left: 8px; border-left: 2px solid var(--yellow); margin: 2px 0; }
.history-time { color: var(--text-dim); font-size: 0.72rem; }

/* Empty state */
.empty-state { text-align: center; padding: 48px 20px; color: var(--text-dim); }

/* Modal */
#pagePreviewModal {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(10px);
    z-index: 1050;
    display: flex; justify-content: center; align-items: center;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s var(--ease);
}
#pagePreviewModal.visible { opacity: 1; pointer-events: auto; }
.preview-modal-content {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    width: 90%; max-width: 900px; height: 80vh;
    display: flex; flex-direction: column; overflow: hidden;
}
.preview-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}
.preview-modal-header h3 { margin: 0; font-size: 1rem; color: var(--text); }
.modal-close-btn {
    background: none; border: none; color: var(--text-muted);
    font-size: 1.2rem; cursor: pointer; padding: 4px 8px; border-radius: var(--radius-sm);
}
.modal-close-btn:hover { color: var(--text); background: var(--bg-hover); }
.preview-modal-body {
    display: grid; grid-template-columns: 1fr 280px;
    height: calc(100% - 56px); overflow: hidden;
}
@media (max-width: 768px) { .preview-modal-body { grid-template-columns: 1fr; overflow-y: auto; } }
.preview-canvas-col {
    background: var(--bg-body);
    display: flex; align-items: center; justify-content: center;
    overflow: auto; padding: 16px;
}
.preview-canvas-col canvas { max-width: 100%; border-radius: var(--radius-sm); }
.preview-info-col {
    padding: 20px;
    border-left: 1px solid var(--border);
    overflow-y: auto;
    display: flex; flex-direction: column; justify-content: space-between;
}
.info-row { margin-bottom: 14px; }
.info-label { display: block; font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase; font-weight: 600; letter-spacing: 0.4px; margin-bottom: 3px; }
.info-value { color: var(--text); font-weight: 500; font-size: 0.85rem; }

/* Spin animation */
@keyframes spin { to { transform: rotate(360deg); } }
.spin { animation: spin 1s linear infinite; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="m-0" style="font-weight:700;"><i class="fi fi-rr-clipboard" style="color:var(--red);"></i> Nhiệm Vụ Của Tôi</h4>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon todo"><i class="fi fi-rr-pencil"></i></div>
            <div><p class="stat-val" id="statTodo">0</p><p class="stat-label">Cần làm</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon revision"><i class="fi fi-rr-undo"></i></div>
            <div><p class="stat-val" id="statRevision">0</p><p class="stat-label">Cần sửa</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon waiting"><i class="fi fi-rr-hourglass-end"></i></div>
            <div><p class="stat-val" id="statSubmitted">0</p><p class="stat-label">Chờ duyệt</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon done"><i class="fi fi-rr-check-circle"></i></div>
            <div><p class="stat-val" id="statApproved">0</p><p class="stat-label">Tháng này</p></div>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <button class="filter-btn active" data-filter="revision">🔴 Cần sửa <span class="nav-badge" id="filterBadgeRevision" style="display:none;">0</span></button>
            <button class="filter-btn" data-filter="all">📋 Tất cả</button>
            <button class="filter-btn" data-filter="pending">⚪ Chờ nhận</button>
            <button class="filter-btn" data-filter="in_progress">🔵 Đang làm</button>
            <button class="filter-btn" data-filter="submitted">🟠 Chờ duyệt</button>
            <button class="filter-btn" data-filter="approved">✅ Hoàn thành</button>
        </div>
        <div class="search-box">
            <i class="fi fi-rr-search"></i>
            <input type="text" id="taskSearch" placeholder="Tìm theo truyện/chương..." />
        </div>
    </div>

    <!-- Tasks List (rendered by JS) -->
    <div id="tasksListContainer"></div>
</div>

<!-- Page Preview Modal -->
<div id="pagePreviewModal">
    <div class="preview-modal-content">
        <div class="preview-modal-header">
            <h3>Xem vùng vẽ được giao</h3>
            <button class="modal-close-btn" onclick="closePagePreviewModal()">✕</button>
        </div>
        <div class="preview-modal-body">
            <div class="preview-canvas-col">
                <canvas id="modalPreviewCanvas"></canvas>
            </div>
            <div class="preview-info-col">
                <div id="modalTasksInfoList" style="overflow-y: auto; flex-grow: 1; margin-bottom: 16px;">
                    <!-- Dynamic task details list -->
                </div>
                <a id="modalDownloadBtn" download class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fi fi-rr-download"></i> Tải ảnh gốc</a>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/tasks_assistant.js?v=<?= time() ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
