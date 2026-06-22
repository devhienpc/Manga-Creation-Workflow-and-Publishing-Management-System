<?php
/**
 * includes/header.php
 * Yêu cầu: $currentUser đã được set (từ header.php parent hoặc dashboard)
 * Biến tùy chọn: $pageTitle (string)
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($currentUser)) {
    $currentUser = getCurrentUser();
}

// Lấy số thông báo chưa đọc
$unreadCount = 0;
try {
    $db   = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$currentUser['id']]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {
    // Bỏ qua nếu bảng chưa tồn tại
}

// Lấy 5 thông báo gần nhất
$notifications = [];
try {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id, type, message, is_read, link, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$currentUser['id']]);
    $notifications = $stmt->fetchAll();
} catch (\Throwable $e) {}

// Tiêu đề hiển thị
$headerTitle = $pageTitle ?? 'Dashboard';

// Role label mapping
$roleLabels = [
    'mangaka'   => 'Họa sĩ',
    'assistant' => 'Trợ lý',
    'editor'    => 'Biên tập',
    'board'     => 'Ban BBT',
    'admin'     => 'Admin',
];
$roleLabel = $roleLabels[$currentUser['role']] ?? ucfirst($currentUser['role']);
?>
<header class="header">
    <!-- Hamburger (mobile) -->
    <button id="hamburgerBtn" class="header-hamburger" aria-label="Mở menu" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
            <line x1="3" y1="6"  x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>

    <!-- Page title -->
    <div class="header-title">
        <?php echo htmlspecialchars($headerTitle); ?>
    </div>

    <!-- Actions -->
    <div class="header-actions" style="position: relative;">

        <!-- Notification bell -->
        <button id="notifBell" class="notif-btn" aria-label="Thông báo" title="Thông báo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php if ($unreadCount > 0): ?>
                <span class="notif-count" id="notifCount"><?= $unreadCount ?></span>
            <?php else: ?>
                <span class="notif-count" id="notifCount" style="display:none">0</span>
            <?php endif; ?>
        </button>

        <!-- Notification dropdown -->
        <div id="notifDropdown" class="notif-dropdown">
            <div class="notif-header">
                <strong>Thông báo</strong>
                <?php if (!empty($notifications)): ?>
                    <button class="notif-mark-all" id="markAllRead">Đánh dấu đã đọc</button>
                <?php endif; ?>
            </div>
            <div class="notif-list">
                <?php if (empty($notifications)): ?>
                    <div class="notif-empty">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;opacity:0.3">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <p>Không có thông báo mới</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <div class="notif-item <?= $notif['is_read'] ? '' : 'unread' ?>"
                             data-id="<?= $notif['id'] ?>"
                             data-link="<?= htmlspecialchars(BASE_URL . ltrim($notif['link'] ?? '', '/')) ?>">
                            <?php if (!$notif['is_read']): ?>
                                <div class="notif-dot"></div>
                            <?php else: ?>
                                <div style="width:8px;flex-shrink:0"></div>
                            <?php endif; ?>
                            <div>
                                <div class="notif-text"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="notif-time"><?= htmlspecialchars(date('d/m H:i', strtotime($notif['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- User info pill -->
        <div class="header-user">
            <?php if (!empty($currentUser['avatar']) && file_exists(UPLOAD_PATH . $currentUser['avatar'])): ?>
                <img src="<?= BASE_URL . 'assets/uploads/' . htmlspecialchars($currentUser['avatar']) ?>"
                     alt="avatar" class="user-avatar">
            <?php else: ?>
                <div class="user-avatar">
                    <?= strtoupper(mb_substr($currentUser['username'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <span class="header-user-name"><?= htmlspecialchars($currentUser['fullname'] ?? $currentUser['username']) ?></span>
            <span class="role-badge <?= $currentUser['role'] ?>"><?= $roleLabel ?></span>
        </div>

        <!-- Logout button (hidden on mobile) -->
        <a href="<?= BASE_URL ?>auth/logout.php" class="header-logout" title="Đăng xuất">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Đăng xuất
        </a>
    </div>
</header>
