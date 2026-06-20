<?php
require_once __DIR__ . '/constants.php';

/**
 * Kết nối CSDL và trả về instance PDO
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $host = 'localhost';
        $db   = 'manga_system';
        $user = 'root';
        $pass = ''; // Mật khẩu rỗng mặc định của Laragon
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            // Dự phòng sang SQLite cục bộ nếu MySQL chưa được cấu hình hoặc chưa import db
            try {
                $sqlite_file = __DIR__ . '/database.sqlite';
                $pdo = new PDO("sqlite:" . $sqlite_file);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (\PDOException $se) {
                die("Kết nối cơ sở dữ liệu thất bại: " . $e->getMessage() . " | SQLite: " . $se->getMessage());
            }
        }
    }
    return $pdo;
}
