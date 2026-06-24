<?php
/**
 * mangaka/ranking.php
 * Bảng xếp hạng tác phẩm và Lịch sử xếp hạng của Mangaka.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'BXH Truyện';
$activePage   = 'ranking';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. ĐỐI SOÁT KỲ BÌNH CHỌN (PERIODS)
   ══════════════════════════════════════════════════ */
// Tìm 2 kỳ gần nhất có dữ liệu bình chọn
$periodsStmt = $db->query("SELECT DISTINCT vote_period FROM votes ORDER BY vote_period DESC LIMIT 2");
$periods = $periodsStmt->fetchAll(PDO::FETCH_COLUMN);
$latestPeriod = $periods[0] ?? null;
$prevPeriod = $periods[1] ?? null;

/* ══════════════════════════════════════════════════
   2. LẤY TOÀN BỘ SERIES ĐANG XUẤT BẢN
   ══════════════════════════════════════════════════ */
$seriesStmt = $db->query("
    SELECT s.id, s.title, s.cover_image, s.mangaka_id, u.username AS mangaka_username
    FROM series s
    JOIN users u ON u.id = s.mangaka_id
    WHERE s.status = 'publishing'
");
$publishingSeries = $seriesStmt->fetchAll();
$totalPublishing = count($publishingSeries);

/* ══════════════════════════════════════════════════
   3. TRUY VẤN VÀ TÍNH TOÁN XẾP HẠNG (LATEST & PREV)
   ══════════════════════════════════════════════════ */
$latestVotes = [];
if ($latestPeriod) {
    $stmt = $db->prepare("SELECT series_id, reader_votes, rank_position FROM votes WHERE vote_period = ?");
    $stmt->execute([$latestPeriod]);
    $latestVotes = $stmt->fetchAll(PDO::FETCH_UNIQUE);
}

$prevVotes = [];
if ($prevPeriod) {
    $stmt = $db->prepare("SELECT series_id, reader_votes, rank_position FROM votes WHERE vote_period = ?");
    $stmt->execute([$prevPeriod]);
    $prevVotes = $stmt->fetchAll(PDO::FETCH_UNIQUE);
}

// Lập danh sách điểm số (phiếu bầu) để tự động tính hạng nếu DB chưa điền rank_position
$latestScores = [];
$prevScores = [];
foreach ($publishingSeries as $s) {
    $sid = $s['id'];
    $latestScores[$sid] = $latestVotes[$sid]['reader_votes'] ?? 0;
    $prevScores[$sid] = $prevVotes[$sid]['reader_votes'] ?? 0;
}
arsort($latestScores);
arsort($prevScores);

// Gán hạng động theo thứ tự giảm dần phiếu bầu
$latestDynamicRanks = [];
$r = 1;
foreach ($latestScores as $sid => $v) {
    $latestDynamicRanks[$sid] = $latestVotes[$sid]['rank_position'] ?? $r;
    $r++;
}

$prevDynamicRanks = [];
$r = 1;
foreach ($prevScores as $sid => $v) {
    $prevDynamicRanks[$sid] = $prevVotes[$sid]['rank_position'] ?? $r;
    $r++;
}

// Tổ hợp dữ liệu hiển thị
foreach ($publishingSeries as &$s) {
    $sid = $s['id'];
    $s['votes'] = $latestVotes[$sid]['reader_votes'] ?? 0;
    $s['rank'] = $latestDynamicRanks[$sid] ?? $totalPublishing;
    $s['prev_rank'] = $prevDynamicRanks[$sid] ?? null;

    // Tính toán xu hướng (trend)
    if (isset($prevVotes[$sid]) || isset($prevDynamicRanks[$sid])) {
        $prevR = $prevDynamicRanks[$sid];
        $currR = $latestDynamicRanks[$sid];
        if ($currR < $prevR) {
            $s['trend'] = 'up';     // Tăng hạng (số thứ hạng nhỏ đi)
        } elseif ($currR > $prevR) {
            $s['trend'] = 'down';   // Giảm hạng
        } else {
            $s['trend'] = 'same';   // Giữ nguyên
        }
    } else {
        $s['trend'] = 'new';        // Kỳ trước chưa có hạng
    }

    // Cảnh báo đỏ nguy cơ hủy (dưới top 70%, tương đương nhóm 30% cuối bảng)
    $s['is_threatened'] = $totalPublishing > 0 ? (($s['rank'] / $totalPublishing) > 0.70) : false;
}
unset($s);

// Sắp xếp danh sách hiển thị theo hạng tăng dần (Hạng 1 đứng đầu)
usort($publishingSeries, function($a, $b) {
    return $a['rank'] <=> $b['rank'];
});

/* ══════════════════════════════════════════════════
   4. TỰ ĐỘNG THÊM THÔNG BÁO RANK_DROP (NẾU CÓ NGUY CƠ)
   ══════════════════════════════════════════════════ */
if ($latestPeriod && $totalPublishing > 0) {
    foreach ($publishingSeries as $s) {
        // Chỉ thông báo cho tác phẩm của Mangaka hiện tại
        if ($s['mangaka_id'] == $uid && $s['is_threatened']) {
            $msg = "Cảnh báo: Bộ truyện \"" . $s['title'] . "\" đang xếp thứ " . $s['rank'] . "/" . $totalPublishing . " (Kỳ " . $latestPeriod . "), rơi xuống dưới mức top 70%! Nguy cơ bị hủy xuất bản cao.";
            
            // Tránh spam thông báo trùng lặp cho cùng một kỳ bình chọn
            $check = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'rank_drop' AND message LIKE ?");
            $check->execute([$uid, "%" . $s['title'] . "%" . $latestPeriod . "%"]);
            $alreadyNotified = (int)$check->fetchColumn() > 0;

            if (!$alreadyNotified) {
                $ins = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'rank_drop', ?, 'mangaka/ranking.php')");
                $ins->execute([$uid, $msg]);
            }
        }
    }
}

/* ══════════════════════════════════════════════════
   5. LẤY LỊCH SỬ XẾP HẠNG CỦA MANGAKA ĐỂ VẼ BIỂU ĐỒ
   ══════════════════════════════════════════════════ */
// Lấy danh sách truyện đang xuất bản của họa sĩ này
$mySeriesStmt = $db->prepare("SELECT id, title FROM series WHERE mangaka_id = ? AND status = 'publishing'");
$mySeriesStmt->execute([$uid]);
$mySeries = $mySeriesStmt->fetchAll();

// Lấy toàn bộ lịch sử vote của các truyện đó
$mySeriesIds = array_column($mySeries, 'id');
$historyData = [];
if (!empty($mySeriesIds)) {
    $placeholders = implode(',', array_fill(0, count($mySeriesIds), '?'));
    $histStmt = $db->prepare("
        SELECT series_id, vote_period, reader_votes, rank_position
        FROM votes
        WHERE series_id IN ($placeholders)
        ORDER BY vote_period ASC
    ");
    $histStmt->execute($mySeriesIds);
    $historyData = $histStmt->fetchAll();
}

// Gom nhóm dữ liệu lịch sử theo series_id để dễ truyền qua JS
$seriesHistoryMap = [];
foreach ($mySeries as $ms) {
    $seriesHistoryMap[$ms['id']] = [
        'title' => $ms['title'],
        'points' => []
    ];
}
foreach ($historyData as $hd) {
    $sid = $hd['series_id'];
    if (isset($seriesHistoryMap[$sid])) {
        // Nếu rank_position trống, chúng ta có thể tính toán tương đối hoặc bỏ qua
        // Ở đây lấy trực tiếp từ DB
        $seriesHistoryMap[$sid]['points'][] = [
            'period' => $hd['vote_period'],
            'votes'  => (int)$hd['reader_votes'],
            'rank'   => $hd['rank_position'] !== null ? (int)$hd['rank_position'] : null
        ];
    }
}

$jsHistoryMap = json_encode($seriesHistoryMap);
?>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">BXH Truyện</span>
    </div>
    <h1>Bảng Xếp Hạng Tác Phẩm</h1>
    <p>Theo dõi thứ hạng độc giả bình chọn của tất cả các bộ truyện đang xuất bản</p>
</div>

<!-- Thống kê tổng quan dạng thẻ -->
<div class="stat-grid grid-3 mb-24">
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Tổng tác phẩm đang phát hành</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px;"><?= $totalPublishing ?></div>
        </div>
        <div class="stat-icon" style="color:var(--red); font-size:1.8rem; opacity:0.8;">📚</div>
    </div>
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Truyện của bạn đang xuất bản</p>
            <div class="stat-number" style="font-size: 2.2rem; font-weight:800; margin-top:5px;"><?= count($mySeries) ?></div>
        </div>
        <div class="stat-icon" style="color:#fbbf24; font-size:1.8rem; opacity:0.8;">🖊️</div>
    </div>
    <div class="card stat-card" style="padding: 20px;">
        <div>
            <p class="text-xs text-muted font-bold" style="text-transform:uppercase; letter-spacing:0.5px;">Kỳ bình chọn mới nhất</p>
            <div class="stat-number" style="font-size: 1.8rem; font-weight:800; margin-top:10px; color:#34d399;"><?= htmlspecialchars($latestPeriod ?? 'Chưa có dữ liệu') ?></div>
        </div>
        <div class="stat-icon" style="color:#34d399; font-size:1.8rem; opacity:0.8;">⏱️</div>
    </div>
</div>

<div class="grid-2 gap-24" style="grid-template-columns: 1.3fr 1fr; align-items: start;">
    <!-- ═══════════════════ LEFT COLUMN: BẢNG XẾP HẠNG ═══════════════════ -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="padding: 20px 24px; border-bottom:1px solid var(--border)">
            <div>
                <p class="card-title" style="font-size:1.05rem; font-weight:700">Thứ Hạng Bình Chọn Độc Giả</p>
                <p class="card-subtitle">Cập nhật theo kỳ <?= htmlspecialchars($latestPeriod ?? 'năm nay') ?></p>
            </div>
        </div>

        <?php if (empty($publishingSeries)): ?>
            <div style="text-align:center; padding: 60px 20px; color:var(--text-muted);">
                <span style="font-size:3rem;">🏆</span>
                <p style="margin-top:10px;">Không có bộ truyện nào đang ở trạng thái xuất bản (Publishing).</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="rank-table" style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid var(--border);">
                            <th style="padding:14px 18px; width:60px;">Hạng</th>
                            <th style="padding:14px 18px;">Tác phẩm</th>
                            <th style="padding:14px 18px; text-align:right;">Phiếu bầu</th>
                            <th style="padding:14px 18px; text-align:center; width:90px;">Xu hướng</th>
                            <th style="padding:14px 18px; text-align:right;">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($publishingSeries as $s):
                            $isMySeries = ($s['mangaka_id'] == $uid);
                            $coverUrl = $s['cover_image'] ? BASE_URL . 'assets/uploads/' . $s['cover_image'] : null;
                            
                            // Xác định class cho thứ hạng top
                            $rankClass = '';
                            if ($s['rank'] <= 3) {
                                $rankClass = 'rank-' . $s['rank'];
                            }
                        ?>
                            <tr class="<?= $isMySeries ? 'rank-highlight' : '' ?> <?= $s['is_threatened'] ? 'threatened-row' : '' ?>" 
                                style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;">
                                
                                <!-- Hạng -->
                                <td style="padding:14px 18px;">
                                    <span class="rank-badge-number <?= $rankClass ?>"><?= $s['rank'] ?></span>
                                </td>

                                <!-- Tác phẩm -->
                                <td style="padding:14px 18px;">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div style="width:40px; height:56px; border-radius:4px; overflow:hidden; background:var(--bg-input); border:1px solid var(--border); flex-shrink:0;">
                                            <?php if ($coverUrl): ?>
                                                <img src="<?= htmlspecialchars($coverUrl) ?>" alt="cover" style="width:100%; height:100%; object-fit:cover">
                                            <?php else: ?>
                                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:1rem">📖</div>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width:0;">
                                            <div class="font-bold truncate" style="font-size:0.9rem;" title="<?= htmlspecialchars($s['title']) ?>">
                                                <?= htmlspecialchars($s['title']) ?>
                                            </div>
                                            <div class="text-xs text-muted truncate">
                                                Họa sĩ: <?= htmlspecialchars($s['mangaka_username']) ?>
                                                <?php if ($isMySeries): ?>
                                                    <span style="color:var(--red); font-weight:700; margin-left:4px;">(Bạn)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Phiếu bầu -->
                                <td style="padding:14px 18px; text-align:right;" class="font-bold">
                                    <?= number_format($s['votes']) ?>
                                </td>

                                <!-- Xu hướng -->
                                <td style="padding:14px 18px; text-align:center;">
                                    <?php if ($s['trend'] === 'up'): ?>
                                        <span class="trend-indicator trend-up" title="Tăng hạng (Kỳ trước: Hạng <?= $s['prev_rank'] ?>)">
                                            ▲ <span style="font-size:0.75rem;"><?= $s['prev_rank'] - $s['rank'] ?></span>
                                        </span>
                                    <?php elseif ($s['trend'] === 'down'): ?>
                                        <span class="trend-indicator trend-down" title="Giảm hạng (Kỳ trước: Hạng <?= $s['prev_rank'] ?>)">
                                            ▼ <span style="font-size:0.75rem;"><?= $s['rank'] - $s['prev_rank'] ?></span>
                                        </span>
                                    <?php elseif ($s['trend'] === 'same'): ?>
                                        <span class="trend-indicator trend-same" title="Giữ nguyên thứ hạng">
                                            ▬
                                        </span>
                                    <?php else: ?>
                                        <span class="trend-new">Mới</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Trạng thái / Cảnh báo -->
                                <td style="padding:14px 18px; text-align:right;">
                                    <?php if ($s['is_threatened']): ?>
                                        <span class="badge threatened-badge" style="font-size: 0.65rem; padding: 3px 8px; font-weight:800;">
                                            ⚠️ Nguy cơ hủy
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-green" style="font-size: 0.65rem; padding: 3px 8px; font-weight:700;">
                                            An toàn
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════ RIGHT COLUMN: LỊCH SỬ XẾP HẠNG (CHART) ═══════════════════ -->
    <div class="card" style="padding: 24px;">
        <div class="card-header" style="padding:0; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <p class="card-title" style="font-size:1.05rem; font-weight:700">Lịch Sử Xếp Hạng</p>
                <p class="card-subtitle">Theo dõi xu hướng các kỳ</p>
            </div>
            
            <?php if (!empty($mySeries)): ?>
                <select id="seriesChartSelect" class="form-control" style="max-width: 180px; padding: 5px 10px; font-size: 0.8rem; margin:0;">
                    <?php foreach ($mySeries as $idx => $ms): ?>
                        <option value="<?= $ms['id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ms['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <?php if (empty($mySeries)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <span style="font-size:3rem;">📈</span>
                <p style="margin-top:10px;">Bạn không có tác phẩm nào đang xuất bản để xem lịch sử xếp hạng.</p>
            </div>
        <?php else: ?>
            <div style="position: relative; width: 100%; height: 260px;">
                <canvas id="rankingHistoryChart"></canvas>
            </div>
            <div class="alert alert-info mt-16" style="padding: 10px 14px; font-size: 0.78rem; border-radius: 8px;">
                💡 <em>Lưu ý: Biểu đồ đường thẳng thể hiện vị trí xếp hạng. Trục dọc được đảo ngược (vị trí càng cao nghĩa là thứ hạng càng tốt).</em>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const historyMap = <?= $jsHistoryMap ?>;
    const selectEl = document.getElementById('seriesChartSelect');
    const ctx = document.getElementById('rankingHistoryChart');
    
    if (!ctx || !selectEl) return;
    
    let activeChart = null;

    function renderChart(seriesId) {
        const dataInfo = historyMap[seriesId];
        if (!dataInfo || dataInfo.points.length === 0) {
            // Trường hợp chưa có dữ liệu lịch sử
            if (activeChart) activeChart.destroy();
            
            // Vẽ biểu đồ trống
            activeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Chưa có dữ liệu'],
                    datasets: [{
                        label: 'Hạng',
                        data: [null],
                        borderColor: '#E63946',
                        backgroundColor: 'rgba(230, 57, 70, 0.1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
            return;
        }

        // Tách nhãn trục X và dữ liệu trục Y
        const labels = dataInfo.points.map(p => p.period);
        const ranks = dataInfo.points.map(p => p.rank);
        const votes = dataInfo.points.map(p => p.votes);

        if (activeChart) activeChart.destroy();

        activeChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Thứ hạng',
                    data: ranks,
                    borderColor: '#E63946',
                    backgroundColor: 'rgba(230, 57, 70, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#E63946',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.15,
                    fill: true
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
                                const index = context.dataIndex;
                                const rankVal = ranks[index];
                                const voteVal = votes[index];
                                return `Hạng: ${rankVal} (${voteVal.toLocaleString()} phiếu)`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        reverse: true, // Đảo ngược Y-axis: Hạng 1 nằm trên cùng
                        ticks: {
                            precision: 0,
                            stepSize: 1
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
            });
        }
    }

    // Render biểu đồ đầu tiên
    renderChart(selectEl.value);

    // Lắng nghe sự kiện đổi bộ truyện
    selectEl.addEventListener('change', function() {
        renderChart(this.value);
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
