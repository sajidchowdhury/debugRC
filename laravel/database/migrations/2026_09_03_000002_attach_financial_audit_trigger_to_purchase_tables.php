<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PURCHASING-1 — Attach fn_financial_audit_trigger to 6 purchase tables.
 *
 * Resolves 3 CRITICAL entries:
 *   - G-030 (purchase-audit G3): fn_financial_audit_trigger NOT attached to
 *     purchase tables. Only supplier_payments was hash-chain-audited.
 *   - G-031 (purchase-receive G3): fn_financial_audit_trigger NOT attached to
 *     purchase_receives. Direct DB::table('purchase_receives') mutations
 *     bypass the hash chain.
 *   - G-032 (purchase-return G3): fn_financial_audit_trigger NOT attached to
 *     purchase_returns. Direct DB::table('purchase_returns') mutations
 *     bypass the hash chain.
 *
 * The 6 in-scope tables (covering both the audit-audit gap and per-module
 * audit-forensic gaps):
 *   1. purchase_orders          (G-030)
 *   2. purchase_order_items     (G-030)
 *   3. purchase_receives        (G-030 + G-031)
 *   4. purchase_receive_items   (G-030)
 *   5. purchase_returns         (G-030 + G-032)
 *   6. purchase_return_items    (G-030)
 *
 * NOTE: supplier_payments is intentionally EXCLUDED — it already has the
 * trigger (per gap text: "Only supplier_payments is hash-chain-audited").
 *
 * Prerequisites:
 *   - fn_financial_audit_trigger() must exist (created by 02_accounting.sql:381-443,
 *     loaded via migration 2025_01_01_000001_create_rcerp_schema.php).
 *   - All 6 target tables exist with `id` PK columns (the trigger reads
 *     NEW.id / OLD.id). Verified in 05_purchase.sql:
 *       * purchase_orders          L11  (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY)
 *       * purchase_order_items     L33  (id ... IDENTITY PRIMARY KEY)
 *       * purchase_receives        L46  (id ... IDENTITY PRIMARY KEY)
 *       * purchase_receive_items   L77  (id ... IDENTITY PRIMARY KEY)
 *       * purchase_returns         L93  (id ... IDENTITY PRIMARY KEY)
 *       * purchase_return_items    L125 (id ... IDENTITY PRIMARY KEY)
 *
 * The trigger function is generic — it reads branch_id from the row's JSONB
 * representation (to_jsonb(NEW)->>'branch_id'). All 6 tables have branch_id
 * directly except the *_items tables, whose branch_id is implied by the
 * parent header. The trigger will log NULL branch_id for *_items rows —
 * the parent header's audit row (same transaction) carries the branch_id,
 * so forensic reconstruction is still possible.
 *
 * Idempotent: uses DROP TRIGGER IF EXISTS before CREATE TRIGGER, so re-running
 * on an already-attached table is a safe no-op.
 *
 * Pattern: mirrors 2026_09_01_000003_attach_financial_audit_trigger_to_finance_tables.php
 * (FINANCE-1) and 2026_09_01_000002_attach_financial_audit_trigger_to_sales_tables.php
 * (SALES-3).
 */
return new class extends Migration
{
    /**
     * The 6 purchase-sector tables to attach the audit trigger to.
     */
    private const PURCHASE_TABLES = [
        'purchase_orders',
        'purchase_order_items',
        'purchase_receives',
        'purchase_receive_items',
        'purchase_returns',
        'purchase_return_items',
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

        foreach (self::PURCHASE_TABLES as $table) {
            $this->attachAuditTrigger($table);
        }
    }

    public function down(): void
    {
        foreach (self::PURCHASE_TABLES as $table) {
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
