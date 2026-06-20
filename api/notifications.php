<?php
/**
 * api/notifications.php
 * AJAX endpoint phục vụ quản lý thông báo của người dùng.
 * Trả về định dạng JSON.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

// Phải đăng nhập mới được gọi API
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$db = getDB();

// Xử lý GET request: Trả về danh sách thông báo và số lượng chưa đọc
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Lấy 20 thông báo gần nhất
        $stmt = $db->prepare(
            "SELECT id, type, message, is_read, link, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$currentUser['id']]);
        $notifications = $stmt->fetchAll();

        // Lấy tổng số thông báo chưa đọc
        $stmtUnread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmtUnread->execute([$currentUser['id']]);
        $unreadCount = (int)$stmtUnread->fetchColumn();

        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
    }
    exit();
}

// Xử lý POST request: Đánh dấu đã đọc
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'mark_all_read') {
        try {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$currentUser['id']]);
            echo json_encode(['success' => true, 'message' => 'Đã đánh dấu đọc tất cả thông báo.']);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID thông báo không hợp lệ.']);
            exit();
        }

        try {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $currentUser['id']]);
            echo json_encode(['success' => true, 'message' => 'Đã đánh dấu đọc thông báo.']);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
    exit();
}

// Phương thức không được hỗ trợ
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.']);
exit();
