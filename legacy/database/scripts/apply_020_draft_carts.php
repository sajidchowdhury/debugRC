<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = file_get_contents($root . '/database/migrations/020_sales_draft_carts.sql');
$pdo->exec($sql);
echo "sales_draft_carts table ensured.\n";