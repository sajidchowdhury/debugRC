<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';

$db = new Database();
$db->query("SHOW COLUMNS FROM stock_adjustments LIKE 'journal_entry_id'");
$row = $db->single();
echo $row ? "journal_entry_id exists\n" : "journal_entry_id MISSING\n";