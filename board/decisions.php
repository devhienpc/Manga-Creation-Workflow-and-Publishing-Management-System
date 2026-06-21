<?php
/**
 * board/decisions.php
 * Trang quyết định xuất bản của Ban biên tập:
 * - Danh sách series đang xuất bản + ranking hiện tại
 * - Nút HUỶ SERIES (confirm modal)
 * - Nút ĐỔI LỊCH weekly ↔ monthly
 * - Lịch sử quyết định (log từ votes + submissions)
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Quyết định xuất bản';
$activePage   = 'decisions';
$allowedRoles = [ROLES['BOARD']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. SERIES ĐANG XUẤT BẢN (publishing)
   ══════════════════════════════════════════════════ */
$publishingStmt = $db->prepare(
    "SELECT s.id, s.title, s.genre, s.description, s.publish_schedule,
            s.cover_image, s.status, s.created_at,
            u.id AS mangaka_id, u.username AS mangaka_name,
            -- Rank hiện tại (kỳ gần nhất)
            v_latest.rank_position AS latest_rank,
            v_latest.reader_votes  AS latest_votes,
            v_latest.vote_period   AS latest_period,
            -- Rank kỳ trước
            v_prev.rank_position   AS prev_rank
     FROM series s
     JOIN users u ON u.id = s.mangaka_id
     LEFT JOIN (
         SELECT v1.series_id, v1.rank_position, v1.reader_votes, v1.vote_period
         FROM votes v1
         WHERE v1.vote_period NOT LIKE '%-board-%'
           AND v1.vote_period NOT LIKE '%-CANCEL-%'
           AND v1.created_at = (
             SELECT MAX(v2.created_at) FROM votes v2
             WHERE v2.series_id = v1.series_id
               AND v2.vote_period NOT LIKE '%-board-%'
               AND v2.vote_period NOT LIKE '%-CANCEL-%'
           )
     ) v_latest ON v_latest.series_id = s.id
     LEFT JOIN (
         SELECT v3.series_id, v3.rank_position
         FROM votes v3
         WHERE v3.vote_period NOT LIKE '%-board-%'
           AND v3.vote_period NOT LIKE '%-CANCEL-%'
           AND v3.created_at = (
             SELECT MAX(v4.created_at) FROM votes v4
             WHERE v4.series_id = v3.series_id
               AND v4.vote_period NOT LIKE '%-board-%'
               AND v4.vote_period NOT LIKE '%-CANCEL-%'
               AND v4.created_at < (
                 SELECT MAX(v5.created_at) FROM votes v5
                 WHERE v5.series_id = v3.series_id
                   AND v5.vote_period NOT LIKE '%-board-%'
                   AND v5.vote_period NOT LIKE '%-CANCEL-%'
               )
           )
     ) v_prev ON v_prev.series_id = s.id
     WHERE s.status = 'publishing'
     ORDER BY COALESCE(v_latest.rank_position, 9999) ASC, s.title ASC"
);
$publishingStmt->execute();
$publishingSeries = $publishingStmt->fetchAll();

/* ══════════════════════════════════════════════════
   2. SERIES VỪA ĐƯỢC DUYỆT (approved nhưng chưa publishing)
   ══════════════════════════════════════════════════ */
$approvedStmt = $db->prepare(
    "SELECT s.id, s.title, s.genre, s.publish_schedule, s.status, s.created_at,
            u.username AS mangaka_name,
            sb.submitted_at AS decision_date, sb.board_notes
     FROM series s
     JOIN users u ON u.id = s.mangaka_id
     LEFT JOIN submissions sb ON sb.series_id = s.id AND sb.status = 'approved'
     WHERE s.status = 'approved'
     ORDER BY sb.submitted_at DESC"
);
$approvedStmt->execute();
$approvedSeries = $approvedStmt->fetchAll();

/* ══════════════════════════════════════════════════
   3. LỊCH SỬ QUYẾT ĐỊNH
   (submissions + cancel logs từ votes)
   ══════════════════════════════════════════════════ */
$historyStmt = $db->prepare(
    "SELECT 'submission' AS log_type,
            sb.id AS log_id,
            sb.status AS action,
            sb.submitted_at AS action_date,
            sb.board_notes AS notes,
            s.title AS series_title,
            s.publish_schedule,
            u_m.username AS mangaka_name,
            u_b.username AS decided_by
     FROM submissions sb
     JOIN series s ON s.id = sb.series_id
     JOIN users u_m ON u_m.id = s.mangaka_id
     JOIN users u_b ON u_b.id = sb.submitted_by
     WHERE sb.status IN ('approved','rejected')

     UNION ALL

     SELECT 'schedule_change' AS log_type,
            v.id AS log_id,
            'schedule_change' AS action,
            v.created_at AS action_date,
            CONCAT('Lịch XB: ', s.publish_schedule) AS notes,
            s.title AS series_title,
            s.publish_schedule,
            u_m.username AS mangaka_name,
            u_e.username AS decided_by
     FROM votes v
     JOIN series s ON s.id = v.series_id
     JOIN users u_m ON u_m.id = s.mangaka_id
     JOIN users u_e ON u_e.id = v.entered_by
     WHERE v.vote_period LIKE '%-CANCEL-%'

     ORDER BY action_date DESC
     LIMIT 40"
);
$historyStmt->execute();
$historyLogs = $historyStmt->fetchAll();

/* ══════════════════════════════════════════════════
   4. THỐNG KÊ NHANH
   ══════════════════════════════════════════════════ */
$quickStats = $db->query(
    "SELECT
        SUM(CASE WHEN status='publishing' THEN 1 ELSE 0 END) AS cnt_pub,
        SUM(CASE WHEN status='cancelled'  THEN 1 ELSE 0 END) AS cnt_cancel,
        SUM(CASE WHEN status='approved'   THEN 1 ELSE 0 END) AS cnt_approved
     FROM series"
)->fetch();

// Tổng số series đang publishing để tính ngưỡng nguy hiểm
$totalPub    = count($publishingSeries);
$dangerZone  = $totalPub > 0 ? (int)ceil($totalPub * 0.75) : 99;
?>

<style>
/* ─────── Decisions Page ─────── */
.decisions-layout {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Tabs */
.tab-row { display:flex; gap:4px; padding:4px; background:var(--bg-input); border-radius:10px; margin-bottom:20px; }
.tab-btn { flex:1; padding:10px 12px; border-radius:8px; border:none; background:transparent;
           color:var(--text-muted); font-size:.83rem; font-weight:600; cursor:pointer; transition:all .2s; text-align:center; }
.tab-btn.active { background:var(--bg-card); color:var(--text-primary); box-shadow:0 2px 6px rgba(0,0,0,.3); }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

/* Series card grid */
.series-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
}
.series-decision-card {
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}
.series-decision-card:hover {
    border-color: rgba(99,102,241,0.35);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}
.series-decision-card.danger-card {
    border-color: rgba(239,68,68,0.3);
    background: linear-gradient(135deg, rgba(239,68,68,0.04), var(--bg-input));
}

.card-top-bar {
    height: 4px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}
.card-top-bar.danger { background: linear-gradient(90deg, #ef4444, #f97316); }
.card-top-bar.gold { background: linear-gradient(90deg, #f59e0b, #d97706); }

.sdc-body { padding: 18px; }
.sdc-title { font-size: 1rem; font-weight: 800; color: #fff; margin-bottom: 4px; line-height: 1.3; }
.sdc-meta { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
.sdc-meta span { font-size: 0.75rem; color: var(--text-muted); }

.sdc-rank-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8rem; font-weight: 700;
    border: 1px solid var(--border);
    background: var(--bg-card);
}
.sdc-rank-badge.rank-top { border-color: rgba(245,158,11,.4); color: #fcd34d; background: rgba(245,158,11,.08); }
.sdc-rank-badge.rank-danger { border-color: rgba(239,68,68,.4); color: #fca5a5; background: rgba(239,68,68,.08); }
.sdc-rank-badge.rank-normal { color: var(--text-secondary); }
.sdc-rank-badge.rank-none { color: var(--text-muted); font-style:italic; }

/* Action buttons in card */
.sdc-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    padding: 12px 18px;
    border-top: 1px solid var(--border);
    background: rgba(0,0,0,0.15);
}
.btn-cancel-series {
    padding: 9px 10px;
    border-radius: 8px;
    border: 1px solid rgba(239,68,68,.35);
    background: rgba(239,68,68,.08);
    color: #fca5a5;
    font-size: 0.78rem; font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    text-align: center;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.btn-cancel-series:hover {
    background: rgba(239,68,68,.18);
    border-color: rgba(239,68,68,.6);
    color: #ef4444;
    transform: none;
}
.btn-change-schedule {
    padding: 9px 10px;
    border-radius: 8px;
    border: 1px solid rgba(99,102,241,.3);
    background: rgba(99,102,241,.08);
    color: #a5b4fc;
    font-size: 0.78rem; font-weight: 700;
    cursor: pointer;
    transition: all 0.18s;
    text-align: center;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.btn-change-schedule:hover {
    background: rgba(99,102,241,.18);
    border-color: rgba(99,102,241,.55);
    color: #818cf8;
}

/* Trend arrow */
.trend-arrow { font-size: 0.72rem; font-weight: 800; }
.trend-up   { color: #10b981; }
.trend-down { color: #ef4444; }
.trend-same { color: var(--text-muted); }

/* Cancel modal */
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:1000;
                  align-items:center; justify-content:center; backdrop-filter:blur(4px); }
.modal-backdrop.open { display:flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 28px;
    max-width: 460px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
    animation: slideUp .25s ease;
}
@keyframes slideUp { from{transform:translateY(16px); opacity:0} to{transform:translateY(0); opacity:1} }
.modal-title {
    font-size: 1.1rem; font-weight: 800; color: #fff;
    margin-bottom: 8px; display: flex; align-items: center; gap: 10px;
}
.modal-series-name {
    color: #ef4444; font-style:italic;
}

/* History log */
.log-action-approve  { color:#10b981; background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.25); }
.log-action-rejected { color:#ef4444; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.2); }
.log-action-cancel   { color:#f97316; background:rgba(249,115,22,.08); border:1px solid rgba(249,115,22,.2); }
.log-action-schedule { color:#a5b4fc; background:rgba(99,102,241,.1);  border:1px solid rgba(99,102,241,.25); }

/* Stats row */
.stat-trio { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.stat-box { padding:14px 12px; border-radius:10px; border:1px solid var(--border); background:var(--bg-input); text-align:center; }
.stat-box .n { font-size:1.6rem; font-weight:800; }
.stat-box .l { font-size:.68rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-top:4px; }

/* Empty state */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state svg { opacity:.2; margin-bottom:16px; display:block; margin-inline:auto; }

@media (max-width:768px) {
    .series-card-grid { grid-template-columns: 1fr; }
    .stat-trio { grid-template-columns: repeat(2,1fr); }
}
</style>

<!-- CANCEL SERIES MODAL -->
<div class="modal-backdrop" id="cancelModal">
    <div class="modal-box">
        <div class="modal-title">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Xác nhận Huỷ Xuất Bản
        </div>
        <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:16px; line-height:1.6;">
            Bạn sắp huỷ xuất bản bộ truyện:<br>
            <strong class="modal-series-name" id="cancelSeriesName">—</strong>
        </p>
        <div style="padding:12px 14px; background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,.25); border-radius:8px; font-size:0.8rem; color:#fca5a5; margin-bottom:16px;">
            ⚠️ Hành động này sẽ cập nhật trạng thái series thành <strong>CANCELLED</strong> và gửi thông báo đến Mangaka. Không thể hoàn tác tự động.
        </div>
        <div class="form-group" style="margin-bottom:20px;">
            <label class="form-label" style="font-size:0.8rem;" for="cancelReason">Lý do huỷ <span style="color:var(--text-muted);">(tuỳ chọn nhưng khuyến khích)</span></label>
            <textarea id="cancelReason" class="form-control" rows="3"
                      placeholder="Ví dụ: Lượt bình chọn giảm liên tục 3 kỳ, không đạt ngưỡng duy trì xuất bản..."></textarea>
        </div>
        <input type="hidden" id="cancelSeriesId" value="">
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeCancelModal()">Hủy bỏ</button>
            <button class="btn" id="btnConfirmCancel"
                    style="background:linear-gradient(135deg,#ef4444,#dc2626); border-color:#ef4444; color:#fff;"
                    onclick="confirmCancel()">
                ✗ Xác nhận Huỷ Series
            </button>
        </div>
    </div>
</div>

<!-- CHANGE SCHEDULE MODAL -->
<div class="modal-backdrop" id="scheduleModal">
    <div class="modal-box">
        <div class="modal-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Đổi Lịch Xuất Bản
        </div>
        <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:6px;">
            Bộ truyện: <strong style="color:#fff;" id="scheduleSeriesName">—</strong>
        </p>
        <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:16px;">
            Lịch hiện tại: <span id="scheduleCurrentLabel" style="color:#a5b4fc; font-weight:700;">—</span>
        </p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
            <div id="optWeeklyChange"
                 onclick="selectNewSchedule('weekly')"
                 style="padding:20px; border-radius:10px; border:2px solid var(--border); background:var(--bg-card); cursor:pointer; text-align:center; transition:all .18s;">
                <div style="font-size:1.8rem; margin-bottom:8px;">📅</div>
                <div style="font-weight:700; font-size:0.9rem; color:#fff;">Hàng tuần</div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">1 chương / tuần</div>
            </div>
            <div id="optMonthlyChange"
                 onclick="selectNewSchedule('monthly')"
                 style="padding:20px; border-radius:10px; border:2px solid var(--border); background:var(--bg-card); cursor:pointer; text-align:center; transition:all .18s;">
                <div style="font-size:1.8rem; margin-bottom:8px;">🗓️</div>
                <div style="font-weight:700; font-size:0.9rem; color:#fff;">Hàng tháng</div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">1 chương / tháng</div>
            </div>
        </div>
        <input type="hidden" id="scheduleSeriesId" value="">
        <input type="hidden" id="newScheduleVal" value="">
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeScheduleModal()">Hủy bỏ</button>
            <button class="btn btn-primary" id="btnConfirmSchedule" onclick="confirmScheduleChange()" disabled>
                📅 Xác nhận đổi lịch
            </button>
        </div>
    </div>
</div>

<!-- Page Header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>board/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Quyết định xuất bản</span>
    </div>
    <h1>Quyết Định Xuất Bản & Quản Trị Series</h1>
    <p>Quản lý toàn bộ series đang xuất bản: điều chỉnh lịch phát hành, huỷ bỏ khi cần thiết và theo dõi lịch sử quyết định.</p>
</div>

<!-- Flash -->
<div id="flashMsg" style="display:none; margin-bottom:16px;"></div>

<!-- Stats Row -->
<div class="stat-trio">
    <div class="stat-box">
        <div class="n" style="color:#10b981;"><?= (int)($quickStats['cnt_pub'] ?? 0) ?></div>
        <div class="l">Đang xuất bản</div>
    </div>
    <div class="stat-box">
        <div class="n" style="color:#a5b4fc;"><?= (int)($quickStats['cnt_approved'] ?? 0) ?></div>
        <div class="l">Vừa phê duyệt</div>
    </div>
    <div class="stat-box">
        <div class="n" style="color:#f97316;"><?= (int)($quickStats['cnt_cancel'] ?? 0) ?></div>
        <div class="l">Đã huỷ bỏ</div>
    </div>
</div>

<!-- Tabs -->
<div class="tab-row">
    <button class="tab-btn active" id="tabPublishing" onclick="switchTab('Publishing')">
        📡 Đang xuất bản
        <span style="background:rgba(16,185,129,.2); color:#6ee7b7; border-radius:10px; padding:1px 7px; font-size:.65rem; font-weight:800; margin-left:4px;"><?= count($publishingSeries) ?></span>
    </button>
    <button class="tab-btn" id="tabApproved" onclick="switchTab('Approved')">
        ✅ Vừa duyệt
        <?php if (!empty($approvedSeries)): ?>
            <span style="background:rgba(99,102,241,.2); color:#a5b4fc; border-radius:10px; padding:1px 7px; font-size:.65rem; font-weight:800; margin-left:4px;"><?= count($approvedSeries) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" id="tabHistory" onclick="switchTab('History')">
        📋 Lịch sử quyết định
        <span style="background:rgba(255,255,255,.08); border-radius:10px; padding:1px 7px; font-size:.65rem; font-weight:700; margin-left:4px;"><?= count($historyLogs) ?></span>
    </button>
</div>

<!-- ══ TAB 1: ĐANG XUẤT BẢN ══ -->
<div class="tab-panel active" id="panelPublishing">
    <?php if (empty($publishingSeries)): ?>
        <div class="empty-state">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <h3 style="color:var(--text-secondary); font-size:1rem; margin:0 0 8px;">Không có series nào đang xuất bản</h3>
            <p style="font-size:.85rem; max-width:380px; margin:0 auto;">Các series được Ban biên tập phê duyệt sẽ xuất hiện tại đây.</p>
        </div>
    <?php else: ?>
        <?php if ($totalPub >= 3): ?>
            <div style="display:flex; align-items:center; gap:10px; padding:10px 16px; background:rgba(239,68,68,.06); border:1px solid rgba(239,68,68,.2); border-radius:8px; margin-bottom:16px; font-size:0.8rem; color:#fca5a5;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Vùng nguy hiểm: Hạng từ <strong><?= $dangerZone ?></strong> trở xuống (≥75% tổng <?= $totalPub ?> series)</span>
            </div>
        <?php endif; ?>
        <div class="series-card-grid">
        <?php foreach ($publishingSeries as $idx => $s):
            $rank     = $s['latest_rank']  ? (int)$s['latest_rank']  : null;
            $prevRank = $s['prev_rank']     ? (int)$s['prev_rank']    : null;
            $isDanger = $rank !== null && $totalPub >= 3 && $rank >= $dangerZone;
            $isTop3   = $rank !== null && $rank <= 3;

            $rankDiff = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;
            $topBarClass = $isTop3 ? 'gold' : ($isDanger ? 'danger' : '');

            $newSched = $s['publish_schedule'] === 'weekly' ? 'monthly' : 'weekly';
            $newSchedLabel = $newSched === 'weekly' ? '📅 Đổi sang Tuần' : '🗓️ Đổi sang Tháng';
        ?>
            <div class="series-decision-card <?= $isDanger ? 'danger-card' : '' ?>" id="scard-<?= $s['id'] ?>">
                <div class="card-top-bar <?= $topBarClass ?>"></div>
                <div class="sdc-body">
                    <!-- Title + rank -->
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:8px;">
                        <div>
                            <div class="sdc-title"><?= htmlspecialchars($s['title']) ?></div>
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">🎨 <?= htmlspecialchars($s['mangaka_name']) ?></div>
                        </div>
                        <?php if ($rank !== null): ?>
                            <div class="sdc-rank-badge <?= $isTop3 ? 'rank-top' : ($isDanger ? 'rank-danger' : 'rank-normal') ?>">
                                <?php if ($rank === 1): ?>🥇
                                <?php elseif ($rank === 2): ?>🥈
                                <?php elseif ($rank === 3): ?>🥉
                                <?php elseif ($isDanger): ?>⚠️<?php else: ?>#<?= $rank ?><?php endif; ?>
                                <span style="font-size:0.72rem;"><?= $rank <= 3 ? "Top {$rank}" : "Hạng {$rank}" ?></span>
                            </div>
                        <?php else: ?>
                            <div class="sdc-rank-badge rank-none">Chưa có rank</div>
                        <?php endif; ?>
                    </div>

                    <!-- Meta -->
                    <div class="sdc-meta">
                        <span>
                            <?php $schedColor = $s['publish_schedule'] === 'weekly' ? '#6ee7b7' : '#93c5fd'; ?>
                            <span style="color:<?= $schedColor ?>; font-weight:700;">
                                <?= $s['publish_schedule'] === 'weekly' ? '📅 Tuần' : '🗓️ Tháng' ?>
                            </span>
                        </span>
                        <?php if ($s['latest_votes'] !== null): ?>
                            <span>🗳️ <?= number_format($s['latest_votes']) ?> votes</span>
                        <?php endif; ?>
                        <?php if ($s['latest_period']): ?>
                            <span>📌 Kỳ <?= htmlspecialchars($s['latest_period']) ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Trend -->
                    <?php if ($rankDiff !== null): ?>
                        <div style="font-size:0.75rem; margin-bottom:8px;">
                            <?php if ($rankDiff > 0): ?>
                                <span class="trend-arrow trend-up">▲ Tăng <?= $rankDiff ?> hạng</span> so với kỳ trước
                            <?php elseif ($rankDiff < 0): ?>
                                <span class="trend-arrow trend-down">▼ Tụt <?= abs($rankDiff) ?> hạng</span> so với kỳ trước
                                <?php if (abs($rankDiff) >= 2): ?> <span style="color:#f97316; font-size:.68rem; font-weight:700;">⚠ Giảm mạnh!</span><?php endif; ?>
                            <?php else: ?>
                                <span class="trend-arrow trend-same">— Giữ hạng</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($s['genre'])): ?>
                        <div style="font-size:0.72rem; color:var(--text-muted);">
                            <?= htmlspecialchars(mb_strimwidth($s['genre'], 0, 50, '…')) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Action buttons -->
                <div class="sdc-actions">
                    <button class="btn-cancel-series"
                            onclick="openCancelModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['title'])) ?>')"
                            title="Huỷ xuất bản bộ truyện này">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        Huỷ Series
                    </button>
                    <button class="btn-change-schedule"
                            onclick="openScheduleModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['title'])) ?>', '<?= $s['publish_schedule'] ?>')"
                            title="Đổi lịch xuất bản">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?= $newSchedLabel ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ══ TAB 2: VỪA ĐƯỢC DUYỆT ══ -->
<div class="tab-panel" id="panelApproved">
    <?php if (empty($approvedSeries)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p>Không có series nào ở trạng thái "Đã duyệt" đang chờ bắt đầu xuất bản.</p>
        </div>
    <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($approvedSeries as $s): ?>
            <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px;
                        border-radius:10px; border:1px solid var(--border); background:var(--bg-input); gap:16px; flex-wrap:wrap;">
                <div>
                    <div style="font-size:0.95rem; font-weight:700; color:#fff; margin-bottom:3px;">
                        <?= htmlspecialchars($s['title']) ?>
                        <span class="badge badge-green" style="font-size:.65rem; padding:2px 8px; margin-left:6px; vertical-align:middle;">✓ Đã duyệt</span>
                    </div>
                    <div style="font-size:0.78rem; color:var(--text-muted);">
                        🎨 <?= htmlspecialchars($s['mangaka_name']) ?>
                        &nbsp;·&nbsp;
                        <?= $s['publish_schedule'] === 'weekly' ? '📅 Lịch tuần' : '🗓️ Lịch tháng' ?>
                        <?php if ($s['decision_date']): ?>
                            &nbsp;·&nbsp; Duyệt: <?= date('d/m/Y', strtotime($s['decision_date'])) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($s['board_notes'])): ?>
                        <div style="margin-top:6px; font-size:0.75rem; color:#c7d2fe; max-width:500px;">
                            💬 <?= htmlspecialchars(mb_strimwidth($s['board_notes'], 0, 120, '…')) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:8px; flex-shrink:0;">
                    <button class="btn-change-schedule"
                            onclick="openScheduleModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['title'])) ?>', '<?= $s['publish_schedule'] ?>')"
                            style="padding:8px 14px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Đổi lịch
                    </button>
                    <button class="btn-cancel-series"
                            onclick="openCancelModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['title'])) ?>')"
                            style="padding:8px 14px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        Huỷ
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ══ TAB 3: LỊCH SỬ QUYẾT ĐỊNH ══ -->
<div class="tab-panel" id="panelHistory">
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:18px 22px; border-bottom:1px solid var(--border);">
            <h3 style="margin:0; font-size:1rem; font-weight:700;">Nhật ký Quyết định Ban biên tập</h3>
            <p style="margin:4px 0 0; font-size:.78rem; color:var(--text-muted);">Tất cả quyết định phê duyệt, từ chối, huỷ và đổi lịch được ghi lại tại đây.</p>
        </div>
        <?php if (empty($historyLogs)): ?>
            <div class="empty-state"><p>Chưa có quyết định nào được ghi lại.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border);">
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:left;">#</th>
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:left;">Bộ truyện</th>
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:left;">Họa sĩ</th>
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:center;">Hành động</th>
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:left;">Ghi chú</th>
                            <th style="padding:12px 18px; font-size:.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:left;">Ngày</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historyLogs as $i => $log): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.03); transition:background .15s;"
                            onmouseover="this.style.background='rgba(255,255,255,0.02)'"
                            onmouseout="this.style.background=''">
                            <td style="padding:12px 18px; font-size:.75rem; color:var(--text-muted);"><?= $i+1 ?></td>
                            <td style="padding:12px 18px; font-weight:700; font-size:.88rem; color:#fff;">
                                <?= htmlspecialchars($log['series_title']) ?>
                            </td>
                            <td style="padding:12px 18px; font-size:.83rem; color:var(--text-secondary);">
                                <?= htmlspecialchars($log['mangaka_name']) ?>
                            </td>
                            <td style="padding:12px 18px; text-align:center;">
                                <?php
                                $actClass = match($log['action']) {
                                    'approved'       => 'log-action-approve',
                                    'rejected'       => 'log-action-rejected',
                                    'schedule_change' => 'log-action-schedule',
                                    default           => 'log-action-cancel',
                                };
                                $actLabel = match($log['action']) {
                                    'approved'       => '✅ Phê duyệt',
                                    'rejected'       => '❌ Từ chối',
                                    'schedule_change' => '📅 Đổi lịch',
                                    default           => '🚫 Huỷ bỏ',
                                };
                                ?>
                                <span class="badge <?= $actClass ?>" style="font-size:.7rem; padding:3px 10px; white-space:nowrap;">
                                    <?= $actLabel ?>
                                </span>
                            </td>
                            <td style="padding:12px 18px; font-size:.78rem; color:var(--text-muted); max-width:220px;">
                                <?php if (!empty($log['notes'])): ?>
                                    <span title="<?= htmlspecialchars($log['notes']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($log['notes'], 0, 70, '…')) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="opacity:.4;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 18px; font-size:.78rem; color:var(--text-muted); white-space:nowrap;">
                                <?= date('d/m/Y H:i', strtotime($log['action_date'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
/* ── Tabs ── */
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('panel' + name).classList.add('active');
    document.getElementById('tab' + name).classList.add('active');
}

/* ── Cancel modal ── */
function openCancelModal(seriesId, seriesName) {
    document.getElementById('cancelSeriesId').value   = seriesId;
    document.getElementById('cancelSeriesName').textContent = seriesName;
    document.getElementById('cancelReason').value     = '';
    document.getElementById('cancelModal').classList.add('open');
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('open');
}
function confirmCancel() {
    const seriesId = document.getElementById('cancelSeriesId').value;
    const reason   = document.getElementById('cancelReason').value.trim();
    const btn      = document.getElementById('btnConfirmCancel');

    btn.disabled    = true;
    btn.textContent = '⏳ Đang xử lý...';

    const params = new URLSearchParams();
    params.append('action', 'cancel_series');
    params.append('series_id', seriesId);
    params.append('reason', reason);

    fetch(BASE_URL + 'api/ranking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        closeCancelModal();
        if (data.success) {
            showFlash(data.message, 'success');
            // Animate out card
            const card = document.getElementById('scard-' + seriesId);
            if (card) {
                card.style.transition = 'all 0.4s';
                card.style.opacity    = '0';
                card.style.transform  = 'scale(0.95)';
                setTimeout(() => card.remove(), 400);
            }
            // Update tab counter
            setTimeout(() => location.reload(), 1800);
        } else {
            showFlash(data.message || 'Lỗi không xác định.', 'error');
            btn.disabled    = false;
            btn.textContent = '✗ Xác nhận Huỷ Series';
        }
    })
    .catch(() => {
        closeCancelModal();
        showFlash('Lỗi kết nối máy chủ.', 'error');
        btn.disabled    = false;
        btn.textContent = '✗ Xác nhận Huỷ Series';
    });
}

/* ── Schedule modal ── */
let currentSchedule = '';

function openScheduleModal(seriesId, seriesName, currentSched) {
    currentSchedule = currentSched;
    document.getElementById('scheduleSeriesId').value       = seriesId;
    document.getElementById('scheduleSeriesName').textContent = seriesName;
    document.getElementById('scheduleCurrentLabel').textContent =
        currentSched === 'weekly' ? '📅 Hàng tuần' : '🗓️ Hàng tháng';
    document.getElementById('newScheduleVal').value  = '';
    document.getElementById('btnConfirmSchedule').disabled = true;

    // Reset styles
    document.getElementById('optWeeklyChange').style.borderColor  = 'var(--border)';
    document.getElementById('optMonthlyChange').style.borderColor = 'var(--border)';
    document.getElementById('optWeeklyChange').style.background   = 'var(--bg-card)';
    document.getElementById('optMonthlyChange').style.background  = 'var(--bg-card)';

    // Disable current one
    if (currentSched === 'weekly') {
        document.getElementById('optWeeklyChange').style.opacity  = '.4';
        document.getElementById('optWeeklyChange').style.cursor   = 'default';
        document.getElementById('optMonthlyChange').style.opacity = '1';
        document.getElementById('optMonthlyChange').style.cursor  = 'pointer';
    } else {
        document.getElementById('optMonthlyChange').style.opacity = '.4';
        document.getElementById('optMonthlyChange').style.cursor  = 'default';
        document.getElementById('optWeeklyChange').style.opacity  = '1';
        document.getElementById('optWeeklyChange').style.cursor   = 'pointer';
    }

    document.getElementById('scheduleModal').classList.add('open');
}
function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('open');
}
function selectNewSchedule(sched) {
    if (sched === currentSchedule) return; // cannot pick same
    document.getElementById('newScheduleVal').value = sched;
    document.getElementById('btnConfirmSchedule').disabled = false;

    const weekEl  = document.getElementById('optWeeklyChange');
    const monthEl = document.getElementById('optMonthlyChange');
    weekEl.style.borderColor  = sched === 'weekly'  ? '#6366f1' : 'var(--border)';
    monthEl.style.borderColor = sched === 'monthly' ? '#6366f1' : 'var(--border)';
    weekEl.style.background   = sched === 'weekly'  ? 'rgba(99,102,241,.1)' : 'var(--bg-card)';
    monthEl.style.background  = sched === 'monthly' ? 'rgba(99,102,241,.1)' : 'var(--bg-card)';
}
function confirmScheduleChange() {
    const seriesId   = document.getElementById('scheduleSeriesId').value;
    const newSched   = document.getElementById('newScheduleVal').value;
    const btn        = document.getElementById('btnConfirmSchedule');

    if (!newSched) return;

    btn.disabled    = true;
    btn.textContent = '⏳ Đang xử lý...';

    const params = new URLSearchParams();
    params.append('action', 'change_schedule');
    params.append('series_id', seriesId);
    params.append('new_schedule', newSched);

    fetch(BASE_URL + 'api/ranking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        closeScheduleModal();
        if (data.success) {
            showFlash(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showFlash(data.message || 'Lỗi không xác định.', 'error');
            btn.disabled    = false;
            btn.textContent = '📅 Xác nhận đổi lịch';
        }
    })
    .catch(() => {
        closeScheduleModal();
        showFlash('Lỗi kết nối máy chủ.', 'error');
        btn.disabled    = false;
        btn.textContent = '📅 Xác nhận đổi lịch';
    });
}

/* Close modals on backdrop click */
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});
document.getElementById('scheduleModal').addEventListener('click', function(e) {
    if (e.target === this) closeScheduleModal();
});

/* ── Flash ── */
function showFlash(msg, type) {
    const el = document.getElementById('flashMsg');
    const c  = type === 'success'
        ? { bg:'rgba(16,185,129,.1)', border:'rgba(16,185,129,.3)', color:'#6ee7b7' }
        : { bg:'rgba(239,68,68,.1)',  border:'rgba(239,68,68,.3)',  color:'#fca5a5' };
    el.style.cssText = `display:flex; align-items:center; gap:10px; padding:14px 18px;
        background:${c.bg}; border:1px solid ${c.border}; border-radius:10px;
        color:${c.color}; font-size:.88rem; font-weight:600;`;
    el.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* Escape key closes modals */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeCancelModal(); closeScheduleModal(); }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
