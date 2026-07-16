<?php
require __DIR__ . '/../../config/config.php';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS);
$rows = $pdo->query('SELECT id, transfer_code, transfer_date, amount, is_reversed, created_at FROM money_transfers ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'count today: ' . $pdo->query("SELECT COUNT(*) FROM money_transfers WHERE transfer_date = CURDATE()")->fetchColumn() . PHP_EOL;