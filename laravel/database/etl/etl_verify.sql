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
