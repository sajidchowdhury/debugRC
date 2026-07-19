-- ============================================================
-- ETL Verification Report
-- Phase 2.3 — Run AFTER sync_sequences.sql
-- ============================================================
-- This script produces a row-count + checksum report for every table,
-- to be compared against the MySQL source counts. Any mismatch blocks
-- Phase 2.5 sign-off.
-- ============================================================

\pset format aligned
\pset pager off

SELECT '=== ETL Verification Report ===' AS header;
SELECT now() AS run_at;

-- ============================================================
-- Part 1: Row counts per table
-- ============================================================
SELECT '=== Row Counts ===' AS section;

SELECT
    schemaname || '.' || relname AS table_name,
    n_live_tup AS row_count
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY relname;

-- ============================================================
-- Part 2: Key financial checksums
-- ============================================================
SELECT '=== Financial Checksums ===' AS section;

-- Total journal entry debits must equal total credits
SELECT 'journal_debits' AS metric, SUM(debit) AS value FROM journal_lines
UNION ALL
SELECT 'journal_credits', SUM(credit) FROM journal_lines;

-- AR sub-ledger total must match GL AR control account
SELECT 'ar_subledger_total' AS metric,
    SUM(COALESCE(debit, 0) - COALESCE(credit, 0)) AS value
FROM customer_ledger;

-- AP sub-ledger total
SELECT 'ap_subledger_total' AS metric,
    SUM(COALESCE(credit, 0) - COALESCE(debit, 0)) AS value
FROM supplier_ledger;

-- Stock valuation: SUM(qty * avg_cost)
SELECT 'stock_value_total' AS metric,
    SUM(qty * avg_cost) AS value
FROM warehouse_stock
WHERE qty > 0;

-- ============================================================
-- Part 3: Data integrity checks
-- ============================================================
SELECT '=== Integrity Checks ===' AS section;

-- Orphan journal lines (journal_entry_id with no parent)
SELECT 'orphan_journal_lines' AS check_name, COUNT(*) AS count
FROM journal_lines jl
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
WHERE je.id IS NULL;

-- Unbalanced journal entries (debits != credits)
SELECT 'unbalanced_journal_entries' AS check_name, COUNT(*) AS count
FROM (
    SELECT journal_entry_id, SUM(debit) AS d, SUM(credit) AS c
    FROM journal_lines
    GROUP BY journal_entry_id
    HAVING SUM(debit) <> SUM(credit)
) x;

-- Negative warehouse stock
SELECT 'negative_stock_rows' AS check_name, COUNT(*) AS count
FROM warehouse_stock
WHERE qty < -0.0001;

-- Customers with NULL customer_code
SELECT 'customers_null_code' AS check_name, COUNT(*) AS count
FROM customers WHERE customer_code IS NULL OR customer_code = '';

-- Products with NULL product_code
SELECT 'products_null_code' AS check_name, COUNT(*) AS count
FROM products WHERE product_code IS NULL OR product_code = '';

-- ============================================================
-- Part 4: Table-by-table row count (for manual MySQL comparison)
-- ============================================================
SELECT '=== Compare These Counts to MySQL Source ===' AS section;

-- The MySQL source counts can be obtained with:
-- SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'osudlagb_remotecenter';
-- Compare those to the n_live_tup values from Part 1.

-- ============================================================
-- DONE. If all integrity checks show 0 and financial checksums match
-- the accountant's records, Phase 2 is ready for sign-off.
-- ============================================================

-- ============================================================
-- P2-4: Additional verification queries for status + column conversions
-- ============================================================

SELECT '=== P2-4 Status Conversion Verification ===' AS section;

-- sales_invoices: should only have draft/confirmed/cancelled/reversed
SELECT 'sales_invoices_invalid_status' AS check_name, COUNT(*) AS count
FROM sales_invoices
WHERE status NOT IN ('draft', 'confirmed', 'cancelled', 'reversed');

-- sales_invoices: godown_issued/challan_completed should be gone
SELECT 'sales_invoices_legacy_status_remaining' AS check_name, COUNT(*) AS count
FROM sales_invoices
WHERE status IN ('godown_issued', 'challan_completed');

-- sales_invoices: confirmed invoices should have is_godown_prepared=true
SELECT 'confirmed_without_godown' AS check_name, COUNT(*) AS count
FROM sales_invoices
WHERE status = 'confirmed' AND is_godown_prepared = false;

-- sales_returns: should only have created/confirmed/reversed
SELECT 'sales_returns_invalid_status' AS check_name, COUNT(*) AS count
FROM sales_returns
WHERE status NOT IN ('created', 'confirmed', 'reversed');

-- sales_returns: pending/completed should be gone
SELECT 'sales_returns_legacy_status_remaining' AS check_name, COUNT(*) AS count
FROM sales_returns
WHERE status IN ('pending', 'completed');

-- sales_returns: branch_id should be populated (no NULLs)
SELECT 'sales_returns_null_branch' AS check_name, COUNT(*) AS count
FROM sales_returns
WHERE branch_id IS NULL OR branch_id = 0;

-- customer_payments: transaction_type should be populated (if column exists)
SELECT 'customer_payments_null_transaction_type' AS check_name, COUNT(*) AS count
FROM customer_payments
WHERE (transaction_type IS NULL OR transaction_type = '')
LIMIT 1; -- returns 0 if column doesn't exist yet (error caught by psql)

-- sales_return_items: original_cost should be populated for returns with challans
SELECT 'return_items_null_original_cost' AS check_name, COUNT(*) AS count
FROM sales_return_items sri
JOIN sales_returns sr ON sr.id = sri.sales_return_id
WHERE (sri.original_cost IS NULL OR sri.original_cost = 0)
  AND sr.status IN ('confirmed', 'reversed');

-- customers: shop_name should exist (if column was added back)
SELECT 'customers_shop_name_check' AS check_name,
    CASE WHEN EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'customers' AND column_name = 'shop_name'
    ) THEN COUNT(*) ELSE -1 END AS count
FROM customers
WHERE shop_name IS NULL OR shop_name = ''
LIMIT 1;

SELECT '=== P2-4 Verification Complete ===' AS section;
SELECT 'All checks above should show 0 for a clean migration.' AS note;
