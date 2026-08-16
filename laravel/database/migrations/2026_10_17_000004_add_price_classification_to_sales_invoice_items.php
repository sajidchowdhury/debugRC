<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 / Session 5 — Add price classification + cost snapshot columns
 * to sales_invoice_items.
 *
 * Goal: every sale line now carries a snapshot of the min/max/default/cost
 * context at the moment of sale, plus a computed `price_classification`
 * in ('min', 'default', 'max', 'below_min'). This enables:
 *   - The below-min admin-override workflow (Session 6) — the cart will
 *     no longer hard-block below-min; it will store the line with a
 *     pending classification and require admin approval to finalize.
 *   - The demand-to-sale FIFO linkage (Session 7) — `branch_demand_item_id`
 *     will point to the demand item that supplied this sale line.
 *   - The Branch P&L report (Session 8) — `cost_rate` snapshot lets the
 *     report compute per-line profit without re-joining to demand items
 *     or product cost history (which may have changed since the sale).
 *
 * All 7 new columns are NULLABLE so the backfill migration
 * (2026_10_17_000005_backfill_price_classification.php) can leave them
 * NULL for rows where the historical price context cannot be reconstructed
 * (e.g. orphaned products, products with no price history, sales dated
 * before any demand was sent to the branch).
 *
 * The `price_classification` column has a CHECK constraint enforcing the
 * 4 valid values. NULL is allowed (for un-backfilled rows and for the
 * brief window between row-insert and classification-compute in the
 * finalize flow — though in practice finalize populates it before the
 * INSERT completes).
 *
 * `branch_demand_item_id` and `below_min_override_id` are created here
 * but left NULL in S5 — they are populated in S6 (override) and S7
 * (FIFO linkage). Creating the columns now means S6 and S7 don't need
 * a schema change, only an UPDATE.
 *
 * FK strategy: `branch_demand_items` is NOT partitioned (it's a child of
 * `branch_demands` which is also not partitioned — see config/fiscal.php),
 * so a standard declarative FK on `branch_demand_item_id` works fine.
 *
 * `user_audit_log` IS partitioned (RANGE by `created_at`) — and PostgreSQL
 * requires the partition key to be part of any unique constraint on a
 * partitioned table. So `user_audit_log` only has PRIMARY KEY (id, created_at),
 * NOT a unique constraint on `id` alone. That makes a declarative FK from
 * `sales_invoice_items(below_min_override_id)` to `user_audit_log(id)`
 * impossible (PG error 42830: "no unique constraint matching given keys").
 *
 * We therefore leave `below_min_override_id` as a plain bigint with NO
 * declarative FK — a soft reference. The S6 override flow is responsible
 * for inserting the audit row FIRST, capturing its id, and only then
 * writing that id into `sales_invoice_items.below_min_override_id`.
 * Referential integrity is enforced at the application layer, not by PG.
 * This matches the compliance-audit pattern: audit rows are never deleted
 * (so ON DELETE SET NULL would never fire anyway).
 *
 * @see \App\Support\PriceClassifier
 * @see \App\Services\Sales\SalesInvoiceService::finalizeFromCart()
 * @see database/migrations/2026_10_17_000005_backfill_price_classification.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 5
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: the table must exist. On a fresh install it is created
        // by database/sql/04_sales.sql (loaded earlier in the migration
        // chain via the rc-erp initial schema). On an upgraded install
        // it has existed since the original Laravel migration.
        if (!Schema::hasTable('sales_invoice_items')) {
            throw new RuntimeException('sales_invoice_items table does not exist — run the base schema migrations first.');
        }

        // 1. price_min — snapshot of product's min_rate at sale time
        if (!Schema::hasColumn('sales_invoice_items', 'price_min')) {
            DB::statement('ALTER TABLE sales_invoice_items ADD COLUMN price_min numeric(12,2)');
        }

        // 2. price_max — snapshot of product's max_rate at sale time
        if (!Schema::hasColumn('sales_invoice_items', 'price_max')) {
            DB::statement('ALTER TABLE sales_invoice_items ADD COLUMN price_max numeric(12,2)');
        }

        // 3. price_default — snapshot of product's default_rate at sale time
        if (!Schema::hasColumn('sales_invoice_items', 'price_default')) {
            DB::statement('ALTER TABLE sales_invoice_items ADD COLUMN price_default numeric(12,2)');
        }

        // 4. cost_rate — snapshot of the locked inter-branch cost from the
        //    demand item that supplied this sale. NULL when the product
        //    was not sourced via a branch demand (e.g. direct purchase
        //    from a supplier — in that case the report falls back to
        //    products.purchase_rate).
        if (!Schema::hasColumn('sales_invoice_items', 'cost_rate')) {
            DB::statement('ALTER TABLE sales_invoice_items ADD COLUMN cost_rate numeric(12,4)');
        }

        // 5. price_classification — computed at finalize time via
        //    PriceClassifier::classify(). CHECK constraint enforces the
        //    4 valid values; NULL allowed for un-backfilled rows.
        if (!Schema::hasColumn('sales_invoice_items', 'price_classification')) {
            DB::statement(
                "ALTER TABLE sales_invoice_items ADD COLUMN price_classification text " .
                "CHECK (price_classification IS NULL OR price_classification IN ('min','default','max','below_min'))"
            );
        }

        // 6. branch_demand_item_id — populated in Session 7 (FIFO linkage).
        //    FK to branch_demand_items(id) ON DELETE SET NULL — if a
        //    demand item is deleted (rare; usually demands are reversed
        //    not deleted), the sale line keeps its data but loses the
        //    linkage. The cost_rate snapshot is preserved.
        if (!Schema::hasColumn('sales_invoice_items', 'branch_demand_item_id')) {
            DB::statement(
                'ALTER TABLE sales_invoice_items ADD COLUMN branch_demand_item_id bigint'
            );
            // FK added separately so a missing branch_demand_items table
            // doesn't break the column add (defensive).
            $bdiExists = DB::selectOne("SELECT to_regclass('public.branch_demand_items') AS reg");
            if ($bdiExists && $bdiExists->reg !== null) {
                DB::statement(
                    'ALTER TABLE sales_invoice_items ' .
                    'ADD CONSTRAINT sii_bdi_fk FOREIGN KEY (branch_demand_item_id) ' .
                    'REFERENCES branch_demand_items(id) ON DELETE SET NULL'
                );
            }
        }

        // 7. below_min_override_id — populated in Session 6 (admin override).
        //    Plain bigint column, NO declarative FK — see class docblock:
        //    user_audit_log is partitioned (RANGE by created_at) so its PK
        //    is (id, created_at), and a FK to (id) alone is impossible.
        //    S6 enforces referential integrity at the application layer.
        if (!Schema::hasColumn('sales_invoice_items', 'below_min_override_id')) {
            DB::statement(
                'ALTER TABLE sales_invoice_items ADD COLUMN below_min_override_id bigint'
            );
        }

        // Index for the S6 / S7 queries: "find all sale lines with no
        // demand item linked" and "find all below-min sales pending
        // approval". Partial index keeps it small (only NULL rows).
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sii_bdi_null ' .
            'ON sales_invoice_items(branch_demand_item_id) ' .
            'WHERE branch_demand_item_id IS NULL'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sii_classification ' .
            'ON sales_invoice_items(price_classification) ' .
            'WHERE price_classification IS NOT NULL'
        );

        Log::info('S5: added price classification + cost snapshot columns to sales_invoice_items.');
    }

    public function down(): void
    {
        // Drop indexes first (they reference the columns being dropped).
        DB::statement('DROP INDEX IF EXISTS idx_sii_classification');
        DB::statement('DROP INDEX IF EXISTS idx_sii_bdi_null');

        // Drop FK constraint (only sii_bdi_fk — sii_ual_fk was never created
        // because user_audit_log is partitioned and has no unique constraint
        // on id alone; see up() docblock).
        DB::statement('ALTER TABLE sales_invoice_items DROP CONSTRAINT IF EXISTS sii_ual_fk');
        DB::statement('ALTER TABLE sales_invoice_items DROP CONSTRAINT IF EXISTS sii_bdi_fk');

        foreach ([
            'below_min_override_id',
            'branch_demand_item_id',
            'price_classification',
            'cost_rate',
            'price_default',
            'price_max',
            'price_min',
        ] as $col) {
            if (Schema::hasColumn('sales_invoice_items', $col)) {
                DB::statement("ALTER TABLE sales_invoice_items DROP COLUMN {$col}");
            }
        }

        Log::info('S5: dropped price classification + cost snapshot columns from sales_invoice_items.');
    }
};
