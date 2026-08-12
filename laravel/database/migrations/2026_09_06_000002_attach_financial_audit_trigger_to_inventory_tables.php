<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REPORTS-AUDIT-3 — Attach fn_financial_audit_trigger to 6 inventory tables.
 *
 * Resolves G-131 (= reports-catalog.md G5). The reports-catalog.md G5 row
 * originally listed 8 tables; 2 of them (`purchase_receives` +
 * `purchase_return_items`) were already attached by migration
 * `2026_09_03_000002_attach_financial_audit_trigger_to_purchase_tables.php`
 * (PURCHASING-1). The ACTUAL remaining 6 inventory tables are attached here:
 *
 *   1. stock_adjustments       — manual stock increase/decrease documents
 *      (status: draft → submitted → approved → confirmed; carries GL
 *      journal_entry_id on confirm). Schema: 03_stock.sql L99-151.
 *   2. damage_invoices         — inventory loss/damage header documents
 *      (status: draft → submitted → approved → confirmed; carries GL
 *      journal_entry_id + 7 approval-workflow columns). Schema:
 *      03_stock.sql L674-706.
 *   3. damage_invoice_items    — per-product line items for damage_invoices
 *      (qty + rate; parent carries branch_id). Schema: 03_stock.sql
 *      L711-718.
 *   4. stock_take_sessions     — stock-take (cycle count) session header
 *      (status: draft → counting → submitted → approved → posted /
 *      cancelled / reversed; carries count_snapshot jsonb + GL
 *      journal_entry_id on post). Schema: 03_stock.sql L213-275.
 *   5. stock_take_items        — per-product count lines for stock-take
 *      sessions (system_qty + physical_qty + GENERATED difference + GL
 *      journal_line_id on post). Schema: 03_stock.sql L328-360.
 *   6. stock_transactions      — the SSOT inventory ledger (PARTITION BY
 *      RANGE(transaction_date) — PG 12+ auto-inherits the trigger to all
 *      existing + future monthly partitions). Every stock movement
 *      (purchase_receive, purchase_return, sales_challan, sales_return,
 *      stock_adjustment, stock_take, warehouse_transfer, damage,
 *      branch_demand, opening_balance, reversal) posts a row here.
 *      Cross-reference: AI_CONTEXT/inventory/stock-ledger.md §2 (SSOT).
 *      Schema: 03_stock.sql L19-46.
 *
 * Background
 * ----------
 * The `financial_audit_log` table records every INSERT/UPDATE/DELETE on a set
 * of "crown-jewel" financial tables via the `fn_financial_audit_trigger()`
 * PostgreSQL function. Each row carries a SHA-256 `row_hash` chained to the
 * previous row's `prev_hash`, producing a tamper-evident ledger. UPDATE and
 * DELETE on `financial_audit_log` are REVOKE'd at the DB level.
 *
 * Prior coverage migrations:
 *   - 02_accounting.sql L446-455: 10 finance tables (journal_entries,
 *     journal_lines, manual_journals, manual_journal_lines,
 *     customer_payments, supplier_payments, money_transfers, other_incomes,
 *     other_expenses, employee_transactions).
 *   - 2026_09_01_000002 (SALES-3): 14 sales + commission tables.
 *   - 2026_09_01_000003 (FINANCE-1): 14 finance-side tables (including
 *     branch_demands + warehouse_transfers).
 *   - 2026_09_03_000002 (PURCHASING-1): 6 purchase tables (purchase_orders,
 *     purchase_order_items, purchase_receives, purchase_receive_items,
 *     purchase_returns, purchase_return_items).
 *
 * After this migration, only the inventory cluster remained uncovered. With
 * this migration attaching the 6 inventory tables, EVERY transactional table
 * that feeds financial reports is now hash-chain-audited. Closes G-131.
 *
 * Trigger function prerequisites
 * ------------------------------
 * `fn_financial_audit_trigger()` is defined in `database/sql/02_accounting.sql`
 * and was hardened by migrations 2026_08_08_000005/000006/000007. The function
 * reads `branch_id` from the row's JSONB representation (works for tables with
 * OR without a `branch_id` column) — confirmed safe for all 6 target tables
 * (some lack `branch_id`, e.g. damage_invoice_items, stock_take_items,
 * stock_transactions has branch_id denormalized via the parent header).
 *
 * Idempotency
 * -----------
 * Each attachment uses `DROP TRIGGER IF EXISTS` before `CREATE TRIGGER` so the
 * migration is safe to re-run. No existing `trg_audit_stock*` or
 * `trg_audit_damage*` triggers exist (verified via grep of database/sql/),
 * so this is a pure addition.
 *
 * Pattern: mirrors 2026_09_03_000002 (PURCHASING-1) which mirrors
 * 2026_09_01_000002 (SALES-3).
 */
return new class extends Migration
{
    /**
     * The 6 inventory-sector tables to attach the audit trigger to.
     * stock_transactions is PARTITION BY RANGE(transaction_date) — PG 12+
     * auto-creates the trigger on all existing AND future partitions when
     * attached to the parent.
     */
    private const INVENTORY_TABLES = [
        'stock_adjustments',
        'damage_invoices',
        'damage_invoice_items',
        'stock_take_sessions',
        'stock_take_items',
        'stock_transactions',
    ];

    public function up(): void
    {
        // Defensive: verify the trigger function exists before attaching.
        // If 02_accounting.sql was not loaded (broken fresh install),
        // attaching would succeed but fire NULL at runtime.
        $fnExists = DB::selectOne("
            SELECT 1
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public'
              AND p.proname = 'fn_financial_audit_trigger'
        ");

        if (!$fnExists) {
            throw new RuntimeException(
                'fn_financial_audit_trigger() does not exist in the public schema. '
                . 'Ensure 02_accounting.sql (loaded by migration 2025_01_01_000001_create_rcerp_schema.php) '
                . 'has been applied before running this migration.'
            );
        }

        foreach (self::INVENTORY_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }
    }

    public function down(): void
    {
        foreach (self::INVENTORY_TABLES as $table) {
            $this->detachAuditTrigger($table);
        }
    }

    /**
     * Attach trg_audit_<table> AFTER INSERT OR UPDATE OR DELETE.
     *
     * DROP IF EXISTS first makes this idempotent — safe to re-run on tables
     * that already have the trigger (e.g. on a re-run after a failed migration).
     */
    private function attachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;

        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
        DB::statement(
            "CREATE TRIGGER {$trigger} AFTER INSERT OR UPDATE OR DELETE ON {$table} "
            . "FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger()"
        );
    }

    private function detachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;
        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
    }
};
