<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';

$db = new Database();
$sql = file_get_contents(__DIR__ . '/../migrations/023_stock_take_phase1.sql');
$parts = preg_split('/;\s*\n/', $sql);

foreach ($parts as $q) {
    $q = trim($q);
    if ($q === '' || str_starts_with($q, '--')) {
        continue;
    }
    try {
        $db->query($q);
        $db->execute();
        echo "OK: " . substr(str_replace("\n", ' ', $q), 0, 70) . "...\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";