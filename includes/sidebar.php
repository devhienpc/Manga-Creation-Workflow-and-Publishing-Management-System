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

$db  = getDB();
$uid = $currentUser['id'];

// Thống kê cho badge sidebar
$submittedTasksCount = 0;
if ($role === 'mangaka') {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM tasks t
            JOIN pages p ON t.page_id=p.id
            JOIN chapters c ON p.chapter_id=c.id
            JOIN series s ON c.series_id=s.id
            WHERE s.mangaka_id = ? AND t.status = 'submitted'
        ");
        $stmt->execute([$uid]);
        $submittedTasksCount = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log("Sidebar submitted tasks count error: " . $e->getMessage());
    }
}

$revisionTasksCount = 0;
if ($role === 'assistant') {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM tasks 
            WHERE assigned_to = ? AND status = 'revision'
        ");
        $stmt->execute([$uid]);
        $revisionTasksCount = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log("Sidebar revision tasks count error: " . $e->getMessage());
    }
}

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
                ['page' => 'tasks',    'label' => 'Task Manager',   'href' => BASE_URL . 'mangaka/tasks.php',   'icon' => 'check-square', 'badge_type' => 'submitted_tasks'],
                ['page' => 'ranking',  'label' => 'BXH Truyện',     'href' => BASE_URL . 'mangaka/ranking.php', 'icon' => 'trending-up'],
                ['page' => 'defense',  'label' => 'Bảo vệ tác phẩm', 'href' => BASE_URL . 'mangaka/defense.php', 'icon' => 'shield'],
                ['page' => 'notifs',   'label' => 'Thông báo',      'href' => BASE_URL . 'mangaka/notifications.php', 'icon' => 'bell', 'badge' => true],
            ]
        ],
        [
            'label' => 'TÀI CHÍNH',
            'items' => [
                ['page' => 'wallet_index', 'label' => 'Ví của tôi',  'href' => BASE_URL . 'wallet/index.php',    'icon' => 'wallet'],
                ['page' => 'wallet_withdraw', 'label' => 'Rút tiền',  'href' => BASE_URL . 'wallet/withdraw.php', 'icon' => 'withdraw'],
                ['page' => 'wallet_withdrawals', 'label' => 'Lịch sử rút', 'href' => BASE_URL . 'wallet/withdrawals.php', 'icon' => 'history'],
            ]
        ],
        [
            'label' => 'AI TOOLS',
            'items' => [
                ['page' => 'ai_colorize', 'label' => 'Tô màu tự động',  'href' => BASE_URL . 'ai/colorize.php', 'icon' => 'zap', 'ai' => true],
                ['page' => 'ai_segment',  'label' => 'Phân đoạn trang', 'href' => BASE_URL . 'ai/segment.php',  'icon' => 'cpu', 'ai' => true],
            ]
        ],
        [
            'label' => 'TÀI KHOẢN',
            'items' => [
                ['page' => 'profile', 'label' => 'Hồ sơ của tôi', 'href' => BASE_URL . 'profile.php', 'icon' => 'user-circle'],
            ]
        ],
    ],
    'assistant' => [
        [
            'label' => 'CHÍNH',
            'items' => [
                ['page' => 'dashboard', 'label' => 'Dashboard',       'href' => BASE_URL . 'assistant/dashboard.php', 'icon' => 'grid'],
                ['page' => 'tasks',     'label' => 'Nhiệm vụ của tôi', 'href' => BASE_URL . 'assistant/tasks.php',    'icon' => 'clipboard', 'badge_type' => 'revision_tasks'],
            ]
        ],
        [
            'label' => 'THU NHẬP',
            'items' => [
                ['page' => 'wallet_index', 'label' => 'Ví của tôi',  'href' => BASE_URL . 'wallet/index.php',    'icon' => 'wallet'],
                ['page' => 'wallet_withdraw', 'label' => 'Rút tiền',  'href' => BASE_URL . 'wallet/withdraw.php', 'icon' => 'withdraw'],
                ['page' => 'earnings',  'label' => 'Bảng lương',     'href' => BASE_URL . 'assistant/earnings.php',  'icon' => 'receipt'],
                ['page' => 'wallet_withdrawals', 'label' => 'Lịch sử rút', 'href' => BASE_URL . 'wallet/withdrawals.php', 'icon' => 'history'],
            ]
        ],
        [
            'label' => 'AI TOOLS',
            'items' => [
                ['page' => 'ai_colorize', 'label' => 'Tô màu tự động', 'href' => BASE_URL . 'ai/colorize.php', 'icon' => 'zap', 'ai' => true],
            ]
        ],
        [
            'label' => 'TÀI KHOẢN',
            'items' => [
                ['page' => 'profile', 'label' => 'Hồ sơ của tôi', 'href' => BASE_URL . 'profile.php', 'icon' => 'user-circle'],
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
        [
            'label' => 'TÀI KHOẢN',
            'items' => [
                ['page' => 'profile', 'label' => 'Hồ sơ của tôi', 'href' => BASE_URL . 'profile.php', 'icon' => 'user-circle'],
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
                ['page' => 'ranking',      'label' => 'Xếp hạng tổng quát', 'href' => BASE_URL . 'board/ranking.php',      'icon' => 'award'],
                ['page' => 'decisions',    'label' => 'Quyết định xuất bản', 'href' => BASE_URL . 'board/decisions.php',    'icon' => 'check-circle'],
                ['page' => 'withdrawals',  'label' => 'Quản lý rút tiền',  'href' => BASE_URL . 'admin/withdrawals.php', 'icon' => 'money-check'],
                ['page' => 'fin_stats',    'label' => 'Thống kê tài chính', 'href' => BASE_URL . 'admin/withdrawals.php#tab-stats', 'icon' => 'stats'],
                ['page' => 'admin_dashboard', 'label' => 'Quản trị hệ thống', 'href' => BASE_URL . 'admin/index.php',     'icon' => 'grid'],
            ]
        ],
        [
            'label' => 'TÀI KHOẢN',
            'items' => [
                ['page' => 'profile', 'label' => 'Hồ sơ của tôi', 'href' => BASE_URL . 'profile.php', 'icon' => 'user-circle'],
            ]
        ],
    ],
    'admin' => [
        [
            'label' => 'QUẢN TRỊ HỆ THỐNG',
            'items' => [
                ['page' => 'admin_dashboard', 'label' => 'Dashboard Admin',    'href' => BASE_URL . 'admin/index.php',         'icon' => 'grid'],
                ['page' => 'withdrawals',     'label' => 'Quản lý rút tiền',  'href' => BASE_URL . 'admin/withdrawals.php',   'icon' => 'money-check'],
                ['page' => 'fin_stats',       'label' => 'Thống kê tài chính', 'href' => BASE_URL . 'admin/withdrawals.php#tab-stats', 'icon' => 'stats'],
            ]
        ],
    ],
];


// SVG / Font icons helper
function navIcon(string $name): string {
    $icons = [
        'grid'         => 'fi-rr-apps',
        'book'         => 'fi-rr-book',
        'check-square' => 'fi-rr-checkbox',
        'trending-up'  => 'fi-rr-chart-line-up',
        'bell'         => 'fi-rr-bell',
        'clipboard'    => 'fi-rr-clipboard',
        'dollar-sign'  => 'fi-rr-usd-circle',
        'receipt'      => 'fi-rr-receipt', // Thay cho Bảng lương
        'file-text'    => 'fi-rr-document',
        'activity'     => 'fi-rr-pulse',
        'shield'       => 'fi-rr-shield',
        'bar-chart-2'  => 'fi-rr-chart-histogram',
        'award'        => 'fi-rr-award',
        'check-circle' => 'fi-rr-check-circle',
        'user-circle'  => 'fi-rr-user',
        'wallet'       => 'fi-rr-wallet',
        'money-check'  => 'fi-rr-money-check',
        'withdraw'     => 'fi-rr-money-bill-wave', // Đã đổi thành icon rút tiền đẹp hơn
        'history'      => 'fi-rr-time-past',
        'stats'        => 'fi-rr-chart-pie',
        // AI Tools icons
        'zap'          => 'fi-rr-bolt',
        'cpu'          => 'fi-rr-cpu',
    ];
    $class = $icons[$name] ?? 'fi-rr-circle';
    return '<i class="fi ' . $class . ' nav-icon" aria-hidden="true"></i>';
}

$currentMenuGroups = $menus[$role] ?? [];
?>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<aside id="appSidebar" class="sidebar">

    <!-- Brand -->
    <a href="<?= BASE_URL . $role . '/dashboard.php' ?>" class="sidebar-brand" style="text-decoration:none;" title="Về trang chủ">
        <div class="brand-icon">MS</div>
        <span class="brand-name">Manga System</span>
    </a>

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
                        <?php if (!empty($item['badge']) && ($unreadCount ?? 0) > 0): ?>
                            <span class="nav-badge"><?= $unreadCount ?? 0 ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['badge_type'])): ?>
                            <?php if ($item['badge_type'] === 'submitted_tasks' && $submittedTasksCount > 0): ?>
                                <span class="nav-badge" style="background-color: #e74c3c;"><?= $submittedTasksCount ?></span>
                            <?php elseif ($item['badge_type'] === 'revision_tasks' && $revisionTasksCount > 0): ?>
                                <span class="nav-badge" style="background-color: #f39c12; color: #fff;"><?= $revisionTasksCount ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($item['ai'])): ?>
                            <span style="font-size:.5rem;font-weight:800;letter-spacing:.5px;padding:1px 5px;border-radius:100px;background:linear-gradient(135deg,#7B2FBE,#a855f7);color:#fff;margin-left:auto;flex-shrink:0;">AI</span>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- User + logout at bottom -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?php if (avatarFileExists($currentUser['avatar'] ?? '')): ?>
                <img src="<?= htmlspecialchars(avatarImageUrl($currentUser['avatar'])) . '?t=' . avatarFileMtime($currentUser['avatar']) ?>"
                     alt="avatar" class="user-avatar">
            <?php else: ?>
                <div class="user-avatar">
                    <?= strtoupper(mb_substr($currentUser['username'] ?? '', 0, 1)) ?>
                </div>
            <?php endif; ?>

            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($currentUser['fullname'] ?? $currentUser['username']) ?></div>
                <div class="user-role-txt"><?= htmlspecialchars($roleLabels[$role] ?? $role) ?></div>
            </div>

            <a href="<?= BASE_URL ?>auth/logout.php" class="logout-btn" title="Đăng xuất">
                <i class="fi fi-rr-exit" style="font-size: 17px; display: inline-flex; align-items: center;"></i>
            </a>
        </div>
    </div>
</aside>
