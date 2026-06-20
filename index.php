<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/auth.php';

// Kiểm tra đăng nhập. Nếu chưa đăng nhập, checkAccess sẽ redirect về auth/login.php.
// Nếu đã đăng nhập, nó sẽ chuyển hướng đến Dashboard tương ứng của vai trò.
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
} else {
    redirectDashboard($_SESSION['user']['role']);
}
