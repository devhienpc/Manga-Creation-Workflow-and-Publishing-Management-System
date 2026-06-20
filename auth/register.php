<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

function redirectByRole($role) {
    switch ($role) {
        case ROLES['MANGAKA']:  header('Location: ' . BASE_URL . 'mangaka/dashboard.php');  break;
        case ROLES['ASSISTANT']: header('Location: ' . BASE_URL . 'assistant/dashboard.php'); break;
        case ROLES['EDITOR']:   header('Location: ' . BASE_URL . 'editor/dashboard.php');   break;
        case ROLES['BOARD']:    header('Location: ' . BASE_URL . 'board/dashboard.php');    break;
        default: header('Location: ' . BASE_URL . 'auth/login.php');
    }
    exit();
}

// Redirect nếu đã đăng nhập
if (isLoggedIn()) {
    redirectByRole(getCurrentUser()['role']);
}

$errors = [];
$form = ['username' => '', 'email' => '', 'role' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS));
    $email            = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role             = $_POST['role'] ?? '';

    $form['username'] = htmlspecialchars($username);
    $form['email']    = htmlspecialchars($email);
    $form['role']     = $role;

    // Validate username
    if (empty($username) || strlen($username) < 3) {
        $errors['username'] = 'Tên người dùng phải có ít nhất 3 ký tự.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors['username'] = 'Chỉ được dùng chữ cái, số và dấu gạch dưới.';
    }

    // Validate email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Địa chỉ email không hợp lệ.';
    }

    // Validate password
    if (strlen($password) < 8) {
        $errors['password'] = 'Mật khẩu phải có ít nhất 8 ký tự.';
    }

    // Validate confirm password
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Mật khẩu xác nhận không khớp.';
    }

    // Validate role
    if (!in_array($role, ROLES)) {
        $errors['role'] = 'Vai trò không hợp lệ.';
    }

    // Check email/username unique (chỉ khi không có lỗi validate trên)
    if (empty($errors)) {
        $db = getDB();

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = 'Email này đã được sử dụng.';
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            $errors['username'] = 'Tên người dùng này đã tồn tại.';
        }
    }

    // Tạo tài khoản nếu không có lỗi
    if (empty($errors)) {
        $db = getDB();
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare(
            "INSERT INTO users (username, email, password, role, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$username, $email, $hashedPassword, $role]);
        $newId = $db->lastInsertId();

        // Auto-login
        $_SESSION['user'] = [
            'id'       => $newId,
            'username' => $username,
            'fullname' => $username,
            'email'    => $email,
            'role'     => $role,
            'avatar'   => null,
        ];
        redirectByRole($role);
    }
}

$roleOptions = [
    ROLES['MANGAKA']   => '🖊️ Họa sĩ Manga (Mangaka)',
    ROLES['ASSISTANT'] => '✏️ Trợ lý Manga (Assistant)',
    ROLES['EDITOR']    => '📋 Biên tập viên (Editor)',
    ROLES['BOARD']     => '🏛️ Ban biên tập (Board)',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản — Manga System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Bangers&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:        #E63946;
            --red-dark:   #C1121F;
            --red-glow:   rgba(230, 57, 70, 0.35);
            --bg:         #0d0d0f;
            --bg-card:    #111114;
            --bg-input:   #18181d;
            --border:     rgba(255,255,255,0.07);
            --border-act: rgba(230,57,70,0.5);
            --text:       #f1f1f3;
            --muted:      #6b6b7a;
            --font:       'Inter', sans-serif;
            --font-hero:  'Bangers', cursive;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, rgba(230,57,70,0.055) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: repeating-conic-gradient(
                from 0deg at 50% 110%,
                rgba(230,57,70,0.03) 0deg,
                transparent 0.5deg,
                transparent 3deg
            );
            pointer-events: none;
            z-index: 0;
        }

        .page-wrap {
            position: relative;
            z-index: 1;
            display: flex;
            width: 100%;
            max-width: 940px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 0 0 1px var(--border), 0 30px 80px rgba(0,0,0,0.6), 0 0 60px rgba(230,57,70,0.07);
        }

        /* ——— Brand panel ——— */
        .brand-panel {
            width: 300px;
            flex-shrink: 0;
            background: linear-gradient(160deg, #1a0508 0%, #0d0d0f 70%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px 36px;
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 260px; height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(230,57,70,0.15) 0%, transparent 70%);
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            bottom: -50px; left: -50px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(230,57,70,0.08) 0%, transparent 70%);
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(230,57,70,0.12);
            border: 1px solid rgba(230,57,70,0.25);
            border-radius: 100px;
            padding: 6px 14px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--red);
            margin-bottom: 28px;
            width: fit-content;
        }

        .brand-badge::before {
            content: '';
            width: 7px; height: 7px;
            background: var(--red);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.7); }
        }

        .brand-logo {
            font-family: var(--font-hero);
            font-size: 3.4rem;
            line-height: 1;
            letter-spacing: 2px;
            background: linear-gradient(135deg, #fff 0%, #E63946 60%, #ff8a93 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .brand-desc {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.7;
            position: relative;
            z-index: 1;
            margin-bottom: 32px;
        }

        .role-preview {
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .role-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.82rem;
            color: #9090a8;
            transition: border-color 0.2s, color 0.2s;
        }

        .role-chip.active {
            border-color: rgba(230,57,70,0.3);
            color: #d0d0e0;
        }

        .role-chip-icon { font-size: 1rem; }

        /* ——— Form panel ——— */
        .form-panel {
            flex: 1;
            background: var(--bg-card);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 46px 44px;
        }

        .form-header {
            margin-bottom: 28px;
        }

        .form-header h1 {
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .form-header p {
            font-size: 0.88rem;
            color: var(--muted);
        }

        .form-header a {
            color: var(--red);
            text-decoration: none;
            font-weight: 600;
        }

        .form-header a:hover { text-decoration: underline; }

        /* Two-col grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 18px;
        }

        .field { margin-bottom: 16px; }
        .field.full { grid-column: 1 / -1; }

        .field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #9090a8;
            margin-bottom: 7px;
            text-transform: uppercase;
        }

        .input-wrap { position: relative; }

        .input-wrap .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrap:focus-within .input-icon { color: var(--red); }

        .form-input {
            width: 100%;
            padding: 12px 13px 12px 38px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: var(--font);
            font-size: 0.92rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            -webkit-appearance: none;
        }

        .form-input:focus {
            border-color: var(--border-act);
            box-shadow: 0 0 0 3px rgba(230,57,70,0.1);
        }

        .form-input.is-error { border-color: rgba(230,57,70,0.5); }

        select.form-input { cursor: pointer; }

        select.form-input option {
            background: #18181d;
            color: var(--text);
        }

        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 0.88rem;
            padding: 2px;
            transition: color 0.2s;
        }
        .pw-toggle:hover { color: var(--text); }

        .field-error {
            font-size: 0.76rem;
            color: #ff8a93;
            margin-top: 5px;
        }

        /* Password strength */
        .pw-strength {
            margin-top: 6px;
            height: 3px;
            border-radius: 3px;
            background: rgba(255,255,255,0.06);
            overflow: hidden;
        }

        .pw-strength-bar {
            height: 100%;
            border-radius: 3px;
            width: 0;
            transition: width 0.3s, background 0.3s;
        }

        .strength-weak   { width: 33%; background: var(--red); }
        .strength-medium { width: 66%; background: #f59e0b; }
        .strength-strong { width: 100%; background: #10b981; }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 15px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 0.86rem;
            font-weight: 500;
            grid-column: 1 / -1;
        }
        .alert-error {
            background: rgba(230,57,70,0.08);
            border: 1px solid rgba(230,57,70,0.2);
            color: #ff8a93;
        }

        /* Submit */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--red);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: var(--font);
            font-size: 0.97rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
            grid-column: 1 / -1;
            position: relative;
            overflow: hidden;
        }

        .btn-submit:hover {
            background: var(--red-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 24px var(--red-glow);
        }

        .btn-submit:active { transform: translateY(0); }

        .btn-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(rgba(255,255,255,0.08), transparent);
            pointer-events: none;
        }

        .terms-note {
            grid-column: 1 / -1;
            font-size: 0.76rem;
            color: var(--muted);
            text-align: center;
            margin-top: 8px;
        }

        /* ——— Responsive ——— */
        @media (max-width: 720px) {
            .page-wrap { flex-direction: column; max-width: 480px; }
            .brand-panel {
                width: 100%;
                padding: 28px 24px;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            .role-preview { display: none; }
            .brand-logo { font-size: 2.6rem; }
            .form-panel { padding: 28px 24px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <!-- Brand panel -->
    <div class="brand-panel">
        <div class="brand-badge">Manga System</div>
        <div class="brand-logo">THAM<br>GIA<br>NGAY</div>
        <p class="brand-desc">Chọn vai trò của bạn và bắt đầu hành trình sáng tạo manga.</p>

        <div class="role-preview" id="rolePrev">
            <?php foreach ($roleOptions as $val => $label): ?>
                <div class="role-chip <?= $form['role'] === $val ? 'active' : '' ?>" data-role="<?= $val ?>">
                    <span class="role-chip-icon"><?= mb_substr($label, 0, 2) ?></span>
                    <span><?= htmlspecialchars(preg_replace('/^..\s/', '', $label)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Form panel -->
    <div class="form-panel">
        <div class="form-header">
            <h1>Tạo tài khoản</h1>
            <p>Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a></p>
        </div>

        <form method="POST" action="" novalidate id="regForm">
            <div class="form-grid">

                <?php
                $generalErrors = array_filter($errors, fn($k) => !in_array($k, ['username','email','password','confirm_password','role']), ARRAY_FILTER_USE_KEY);
                if (!empty($generalErrors)):
                ?>
                    <div class="alert alert-error">✕ <?= htmlspecialchars(implode(' ', $generalErrors)) ?></div>
                <?php endif; ?>

                <!-- Username -->
                <div class="field">
                    <label for="username">Tên người dùng</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username"
                            class="form-input <?= isset($errors['username']) ? 'is-error' : '' ?>"
                            placeholder="mangaka_viet" value="<?= $form['username'] ?>"
                            autocomplete="username" maxlength="50">
                        <svg class="input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <?php if (isset($errors['username'])): ?>
                        <div class="field-error">✕ <?= htmlspecialchars($errors['username']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Role -->
                <div class="field">
                    <label for="role">Vai trò</label>
                    <div class="input-wrap">
                        <select id="role" name="role"
                            class="form-input <?= isset($errors['role']) ? 'is-error' : '' ?>"
                            style="padding-left: 13px;">
                            <option value="">— Chọn vai trò —</option>
                            <?php foreach ($roleOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $form['role'] === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isset($errors['role'])): ?>
                        <div class="field-error">✕ <?= htmlspecialchars($errors['role']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Email -->
                <div class="field full">
                    <label for="email">Địa chỉ Email</label>
                    <div class="input-wrap">
                        <input type="email" id="email" name="email"
                            class="form-input <?= isset($errors['email']) ? 'is-error' : '' ?>"
                            placeholder="you@example.com" value="<?= $form['email'] ?>"
                            autocomplete="email">
                        <svg class="input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <?php if (isset($errors['email'])): ?>
                        <div class="field-error">✕ <?= htmlspecialchars($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Password -->
                <div class="field">
                    <label for="password">Mật khẩu</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password"
                            class="form-input <?= isset($errors['password']) ? 'is-error' : '' ?>"
                            placeholder="Ít nhất 8 ký tự" autocomplete="new-password"
                            oninput="updateStrength(this.value)">
                        <svg class="input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <button type="button" class="pw-toggle" onclick="togglePw('password', this)">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="field-error">✕ <?= htmlspecialchars($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Confirm password -->
                <div class="field">
                    <label for="confirm_password">Xác nhận mật khẩu</label>
                    <div class="input-wrap">
                        <input type="password" id="confirm_password" name="confirm_password"
                            class="form-input <?= isset($errors['confirm_password']) ? 'is-error' : '' ?>"
                            placeholder="Nhập lại mật khẩu" autocomplete="new-password">
                        <svg class="input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div class="field-error">✕ <?= htmlspecialchars($errors['confirm_password']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    Tạo tài khoản →
                </button>

                <p class="terms-note">
                    Bằng cách đăng ký, bạn đồng ý với các điều khoản sử dụng của Manga System.
                </p>
            </div>
        </form>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.style.color = inp.type === 'text' ? 'var(--red)' : '';
}

function updateStrength(val) {
    const bar = document.getElementById('pwBar');
    if (!bar) return;
    bar.className = 'pw-strength-bar';
    if (val.length === 0) { bar.style.width = '0'; return; }
    if (val.length < 8) { bar.classList.add('strength-weak'); }
    else if (val.length < 12 || !/[^a-zA-Z0-9]/.test(val)) { bar.classList.add('strength-medium'); }
    else { bar.classList.add('strength-strong'); }
}

// Highlight role chip based on select value
const roleSelect = document.getElementById('role');
const chips = document.querySelectorAll('.role-chip');

function syncRoleChips() {
    chips.forEach(c => {
        c.classList.toggle('active', c.dataset.role === roleSelect.value);
    });
}

roleSelect?.addEventListener('change', syncRoleChips);
syncRoleChips();

document.getElementById('regForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.textContent = 'Đang tạo tài khoản…';
    btn.disabled = true;
});
</script>
</body>
</html>
