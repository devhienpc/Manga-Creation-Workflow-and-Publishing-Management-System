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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/main.css">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet" href="<?= BASE_URL . htmlspecialchars($extraCss) ?>">
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
