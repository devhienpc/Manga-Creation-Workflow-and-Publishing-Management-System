<?php
/**
 * includes/sidebar.php
 * Biến yêu cầu: $currentUser (array), $activePage (string — tên page hiện tại)
 */
if (!isset($currentUser)) {
    $currentUser = getCurrentUser();
}
$role       = $currentUser['role'];
$activePage = $activePage ?? '';

// Role label và màu sắc
$roleLabels = [
    'mangaka'   => 'Họa sĩ Manga',
    'assistant' => 'Trợ lý Manga',
    'editor'    => 'Biên tập viên',
    'board'     => 'Ban biên tập',
    'admin'     => 'Quản trị viên',
];

// Định nghĩa menu theo role
$menus = [
    'mangaka' => [
        [
            'label' => 'CHÍNH',
            'items' => [
                ['page' => 'dashboard', 'label' => 'Dashboard',         'href' => BASE_URL . 'mangaka/dashboard.php',  'icon' => 'grid'],
                ['page' => 'series',    'label' => 'Bộ truyện của tôi', 'href' => BASE_URL . 'mangaka/series.php',     'icon' => 'book'],
            ]
        ],
        [
            'label' => 'QUẢN LÝ',
            'items' => [
                ['page' => 'tasks',    'label' => 'Task Manager',   'href' => BASE_URL . 'mangaka/tasks.php',   'icon' => 'check-square'],
                ['page' => 'ranking',  'label' => 'BXH Truyện',     'href' => BASE_URL . 'mangaka/ranking.php', 'icon' => 'trending-up'],
                ['page' => 'defense',  'label' => 'Bảo vệ tác phẩm', 'href' => BASE_URL . 'mangaka/defense.php', 'icon' => 'shield'],
                ['page' => 'notifs',   'label' => 'Thông báo',      'href' => BASE_URL . 'mangaka/notifications.php', 'icon' => 'bell', 'badge' => true],
            ]
        ],
    ],
    'assistant' => [
        [
            'label' => 'CHÍNH',
            'items' => [
                ['page' => 'dashboard', 'label' => 'Dashboard',       'href' => BASE_URL . 'assistant/dashboard.php', 'icon' => 'grid'],
                ['page' => 'tasks',     'label' => 'Nhiệm vụ của tôi', 'href' => BASE_URL . 'assistant/tasks.php',    'icon' => 'clipboard'],
                ['page' => 'earnings',  'label' => 'Thu nhập',         'href' => BASE_URL . 'assistant/earnings.php',  'icon' => 'dollar-sign'],
            ]
        ],
    ],
    'editor' => [
        [
            'label' => 'CHÍNH',
            'items' => [
                ['page' => 'dashboard',    'label' => 'Dashboard',         'href' => BASE_URL . 'editor/dashboard.php',   'icon' => 'grid'],
                ['page' => 'manuscripts',  'label' => 'Bản thảo duyệt',    'href' => BASE_URL . 'editor/manuscripts.php', 'icon' => 'file-text'],
            ]
        ],
        [
            'label' => 'THEO DÕI',
            'items' => [
                ['page' => 'progress',  'label' => 'Tiến độ Studio',    'href' => BASE_URL . 'editor/progress.php',   'icon' => 'activity'],
                ['page' => 'defense',   'label' => 'Bảo vệ tác phẩm',  'href' => BASE_URL . 'editor/defense.php',    'icon' => 'shield'],
            ]
        ],
    ],
    'board' => [
        [
            'label' => 'CHÍNH',
            'items' => [
                ['page' => 'dashboard',  'label' => 'Dashboard',          'href' => BASE_URL . 'board/dashboard.php',  'icon' => 'grid'],
                ['page' => 'voting',     'label' => 'Bình chọn & Đánh giá','href' => BASE_URL . 'board/voting.php',    'icon' => 'bar-chart-2'],
            ]
        ],
        [
            'label' => 'QUẢN TRỊ',
            'items' => [
                ['page' => 'ranking',   'label' => 'Xếp hạng tổng quát', 'href' => BASE_URL . 'board/ranking.php',   'icon' => 'award'],
                ['page' => 'decisions', 'label' => 'Quyết định xuất bản', 'href' => BASE_URL . 'board/decisions.php', 'icon' => 'check-circle'],
                ['page' => 'admin_dashboard', 'label' => 'Quản trị hệ thống', 'href' => BASE_URL . 'admin/index.php', 'icon' => 'grid'],
            ]
        ],
    ],
    'admin' => [
        [
            'label' => 'QUẢN TRỊ HỆ THỐNG',
            'items' => [
                ['page' => 'admin_dashboard', 'label' => 'Dashboard Admin', 'href' => BASE_URL . 'admin/index.php', 'icon' => 'grid'],
            ]
        ],
    ],
];

// SVG icons helper
function navIcon(string $name): string {
    $icons = [
        'grid'         => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'book'         => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'check-square' => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'trending-up'  => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'bell'         => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'clipboard'    => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'dollar-sign'  => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'file-text'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'activity'     => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'bar-chart-2'  => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'award'        => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    ];
    $d = $icons[$name] ?? '<circle cx="12" cy="12" r="5"/>';
    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

$currentMenuGroups = $menus[$role] ?? [];
?>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<aside id="appSidebar" class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-icon">MF</div>
        <span class="brand-name">MangaFlow</span>
    </div>

    <!-- User role strip -->
    <div class="sidebar-role">
        <span class="role-badge <?= $role ?>"><?= htmlspecialchars($roleLabels[$role] ?? $role) ?></span>
    </div>

    <!-- Navigation -->
    <nav class="nav-group" aria-label="Điều hướng chính">
        <?php foreach ($currentMenuGroups as $group): ?>
            <p class="nav-group-label"><?= htmlspecialchars($group['label']) ?></p>
            <?php foreach ($group['items'] as $item): ?>
                <div class="nav-item">
                    <a href="<?= htmlspecialchars($item['href']) ?>"
                       class="nav-link <?= ($activePage === $item['page']) ? 'active' : '' ?>"
                       data-path="<?= htmlspecialchars(parse_url($item['href'], PHP_URL_PATH)) ?>">
                        <?= navIcon($item['icon']) ?>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                        <?php if (!empty($item['badge']) && $unreadCount > 0): ?>
                            <span class="nav-badge"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- User + logout at bottom -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?php if (!empty($currentUser['avatar']) && file_exists(UPLOAD_PATH . $currentUser['avatar'])): ?>
                <img src="<?= BASE_URL . 'assets/uploads/' . htmlspecialchars($currentUser['avatar']) ?>"
                     alt="avatar" class="user-avatar">
            <?php else: ?>
                <div class="user-avatar">
                    <?= strtoupper(mb_substr($currentUser['username'], 0, 1)) ?>
                </div>
            <?php endif; ?>

            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentUser['fullname'] ?? $currentUser['username']) ?></div>
                <div class="user-role-txt"><?= htmlspecialchars($roleLabels[$role] ?? $role) ?></div>
            </div>

            <a href="<?= BASE_URL ?>auth/logout.php" class="logout-btn" title="Đăng xuất">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </a>
        </div>
    </div>
</aside>
