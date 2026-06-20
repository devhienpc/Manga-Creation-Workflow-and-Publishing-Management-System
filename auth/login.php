<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Redirect nếu đã đăng nhập
if (isLoggedIn()) {
    $user = getCurrentUser();
    redirectByRole($user['role']);
}

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

$errors = [];
$formEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $password = $_POST['password'] ?? '';

    // Validate phía server
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email không hợp lệ.';
    }
    if (empty($password)) {
        $errors['password'] = 'Vui lòng nhập mật khẩu.';
    }

    $formEmail = htmlspecialchars($email);

    if (empty($errors)) {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT id, username, email, password, role, avatar FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Lưu session
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'fullname' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
                'avatar'   => $user['avatar'],
            ];
            redirectByRole($user['role']);
        } else {
            $errors['general'] = 'Email hoặc mật khẩu không chính xác.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập — Manga System</title>
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
            --subtle:     #3a3a45;
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
            padding: 20px;
            overflow-x: hidden;
        }

        /* Manga-tone halftone background pattern */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle, rgba(230,57,70,0.06) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
            z-index: 0;
        }

        /* Speed-line overlay */
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
            max-width: 900px;
            min-height: 580px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 0 0 1px var(--border), 0 30px 80px rgba(0,0,0,0.6), 0 0 60px rgba(230,57,70,0.07);
        }

        /* ——— Branding panel ——— */
        .brand-panel {
            flex: 1;
            background:
                linear-gradient(160deg, #1a0508 0%, #0d0d0f 70%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23E63946' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 50px 44px;
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            min-width: 300px;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 280px; height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(230,57,70,0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            bottom: -40px; left: -40px;
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
            font-size: 3.8rem;
            line-height: 1;
            letter-spacing: 2px;
            background: linear-gradient(135deg, #fff 0%, #E63946 60%, #ff8a93 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .brand-desc {
            font-size: 0.95rem;
            color: var(--muted);
            line-height: 1.7;
            max-width: 240px;
            position: relative;
            z-index: 1;
            margin-bottom: 36px;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #8888a0;
        }

        .feature-dot {
            width: 6px; height: 6px;
            background: var(--red);
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ——— Form panel ——— */
        .form-panel {
            flex: 1;
            background: var(--bg-card);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px 44px;
        }

        .form-header {
            margin-bottom: 32px;
        }

        .form-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .form-header p {
            font-size: 0.9rem;
            color: var(--muted);
        }

        .form-header a {
            color: var(--red);
            text-decoration: none;
            font-weight: 600;
        }

        .form-header a:hover { text-decoration: underline; }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.88rem;
            font-weight: 500;
        }

        .alert-error {
            background: rgba(230,57,70,0.08);
            border: 1px solid rgba(230,57,70,0.2);
            color: #ff8a93;
        }

        .alert-icon { font-size: 1rem; }

        /* Fields */
        .field { margin-bottom: 18px; }

        .field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #9090a8;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-input {
            width: 100%;
            padding: 13px 14px 13px 40px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: var(--font);
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            -webkit-appearance: none;
        }

        .form-input:focus {
            border-color: var(--border-act);
            box-shadow: 0 0 0 3px rgba(230,57,70,0.1);
        }

        .form-input:focus + .input-icon,
        .input-wrap:focus-within .input-icon {
            color: var(--red);
        }

        .form-input.is-error { border-color: rgba(230,57,70,0.5); }

        .field-error {
            font-size: 0.78rem;
            color: #ff8a93;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Password toggle */
        .pw-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 2px;
            transition: color 0.2s;
        }

        .pw-toggle:hover { color: var(--text); }

        /* Submit */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--red);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: var(--font);
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
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
            background: linear-gradient(rgba(255,255,255,0.1), transparent);
            pointer-events: none;
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0;
            color: var(--muted);
            font-size: 0.78rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* Demo hint */
        .demo-hint {
            background: rgba(255,255,255,0.025);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 0.8rem;
            color: var(--muted);
            line-height: 1.8;
        }

        .demo-hint strong {
            color: #9090a8;
            display: block;
            margin-bottom: 4px;
        }

        code {
            background: rgba(230,57,70,0.1);
            color: #ff8a93;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.78rem;
        }

        /* ——— Responsive ——— */
        @media (max-width: 680px) {
            .page-wrap { flex-direction: column; max-width: 440px; }
            .brand-panel {
                min-width: 0;
                padding: 30px 28px;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            .brand-features { display: none; }
            .brand-logo { font-size: 2.8rem; }
            .form-panel { padding: 30px 28px; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <!-- Brand panel -->
    <div class="brand-panel">
        <div class="brand-badge">Manga System</div>
        <div class="brand-logo">MANGA<br>SYSTEM</div>
        <p class="brand-desc">Nền tảng quản lý quy trình sản xuất truyện tranh chuyên nghiệp.</p>
        <div class="brand-features">
            <div class="feature-item"><span class="feature-dot"></span>Quản lý bộ truyện & chương</div>
            <div class="feature-item"><span class="feature-dot"></span>Phân công trợ lý & theo dõi task</div>
            <div class="feature-item"><span class="feature-dot"></span>Biên tập & duyệt bản thảo</div>
            <div class="feature-item"><span class="feature-dot"></span>Bình chọn & quyết định xuất bản</div>
        </div>
    </div>

    <!-- Form panel -->
    <div class="form-panel">
        <div class="form-header">
            <h1>Đăng nhập</h1>
            <p>Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a></p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error">
                <span class="alert-icon">✕</span>
                <span><?= htmlspecialchars($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate id="loginForm">
            <!-- Email -->
            <div class="field">
                <label for="email">Email</label>
                <div class="input-wrap">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input <?= isset($errors['email']) ? 'is-error' : '' ?>"
                        placeholder="mangaka@example.com"
                        value="<?= $formEmail ?>"
                        autocomplete="email"
                        required>
                    <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <?php if (isset($errors['email'])): ?>
                    <div class="field-error">✕ <?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Password -->
            <div class="field">
                <label for="password">Mật khẩu</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input <?= isset($errors['password']) ? 'is-error' : '' ?>"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required>
                    <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <button type="button" class="pw-toggle" onclick="togglePw('password', this)" title="Hiện/Ẩn mật khẩu">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="field-error">✕ <?= htmlspecialchars($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">Đăng nhập →</button>
        </form>

        <div class="divider">Tài khoản mẫu</div>

        <div class="demo-hint">
            <strong>Thử nghiệm nhanh:</strong>
            Họa sĩ: <code>mangaka@mangasystem.com</code> / <code>mangaka123</code><br>
            Trợ lý: <code>assistant@mangasystem.com</code> / <code>assistant123</code><br>
            Biên tập: <code>editor@mangasystem.com</code> / <code>editor123</code><br>
            Ban BBT: <code>board@mangasystem.com</code> / <code>board123</code>
        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    btn.style.color = isHidden ? 'var(--red)' : '';
}

document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.textContent = 'Đang xử lý…';
    btn.disabled = true;
});
</script>
</body>
</html>
