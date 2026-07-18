<?php
/**
 * wallet/withdraw.php
 * Trang rút tiền cho Mangaka & Assistant.
 * Layout 2 cột: Trái = form rút tiền 3 bước, Phải = thông tin + lịch sử.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Rút tiền';
$activePage   = 'wallet';
$allowedRoles = [ROLES['MANGAKA'], ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db     = getDB();
$userId = $currentUser['id'];
$wallet = get_wallet($userId);

if (!$wallet) {
    $db->prepare("INSERT INTO wallets (user_id) VALUES (?)")->execute([$userId]);
    $wallet = get_wallet($userId);
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

// ── Lấy danh sách tài khoản đã lưu ──
$savedAccounts = [];
try {
    $stmtA = $db->prepare("SELECT * FROM payment_accounts WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
    $stmtA->execute([$userId]);
    $savedAccounts = $stmtA->fetchAll();
} catch (\Throwable $e) {}

// ── 5 lệnh rút gần nhất ──
$recentWithdrawals = [];
try {
    $stmtW = $db->prepare("SELECT * FROM withdrawal_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 5");
    $stmtW->execute([$userId]);
    $recentWithdrawals = $stmtW->fetchAll();
} catch (\Throwable $e) {}

// ── Phân nhóm tài khoản đã lưu theo phương thức ──
$accByMethod = ['bank_transfer' => [], 'momo' => [], 'zalopay' => []];
foreach ($savedAccounts as $acc) {
    $accByMethod[$acc['method']][] = $acc;
}

// ── Danh sách ngân hàng Việt Nam ──
$banks = [
    ['code' => 'VCB',  'name' => 'Vietcombank'],
    ['code' => 'BIDV', 'name' => 'BIDV'],
    ['code' => 'AGR',  'name' => 'Agribank'],
    ['code' => 'TCB',  'name' => 'Techcombank'],
    ['code' => 'MB',   'name' => 'MBBank'],
    ['code' => 'VPB',  'name' => 'VPBank'],
    ['code' => 'TPB',  'name' => 'TPBank'],
    ['code' => 'ACB',  'name' => 'ACB'],
    ['code' => 'STB',  'name' => 'Sacombank'],
    ['code' => 'VIB',  'name' => 'VIB'],
    ['code' => 'HDB',  'name' => 'HDBank'],
    ['code' => 'OCB',  'name' => 'OCB'],
    ['code' => 'MSB',  'name' => 'MSB'],
    ['code' => 'SEAB', 'name' => 'SeABank'],
    ['code' => 'BAB',  'name' => 'BacABank'],
    ['code' => 'NVB',  'name' => 'NCB'],
    ['code' => 'SHB',  'name' => 'SHB'],
    ['code' => 'LPB',  'name' => 'LienVietPostBank'],
    ['code' => 'NAB',  'name' => 'Nam A Bank'],
    ['code' => 'EIB',  'name' => 'Eximbank'],
];

$statusLabels = [
    'pending'    => ['label' => 'Đang chờ',   'cls' => 'pending'],
    'processing' => ['label' => 'Đang xử lý', 'cls' => 'processing'],
    'completed'  => ['label' => 'Thành công',  'cls' => 'completed'],
    'rejected'   => ['label' => 'Từ chối',     'cls' => 'rejected'],
];
?>

<style>
/* ─── Page grid ─── */
.wd-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 960px) {
    .wd-grid { grid-template-columns: 1fr; }
}

/* ─── Card shell ─── */
.wd-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 20px;
}
.wd-card-title {
    font-size: 0.92rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ─── Balance hero ─── */
.balance-hero {
    background: linear-gradient(135deg, rgba(16,185,129,.12) 0%, rgba(16,185,129,.03) 100%);
    border: 1px solid rgba(16,185,129,.2);
    border-radius: var(--radius-sm);
    padding: 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.balance-hero .lbl { font-size:.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.balance-hero .val { font-size:1.8rem; font-weight:800; color:#10b981; margin-top:4px; }

/* ─── Amount input group ─── */
.amount-input-wrap {
    position: relative;
}
.amount-input-wrap input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: #fff;
    padding: 14px 50px 14px 16px;
    border-radius: var(--radius-sm);
    font-size: 1.1rem;
    font-weight: 700;
    font-family: 'SF Mono', 'Consolas', monospace;
    outline: none;
    transition: border-color var(--dur) var(--ease);
}
.amount-input-wrap input:focus {
    border-color: #10b981;
}
.amount-input-wrap .suffix {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: .85rem;
    font-weight: 600;
    pointer-events: none;
}

/* ─── Shortcut buttons ─── */
.shortcuts {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.shortcuts button {
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text-muted);
    padding: 6px 14px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--dur) var(--ease);
}
.shortcuts button:hover {
    background: rgba(16,185,129,.1);
    border-color: #10b981;
    color: #10b981;
}

/* ─── Fee preview ─── */
.fee-box {
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 14px 18px;
    margin-top: 16px;
}
.fee-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: .84rem;
    color: var(--text-muted);
}
.fee-row.total {
    border-top: 1px solid var(--border);
    margin-top: 8px;
    padding-top: 10px;
    color: #fff;
    font-weight: 700;
}
.fee-row .neg { color: #ef4444; }
.fee-row .pos { color: #10b981; font-size: 1rem; }

/* ─── Method tabs ─── */
.method-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 20px;
}
.method-tab {
    flex: 1;
    padding: 12px 0;
    text-align: center;
    font-size: .82rem;
    font-weight: 700;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all var(--dur) var(--ease);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.method-tab:hover { color: #fff; }
.method-tab.active {
    color: #10b981;
    border-bottom-color: #10b981;
}

/* ─── Method panel ─── */
.method-panel { display: none; }
.method-panel.active { display: block; }

/* ─── Form controls ─── */
.wd-label {
    display: block;
    font-size: .78rem;
    font-weight: 700;
    color: var(--text-muted);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.wd-input, .wd-select {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: #fff;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: .85rem;
    outline: none;
    transition: border-color var(--dur) var(--ease);
}
.wd-input:focus, .wd-select:focus { border-color: #10b981; }
.wd-select option { background: var(--bg-card); color: #fff; }
.wd-input::placeholder { color: var(--text-dim); }
.wd-fg { margin-bottom: 14px; }

.wd-checkbox-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    font-size: .82rem;
    color: var(--text-muted);
    cursor: pointer;
}
.wd-checkbox-row input[type="checkbox"] {
    accent-color: #10b981;
    width: 16px;
    height: 16px;
}

/* ─── Confirm box ─── */
.confirm-box {
    background: linear-gradient(135deg, rgba(16,185,129,.08) 0%, rgba(139,92,246,.06) 100%);
    border: 1px solid rgba(16,185,129,.2);
    border-radius: var(--radius);
    padding: 20px;
    margin-top: 20px;
    display: none;
}
.confirm-box.show { display: block; }
.confirm-title {
    font-weight: 700;
    color: #10b981;
    font-size: .9rem;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.confirm-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: .83rem;
}
.confirm-row .cl { color: var(--text-muted); }
.confirm-row .cv { color: #fff; font-weight: 600; }
.confirm-row.highlight .cv { color: #10b981; font-weight: 800; }

.confirm-check-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    font-size: .82rem;
    color: var(--text-muted);
}
.confirm-check-row input { accent-color: #10b981; width: 16px; height: 16px; }

/* ─── Submit button ─── */
.btn-submit-wd {
    width: 100%;
    margin-top: 16px;
    padding: 14px 0;
    background: #10b981;
    border: none;
    border-radius: var(--radius-sm);
    color: #fff;
    font-size: .9rem;
    font-weight: 800;
    cursor: pointer;
    transition: all var(--dur) var(--ease);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-submit-wd:hover { background: #059669; transform: translateY(-1px); }
.btn-submit-wd:disabled {
    opacity: .4;
    cursor: not-allowed;
    transform: none;
}

/* ─── Right column - info boxes ─── */
.info-list li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    font-size: .82rem;
    color: var(--text-muted);
    line-height: 1.5;
}
.info-list li .ico { font-size: 1rem; flex-shrink: 0; margin-top: 2px; }
.info-list { list-style: none; }

/* ─── Saved account list ─── */
.saved-acc-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    transition: all var(--dur) var(--ease);
}
.saved-acc-item:hover { border-color: rgba(255,255,255,.12); }
.saved-acc-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
.saved-acc-info .acc-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .9rem;
    font-weight: 800;
}
.acc-icon.bank { background: rgba(59,130,246,.15); color: #3b82f6; }
.acc-icon.momo { background: rgba(167,57,123,.2); color: #a7397b; }
.acc-icon.zalo { background: rgba(0,133,255,.15); color: #0085ff; }

.saved-acc-details .acc-name { font-size:.82rem; font-weight:600; color:#fff; }
.saved-acc-details .acc-num  { font-size:.72rem; color:var(--text-muted); margin-top:2px; }
.badge-default {
    font-size: .65rem;
    font-weight: 700;
    background: rgba(16,185,129,.12);
    color: #10b981;
    padding: 2px 8px;
    border-radius: 4px;
    margin-left: 6px;
}
.btn-del-acc {
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    font-size: .9rem;
    padding: 4px;
    transition: color var(--dur);
}
.btn-del-acc:hover { color: #ef4444; }

/* ─── Recent withdrawal list ─── */
.recent-wd-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: .82rem;
}
.recent-wd-item:last-child { border-bottom: none; }
.rw-date { color: var(--text-muted); font-size:.75rem; }
.rw-amount { color: #fff; font-weight: 700; }
.rw-method { color: var(--text-muted); font-size: .72rem; }
.rw-status { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:4px; }
.rw-status.pending    { background:rgba(245,158,11,.12); color:#f59e0b; }
.rw-status.processing { background:rgba(59,130,246,.12);  color:#3b82f6; }
.rw-status.completed  { background:rgba(16,185,129,.12);  color:#10b981; }
.rw-status.rejected   { background:rgba(239,68,68,.12);   color:#ef4444; }

.link-all { font-size:.78rem; font-weight:600; color:#a78bfa; text-decoration:none; }
.link-all:hover { text-decoration: underline; }

/* ─── Toast ─── */
.toast-wd {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 10000;
    padding: 14px 24px;
    border-radius: var(--radius-sm);
    font-size: .85rem;
    font-weight: 700;
    color: #fff;
    opacity: 0;
    transform: translateY(-20px);
    transition: all .35s var(--ease);
    pointer-events: none;
}
.toast-wd.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
.toast-wd.success { background: #10b981; }
.toast-wd.error   { background: #ef4444; }

/* ─── Step sections ─── */
.step-section {
    margin-bottom: 28px;
}
.step-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.step-badge {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(139,92,246,.15);
    color: #a78bfa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    font-weight: 800;
}
.step-title {
    font-size: .88rem;
    font-weight: 700;
    color: #fff;
}
/* =========================================================
   HIỆU ỨNG ĐỘNG DÀNH RIÊNG CHO TRANG RÚT TIỀN
========================================================= */
@keyframes fadeSlideInRight {
    from { opacity: 0; transform: translateX(-15px); }
    to { opacity: 1; transform: translateX(0); }
}
@keyframes popIn {
    0% { opacity: 0; transform: scale(0.95); }
    100% { opacity: 1; transform: scale(1); }
}

/* 1. Hiệu ứng xuất hiện mượt mà cho các phần tử chính */
.balance-hero { 
    animation: popIn 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; 
}
.step-section:nth-child(1) { opacity: 0; animation: fadeSlideInRight 0.4s ease-out 0.1s forwards; }
.step-section:nth-child(2) { opacity: 0; animation: fadeSlideInRight 0.4s ease-out 0.25s forwards; }
.step-section:nth-child(3) { opacity: 0; animation: fadeSlideInRight 0.4s ease-out 0.4s forwards; }

.wd-grid > div:last-child .wd-card {
    opacity: 0;
    animation: popIn 0.5s ease-out 0.3s forwards;
}

/* 2. Tương tác hover cho danh sách tài khoản đã lưu */
.saved-acc-item {
    transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275), border-color 0.25s ease, box-shadow 0.25s ease !important;
    will-change: transform;
}
.saved-acc-item:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    border-color: rgba(255,255,255,0.15) !important;
}

/* 3. Hiệu ứng trượt dòng cho lịch sử rút tiền */
.recent-wd-item {
    transition: transform 0.2s ease, background-color 0.2s ease;
    padding: 10px 8px !important;
    border-radius: 6px;
    margin: 0 -8px; 
}
.recent-wd-item:hover {
    transform: translateX(4px);
    background-color: rgba(255,255,255,0.03);
}

/* 4. Nổi bật các Tab phương thức thanh toán (Ngân hàng, MoMo, ZaloPay) */
.method-tab {
    transition: transform 0.2s ease, color 0.2s ease;
}
.method-tab:not(.active):hover {
    transform: translateY(-2px);
    color: #10b981 !important;
}

/* 5. Hiệu ứng nhịp đập lôi cuốn cho nút Gửi yêu cầu (chỉ khi đủ điều kiện bấm) */
@keyframes gentlePulse {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
    70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.btn-submit-wd:not(:disabled) {
    animation: gentlePulse 2s infinite;
}   
</style>

<!-- Toast notification container -->
<div class="toast-wd" id="toastWd"></div>

<div class="wd-page">
    <!-- Page header -->
    <div style="margin-bottom:24px;">
        <div class="breadcrumb">
            <a href="index.php">Ví điện tử</a>
            <span class="sep">›</span>
            <span class="current">Rút tiền</span>
        </div>
        <h1>Yêu Cầu Rút Tiền</h1>
    </div>

    <div class="wd-grid">
        <!-- ========== CỘT TRÁI: FORM RÚT TIỀN ========== -->
        <div>
            <div class="wd-card">
                <!-- Balance hero -->
                <div class="balance-hero">
                    <div>
                        <div class="lbl">Số dư khả dụng</div>
                        <div class="val"><?= format_money($wallet['balance']) ?> ₫</div>
                    </div>
                    <i class="fi fi-sr-wallet" style="font-size:2rem; color:#10b981; opacity:.7;"></i>
                </div>

                <form id="withdrawForm" autocomplete="off">
                    <!-- ── BƯỚC 1: NHẬP SỐ TIỀN ── -->
                    <div class="step-section">
                        <div class="step-header">
                            <div class="step-badge">1</div>
                            <div class="step-title">Nhập số tiền</div>
                        </div>

                        <div class="amount-input-wrap">
                            <input type="text" id="amountInput" inputmode="numeric"
                                   placeholder="Tối thiểu <?= format_money($min_withdrawal) ?> ₫"
                                   data-min="<?= $min_withdrawal ?>"
                                   data-max="<?= min($wallet['balance'], $max_withdrawal) ?>"
                                   data-balance="<?= $wallet['balance'] ?>">
                            <span class="suffix">₫</span>
                        </div>

                        <div class="shortcuts">
                            <button type="button" onclick="setAmount(100000)">100K</button>
                            <button type="button" onclick="setAmount(500000)">500K</button>
                            <button type="button" onclick="setAmount(1000000)">1M</button>
                            <button type="button" onclick="setAmount(<?= min($wallet['balance'], $max_withdrawal) ?>)">Tất cả</button>
                        </div>

                        <!-- Fee preview -->
                        <div class="fee-box" id="feeBox" style="display:none;">
                            <div class="fee-row">
                                <span>Số tiền rút:</span>
                                <span id="feeAmountDisplay">0 ₫</span>
                            </div>
                            <div class="fee-row">
                                <span>Phí xử lý (<?= $fee_percent ?>%):</span>
                                <span class="neg" id="feeFeeDisplay">−0 ₫</span>
                            </div>
                            <div class="fee-row total">
                                <span>Thực nhận:</span>
                                <span class="pos" id="feeNetDisplay">0 ₫</span>
                            </div>
                        </div>
                    </div>

                    <!-- ── BƯỚC 2: CHỌN PHƯƠNG THỨC ── -->
                    <div class="step-section">
                        <div class="step-header">
                            <div class="step-badge">2</div>
                            <div class="step-title">Chọn phương thức nhận tiền</div>
                        </div>

                        <div class="method-tabs">
                            <div class="method-tab active" data-method="bank_transfer" onclick="switchMethod('bank_transfer')">
                                <i class="fi fi-rr-bank"></i> Ngân hàng
                            </div>
                            <div class="method-tab" data-method="momo" onclick="switchMethod('momo')">
                                <i class="fi fi-rr-smartphone"></i> MoMo
                            </div>
                            <div class="method-tab" data-method="zalopay" onclick="switchMethod('zalopay')">
                                <i class="fi fi-rr-wallet"></i> ZaloPay
                            </div>
                        </div>

                        <!-- TAB NGÂN HÀNG -->
                        <div class="method-panel active" id="panel_bank_transfer">
                            <div class="wd-fg">
                                <label class="wd-label">Chọn tài khoản</label>
                                <select class="wd-select" id="savedBank" onchange="onSavedAccountChange('bank_transfer')">
                                    <?php foreach ($accByMethod['bank_transfer'] as $a): ?>
                                        <option value="<?= $a['id'] ?>" 
                                                data-name="<?= htmlspecialchars($a['account_name']) ?>"
                                                data-number="<?= htmlspecialchars($a['account_number']) ?>"
                                                data-bank="<?= htmlspecialchars($a['bank_name']) ?>"
                                                data-bankcode="<?= htmlspecialchars($a['bank_code'] ?? '') ?>">
                                            <?= htmlspecialchars($a['bank_name']) ?> — <?= htmlspecialchars($a['account_number']) ?>
                                            <?= $a['is_default'] ? '⭐' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="new">➕ Thêm tài khoản mới</option>
                                </select>
                            </div>

                            <div id="newBankFields" style="display:<?= empty($accByMethod['bank_transfer']) ? 'block' : 'none' ?>;">
                                <div class="wd-fg">
                                    <label class="wd-label">Ngân hàng</label>
                                    <select class="wd-select" id="bankSelect">
                                        <option value="">— Chọn ngân hàng —</option>
                                        <?php foreach ($banks as $b): ?>
                                            <option value="<?= $b['code'] ?>"><?= $b['name'] ?> (<?= $b['code'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="wd-fg">
                                    <label class="wd-label">Số tài khoản</label>
                                    <input class="wd-input" id="bankAccNumber" placeholder="Nhập số tài khoản ngân hàng...">
                                </div>
                                <div class="wd-fg">
                                    <label class="wd-label">Tên chủ tài khoản</label>
                                    <input class="wd-input" id="bankAccName" placeholder="NGUYEN VAN A" style="text-transform:uppercase;">
                                </div>
                                <label class="wd-checkbox-row">
                                    <input type="checkbox" id="saveBankAcc" checked> Lưu tài khoản này để dùng lại
                                </label>
                            </div>
                        </div>

                        <!-- TAB MOMO -->
                        <div class="method-panel" id="panel_momo">
                            <div class="wd-fg">
                                <label class="wd-label">Chọn tài khoản MoMo</label>
                                <select class="wd-select" id="savedMomo" onchange="onSavedAccountChange('momo')">
                                    <?php foreach ($accByMethod['momo'] as $a): ?>
                                        <option value="<?= $a['id'] ?>"
                                                data-name="<?= htmlspecialchars($a['account_name']) ?>"
                                                data-number="<?= htmlspecialchars($a['account_number']) ?>">
                                            <?= htmlspecialchars($a['account_number']) ?> — <?= htmlspecialchars($a['account_name']) ?>
                                            <?= $a['is_default'] ? '⭐' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="new">➕ Thêm tài khoản mới</option>
                                </select>
                            </div>

                            <div id="newMomoFields" style="display:<?= empty($accByMethod['momo']) ? 'block' : 'none' ?>;">
                                <div class="wd-fg">
                                    <label class="wd-label">Số điện thoại MoMo</label>
                                    <input class="wd-input" id="momoPhone" placeholder="VD: 0912345678">
                                </div>
                                <div class="wd-fg">
                                    <label class="wd-label">Tên tài khoản MoMo</label>
                                    <input class="wd-input" id="momoAccName" placeholder="NGUYEN VAN A" style="text-transform:uppercase;">
                                </div>
                                <label class="wd-checkbox-row">
                                    <input type="checkbox" id="saveMomoAcc" checked> Lưu tài khoản này để dùng lại
                                </label>
                            </div>
                        </div>

                        <!-- TAB ZALOPAY -->
                        <div class="method-panel" id="panel_zalopay">
                            <div class="wd-fg">
                                <label class="wd-label">Chọn tài khoản ZaloPay</label>
                                <select class="wd-select" id="savedZalo" onchange="onSavedAccountChange('zalopay')">
                                    <?php foreach ($accByMethod['zalopay'] as $a): ?>
                                        <option value="<?= $a['id'] ?>"
                                                data-name="<?= htmlspecialchars($a['account_name']) ?>"
                                                data-number="<?= htmlspecialchars($a['account_number']) ?>">
                                            <?= htmlspecialchars($a['account_number']) ?> — <?= htmlspecialchars($a['account_name']) ?>
                                            <?= $a['is_default'] ? '⭐' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="new">➕ Thêm tài khoản mới</option>
                                </select>
                            </div>

                            <div id="newZaloFields" style="display:<?= empty($accByMethod['zalopay']) ? 'block' : 'none' ?>;">
                                <div class="wd-fg">
                                    <label class="wd-label">Số điện thoại ZaloPay</label>
                                    <input class="wd-input" id="zaloPhone" placeholder="VD: 0912345678">
                                </div>
                                <div class="wd-fg">
                                    <label class="wd-label">Tên tài khoản ZaloPay</label>
                                    <input class="wd-input" id="zaloAccName" placeholder="NGUYEN VAN A" style="text-transform:uppercase;">
                                </div>
                                <label class="wd-checkbox-row">
                                    <input type="checkbox" id="saveZaloAcc" checked> Lưu tài khoản này để dùng lại
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ── BƯỚC 3: XÁC NHẬN ── -->
                    <div class="step-section">
                        <div class="step-header">
                            <div class="step-badge">3</div>
                            <div class="step-title">Xác nhận & gửi yêu cầu</div>
                        </div>

                        <div class="confirm-box" id="confirmBox">
                            <div class="confirm-title"><i class="fi fi-sr-check-circle"></i> Xác nhận thông tin rút tiền</div>
                            <div class="confirm-row"><span class="cl">Phương thức:</span> <span class="cv" id="cfMethod">—</span></div>
                            <div class="confirm-row"><span class="cl">Số TK / SĐT:</span> <span class="cv" id="cfAccNum">—</span></div>
                            <div class="confirm-row"><span class="cl">Chủ TK:</span> <span class="cv" id="cfAccName">—</span></div>
                            <div class="confirm-row"><span class="cl">Số tiền rút:</span> <span class="cv" id="cfAmount">—</span></div>
                            <div class="confirm-row"><span class="cl">Phí xử lý:</span> <span class="cv" id="cfFee" style="color:#ef4444;">—</span></div>
                            <div class="confirm-row highlight"><span class="cl">Thực nhận:</span> <span class="cv" id="cfNet">—</span></div>

                            <label class="confirm-check-row">
                                <input type="checkbox" id="confirmCheck" onchange="toggleSubmit()">
                                Tôi xác nhận thông tin trên là chính xác
                            </label>

                            <button type="submit" class="btn-submit-wd" id="btnSubmit" disabled>
                                💸 Gửi yêu cầu rút tiền
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========== CỘT PHẢI: THÔNG TIN PHỤ ========== -->
        <div>
            <!-- Box lưu ý rút tiền -->
            <div class="wd-card">
                <div class="wd-card-title">
                    <i class="fi fi-rr-info" style="color:#f59e0b;"></i> Lưu ý rút tiền
                </div>
                <ul class="info-list">
                    <li><span class="ico">⏰</span> Thời gian xử lý: 1–2 ngày làm việc</li>
                    <li><span class="ico">💰</span> Phí xử lý: <?= $fee_percent ?>% số tiền rút</li>
                    <li><span class="ico">📋</span> Tối thiểu: <?= format_money($min_withdrawal) ?> ₫ mỗi lần</li>
                    <li><span class="ico">🔒</span> Tối đa: <?= format_money($max_withdrawal) ?> ₫ mỗi lần</li>
                    <li><span class="ico">🏦</span> Tiền sẽ về tài khoản sau khi admin xác nhận</li>
                </ul>
            </div>

            <!-- Box tài khoản đã lưu -->
            <div class="wd-card" id="savedAccountsBox">
                <div class="wd-card-title">
                    <i class="fi fi-rr-credit-card" style="color:#3b82f6;"></i> Tài khoản đã lưu
                </div>
                <?php if (empty($savedAccounts)): ?>
                    <p style="font-size:.82rem; color:var(--text-muted);">Chưa có tài khoản nào được lưu.</p>
                <?php else: ?>
                    <?php foreach ($savedAccounts as $sa): ?>
                        <?php
                        $iconClass = 'bank';
                        if ($sa['method'] === 'momo') $iconClass = 'momo';
                        elseif ($sa['method'] === 'zalopay') $iconClass = 'zalo';
                        $iconLabel = strtoupper(substr($sa['method'] === 'bank_transfer' ? ($sa['bank_code'] ?? 'BK') : $sa['method'], 0, 3));
                        ?>
                        <div class="saved-acc-item" id="savedAccItem_<?= $sa['id'] ?>">
                            <div class="saved-acc-info">
                                <div class="acc-icon <?= $iconClass ?>"><?= $iconLabel ?></div>
                                <div class="saved-acc-details">
                                    <div class="acc-name">
                                        <?= htmlspecialchars($sa['account_name']) ?>
                                        <?php if ($sa['is_default']): ?><span class="badge-default">Mặc định</span><?php endif; ?>
                                    </div>
                                    <div class="acc-num">
                                        <?= htmlspecialchars($sa['method'] === 'bank_transfer' ? ($sa['bank_name'] ?? '') . ' — ' : '') ?>
                                        <?= htmlspecialchars($sa['account_number']) ?>
                                    </div>
                                </div>
                            </div>
                            <button class="btn-del-acc" title="Xóa tài khoản" onclick="deleteAccount(<?= $sa['id'] ?>)">
                                <i class="fi fi-rr-trash"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Box lịch sử rút gần đây -->
            <div class="wd-card">
                <div class="wd-card-title" style="justify-content:space-between;">
                    <span style="display:flex; align-items:center; gap:8px;">
                        <i class="fi fi-rr-time-past" style="color:#a78bfa;"></i> Lịch sử rút gần đây
                    </span>
                    <a href="withdrawals.php" class="link-all">Xem tất cả →</a>
                </div>
                <?php if (empty($recentWithdrawals)): ?>
                    <p style="font-size:.82rem; color:var(--text-muted);">Chưa có yêu cầu rút tiền nào.</p>
                <?php else: ?>
                    <?php foreach ($recentWithdrawals as $rw): ?>
                        <?php $sm = $statusLabels[$rw['status']] ?? ['label' => $rw['status'], 'cls' => 'pending']; ?>
                        <div class="recent-wd-item">
                            <div>
                                <div class="rw-amount"><?= format_money($rw['amount']) ?> ₫</div>
                                <div class="rw-method"><?= ($rw['method'] === 'bank_transfer') ? htmlspecialchars($rw['bank_name'] ?? 'Bank') : strtoupper($rw['method']) ?></div>
                                <div class="rw-date"><?= date('d/m/Y H:i', strtotime($rw['requested_at'])) ?></div>
                            </div>
                            <span class="rw-status <?= $sm['cls'] ?>"><?= $sm['label'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const FEE_PERCENT = <?= $fee_percent ?>;
const MIN_WD = <?= $min_withdrawal ?>;
const MAX_WD = <?= min($wallet['balance'], $max_withdrawal) ?>;
const BALANCE = <?= $wallet['balance'] ?>;
let currentMethod = 'bank_transfer';

// ── Helpers ──
function fmt(n) {
    return Math.round(n).toLocaleString('vi-VN');
}

function parseAmount(str) {
    return parseInt(String(str).replace(/[^\d]/g, ''), 10) || 0;
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toastWd');
    t.textContent = msg;
    t.className = 'toast-wd show ' + type;
    setTimeout(() => { t.className = 'toast-wd'; }, 3500);
}

// ── Set amount shortcut ──
function setAmount(val) {
    const inp = document.getElementById('amountInput');
    inp.value = fmt(val);
    calcFee();
    updateConfirmBox();
}

// ── Auto format on input ──
document.getElementById('amountInput').addEventListener('input', function() {
    const raw = parseAmount(this.value);
    if (raw > 0) {
        const pos = this.selectionStart;
        this.value = fmt(raw);
    }
    calcFee();
    updateConfirmBox();
});

// ── Fee calculator ──
function calcFee() {
    const box = document.getElementById('feeBox');
    const raw = parseAmount(document.getElementById('amountInput').value);
    if (raw <= 0) { box.style.display = 'none'; return; }
    
    box.style.display = 'block';
    const fee = Math.round(raw * FEE_PERCENT / 100);
    const net = raw - fee;
    
    document.getElementById('feeAmountDisplay').textContent = fmt(raw) + ' ₫';
    document.getElementById('feeFeeDisplay').textContent = '−' + fmt(fee) + ' ₫';
    document.getElementById('feeNetDisplay').textContent = fmt(net) + ' ₫';
}

// ── Method tabs ──
function switchMethod(method) {
    currentMethod = method;
    document.querySelectorAll('.method-tab').forEach(t => t.classList.toggle('active', t.dataset.method === method));
    document.querySelectorAll('.method-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel_' + method).classList.add('active');
    updateConfirmBox();
}

// ── Saved account dropdown change ──
function onSavedAccountChange(method) {
    let selectEl, newFieldsEl;
    if (method === 'bank_transfer') {
        selectEl = document.getElementById('savedBank');
        newFieldsEl = document.getElementById('newBankFields');
    } else if (method === 'momo') {
        selectEl = document.getElementById('savedMomo');
        newFieldsEl = document.getElementById('newMomoFields');
    } else {
        selectEl = document.getElementById('savedZalo');
        newFieldsEl = document.getElementById('newZaloFields');
    }
    
    const isNew = selectEl.value === 'new';
    newFieldsEl.style.display = isNew ? 'block' : 'none';
    
    if (!isNew && method === 'bank_transfer') {
        const opt = selectEl.selectedOptions[0];
        document.getElementById('bankAccName').value = opt?.dataset.name || '';
        document.getElementById('bankAccNumber').value = opt?.dataset.number || '';
    }
    
    updateConfirmBox();
}

// ── Collect current account info ──
function getAccountInfo() {
    let method = currentMethod;
    let accName = '', accNumber = '', bankName = '', bankCode = '', saveNew = false, isNew = false;

    if (method === 'bank_transfer') {
        const sel = document.getElementById('savedBank');
        isNew = sel.value === 'new';
        if (isNew) {
            const bankOpt = document.getElementById('bankSelect');
            bankCode = bankOpt.value;
            bankName = bankOpt.selectedOptions[0]?.textContent.split('(')[0]?.trim() || '';
            accNumber = document.getElementById('bankAccNumber').value.trim();
            accName = document.getElementById('bankAccName').value.trim().toUpperCase();
            saveNew = document.getElementById('saveBankAcc').checked;
        } else {
            const opt = sel.selectedOptions[0];
            accName = opt?.dataset.name || '';
            accNumber = opt?.dataset.number || '';
            bankName = opt?.dataset.bank || '';
            bankCode = opt?.dataset.bankcode || '';
        }
    } else if (method === 'momo') {
        const sel = document.getElementById('savedMomo');
        isNew = sel.value === 'new';
        if (isNew) {
            accNumber = document.getElementById('momoPhone').value.trim();
            accName = document.getElementById('momoAccName').value.trim().toUpperCase();
            saveNew = document.getElementById('saveMomoAcc').checked;
        } else {
            const opt = sel.selectedOptions[0];
            accName = opt?.dataset.name || '';
            accNumber = opt?.dataset.number || '';
        }
    } else {
        const sel = document.getElementById('savedZalo');
        isNew = sel.value === 'new';
        if (isNew) {
            accNumber = document.getElementById('zaloPhone').value.trim();
            accName = document.getElementById('zaloAccName').value.trim().toUpperCase();
            saveNew = document.getElementById('saveZaloAcc').checked;
        } else {
            const opt = sel.selectedOptions[0];
            accName = opt?.dataset.name || '';
            accNumber = opt?.dataset.number || '';
        }
    }

    return { method, accName, accNumber, bankName, bankCode, saveNew, isNew };
}

// ── Update confirm box ──
function updateConfirmBox() {
    const raw = parseAmount(document.getElementById('amountInput').value);
    const info = getAccountInfo();
    const box = document.getElementById('confirmBox');
    
    if (raw >= MIN_WD && raw <= MAX_WD && info.accName && info.accNumber) {
        box.classList.add('show');
        
        const fee = Math.round(raw * FEE_PERCENT / 100);
        const net = raw - fee;
        
        let methodLabel = 'Ngân hàng';
        if (info.method === 'momo') methodLabel = 'Ví MoMo';
        else if (info.method === 'zalopay') methodLabel = 'Ví ZaloPay';
        if (info.bankName) methodLabel = info.bankName;
        
        document.getElementById('cfMethod').textContent = methodLabel;
        document.getElementById('cfAccNum').textContent = info.accNumber;
        document.getElementById('cfAccName').textContent = info.accName;
        document.getElementById('cfAmount').textContent = fmt(raw) + ' ₫';
        document.getElementById('cfFee').textContent = fmt(fee) + ' ₫';
        document.getElementById('cfNet').textContent = fmt(net) + ' ₫';
    } else {
        box.classList.remove('show');
    }
    
    document.getElementById('confirmCheck').checked = false;
    toggleSubmit();
}

// ── Watch all inputs for live confirm update ──
document.querySelectorAll('.wd-input, .wd-select').forEach(el => {
    el.addEventListener('input', () => updateConfirmBox());
    el.addEventListener('change', () => updateConfirmBox());
});

function toggleSubmit() {
    document.getElementById('btnSubmit').disabled = !document.getElementById('confirmCheck').checked;
}

// ── SUBMIT via AJAX ──
document.getElementById('withdrawForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const raw = parseAmount(document.getElementById('amountInput').value);
    const info = getAccountInfo();

    if (raw < MIN_WD) return showToast('Số tiền phải tối thiểu ' + fmt(MIN_WD) + ' ₫', 'error');
    if (raw > MAX_WD) return showToast('Số tiền vượt quá hạn mức cho phép', 'error');
    if (!info.accNumber || !info.accName) return showToast('Vui lòng nhập đầy đủ thông tin tài khoản', 'error');

    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fi fi-rr-spinner" style="animation:spin 1s linear infinite;"></i> Đang xử lý...';

    const body = new URLSearchParams({
        amount: raw,
        method: info.method,
        account_name: info.accName,
        account_number: info.accNumber,
        bank_name: info.bankName,
        bank_code: info.bankCode,
        save_account: info.saveNew ? '1' : '0'
    });

    try {
        const res = await fetch(BASE_URL + 'api/finance/withdraw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
        const json = await res.json();

        if (json.success) {
            showToast(json.message || 'Yêu cầu rút tiền đã được gửi!', 'success');
            setTimeout(() => { window.location.href = 'index.php'; }, 2000);
        } else {
            showToast(json.message || 'Có lỗi xảy ra', 'error');
            btn.disabled = false;
            btn.innerHTML = '💸 Gửi yêu cầu rút tiền';
        }
    } catch (err) {
        showToast('Lỗi kết nối máy chủ', 'error');
        btn.disabled = false;
        btn.innerHTML = '💸 Gửi yêu cầu rút tiền';
    }
});

// ── Delete saved account ──
async function deleteAccount(accId) {
    if (!confirm('Bạn có chắc muốn xóa tài khoản này?')) return;
    
    try {
        const res = await fetch(BASE_URL + 'api/finance/withdraw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_account&account_id=' + accId
        });
        const json = await res.json();
        if (json.success) {
            const el = document.getElementById('savedAccItem_' + accId);
            if (el) el.remove();
            showToast('Đã xóa tài khoản', 'success');
        } else {
            showToast(json.message || 'Xóa thất bại', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối máy chủ', 'error');
    }
}

// Spinner animation
const styleTag = document.createElement('style');
styleTag.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
document.head.appendChild(styleTag);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
