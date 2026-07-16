<?php
/**
 * database/seed_large_test_data.php
 *
 * Rewritten based on CURRENT DB schema from osudlagb_remotecenter.sql (recent dump 2026-06).
 *
 * PURPOSE: Force-insert LARGE realistic volume of test data so every report
 * (ProductStockAnalysis, Sales/Purchase History, Aging, TrialBalance, DailyCashBook,
 * Stock Valuation, Ledgers, etc.) has rich, filterable, multi-branch/warehouse data
 * with correct is_reversed=0, proper polarities, and historical dates.
 *
 * CRITICAL:
 * - This script TRUNCATES transactional tables (backs up first!!).
 * - Keep ONLY basics before running: branches, warehouses, users, banks,
 *   product_categories, products (already hundreds in dump), ledgers (with natures),
 *   document_sequences (optional), employees if any.
 * - Customers/Suppliers: script will add more if needed (existing kept then augmented).
 * - Run from project root: php database/seed_large_test_data.php
 * - After run, you may need: php database/scripts/apply_stock_triggers.php or GL backfills
 *   if some reports rely on them.
 *
 * The data exercises:
 * - All stock_transaction reference_types + positive/negative qty over date range.
 * - Sales invoices + items + challans + matching customer_ledger 'invoice' (debit).
 * - Purchase receives + items + matching supplier_ledger 'purchase' (debit per convention).
 * - Customer payments (credit to cust ledger) + supplier payments (credit to sup ledger).
 * - Other income/expense (cash + bank) + cash_ledger entries.
 * - Money transfers between branches.
 * - Manual journal_entries (balanced Dr/Cr using real cash_bank + income/expense ledgers).
 * - warehouse_stock snapshot rebuilt from tx (for valuation/current stock reports).
 * - Zero is_reversed rows (you can reverse some via UI later for reversal testing).
 */

if (php_sapi_name() !== 'cli') {
    die("Must run from CLI: php database/seed_large_test_data.php\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

echo "==============================================\n";
echo "  LARGE TEST DATA SEEDER (schema-accurate 2026)\n";
echo "==============================================\n";
echo "Target DB: " . DB_NAME . "\n\n";

echo ">>> WARNING: This will TRUNCATE all transactional data and force-insert large volume.\n";
echo ">>> Backup your DB first (mysqldump or the osudlagb_remotecenter.sql you have).\n";
echo ">>> Only basics (branches/warehouses/products/ledgers/users/banks) should remain.\n";
echo ">>> Press Ctrl+C now if not sure. Sleeping 4s...\n\n";
sleep(4);

$db = new Database();

/**
 * Generate a unique code for a table/column that has a UNIQUE constraint.
 * Used for customer_code, supplier_code, invoice_code, receive_code, payment_code, etc.
 * Checks the DB on each attempt. Falls back if we somehow can't find a free one after many tries.
 */
function generateUniqueCode($db, string $prefix, string $table, string $column, int $randMin = 100000, int $randMax = 999999): string {
    $attempts = 0;
    $maxAttempts = 50;
    do {
        $randPart = str_pad((string)mt_rand($randMin, $randMax), 6, '0', STR_PAD_LEFT);
        $code = $prefix . $randPart;
        $db->query("SELECT 1 FROM `$table` WHERE `$column` = :code LIMIT 1");
        $db->bind(':code', $code);
        $row = $db->resultSet();
        $attempts++;
        if (!$row) {
            return $code;
        }
    } while ($attempts < $maxAttempts);

    // Fallback: highly unique
    return $prefix . date('YmdHis') . mt_rand(10, 99);
}

// ========== TRUNCATE TRANSACTIONAL TABLES (clean slate for reports) ==========
echo "Truncating transactional tables (only those that exist)...\n";
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$truncateTables = [
    // Child tables first (those with FKs pointing to the parents below)
    'invoice_payment_allocations',
    'sales_invoice_dispatchers',
    'sales_invoice_dispatches',
    'sales_draft_carts',
    'customer_payment_settlements',
    'supplier_payment_settlements',
    'journal_posting_logs',
    'journal_lines',
    'stock_take_items',
    'stock_take_warehouses',
    'damage_invoice_items',
    'stock_adjustment_items',
    'purchase_return_items',
    'purchase_receive_items',
    'sales_return_items',
    'sales_invoice_items',
    'warehouse_transfer_items',
    'branch_demand_items',

    // Then the main transactional parents
    'stock_transactions', 'warehouse_stock',
    'sales_invoices', 'sales_challans',
    'sales_returns',
    'purchase_receives', 'purchase_returns',
    'customer_payments', 'customer_ledger',
    'supplier_payments', 'supplier_ledger',
    'other_incomes', 'other_expenses',
    'money_transfers',
    'journal_entries',
    'cash_ledger',
    'damage_invoices',
    'stock_adjustments',
    'stock_take_sessions',
    'warehouse_transfers',
    'branch_demands', 'branch_expenses', 'branch_ledger'
];
foreach ($truncateTables as $t) {
    // Safe truncate: ignore if table does not exist in this DB snapshot
    $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
    $db->bind(':t', $t);
    $exists = $db->resultSet();
    if ($exists) {
        // Use DELETE (not TRUNCATE) because MariaDB/XAMPP often blocks TRUNCATE
        // on tables involved in FK relationships (even with FK checks disabled).
        $db->query("DELETE FROM `$t`");
        $db->execute();
    }
}
$db->query("SET FOREIGN_KEY_CHECKS = 1");
$db->execute();

// Reset auto-increment on main tables so new seed data has clean low IDs (nice for debugging)
echo "Resetting AUTO_INCREMENT on key tables...\n";
$resetAutoInc = [
    'sales_invoices', 'sales_challans', 'sales_returns',
    'purchase_receives', 'purchase_returns',
    'customer_payments', 'supplier_payments',
    'other_incomes', 'other_expenses',
    'money_transfers',
    'journal_entries',
    'stock_transactions',
    'cash_ledger', 'customer_ledger', 'supplier_ledger'
];
foreach ($resetAutoInc as $t) {
    $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
    $db->bind(':t', $t);
    if ($db->resultSet()) {
        $db->query("ALTER TABLE `$t` AUTO_INCREMENT = 1");
        $db->execute();
    }
}
echo "Truncates + auto-inc reset done.\n\n";

// ========== LOAD BASICS (must exist) ==========
echo "Loading reference data (branches, warehouses, products, ledgers, users, banks)...\n";

$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id");
$branchRows = $db->resultSet() ?: [];
$branchIds = array_column($branchRows, 'id');
if (empty($branchIds)) die("FATAL: No active branches. Keep basic master data.\n");

$db->query("SELECT id, branch_id FROM warehouses WHERE is_active = 1 ORDER BY id");
$whRows = $db->resultSet() ?: [];
$warehouseIds = array_column($whRows, 'id');
$whByBranch = [];
foreach ($whRows as $w) {
    $bid = $w['branch_id'];
    if (!isset($whByBranch[$bid])) $whByBranch[$bid] = [];
    $whByBranch[$bid][] = (int)$w['id'];
}
if (empty($warehouseIds)) die("FATAL: No active warehouses.\n");

$db->query("SELECT id FROM product_categories WHERE is_active = 1 ORDER BY id");
$catRows = $db->resultSet() ?: [];
$categoryIds = array_column($catRows, 'id');

$db->query("SELECT id, category_id FROM products WHERE is_active = 1 ORDER BY id");
$prodRows = $db->resultSet() ?: [];
$productIds = array_column($prodRows, 'id');

$db->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 10");
$userRows = $db->resultSet() ?: [];
$userIds = array_column($userRows, 'id');
if (empty($userIds)) $userIds = [1];

$db->query("SELECT id FROM banks WHERE is_active = 1 ORDER BY id");
$bankRows = $db->resultSet() ?: [];
$bankIds = array_column($bankRows, 'id');
if (empty($bankIds)) $bankIds = [null];

$db->query("SELECT id FROM ledgers WHERE account_type = 'Income' AND is_active = 1 ORDER BY id");
$incomeLedgers = array_column($db->resultSet() ?: [], 'id');
if (empty($incomeLedgers)) $incomeLedgers = [14]; // Other Income from typical seed

$db->query("SELECT id FROM ledgers WHERE account_type = 'Expense' AND is_active = 1 ORDER BY id");
$expenseLedgers = array_column($db->resultSet() ?: [], 'id');
if (empty($expenseLedgers)) $expenseLedgers = [15,16];

$db->query("SELECT id FROM ledgers WHERE ledger_nature = 'cash_bank' AND is_active = 1 ORDER BY id");
$cashBankLedgers = array_column($db->resultSet() ?: [], 'id');
if (empty($cashBankLedgers)) $cashBankLedgers = [18,19];

echo "Loaded: " . count($branchIds) . " branches, " . count($warehouseIds) . " warehouses, "
   . count($productIds) . " products, " . count($categoryIds) . " categories, "
   . count($userIds) . " users, " . count($bankIds) . " banks, "
   . count($incomeLedgers) . " income ledgers, " . count($cashBankLedgers) . " cash/bank ledgers\n\n";

if (count($productIds) < 50) {
    echo "Adding extra products for volume...\n";
    $added = 0;
    for ($i = 0; $i < 120; $i++) {
        $cat = $categoryIds ? $categoryIds[array_rand($categoryIds)] : null;
        $code = generateUniqueCode($db, 'TST', 'products', 'product_code');
        $name = 'Seed Product ' . ($i + 1) . ' ' . ['Remote','Adapter','Cable','Meter','Transformer'][array_rand([0,1,2,3,4])];
        $db->query("INSERT INTO products (product_code, product_name, category_id, unit, pcs_per_carton, safety_stock, is_active, created_by)
                    VALUES (:c, :n, :cat, 'Pcs', 1, 5, 1, :u)");
        $db->bind(':c', $code); $db->bind(':n', $name); $db->bind(':cat', $cat); $db->bind(':u', $userIds[0]);
        if ($db->execute()) {
            $productIds[] = $db->lastInsertId();
            $added++;
        }
    }
    echo "Added $added products.\n";
}

// ========== CUSTOMERS (augment for AR/aging reports) ==========
echo "Seeding additional customers (for AR volume)...\n";
$custIds = [];
$shopBases = ['Rahim Electronics','Karim Traders','Fatima Store','Ali Mart','Sultana Electronics','Babu Brothers','City Lights','Modern House','Star Electronics','Quick Buy','New Tech','Power House','Home Solutions','Digital Mart'];
for ($i = 0; $i < 250; $i++) {
    $code = generateUniqueCode($db, '9', 'customers', 'customer_code'); // guaranteed unique even if previous seeds or original data used high numbers
    $shop = $shopBases[array_rand($shopBases)] . ' ' . ($i % 47 + 1);
    $cname = 'Contact-' . ($i + 1);
    $mobile = '017' . mt_rand(10000000, 99999999);
    $addr = 'Test Market, Dhaka-' . mt_rand(1000, 1500);
    $limit = mt_rand(0, 1) ? mt_rand(30000, 2500000) : 0;
    $sp = $userIds[array_rand($userIds)];

    $db->query("INSERT INTO customers (customer_code, shop_name, customer_name, mobile, address, sales_person_id, credit_limit, is_active, created_by)
                VALUES (:code, :shop, :cname, :mob, :addr, :sp, :lim, 1, :u)");
    $db->bind(':code', $code); $db->bind(':shop', $shop); $db->bind(':cname', $cname);
    $db->bind(':mob', $mobile); $db->bind(':addr', $addr); $db->bind(':sp', $sp);
    $db->bind(':lim', $limit); $db->bind(':u', $userIds[0]);
    if ($db->execute()) {
        $custIds[] = $db->lastInsertId();
    }
}
echo count($custIds) . " new customers added (existing masters kept).\n";

// ========== SUPPLIERS ==========
echo "Seeding additional suppliers...\n";
$supIds = [];
$supBases = ['Khadiza Plastic','Ma Ayesha Plastic','Foyaz Plastic','Monia Plastic','Mizan Products','Tanha Plastic','Star Industry','Soudia Electronics','China Traders','RC Star','Mirdha Packing'];
for ($i = 0; $i < 90; $i++) {
    $code = generateUniqueCode($db, 'S', 'suppliers', 'supplier_code');
    $sname = $supBases[array_rand($supBases)] . ' ' . ($i % 19 + 1);
    $mob = '018' . mt_rand(10000000, 99999999);
    $db->query("INSERT INTO suppliers (supplier_code, supplier_name, mobile, address, is_active, created_by)
                VALUES (:c, :n, :m, :a, 1, :u)");
    $db->bind(':c', $code); $db->bind(':n', $sname); $db->bind(':m', $mob);
    $db->bind(':a', 'Wholesale Area, Dhaka'); $db->bind(':u', $userIds[0]);
    if ($db->execute()) $supIds[] = $db->lastInsertId();
}
echo count($supIds) . " new suppliers.\n";

// ========== DATES ==========
$startDate = strtotime('2025-01-01');
$endDate   = strtotime('2026-06-04');
function randDate($start, $end) {
    return date('Y-m-d', mt_rand($start, $end));
}

// ========== LARGE STOCK TRANSACTIONS (primary for ProductStockAnalysis + valuation) ==========
echo "Seeding LARGE stock_transactions (core for all stock reports)...\n";
$stCount = 0;
$refTypes = ['opening_balance','purchase_receive','purchase_return','sales_challan','sales_return','warehouse_transfer','damage','adjustment','stock_take'];
$prodsForStock = array_slice($productIds, 0, min(120, count($productIds)));

foreach ($warehouseIds as $whId) {
    $whProds = array_slice($prodsForStock, 0, min(70, count($prodsForStock)));
    foreach ($whProds as $pId) {
        $numTx = mt_rand(12, 38);
        $cum = 0.0;
        for ($t = 0; $t < $numTx; $t++) {
            $dt = randDate($startDate, $endDate);
            $type = $refTypes[array_rand($refTypes)];
            $isIn = in_array($type, ['purchase_receive','sales_return','opening_balance','stock_take','adjustment']) && (mt_rand(0,2) > 0);
            $qty = $isIn ? (mt_rand(4, 55) * (mt_rand(0,1) ? 1 : 0.5)) : -(mt_rand(1, 28) * (mt_rand(0,1) ? 1 : 0.5));
            if ($cum + $qty < -2) $qty = abs($qty); // keep realistic
            $rate = mt_rand(35, 1250) / 1.0;
            $refId = mt_rand(1, 80000);
            $u = $userIds[array_rand($userIds)];

            $db->query("INSERT INTO stock_transactions
                (transaction_date, reference_type, reference_id, product_id, warehouse_id, qty, rate, remarks, created_by, is_reversed)
                VALUES (:dt, :rt, :rid, :pid, :wh, :q, :r, 'Large seed volume', :u, 0)");
            $db->bind(':dt', $dt); $db->bind(':rt', $type); $db->bind(':rid', $refId);
            $db->bind(':pid', $pId); $db->bind(':wh', $whId); $db->bind(':q', $qty);
            $db->bind(':r', $rate); $db->bind(':u', $u);
            if ($db->execute()) {
                $stCount++;
                $cum += $qty;
            }
        }
    }
}
echo "$stCount stock_transactions inserted.\n";

// Rebuild warehouse_stock snapshot (for current stock + valuation reports)
echo "Rebuilding warehouse_stock from transactions...\n";
$db->query("DELETE FROM warehouse_stock");
$db->execute();

$db->query("
    INSERT INTO warehouse_stock (warehouse_id, product_id, qty, avg_cost)
    SELECT 
        warehouse_id, 
        product_id, 
        SUM(qty) as qty,
        COALESCE(
            SUM(CASE WHEN qty > 0 THEN qty * rate ELSE 0 END) / NULLIF(SUM(CASE WHEN qty > 0 THEN qty ELSE 0 END), 0),
            0
        ) as avg_cost
    FROM stock_transactions
    WHERE COALESCE(is_reversed, 0) = 0
    GROUP BY warehouse_id, product_id
    HAVING SUM(qty) >= 0
");
if ($db->execute()) {
    echo "warehouse_stock rebuilt.\n";
} else {
    echo "warehouse_stock sync note: some rows may have been prevented by trigger (negative). OK for test.\n";
}

// ========== SALES INVOICES + ITEMS + CHALLANS + LEDGER (AR side) ==========
echo "Seeding sales_invoices + items + challans + customer_ledger (invoice debits)...\n";
$salesCount = 0;
$invLedgerCount = 0;

for ($i = 0; $i < 520; $i++) {
    $dt = randDate($startDate, $endDate);
    $cust = $custIds[array_rand($custIds)];
    $br = $branchIds[array_rand($branchIds)];
    $sub = round(mt_rand(650, 18500) / 1.0, 2);
    $disc = round(mt_rand(0, 420) / 10.0, 2);
    $trans = round(mt_rand(0, 280) / 1.0, 2);
    $tot = round($sub - $disc + $trans, 2);
    $code = generateUniqueCode($db, 'SI-' . date('Ymd', strtotime($dt)) . '-', 'sales_invoices', 'invoice_code');

    $u = $userIds[array_rand($userIds)];

    $db->query("INSERT INTO sales_invoices 
        (invoice_code, invoice_date, customer_id, salesman_id, branch_id, subtotal, discount, transport_cost, total_amount, status, created_by, is_reversed)
        VALUES (:c, :d, :cid, :sid, :b, :sub, :disc, :tr, :tot, 'challan_completed', :u, 0)");
    $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':cid', $cust);
    $db->bind(':sid', $u); $db->bind(':b', $br);
    $db->bind(':sub', $sub); $db->bind(':disc', $disc); $db->bind(':tr', $trans);
    $db->bind(':tot', $tot); $db->bind(':u', $u);

    if ($db->execute()) {
        $invId = $db->lastInsertId();
        $salesCount++;

        // 1-3 items
        $numItems = mt_rand(1, 3);
        $whsForBr = $whByBranch[$br] ?? $warehouseIds;
        for ($ii = 0; $ii < $numItems; $ii++) {
            $p = $productIds[array_rand($productIds)];
            $q = mt_rand(1, 6);
            $r = round($sub / max(1, $numItems) / max(1, $q) * (0.85 + mt_rand(0,30)/100), 2);
            $wh = $whsForBr[array_rand($whsForBr)];

            $db->query("INSERT INTO sales_invoice_items (sales_invoice_id, product_id, warehouse_id, qty, rate)
                        VALUES (:iid, :p, :wh, :q, :r)");
            $db->bind(':iid', $invId); $db->bind(':p', $p); $db->bind(':wh', $wh);
            $db->bind(':q', $q); $db->bind(':r', $r);
            $db->execute();
        }

        // Challan (max space report style friendly)
        $chCode = generateUniqueCode($db, 'CH-' . date('Ymd', strtotime($dt)) . '-', 'sales_challans', 'challan_code');
        $wh = $whsForBr[array_rand($whsForBr)];
        $db->query("INSERT INTO sales_challans (challan_code, sales_invoice_id, challan_date, warehouse_id, total_amount, created_by, is_reversed)
                    VALUES (:c, :iid, :d, :wh, :tot, :u, 0)");
        $db->bind(':c', $chCode); $db->bind(':iid', $invId); $db->bind(':d', $dt);
        $db->bind(':wh', $wh); $db->bind(':tot', $tot); $db->bind(':u', $u);
        $db->execute();

        // Customer ledger - sales on account (debit side per aging convention)
        $db->query("INSERT INTO customer_ledger (transaction_date, customer_id, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, branch_id, is_reversed)
                    VALUES (:d, :cid, 'invoice', :rid, :deb, 0, 0, 'Seed sales invoice', :u, :b, 0)");
        $db->bind(':d', $dt); $db->bind(':cid', $cust); $db->bind(':rid', $invId);
        $db->bind(':deb', $tot); $db->bind(':u', $u); $db->bind(':b', $br);
        if ($db->execute()) $invLedgerCount++;
    }
}
echo "$salesCount sales invoices + challans + $invLedgerCount customer_ledger invoice entries.\n";

// ========== PURCHASE RECEIVES + ITEMS + SUPPLIER LEDGER (purchase side) ==========
echo "Seeding purchase_receives + items + supplier_ledger (purchase debits)...\n";
$prCount = 0;
$prLedgerCount = 0;

for ($i = 0; $i < 310; $i++) {
    $dt = randDate($startDate, $endDate);
    $sup = $supIds[array_rand($supIds)];
    $br = $branchIds[array_rand($branchIds)];
    $tot = round(mt_rand(1800, 32000) / 1.0, 2);
    $code = generateUniqueCode($db, 'GRN-' . date('Ymd', strtotime($dt)) . '-', 'purchase_receives', 'receive_code');
    $u = $userIds[array_rand($userIds)];
    $whsForBr = $whByBranch[$br] ?? $warehouseIds;
    $wh = $whsForBr[array_rand($whsForBr)];

    $db->query("INSERT INTO purchase_receives (receive_code, supplier_id, branch_id, receive_date, total_amount, status, remarks, created_by, is_reversed)
                VALUES (:c, :s, :b, :d, :t, 'received', 'Large seed data', :u, 0)");
    $db->bind(':c', $code); $db->bind(':s', $sup); $db->bind(':b', $br);
    $db->bind(':d', $dt); $db->bind(':t', $tot); $db->bind(':u', $u);

    if ($db->execute()) {
        $recId = $db->lastInsertId();
        $prCount++;

        // 1-4 items
        $num = mt_rand(1, 4);
        for ($ii = 0; $ii < $num; $ii++) {
            $p = $productIds[array_rand($productIds)];
            $q = mt_rand(5, 45);
            $r = round($tot / max(1, $num) / max(1, $q) * (0.9 + mt_rand(0,20)/100), 2);

            $db->query("INSERT INTO purchase_receive_items (purchase_receive_id, product_id, warehouse_id, qty, rate)
                        VALUES (:rid, :p, :wh, :q, :r)");
            $db->bind(':rid', $recId); $db->bind(':p', $p); $db->bind(':wh', $wh);
            $db->bind(':q', $q); $db->bind(':r', $r);
            $db->execute();
        }

        // Supplier ledger - purchase (debit side to create payable per aging formula)
        $db->query("INSERT INTO supplier_ledger (transaction_date, supplier_id, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, branch_id, is_reversed)
                    VALUES (:d, :sid, 'purchase', :rid, :deb, 0, 0, 'Seed purchase receive', :u, :b, 0)");
        $db->bind(':d', $dt); $db->bind(':sid', $sup); $db->bind(':rid', $recId);
        $db->bind(':deb', $tot); $db->bind(':u', $u); $db->bind(':b', $br);
        if ($db->execute()) $prLedgerCount++;
    }
}
echo "$prCount purchase receives + $prLedgerCount supplier_ledger purchase entries.\n";

// ========== CUSTOMER PAYMENTS (credits) + some cash_ledger ==========
echo "Seeding customer_payments (receive) + customer_ledger credits...\n";
$cpCount = 0;
$cpLedger = 0;
$cashFromCust = 0;

foreach (array_slice($custIds, 0, 180) as $cid) {
    $loops = mt_rand(1, 5);
    for ($k = 0; $k < $loops; $k++) {
        $dt = randDate($startDate, $endDate);
        $amt = round(mt_rand(300, 9500) / 1.0, 2);
        $mode = ['cash', 'bank'][mt_rand(0, 1)];
        $code = generateUniqueCode($db, 'CP-' . date('Ymd', strtotime($dt)) . '-', 'customer_payments', 'payment_code');
        $br = $branchIds[array_rand($branchIds)];
        $bank = $bankIds && $mode === 'bank' ? $bankIds[array_rand($bankIds)] : null;
        $u = $userIds[array_rand($userIds)];

        $db->query("INSERT INTO customer_payments 
            (payment_code, payment_date, transaction_type, customer_id, amount, payment_mode, bank_id, remarks, created_by, branch_id, is_reversed)
            VALUES (:c, :d, 'receive', :cid, :a, :m, :b, 'Seed customer payment', :u, :br, 0)");
        $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':cid', $cid);
        $db->bind(':a', $amt); $db->bind(':m', $mode); $db->bind(':b', $bank);
        $db->bind(':u', $u); $db->bind(':br', $br);

        if ($db->execute()) {
            $pid = $db->lastInsertId();
            $cpCount++;

            // credit to customer (reduces due)
            $db->query("INSERT INTO customer_ledger (transaction_date, customer_id, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, branch_id, is_reversed)
                        VALUES (:d, :cid, 'payment', :rid, 0, :cr, 0, 'Seed payment', :u, :b, 0)");
            $db->bind(':d', $dt); $db->bind(':cid', $cid); $db->bind(':rid', $pid);
            $db->bind(':cr', $amt); $db->bind(':u', $u); $db->bind(':b', $br);
            $db->execute();
            $cpLedger++;

            if ($mode === 'cash') {
                $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                            VALUES (:d, :b, 'main_cash', 'customer_payment', :rid, 0, :cr, 0, 'Seed from cust payment', :u, 0)");
                $db->bind(':d', $dt); $db->bind(':b', $br); $db->bind(':rid', $pid);
                $db->bind(':cr', $amt); $db->bind(':u', $u);
                $db->execute();
                $cashFromCust++;
            }
        }
    }
}
echo "$cpCount customer payments + $cpLedger ledger credits + $cashFromCust cash inflows.\n";

// ========== SUPPLIER PAYMENTS (credits to reduce payable) ==========
echo "Seeding supplier_payments (credits to ledger)...\n";
$spCount = 0;
$spLedger = 0;

foreach (array_slice($supIds, 0, 70) as $sid) {
    $loops = mt_rand(1, 4);
    for ($k = 0; $k < $loops; $k++) {
        $dt = randDate($startDate, $endDate);
        $amt = round(mt_rand(1500, 15000) / 1.0, 2);
        $code = generateUniqueCode($db, 'SP-' . date('Ymd', strtotime($dt)) . '-', 'supplier_payments', 'payment_code');
        $br = $branchIds[array_rand($branchIds)];
        $u = $userIds[array_rand($userIds)];

        $db->query("INSERT INTO supplier_payments 
            (payment_code, payment_date, transaction_type, supplier_id, amount, payment_mode, bank_id, remarks, created_by, branch_id, is_reversed)
            VALUES (:c, :d, 'payment', :sid, :a, 'bank', NULL, 'Seed supplier payment', :u, :br, 0)");
        $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':sid', $sid);
        $db->bind(':a', $amt); $db->bind(':u', $u); $db->bind(':br', $br);

        if ($db->execute()) {
            $pid = $db->lastInsertId();
            $spCount++;

            // CREDIT to supplier_ledger (reduces the debit due)
            $db->query("INSERT INTO supplier_ledger (transaction_date, supplier_id, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, branch_id, is_reversed)
                        VALUES (:d, :sid, 'payment', :rid, 0, :cr, 0, 'Seed payment', :u, :b, 0)");
            $db->bind(':d', $dt); $db->bind(':sid', $sid); $db->bind(':rid', $pid);
            $db->bind(':cr', $amt); $db->bind(':u', $u); $db->bind(':b', $br);
            $db->execute();
            $spLedger++;
        }
    }
}
echo "$spCount supplier payments + $spLedger supplier_ledger credits.\n";

// ========== OTHER INCOMES + OTHER EXPENSES ==========
echo "Seeding other_incomes + other_expenses...\n";
$oiCount = 0; $oeCount = 0; $oiCash = 0; $oeCash = 0;

for ($i = 0; $i < 95; $i++) {
    $dt = randDate($startDate, $endDate);
    $amt = round(mt_rand(180, 6200) / 1.0, 2);
    $br = $branchIds[array_rand($branchIds)];
    $code = generateUniqueCode($db, 'OI-' . date('Ymd', strtotime($dt)) . '-', 'other_incomes', 'income_code');
    $lid = $incomeLedgers[array_rand($incomeLedgers)];
    $mode = ['cash','bank'][mt_rand(0,1)];
    $bank = ($mode === 'bank' && $bankIds) ? $bankIds[array_rand($bankIds)] : null;
    $u = $userIds[array_rand($userIds)];

    $db->query("INSERT INTO other_incomes (income_code, income_date, ledger_id, amount, payment_mode, bank_id, remarks, created_by, branch_id, is_reversed)
                VALUES (:c, :d, :l, :a, :m, :b, 'Seed other income', :u, :br, 0)");
    $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':l', $lid);
    $db->bind(':a', $amt); $db->bind(':m', $mode); $db->bind(':b', $bank);
    $db->bind(':u', $u); $db->bind(':br', $br);
    if ($db->execute()) {
        $oiCount++;
        if ($mode === 'cash') {
            $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                        VALUES (:d, :b, 'main_cash', 'adjustment', :rid, 0, :cr, 0, 'Seed other income cash', :u, 0)");
            $db->bind(':d', $dt); $db->bind(':b', $br); $db->bind(':rid', $db->lastInsertId());
            $db->bind(':cr', $amt); $db->bind(':u', $u);
            $db->execute();
            $oiCash++;
        }
    }
}

for ($i = 0; $i < 78; $i++) {
    $dt = randDate($startDate, $endDate);
    $amt = round(mt_rand(120, 4800) / 1.0, 2);
    $br = $branchIds[array_rand($branchIds)];
    $code = generateUniqueCode($db, 'OE-' . date('Ymd', strtotime($dt)) . '-', 'other_expenses', 'expense_code');
    $lid = $expenseLedgers[array_rand($expenseLedgers)];
    $mode = ['cash','bank'][mt_rand(0,1)];
    $bank = ($mode === 'bank' && $bankIds) ? $bankIds[array_rand($bankIds)] : null;
    $u = $userIds[array_rand($userIds)];

    $db->query("INSERT INTO other_expenses (expense_code, expense_date, ledger_id, amount, payment_mode, bank_id, remarks, created_by, branch_id, is_reversed)
                VALUES (:c, :d, :l, :a, :m, :b, 'Seed other expense', :u, :br, 0)");
    $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':l', $lid);
    $db->bind(':a', $amt); $db->bind(':m', $mode); $db->bind(':b', $bank);
    $db->bind(':u', $u); $db->bind(':br', $br);
    if ($db->execute()) {
        $oeCount++;
        if ($mode === 'cash') {
            $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                        VALUES (:d, :b, 'main_cash', 'adjustment', :rid, :db, 0, 0, 'Seed other expense cash', :u, 0)");
            $db->bind(':d', $dt); $db->bind(':b', $br); $db->bind(':rid', $db->lastInsertId());
            $db->bind(':db', $amt); $db->bind(':u', $u);
            $db->execute();
            $oeCash++;
        }
    }
}
echo "$oiCount other incomes ($oiCash cash), $oeCount other expenses ($oeCash cash).\n";

// ========== MONEY TRANSFERS ==========
echo "Seeding money_transfers...\n";
$mtCount = 0;
for ($i = 0; $i < 55; $i++) {
    if (count($branchIds) < 2) break;
    $f = $branchIds[array_rand($branchIds)];
    $t = $branchIds[array_rand($branchIds)];
    if ($f == $t) continue;

    $dt = randDate($startDate, $endDate);
    $amt = round(mt_rand(800, 18500) / 1.0, 2);
    $code = generateUniqueCode($db, 'MT-' . date('Ymd', strtotime($dt)) . '-', 'money_transfers', 'transfer_code');
    $typ = ['cash_to_bank', 'bank_to_cash', 'cash_to_cash', 'bank_to_bank'][array_rand([0,1,2,3])];
    $u = $userIds[array_rand($userIds)];
    $fromBank = ($typ === 'bank_to_cash' || $typ === 'bank_to_bank') && $bankIds ? $bankIds[array_rand($bankIds)] : null;
    $toBank   = ($typ === 'cash_to_bank' || $typ === 'bank_to_bank') && $bankIds ? $bankIds[array_rand($bankIds)] : null;
    $fromCP = in_array($typ, ['cash_to_bank','cash_to_cash']) ? 'main_cash' : null;
    $toCP   = in_array($typ, ['bank_to_cash','cash_to_cash']) ? 'main_cash' : null;

    $db->query("INSERT INTO money_transfers 
        (transfer_code, transfer_date, from_branch_id, to_branch_id, amount, transfer_type, from_bank_id, to_bank_id, from_cash_point, to_cash_point, narration, branch_id, created_by, is_reversed)
        VALUES (:c, :d, :f, :t, :a, :typ, :fb, :tb, :fcp, :tcp, 'Large seed transfer', :b, :u, 0)");
    $db->bind(':c', $code); $db->bind(':d', $dt); $db->bind(':f', $f); $db->bind(':t', $t);
    $db->bind(':a', $amt); $db->bind(':typ', $typ); $db->bind(':fb', $fromBank); $db->bind(':tb', $toBank);
    $db->bind(':fcp', $fromCP); $db->bind(':tcp', $toCP); $db->bind(':b', $f); $db->bind(':u', $u);

    if ($db->execute()) {
        $mtCount++;
        // also cash_ledger movements for cash types (simplified)
        if (in_array($typ, ['cash_to_cash','cash_to_bank'])) {
            $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                        VALUES (:d, :b, 'main_cash', 'money_transfer', :rid, :dbt, 0, 0, 'Seed MT out', :u, 0)");
            $db->bind(':d', $dt); $db->bind(':b', $f); $db->bind(':rid', $db->lastInsertId());
            $db->bind(':dbt', $amt); $db->bind(':u', $u);
            $db->execute();
        }
        if (in_array($typ, ['cash_to_cash','bank_to_cash'])) {
            $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                        VALUES (:d, :b, 'main_cash', 'money_transfer', :rid, 0, :cr, 0, 'Seed MT in', :u, 0)");
            $db->bind(':d', $dt); $db->bind(':b', $t); $db->bind(':rid', $db->lastInsertId());
            $db->bind(':cr', $amt); $db->bind(':u', $u);
            $db->execute();
        }
    }
}
echo "$mtCount money transfers + related cash movements.\n";

// ========== JOURNAL ENTRIES (for TB, GL, cashbook cross checks) ==========
echo "Seeding journal_entries (balanced, using real ledgers)...\n";
$jeCount = 0;
$jeLineCount = 0;
$cashL = $cashBankLedgers[0] ?? 18;
$incL = $incomeLedgers[0] ?? 14;
$expL = $expenseLedgers[0] ?? 15;

for ($i = 0; $i < 145; $i++) {
    $dt = randDate($startDate, $endDate);
    $br = $branchIds[array_rand($branchIds)];
    $desc = 'Seed journal entry ' . ($i + 1) . ' - test data';
    $tot = round(mt_rand(450, 12500) / 1.0, 2);
    $code = generateUniqueCode($db, 'JE-' . date('Y', strtotime($dt)) . '-', 'journal_entries', 'entry_no');
    $u = $userIds[0];

    $db->query("INSERT INTO journal_entries (entry_no, entry_date, description, reference_type, reference_id, branch_id, total_debit, total_credit, created_by, is_reversed)
                VALUES (:no, :d, :desc, 'manual', 0, :b, :t, :t, :u, 0)");
    $db->bind(':no', $code); $db->bind(':d', $dt); $db->bind(':desc', $desc);
    $db->bind(':b', $br); $db->bind(':t', $tot); $db->bind(':u', $u);

    if ($db->execute()) {
        $jid = $db->lastInsertId();
        $jeCount++;

        // Dr cash/bank , Cr income (or mix)
        $db->query("INSERT INTO journal_lines (journal_entry_id, ledger_id, debit, credit, description) VALUES (:j, :l, :deb, 0, 'Seed dr')");
        $db->bind(':j', $jid); $db->bind(':l', $cashL); $db->bind(':deb', $tot);
        $db->execute();

        $db->query("INSERT INTO journal_lines (journal_entry_id, ledger_id, debit, credit, description) VALUES (:j, :l, 0, :cr, 'Seed cr')");
        $db->bind(':j', $jid); $db->bind(':l', $incL); $db->bind(':cr', $tot);
        $db->execute();
        $jeLineCount += 2;

        // occasional expense dr + cash cr
        if (mt_rand(0, 4) === 0) {
            $db->query("INSERT INTO journal_lines (journal_entry_id, ledger_id, debit, credit, description) VALUES (:j, :l, :deb, 0, 'Seed exp dr')");
            $db->bind(':j', $jid); $db->bind(':l', $expL); $db->bind(':deb', round($tot * 0.4, 2));
            $db->execute();
            $db->query("INSERT INTO journal_lines (journal_entry_id, ledger_id, debit, credit, description) VALUES (:j, :l, 0, :cr, 'Seed exp offset')");
            $db->bind(':j', $jid); $db->bind(':l', $cashL); $db->bind(':cr', round($tot * 0.4, 2));
            $db->execute();
            $jeLineCount += 2;
        }
    }
}
echo "$jeCount journal entries ($jeLineCount lines).\n";

// ========== EXTRA CASH_LEDGER (for cash book volume) ==========
echo "Seeding extra cash_ledger for cashbook reports...\n";
$cashExtra = 0;
foreach ($branchIds as $b) {
    for ($k = 0; $k < 22; $k++) {
        $dt = randDate($startDate, $endDate);
        $isDr = mt_rand(0, 1);
        $amt = round(mt_rand(80, 4200) / 1.0, 2);
        $dbt = $isDr ? $amt : 0;
        $cr  = $isDr ? 0 : $amt;

        $db->query("INSERT INTO cash_ledger (transaction_date, branch_id, cash_point, reference_type, reference_id, debit, credit, running_balance, remarks, created_by, is_reversed)
                    VALUES (:d, :b, 'main_cash', 'adjustment', 0, :db, :cr, 0, 'Seed direct cash', :u, 0)");
        $db->bind(':d', $dt); $db->bind(':b', $b); $db->bind(':db', $dbt); $db->bind(':cr', $cr);
        $db->bind(':u', $userIds[0]);
        $db->execute();
        $cashExtra++;
    }
}
echo "$cashExtra extra cash_ledger rows.\n";

// ========== FINAL ==========
echo "\n==============================================\n";
echo "SEEDING COMPLETE\n";
echo "==============================================\n";
echo "Run your reports now.\n";
echo "Start with ProductStockAnalysis (use offcanvas filters: multi cat/branch/wh/product + date range).\n";
echo "Then check SalesHistory, PurchaseHistory, Aging (receivable/payable), DailyCashBook, TrialBalance, StockValuation, etc.\n";
echo "All seeded with is_reversed=0 and proper ledger polarities.\n";
echo "You can now reverse a few documents via UI to test reversal flows + report filters.\n";
echo "If some reports look empty, run the backfill scripts in database/scripts/ (stock triggers, GL etc).\n";
echo "Re-run this seeder (it truncates first) anytime for fresh volume.\n";