<?php
// TEMPORARY diagnostic page. Delete this file when everything works.
header('Content-Type: text/plain; charset=utf-8');
echo "CAINTRACK database check\n========================\n\n";

echo "PDO MySQL driver: " . (extension_loaded('pdo_mysql') ? "OK" : "MISSING (add docker-php-ext-install pdo pdo_mysql to Dockerfile)") . "\n";
echo "DB_HOST: " . (getenv('DB_HOST') ?: '(not set, using default \"db\")') . "\n";
echo "DB_NAME: " . (getenv('DB_NAME') ?: '(not set, using default \"caintrack\")') . "\n";
echo "DB_USER: " . (getenv('DB_USER') ?: '(not set, using default \"caintrack\")') . "\n\n";

$file = __DIR__ . '/includes/db.php';
if (!file_exists($file)) { exit("includes/db.php: NOT FOUND at $file\n"); }
require $file;
echo "includes/db.php: found\n";

try {
    $pdo = db();
    echo "Connection: OK\n\n";
} catch (Throwable $e) {
    exit("Connection: FAILED\n" . $e->getMessage() . "\n");
}

try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . ($tables ? implode(', ', $tables) : '(none, schema.sql did not run)') . "\n";
    if (in_array('barangays', $tables, true)) {
        echo "\nBarangays:\n";
        foreach ($pdo->query('SELECT id, name FROM barangays ORDER BY name') as $r) {
            echo "  {$r['id']}. {$r['name']}\n";
        }
    }
    if (in_array('users', $tables, true)) {
        echo "\nUsers table columns:\n";
        foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) {
            echo "  {$c['Field']} ({$c['Type']})\n";
        }
        $n = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        echo "\nAccounts in users table: $n\n";
        foreach ($pdo->query('SELECT username, email, role, is_active FROM users') as $u) {
            echo "  {$u['username']} | {$u['email']} | {$u['role']} | active=" . $u['is_active'] . "\n";
        }
    } else {
        echo "\nusers table: MISSING. Run the SQL in phpMyAdmin.\n";
    }
} catch (Throwable $e) {
    echo "Query FAILED: " . $e->getMessage() . "\n";
}