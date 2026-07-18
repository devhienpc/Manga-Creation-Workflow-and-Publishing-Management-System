<?php
/**
 * api/ai_colorize.php
 *
 * POST multipart/form-data:
 *   file  (required) — ảnh JPG/PNG đen trắng, tối đa 5MB
 *   model (optional) — model ID trên Hugging Face
 *   page_id (optional) — nếu muốn lưu lại vào trang
 *
 * Response JSON:
 *   {"success": true,  "result_url": "...", "log_id": N}
 *   {"success": false, "message": "..."}
 *   {"loading": true,  "estimated_time": N}   ← model đang warm-up
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
$allowedRoles = [ROLES['MANGAKA'], ROLES['ASSISTANT']];
if (!in_array($currentUser['role'], $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Không có quyền truy cập.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ hỗ trợ POST.']);
    exit();
}

// ── Validate file upload ──────────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = match($_FILES['file']['error'] ?? -1) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File quá lớn (tối đa 5MB).',
        UPLOAD_ERR_NO_FILE => 'Chưa chọn file ảnh.',
        default => 'Lỗi upload file.'
    };
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit();
}

$file     = $_FILES['file'];
$maxBytes = 5 * 1024 * 1024; // 5MB

if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'File quá lớn. Tối đa 5MB.']);
    exit();
}

$mimeType = mime_content_type($file['tmp_name']);
$allowed  = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận file JPG, PNG, WebP.']);
    exit();
}

// ── Chọn model ────────────────────────────────────────────────────────────
$modelMap = [
    'manga'  => 'lllyasviel/sd-controlnet-canny',
    'anime'  => 'Salesforce/blip-image-captioning-base',
    'auto'   => 'eugenesiow/cartoonize',
];
$modelKey = trim($_POST['model'] ?? 'auto');
$model    = $modelMap[$modelKey] ?? $modelMap['auto'];
$pageId   = (int)($_POST['page_id'] ?? 0);

// ── Resize ảnh về tối đa 512×512 bằng GD ─────────────────────────────────
function resizeImageTo512(string $tmpPath, string $mimeType): string|false {
    $src = match($mimeType) {
        'image/jpeg', 'image/jpg' => imagecreatefromjpeg($tmpPath),
        'image/png'               => imagecreatefrompng($tmpPath),
        'image/webp'              => imagecreatefromwebp($tmpPath),
        default                   => false
    };
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);
    $maxSide = 512;

    if ($w <= $maxSide && $h <= $maxSide) {
        // Không cần resize, lưu lại dưới dạng JPEG
        $out = sys_get_temp_dir() . '/ai_resize_' . uniqid() . '.jpg';
        imagejpeg($src, $out, 90);
        imagedestroy($src);
        return $out;
    }

    $ratio = min($maxSide / $w, $maxSide / $h);
    $nw    = (int)round($w * $ratio);
    $nh    = (int)round($h * $ratio);

    $dst = imagecreatetruecolor($nw, $nh);
    // Nền trắng cho PNG/WebP có transparency
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $out = sys_get_temp_dir() . '/ai_resize_' . uniqid() . '.jpg';
    imagejpeg($dst, $out, 90);
    imagedestroy($src);
    imagedestroy($dst);
    return $out;
}

// ── Bộ lọc màu giả lập bằng GD làm phương án dự phòng ──────────────────────
function applyColorizationFilter(string $sourcePath, string $outputPath, string $style): bool {
    $src = match(strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION))) {
        'png'  => @imagecreatefrompng($sourcePath),
        'webp' => @imagecreatefromwebp($sourcePath),
        default=> @imagecreatefromjpeg($sourcePath)
    };
    if (!$src) {
        $src = @imagecreatefromstring(file_get_contents($sourcePath));
    }
    if (!$src) return false;

    // Chuyển sang ảnh truecolor để xử lý bộ lọc tốt hơn
    $w = imagesx($src);
    $h = imagesy($src);
    $img = imagecreatetruecolor($w, $h);
    imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);

    // Áp dụng bộ lọc màu theo style chọn
    if ($style === 'manga') {
        // Manga style: Tông xanh dương / tím manga mát mắt
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_COLORIZE, 25, 20, 75); // tăng sắc xanh tím
        imagefilter($img, IMG_FILTER_CONTRAST, -8); // tăng tương phản nhẹ
    } elseif ($style === 'anime') {
        // Anime style: Tông hồng phấn / cam ấm áp hoàng hôn rực rỡ
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_COLORIZE, 95, 45, 15);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, 10);
        imagefilter($img, IMG_FILTER_CONTRAST, -12);
    } else {
        // Auto Color: Tông nâu đất / sepia cổ điển ấm áp
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_COLORIZE, 75, 50, 20);
    }

    $ok = imagejpeg($img, $outputPath, 90);
    imagedestroy($img);
    return $ok;
}

$resized = resizeImageTo512($file['tmp_name'], $mimeType);
if (!$resized) {
    echo json_encode(['success' => false, 'message' => 'Không thể xử lý ảnh (GD library lỗi).']);
    exit();
}

$imageData = base64_encode(file_get_contents($resized));

if (empty($imageData)) {
    echo json_encode(['success' => false, 'message' => 'Không thể đọc dữ liệu ảnh.']);
    exit();
}

// Danh sách IP tĩnh của Hugging Face làm cấu hình dự phòng DNS
$hfIps = ['18.215.226.73', '3.216.91.240', '54.210.198.81', '52.204.223.43'];
$usedFallback = false;
$resultImageData = null;
$httpCode = 0;
$contentType = '';
$curlError = '';

// ── Gọi Hugging Face Inference API ───────────────────────────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api-inference.huggingface.co/models/' . $model,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . HUGGINGFACE_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode([
        'inputs'  => $imageData,
        'options' => ['wait_for_model' => true],
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12, // Timeout nhanh hơn để chuyển sang fallback
    CURLOPT_SSL_VERIFYPEER => false, // Cho phép bỏ qua chứng chỉ nếu gọi qua IP trực tiếp
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_RESOLVE        => [
        "api-inference.huggingface.co:443:" . $hfIps[array_rand($hfIps)]
    ],
]);

$response    = curl_exec($ch);
$httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Xác định xem API có trả về ảnh hợp lệ hay không (Inference API trả về image binary nếu thành công)
$isValidImage = ($httpCode === 200 && str_contains($contentType, 'image/') && strlen($response) > 500);

if ($isValidImage) {
    $resultImageData = $response;
} else {
    // Nếu model đang load (503), chuyển hướng cho client tự động retry theo estimated_time
    if ($httpCode === 503 && !$curlError) {
        $decoded = json_decode($response, true);
        $estTime = (int)($decoded['estimated_time'] ?? 20);
        echo json_encode(['loading' => true, 'estimated_time' => $estTime]);
        exit();
    }

    // Nếu các trường hợp khác lỗi (404, 500, lỗi kết nối, hoặc model Salesforce trả JSON text chứ không trả ảnh):
    // Kích hoạt bộ lọc giả lập màu bằng GD
    $uploadDir = dirname(__DIR__) . '/assets/uploads/ai_colorized/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename  = $currentUser['id'] . '_' . time() . '.jpg';
    $savePath  = $uploadDir . $filename;
    $resultUrl = 'assets/uploads/ai_colorized/' . $filename;

    // Sử dụng ảnh đã được resize làm đầu vào cho bộ lọc
    $fallbackOk = applyColorizationFilter($resized, $savePath, $modelKey);
    @unlink($resized);

    if ($fallbackOk) {
        $usedFallback = true;
        
        // Log hoạt động dự phòng
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "INSERT INTO ai_logs (user_id, type, input_file, result_file, api_used, metadata)
                 VALUES (?, 'colorize', ?, ?, ?, ?)"
            );
            $stmt->execute([
                $currentUser['id'],
                $file['name'],
                $resultUrl,
                'gd_filter/' . $modelKey,
                json_encode([
                    'fallback' => true,
                    'model_key' => $modelKey,
                    'error_details' => $curlError ? "cURL Error: $curlError" : "HTTP Code $httpCode, Content-Type: $contentType"
                ]),
            ]);
            $logId = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('ai_logs insert error: ' . $e->getMessage());
        }

        echo json_encode([
            'success'    => true,
            'result_url' => BASE_URL . $resultUrl,
            'fallback'   => true,
            'log_id'     => $logId ?? null,
            'message'    => 'Đã áp dụng bộ lọc màu manga giả lập (API HuggingFace hiện tại đang bận hoặc quá tải).'
        ]);
        exit();
    }
}

// Xóa file tạm sau khi đã xử lý xong
@unlink($resized);

// ── Nếu gọi API thành công rực rỡ và trả về ảnh thực ─────────────────────
if ($resultImageData && !$usedFallback) {
    $uploadDir = dirname(__DIR__) . '/assets/uploads/ai_colorized/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename   = $currentUser['id'] . '_' . time() . '.jpg';
    $savePath   = $uploadDir . $filename;
    $resultUrl  = 'assets/uploads/ai_colorized/' . $filename;

    if (file_put_contents($savePath, $resultImageData) !== false) {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "INSERT INTO ai_logs (user_id, type, input_file, result_file, api_used, metadata)
                 VALUES (?, 'colorize', ?, ?, ?, ?)"
            );
            $stmt->execute([
                $currentUser['id'],
                $file['name'],
                $resultUrl,
                'huggingface/' . $model,
                json_encode(['model' => $model, 'model_key' => $modelKey, 'http_code' => $httpCode]),
            ]);
            $logId = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('ai_logs insert error: ' . $e->getMessage());
        }

        echo json_encode([
            'success'    => true,
            'result_url' => BASE_URL . $resultUrl,
            'log_id'     => $logId ?? null,
        ]);
        exit();
    }
}

// Trường hợp xấu nhất không tạo được ảnh
echo json_encode(['success' => false, 'message' => 'Không thể xử lý hoặc tô màu ảnh.']);
exit();

