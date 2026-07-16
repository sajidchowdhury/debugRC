<?php
/**
 * Phase 3 smoke checklist (manual / CLI hints).
 * Run: php database/tests/challan_reversal_smoke.php
 *
 * Verifies schema + reports invoices where challan totals may be inconsistent after reversal gaps.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Phase 3: Challan reversal & transport ===\n\n";

$cols = $pdo->query("SHOW COLUMNS FROM sales_invoices LIKE 'pre_challan_total'")->fetch();
echo $cols ? "[OK] sales_invoices.pre_challan_* columns exist\n" : "[FAIL] Run migration 017_challan_transport_snapshot.sql\n";

$cols2 = $pdo->query("SHOW COLUMNS FROM sales_challans LIKE 'transport_adjustment'")->fetch();
echo $cols2 ? "[OK] sales_challans.transport_adjustment exists\n" : "[FAIL] Run migration 017\n";

$sci = $pdo->query("SHOW TABLES LIKE 'sales_challan_items'")->fetch();
echo $sci ? "[OK] sales_challan_items table exists (W1 issue cost SSOT)\n" : "[WARN] Run migration 040_sales_challan_issue_cost.sql for issue-rate restore\n";

if ($sci) {
    $zeroRate = $pdo->query("
        SELECT COUNT(*) AS c FROM sales_challan_items WHERE issue_rate <= 0
    ")->fetch(PDO::FETCH_ASSOC);
    if ((int)($zeroRate['c'] ?? 0) === 0) {
        echo "[OK] All sales_challan_items rows have issue_rate > 0\n";
    } else {
        echo "[WARN] {$zeroRate['c']} sales_challan_items row(s) with zero issue_rate — reversal may fail until backfilled\n";
    }
}

$stuck = $pdo->query("
    SELECT si.id, si.invoice_code, si.status, si.total_amount, si.pre_challan_total,
           sc.challan_code, sc.is_reversed, sc.transport_adjustment
    FROM sales_invoices si
    INNER JOIN sales_challans sc ON sc.sales_invoice_id = si.id
    WHERE si.status = 'godown_issued'
      AND sc.is_reversed = 1
      AND si.pre_challan_total IS NOT NULL
      AND ABS(si.total_amount - si.pre_challan_total) > 0.02
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($stuck === []) {
    echo "[OK] No reversed challans with invoice total still above pre-challan snapshot\n";
} else {
    echo "[WARN] Invoices that may need manual fix after old reversals:\n";
    foreach ($stuck as $row) {
        echo "  - {$row['invoice_code']} total={$row['total_amount']} pre={$row['pre_challan_total']}\n";
    }
}

$misalignedGodown = $pdo->query("
    SELECT si.id, si.invoice_code, si.total_amount, cl.id AS ledger_id, cl.remarks
    FROM sales_invoices si
    INNER JOIN sales_challans sc ON sc.sales_invoice_id = si.id AND sc.is_reversed = 1
    INNER JOIN customer_ledger cl ON cl.reference_id = si.id
      AND cl.reference_type = 'invoice_adjustment'
      AND cl.remarks LIKE '%updated at godown save%'
      AND COALESCE(cl.is_reversed, 0) = 1
    WHERE si.status = 'godown_issued'
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($misalignedGodown === []) {
    echo "[OK] No godown transport adjustments wrongly marked reversed after challan undo\n";
} else {
    echo "[WARN] Legacy misaligned godown ledger rows (re-run reversal after fix or manual repair):\n";
    foreach ($misalignedGodown as $row) {
        echo "  - {$row['invoice_code']} ledger #{$row['ledger_id']}\n";
    }
}

echo "\nManual test: challan (change transport) → reverse → re-challan\n";
echo "  1. Godown + complete challan with different transport than invoice\n";
echo "  2. Reverse challan — expect godown_issued, transport/total restored, AR + GL adjustment reversed\n";
echo "  3. Complete challan again — new code, stock OUT, optional new transport delta\n";