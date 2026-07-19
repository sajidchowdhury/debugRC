-- ============================================================
-- Post-Load Fixes: MySQL → PostgreSQL data corrections
-- Phase 2.3 — Run AFTER pgloader, BEFORE going live
-- ============================================================
-- These fixes address data-quality issues that pgloader cannot handle
-- automatically. Each fix is idempotent (safe to run multiple times).
-- ============================================================

\set ON_ERROR_STOP ON

-- ============================================================
-- FIX 1: banks.balance — recompute from transactions
-- ============================================================
-- MySQL stored banks.balance as FLOAT(20,2) — precision loss for money.
-- Recompute each bank's balance from the transaction history to get
-- an accurate numeric(18,2) value.
--
-- Formula: balance = opening_balance (from banks.created_at snapshot, if available)
--                   + SUM(customer_payments to this bank)
--                   - SUM(supplier_payments from this bank)
--                   + SUM(other_incomes to this bank)
--                   - SUM(other_expenses from this bank)
--                   + SUM(money_transfers TO this bank)
--                   - SUM(money_transfers FROM this bank)
--
-- NOTE: If you don't have an opening_balance column, use the MySQL float value
-- as a starting point and log any deltas > 0.01 for accountant review.

-- For now: the pgloader cast (float→numeric) preserves the float value as-is.
-- This fix is a VERIFICATION step — it logs deltas but does not overwrite.
-- The accountant must review and approve any corrections.

CREATE TEMP TABLE IF NOT EXISTS bank_balance_check AS
SELECT
    b.id AS bank_id,
    b.bank_name,
    b.balance AS stored_balance,
    COALESCE(cp.total, 0) - COALESCE(sp.total, 0) + COALESCE(oi.total, 0) - COALESCE(oe.total, 0)
    + COALESCE(mt_in.total, 0) - COALESCE(mt_out.total, 0) AS computed_balance
FROM banks b
LEFT JOIN (SELECT bank_id, SUM(amount) AS total FROM customer_payments WHERE is_reversed = false GROUP BY bank_id) cp ON cp.bank_id = b.id
LEFT JOIN (SELECT bank_id, SUM(amount) AS total FROM supplier_payments WHERE is_reversed = false GROUP BY bank_id) sp ON sp.bank_id = b.id
LEFT JOIN (SELECT bank_id, SUM(amount) AS total FROM other_incomes WHERE is_reversed = false GROUP BY bank_id) oi ON oi.bank_id = b.id
LEFT JOIN (SELECT bank_id, SUM(amount) AS total FROM other_expenses WHERE is_reversed = false GROUP BY bank_id) oe ON oe.bank_id = b.id
LEFT JOIN (SELECT to_bank_id AS bank_id, SUM(amount) AS total FROM money_transfers WHERE transfer_type IN ('cash_to_bank','bank_to_bank') AND is_reversed = false GROUP BY to_bank_id) mt_in ON mt_in.bank_id = b.id
LEFT JOIN (SELECT from_bank_id AS bank_id, SUM(amount) AS total FROM money_transfers WHERE transfer_type IN ('bank_to_cash','bank_to_bank') AND is_reversed = false GROUP BY from_bank_id) mt_out ON mt_out.bank_id = b.id;

-- Log deltas > 0.01 for accountant review
SELECT bank_id, bank_name, stored_balance, computed_balance,
       stored_balance - computed_balance AS delta
FROM bank_balance_check
WHERE ABS(stored_balance - computed_balance) > 0.01
ORDER BY ABS(stored_balance - computed_balance) DESC;

-- ============================================================
-- FIX 2: banks.updated_at — was INT(11) storing YYYYMMDD
-- ============================================================
-- pgloader cast int→integer, so banks.updated_at is now an integer like 20221011.
-- We need to convert it to a date.
-- NOTE: The PG schema defines banks.updated_at as date. If pgloader loaded it
-- as integer, we need a temp column swap.

-- Check if the column is still integer (pgloader may have kept the MySQL type)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'banks' AND column_name = 'updated_at'
          AND data_type = 'integer'
    ) THEN
        -- Add a temp date column, convert, swap, drop
        ALTER TABLE banks ADD COLUMN updated_at_new date;
        UPDATE banks SET updated_at_new = CASE
            WHEN updated_at IS NULL OR updated_at = 0 THEN NULL
            ELSE to_date(updated_at::text, 'YYYYMMDD')
        END;
        ALTER TABLE banks DROP COLUMN updated_at;
        ALTER TABLE banks RENAME COLUMN updated_at_new TO updated_at;
        ALTER TABLE banks ALTER COLUMN updated_at SET DEFAULT CURRENT_DATE;
        RAISE NOTICE 'Converted banks.updated_at from integer(YYYYMMDD) to date';
    END IF;
END;
$$;

-- ============================================================
-- FIX 3: sales_invoices.updated_at — was DATE (not datetime) in MySQL
-- ============================================================
-- The PG schema defines this as timestamp(0). pgloader should have cast it.
-- Verify and fix if needed.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'sales_invoices' AND column_name = 'updated_at'
          AND data_type = 'date'
    ) THEN
        ALTER TABLE sales_invoices ALTER COLUMN updated_at TYPE timestamp(0) USING updated_at::timestamp(0);
        RAISE NOTICE 'Converted sales_invoices.updated_at from date to timestamp';
    END IF;
END;
$$;

-- ============================================================
-- FIX 4: Zero-date cleanup (safety net — pgloader should have handled this)
-- ============================================================
-- Any remaining '0000-00-00' or epoch-zero timestamps → NULL.
-- Run for every table with date/timestamp columns.
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT table_name, column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND (data_type LIKE 'timestamp%' OR data_type = 'date')
          AND table_name NOT LIKE 'schema_migrations'
    LOOP
        BEGIN
            EXECUTE format(
                'UPDATE %I SET %I = NULL WHERE %I = ''0000-00-00'' OR %I = ''0000-00-00 00:00:00'' OR %I < ''1900-01-01''',
                r.table_name, r.column_name, r.column_name, r.column_name, r.column_name
            );
        EXCEPTION WHEN others THEN
            NULL; -- skip columns that can't be compared to string
        END;
    END LOOP;
END;
$$;

-- ============================================================
-- FIX 5: Recompute warehouse_stock.avg_cost from stock_transactions
-- ============================================================
-- CRITICAL: The moving-average cost must be verified against a replay of
-- all stock_transactions. This is done by the shadow-mode replay test in
-- Phase 6.2, NOT here. Here we just verify the data loaded.
-- Log any warehouse_stock rows with NULL avg_cost:
SELECT warehouse_id, product_id, qty, avg_cost
FROM warehouse_stock
WHERE avg_cost IS NULL OR avg_cost = 0
ORDER BY product_id;
-- (These will be recomputed in Phase 6.2's replay test.)

-- ============================================================
-- FIX 6: ledgers.parent_id = 0 → NULL (for future self-referential FK)
-- ============================================================
-- MySQL used 0 as "no parent" sentinel. PG uses NULL.
UPDATE ledgers SET parent_id = NULL WHERE parent_id = 0;

-- ============================================================
-- FIX 7: Populate schema_migrations (Laravel migration tracking)
-- ============================================================
-- Mark the baseline migration as applied.
INSERT INTO schema_migrations (filename, applied_at)
VALUES ('2025_01_01_000001_create_rcerp_schema', CURRENT_TIMESTAMP)
ON CONFLICT (filename) DO NOTHING;

-- ============================================================
-- FIX 8: Verify row counts match MySQL source
-- ============================================================
-- This is a manual verification step. Compare these counts to the MySQL source.
-- Run: SELECT table_name, (SELECT COUNT(*) FROM information_schema.tables WHERE ...) ...
-- Or use the etl_verify.sql script for a comprehensive check.

-- ============================================================
-- DONE. Run sync_sequences.sql next.
-- ============================================================

-- ============================================================
-- P2-4: Status enum + column rename + branch_id backfill conversions
-- ============================================================
-- These fixes handle data conversions that pgloader cannot do automatically
-- because the PG schema redesigned some columns (status enums changed,
-- columns renamed, denormalized columns added).
-- Each fix is idempotent (safe to run multiple times).
-- ============================================================

-- ============================================================
-- FIX 9 (P2-4): sales_invoices.status conversion
-- ============================================================
-- Legacy MySQL: status ENUM('draft','godown_issued','challan_completed',
--                           'cancelled','reversed')
-- PG schema:    status VARCHAR(20) CHECK('draft','confirmed','cancelled',
--                                        'reversed')
--               + boolean flags is_godown_prepared + is_challan_issued
--
-- Conversion:
--   'godown_issued'    → is_godown_prepared=true, godown_prepared_at=godown_issued_at, status='confirmed'
--   'challan_completed' → is_godown_prepared=true, is_challan_issued=true,
--                         challan_issued_at=challan_completed_at, status='confirmed'
--
-- NOTE: If pgloader loaded the legacy status values, they violate the PG
-- CHECK constraint. We must convert BEFORE the CHECK is enforced.
-- If the migration adding the CHECK has already run, the legacy values
-- would have been rejected at insert — in that case, this fix is a no-op.

DO $$
BEGIN
    -- Convert 'godown_issued' → confirmed + is_godown_prepared=true
    IF EXISTS (SELECT 1 FROM sales_invoices WHERE status = 'godown_issued' LIMIT 1) THEN
        UPDATE sales_invoices
        SET is_godown_prepared = true,
            godown_prepared_at = COALESCE(godown_prepared_at, updated_at),
            status = 'confirmed'
        WHERE status = 'godown_issued';
        RAISE NOTICE 'Converted % sales_invoices from godown_issued to confirmed', ROW_COUNT;
    END IF;

    -- Convert 'challan_completed' → confirmed + both flags true
    IF EXISTS (SELECT 1 FROM sales_invoices WHERE status = 'challan_completed' LIMIT 1) THEN
        UPDATE sales_invoices
        SET is_godown_prepared = true,
            is_challan_issued = true,
            godown_prepared_at = COALESCE(godown_prepared_at, updated_at),
            challan_issued_at = COALESCE(challan_issued_at, updated_at),
            status = 'confirmed'
        WHERE status = 'challan_completed';
        RAISE NOTICE 'Converted % sales_invoices from challan_completed to confirmed', ROW_COUNT;
    END IF;
END;
$$;

-- ============================================================
-- FIX 10 (P2-4): sales_returns.status conversion
-- ============================================================
-- Legacy MySQL: status ENUM('pending','completed','reversed')
-- PG schema:    status VARCHAR(20) CHECK('created','confirmed','reversed')
--
-- Conversion: 'pending' → 'created', 'completed' → 'confirmed'

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM sales_returns WHERE status = 'pending' LIMIT 1) THEN
        UPDATE sales_returns SET status = 'created' WHERE status = 'pending';
        RAISE NOTICE 'Converted % sales_returns from pending to created', ROW_COUNT;
    END IF;

    IF EXISTS (SELECT 1 FROM sales_returns WHERE status = 'completed' LIMIT 1) THEN
        UPDATE sales_returns SET status = 'confirmed' WHERE status = 'completed';
        RAISE NOTICE 'Converted % sales_returns from completed to confirmed', ROW_COUNT;
    END IF;
END;
$$;

-- ============================================================
-- FIX 11 (P2-4): sales_returns.branch_id backfill
-- ============================================================
-- Legacy sales_returns did NOT store branch_id (derived via JOIN to
-- sales_invoices). PG schema adds it as a denormalized column.
-- Backfill from the linked invoice.

UPDATE sales_returns sr
SET branch_id = si.branch_id
FROM sales_invoices si
WHERE sr.sales_invoice_id = si.id
  AND (sr.branch_id IS NULL OR sr.branch_id = 0);

-- ============================================================
-- FIX 12 (P2-4): customers.shop_name — add back if missing
-- ============================================================
-- Legacy customers had shop_name; PG schema removed it. If the column
-- was dropped, all legacy code referencing c.shop_name will fail.
-- This fix checks if the column exists; if not, it adds it back and
-- populates from customer_name (best available fallback).

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'customers' AND column_name = 'shop_name'
    ) THEN
        ALTER TABLE customers ADD COLUMN shop_name varchar(200);
        UPDATE customers SET shop_name = customer_name WHERE shop_name IS NULL;
        RAISE NOTICE 'Added + populated customers.shop_name from customer_name';
    END IF;
END;
$$;

-- ============================================================
-- FIX 13 (P2-4): customer_payments.transaction_type — backfill
-- ============================================================
-- Legacy had transaction_type ENUM('receive','payment','discount','write_off').
-- P2-5 restores this column. If the migration hasn't run yet, this is a no-op.
-- If it has, backfill existing rows to 'receive' (the default for existing data).

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'customer_payments' AND column_name = 'transaction_type'
    ) THEN
        UPDATE customer_payments
        SET transaction_type = 'receive'
        WHERE transaction_type IS NULL OR transaction_type = '';
        RAISE NOTICE 'Backfilled % customer_payments.transaction_type to receive', ROW_COUNT;
    END IF;
END;
$$;

-- ============================================================
-- FIX 14 (P2-4): sales_return_items.original_cost — backfill
-- ============================================================
-- P0-3 added the original_cost column. For existing returns (migrated from
-- legacy which didn't populate it), backfill from the challan's stock_transaction rate.

UPDATE sales_return_items sri
SET original_cost = COALESCE(st.rate, sri.rate)
FROM stock_transactions st
WHERE st.reference_type = 'sales_challan'
  AND st.product_id = sri.product_id
  AND st.warehouse_id = sri.warehouse_id
  AND st.qty < -0.0001  -- stock OUT (negative)
  AND (sri.original_cost IS NULL OR sri.original_cost = 0);

-- ============================================================
-- P2-4 ETL conversions complete. Re-run etl_verify.sql to confirm.
-- ============================================================
