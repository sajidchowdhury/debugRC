<?php

/**
 * G-352 (G27) FINANCE-BD-1: Add timestamps to branch_demand_items.
 *
 * `branch_demand_items` was created without `created_at` / `updated_at` columns
 * (migration 2026_07_29_000014 + SQL baseline 03_stock.sql:769-781). The
 * `BranchDemandItem` model declares `public $timestamps = false;` to match.
 * This makes it impossible to track when an item was last modified (e.g., when
 * `from_warehouse_id` was set at send time, or `cost_rate` was locked).
 *
 * This migration adds nullable `created_at` + `updated_at` columns. Existing
 * rows get `created_at = NOW()` backfilled so they have a sane value. The
 * `BranchDemandItem` model drops `public $timestamps = false;` so Eloquent's
 * timestamp magic fires on future creates/updates.
 *
 * Cross-ref: `BranchDemandService::sendGoodsWithWarehouses` uses
 * `DB::table('branch_demand_items')->where('id', ...)->update(...)` which
 * bypasses Eloquent timestamps. Those call sites need to manually set
 * `'updated_at' => now()` in the update array (or be refactored to use
 * `BranchDemandItem::where('id', ...)->update(...)` which DOES update
 * `updated_at` via Eloquent). This migration adds the column; the service-
 * layer call-site audit is a follow-up if the accountant needs per-item
 * modification timestamps for forensics.
 *
 * Idempotent: `Schema::hasColumn` guards each ADD COLUMN.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('branch_demand_items', 'created_at')) {
            DB::statement('ALTER TABLE branch_demand_items ADD COLUMN created_at timestamp(0)');
        }
        if (!Schema::hasColumn('branch_demand_items', 'updated_at')) {
            DB::statement('ALTER TABLE branch_demand_items ADD COLUMN updated_at timestamp(0)');
        }

        // Backfill existing rows so they have a sane created_at.
        $backfilled = DB::table('branch_demand_items')
            ->whereNull('created_at')
            ->update(['created_at' => DB::raw('NOW()')]);

        if ($backfilled > 0) {
            echo "  G-352: added created_at + updated_at columns to branch_demand_items; backfilled {$backfilled} existing row(s) with created_at = NOW().\n";
        } else {
            echo "  G-352: added created_at + updated_at columns to branch_demand_items (no existing rows to backfill).\n";
        }
    }

    public function down(): void
    {
        foreach (['updated_at', 'created_at'] as $col) {
            if (Schema::hasColumn('branch_demand_items', $col)) {
                DB::statement("ALTER TABLE branch_demand_items DROP COLUMN {$col}");
            }
        }
    }
};
