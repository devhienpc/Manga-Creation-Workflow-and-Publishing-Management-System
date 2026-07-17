<?php
/**
 * api/finance/complete_withdrawal.php
 * API xử lý hoàn thành hoặc đánh dấu đang xử lý yêu cầu rút tiền.
 *
 * POST:
 *   action = 'mark_processing' | 'complete'
 *   id     = withdrawal_request ID
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
$action = $_POST['action'] ?? '';
$reqId  = (int)($_POST['id'] ?? 0);

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID yêu cầu không hợp lệ.']);
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

// ═══════════════════════════════════════════════════════════
// ACTION: Đánh dấu đang xử lý (processing)
// ═══════════════════════════════════════════════════════════
if ($action === 'mark_processing') {
    if ($request['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Chỉ có thể đánh dấu xử lý yêu cầu đang ở trạng thái chờ.']);
        exit();
    }

    try {
        $db->beginTransaction();

        $upd = $db->prepare("UPDATE withdrawal_requests SET status = 'processing', processed_by = ? WHERE id = ?");
        $upd->execute([$currentUser['id'], $reqId]);

        // Gửi notification cho user
        try {
            $insNotif = $db->prepare("
                INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
                VALUES (?, 'withdrawal_update', ?, ?, 0, NOW())
            ");
            $msg = "⏳ Yêu cầu rút tiền #WD" . str_pad($reqId, 6, '0', STR_PAD_LEFT) . " đang được xử lý. Vui lòng chờ xác nhận.";
            $insNotif->execute([$request['user_id'], $msg, '/wallet/withdrawals.php']);
        } catch (\Throwable $e) {
            error_log("Notif error (processing): " . $e->getMessage());
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Đã đánh dấu đang xử lý.']);
    } catch (\Throwable $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════
// ACTION: Xác nhận đã chuyển tiền (complete)
// ═══════════════════════════════════════════════════════════
if ($action === 'complete') {
    if (!in_array($request['status'], ['pending', 'processing'])) {
        echo json_encode(['success' => false, 'message' => 'Yêu cầu này không thể hoàn tất (trạng thái: ' . $request['status'] . ').']);
        exit();
    }

    $amount    = (float)$request['amount'];
    $feeAmount = (float)$request['fee_amount'];
    $netAmount = (float)$request['net_amount'];
    $userId    = (int)$request['user_id'];

    // Lấy wallet
    $wallet = get_wallet($userId);
    if (!$wallet) {
        echo json_encode(['success' => false, 'message' => 'Ví người dùng không tồn tại.']);
        exit();
    }

    try {
        $db->beginTransaction();

        // 1. Cập nhật withdrawal_requests
        $upd = $db->prepare("
            UPDATE withdrawal_requests SET status = 'completed', processed_at = NOW(), processed_by = ? WHERE id = ?
        ");
        $upd->execute([$currentUser['id'], $reqId]);

        // 2. Cập nhật wallets
        $updW = $db->prepare("
            UPDATE wallets SET 
                pending_balance = GREATEST(0, pending_balance - ?),
                total_withdrawn = total_withdrawn + ?
            WHERE user_id = ?
        ");
        $updW->execute([$amount, $netAmount, $userId]);

        // 3. Cập nhật transactions liên quan thành completed
        $updTx = $db->prepare("
            UPDATE transactions SET status = 'completed' 
            WHERE reference_id = ? AND reference_type = 'withdrawal' AND status = 'pending'
        ");
        $updTx->execute([$reqId]);

        // 4. Ghi giao dịch phí rút tiền riêng (withdraw_fee) nếu chưa có
        if ($feeAmount > 0) {
            $checkFee = $db->prepare("
                SELECT COUNT(*) FROM transactions 
                WHERE reference_id = ? AND reference_type = 'withdrawal' AND type = 'withdraw_fee'
            ");
            $checkFee->execute([$reqId]);
            $hasFee = (int)$checkFee->fetchColumn();

            if ($hasFee === 0) {
                $balNow = (float)$wallet['balance'];
                $insFee = $db->prepare("
                    INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_id, reference_type, status)
                    VALUES (?, 'withdraw_fee', ?, ?, ?, ?, ?, 'withdrawal', 'completed')
                ");
                $feeDesc = "Phí xử lý rút tiền #WD" . str_pad($reqId, 6, '0', STR_PAD_LEFT);
                $insFee->execute([
                    $wallet['id'], -$feeAmount, $balNow, $balNow - $feeAmount,
                    $feeDesc, $reqId
                ]);
            }
        }

        // 5. Gửi notification cho user
        try {
            $insNotif = $db->prepare("
                INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
                VALUES (?, 'withdrawal_completed', ?, ?, 0, NOW())
            ");
            $msg = "✅ Rút tiền thành công! " . format_money($netAmount) . " ₫ đã được chuyển về tài khoản của bạn.";
            $insNotif->execute([$userId, $msg, '/wallet/withdrawals.php']);
        } catch (\Throwable $e) {
            error_log("Notif error (complete): " . $e->getMessage());
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Đã xác nhận chuyển tiền thành công!']);
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log("Complete withdrawal error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Action không hợp lệ.']);
