<?php
require __DIR__ . '/../../config/config.php';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS);
echo 'DB: ' . DB_NAME . PHP_EOL;
echo 'money_transfers: ' . $pdo->query('SELECT COUNT(*) FROM money_transfers')->fetchColumn() . PHP_EOL;
echo 'journal money_transfer ref: ' . $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_type='money_transfer'")->fetchColumn() . PHP_EOL;
$je = $pdo->query("SELECT id,entry_no,reference_id,is_reversed FROM journal_entries WHERE reference_type='money_transfer' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($je, JSON_PRETTY_PRINT) . PHP_EOL;