<?php
/**
 * config/env.php
 *
 * Parser đơn giản cho file .env, không cần Composer hay thư viện ngoài.
 * Đọc file .env ở thư mục gốc project, nạp các biến vào $_ENV và $_SERVER,
 * đồng thời có thể gọi qua hàm env() để lấy giá trị với fallback.
 *
 * Cú pháp .env hỗ trợ:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value with spaces'
 *   # dòng comment
 *   (dòng trống bỏ qua)
 */

(function () {
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($envFile) || !is_readable($envFile)) {
        // Không tìm thấy .env — chạy tiếp với giá trị mặc định hoặc $_ENV đã có
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Bỏ qua comment và dòng không hợp lệ
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        // Tách KEY và VALUE
        [$key, $value] = array_map('trim', explode('=', $line, 2));

        // Bỏ dấu nháy bao quanh nếu có
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Nạp vào superglobals nếu chưa tồn tại (ưu tiên giá trị do server/hệ thống đặt trước)
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (!array_key_exists($key, $_SERVER)) {
            $_SERVER[$key] = $value;
        }

        // Đặt vào getenv() (không ghi đè giá trị đã có)
        putenv("{$key}={$value}");
    }
})();

/**
 * Lấy giá trị biến môi trường.
 * Trả về $default CHỈ KHI biến không tồn tại trong .env.
 * Giá trị rỗng (DB_PASS=) được trả về là chuỗi "" — KHÔNG bị thay bằng $default.
 *
 * @param string $key      Tên biến (vd: 'DB_HOST')
 * @param mixed  $default  Giá trị mặc định nếu biến không tồn tại
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    if ($val !== false) {
        return $val;
    }
    return $default;
}
