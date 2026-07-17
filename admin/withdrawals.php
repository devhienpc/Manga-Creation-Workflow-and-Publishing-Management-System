<?php
/**
 * admin/withdrawals.php
 * Quản lý yêu cầu rút tiền — Dành cho Board & Admin.
 * Trang quan trọng nhất của module tài chính.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Quản lý rút tiền';
$activePage   = 'withdrawals';
$allowedRoles = [ROLES['BOARD'], ROLES['ADMIN']];
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// ═══════════════════════════════════════════════════════════
// THỐNG KÊ HEADER CARDS
// ═══════════════════════════════════════════════════════════
$pendingCount = 0; $processingCount = 0; $completedToday = 0; $totalPaidToday = 0;
try {
    $pendingCount    = (int)$db->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending'")->fetchColumn();
    $processingCount = (int)$db->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'processing'")->fetchColumn();
    $completedToday  = (int)$db->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'completed' AND DATE(processed_at) = CURDATE()")->fetchColumn();
    $totalPaidToday  = (float)$db->query("SELECT COALESCE(SUM(net_amount), 0) FROM withdrawal_requests WHERE status = 'completed' AND DATE(processed_at) = CURDATE()")->fetchColumn();
} catch (\Throwable $e) {}

// ═══════════════════════════════════════════════════════════
// FILTER & PHÂN TRANG — TAB RÚT TIỀN
// ═══════════════════════════════════════════════════════════
$fStatus   = trim($_GET['status'] ?? '');
$fMethod   = trim($_GET['method'] ?? '');
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo   = trim($_GET['date_to'] ?? '');
$fSearch   = trim($_GET['search'] ?? '');
$fPage     = max(1, (int)($_GET['page'] ?? 1));
$limit     = 15;

$where  = ['1=1'];
$params = [];

if ($fStatus !== '') {
    $where[]  = 'wr.status = ?';
    $params[] = $fStatus;
}
if ($fMethod !== '') {
    $where[]  = 'wr.method = ?';
    $params[] = $fMethod;
}
if ($fDateFrom !== '') {
    $where[]  = 'DATE(wr.requested_at) >= ?';
    $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
    $where[]  = 'DATE(wr.requested_at) <= ?';
    $params[] = $fDateTo;
}
if ($fSearch !== '') {
    $where[]  = '(u.username LIKE ? OR wr.account_number LIKE ? OR wr.account_name LIKE ?)';
    $params[] = "%$fSearch%";
    $params[] = "%$fSearch%";
    $params[] = "%$fSearch%";
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM withdrawal_requests wr JOIN users u ON wr.user_id = u.id WHERE $whereSql");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $limit));
$fPage      = min($totalPages, $fPage);
$offset     = ($fPage - 1) * $limit;

// Data
$dataStmt = $db->prepare("
    SELECT wr.*, u.username, u.email, u.role, u.avatar,
           w.balance AS wallet_balance, w.total_earned AS wallet_earned, w.total_withdrawn AS wallet_withdrawn
    FROM withdrawal_requests wr
    JOIN users u ON wr.user_id = u.id
    LEFT JOIN wallets w ON w.user_id = u.id
    WHERE $whereSql
    ORDER BY
        FIELD(wr.status, 'pending', 'processing', 'completed', 'rejected'),
        wr.requested_at DESC
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
");
$dataStmt->execute($params);
$requests = $dataStmt->fetchAll();

// ═══════════════════════════════════════════════════════════
// THỐNG KÊ TÀI CHÍNH — TAB 2
// ═══════════════════════════════════════════════════════════
$platformRevMonth = 0;
try {
    $platformRevMonth = (float)$db->query("
        SELECT COALESCE(SUM(ABS(amount)), 0) FROM transactions
        WHERE type = 'platform_fee' AND status = 'completed'
          AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
    ")->fetchColumn();
} catch (\Throwable $e) {}

// Doanh thu platform 12 tháng
$monthlyPlatformRev = [];
try {
    $stmt12 = $db->query("
        SELECT YEAR(created_at) y, MONTH(created_at) m, COALESCE(SUM(ABS(amount)), 0) total
        FROM transactions
        WHERE type = 'platform_fee' AND status = 'completed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY y ASC, m ASC
    ");
    $monthlyPlatformRev = $stmt12->fetchAll();
} catch (\Throwable $e) {}

$chartLabels12 = [];
$chartValues12 = [];
for ($i = 11; $i >= 0; $i--) {
    $t = strtotime("-$i months");
    $mm = (int)date('m', $t);
    $yy = (int)date('Y', $t);
    $chartLabels12[] = "T$mm/$yy";
    $val = 0;
    foreach ($monthlyPlatformRev as $r) {
        if ((int)$r['m'] === $mm && (int)$r['y'] === $yy) { $val = (float)$r['total']; break; }
    }
    $chartValues12[] = $val;
}

// Top 5 mangaka
$topMangaka = [];
try {
    $topMangaka = $db->query("
        SELECT u.username, u.avatar, SUM(t.amount) total_earned
        FROM transactions t
        JOIN wallets w ON t.wallet_id = w.id
        JOIN users u ON w.user_id = u.id
        WHERE t.type = 'earn' AND t.status = 'completed' AND u.role = 'mangaka'
        GROUP BY u.id ORDER BY total_earned DESC LIMIT 5
    ")->fetchAll();
} catch (\Throwable $e) {}

// Top 5 assistant
$topAssistant = [];
try {
    $topAssistant = $db->query("
        SELECT u.username, u.avatar, SUM(t.amount) total_earned
        FROM transactions t
        JOIN wallets w ON t.wallet_id = w.id
        JOIN users u ON w.user_id = u.id
        WHERE t.type = 'salary_receive' AND t.status = 'completed' AND u.role = 'assistant'
        GROUP BY u.id ORDER BY total_earned DESC LIMIT 5
    ")->fetchAll();
} catch (\Throwable $e) {}

// Tổng tiền đang giữ trong tất cả ví
$totalHeld = 0;
try {
    $totalHeld = (float)$db->query("SELECT COALESCE(SUM(balance + pending_balance), 0) FROM wallets")->fetchColumn();
} catch (\Throwable $e) {}

// ═══════════════════════════════════════════════════════════
// HELPER
// ═══════════════════════════════════════════════════════════
$statusMeta = [
    'pending'    => ['label' => 'Chờ xử lý',  'cls' => 'wd-st-pending'],
    'processing' => ['label' => 'Đang xử lý', 'cls' => 'wd-st-processing'],
    'completed'  => ['label' => 'Hoàn thành',  'cls' => 'wd-st-completed'],
    'rejected'   => ['label' => 'Từ chối',     'cls' => 'wd-st-rejected'],
];
$roleLabels = [
    'mangaka'   => 'Họa sĩ',
    'assistant' => 'Trợ lý',
    'editor'    => 'BTV',
    'board'     => 'BBT',
    'admin'     => 'Admin',
];

function maskAccount($num) {
    $len = strlen($num);
    if ($len <= 5) return $num;
    return substr($num, 0, 4) . str_repeat('*', $len - 7) . substr($num, -3);
}

function generateVietQR($bankCode, $accountNo, $amount, $desc, $accountName = '') {
    return "https://img.vietqr.io/image/"
        . urlencode($bankCode) . "-" . urlencode($accountNo)
        . "-compact2.jpg"
        . "?amount=" . (int)$amount
        . "&addInfo=" . urlencode($desc)
        . "&accountName=" . urlencode(strtoupper($accountName));
}

// JSON data for modal JS
$requestsJson = [];
foreach ($requests as $req) {
    $avatarUrl = null;
    if (!empty($req['avatar'])) {
        $avatarUrl = avatarImageUrl($req['avatar']);
    }
    
    $qrUrl = null;
    if ($req['method'] === 'bank_transfer' && !empty($req['bank_code'])) {
        $wdCode = 'MANGA WD' . str_pad($req['id'], 6, '0', STR_PAD_LEFT);
        $qrUrl = generateVietQR($req['bank_code'], $req['account_number'], $req['net_amount'], $wdCode, $req['account_name']);
    }

    $requestsJson[$req['id']] = [
        'id'              => (int)$req['id'],
        'username'        => $req['username'],
        'email'           => $req['email'],
        'role'            => $req['role'],
        'avatar'          => $avatarUrl,
        'wallet_balance'  => (float)($req['wallet_balance'] ?? 0),
        'wallet_earned'   => (float)($req['wallet_earned'] ?? 0),
        'wallet_withdrawn'=> (float)($req['wallet_withdrawn'] ?? 0),
        'method'          => $req['method'],
        'bank_name'       => $req['bank_name'] ?? '',
        'bank_code'       => $req['bank_code'] ?? '',
        'account_number'  => $req['account_number'],
        'account_name'    => $req['account_name'],
        'amount'          => (float)$req['amount'],
        'fee_amount'      => (float)$req['fee_amount'],
        'net_amount'      => (float)$req['net_amount'],
        'status'          => $req['status'],
        'admin_note'      => $req['admin_note'] ?? '',
        'requested_at'    => date('H:i d/m/Y', strtotime($req['requested_at'])),
        'qr_url'          => $qrUrl,
    ];
}
?>

<style>
/* ─── Tabs ─── */
.wd-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 24px;
}
.wd-tab {
    padding: 12px 24px;
    font-size: .88rem;
    font-weight: 700;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all var(--dur) var(--ease);
    display: flex;
    align-items: center;
    gap: 8px;
}
.wd-tab:hover { color: #fff; }
.wd-tab.active { color: var(--red); border-bottom-color: var(--red); }
.wd-tab-content { display: none; }
.wd-tab-content.active { display: block; }

/* ─── Stat cards ─── */
.wd-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.wd-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    position: relative;
    overflow: hidden;
}
.wd-stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px;
    height: 100%;
    background: var(--sc-color);
}
.wd-stat-card .sc-label {
    font-size: .72rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}
.wd-stat-card .sc-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--sc-color);
}
.wd-stat-card .sc-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.6rem;
    color: var(--sc-color);
    opacity: .2;
}

/* ─── Filter bar ─── */
.wd-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
}
.wd-filter select, .wd-filter input {
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: .82rem;
    outline: none;
}
.wd-filter input[type="date"] { color: var(--text-muted); }
.wd-filter input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(.7); }
.wd-filter .lbl { font-size:.72rem; font-weight:600; color:var(--text-muted); margin-right:4px; }
.wd-filter-btn {
    background: var(--red);
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
    transition: opacity var(--dur);
}
.wd-filter-btn:hover { opacity: .85; }

/* ─── Table ─── */
.wd-table-wrap {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.wd-table {
    width: 100%;
    border-collapse: collapse;
}
.wd-table th, .wd-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    font-size: .82rem;
    text-align: left;
    white-space: nowrap;
}
.wd-table th {
    background: rgba(255,255,255,.01);
    color: var(--text-muted);
    font-weight: 700;
    text-transform: uppercase;
    font-size: .72rem;
    letter-spacing: .4px;
}
.wd-table tbody tr { transition: background var(--dur); }
.wd-table tbody tr:hover { background: var(--bg-hover); }

/* User cell */
.wd-user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}
.wd-user-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--bg-input);
}
.wd-user-name { font-weight: 600; color: #fff; }
.wd-user-role {
    font-size: .65rem; font-weight: 700;
    padding: 1px 6px; border-radius: 4px;
    margin-left: 4px;
}
.wd-user-role.mangaka   { background: rgba(139,92,246,.15); color: #a78bfa; }
.wd-user-role.assistant { background: rgba(59,130,246,.15);  color: #60a5fa; }

/* Status badges */
.wd-st-pending    { background: rgba(245,158,11,.12); color: #f59e0b; }
.wd-st-processing { background: rgba(59,130,246,.12);  color: #3b82f6; }
.wd-st-completed  { background: rgba(16,185,129,.12);  color: #10b981; }
.wd-st-rejected   { background: rgba(239,68,68,.12);   color: #ef4444; }
.wd-badge {
    font-size: .7rem; font-weight: 700;
    padding: 3px 10px; border-radius: 100px;
    display: inline-block;
}

/* Method icon */
.wd-method-icon { font-size:.85rem; margin-right:4px; }

/* Action btn */
.btn-process {
    background: rgba(139,92,246,.12);
    color: #a78bfa;
    border: 1px solid rgba(139,92,246,.2);
    padding: 5px 12px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--dur);
}
.btn-process:hover { background: rgba(139,92,246,.25); }

/* Money formatting */
.money-pos { color: #10b981; font-weight: 700; }
.money-neg { color: #ef4444; font-weight: 700; }
.money-val { font-family: 'SF Mono', 'Consolas', monospace; font-weight: 700; color: #fff; }

/* ─── Modal ─── */
.wd-modal-backdrop {
    position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,.75);
    backdrop-filter: blur(6px);
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 20px;
    overflow-y: auto;
}
.wd-modal-backdrop.open { display: flex; animation: wdFadeIn .2s ease; }
@keyframes wdFadeIn { from { opacity:0 } to { opacity:1 } }

.wd-modal {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    max-width: 680px;
    width: 100%;
    animation: wdSlideIn .25s var(--ease);
    box-shadow: 0 30px 70px rgba(0,0,0,.6);
}
@keyframes wdSlideIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

.wd-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
}
.wd-modal-header h3 { font-size: 1rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 8px; }
.wd-modal-close {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 1.4rem; padding: 4px 8px; border-radius: 6px; line-height:1;
}
.wd-modal-close:hover { color: var(--red); background: rgba(230,57,70,.08); }
.wd-modal-body { padding: 24px; }

/* Modal sections */
.wd-section {
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 16px;
}
.wd-section-title {
    font-size: .78rem; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .5px;
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.wd-info-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: .83rem;
}
.wd-info-row .lk { color: var(--text-muted); }
.wd-info-row .lv { color: #fff; font-weight: 600; }

/* QR section */
.wd-qr-box {
    text-align: center;
    padding: 20px;
}
.wd-qr-box img {
    border-radius: 12px;
    border: 2px solid var(--border);
    background: #fff;
}
.wd-qr-guide {
    font-size: .78rem;
    color: var(--text-muted);
    margin-top: 12px;
    line-height: 1.6;
}
.wd-ewallet-box {
    text-align: center;
    padding: 16px;
}
.wd-ewallet-phone {
    font-size: 1.6rem;
    font-weight: 800;
    color: #fff;
    margin: 8px 0;
    font-family: 'SF Mono', 'Consolas', monospace;
}

/* Action buttons in modal */
.wd-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.wd-btn {
    flex: 1;
    padding: 10px 16px;
    border-radius: var(--radius-sm);
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all var(--dur);
}
.wd-btn:hover { opacity: .85; transform: translateY(-1px); }
.wd-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }
.wd-btn-process  { background: #3b82f6; color: #fff; }
.wd-btn-complete { background: #10b981; color: #fff; }
.wd-btn-reject   { background: #ef4444; color: #fff; }

/* Reject textarea */
.wd-reject-area {
    display: none;
    margin-top: 12px;
}
.wd-reject-area.show { display: block; }
.wd-reject-area textarea {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: #fff;
    padding: 10px;
    border-radius: 6px;
    font-size: .84rem;
    min-height: 80px;
    outline: none;
    resize: vertical;
}
.wd-reject-send {
    margin-top: 8px;
    background: #ef4444;
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
}

/* ─── Tab 2: Finance stats ─── */
.fin-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width:800px) { .fin-grid-2 { grid-template-columns: 1fr; } }
.fin-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
}
.fin-card-title {
    font-size: .88rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
}
.fin-big-num {
    font-size: 1.5rem;
    font-weight: 800;
    color: #10b981;
}

.top-table {
    width: 100%;
    border-collapse: collapse;
}
.top-table th, .top-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    font-size: .82rem;
}
.top-table th {
    color: var(--text-muted);
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
}
.top-user {
    display: flex;
    align-items: center;
    gap: 8px;
}
.top-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    object-fit: cover; background: var(--bg-input);
}
.rank-badge {
    width: 22px; height: 22px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .7rem; font-weight: 800; color: #fff;
}
.rank-1 { background: #f59e0b; }
.rank-2 { background: #94a3b8; }
.rank-3 { background: #cd7f32; }

/* Toast */
.wd-toast {
    position: fixed; top: 24px; right: 24px; z-index: 10000;
    padding: 14px 24px; border-radius: var(--radius-sm);
    font-size: .85rem; font-weight: 700; color: #fff;
    opacity: 0; transform: translateY(-20px);
    transition: all .35s var(--ease); pointer-events: none;
}
.wd-toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
.wd-toast.success { background: #10b981; }
.wd-toast.error   { background: #ef4444; }
.wd-toast.warning { background: #f59e0b; }

/* Pagination */
.wd-pagination {
    display: flex; justify-content: center; gap: 4px; padding: 16px;
}
.wd-pg-link {
    padding: 6px 12px; border-radius: 6px;
    font-size: .82rem; font-weight: 600;
    color: var(--text-muted); background: var(--bg-input);
    text-decoration: none; transition: all var(--dur);
    border: 1px solid var(--border);
}
.wd-pg-link:hover { color: #fff; border-color: rgba(255,255,255,.15); }
.wd-pg-link.active { background: var(--red); color: #fff; border-color: var(--red); }
.wd-pg-link.disabled { opacity: .3; pointer-events: none; }
</style>

<!-- Toast -->
<div class="wd-toast" id="wdToast"></div>

<!-- Page header -->
<div style="margin-bottom:20px;">
    <div class="breadcrumb"><span class="current">Quản lý rút tiền & Tài chính</span></div>
    <h1>Quản Lý Yêu Cầu Rút Tiền</h1>
</div>

<!-- Stats cards -->
<div class="wd-stats">
    <div class="wd-stat-card" style="--sc-color:#f59e0b;">
        <div class="sc-label">Đang chờ xử lý</div>
        <div class="sc-val"><?= $pendingCount ?></div>
        <i class="fi fi-sr-clock sc-icon"></i>
    </div>
    <div class="wd-stat-card" style="--sc-color:#3b82f6;">
        <div class="sc-label">Đang xử lý</div>
        <div class="sc-val"><?= $processingCount ?></div>
        <i class="fi fi-sr-rotate-right sc-icon"></i>
    </div>
    <div class="wd-stat-card" style="--sc-color:#10b981;">
        <div class="sc-label">Hoàn thành hôm nay</div>
        <div class="sc-val"><?= $completedToday ?></div>
        <i class="fi fi-sr-check-circle sc-icon"></i>
    </div>
    <div class="wd-stat-card" style="--sc-color:#a78bfa;">
        <div class="sc-label">Tổng đã chi hôm nay</div>
        <div class="sc-val"><?= format_money($totalPaidToday) ?> ₫</div>
        <i class="fi fi-sr-money-bill-wave sc-icon"></i>
    </div>
</div>

<!-- Tabs -->
<div class="wd-tabs">
    <div class="wd-tab active" data-tab="tab-withdraw" onclick="switchWdTab('tab-withdraw')">
        <i class="fi fi-rr-money-check"></i> Yêu cầu rút tiền
        <?php if ($pendingCount > 0): ?>
            <span style="background:var(--red); color:#fff; font-size:.65rem; font-weight:800; padding:2px 7px; border-radius:100px; margin-left:2px;"><?= $pendingCount ?></span>
        <?php endif; ?>
    </div>
    <div class="wd-tab" data-tab="tab-finance" onclick="switchWdTab('tab-finance')">
        <i class="fi fi-rr-chart-pie"></i> Thống kê tài chính
    </div>
</div>

<!-- ═══════════════ TAB 1: RÚT TIỀN ═══════════════ -->
<div class="wd-tab-content active" id="tab-withdraw">

    <!-- Filter bar -->
    <form class="wd-filter" method="GET" action="">
        <span class="lbl">Trạng thái:</span>
        <select name="status">
            <option value="">Tất cả</option>
            <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>Chờ xử lý</option>
            <option value="processing" <?= $fStatus === 'processing' ? 'selected' : '' ?>>Đang xử lý</option>
            <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>Hoàn thành</option>
            <option value="rejected" <?= $fStatus === 'rejected' ? 'selected' : '' ?>>Từ chối</option>
        </select>

        <span class="lbl">Phương thức:</span>
        <select name="method">
            <option value="">Tất cả</option>
            <option value="bank_transfer" <?= $fMethod === 'bank_transfer' ? 'selected' : '' ?>>Ngân hàng</option>
            <option value="momo" <?= $fMethod === 'momo' ? 'selected' : '' ?>>MoMo</option>
            <option value="zalopay" <?= $fMethod === 'zalopay' ? 'selected' : '' ?>>ZaloPay</option>
        </select>

        <span class="lbl">Từ:</span>
        <input type="date" name="date_from" value="<?= htmlspecialchars($fDateFrom) ?>">
        <span class="lbl">Đến:</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($fDateTo) ?>">

        <input type="text" name="search" placeholder="Tên user / Số TK..." value="<?= htmlspecialchars($fSearch) ?>" style="width:180px;">
        <button type="submit" class="wd-filter-btn">Lọc</button>
        <?php if ($fStatus || $fMethod || $fDateFrom || $fDateTo || $fSearch): ?>
            <a href="withdrawals.php" style="font-size:.78rem; color:var(--text-muted); text-decoration:none; font-weight:600;">Đặt lại</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="wd-table-wrap">
        <div style="overflow-x:auto;">
            <table class="wd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Phương thức</th>
                        <th>Tài khoản</th>
                        <th style="text-align:right;">Rút</th>
                        <th style="text-align:right;">Phí</th>
                        <th style="text-align:right;">Thực nhận</th>
                        <th>Ngày yêu cầu</th>
                        <th style="text-align:center;">Trạng thái</th>
                        <th style="text-align:center;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:48px; color:var(--text-muted);">
                                <i class="fi fi-rr-inbox-out" style="font-size:2rem; display:block; margin-bottom:10px; opacity:.3;"></i>
                                Không có yêu cầu nào phù hợp bộ lọc.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                            <?php
                            $sm = $statusMeta[$req['status']] ?? ['label' => $req['status'], 'cls' => 'wd-st-pending'];
                            $methodIcon = '🏦';
                            $methodName = 'Ngân hàng';
                            if ($req['method'] === 'momo')    { $methodIcon = '📱'; $methodName = 'MoMo'; }
                            if ($req['method'] === 'zalopay') { $methodIcon = '💳'; $methodName = 'ZaloPay'; }
                            $avUrl = (!empty($req['avatar'])) ? avatarImageUrl($req['avatar']) : (BASE_URL . 'assets/images/default-avatar.png');
                            $rl = $roleLabels[$req['role']] ?? $req['role'];
                            ?>
                            <tr>
                                <td style="color:var(--text-muted); font-weight:600;">#<?= $req['id'] ?></td>
                                <td>
                                    <div class="wd-user-cell">
                                        <img src="<?= $avUrl ?>" class="wd-user-avatar" alt="">
                                        <div>
                                            <span class="wd-user-name"><?= htmlspecialchars($req['username']) ?></span>
                                            <span class="wd-user-role <?= $req['role'] ?>"><?= $rl ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="wd-method-icon"><?= $methodIcon ?></span> <?= $methodName ?></td>
                                <td style="font-family:'SF Mono',monospace; color:var(--text-muted);"><?= maskAccount($req['account_number']) ?></td>
                                <td class="money-val" style="text-align:right;"><?= format_money($req['amount']) ?> ₫</td>
                                <td style="text-align:right; color:var(--text-muted);"><?= format_money($req['fee_amount']) ?> ₫</td>
                                <td class="money-pos" style="text-align:right;"><?= format_money($req['net_amount']) ?> ₫</td>
                                <td style="color:var(--text-muted); font-size:.8rem;"><?= date('d/m/Y H:i', strtotime($req['requested_at'])) ?></td>
                                <td style="text-align:center;"><span class="wd-badge <?= $sm['cls'] ?>"><?= $sm['label'] ?></span></td>
                                <td style="text-align:center;">
                                    <button class="btn-process" onclick="openWdModal(<?= $req['id'] ?>)">Xử lý</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="wd-pagination">
                <a href="?page=<?= $fPage - 1 ?>&status=<?= urlencode($fStatus) ?>&method=<?= urlencode($fMethod) ?>&date_from=<?= urlencode($fDateFrom) ?>&date_to=<?= urlencode($fDateTo) ?>&search=<?= urlencode($fSearch) ?>"
                   class="wd-pg-link <?= ($fPage <= 1) ? 'disabled' : '' ?>">&lsaquo;</a>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?= $p ?>&status=<?= urlencode($fStatus) ?>&method=<?= urlencode($fMethod) ?>&date_from=<?= urlencode($fDateFrom) ?>&date_to=<?= urlencode($fDateTo) ?>&search=<?= urlencode($fSearch) ?>"
                       class="wd-pg-link <?= ($fPage === $p) ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= $fPage + 1 ?>&status=<?= urlencode($fStatus) ?>&method=<?= urlencode($fMethod) ?>&date_from=<?= urlencode($fDateFrom) ?>&date_to=<?= urlencode($fDateTo) ?>&search=<?= urlencode($fSearch) ?>"
                   class="wd-pg-link <?= ($fPage >= $totalPages) ? 'disabled' : '' ?>">&rsaquo;</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════ TAB 2: THỐNG KÊ TÀI CHÍNH ═══════════════ -->
<div class="wd-tab-content" id="tab-finance">

    <!-- KPI row -->
    <div class="wd-stats" style="margin-bottom:24px;">
        <div class="wd-stat-card" style="--sc-color:#10b981;">
            <div class="sc-label">Doanh thu Platform tháng này</div>
            <div class="sc-val"><?= format_money($platformRevMonth) ?> ₫</div>
            <i class="fi fi-sr-chart-line-up sc-icon"></i>
        </div>
        <div class="wd-stat-card" style="--sc-color:#a78bfa;">
            <div class="sc-label">Tổng tiền đang giữ (tất cả ví)</div>
            <div class="sc-val"><?= format_money($totalHeld) ?> ₫</div>
            <i class="fi fi-sr-vault sc-icon"></i>
        </div>
    </div>

    <!-- Chart + tables -->
    <div class="fin-grid-2">
        <!-- Line chart doanh thu 12 tháng -->
        <div class="fin-card" style="grid-column:1/-1;">
            <div class="fin-card-title"><i class="fi fi-sr-chart-mixed" style="color:#a78bfa;"></i> Doanh thu Platform 12 tháng</div>
            <div style="position:relative; height:300px;"><canvas id="platformRevenueChart"></canvas></div>
        </div>
    </div>

    <div class="fin-grid-2">
        <!-- Top 5 Mangaka -->
        <div class="fin-card">
            <div class="fin-card-title"><i class="fi fi-sr-trophy" style="color:#f59e0b;"></i> Top 5 Mangaka thu nhập cao nhất</div>
            <table class="top-table">
                <thead><tr><th>#</th><th>Họa sĩ</th><th style="text-align:right;">Tổng thu nhập</th></tr></thead>
                <tbody>
                    <?php if (empty($topMangaka)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:24px;">Chưa có dữ liệu</td></tr>
                    <?php else: ?>
                        <?php foreach ($topMangaka as $i => $tm): ?>
                            <tr>
                                <td><span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
                                <td>
                                    <div class="top-user">
                                        <img src="<?= $tm['avatar'] ? avatarImageUrl($tm['avatar']) : (BASE_URL.'assets/images/default-avatar.png') ?>" class="top-avatar" alt="">
                                        <span style="font-weight:600; color:#fff;"><?= htmlspecialchars($tm['username']) ?></span>
                                    </div>
                                </td>
                                <td class="money-pos" style="text-align:right;"><?= format_money($tm['total_earned']) ?> ₫</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top 5 Assistant -->
        <div class="fin-card">
            <div class="fin-card-title"><i class="fi fi-sr-star" style="color:#3b82f6;"></i> Top 5 Assistant nhận lương nhiều nhất</div>
            <table class="top-table">
                <thead><tr><th>#</th><th>Trợ lý</th><th style="text-align:right;">Tổng nhận</th></tr></thead>
                <tbody>
                    <?php if (empty($topAssistant)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:24px;">Chưa có dữ liệu</td></tr>
                    <?php else: ?>
                        <?php foreach ($topAssistant as $i => $ta): ?>
                            <tr>
                                <td><span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
                                <td>
                                    <div class="top-user">
                                        <img src="<?= $ta['avatar'] ? avatarImageUrl($ta['avatar']) : (BASE_URL.'assets/images/default-avatar.png') ?>" class="top-avatar" alt="">
                                        <span style="font-weight:600; color:#fff;"><?= htmlspecialchars($ta['username']) ?></span>
                                    </div>
                                </td>
                                <td class="money-pos" style="text-align:right;"><?= format_money($ta['total_earned']) ?> ₫</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════ MODAL XỬ LÝ ═══════════════ -->
<div class="wd-modal-backdrop" id="wdModal">
    <div class="wd-modal">
        <div class="wd-modal-header">
            <h3><i class="fi fi-sr-money-check" style="color:var(--red);"></i> Xử lý yêu cầu rút tiền <span id="mdIdLabel" style="color:var(--text-muted);">#—</span></h3>
            <button class="wd-modal-close" onclick="closeWdModal()">×</button>
        </div>
        <div class="wd-modal-body">
            <!-- User info -->
            <div class="wd-section">
                <div class="wd-section-title"><i class="fi fi-rr-user"></i> Thông tin người dùng</div>
                <div style="display:flex; align-items:center; gap:14px; margin-bottom:12px;">
                    <img id="mdAvatar" src="" style="width:48px; height:48px; border-radius:50%; object-fit:cover; background:var(--bg-input);">
                    <div>
                        <div style="font-weight:700; color:#fff; font-size:.95rem;" id="mdUsername">—</div>
                        <div style="font-size:.78rem; color:var(--text-muted);" id="mdEmail">—</div>
                    </div>
                    <span class="wd-badge" id="mdRole" style="margin-left:auto;">—</span>
                </div>
                <div class="wd-info-row"><span class="lk">Số dư ví hiện tại:</span><span class="lv" id="mdBalance">—</span></div>
                <div class="wd-info-row"><span class="lk">Tổng đã kiếm:</span><span class="lv" id="mdEarned">—</span></div>
                <div class="wd-info-row"><span class="lk">Tổng đã rút trước đây:</span><span class="lv" id="mdWithdrawn">—</span></div>
            </div>

            <!-- Request info -->
            <div class="wd-section">
                <div class="wd-section-title"><i class="fi fi-rr-document-signed"></i> Chi tiết yêu cầu</div>
                <div class="wd-info-row"><span class="lk">Phương thức:</span><span class="lv" id="mdMethod">—</span></div>
                <div class="wd-info-row"><span class="lk">Số tài khoản / SĐT:</span><span class="lv" id="mdAccNumber" style="font-family:'SF Mono',monospace;">—</span></div>
                <div class="wd-info-row"><span class="lk">Chủ tài khoản:</span><span class="lv" id="mdAccName">—</span></div>
                <div class="wd-info-row"><span class="lk">Số tiền rút:</span><span class="lv money-val" id="mdAmount">—</span></div>
                <div class="wd-info-row"><span class="lk">Phí xử lý:</span><span class="lv" id="mdFee" style="color:#ef4444;">—</span></div>
                <div class="wd-info-row"><span class="lk">Thực nhận:</span><span class="lv money-pos" id="mdNet" style="font-size:1rem; font-weight:800;">—</span></div>
                <div class="wd-info-row"><span class="lk">Ngày yêu cầu:</span><span class="lv" id="mdDate">—</span></div>
            </div>

            <!-- QR / ewallet -->
            <div class="wd-section" id="mdQrSection" style="display:none;">
                <div class="wd-section-title"><i class="fi fi-rr-qrcode"></i> <span id="mdQrTitle">📱 Quét QR để chuyển tiền</span></div>
                <div id="mdQrContent"></div>
            </div>

            <!-- Actions -->
            <div id="mdActionsArea">
                <div class="wd-actions" id="mdActions">
                    <button class="wd-btn wd-btn-process" id="btnProcessing" onclick="doProcessing()">▶️ Đánh dấu Đang xử lý</button>
                    <button class="wd-btn wd-btn-complete" id="btnComplete" onclick="doComplete()">✅ Xác nhận đã chuyển tiền</button>
                    <button class="wd-btn wd-btn-reject" id="btnRejectToggle" onclick="toggleRejectArea()">❌ Từ chối</button>
                </div>
                <div class="wd-reject-area" id="rejectArea">
                    <textarea id="rejectReason" placeholder="Nhập lý do từ chối (bắt buộc)..."></textarea>
                    <button class="wd-reject-send" onclick="doReject()">Gửi từ chối</button>
                </div>
            </div>

            <!-- Admin note if rejected -->
            <div id="mdAdminNote" style="display:none; margin-top:12px;">
                <div style="font-size:.78rem; font-weight:700; color:#ef4444; margin-bottom:6px;">Lý do từ chối:</div>
                <div style="font-size:.84rem; color:var(--text-muted); font-style:italic; background:rgba(239,68,68,.06); padding:10px; border-radius:6px;" id="mdAdminNoteText"></div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// ── Data ──
const requestsData = <?= json_encode($requestsJson) ?>;
let currentReqId = null;

const roleLabelsJs = {
    mangaka: 'Họa sĩ',
    assistant: 'Trợ lý',
    editor: 'BTV',
    board: 'BBT',
    admin: 'Admin'
};
const roleBadgeCls = {
    mangaka: 'wd-st-processing',
    assistant: 'wd-st-pending',
};

function fmt(n) { return Math.round(n).toLocaleString('vi-VN'); }

function showToast(msg, type = 'success') {
    const t = document.getElementById('wdToast');
    t.textContent = msg;
    t.className = 'wd-toast show ' + type;
    setTimeout(() => { t.className = 'wd-toast'; }, 3500);
}

// ── Tab switching ──
function switchWdTab(tabId) {
    document.querySelectorAll('.wd-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.wd-tab-content').forEach(c => c.classList.toggle('active', c.id === tabId));
    if (tabId === 'tab-finance') {
        window.location.hash = 'tab-stats';
    } else {
        // Clear hash silently
        history.replaceState(null, null, ' ');
    }
}

// Auto-switch on load if hash matches
window.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash === '#tab-stats') {
        switchWdTab('tab-finance');
    }
});

// ── Modal ──
function openWdModal(id) {
    const r = requestsData[id];
    if (!r) return;
    currentReqId = id;

    document.getElementById('mdIdLabel').textContent = '#' + r.id;
    document.getElementById('mdAvatar').src = r.avatar || (BASE_URL + 'assets/images/default-avatar.png');
    document.getElementById('mdUsername').textContent = r.username;
    document.getElementById('mdEmail').textContent = r.email;
    document.getElementById('mdRole').textContent = roleLabelsJs[r.role] || r.role;
    document.getElementById('mdRole').className = 'wd-badge ' + (roleBadgeCls[r.role] || 'wd-st-pending');
    document.getElementById('mdBalance').textContent = fmt(r.wallet_balance) + ' ₫';
    document.getElementById('mdEarned').textContent = fmt(r.wallet_earned) + ' ₫';
    document.getElementById('mdWithdrawn').textContent = fmt(r.wallet_withdrawn) + ' ₫';

    let methodLabel = r.bank_name || 'Ngân hàng';
    if (r.method === 'momo') methodLabel = 'Ví MoMo';
    if (r.method === 'zalopay') methodLabel = 'Ví ZaloPay';
    document.getElementById('mdMethod').textContent = methodLabel;
    document.getElementById('mdAccNumber').textContent = r.account_number;
    document.getElementById('mdAccName').textContent = r.account_name;
    document.getElementById('mdAmount').textContent = fmt(r.amount) + ' ₫';
    document.getElementById('mdFee').textContent = fmt(r.fee_amount) + ' ₫';
    document.getElementById('mdNet').textContent = fmt(r.net_amount) + ' ₫';
    document.getElementById('mdDate').textContent = r.requested_at;

    // QR section
    const qrSection = document.getElementById('mdQrSection');
    const qrContent = document.getElementById('mdQrContent');
    const qrTitle   = document.getElementById('mdQrTitle');
    qrContent.innerHTML = '';

    if (r.method === 'bank_transfer' && r.qr_url) {
        qrSection.style.display = 'block';
        qrTitle.textContent = '📱 Quét QR để chuyển tiền';
        qrContent.innerHTML = `
            <div class="wd-qr-box">
                <img src="${r.qr_url}" width="220" height="220" alt="VietQR" loading="lazy">
                <div class="wd-qr-guide">
                    Mở app ngân hàng → Quét mã QR → Kiểm tra thông tin → Chuyển tiền
                </div>
            </div>`;
    } else if (r.method === 'momo' || r.method === 'zalopay') {
        qrSection.style.display = 'block';
        const appName = r.method === 'momo' ? 'MoMo' : 'ZaloPay';
        qrTitle.textContent = '📱 Chuyển tiền qua ' + appName;
        qrContent.innerHTML = `
            <div class="wd-ewallet-box">
                <div style="font-size:.82rem; color:var(--text-muted);">Chuyển tiền qua app ${appName} đến số:</div>
                <div class="wd-ewallet-phone">${r.account_number}</div>
                <div style="font-size:.82rem; color:var(--text-muted);">Tên: <strong style="color:#fff;">${r.account_name}</strong></div>
                <div style="font-size:.82rem; color:var(--text-muted); margin-top:6px;">Số tiền: <strong style="color:#10b981;">${fmt(r.net_amount)} ₫</strong></div>
            </div>`;
    } else {
        qrSection.style.display = 'none';
    }

    // Actions visibility
    const actionsArea = document.getElementById('mdActionsArea');
    const adminNote = document.getElementById('mdAdminNote');
    document.getElementById('rejectArea').classList.remove('show');

    if (r.status === 'pending' || r.status === 'processing') {
        actionsArea.style.display = 'block';
        adminNote.style.display = 'none';
        document.getElementById('btnProcessing').style.display = (r.status === 'pending') ? '' : 'none';
    } else {
        actionsArea.style.display = 'none';
        if (r.status === 'rejected' && r.admin_note) {
            adminNote.style.display = 'block';
            document.getElementById('mdAdminNoteText').textContent = r.admin_note;
        } else {
            adminNote.style.display = 'none';
        }
    }

    document.getElementById('wdModal').classList.add('open');
}

function closeWdModal() {
    document.getElementById('wdModal').classList.remove('open');
    currentReqId = null;
}

// Close on backdrop click
document.getElementById('wdModal').addEventListener('click', function(e) {
    if (e.target === this) closeWdModal();
});

// ── API calls ──
async function doProcessing() {
    if (!currentReqId) return;
    const btn = document.getElementById('btnProcessing');
    btn.disabled = true;
    btn.textContent = 'Đang xử lý...';

    try {
        const res = await fetch(BASE_URL + 'api/finance/complete_withdrawal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_processing&id=${currentReqId}`
        });
        const json = await res.json();
        if (json.success) {
            showToast('Đã đánh dấu đang xử lý', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(json.message || 'Lỗi', 'error');
            btn.disabled = false;
            btn.textContent = '▶️ Đánh dấu Đang xử lý';
        }
    } catch (e) {
        showToast('Lỗi kết nối', 'error');
        btn.disabled = false;
        btn.textContent = '▶️ Đánh dấu Đang xử lý';
    }
}

async function doComplete() {
    if (!currentReqId) return;
    const r = requestsData[currentReqId];
    if (!confirm(`Xác nhận đã chuyển ${fmt(r.net_amount)} ₫ đến ${r.account_number}?`)) return;

    const btn = document.getElementById('btnComplete');
    btn.disabled = true;
    btn.textContent = 'Đang xác nhận...';

    try {
        const res = await fetch(BASE_URL + 'api/finance/complete_withdrawal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete&id=${currentReqId}`
        });
        const json = await res.json();
        if (json.success) {
            showToast('Đã xác nhận chuyển tiền thành công!', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(json.message || 'Lỗi', 'error');
            btn.disabled = false;
            btn.textContent = '✅ Xác nhận đã chuyển tiền';
        }
    } catch (e) {
        showToast('Lỗi kết nối', 'error');
        btn.disabled = false;
        btn.textContent = '✅ Xác nhận đã chuyển tiền';
    }
}

function toggleRejectArea() {
    document.getElementById('rejectArea').classList.toggle('show');
}

async function doReject() {
    if (!currentReqId) return;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { showToast('Vui lòng nhập lý do từ chối', 'error'); return; }

    try {
        const res = await fetch(BASE_URL + 'api/finance/reject_withdrawal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${currentReqId}&reason=${encodeURIComponent(reason)}`
        });
        const json = await res.json();
        if (json.success) {
            showToast('Đã từ chối yêu cầu rút tiền', 'warning');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(json.message || 'Lỗi', 'error');
        }
    } catch (e) {
        showToast('Lỗi kết nối', 'error');
    }
}

// ── Chart: doanh thu platform 12 tháng (Line) ──
const ctx12 = document.getElementById('platformRevenueChart');
if (ctx12) {
    const g = ctx12.getContext('2d');
    const grad = g.createLinearGradient(0, 0, 0, 300);
    grad.addColorStop(0, 'rgba(167, 139, 250, 0.4)');
    grad.addColorStop(1, 'rgba(167, 139, 250, 0)');

    new Chart(g, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels12) ?>,
            datasets: [{
                label: 'Doanh thu Platform',
                data: <?= json_encode($chartValues12) ?>,
                fill: true,
                backgroundColor: grad,
                borderColor: '#a78bfa',
                borderWidth: 2,
                pointBackgroundColor: '#a78bfa',
                pointRadius: 4,
                tension: .4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.raw.toLocaleString() + ' ₫'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,.04)' },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 10 },
                        callback: v => v >= 1e6 ? (v/1e6)+'M' : v.toLocaleString()
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#6b7280', font: { size: 10 } }
                }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
