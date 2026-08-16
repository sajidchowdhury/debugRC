<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 / Session 7 — Add `consumed_qty` + `receiving_branch_id` to
 * branch_demand_items, plus a partial index for the FIFO hot path.
 *
 * Goal: enable deterministic per-demand-item profit/loss attribution.
 * Each sale line links to the demand item that supplied the goods
 * (sales_invoice_items.branch_demand_item_id, added in S5). The
 * demand item tracks how much of its qty has been "consumed" by sales
 * via this migration's new `consumed_qty` column.
 *
 * Terminology note (matches the rest of the codebase, including
 * BranchDemandService::getActiveCostRate() — see its docblock):
 *   - The selling branch (the branch that RECEIVES goods via a demand
 *     and later sells them to customers) is identified by
 *     `branch_demands.to_branch_id` in this codebase's legacy naming
 *     convention. (The model docstring on BranchDemand flips this
 *     semantic, but the actual FIFO query in
 *     BranchDemandService::getActiveCostRate() and the
 *     `confirmReceipt()` authorization agree that `from_branch_id`
 *     is the requester who CONFIRMS RECEIPT — so `from_branch_id`
 *     is the branch that receives the goods and later sells them.)
 *
 * To eliminate ambiguity and to make the FIFO partial index usable
 * WITHOUT a JOIN to branch_demands, we denormalize the receiving
 * branch id onto each branch_demand_items row as
 * `receiving_branch_id`. This column is backfilled from
 * `branch_demands.from_branch_id` (the requester == the receiver)
 * at migration time, and kept in sync by BranchDemandService at
 * create-time (the new column is added to the demand-item INSERT).
 *
 * The partial index `WHERE qty > consumed_qty` is the hot-path
 * index for FIFO resolution: it only covers demand items that still
 * have remaining un-consumed stock. As items get fully consumed,
 * they fall out of the index — keeping it small and the resolver
 * fast even on tables with millions of historical demand items.
 *
 * @see \App\Services\DemandItemFifoResolver
 * @see \App\Services\Sales\SalesInvoiceService::finalizeFromCart()
 * @see database/migrations/2026_10_18_000008_backfill_branch_demand_item_id.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 7
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_demand_items')) {
            throw new RuntimeException(
                'branch_demand_items table does not exist — run the base schema migrations first.'
            );
        }

        // 1. consumed_qty — how much of this demand item has been sold by
        //    the receiving branch. `qty - consumed_qty` = remaining stock
        //    available for sale attribution. DEFAULT 0 so existing rows
        //    start "fully available" (consistent with S5 behavior where
        //    cost_rate lookup did not consider consumption).
        if (!Schema::hasColumn('branch_demand_items', 'consumed_qty')) {
            DB::statement(
                'ALTER TABLE branch_demand_items ' .
                'ADD COLUMN consumed_qty numeric(14,3) NOT NULL DEFAULT 0'
            );
        }

        // 2. consumed_qty_updated_at — for debugging / audit. NULL until
        //    the first sale consumes from this item.
        if (!Schema::hasColumn('branch_demand_items', 'consumed_qty_updated_at')) {
            DB::statement(
                'ALTER TABLE branch_demand_items ' .
                'ADD COLUMN consumed_qty_updated_at timestamp(0)'
            );
        }

        // 3. receiving_branch_id — denormalized copy of
        //    branch_demands.from_branch_id (the requester == receiver
        //    of goods). Backfilled below. Kept in sync by
        //    BranchDemandService::createDemand() going forward.
        if (!Schema::hasColumn('branch_demand_items', 'receiving_branch_id')) {
            DB::statement(
                'ALTER TABLE branch_demand_items ' .
                'ADD COLUMN receiving_branch_id integer'
            );

            // Backfill from the parent demand.
            //
            // NOTE: We deliberately do NOT use Laravel's query-builder
            // join-update here (DB::table(...)->join(...)->update([...])).
            // On PostgreSQL, Laravel compiles that pattern into
            //   UPDATE "t" SET col = bd.x WHERE ctid IN (SELECT ... FROM t JOIN bd ...)
            // which fails with `missing FROM-clause entry for table "bd"`
            // because `bd` is only aliased inside the subquery and is not
            // visible in the outer UPDATE's SET clause. Use PostgreSQL's
            // native UPDATE ... FROM ... syntax instead — the target table
            // is NOT re-listed in FROM (only the source table is), and the
            // join lives in WHERE.
            $updated = DB::affectingStatement(
                'UPDATE branch_demand_items AS bdi ' .
                'SET receiving_branch_id = bd.from_branch_id ' .
                'FROM branch_demands AS bd ' .
                'WHERE bd.id = bdi.branch_demand_id ' .
                'AND bdi.receiving_branch_id IS NULL'
            );

            Log::info('S7: backfilled receiving_branch_id on branch_demand_items.', [
                'rows_updated' => $updated,
            ]);
        }

        // 4. CHECK constraint: consumed_qty must be in [0, qty].
        //    Prevents over-consumption (a bug in the FIFO resolver would
        //    show up as a CHECK violation rather than silent data loss).
        //    Uses ALTER TABLE ... ADD CONSTRAINT (Laravel 12's Blueprint
        //    does not have a check() helper — see S3 fix cb975c9).
        $constraintExists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'bdi_consumed_qty_range' LIMIT 1"
        );
        if (!$constraintExists) {
            DB::statement(
                'ALTER TABLE branch_demand_items ' .
                'ADD CONSTRAINT bdi_consumed_qty_range ' .
                'CHECK (consumed_qty >= 0 AND consumed_qty <= qty)'
            );
        }

        // 5. Partial index — the hot path for FIFO resolution.
        //    Only covers demand items that still have un-consumed stock.
        //    The resolver orders by demand_date, id (oldest first).
        $indexExists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'idx_bdi_fifo_open' LIMIT 1"
        );
        if (!$indexExists) {
            DB::statement(
                'CREATE INDEX idx_bdi_fifo_open ' .
                'ON branch_demand_items (receiving_branch_id, product_id, id) ' .
                'WHERE consumed_qty < qty'
            );
        }

        // 6. Secondary index for the release path: lookup demand items
        //    linked to a specific sale invoice item. Actually this index
        //    lives on sales_invoice_items(branch_demand_item_id) — but
        //    S5 already added it as a partial NULL-only index for the
        //    backfill path. Add a full (non-partial) index here so the
        //    release path (which queries by branch_demand_item_id IN (...))
        //    can use it. Drop the partial first to avoid duplicate-index
        //    bloat.
        if (!DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname = 'idx_sii_bdi' LIMIT 1")) {
            // Drop the S5 partial index (idx_sii_bdi_null) — the full
            // index below subsumes its use case (NULLs are also indexed).
            DB::statement('DROP INDEX IF EXISTS idx_sii_bdi_null');
            DB::statement(
                'CREATE INDEX idx_sii_bdi ON sales_invoice_items(branch_demand_item_id)'
            );
        }

        Log::info('S7: added consumed_qty + receiving_branch_id + FIFO partial index to branch_demand_items.');
    }

    public function down(): void
    {
        // Drop indexes first.
        DB::statement('DROP INDEX IF EXISTS idx_sii_bdi');
        DB::statement('DROP INDEX IF EXISTS idx_bdi_fifo_open');

        // Recreate the S5 partial index that we dropped in up() so
        // rolling back S7 doesn't leave S5 without its index.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sii_bdi_null ' .
            'ON sales_invoice_items(branch_demand_item_id) ' .
            'WHERE branch_demand_item_id IS NULL'
        );

        // Drop CHECK constraint.
        DB::statement('ALTER TABLE branch_demand_items DROP CONSTRAINT IF EXISTS bdi_consumed_qty_range');

        foreach (['consumed_qty_updated_at', 'consumed_qty', 'receiving_branch_id'] as $col) {
            if (Schema::hasColumn('branch_demand_items', $col)) {
                DB::statement("ALTER TABLE branch_demand_items DROP COLUMN {$col}");
            }
        }

        Log::info('S7: rolled back consumed_qty + receiving_branch_id + FIFO partial index.');
    }
};
