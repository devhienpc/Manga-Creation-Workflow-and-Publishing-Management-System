<?php
require_once __DIR__ . '/constants.php';

/**
 * Kết nối CSDL và trả về instance PDO.
 * Thông tin kết nối được đọc từ file .env (qua hàm env()).
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $host    = env('DB_HOST', 'localhost');
        $db      = env('DB_NAME', 'manga_system');
        $user    = env('DB_USER', 'root');
        $pass    = env('DB_PASS', '');
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

/**
 * Định dạng tiền tệ VND.
 */
function format_money($amount) {
    return number_format((float)$amount, 0, ',', '.'); 
}

/**
 * Lấy thông tin ví của người dùng.
 */
function get_wallet($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Lấy danh sách giao dịch có phân trang và bộ lọc.
 */
function get_transactions($wallet_id, $type='', $month='', $page=1, $search='', $limit=20) {
    $db = getDB();
    $where = ["wallet_id = ?"];
    $params = [$wallet_id];

    if (!empty($type)) {
        $where[] = "type = ?";
        $params[] = $type;
    }

    if (!empty($month)) {
        $where[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
        $params[] = $month;
    }

    if (!empty($search)) {
        $where[] = "description LIKE ?";
        $params[] = "%" . $search . "%";
    }

    $whereSql = implode(" AND ", $where);

    // Tính tổng số dòng để phân trang
    $countStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRecords / $limit));
    $page = max(1, min($totalPages, (int)$page));
    $offset = ($page - 1) * $limit;

    // Lấy dữ liệu phân trang
    $txStmt = $db->prepare("
        SELECT * FROM transactions
        WHERE $whereSql
        ORDER BY created_at DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ");
    $txStmt->execute($params);
    $data = $txStmt->fetchAll();

    return [
        'data'          => $data,
        'total_records' => $totalRecords,
        'total_pages'   => $totalPages,
        'page'          => $page
    ];
}

