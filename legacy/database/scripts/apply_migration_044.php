<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$path = $root . '/database/migrations/044_manual_journals.sql';
$sql = file_get_contents($path);
$statements = array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql))));

foreach ($statements as $statement) {
    if ($statement === '') {
        continue;
    }
    $n = $pdo->exec($statement);
    echo 'Executed (' . ($n === false ? 0 : $n) . ' rows): ' . substr(str_replace("\n", ' ', $statement), 0, 72) . "...\n";
}

echo "Migration 044 complete.\n";
