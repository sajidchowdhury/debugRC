<?php
/**
 * Backfill GL journals for stock adjustments created before journal_entry_id linking.
 *
 * Usage (from project root):
 *   php database/scripts/backfill_stock_adjustment_gl.php --dry-run
 *   php database/scripts/backfill_stock_adjustment_gl.php
 *   php database/scripts/backfill_stock_adjustment_gl.php --limit=50
 *   php database/scripts/backfill_stock_adjustment_gl.php --id=8
 *
 * Options:
 *   --dry-run   List actions only; no inserts/updates
 *   --limit=N   Process at most N rows (0 = all)
 *   --id=N      Only adjustment id N
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../app/models/JournalEntryModel.php';
require_once __DIR__ . '/../../app/services/Accounting/JournalPostingService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$argv = $argv ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$limit = 0;
$onlyId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    }
    if (str_starts_with($arg, '--id=')) {
        $onlyId = (int)substr($arg, 5);
    }
}

$db = new Database();
$journalModel = new JournalEntryModel();
$journalPosting = new JournalPostingService();

echo "Stock adjustment GL backfill" . ($dryRun ? ' [DRY RUN]' : '') . "\n";
echo str_repeat('-', 60) . "\n";

$sql = "
    SELECT
        sa.id,
        sa.adjustment_code,
        sa.adjustment_date,
        sa.adjustment_type,
        sa.total_amount,
        sa.is_reversed,
        w.branch_id,
        COALESCE(sa.total_amount, 0) AS header_amount,
        (
            SELECT COALESCE(SUM(sai.qty * sai.rate), 0)
            FROM stock_adjustment_items sai
            WHERE sai.stock_adjustment_id = sa.id
        ) AS computed_amount,
        (
            SELECT COUNT(*)
            FROM stock_transactions st
            WHERE st.reference_type = 'adjustment'
              AND st.reference_id = sa.id
              AND COALESCE(st.is_reversed, 0) = 0
        ) AS active_movements
    FROM stock_adjustments sa
    INNER JOIN warehouses w ON w.id = sa.warehouse_id
    WHERE COALESCE(sa.is_reversed, 0) = 0
      AND COALESCE(sa.journal_entry_id, 0) = 0
";
if ($onlyId > 0) {
    $sql .= ' AND sa.id = ' . (int)$onlyId;
}
$sql .= ' ORDER BY sa.adjustment_date ASC, sa.id ASC';
if ($limit > 0) {
    $sql .= ' LIMIT ' . (int)$limit;
}

$db->query($sql);
$rows = $db->resultSet() ?: [];

$stats = [
    'candidates' => count($rows),
    'linked'     => 0,
    'posted'     => 0,
    'skipped'    => 0,
    'errors'     => 0,
];

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $code = $row['adjustment_code'] ?? ('ADJ-' . $id);
    $type = (string)($row['adjustment_type'] ?? '');
    $amount = round(max((float)$row['header_amount'], (float)$row['computed_amount']), 2);
    $movements = (int)($row['active_movements'] ?? 0);

    if ($movements === 0) {
        echo "[SKIP] #{$id} {$code} — no active stock movements\n";
        $stats['skipped']++;
        continue;
    }

    if ($amount < 0.01) {
        echo "[SKIP] #{$id} {$code} — zero value (no GL required)\n";
        $stats['skipped']++;
        continue;
    }

    if (!in_array($type, ['increase', 'decrease'], true)) {
        echo "[SKIP] #{$id} {$code} — unknown type '{$type}'\n";
        $stats['skipped']++;
        continue;
    }

    $existingJe = $journalModel->findActiveJournalEntryByReference('stock_adjustment', $id);
    if ($existingJe) {
        echo "[LINK] #{$id} {$code} — journal #{$existingJe} already exists\n";
        if (!$dryRun) {
            $db->query('UPDATE stock_adjustments SET journal_entry_id = :jeid WHERE id = :id');
            $db->bind(':jeid', $existingJe);
            $db->bind(':id', $id);
            $db->execute();
        }
        $stats['linked']++;
        continue;
    }

    $lossAmount = $type === 'decrease' ? $amount : 0.0;
    $gainAmount = $type === 'increase' ? $amount : 0.0;

    echo "[POST] #{$id} {$code} {$type} amount={$amount} branch=" . (int)$row['branch_id'] . "\n";

    if ($dryRun) {
        $stats['posted']++;
        continue;
    }

    try {
        $result = $journalPosting->postStockAdjustment($id, [
            'adjustment_code'  => $code,
            'adjustment_date'  => $row['adjustment_date'] ?? date('Y-m-d'),
            'branch_id'        => (int)($row['branch_id'] ?? 1),
        ], $lossAmount, $gainAmount);

        if (($result['status'] ?? '') !== 'success') {
            echo "       ERR: " . ($result['message'] ?? 'unknown') . "\n";
            $stats['errors']++;
            continue;
        }

        $jeId = (int)($result['journal_entry_id'] ?? 0);
        if ($jeId > 0) {
            $db->query('UPDATE stock_adjustments SET journal_entry_id = :jeid WHERE id = :id');
            $db->bind(':jeid', $jeId);
            $db->bind(':id', $id);
            $db->execute();
            echo "       OK journal #{$jeId} " . ($result['entry_no'] ?? '') . "\n";
            $stats['posted']++;
        } else {
            echo "       OK (no journal — zero GL)\n";
            $stats['skipped']++;
        }
    } catch (Throwable $e) {
        echo '       EXC: ' . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

echo str_repeat('-', 60) . "\n";
echo "Candidates: {$stats['candidates']}\n";
echo "Linked existing journal: {$stats['linked']}\n";
echo "Posted new journal: {$stats['posted']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";
if ($dryRun) {
    echo "\nRe-run without --dry-run to apply.\n";
}