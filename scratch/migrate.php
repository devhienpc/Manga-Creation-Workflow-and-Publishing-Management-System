<?php
/**
 * scratch/migrate.php
 * Database migration script to alter users table and create settings table.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';

try {
    $db = getDB();
    echo "Connected successfully to the database.\n";

    // 1. Add is_active column to users table if not exists
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetchAll();
    if (empty($columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER bio");
        echo "Column 'is_active' added to 'users' table.\n";
    } else {
        echo "Column 'is_active' already exists in 'users' table.\n";
    }

    // 2. Modify role column ENUM to support 'admin' role
    // First, verify the current column definition
    $roleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($roleColumn) {
        $type = $roleColumn['Type']; // e.g., enum('mangaka','assistant','editor','board')
        if (strpos($type, "'admin'") === false) {
            $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('mangaka', 'assistant', 'editor', 'board', 'admin') NOT NULL");
            echo "Role column modified to include 'admin' role.\n";
        } else {
            echo "Role column already includes 'admin' role.\n";
        }
    }

    // 3. Create settings table if not exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key_name VARCHAR(100) PRIMARY KEY,
            value_text TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Table 'settings' verified/created.\n";

    // 4. Seed default assistant rate setting if not exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE key_name = 'default_assistant_rate'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmtInsert = $db->prepare("INSERT INTO settings (key_name, value_text) VALUES ('default_assistant_rate', '250000')");
        $stmtInsert->execute();
        echo "Default assistant rate setting seeded successfully.\n";
    } else {
        echo "Default assistant rate setting already exists.\n";
    }

    echo "Migration completed successfully!\n";
} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
