<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-1 (Phase 1) — Attach fn_financial_audit_trigger to 14 finance tables.
 *
 * Resolves 4 CRITICAL entries:
 *   - G-017 (consolidation-intercompany G4): trigger NOT attached to 7 in-scope tables
 *   - G-020 (branch-demand G6): trigger NOT attached to ANY branch_demand* table or branch_ledger
 *
 * The 7 consolidation-sector tables (G-017):
 *   1. consolidation_runs
 *   2. elimination_rules
 *   3. elimination_entries
 *   4. companies
 *   5. warehouse_transfers
 *   6. warehouse_transfer_items
 *   7. branch_ledger
 *
 * The 7 branch-demand-sector tables (G-020, excluding branch_ledger which is
 * already counted in G-017's list of 7):
 *   8.  branch_demands
 *   9.  branch_demand_items
 *   10. branch_demand_repricing
 *   11. branch_demand_customer_payment_settlements
 *   12. branch_demand_money_transfer_settlements
 *   13. shadow_demand_comparisons
 *   14. shadow_cutover_log
 *
 * NOTE: branch_demand_audit_log is intentionally EXCLUDED — it is itself an
 * append-only audit trail (RLS blocks UPDATE/DELETE via USING(false)). Hash-
 * chaining the audit-of-the-audit adds overhead without forensic value.
 *
 * Prerequisites:
 *   - fn_financial_audit_trigger() must exist (created by 02_accounting.sql:381-443,
 *     loaded via migration 2025_01_01_000001_create_rcerp_schema.php).
 *   - All 14 target tables must exist with an `id` PK column (the trigger reads
 *     NEW.id / OLD.id). Verified:
 *       * consolidation_runs / elimination_rules / elimination_entries / companies
 *         created by migration 2026_08_11_000001 (all use $table->id()).
 *       * warehouse_transfers / warehouse_transfer_items in 03_stock.sql:640,665
 *         (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY).
 *       * branch_ledger in 02_accounting.sql:203 (id integer GENERATED ALWAYS ...).
 *       * branch_demands / branch_demand_items in 03_stock.sql:715,732 (id ...).
 *       * branch_demand_repricing / *_settlements / shadow_demand_comparisons /
 *         shadow_cutover_log created by migrations 2026_07_29_000014..000019 and
 *         2025_07_28_000012 (all use $table->id() or GENERATED ALWAYS AS IDENTITY).
 *
 * The trigger function is generic — it reads branch_id from the row's JSONB
 * representation (to_jsonb(NEW)->>'branch_id'), so it works on tables that
 * have no branch_id column (e.g. companies, elimination_rules, shadow_cutover_log).
 * See migration 2026_08_08_000007_fix_audit_trigger_branch_id_access.php for
 * the hardening that made this safe.
 *
 * Idempotent: uses DROP TRIGGER IF EXISTS before CREATE TRIGGER, so re-running
 * on an already-attached table is a safe no-op.
 */
return new class extends Migration
{
    /**
     * The 14 finance-sector tables to attach the audit trigger to.
     *
     * Grouped by source gap for traceability. branch_ledger appears in G-017's
     * list (consolidation sector) but is physically shared with branch-demand —
     * listed once here under CONSOLIDATION_TABLES.
     */
    private const CONSOLIDATION_TABLES = [
        'consolidation_runs',
        'elimination_rules',
        'elimination_entries',
        'companies',
        'warehouse_transfers',
        'warehouse_transfer_items',
        'branch_ledger',
    ];

    private const BRANCH_DEMAND_TABLES = [
        'branch_demands',
        'branch_demand_items',
        'branch_demand_repricing',
        'branch_demand_customer_payment_settlements',
        'branch_demand_money_transfer_settlements',
        'shadow_demand_comparisons',
        'shadow_cutover_log',
    ];

    public function up(): void
    {
        // Defensive: verify the trigger function exists before attaching.
        // If 02_accounting.sql wasn't loaded (e.g. broken fresh install),
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

        foreach (self::CONSOLIDATION_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }
        foreach (self::BRANCH_DEMAND_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }
    }

    public function down(): void
    {
        foreach (self::CONSOLIDATION_TABLES as $table) {
            $this->detachAuditTrigger($table);
        }
        foreach (self::BRANCH_DEMAND_TABLES as $table) {
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
