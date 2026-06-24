<?php
require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Dashboard';
$activePage   = 'dashboard';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. SERIES COUNT BY STATUS
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT status, COUNT(*) AS cnt
     FROM series
     WHERE mangaka_id = ?
     GROUP BY status"
);
$stmt->execute([$uid]);
$seriesRaw = $stmt->fetchAll();

$seriesByStatus = [
    'draft'      => 0,
    'submitted'  => 0,
    'approved'   => 0,
    'publishing' => 0,
    'cancelled'  => 0,
];
foreach ($seriesRaw as $row) {
    $seriesByStatus[$row['status']] = (int) $row['cnt'];
}
$totalSeries = array_sum($seriesByStatus);

/* ══════════════════════════════════════════════════
   2. CHAPTERS IN PROGRESS (with deadline countdown)
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT c.id, c.chapter_number, c.title, c.status, c.deadline,
            s.title AS series_title, s.id AS series_id
     FROM chapters c
     JOIN series s ON s.id = c.series_id
     WHERE s.mangaka_id = ?
       AND c.status IN ('planning','in_progress','review')
     ORDER BY c.deadline ASC
     LIMIT 5"
);
$stmt->execute([$uid]);
$activeChapters = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   3. TASKS ASSIGNED BY THIS MANGAKA (count by status)
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT t.status, COUNT(*) AS cnt
     FROM tasks t
     WHERE t.assigned_by = ?
       AND t.status NOT IN ('approved')
     GROUP BY t.status"
);
$stmt->execute([$uid]);
$taskRaw = $stmt->fetchAll();

$tasksByStatus = [
    'pending'    => 0,
    'in_progress'=> 0,
    'submitted'  => 0,
    'revision'   => 0,
];
foreach ($taskRaw as $row) {
    if (array_key_exists($row['status'], $tasksByStatus)) {
        $tasksByStatus[$row['status']] = (int) $row['cnt'];
    }
}
$totalOpenTasks = array_sum($tasksByStatus);

/* ══════════════════════════════════════════════════
   4. RANKING — top 3 publishing series (latest week)
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT v.series_id, v.vote_period, v.reader_votes, v.rank_position,
            s.title AS series_title
     FROM votes v
     JOIN series s ON s.id = v.series_id
     WHERE s.mangaka_id = ?
     ORDER BY v.created_at DESC, v.rank_position ASC
     LIMIT 3"
);
$stmt->execute([$uid]);
$topRankings = $stmt->fetchAll();

/* Vote history for chart — last 8 weeks for publishing series */
$stmt = $db->prepare(
    "SELECT v.vote_period, v.reader_votes, s.title AS series_title
     FROM votes v
     JOIN series s ON s.id = v.series_id
     WHERE s.mangaka_id = ? AND s.status = 'publishing'
     ORDER BY v.vote_period ASC
     LIMIT 24"
);
$stmt->execute([$uid]);
$voteHistory = $stmt->fetchAll();

// Build chart.js datasets
$chartLabels  = [];
$chartDatasets = [];
$seriesMap    = [];

foreach ($voteHistory as $row) {
    if (!in_array($row['vote_period'], $chartLabels)) {
        $chartLabels[] = $row['vote_period'];
    }
    $seriesMap[$row['series_title']][$row['vote_period']] = $row['reader_votes'];
}

$palette = ['#E63946', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'];
$i = 0;
foreach ($seriesMap as $title => $periodData) {
    $data = [];
    foreach ($chartLabels as $period) {
        $data[] = $periodData[$period] ?? null;
    }
    $color = $palette[$i % count($palette)];
    $chartDatasets[] = [
        'label'           => $title,
        'data'            => $data,
        'borderColor'     => $color,
        'backgroundColor' => $color . '22',
        'tension'         => 0.4,
        'fill'            => true,
        'pointRadius'     => 4,
        'pointHoverRadius'=> 7,
        'pointBackgroundColor' => $color,
    ];
    $i++;
}

/* ══════════════════════════════════════════════════
   5. NOTIFICATIONS (latest 5 for this user)
   ══════════════════════════════════════════════════ */
$stmt = $db->prepare(
    "SELECT id, type, message, is_read, link, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 5"
);
$stmt->execute([$uid]);
$latestNotifs = $stmt->fetchAll();

/* ══════════════════════════════════════════════════
   Helpers
   ══════════════════════════════════════════════════ */
function deadlineCountdown(?string $date): array {
    if (!$date) return ['label' => 'Không có hạn', 'class' => 'badge-gray', 'days' => null];
    $diff = (int) ceil((strtotime($date) - time()) / 86400);
    if ($diff < 0)  return ['label' => 'Đã quá hạn ' . abs($diff) . 'd', 'class' => 'badge-red',    'days' => $diff];
    if ($diff === 0) return ['label' => 'Hôm nay',                         'class' => 'badge-red',    'days' => 0];
    if ($diff <= 3) return ['label' => "Còn $diff ngày",                   'class' => 'badge-yellow', 'days' => $diff];
    return ['label' => "Còn $diff ngày",                                   'class' => 'badge-green',  'days' => $diff];
}

$statusLabels = [
    'planning'    => ['Lên kế hoạch', 'badge-gray'],
    'in_progress' => ['Đang vẽ',      'badge-blue'],
    'review'      => ['Chờ duyệt',    'badge-yellow'],
    'approved'    => ['Đã duyệt',     'badge-green'],
    'published'   => ['Đã xuất bản',  'badge-purple'],
];

$taskTypeLabels = [
    'pending'     => 'Chưa làm',
    'in_progress' => 'Đang làm',
    'submitted'   => 'Chờ duyệt',
    'revision'    => 'Cần sửa',
];

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Vừa xong';
    if ($diff < 3600)   return floor($diff / 60) . ' phút trước';
    if ($diff < 86400)  return floor($diff / 3600) . ' giờ trước';
    if ($diff < 604800) return floor($diff / 86400) . ' ngày trước';
    return date('d/m/Y', strtotime($datetime));
}

$notifTypeIcon = [
    'task_assigned'     => ['🎨', 'badge-purple'],
    'manuscript_review' => ['📋', 'badge-yellow'],
    'submission_result' => ['🏛️', 'badge-blue'],
    'ranking_update'    => ['📈', 'badge-green'],
    'default'           => ['🔔', 'badge-gray'],
];
?>

<!-- ── Stat cards grid (4 columns) ── -->
<div class="stat-grid" style="grid-template-columns: repeat(4,1fr); margin-bottom: 28px;">

    <!-- Total series -->
    <div class="stat-card" style="--accent:#8b5cf6;--icon-bg:rgba(139,92,246,.12)">
        <div class="stat-info">
            <p class="stat-label">Tổng bộ truyện</p>
            <p class="stat-value"><?= $totalSeries ?></p>
            <p class="stat-change">
                <span style="color:var(--green)"><?= $seriesByStatus['publishing'] ?> đang đăng</span>
                · <?= $seriesByStatus['draft'] ?> bản nháp
            </p>
        </div>
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
        </div>
    </div>

    <!-- Active chapters -->
    <div class="stat-card" style="--accent:#3b82f6;--icon-bg:rgba(59,130,246,.12)">
        <div class="stat-info">
            <p class="stat-label">Chương đang vẽ</p>
            <p class="stat-value"><?= count($activeChapters) ?></p>
            <p class="stat-change">
                <?php
                $overdue = array_filter($activeChapters, fn($c) => $c['deadline'] && strtotime($c['deadline']) < time());
                $n = count($overdue);
                ?>
                <?= $n > 0
                    ? "<span style='color:var(--red)'>$n chương quá hạn</span>"
                    : '<span style="color:var(--green)">Đúng tiến độ</span>'
                ?>
            </p>
        </div>
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
        </div>
    </div>

    <!-- Open tasks -->
    <div class="stat-card" style="--accent:var(--red);--icon-bg:var(--red-subtle)">
        <div class="stat-info">
            <p class="stat-label">Task chưa hoàn thành</p>
            <p class="stat-value"><?= $totalOpenTasks ?></p>
            <p class="stat-change">
                <?php if ($tasksByStatus['revision'] > 0): ?>
                    <span style="color:var(--red)"><?= $tasksByStatus['revision'] ?> cần sửa lại</span>
                <?php else: ?>
                    <span style="color:var(--green)"><?= $tasksByStatus['submitted'] ?> chờ bạn duyệt</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
        </div>
    </div>

    <!-- Best rank -->
    <div class="stat-card" style="--accent:#f59e0b;--icon-bg:rgba(245,158,11,.12)">
        <div class="stat-info">
            <p class="stat-label">Xếp hạng tốt nhất</p>
            <?php
            $bestRank = null;
            foreach ($topRankings as $r) {
                if ($r['rank_position'] !== null) {
                    if ($bestRank === null || $r['rank_position'] < $bestRank) {
                        $bestRank = $r['rank_position'];
                    }
                }
            }
            ?>
            <p class="stat-value"><?= $bestRank !== null ? '#' . $bestRank : 'N/A' ?></p>
            <p class="stat-change">Tuần gần nhất</p>
        </div>
        <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>
            </svg>
        </div>
    </div>
</div>

<!-- ── Row 2: Chapter countdown + Task status breakdown ── -->
<div class="grid-2 mb-24" style="grid-template-columns: 1.6fr 1fr;">

    <!-- Chapter deadline table -->
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Chương đang tiến hành</p>
                <p class="card-subtitle">Sắp xếp theo deadline gần nhất</p>
            </div>
            <a href="<?= BASE_URL ?>mangaka/series.php" class="btn btn-secondary btn-sm">Xem series</a>
        </div>

        <?php if (empty($activeChapters)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
                <p>Không có chương nào đang tiến hành.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Chương</th>
                        <th>Bộ truyện</th>
                        <th>Trạng thái</th>
                        <th>Deadline</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeChapters as $chap): ?>
                    <?php
                        [$statusLabel, $statusClass] = $statusLabels[$chap['status']] ?? ['Unknown', 'badge-gray'];
                        $countdown = deadlineCountdown($chap['deadline']);
                    ?>
                    <tr>
                        <td>
                            <span class="font-bold">Chương <?= $chap['chapter_number'] ?></span>
                            <div class="text-xs text-muted truncate" style="max-width:160px">
                                <?= htmlspecialchars($chap['title']) ?>
                            </div>
                        </td>
                        <td class="td-muted text-sm truncate" style="max-width:130px">
                            <?= htmlspecialchars($chap['series_title']) ?>
                        </td>
                        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                        <td>
                            <span class="badge <?= $countdown['class'] ?>">
                                <?= $countdown['label'] ?>
                            </span>
                            <?php if ($chap['deadline']): ?>
                                <div class="text-xs text-muted mt-8">
                                    <?= date('d/m/Y', strtotime($chap['deadline'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Task status breakdown -->
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Nhiệm vụ đang giao</p>
                <p class="card-subtitle">Trợ lý chưa hoàn thành</p>
            </div>
            <a href="<?= BASE_URL ?>mangaka/tasks.php" class="btn btn-secondary btn-sm">Quản lý</a>
        </div>

        <div style="display:flex;flex-direction:column;gap:14px;padding-top:4px;">
            <?php
            $taskColors = [
                'pending'     => ['#6b7280', '#374151'],
                'in_progress' => ['#3b82f6', 'rgba(59,130,246,.12)'],
                'submitted'   => ['#f59e0b', 'rgba(245,158,11,.12)'],
                'revision'    => ['#E63946', 'var(--red-subtle)'],
            ];
            $totalOpenTasksForPct = max(1, $totalOpenTasks);
            ?>
            <?php foreach ($tasksByStatus as $status => $count): ?>
            <?php [$color, $bg] = $taskColors[$status]; ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span class="text-sm" style="color:var(--text-muted)">
                        <?= $taskTypeLabels[$status] ?>
                    </span>
                    <span class="font-bold" style="font-size:.9rem;color:<?= $color ?>"><?= $count ?></span>
                </div>
                <div class="progress">
                    <div class="progress-bar"
                         style="width:<?= $totalOpenTasksForPct > 0 ? round($count / $totalOpenTasksForPct * 100) : 0 ?>%;background:<?= $color ?>">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($totalOpenTasks === 0): ?>
                <div style="text-align:center;padding:20px 0;color:var(--text-muted)">
                    <p>✅ Tất cả task đã hoàn thành!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 3: Ranking chart + Notifications ── -->
<div class="grid-2 mb-24" style="grid-template-columns: 1.6fr 1fr;">

    <!-- Vote/ranking line chart -->
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Biểu đồ bình chọn của độc giả</p>
                <p class="card-subtitle">Xu hướng theo từng kỳ phát hành</p>
            </div>
            <a href="<?= BASE_URL ?>mangaka/ranking.php" class="btn btn-secondary btn-sm">BXH đầy đủ</a>
        </div>

        <?php if (empty($chartLabels)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted)">
                <p>Chưa có dữ liệu bình chọn.</p>
            </div>
        <?php else: ?>
            <div style="position:relative;height:240px;">
                <canvas id="rankingChart"></canvas>
            </div>
        <?php endif; ?>

        <!-- Top 3 ranking table below chart -->
        <?php if (!empty($topRankings)): ?>
        <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
            <p class="text-xs text-muted" style="margin-bottom:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase">Top xếp hạng gần nhất</p>
            <?php foreach ($topRankings as $idx => $r): ?>
            <?php
                $medals = ['🥇','🥈','🥉'];
                $medal  = $medals[$idx] ?? "#{$r['rank_position']}";
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03)">
                <span style="font-size:1.3rem;width:28px;text-align:center"><?= $medal ?></span>
                <div style="flex:1;min-width:0">
                    <p class="text-sm font-bold truncate"><?= htmlspecialchars($r['series_title']) ?></p>
                    <p class="text-xs text-muted"><?= htmlspecialchars($r['vote_period']) ?></p>
                </div>
                <div style="text-align:right">
                    <p class="font-bold" style="font-size:.95rem;color:#f59e0b">
                        <?= number_format($r['reader_votes']) ?>
                    </p>
                    <p class="text-xs text-muted">phiếu bầu</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Notifications -->
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Thông báo mới nhất</p>
                <p class="card-subtitle">5 thông báo gần đây</p>
            </div>
            <?php if ($unreadCount > 0): ?>
                <span class="badge badge-red"><?= $unreadCount ?> mới</span>
            <?php endif; ?>
        </div>

        <?php if (empty($latestNotifs)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
                <p>Không có thông báo nào.</p>
            </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:2px;">
            <?php foreach ($latestNotifs as $notif): ?>
            <?php
                [$icon, $iconClass] = $notifTypeIcon[$notif['type']] ?? $notifTypeIcon['default'];
                $link = !empty($notif['link'])
                    ? BASE_URL . ltrim($notif['link'], '/')
                    : '#';
            ?>
            <a href="<?= htmlspecialchars($link) ?>"
               style="display:flex;gap:10px;padding:10px 8px;border-radius:8px;transition:background .15s;text-decoration:none;<?= !$notif['is_read'] ? 'background:rgba(230,57,70,.04)' : '' ?>"
               onmouseover="this.style.background='var(--bg-hover)'"
               onmouseout="this.style.background='<?= !$notif['is_read'] ? 'rgba(230,57,70,.04)' : '' ?>'">

                <div style="font-size:1.2rem;flex-shrink:0;margin-top:1px"><?= $icon ?></div>
                <div style="flex:1;min-width:0">
                    <p class="text-sm" style="line-height:1.5;<?= !$notif['is_read'] ? 'color:var(--text)' : 'color:var(--text-muted)' ?>">
                        <?= htmlspecialchars($notif['message']) ?>
                    </p>
                    <p class="text-xs text-muted mt-8">
                        <?= timeAgo($notif['created_at']) ?>
                        <?php if (!$notif['is_read']): ?>
                            <span style="display:inline-block;width:6px;height:6px;background:var(--red);border-radius:50%;margin-left:6px;vertical-align:middle"></span>
                        <?php endif; ?>
                    </p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Chart.js ── -->
<?php if (!empty($chartLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels   = <?= json_encode($chartLabels) ?>;
    const datasets = <?= json_encode($chartDatasets) ?>;

    const ctx = document.getElementById('rankingChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: {
                        color: '#9090a8',
                        font: { family: 'Inter', size: 12 },
                        boxWidth: 12,
                        boxHeight: 12,
                        borderRadius: 3,
                        useBorderRadius: true,
                    }
                },
                tooltip: {
                    backgroundColor: '#1e2547',
                    titleColor: '#f0f0f8',
                    bodyColor: '#9090a8',
                    borderColor: 'rgba(255,255,255,.08)',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y?.toLocaleString('vi-VN') ?? 'N/A'} phiếu`
                    }
                }
            },
            scales: {
                x: {
                    grid:  { color: 'rgba(255,255,255,.04)' },
                    ticks: { color: '#6b6b7a', font: { family: 'Inter', size: 11 } }
                },
                y: {
                    grid:  { color: 'rgba(255,255,255,.04)' },
                    ticks: {
                        color: '#6b6b7a',
                        font:  { family: 'Inter', size: 11 },
                        callback: v => v >= 1000 ? (v / 1000).toFixed(1) + 'K' : v
                    },
                    beginAtZero: true
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
