<?php
/**
 * mangaka/tasks.php
 * Giao diện quản lý & review nhiệm vụ trợ lý dành cho Mangaka.
 * Hỗ trợ giao việc trên canvas vẽ tay, tự động phân tách vùng bằng AI,
 * xem lịch sử nộp bài (timeline), duyệt/sửa bài độc lập trên giao diện modal.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// ── Bảo mật và Phân quyền (AJAX hoặc Request thường) ──
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['MANGAKA']) {
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

// ── Xử lý AJAX endpoints ──
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax'];
    
    if ($ajaxAction === 'get_chapters') {
        $seriesId = (int)($_GET['series_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT id, chapter_number, title 
            FROM chapters 
            WHERE series_id = ? 
            ORDER BY chapter_number DESC
        ");
        $stmt->execute([$seriesId]);
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'chapters' => $chapters]);
        exit();
    }
    
    if ($ajaxAction === 'get_pages') {
        $chapterId = (int)($_GET['chapter_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT id, page_number, status, original_file, composite_file 
            FROM pages 
            WHERE chapter_id = ? 
            ORDER BY page_number ASC
        ");
        $stmt->execute([$chapterId]);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'pages' => $pages]);
        exit();
    }
    
    if ($ajaxAction === 'get_page_detail') {
        $pageId = (int)($_GET['page_id'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT p.id, p.page_number, p.original_file, p.composite_file, p.status,
                   c.chapter_number, c.title as chapter_title, s.title as series_title
            FROM pages p
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE p.id = ? AND s.mangaka_id = ?
            LIMIT 1
        ");
        $stmt->execute([$pageId, $uid]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($page) {
            $page['original_url'] = manuscriptUrl($page['original_file']);
            $page['composite_url'] = !empty($page['composite_file']) ? manuscriptUrl($page['composite_file']) : null;
            echo json_encode(['success' => true, 'page' => $page]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy trang hoặc bạn không có quyền.']);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Hành động AJAX không hợp lệ.']);
    exit();
}

// Lấy danh sách series ban đầu cho dropdown
$stmt = $db->prepare("
    SELECT id, title 
    FROM series 
    WHERE mangaka_id = ? 
    ORDER BY title ASC
");
$stmt->execute([$uid]);
$allSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách trợ lý vẽ
$stmt = $db->prepare("
    SELECT id, username 
    FROM users 
    WHERE role = 'assistant' AND is_active = 1 
    ORDER BY username ASC
");
$stmt->execute();
$activeAssistants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy đơn giá mặc định của các loại nhiệm vụ
$rates = [];
foreach (['background', 'shading', 'effects', 'lettering', 'cleanup'] as $type) {
    $stmtRate = $db->prepare("SELECT value_text FROM settings WHERE key_name = ? LIMIT 1");
    $stmtRate->execute(['default_rate_' . $type]);
    $val = $stmtRate->fetchColumn();
    if ($val === false) {
        $fallbacks = [
            'background' => 100000,
            'shading'    => 60000,
            'effects'    => 50000,
            'lettering'  => 30000,
            'cleanup'    => 20000
        ];
        $val = $fallbacks[$type];
    }
    $rates[$type] = (float)$val;
}

$pageTitle    = 'Review & Giao Việc Trợ Lý';
$activePage   = 'tasks';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';
?>
<script>
const DEFAULT_TASK_RATES = <?= json_encode($rates) ?>;
</script>

<style>
/* Scoped styles for Tasks Manager Dashboard */
.task-manager-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
    margin-top: 20px;
    align-items: start;
}
@media (max-width: 1200px) {
    .task-manager-container {
        grid-template-columns: 1fr;
    }
}
.canvas-wrapper {
    position: relative;
    background: var(--bg-body);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}
.canvas-wrapper img {
    max-width: 100%;
    height: auto;
    display: block;
    user-select: none;
}
.canvas-wrapper canvas {
    position: absolute;
    top: 0;
    left: 0;
    cursor: crosshair;
}
/* Selector panel */
.selector-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}
.selector-panel label {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted) !important;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.selector-panel select {
    background-color: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: var(--radius-sm);
    padding: 10px 14px;
    font-weight: 500;
}
.selector-panel select:focus {
    background-color: var(--bg-input);
    border-color: var(--red);
    box-shadow: 0 0 0 0.25rem var(--red-glow);
    color: var(--text);
}

/* Floating AI Button */
.ai-seg-floating-btn {
    position: absolute;
    top: 15px;
    right: 15px;
    z-index: 10;
    background: linear-gradient(135deg, var(--red), #a855f7);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 30px;
    font-weight: 600;
    font-size: 0.85rem;
    box-shadow: 0 4px 15px var(--red-glow);
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ai-seg-floating-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(230, 57, 70, 0.4);
}
.ai-seg-floating-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

/* Filter tabs */
.filter-tabs-wrapper {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.filter-tab {
    padding: 6px 12px;
    border-radius: 20px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    color: var(--text-muted);
    transition: all 0.2s ease;
}
.filter-tab:hover {
    background: var(--bg-hover);
    color: var(--text);
}
.filter-tab.active {
    background: var(--red);
    border-color: var(--red);
    color: #fff;
    box-shadow: 0 4px 10px var(--red-glow);
}

/* Task cards */
.task-card {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-left: 4px solid var(--text-muted);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: all 0.2s ease;
    position: relative;
}
.task-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.25);
    background: var(--bg-hover);
}
.task-card.border-background { border-left-color: var(--blue); }
.task-card.border-shading { border-left-color: var(--yellow); }
.task-card.border-effects { border-left-color: var(--purple); }
.task-card.border-lettering { border-left-color: var(--green); }
.task-card.border-cleanup { border-left-color: var(--text-muted); }

.task-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.task-card-meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 8px;
}
.task-card-desc {
    font-size: 0.85rem;
    color: var(--text);
    margin-bottom: 12px;
    line-height: 1.4;
}
.highlight-glow {
    animation: glow-pulse 1.5s ease-in-out;
}
@keyframes glow-pulse {
    0% { box-shadow: 0 0 0 rgba(230, 57, 70, 0); }
    50% { box-shadow: 0 0 15px rgba(230, 57, 70, 0.8); border-color: var(--red); }
    100% { box-shadow: 0 0 0 rgba(230, 57, 70, 0); }
}

/* Review Panels */
.review-panel {
    background: rgba(245, 158, 11, 0.08);
    border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: var(--radius-sm);
    padding: 10px;
    margin-top: 10px;
}
.review-panel-meta {
    font-size: 0.75rem;
    color: var(--yellow);
    margin-bottom: 8px;
}
.submission-note {
    margin: 4px 0 0 0;
    color: var(--text);
    font-style: italic;
}
.review-panel-actions {
    display: flex;
    justify-content: flex-end;
}
.approved-info {
    font-size: 0.75rem;
    color: var(--green);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}
.btn-link {
    background: none;
    border: none;
    color: var(--blue);
    text-decoration: underline;
    cursor: pointer;
    font-size: 0.75rem;
    padding: 0;
}
.btn-link:hover {
    color: var(--text);
}
.revision-info {
    background: rgba(230, 57, 70, 0.08);
    border: 1px solid rgba(230, 57, 70, 0.15);
    border-radius: var(--radius-sm);
    padding: 10px;
    margin-top: 10px;
    font-size: 0.75rem;
}
.revision-info span {
    color: var(--red);
    font-weight: 700;
}
.revision-comment {
    margin: 4px 0 0 0;
    color: var(--text-muted);
}

/* Modal Styling */
#reviewModal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(10, 10, 15, 0.85);
    backdrop-filter: blur(12px);
    z-index: 1050;
    display: flex;
    justify-content: center;
    align-items: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}
#reviewModal.visible {
    opacity: 1;
    pointer-events: auto;
}
.modal-content-wide {
    background: #141419;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    width: 95%;
    max-width: 1200px;
    height: 85vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,0.5);
}
.modal-header-custom {
    padding: 16px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(255, 255, 255, 0.02);
}
.modal-header-custom h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #fff;
}
.modal-close-btn {
    background: none;
    border: none;
    color: #a0a0b0;
    font-size: 1.5rem;
    cursor: pointer;
    transition: color 0.2s;
}
.modal-close-btn:hover {
    color: #fff;
}
.modal-body-custom {
    display: grid;
    grid-template-columns: 35% 35% 30%;
    height: calc(100% - 60px);
    overflow: hidden;
}
@media (max-width: 992px) {
    .modal-body-custom {
        grid-template-columns: 100%;
        overflow-y: auto;
        height: auto;
    }
}
.modal-col {
    padding: 20px;
    border-right: 1px solid rgba(255, 255, 255, 0.08);
    overflow-y: auto;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.modal-col:last-child {
    border-right: none;
}
.non-image-file-icon {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px;
}

/* Timeline Container */
.timeline-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 250px;
    overflow-y: auto;
    padding-right: 4px;
}
.timeline-item {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    padding: 12px;
}
.timeline-header {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #a0a0b0;
    margin-bottom: 6px;
}
.timeline-body {
    font-size: 0.8rem;
}

/* Quick templates */
.quick-comment-templates button {
    margin-right: 4px;
    margin-top: 4px;
    font-size: 0.7rem;
}

/* Revision box */
.revision-request-box {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 8px;
    padding: 14px;
}

/* Summary sticky bar */
#summaryStickyBar {
    position: fixed;
    bottom: 0;
    left: 260px; /* Sidebar width */
    right: 0;
    height: 60px;
    background: rgba(20, 20, 26, 0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    z-index: 1000;
    display: flex;
    align-items: center;
    padding: 0 30px;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.3);
}
@media (max-width: 768px) {
    #summaryStickyBar {
        left: 0;
        padding: 0 15px;
    }
}
.summary-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
}
.summary-progress-wrapper {
    width: 250px;
    height: 8px;
    background: rgba(255,255,255,0.08);
    border-radius: 4px;
    overflow: hidden;
}
.summary-progress-bar {
    height: 100%;
    width: 0%;
    background: var(--green);
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* Redesign overrides to match the premium theme */
.card.bg-dark, .modal-col.bg-dark {
    background-color: var(--bg-card) !important;
}
.bg-dark-subtle, .modal-col.bg-dark-subtle {
    background-color: var(--bg-input) !important;
}
.border-secondary, .modal-content-wide, .modal-col {
    border-color: var(--border) !important;
}
.text-primary, .text-orange {
    color: var(--red) !important;
}
.bg-primary-subtle {
    background-color: var(--red-subtle) !important;
    color: var(--red) !important;
}
.form-select, .form-control {
    background-color: var(--bg-input) !important;
    border-color: var(--border) !important;
    color: var(--text) !important;
}
.form-select:focus, .form-control:focus {
    border-color: var(--red) !important;
    box-shadow: 0 0 0 3px var(--red-glow) !important;
}
.btn-outline-secondary {
    border-color: var(--border) !important;
    color: var(--text-muted) !important;
}
.btn-outline-secondary:hover {
    background-color: var(--bg-hover) !important;
    color: var(--text) !important;
}
.btn-ghost {
    color: var(--text-muted) !important;
}
.btn-ghost:hover {
    background-color: var(--bg-hover) !important;
    color: var(--text) !important;
}
.btn-primary {
    background-color: var(--red) !important;
    border-color: var(--red) !important;
}
.btn-primary:hover {
    background-color: var(--red-dark) !important;
    border-color: var(--red-dark) !important;
    box-shadow: 0 4px 12px var(--red-glow) !important;
}
.alert-success {
    background-color: rgba(16, 185, 129, 0.1) !important;
    border-color: rgba(16, 185, 129, 0.2) !important;
    color: var(--green) !important;
}
</style>

<div class="container-fluid py-4">
    <!-- Breadcrumb -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="m-0 text-white font-weight-bold"><i class="fi fi-rr-list text-primary"></i> Quản Lý & Phê Duyệt Nhiệm Vụ</h4>
    </div>

    <!-- PHẦN 1 - BREADCRUMB + SELECTOR -->
    <div class="selector-panel">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label text-muted small uppercase fw-bold">1. Bộ truyện</label>
                <select id="seriesSelect" class="form-select form-select-lg">
                    <option value="">-- Chọn Series --</option>
                    <?php foreach ($allSeries as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label text-muted small uppercase fw-bold">2. Chương truyện</label>
                <select id="chapterSelect" class="form-select form-select-lg" disabled>
                    <option value="">-- Chọn Chapter --</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label text-muted small uppercase fw-bold">3. Trang truyện</label>
                <select id="pageSelect" class="form-select form-select-lg" disabled>
                    <option value="">-- Chọn Trang --</option>
                </select>
            </div>
        </div>
    </div>

    <!-- PHẦN 2 - NỘI DUNG CHÍNH (Hiển thị khi chọn đủ 3 dropdown) -->
    <div id="mainContentArea" class="task-manager-container" style="display: none;">
        <!-- Cột trái (60%): Canvas giao việc -->
        <div class="card bg-dark border-secondary p-0 overflow-hidden" style="position: relative; border-radius: 12px;">
            <div class="card-header border-bottom border-secondary d-flex justify-content-between align-items-center p-3">
                <h5 class="m-0 text-white"><i class="fi fi-rr-palette text-primary"></i> Bản vẽ & Vùng giao việc</h5>
                <span class="text-muted small align-self-center"><i class="fi fi-rr-info"></i> Kéo thả chuột trên ảnh để khoanh vùng</span>
            </div>
            
            <div class="canvas-wrapper" id="canvasWrapper" style="min-height: 400px; max-height: 700px;">
                <!-- Nút AI Phân đoạn đè lên canvas -->
                <button class="ai-seg-floating-btn" id="aiSegFloatingBtn" onclick="runAiSegment()">
                    🤖 AI Phân đoạn <span style="font-size: .55rem; font-weight: 800; padding: 2px 6px; border-radius: 10px; background: rgba(255,255,255,0.25); letter-spacing: 0.5px;">AI</span>
                </button>
                
                <img id="pageImage" style="max-width: 100%; display: block;" alt="Page background" />
                
                <!-- Canvas vẽ vùng chính -->
                <canvas id="taskCanvas"></canvas>
                
                <!-- Canvas overlay chứa kết quả phân tách vùng của AI -->
                <canvas id="aiSegCanvas" style="display: none; pointer-events: none;"></canvas>
            </div>
        </div>
        
        <!-- Cột phải (40%): Danh sách tasks + Form tạo mới -->
        <div class="tasks-sidebar-panel">
            <!-- THÔNG BÁO TRANG ĐÃ APPROVED -->
            <div class="alert alert-success border-success text-center mb-3" id="approvedPageMsg" style="display: none; border-radius: 12px; background: rgba(46, 204, 113, 0.15);">
                <h5 class="alert-heading text-white mb-1"><i class="fi fi-rr-checkbox text-success"></i> ĐÃ DUYỆT TRANG NÀY!</h5>
                <p class="text-muted small mb-0">Trang này đã được hoàn thành và phê duyệt toàn bộ các nhiệm vụ vẽ.</p>
            </div>
            
            <!-- FORM TẠO TASK MỚI (Hiện sau khi vẽ vùng xong) -->
            <div class="card bg-dark border-secondary mb-3 shadow" id="taskFormCard" style="display: none; border-radius: 12px;">
                <div class="card-header border-bottom border-secondary bg-primary-subtle text-primary p-3 d-flex justify-content-between align-items-center">
                    <strong class="text-white"><i class="fi fi-rr-add text-primary"></i> GIAO NHIỆM VỤ MỚI</strong>
                    <span class="badge bg-primary" id="regionCoords">Coords</span>
                </div>
                <form id="createTaskForm" onsubmit="submitCreateTask(event)" class="card-body p-3">
                    <input type="hidden" id="coordX" />
                    <input type="hidden" id="coordY" />
                    <input type="hidden" id="coordW" />
                    <input type="hidden" id="coordH" />
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Chọn Trợ lý</label>
                        <select id="taskAssistant" class="form-select" required>
                            <option value="">-- Chọn Trợ lý --</option>
                            <?php foreach ($activeAssistants as $ast): ?>
                                <option value="<?= $ast['id'] ?>"><?= htmlspecialchars($ast['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Loại việc</label>
                        <select id="taskType" class="form-select">
                            <option value="background">🎨 Tô màu nền (background)</option>
                            <option value="shading">🌑 Tô bóng (shading)</option>
                            <option value="effects">✨ Hiệu ứng (effects)</option>
                            <option value="lettering">💬 Chữ/Thoại (lettering)</option>
                            <option value="cleanup">🧹 Làm sạch (cleanup)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Mô tả công việc (min 20 ký tự)</label>
                        <textarea id="taskDescription" class="form-control" rows="3" placeholder="VD: Tô nền bầu trời màu hoàng hôn, gradient từ cam sang tím" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Hạn chót</label>
                        <input type="date" id="taskDueDate" class="form-control" />
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Đơn giá nhiệm vụ (VND)</label>
                        <input type="number" id="taskPrice" class="form-control" placeholder="100000" min="0" required />
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-ghost" onclick="cancelTaskCreate()">Hủy</button>
                        <button type="submit" class="btn btn-primary">Giao việc</button>
                    </div>
                </form>
            </div>
            
            <!-- DANH SÁCH TASKS ĐÃ TẠO -->
            <div class="card bg-dark border-secondary shadow animate-fadeIn" style="border-radius: 12px;">
                <div class="card-header border-bottom border-secondary p-3">
                    <h6 class="m-0 text-white font-weight-bold" id="taskCountHeader">Tasks trang này (0 tasks | 0 cần review)</h6>
                </div>
                
                <div class="p-3 border-bottom border-secondary bg-dark-subtle">
                    <div class="filter-tabs-wrapper">
                        <div class="filter-tab active" data-filter="all"><i class="fi fi-rr-list"></i> Tất cả</div>
                        <div class="filter-tab" data-filter="pending"><i class="fi fi-rr-clock"></i> Chờ</div>
                        <div class="filter-tab" data-filter="in_progress"><i class="fi fi-rr-play"></i> Đang làm</div>
                        <div class="filter-tab" data-filter="submitted"><i class="fi fi-rr-document-signed"></i> Cần review</div>
                        <div class="filter-tab" data-filter="approved"><i class="fi fi-rr-badge-check"></i> Xong</div>
                        <div class="filter-tab" data-filter="revision"><i class="fi fi-rr-refresh"></i> Sửa</div>
                    </div>
                </div>
                
                <div class="card-body p-3 overflow-auto" id="tasksListContainer" style="max-height: 550px;">
                    <!-- Dữ liệu render động bằng JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PHẦN 3 - MODAL REVIEW CHI TIẾT -->
<div id="reviewModal">
    <div class="modal-content-wide">
        <div class="modal-header-custom">
            <h3 id="modalTaskHeader">Review Task: Type - AssistantName - v[N]</h3>
            <button class="modal-close-btn" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body-custom">
            <!-- Cột 1 (35%): Ảnh gốc với vùng highlight -->
            <div class="modal-col bg-dark-subtle text-center d-flex flex-column align-items-center justify-content-center">
                <h5 class="text-white mb-3">Vùng yêu cầu vẽ</h5>
                <div class="canvas-wrapper p-0 overflow-hidden" style="max-width: 100%; border: 1px solid rgba(255,255,255,0.1);">
                    <canvas id="modalRegionCanvas"></canvas>
                </div>
            </div>
            
            <!-- Cột 2 (35%): File kết quả của assistant -->
            <div class="modal-col bg-dark border-left border-right border-secondary d-flex flex-column justify-content-between">
                <div>
                    <h5 class="text-white mb-3 text-center">Kết quả nộp bài</h5>
                    <div id="modalFilePreview" class="text-center d-flex align-items-center justify-content-center border border-secondary rounded bg-dark-subtle p-3" style="min-height: 300px;">
                        <!-- Ảnh hoặc File icon -->
                    </div>
                </div>
                <div id="modalFileInfo">
                    <!-- Nút download, Lời nhắn, Thời gian -->
                </div>
            </div>
            
            <!-- Cột 3 (30%): Lịch sử versions + Action -->
            <div class="modal-col bg-dark d-flex flex-column justify-content-between">
                <div>
                    <h5 class="text-white mb-3">Lịch sử Versions</h5>
                    <div id="modalTimeline" class="timeline-container">
                        <!-- Timeline version list -->
                    </div>
                </div>
                <div id="modalActionsContainer" class="mt-4">
                    <!-- Action buttons -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PHẦN 4 - SUMMARY BAR (sticky bottom) -->
<div id="summaryStickyBar">
    <div class="summary-content">
        <div id="summaryText" class="text-white d-flex align-items-center gap-3">
            Trang N: 0 tasks
        </div>
        <div class="summary-progress-wrapper">
            <div id="summaryProgressBar" class="summary-progress-bar"></div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="<?= BASE_URL ?>assets/js/tasks_mangaka.js?v=<?= time() ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>