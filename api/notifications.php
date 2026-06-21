<?php
/**
 * api/notifications.php
 *
 * GET  → Lấy danh sách notifications của user đang đăng nhập
 *         ?limit=20   Số thông báo tối đa (mặc định 20, tối đa 100)
 *         ?unread=1   Chỉ lấy chưa đọc
 *         ?type=...   Lọc theo loại (task_assigned, rank_drop, ...)
 *
 * POST → Hành động với notification
 *         action=mark_read      id=<int>
 *         action=mark_all_read
 *         action=delete         id=<int>
 *
 * Response format:
 *   { "success": bool, "data": {...}, "message": "..." }
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
// Allow CORS for same-origin AJAX
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
function notifOut(bool $ok, $data = null, string $msg = '', int $code = 0): void {
    if ($code > 0) http_response_code($code);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit();
}

// ═══════════════════════════════════════════════════════
// GET — Lấy danh sách thông báo
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit    = min(100, max(1, (int)($_GET['limit']  ?? 20)));
    $unreadOnly = !empty($_GET['unread']) && $_GET['unread'] === '1';
    $typeFilter = trim($_GET['type'] ?? '');

    try {
        $where  = ['user_id = ?'];
        $params = [$currentUser['id']];

        if ($unreadOnly) {
            $where[] = 'is_read = 0';
        }
        if ($typeFilter !== '') {
            $where[] = 'type = ?';
            $params[] = $typeFilter;
        }

        $whereSQL = implode(' AND ', $where);

        // Notifications list
        $stmt = $db->prepare(
            "SELECT id, type, message, is_read, link, created_at
             FROM notifications
             WHERE {$whereSQL}
             ORDER BY created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        // Format timestamps
        foreach ($notifications as &$n) {
            $n['created_at_formatted'] = date('d/m/Y H:i', strtotime($n['created_at']));
            $n['is_read'] = (bool)$n['is_read'];
        }
        unset($n);

        // Unread count (always)
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $cntStmt->execute([$currentUser['id']]);
        $unreadCount = (int)$cntStmt->fetchColumn();

        notifOut(true, [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'total_shown'   => count($notifications),
        ], '');
    } catch (\Throwable $e) {
        notifOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════
// POST — Hành động
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Support both form-encoded and JSON body
    $body = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $body = $_POST;
    }

    $action = trim($body['action'] ?? '');

    // ── mark_read ──
    if ($action === 'mark_read') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) notifOut(false, null, 'ID thông báo không hợp lệ.', 422);

        try {
            $stmt = $db->prepare(
                "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$id, $currentUser['id']]);

            if ($stmt->rowCount() === 0) {
                notifOut(false, null, 'Không tìm thấy thông báo hoặc không có quyền.', 404);
            }

            // Return updated unread count
            $cnt = (int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0")
                            ->execute([$currentUser['id']]) ? 0 : 0;
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $cntStmt->execute([$currentUser['id']]);
            $unreadCount = (int)$cntStmt->fetchColumn();

            notifOut(true, ['unread_count' => $unreadCount], 'Đã đánh dấu đã đọc.');
        } catch (\Throwable $e) {
            notifOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── mark_all_read ──
    if ($action === 'mark_all_read') {
        try {
            $stmt = $db->prepare(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$currentUser['id']]);
            $affected = $stmt->rowCount();

            notifOut(true, ['updated' => $affected, 'unread_count' => 0], "Đã đánh dấu đã đọc {$affected} thông báo.");
        } catch (\Throwable $e) {
            notifOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── delete ──
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) notifOut(false, null, 'ID không hợp lệ.', 422);

        try {
            $stmt = $db->prepare(
                "DELETE FROM notifications WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$id, $currentUser['id']]);

            if ($stmt->rowCount() === 0) {
                notifOut(false, null, 'Không tìm thấy hoặc không có quyền xóa.', 404);
            }

            $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $cntStmt->execute([$currentUser['id']]);
            $unreadCount = (int)$cntStmt->fetchColumn();

            notifOut(true, ['unread_count' => $unreadCount], 'Đã xóa thông báo.');
        } catch (\Throwable $e) {
            notifOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    // ── delete_all_read ──
    if ($action === 'delete_all_read') {
        try {
            $stmt = $db->prepare(
                "DELETE FROM notifications WHERE user_id = ? AND is_read = 1"
            );
            $stmt->execute([$currentUser['id']]);
            notifOut(true, ['deleted' => $stmt->rowCount()], 'Đã xóa tất cả thông báo đã đọc.');
        } catch (\Throwable $e) {
            notifOut(false, null, 'Lỗi máy chủ: ' . $e->getMessage(), 500);
        }
    }

    notifOut(false, null, 'Hành động không hợp lệ.', 400);
}

// ── Unsupported method ──
http_response_code(405);
echo json_encode(['success' => false, 'data' => null, 'message' => 'Phương thức không được hỗ trợ.']);
exit();
