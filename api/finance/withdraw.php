<?php
/**
 * api/finance/withdraw.php
 * API xử lý yêu cầu rút tiền.
 *
 * POST (rút tiền):
 *   amount, method, account_name, account_number, bank_name, bank_code, save_account
 *
 * POST (xóa tài khoản đã lưu):
 *   action=delete_account, account_id
 *
 * Response: { "success": bool, "message": "...", "withdrawal_id": int|null }
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ──
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$allowedRoles = ['mangaka', 'assistant'];
if (!in_array($currentUser['role'], $allowedRoles)) {
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
$userId = $currentUser['id'];

// ═══════════════════════════════════════════════════════════
// ACTION: Xóa tài khoản thanh toán đã lưu
// ═══════════════════════════════════════════════════════════
if (($_POST['action'] ?? '') === 'delete_account') {
    $accountId = (int)($_POST['account_id'] ?? 0);
    if ($accountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID tài khoản không hợp lệ.']);
        exit();
    }

    // Chỉ xóa tài khoản thuộc user hiện tại
    $stmt = $db->prepare("DELETE FROM payment_accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Đã xóa tài khoản.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản hoặc không có quyền xóa.']);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════
// ACTION: Gửi yêu cầu rút tiền
// ═══════════════════════════════════════════════════════════

// ── 1. Thu thập & validate input ──
$amount         = (float)($_POST['amount'] ?? 0);
$method         = $_POST['method'] ?? '';
$account_name   = trim($_POST['account_name'] ?? '');
$account_number = trim($_POST['account_number'] ?? '');
$bank_name      = trim($_POST['bank_name'] ?? '');
$bank_code      = trim($_POST['bank_code'] ?? '');
$saveAccount    = ($_POST['save_account'] ?? '0') === '1';

$validMethods = ['bank_transfer', 'momo', 'zalopay'];
if (!in_array($method, $validMethods)) {
    echo json_encode(['success' => false, 'message' => 'Phương thức rút tiền không hợp lệ.']);
    exit();
}

if (empty($account_name) || empty($account_number)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin tài khoản nhận.']);
    exit();
}

// ── Đọc cài đặt tài chính ──
$min_withdrawal = 100000;
$max_withdrawal = 50000000;
$fee_percent    = 2;
try {
    $stmtS = $db->query("SELECT setting_key, setting_value FROM finance_settings");
    $settings = $stmtS->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($settings['min_withdrawal']))          $min_withdrawal = (float)$settings['min_withdrawal'];
    if (isset($settings['max_withdrawal']))          $max_withdrawal = (float)$settings['max_withdrawal'];
    if (isset($settings['withdrawal_fee_percent']))  $fee_percent    = (float)$settings['withdrawal_fee_percent'];
} catch (\Throwable $e) {}

if ($amount < $min_withdrawal) {
    echo json_encode(['success' => false, 'message' => 'Số tiền rút tối thiểu là ' . number_format($min_withdrawal, 0, ',', '.') . ' ₫.']);
    exit();
}
if ($amount > $max_withdrawal) {
    echo json_encode(['success' => false, 'message' => 'Số tiền rút tối đa mỗi lần là ' . number_format($max_withdrawal, 0, ',', '.') . ' ₫.']);
    exit();
}

// ── Lấy thông tin ví ──
$wallet = get_wallet($userId);
if (!$wallet) {
    echo json_encode(['success' => false, 'message' => 'Ví chưa được khởi tạo.']);
    exit();
}

if ($amount > (float)$wallet['balance']) {
    echo json_encode(['success' => false, 'message' => 'Số dư ví không đủ. Số dư khả dụng: ' . format_money($wallet['balance']) . ' ₫.']);
    exit();
}

// ── 2. Tính phí ──
$fee_amount = round($amount * $fee_percent / 100);
$net_amount = $amount - $fee_amount;

try {
    $db->beginTransaction();

    // ── 3. INSERT withdrawal_requests ──
    $insReq = $db->prepare("
        INSERT INTO withdrawal_requests 
            (user_id, amount, fee_amount, net_amount, method, account_name, account_number, bank_name, bank_code, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $insReq->execute([
        $userId, $amount, $fee_amount, $net_amount,
        $method, $account_name, $account_number,
        $bank_name ?: null, $bank_code ?: null
    ]);
    $withdrawalId = (int)$db->lastInsertId();

    // ── 4. Cập nhật ví & tạo giao dịch ──
    $balance_before = (float)$wallet['balance'];
    $balance_after  = $balance_before - $amount;

    // 4a. Trừ balance, cộng pending_balance
    $updWallet = $db->prepare("
        UPDATE wallets 
        SET balance = balance - ?, pending_balance = pending_balance + ?
        WHERE user_id = ?
    ");
    $updWallet->execute([$amount, $amount, $userId]);

    // 4b. Ghi giao dịch withdraw
    $methodLabel = ($method === 'bank_transfer') ? ($bank_name ?: 'Ngân hàng') : strtoupper($method);
    $desc = "Yêu cầu rút tiền #WD{$withdrawalId} — {$methodLabel} ({$account_number})";

    $insTx = $db->prepare("
        INSERT INTO transactions 
            (wallet_id, type, amount, balance_before, balance_after, description, reference_id, reference_type, status)
        VALUES (?, 'withdraw', ?, ?, ?, ?, ?, 'withdrawal', 'pending')
    ");
    $insTx->execute([
        $wallet['id'], -$amount, $balance_before, $balance_after,
        $desc, $withdrawalId
    ]);

    // ── 5. Lưu tài khoản mới (nếu checkbox) ──
    if ($saveAccount) {
        // Kiểm tra trùng lặp trước khi lưu
        $checkDup = $db->prepare("
            SELECT COUNT(*) FROM payment_accounts 
            WHERE user_id = ? AND method = ? AND account_number = ?
        ");
        $checkDup->execute([$userId, $method, $account_number]);
        $exists = (int)$checkDup->fetchColumn();

        if ($exists === 0) {
            $insAcc = $db->prepare("
                INSERT INTO payment_accounts (user_id, method, account_name, account_number, bank_name, bank_code, is_default)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ");
            $insAcc->execute([
                $userId, $method, $account_name, $account_number,
                $bank_name ?: null, $bank_code ?: null
            ]);
        }
    }

    // ── 6. Gửi notification cho admin/board ──
    try {
        $username = $currentUser['username'] ?? $currentUser['email'] ?? 'Người dùng';
        $notifMsg = "Có yêu cầu rút tiền mới từ {$username}: " . format_money($amount) . " ₫ (thực nhận " . format_money($net_amount) . " ₫)";
        $notifLink = '/admin/withdrawals.php';

        $stmtBoard = $db->prepare("SELECT id FROM users WHERE role IN ('board', 'admin')");
        $stmtBoard->execute();
        $boardUsers = $stmtBoard->fetchAll();

        $insNotif = $db->prepare("
            INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
            VALUES (?, 'withdrawal_request', ?, ?, 0, NOW())
        ");
        foreach ($boardUsers as $bu) {
            $insNotif->execute([$bu['id'], $notifMsg, $notifLink]);
        }
    } catch (\Throwable $e) {
        // Notification gửi thất bại không block flow rút tiền
        error_log("Withdraw notification error: " . $e->getMessage());
    }

    $db->commit();

    // ── 7. Response ──
    echo json_encode([
        'success'       => true,
        'message'       => 'Yêu cầu rút tiền đã được gửi thành công! Hệ thống sẽ xử lý trong 1-2 ngày làm việc.',
        'withdrawal_id' => $withdrawalId
    ]);

} catch (\Throwable $e) {
    $db->rollBack();
    error_log("Withdraw error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi hệ thống xảy ra. Vui lòng thử lại sau.']);
}
