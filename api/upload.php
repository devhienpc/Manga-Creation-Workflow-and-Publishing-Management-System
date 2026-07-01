<?php
/**
 * api/upload.php
 *
 * POST → Upload file (multipart/form-data)
 *         field: file         - File cần upload (bắt buộc)
 *         field: upload_type  - 'cover' | 'manuscript' | 'page' | 'task_result' (bắt buộc)
 *         field: series_id    - Bắt buộc với cover, manuscript, page
 *         field: chapter_id   - Bắt buộc với page, manuscript
 *
 * DELETE → Xoá file đã upload (chỉ người upload hoặc editor/board)
 *          JSON body: { "file_path": "uploads/..." }
 *
 * Response: { "success": bool, "data": { "path": "...", "url": "..." }, "message": "..." }
 *
 * Phân quyền:
 *   cover        → mangaka (series của mình)
 *   manuscript   → mangaka (series của mình)
 *   page         → mangaka hoặc assistant (task được giao)
 *   task_result  → assistant (task được giao)
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => null, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$db          = getDB();

// ── Helper ────────────────────────────────────────────
function uploadOut(bool $ok, $data = null, string $msg = '', int $code = 0): void {
    if ($code > 0) http_response_code($code);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg]);
    exit();
}

// Cấu hình upload theo loại
const UPLOAD_CONFIG = [
    'cover' => [
        'dir'           => 'assets/uploads/covers/',
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
        'max_size'      => 5 * 1024 * 1024,   // 5 MB
        'max_size_label'=> '5MB',
    ],
    'manuscript' => [
        'dir'           => 'assets/uploads/manuscripts/',
        'allowed_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/zip'],
        'allowed_exts'  => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'zip'],
        'max_size'      => 50 * 1024 * 1024,  // 50 MB
        'max_size_label'=> '50MB',
    ],
    'page' => [
        'dir'           => 'assets/uploads/pages/',
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
        'max_size'      => 20 * 1024 * 1024,  // 20 MB
        'max_size_label'=> '20MB',
    ],
    'task_result' => [
        'dir'           => 'assets/uploads/tasks/',
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
        'max_size'      => 20 * 1024 * 1024,  // 20 MB mỗi ảnh
        'max_size_label'=> '20MB',
    ],
];

// Đường dẫn gốc project
$projectRoot = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR;

// ═══════════════════════════════════════════════════════
// POST — Xử lý upload
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uploadType = trim($_POST['upload_type'] ?? '');
    $seriesId   = (int)($_POST['series_id']  ?? 0);
    $chapterId  = (int)($_POST['chapter_id'] ?? 0);

    // Validate upload_type
    if (!array_key_exists($uploadType, UPLOAD_CONFIG)) {
        uploadOut(false, null,
            'upload_type không hợp lệ. Chấp nhận: ' . implode(', ', array_keys(UPLOAD_CONFIG)),
            422);
    }

    $cfg = UPLOAD_CONFIG[$uploadType];

    // Kiểm tra file có được gửi lên không
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match($_FILES['file']['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "File quá lớn (tối đa {$cfg['max_size_label']}).",
            UPLOAD_ERR_NO_FILE   => 'Chưa chọn file.',
            UPLOAD_ERR_PARTIAL   => 'Upload bị gián đoạn.',
            default              => 'Lỗi upload file không xác định.',
        };
        uploadOut(false, null, $errMsg, 422);
    }

    $file = $_FILES['file'];

    // ── Validate kích thước ──
    if ($file['size'] > $cfg['max_size']) {
        uploadOut(false, null,
            "File quá lớn. Tối đa {$cfg['max_size_label']}. File của bạn: " . round($file['size'] / 1048576, 1) . 'MB.',
            422);
    }
    if ($file['size'] === 0) {
        uploadOut(false, null, 'File rỗng (0 bytes).', 422);
    }

    // ── Validate MIME type (bằng finfo, không chỉ dựa vào browser) ──
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $cfg['allowed_types'], true)) {
        uploadOut(false, null,
            "Loại file không được phép. Chấp nhận: " . implode(', ', $cfg['allowed_exts']) . ".",
            415);
    }

    // ── Validate extension ──
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($origExt, $cfg['allowed_exts'], true)) {
        uploadOut(false, null,
            "Đuôi file không hợp lệ. Chấp nhận: ." . implode(', .', $cfg['allowed_exts']) . ".",
            415);
    }

    // ── Phân quyền theo upload_type ──
    switch ($uploadType) {
        case 'cover':
        case 'manuscript':
            if ($currentUser['role'] !== ROLES['MANGAKA']) {
                uploadOut(false, null, 'Chỉ họa sĩ Manga mới được upload loại này.', 403);
            }
            if ($seriesId <= 0) uploadOut(false, null, 'series_id là bắt buộc.', 422);

            // Xác minh series thuộc về mangaka này
            $chkStmt = $db->prepare("SELECT id FROM series WHERE id = ? AND mangaka_id = ? LIMIT 1");
            $chkStmt->execute([$seriesId, $currentUser['id']]);
            if (!$chkStmt->fetch()) {
                uploadOut(false, null, 'Không có quyền upload cho series này.', 403);
            }
            break;

        case 'page':
            // Mangaka hoặc assistant đang được giao task
            if (!in_array($currentUser['role'], [ROLES['MANGAKA'], ROLES['ASSISTANT']])) {
                uploadOut(false, null, 'Không có quyền upload loại này.', 403);
            }
            if ($seriesId <= 0 || $chapterId <= 0) {
                uploadOut(false, null, 'series_id và chapter_id là bắt buộc.', 422);
            }
            if ($currentUser['role'] === ROLES['MANGAKA']) {
                $chkStmt = $db->prepare(
                    "SELECT s.id FROM series s WHERE s.id = ? AND s.mangaka_id = ? LIMIT 1"
                );
                $chkStmt->execute([$seriesId, $currentUser['id']]);
                if (!$chkStmt->fetch()) uploadOut(false, null, 'Không có quyền upload trang này.', 403);
            } else {
                // Assistant: phải có task đang được giao trong chapter này
                $chkStmt = $db->prepare(
                    "SELECT t.id FROM tasks t
                     JOIN pages    p ON p.id = t.page_id
                     JOIN chapters c ON c.id = p.chapter_id
                     WHERE c.id = ? AND t.assigned_to = ?
                       AND t.status IN ('pending','in_progress','revision')
                     LIMIT 1"
                );
                $chkStmt->execute([$chapterId, $currentUser['id']]);
                if (!$chkStmt->fetch()) uploadOut(false, null, 'Không có task được giao trong chapter này.', 403);
            }
            break;

        case 'task_result':
            if ($currentUser['role'] !== ROLES['ASSISTANT']) {
                uploadOut(false, null, 'Chỉ trợ lý mới được nộp kết quả task.', 403);
            }
            break;
    }

    // ── Tạo thư mục đích nếu chưa tồn tại ──
    $targetDir  = $projectRoot . str_replace('/', DIRECTORY_SEPARATOR, $cfg['dir']);
    $subDir     = $seriesId > 0 ? $seriesId . DIRECTORY_SEPARATOR : '';
    $fullDir    = $targetDir . $subDir; // OS-native separators for filesystem ops

    if (!is_dir($fullDir)) {
        if (!mkdir($fullDir, 0755, true)) {
            uploadOut(false, null, 'Không thể tạo thư mục đích.', 500);
        }
    }

    // ── Tạo tên file an toàn & không trùng ──
    $uid       = $currentUser['id'];
    $timestamp = date('Ymd_His');
    $random    = bin2hex(random_bytes(4));
    $safeExt   = $origExt;
    $newName   = "u{$uid}_{$timestamp}_{$random}.{$safeExt}";
    $destPath  = $fullDir . $newName;

    // ── Move file ──
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        uploadOut(false, null, 'Lỗi khi lưu file. Thử lại sau.', 500);
    }

    // ── Tạo relative path để lưu vào DB ──
    // Normalize $subDir to forward slashes (important on Windows where DIRECTORY_SEPARATOR = '\')
    $subDirWeb    = str_replace(DIRECTORY_SEPARATOR, '/', $subDir);
    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $cfg['dir']) . $subDirWeb . $newName;
    // Chuẩn hóa path: xóa double-slash và backslash còn sót lại
    $relativePath = str_replace(['\\', '//'], ['/', '/'], $relativePath);
    $relativePath = ltrim($relativePath, '/');

    // ── Tạo URL public ──
    $publicUrl = rtrim(BASE_URL, '/') . '/' . ltrim($relativePath, '/');

    // ── Nếu upload_type=page và page_id được cung cấp → cập nhật pages.original_file ──
    $pageId = (int)($_POST['page_id'] ?? 0);
    if ($uploadType === 'page' && $pageId > 0) {
        // Xác minh page thuộc chapter/series của người dùng hiện tại
        $verifyStmt = $db->prepare(
            "SELECT p.id FROM pages p
             JOIN chapters c ON c.id = p.chapter_id
             JOIN series   s ON s.id = c.series_id
             WHERE p.id = ? AND (
                 s.mangaka_id = ?
                 OR EXISTS (
                     SELECT 1 FROM tasks t
                     WHERE t.page_id = p.id AND t.assigned_to = ?
                 )
             ) LIMIT 1"
        );
        $verifyStmt->execute([$pageId, $currentUser['id'], $currentUser['id']]);
        if ($verifyStmt->fetch()) {
            $db->prepare("UPDATE pages SET original_file = ? WHERE id = ?")
               ->execute([$relativePath, $pageId]);
        }
    }

    uploadOut(true, [
        'path'        => $relativePath,
        'url'         => $publicUrl,
        'filename'    => $newName,
        'original'    => $file['name'],
        'size'        => $file['size'],
        'mime'        => $mimeType,
        'upload_type' => $uploadType,
        'page_id'     => $pageId ?: null,
    ], 'Upload thành công!');
}

// ═══════════════════════════════════════════════════════
// DELETE — Xoá file đã upload
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $filePath = trim($body['file_path'] ?? '');

    if (empty($filePath)) uploadOut(false, null, 'file_path là bắt buộc.', 422);

    // Chỉ editor và board có thể xóa file bất kỳ; mangaka/assistant chỉ xóa file của mình
    $allowedDeleteRoles = [ROLES['EDITOR'], ROLES['BOARD']];
    $isSuperUser = in_array($currentUser['role'], $allowedDeleteRoles, true);

    // Tránh Path Traversal: file_path phải bắt đầu bằng assets/uploads/
    if (!str_starts_with($filePath, 'assets/uploads/')) {
        uploadOut(false, null, 'Đường dẫn file không hợp lệ.', 422);
    }

    $absPath = realpath($projectRoot . str_replace('/', DIRECTORY_SEPARATOR, $filePath));

    // Double-check đường dẫn thực tế nằm trong project
    if ($absPath === false || !str_starts_with($absPath, $projectRoot)) {
        uploadOut(false, null, 'File không tồn tại hoặc nằm ngoài phạm vi cho phép.', 404);
    }

    if (!$isSuperUser) {
        // Mangaka/assistant: kiểm tra file tên có chứa uid của họ không (u{uid}_...)
        $basename = basename($absPath);
        if (!str_starts_with($basename, 'u' . $currentUser['id'] . '_')) {
            uploadOut(false, null, 'Không có quyền xóa file này.', 403);
        }
    }

    if (!file_exists($absPath)) {
        uploadOut(false, null, 'File không tồn tại.', 404);
    }

    if (!unlink($absPath)) {
        uploadOut(false, null, 'Không thể xóa file.', 500);
    }

    uploadOut(true, ['deleted_path' => $filePath], 'Đã xóa file thành công.');
}

// ── Unsupported method ──
http_response_code(405);
echo json_encode(['success' => false, 'data' => null, 'message' => 'Phương thức không được hỗ trợ (POST, DELETE).']);
exit();
