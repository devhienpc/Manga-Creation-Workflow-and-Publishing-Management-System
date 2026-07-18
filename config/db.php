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

        if ($pdo !== null) {
            try {
                $priceColExists = false;
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    $stmtCol = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'price'");
                    $priceColExists = (bool)$stmtCol->fetch();
                } else {
                    $stmtCol = $pdo->query("PRAGMA table_info(tasks)");
                    $cols = $stmtCol->fetchAll();
                    foreach ($cols as $col) {
                        if ($col['name'] === 'price') {
                            $priceColExists = true;
                            break;
                        }
                    }
                }

                if (!$priceColExists) {
                    $pdo->exec("ALTER TABLE tasks ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER version");
                }

                $salaryRecordIdExists = false;
                if ($driver === 'mysql') {
                    $stmtCol = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'salary_record_id'");
                    $salaryRecordIdExists = (bool)$stmtCol->fetch();
                } else {
                    $stmtCol = $pdo->query("PRAGMA table_info(tasks)");
                    $cols = $stmtCol->fetchAll();
                    foreach ($cols as $col) {
                        if ($col['name'] === 'salary_record_id') {
                            $salaryRecordIdExists = true;
                            break;
                        }
                    }
                }

                if (!$salaryRecordIdExists) {
                    if ($driver === 'mysql') {
                        $pdo->exec("ALTER TABLE tasks ADD COLUMN salary_record_id INT DEFAULT NULL AFTER price");
                        try {
                            $pdo->exec("ALTER TABLE tasks ADD CONSTRAINT fk_tasks_salary_record FOREIGN KEY (salary_record_id) REFERENCES salary_records(id) ON DELETE SET NULL");
                        } catch (\Throwable $e) {}
                    } else {
                        $pdo->exec("ALTER TABLE tasks ADD COLUMN salary_record_id INTEGER DEFAULT NULL");
                    }
                }

                if ($driver === 'mysql') {
                    try {
                        $pdo->exec("ALTER TABLE salary_records ADD INDEX idx_salary_assistant (assistant_id)");
                    } catch (\Throwable $e) {}

                    try {
                        $pdo->exec("ALTER TABLE salary_records DROP INDEX unique_salary");
                    } catch (\Throwable $e) {}

                    try {
                        $pdo->exec("
                            UPDATE tasks t
                            JOIN pages p ON t.page_id = p.id
                            JOIN chapters c ON p.chapter_id = c.id
                            JOIN series s ON c.series_id = s.id
                            JOIN salary_records sr ON t.assigned_to = sr.assistant_id
                              AND s.mangaka_id = sr.mangaka_id
                              AND MONTH(COALESCE(t.approved_at, t.created_at)) = sr.month
                              AND YEAR(COALESCE(t.approved_at, t.created_at)) = sr.year
                            SET t.salary_record_id = sr.id
                            WHERE t.status = 'approved' 
                              AND t.salary_record_id IS NULL
                              AND COALESCE(t.approved_at, t.created_at) <= COALESCE(sr.paid_at, sr.created_at)
                        ");
                    } catch (\Throwable $e) {}
                }

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS settings (
                        key_name VARCHAR(100) PRIMARY KEY,
                        value_text TEXT,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $defaultRates = [
                    'default_rate_background' => '100000',
                    'default_rate_shading'    => '60000',
                    'default_rate_effects'    => '50000',
                    'default_rate_lettering'  => '30000',
                    'default_rate_cleanup'    => '20000'
                ];
                foreach ($defaultRates as $key => $val) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE key_name = ?");
                    $stmt->execute([$key]);
                    if ($stmt->fetchColumn() == 0) {
                        $pdo->prepare("INSERT INTO settings (key_name, value_text) VALUES (?, ?)")->execute([$key, $val]);
                    }
                }
            } catch (\Throwable $migError) {
                error_log("Migration error: " . $migError->getMessage());
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

