<?php
/**
 * api/finance/reject_withdrawal.php
 * API từ chối yêu cầu rút tiền — hoàn lại tiền vào ví user.
 *
 * POST:
 *   id     = withdrawal_request ID
 *   reason = lý do từ chối (required)
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
if (!in_array($currentUser['role'], ['board', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Không có quyền truy cập.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit();
}

$db     = getDB();
$reqId  = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID yêu cầu không hợp lệ.']);
    exit();
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập lý do từ chối.']);
    exit();
}

// Lấy thông tin yêu cầu
$stmt = $db->prepare("SELECT * FROM withdrawal_requests WHERE id = ?");
$stmt->execute([$reqId]);
$request = $stmt->fetch();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Yêu cầu không tồn tại.']);
    exit();
}

if (!in_array($request['status'], ['pending', 'processing'])) {
    echo json_encode(['success' => false, 'message' => 'Yêu cầu này không thể từ chối (trạng thái: ' . $request['status'] . ').']);
    exit();
}

$amount = (float)$request['amount'];
$userId = (int)$request['user_id'];

try {
    $db->beginTransaction();

    // 1. Cập nhật withdrawal_requests
    $upd = $db->prepare("
        UPDATE withdrawal_requests 
        SET status = 'rejected', admin_note = ?, processed_at = NOW(), processed_by = ?
        WHERE id = ?
    ");
    $upd->execute([$reason, $currentUser['id'], $reqId]);

    // 2. Hoàn tiền vào ví: cộng lại balance, trừ pending_balance
    $updW = $db->prepare("
        UPDATE wallets SET 
            balance = balance + ?,
            pending_balance = GREATEST(0, pending_balance - ?)
        WHERE user_id = ?
    ");
    $updW->execute([$amount, $amount, $userId]);

    // 3. Đánh dấu transactions liên quan thành cancelled
    $updTx = $db->prepare("
        UPDATE transactions SET status = 'cancelled' 
        WHERE reference_id = ? AND reference_type = 'withdrawal' AND status = 'pending'
    ");
    $updTx->execute([$reqId]);

    // 4. Gửi notification cho user kèm lý do
    try {
        $insNotif = $db->prepare("
            INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
            VALUES (?, 'withdrawal_rejected', ?, ?, 0, NOW())
        ");
        $msg = "❌ Yêu cầu rút tiền #WD" . str_pad($reqId, 6, '0', STR_PAD_LEFT) 
             . " bị từ chối. Lý do: " . mb_substr($reason, 0, 100) 
             . ". Tiền đã được hoàn lại ví.";
        $insNotif->execute([$userId, $msg, '/wallet/withdrawals.php']);
    } catch (\Throwable $e) {
        error_log("Notif error (reject): " . $e->getMessage());
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Đã từ chối yêu cầu và hoàn tiền về ví người dùng.']);
} catch (\Throwable $e) {
    $db->rollBack();
    error_log("Reject withdrawal error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
