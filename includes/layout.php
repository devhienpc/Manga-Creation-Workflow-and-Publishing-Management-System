<?php
/**
 * includes/layout.php
 * Tất cả các trang dashboard protected đều require_once file này.
 *
 * Biến tùy chọn (đặt TRƯỚC khi require_once layout.php):
 *   $pageTitle  (string) — tiêu đề trang, hiển thị trong <title> và header
 *   $activePage (string) — tên page hiện tại để highlight menu
 *   $allowedRoles (array) — danh sách role được phép truy cập trang này
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// Yêu cầu đăng nhập
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Phân quyền theo role (nếu trang có định nghĩa $allowedRoles)
if (!empty($allowedRoles) && !in_array($currentUser['role'], $allowedRoles)) {
    // Redirect về dashboard của role hiện tại
    $role = $currentUser['role'];
    header('Location: ' . BASE_URL . $role . '/dashboard.php');
    exit();
}

$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? '';

// Lấy số thông báo chưa đọc (dùng lại trong sidebar badge)
$unreadCount = 0;
try {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$currentUser['id']]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {error_log("Lỗi đếm thông báo (layout.php): " . $e->getMessage());}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — MangaFlow</title>
    <meta name="description" content="MangaFlow — Hệ thống quản lý quy trình sản xuất truyện tranh Manga">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Bangers&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@flaticon/flaticon-uicons@3.3.1/css/all/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/main.css">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet" href="<?= BASE_URL . htmlspecialchars($extraCss) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/main.css">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet" href="<?= BASE_URL . htmlspecialchars($extraCss) ?>">
    <?php endif; ?>

    <!-- ▼▼▼ CHÈN ĐOẠN CODE HIỆU ỨNG VÀO ĐÂY ▼▼▼ -->
    <?php if (isset($currentUser) && $currentUser['role'] === ROLES['ASSISTANT']): ?>
    <style>
    /* =========================================================
       1. HIỆU ỨNG XUẤT HIỆN KHI TẢI TRANG (FADE & SLIDE UP)
    ========================================================= */
    @keyframes fadeSlideUp {
        0% { opacity: 0; transform: translateY(15px); }
        100% { opacity: 1; transform: translateY(0); }
    }

    .page-header { 
        animation: fadeSlideUp 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; 
    }
    .stat-grid, .stats-grid { 
        opacity: 0; 
        animation: fadeSlideUp 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) 0.15s forwards; 
    }
    .grid-2, .filter-bar { 
        opacity: 0; 
        animation: fadeSlideUp 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) 0.3s forwards; 
    }
    #tasksListContainer {
        opacity: 0;
        animation: fadeSlideUp 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) 0.45s forwards;
    }

    /* =========================================================
       2. HIỆU ỨNG TƯƠNG TÁC KHI DI CHUỘT VÀO THẺ (CARDS)
    ========================================================= */
    .card, .stat-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease !important;
        will-change: transform;
    }
    .card:hover, .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.2) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    .stat-icon {
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .stat-card:hover .stat-icon {
        transform: scale(1.15) rotate(8deg); 
    }

    /* =========================================================
       3. HIỆU ỨNG TƯƠNG TÁC CHO BẢNG & DANH SÁCH (TABLE / LIST)
    ========================================================= */
    tbody tr, .task-row {
        transition: transform 0.25s ease, background-color 0.25s ease !important;
    }
    tbody tr:hover {
        transform: translateX(4px);
        background-color: rgba(255,255,255,0.06) !important;
    }

    .grid-2 > div:last-child .card > div > div {
        transition: transform 0.25s ease;
    }
    .grid-2 > div:last-child .card > div > div:hover {
        transform: translateX(4px);
    }

    /* =========================================================
       4. HIỆU ỨNG TƯƠNG TÁC CHO NÚT BẤM (BUTTONS & FILTERS)
    ========================================================= */
    .btn, .filter-btn {
        transition: transform 0.2s ease, filter 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease !important;
    }
    .btn:hover, .filter-btn:hover {
        transform: scale(1.03);
        filter: brightness(1.1);
    }
    .btn:active, .filter-btn:active {
        transform: scale(0.96);
    }

    @keyframes pulseGlow {
        0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
        70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
        100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    .badge-red {
        animation: pulseGlow 2s infinite;
    }
    </style>
    <?php endif; ?>
</head>
<body>
<div class="app-shell">

<?php
// Render sidebar (có quyền truy cập $currentUser, $activePage, $unreadCount)
require_once __DIR__ . '/sidebar.php';
?>

<div class="main-area">

<?php
// Render header (có quyền truy cập $currentUser, $pageTitle, $unreadCount)
require_once __DIR__ . '/header.php';
?>

    <div class="page-content">
<!-- ▼▼▼ NỘI DUNG TRANG BẮT ĐẦU TỪ ĐÂY ▼▼▼ -->
