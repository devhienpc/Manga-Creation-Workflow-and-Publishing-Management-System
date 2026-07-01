<?php
/**
 * api/tasks.php
 *
 * GET  → Lấy danh sách tasks (với bộ lọc)
 *         ?page_id=<int>    Tasks của một trang
 *         ?assigned_to=<int> Tasks của assistant cụ thể
 *         ?status=<string>  Lọc trạng thái
 *         ?my_tasks=1       Tasks được giao cho tôi (role=assistant)
 *
 * POST → Tạo / cập nhật task
 *         action=create_task   (mangaka only)
 *         action=review_task   (mangaka only)
 *         action=submit_task   (assistant only) — nộp kết quả
 *         action=get_page_tasks
 *
 * PUT  → Cập nhật status hoặc file_result qua JSON body
 *         { task_id, status?, file_result?, comment? }
 *
 * Response: { "success": bool, "data": {...}, "message": "..." }
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => null, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$db          = getDB();

// ── Helper ────────────────────────────────────────────
function taskOut(bool $ok, $data = null, string $msg = '', int $httpCode = 0): void {
    if ($httpCode > 0) http_response_code($httpCode);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit();
}

$validTaskTypes   = ['background', 'shading', 'effects', 'lettering', 'cleanup'];
$validTaskStatuses = ['pending', 'in_progress', 'submitted', 'approved', 'revision'];

// ═══════════════════════════════════════════════════════
// GET — Lấy danh sách tasks
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where  = ['1=1'];
    $params = [];

    $pageId      = (int)($_GET['page_id']      ?? 0);
    $assignedTo  = (int)($_GET['assigned_to']  ?? 0);
    $filterStatus = trim($_GET['status']       ?? '');
    $myTasks     = !empty($_GET['my_tasks']) && $_GET['my_tasks'] === '1';
    $seriesId    = (int)($_GET['series_id']    ?? 0);
    $limit       = min(200, max(1, (int)($_GET['limit'] ?? 50)));

    // my_tasks: assistant chỉ thấy tasks của mình
    if ($myTasks && $currentUser['role'] === ROLES['ASSISTANT']) {
        $where[]  = 't.assigned_to = ?';
        $params[] = $currentUser['id'];
    } elseif ($assignedTo > 0) {
        $where[]  = 't.assigned_to = ?';
        $params[] = $assignedTo;
    }

    if ($pageId > 0) {
        $where[]  = 't.page_id = ?';
        $params[] = $pageId;
    }

    if ($seriesId > 0) {
        $where[]  = 's.id = ?';
        $params[] = $seriesId;
    }

    if ($filterStatus !== '' && in_array($filterStatus, $validTaskStatuses, true)) {
        $where[]  = 't.status = ?';
        $params[] = $filterStatus;
    }

    // Mangaka chỉ thấy tasks thuộc series của mình
    if ($currentUser['role'] === ROLES['MANGAKA'] && !$myTasks) {
        $where[]  = 's.mangaka_id = ?';
        $params[] = $currentUser['id'];
    }

    $whereSQL = implode(' AND ', $where);

    try {
        $stmt = $db->prepare(
            "SELECT t.id, t.task_type, t.description, t.status, t.due_date,
                    t.file_result, t.region_data, t.created_at,
                    p.page_number, p.chapter_id,
                    c.chapter_number, c.title AS chapter_title,
                    s.id AS series_id, s.title AS series_title,
                    u_assigned.id   AS assigned_to_id,
                    u_assigned.username AS assigned_to_name,
                    u_by.username   AS assigned_by_name
             FROM tasks t
             JOIN pages    p ON p.id = t.page_id
             JOIN chapters c ON c.id = p.chapter_id
             JOIN series   s ON s.id = c.series_id
             JOIN users u_assigned ON u_assigned.id = t.assigned_to
             JOIN users u_by       ON u_by.id       = t.assigned_by
             WHERE {$whereSQL}
             ORDER BY t.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();

        foreach ($tasks as &$t) {
            $t['region_data'] = json_decode($t['region_data'] ?? 'null', true);
        }
        unset($t);

        taskOut(true, ['tasks' => $tasks, 'count' => count($tasks)], '');
    } catch (\Throwable $e) {
        taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// PUT — Cập nhật task (status / file_result)
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $taskId     = (int)($body['task_id']     ?? 0);
    $newStatus  = trim($body['status']       ?? '');
    $fileResult = trim($body['file_result']  ?? '');
    $comment    = trim($body['comment']      ?? '');

    if ($taskId <= 0) taskOut(false, null, 'task_id không hợp lệ.', 422);

    // Fetch task + verify ownership
    try {
        $stmt = $db->prepare(
            "SELECT t.id, t.status, t.page_id, t.assigned_to, t.assigned_by,
                    t.task_type, s.mangaka_id
             FROM tasks t
             JOIN pages    p ON p.id = t.page_id
             JOIN chapters c ON c.id = p.chapter_id
             JOIN series   s ON s.id = c.series_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) taskOut(false, null, 'Task không tồn tại.', 404);

        // Quyền: mangaka (owner) hoặc assistant được giao
        $isMangaka   = $currentUser['role'] === ROLES['MANGAKA'] && (int)$task['mangaka_id'] === $currentUser['id'];
        $isAssistant = $currentUser['role'] === ROLES['ASSISTANT'] && (int)$task['assigned_to'] === $currentUser['id'];
        $isEditor    = $currentUser['role'] === ROLES['EDITOR'];

        if (!$isMangaka && !$isAssistant && !$isEditor) {
            taskOut(false, null, 'Không có quyền chỉnh sửa task này.', 403);
        }

        $setClauses = [];
        $setParams  = [];

        // Validate + apply status change
        if ($newStatus !== '') {
            if (!in_array($newStatus, $validTaskStatuses, true)) {
                taskOut(false, null, 'Trạng thái không hợp lệ.', 422);
            }

            // Business rules
            if ($newStatus === 'submitted' && !$isAssistant) {
                taskOut(false, null, 'Chỉ assistant mới được nộp kết quả.', 403);
            }
            if (in_array($newStatus, ['approved', 'revision']) && !$isMangaka) {
                taskOut(false, null, 'Chỉ mangaka mới được duyệt/yêu cầu sửa.', 403);
            }

            $setClauses[] = 'status = ?';
            $setParams[]  = $newStatus;

            // Auto-update page status
            if ($newStatus === 'approved') {
                // Check all tasks on the page approved
                $remaining = (int)$db->prepare(
                    "SELECT COUNT(*) FROM tasks WHERE page_id = ? AND id != ? AND status != 'approved'"
                )->execute([$task['page_id'], $taskId]) ? 0 : 0;
                $chkStmt = $db->prepare(
                    "SELECT COUNT(*) FROM tasks WHERE page_id = ? AND id != ? AND status != 'approved'"
                );
                $chkStmt->execute([$task['page_id'], $taskId]);
                if ((int)$chkStmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE pages SET status = 'approved' WHERE id = ?")
                       ->execute([$task['page_id']]);
                }
            } elseif ($newStatus === 'revision') {
                $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ?")
                   ->execute([$task['page_id']]);
            } elseif ($newStatus === 'in_progress') {
                $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
                   ->execute([$task['page_id']]);
            }
        }

        // file_result
        if ($fileResult !== '') {
            $setClauses[] = 'file_result = ?';
            $setParams[]  = $fileResult;
        }

        if (empty($setClauses)) {
            taskOut(false, null, 'Không có trường nào để cập nhật.', 422);
        }

        $setParams[] = $taskId;
        $db->prepare("UPDATE tasks SET " . implode(', ', $setClauses) . " WHERE id = ?")
           ->execute($setParams);

        // Notification
        if ($newStatus !== '' && $newStatus !== $task['status']) {
            $notifTarget = null; $notifMsg = ''; $notifType = '';
            $username = $currentUser['username'];

            if ($newStatus === 'submitted') {
                $notifTarget = (int)$task['assigned_by'];
                $notifMsg    = "Trợ lý {$username} đã NỘP KẾT QUẢ nhiệm vụ ({$task['task_type']}).";
                $notifType   = 'task_submitted';
            } elseif ($newStatus === 'approved') {
                $notifTarget = (int)$task['assigned_to'];
                $notifMsg    = "Họa sĩ {$username} đã DUYỆT nhiệm vụ ({$task['task_type']}) của bạn!";
                $notifType   = 'task_approved';
            } elseif ($newStatus === 'revision') {
                $notifTarget = (int)$task['assigned_to'];
                $notifMsg    = "Họa sĩ {$username} yêu cầu SỬA LẠI nhiệm vụ ({$task['task_type']})." . ($comment ? " Ghi chú: {$comment}" : '');
                $notifType   = 'task_revision';
            }

            if ($notifTarget) {
                $db->prepare(
                    "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, 'assistant/tasks.php')"
                )->execute([$notifTarget, $notifType, $notifMsg]);
            }
        }

        taskOut(true, ['task_id' => $taskId, 'new_status' => $newStatus ?: $task['status']], 'Đã cập nhật task.');
    } catch (\Throwable $e) {
        taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// POST — Tạo task / Hành động
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $body = $_POST;
    }

    $action = trim($body['action'] ?? '');

    // ── create_task ────────────────────────────────────
    if ($action === 'create_task') {
        if ($currentUser['role'] !== ROLES['MANGAKA']) {
            taskOut(false, null, 'Chỉ họa sĩ Manga mới được giao việc.', 403);
        }

        $pageId      = (int)($body['page_id']     ?? 0);
        $assignedTo  = (int)($body['assigned_to'] ?? 0);
        $taskType    = trim($body['task_type']    ?? '');
        $description = trim($body['description']  ?? '');
        $dueDate     = trim($body['due_date']     ?? '');
        $regionX     = (float)($body['region_x']  ?? 0);
        $regionY     = (float)($body['region_y']  ?? 0);
        $regionW     = (float)($body['region_w']  ?? 0);
        $regionH     = (float)($body['region_h']  ?? 0);

        if ($pageId <= 0)   taskOut(false, null, 'page_id không hợp lệ.', 422);
        if ($assignedTo <= 0) taskOut(false, null, 'Chưa chọn trợ lý.', 422);
        if (!in_array($taskType, $validTaskTypes, true)) taskOut(false, null, 'Loại task không hợp lệ.', 422);
        if ($regionW < 1 || $regionH < 1) taskOut(false, null, 'Vùng chọn quá nhỏ.', 422);
        if (!empty($dueDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            taskOut(false, null, 'Ngày deadline không đúng định dạng (YYYY-MM-DD).', 422);
        }

        // Verify page thuộc series của mangaka này
        $stmt = $db->prepare(
            "SELECT p.id FROM pages p
             JOIN chapters c ON c.id = p.chapter_id
             JOIN series   s ON s.id = c.series_id
             WHERE p.id = ? AND s.mangaka_id = ? LIMIT 1"
        );
        $stmt->execute([$pageId, $currentUser['id']]);
        if (!$stmt->fetch()) taskOut(false, null, 'Không có quyền giao việc trên trang này.', 403);

        // Verify assistant tồn tại
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'assistant' LIMIT 1");
        $stmt->execute([$assignedTo]);
        if (!$stmt->fetch()) taskOut(false, null, 'Trợ lý không tồn tại.', 404);

        $clamp = fn($v) => max(0.0, min(100.0, (float)$v));
        $regionData = json_encode([
            'x' => round($clamp($regionX), 2),
            'y' => round($clamp($regionY), 2),
            'w' => round($clamp($regionW), 2),
            'h' => round($clamp($regionH), 2),
        ]);

        try {
            $stmt = $db->prepare(
                "INSERT INTO tasks (page_id, assigned_to, assigned_by, task_type, description, region_data, status, due_date)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
            );
            $stmt->execute([
                $pageId, $assignedTo, $currentUser['id'],
                $taskType, $description ?: null, $regionData, $dueDate ?: null,
            ]);
            $newId = (int)$db->lastInsertId();

            $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
               ->execute([$pageId]);

            $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'task_assigned', ?, 'assistant/tasks.php')"
            )->execute([
                $assignedTo,
                "Họa sĩ {$currentUser['username']} đã giao cho bạn nhiệm vụ ({$taskType}).",
            ]);

            taskOut(true, ['task_id' => $newId], 'Giao việc thành công!');
        } catch (\Throwable $e) {
            taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── submit_task — assistant nộp kết quả (nhiều ảnh) ───────────
    if ($action === 'submit_task') {
        if ($currentUser['role'] !== ROLES['ASSISTANT']) {
            taskOut(false, null, 'Chỉ trợ lý mới được nộp kết quả.', 403);
        }

        $taskId = (int)($body['task_id'] ?? 0);
        if ($taskId <= 0) taskOut(false, null, 'task_id không hợp lệ.', 422);

        // Nhận danh sách paths ảnh (JSON array string hoặc array)
        $filePaths = $body['file_paths'] ?? $body['file_result'] ?? null;
        if (is_string($filePaths)) {
            // Thử parse JSON array, nếu không thì coi là 1 path đơn
            $decoded = json_decode($filePaths, true);
            $filePaths = is_array($decoded) ? $decoded : [$filePaths];
        }
        // Lọc rỗng
        $filePaths = array_values(array_filter((array)$filePaths, fn($p) => !empty(trim($p))));

        if (empty($filePaths)) {
            taskOut(false, null, 'Cần ít nhất 1 ảnh kết quả.', 422);
        }

        // Validate: chỉ cho phép ảnh
        $allowedImgExts = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($filePaths as $p) {
            $ext = strtolower(pathinfo(trim($p), PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedImgExts, true)) {
                taskOut(false, null, "File \"" . basename($p) . "\" không phải ảnh (jpg/jpeg/png/webp).", 422);
            }
        }

        try {
            // Kiểm tra task thuộc về assistant này
            $stmt = $db->prepare(
                "SELECT t.id, t.status, t.page_id, t.assigned_by, t.task_type,
                        s.mangaka_id
                 FROM tasks t
                 JOIN pages    p ON p.id = t.page_id
                 JOIN chapters c ON c.id = p.chapter_id
                 JOIN series   s ON s.id = c.series_id
                 WHERE t.id = ? AND t.assigned_to = ? LIMIT 1"
            );
            $stmt->execute([$taskId, $currentUser['id']]);
            $task = $stmt->fetch();

            if (!$task) taskOut(false, null, 'Task không tìm thấy hoặc không có quyền.', 404);
            if (!in_array($task['status'], ['pending', 'in_progress', 'revision'])) {
                taskOut(false, null, 'Task không ở trạng thái có thể nộp.', 409);
            }

            // Lưu JSON array paths
            $fileResultJson = count($filePaths) === 1
                ? $filePaths[0]             // Tương thích ngược: 1 file → string thường
                : json_encode($filePaths);  // Nhiều file → JSON array

            $db->prepare(
                "UPDATE tasks SET status = 'submitted', file_result = ? WHERE id = ?"
            )->execute([$fileResultJson, $taskId]);

            // Notify mangaka
            $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link)
                 VALUES (?, 'task_submitted', ?, 'mangaka/tasks.php')"
            )->execute([
                $task['mangaka_id'],
                "Trợ lý {$currentUser['username']} đã NỘP KẾT QUẢ nhiệm vụ ({$task['task_type']}) — " . count($filePaths) . " ảnh.",
            ]);

            taskOut(true, ['task_id' => $taskId, 'file_count' => count($filePaths)], 'Đã nộp kết quả thành công!');
        } catch (\Throwable $e) {
            taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── review_task — mangaka duyệt ────────────────────
    if ($action === 'review_task') {
        if ($currentUser['role'] !== ROLES['MANGAKA']) {
            taskOut(false, null, 'Chỉ họa sĩ Manga mới được duyệt task.', 403);
        }

        $taskId  = (int)($body['task_id'] ?? 0);
        $review  = trim($body['review']   ?? ''); // 'approve' | 'revision'
        $comment = trim($body['comment']  ?? '');

        if ($taskId <= 0) taskOut(false, null, 'task_id không hợp lệ.', 422);
        if (!in_array($review, ['approve', 'revision'], true)) {
            taskOut(false, null, 'review phải là "approve" hoặc "revision".', 422);
        }

        try {
            $stmt = $db->prepare(
                "SELECT t.id, t.status, t.page_id, t.assigned_to, t.task_type, s.mangaka_id
                 FROM tasks t
                 JOIN pages    p ON p.id = t.page_id
                 JOIN chapters c ON c.id = p.chapter_id
                 JOIN series   s ON s.id = c.series_id
                 WHERE t.id = ? AND s.mangaka_id = ? LIMIT 1"
            );
            $stmt->execute([$taskId, $currentUser['id']]);
            $task = $stmt->fetch();

            if (!$task) taskOut(false, null, 'Task không tìm thấy hoặc không có quyền.', 404);

            $newStatus = ($review === 'approve') ? 'approved' : 'revision';
            $db->prepare("UPDATE tasks SET status = ? WHERE id = ?")
               ->execute([$newStatus, $taskId]);

            // Cascade to page
            if ($newStatus === 'approved') {
                $chkStmt = $db->prepare(
                    "SELECT COUNT(*) FROM tasks WHERE page_id = ? AND status != 'approved'"
                );
                $chkStmt->execute([$task['page_id']]);
                if ((int)$chkStmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE pages SET status = 'approved' WHERE id = ?")
                       ->execute([$task['page_id']]);
                }
            } else {
                $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ?")
                   ->execute([$task['page_id']]);
            }

            // Notify assistant
            $msg  = $newStatus === 'approved'
                ? "Họa sĩ {$currentUser['username']} đã DUYỆT nhiệm vụ ({$task['task_type']}) của bạn!"
                : "Họa sĩ {$currentUser['username']} yêu cầu SỬA LẠI nhiệm vụ ({$task['task_type']})." . ($comment ? " Ghi chú: {$comment}" : '');
            $type = $newStatus === 'approved' ? 'task_approved' : 'task_revision';
            $db->prepare(
                "INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, 'assistant/tasks.php')"
            )->execute([$task['assigned_to'], $type, $msg]);

            taskOut(true, ['task_id' => $taskId, 'new_status' => $newStatus],
                $newStatus === 'approved' ? 'Đã duyệt nhiệm vụ!' : 'Đã yêu cầu sửa lại!');
        } catch (\Throwable $e) {
            taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── get_page_tasks ─────────────────────────────────
    if ($action === 'get_page_tasks') {
        $pageId = (int)($body['page_id'] ?? 0);
        if ($pageId <= 0) taskOut(false, null, 'page_id không hợp lệ.', 422);

        try {
            $stmt = $db->prepare(
                "SELECT t.id, t.task_type, t.region_data, t.status, t.description,
                        t.due_date, t.file_result,
                        u.username AS assistant_name
                 FROM tasks t
                 JOIN users u ON u.id = t.assigned_to
                 JOIN pages    p ON p.id = t.page_id
                 JOIN chapters c ON c.id = p.chapter_id
                 JOIN series   s ON s.id = c.series_id
                 WHERE t.page_id = ? AND s.mangaka_id = ?"
            );
            $stmt->execute([$pageId, $currentUser['id']]);
            $tasks = $stmt->fetchAll();

            foreach ($tasks as &$t) {
                $t['region_data'] = json_decode($t['region_data'] ?? 'null', true);
            }
            unset($t);

            taskOut(true, ['tasks' => $tasks, 'count' => count($tasks)], '');
        } catch (\Throwable $e) {
            taskOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    taskOut(false, null, 'Hành động không hợp lệ.', 400);
}

// ── Unsupported method ──
http_response_code(405);
echo json_encode(['success' => false, 'data' => null, 'message' => 'Phương thức không được hỗ trợ.']);
exit();
