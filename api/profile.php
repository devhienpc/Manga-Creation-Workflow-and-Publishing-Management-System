<?php
/**
 * api/profile.php
 * JSON API cho trang hồ sơ cá nhân.
 *
 * POST actions:
 *   update_info     → cập nhật username, bio
 *   update_avatar   → upload + resize avatar (GD)
 *   update_password → đổi mật khẩu (verify + hash)
 *
 * Response: {"success": bool, "message": "...", "data": {...}}
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth guard ──────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.', 'data' => null]);
    exit();
}

$currentUser = getCurrentUser();
$uid         = (int) $currentUser['id'];
$db          = getDB();

// ── Helper ──────────────────────────────────────────────
function apiOut(bool $ok, string $msg = '', $data = null, int $code = 0): void {
    if ($code > 0) http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data]);
    exit();
}

// ── Route ───────────────────────────────────────────────
$action = trim($_POST['action'] ?? '');

switch ($action) {

    /* ══════════════════════════════════════════════════
       ACTION: update_info
    ══════════════════════════════════════════════════ */
    case 'update_info':
        $username = trim($_POST['username'] ?? '');
        $bio      = mb_substr(trim($_POST['bio'] ?? ''), 0, 500);

        // Validate
        if (mb_strlen($username) < 3) {
            apiOut(false, 'Tên người dùng phải có ít nhất 3 ký tự.');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/u', $username)) {
            apiOut(false, 'Tên người dùng chỉ được chứa chữ, số, dấu gạch dưới, dấu chấm, dấu gạch ngang.');
        }

        // Check duplicate (excluding self)
        $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $uid]);
        if ($check->fetchColumn()) {
            apiOut(false, 'Tên người dùng "' . htmlspecialchars($username) . '" đã được sử dụng bởi tài khoản khác.');
        }

        // Update
        $stmt = $db->prepare("UPDATE users SET username = ?, bio = ? WHERE id = ?");
        $stmt->execute([$username, $bio, $uid]);

        // Update session
        $_SESSION['user']['username'] = $username;
        $_SESSION['user']['fullname'] = $username;
        $_SESSION['user']['bio']      = $bio;

        apiOut(true, 'Thông tin đã được cập nhật thành công!', ['username' => $username, 'bio' => $bio]);
        break;

    /* ══════════════════════════════════════════════════
       ACTION: update_avatar
    ══════════════════════════════════════════════════ */
    case 'update_avatar':
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
            apiOut(false, 'Không có file nào được gửi lên.');
        }

        $file = $_FILES['avatar'];

        // Check upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            apiOut(false, 'Lỗi upload file (code: ' . $file['error'] . ').');
        }

        // Validate size (2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            apiOut(false, 'Ảnh vượt quá kích thước tối đa 2MB.');
        }

        // Validate MIME (dùng getimagesize để tránh spoofing)
        $imgInfo = @getimagesize($file['tmp_name']);
        if (!$imgInfo) {
            apiOut(false, 'File không phải ảnh hợp lệ.');
        }
        $allowedMimes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (!in_array($imgInfo[2], $allowedMimes)) {
            apiOut(false, 'Chỉ chấp nhận ảnh JPG, PNG, WEBP.');
        }

        // Prepare save directory
        $saveDir = UPLOAD_PATH . 'avatars/';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }

        // Filename: {user_id}.jpg (xóa ảnh cũ nếu có)
        $saveName = $uid . '.jpg';
        $savePath = $saveDir . $saveName;

        // Xóa file cũ nếu tồn tại
        if (file_exists($savePath)) {
            @unlink($savePath);
        }

        // Load source image
        switch ($imgInfo[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($file['tmp_name']); break;
            default: $src = false;
        }
        if (!$src) {
            apiOut(false, 'Không thể xử lý ảnh. Vui lòng thử file khác.');
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        // Resize về 300×300 (center-crop)
        $size    = 300;
        $canvas  = imagecreatetruecolor($size, $size);

        // Preserve alpha for PNG/WEBP
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

        // Center crop
        $srcRatio = $origW / $origH;
        if ($srcRatio > 1) {
            // Landscape: crop width
            $srcH = $origH;
            $srcW = $origH;
            $srcX = (int)(($origW - $origH) / 2);
            $srcY = 0;
        } elseif ($srcRatio < 1) {
            // Portrait: crop height
            $srcW = $origW;
            $srcH = $origW;
            $srcX = 0;
            $srcY = (int)(($origH - $origW) / 2);
        } else {
            $srcW = $origW; $srcH = $origH; $srcX = 0; $srcY = 0;
        }

        imagecopyresampled($canvas, $src, 0, 0, $srcX, $srcY, $size, $size, $srcW, $srcH);
        imagedestroy($src);

        // Save as JPEG quality 88
        $saved = imagejpeg($canvas, $savePath, 88);
        imagedestroy($canvas);

        if (!$saved) {
            apiOut(false, 'Không thể lưu ảnh. Vui lòng kiểm tra quyền thư mục.');
        }

        // Relative path saved in DB
        $relPath = $uid . '.jpg';

        // Update DB
        $upStmt = $db->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $upStmt->execute([$relPath, $uid]);

        // Update session
        $_SESSION['user']['avatar'] = $relPath;

        $avatarUrl = BASE_URL . 'assets/uploads/avatars/' . $relPath;

        apiOut(true, 'Ảnh đại diện đã được cập nhật thành công!', [
            'avatar'     => $relPath,
            'avatar_url' => $avatarUrl,
        ]);
        break;

    /* ══════════════════════════════════════════════════
       ACTION: update_password
    ══════════════════════════════════════════════════ */
    case 'update_password':
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        // Basic validation
        if (empty($currentPw)) {
            apiOut(false, 'Vui lòng nhập mật khẩu hiện tại.');
        }
        if (mb_strlen($newPw) < 8) {
            apiOut(false, 'Mật khẩu mới phải có ít nhất 8 ký tự.');
        }
        if (!preg_match('/[A-Z]/', $newPw)) {
            apiOut(false, 'Mật khẩu mới phải có ít nhất 1 chữ hoa (A-Z).');
        }
        if (!preg_match('/[0-9]/', $newPw)) {
            apiOut(false, 'Mật khẩu mới phải có ít nhất 1 chữ số (0-9).');
        }
        if ($newPw !== $confirmPw) {
            apiOut(false, 'Mật khẩu xác nhận không khớp.');
        }

        // Fetch current password hash from DB
        $pwStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $pwStmt->execute([$uid]);
        $row = $pwStmt->fetch();

        if (!$row || !password_verify($currentPw, $row['password'])) {
            apiOut(false, 'Mật khẩu hiện tại không đúng.');
        }

        // Hash & save
        $newHash = password_hash($newPw, PASSWORD_BCRYPT);
        $upPw    = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upPw->execute([$newHash, $uid]);

        apiOut(true, 'Mật khẩu đã được cập nhật thành công!');
        break;

    /* ══════════════════════════════════════════════════
       DEFAULT: unknown action
    ══════════════════════════════════════════════════ */
    default:
        http_response_code(400);
        apiOut(false, 'Hành động không hợp lệ: ' . htmlspecialchars($action));
}
