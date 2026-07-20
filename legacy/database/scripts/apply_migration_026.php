<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';

$db = new Database();
$sql = trim(file_get_contents(__DIR__ . '/../migrations/026_warehouse_transfer_gl.sql'));
$parts = preg_split('/;\s*(?:\n|$)/', $sql);

foreach ($parts as $q) {
    $q = trim(preg_replace('/--[^\n]*\n?/', '', $q));
    if ($q === '') continue;
    try {
        $db->query($q);
        $db->execute();
        echo 'OK: ' . substr(str_replace("\n", ' ', $q), 0, 80) . "...\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}
echo "Done.\n";