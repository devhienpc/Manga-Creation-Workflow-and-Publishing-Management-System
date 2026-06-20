<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Chưa đăng nhập hệ thống']);
    exit();
}

echo json_encode([
    'status' => 'success',
    'message' => 'Cổng kết nối AJAX của Manga System hoạt động bình thường',
    'timestamp' => time()
]);
