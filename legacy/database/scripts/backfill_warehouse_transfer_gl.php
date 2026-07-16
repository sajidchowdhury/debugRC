<?php
/**
 * Backfill dual-branch GL for standalone warehouse transfers (no branch_demand_id).
 *
 *   php database/scripts/backfill_warehouse_transfer_gl.php --dry-run
 *   php database/scripts/backfill_warehouse_transfer_gl.php
 *   php database/scripts/backfill_warehouse_transfer_gl.php --id=8
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
$onlyId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $onlyId = (int)substr($arg, 5);
    }
}

$db = new Database();
$journalModel = new JournalEntryModel();
$journalPosting = new JournalPostingService();

echo 'Warehouse transfer GL backfill' . ($dryRun ? ' [DRY RUN]' : '') . "\n";
echo str_repeat('-', 60) . "\n";

$sql = "
    SELECT wt.id, wt.transfer_code, wt.transfer_date, wt.total_amount,
           wt.branch_demand_id, wt.journal_entry_id, wt.journal_entry_id_debtor,
           fw.branch_id AS from_branch_id, tw.branch_id AS to_branch_id,
           (
               SELECT COALESCE(SUM(wti.qty * wti.rate), 0)
               FROM warehouse_transfer_items wti
               WHERE wti.warehouse_transfer_id = wt.id
           ) AS computed_amount
    FROM warehouse_transfers wt
    JOIN warehouses fw ON fw.id = wt.from_warehouse_id
    JOIN warehouses tw ON tw.id = wt.to_warehouse_id
    WHERE COALESCE(wt.is_reversed, 0) = 0
      AND COALESCE(wt.branch_demand_id, 0) = 0
      AND fw.branch_id <> tw.branch_id
      AND (COALESCE(wt.journal_entry_id, 0) = 0 OR COALESCE(wt.journal_entry_id_debtor, 0) = 0)
";
if ($onlyId > 0) {
    $sql .= ' AND wt.id = ' . (int)$onlyId;
}
$sql .= ' ORDER BY wt.id ASC';

$db->query($sql);
$rows = $db->resultSet() ?: [];

$posted = $linked = $skipped = $errors = 0;

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $code = $row['transfer_code'] ?? ('WT-' . $id);
    $amount = round(max((float)$row['total_amount'], (float)$row['computed_amount']), 2);
    $fromBranch = (int)$row['from_branch_id'];
    $toBranch = (int)$row['to_branch_id'];

    if ($amount < 0.01) {
        echo "[SKIP] #{$id} {$code} — zero value\n";
        $skipped++;
        continue;
    }

    $existingFrom = $journalModel->findActiveJournalEntryByReference('warehouse_transfer', $id);
    $jeFrom = (int)($row['journal_entry_id'] ?? 0) ?: ($existingFrom ?? 0);

    if ($jeFrom && empty($row['journal_entry_id_debtor'])) {
        echo "[LINK] #{$id} {$code} — partial link sender journal #{$jeFrom}\n";
        if (!$dryRun) {
            $db->query('UPDATE warehouse_transfers SET journal_entry_id = :j WHERE id = :id');
            $db->bind(':j', $jeFrom);
            $db->bind(':id', $id);
            $db->execute();
        }
        $linked++;
        continue;
    }

    if ($jeFrom && !empty($row['journal_entry_id_debtor'])) {
        echo "[SKIP] #{$id} {$code} — already has GL\n";
        $skipped++;
        continue;
    }

    echo "[POST] #{$id} {$code} amount={$amount} branches {$fromBranch}→{$toBranch}\n";

    if ($dryRun) {
        $posted++;
        continue;
    }

    try {
        $result = $journalPosting->postWarehouseTransferInterbranch(
            $id,
            $code,
            $row['transfer_date'] ?? date('Y-m-d'),
            $fromBranch,
            $toBranch,
            $amount
        );
        if (($result['status'] ?? '') !== 'success') {
            echo '       ERR: ' . ($result['message'] ?? '') . "\n";
            $errors++;
            continue;
        }
        $jf = (int)($result['journal_entry_id'] ?? 0);
        $jt = (int)($result['journal_entry_id_debtor'] ?? 0);
        $db->query('UPDATE warehouse_transfers SET journal_entry_id = :jf, journal_entry_id_debtor = :jt WHERE id = :id');
        $db->bind(':jf', $jf ?: null);
        $db->bind(':jt', $jt ?: null);
        $db->bind(':id', $id);
        $db->execute();
        echo "       OK journals #{$jf} / #{$jt}\n";
        $posted++;
    } catch (Throwable $e) {
        echo '       EXC: ' . $e->getMessage() . "\n";
        $errors++;
    }
}

echo str_repeat('-', 60) . "\n";
echo "Rows: " . count($rows) . " | Posted: {$posted} | Linked: {$linked} | Skipped: {$skipped} | Errors: {$errors}\n";
if ($dryRun) {
    echo "Re-run without --dry-run to apply.\n";
}