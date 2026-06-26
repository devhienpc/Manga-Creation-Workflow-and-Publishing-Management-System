<?php
/**
 * board/voting.php
 * Trang bỏ phiếu của Ban biên tập:
 * - Danh sách submissions đang chờ duyệt (pending)
 * - Xem thông tin series + đọc manuscript (PDF viewer / ảnh trang)
 * - Form bỏ phiếu: Approve/Reject, lịch xuất bản, ghi chú, ngày bắt đầu
 * - Submit → cập nhật submissions + series + gửi notification
 * - Lịch sử bỏ phiếu
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle = 'Bình chọn & Phê duyệt';
$activePage = 'voting';
$allowedRoles = [ROLES['BOARD']];
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();
$uid = $currentUser['id'];

/* ══════════════════════════════════════════════════
   1. LẤY DANH SÁCH SUBMISSIONS ĐANG PENDING
   ══════════════════════════════════════════════════ */
$pendingStmt = $db->prepare(
    "SELECT sb.id, sb.status, sb.board_notes, sb.submitted_at,
            s.id AS series_id, s.title AS series_title, s.genre, s.description,
            s.cover_image, s.publish_schedule, s.status AS series_status,
            m.id AS manuscript_id, m.file_path, m.version,
            c.chapter_number, c.title AS chapter_title,
            u_mangaka.id AS mangaka_id, u_mangaka.username AS mangaka_name,
            u_editor.username AS editor_name
     FROM submissions sb
     JOIN series s ON s.id = sb.series_id
     JOIN manuscripts m ON m.id = sb.manuscript_id
     JOIN chapters c ON c.id = m.chapter_id
     JOIN users u_mangaka ON u_mangaka.id = s.mangaka_id
     JOIN users u_editor ON u_editor.id = sb.submitted_by
     WHERE sb.status = 'pending'
     ORDER BY sb.submitted_at ASC"
);
$pendingStmt->execute();
$pendingSubmissions = $pendingStmt->fetchAll();

/* ══════════════════════════════════════════════════
   2. SUBMISSION ĐƯỢC CHỌN ĐỂ XEM CHI TIẾT
   ══════════════════════════════════════════════════ */
$selectedId = (int) ($_GET['id'] ?? 0);
$selectedSub = null;
$pages = [];

if ($selectedId > 0) {
    $detailStmt = $db->prepare(
        "SELECT sb.id, sb.status, sb.board_notes, sb.submitted_at,
                s.id AS series_id, s.title AS series_title, s.genre, s.description,
                s.cover_image, s.publish_schedule, s.status AS series_status,
                m.id AS manuscript_id, m.file_path, m.version, m.chapter_id,
                c.chapter_number, c.title AS chapter_title,
                u_mangaka.id AS mangaka_id, u_mangaka.username AS mangaka_name,
                u_mangaka.bio AS mangaka_bio,
                u_editor.username AS editor_name, u_editor.id AS editor_id
         FROM submissions sb
         JOIN series s ON s.id = sb.series_id
         JOIN manuscripts m ON m.id = sb.manuscript_id
         JOIN chapters c ON c.id = m.chapter_id
         JOIN users u_mangaka ON u_mangaka.id = s.mangaka_id
         JOIN users u_editor ON u_editor.id = sb.submitted_by
         WHERE sb.id = ?"
    );
    $detailStmt->execute([$selectedId]);
    $selectedSub = $detailStmt->fetch();

    if ($selectedSub && $selectedSub['chapter_id']) {
        $pagesStmt = $db->prepare(
            "SELECT id, page_number, original_file, composite_file, status
             FROM pages WHERE chapter_id = ? ORDER BY page_number ASC"
        );
        $pagesStmt->execute([$selectedSub['chapter_id']]);
        $pages = $pagesStmt->fetchAll();
    }
}

/* ══════════════════════════════════════════════════
   3. LỊCH SỬ BỎ PHIẾU (approved + rejected)
   ══════════════════════════════════════════════════ */
$historyStmt = $db->prepare(
    "SELECT sb.id, sb.status, sb.board_notes, sb.submitted_at,
            s.title AS series_title, s.publish_schedule,
            u_mangaka.username AS mangaka_name,
            u_editor.username AS editor_name
     FROM submissions sb
     JOIN series s ON s.id = sb.series_id
     JOIN users u_mangaka ON u_mangaka.id = s.mangaka_id
     JOIN users u_editor ON u_editor.id = sb.submitted_by
     WHERE sb.status IN ('approved','rejected')
     ORDER BY sb.submitted_at DESC
     LIMIT 30"
);
$historyStmt->execute();
$historyList = $historyStmt->fetchAll();

/* ══════════════════════════════════════════════════
   4. THỐNG KÊ TỔNG QUÁT
   ══════════════════════════════════════════════════ */
$statsStmt = $db->query(
    "SELECT
        SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) AS cnt_pending,
        SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS cnt_approved,
        SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS cnt_rejected
     FROM submissions"
);
$stats = $statsStmt->fetch();

$pendingCount = (int) ($stats['cnt_pending'] ?? 0);
$approvedCount = (int) ($stats['cnt_approved'] ?? 0);
$rejectedCount = (int) ($stats['cnt_rejected'] ?? 0);
?>

<style>
    /* ─────────── Voting Page Layout ─────────── */
    .voting-layout {
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 22px;
        align-items: start;
    }

    .voting-sidebar {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .voting-main {
        display: flex;
        flex-direction: column;
        gap: 20px;
        min-width: 0;
    }

    /* Submission Card */
    .sub-card {
        padding: 14px 16px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--bg-input);
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        text-decoration: none;
        display: block;
    }

    .sub-card:hover {
        border-color: rgba(99, 102, 241, 0.5);
        background: rgba(99, 102, 241, 0.05);
        transform: translateX(3px);
    }

    .sub-card.active {
        border-color: var(--accent-primary);
        background: rgba(99, 102, 241, 0.1);
        box-shadow: 0 0 0 1px var(--accent-primary);
    }

    .sub-card-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 4px;
        line-height: 1.35;
    }

    .sub-card-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 6px;
    }

    .sub-card-dot {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #f59e0b;
        box-shadow: 0 0 6px #f59e0b;
        animation: pulse-dot 1.8s ease-in-out infinite;
    }

    @keyframes pulse-dot {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.5;
            transform: scale(1.4);
        }
    }

    /* Stats row */
    .stats-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 4px;
    }

    .stat-mini {
        padding: 14px 16px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--bg-input);
        text-align: center;
    }

    .stat-mini .num {
        font-size: 1.7rem;
        font-weight: 800;
        line-height: 1;
    }

    .stat-mini .lbl {
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 4px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .stat-mini.pending .num {
        color: #f59e0b;
    }

    .stat-mini.approved .num {
        color: #10b981;
    }

    .stat-mini.rejected .num {
        color: #ef4444;
    }

    /* Manuscript viewer */
    .manuscript-viewer {
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #05050c;
        overflow: hidden;
    }

    .manuscript-viewer iframe {
        width: 100%;
        height: 680px;
        border: none;
        display: block;
    }

    .page-strip {
        display: flex;
        gap: 8px;
        padding: 12px;
        overflow-x: auto;
        background: rgba(0, 0, 0, 0.4);
        border-bottom: 1px solid var(--border);
    }

    .page-strip::-webkit-scrollbar {
        height: 4px;
    }

    .page-strip::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .15);
        border-radius: 4px;
    }

    .page-thumb {
        flex-shrink: 0;
        width: 56px;
        height: 80px;
        border-radius: 5px;
        border: 2px solid var(--border);
        background: var(--bg-card);
        cursor: pointer;
        overflow: hidden;
        position: relative;
        transition: all 0.15s;
    }

    .page-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .page-thumb:hover {
        border-color: rgba(99, 102, 241, .5);
    }

    .page-thumb.active {
        border-color: var(--accent-primary);
        box-shadow: 0 0 8px rgba(99, 102, 241, 0.4);
    }

    .page-thumb .pnum {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, .7);
        font-size: .6rem;
        text-align: center;
        padding: 2px 0;
        font-weight: 700;
    }

    .page-main-display {
        padding: 16px;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 500px;
    }

    .page-main-display img {
        max-width: 100%;
        max-height: 680px;
        object-fit: contain;
        border-radius: 6px;
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.5);
    }

    /* Series Info Card */
    .series-info-grid {
        display: grid;
        grid-template-columns: 100px 1fr;
        gap: 16px;
        align-items: start;
    }

    .series-cover {
        width: 100px;
        height: 140px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid var(--border);
        background: var(--bg-input);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }

    .series-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 8px;
    }

    .genre-tag {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: rgba(99, 102, 241, 0.15);
        color: #a5b4fc;
        border: 1px solid rgba(99, 102, 241, 0.25);
        margin: 2px;
    }

    /* Vote Form */
    .vote-form-card {
        padding: 24px;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: var(--bg-input);
    }

    .vote-choice-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-bottom: 20px;
    }

    .vote-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 18px 16px;
        border-radius: 10px;
        border: 2px solid transparent;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 700;
        transition: all 0.2s;
        background: var(--bg-card);
        color: var(--text-secondary);
        position: relative;
        outline: none;
        text-align: center;
    }

    .vote-btn svg {
        flex-shrink: 0;
    }

    .vote-btn.approve {
        border-color: rgba(16, 185, 129, 0.3);
        color: #6ee7b7;
    }

    .vote-btn.approve:hover,
    .vote-btn.approve.selected {
        background: rgba(16, 185, 129, 0.12);
        border-color: #10b981;
        color: #10b981;
        box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
        transform: translateY(-2px);
    }

    .vote-btn.reject {
        border-color: rgba(239, 68, 68, 0.3);
        color: #fca5a5;
    }

    .vote-btn.reject:hover,
    .vote-btn.reject.selected {
        background: rgba(239, 68, 68, 0.1);
        border-color: #ef4444;
        color: #ef4444;
        box-shadow: 0 0 20px rgba(239, 68, 68, 0.15);
        transform: translateY(-2px);
    }

    .vote-btn .check-mark {
        position: absolute;
        top: 8px;
        right: 10px;
        font-size: 0.7rem;
        display: none;
    }

    .vote-btn.selected .check-mark {
        display: block;
    }

    /* Schedule toggle */
    .schedule-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }

    .schedule-opt {
        padding: 12px 14px;
        border-radius: 8px;
        border: 2px solid var(--border);
        background: var(--bg-card);
        cursor: pointer;
        text-align: center;
        transition: all 0.18s;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
    }

    .schedule-opt:hover {
        border-color: rgba(99, 102, 241, 0.4);
        color: var(--text-primary);
    }

    .schedule-opt.selected {
        border-color: var(--accent-primary);
        background: rgba(99, 102, 241, 0.12);
        color: #a5b4fc;
    }

    .schedule-opt .icon {
        font-size: 1.4rem;
        display: block;
        margin-bottom: 4px;
    }

    /* Approve fields (conditional) */
    .approve-fields {
        display: none;
        animation: slideDown 0.25s ease;
    }

    .approve-fields.visible {
        display: block;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* History table */
    .history-status-approve {
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.25);
    }

    .history-status-reject {
        color: #ef4444;
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    /* No selection state */
    .no-selection-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 80px 40px;
        text-align: center;
        color: var(--text-muted);
        background: var(--bg-input);
        border-radius: 12px;
        border: 1px dashed var(--border);
        gap: 16px;
    }

    .no-selection-state svg {
        opacity: 0.3;
    }

    /* Loading spinner */
    .vote-loading {
        display: none;
    }

    .vote-loading.active {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Section heading */
    .section-heading {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--border);
    }

    .section-heading h3 {
        font-size: 1rem;
        font-weight: 700;
        color: #fff;
        margin: 0;
    }

    .section-heading .badge-count {
        background: rgba(99, 102, 241, 0.2);
        color: #a5b4fc;
        border-radius: 20px;
        padding: 2px 9px;
        font-size: 0.72rem;
        font-weight: 700;
    }

    /* Tabs */
    .tab-row {
        display: flex;
        gap: 4px;
        padding: 4px;
        background: var(--bg-input);
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .tab-btn {
        flex: 1;
        padding: 9px 12px;
        border-radius: 7px;
        border: none;
        background: transparent;
        color: var(--text-muted);
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
    }

    .tab-btn.active {
        background: var(--bg-card);
        color: var(--text-primary);
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
    }

    .tab-panel {
        display: none;
    }

    .tab-panel.active {
        display: block;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .voting-layout {
            grid-template-columns: 1fr;
        }

        .series-info-grid {
            grid-template-columns: 80px 1fr;
        }
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>board/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Bình chọn & Phê duyệt</span>
    </div>
    <h1>Bình Chọn & Phê Duyệt Xuất Bản</h1>
    <p>Xét duyệt các đệ trình bản thảo từ biên tập viên, bỏ phiếu và quyết định lịch xuất bản chính thức.</p>
</div>

<!-- Flash Message -->
<div id="flashMsg" style="display:none; margin-bottom:16px;"></div>

<!-- Stats Row -->
<div class="stats-row">
    <div class="stat-mini pending">
        <div class="num"><?= $pendingCount ?></div>
        <div class="lbl">⏳ Chờ xét</div>
    </div>
    <div class="stat-mini approved">
        <div class="num"><?= $approvedCount ?></div>
        <div class="lbl">✅ Đã duyệt</div>
    </div>
    <div class="stat-mini rejected">
        <div class="num"><?= $rejectedCount ?></div>
        <div class="lbl">❌ Từ chối</div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-row">
    <button class="tab-btn active" id="tabVoting" onclick="switchTab('voting')">
        🗳️ Bỏ phiếu
        <?php if ($pendingCount > 0): ?>
            <span
                style="background:#f59e0b; color:#0b0b16; border-radius:10px; padding:1px 6px; font-size:.65rem; font-weight:800; margin-left:4px;"><?= $pendingCount ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" id="tabHistory" onclick="switchTab('history')">
        📋 Lịch sử bỏ phiếu <span
            style="background:rgba(255,255,255,0.1); border-radius:10px; padding:1px 6px; font-size:.65rem; font-weight:700; margin-left:4px;"><?= count($historyList) ?></span>
    </button>
</div>

<!-- ─────────────── TAB: BỎ PHIẾU ─────────────── -->
<div class="tab-panel active" id="panelVoting">
    <div class="voting-layout">

        <!-- ■ LEFT: Danh sách submissions chờ -->
        <div class="voting-sidebar">
            <div class="card" style="padding: 18px;">
                <div class="section-heading">
                    <h3>Đang chờ xét duyệt</h3>
                    <span class="badge-count"><?= $pendingCount ?></span>
                </div>
                <?php if (empty($pendingSubmissions)): ?>
                    <div style="text-align:center; padding:40px 10px; color:var(--text-muted);">
                        <div style="font-size:2.5rem; margin-bottom:12px; opacity:0.5;">📭</div>
                        <p style="font-size:0.85rem;">Không có đệ trình nào đang chờ xét duyệt.</p>
                    </div>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($pendingSubmissions as $sub): ?>
                            <a href="?id=<?= $sub['id'] ?>"
                                class="sub-card <?= ($selectedId === (int) $sub['id']) ? 'active' : '' ?>"
                                id="subcard-<?= $sub['id'] ?>">
                                <div class="sub-card-dot"></div>
                                <div class="sub-card-title"><?= htmlspecialchars($sub['series_title']) ?></div>
                                <div style="font-size:0.78rem; color:#a5b4fc; margin-bottom:4px;">
                                    Chương <?= $sub['chapter_number'] ?>: <?= htmlspecialchars($sub['chapter_title']) ?>
                                </div>
                                <div class="sub-card-meta">
                                    <span>🎨 <?= htmlspecialchars($sub['mangaka_name']) ?></span>
                                    <span>📝 <?= htmlspecialchars($sub['editor_name']) ?></span>
                                </div>
                                <div class="sub-card-meta">
                                    <span>🕒 <?= date('d/m/Y', strtotime($sub['submitted_at'])) ?></span>
                                    <span
                                        style="background:rgba(245,158,11,0.15); color:#fcd34d; border-radius:4px; padding:1px 6px; font-size:.65rem; font-weight:700;">PENDING</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ■ RIGHT: Chi tiết + Form bỏ phiếu -->
        <div class="voting-main">
            <?php if ($selectedSub): ?>

                <!-- Series Info -->
                <div class="card" style="padding:20px;">
                    <div class="section-heading">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
                        </svg>
                        <h3>Thông tin tác phẩm</h3>
                    </div>
                    <div class="series-info-grid">
                        <div class="series-cover">
                            <?php $coverUrl = coverImageUrl($selectedSub['cover_image'] ?? null); ?>
                            <?php if ($coverUrl): ?>
                                <img src="<?= htmlspecialchars($coverUrl) ?>"
                                    alt="cover">
                            <?php else: ?>
                                📚
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 style="font-size:1.2rem; font-weight:800; color:#fff; margin-bottom:6px;">
                                <?= htmlspecialchars($selectedSub['series_title']) ?></h2>
                            <div style="margin-bottom:8px;">
                                <?php foreach (explode(',', $selectedSub['genre']) as $g): ?>
                                    <span class="genre-tag"><?= htmlspecialchars(trim($g)) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <p style="font-size:0.82rem; color:var(--text-muted); line-height:1.6; margin-bottom:12px;">
                                <?= htmlspecialchars($selectedSub['description'] ?: 'Chưa có mô tả.') ?>
                            </p>
                            <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:0.8rem;">
                                <div>
                                    <span style="color:var(--text-muted);">Họa sĩ:</span>
                                    <strong style="color:#fff;">
                                        <?= htmlspecialchars($selectedSub['mangaka_name']) ?></strong>
                                </div>
                                <div>
                                    <span style="color:var(--text-muted);">Đề xuất bởi:</span>
                                    <strong style="color:#a5b4fc;">
                                        <?= htmlspecialchars($selectedSub['editor_name']) ?></strong>
                                </div>
                                <div>
                                    <span style="color:var(--text-muted);">Ngày đệ trình:</span>
                                    <strong style="color:#fcd34d;">
                                        <?= date('d/m/Y H:i', strtotime($selectedSub['submitted_at'])) ?></strong>
                                </div>
                                <div>
                                    <span style="color:var(--text-muted);">Phiên bản bản thảo:</span>
                                    <span class="badge badge-gray"
                                        style="font-size:.7rem; padding:2px 7px;">v<?= $selectedSub['version'] ?></span>
                                </div>
                            </div>
                            <?php if (!empty($selectedSub['board_notes'])): ?>
                                <div
                                    style="margin-top:12px; padding:10px 14px; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.2); border-radius:8px; font-size:0.82rem; color:#c7d2fe;">
                                    <strong style="color:#a5b4fc;">💬 Nhận xét của Biên tập viên:</strong><br>
                                    <?= htmlspecialchars($selectedSub['board_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Manuscript Viewer -->
                <div class="card" style="padding:20px;">
                    <div class="section-heading">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                        </svg>
                        <h3>Đọc Bản Thảo</h3>
                        <a href="<?= BASE_URL . htmlspecialchars($selectedSub['file_path']) ?>" target="_blank" download
                            class="btn btn-secondary btn-sm" style="margin-left:auto; font-size:0.75rem; padding:5px 12px;">
                            📥 Tải về (PDF/ZIP)
                        </a>
                    </div>

                    <?php
                    $filePath = $selectedSub['file_path'];
                    $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $fileUrl = BASE_URL . htmlspecialchars($filePath);
                    ?>

                    <?php if ($fileExt === 'pdf'): ?>
                        <!-- PDF Viewer -->
                        <div class="manuscript-viewer">
                            <iframe src="<?= $fileUrl ?>#toolbar=1&navpanes=0&scrollbar=1"
                                title="PDF Bản thảo <?= htmlspecialchars($selectedSub['series_title']) ?>">
                                <p style="padding:20px; color:var(--text-muted);">
                                    Trình duyệt không hỗ trợ xem PDF.
                                    <a href="<?= $fileUrl ?>" target="_blank" style="color:var(--accent-primary);">Mở file trực
                                        tiếp</a>
                                </p>
                            </iframe>
                        </div>
                    <?php elseif (!empty($pages)): ?>
                        <!-- Image Page Viewer -->
                        <div class="manuscript-viewer"
                            style="background: #05050c; border-radius:10px; border:1px solid var(--border); overflow:hidden;">
                            <!-- Thumbnail strip -->
                            <div class="page-strip" id="pageStrip">
                                <?php foreach ($pages as $pg): ?>
                                    <div class="page-thumb <?= ($pg['id'] === ($pages[0]['id'] ?? 0)) ? 'active' : '' ?>"
                                        id="thumb-<?= $pg['id'] ?>"
                                        onclick="selectPage(<?= $pg['id'] ?>, '<?= addslashes($pg['original_file'] ? BASE_URL . $pg['original_file'] : '') ?>')"
                                        title="Trang <?= $pg['page_number'] ?>">
                                        <?php if ($pg['original_file']): ?>
                                            <img src="<?= BASE_URL . htmlspecialchars($pg['original_file']) ?>"
                                                alt="Trang <?= $pg['page_number'] ?>" loading="lazy">
                                        <?php else: ?>
                                            <div
                                                style="height:100%; display:flex; align-items:center; justify-content:center; font-size:1.2rem; opacity:0.3;">
                                                📄</div>
                                        <?php endif; ?>
                                        <div class="pnum">T<?= $pg['page_number'] ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Main page display -->
                            <div class="page-main-display" id="pageDisplay">
                                <?php if (!empty($pages[0]['original_file'])): ?>
                                    <img id="mainPageImg" src="<?= BASE_URL . htmlspecialchars($pages[0]['original_file']) ?>"
                                        alt="Trang <?= $pages[0]['page_number'] ?>">
                                <?php else: ?>
                                    <div style="color:var(--text-muted); text-align:center; padding:40px;">
                                        <div style="font-size:3rem; opacity:0.3; margin-bottom:12px;">📄</div>
                                        <p>Chương này chưa có ảnh trang nào được tải lên.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div
                            style="padding:40px; text-align:center; background:var(--bg-input); border-radius:8px; border:1px dashed var(--border); color:var(--text-muted);">
                            <div style="font-size:3rem; margin-bottom:12px; opacity:0.4;">📂</div>
                            <p>Bản thảo chưa có file đính kèm hoặc ảnh trang để xem trước.</p>
                            <?php if ($filePath): ?>
                                <a href="<?= $fileUrl ?>" target="_blank" class="btn btn-secondary"
                                    style="margin-top:16px; display:inline-flex;">
                                    Mở file bản thảo
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Vote Form -->
                <div class="vote-form-card" id="voteFormCard">
                    <div class="section-heading">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                        <h3>Bỏ Phiếu Quyết Định</h3>
                    </div>

                    <input type="hidden" id="voteSubmissionId" value="<?= $selectedId ?>">
                    <input type="hidden" id="voteChoice" value="">

                    <!-- Vote Buttons -->
                    <label
                        style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:10px;">
                        Quyết định của Ban biên tập *
                    </label>
                    <div class="vote-choice-row">
                        <button class="vote-btn approve" id="btnApprove" onclick="selectVote('approve')">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.2">
                                <path d="M20 6L9 17l-5-5" />
                            </svg>
                            PHÊ DUYỆT
                            <span class="check-mark">✓</span>
                        </button>
                        <button class="vote-btn reject" id="btnReject" onclick="selectVote('reject')">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.2">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                            TỪ CHỐI
                            <span class="check-mark">✓</span>
                        </button>
                    </div>

                    <!-- Approve-only Fields -->
                    <div class="approve-fields" id="approveFields">
                        <label
                            style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:10px;">
                            Lịch xuất bản *
                        </label>
                        <div class="schedule-row">
                            <div class="schedule-opt" id="optWeekly" onclick="selectSchedule('weekly')">
                                <span class="icon">📅</span>
                                Hàng tuần
                                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;">Mỗi tuần 1 chương
                                </div>
                            </div>
                            <div class="schedule-opt" id="optMonthly" onclick="selectSchedule('monthly')">
                                <span class="icon">🗓️</span>
                                Hàng tháng
                                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;">Mỗi tháng 1 chương
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="scheduleChoice" value="">

                        <div class="form-group" style="margin-bottom:16px;">
                            <label class="form-label" for="publishDateInput">
                                📅 Ngày bắt đầu xuất bản
                            </label>
                            <input type="date" id="publishDateInput" class="form-control" min="<?= date('Y-m-d') ?>"
                                value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" for="boardNotesInput">
                            💬 Ghi chú quyết định
                            <span style="color:var(--text-muted); font-weight:400;">(tùy chọn)</span>
                        </label>
                        <textarea id="boardNotesInput" class="form-control" rows="4"
                            placeholder="Ví dụ: Bộ truyện có tiềm năng tốt, chất lượng bản thảo đạt yêu cầu, duyệt xuất bản hàng tuần bắt đầu từ tháng 7..."></textarea>
                    </div>

                    <!-- Submit -->
                    <div style="display:flex; gap:12px; align-items:center;">
                        <button class="btn btn-primary" id="btnSubmitVote" onclick="submitVote()"
                            style="min-width:160px; position:relative;" disabled>
                            <span id="btnVoteText">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" style="vertical-align:-3px;">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                Xác nhận bỏ phiếu
                            </span>
                            <span id="btnVoteLoading" style="display:none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" style="animation:spin 1s linear infinite; vertical-align:-3px;">
                                    <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                                </svg>
                                Đang xử lý...
                            </span>
                        </button>
                        <a href="voting.php" class="btn btn-secondary">Hủy</a>

                        <div style="margin-left:auto; font-size:0.8rem; color:var(--text-muted);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" style="vertical-align:-2px;">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            Thông báo sẽ được gửi tự động đến Mangaka
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- No submission selected -->
                <div class="no-selection-state">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                        <path
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
                    </svg>
                    <?php if (empty($pendingSubmissions)): ?>
                        <h3 style="color:var(--text-secondary); font-size:1.1rem; margin:0;">Không có đệ trình nào đang chờ</h3>
                        <p style="font-size:0.85rem; max-width:400px; line-height:1.6;">
                            Hiện tại không có tác phẩm nào đang chờ Ban biên tập xét duyệt. Khi Biên tập viên đề xuất xuất bản
                            một bản thảo, chúng sẽ xuất hiện ở đây.
                        </p>
                    <?php else: ?>
                        <h3 style="color:var(--text-secondary); font-size:1.1rem; margin:0;">Chọn một đệ trình để bắt đầu bỏ
                            phiếu</h3>
                        <p style="font-size:0.85rem; max-width:400px; line-height:1.6;">
                            Nhấn vào một trong các đệ trình ở danh sách bên trái để xem thông tin chi tiết và bỏ phiếu quyết
                            định xuất bản.
                        </p>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                            <?php foreach (array_slice($pendingSubmissions, 0, 3) as $sub): ?>
                                <a href="?id=<?= $sub['id'] ?>" class="btn btn-secondary btn-sm">
                                    <?= htmlspecialchars($sub['series_title']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div><!-- /voting-main -->
    </div><!-- /voting-layout -->
</div><!-- /panelVoting -->

<!-- ─────────────── TAB: LỊCH SỬ BỎ PHIẾU ─────────────── -->
<div class="tab-panel" id="panelHistory">
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:20px 24px; border-bottom:1px solid var(--border);">
            <h3 style="margin:0; font-size:1rem; font-weight:700;">Lịch sử bỏ phiếu & Quyết định</h3>
            <p style="margin:4px 0 0; font-size:0.8rem; color:var(--text-muted);">Tất cả quyết định phê duyệt / từ chối
                xuất bản được ghi lại dưới đây.</p>
        </div>

        <?php if (empty($historyList)): ?>
            <div style="padding:60px; text-align:center; color:var(--text-muted);">
                <div style="font-size:3rem; opacity:0.3; margin-bottom:12px;">📋</div>
                <p>Chưa có quyết định nào được ghi lại.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border); text-align:left;">
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                #</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Tác phẩm</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Họa sĩ</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Đề xuất bởi</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; text-align:center;">
                                Quyết định</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Lịch XB</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Ghi chú</th>
                            <th
                                style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">
                                Ngày</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyList as $i => $h): ?>
                            <tr style="border-bottom:1px solid rgba(255,255,255,0.03); transition:background 0.15s;"
                                onmouseover="this.style.background='rgba(255,255,255,0.025)'"
                                onmouseout="this.style.background=''">
                                <td style="padding:13px 18px; font-size:0.75rem; color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td style="padding:13px 18px;">
                                    <div style="font-weight:700; font-size:0.88rem; color:#fff;">
                                        <?= htmlspecialchars($h['series_title']) ?></div>
                                </td>
                                <td style="padding:13px 18px; font-size:0.83rem; color:var(--text-secondary);">
                                    <?= htmlspecialchars($h['mangaka_name']) ?>
                                </td>
                                <td style="padding:13px 18px; font-size:0.83rem; color:#a5b4fc;">
                                    <?= htmlspecialchars($h['editor_name']) ?>
                                </td>
                                <td style="padding:13px 18px; text-align:center;">
                                    <?php if ($h['status'] === 'approved'): ?>
                                        <span class="badge history-status-approve" style="font-size:.7rem; padding:3px 10px;">✅ Đã
                                            duyệt</span>
                                    <?php else: ?>
                                        <span class="badge history-status-reject" style="font-size:.7rem; padding:3px 10px;">❌ Từ
                                            chối</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:13px 18px; font-size:0.8rem;">
                                    <?php if ($h['status'] === 'approved'): ?>
                                        <span style="color:#6ee7b7;">
                                            <?= $h['publish_schedule'] === 'weekly' ? '📅 Tuần' : '🗓️ Tháng' ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:13px 18px; font-size:0.78rem; color:var(--text-muted); max-width:220px;">
                                    <?php if ($h['board_notes']): ?>
                                        <span title="<?= htmlspecialchars($h['board_notes']) ?>">
                                            <?= htmlspecialchars(mb_strimwidth($h['board_notes'], 0, 60, '...')) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="opacity:0.4;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:13px 18px; font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">
                                    <?= date('d/m/Y H:i', strtotime($h['submitted_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div><!-- /panelHistory -->

<script>
    /* ── Tabs ── */
    function switchTab(name) {
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('panel' + name.charAt(0).toUpperCase() + name.slice(1)).classList.add('active');
        document.getElementById('tab' + name.charAt(0).toUpperCase() + name.slice(1)).classList.add('active');
    }

    /* ── Page Viewer ── */
    function selectPage(pageId, imgSrc) {
        // Update thumbs
        document.querySelectorAll('.page-thumb').forEach(t => t.classList.remove('active'));
        const thumb = document.getElementById('thumb-' + pageId);
        if (thumb) thumb.classList.add('active');

        // Update main image
        const mainImg = document.getElementById('mainPageImg');
        if (mainImg && imgSrc) {
            mainImg.style.opacity = '0';
            mainImg.src = imgSrc;
            mainImg.onload = () => {
                mainImg.style.transition = 'opacity 0.3s';
                mainImg.style.opacity = '1';
            };
        }
    }

    /* ── Vote selection ── */
    let currentVote = '';
    let currentSchedule = '';

    function selectVote(vote) {
        currentVote = vote;
        document.getElementById('voteChoice').value = vote;

        document.getElementById('btnApprove').classList.toggle('selected', vote === 'approve');
        document.getElementById('btnReject').classList.toggle('selected', vote === 'reject');

        const approveFields = document.getElementById('approveFields');
        if (vote === 'approve') {
            approveFields.classList.add('visible');
        } else {
            approveFields.classList.remove('visible');
            currentSchedule = '';
            document.getElementById('scheduleChoice').value = '';
            document.querySelectorAll('.schedule-opt').forEach(o => o.classList.remove('selected'));
        }

        updateSubmitBtn();
    }

    function selectSchedule(schedule) {
        currentSchedule = schedule;
        document.getElementById('scheduleChoice').value = schedule;
        document.getElementById('optWeekly').classList.toggle('selected', schedule === 'weekly');
        document.getElementById('optMonthly').classList.toggle('selected', schedule === 'monthly');
        updateSubmitBtn();
    }

    function updateSubmitBtn() {
        const btn = document.getElementById('btnSubmitVote');
        const valid = currentVote === 'reject' || (currentVote === 'approve' && currentSchedule !== '');
        btn.disabled = !valid;
        if (currentVote === 'approve' && valid) {
            btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
            btn.style.borderColor = '#10b981';
        } else if (currentVote === 'reject') {
            btn.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
            btn.style.borderColor = '#ef4444';
        } else {
            btn.style.background = '';
            btn.style.borderColor = '';
        }
    }

    /* ── Submit Vote ── */
    function submitVote() {
        const submissionId = document.getElementById('voteSubmissionId').value;
        const vote = currentVote;
        const schedule = currentSchedule;
        const notes = document.getElementById('boardNotesInput').value.trim();
        const publishDate = document.getElementById('publishDateInput') ? document.getElementById('publishDateInput').value : '';

        if (!vote) {
            showFlash('Vui lòng chọn Phê duyệt hoặc Từ chối.', 'error');
            return;
        }
        if (vote === 'approve' && !schedule) {
            showFlash('Vui lòng chọn lịch xuất bản (Hàng tuần / Hàng tháng).', 'error');
            return;
        }

        const confirmMsg = vote === 'approve'
            ? `Xác nhận PHÊ DUYỆT xuất bản tác phẩm này với lịch ${schedule === 'weekly' ? 'hàng tuần' : 'hàng tháng'}?`
            : 'Xác nhận TỪ CHỐI xuất bản tác phẩm này?';

        if (!confirm(confirmMsg)) return;

        // Show loading
        document.getElementById('btnVoteText').style.display = 'none';
        document.getElementById('btnVoteLoading').style.display = '';
        document.getElementById('btnSubmitVote').disabled = true;

        const params = new URLSearchParams();
        params.append('action', 'submit_vote');
        params.append('submission_id', submissionId);
        params.append('vote', vote);
        params.append('publish_schedule', schedule);
        params.append('board_notes', notes);
        params.append('publish_date', publishDate);

        fetch(BASE_URL + 'api/voting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showFlash(data.message || 'Bỏ phiếu thành công!', 'success');
                    // Remove card from sidebar
                    const card = document.getElementById('subcard-' + submissionId);
                    if (card) {
                        card.style.transition = 'all 0.4s';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(-20px)';
                        setTimeout(() => card.remove(), 400);
                    }
                    // Redirect after a short delay
                    setTimeout(() => {
                        window.location.href = 'voting.php';
                    }, 1800);
                } else {
                    showFlash(data.message || 'Đã xảy ra lỗi.', 'error');
                    resetSubmitBtn();
                }
            })
            .catch(err => {
                console.error(err);
                showFlash('Lỗi kết nối máy chủ. Vui lòng thử lại.', 'error');
                resetSubmitBtn();
            });
    }

    function resetSubmitBtn() {
        document.getElementById('btnVoteText').style.display = '';
        document.getElementById('btnVoteLoading').style.display = 'none';
        document.getElementById('btnSubmitVote').disabled = false;
    }

    /* ── Flash Message ── */
    function showFlash(msg, type) {
        const el = document.getElementById('flashMsg');
        const colors = {
            success: { bg: 'rgba(16,185,129,0.1)', border: 'rgba(16,185,129,0.3)', color: '#6ee7b7' },
            error: { bg: 'rgba(239,68,68,0.1)', border: 'rgba(239,68,68,0.3)', color: '#fca5a5' }
        };
        const c = colors[type] || colors.error;
        el.style.cssText = `display:flex; align-items:center; gap:10px; padding:14px 18px;
        background:${c.bg}; border:1px solid ${c.border}; border-radius:10px;
        color:${c.color}; font-size:0.88rem; font-weight:600; animation: slideDown 0.3s ease;`;
        el.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
        el.style.display = 'flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* ── Spin keyframe (for loading spinner) ── */
    const styleEl = document.createElement('style');
    styleEl.textContent = `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`;
    document.head.appendChild(styleEl);

    /* Auto-activate history tab if no pending submissions and URL has #history */
    if (window.location.hash === '#history') {
        switchTab('history');
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>