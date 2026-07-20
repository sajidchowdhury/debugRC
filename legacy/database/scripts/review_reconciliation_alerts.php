<?php
/**
 * Review recent reconciliation / audit alert log entries (Phase 4C).
 *
 * Usage:
 *   php database/scripts/review_reconciliation_alerts.php
 *   php database/scripts/review_reconciliation_alerts.php --limit=50
 *   php database/scripts/review_reconciliation_alerts.php --since=2026-06-01
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/config/config.php';
require_once $root . '/app/services/Accounting/ReconciliationService.php';

$opts = getopt('', ['limit::', 'since::']);
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 20;
$since = isset($opts['since']) ? (string)$opts['since'] : '';

$path = ReconciliationService::alertLogPath();
echo "Alert log: {$path}\n\n";

if (!is_readable($path)) {
    echo "No log file yet (no alerts recorded).\n";
    exit(0);
}

$entries = ReconciliationService::readRecentAlerts($limit);
if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    $entries = array_values(array_filter(
        $entries,
        static fn(array $row): bool => ($row['timestamp'] ?? '') >= $since
    ));
}

if ($entries === []) {
    echo "No matching entries.\n";
    exit(0);
}

foreach ($entries as $entry) {
    echo '[' . ($entry['timestamp'] ?? '') . '] ' . ($entry['message'] ?? '') . "\n";
    $context = $entry['context'] ?? [];
    if ($context !== []) {
        echo json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo str_repeat('-', 60) . "\n";
}

exit(0);
