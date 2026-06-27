	<?php
/**
 * assistant/tasks.php
 * Quản lý danh sách nhiệm vụ và nộp kết quả của Trợ lý Manga (Assistant).
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php'; // Nạp hàm getDB()
require_once __DIR__ . '/../config/auth.php'; // Nạp hàm getCurrentUser()


// 1. KHỞI TẠO KẾT NỐI DB VÀ THÔNG TIN USER TRƯỚC
$db  = getDB();
// Gọi hàm getCurrentUser() (hàm này đã có sẵn theo như code sidebar của bạn)
$currentUser = getCurrentUser(); 
$uid = $currentUser['id'];

$flashMsg = '';
$flashType = 'success';
$errorTaskId = 0;

// Nhận flash từ redirect
if (isset($_GET['flash']) && $_GET['flash'] === 'success') {
    $flashMsg = 'Nộp kết quả nhiệm vụ thành công! Họa sĩ đã được thông báo.';
    $flashType = 'success';
}

/* ══════════════════════════════════════════════════
   XỬ LÝ SUBMIT KẾT QUẢ — Upload nhiều ảnh qua AJAX
   Không cần POST handler server-side vì upload qua api/upload.php
   ══════════════════════════════════════════════════ */

// 3. SAU KHI XỬ LÝ XONG LOGIC TRÊN, MỚI GỌI LAYOUT ĐỂ XUẤT HTML RA MÀN HÌNH
$pageTitle    = 'Nhiệm vụ của tôi';
$activePage   = 'tasks';
$allowedRoles = [ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

/* ══════════════════════════════════════════════════
   DANH SÁCH NHIỆM VỤ (QUERY & FILTER)
   ══════════════════════════════════════════════════ */
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['task_type'] ?? '';

$query = "
    SELECT t.id, t.task_type, t.description, t.region_data, t.status, t.due_date, t.file_result, t.created_at,
           p.original_file, p.page_number, 
           c.chapter_number, c.title AS chapter_title, 
           s.title AS series_title,
           u.username AS mangaka_name
    FROM tasks t
    JOIN pages p ON p.id = t.page_id
    JOIN chapters c ON c.id = p.chapter_id
    JOIN series s ON s.id = c.series_id
    JOIN users u ON u.id = t.assigned_by
    WHERE t.assigned_to = ?
";
$params = [$uid];

if ($filterStatus !== '') {
    $query .= " AND t.status = ?";
    $params[] = $filterStatus;
}
if ($filterType !== '') {
    $query .= " AND t.task_type = ?";
    $params[] = $filterType;
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$taskList = $stmt->fetchAll();

// TỐI ƯU HIỆU NĂNG: Lấy toàn bộ thông báo revision của user ra trước 1 lần duy nhất
$revStmt = $db->prepare("
    SELECT message 
    FROM notifications 
    WHERE user_id = ? AND type = 'task_revision' 
    ORDER BY created_at DESC
");
$revStmt->execute([$uid]);
$allRevisions = $revStmt->fetchAll(PDO::FETCH_COLUMN);

// Tổ hợp dữ liệu JSON cho Javascript xử lý Modal
$tasksJson = [];
foreach ($taskList as $task) {
    $revisionNote = '';
    
    if ($task['status'] === 'revision') {
        $notifMsg = '';
        $targetType = $task['task_type'];
        
        // Tìm thông báo khớp với task_type từ mảng đã tải sẵn (thay vì query DB)
        foreach ($allRevisions as $msg) {
            if (strpos($msg, $targetType) !== false) {
                $notifMsg = $msg;
                break; // Lấy cái mới nhất đầu tiên tìm thấy
            }
        }
        
        if ($notifMsg && strpos($notifMsg, 'Ghi chú:') !== false) {
            $parts = explode('Ghi chú:', $notifMsg);
            $revisionNote = trim($parts[1]);
        } else {
            $revisionNote = $notifMsg ?: 'Yêu cầu sửa lại từ họa sĩ.';
        }
    }

    $tasksJson[$task['id']] = [
        'id'             => $task['id'],
        'task_type'      => $task['task_type'],
        'status'         => $task['status'],
        'description'    => $task['description'],
        'region_data'    => json_decode($task['region_data'] ?? '{}', true),
        'due_date'       => $task['due_date'],
        'file_result_raw'=> $task['file_result'], // raw value from DB
        'file_result'    => $task['file_result'] ? BASE_URL . 'assets/uploads/' . $task['file_result'] : null,
        'original_file'  => $task['original_file'] ? BASE_URL . $task['original_file'] : null,
        'page_number'    => $task['page_number'],
        'chapter_number' => $task['chapter_number'],
        'chapter_title'  => $task['chapter_title'],
        'series_title'   => $task['series_title'],
        'mangaka_name'   => $task['mangaka_name'],
        'revision_note'  => $revisionNote
    ];
}

$jsTasksData = json_encode($tasksJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
/* Nhãn hiển thị */
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

<style>
/* Local Modal Styles */
.modal-backdrop {
    position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,.7); backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center;
    padding: 20px;
}
.modal-backdrop.open { display: flex; animation: fadeIn .15s ease; }
@keyframes fadeIn { from { opacity:0 } to { opacity:1 } }

.modal-box {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); max-width: 720px; width: 100%;
    overflow: hidden;
    animation: slideIn .2s ease;
}
@keyframes slideIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
}
.modal-header h3 { font-size: 1.05rem; font-weight: 700; margin: 0; }
.modal-close {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 1.4rem; padding: 2px 6px; border-radius: 6px;
    line-height: 1;
}
.modal-close:hover { color: var(--red); background: rgba(230,57,70,.1); }

.modal-body { padding: 20px; max-height: 75vh; overflow-y: auto; }
.modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 14px 20px; border-top: 1px solid var(--border);
    background: rgba(0,0,0,.2);
}

/* Canvas overlay styles */
.canvas-container {
    position: relative;
    display: inline-block;
    max-width: 100%;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--border);
    background: #05050e;
    margin-top: 8px;
    width: 100%;
}
.canvas-container img {
    display: block;
    width: 100%;
    height: auto;
}
.region-highlight {
    position: absolute;
    border: 2px dashed #E63946;
    background: rgba(230, 57, 70, 0.16);
    box-shadow: 0 0 10px rgba(230, 57, 70, 0.4);
    pointer-events: none;
}

/* ── Multi-image upload styles ── */
.img-upload-drop {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: var(--bg-input);
    position: relative;
}
.img-upload-drop:hover, .img-upload-drop.drag-over {
    border-color: var(--red);
    background: rgba(230,57,70,.06);
}
.img-upload-drop input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.img-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
    margin-top: 12px;
}
.img-preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: var(--bg-input);
}
.img-preview-item img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.img-preview-item .remove-btn {
    position: absolute; top: 3px; right: 3px;
    background: rgba(0,0,0,.7); color: #fff;
    border: none; border-radius: 50%; width: 20px; height: 20px;
    font-size: .7rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.img-preview-item .remove-btn:hover { background: var(--red); }
.img-preview-item .upload-overlay {
    position: absolute; inset: 0; background: rgba(0,0,0,.55);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; color: #fff; font-weight: 700;
}
.upload-status-bar {
    margin-top: 10px; font-size: .78rem; font-weight: 600; color: var(--text-muted);
}
/* Submitted gallery */
.result-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
    gap: 8px;
    margin-top: 10px;
}
.result-gallery-item {
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: transform .15s, border-color .15s;
}
.result-gallery-item:hover { transform: scale(1.04); border-color: rgba(230,57,70,.5); }
.result-gallery-item img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
/* Lightbox */
#asst-lightbox {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.92); backdrop-filter: blur(8px);
    display: none; align-items: center; justify-content: center;
}
#asst-lightbox.open { display: flex; }
#asst-lightbox img { max-width: 92vw; max-height: 92vh; border-radius: 8px; }
#asst-lightbox .lb-close {
    position: absolute; top: 16px; right: 20px;
    background: rgba(0,0,0,.6); border: 1px solid rgba(255,255,255,.2);
    color: #fff; font-size: 1.1rem; width: 36px; height: 36px;
    border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
#asst-lightbox .lb-prev, #asst-lightbox .lb-next {
    position: absolute; top: 50%; transform: translateY(-50%);
    background: rgba(0,0,0,.5); border: 1px solid rgba(255,255,255,.15);
    color: #fff; font-size: 1.3rem; width: 42px; height: 42px;
    border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
#asst-lightbox .lb-prev { left: 16px; }
#asst-lightbox .lb-next { right: 16px; }
</style>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>assistant/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Nhiệm vụ của tôi</span>
    </div>
    <h1>Danh Sách Nhiệm Vụ Được Phân Công</h1>
    <p>Kiểm tra yêu cầu chi tiết, tải xuống tài nguyên và tải lên kết quả hoàn thành</p>
</div>

<!-- Trình thông báo Flash -->
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?> mb-24" data-auto-dismiss="5000">
    <?= $flashType === 'error' ? '✕' : '✓' ?> <?= $flashMsg ?>
    <button class="alert-close" style="margin-left:auto; background:none; border:none; color:inherit; cursor:pointer;">×</button>
</div>
<?php endif; ?>

<!-- Thanh lọc dữ liệu -->
<div class="card mb-24" style="padding: 16px 20px;">
    <form method="GET" action="" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
        <!-- Lọc Trạng thái -->
        <div style="display:flex; align-items:center; gap:8px;">
            <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Trạng thái:</label>
            <select name="status" class="form-control" style="width:160px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                <option value="">— Tất cả —</option>
                <?php foreach ($taskStatusLabels as $val => [$lbl,]): ?>
                    <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Lọc Loại công việc -->
        <div style="display:flex; align-items:center; gap:8px;">
            <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Loại việc:</label>
            <select name="task_type" class="form-control" style="width:160px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                <option value="">— Tất cả —</option>
                <?php foreach ($taskTypeLabels as $val => [$lbl,]): ?>
                    <option value="<?= $val ?>" <?= $filterType === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($filterStatus !== '' || $filterType !== ''): ?>
            <a href="tasks.php" class="btn btn-secondary btn-sm" style="margin-left:auto;">Xóa bộ lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- Bảng danh sách tasks -->
<div class="card" style="padding:0; overflow:hidden;">
    <?php if (empty($taskList)): ?>
        <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
            <span style="font-size:3rem;">🎨</span>
            <p style="margin-top:10px;">Không tìm thấy nhiệm vụ nào phù hợp với bộ lọc.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                        <th style="padding:14px 18px;">Tác phẩm / Chương</th>
                        <th style="padding:14px 18px;">Trang</th>
                        <th style="padding:14px 18px;">Loại công việc</th>
                        <th style="padding:14px 18px;">Hạn chót</th>
                        <th style="padding:14px 18px;">Trạng thái</th>
                        <th style="padding:14px 18px; text-align:right;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taskList as $t):
                        [$typeLabel, $typeColor, $typeBg] = $taskTypeLabels[$t['task_type']] ?? ['?', '#fff', 'rgba(255,255,255,.1)'];
                        [$stLabel, $stClass] = $taskStatusLabels[$t['status']] ?? ['?', 'badge-gray'];
                        $isOverdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'approved';
                    ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                            <td style="padding:14px 18px;">
                                <div class="font-bold" style="font-size:0.9rem;"><?= htmlspecialchars($t['series_title']) ?></div>
                                <div class="text-xs text-muted">Chương <?= $t['chapter_number'] ?> · <?= htmlspecialchars($t['chapter_title']) ?></div>
                            </td>
                            <td style="padding:14px 18px;" class="font-bold">Trang <?= $t['page_number'] ?></td>
                            <td style="padding:14px 18px;">
                                <span style="display:inline-flex; align-items:center; padding:3px 9px; border-radius:100px; font-size:0.72rem; font-weight:700; background:<?= $typeBg ?>; color:<?= $typeColor ?>; white-space:nowrap;">
                                    <?= $typeLabel ?>
                                </span>
                            </td>
                            <td style="padding:14px 18px;">
                                <?php if ($t['due_date']): ?>
                                    <span class="badge <?= $isOverdue ? 'badge-red' : 'badge-gray' ?>">
                                        <?= date('d/m/Y', strtotime($t['due_date'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:14px 18px;">
                                <span class="badge <?= $stClass ?>" style="font-size:0.75rem; padding:4px 10px;"><?= $stLabel ?></span>
                            </td>
                            <td style="padding:14px 18px; text-align:right;">
                                <button onclick="openTaskModal(<?= $t['id'] ?>)" class="btn btn-secondary btn-sm">Xem chi tiết</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Chi Tiết Nhiệm Vụ -->
<div class="modal-backdrop" id="taskDetailModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Chi tiết nhiệm vụ</h3>
            <button class="modal-close" onclick="closeTaskModal()">×</button>
        </div>
        <div class="modal-body">
            <!-- Thông tin tóm tắt -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px; font-size:0.85rem;">
                <div>
                    <span class="text-muted">Bộ truyện:</span> <strong id="modalSeries" style="color:#fff;">—</strong>
                </div>
                <div>
                    <span class="text-muted">Chương:</span> <strong id="modalChapter" style="color:#fff;">—</strong>
                </div>
                <div>
                    <span class="text-muted">Loại việc:</span> <span id="modalTypeBadge" style="display:inline-flex; padding:2px 8px; border-radius:100px; font-weight:700; font-size:0.68rem;">—</span>
                </div>
                <div>
                    <span class="text-muted">Họa sĩ giao:</span> <strong id="modalMangaka" style="color:#fff;">—</strong>
                </div>
                <div>
                    <span class="text-muted">Hạn chót:</span> <span id="modalDueDate" class="badge">--/--/----</span>
                </div>
                <div>
                    <span class="text-muted">Trạng thái:</span> <span id="modalStatusBadge" class="badge">Chưa làm</span>
                </div>
            </div>

            <!-- Ghi chú / Mô tả công việc -->
            <div class="mb-16">
                <p class="text-xs text-muted font-bold" style="text-transform:uppercase;">Yêu cầu chi tiết từ Họa sĩ:</p>
                <div id="modalDesc" style="background:rgba(255,255,255,0.03); border:1px solid var(--border); border-radius:8px; padding:12px 16px; font-size:0.9rem; line-height:1.6; white-space:pre-wrap; margin-top:6px; color:var(--text);">
                    Chưa có mô tả.
                </div>
            </div>

            <!-- Yêu cầu revision (chỉ hiển thị khi status là revision) -->
            <div id="modalRevisionBox" class="alert alert-error mb-16" style="display:none; flex-direction:column; align-items:start; gap:6px;">
                <strong style="font-size:0.85rem;">↩ Yêu cầu chỉnh sửa lại từ Họa sĩ:</strong>
                <p id="modalRevisionComment" style="font-size:0.85rem; line-height:1.5; font-style:italic;">—</p>
            </div>

            <!-- Hiển thị bản vẽ gốc và vùng chọn vẽ -->
            <div class="mb-16">
                <p class="text-xs text-muted font-bold" style="text-transform:uppercase; display:flex; justify-content:space-between;">
                    <span>Vùng được giao vẽ trên Trang:</span>
                    <a id="downloadOrigLink" href="" download class="link" style="font-size:0.75rem; font-weight:700;">📥 Tải về ảnh gốc</a>
                </p>
                
                <div class="canvas-container">
                    <img id="origPageImg" src="" alt="Page original">
                    <div class="region-highlight" id="regionHighlight"></div>
                </div>
            </div>

            <!-- Nộp kết quả — Multi-image drop zone -->
            <div id="uploadResultSection" style="display:none; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <p class="text-xs text-muted font-bold mb-8" style="text-transform:uppercase;">Nộp ảnh kết quả (JPG / PNG / WEBP — nhiều ảnh):</p>
                <div class="img-upload-drop" id="imgDropZone">
                    <input type="file" id="imgFileInput" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                           multiple onchange="handleImgSelect(this.files)">
                    <div>
                        <div style="font-size:2rem;margin-bottom:8px;">🖼️</div>
                        <div style="font-size:.85rem;font-weight:700;">Kéo ảnh vào đây hoặc click để chọn</div>
                        <div class="text-xs text-muted mt-4">JPG, PNG, WEBP &mdash; không giới hạn số lượng &mdash; tối đa 20MB/ảnh</div>
                    </div>
                </div>
                <div class="img-preview-grid" id="imgPreviewGrid"></div>
                <div class="upload-status-bar" id="uploadStatusBar"></div>
                <button id="submitImgsBtn" class="btn btn-primary" style="width:100%;margin-top:12px;display:none;"
                        onclick="submitImages()">
                    🚀 Nộp Kết Quả (<span id="imgCountLabel">0</span> ảnh)
                </button>
            </div>

            <!-- Kết quả đã nộp (Gallery) -->
            <div id="submittedResultSection" style="display:none; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <p class="text-xs text-muted font-bold mb-8" style="text-transform:uppercase;">Kết quả đã nộp:</p>
                <div class="result-gallery" id="resultGallery"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeTaskModal()">Đóng</button>
        </div>
    </div>
</div>

<!-- Lightbox -->
<div id="asst-lightbox">
    <button class="lb-close" onclick="closeLightbox()">✕</button>
    <button class="lb-prev" id="lbPrev" onclick="lbNav(-1)">&#8249;</button>
    <img id="lbImg" src="" alt="">
    <button class="lb-next" id="lbNext" onclick="lbNav(1)">&#8250;</button>
</div>

<script>
/* Dữ liệu Tasks dạng JS object truyền từ PHP */
const TASKS_DATA = <?= $jsTasksData ?>;
// Nhận ID task bị lỗi từ PHP truyền xuống (nếu không có lỗi thì mặc định là 0)
const errorTaskId = 0; // Không dùng PHP POST nữa — AJAX xử lý lỗi trực tiếp
const BASE_URL_JS = <?= json_encode(BASE_URL) ?>;

// Lấy tham số task_id để tự động mở modal nếu đi từ dashboard
const urlParams    = new URLSearchParams(window.location.search);
const autoOpenTaskId = parseInt(urlParams.get('task_id') || '0');

document.addEventListener('DOMContentLoaded', function() {
    // Ưu tiên mở modal của task bị lỗi upload trước, nếu không có lỗi thì mới mở theo URL
    const targetTaskId = errorTaskId > 0 ? errorTaskId : autoOpenTaskId;
    
    if (targetTaskId > 0 && TASKS_DATA[targetTaskId]) {
        openTaskModal(targetTaskId);
    }
    // Drag & drop support
    const drop = document.getElementById('imgDropZone');
    if (drop) {
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag-over'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
        drop.addEventListener('drop', e => {
            e.preventDefault();
            drop.classList.remove('drag-over');
            handleImgSelect(e.dataTransfer.files);
        });
    }
});

/* ── State ── */
let _currentTaskId = null;
let _selectedFiles = []; // Array of File objects
let _uploadedPaths = []; // Array of paths returned by upload API

/* ── Parse file_result (string or JSON array) ── */
function parseResultUrls(task) {
    const raw = task.file_result_raw;
    if (!raw) return [];
    try {
        const arr = JSON.parse(raw);
        if (Array.isArray(arr)) {
            return arr.map(p => BASE_URL_JS + 'assets/uploads/' + p);
        }
    } catch(e) {}
    return [BASE_URL_JS + 'assets/uploads/' + raw];
}

function openTaskModal(taskId) {
    const task = TASKS_DATA[taskId];
    if (!task) return;
    _currentTaskId = taskId;
    _selectedFiles = [];
    _uploadedPaths = [];

    // Thiết lập thông tin
    document.getElementById('modalTitle').textContent = `Nhiệm vụ #${task.id} — Trang ${task.page_number}`;
    document.getElementById('modalSeries').textContent = task.series_title;
    document.getElementById('modalChapter').textContent = `Chương ${task.chapter_number}: ${task.chapter_title}`;
    document.getElementById('modalMangaka').textContent = task.mangaka_name;

    // Config hạn chót
    const dueEl = document.getElementById('modalDueDate');
    if (task.due_date) {
        dueEl.textContent = formatDate(task.due_date);
        const isOverdue = new Date(task.due_date) < new Date() && task.status !== 'approved';
        dueEl.className = isOverdue ? 'badge badge-red' : 'badge badge-gray';
    } else {
        dueEl.textContent = 'Không có hạn';
        dueEl.className = 'badge badge-gray';
    }

    // Config Loại công việc
    const typeColors = {
        'background': ['Phông nền', '#10b981', 'rgba(16,185,129,.15)'],
        'shading':    ['Đổ bóng',   '#3b82f6', 'rgba(59,130,246,.15)'],
        'effects':    ['Hiệu ứng',  '#8b5cf6', 'rgba(139,92,246,.15)'],
        'lettering':  ['Chữ/Thoại', '#f59e0b', 'rgba(245,158,11,.15)'],
        'cleanup':    ['Đi nét',    '#E63946', 'rgba(230,57,70,.15)'],
    };
    const typeBadge = document.getElementById('modalTypeBadge');
    const [tLabel, tColor, tBg] = typeColors[task.task_type] ?? ['?', '#fff', 'rgba(255,255,255,.1)'];
    typeBadge.textContent = tLabel;
    typeBadge.style.color = tColor;
    typeBadge.style.backgroundColor = tBg;

    // Config Trạng thái
    const statusBadges = {
        'pending':     ['Chờ làm',    'badge-gray'],
        'in_progress': ['Đang làm',   'badge-blue'],
        'submitted':   ['Chờ duyệt',  'badge-yellow'],
        'approved':    ['Đã duyệt',   'badge-green'],
        'revision':    ['Cần sửa lại','badge-red'],
    };
    const [stLabel, stClass] = statusBadges[task.status] ?? ['?', 'badge-gray'];
    const statusBadge = document.getElementById('modalStatusBadge');
    statusBadge.textContent = stLabel;
    statusBadge.className = `badge ${stClass}`;

    // Mô tả
    document.getElementById('modalDesc').textContent = task.description || 'Không có mô tả chi tiết kèm theo.';

    // Revision Box
    const revBox = document.getElementById('modalRevisionBox');
    if (task.status === 'revision') {
        revBox.style.display = 'flex';
        document.getElementById('modalRevisionComment').textContent = task.revision_note || 'Yêu cầu sửa lại nhưng không ghi thêm ý kiến cụ thể.';
    } else {
        revBox.style.display = 'none';
    }

    // Ảnh gốc và Vùng chọn vẽ
    const img = document.getElementById('origPageImg');
    const highlight = document.getElementById('regionHighlight');
    const downloadOrig = document.getElementById('downloadOrigLink');
    if (task.original_file) {
        img.src = task.original_file;
        downloadOrig.href = task.original_file;
        downloadOrig.style.display = 'inline-block';
        const r = task.region_data || {};
        highlight.style.left   = (r.x || 0) + '%';
        highlight.style.top    = (r.y || 0) + '%';
        highlight.style.width  = (r.w || 0) + '%';
        highlight.style.height = (r.h || 0) + '%';
        highlight.style.display = 'block';
    } else {
        img.src = '';
        downloadOrig.style.display = 'none';
        highlight.style.display = 'none';
    }

    // Reset upload section
    document.getElementById('imgPreviewGrid').innerHTML = '';
    document.getElementById('uploadStatusBar').textContent = '';
    document.getElementById('submitImgsBtn').style.display = 'none';
    document.getElementById('imgCountLabel').textContent = '0';

    // Upload Section
    const uploadSec = document.getElementById('uploadResultSection');
    uploadSec.style.display = ['pending', 'in_progress', 'revision'].includes(task.status) ? 'block' : 'none';

    // Submitted gallery
    const submittedSec = document.getElementById('submittedResultSection');
    const gallery = document.getElementById('resultGallery');
    const resultUrls = parseResultUrls(task);
    if (resultUrls.length > 0) {
        submittedSec.style.display = 'block';
        gallery.innerHTML = '';
        resultUrls.forEach((url, i) => {
            const item = document.createElement('div');
            item.className = 'result-gallery-item';
            item.onclick = () => openLightbox(resultUrls, i);
            item.innerHTML = `<img src="${url}" alt="Kết quả ${i+1}" loading="lazy">`;
            gallery.appendChild(item);
        });
    } else {
        submittedSec.style.display = 'none';
    }

    // Mở modal
    document.getElementById('taskDetailModal').classList.add('open');
}

/* ── Multi-image select & preview ── */
function handleImgSelect(files) {
    if (!files || files.length === 0) return;
    const grid = document.getElementById('imgPreviewGrid');
    Array.from(files).forEach(file => {
        if (!file.type.startsWith('image/')) return;
        if (file.size > 20 * 1024 * 1024) {
            alert(`Ảnh "${file.name}" quá 20MB, bỏ qua.`);
            return;
        }
        _selectedFiles.push(file);
        const idx = _selectedFiles.length - 1;
        const reader = new FileReader();
        reader.onload = e => {
            const item = document.createElement('div');
            item.className = 'img-preview-item';
            item.id = `prev-${idx}`;
            item.innerHTML = `
                <img src="${e.target.result}" alt="">
                <button class="remove-btn" onclick="removeImg(${idx})">×</button>
            `;
            grid.appendChild(item);
        };
        reader.readAsDataURL(file);
    });
    updateSubmitBtn();
}

function removeImg(idx) {
    _selectedFiles[idx] = null; // mark removed
    const el = document.getElementById(`prev-${idx}`);
    if (el) el.remove();
    updateSubmitBtn();
}

function updateSubmitBtn() {
    const count = _selectedFiles.filter(f => f !== null).length;
    document.getElementById('imgCountLabel').textContent = count;
    document.getElementById('submitImgsBtn').style.display = count > 0 ? 'block' : 'none';
}

/* ── Upload & Submit ── */
async function submitImages() {
    const files = _selectedFiles.filter(f => f !== null);
    if (files.length === 0) return;

    const btn = document.getElementById('submitImgsBtn');
    btn.disabled = true;
    const statusBar = document.getElementById('uploadStatusBar');
    statusBar.textContent = `Đang upload 0/${files.length} ảnh...`;

    const uploadedPaths = [];
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        statusBar.textContent = `Đang upload ${i+1}/${files.length}: ${file.name}...`;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('upload_type', 'task_result');
        try {
            const res  = await fetch(BASE_URL_JS + 'api/upload.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                uploadedPaths.push(json.data.path);
                // Mark preview as done
                const prevEl = document.getElementById(`prev-${_selectedFiles.indexOf(file)}`);
                if (prevEl) {
                    const ov = document.createElement('div');
                    ov.className = 'upload-overlay';
                    ov.textContent = '✓ Xong';
                    prevEl.appendChild(ov);
                }
            } else {
                statusBar.textContent = `Lỗi upload "${file.name}": ${json.message}`;
                btn.disabled = false;
                return;
            }
        } catch(err) {
            statusBar.textContent = `Lỗi kết nối: ${err.message}`;
            btn.disabled = false;
            return;
        }
    }

    // Submit task
    statusBar.textContent = 'Xác nhận nộp kết quả...';
    try {
        const fd2 = new FormData();
        fd2.append('action', 'submit_task');
        fd2.append('task_id', _currentTaskId);
        fd2.append('file_paths', JSON.stringify(uploadedPaths));
        const res2  = await fetch(BASE_URL_JS + 'api/tasks.php', { method: 'POST', body: fd2 });
        const json2 = await res2.json();
        if (json2.success) {
            statusBar.textContent = `✔ Nộp thành công ${uploadedPaths.length} ảnh!`;
            statusBar.style.color = '#10b981';
            btn.textContent = 'Đã nộp ✓';
            setTimeout(() => window.location.reload(), 1200);
        } else {
            statusBar.textContent = 'Lỗi nộp: ' + json2.message;
            btn.disabled = false;
        }
    } catch(err) {
        statusBar.textContent = 'Lỗi kết nối: ' + err.message;
        btn.disabled = false;
    }
}

/* ── Lightbox ── */
let _lbUrls = [], _lbIdx = 0;
function openLightbox(urls, idx) {
    _lbUrls = urls;
    _lbIdx  = idx;
    document.getElementById('lbImg').src = urls[idx];
    document.getElementById('lbPrev').style.display = urls.length > 1 ? 'flex' : 'none';
    document.getElementById('lbNext').style.display = urls.length > 1 ? 'flex' : 'none';
    document.getElementById('asst-lightbox').classList.add('open');
}
function closeLightbox() {
    document.getElementById('asst-lightbox').classList.remove('open');
    document.getElementById('lbImg').src = '';
}
function lbNav(dir) {
    _lbIdx = (_lbIdx + dir + _lbUrls.length) % _lbUrls.length;
    document.getElementById('lbImg').src = _lbUrls[_lbIdx];
}
document.getElementById('asst-lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});

function closeTaskModal() {
    document.getElementById('taskDetailModal').classList.remove('open');
    const url = new URL(window.location);
    url.searchParams.delete('task_id');
    window.history.replaceState({}, '', url);
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('vi-VN');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
