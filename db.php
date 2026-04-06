<?php
require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$environment = clinic_runtime_environment();
$prefix = $environment === 'local' ? 'DB_LOCAL_' : 'DB_PRODUCTION_';

$host = (string)clinic_config($prefix . 'HOST', '127.0.0.1');
$db_name = (string)clinic_config($prefix . 'NAME', 'clinic system');
$username = (string)clinic_config($prefix . 'USER', 'root');
$password = (string)clinic_config($prefix . 'PASS', '');

try {
    $conn = new mysqli($host, $username, $password, $db_name);
} catch (mysqli_sql_exception $e) {
    $safeHost = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
    $safeDb = htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8');
    die("Database connection failed for {$environment} environment ({$safeHost}/{$safeDb}): " . $e->getMessage());
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if (!function_exists('clinic_column_exists')) {
    function clinic_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $sql = "SELECT 1
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$table}'
                  AND COLUMN_NAME = '{$column}'
                LIMIT 1";
        $result = $conn->query($sql);
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('clinic_table_exists')) {
    function clinic_table_exists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $sql = "SELECT 1
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$table}'
                LIMIT 1";
        $result = $conn->query($sql);
        return $result && $result->num_rows > 0;
    }
}

$GLOBALS['clinic_stock_has_notes'] = clinic_column_exists($conn, 'stock', 'notes');
$GLOBALS['clinic_stock_has_other_details'] = clinic_column_exists($conn, 'stock', 'other_details');
$GLOBALS['clinic_stock_has_image_path'] = clinic_column_exists($conn, 'stock', 'image_path');
$GLOBALS['clinic_stock_has_updated_at'] = clinic_column_exists($conn, 'stock', 'updated_at');
$GLOBALS['clinic_stock_has_updated_by'] = clinic_column_exists($conn, 'stock', 'updated_by');
$GLOBALS['clinic_stock_has_date_received'] = clinic_column_exists($conn, 'stock', 'date_received');
$GLOBALS['clinic_supplier_has_contact'] = clinic_column_exists($conn, 'suppliers', 'contact');
$GLOBALS['clinic_po_has_received_by'] = clinic_column_exists($conn, 'purchase_orders', 'received_by');
$GLOBALS['clinic_po_has_received_at'] = clinic_column_exists($conn, 'purchase_orders', 'received_at');

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            contact VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            user_id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id VARCHAR(50) NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(150) NOT NULL UNIQUE,
            phone VARCHAR(50) NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'staff',
            password VARCHAR(255) NOT NULL,
            google_id VARCHAR(100) NULL UNIQUE,
            auth_provider VARCHAR(50) NOT NULL DEFAULT 'local',
            reset_token VARCHAR(128) NULL,
            token_expiry DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            staff_scope VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN role IN ('admin','staff') THEN staff_id ELSE NULL END) STORED,
            UNIQUE KEY uq_staff_scope (staff_scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS stock (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            batch_number VARCHAR(100) NULL,
            quantity INT NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) NULL,
            supplier_id INT NULL,
            expiry_date DATE NULL,
            date_received DATE NULL,
            notes TEXT NULL,
            other_details TEXT NULL,
            image_path VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) NULL,
            INDEX idx_stock_name (name),
            INDEX idx_stock_qty (quantity),
            CONSTRAINT fk_stock_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS usage_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stock_id INT NOT NULL,
            used_quantity INT NOT NULL,
            used_by VARCHAR(255) NOT NULL,
            note VARCHAR(255) NULL,
            used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usage_stock (stock_id),
            INDEX idx_usage_date (used_at),
            CONSTRAINT fk_usage_stock FOREIGN KEY (stock_id) REFERENCES stock(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

if (!$GLOBALS['clinic_stock_has_notes']) {
    try {
        $conn->query("ALTER TABLE stock ADD COLUMN notes TEXT NULL");
        $GLOBALS['clinic_stock_has_notes'] = clinic_column_exists($conn, 'stock', 'notes');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_stock_has_notes'] = false;
    }
}

if (!$GLOBALS['clinic_stock_has_image_path']) {
    try {
        $conn->query("ALTER TABLE stock ADD COLUMN image_path VARCHAR(255) NULL");
        $GLOBALS['clinic_stock_has_image_path'] = clinic_column_exists($conn, 'stock', 'image_path');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_stock_has_image_path'] = false;
    }
}

if (!$GLOBALS['clinic_supplier_has_contact']) {
    try {
        $conn->query("ALTER TABLE suppliers ADD COLUMN contact VARCHAR(255) NULL");
        $GLOBALS['clinic_supplier_has_contact'] = clinic_column_exists($conn, 'suppliers', 'contact');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_supplier_has_contact'] = false;
    }
}

if (!$GLOBALS['clinic_stock_has_other_details']) {
    try {
        $conn->query("ALTER TABLE stock ADD COLUMN other_details TEXT NULL");
        $GLOBALS['clinic_stock_has_other_details'] = clinic_column_exists($conn, 'stock', 'other_details');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_stock_has_other_details'] = false;
    }
}

if (!$GLOBALS['clinic_stock_has_updated_at']) {
    try {
        $conn->query("ALTER TABLE stock ADD COLUMN updated_at DATETIME NULL");
        $GLOBALS['clinic_stock_has_updated_at'] = clinic_column_exists($conn, 'stock', 'updated_at');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_stock_has_updated_at'] = false;
    }
}

if (!$GLOBALS['clinic_stock_has_updated_by']) {
    try {
        $conn->query("ALTER TABLE stock ADD COLUMN updated_by VARCHAR(100) NULL");
        $GLOBALS['clinic_stock_has_updated_by'] = clinic_column_exists($conn, 'stock', 'updated_by');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_stock_has_updated_by'] = false;
    }
}

if (!$GLOBALS['clinic_po_has_received_by']) {
    try {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN received_by VARCHAR(100) NULL");
        $GLOBALS['clinic_po_has_received_by'] = clinic_column_exists($conn, 'purchase_orders', 'received_by');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_po_has_received_by'] = false;
    }
}

if (!$GLOBALS['clinic_po_has_received_at']) {
    try {
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN received_at DATETIME NULL");
        $GLOBALS['clinic_po_has_received_at'] = clinic_column_exists($conn, 'purchase_orders', 'received_at');
    } catch (mysqli_sql_exception $e) {
        $GLOBALS['clinic_po_has_received_at'] = false;
    }
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL UNIQUE,
            supplier_id INT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            requested_by VARCHAR(100) NOT NULL,
            total_items INT NOT NULL DEFAULT 0,
            total_units INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'ordered',
            follow_up_date DATE NULL,
            notification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_created_at (created_at),
            INDEX idx_po_supplier (supplier_id),
            CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT NOT NULL,
            stock_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            current_quantity INT NOT NULL,
            order_quantity INT NOT NULL,
            line_status VARCHAR(30) NOT NULL DEFAULT 'ordered',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_poi_order (purchase_order_id),
            INDEX idx_poi_stock (stock_id),
            CONSTRAINT fk_poi_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_poi_stock FOREIGN KEY (stock_id) REFERENCES stock(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT NOT NULL,
            supplier_id INT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            scheduled_for DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_followup_date (scheduled_for),
            INDEX idx_followup_order (purchase_order_id),
            CONSTRAINT fk_followup_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_followup_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (mysqli_sql_exception $e) {
}
