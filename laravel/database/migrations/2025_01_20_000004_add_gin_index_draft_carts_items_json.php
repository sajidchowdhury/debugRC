<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 23: Add GIN index on sales_draft_carts.items_json.
 *
 * GIN (Generalized Inverted Index) indexes are the standard PostgreSQL
 * approach for JSONB columns. They support the containment operator (@>)
 * which checks whether a JSONB document contains a specific key/value pair
 * or structure — e.g., "find all carts that contain product_id 42".
 *
 * Current usage: items_json is treated as an opaque blob (full read →
 * PHP mutate → full write). All WHERE clauses use scalar columns
 * (user_id, customer_id). No @> queries exist yet.
 *
 * Why add it now:
 *   1. Forward-looking: as the cart system evolves to support multi-warehouse
 *      carts, condition-state tracking, and product-based cart lookups
 *      (e.g., "which open carts contain product X?"), the GIN index will
 *      enable those queries without a full table scan.
 *   2. Near-zero cost: GIN on jsonb_path_ops is compact (~10% of JSONB data
 *      size) and has minimal write overhead for the insert-heavy cart pattern
 *      (carts are created, updated a few times, then deleted on invoice save).
 *   3. The jsonb_path_ops operator class is chosen over the default because:
 *      - It only supports @> containment (not existence ? operators)
 *      - It produces a smaller, faster index (~30% smaller than default GIN)
 *      - Containment is the expected query pattern for cart item lookups
 *
 * Uses CREATE INDEX IF NOT EXISTS for idempotency.
 * A final ANALYZE refreshes planner statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GIN index for JSONB cart items — enables @> containment queries.
        // jsonb_path_ops: smaller index, faster for @> (no ? operator support needed).
        //
        // Enables queries like:
        //   SELECT * FROM sales_draft_carts
        //   WHERE items_json @> '[{"product_id": 42}]';
        //
        //   SELECT * FROM sales_draft_carts
        //   WHERE items_json @> '{"product_id": 42, "warehouse_id": 3}';
        //
        //   -- Find all carts containing a specific product across all users
        //   -- (useful for inventory reservation / stock availability checks)
        //   SELECT user_id, customer_id, items_json
        //   FROM sales_draft_carts
        //   WHERE items_json @> '[{"product_id": 42}]';
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sdc_items_gin
             ON sales_draft_carts USING GIN (items_json jsonb_path_ops)"
        );

        // Refresh planner statistics for the newly indexed column
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_sdc_items_gin");
    }
};
