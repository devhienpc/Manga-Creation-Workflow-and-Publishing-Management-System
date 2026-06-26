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

/**
 * Trả về URL đầy đủ cho ảnh bìa bộ truyện.
 * Xử lý 2 trường hợp:
 *   - Seed data cũ: 'assets/images/covers/xxx.png' → BASE_URL . 'assets/images/covers/xxx.png'
 *   - User upload:  'covers/mg_xxx.jpg'            → BASE_URL . 'assets/uploads/covers/mg_xxx.jpg'
 */
function coverImageUrl(?string $cover): ?string {
    if (empty($cover)) return null;
    // Nếu đã có tiền tố 'assets/' thì dùng thẳng BASE_URL
    if (strpos($cover, 'assets/') === 0) {
        return BASE_URL . $cover;
    }
    // Ngược lại coi là file được upload vào assets/uploads/
    return BASE_URL . 'assets/uploads/' . $cover;
}

/**
 * Trả về URL đầy đủ cho ảnh đại diện của user.
 * Hỗ trợ seed data và user upload.
 */
function avatarImageUrl(?string $avatar): ?string {
    if (empty($avatar)) return null;
    if (strpos($avatar, 'http://') === 0 || strpos($avatar, 'https://') === 0) {
        return $avatar;
    }
    if (strpos($avatar, 'assets/') === 0) {
        return BASE_URL . $avatar;
    }
    if (strpos($avatar, 'avatars/') === 0) {
        return BASE_URL . 'assets/uploads/' . $avatar;
    }
    return BASE_URL . 'assets/uploads/avatars/' . $avatar;
}

/**
 * Kiểm tra xem ảnh đại diện của user có tồn tại thực sự trên ổ đĩa hay không.
 */
function avatarFileExists(?string $avatar): bool {
    if (empty($avatar)) return false;
    if (strpos($avatar, 'assets/') === 0) {
        return file_exists(dirname(__DIR__) . '/' . $avatar);
    }
    if (strpos($avatar, 'avatars/') === 0) {
        return file_exists(UPLOAD_PATH . $avatar);
    }
    return file_exists(UPLOAD_PATH . 'avatars/' . $avatar);
}

/**
 * Trả về thời gian chỉnh sửa cuối của ảnh đại diện để chống cache trình duyệt.
 */
function avatarFileMtime(?string $avatar): int {
    if (empty($avatar)) return time();
    $path = '';
    if (strpos($avatar, 'assets/') === 0) {
        $path = dirname(__DIR__) . '/' . $avatar;
    } elseif (strpos($avatar, 'avatars/') === 0) {
        $path = UPLOAD_PATH . $avatar;
    } else {
        $path = UPLOAD_PATH . 'avatars/' . $avatar;
    }
    return file_exists($path) ? filemtime($path) : time();
}


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
