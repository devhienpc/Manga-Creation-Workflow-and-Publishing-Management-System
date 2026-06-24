<?php
/**
 * admin/index.php
 * Trang Quản Trị Hệ Thống (dành cho Ban biên tập 'board' hoặc Quản trị viên 'admin').
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Yêu cầu đăng nhập trước
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// 1. Tự động kiểm tra & Chạy Migration khi vào trang (Self-healing database setup)
try {
    $db = getDB();
    
    // Thêm cột is_active cho bảng users nếu chưa có
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetchAll();
    if (empty($columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER bio");
    }

    // Thay đổi enum role của users để hỗ trợ vai trò 'admin'
    $roleCol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($roleCol && strpos($roleCol['Type'], "'admin'") === false) {
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('mangaka', 'assistant', 'editor', 'board', 'admin') NOT NULL");
    }

    // Tạo bảng settings nếu chưa có
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key_name VARCHAR(100) PRIMARY KEY,
            value_text TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Đặt giá trị mặc định cho rate trợ lý
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE key_name = 'default_assistant_rate'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO settings (key_name, value_text) VALUES ('default_assistant_rate', '250000')");
    }
} catch (\Throwable $e) {
    // Ghi lỗi nhưng cho phép chạy tiếp nếu có thể
    error_log("Admin migration error: " . $e->getMessage());
}

$flashMsg = '';
$flashType = 'success';

// Nhận flash từ redirect (GET)
if (isset($_GET['flash'])) {
    if ($_GET['flash'] === 'rate_saved') {
        $flashMsg = 'Lưu cấu hình đơn giá trợ lý thành công!';
        $flashType = 'success';
    } elseif ($_GET['flash'] === 'user_deactivated') {
        $flashMsg = 'Đã vô hiệu hóa tài khoản thành công!';
        $flashType = 'success';
    } elseif ($_GET['flash'] === 'user_activated') {
        $flashMsg = 'Đã kích hoạt lại tài khoản thành công!';
        $flashType = 'success';
    } elseif ($_GET['flash'] === 'cannot_deactivate_self') {
        $flashMsg = 'Không thể tự vô hiệu hóa tài khoản của chính mình!';
        $flashType = 'error';
    }
}

/* ══════════════════════════════════════════════════
   XỬ LÝ POST ACTIONS (Cập nhật settings & Trạng thái user)
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Hành động 1: Lưu đơn giá trợ lý
    if ($action === 'save_assistant_rate') {
        $rate = trim($_POST['default_assistant_rate'] ?? '250000');
        // Validate rate là số dương
        if (is_numeric($rate) && $rate >= 0) {
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM settings WHERE key_name = 'default_assistant_rate'");
            $stmtCheck->execute();
            if ($stmtCheck->fetchColumn() > 0) {
                $stmt = $db->prepare("UPDATE settings SET value_text = ? WHERE key_name = 'default_assistant_rate'");
                $stmt->execute([$rate]);
            } else {
                $stmt = $db->prepare("INSERT INTO settings (key_name, value_text) VALUES ('default_assistant_rate', ?)");
                $stmt->execute([$rate]);
            }
            header('Location: ' . BASE_URL . 'admin/index.php?flash=rate_saved');
            exit();
        } else {
            $flashMsg = 'Đơn giá trợ lý phải là một số hợp lệ lớn hơn hoặc bằng 0.';
            $flashType = 'error';
        }
    }
    
    // Hành động 2: Toggle kích hoạt/vô hiệu hóa thành viên
    if ($action === 'toggle_user_status') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 1); // 1 = active, 0 = inactive
        
        if ($targetUserId === (int)$currentUser['id']) {
            header('Location: ' . BASE_URL . 'admin/index.php?flash=cannot_deactivate_self');
            exit();
        }
        
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $targetUserId]);
        
        $flash = $newStatus === 1 ? 'user_activated' : 'user_deactivated';
        header('Location: ' . BASE_URL . 'admin/index.php?flash=' . $flash);
        exit();
    }
}

// 2. Bảo vệ trang: Chỉ user role='board' hoặc 'admin' mới được truy cập
$allowedRoles = [ROLES['BOARD'], 'admin'];
$pageTitle    = 'Quản trị hệ thống';
$activePage   = 'admin_dashboard'; // Highlight sidebar menu nếu role là admin
require_once __DIR__ . '/../includes/layout.php';

/* ══════════════════════════════════════════════════
   TRUY VẤN DỮ LIỆU BÁO CÁO & HIỂN THỊ
   ══════════════════════════════════════════════════ */
// Thống kê tổng quan
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalSeries = (int)$db->query("SELECT COUNT(*) FROM series")->fetchColumn();
$totalChapters = (int)$db->query("SELECT COUNT(*) FROM chapters")->fetchColumn();
$totalTasks = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();

// Đọc cài đặt đơn giá hiện tại
$stmtRate = $db->prepare("SELECT value_text FROM settings WHERE key_name = 'default_assistant_rate' LIMIT 1");
$stmtRate->execute();
$defaultRate = $stmtRate->fetchColumn() ?: '250000';

// Bộ lọc cho danh sách Users
$searchUser = trim($_GET['search_user'] ?? '');
$filterRole = trim($_GET['filter_role'] ?? '');

$userQuery = "SELECT id, username, email, role, avatar, bio, is_active, created_at FROM users WHERE 1=1";
$userParams = [];

if ($searchUser !== '') {
    $userQuery .= " AND (username LIKE ? OR email LIKE ?)";
    $userParams[] = "%$searchUser%";
    $userParams[] = "%$searchUser%";
}

if ($filterRole !== '') {
    $userQuery .= " AND role = ?";
    $userParams[] = $filterRole;
}

$userQuery .= " ORDER BY created_at DESC";
$stmtUsers = $db->prepare($userQuery);
$stmtUsers->execute($userParams);
$usersList = $stmtUsers->fetchAll();

// Tạo mảng thông tin chi tiết của Users dạng JSON để phục vụ Modal Xem chi tiết
$usersDetailsJson = [];
foreach ($usersList as $u) {
    // Thống kê liên quan đến User
    $statText = '';
    try {
        if ($u['role'] === 'mangaka') {
            $c = $db->prepare("SELECT COUNT(*) FROM series WHERE mangaka_id = ?");
            $c->execute([$u['id']]);
            $num = $c->fetchColumn();
            $statText = "Đang quản lý $num bộ truyện.";
        } elseif ($u['role'] === 'assistant') {
            $c = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ?");
            $c->execute([$u['id']]);
            $num = $c->fetchColumn();
            
            $c2 = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'approved'");
            $c2->execute([$u['id']]);
            $numApp = $c2->fetchColumn();
            $statText = "Được giao $num nhiệm vụ ($numApp đã duyệt hoàn thành).";
        } elseif ($u['role'] === 'editor') {
            $c = $db->prepare("SELECT COUNT(*) FROM annotations WHERE editor_id = ?");
            $c->execute([$u['id']]);
            $num = $c->fetchColumn();
            $statText = "Đã thực hiện $num ghi chú/chỉ dẫn bản thảo.";
        } elseif ($u['role'] === 'board') {
            $c = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by = ?");
            $c->execute([$u['id']]);
            $num = $c->fetchColumn();
            $statText = "Chịu trách nhiệm BBT đề xuất/phê duyệt xuất bản.";
        }
    } catch (\Throwable $e) {}

    $usersDetailsJson[$u['id']] = [
        'id'         => $u['id'],
        'username'   => $u['username'],
        'email'      => $u['email'],
        'role'       => $u['role'],
        'avatar'     => $u['avatar'] ? BASE_URL . 'assets/uploads/' . $u['avatar'] : null,
        'bio'        => $u['bio'] ?: 'Chưa cập nhật giới thiệu bản thân.',
        'is_active'  => (int)$u['is_active'],
        'created_at' => date('d/m/Y H:i', strtotime($u['created_at'])),
        'stat_text'  => $statText
    ];
}
$jsUsersData = json_encode($usersDetailsJson);

// Danh sách bộ truyện (mọi mangaka)
$searchSeries = trim($_GET['search_series'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');

$seriesQuery = "
    SELECT s.id, s.title, s.genre, s.status, s.cover_image, s.publish_schedule, s.created_at,
           u.username AS mangaka_name
    FROM series s
    JOIN users u ON s.mangaka_id = u.id
    WHERE 1=1
";
$seriesParams = [];

if ($searchSeries !== '') {
    $seriesQuery .= " AND (s.title LIKE ? OR s.genre LIKE ?)";
    $seriesParams[] = "%$searchSeries%";
    $seriesParams[] = "%$searchSeries%";
}

if ($filterStatus !== '') {
    $seriesQuery .= " AND s.status = ?";
    $seriesParams[] = $filterStatus;
}

$seriesQuery .= " ORDER BY s.created_at DESC";
$stmtSeries = $db->prepare($seriesQuery);
$stmtSeries->execute($seriesParams);
$seriesList = $stmtSeries->fetchAll();

// Role label mapping bản địa hóa
$roleDisplayNames = [
    'mangaka'   => 'Họa sĩ (Mangaka)',
    'assistant' => 'Trợ lý (Assistant)',
    'editor'    => 'Biên tập viên (Editor)',
    'board'     => 'Ban biên tập (Board)',
    'admin'     => 'Quản trị viên (Admin)',
];
?>

<style>
/* 🎨 Custom Styling for Premium Administration Module */
.admin-tabs {
    display: flex;
    gap: 10px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
    padding-bottom: 2px;
}
.tab-btn {
    background: none;
    border: none;
    padding: 12px 20px;
    color: var(--text-muted);
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    position: relative;
    transition: all var(--dur) var(--ease);
}
.tab-btn:hover {
    color: var(--text);
    background: var(--bg-hover);
}
.tab-btn.active {
    color: var(--red);
}
.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -3px;
    left: 0;
    width: 100%;
    height: 3px;
    background: var(--red);
    border-radius: 3px;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* User Status Badges */
.badge-active {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.25);
}
.badge-inactive {
    background: rgba(230, 57, 70, 0.12);
    color: #f87171;
    border: 1px solid rgba(230, 57, 70, 0.22);
}

/* Modal styles overrides matching layout */
.modal-backdrop {
    position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,.75); backdrop-filter: blur(5px);
    display: none; align-items: center; justify-content: center;
    padding: 20px;
}
.modal-backdrop.open { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity:0 } to { opacity:1 } }

.modal-box {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); max-width: 600px; width: 100%;
    overflow: hidden;
    animation: slideIn .25s var(--ease);
    box-shadow: 0 25px 60px rgba(0,0,0,0.6);
}
@keyframes slideIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
}
.modal-header h3 { font-size: 1.1rem; font-weight: 700; margin: 0; }
.modal-close {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 1.5rem; padding: 2px 6px; border-radius: 6px;
    line-height: 1;
}
.modal-close:hover { color: var(--red); background: rgba(230,57,70,.1); }
.modal-body { padding: 24px; }
.modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 14px 20px; border-top: 1px solid var(--border);
    background: rgba(0,0,0,.25);
}

.avatar-large {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: var(--red);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 2rem;
    color: #fff;
    margin: 0 auto 16px;
    border: 3px solid rgba(230,57,70,0.3);
    object-fit: cover;
}

.role-badge.admin {
    background: rgba(230,57,70,0.15);
    color: #f87171;
    border: 1px solid rgba(230,57,70,0.25);
}
</style>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>">MangaFlow</a>
        <span class="sep">›</span>
        <span class="current">Hệ thống Quản Trị</span>
    </div>
    <h1>Quản Trị & Cấu Hình Hệ Thống</h1>
    <p>Tổng hợp thống kê, cấu hình đơn giá và quản lý người dùng, tác phẩm sản xuất.</p>
</div>

<!-- Trình thông báo Flash -->
<?php if (!empty($flashMsg)): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?> mb-24" data-auto-dismiss="5000">
    <?= $flashType === 'error' ? '✕' : '✓' ?> <?= htmlspecialchars($flashMsg) ?>
    <button class="alert-close" style="margin-left:auto; background:none; border:none; color:inherit; cursor:pointer;" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="admin-tabs">
    <button class="tab-btn active" onclick="switchTab('overview')">📊 Tổng quan & Cấu hình</button>
    <button class="tab-btn" onclick="switchTab('users')">👥 Quản lý Thành viên</button>
    <button class="tab-btn" onclick="switchTab('series')">📚 Quản lý Bộ truyện</button>
</div>

<!-- ================= Tab 1: Tổng quan & Cấu hình ================= -->
<div id="tab-overview" class="tab-content active">
    <!-- Stat grid -->
    <div class="stat-grid">
        <div class="stat-card" style="--accent: var(--purple); --icon-bg: rgba(139,92,246,0.12);">
            <div class="stat-info">
                <span class="stat-label">Tổng thành viên</span>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-change">Người dùng trong hệ thống</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        </div>
        
        <div class="stat-card" style="--accent: var(--blue); --icon-bg: rgba(59,130,246,0.12);">
            <div class="stat-info">
                <span class="stat-label">Tổng bộ truyện</span>
                <div class="stat-value"><?= $totalSeries ?></div>
                <div class="stat-change">Được tạo bởi họa sĩ</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-book"></i></div>
        </div>
        
        <div class="stat-card" style="--accent: var(--green); --icon-bg: rgba(16,185,129,0.12);">
            <div class="stat-info">
                <span class="stat-label">Tổng chương truyện</span>
                <div class="stat-value"><?= $totalChapters ?></div>
                <div class="stat-change">Đang trong tiến trình</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
        </div>
        
        <div class="stat-card" style="--accent: var(--red); --icon-bg: rgba(230,57,70,0.12);">
            <div class="stat-info">
                <span class="stat-label">Tổng nhiệm vụ vẽ</span>
                <div class="stat-value"><?= $totalTasks ?></div>
                <div class="stat-change">Đã giao cho trợ lý</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-tasks"></i></div>
        </div>
    </div>

    <!-- Configuration section -->
    <div class="grid-2">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">💰 Cài đặt đơn giá trợ lý</h3>
                    <p class="card-subtitle">Đặt đơn giá thanh toán mặc định cho mỗi trang truyện trợ lý vẽ hoàn thành (VND/Trang).</p>
                </div>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_assistant_rate">
                <div class="form-group">
                    <label class="form-label" for="default_assistant_rate">Đơn giá mặc định (VND / Trang)</label>
                    <div style="display:flex; gap:10px;">
                        <input type="number" id="default_assistant_rate" name="default_assistant_rate" class="form-control" value="<?= htmlspecialchars($defaultRate) ?>" required min="0" placeholder="250000" style="font-size:1.1rem; font-weight:700;">
                        <span style="display:flex; align-items:center; color:var(--text-muted); font-size:0.9rem; font-weight:600; padding:0 5px;">VND</span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    💾 Lưu cấu hình
                </button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">💡 Thông tin vận hành</h3>
                    <p class="card-subtitle">Lưu ý khi thay đổi các cài đặt hệ thống.</p>
                </div>
            </div>
            <div style="font-size: 0.9rem; color: var(--text-muted); display:flex; flex-direction:column; gap:12px;">
                <p>📌 <strong>Đơn giá trợ lý:</strong> Được dùng làm căn cứ tự động tính toán thu nhập hàng tháng của trợ lý (Manga Assistant) trong phần bảng lương, trừ khi họa sĩ ghi đè đơn giá cụ thể khi phê duyệt.</p>
                <p>📌 <strong>Kiểm soát tài khoản:</strong> Khi tài khoản bị vô hiệu hóa (deactivated), người dùng đó sẽ bị ngắt kết nối session lập tức khi reload trang và không thể thực hiện đăng nhập lại.</p>
                <p>📌 <strong>Bảo vệ an toàn:</strong> Hệ thống không cho phép Quản trị viên tự vô hiệu hóa tài khoản của chính mình để tránh sự cố mất quyền điều hành đột ngột.</p>
            </div>
        </div>
    </div>
</div>

<!-- ================= Tab 2: Quản lý Thành viên ================= -->
<div id="tab-users" class="tab-content">
    <!-- Tìm kiếm & Bộ lọc -->
    <div class="card mb-24" style="padding: 16px 20px;">
        <form method="GET" action="" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="tab" value="users">
            
            <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:240px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase; white-space:nowrap;">Tìm kiếm:</label>
                <input type="text" name="search_user" class="form-control" placeholder="Tên đăng nhập hoặc email..." value="<?= htmlspecialchars($searchUser) ?>" style="padding: 6px 12px; font-size:0.85rem;">
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase; white-space:nowrap;">Vai trò:</label>
                <select name="filter_role" class="form-control" style="width:160px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                    <option value="">— Tất cả —</option>
                    <?php foreach ($roleDisplayNames as $roleKey => $roleLabel): ?>
                        <option value="<?= $roleKey ?>" <?= $filterRole === $roleKey ? 'selected' : '' ?>><?= $roleLabel ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Tìm kiếm</button>
            
            <?php if ($searchUser !== '' || $filterRole !== ''): ?>
                <a href="index.php?tab=users" class="btn btn-ghost btn-sm">Xóa bộ lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bảng Users -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="padding:14px 18px;">Thành viên</th>
                        <th style="padding:14px 18px;">Email</th>
                        <th style="padding:14px 18px;">Vai trò</th>
                        <th style="padding:14px 18px;">Ngày đăng ký</th>
                        <th style="padding:14px 18px;">Trạng thái</th>
                        <th style="padding:14px 18px; text-align:right;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usersList)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:40px; color:var(--text-muted);">
                                🔍 Không tìm thấy thành viên nào.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usersList as $u):
                            $roleLabel = $roleDisplayNames[$u['role']] ?? ucfirst($u['role']);
                            $isActive = (int)$u['is_active'];
                        ?>
                            <tr>
                                <td style="padding:14px 18px; display:flex; align-items:center; gap:10px;">
                                    <?php if (!empty($u['avatar']) && file_exists(UPLOAD_PATH . $u['avatar'])): ?>
                                        <img src="<?= BASE_URL . 'assets/uploads/' . htmlspecialchars($u['avatar']) ?>" alt="avatar" style="width:32px; height:32px; border-radius:50%; object-fit:cover;">
                                    <?php else: ?>
                                        <div style="width:32px; height:32px; border-radius:50%; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem; color:#fff;">
                                            <?= strtoupper(mb_substr($u['username'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <strong style="font-size:0.9rem;"><?= htmlspecialchars($u['username']) ?></strong>
                                </td>
                                <td style="padding:14px 18px;" class="td-muted"><?= htmlspecialchars($u['email']) ?></td>
                                <td style="padding:14px 18px;">
                                    <span class="role-badge <?= $u['role'] ?>"><?= $roleLabel ?></span>
                                </td>
                                <td style="padding:14px 18px;" class="td-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                                <td style="padding:14px 18px;">
                                    <?php if ($isActive === 1): ?>
                                        <span class="badge badge-active">Hoạt động</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Vô hiệu</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 18px; text-align:right; display:flex; justify-content:flex-end; gap:8px;">
                                    <button class="btn btn-secondary btn-sm" onclick="openUserModal(<?= $u['id'] ?>)">Chi tiết</button>
                                    
                                    <?php if ($u['id'] !== $currentUser['id']): ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $isActive === 1 ? 0 : 1 ?>">
                                            
                                            <?php if ($isActive === 1): ?>
                                                <button type="submit" class="btn btn-danger btn-sm">Vô hiệu hóa</button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-primary btn-sm" style="background:#10b981; color:#fff;">Kích hoạt</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.8rem; padding: 6px 12px; display:inline-block; font-style:italic;">Bản thân</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= Tab 3: Quản lý Bộ truyện ================= -->
<div id="tab-series" class="tab-content">
    <!-- Tìm kiếm & Lọc tác phẩm -->
    <div class="card mb-24" style="padding: 16px 20px;">
        <form method="GET" action="" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="tab" value="series">
            
            <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:240px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase; white-space:nowrap;">Tên truyện / Thể loại:</label>
                <input type="text" name="search_series" class="form-control" placeholder="Tìm tên bộ truyện hoặc thể loại..." value="<?= htmlspecialchars($searchSeries) ?>" style="padding: 6px 12px; font-size:0.85rem;">
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase; white-space:nowrap;">Trạng thái:</label>
                <select name="filter_status" class="form-control" style="width:160px; padding: 6px 12px; font-size:0.85rem;" onchange="this.form.submit()">
                    <option value="">— Tất cả —</option>
                    <?php foreach (STATUS['series'] as $st): ?>
                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Tìm kiếm</button>
            
            <?php if ($searchSeries !== '' || $filterStatus !== ''): ?>
                <a href="index.php?tab=series" class="btn btn-ghost btn-sm">Xóa bộ lọc</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Danh sách truyện tranh dạng bảng hoặc grid -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="padding:14px 18px;">Bìa</th>
                        <th style="padding:14px 18px;">Bộ truyện / Tên tác phẩm</th>
                        <th style="padding:14px 18px;">Họa sĩ (Mangaka)</th>
                        <th style="padding:14px 18px;">Thể loại</th>
                        <th style="padding:14px 18px;">Lịch ra mắt</th>
                        <th style="padding:14px 18px;">Trạng thái duyệt</th>
                        <th style="padding:14px 18px; text-align:right;">Ngày tạo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($seriesList)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
                                🔍 Không tìm thấy bộ truyện nào.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($seriesList as $s): ?>
                            <tr>
                                <td style="padding:14px 18px;">
                                    <?php if (!empty($s['cover_image'])): ?>
                                        <img src="<?= BASE_URL . htmlspecialchars($s['cover_image']) ?>" alt="cover" style="width:40px; height:52px; border-radius:6px; object-fit:cover; border: 1px solid var(--border);">
                                    <?php else: ?>
                                        <div style="width:40px; height:52px; border-radius:6px; background:var(--bg-body); border: 1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:0.7rem; color:var(--text-muted);">No cover</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 18px;">
                                    <strong style="font-size:0.9rem; color:#fff;"><?= htmlspecialchars($s['title']) ?></strong>
                                    <div class="text-xs text-muted">ID: #<?= $s['id'] ?></div>
                                </td>
                                <td style="padding:14px 18px;">
                                    <span class="role-badge mangaka" style="padding:2px 8px; font-size:0.7rem;"><?= htmlspecialchars($s['mangaka_name']) ?></span>
                                </td>
                                <td style="padding:14px 18px;" class="td-muted"><?= htmlspecialchars($s['genre'] ?: 'Chưa cập nhật') ?></td>
                                <td style="padding:14px 18px; text-transform:uppercase; font-weight:700; font-size:0.75rem;">
                                    <?php if ($s['publish_schedule'] === 'weekly'): ?>
                                        <span class="badge badge-purple">Hàng tuần</span>
                                    <?php else: ?>
                                        <span class="badge badge-blue">Hàng tháng</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 18px;">
                                    <span class="badge badge-status-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span>
                                </td>
                                <td style="padding:14px 18px; text-align:right;" class="td-muted">
                                    <?= date('d/m/Y', strtotime($s['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= Modal Xem chi tiết thành viên ================= -->
<div class="modal-backdrop" id="userDetailModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Chi tiết tài khoản thành viên</h3>
            <button class="modal-close" onclick="closeUserModal()">×</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <!-- Avatar/Name -->
            <div id="modalUserAvatarBox"></div>
            <h2 id="modalUserUsername" style="color:#fff; margin-bottom:4px; font-size:1.4rem;">—</h2>
            <div id="modalUserRoleBadge" style="margin-bottom:16px;"></div>
            
            <!-- Info block -->
            <div style="text-align:left; background:rgba(255,255,255,0.03); border:1px solid var(--border); border-radius:8px; padding:16px; font-size:0.9rem; margin-bottom:16px; display:flex; flex-direction:column; gap:10px;">
                <div>
                    <span class="text-muted">Thư điện tử (Email):</span> <strong id="modalUserEmail" style="color:#fff; margin-left:5px;">—</strong>
                </div>
                <div>
                    <span class="text-muted">Ngày tham gia:</span> <strong id="modalUserJoined" style="color:#fff; margin-left:5px;">—</strong>
                </div>
                <div>
                    <span class="text-muted">Trạng thái:</span> <span id="modalUserStatus" class="badge" style="margin-left:5px;">—</span>
                </div>
                <div style="border-top: 1px solid var(--border); padding-top:8px; margin-top:4px;">
                    <span class="text-muted">Vận hành:</span> <strong id="modalUserStatText" style="color:#fff; display:block; margin-top:4px;">—</strong>
                </div>
            </div>

            <!-- Bio -->
            <div style="text-align:left;">
                <label class="text-xs text-muted font-bold" style="text-transform:uppercase;">Giới thiệu / Bio:</label>
                <div id="modalUserBio" style="background:rgba(0,0,0,0.15); border:1px solid var(--border); border-radius:8px; padding:12px 14px; font-size:0.88rem; line-height:1.6; color:var(--text); white-space:pre-wrap; margin-top:6px; max-height:120px; overflow-y:auto;">
                    —
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeUserModal()">Đóng</button>
        </div>
    </div>
</div>

<script>
// Nhận dữ liệu chi tiết của Users
const USERS_DATA = <?= $jsUsersData ?>;

// Hàm switch tab và đồng bộ URL query parameter
function switchTab(tabId) {
    // Ẩn tất cả tab contents
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    // Bỏ kích hoạt các nút tab
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Kích hoạt tab và nút tương ứng
    document.getElementById('tab-' + tabId).classList.add('active');
    
    // Tìm và kích hoạt nút
    const buttons = document.querySelectorAll('.tab-btn');
    if (tabId === 'overview') buttons[0].classList.add('active');
    else if (tabId === 'users') buttons[1].classList.add('active');
    else if (tabId === 'series') buttons[2].classList.add('active');

    // Cập nhật query parameter mà không cần reload trang
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// Khi tải trang xong, kiểm tra URL xem có tab nào được cấu hình từ trước không
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const initialTab = urlParams.get('tab') || 'overview';
    switchTab(initialTab);
});

// Modal điều khiển
function openUserModal(userId) {
    const user = USERS_DATA[userId];
    if (!user) return;

    // Thiết lập Avatar
    const avatarBox = document.getElementById('modalUserAvatarBox');
    if (user.avatar) {
        avatarBox.innerHTML = `<img src="${user.avatar}" class="avatar-large" alt="avatar">`;
    } else {
        const letter = user.username.substring(0, 1).toUpperCase();
        avatarBox.innerHTML = `<div class="avatar-large">${letter}</div>`;
    }

    // Thiết lập Text
    document.getElementById('modalUserUsername').textContent = user.username;
    document.getElementById('modalUserEmail').textContent = user.email;
    document.getElementById('modalUserJoined').textContent = user.created_at;
    document.getElementById('modalUserBio').textContent = user.bio;
    document.getElementById('modalUserStatText').textContent = user.stat_text || 'Không có dữ liệu thống kê liên quan.';

    // Vai trò Badge
    const roleColors = {
        'mangaka':   'Họa sĩ Manga',
        'assistant': 'Trợ lý Manga',
        'editor':    'Biên tập viên',
        'board':     'Ban biên tập',
        'admin':     'Quản trị viên',
    };
    const roleLabel = roleColors[user.role] || user.role;
    const roleBadge = document.getElementById('modalUserRoleBadge');
    roleBadge.innerHTML = `<span class="role-badge ${user.role}">${roleLabel}</span>`;

    // Trạng thái Badge
    const statusBadge = document.getElementById('modalUserStatus');
    if (user.is_active === 1) {
        statusBadge.textContent = 'Đang hoạt động';
        statusBadge.className = 'badge badge-active';
    } else {
        statusBadge.textContent = 'Vô hiệu hóa';
        statusBadge.className = 'badge badge-inactive';
    }

    // Mở modal
    document.getElementById('userDetailModal').classList.add('open');
}

function closeUserModal() {
    document.getElementById('userDetailModal').classList.remove('open');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
