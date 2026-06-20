<?php
/**
 * api/tasks.php
 * AJAX endpoint xử lý tác vụ: create_task, review_task
 * Trả về JSON.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Enforce JSON response always
header('Content-Type: application/json; charset=utf-8');

// Helper: gửi JSON và thoát
function jsonOut(bool $ok, string $msg = '', array $data = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit();
}

// Chỉ chấp nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'Phương thức không hợp lệ.');
}

// Phải đã đăng nhập
if (!isLoggedIn()) {
    http_response_code(401);
    jsonOut(false, 'Chưa đăng nhập.');
}

$currentUser = getCurrentUser();
$db          = getDB();
$action      = trim($_POST['action'] ?? '');

/* ══════════════════════════════════════════════════════════
   ACTION: create_task
   Chỉ mangaka được giao việc
   ══════════════════════════════════════════════════════════ */
if ($action === 'create_task') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonOut(false, 'Chỉ họa sĩ Manga mới được giao việc.');
    }

    // Validate inputs
    $pageId      = (int)($_POST['page_id']     ?? 0);
    $assignedTo  = (int)($_POST['assigned_to'] ?? 0);
    $taskType    = trim($_POST['task_type']    ?? '');
    $description = trim($_POST['description']  ?? '');
    $dueDate     = trim($_POST['due_date']     ?? '');
    $regionX     = (float)($_POST['region_x']  ?? 0);
    $regionY     = (float)($_POST['region_y']  ?? 0);
    $regionW     = (float)($_POST['region_w']  ?? 0);
    $regionH     = (float)($_POST['region_h']  ?? 0);

    // Basic validations
    if ($pageId <= 0) {
        jsonOut(false, 'Trang không hợp lệ.');
    }
    if ($assignedTo <= 0) {
        jsonOut(false, 'Chưa chọn trợ lý.');
    }
    $validTypes = ['background', 'shading', 'effects', 'lettering', 'cleanup'];
    if (!in_array($taskType, $validTypes, true)) {
        jsonOut(false, 'Loại công việc không hợp lệ.');
    }
    if ($regionW < 1 || $regionH < 1) {
        jsonOut(false, 'Vùng chọn quá nhỏ hoặc chưa vẽ.');
    }
    if (!empty($dueDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        jsonOut(false, 'Ngày deadline không đúng định dạng.');
    }

    // Verify page belongs to one of this mangaka's series
    $stmt = $db->prepare(
        "SELECT p.id FROM pages p
         JOIN chapters c ON c.id = p.chapter_id
         JOIN series   s ON s.id = c.series_id
         WHERE p.id = ? AND s.mangaka_id = ?
         LIMIT 1"
    );
    $stmt->execute([$pageId, $currentUser['id']]);
    if (!$stmt->fetch()) {
        jsonOut(false, 'Không có quyền giao việc trên trang này.');
    }

    // Verify assignee is an assistant
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'assistant' LIMIT 1");
    $stmt->execute([$assignedTo]);
    if (!$stmt->fetch()) {
        jsonOut(false, 'Trợ lý không tồn tại.');
    }

    // Clamp coords to [0, 100]
    $clamp = fn($v) => max(0.0, min(100.0, (float)$v));
    $regionData = json_encode([
        'x' => round($clamp($regionX), 2),
        'y' => round($clamp($regionY), 2),
        'w' => round($clamp($regionW), 2),
        'h' => round($clamp($regionH), 2),
    ]);

    // Insert task
    $stmt = $db->prepare(
        "INSERT INTO tasks
            (page_id, assigned_to, assigned_by, task_type, description, region_data, status, due_date)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
    );
    $stmt->execute([
        $pageId,
        $assignedTo,
        $currentUser['id'],
        $taskType,
        $description ?: null,
        $regionData,
        $dueDate ?: null,
    ]);
    $newTaskId = (int)$db->lastInsertId();

    // Update page status to in_progress if still pending
    $stmt = $db->prepare(
        "UPDATE pages SET status = 'in_progress'
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$pageId]);

    // Create notification for assistant
    $mangakaName = $currentUser['username'];
    $notifMsg    = "Họa sĩ $mangakaName đã giao cho bạn nhiệm vụ ($taskType) trên trang.";
    $stmt = $db->prepare(
        "INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'task_assigned', ?, 'assistant/tasks.php')"
    );
    $stmt->execute([$assignedTo, $notifMsg]);

    jsonOut(true, 'Giao việc thành công!', ['task_id' => $newTaskId]);
}

/* ══════════════════════════════════════════════════════════
   ACTION: review_task
   Mangaka duyệt hoặc yêu cầu sửa task đã submitted
   ══════════════════════════════════════════════════════════ */
if ($action === 'review_task') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonOut(false, 'Chỉ họa sĩ Manga mới được duyệt task.');
    }

    $taskId  = (int)($_POST['task_id'] ?? 0);
    $review  = trim($_POST['review']   ?? '');
    $comment = trim($_POST['comment']  ?? '');

    if ($taskId <= 0) { jsonOut(false, 'Task không hợp lệ.'); }
    if (!in_array($review, ['approve', 'revision'], true)) {
        jsonOut(false, 'Hành động không hợp lệ.');
    }

    // Verify task belongs to this mangaka and can be reviewed
    $stmt = $db->prepare(
        "SELECT t.id, t.status, t.page_id, t.assigned_to, t.task_type,
                p.chapter_id, s.mangaka_id
         FROM tasks t
         JOIN pages   p ON p.id = t.page_id
         JOIN chapters c ON c.id = p.chapter_id
         JOIN series   s ON s.id = c.series_id
         WHERE t.id = ? AND s.mangaka_id = ?
         LIMIT 1"
    );
    $stmt->execute([$taskId, $currentUser['id']]);
    $task = $stmt->fetch();
    if (!$task) {
        jsonOut(false, 'Không tìm thấy task hoặc không có quyền.');
    }

    // Determine new task status
    $newStatus = ($review === 'approve') ? 'approved' : 'revision';

    // Update task
    $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $taskId]);

    // If approved: check if ALL tasks on this page are approved → mark page approved
    if ($newStatus === 'approved') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM tasks WHERE page_id = ? AND status != 'approved'"
        );
        $stmt->execute([$task['page_id']]);
        $remaining = (int)$stmt->fetchColumn();
        if ($remaining === 0) {
            $db->prepare("UPDATE pages SET status = 'approved' WHERE id = ?")
               ->execute([$task['page_id']]);
        }
    } elseif ($newStatus === 'revision') {
        // Mark page back to in_progress
        $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ?")
           ->execute([$task['page_id']]);
    }

    // Notify assistant
    $mangakaName = $currentUser['username'];
    if ($newStatus === 'approved') {
        $notifMsg  = "Họa sĩ $mangakaName đã DUYỆT nhiệm vụ ({$task['task_type']}) của bạn!";
        $notifType = 'task_approved';
    } else {
        $notifMsg  = "Họa sĩ $mangakaName yêu cầu SỬA LẠI nhiệm vụ ({$task['task_type']})." . ($comment ? " Ghi chú: $comment" : '');
        $notifType = 'task_revision';
    }
    $stmt = $db->prepare(
        "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, 'assistant/tasks.php')"
    );
    $stmt->execute([$task['assigned_to'], $notifType, $notifMsg]);

    jsonOut(true, $newStatus === 'approved' ? 'Đã duyệt nhiệm vụ!' : 'Đã yêu cầu sửa lại!', [
        'new_status' => $newStatus,
        'task_id'    => $taskId,
    ]);
}

/* ══════════════════════════════════════════════════════════
   ACTION: get_page_tasks
   Trả về tasks của 1 trang (dùng cho AJAX refresh region overlay)
   ══════════════════════════════════════════════════════════ */
if ($action === 'get_page_tasks') {
    $pageId = (int)($_POST['page_id'] ?? 0);
    if ($pageId <= 0) { jsonOut(false, 'Page ID không hợp lệ.'); }

    $stmt = $db->prepare(
        "SELECT t.id, t.task_type, t.region_data, t.status, t.description,
                u.username AS assistant_name
         FROM tasks t
         JOIN users u ON u.id = t.assigned_to
         JOIN pages p ON p.id = t.page_id
         JOIN chapters c ON c.id = p.chapter_id
         JOIN series s ON s.id = c.series_id
         WHERE t.page_id = ? AND s.mangaka_id = ?"
    );
    $stmt->execute([$pageId, $currentUser['id']]);
    $tasks = $stmt->fetchAll();

    // Decode region_data JSON strings to arrays
    foreach ($tasks as &$t) {
        $t['region_data'] = json_decode($t['region_data'] ?? 'null', true);
    }
    unset($t);

    jsonOut(true, '', ['tasks' => $tasks]);
}

// Unknown action
jsonOut(false, 'Hành động không được hỗ trợ.');
