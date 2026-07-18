<?php
/**
 * assistant/earnings.php
 * Thống kê thu nhập chi tiết của Trợ lý Manga (Assistant) từ salary_records.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Thu nhập';
$activePage   = 'earnings';
$allowedRoles = [ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

// Năm được chọn lọc (Mặc định là năm hiện tại)
$selectedYear = (int)($_GET['year'] ?? date('Y'));

/* ══════════════════════════════════════════════════
   1. DANH SÁCH CÁC NĂM ĐỂ ĐIỀN VÀO DROPDOWN FILTER
   ══════════════════════════════════════════════════ */
$yearsList = [date('Y')];
try {
    $stmt = $db->prepare("SELECT DISTINCT year FROM salary_records WHERE assistant_id = ? ORDER BY year DESC");
    $stmt->execute([$uid]);
    $dbYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dbYears)) {
        $yearsList = $dbYears;
    }
} catch (\Throwable $e) {}

/* ══════════════════════════════════════════════════
   2. THỐNG KÊ TỔNG QUAN
   ══════════════════════════════════════════════════ */
$allTimeEarnings = 0.0;
$allTimeTasks    = 0;
$yearEarnings    = 0.0;
$yearTasks       = 0;

try {
    // Tổng thu nhập chốt thành công trọn đời
    $stmt = $db->prepare("SELECT SUM(gross_amount) FROM salary_records WHERE assistant_id = ? AND status = 'paid'");
    $stmt->execute([$uid]);
    $allTimeEarnings = (float)$stmt->fetchColumn();

    // Tổng số nhiệm vụ đã được duyệt trọn đời
    $stmt = $db->prepare("SELECT SUM(approved_pages) FROM salary_records WHERE assistant_id = ? AND status = 'paid'");
    $stmt->execute([$uid]);
    $allTimeTasks = (int)$stmt->fetchColumn();

    // Tổng thu nhập chốt thành công trong năm đang lọc
    $stmt = $db->prepare("SELECT SUM(gross_amount) FROM salary_records WHERE assistant_id = ? AND year = ? AND status = 'paid'");
    $stmt->execute([$uid, $selectedYear]);
    $yearEarnings = (float)$stmt->fetchColumn();

    // Tổng số nhiệm vụ được duyệt và đã thanh toán trong năm đang lọc
    $stmt = $db->prepare("SELECT SUM(approved_pages) FROM salary_records WHERE assistant_id = ? AND year = ? AND status = 'paid'");
    $stmt->execute([$uid, $selectedYear]);
    $yearTasks = (int)$stmt->fetchColumn();
} catch (\Throwable $e) {}

/* ══════════════════════════════════════════════════
   3. DANH SÁCH CHI TIẾT LƯƠNG THEO THÁNG CỦA NĂM LỌC
   ══════════════════════════════════════════════════ */
$monthlyList = [];
try {
    $stmt = $db->prepare("
        SELECT sr.*, u.username as mangaka_name
        FROM salary_records sr
        JOIN users u ON sr.mangaka_id = u.id
        WHERE sr.assistant_id = ? AND sr.year = ?
        ORDER BY sr.month DESC, sr.id DESC
    ");
    $stmt->execute([$uid, $selectedYear]);
    $monthlyList = $stmt->fetchAll();
} catch (\Throwable $e) {}

/* ══════════════════════════════════════════════════
   4. CHUẨN BỊ DỮ LIỆU BIỂU ĐỒ 6 THÁNG GẦN NHẤT (CHỈ TÍNH LƯƠNG ĐÃ NHẬN 'paid')
   ══════════════════════════════════════════════════ */
$barLabels = [];
$barValues = [];
for ($i = 5; $i >= 0; $i--) {
    $d = strtotime("first day of this month -$i month");
    $m = (int)date('m', $d);
    $y = (int)date('Y', $d);
    
    $barLabels[] = "Tháng $m/$y";
    
    $totalVal = 0.0;
    try {
        $stmt = $db->prepare("SELECT SUM(gross_amount) FROM salary_records WHERE assistant_id = ? AND month = ? AND year = ? AND status = 'paid'");
        $stmt->execute([$uid, $m, $y]);
        $totalVal = (float)$stmt->fetchColumn();
    } catch (\Throwable $e) {}
    $barValues[] = $totalVal;
}

$jsBarLabels = json_encode($barLabels);
$jsBarValues = json_encode($barValues);

// Helper badges
$statusMeta = [
    'paid'               => ['label' => '🟢 ĐÃ TRẢ', 'cls' => 'badge-green'],
    'pending'            => ['label' => '🟡 CHỜ TRẢ', 'cls' => 'badge-yellow'],
    'insufficient_funds' => ['label' => '🔴 THIẾU TIỀN', 'cls' => 'badge-red'],
];
?>

<style>
/* Tooltip css */
.tooltip-container {
    position: relative;
    cursor: help;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.tooltip-container .tooltip-text {
    visibility: hidden;
    width: 260px;
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

.badge-green { background: rgba(16,185,129,0.12); color: #10b981; }
.badge-yellow { background: rgba(245,158,11,0.12); color: #f59e0b; }
.badge-red   { background: rgba(239,68,68,0.12); color: #ef4444; }

.assistant-badge {
    font-size: 0.72rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 4px;
}
</style>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>assistant/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Thu nhập</span>
    </div>
    <h1>Thống Kê Thu Nhập Trợ Lý</h1>
    <p>Theo dõi tổng kết nhận lương vẽ trang truyện được chốt theo từng tháng từ ví họa sĩ.</p>
</div>

<!-- 3 Cards Thống Kê Tổng Quan -->
<div class="stat-grid grid-3 mb-24">
    <!-- Lifetime Cumulative Total -->
    <div class="card stat-card" style="padding: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Tổng lương đã nhận</p>
            <div class="stat-number" style="font-size: 2rem; font-weight:800; color:#fbbf24;"><?= format_money($allTimeEarnings) ?> ₫</div>
            <p class="text-xs text-muted mt-8">Cộng dồn đã hoàn thành trọn đời</p>
        </div>
        <div class="stat-icon" style="color:#fbbf24; font-size:1.8rem; opacity:0.8;"><i class="fi fi-sr-gem"></i></div>
    </div>
    <!-- Year Total -->
    <div class="card stat-card" style="padding: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Thu nhập nhận năm <?= $selectedYear ?></p>
            <div class="stat-number" style="font-size: 2rem; font-weight:800; color:#34d399;"><?= format_money($yearEarnings) ?> ₫</div>
            <p class="text-xs text-muted mt-8">Chỉ tính các giao dịch đã hoàn thành</p>
        </div>
        <div class="stat-icon" style="color:#34d399; font-size:1.8rem; opacity:0.8;"><i class="fi fi-sr-dollar"></i></div>
    </div>
    <!-- Year Tasks -->
    <div class="card stat-card" style="padding: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Nhiệm vụ hoàn thành năm <?= $selectedYear ?></p>
            <div class="stat-number" style="font-size: 2rem; font-weight:800; color:#60a5fa;"><?= $yearTasks ?> nhiệm vụ</div>
            <p class="text-xs text-muted mt-8">Trọn đời: <?= $allTimeTasks ?> nhiệm vụ vẽ đã trả tiền</p>
        </div>
        <div class="stat-icon" style="color:#60a5fa; font-size:1.8rem; opacity:0.8;"><i class="fi fi-sr-palette"></i></div>
    </div>
</div>

<div class="grid-2 gap-24" style="grid-template-columns: 1.35fr 1fr; align-items: start;">
    <!-- LEFT COLUMN: THỐNG KÊ CHI TIẾT & BẢNG LỌC -->
    <div>
        <div class="card" style="padding:0; overflow:hidden;">
            <!-- Header có bộ lọc -->
            <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <p class="card-title" style="font-size:1.05rem; font-weight:700; margin-bottom:4px;">Bảng Chi Tiết Lương Tháng</p>
                    <p class="card-subtitle" style="margin-bottom:0;">Chi tiết thu nhập từ các họa sĩ trong năm <?= $selectedYear ?></p>
                </div>
                
                <!-- Bộ lọc theo năm -->
                <form method="GET" action="" style="display:flex; align-items:center; gap:8px; margin:0;">
                    <label class="text-xs text-muted font-bold" style="text-transform:uppercase; white-space:nowrap;">Năm:</label>
                    <select name="year" class="form-control" style="width:110px; padding: 5px 10px; font-size:0.8rem; margin:0;" onchange="this.form.submit()">
                        <?php foreach ($yearsList as $yr): ?>
                            <option value="<?= $yr ?>" <?= $yr == $selectedYear ? 'selected' : '' ?>><?= $yr ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (empty($monthlyList)): ?>
                <div style="text-align:center; padding: 60px 20px; color:var(--text-muted);">
                    <span style="font-size:3rem; display:block; margin-bottom:10px;">💸</span>
                    <p style="margin-bottom:0;">Chưa có dữ liệu chốt lương nào trong năm <?= $selectedYear ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap" style="overflow-x:auto;">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                                <th style="padding:14px 18px;">Tháng/Năm</th>
                                <th style="padding:14px 18px;">Họa sĩ chi trả</th>
                                <th style="padding:14px 18px; text-align:center;">Số nhiệm vụ</th>
                                <th style="padding:14px 18px; text-align:right;">Đơn giá TB / nhiệm vụ</th>
                                <th style="padding:14px 18px; text-align:right;">Lương</th>
                                <th style="padding:14px 18px; text-align:center;">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyList as $item): ?>
                                <?php 
                                $meta = $statusMeta[$item['status']] ?? ['label' => $item['status'], 'cls' => ''];
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                    <td style="padding:14px 18px;" class="font-bold">
                                        Tháng <?= sprintf('%02d', $item['month']) ?> / <?= $item['year'] ?>
                                    </td>
                                    <td style="padding:14px 18px; color:var(--text-muted);">
                                        <?= htmlspecialchars($item['mangaka_name']) ?>
                                    </td>
                                    <td style="padding:14px 18px; text-align:center; font-weight:700;">
                                        <?= $item['approved_pages'] ?> nhiệm vụ
                                    </td>
                                    <td style="padding:14px 18px; text-align:right; color:var(--text-muted); font-family: 'SF Mono', monospace;">
                                        <?= format_money($item['rate_per_page']) ?> ₫
                                    </td>
                                    <td style="padding:14px 18px; text-align:right; color:#10b981; font-weight:700; font-family: 'SF Mono', monospace;">
                                        <?= format_money($item['gross_amount']) ?> ₫
                                    </td>
                                    <td style="padding:14px 18px; text-align:center;">
                                        <?php if ($item['status'] === 'insufficient_funds'): ?>
                                            <div class="tooltip-container assistant-badge <?= $meta['cls'] ?>">
                                                <?= $meta['label'] ?>
                                                <i class="fi fi-rr-interrogation" style="font-size:0.7rem; vertical-align:middle;"></i>
                                                <span class="tooltip-text">
                                                    Mangaka chưa đủ số dư, liên hệ để giải quyết.
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="assistant-badge <?= $meta['cls'] ?>"><?= $meta['label'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COLUMN: BIỂU ĐỒ BAR CHART 6 THÁNG GẦN NHẤT -->
    <div>
        <div class="card" style="padding: 24px;">
            <div class="card-header" style="padding:0; margin-bottom: 20px;">
                <p class="card-title" style="font-size:1.05rem; font-weight:700; margin-bottom:4px;">Xu Hướng Thu Nhập 6 Tháng</p>
                <p class="card-subtitle" style="margin-bottom:0;">Thống kê tiền lương vẽ đã nhận thực tế theo từng tháng</p>
            </div>

            <div style="position: relative; width: 100%; height: 260px;">
                <canvas id="earningsBarChart"></canvas>
            </div>
            
            <div class="alert alert-info mt-16" style="padding: 10px 14px; font-size: 0.78rem; border-radius: 8px; background: rgba(96,165,250,0.06); border: 1px solid rgba(96,165,250,0.15); color: #93c5fd;">
                💡 <em>Lưu ý: Biểu đồ cột chỉ thể hiện mức thu nhập thực tế đã được chuyển khoản thành công vào ví của bạn.</em>
            </div>
        </div>
    </div>
</div>

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const labels = <?= $jsBarLabels ?>;
    const values = <?= $jsBarValues ?>;
    const ctx = document.getElementById('earningsBarChart');

    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Lương đã nhận',
                data: values,
                backgroundColor: 'rgba(52, 211, 153, 0.75)',
                borderColor: '#34d399',
                borderWidth: 1.5,
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ` Thu nhận: ${context.raw.toLocaleString()} ₫`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#6b7280',
                        font: { size: 10 },
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000) + 'M ₫';
                            }
                            return value.toLocaleString() + ' ₫';
                        }
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.04)'
                    }
                },
                x: {
                    ticks: { color: '#6b7280', font: { size: 10 } },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
