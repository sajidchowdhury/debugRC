<?php
// DEPRECATED (Phase 2): This script uses MySQL-specific SQL and will NOT run on PostgreSQL.
// It is retained for historical reference only. The Laravel migration system (Phase 2.2+)
// replaces database/run_migrations.php. Utility scripts will be rewritten as Laravel artisan
// commands. Test scripts will be rewritten as PHPUnit tests in Phase 3+.
// Do NOT run this script against PostgreSQL.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';

$db = new Database();
$db->query("SHOW COLUMNS FROM stock_adjustments LIKE 'journal_entry_id'");
$row = $db->single();
echo $row ? "journal_entry_id exists\n" : "journal_entry_id MISSING\n";
