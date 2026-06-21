<?php
/**
 * board/ranking.php
 * Trang nhập kết quả bình chọn và xem bảng xếp hạng tổng hợp.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Xếp hạng tổng quát';
$activePage   = 'ranking';
$allowedRoles = [ROLES['BOARD']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. SERIES ĐANG XUẤT BẢN (để nhập phiếu)
   ══════════════════════════════════════════════════ */
$publishingStmt = $db->prepare(
    "SELECT s.id, s.title, s.genre, s.publish_schedule, s.cover_image,
            u.username AS mangaka_name
     FROM series s
     JOIN users u ON u.id = s.mangaka_id
     WHERE s.status = 'publishing'
     ORDER BY s.title ASC"
);
$publishingStmt->execute();
$publishingSeries = $publishingStmt->fetchAll();

/* ══════════════════════════════════════════════════
   2. TẤT CẢ KỲ ĐÃ CÓ (để tránh trùng + chọn xem)
   ══════════════════════════════════════════════════ */
$periodsStmt = $db->query(
    "SELECT DISTINCT vote_period, MAX(created_at) AS period_date
     FROM votes
     WHERE vote_period NOT LIKE '%-board-%'
       AND vote_period NOT LIKE '%-CANCEL-%'
     GROUP BY vote_period
     ORDER BY period_date DESC
     LIMIT 24"
);
$existingPeriods = $periodsStmt->fetchAll();

/* ══════════════════════════════════════════════════
   3. BẢNG XẾP HẠNG KỲ ĐƯỢC CHỌN
   ══════════════════════════════════════════════════ */
$selectedPeriod = trim($_GET['period'] ?? '');
if (empty($selectedPeriod) && !empty($existingPeriods)) {
    $selectedPeriod = $existingPeriods[0]['vote_period'];
}

$rankingData   = [];
$prevRankData  = [];

if ($selectedPeriod) {
    // Lấy rank kỳ hiện tại
    $rankStmt = $db->prepare(
        "SELECT v.series_id, v.reader_votes, v.rank_position, v.created_at,
                s.title AS series_title, s.genre, s.publish_schedule, s.cover_image, s.status AS series_status,
                u.username AS mangaka_name
         FROM votes v
         JOIN series s ON s.id = v.series_id
         JOIN users u ON u.id = s.mangaka_id
         WHERE v.vote_period = ?
           AND v.vote_period NOT LIKE '%-board-%'
           AND v.vote_period NOT LIKE '%-CANCEL-%'
         ORDER BY v.rank_position ASC"
    );
    $rankStmt->execute([$selectedPeriod]);
    $rankingData = $rankStmt->fetchAll();

    // Lấy rank kỳ liền trước (để tính trend)
    if (!empty($rankingData) && !empty($existingPeriods)) {
        $prevPeriod = null;
        $foundCurrent = false;
        foreach ($existingPeriods as $ep) {
            if ($foundCurrent) { $prevPeriod = $ep['vote_period']; break; }
            if ($ep['vote_period'] === $selectedPeriod) $foundCurrent = true;
        }
        if ($prevPeriod) {
            $prevStmt = $db->prepare(
                "SELECT series_id, rank_position FROM votes WHERE vote_period = ?"
            );
            $prevStmt->execute([$prevPeriod]);
            foreach ($prevStmt->fetchAll() as $pr) {
                $prevRankData[$pr['series_id']] = (int)$pr['rank_position'];
            }
        }
    }
}

/* ══════════════════════════════════════════════════
   4. THỐNG KÊ TỔNG KẾT
   ══════════════════════════════════════════════════ */
$statsStmt = $db->query(
    "SELECT COUNT(DISTINCT vote_period) AS total_periods,
            COUNT(DISTINCT series_id)   AS total_series,
            MAX(reader_votes)           AS max_votes
     FROM votes
     WHERE vote_period NOT LIKE '%-board-%'
       AND vote_period NOT LIKE '%-CANCEL-%'"
);
$stats = $statsStmt->fetch();

/* Trend helper: positive = improved (rank number went down) */
function getTrend(int $seriesId, int $currentRank, array $prevRanks): string {
    if (!isset($prevRanks[$seriesId])) return 'new';
    $prev = $prevRanks[$seriesId];
    $diff = $prev - $currentRank; // positive = improved
    if ($diff > 0) return 'up_' . $diff;
    if ($diff < 0) return 'down_' . abs($diff);
    return 'same';
}

$totalInPeriod = count($rankingData);
$dangerZone    = $totalInPeriod > 0 ? max(1, (int)ceil($totalInPeriod * 0.75)) : 99;

// Default next period values
$nowWeek  = (int)date('W');
$nowMonth = (int)date('n');
$nowYear  = (int)date('Y');
?>

<style>
/* ─────── Ranking Page ─────── */
.rank-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 22px;
    align-items: start;
}
.rank-main { min-width: 0; }
.rank-sidebar { position: sticky; top: calc(var(--header-h, 64px) + 20px); }

/* Rank table */
.rank-table-wrap { overflow-x: auto; }
.rank-table { width: 100%; border-collapse: collapse; }
.rank-table thead tr { border-bottom: 1px solid var(--border); }
.rank-table th {
    padding: 12px 16px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    white-space: nowrap;
}
.rank-table tbody tr {
    border-bottom: 1px solid rgba(255,255,255,0.03);
    transition: background 0.15s;
}
.rank-table tbody tr:hover { background: rgba(255,255,255,0.025); }
.rank-table td { padding: 12px 16px; vertical-align: middle; }

/* Rank badge */
.rank-badge {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 800;
    flex-shrink: 0;
}
.rank-1 { background: linear-gradient(135deg, #f59e0b, #d97706); color: #0b0b16; box-shadow: 0 0 12px rgba(245,158,11,.4); }
.rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
.rank-3 { background: linear-gradient(135deg, #c47a3f, #92400e); color: #fff; }
.rank-n { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-muted); }
.rank-danger { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3); color: #fca5a5 !important; }

/* Trend indicators */
.trend { display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; font-weight: 700; }
.trend-up   { color: #10b981; }
.trend-down { color: #ef4444; }
.trend-same { color: var(--text-muted); }
.trend-new  { color: #6366f1; }

/* Vote bars */
.vote-bar-wrap {
    display: flex; align-items: center; gap: 10px;
    min-width: 160px;
}
.vote-bar-track {
    flex: 1; height: 6px;
    background: rgba(255,255,255,0.06);
    border-radius: 3px; overflow: hidden;
}
.vote-bar-fill {
    height: 100%; border-radius: 3px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    transition: width 0.6s ease;
}
.vote-bar-fill.gold   { background: linear-gradient(90deg, #f59e0b, #d97706); }
.vote-bar-fill.silver { background: linear-gradient(90deg, #94a3b8, #64748b); }
.vote-bar-fill.bronze { background: linear-gradient(90deg, #c47a3f, #92400e); }
.vote-bar-fill.danger { background: linear-gradient(90deg, #ef4444, #dc2626); }

/* Danger zone indicator */
.danger-zone-row td { background: rgba(239,68,68,0.04) !important; }
.danger-label {
    font-size: 0.65rem; font-weight: 800;
    background: rgba(239,68,68,.15); color: #fca5a5;
    border: 1px solid rgba(239,68,68,.3);
    border-radius: 4px; padding: 2px 6px;
    text-transform: uppercase; letter-spacing: .04em;
}

/* Input form */
.vote-input-table { width: 100%; border-collapse: collapse; }
.vote-input-table th {
    padding: 10px 14px;
    font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted); text-align: left;
    border-bottom: 1px solid var(--border);
}
.vote-input-table tr { border-bottom: 1px solid rgba(255,255,255,0.04); }
.vote-input-table td { padding: 10px 14px; vertical-align: middle; }
.vote-input-table tr:hover td { background: rgba(255,255,255,0.02); }

.vote-num-input {
    width: 110px;
    padding: 7px 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 7px;
    color: var(--text-primary);
    font-size: 0.88rem;
    font-weight: 600;
    text-align: right;
    transition: border-color 0.2s;
    outline: none;
}
.vote-num-input:focus { border-color: var(--accent-primary); box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }

/* Period selector */
.period-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 16px;
}
.period-type-btn {
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-muted);
    font-size: 0.82rem; font-weight: 600;
    cursor: pointer;
    transition: all 0.18s;
    text-align: center;
}
.period-type-btn.active {
    border-color: var(--accent-primary);
    background: rgba(99,102,241,0.12);
    color: #a5b4fc;
}

/* Stats mini */
.stat-trio { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
.stat-box {
    padding: 14px 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    text-align: center;
}
.stat-box .n { font-size: 1.5rem; font-weight: 800; color: #a5b4fc; }
.stat-box .l { font-size: 0.68rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-top: 4px; }

/* Period history pills */
.period-pill {
    display: inline-flex; align-items: center;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-muted);
    font-size: 0.75rem; font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    white-space: nowrap;
}
.period-pill:hover { border-color: rgba(99,102,241,.4); color: var(--text-primary); }
.period-pill.current { border-color: var(--accent-primary); background: rgba(99,102,241,.1); color: #a5b4fc; }

/* Empty state */
.empty-rank {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}
.empty-rank svg { opacity: 0.2; margin-bottom: 16px; }

/* Tabs */
.tab-row-sm {
    display: flex; gap: 4px;
    padding: 4px;
    background: var(--bg-input);
    border-radius: 8px;
    margin-bottom: 18px;
}
.tab-sm { flex:1; padding:8px 10px; border-radius:6px; border:none; background:transparent;
    color:var(--text-muted); font-size:.8rem; font-weight:600; cursor:pointer; transition:all .18s; }
.tab-sm.active { background:var(--bg-card); color:var(--text-primary); box-shadow:0 1px 4px rgba(0,0,0,.3); }
.tab-panel-sm { display:none; }
.tab-panel-sm.active { display:block; }

@media (max-width: 1024px) {
    .rank-layout { grid-template-columns: 1fr; }
    .rank-sidebar { position: static; }
}
@keyframes slideInRow { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.rank-table tbody tr { animation: slideInRow .25s ease both; }
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>board/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Xếp hạng tổng quát</span>
    </div>
    <h1>Xếp Hạng & Bình Chọn Bộ Truyện</h1>
    <p>Nhập kết quả bình chọn của độc giả theo từng kỳ, theo dõi xu hướng và phát hiện sớm các tác phẩm đang suy giảm.</p>
</div>

<!-- Flash -->
<div id="flashMsg" style="display:none; margin-bottom:16px;"></div>

<!-- Stats -->
<div class="stat-trio">
    <div class="stat-box">
        <div class="n"><?= (int)($stats['total_periods'] ?? 0) ?></div>
        <div class="l">Số kỳ đã nhập</div>
    </div>
    <div class="stat-box">
        <div class="n"><?= count($publishingSeries) ?></div>
        <div class="l">Series xuất bản</div>
    </div>
    <div class="stat-box">
        <div class="n"><?= number_format((int)($stats['max_votes'] ?? 0)) ?></div>
        <div class="l">Votes cao nhất</div>
    </div>
</div>

<div class="rank-layout">

    <!-- ■ MAIN: Bảng xếp hạng -->
    <div class="rank-main">
        <div class="card" style="padding:0; overflow:hidden;">

            <!-- Header + period pills -->
            <div style="padding:18px 20px; border-bottom:1px solid var(--border);">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h3 style="margin:0; font-size:1rem; font-weight:700;">
                            Bảng Xếp Hạng
                            <?php if ($selectedPeriod): ?>
                                <span style="color:#a5b4fc; font-size:0.82rem; font-weight:500; margin-left:8px;">Kỳ: <?= htmlspecialchars($selectedPeriod) ?></span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($totalInPeriod > 0 && $dangerZone <= $totalInPeriod): ?>
                            <p style="font-size:0.75rem; color:var(--text-muted); margin:4px 0 0;">
                                <span style="color:#fca5a5;">⚠ Vùng nguy hiểm:</span> Hạng từ <?= $dangerZone ?> trở xuống
                            </p>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px; max-width:380px;">
                        <?php foreach (array_slice($existingPeriods, 0, 8) as $ep): ?>
                            <a href="?period=<?= urlencode($ep['vote_period']) ?>"
                               class="period-pill <?= $ep['vote_period'] === $selectedPeriod ? 'current' : '' ?>">
                                <?= htmlspecialchars($ep['vote_period']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($existingPeriods) > 8): ?>
                            <span style="font-size:0.72rem; color:var(--text-muted); align-self:center;">+<?= count($existingPeriods)-8 ?> kỳ khác</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($rankingData)): ?>
                <div class="empty-rank">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path d="M18 20V10M12 20V4M6 20v-6"/>
                    </svg>
                    <h3 style="color:var(--text-secondary); font-size:1rem; margin:0 0 8px;">Chưa có dữ liệu xếp hạng</h3>
                    <p style="font-size:0.85rem; max-width:340px; margin:0 auto; line-height:1.6;">
                        <?= empty($existingPeriods) ? 'Sử dụng form bên phải để nhập kết quả bình chọn kỳ đầu tiên.' : 'Chọn một kỳ ở trên để xem bảng xếp hạng.' ?>
                    </p>
                </div>
            <?php else: ?>
                <?php
                $maxVotes = max(array_column($rankingData, 'reader_votes')) ?: 1;
                ?>
                <div class="rank-table-wrap">
                    <table class="rank-table">
                        <thead>
                            <tr>
                                <th style="width:54px; text-align:center;">Hạng</th>
                                <th>Bộ truyện</th>
                                <th>Họa sĩ</th>
                                <th>Lịch XB</th>
                                <th>Votes</th>
                                <th style="text-align:center;">Trend</th>
                                <th style="text-align:center;">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rankingData as $idx => $row):
                            $rank     = (int)$row['rank_position'];
                            $isDanger = ($rank >= $dangerZone) && ($totalInPeriod >= 3);
                            $trend    = getTrend($row['series_id'], $rank, $prevRankData);
                            $pct      = $maxVotes > 0 ? round(($row['reader_votes'] / $maxVotes) * 100) : 0;
                            $barClass = match($rank) { 1 => 'gold', 2 => 'silver', 3 => 'bronze', default => ($isDanger ? 'danger' : '') };
                            $badgeClass = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => ($isDanger ? 'rank-danger' : 'rank-n') };
                        ?>
                            <tr class="<?= $isDanger ? 'danger-zone-row' : '' ?>"
                                style="animation-delay: <?= $idx * 0.04 ?>s">
                                <td style="text-align:center;">
                                    <div class="rank-badge <?= $badgeClass ?>">
                                        <?php if ($rank === 1): ?>🥇
                                        <?php elseif ($rank === 2): ?>🥈
                                        <?php elseif ($rank === 3): ?>🥉
                                        <?php else: ?><?= $rank ?><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:700; font-size:0.9rem; color:#fff;"><?= htmlspecialchars($row['series_title']) ?></div>
                                    <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($row['genre'] ?? '', 0, 40, '...')) ?></div>
                                </td>
                                <td style="font-size:0.83rem; color:var(--text-secondary);"><?= htmlspecialchars($row['mangaka_name']) ?></td>
                                <td>
                                    <span style="font-size:0.78rem; color:<?= $row['publish_schedule'] === 'weekly' ? '#6ee7b7' : '#93c5fd' ?>;">
                                        <?= $row['publish_schedule'] === 'weekly' ? '📅 Tuần' : '🗓️ Tháng' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="vote-bar-wrap">
                                        <div class="vote-bar-track">
                                            <div class="vote-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <span style="font-size:0.8rem; font-weight:700; min-width:48px; text-align:right; color:#fff;">
                                            <?= number_format($row['reader_votes']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    if ($trend === 'new') {
                                        echo '<span class="trend trend-new">✦ Mới</span>';
                                    } elseif ($trend === 'same') {
                                        echo '<span class="trend trend-same">— Giữ</span>';
                                    } elseif (str_starts_with($trend, 'up_')) {
                                        $diff = substr($trend, 3);
                                        echo "<span class=\"trend trend-up\">▲ +{$diff}</span>";
                                    } elseif (str_starts_with($trend, 'down_')) {
                                        $diff = substr($trend, 5);
                                        echo "<span class=\"trend trend-down\">▼ -{$diff}</span>";
                                    }
                                    ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($isDanger): ?>
                                        <span class="danger-label">⚠ Nguy hiểm</span>
                                    <?php else: ?>
                                        <span class="badge badge-green" style="font-size:.68rem; padding:2px 8px;">✓ An toàn</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalInPeriod > 0): ?>
                <div style="padding:12px 20px; border-top:1px solid var(--border); font-size:0.75rem; color:var(--text-muted); display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <span><span class="trend trend-up">▲</span> Tăng hạng</span>
                    <span><span class="trend trend-down">▼</span> Tụt hạng</span>
                    <span><span class="trend trend-same">—</span> Giữ nguyên</span>
                    <span><span class="trend trend-new">✦</span> Mới vào kỳ này</span>
                    <span style="margin-left:auto;"><span class="danger-label" style="font-size:.65rem;">⚠ Nguy hiểm</span> = hạng từ <?= $dangerZone ?> trở xuống (≥75% tổng)</span>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div><!-- /rank-main -->

    <!-- ■ SIDEBAR: Form nhập bình chọn -->
    <div class="rank-sidebar">
        <div class="card" style="padding:20px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2"><path d="M12 20V10M18 20V4M6 20v-6"/></svg>
                <h3 style="margin:0; font-size:0.95rem; font-weight:700;">Nhập Kết Quả Bình Chọn</h3>
            </div>

            <?php if (empty($publishingSeries)): ?>
                <div style="text-align:center; padding:30px 10px; color:var(--text-muted); font-size:0.85rem;">
                    <div style="font-size:2rem; margin-bottom:10px; opacity:0.4;">📭</div>
                    <p>Chưa có series nào đang xuất bản để nhập phiếu bình chọn.</p>
                </div>
            <?php else: ?>
                <!-- Chọn loại kỳ -->
                <label style="font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; display:block; margin-bottom:8px;">Loại kỳ phát hành</label>
                <div class="period-selector">
                    <button class="period-type-btn active" id="btnWeekPeriod" onclick="setPeriodType('week')">📅 Theo tuần</button>
                    <button class="period-type-btn" id="btnMonthPeriod" onclick="setPeriodType('month')">🗓️ Theo tháng</button>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px;" id="periodInputRow">
                    <div>
                        <label id="periodNumLabel" class="form-label" style="font-size:0.75rem;" for="periodNum">Số tuần</label>
                        <input type="number" id="periodNum" class="form-control" style="padding:8px 12px;"
                               min="1" max="53" value="<?= $nowWeek ?>" oninput="updatePeriodPreview()">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.75rem;" for="periodYear">Năm</label>
                        <input type="number" id="periodYear" class="form-control" style="padding:8px 12px;"
                               min="2020" max="2100" value="<?= $nowYear ?>" oninput="updatePeriodPreview()">
                    </div>
                </div>

                <div style="padding:8px 14px; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,.2); border-radius:8px; margin-bottom:16px; font-size:0.82rem; color:#a5b4fc;" id="periodPreview">
                    📌 Kỳ: <strong id="periodPreviewText"><?= $nowYear ?>-W<?= str_pad($nowWeek, 2, '0', STR_PAD_LEFT) ?></strong>
                </div>

                <!-- Table nhập votes -->
                <label style="font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; display:block; margin-bottom:8px;">Số votes từng series</label>
                <div style="max-height:320px; overflow-y:auto; border:1px solid var(--border); border-radius:8px; margin-bottom:16px;">
                    <table class="vote-input-table">
                        <thead>
                            <tr>
                                <th>Bộ truyện</th>
                                <th style="text-align:right;">Votes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($publishingSeries as $ps): ?>
                            <tr>
                                <td>
                                    <div style="font-size:0.82rem; font-weight:600; color:#fff; line-height:1.3;">
                                        <?= htmlspecialchars(mb_strimwidth($ps['title'], 0, 30, '…')) ?>
                                    </div>
                                    <div style="font-size:0.68rem; color:var(--text-muted);"><?= htmlspecialchars($ps['mangaka_name']) ?></div>
                                </td>
                                <td style="text-align:right;">
                                    <input type="number" class="vote-num-input series-vote-input"
                                           data-series-id="<?= $ps['id'] ?>"
                                           data-series-title="<?= htmlspecialchars($ps['title']) ?>"
                                           min="0" max="9999999"
                                           placeholder="0"
                                           value=""
                                           oninput="previewRank()">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Live rank preview -->
                <div id="rankPreview" style="display:none; margin-bottom:16px; padding:10px 14px; background:var(--bg-card); border:1px solid var(--border); border-radius:8px;">
                    <div style="font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Xem trước thứ hạng</div>
                    <div id="rankPreviewList" style="font-size:0.8rem; display:flex; flex-direction:column; gap:4px;"></div>
                </div>

                <button class="btn btn-primary" onclick="submitVotes()"
                        style="width:100%;" id="btnSubmitVotes">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><path d="M12 20V10M18 20V4M6 20v-6"/></svg>
                    Lưu kết quả bình chọn
                </button>
                <p style="font-size:0.72rem; color:var(--text-muted); margin:8px 0 0; text-align:center;">
                    Sẽ tự động gửi cảnh báo cho mangaka tụt hạng nguy hiểm
                </p>
            <?php endif; ?>
        </div>
    </div><!-- /rank-sidebar -->

</div><!-- /rank-layout -->

<script>
/* ── Period type toggle ── */
let periodType = 'week';

function setPeriodType(type) {
    periodType = type;
    document.getElementById('btnWeekPeriod').classList.toggle('active', type === 'week');
    document.getElementById('btnMonthPeriod').classList.toggle('active', type === 'month');

    const lbl = document.getElementById('periodNumLabel');
    const inp = document.getElementById('periodNum');
    if (type === 'week') {
        lbl.textContent = 'Số tuần';
        inp.max = 53;
        inp.value = <?= $nowWeek ?>;
    } else {
        lbl.textContent = 'Số tháng';
        inp.max = 12;
        inp.value = <?= $nowMonth ?>;
    }
    updatePeriodPreview();
}

function updatePeriodPreview() {
    const num  = String(document.getElementById('periodNum').value || '1').padStart(2, '0');
    const year = document.getElementById('periodYear').value || '<?= $nowYear ?>';
    const prefix = periodType === 'week' ? 'W' : 'M';
    document.getElementById('periodPreviewText').textContent = `${year}-${prefix}${num}`;
}

/* ── Live rank preview ── */
function previewRank() {
    const inputs = [...document.querySelectorAll('.series-vote-input')];
    const data = inputs
        .map(inp => ({ id: inp.dataset.seriesId, title: inp.dataset.seriesTitle, votes: parseInt(inp.value || '0') }))
        .filter(d => d.votes > 0);

    if (data.length === 0) {
        document.getElementById('rankPreview').style.display = 'none';
        return;
    }

    data.sort((a, b) => b.votes - a.votes);
    const listEl = document.getElementById('rankPreviewList');
    const medals = ['🥇','🥈','🥉'];
    listEl.innerHTML = data.map((d, i) =>
        `<div style="display:flex; justify-content:space-between; align-items:center;">
            <span>${medals[i] || '#' + (i+1)} ${d.title}</span>
            <span style="color:#a5b4fc; font-weight:700;">${d.votes.toLocaleString()}</span>
         </div>`
    ).join('');
    document.getElementById('rankPreview').style.display = 'block';
}

/* ── Submit votes ── */
function submitVotes() {
    const inputs = [...document.querySelectorAll('.series-vote-input')];
    const hasAny = inputs.some(inp => inp.value !== '' && parseInt(inp.value || '0') >= 0);

    if (!hasAny) {
        showFlash('Vui lòng nhập số votes cho ít nhất một series.', 'error');
        return;
    }

    const periodNum  = document.getElementById('periodNum').value;
    const periodYear = document.getElementById('periodYear').value;
    const periodPreview = document.getElementById('periodPreviewText').textContent;

    if (!confirm(`Xác nhận lưu kết quả bình chọn kỳ "${periodPreview}"?\n\nThao tác này không thể hoàn tác.`)) return;

    const votesData = inputs.map(inp => ({
        series_id:   parseInt(inp.dataset.seriesId),
        reader_votes: parseInt(inp.value || '0')
    }));

    const btn = document.getElementById('btnSubmitVotes');
    btn.disabled = true;
    btn.textContent = '⏳ Đang lưu...';

    const params = new URLSearchParams();
    params.append('action', 'submit_votes');
    params.append('period_type', periodType);
    params.append('period_num', periodNum);
    params.append('period_year', periodYear);
    params.append('votes_data', JSON.stringify(votesData));

    fetch(BASE_URL + 'api/ranking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showFlash(data.message + (data.notif_sent > 0 ? ` (${data.notif_sent} cảnh báo đã gửi)` : ''), 'success');
            setTimeout(() => {
                window.location.href = `ranking.php?period=${encodeURIComponent(data.period)}`;
            }, 1500);
        } else {
            showFlash(data.message || 'Lỗi không xác định.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><path d="M12 20V10M18 20V4M6 20v-6"/></svg> Lưu kết quả bình chọn';
        }
    })
    .catch(err => {
        showFlash('Lỗi kết nối máy chủ.', 'error');
        btn.disabled = false;
        btn.innerHTML = 'Lưu kết quả bình chọn';
    });
}

/* ── Flash ── */
function showFlash(msg, type) {
    const el = document.getElementById('flashMsg');
    const c = type === 'success'
        ? { bg:'rgba(16,185,129,.1)', border:'rgba(16,185,129,.3)', color:'#6ee7b7' }
        : { bg:'rgba(239,68,68,.1)',  border:'rgba(239,68,68,.3)',  color:'#fca5a5' };
    el.style.cssText = `display:flex; align-items:center; gap:10px; padding:14px 18px;
        background:${c.bg}; border:1px solid ${c.border}; border-radius:10px;
        color:${c.color}; font-size:.88rem; font-weight:600;`;
    el.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
    el.style.display = 'flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
