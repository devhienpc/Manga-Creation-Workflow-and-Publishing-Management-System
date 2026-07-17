<?php
/**
 * wallet/index.php
 * Ví điện tử dùng chung cho Mangaka và Assistant.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Ví điện tử';
$activePage   = 'wallet';
$allowedRoles = [ROLES['MANGAKA'], ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();
$userId = $currentUser['id'];

// Helper function: Tự động tạo giao dịch mẫu nếu ví mới được tạo
function seedMockTransactions($db, $walletId, $role) {
    $now = time();
    if ($role === 'mangaka') {
        $mocks = [
            // Month 1 (Feb 2026)
            ['type' => 'earn', 'amount' => 12000000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 02/2026', 'status' => 'completed', 'days_ago' => 150],
            ['type' => 'platform_fee', 'amount' => -3600000, 'ref_type' => 'chapter', 'desc' => 'Phí platform doanh thu tháng 02/2026 (30%)', 'status' => 'completed', 'days_ago' => 150],
            
            // Month 2 (Mar 2026)
            ['type' => 'earn', 'amount' => 15000000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 03/2026', 'status' => 'completed', 'days_ago' => 120],
            ['type' => 'platform_fee', 'amount' => -4500000, 'ref_type' => 'chapter', 'desc' => 'Phí platform doanh thu tháng 03/2026 (30%)', 'status' => 'completed', 'days_ago' => 120],
            ['type' => 'withdraw', 'amount' => -8000000, 'ref_type' => 'withdrawal', 'desc' => 'Rút tiền về ngân hàng MB Bank', 'status' => 'completed', 'days_ago' => 110],
            ['type' => 'withdraw_fee', 'amount' => -160000, 'ref_type' => 'withdrawal', 'desc' => 'Phí xử lý rút tiền MB Bank (2%)', 'status' => 'completed', 'days_ago' => 110],
            
            // Month 3 (Apr 2026)
            ['type' => 'earn', 'amount' => 14500000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 04/2026', 'status' => 'completed', 'days_ago' => 90],
            ['type' => 'platform_fee', 'amount' => -4350000, 'ref_type' => 'chapter', 'desc' => 'Phí platform doanh thu tháng 04/2026 (30%)', 'status' => 'completed', 'days_ago' => 90],
            ['type' => 'salary_pay', 'amount' => -3000000, 'ref_type' => 'salary', 'desc' => 'Thanh toán tiền lương phụ tá (vẽ background tháng 4)', 'status' => 'completed', 'days_ago' => 85],
            
            // Month 4 (May 2026)
            ['type' => 'earn', 'amount' => 18000000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 05/2026', 'status' => 'completed', 'days_ago' => 60],
            ['type' => 'platform_fee', 'amount' => -5400000, 'ref_type' => 'chapter', 'desc' => 'Phí platform doanh thu tháng 05/2026 (30%)', 'status' => 'completed', 'days_ago' => 60],
            ['type' => 'withdraw', 'amount' => -10000000, 'ref_type' => 'withdrawal', 'desc' => 'Rút tiền nhanh qua VietQR', 'status' => 'completed', 'days_ago' => 50],
            ['type' => 'withdraw_fee', 'amount' => -200000, 'ref_type' => 'withdrawal', 'desc' => 'Phí xử lý rút tiền VietQR (2%)', 'status' => 'completed', 'days_ago' => 50],
            
            // Month 5 (Jun 2026)
            ['type' => 'earn', 'amount' => 16000000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 06/2026', 'status' => 'completed', 'days_ago' => 30],
            ['type' => 'platform_fee', 'amount' => -4800000, 'ref_type' => 'chapter', 'desc' => 'Phí platform doanh thu tháng 06/2026 (30%)', 'status' => 'completed', 'days_ago' => 30],
            ['type' => 'tip', 'amount' => 2000000, 'ref_type' => 'tip', 'desc' => 'Tiền tip độc giả ủng hộ bộ truyện của tôi', 'status' => 'completed', 'days_ago' => 25],
            ['type' => 'platform_fee', 'amount' => -300000, 'ref_type' => 'tip', 'desc' => 'Phí platform tiền tip (15%)', 'status' => 'completed', 'days_ago' => 25],
            
            // Month 6 (Jul 2026 - Current)
            ['type' => 'earn', 'amount' => 9000000, 'ref_type' => 'chapter', 'desc' => 'Doanh thu bán chương truyện tháng 07/2026 (Tạm tính)', 'status' => 'completed', 'days_ago' => 5],
            ['type' => 'platform_fee', 'amount' => -2700000, 'ref_type' => 'chapter', 'desc' => 'Phí platform tháng 07/2026 (30% - Tạm tính)', 'status' => 'completed', 'days_ago' => 5],
            ['type' => 'tip', 'amount' => 1500000, 'ref_type' => 'tip', 'desc' => 'Tiền tip độc giả tháng 7', 'status' => 'completed', 'days_ago' => 2],
            ['type' => 'platform_fee', 'amount' => -225000, 'ref_type' => 'tip', 'desc' => 'Phí platform tiền tip tháng 7 (15%)', 'status' => 'completed', 'days_ago' => 2],
            
            // Pending withdrawal to show pending_balance in action
            ['type' => 'withdraw', 'amount' => -1500000, 'ref_type' => 'withdrawal', 'desc' => 'Yêu cầu rút tiền qua MoMo đang xử lý', 'status' => 'pending', 'days_ago' => 1],
        ];
    } else {
        $mocks = [
            // Month 1 (Feb 2026)
            ['type' => 'salary_receive', 'amount' => 4500000, 'ref_type' => 'salary', 'desc' => 'Nhận lương vẽ background chương 40-41', 'status' => 'completed', 'days_ago' => 150],
            
            // Month 2 (Mar 2026)
            ['type' => 'salary_receive', 'amount' => 5000000, 'ref_type' => 'salary', 'desc' => 'Nhận lương vẽ line-art chương 42', 'status' => 'completed', 'days_ago' => 120],
            ['type' => 'withdraw', 'amount' => -6000000, 'ref_type' => 'withdrawal', 'desc' => 'Rút tiền về ví MoMo', 'status' => 'completed', 'days_ago' => 115],
            ['type' => 'withdraw_fee', 'amount' => -120000, 'ref_type' => 'withdrawal', 'desc' => 'Phí rút tiền về ví MoMo (2%)', 'status' => 'completed', 'days_ago' => 115],
            
            // Month 3 (Apr 2026)
            ['type' => 'salary_receive', 'amount' => 3800000, 'ref_type' => 'salary', 'desc' => 'Nhận lương vẽ chương 43', 'status' => 'completed', 'days_ago' => 90],
            
            // Month 4 (May 2026)
            ['type' => 'salary_receive', 'amount' => 5400000, 'ref_type' => 'salary', 'desc' => 'Nhận lương vẽ chương 44 (18 trang x 300Kđ/trang)', 'status' => 'completed', 'days_ago' => 60],
            ['type' => 'withdraw', 'amount' => -6500000, 'ref_type' => 'withdrawal', 'desc' => 'Rút tiền về tài khoản Techcombank', 'status' => 'completed', 'days_ago' => 50],
            ['type' => 'withdraw_fee', 'amount' => -130000, 'ref_type' => 'withdrawal', 'desc' => 'Phí rút tiền về tài khoản Techcombank (2%)', 'status' => 'completed', 'days_ago' => 50],
            
            // Month 5 (Jun 2026)
            ['type' => 'salary_receive', 'amount' => 4800000, 'ref_type' => 'salary', 'desc' => 'Nhận lương hoàn thành task tháng 6', 'status' => 'completed', 'days_ago' => 30],
            
            // Month 6 (Jul 2026 - Current)
            ['type' => 'salary_receive', 'amount' => 3500000, 'ref_type' => 'salary', 'desc' => 'Lương vẽ chương 47', 'status' => 'completed', 'days_ago' => 4],
            
            // Pending withdrawal to show pending_balance in action
            ['type' => 'withdraw', 'amount' => -1000000, 'ref_type' => 'withdrawal', 'desc' => 'Yêu cầu rút tiền qua VietQR đang chờ duyệt', 'status' => 'pending', 'days_ago' => 1],
        ];
    }

    $current_balance = 0.0;
    foreach ($mocks as $m) {
        $created_at = date('Y-m-d H:i:s', $now - ($m['days_ago'] * 86400));
        $amount = (float)$m['amount'];
        $balance_before = $current_balance;

        if ($m['status'] === 'completed') {
            $current_balance += $amount;
        }
        $balance_after = $current_balance;

        $stmt = $db->prepare("
            INSERT INTO transactions (wallet_id, type, amount, balance_before, balance_after, description, reference_type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $walletId, $m['type'], $amount, $balance_before, $balance_after, $m['desc'], $m['ref_type'], $m['status'], $created_at
        ]);
    }
}

function recalculateWalletBalancesLocal($db, $walletId) {
    $stmt = $db->prepare("SELECT type, amount, status FROM transactions WHERE wallet_id = ?");
    $stmt->execute([$walletId]);
    $txs = $stmt->fetchAll();

    $balance = 0.0;
    $pending_balance = 0.0;
    $total_earned = 0.0;
    $total_withdrawn = 0.0;

    foreach ($txs as $tx) {
        $amount = (float)$tx['amount'];
        $status = $tx['status'];
        $type = $tx['type'];

        if ($status === 'completed') {
            $balance += $amount;
            if ($amount > 0 && in_array($type, ['earn', 'salary_receive', 'tip', 'refund'])) {
                $total_earned += $amount;
            }
            if ($amount < 0 && $type === 'withdraw') {
                $total_withdrawn += abs($amount);
            }
        } elseif ($status === 'pending' && $type === 'withdraw') {
            // Khi gửi yêu cầu rút, tiền sẽ trừ khỏi khả dụng ngay và đưa vào pending_balance
            $balance += $amount;
            $pending_balance += abs($amount);
        }
    }

    $stmt = $db->prepare("
        UPDATE wallets 
        SET balance = ?, pending_balance = ?, total_earned = ?, total_withdrawn = ?
        WHERE id = ?
    ");
    $stmt->execute([$balance, $pending_balance, $total_earned, $total_withdrawn, $walletId]);
}

// Lấy thông tin ví của người dùng bằng hàm get_wallet() từ db.php
$wallet = get_wallet($userId);

if (!$wallet) {
    // Nếu chưa có bản ghi, tạo ví (Mặc dù đã có trigger và SQL chèn dự phòng ở database.sql)
    $stmt = $db->prepare("INSERT INTO wallets (user_id, balance, pending_balance, total_earned, total_withdrawn) VALUES (?, 0.00, 0.00, 0.00, 0.00)");
    $stmt->execute([$userId]);
    $wallet = get_wallet($userId);
}

// Kiểm tra và seed giao dịch mẫu nếu lịch sử giao dịch trống
$txCountStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE wallet_id = ?");
$txCountStmt->execute([$wallet['id']]);
$txCount = (int)$txCountStmt->fetchColumn();

if ($txCount === 0) {
    seedMockTransactions($db, $wallet['id'], $currentUser['role']);
    recalculateWalletBalancesLocal($db, $wallet['id']);
    // Fetch lại ví sau khi cập nhật dữ liệu
    $wallet = get_wallet($userId);
}

// --- BIỂU ĐỒ 1: THU NHẬP 6 THÁNG GẦN NHẤT ---
$earnType = ($currentUser['role'] === 'mangaka') ? 'earn' : 'salary_receive';
$monthlyQuery = $db->prepare("
    SELECT YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total
    FROM transactions
    WHERE wallet_id = ? AND type = ? AND status = 'completed'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY YEAR(created_at) ASC, MONTH(created_at) ASC
");
$monthlyQuery->execute([$wallet['id'], $earnType]);
$monthlyData = $monthlyQuery->fetchAll();

$chartLabels = [];
$chartValues = [];
for ($i = 5; $i >= 0; $i--) {
    $time = strtotime("-$i months");
    $m = (int)date('m', $time);
    $y = (int)date('Y', $time);
    $chartLabels[] = "Tháng " . $m;
    
    $total = 0;
    foreach ($monthlyData as $row) {
        if ((int)$row['month'] === $m && (int)$row['year'] === $y) {
            $total = (float)$row['total'];
            break;
        }
    }
    $chartValues[] = $total;
}

// --- BIỂU ĐỒ 2: PHÂN BỔ THU NHẬP ---
$sumsQuery = $db->prepare("
    SELECT type, SUM(ABS(amount)) as total
    FROM transactions
    WHERE wallet_id = ? AND status = 'completed'
    GROUP BY type
");
$sumsQuery->execute([$wallet['id']]);
$sumsData = $sumsQuery->fetchAll();

$earnVal = 0.0;
$payVal = 0.0;
$withdrawVal = 0.0;
$feeVal = 0.0;

foreach ($sumsData as $row) {
    $t = $row['type'];
    $val = (float)$row['total'];
    if ($t === $earnType || $t === 'tip') {
        $earnVal += $val;
    } elseif ($t === 'salary_pay') {
        $payVal += $val;
    } elseif ($t === 'withdraw') {
        $withdrawVal += $val;
    } elseif ($t === 'platform_fee' || $t === 'withdraw_fee') {
        $feeVal += $val;
    }
}

// --- XỬ LÝ LỌC & PHÂN TRANG LỊCH SỬ GIAO DỊCH ---
$filterType = $_GET['type'] ?? '';
$filterMonth = $_GET['month'] ?? ''; // Format: YYYY-MM
$filterSearch = trim($_GET['search'] ?? '');
$filterPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Gọi hàm get_transactions() được tạo ở db.php
$txResult = get_transactions($wallet['id'], $filterType, $filterMonth, $filterPage, $filterSearch, 20);
$transactions = $txResult['data'];
$totalPages = $txResult['total_pages'];
$page = $txResult['page'];

// Tạo danh sách 12 tháng gần nhất cho filter
$monthsFilterOptions = [];
for ($i = 0; $i < 12; $i++) {
    $time = strtotime("-$i months");
    $m_label = "Tháng " . date('m/Y', $time);
    $m_val = date('Y-m', $time);
    $monthsFilterOptions[] = ['label' => $m_label, 'val' => $m_val];
}

// Thông tin phục vụ hiển thị loại giao dịch
$txTypesInfo = [
    'earn'           => ['label' => 'Thu nhập',     'badge' => 'earn'],
    'salary_pay'     => ['label' => 'Trả lương',    'badge' => 'salary_pay'],
    'salary_receive' => ['label' => 'Nhận lương',   'badge' => 'salary_receive'],
    'withdraw'       => ['label' => 'Rút tiền',     'badge' => 'withdraw'],
    'withdraw_fee'   => ['label' => 'Phí rút',      'badge' => 'withdraw_fee'],
    'platform_fee'   => ['label' => 'Phí platform', 'badge' => 'platform_fee'],
    'tip'            => ['label' => 'Tip',          'badge' => 'tip'],
    'refund'         => ['label' => 'Hoàn tiền',    'badge' => 'refund'],
];
?>

<style>
/* Reset and utilities */
.w-full { width: 100%; }
.d-flex { display: flex; }
.justify-between { justify-content: space-between; }
.align-center { align-items: center; }
.flex-wrap { flex-wrap: wrap; }
.gap-10 { gap: 10px; }
.gap-16 { gap: 16px; }

/* Grid styles */
.wallet-grid-4 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.wallet-grid-2 {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 900px) {
    .wallet-grid-2 {
        grid-template-columns: 1fr;
    }
}

/* Wallet Card Styles */
.overview-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.overview-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--card-color, var(--purple));
}

.overview-card.c-balance { --card-color: #10b981; }
.overview-card.c-pending { --card-color: #f59e0b; }
.overview-card.c-earned  { --card-color: #8b5cf6; }
.overview-card.c-withdraw{ --card-color: #f97316; }

.overview-info .c-title {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 0 0 6px 0;
}

.overview-info .c-val {
    font-size: 1.6rem;
    font-weight: 800;
    line-height: 1.2;
}

.overview-card.c-balance .c-val { color: #10b981; }
.overview-card.c-pending .c-val { color: #f59e0b; }
.overview-card.c-earned .c-val  { color: #a78bfa; }
.overview-card.c-withdraw .c-val{ color: #f97316; }

.overview-info .c-sub {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin: 4px 0 0 0;
    font-weight: 500;
}

.overview-icon {
    font-size: 1.8rem;
    color: var(--card-color);
    background: rgba(255, 255, 255, 0.02);
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.04);
}

/* Page action buttons */
.btn-action-group {
    display: flex;
    gap: 12px;
}

.btn-act {
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    transition: transform 0.15s, opacity 0.15s;
    cursor: pointer;
}

.btn-act:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.btn-act-primary {
    background: #10b981;
    color: #fff;
    border: 1px solid #10b981;
}

.btn-act-secondary {
    background: var(--bg-card);
    color: #fff;
    border: 1px solid var(--border);
}

/* Chart block */
.card-chart {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
}

.card-chart-title {
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-wrapper {
    position: relative;
    width: 100%;
    min-height: 260px;
}

/* History block */
.card-history {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
}

.history-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
}

.filter-form-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.form-filter-select, .form-filter-input {
    background: var(--bg-body);
    border: 1px solid var(--border);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.82rem;
    outline: none;
}

.form-filter-input {
    width: 220px;
}

/* Badges phân loại */
.badge-tx {
    font-size: 0.7rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 100px;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.badge-tx.earn           { background: rgba(16, 185, 129, 0.12); color: #10b981; }     /* xanh lá */
.badge-tx.salary_pay     { background: rgba(239, 68, 68, 0.12);  color: #ef4444; }     /* đỏ */
.badge-tx.salary_receive { background: rgba(59, 130, 246, 0.12);  color: #3b82f6; }     /* xanh dương */
.badge-tx.withdraw       { background: rgba(249, 115, 22, 0.12);  color: #f97316; }     /* cam */
.badge-tx.withdraw_fee   { background: rgba(107, 114, 128, 0.12); color: #9ca3af; }     /* xám */
.badge-tx.platform_fee   { background: rgba(209, 213, 219, 0.08); color: #9ca3af; }     /* xám nhạt */
.badge-tx.tip            { background: rgba(234, 179, 8, 0.12);   color: #eab308; }     /* vàng */
.badge-tx.refund         { background: rgba(167, 139, 250, 0.12); color: #a78bfa; }     /* tím */

.tx-amount {
    font-family: 'SF Mono', monospace;
    font-weight: 700;
}
.tx-amount.positive { color: #10b981; }
.tx-amount.negative { color: #ef4444; }

/* Status circle */
.tx-status-col {
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.tx-status-col.completed { color: #10b981; }
.tx-status-col.pending   { color: #f59e0b; }
.tx-status-col.failed    { color: #ef4444; }
.tx-status-col.cancelled { color: #9ca3af; }
.tx-status-col::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
</style>

<div class="wallet-container">

    <!-- Page Header & Action Buttons -->
    <div class="page-header justify-between align-center flex-wrap gap-16" style="display:flex; margin-bottom: 24px;">
        <div>
            <div class="breadcrumb">
                <span class="current">Ví điện tử</span>
            </div>
            <h1>Quản Lý Tài Chính & Ví</h1>
            <p>Kiểm soát dòng tiền cá nhân, xem phân bổ thu nhập và thực hiện yêu cầu thanh toán.</p>
        </div>
        <div class="btn-action-group">
            <a href="<?= BASE_URL ?>wallet/withdraw.php" class="btn-act btn-act-primary">
                <i class="fi fi-sr-wallet"></i> 💸 Rút tiền
            </a>
            <a href="<?= BASE_URL ?>wallet/withdrawals.php" class="btn-act btn-act-secondary">
                <i class="fi fi-rr-time-past"></i> 📋 Lịch sử rút tiền
            </a>
        </div>
    </div>

    <!-- PHẦN 1 - CARDS SỐ DƯ (grid 4 cột) -->
    <div class="wallet-grid-4">
        <!-- Card 1: Số dư khả dụng -->
        <div class="overview-card c-balance">
            <div class="overview-info">
                <p class="c-title">Số dư khả dụng</p>
                <div class="c-val"><?= format_money($wallet['balance']) ?> ₫</div>
                <p class="c-sub">Có thể rút ngay</p>
            </div>
            <div class="overview-icon">
                <i class="fi fi-sr-wallet"></i>
            </div>
        </div>

        <!-- Card 2: Đang chờ xử lý -->
        <div class="overview-card c-pending">
            <div class="overview-info">
                <p class="c-title">Đang chờ xử lý</p>
                <div class="c-val"><?= format_money($wallet['pending_balance']) ?> ₫</div>
                <p class="c-sub">Yêu cầu rút đang xử lý</p>
            </div>
            <div class="overview-icon">
                <i class="fi fi-sr-clock"></i>
            </div>
        </div>

        <!-- Card 3: Tổng đã kiếm -->
        <div class="overview-card c-earned">
            <div class="overview-info">
                <p class="c-title">Tổng đã kiếm</p>
                <div class="c-val"><?= format_money($wallet['total_earned']) ?> ₫</div>
                <p class="c-sub">Từ trước đến nay</p>
            </div>
            <div class="overview-icon">
                <i class="fi fi-sr-chart-line-up"></i>
            </div>
        </div>

        <!-- Card 4: Tổng đã rút -->
        <div class="overview-card c-withdraw">
            <div class="overview-info">
                <p class="c-title">Tổng đã rút</p>
                <div class="c-val"><?= format_money($wallet['total_withdrawn']) ?> ₫</div>
                <p class="c-sub">Đã rút thành công</p>
            </div>
            <div class="overview-icon">
                <i class="fi fi-sr-arrow-up-circle"></i>
            </div>
        </div>
    </div>

    <!-- PHẦN 2 - BIỂU ĐỒ (grid 2 cột) -->
    <div class="wallet-grid-2">
        <!-- Cột trái: Bar chart Thu nhập 6 tháng gần nhất -->
        <div class="card-chart">
            <div class="card-chart-title">
                <i class="fi fi-sr-chart-bar" style="color:#a78bfa;"></i>
                Thu nhập 6 tháng gần nhất
            </div>
            <div class="chart-wrapper">
                <canvas id="monthlyEarningsChart"></canvas>
            </div>
        </div>

        <!-- Cột phải: Doughnut chart Phân bổ thu nhập -->
        <div class="card-chart">
            <div class="card-chart-title">
                <i class="fi fi-sr-chart-pie" style="color:#10b981;"></i>
                Phân bổ thu nhập
            </div>
            <div class="chart-wrapper">
                <canvas id="financeAllocationChart"></canvas>
            </div>
        </div>
    </div>

    <!-- PHẦN 3 - LỊCH SỬ GIAO DỊCH -->
    <div class="card-history">
        <div class="history-toolbar">
            <div class="card-chart-title" style="margin-bottom:0;">
                <i class="fi fi-rr-list" style="color:var(--purple);"></i>
                Lịch sử giao dịch ví
            </div>
            
            <form method="GET" action="" class="filter-form-row">
                <!-- Chọn loại GD -->
                <select name="type" class="form-filter-select">
                    <option value="">Tất cả loại GD</option>
                    <option value="earn" <?= $filterType === 'earn' ? 'selected' : '' ?>>Thu nhập</option>
                    <?php if ($currentUser['role'] === 'mangaka'): ?>
                        <option value="salary_pay" <?= $filterType === 'salary_pay' ? 'selected' : '' ?>>Trả lương</option>
                        <option value="tip" <?= $filterType === 'tip' ? 'selected' : '' ?>>Tip</option>
                    <?php else: ?>
                        <option value="salary_receive" <?= $filterType === 'salary_receive' ? 'selected' : '' ?>>Nhận lương</option>
                    <?php endif; ?>
                    <option value="withdraw" <?= $filterType === 'withdraw' ? 'selected' : '' ?>>Rút tiền</option>
                </select>

                <!-- Chọn tháng (12 tháng gần nhất) -->
                <select name="month" class="form-filter-select">
                    <option value="">Tất cả các tháng</option>
                    <?php foreach ($monthsFilterOptions as $opt): ?>
                        <option value="<?= $opt['val'] ?>" <?= $filterMonth === $opt['val'] ? 'selected' : '' ?>>
                            <?= $opt['label'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Tìm kiếm mô tả -->
                <input type="text" name="search" class="form-filter-input" placeholder="Tìm kiếm mô tả..." value="<?= htmlspecialchars($filterSearch) ?>">

                <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size:0.8rem; font-weight:700;">Lọc</button>
                <?php if (!empty($filterType) || !empty($filterMonth) || !empty($filterSearch)): ?>
                    <a href="index.php" class="btn btn-secondary" style="padding: 8px 16px; font-size:0.8rem; text-decoration:none; display:inline-flex; align-items:center; font-weight:600;">Đặt lại</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="tx-table-wrap">
            <table class="tx-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Thời gian</th>
                        <th style="width: 140px;">Loại</th>
                        <th>Mô tả</th>
                        <th style="width: 130px; text-align: right;">Số tiền</th>
                        <th style="width: 130px; text-align: right;">Số dư sau</th>
                        <th style="width: 120px; text-align: center;">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 48px;">
                                <i class="fi fi-rr-envelope-open" style="font-size: 2.2rem; display: block; margin-bottom: 12px; opacity:0.35;"></i>
                                Không tìm thấy dữ liệu giao dịch nào.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php 
                            $typeMeta = $txTypesInfo[$tx['type']] ?? ['label' => $tx['type'], 'badge' => 'platform_fee'];
                            
                            // Các loại giao dịch trừ tiền
                            $isMinusType = in_array($tx['type'], ['salary_pay', 'withdraw', 'withdraw_fee', 'platform_fee']);
                            
                            $formattedAmount = ($isMinusType ? '-' : '+') . format_money(abs($tx['amount'])) . ' ₫';
                            $amountClass = $isMinusType ? 'negative' : 'positive';
                            ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 0.85rem;">
                                    <?= date('H:i d/m/Y', strtotime($tx['created_at'])) ?>
                                </td>
                                <td>
                                    <span class="badge-tx <?= $typeMeta['badge'] ?>">
                                        <?= $typeMeta['label'] ?>
                                    </span>
                                </td>
                                <td style="color: #fff; font-weight: 500; font-size: 0.85rem;"><?= htmlspecialchars($tx['description']) ?></td>
                                <td class="tx-amount <?= $amountClass ?>" style="text-align: right;">
                                    <?= $formattedAmount ?>
                                </td>
                                <td style="text-align: right; font-family:'SF Mono',monospace; color:var(--text-muted);">
                                    <?= format_money($tx['balance_after']) ?> ₫
                                </td>
                                <td style="text-align: center;">
                                    <span class="tx-status-col <?= $tx['status'] ?>">
                                        <?= ($tx['status'] === 'completed') ? 'Thành công' : (($tx['status'] === 'pending') ? 'Đang chờ' : (($tx['status'] === 'failed') ? 'Thất bại' : 'Đã huỷ')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Phân trang -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?page=<?= $page - 1 ?>&type=<?= urlencode($filterType) ?>&month=<?= urlencode($filterMonth) ?>&search=<?= urlencode($filterSearch) ?>" 
                   class="page-link <?= ($page <= 1) ? 'disabled' : '' ?>" 
                   onclick="return <?= ($page <= 1) ? 'false' : 'true' ?>;">&lsaquo;</a>
                
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?= $p ?>&type=<?= urlencode($filterType) ?>&month=<?= urlencode($filterMonth) ?>&search=<?= urlencode($filterSearch) ?>" 
                       class="page-link <?= ($page === $p) ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
                
                <a href="?page=<?= $page + 1 ?>&type=<?= urlencode($filterType) ?>&month=<?= urlencode($filterMonth) ?>&search=<?= urlencode($filterSearch) ?>" 
                   class="page-link <?= ($page >= $totalPages) ? 'disabled' : '' ?>" 
                   onclick="return <?= ($page >= $totalPages) ? 'false' : 'true' ?>;">&rsaquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Biểu đồ cột (Bar Chart) thu nhập 6 tháng gần nhất với màu gradient Tím -> Đỏ
const ctxBar = document.getElementById('monthlyEarningsChart').getContext('2d');
const barLabels = <?= json_encode($chartLabels) ?>;
const barData = <?= json_encode($chartValues) ?>;

// Tạo gradient màu Tím -> Đỏ
const purpleRedGradient = ctxBar.createLinearGradient(0, 0, 0, 300);
purpleRedGradient.addColorStop(0, '#ef4444'); // Đỏ ở trên cùng
purpleRedGradient.addColorStop(1, '#8b5cf6'); // Tím ở dưới cùng

new Chart(ctxBar, {
    type: 'bar',
    data: {
        labels: barLabels,
        datasets: [{
            label: 'Thu nhập',
            data: barData,
            backgroundColor: purpleRedGradient,
            borderColor: 'rgba(239, 68, 68, 0.4)',
            borderWidth: 1,
            borderRadius: 6,
            barThickness: 28
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' Thu nhập: ' + context.raw.toLocaleString() + ' ₫';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(255, 255, 255, 0.05)'
                },
                ticks: {
                    color: '#9ca3af',
                    font: { size: 10 },
                    callback: function(value) {
                        if (value >= 1000000) {
                            return (value / 1000000) + 'M ₫';
                        }
                        return value.toLocaleString() + ' ₫';
                    }
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: '#9ca3af',
                    font: { size: 10 }
                }
            }
        }
    }
});

// Biểu đồ Doughnut phân bổ thu nhập
const ctxPie = document.getElementById('financeAllocationChart').getContext('2d');
const isMangaka = <?= ($currentUser['role'] === 'mangaka') ? 'true' : 'false' ?>;

// Dữ liệu PHP truyền vào
const earnVal = <?= $earnVal ?>;
const payVal = <?= $payVal ?>;
const withdrawVal = <?= $withdrawVal ?>;
const feeVal = <?= $feeVal ?>;

let doughnutLabels = [];
let doughnutData = [];
let doughnutColors = [];

// earn -> xanh lá
doughnutLabels.push('Thu nhập');
doughnutData.push(earnVal);
doughnutColors.push('#10b981');

// salary_pay -> đỏ (Chỉ hiện đối với Mangaka)
if (isMangaka) {
    doughnutLabels.push('Trả lương');
    doughnutData.push(payVal);
    doughnutColors.push('#ef4444');
}

// withdraw -> cam
doughnutLabels.push('Đã rút');
doughnutData.push(withdrawVal);
doughnutColors.push('#f97316');

// platform_fee -> xám
doughnutLabels.push('Phí platform');
doughnutData.push(feeVal);
doughnutColors.push('#6b7280');

new Chart(ctxPie, {
    type: 'doughnut',
    data: {
        labels: doughnutLabels,
        datasets: [{
            data: doughnutData,
            backgroundColor: doughnutColors,
            borderColor: 'var(--bg-card)',
            borderWidth: 2,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#9ca3af',
                    font: {
                        size: 11,
                        weight: 600
                    },
                    boxWidth: 12,
                    padding: 12
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const value = context.raw;
                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                        return ' ' + context.label + ': ' + value.toLocaleString() + ' ₫ (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
