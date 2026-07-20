<?php
// DEPRECATED (Phase 2): This script uses MySQL-specific SQL and will NOT run on PostgreSQL.
// It is retained for historical reference only. The Laravel migration system (Phase 2.2+)
// replaces database/run_migrations.php. Utility scripts will be rewritten as Laravel artisan
// commands. Test scripts will be rewritten as PHPUnit tests in Phase 3+.
// Do NOT run this script against PostgreSQL.

require __DIR__ . '/../../config/config.php';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS);
$rows = $pdo->query('SELECT id, transfer_code, transfer_date, amount, is_reversed, created_at FROM money_transfers ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'count today: ' . $pdo->query("SELECT COUNT(*) FROM money_transfers WHERE transfer_date = CURDATE()")->fetchColumn() . PHP_EOL;
