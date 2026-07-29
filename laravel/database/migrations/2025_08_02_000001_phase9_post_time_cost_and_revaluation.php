<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 (Stock Take plan) — GL & costing refinements: post-time cost + revaluation.
 *
 * Eliminates the count-time vs post-time avg-cost drift. Before Phase 9, the
 * GL was posted at the snapshot rate (stock_take_items.rate captured at setup
 * time). When avg cost drifted between setup and post (inbound receipts, cost
 * adjustments, transfers, another posted stock-take), the GL value diverged
 * from the actual stock-value change — the books quietly went out of sync.
 *
 * Phase 9 fixes this in three moves:
 *
 *   (a) Capture the setup-time avg cost as `system_rate` on each item row
 *       (denormalized snapshot, never updated). The existing `rate` column is
 *       repurposed as the POST-TIME rate used for GL — written during
 *       postSession, not setup.
 *
 *   (b) At post time, re-fetch the live avg cost (`StockService::getWarehouse
 *       AvgCost`) and use it for the GL value. `rate` is overwritten with
 *       this post-time rate. So the GL always reflects the cost as it stood
 *       at the moment of posting, not the moment of setup.
 *
 *   (c) If the post-time rate differs from the setup-time system_rate by more
 *       than a configurable epsilon (default 0.01), post an additional
 *       revaluation adjusting entry: Dr/Cr Inventory / Inventory Revaluation
 *       Expense for the difference × the counted quantity. This brings the
 *       book value of the counted stock in line with its post-time cost.
 *
 * Schema additions:
 *   - stock_take_items.system_rate        numeric(18,6) — setup-time avg cost.
 *   - stock_take_items.post_rate          numeric(18,6) — post-time avg cost.
 *   - stock_take_items.revaluation_amount numeric(18,6) — the reval adj amount.
 *   - stock_take_items.revaluation_line_id integer      — per-line GL trace.
 *   - stock_take_policies: stock_take.revaluation_epsilon (numeric, default 0.01).
 *   - ledgers: inventory_revaluation nature seeded (L-0504 Expense).
 *
 * Idempotency: every ALTER uses IF NOT EXISTS / IF EXISTS, every constraint
 * add is name-guarded, every CREATE uses IF NOT EXISTS, and the ledger seed
 * uses a lookup-then-insert pattern guarded by BOTH ledger_nature AND
 * ledger_code (defense in depth — see Hotfix #5 below). Re-running (or
 * running against a partially-migrated DB) is safe.
 *
 * Hotfix #5 (Task 16): the original Phase 9 seed used L-0503 for the
 * inventory_revaluation ledger. L-0503 was already taken by "Damage Loss"
 * (ledger_nature='damage_loss') in the chart-of-accounts seeder
 * (2025_01_05_000001_seed_default_chart_of_accounts.php line 111). The
 * original idempotency check only counted ledgers by ledger_nature, so it
 * saw 0 rows with nature='inventory_revaluation' and tried to INSERT L-0503
 * — colliding with the unique constraint ledgers_ledger_code_unique with
 * SQLSTATE[23505]. Fix: use L-0504 (next free code in the L-05xx inventory-
 * expense range) AND guard the insert by checking ledger_code too.
 *
 * PostgreSQL prepared-statement safety: every DB::statement() in this file
 * contains exactly ONE SQL command. Multi-command strings are rejected by
 * PDO_PGSQL with SQLSTATE[42601]; see Task 14 hotfix for the prior instance.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §7 Phase 9
 *   - app/Services/Stock/StockTakeService.php  (setupWarehouseCounts, postSession, postStockTakeGL)
 *   - app/Services/Stock/StockTakePolicyService.php  (revaluationEpsilon)
 *   - app/Services/Stock/StockTakeVarianceReport.php  (cost columns)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── (1) stock_take_items: costing columns ─────────────────────────
        // system_rate — setup-time avg cost (snapshot, never updated). The
        // existing `rate` column (numeric(12,2)) is repurposed as the post-
        // time rate used for GL; it stays NULL-ish/0 until postSession writes
        // the live avg cost into it. system_rate is the immutable baseline.
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'ADD COLUMN IF NOT EXISTS system_rate numeric(18,6)'
        );

        // post_rate — the post-time avg cost (re-fetched at postSession).
        // Persisted separately from `rate` so the variance report can show
        // both the setup-time and post-time costs without recomputation.
        // `rate` continues to hold the value used for the GL entry (which
        // equals post_rate for variance lines), kept for backward compat
        // with the Phase 6 variance report.
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'ADD COLUMN IF NOT EXISTS post_rate numeric(18,6)'
        );

        // revaluation_amount — the adjusting entry amount for this line:
        //   (post_rate - system_rate) * physical_qty
        // when |post_rate - system_rate| > epsilon AND physical_qty ≠ 0.
        // Zero for lines with no cost drift or zero counted qty. Persisted
        // so the variance report can total it without recomputation.
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'ADD COLUMN IF NOT EXISTS revaluation_amount numeric(18,6) NOT NULL DEFAULT 0'
        );

        // revaluation_line_id — per-line GL traceability for the revaluation
        // entry (mirrors the Phase 1 journal_line_id pattern). ON DELETE SET
        // NULL so deleting the journal line does not orphan the item row.
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'ADD COLUMN IF NOT EXISTS revaluation_line_id integer'
        );

        // Name-guarded FK for revaluation_line_id. DO block so re-runs are
        // a no-op (single SQL command — DO $$ ... $$; is ONE statement).
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'sti_revaluation_line_id_fk'
                      AND conrelid = 'stock_take_items'::regclass
                ) THEN
                    ALTER TABLE stock_take_items
                    ADD CONSTRAINT sti_revaluation_line_id_fk
                    FOREIGN KEY (revaluation_line_id) REFERENCES journal_lines(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        // ── (2) Backfill system_rate from rate for pre-Phase-9 rows ───────
        // Existing items have `rate` populated at setup (it held the setup-
        // time avg cost before Phase 9). Copy it into system_rate so the
        // drift comparison works for sessions set up before this migration.
        // Only touches rows where system_rate IS NULL (idempotent).
        DB::statement(<<<'SQL'
            UPDATE stock_take_items
            SET system_rate = rate
            WHERE system_rate IS NULL
              AND rate IS NOT NULL
        SQL);

        // ── (3) Seed the revaluation_epsilon policy ───────────────────────
        // Reuses the Phase 4 stock_take_policies table. updateOrInsert makes
        // this idempotent — re-runs update the row in place rather than
        // inserting a duplicate (the table has a UNIQUE on `key`).
        DB::table('stock_take_policies')->updateOrInsert(
            ['key' => 'stock_take.revaluation_epsilon'],
            [
                'value'       => json_encode(0.01),
                'description' => 'Phase 9: minimum |post_rate - system_rate| delta (in currency units) that triggers a revaluation adjusting entry at post time. When the avg cost drifts by more than this epsilon between setup and post, an additional Dr/Cr Inventory/Inventory Revaluation Expense line is posted for (post_rate - system_rate) * physical_qty. Default 0.01 (any non-trivial drift). Set to 0 to revalue on every post.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        // ── (4) Seed the inventory_revaluation ledger nature ──────────────
        // Revaluation expense ledger (L-0504), sibling of inventory_shrinkage
        // (L-0502) and Damage Loss (L-0503). Dr this when post_rate <
        // system_rate (cost fell — the book value of counted stock decreases);
        // Cr this when post_rate > system_rate (cost rose — book value
        // increases). The offset is always Inventory.
        //
        // Idempotency (Hotfix #5, Task 16): guard by BOTH ledger_nature AND
        // ledger_code. The original Phase 9 seed only checked ledger_nature,
        // which returned 0 (no ledger had that nature yet) — but L-0503 was
        // already taken by "Damage Loss" (ledger_nature='damage_loss') in the
        // chart-of-accounts seeder, so the INSERT crashed with SQLSTATE[23505]
        // on the ledgers_ledger_code_unique constraint. Now we use L-0504
        // (next free code) and also check the code is free before inserting,
        // so the migration is safe even if a future seeder assigns L-0504 to
        // another nature.
        $existing = DB::table('ledgers')
            ->where('ledger_nature', 'inventory_revaluation')
            ->exists();
        if (! $existing) {
            // Look up the parent expense group id (the same parent used by
            // inventory_shrinkage in the chart-of-accounts seeder).
            $shrinkage = DB::table('ledgers')
                ->where('ledger_nature', 'inventory_shrinkage')
                ->first();
            $parentExpenseId = $shrinkage?->parent_id;
            if ($parentExpenseId !== null) {
                // Defense in depth: also verify L-0504 is free. If a future
                // seeder grabs L-0504 for another nature, skip the insert
                // rather than crashing the migration. The application looks
                // up the ledger by ledger_nature (via lookupLedgerByNature),
                // so a missing seed is a clear runtime error, not silent
                // corruption.
                $codeTaken = DB::table('ledgers')
                    ->where('ledger_code', 'L-0504')
                    ->exists();
                if (! $codeTaken) {
                    DB::table('ledgers')->insert([
                        'ledger_code'   => 'L-0504',
                        'ledger_name'   => 'Inventory Revaluation Expense',
                        'parent_id'     => $parentExpenseId,
                        'account_type'  => 'Expense',
                        'ledger_nature' => 'inventory_revaluation',
                        'sort_order'    => 540,
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Drop the revaluation FK + columns from stock_take_items.
        DB::statement('ALTER TABLE stock_take_items DROP CONSTRAINT IF EXISTS sti_revaluation_line_id_fk');
        DB::statement('ALTER TABLE stock_take_items DROP COLUMN IF EXISTS revaluation_line_id');
        DB::statement('ALTER TABLE stock_take_items DROP COLUMN IF EXISTS revaluation_amount');
        DB::statement('ALTER TABLE stock_take_items DROP COLUMN IF EXISTS post_rate');
        DB::statement('ALTER TABLE stock_take_items DROP COLUMN IF EXISTS system_rate');

        // Remove the revaluation_epsilon policy seed.
        DB::table('stock_take_policies')
            ->where('key', 'stock_take.revaluation_epsilon')
            ->delete();

        // Remove the inventory_revaluation ledger seed (L-0504). Delete by
        // ledger_nature (not by ledger_code) so this still works even if a
        // future seeder reassigns L-0504 to another nature. Only deletes the
        // ledger we created — leave any journal_lines referencing it (there
        // should be none after the column drop above, but defence in depth).
        DB::table('ledgers')
            ->where('ledger_nature', 'inventory_revaluation')
            ->delete();
    }
};
