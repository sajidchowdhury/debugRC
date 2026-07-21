<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 18 — Window-Function Running Balance Reconciliation Job.
 *
 * Adds infrastructure to verify that the denormalized `balance` column in
 * each sub-ledger (customer_ledger, supplier_ledger, employee_ledger, cash_ledger)
 * matches the mathematically correct running balance computed by window functions.
 *
 * Components:
 *   1. reconciliation_snapshots table — stores structured reconciliation results
 *   2. Materialized views (4) — compute running balance via SUM() OVER and
 *      flag rows where stored_balance ≠ computed_balance
 *   3. Indexes on reconciliation_snapshots for audit queries
 *
 * Strategy: Keep `balance` column for fast reads (denormalization), but add
 * window-function verification that catches drift early. The Artisan command
 * `reconcile:running-balance` refreshes these materialized views and reports
 * any mismatches.
 *
 * Running balance formulas (same as SubLedgerService):
 *   customer_ledger: balance = prev + debit - credit
 *   supplier_ledger: balance = prev + credit - debit
 *   employee_ledger: balance = prev + credit - debit
 *   cash_ledger:     balance = prev + amount  (positive=IN, negative=OUT)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. reconciliation_snapshots — Structured audit trail for
        //    running balance reconciliation runs (replaces ad-hoc JSON
        //    logging in user_audit_log).
        // ============================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS reconciliation_snapshots (
                id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                run_type        varchar(30) NOT NULL DEFAULT 'running_balance',
                ledger_type     varchar(30) NOT NULL,
                entity_id       integer,
                total_rows      integer NOT NULL DEFAULT 0,
                matched_rows    integer NOT NULL DEFAULT 0,
                drift_rows      integer NOT NULL DEFAULT 0,
                max_drift       numeric(15,2) DEFAULT 0,
                max_drift_entity_id integer,
                status          varchar(10) NOT NULL DEFAULT 'green' CHECK (status IN ('green','red','error')),
                tolerance       numeric(15,4) NOT NULL DEFAULT 0.02,
                as_of_date      date,
                details         jsonb,
                ran_at          timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ran_by          integer
            )
        ");

        // Indexes for reconciliation audit queries.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_rs_run_type_ran_at
                ON reconciliation_snapshots (run_type, ran_at DESC)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_rs_ledger_type_status
                ON reconciliation_snapshots (ledger_type, status, ran_at DESC)
        ");

        // ============================================================
        // 2. Materialized View: mv_customer_ledger_balance_check
        //    Compares stored balance vs window-function computed balance
        //    per customer. Only includes non-reversed rows.
        //    Formula: SUM(debit - credit) OVER (PARTITION BY customer_id ORDER BY id)
        // ============================================================
        DB::statement("
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_customer_ledger_balance_check AS
            SELECT
                id,
                customer_id,
                transaction_date,
                transaction_type,
                debit,
                credit,
                balance AS stored_balance,
                SUM(debit - credit) OVER (
                    PARTITION BY customer_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS computed_balance,
                ROUND(balance - SUM(debit - credit) OVER (
                    PARTITION BY customer_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ), 2) AS drift
            FROM customer_ledger
            WHERE COALESCE(is_reversed, false) = false
            WITH DATA
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_clbc_id
                ON mv_customer_ledger_balance_check (id)
        ");

        // ============================================================
        // 3. Materialized View: mv_supplier_ledger_balance_check
        //    Formula: SUM(credit - debit) OVER (PARTITION BY supplier_id ORDER BY id)
        // ============================================================
        DB::statement("
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_supplier_ledger_balance_check AS
            SELECT
                id,
                supplier_id,
                transaction_date,
                transaction_type,
                debit,
                credit,
                balance AS stored_balance,
                SUM(credit - debit) OVER (
                    PARTITION BY supplier_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS computed_balance,
                ROUND(balance - SUM(credit - debit) OVER (
                    PARTITION BY supplier_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ), 2) AS drift
            FROM supplier_ledger
            WHERE COALESCE(is_reversed, false) = false
            WITH DATA
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_slbc_id
                ON mv_supplier_ledger_balance_check (id)
        ");

        // ============================================================
        // 4. Materialized View: mv_employee_ledger_balance_check
        //    Formula: SUM(credit - debit) OVER (PARTITION BY employee_id ORDER BY id)
        // ============================================================
        DB::statement("
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_employee_ledger_balance_check AS
            SELECT
                id,
                employee_id,
                transaction_date,
                transaction_type,
                debit,
                credit,
                balance AS stored_balance,
                SUM(credit - debit) OVER (
                    PARTITION BY employee_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS computed_balance,
                ROUND(balance - SUM(credit - debit) OVER (
                    PARTITION BY employee_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ), 2) AS drift
            FROM employee_ledger
            WHERE COALESCE(is_reversed, false) = false
            WITH DATA
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_elbc_id
                ON mv_employee_ledger_balance_check (id)
        ");

        // ============================================================
        // 5. Materialized View: mv_cash_ledger_balance_check
        //    Formula: SUM(amount) OVER (PARTITION BY branch_id ORDER BY id)
        //    Cash ledger: positive = IN, negative = OUT
        //
        //    NOTE: cash_ledger does NOT have an `is_reversed` column (unlike
        //    customer_ledger, supplier_ledger, employee_ledger which get one
        //    from migration 2025_01_02_000002). Cash ledger rows are never
        //    reversed in the current application design (reversals are
        //    appended as new rows with opposite-sign amount). So no
        //    is_reversed filter is applied here.
        // ============================================================
        DB::statement("
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv_cash_ledger_balance_check AS
            SELECT
                id,
                branch_id,
                transaction_date,
                transaction_type,
                amount,
                balance AS stored_balance,
                SUM(amount) OVER (
                    PARTITION BY branch_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS computed_balance,
                ROUND(balance - SUM(amount) OVER (
                    PARTITION BY branch_id
                    ORDER BY id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ), 2) AS drift
            FROM cash_ledger
            WITH DATA
        ");

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_cashlbc_id
                ON mv_cash_ledger_balance_check (id)
        ");

        // Refresh statistics after creating new objects.
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        // Drop materialized views (IF EXISTS for safety).
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_cash_ledger_balance_check');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_employee_ledger_balance_check');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_supplier_ledger_balance_check');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_customer_ledger_balance_check');

        // Drop reconciliation_snapshots table.
        DB::statement('DROP TABLE IF EXISTS reconciliation_snapshots');
    }
};
