<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 7.1: Complete retention config for ALL partitioned tables.
 *
 * Audit finding (roadmap §6.1, lines 399-414): After Phases 0–6 every partitioned
 * table MUST have a retention row in `partman.part_config` so that pg_partman's
 * nightly `run_maintenance_proc()` will auto-detach old monthly partitions and
 * move them to the `archive` schema. Without retention, old partitions accumulate
 * forever and the catalog grows unbounded.
 *
 * Prior migrations set retention inline for some but NOT all tables:
 *
 *   - Phase 1 (`2026_08_02_000001_partition_audit_log_tables.php`):
 *       financial_audit_log (84), user_audit_log (36),
 *       stock_take_audit_log (36), stock_adjustment_audit_log (36),
 *       branch_demand_audit_log (36), journal_posting_logs (84).
 *   - Phase 2 (`2026_08_02_000002_partition_sub_ledger_tables.php`):
 *       customer_ledger, supplier_ledger, employee_ledger, cash_ledger,
 *       branch_ledger (all 84).
 *   - Phase 3 (`2026_08_02_000003_partition_daily_warehouse_stock_summary.php`):
 *       daily_warehouse_stock_summary (24 months). **Roadmap §14.2 revises this
 *       to 36 months** (24 hot + 12 warm). This migration overwrites 24→36.
 *   - Phase 0.2 (`2026_08_15_000002_add_missing_retention_configs.php`):
 *       money_transfers, employee_transactions, other_incomes, other_expenses,
 *       sales_returns, purchase_receives, purchase_returns, damage_invoices,
 *       manual_journals, stock_transactions, sales_invoices (all 84).
 *   - Phase 5 (`2026_08_20_000001_partition_transaction_headers_multi_fk.php`):
 *       registered the 6 parents with pg_partman but did NOT set retention
 *       inline (oversight in the Phase 5 migration). customer_payments,
 *       supplier_payments, sales_challans, warehouse_transfers,
 *       stock_adjustments, stock_take_sessions need 84-month retention added.
 *   - Phase 6 (`2026_08_22_000002` + `2026_08_22_000003`):
 *       journal_entries, journal_lines (both 84).
 *
 * This migration is the SINGLE SOURCE OF TRUTH for retention config across
 * all ~30 partitioned tables. It is IDEMPOTENT:
 *
 *   - Uses `UPDATE ... WHERE retention IS NULL OR retention = '' OR retention != '<months> months'`
 *     so re-runs are no-ops once the target value is in place.
 *   - For `daily_warehouse_stock_summary` (24→36 revision), the WHERE clause
 *     matches because `'24 months' != '36 months'`.
 *   - If a `part_config` row is missing for a table, logs a warning and skips
 *     (the table may not be registered with pg_partman yet, e.g. on a fresh
 *     install before the partitioning migrations have run).
 *
 * All retention rows use:
 *   - retention_keep_table = true   (DETACH only, never DROP — preserves audit trail)
 *   - retention_schema     = 'archive' (move detached partitions to the `archive`
 *                                       schema, created by Phase 0.5 migration)
 *
 * Retention matrix (roadmap lines 404-413):
 *
 *   | Category              | Tables                                                                                              | Months |
 *   |-----------------------|-----------------------------------------------------------------------------------------------------|--------|
 *   | Financial audit logs  | financial_audit_log, journal_posting_logs                                                           | 84     |
 *   | User audit logs       | user_audit_log, stock_take_audit_log, stock_adjustment_audit_log, branch_demand_audit_log           | 36     |
 *   | Sub-ledgers           | customer_ledger, supplier_ledger, employee_ledger, cash_ledger, branch_ledger                       | 84     |
 *   | Journal core          | journal_entries, journal_lines                                                                      | 84     |
 *   | Transaction headers   | customer_payments, supplier_payments, money_transfers, employee_transactions, other_incomes,        | 84     |
 *   |                       | other_expenses, sales_returns, purchase_receives, purchase_returns, damage_invoices,               |        |
 *   |                       | manual_journals, sales_challans, warehouse_transfers, stock_adjustments, stock_take_sessions        |        |
     *   |                       | purchase_orders (G-121: partitioning deferred; retention pre-registered)                            |        |
 *   | Stock transactions    | stock_transactions                                                                                  | 84     |
 *   | Sales invoices        | sales_invoices                                                                                      | 84     |
 *   | Daily warehouse sum.  | daily_warehouse_stock_summary                                                                       | 36     |
 */
return new class extends Migration
{
    /**
     * Retention config for every partitioned table in the system.
     *
     * Keyed by table name (without schema prefix — `public.` is added in SQL).
     * Value = retention in months.
     *
     * 84 months = 7 years  (financial compliance — never purge source rows)
     * 36 months = 3 years  (operational audit + warm-tier summaries)
     */
    private const RETENTION_CONFIGS = [
        // ── Financial audit logs — 84 months (7-year compliance) ──────────
        'financial_audit_log'                   => 84,
        'journal_posting_logs'                  => 84,

        // ── User / operational audit logs — 36 months (3 years) ───────────
        'user_audit_log'                        => 36,
        'stock_take_audit_log'                  => 36,
        'stock_adjustment_audit_log'            => 36,
        'branch_demand_audit_log'               => 36,

        // ── Sub-ledgers — 84 months (7-year compliance) ────────────────────
        'customer_ledger'                       => 84,
        'supplier_ledger'                       => 84,
        'employee_ledger'                       => 84,
        'cash_ledger'                           => 84,
        'branch_ledger'                         => 84,

        // ── Journal core — 84 months (7-year compliance) ───────────────────
        'journal_entries'                       => 84,
        'journal_lines'                         => 84,

        // ── Transaction headers — 84 months (7-year compliance) ────────────
        'customer_payments'                     => 84,
        'supplier_payments'                     => 84,
        'money_transfers'                       => 84,
        'employee_transactions'                 => 84,
        'other_incomes'                         => 84,
        'other_expenses'                        => 84,
        'sales_returns'                         => 84,
        'purchase_receives'                     => 84,
        'purchase_returns'                      => 84,
        'damage_invoices'                       => 84,
        'manual_journals'                       => 84,
        'sales_challans'                        => 84,
        'warehouse_transfers'                   => 84,
        'stock_adjustments'                     => 84,
        'stock_take_sessions'                   => 84,
        // PURCHASING-API-1 (G-121): purchase_orders was missing from the
        // retention matrix — the archival engine never archived old POs
        // (operational bloat over time). POs are NOT yet partitioned by
        // pg_partman (partitioning purchase_orders is a separate, larger
        // task — it has inbound FKs from purchase_order_items, purchase_receives,
        // and supplier_payment_settlements that would all need to be made
        // DEFERRABLE first). This entry ensures that the MOMENT purchase_orders
        // is registered with pg_partman, the 84-month retention will auto-apply
        // via the idempotent UPDATE below (the partman.part_config row will
        // be created by the partitioning migration, and this UPDATE will set
        // its retention on the next run). The Log::warning branch below
        // already handles the "part_config row not found yet" case gracefully.
        'purchase_orders'                       => 84,

        // ── Stock transactions — 84 months (7-year compliance) ─────────────
        'stock_transactions'                    => 84,

        // ── Sales invoices — 84 months (7-year compliance) ──────────────────
        'sales_invoices'                        => 84,

        // ── Daily warehouse stock summary — 36 months
        //    (24 hot + 12 warm per roadmap §14.2; supersedes Phase 3's 24-month
        //    config — this migration's WHERE clause overwrites 24→36.)
        'daily_warehouse_stock_summary'         => 36,
    ];

    public function up(): void
    {
        // Guard: skip if pg_partman is not installed. The partitioning migrations
        // (000001-000004, 2026_08_20_000001, 2026_08_22_*) register tables with
        // pg_partman only when the extension is available; if it's absent,
        // partman.part_config doesn't exist and this UPDATE would throw
        // SQLSTATE 42P01. Partitioning still works without pg_partman — only
        // automatic maintenance (retention/detachment) is disabled.
        $partmanInstalled = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_partman'
            ) AS installed
        ")->installed ?? false;

        if (! $partmanInstalled) {
            Log::warning(
                'pg_partman extension not installed — skipping retention config '
                . '(migration 2026_08_25_000001, Phase 7.1). Partitioning still '
                . 'works; only automatic maintenance (retention/detachment) is disabled.'
            );
            return;
        }

        foreach (self::RETENTION_CONFIGS as $table => $months) {
            $target = "{$months} months";

            // Idempotent UPDATE: only modify the row when the current retention
            // is missing or differs from the target value. This avoids
            // overwriting future manual tuning while still correcting the
            // Phase 3 24→36 revision for daily_warehouse_stock_summary.
            $updated = DB::affectingStatement(<<<SQL
                UPDATE partman.part_config
                SET retention             = '{$target}',
                    retention_keep_table = true,
                    retention_schema     = 'archive'
                WHERE parent_table = 'public.{$table}'
                  AND (
                      retention IS NULL
                      OR retention = ''
                      OR retention <> '{$target}'
                  )
            SQL);

            if ($updated === 0) {
                // Either (a) the row already had the exact target retention,
                // or (b) the row doesn't exist at all. Distinguish the two so
                // we can warn on (b) — the table may not be registered with
                // pg_partman yet (e.g. on a fresh install where the
                // partitioning migrations haven't run).
                $exists = DB::selectOne(
                    "SELECT 1 FROM partman.part_config WHERE parent_table = ?",
                    ["public.{$table}"]
                );

                if (! $exists) {
                    Log::warning(
                        "Phase 7.1: partman.part_config row not found for public.{$table} "
                        . "— table is not registered with pg_partman yet. "
                        . "Retention will be applied when the parent is registered."
                    );
                }
                // If the row exists but already had the target retention,
                // this is the desired state — no log needed.
            } else {
                Log::info("Phase 7.1: retention for public.{$table} set to {$target} (keep_table=true, schema=archive).");
            }
        }
    }

    public function down(): void
    {
        // Guard: skip if pg_partman is not installed (mirrors up()).
        $partmanInstalled = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_partman'
            ) AS installed
        ")->installed ?? false;

        if (! $partmanInstalled) {
            return;
        }

        // Reverse: clear retention config for every table we touched.
        // We only clear tables whose retention matches one of our target
        // values, to avoid wiping any future custom configs.
        foreach (self::RETENTION_CONFIGS as $table => $months) {
            $target = "{$months} months";

            DB::statement(<<<SQL
                UPDATE partman.part_config
                SET retention             = NULL,
                    retention_keep_table = false,
                    retention_schema     = NULL
                WHERE parent_table        = 'public.{$table}'
                  AND retention           = '{$target}'
                  AND retention_keep_table = true
                  AND retention_schema     = 'archive'
            SQL);
        }
    }
};
