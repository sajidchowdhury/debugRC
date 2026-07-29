<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.2 — Align branch_demand_items table with legacy schema.
 *
 * The PostgreSQL schema has:
 *   - warehouse_id (single)  →  Legacy has from_warehouse_id + to_warehouse_id
 *   - rate (numeric 12,2)    →  Legacy has cost_rate (numeric 12,4)
 *   - fulfilled_qty           →  Not needed (single send, no partial fulfillment)
 *
 * This migration:
 *   1. Adds from_warehouse_id, to_warehouse_id
 *   2. Adds cost_rate (numeric 12,4) to match legacy precision
 *   3. Adds price_min, price_max, price_default for price range tracking
 *   4. Drops the legacy-incompatible warehouse_id column
 *   5. Drops fulfilled_qty (not used in the business logic)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add from_warehouse_id and to_warehouse_id
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS from_warehouse_id integer REFERENCES warehouses(id)
        ");
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS to_warehouse_id integer REFERENCES warehouses(id)
        ");

        // 2. Add cost_rate (12,4) — the legacy uses this for locked avg_cost
        //    The existing 'rate' column (12,2) is less precise; we add cost_rate
        //    and will use it going forward. The 'rate' column is kept for
        //    backward compatibility but will be deprecated.
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS cost_rate numeric(12,4) DEFAULT 0
        ");

        // 3. Add price range columns for price range tracking
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS price_min numeric(12,2) DEFAULT 0
        ");
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS price_max numeric(12,2) DEFAULT 0
        ");
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS price_default numeric(12,2) DEFAULT 0
        ");

        // 4. Drop the legacy-incompatible warehouse_id column
        //    (from_warehouse_id and to_warehouse_id replace it)
        DB::statement("
            ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS warehouse_id
        ");

        // 5. Drop fulfilled_qty — not needed (single send, no partial fulfillment)
        DB::statement("
            ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS fulfilled_qty
        ");

        // 6. Drop the old 'rate' column — cost_rate replaces it
        DB::statement("
            ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS rate
        ");

        // 7. Add indexes
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdi_product
            ON branch_demand_items(product_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdi_from_warehouse
            ON branch_demand_items(from_warehouse_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdi_to_warehouse
            ON branch_demand_items(to_warehouse_id)
        ");
    }

    public function down(): void
    {
        // Drop indexes
        DB::statement("DROP INDEX IF EXISTS idx_bdi_to_warehouse");
        DB::statement("DROP INDEX IF EXISTS idx_bdi_from_warehouse");
        DB::statement("DROP INDEX IF EXISTS idx_bdi_product");

        // Restore original columns
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS rate numeric(12,2) DEFAULT 0
        ");
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS fulfilled_qty numeric(14,4) DEFAULT 0
        ");
        DB::statement("
            ALTER TABLE branch_demand_items
            ADD COLUMN IF NOT EXISTS warehouse_id integer REFERENCES warehouses(id)
        ");

        // Drop new columns
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS price_default");
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS price_max");
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS price_min");
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS cost_rate");
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS to_warehouse_id");
        DB::statement("ALTER TABLE branch_demand_items DROP COLUMN IF EXISTS from_warehouse_id");
    }
};
