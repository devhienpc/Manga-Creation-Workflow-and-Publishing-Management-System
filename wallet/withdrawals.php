<?php
/**
 * wallet/withdrawals.php
 * Lịch sử yêu cầu rút tiền của họa sĩ & trợ lý.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Lịch sử rút tiền';
$activePage   = 'wallet_withdrawals';
$allowedRoles = [ROLES['MANGAKA'], ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db     = getDB();
$userId = $currentUser['id'];

// ── Bộ lọc ──
$fStatus = trim($_GET['status'] ?? '');
$fMonth  = trim($_GET['month'] ?? ''); // Định dạng Y-m

$where = ['user_id = ?'];
$params = [$userId];

if ($fStatus !== '') {
    $where[] = 'status = ?';
    $params[] = $fStatus;
}
if ($fMonth !== '') {
    $where[] = 'DATE_FORMAT(requested_at, "%Y-%m") = ?';
    $params[] = $fMonth;
}

$whereSql = implode(' AND ', $where);

// Lấy danh sách yêu cầu rút tiền
$stmt = $db->prepare("
    SELECT * FROM withdrawal_requests
    WHERE $whereSql
    ORDER BY requested_at DESC
");
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

// ── Tính toán thống kê toàn bộ lịch sử rút ──
$totalCompleted = 0;
$totalPending   = 0;
$totalRejected  = 0;
$rejectedCount  = 0;

try {
    $statsStmt = $db->prepare("
        SELECT status, COALESCE(SUM(net_amount), 0) as total_net, COALESCE(SUM(amount), 0) as total_gross, COUNT(*) as cnt 
        FROM withdrawal_requests 
        WHERE user_id = ? 
        GROUP BY status
    ");
    $statsStmt->execute([$userId]);
    $statusStats = $statsStmt->fetchAll(PDO::FETCH_UNIQUE);

    $totalCompleted = (float)($statusStats['completed']['total_net'] ?? 0);
    $totalPending   = (float)(($statusStats['pending']['total_gross'] ?? 0) + ($statusStats['processing']['total_gross'] ?? 0));
    $totalRejected  = (float)($statusStats['rejected']['total_gross'] ?? 0);
    $rejectedCount  = (int)($statusStats['rejected']['cnt'] ?? 0);
} catch (\Throwable $e) {
    error_log("Lỗi tính thống kê rút tiền: " . $e->getMessage());
}

// Danh sách các tháng đã có yêu cầu để filter
$monthsList = [];
try {
    $mStmt = $db->prepare("
        SELECT DISTINCT DATE_FORMAT(requested_at, '%Y-%m') as ym
        FROM withdrawal_requests
        WHERE user_id = ?
        ORDER BY ym DESC
    ");
    $mStmt->execute([$userId]);
    $monthsList = $mStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

// Helper labels
$statusMeta = [
    'pending'    => ['label' => '🟡 Chờ xử lý',  'cls' => 'wd-badge-pending'],
    'processing' => ['label' => '🔵 Đang xử lý', 'cls' => 'wd-badge-processing'],
    'completed'  => ['label' => '🟢 Hoàn thành',  'cls' => 'wd-badge-completed'],
    'rejected'   => ['label' => '🔴 Từ chối',     'cls' => 'wd-badge-rejected'],
];

function getMethodLabel($m, $bank = '') {
    if ($m === 'bank_transfer') return '🏦 ' . ($bank ?: 'Ngân hàng');
    if ($m === 'momo') return '📱 Ví MoMo';
    if ($m === 'zalopay') return '💳 Ví ZaloPay';
    return $m;
}
?>

<style>
/* ─── CSS Tooltip ─── */
.tooltip-container {
    position: relative;
    cursor: help;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.tooltip-container .tooltip-text {
    visibility: hidden;
    width: 240px;
    background-color: #1e1e24;
    color: #fff;
    text-align: left;
    border-radius: 6px;
    padding: 10px 14px;
    position: absolute;
    z-index: 100;
    bottom: 125%;
    left: 50%;
    transform: translateX(-50%);
    opacity: 0;
    transition: opacity 0.25s, transform 0.25s;
    font-size: 0.78rem;
    font-weight: 500;
    line-height: 1.4;
    border: 1px solid var(--border);
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    pointer-events: none;
}
.tooltip-container .tooltip-text::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -5px;
    border-width: 5px;
    border-style: solid;
    border-color: #1e1e24 transparent transparent transparent;
}
.tooltip-container:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
    transform: translate(-50%, -4px);
}

/* ─── Badges style ─── */
.wd-badge {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 100px;
    display: inline-flex;
    align-items: center;
}
.wd-badge-pending    { background: rgba(245,158,11,0.12); color: #f59e0b; }
.wd-badge-processing { background: rgba(59,130,246,0.12);  color: #3b82f6; }
.wd-badge-completed  { background: rgba(16,185,129,0.12);  color: #10b981; }
.wd-badge-rejected   { background: rgba(239,68,68,0.12);   color: #ef4444; }

/* ─── Filter & tables ─── */
.history-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 20px;
}
.history-filter select {
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: #fff;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.82rem;
    outline: none;
}
.filter-btn {
    background: var(--red);
    color: #fff;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: opacity var(--dur);
}
.filter-btn:hover { opacity: 0.85; }

.history-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 24px;
}
.history-table {
    width: 100%;
    border-collapse: collapse;
}
.history-table th, .history-table td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    font-size: 0.84rem;
    text-align: left;
    white-space: nowrap;
}
.history-table th {
    background: rgba(255,255,255,0.01);
    color: var(--text-muted);
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.history-table tbody tr { transition: background var(--dur); }
.history-table tbody tr:hover { background: var(--bg-hover); }

/* ─── Summary Panel ─── */
.summary-panel {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-top: 24px;
}
.summary-item {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    text-align: center;
}
.summary-item .si-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-bottom: 8px;
    letter-spacing: 0.5px;
}
.summary-item .si-val {
    font-size: 1.4rem;
    font-weight: 800;
}
.summary-item.completed .si-val { color: #10b981; }
.summary-item.pending .si-val   { color: #f59e0b; }
.summary-item.rejected .si-val  { color: #ef4444; }
</style>

<div style="margin-bottom:20px;">
    <div class="breadcrumb">
        <a href="<?= BASE_URL . $currentUser['role'] ?>/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <a href="<?= BASE_URL ?>wallet/index.php">Ví của tôi</a>
        <span class="sep">›</span>
        <span class="current">Lịch sử rút tiền</span>
    </div>
    <h1>Lịch Sử Rút Tiền</h1>
    <p class="text-muted">Xem và theo dõi trạng thái các yêu cầu rút tiền của bạn về tài khoản ngân hàng hoặc ví điện tử.</p>
</div>

<!-- Bộ lọc -->
<form class="history-filter" method="GET" action="">
    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Bộ lọc:</span>
    
    <select name="status">
        <option value="">— Tất cả trạng thái —</option>
        <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>🟡 Chờ xử lý</option>
        <option value="processing" <?= $fStatus === 'processing' ? 'selected' : '' ?>>🔵 Đang xử lý</option>
        <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>🟢 Hoàn thành</option>
        <option value="rejected" <?= $fStatus === 'rejected' ? 'selected' : '' ?>>🔴 Từ chối</option>
    </select>

    <select name="month">
        <option value="">— Tất cả thời gian —</option>
        <?php foreach ($monthsList as $ym): ?>
            <?php 
            $parts = explode('-', $ym);
            $label = "Tháng " . $parts[1] . "/" . $parts[0];
            ?>
            <option value="<?= $ym ?>" <?= $fMonth === $ym ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="filter-btn">Lọc kết quả</button>
    <?php if ($fStatus !== '' || $fMonth !== ''): ?>
        <a href="withdrawals.php" style="font-size:0.8rem; color:var(--text-muted); text-decoration:none; font-weight:600; margin-left:8px;">Đặt lại</a>
    <?php endif; ?>
</form>

<!-- Bảng kết quả -->
<div class="history-card">
    <div style="overflow-x:auto;">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Ngày yêu cầu</th>
                    <th>Phương thức</th>
                    <th>Tài khoản nhận</th>
                    <th style="text-align:right;">Số tiền rút</th>
                    <th style="text-align:right;">Phí xử lý</th>
                    <th style="text-align:right;">Thực nhận</th>
                    <th style="text-align:center;">Trạng thái</th>
                    <th>Ngày xử lý</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($withdrawals)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:48px; color:var(--text-muted);">
                            <i class="fi fi-rr-inbox-out" style="font-size:2rem; display:block; margin-bottom:10px; opacity:0.3;"></i>
                            Không có yêu cầu rút tiền nào trong danh sách.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($withdrawals as $w): ?>
                        <?php 
                        $meta = $statusMeta[$w['status']] ?? ['label' => $w['status'], 'cls' => ''];
                        $processedAt = $w['processed_at'] ? date('H:i d/m/Y', strtotime($w['processed_at'])) : '—';
                        ?>
                        <tr>
                            <td style="color:var(--text-muted); font-size:0.8rem;"><?= date('H:i d/m/Y', strtotime($w['requested_at'])) ?></td>
                            <td><?= getMethodLabel($w['method'], $w['bank_name']) ?></td>
                            <td style="font-family:'SF Mono',monospace; color:var(--text-muted);">
                                <strong><?= htmlspecialchars($w['account_number']) ?></strong><br>
                                <span style="font-size:0.75rem; text-transform:uppercase;"><?= htmlspecialchars($w['account_name']) ?></span>
                            </td>
                            <td style="text-align:right; font-family:'SF Mono',monospace; font-weight:700; color:#fff;">
                                <?= format_money($w['amount']) ?> ₫
                            </td>
                            <td style="text-align:right; font-family:'SF Mono',monospace; color:var(--text-muted);">
                                <?= format_money($w['fee_amount']) ?> ₫
                            </td>
                            <td style="text-align:right; font-family:'SF Mono',monospace; font-weight:700; color:#10b981;">
                                <?= format_money($w['net_amount']) ?> ₫
                            </td>
                            <td style="text-align:center;">
                                <?php if ($w['status'] === 'rejected'): ?>
                                    <div class="tooltip-container wd-badge <?= $meta['cls'] ?>">
                                        <?= $meta['label'] ?> 
                                        <i class="fi fi-rr-interrogation" style="font-size:0.75rem; vertical-align:middle;"></i>
                                        <span class="tooltip-text">
                                            <strong>Lý do từ chối:</strong><br>
                                            <?= htmlspecialchars($w['admin_note'] ?: 'Không có ghi chú lý do cụ thể.') ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="wd-badge <?= $meta['cls'] ?>"><?= $meta['label'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text-muted); font-size:0.8rem;"><?= $processedAt ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Tổng kết cuối trang -->
<div style="margin-top:32px;">
    <h3 style="font-size:1rem; font-weight:700; margin-bottom:14px; border-left:3px solid var(--red); padding-left:10px;">Tổng kết tài chính</h3>
    <div class="summary-panel">
        <div class="summary-item completed">
            <div class="si-label">Tổng đã rút thành công</div>
            <div class="si-val"><?= format_money($totalCompleted) ?> ₫</div>
        </div>
        <div class="summary-item pending">
            <div class="si-label">Đang chờ xử lý</div>
            <div class="si-val"><?= format_money($totalPending) ?> ₫</div>
        </div>
        <div class="summary-item rejected">
            <div class="si-label">Đã bị từ chối</div>
            <div class="si-val"><?= format_money($totalRejected) ?> ₫</div>
            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Tổng số lần: <?= $rejectedCount ?></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
