<?php
/**
 * assistant/tasks.php
 * Quản lý danh sách nhiệm vụ và nộp kết quả của Trợ lý Manga (Assistant).
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Nhiệm vụ của tôi';
$activePage   = 'tasks';
$allowedRoles = [ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

$flashMsg = '';
$flashType = 'success';

// Nhận flash từ redirect
if (isset($_GET['flash']) && $_GET['flash'] === 'success') {
    $flashMsg = 'Nộp kết quả nhiệm vụ thành công! Họa sĩ đã được thông báo.';
    $flashType = 'success';
}

/* ══════════════════════════════════════════════════
   XỬ LÝ SUBMIT FILE KẾT QUẢ (POST)
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_task_result') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    
    // Kiểm tra task hợp lệ và thuộc về trợ lý này
    $stmt = $db->prepare(
        "SELECT t.*, p.page_number, p.chapter_id, p.id AS page_id, s.mangaka_id 
         FROM tasks t 
         JOIN pages p ON p.id = t.page_id 
         JOIN chapters c ON c.id = p.chapter_id 
         JOIN series s ON s.id = c.series_id 
         WHERE t.id = ? AND t.assigned_to = ?"
    );
    $stmt->execute([$taskId, $uid]);
    $task = $stmt->fetch();
    
    if (!$task) {
        $flashMsg = 'Nhiệm vụ không tồn tại hoặc bạn không có quyền nộp.';
        $flashType = 'error';
    } elseif (!in_array($task['status'], ['pending', 'in_progress', 'revision'])) {
        $flashMsg = 'Nhiệm vụ này đã được nộp hoặc đã được duyệt.';
        $flashType = 'error';
    } elseif (!isset($_FILES['file_result']) || $_FILES['file_result']['error'] === UPLOAD_ERR_NO_FILE) {
        $flashMsg = 'Vui lòng chọn tệp kết quả để nộp.';
        $flashType = 'error';
    } else {
        $file = $_FILES['file_result'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $flashMsg = 'Lỗi tải lên tệp: code ' . $file['error'];
            $flashType = 'error';
        } elseif ($file['size'] > 52428800) { // 50MB
            $flashMsg = 'Kích thước tệp vượt quá giới hạn 50MB.';
            $flashType = 'error';
        } else {
            // Kiểm tra loại tệp
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            
            $allowedMimes = [
                'image/png' => 'png',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip',
                // Photoshop
                'image/vnd.adobe.photoshop' => 'psd',
                'image/photoshop' => 'psd',
                'application/photoshop' => 'psd',
                'application/x-photoshop' => 'psd',
                'application/octet-stream' => 'psd',
            ];
            
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $isMimeValid = false;
            
            if (isset($allowedMimes[$mime])) {
                $isMimeValid = true;
                if ($mime === 'application/octet-stream' && $ext !== 'psd') {
                    $isMimeValid = false;
                }
            }
            
            if (!$isMimeValid && !in_array($ext, ['png', 'psd', 'zip'], true)) {
                $flashMsg = "Loại tệp không hợp lệ ($mime). Chỉ cho phép tệp PNG, PSD, ZIP.";
                $flashType = 'error';
            } else {
                if (!in_array($ext, ['png', 'psd', 'zip'], true)) {
                    $ext = $allowedMimes[$mime];
                }
                
                $filename = 'task_' . $taskId . '_' . uniqid() . '.' . $ext;
                $destDir  = UPLOAD_PATH . 'tasks';
                $destPath = $destDir . '/' . $filename;
                
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    // Cập nhật Database
                    $update = $db->prepare("UPDATE tasks SET file_result = ?, status = 'submitted' WHERE id = ?");
                    $update->execute(['tasks/' . $filename, $taskId]);
                    
                    // Gửi thông báo cho Họa sĩ (Mangaka)
                    $assistantName = $currentUser['username'];
                    $notifMsg = "Trợ lý $assistantName đã NỘP kết quả cho nhiệm vụ ({$task['task_type']}) trên Trang {$task['page_number']}.";
                    $link = "mangaka/tasks.php?chapter_id={$task['chapter_id']}&page_id={$task['page_id']}";
                    
                    $notif = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'task_submitted', ?, ?)");
                    $notif->execute([$task['assigned_by'], $notifMsg, $link]);
                    
                    // Chuyển hướng tránh double submit
                    header('Location: ' . BASE_URL . 'assistant/tasks.php?flash=success');
                    exit();
                } else {
                    $flashMsg = 'Không thể lưu tệp kết quả tải lên.';
                    $flashType = 'error';
                }
            }
        }
    }
}

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

// Tổ hợp dữ liệu JSON cho Javascript xử lý Modal
$tasksJson = [];
foreach ($taskList as $task) {
    $revisionNote = '';
    if ($task['status'] === 'revision') {
        // Tìm thông báo yêu cầu sửa đổi liên quan đến nhiệm vụ này
        try {
            $rStmt = $db->prepare("
                SELECT message FROM notifications 
                WHERE user_id = ? AND type = 'task_revision' AND message LIKE ? 
                ORDER BY created_at DESC LIMIT 1
            ");
            $rStmt->execute([$uid, "%" . $task['task_type'] . "%"]);
            $notifMsg = $rStmt->fetchColumn();
            
            if ($notifMsg && strpos($notifMsg, 'Ghi chú:') !== false) {
                $parts = explode('Ghi chú:', $notifMsg);
                $revisionNote = trim($parts[1]);
            } else {
                $revisionNote = $notifMsg ?: 'Yêu cầu sửa lại từ họa sĩ.';
            }
        } catch (\Throwable $e) {}
    }

    $tasksJson[$task['id']] = [
        'id'             => $task['id'],
        'task_type'      => $task['task_type'],
        'status'         => $task['status'],
        'description'    => $task['description'],
        'region_data'    => json_decode($task['region_data'] ?? '{}', true),
        'due_date'       => $task['due_date'],
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

$jsTasksData = json_encode($tasksJson);

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

            <!-- Nộp kết quả (Form upload) -->
            <div id="uploadResultSection" style="display:none; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <p class="text-xs text-muted font-bold mb-8" style="text-transform:uppercase;">Nộp tệp kết quả (PNG, PSD hoặc ZIP):</p>
                <form id="submitResultForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_task_result">
                    <input type="hidden" name="task_id" id="submitTaskId" value="">

                    <div class="form-group">
                        <input type="file" name="file_result" class="form-control" accept=".png,.psd,.zip" required>
                        <p class="text-xs text-muted mt-8">Hỗ trợ các file vẽ nét đứt/shading PNG, file Photoshop gốc (PSD) hoặc file ZIP chứa thư mục (tối đa 50MB).</p>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        🚀 Nộp Kết Quả
                    </button>
                </form>
            </div>

            <!-- Kết quả đã nộp (Nếu có) -->
            <div id="submittedResultSection" style="display:none; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <p class="text-xs text-muted font-bold mb-8" style="text-transform:uppercase;">Kết quả đã tải lên trước đó:</p>
                <div style="display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.03); padding:10px 14px; border-radius:8px; border:1px solid var(--border);">
                    <div style="font-size:1.5rem;">📄</div>
                    <div style="min-width:0; flex:1;">
                        <div class="text-sm font-bold truncate">File sản phẩm kết quả</div>
                        <div class="text-xs text-muted" id="submittedTimeText">Nhấn nút bên phải để tải về kiểm tra</div>
                    </div>
                    <a id="downloadResultLink" href="" download class="btn btn-secondary btn-sm">Tải về</a>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeTaskModal()">Đóng</button>
        </div>
    </div>
</div>

<script>
/* Dữ liệu Tasks dạng JS object truyền từ PHP */
const TASKS_DATA = <?= $jsTasksData ?>;

// Lấy tham số task_id để tự động mở modal nếu đi từ dashboard
const urlParams = new URLSearchParams(window.location.search);
const autoOpenTaskId = parseInt(urlParams.get('task_id') || '0');

document.addEventListener('DOMContentLoaded', function() {
    if (autoOpenTaskId > 0 && TASKS_DATA[autoOpenTaskId]) {
        openTaskModal(autoOpenTaskId);
    }
});

function openTaskModal(taskId) {
    const task = TASKS_DATA[taskId];
    if (!task) return;

    // Thiết lập thông tin
    document.getElementById('modalTitle').textContent = `Nhiệm vụ #${task.id} — Trang ${task.page_number}`;
    document.getElementById('modalSeries').textContent = task.series_title;
    document.getElementById('modalChapter').textContent = `Chương ${task.chapter_number}: ${task.chapter_title}`;
    document.getElementById('modalMangaka').textContent = task.mangaka_name;
    document.getElementById('submitTaskId').value = task.id;

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

    // Config Loại công việc (Badge style)
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
        
        // Vẽ ô vùng chọn (region_data chứa x, y, w, h dạng tỷ lệ %)
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

    // Section Nộp sản phẩm (Chỉ hiển thị khi status cho phép sửa/nộp)
    const uploadSec = document.getElementById('uploadResultSection');
    if (['pending', 'in_progress', 'revision'].includes(task.status)) {
        uploadSec.style.display = 'block';
    } else {
        uploadSec.style.display = 'none';
    }

    // Section Sản phẩm đã nộp trước đó
    const submittedSec = document.getElementById('submittedResultSection');
    const downloadRes = document.getElementById('downloadResultLink');
    if (task.file_result) {
        submittedSec.style.display = 'block';
        downloadRes.href = task.file_result;
    } else {
        submittedSec.style.display = 'none';
    }

    // Mở modal
    document.getElementById('taskDetailModal').classList.add('open');
}

function closeTaskModal() {
    document.getElementById('taskDetailModal').classList.remove('open');
    
    // Xóa tham số URL để tránh tự động mở lại khi load trang sau
    const url = new URL(window.location);
    url.searchParams.delete('task_id');
    window.history.replaceState({}, '', url);
}

// Định dạng ngày
function formatDate(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
