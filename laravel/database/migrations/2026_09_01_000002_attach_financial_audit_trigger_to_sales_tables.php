<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G4 (Sales CRITICAL) fix — attach `fn_financial_audit_trigger` to the 14
 * sales-ecosystem tables that were NOT hash-chain-audited.
 *
 * Background
 * ----------
 * The `financial_audit_log` table records every INSERT/UPDATE/DELETE on a set
 * of "crown-jewel" financial tables via the `fn_financial_audit_trigger()`
 * PostgreSQL function. Each row carries a SHA-256 `row_hash` chained to the
 * previous row's `prev_hash`, producing a tamper-evident ledger. UPDATE and
 * DELETE on `financial_audit_log` are REVOKE'd at the DB level.
 *
 * The trigger was attached to 10 tables in `database/sql/02_accounting.sql`
 * (lines 446-455): journal_entries, journal_lines, manual_journals,
 * manual_journal_lines, customer_payments, supplier_payments, money_transfers,
 * other_incomes, other_expenses, employee_transactions.
 *
 * Of the sales ecosystem, ONLY `customer_payments` was covered. The 9 core
 * sales tables + 5 commission tables had NO trigger attachment — direct DB
 * mutations to these tables bypassed the forensic hash chain entirely (gap G4
 * CRITICAL, documented in `AI_CONTEXT/sales/sales-audit.md` §11.1 and across
 * 6 sibling AI_CONTEXT files).
 *
 * This migration attaches `trg_audit_<table>` to all 14 tables, closing G4.
 *
 * Tables covered (14)
 * -------------------
 * Core sales (9):
 *   1. sales_invoices          (PARTITION BY RANGE(invoice_date) — PG 12+
 *      auto-inherits the trigger to all existing + future monthly partitions)
 *   2. sales_invoice_items
 *   3. sales_invoice_dispatchers
 *   4. sales_invoice_dispatches
 *   5. sales_challans
 *   6. sales_challan_items
 *   7. sales_draft_carts       (high write-frequency — cart item add/edit/remove;
 *      audit value = cart tampering detection outweighs the per-write cost)
 *   8. sales_returns
 *   9. sales_return_items
 *
 * Commission (5):
 *  10. commission_rules
 *  11. commission_rule_tiers
 *  12. commission_rule_product_groups
 *  13. commission_rule_targets
 *  14. commission_entries
 *
 * Idempotency
 * -----------
 * Each attachment uses `DROP TRIGGER IF EXISTS` before `CREATE TRIGGER` so the
 * migration is safe to re-run. No existing `trg_audit_sales*` or
 * `trg_audit_commission*` triggers exist (verified via grep of database/sql/),
 * so this is a pure addition.
 *
 * Performance note
 * ----------------
 * The trigger function fires on every INSERT/UPDATE/DELETE and does a
 * `SELECT row_hash FROM financial_audit_log ORDER BY id DESC LIMIT 1` for the
 * hash chain. This is the SAME behaviour as the existing 10 audited tables
 * (journal_entries, customer_payments, etc.) — the project has accepted this
 * cost for those tables, and the sales tables have comparable write volumes.
 * The `financial_audit_log` BRIN index on `created_at` keeps the chain lookup
 * efficient. If write-latency becomes an issue on the highest-frequency tables
 * (sales_draft_carts, sales_invoice_items), a future migration can move those
 * to an async audit queue — but correctness (hash-chain coverage) comes first.
 *
 * Trigger function prerequisites
 * ------------------------------
 * `fn_financial_audit_trigger()` is defined in `database/sql/02_accounting.sql`
 * (lines 381-443) and was hardened by migrations:
 *   - 2026_08_08_000005_enable_pgcrypto_extension (pgcrypto for digest())
 *   - 2026_08_08_000006_fix_financial_audit_trigger_xmin (xid handling)
 *   - 2026_08_08_000007_fix_audit_trigger_branch_id_access (JSONB branch_id)
 * The function reads `branch_id` from the row's JSONB representation (works
 * for tables with OR without a `branch_id` column) — confirmed safe for all 14
 * target tables (some lack `branch_id`, e.g. sales_invoice_items,
 * sales_invoice_dispatchers, commission_rule_tiers).
 *
 * Closes: AI_CONTEXT ISSUES_REGISTER G-064, G-065, G-066, G-067, G-068,
 *         G-069, G-070 (sales G4 CRITICAL — fn_financial_audit_trigger NOT
 *         attached to sales + commission tables).
 *         (SALES-3 batch.)
 */
return new class extends Migration
{
    /**
     * The 14 tables to which `fn_financial_audit_trigger` will be attached.
     * Grouped by ecosystem for readability; the trigger SQL is identical per table.
     */
    private const SALES_TABLES = [
        'sales_invoices',
        'sales_invoice_items',
        'sales_invoice_dispatchers',
        'sales_invoice_dispatches',
        'sales_challans',
        'sales_challan_items',
        'sales_draft_carts',
        'sales_returns',
        'sales_return_items',
    ];

    private const COMMISSION_TABLES = [
        'commission_rules',
        'commission_rule_tiers',
        'commission_rule_product_groups',
        'commission_rule_targets',
        'commission_entries',
    ];

    public function up(): void
    {
        // Verify the trigger function exists before attaching (defensive —
        // the function is created by 02_accounting.sql + hardened by 3 migrations,
        // but a fresh `migrate` without the SQL bootstrap would fail here).
        $fnExists = DB::table('pg_proc')
            ->join('pg_namespace', 'pg_proc.pronamespace', '=', 'pg_namespace.oid')
            ->where('pg_namespace.nspname', 'public')
            ->where('pg_proc.proname', 'fn_financial_audit_trigger')
            ->exists();

        if (! $fnExists) {
            throw new RuntimeException(
                'fn_financial_audit_trigger() function does not exist. '
                . 'Run database/sql/02_accounting.sql first, then re-run migrations.'
            );
        }

        foreach (self::SALES_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }

        foreach (self::COMMISSION_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }
    }

    public function down(): void
    {
        foreach (self::SALES_TABLES as $table) {
            $this->detachAuditTrigger($table);
        }

        foreach (self::COMMISSION_TABLES as $table) {
            $this->detachAuditTrigger($table);
        }
    }

    /**
     * Attach `trg_audit_<table>` to a table. Idempotent — drops any existing
     * trigger with the same name first.
     *
     * For partitioned tables (e.g. sales_invoices), PG 12+ auto-creates the
     * trigger on all existing AND future partitions when attached to the parent.
     */
    private function attachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;

        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");

        DB::statement(
            "CREATE TRIGGER {$trigger} "
            . "AFTER INSERT OR UPDATE OR DELETE ON {$table} "
            . "FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger()"
        );
    }

    private function detachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;

        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
    }
};
