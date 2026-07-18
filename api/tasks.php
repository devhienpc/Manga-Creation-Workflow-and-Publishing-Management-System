<?php
/**
 * api/tasks.php
 *
 * Hoàn toàn được viết lại theo yêu cầu của hệ thống task review mới.
 * Xử lý mọi actions liên quan đến task qua POST JSON, GET hoặc tương thích multipart.
 * Mọi response trả về dạng JSON. Kiểm tra session và vai trò người dùng.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Kiểm tra Session ──────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$db = getDB();

// ── Helper Functions ──────────────────────────────────

// Trả về JSON response và kết thúc request
function jsonResponse(bool $success, string $message = '', array $extra = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    $response = array_merge(['success' => $success], $extra);
    if ($message !== '') {
        $response['message'] = $message;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Gửi notification
function sendNotification($pdo, $user_id, $type, $message, $link = '') {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$user_id, $type, $message, $link]);
}

// Kiểm tra page có tất cả tasks approved chưa
function checkPageCompletion($pdo, $page_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved
        FROM tasks WHERE page_id = ?
    ");
    $stmt->execute([$page_id]);
    $result = $stmt->fetch();
    
    if ($result['total'] > 0 && $result['total'] == $result['approved']) {
        // Tất cả tasks đã approved → tự động approve page
        $pdo->prepare("UPDATE pages SET status='approved' WHERE id=?")
            ->execute([$page_id]);
        
        // Lấy thông tin page để gửi notification
        $page = $pdo->prepare("
            SELECT p.*, c.series_id, c.chapter_number, s.mangaka_id, s.title
            FROM pages p
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE p.id = ?
        ");
        $page->execute([$page_id]);
        $pageData = $page->fetch();
        
        // Notification cho mangaka
        sendNotification($pdo, $pageData['mangaka_id'], 'page_completed',
            "🎉 Trang {$pageData['page_number']} - Chapter {$pageData['chapter_number']} ({$pageData['title']}) đã hoàn thành toàn bộ tasks!",
            "/mangaka/tasks.php?page_id={$page_id}"
        );
        return true;
    }
    return false;
}

// ── Nhận dữ liệu đầu vào (Multipart, GET hoặc POST JSON) ──
$body = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = $_POST;
}

// Lấy action (hỗ trợ cả GET và POST)
$action = trim($_GET['action'] ?? $body['action'] ?? '');

if (empty($action)) {
    jsonResponse(false, 'Hành động không hợp lệ hoặc bị thiếu.', [], 400);
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 1: get_page_tasks
// ═══════════════════════════════════════════════════════
if ($action === 'get_page_tasks') {
    if ($currentUser['role'] !== ROLES['MANGAKA'] && $currentUser['role'] !== ROLES['EDITOR']) {
        jsonResponse(false, 'Không có quyền thực hiện hành động này.', [], 403);
    }

    $pageId = (int)($_GET['page_id'] ?? $body['page_id'] ?? 0);
    if ($pageId <= 0) {
        jsonResponse(false, 'page_id không hợp lệ.', [], 422);
    }

    try {
        $stmt = $db->prepare("
            SELECT 
                t.*,
                u.username as assistant_name,
                u.avatar as assistant_avatar,
                ts_latest.id as latest_submission_id,
                ts_latest.file_result as latest_file,
                ts_latest.note as submission_note,
                ts_latest.submitted_at,
                ts_latest.version as current_version,
                tr_latest.action as last_review_action,
                tr_latest.comment as last_review_comment,
                tr_latest.reviewed_at as last_reviewed_at,
                (SELECT COUNT(*) FROM task_submissions WHERE task_id=t.id) as total_submissions
            FROM tasks t
            JOIN users u ON t.assigned_to = u.id
            LEFT JOIN task_submissions ts_latest ON ts_latest.id = (
                SELECT id FROM task_submissions 
                WHERE task_id = t.id 
                ORDER BY version DESC LIMIT 1
            )
            LEFT JOIN task_reviews tr_latest ON tr_latest.id = (
                SELECT id FROM task_reviews 
                WHERE task_id = t.id 
                ORDER BY reviewed_at DESC LIMIT 1
            )
            WHERE t.page_id = ?
            ORDER BY t.created_at ASC
        ");
        $stmt->execute([$pageId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Chuẩn hóa region_data và thống kê page_summary
        $summary = [
            'total' => 0,
            'pending' => 0,
            'in_progress' => 0,
            'submitted' => 0,
            'approved' => 0,
            'revision' => 0
        ];
        
        foreach ($tasks as &$t) {
            if (!empty($t['region_data'])) {
                $t['region_data'] = json_decode($t['region_data'], true);
            } else {
                $t['region_data'] = null;
            }
            $status = $t['status'] ?? 'pending';
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            $summary['total']++;
        }
        unset($t);

        jsonResponse(true, '', [
            'tasks' => $tasks,
            'page_summary' => $summary
        ]);
    } catch (\Throwable $e) {
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 2: get_my_tasks
// ═══════════════════════════════════════════════════════
if ($action === 'get_my_tasks') {
    if ($currentUser['role'] !== ROLES['ASSISTANT']) {
        jsonResponse(false, 'Chỉ trợ lý mới có quyền truy cập hành động này.', [], 403);
    }

    $statusFilter = trim($_GET['status'] ?? $body['status'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? $body['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    try {
        $stmt = $db->prepare("
            SELECT 
                t.*,
                p.page_number,
                p.original_file as page_image,
                c.chapter_number,
                c.title as chapter_title,
                s.title as series_title,
                s.id as series_id,
                u.username as mangaka_name,
                ts_latest.file_result as my_latest_file,
                ts_latest.version as my_version,
                ts_latest.submitted_at as last_submitted_at,
                tr_latest.comment as revision_comment,
                tr_latest.reviewed_at as revision_at,
                (SELECT COUNT(*) FROM task_submissions WHERE task_id=t.id) as submit_count
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            JOIN users u ON s.mangaka_id = u.id
            LEFT JOIN task_submissions ts_latest ON ts_latest.id = (
                SELECT id FROM task_submissions WHERE task_id=t.id ORDER BY version DESC LIMIT 1
            )
            LEFT JOIN task_reviews tr_latest ON tr_latest.id = (
                SELECT id FROM task_reviews WHERE task_id=t.id ORDER BY reviewed_at DESC LIMIT 1
            )
            WHERE t.assigned_to = ? 
            AND (? = '' OR t.status = ?)
            ORDER BY 
                CASE t.status 
                    WHEN 'revision' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'in_progress' THEN 3
                    WHEN 'submitted' THEN 4
                    WHEN 'approved' THEN 5
                END,
                t.due_date ASC
            LIMIT 10 OFFSET ?
        ");
        $stmt->bindValue(1, $currentUser['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $statusFilter, PDO::PARAM_STR);
        $stmt->bindValue(3, $statusFilter, PDO::PARAM_STR);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tasks as &$t) {
            if (!empty($t['region_data'])) {
                $t['region_data'] = json_decode($t['region_data'], true);
            } else {
                $t['region_data'] = null;
            }
        }
        unset($t);

        jsonResponse(true, '', ['tasks' => $tasks]);
    } catch (\Throwable $e) {
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 3: create_task
// ═══════════════════════════════════════════════════════
if ($action === 'create_task') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonResponse(false, 'Chỉ họa sĩ Manga mới được giao task.', [], 403);
    }

    $pageId = (int)($body['page_id'] ?? 0);
    $assignedTo = (int)($body['assigned_to'] ?? 0);
    $taskType = trim($body['task_type'] ?? '');
    $description = trim($body['description'] ?? '');
    $regionDataRaw = trim($body['region_data'] ?? '');
    $dueDate = trim($body['due_date'] ?? '');
    $price = isset($body['price']) ? (float)$body['price'] : 0.00;

    if ($pageId <= 0) {
        jsonResponse(false, 'page_id không hợp lệ.', [], 422);
    }
    if ($assignedTo <= 0) {
        jsonResponse(false, 'Chưa chọn trợ lý.', [], 422);
    }
    if (!in_array($taskType, ['background', 'shading', 'effects', 'lettering', 'cleanup'], true)) {
        jsonResponse(false, 'Loại task không hợp lệ.', [], 422);
    }
    if ($price < 0) {
        jsonResponse(false, 'Đơn giá nhiệm vụ không được nhỏ hơn 0.', [], 422);
    }

    // Validate page_id belongs to this mangaka
    $stmt = $db->prepare("
        SELECT p.id, p.page_number, s.title as series_title
        FROM pages p
        JOIN chapters c ON p.chapter_id = c.id
        JOIN series s ON c.series_id = s.id
        WHERE p.id = ? AND s.mangaka_id = ?
        LIMIT 1
    ");
    $stmt->execute([$pageId, $currentUser['id']]);
    $pageInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pageInfo) {
        jsonResponse(false, 'Trang không tồn tại hoặc không thuộc quyền quản lý của bạn.', [], 403);
    }

    // Validate assistant
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'assistant' LIMIT 1");
    $stmt->execute([$assignedTo]);
    $assistantExists = $stmt->fetch();
    if (!$assistantExists) {
        jsonResponse(false, 'Trợ lý không hợp lệ hoặc không tồn tại.', [], 422);
    }

    // Validate due_date
    if (!empty($dueDate)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            jsonResponse(false, 'Định dạng due_date không hợp lệ (YYYY-MM-DD).', [], 422);
        }
        if ($dueDate < date('Y-m-d')) {
            jsonResponse(false, 'Hạn chót không được ở trong quá khứ.', [], 422);
        }
    } else {
        $dueDate = null;
    }

    // Validate region_data
    $regionDecoded = json_decode($regionDataRaw, true);
    if (!is_array($regionDecoded) || !isset($regionDecoded['x']) || !isset($regionDecoded['y']) || (!isset($regionDecoded['width']) && !isset($regionDecoded['w'])) || (!isset($regionDecoded['height']) && !isset($regionDecoded['h']))) {
        jsonResponse(false, 'region_data không hợp lệ hoặc thiếu thông tin vùng chọn.', [], 422);
    }
    
    $w = $regionDecoded['w'] ?? $regionDecoded['width'] ?? 0;
    $h = $regionDecoded['h'] ?? $regionDecoded['height'] ?? 0;
    $x = $regionDecoded['x'];
    $y = $regionDecoded['y'];

    $clamp = fn($v) => max(0.0, min(100.0, (float)$v));
    $normalizedRegion = json_encode([
        'x' => round($clamp($x), 2),
        'y' => round($clamp($y), 2),
        'w' => round($clamp($w), 2),
        'h' => round($clamp($h), 2)
    ]);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO tasks 
                (page_id, assigned_to, assigned_by, task_type, description, region_data, status, due_date, version, price)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 1, ?)
        ");
        $stmt->execute([
            $pageId, $assignedTo, $currentUser['id'], $taskType,
            $description !== '' ? $description : null,
            $normalizedRegion, $dueDate, $price
        ]);
        $taskId = (int)$db->lastInsertId();

        // Update page status to in_progress if it was pending
        $db->prepare("UPDATE pages SET status='in_progress' WHERE id=? AND status='pending'")
           ->execute([$pageId]);

        $db->commit();

        // Send notification
        $msg = "📋 Bạn có task mới: [{$taskType}] trên Trang {$pageInfo['page_number']} - {$pageInfo['series_title']}";
        sendNotification($db, $assignedTo, 'task_assigned', $msg, "/assistant/tasks.php");

        jsonResponse(true, 'Đã giao task thành công', ['task_id' => $taskId]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 4: start_task
// ═══════════════════════════════════════════════════════
if ($action === 'start_task') {
    if ($currentUser['role'] !== ROLES['ASSISTANT']) {
        jsonResponse(false, 'Chỉ trợ lý mới thực hiện được hành động này.', [], 403);
    }

    $taskId = (int)($body['task_id'] ?? 0);
    if ($taskId <= 0) {
        jsonResponse(false, 'task_id không hợp lệ.', [], 422);
    }

    try {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }
        if ((int)$task['assigned_to'] !== $currentUser['id']) {
            jsonResponse(false, 'Bạn không được giao task này.', [], 403);
        }
        if ($task['status'] !== 'pending') {
            jsonResponse(false, 'Task đã bắt đầu hoặc đã hoàn thành.', [], 409);
        }

        $stmt = $db->prepare("UPDATE tasks SET status='in_progress' WHERE id=?");
        $stmt->execute([$taskId]);

        // Cập nhật trang sang in_progress
        $db->prepare("UPDATE pages SET status='in_progress' WHERE id=? AND status='pending'")
           ->execute([$task['page_id']]);

        jsonResponse(true, 'Đã bắt đầu làm task');
    } catch (\Throwable $e) {
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 5: submit_task
// ═══════════════════════════════════════════════════════
if ($action === 'submit_task') {
    if ($currentUser['role'] !== ROLES['ASSISTANT']) {
        jsonResponse(false, 'Chỉ trợ lý mới có quyền nộp kết quả task.', [], 403);
    }

    $taskId = (int)($_POST['task_id'] ?? $body['task_id'] ?? 0);
    $note = trim($_POST['note'] ?? $body['note'] ?? '');

    if ($taskId <= 0) {
        jsonResponse(false, 'task_id không hợp lệ.', [], 422);
    }

    try {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }
        if ((int)$task['assigned_to'] !== $currentUser['id']) {
            jsonResponse(false, 'Bạn không được phân công task này.', [], 403);
        }
        if (!in_array($task['status'], ['in_progress', 'revision'], true)) {
            jsonResponse(false, 'Trạng thái task hiện tại không cho phép nộp bài.', [], 409);
        }

        // Validate File
        $file = $_FILES['file'] ?? $_FILES['file_result'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Cần nộp tệp tin kết quả.', [], 422);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'psd', 'zip', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            jsonResponse(false, 'Định dạng tệp không được hỗ trợ (chỉ chấp nhận jpg, jpeg, png, psd, zip, pdf).', [], 422);
        }

        $maxSize = 50 * 1024 * 1024; // 50MB
        if ($file['size'] > $maxSize) {
            jsonResponse(false, 'Dung lượng tệp vượt quá giới hạn cho phép (tối đa 50MB).', [], 422);
        }

        // Kiểm tra deadline
        $isLate = false;
        if (!empty($task['due_date'])) {
            if (date('Y-m-d') > $task['due_date']) {
                $isLate = true;
            }
        }
        if ($isLate) {
            $note = trim(($note !== '' ? $note . ' ' : '') . '(Nộp trễ)');
        }

        // Xử lý lưu file
        $newVersion = (int)$task['version'];
        $filename = "task_{$taskId}_v{$newVersion}_{$currentUser['id']}." . $ext;
        
        $uploadDir = dirname(__DIR__) . '/assets/uploads/task_results/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fullSavePath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $fullSavePath)) {
            jsonResponse(false, 'Không thể lưu tệp tin tải lên.', [], 500);
        }

        $savePath = "assets/uploads/task_results/{$filename}";

        $db->beginTransaction();

        // Cập nhật task
        $stmt = $db->prepare("
            UPDATE tasks SET 
                status = 'submitted',
                file_result = ?,
                version = ?
            WHERE id = ?
        ");
        $stmt->execute([$savePath, $newVersion, $taskId]);

        // Tạo record submission
        $stmt = $db->prepare("
            INSERT INTO task_submissions 
                (task_id, submitted_by, version, file_result, file_size, note)
            VALUES 
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$taskId, $currentUser['id'], $newVersion, $savePath, $file['size'], $note]);

        // Lấy thông tin để gửi notification
        $stmt = $db->prepare("
            SELECT s.mangaka_id, p.page_number
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $pageInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->commit();

        // Gửi notification cho mangaka
        $msg = "📤 {$currentUser['username']} đã nộp task [{$task['task_type']}] Trang {$pageInfo['page_number']} (v{$newVersion}) - cần bạn review";
        sendNotification($db, $pageInfo['mangaka_id'], 'task_submitted', $msg, "/mangaka/tasks.php?page_id={$task['page_id']}");

        jsonResponse(true, "Đã nộp bài thành công (v{$newVersion})", ['version' => $newVersion]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 5B: submit_page_tasks (nộp 1 file cho TẤT CẢ tasks trên 1 trang)
// ═══════════════════════════════════════════════════════
if ($action === 'submit_page_tasks') {
    if ($currentUser['role'] !== ROLES['ASSISTANT']) {
        jsonResponse(false, 'Chỉ trợ lý mới có quyền nộp kết quả.', [], 403);
    }

    $pageId = (int)($_POST['page_id'] ?? $body['page_id'] ?? 0);
    $note = trim($_POST['note'] ?? $body['note'] ?? '');

    if ($pageId <= 0) {
        jsonResponse(false, 'page_id không hợp lệ.', [], 422);
    }

    try {
        // Lấy tất cả tasks thuộc trang này mà trợ lý được giao và đang ở trạng thái hợp lệ
        $stmt = $db->prepare("
            SELECT t.* FROM tasks t
            WHERE t.page_id = ? 
              AND t.assigned_to = ?
              AND t.status IN ('in_progress', 'revision')
            ORDER BY t.id ASC
        ");
        $stmt->execute([$pageId, $currentUser['id']]);
        $eligibleTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($eligibleTasks)) {
            jsonResponse(false, 'Không tìm thấy task nào hợp lệ trên trang này để nộp bài.', [], 409);
        }

        // Validate File
        $file = $_FILES['file'] ?? $_FILES['file_result'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Cần nộp tệp tin kết quả.', [], 422);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'psd', 'zip', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            jsonResponse(false, 'Định dạng tệp không được hỗ trợ (chỉ chấp nhận jpg, jpeg, png, psd, zip, pdf).', [], 422);
        }

        $maxSize = 50 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            jsonResponse(false, 'Dung lượng tệp vượt quá giới hạn (tối đa 50MB).', [], 422);
        }

        // Kiểm tra deadline (lấy deadline sớm nhất)
        $isLate = false;
        foreach ($eligibleTasks as $et) {
            if (!empty($et['due_date']) && date('Y-m-d') > $et['due_date']) {
                $isLate = true;
                break;
            }
        }
        if ($isLate) {
            $note = trim(($note !== '' ? $note . ' ' : '') . '(Nộp trễ)');
        }

        // Lưu file 1 lần duy nhất
        $filename = "page_{$pageId}_u{$currentUser['id']}_" . time() . ".{$ext}";
        $uploadDir = dirname(__DIR__) . '/assets/uploads/task_results/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            jsonResponse(false, 'Không thể lưu tệp tin tải lên.', [], 500);
        }
        $savePath = "assets/uploads/task_results/{$filename}";

        $db->beginTransaction();

        $taskIds = [];
        foreach ($eligibleTasks as $task) {
            $newVersion = (int)$task['version'];

            // Cập nhật task
            $db->prepare("
                UPDATE tasks SET status='submitted', file_result=?, version=? WHERE id=?
            ")->execute([$savePath, $newVersion, $task['id']]);

            // Tạo submission record (cùng file)
            $db->prepare("
                INSERT INTO task_submissions (task_id, submitted_by, version, file_result, file_size, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$task['id'], $currentUser['id'], $newVersion, $savePath, $file['size'], $note]);

            $taskIds[] = $task['id'];
        }

        // Lấy thông tin page để gửi notification
        $stmt = $db->prepare("
            SELECT s.mangaka_id, p.page_number, s.title as series_title
            FROM pages p
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$pageId]);
        $pageInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->commit();

        // Gửi notification cho mangaka
        $taskCount = count($taskIds);
        $msg = "📤 {$currentUser['username']} đã nộp {$taskCount} task(s) trên Trang {$pageInfo['page_number']} - {$pageInfo['series_title']} — cần bạn review";
        sendNotification($db, $pageInfo['mangaka_id'], 'task_submitted', $msg, "/mangaka/tasks.php");

        jsonResponse(true, "Đã nộp bài thành công cho {$taskCount} nhiệm vụ", [
            'task_ids' => $taskIds,
            'task_count' => $taskCount
        ]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 6: approve_task
// ═══════════════════════════════════════════════════════
if ($action === 'approve_task') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonResponse(false, 'Chỉ họa sĩ Manga mới được duyệt task.', [], 403);
    }

    $taskId = (int)($body['task_id'] ?? 0);
    $submissionId = (int)($body['submission_id'] ?? 0);

    if ($taskId <= 0 || $submissionId <= 0) {
        jsonResponse(false, 'task_id hoặc submission_id không hợp lệ.', [], 422);
    }

    try {
        // Validate task belongs to this mangaka
        $stmt = $db->prepare("
            SELECT t.*, s.mangaka_id, p.page_number
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }
        if ((int)$task['mangaka_id'] !== $currentUser['id']) {
            jsonResponse(false, 'Task không thuộc bộ truyện của bạn.', [], 403);
        }
        if ($task['status'] !== 'submitted') {
            jsonResponse(false, 'Task không ở trạng thái cần duyệt.', [], 409);
        }

        // Validate submission_id is the latest submission
        $stmt = $db->prepare("SELECT id FROM task_submissions WHERE task_id = ? ORDER BY version DESC LIMIT 1");
        $stmt->execute([$taskId]);
        $latestSub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$latestSub || (int)$latestSub['id'] !== $submissionId) {
            jsonResponse(false, 'Yêu cầu duyệt không hợp lệ hoặc đã có phiên bản nộp bài mới hơn.', [], 409);
        }

        $db->beginTransaction();

        // Approve task
        $stmt = $db->prepare("
            UPDATE tasks SET 
                status = 'approved',
                approved_at = NOW(),
                approved_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['id'], $taskId]);

        // Ghi review record
        $stmt = $db->prepare("
            INSERT INTO task_reviews 
                (task_id, submission_id, reviewed_by, action, comment)
            VALUES (?, ?, ?, 'approved', NULL)
        ");
        $stmt->execute([$taskId, $submissionId, $currentUser['id']]);

        $db->commit();

        // Gửi notification cho assistant
        $msg = "✅ Task [{$task['task_type']}] Trang {$task['page_number']} của bạn đã được duyệt!";
        sendNotification($db, $task['assigned_to'], 'task_approved', $msg, "/assistant/tasks.php");

        // Kiểm tra page completion
        $pageCompleted = checkPageCompletion($db, $task['page_id']);

        jsonResponse(true, 'Đã duyệt task thành công', ['page_completed' => $pageCompleted]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 7: request_revision
// ═══════════════════════════════════════════════════════
if ($action === 'request_revision') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonResponse(false, 'Chỉ họa sĩ Manga mới có quyền yêu cầu sửa lại.', [], 403);
    }

    $taskId = (int)($body['task_id'] ?? 0);
    $submissionId = (int)($body['submission_id'] ?? 0);
    $comment = trim($body['comment'] ?? '');

    if ($taskId <= 0 || $submissionId <= 0) {
        jsonResponse(false, 'task_id hoặc submission_id không hợp lệ.', [], 422);
    }
    if ($comment === '') {
        jsonResponse(false, 'Vui lòng cung cấp lý do yêu cầu sửa đổi.', [], 422);
    }
    if (mb_strlen($comment) < 10) {
        jsonResponse(false, 'Lý do yêu cầu sửa đổi phải từ 10 ký tự trở lên.', [], 422);
    }

    try {
        // Validate task belongs to this mangaka
        $stmt = $db->prepare("
            SELECT t.*, s.mangaka_id, p.page_number
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }
        if ((int)$task['mangaka_id'] !== $currentUser['id']) {
            jsonResponse(false, 'Task không thuộc bộ truyện của bạn.', [], 403);
        }
        if ($task['status'] !== 'submitted') {
            jsonResponse(false, 'Task không ở trạng thái cần review.', [], 409);
        }

        // Validate submission_id is the latest submission
        $stmt = $db->prepare("SELECT id FROM task_submissions WHERE task_id = ? ORDER BY version DESC LIMIT 1");
        $stmt->execute([$taskId]);
        $latestSub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$latestSub || (int)$latestSub['id'] !== $submissionId) {
            jsonResponse(false, 'Yêu cầu sửa không hợp lệ hoặc đã có phiên bản nộp bài mới hơn.', [], 409);
        }

        $nextVersion = (int)$task['version'] + 1;

        $db->beginTransaction();

        // Đổi status task về revision và tăng version
        $stmt = $db->prepare("
            UPDATE tasks SET 
                status = 'revision',
                version = ?
            WHERE id = ?
        ");
        $stmt->execute([$nextVersion, $taskId]);

        // Ghi review record
        $stmt = $db->prepare("
            INSERT INTO task_reviews
                (task_id, submission_id, reviewed_by, action, comment)
            VALUES (?, ?, ?, 'revision', ?)
        ");
        $stmt->execute([$taskId, $submissionId, $currentUser['id'], $comment]);

        // Cập nhật lại status của page sang in_progress (nếu chưa phải)
        $db->prepare("UPDATE pages SET status = 'in_progress' WHERE id = ?")
           ->execute([$task['page_id']]);

        $db->commit();

        // Gửi notification cho assistant
        $msg = "🔄 Task [{$task['task_type']}] Trang {$task['page_number']} cần sửa lại (v{$nextVersion}): {$comment}";
        sendNotification($db, $task['assigned_to'], 'task_revision', $msg, "/assistant/tasks.php?task_id={$taskId}");

        jsonResponse(true, 'Đã gửi yêu cầu sửa lại', ['next_version' => $nextVersion]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 8: get_task_history
// ═══════════════════════════════════════════════════════
if ($action === 'get_task_history') {
    $taskId = (int)($_GET['task_id'] ?? $body['task_id'] ?? 0);
    if ($taskId <= 0) {
        jsonResponse(false, 'task_id không hợp lệ.', [], 422);
    }

    try {
        // Validate task exists and belongs to mangaka, assistant, or editor
        $stmt = $db->prepare("
            SELECT t.*, s.mangaka_id 
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }

        // Quyền truy cập
        $isRelated = ($currentUser['role'] === ROLES['MANGAKA'] && (int)$task['mangaka_id'] === $currentUser['id']) ||
                     ($currentUser['role'] === ROLES['ASSISTANT'] && (int)$task['assigned_to'] === $currentUser['id']) ||
                     ($currentUser['role'] === ROLES['EDITOR']);

        if (!$isRelated) {
            jsonResponse(false, 'Không có quyền truy cập lịch sử của task này.', [], 403);
        }

        $stmt = $db->prepare("
            SELECT 
                ts.id as submission_id, ts.version, ts.file_result, ts.note, ts.submitted_at,
                u_sub.username as submitted_by_name,
                tr.action as review_action,
                tr.comment as review_comment,
                tr.reviewed_at,
                u_rev.username as reviewed_by_name
            FROM task_submissions ts
            JOIN users u_sub ON ts.submitted_by = u_sub.id
            LEFT JOIN task_reviews tr ON tr.submission_id = ts.id
            LEFT JOIN users u_rev ON tr.reviewed_by = u_rev.id
            WHERE ts.task_id = ?
            ORDER BY ts.version ASC
        ");
        $stmt->execute([$taskId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, '', ['history' => $history]);
    } catch (\Throwable $e) {
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ═══════════════════════════════════════════════════════
// HÀNH ĐỘNG 9: delete_task
// ═══════════════════════════════════════════════════════
if ($action === 'delete_task') {
    if ($currentUser['role'] !== ROLES['MANGAKA']) {
        jsonResponse(false, 'Chỉ họa sĩ Manga mới có quyền xóa task.', [], 403);
    }

    $taskId = (int)($body['task_id'] ?? 0);
    if ($taskId <= 0) {
        jsonResponse(false, 'task_id không hợp lệ.', [], 422);
    }

    try {
        $stmt = $db->prepare("
            SELECT t.*, s.mangaka_id 
            FROM tasks t
            JOIN pages p ON t.page_id = p.id
            JOIN chapters c ON p.chapter_id = c.id
            JOIN series s ON c.series_id = s.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            jsonResponse(false, 'Task không tồn tại.', [], 404);
        }
        if ((int)$task['mangaka_id'] !== $currentUser['id']) {
            jsonResponse(false, 'Task không thuộc bộ truyện của bạn.', [], 403);
        }
        if ($task['status'] !== 'pending') {
            jsonResponse(false, 'Không thể xóa task đang thực hiện.', [], 409);
        }
        if ((int)$task['assigned_by'] !== $currentUser['id']) {
            jsonResponse(false, 'Không thể xóa task do người khác giao.', [], 403);
        }

        $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND status = 'pending' AND assigned_by = ?");
        $stmt->execute([$taskId, $currentUser['id']]);

        jsonResponse(true, 'Đã xóa task');
    } catch (\Throwable $e) {
        jsonResponse(false, 'Lỗi hệ thống: ' . $e->getMessage(), [], 500);
    }
}

// ── Fallback nếu không khớp action nào ──
jsonResponse(false, 'Hành động không hợp lệ.', [], 400);
