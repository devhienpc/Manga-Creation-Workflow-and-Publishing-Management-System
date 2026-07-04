<?php
/**
 * api/ai_segment.php
 *
 * POST multipart/form-data (upload ảnh mới):
 *   file    — ảnh JPG/PNG trang manga
 *
 * POST application/json (dùng ảnh từ DB):
 *   page_id — ID trang trong DB
 *
 * Response JSON:
 *   {"success": true, "regions": [...], "total_regions": N, "page_complexity": "..."}
 *   {"success": false, "message": "..."}
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Auth — chỉ Mangaka ────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit();
}

$currentUser = getCurrentUser();
if ($currentUser['role'] !== ROLES['MANGAKA']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Chỉ Mangaka mới được dùng tính năng này.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ hỗ trợ POST.']);
    exit();
}

$db = getDB();

// ── Lấy ảnh: từ upload hoặc từ page_id ────────────────────────────────────
$base64Image = null;
$inputFileName = 'upload';

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    // JSON body với page_id
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $pageId = (int)($body['page_id'] ?? 0);
    if ($pageId <= 0) {
        echo json_encode(['success' => false, 'message' => 'page_id không hợp lệ.']);
        exit();
    }

    // Lấy original_file từ DB (kiểm tra quyền: trang thuộc series của mangaka này)
    $stmt = $db->prepare(
        "SELECT p.original_file, p.page_number
         FROM pages p
         JOIN chapters c ON c.id = p.chapter_id
         JOIN series   s ON s.id = c.series_id
         WHERE p.id = ? AND s.mangaka_id = ? LIMIT 1"
    );
    $stmt->execute([$pageId, $currentUser['id']]);
    $page = $stmt->fetch();

    if (!$page) {
        echo json_encode(['success' => false, 'message' => 'Trang không tồn tại hoặc không có quyền.']);
        exit();
    }
    if (empty($page['original_file'])) {
        echo json_encode(['success' => false, 'message' => 'Trang chưa có file ảnh.']);
        exit();
    }

    $normalizedFile = str_replace('\\', '/', ltrim($page['original_file'], '/\\'));
    $filePath = dirname(__DIR__) . '/' . $normalizedFile;
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'File ảnh không tồn tại trên server. Path: ' . $normalizedFile]);
        exit();
    }
    $base64Image = base64_encode(file_get_contents($filePath));
    $inputFileName = 'page_' . $pageId;

} else {
    // Multipart upload
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? -1;
        $errMsg  = match($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File quá lớn.',
            UPLOAD_ERR_NO_FILE => 'Chưa chọn file ảnh.',
            default            => 'Lỗi upload (code ' . $errCode . ').'
        };
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit();
    }

    $file     = $_FILES['file'];
    $maxBytes = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'File quá lớn. Tối đa 10MB.']);
        exit();
    }

    $mime    = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận JPG, PNG, WebP.']);
        exit();
    }

    $base64Image   = base64_encode(file_get_contents($file['tmp_name']));
    $inputFileName = $file['name'];
    $mime          = $mime; // giữ lại
}

if (empty($base64Image)) {
    echo json_encode(['success' => false, 'message' => 'Không thể đọc dữ liệu ảnh.']);
    exit();
}

// ── Xây dựng payload Gemini ────────────────────────────────────────────────
$mimeForGemini = 'image/jpeg'; // default
if (!empty($_FILES['file']['type'])) {
    $mimeForGemini = $_FILES['file']['type'];
} elseif (!empty($mime)) {
    $mimeForGemini = $mime;
}

$promptText = <<<PROMPT
Analyze this manga page and identify all distinct regions.
Return ONLY a valid JSON object. Do not include any explanation, markdown, or code fences.
The JSON must follow this exact schema:
{
  "regions": [
    {
      "id": 1,
      "type": "background",
      "label": "mô tả vùng bằng tiếng Việt",
      "x": 0,
      "y": 0,
      "width": 50,
      "height": 50,
      "suggested_task": "background",
      "confidence": 0.85
    }
  ],
  "total_regions": 1,
  "page_complexity": "medium"
}
Rules:
- type must be one of: background, character, effects, shading, text_bubble
- suggested_task must be one of: background, shading, effects, lettering, cleanup
- page_complexity must be one of: low, medium, high
- x, y, width, height are percentages of image dimensions (0-100)
- Identify up to 8 key regions
- Focus on: background panels, character figures, speech bubbles, effect lines, shadow/shading areas
PROMPT;

$payload = [
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => $mimeForGemini,
                    'data'      => $base64Image,
                ]
            ],
            [
                'text' => $promptText
            ]
        ]
    ]],
    'generationConfig' => [
        'temperature'        => 0.1,
        'maxOutputTokens'    => 4096,
        'responseMimeType'   => 'application/json',
    ],
];

// ── Gọi Gemini 2.5 Flash API ──────────────────────────────────────────────
$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
]);

$response  = curl_exec($ch);
$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kết nối Gemini API: ' . $curlError]);
    exit();
}

if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $errMsg  = $errData['error']['message'] ?? "Gemini API lỗi HTTP {$httpCode}";
    echo json_encode(['success' => false, 'message' => 'Gemini API: ' . $errMsg]);
    exit();
}

// ── Parse response Gemini ──────────────────────────────────────────────────
$geminiData = json_decode($response, true);

// Gemini 2.5 Flash có thinking tokens — lặp qua tất cả parts để lấy text JSON
$rawText = '';
$parts = $geminiData['candidates'][0]['content']['parts'] ?? [];
foreach ($parts as $part) {
    // Bỏ qua thinking part, chỉ lấy text thực
    if (isset($part['text']) && !isset($part['thought'])) {
        $rawText = $part['text'];
        break;
    }
}
// Fallback: nếu không tìm được, lấy part cuối cùng có text
if ($rawText === '') {
    foreach (array_reverse($parts) as $part) {
        if (isset($part['text'])) {
            $rawText = $part['text'];
            break;
        }
    }
}

// Strip markdown code fences nếu Gemini vẫn thêm vào
$rawText = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
$rawText = preg_replace('/```\s*$/',            '', $rawText);
$rawText = trim($rawText);

$parsed = json_decode($rawText, true);
if (!$parsed || !isset($parsed['regions']) || !is_array($parsed['regions'])) {
    // Thử trích xuất JSON object lớn nhất bằng cách tìm cặp ngoặc nhọn đầu-cuối
    $firstBrace = strpos($rawText, '{');
    $lastBrace  = strrpos($rawText, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $jsonCandidate = substr($rawText, $firstBrace, $lastBrace - $firstBrace + 1);
        $parsed = json_decode($jsonCandidate, true);
    }
    if (!$parsed || !isset($parsed['regions'])) {
        echo json_encode([
            'success'    => false,
            'message'    => 'Gemini không trả về JSON hợp lệ. Thử lại.',
            'raw'        => substr($rawText, 0, 500),
            'json_error' => json_last_error_msg(),
            'parts_count'=> count($parts),
        ]);
        exit();
    }
}

// ── Validate và sanitize regions ──────────────────────────────────────────
$validTypes         = ['background', 'character', 'effects', 'shading', 'text_bubble'];
$validSuggestedTask = ['background', 'shading', 'effects', 'lettering', 'cleanup'];
$clamp = fn($v, $min = 0, $max = 100) => max($min, min($max, (float)$v));

$sanitized = [];
foreach ($parsed['regions'] as $idx => $r) {
    $type  = in_array($r['type'] ?? '', $validTypes) ? $r['type'] : 'background';
    $sTask = in_array($r['suggested_task'] ?? '', $validSuggestedTask) ? $r['suggested_task'] : 'background';

    $sanitized[] = [
        'id'             => (int)($r['id'] ?? $idx + 1),
        'type'           => $type,
        'label'          => htmlspecialchars(substr($r['label'] ?? "Vùng " . ($idx + 1), 0, 80)),
        'x'              => round($clamp($r['x'] ?? 0), 2),
        'y'              => round($clamp($r['y'] ?? 0), 2),
        'width'          => round($clamp($r['width']  ?? 10, 1, 100), 2),
        'height'         => round($clamp($r['height'] ?? 10, 1, 100), 2),
        'suggested_task' => $sTask,
        'confidence'     => round($clamp($r['confidence'] ?? 0.8, 0, 1), 2),
    ];
}

// ── Ghi log vào ai_logs ────────────────────────────────────────────────────
$logId = null;
try {
    $stmt = $db->prepare(
        "INSERT INTO ai_logs (user_id, type, input_file, result_file, api_used, metadata)
         VALUES (?, 'segment', ?, NULL, 'gemini/gemini-2.5-flash', ?)"
    );
    $stmt->execute([
        $currentUser['id'],
        $inputFileName,
        json_encode([
            'total_regions'   => count($sanitized),
            'page_complexity' => $parsed['page_complexity'] ?? 'medium',
            'http_code'       => $httpCode,
        ]),
    ]);
    $logId = (int)$db->lastInsertId();
} catch (\Throwable $e) {
    error_log('ai_logs insert error: ' . $e->getMessage());
}

echo json_encode([
    'success'         => true,
    'regions'         => $sanitized,
    'total_regions'   => count($sanitized),
    'page_complexity' => $parsed['page_complexity'] ?? 'medium',
    'log_id'          => $logId,
]);
exit();
