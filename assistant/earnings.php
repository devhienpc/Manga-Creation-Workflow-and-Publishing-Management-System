<?php
/**
 * assistant/earnings.php
 * Thống kê thu nhập chi tiết của Trợ lý Manga (Assistant).
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
$stmt = $db->prepare("SELECT DISTINCT year FROM earnings WHERE assistant_id = ? ORDER BY year DESC");
$stmt->execute([$uid]);
$yearsList = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Nếu CSDL trống chưa có bản ghi nào, mặc định hiển thị năm hiện tại
if (empty($yearsList)) {
    $yearsList = [date('Y')];
}

/* ══════════════════════════════════════════════════
   2. THỐNG KÊ TỔNG QUAN
   ══════════════════════════════════════════════════ */
// Tổng thu nhập cộng dồn trọn đời (All-time)
$stmt = $db->prepare("SELECT SUM(total) FROM earnings WHERE assistant_id = ?");
$stmt->execute([$uid]);
$allTimeEarnings = (float)$stmt->fetchColumn();

// Tổng số trang đã được duyệt trọn đời (All-time)
$stmt = $db->prepare("SELECT SUM(approved_pages) FROM earnings WHERE assistant_id = ?");
$stmt->execute([$uid]);
$allTimePages = (int)$stmt->fetchColumn();

// Tổng thu nhập trong năm đang lọc
$stmt = $db->prepare("SELECT SUM(total) FROM earnings WHERE assistant_id = ? AND year = ?");
$stmt->execute([$uid, $selectedYear]);
$yearEarnings = (float)$stmt->fetchColumn();

// Tổng số trang được duyệt trong năm đang lọc
$stmt = $db->prepare("SELECT SUM(approved_pages) FROM earnings WHERE assistant_id = ? AND year = ?");
$stmt->execute([$uid, $selectedYear]);
$yearPages = (int)$stmt->fetchColumn();

/* ══════════════════════════════════════════════════
   3. DANH SÁCH CHI TIẾT THU NHẬP THEO THÁNG CỦA NĂM LỌC
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT id, month, year, approved_pages, rate_per_page, total 
     FROM earnings 
     WHERE assistant_id = ? AND year = ? 
     ORDER BY month DESC"
);
$stmt->execute([$uid, $selectedYear]);
$monthlyList = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   4. CHUẨN BỊ DỮ LIỆU BIỂU ĐỒ 6 THÁNG GẦN NHẤT
   ══════════════════════════════════════════════════ */
$barLabels = [];
$barValues = [];
for ($i = 5; $i >= 0; $i--) {
    $d = strtotime("-$i month");
    $m = (int)date('m', $d);
    $y = (int)date('Y', $d);
    
    $barLabels[] = "Tháng $m/$y";
    
    // Truy vấn doanh thu của từng tháng tương ứng
    $stmt = $db->prepare("SELECT total FROM earnings WHERE assistant_id = ? AND month = ? AND year = ?");
    $stmt->execute([$uid, $m, $y]);
    $totalVal = (float)$stmt->fetchColumn();
    $barValues[] = $totalVal ?: 0.0;
}

$jsBarLabels = json_encode($barLabels);
$jsBarValues = json_encode($barValues);
?>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>assistant/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Thu nhập</span>
    </div>
    <h1>Thống Kê Thu Nhập Trợ Lý</h1>
    <p>Theo dõi tổng kết thanh toán lương vẽ trang truyện được chốt theo từng tháng</p>
</div>

<!-- 3 Cards Thống Kê Tổng Quan -->
<div class="stat-grid grid-3 mb-24">
    <!-- Lifetime Cumulative Total -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Tổng thu nhập tích luỹ</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#fbbf24;"><?= number_format($allTimeEarnings) ?> đ</div>
            <p class="text-xs text-muted mt-8">Cộng dồn trọn đời từ hệ thống</p>
        </div>
        <div class="stat-icon" style="color:#fbbf24; font-size:1.8rem; opacity:0.8;">💎</div>
    </div>
    <!-- Year Total -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Thu nhập năm <?= $selectedYear ?></p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#34d399;"><?= number_format($yearEarnings) ?> đ</div>
            <p class="text-xs text-muted mt-8">Trong năm đang được chọn lọc</p>
        </div>
        <div class="stat-icon" style="color:#34d399; font-size:1.8rem; opacity:0.8;">💰</div>
    </div>
    <!-- Year Pages -->
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Trang hoàn thành năm <?= $selectedYear ?></p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px; color:#60a5fa;"><?= $yearPages ?> trang</div>
            <p class="text-xs text-muted mt-8">Trọn đời: <?= $allTimePages ?> trang đã duyệt</p>
        </div>
        <div class="stat-icon" style="color:#60a5fa; font-size:1.8rem; opacity:0.8;">🎨</div>
    </div>
</div>

<div class="grid-2 gap-24" style="grid-template-columns: 1.25fr 1fr; align-items: start;">
    <!-- LEFT COLUMN: THỐNG KÊ CHI TIẾT & BẢNG LỌC -->
    <div>
        <div class="card" style="padding:0; overflow:hidden;">
            <!-- Header có bộ lọc -->
            <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <p class="card-title" style="font-size:1.05rem; font-weight:700">Lịch Sử Thanh Toán Chi Tiết</p>
                    <p class="card-subtitle">Chi tiết lương chốt theo từng tháng của năm <?= $selectedYear ?></p>
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
                    <span style="font-size:3rem;">💸</span>
                    <p style="margin-top:10px;">Chưa có dữ liệu thanh toán nào được chốt cho năm <?= $selectedYear ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                                <th style="padding:14px 18px;">Thời gian</th>
                                <th style="padding:14px 18px; text-align:right;">Số trang duyệt</th>
                                <th style="padding:14px 18px; text-align:right;">Đơn giá / trang</th>
                                <th style="padding:14px 18px; text-align:right; font-weight:700;">Tổng tiền chốt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyList as $item): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                    <td style="padding:14px 18px;" class="font-bold">
                                        Tháng <?= sprintf('%02d', $item['month']) ?> / <?= $item['year'] ?>
                                    </td>
                                    <td style="padding:14px 18px; text-align:right;">
                                        <?= $item['approved_pages'] ?> trang
                                    </td>
                                    <td style="padding:14px 18px; text-align:right; color:var(--text-muted);">
                                        <?= number_format($item['rate_per_page']) ?> đ
                                    </td>
                                    <td style="padding:14px 18px; text-align:right; color:#fbbf24; font-weight:700;">
                                        <?= number_format($item['total']) ?> đ
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
                <p class="card-title" style="font-size:1.05rem; font-weight:700">Xu Hướng Thu Nhập 6 Tháng</p>
                <p class="card-subtitle">Thống kê lương chốt gần nhất tính đến tháng hiện tại</p>
            </div>

            <div style="position: relative; width: 100%; height: 260px;">
                <canvas id="earningsBarChart"></canvas>
            </div>
            
            <div class="alert alert-info mt-16" style="padding: 10px 14px; font-size: 0.78rem; border-radius: 8px;">
                💡 <em>Lưu ý: Biểu đồ cột thể hiện mức thu nhập thực tế đã được duyệt và chuyển khoản bởi Ban biên tập.</em>
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
                label: 'Thu nhập',
                data: values,
                backgroundColor: 'rgba(230, 57, 70, 0.85)',
                borderColor: '#E63946',
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
                            return `Thu nhập: ${context.raw.toLocaleString()} đ`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000) + 'M đ';
                            }
                            return value.toLocaleString() + ' đ';
                        }
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)'
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.05)'
                    }
                }
            }
        }
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
