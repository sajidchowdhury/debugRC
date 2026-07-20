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

$path = $root . '/database/migrations/040_sales_challan_issue_cost.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Cannot read migration file\n");
    exit(1);
}

// Strip line comments, then split on semicolon at end of statement.
$lines = preg_split('/\R/', $sql);
$buffer = '';
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }
    $buffer .= $line . "\n";
}

$statements = array_filter(array_map('trim', explode(';', $buffer)));

foreach ($statements as $statement) {
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement);
    echo 'Executed: ' . substr(str_replace("\n", ' ', $statement), 0, 80) . "...\n";
}

echo "Migration 040 complete.\n";
