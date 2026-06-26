<?php
/**
 * profile.php
 * Trang hồ sơ cá nhân – dùng chung cho tất cả role.
 */

require_once __DIR__ . '/config/constants.php';
$pageTitle  = 'Hồ sơ của tôi';
$activePage = 'profile';
require_once __DIR__ . '/includes/layout.php';

$db  = getDB();
$uid = (int) $currentUser['id'];

// ── Lấy thông tin đầy đủ từ DB ──────────────────────────
$stmt = $db->prepare("SELECT id, username, email, role, avatar, bio, is_active, created_at FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

// ── Thống kê theo role ────────────────────────────────────
$stats = [];
switch ($user['role']) {
    case 'mangaka':
        $s1 = $db->prepare("SELECT COUNT(*) FROM series WHERE mangaka_id = ?");
        $s1->execute([$uid]);
        $s2 = $db->prepare("SELECT COUNT(*) FROM chapters c JOIN series s ON s.id = c.series_id WHERE s.mangaka_id = ?");
        $s2->execute([$uid]);
        $stats = [
            ['label' => 'Bộ truyện', 'value' => (int) $s1->fetchColumn(), 'icon' => '📚'],
            ['label' => 'Chapter đã tạo', 'value' => (int) $s2->fetchColumn(), 'icon' => '📖'],
        ];
        break;
    case 'assistant':
        $s1 = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'approved'");
        $s1->execute([$uid]);
        $s2 = $db->prepare("SELECT COALESCE(SUM(approved_pages),0) FROM earnings WHERE assistant_id = ?");
        $s2->execute([$uid]);
        $stats = [
            ['label' => 'Task hoàn thành', 'value' => (int) $s1->fetchColumn(), 'icon' => '✅'],
            ['label' => 'Trang được duyệt', 'value' => (int) $s2->fetchColumn(), 'icon' => '🎨'],
        ];
        break;
    case 'editor':
        $s1 = $db->prepare("SELECT COUNT(*) FROM manuscripts WHERE status IN ('approved','rejected') AND submitted_by != ?");
        $s1->execute([$uid]);
        // Dùng annotations làm đại diện cho số lần review
        $s2 = $db->prepare("SELECT COUNT(*) FROM annotations WHERE editor_id = ?");
        $s2->execute([$uid]);
        $stats = [
            ['label' => 'Bản thảo đã review', 'value' => (int) $s1->fetchColumn(), 'icon' => '📝'],
            ['label' => 'Ghi chú đã tạo', 'value' => (int) $s2->fetchColumn(), 'icon' => '🔍'],
        ];
        break;
    case 'board':
        $s1 = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by = ? OR status != 'pending'");
        $s1->execute([$uid]);
        $s2 = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status = 'approved'");
        $s2->execute([]);
        $stats = [
            ['label' => 'Lần bỏ phiếu', 'value' => (int) $s1->fetchColumn(), 'icon' => '🗳️'],
            ['label' => 'Tác phẩm duyệt', 'value' => (int) $s2->fetchColumn(), 'icon' => '✨'],
        ];
        break;
    default:
        $stats = [];
}

// Role labels & badge classes
$roleBadges = [
    'mangaka'   => ['label' => 'Họa sĩ Manga',   'class' => 'role-badge mangaka'],
    'assistant' => ['label' => 'Trợ lý Manga',    'class' => 'role-badge assistant'],
    'editor'    => ['label' => 'Biên tập viên',   'class' => 'role-badge editor'],
    'board'     => ['label' => 'Ban biên tập',     'class' => 'role-badge board'],
    'admin'     => ['label' => 'Quản trị viên',   'class' => 'role-badge board'],
];
$badge = $roleBadges[$user['role']] ?? ['label' => $user['role'], 'class' => 'role-badge'];

// Avatar URL
$avatarUrl = null;
if (avatarFileExists($user['avatar'] ?? '')) {
    $avatarUrl = avatarImageUrl($user['avatar']) . '?t=' . avatarFileMtime($user['avatar']);
}
?>

<style>
/* ── Profile Page ─────────────────────────────────────── */
.profile-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
    align-items: start;
}

/* Left card */
.profile-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px 24px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 0;
    position: sticky;
    top: 80px;
}

/* Avatar ring */
.avatar-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    margin-bottom: 18px;
}
.avatar-ring {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    padding: 3px;
    background: linear-gradient(135deg, #E63946, #ff8c42);
    box-shadow: 0 0 24px rgba(230,57,70,0.35);
}
.avatar-ring-inner {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    overflow: hidden;
    background: var(--bg-sidebar);
    display: flex;
    align-items: center;
    justify-content: center;
}
.avatar-ring-inner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.avatar-fallback {
    font-size: 2.8rem;
    color: var(--text-muted);
    line-height: 1;
}

/* Username */
.profile-username {
    font-size: 1.25rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 6px;
}

/* Role badge */
.profile-role { margin-bottom: 14px; }

/* Bio */
.profile-bio {
    font-size: 0.83rem;
    color: var(--text-muted);
    line-height: 1.65;
    text-align: center;
    margin-bottom: 20px;
    min-height: 40px;
    word-break: break-word;
}

/* Join date */
.profile-join {
    font-size: 0.75rem;
    color: var(--text-dim);
    margin-bottom: 24px;
}

/* Stats */
.profile-stats {
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 4px;
}
.stat-box {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 10px;
    text-align: center;
}
.stat-box-icon { font-size: 1.3rem; margin-bottom: 4px; }
.stat-box-val  { font-size: 1.5rem; font-weight: 800; color: #fff; line-height: 1; }
.stat-box-lbl  { font-size: 0.68rem; color: var(--text-muted); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }

/* Divider */
.profile-divider {
    width: 100%;
    height: 1px;
    background: var(--border);
    margin: 20px 0;
}

/* Right panel - tabs */
.profile-right {
    min-width: 0;
}

/* Tab nav */
.tab-nav {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 24px;
    overflow-x: auto;
}
.tab-nav::-webkit-scrollbar { height: 3px; }

.tab-btn-prf {
    padding: 12px 22px;
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 7px;
}
.tab-btn-prf:hover { color: var(--text); }
.tab-btn-prf.active {
    color: #fff;
    border-bottom-color: #E63946;
}

/* Tab panels */
.tab-panel-prf { display: none; }
.tab-panel-prf.active { display: block; animation: fadeUp 0.25s ease; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Panel card */
.prf-panel-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 28px;
}

/* Form styles */
.prf-field { margin-bottom: 20px; }
.prf-label {
    display: block;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 8px;
}
.prf-input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 11px 14px;
    color: var(--text);
    font-size: 0.92rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
}
.prf-input:focus {
    border-color: rgba(230,57,70,0.5);
    box-shadow: 0 0 0 3px rgba(230,57,70,0.12);
}
.prf-input.is-valid   { border-color: rgba(16,185,129,0.5); }
.prf-input.is-invalid { border-color: rgba(239,68,68,0.5); }

textarea.prf-input { resize: vertical; min-height: 100px; }

/* Character counter */
.char-counter {
    text-align: right;
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 4px;
}
.char-counter.near  { color: #f59e0b; }
.char-counter.limit { color: #ef4444; }

/* Password strength */
.strength-bar {
    height: 4px;
    border-radius: 2px;
    background: var(--bg-input);
    margin-top: 8px;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s, background 0.3s;
    width: 0;
}
.strength-label {
    font-size: 0.72rem;
    font-weight: 600;
    margin-top: 4px;
}

/* Avatar upload area */
.avatar-upload-preview {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    padding: 3px;
    background: linear-gradient(135deg, #E63946, #ff8c42);
    margin: 0 auto 20px;
    cursor: pointer;
    transition: box-shadow 0.2s;
}
.avatar-upload-preview:hover {
    box-shadow: 0 0 0 4px rgba(230,57,70,0.25);
}
.avatar-upload-preview-inner {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    overflow: hidden;
    background: var(--bg-sidebar);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.avatar-upload-preview-inner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.avatar-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.45);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
    font-size: 0.75rem;
    color: #fff;
    font-weight: 600;
    text-align: center;
    flex-direction: column;
    gap: 4px;
}
.avatar-upload-preview:hover .avatar-overlay { opacity: 1; }

/* Submit row */
.prf-submit-row {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 8px;
}

/* Responsive */
@media (max-width: 900px) {
    .profile-layout { grid-template-columns: 1fr; }
    .profile-card { position: static; }
}
</style>

<!-- Page header -->
<div style="display:flex; align-items:center; gap:14px; margin-bottom:28px;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:800; color:#fff; margin:0;">Hồ sơ của tôi</h1>
        <p style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">Xem và cập nhật thông tin cá nhân</p>
    </div>
</div>

<div class="profile-layout">

    <!-- ═══════════ LEFT: INFO CARD ═══════════ -->
    <div class="profile-card">

        <!-- Avatar -->
        <div class="avatar-wrapper">
            <div class="avatar-ring">
                <div class="avatar-ring-inner">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= $avatarUrl ?>" alt="Avatar" id="sidebarAvatarImg">
                    <?php else: ?>
                        <div class="avatar-fallback">
                            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Name + Role -->
        <div class="profile-username"><?= htmlspecialchars($user['username']) ?></div>
        <div class="profile-role">
            <span class="<?= $badge['class'] ?>"><?= $badge['label'] ?></span>
        </div>

        <!-- Email -->
        <div style="font-size:0.82rem; color:var(--text-muted); margin-bottom:14px; word-break:break-all;">
            <?= htmlspecialchars($user['email']) ?>
        </div>

        <!-- Bio -->
        <div class="profile-bio">
            <?= $user['bio'] ? nl2br(htmlspecialchars($user['bio'])) : '<em style="opacity:0.5;">Chưa có giới thiệu</em>' ?>
        </div>

        <!-- Join date -->
        <div class="profile-join">
            🗓️ Tham gia ngày <?= date('d/m/Y', strtotime($user['created_at'])) ?>
        </div>

        <div class="profile-divider"></div>

        <!-- Stats -->
        <?php if (!empty($stats)): ?>
        <div class="profile-stats">
            <?php foreach ($stats as $st): ?>
            <div class="stat-box">
                <div class="stat-box-icon"><?= $st['icon'] ?></div>
                <div class="stat-box-val"><?= $st['value'] ?></div>
                <div class="stat-box-lbl"><?= htmlspecialchars($st['label']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- ═══════════ RIGHT: EDIT TABS ═══════════ -->
    <div class="profile-right">

        <!-- Tab nav -->
        <div class="tab-nav" role="tablist">
            <button class="tab-btn-prf active" id="tabInfoBtn" onclick="switchPrfTab('info')" role="tab">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Thông tin cá nhân
            </button>
            <button class="tab-btn-prf" id="tabAvatarBtn" onclick="switchPrfTab('avatar')" role="tab">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Ảnh đại diện
            </button>
            <button class="tab-btn-prf" id="tabPasswordBtn" onclick="switchPrfTab('password')" role="tab">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Đổi mật khẩu
            </button>
        </div>

        <!-- ─── TAB 1: Thông tin cá nhân ─── -->
        <div class="tab-panel-prf active" id="panelInfo">
            <div class="prf-panel-card">
                <h3 style="font-size:1rem; font-weight:700; color:#fff; margin-bottom:20px;">Cập nhật thông tin</h3>

                <div class="prf-field">
                    <label class="prf-label" for="usernameInput">Tên người dùng *</label>
                    <input type="text" id="usernameInput" class="prf-input"
                           value="<?= htmlspecialchars($user['username']) ?>"
                           minlength="3" maxlength="50" required
                           placeholder="Tên người dùng (tối thiểu 3 ký tự)">
                    <div id="usernameHint" style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;"></div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="emailDisplay">Email</label>
                    <input type="text" class="prf-input" value="<?= htmlspecialchars($user['email']) ?>"
                           disabled style="opacity:0.5; cursor:not-allowed;" title="Email không thể thay đổi">
                    <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Email không thể thay đổi sau khi đăng ký.</div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="bioInput">Giới thiệu bản thân</label>
                    <textarea id="bioInput" class="prf-input" maxlength="500"
                              placeholder="Một vài dòng về bạn..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    <div class="char-counter" id="bioCounter">0 / 500</div>
                </div>

                <div class="prf-submit-row">
                    <button class="btn btn-primary" id="btnSaveInfo" onclick="saveInfo()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><polyline points="20 6 9 17 4 12"/></svg>
                        Lưu thay đổi
                    </button>
                    <span id="infoResult" style="font-size:0.82rem;"></span>
                </div>
            </div>
        </div>

        <!-- ─── TAB 2: Ảnh đại diện ─── -->
        <div class="tab-panel-prf" id="panelAvatar">
            <div class="prf-panel-card" style="text-align:center;">
                <h3 style="font-size:1rem; font-weight:700; color:#fff; margin-bottom:6px;">Ảnh đại diện</h3>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:24px;">Nhấn vào ảnh bên dưới để chọn file mới. Hỗ trợ JPG, PNG, WEBP · Tối đa 2MB</p>

                <!-- Preview -->
                <div class="avatar-upload-preview" onclick="document.getElementById('avatarFileInput').click();">
                    <div class="avatar-upload-preview-inner">
                        <?php if ($avatarUrl): ?>
                            <img src="<?= $avatarUrl ?>" alt="Avatar" id="avatarPreviewImg">
                        <?php else: ?>
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:var(--text-muted);" id="avatarPreviewIcon">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        <?php endif; ?>
                        <div class="avatar-overlay">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span>Chọn ảnh</span>
                        </div>
                    </div>
                </div>

                <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/webp"
                       style="display:none;" onchange="previewAvatar(this)">

                <div id="avatarFileName" style="font-size:0.78rem; color:var(--text-muted); margin-bottom:16px; min-height:18px;"></div>

                <div class="prf-submit-row" style="justify-content:center;">
                    <button class="btn btn-primary" id="btnSaveAvatar" onclick="saveAvatar()" disabled>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><polyline points="20 6 9 17 4 12"/></svg>
                        Lưu ảnh đại diện
                    </button>
                    <span id="avatarResult" style="font-size:0.82rem;"></span>
                </div>
            </div>
        </div>

        <!-- ─── TAB 3: Đổi mật khẩu ─── -->
        <div class="tab-panel-prf" id="panelPassword">
            <div class="prf-panel-card">
                <h3 style="font-size:1rem; font-weight:700; color:#fff; margin-bottom:20px;">Đổi mật khẩu</h3>

                <div class="prf-field">
                    <label class="prf-label" for="currentPasswordInput">Mật khẩu hiện tại *</label>
                    <input type="password" id="currentPasswordInput" class="prf-input"
                           placeholder="Nhập mật khẩu hiện tại" autocomplete="current-password">
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="newPasswordInput">Mật khẩu mới *</label>
                    <input type="password" id="newPasswordInput" class="prf-input"
                           placeholder="Tối thiểu 8 ký tự, có chữ hoa và số"
                           oninput="checkStrength(this.value); checkMatch();"
                           autocomplete="new-password">
                    <!-- Strength bar -->
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="confirmPasswordInput">Xác nhận mật khẩu mới *</label>
                    <input type="password" id="confirmPasswordInput" class="prf-input"
                           placeholder="Nhập lại mật khẩu mới"
                           oninput="checkMatch();"
                           autocomplete="new-password">
                    <div id="matchHint" style="font-size:0.72rem; margin-top:4px;"></div>
                </div>

                <div style="background:rgba(230,57,70,0.06); border:1px solid rgba(230,57,70,0.15); border-radius:8px; padding:12px 14px; margin-bottom:20px; font-size:0.78rem; color:var(--text-muted); line-height:1.7;">
                    <strong style="color:#f87171;">Yêu cầu mật khẩu:</strong><br>
                    • Tối thiểu 8 ký tự<br>
                    • Ít nhất 1 chữ cái viết hoa (A–Z)<br>
                    • Ít nhất 1 chữ số (0–9)
                </div>

                <div class="prf-submit-row">
                    <button class="btn btn-primary" id="btnSavePassword" onclick="savePassword()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Cập nhật mật khẩu
                    </button>
                    <span id="passwordResult" style="font-size:0.82rem;"></span>
                </div>
            </div>
        </div>

    </div><!-- /profile-right -->
</div><!-- /profile-layout -->

<script>
/* ══════════════════════════════════════════════════
   TAB SWITCHING
══════════════════════════════════════════════════ */
function switchPrfTab(name) {
    document.querySelectorAll('.tab-btn-prf').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel-prf').forEach(p => p.classList.remove('active'));
    document.getElementById('tab' + name.charAt(0).toUpperCase() + name.slice(1) + 'Btn').classList.add('active');
    document.getElementById('panel' + name.charAt(0).toUpperCase() + name.slice(1)).classList.add('active');
}

/* ══════════════════════════════════════════════════
   BIO CHAR COUNTER
══════════════════════════════════════════════════ */
(function () {
    const bio = document.getElementById('bioInput');
    const counter = document.getElementById('bioCounter');
    if (!bio) return;
    function update() {
        const len = bio.value.length;
        counter.textContent = len + ' / 500';
        counter.className = 'char-counter' + (len >= 500 ? ' limit' : len >= 450 ? ' near' : '');
    }
    bio.addEventListener('input', update);
    update();
})();

/* ══════════════════════════════════════════════════
   AVATAR PREVIEW
══════════════════════════════════════════════════ */
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        showToast('Chỉ chấp nhận JPG, PNG, WEBP.', 'error');
        input.value = '';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        showToast('Ảnh vượt quá 2MB.', 'error');
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
        // Update preview
        const container = document.querySelector('.avatar-upload-preview-inner');
        let img = document.getElementById('avatarPreviewImg');
        if (!img) {
            container.innerHTML = '';
            img = document.createElement('img');
            img.id = 'avatarPreviewImg';
            container.appendChild(img);
            // Re-add overlay
            const ov = document.createElement('div');
            ov.className = 'avatar-overlay';
            ov.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><span>Thay đổi</span>';
            container.appendChild(ov);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);

    document.getElementById('avatarFileName').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    document.getElementById('btnSaveAvatar').disabled = false;
}

/* ══════════════════════════════════════════════════
   PASSWORD STRENGTH
══════════════════════════════════════════════════ */
function checkStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');

    const config = [
        { w: '0%',   bg: 'transparent', text: '' },
        { w: '30%',  bg: '#ef4444',     text: '🔴 Yếu' },
        { w: '55%',  bg: '#f59e0b',     text: '🟡 Trung bình' },
        { w: '80%',  bg: '#3b82f6',     text: '🔵 Khá mạnh' },
        { w: '100%', bg: '#10b981',     text: '🟢 Mạnh' },
    ];
    const c = config[score] || config[0];
    fill.style.width      = val ? c.w : '0%';
    fill.style.background = c.bg;
    label.textContent     = val ? c.text : '';
    label.style.color     = c.bg;
}

function checkMatch() {
    const np = document.getElementById('newPasswordInput').value;
    const cp = document.getElementById('confirmPasswordInput').value;
    const hint = document.getElementById('matchHint');
    const conf = document.getElementById('confirmPasswordInput');

    if (!cp) { hint.textContent = ''; conf.className = 'prf-input'; return; }

    if (np === cp) {
        hint.textContent = '✓ Mật khẩu khớp';
        hint.style.color = '#10b981';
        conf.classList.remove('is-invalid');
        conf.classList.add('is-valid');
    } else {
        hint.textContent = '✗ Mật khẩu chưa khớp';
        hint.style.color = '#ef4444';
        conf.classList.remove('is-valid');
        conf.classList.add('is-invalid');
    }
}

/* ══════════════════════════════════════════════════
   SAVE INFO
══════════════════════════════════════════════════ */
function saveInfo() {
    const username = document.getElementById('usernameInput').value.trim();
    const bio      = document.getElementById('bioInput').value;
    const btn      = document.getElementById('btnSaveInfo');

    if (username.length < 3) {
        showToast('Tên người dùng phải có ít nhất 3 ký tự.', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Đang lưu...';

    const fd = new FormData();
    fd.append('action', 'update_info');
    fd.append('username', username);
    fd.append('bio', bio);

    fetch(BASE_URL + 'api/profile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Đã cập nhật thông tin!', 'success');
                // Cập nhật tất cả các nơi hiển thị username trong DOM
                document.querySelectorAll('.user-name, .header-user-name, .sidebar-user .user-name').forEach(el => {
                    el.textContent = username;
                });
                // Cập nhật thẻ avatar chữ cái (nếu chưa có ảnh)
                document.querySelectorAll('.user-avatar:not(:has(img))').forEach(el => {
                    el.textContent = username.charAt(0).toUpperCase();
                });
                // Cập nhật info card bên trái
                document.querySelector('.profile-username').textContent = username;
                const bioEl = document.querySelector('.profile-bio');
                bioEl.innerHTML = bio.trim()
                    ? bio.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')
                    : '<em style="opacity:0.5;">Chưa có giới thiệu</em>';
            } else {
                showToast(data.message || 'Cập nhật thất bại.', 'error');
            }
        })
        .catch(() => showToast('Lỗi kết nối máy chủ.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><polyline points="20 6 9 17 4 12"/></svg> Lưu thay đổi';
        });
}

/* ══════════════════════════════════════════════════
   SAVE AVATAR
══════════════════════════════════════════════════ */
function saveAvatar() {
    const fileInput = document.getElementById('avatarFileInput');
    if (!fileInput.files[0]) {
        showToast('Vui lòng chọn ảnh trước.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSaveAvatar');
    btn.disabled = true;
    btn.textContent = 'Đang tải lên...';

    const fd = new FormData();
    fd.append('action', 'update_avatar');
    fd.append('avatar', fileInput.files[0]);

    fetch(BASE_URL + 'api/profile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Ảnh đại diện đã được cập nhật!', 'success');
                const newUrl = (data.data?.avatar_url || '') + '?t=' + Date.now();
                if (newUrl) {
                    // Cập nhật tất cả ảnh avatar trong trang (sidebar, header, preview)
                    const allAvatarImgs = document.querySelectorAll(
                        '.user-avatar img, #avatarPreviewImg, #sidebarAvatarImg, .avatar-ring-inner img'
                    );
                    allAvatarImgs.forEach(img => { img.src = newUrl; });

                    // Nếu sidebar hiển thị chữ cái (chưa có ảnh), thay bằng ảnh mới
                    document.querySelectorAll('.user-avatar').forEach(el => {
                        if (!el.querySelector('img')) {
                            el.innerHTML = '<img src="' + newUrl + '" alt="avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
                        }
                    });

                    // Cập nhật avatar ring cột trái
                    const ring = document.querySelector('.avatar-ring-inner');
                    if (ring) {
                        let img = ring.querySelector('img');
                        if (!img) {
                            ring.innerHTML = '';
                            img = document.createElement('img');
                            ring.appendChild(img);
                        }
                        img.src = newUrl;
                        img.id = 'sidebarAvatarImg';
                    }
                }
                document.getElementById('avatarFileName').textContent = 'Đã lưu thành công ✓';
            } else {
                showToast(data.message || 'Upload thất bại.', 'error');
            }
        })
        .catch(() => showToast('Lỗi kết nối máy chủ.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><polyline points="20 6 9 17 4 12"/></svg> Lưu ảnh đại diện';
        });
}

/* ══════════════════════════════════════════════════
   SAVE PASSWORD
══════════════════════════════════════════════════ */
function savePassword() {
    const curr = document.getElementById('currentPasswordInput').value;
    const np   = document.getElementById('newPasswordInput').value;
    const conf = document.getElementById('confirmPasswordInput').value;
    const btn  = document.getElementById('btnSavePassword');

    if (!curr) { showToast('Vui lòng nhập mật khẩu hiện tại.', 'error'); return; }
    if (np.length < 8) { showToast('Mật khẩu mới phải có ít nhất 8 ký tự.', 'error'); return; }
    if (!/[A-Z]/.test(np)) { showToast('Mật khẩu mới phải có ít nhất 1 chữ hoa.', 'error'); return; }
    if (!/[0-9]/.test(np)) { showToast('Mật khẩu mới phải có ít nhất 1 chữ số.', 'error'); return; }
    if (np !== conf) { showToast('Mật khẩu xác nhận không khớp.', 'error'); return; }

    btn.disabled = true;
    btn.textContent = 'Đang cập nhật...';

    const fd = new FormData();
    fd.append('action', 'update_password');
    fd.append('current_password', curr);
    fd.append('new_password', np);
    fd.append('confirm_password', conf);

    fetch(BASE_URL + 'api/profile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Mật khẩu đã được đổi!', 'success');
                document.getElementById('currentPasswordInput').value = '';
                document.getElementById('newPasswordInput').value = '';
                document.getElementById('confirmPasswordInput').value = '';
                document.getElementById('strengthFill').style.width = '0';
                document.getElementById('strengthLabel').textContent = '';
                document.getElementById('matchHint').textContent = '';
            } else {
                showToast(data.message || 'Cập nhật thất bại.', 'error');
            }
        })
        .catch(() => showToast('Lỗi kết nối máy chủ.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="vertical-align:-2px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Cập nhật mật khẩu';
        });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
