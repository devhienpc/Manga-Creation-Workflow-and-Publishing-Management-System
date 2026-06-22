<?php
// Khởi tạo session nếu chưa có
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', 'Manga System');

// Tự động nhận diện Base URL động
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$projectRoot = str_replace('\\', '/', dirname(__DIR__));

$relativeRoot = '';
if (!empty($docRoot) && strpos($projectRoot, $docRoot) === 0) {
    $relativeRoot = substr($projectRoot, strlen($docRoot));
} else {
    $relativeRoot = preg_replace('/(config|auth|mangaka|assistant|editor|board|api|includes|admin)\/.*$/i', '', $scriptName);
}
$relativeRoot = '/' . ltrim(str_replace('\\', '/', $relativeRoot), '/');
$relativeRoot = rtrim($relativeRoot, '/') . '/';

$baseUrl = $protocol . $host . $relativeRoot;
define('BASE_URL', $baseUrl);

// Hằng số UPLOAD_PATH
define('UPLOAD_PATH', dirname(__DIR__) . '/assets/uploads/');

// Định nghĩa vai trò (ROLES)
define('ROLES', [
    'MANGAKA' => 'mangaka',
    'ASSISTANT' => 'assistant',
    'EDITOR' => 'editor',
    'BOARD' => 'board',
    'ADMIN' => 'admin'
]);

// Định nghĩa vai trò dưới dạng hằng số đơn lẻ (Tương thích ngược)
define('ROLE_MANGAKA', 'mangaka');
define('ROLE_ASSISTANT', 'assistant');
define('ROLE_EDITOR', 'editor');
define('ROLE_BOARD', 'board');
define('ROLE_ADMIN', 'admin');

// Định nghĩa các trạng thái (STATUS)
define('STATUS', [
    'series' => ['draft', 'submitted', 'approved', 'publishing', 'cancelled'],
    'chapter' => ['planning', 'in_progress', 'review', 'approved', 'published'],
    'page' => ['pending', 'in_progress', 'approved', 'revision'],
    'manuscript' => ['pending', 'reviewing', 'approved', 'rejected'],
    'task' => ['pending', 'in_progress', 'submitted', 'approved', 'revision'],
    'submission' => ['pending', 'approved', 'rejected'],
    'annotation' => ['open', 'resolved']
]);
