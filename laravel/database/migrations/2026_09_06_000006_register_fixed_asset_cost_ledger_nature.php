<?php

/**
 * G-276 (G10) FINANCE-FA-1: Register the `fixed_asset_cost` ledger nature.
 *
 * The 5 asset-at-cost ledgers (L-0201 Tangible Fixed Assets, L-0210 Machinery
 * & Equipment, L-0220 Furniture & Fixtures, L-0230 Vehicles, L-0240 Office
 * Equipment) were seeded by `2026_08_13_000001_create_fixed_assets.php` with
 * `ledger_nature = null`. This prevented `LedgerNatureService::validateChartOfAccounts`
 * from type-checking them and meant a crafted POST to `/admin/fixed-assets`
 * could bypass the `create()` dropdown filter (which filters by `parent_id = L-0200`
 * only) and point `asset_ledger_id` at any active Asset ledger (e.g. L-0101 Cash).
 *
 * The companion code change in `LedgerNatureService::EXTENDED_NATURES` registers
 * the new `fixed_asset_cost` nature; this migration backfills the 5 seeded
 * ledgers so the nature is populated in existing DBs. `FixedAssetController::store`
 * now reads `$assetLedger->ledger_nature === 'fixed_asset_cost'` as a server-side
 * guard (closing the crafted-POST bypass).
 *
 * Idempotent: `WHERE ledger_nature IS NULL` skips rows already backfilled on a
 * re-run (and preserves any manual nature override an admin may have set).
 * Uses `UPDATE` (not `upsert`) so the migration is safe to run on databases
 * where the ledgers have been renamed, re-parented, or have additional columns.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('ledgers')
            ->whereIn('ledger_code', ['L-0201', 'L-0210', 'L-0220', 'L-0230', 'L-0240'])
            ->whereNull('ledger_nature')
            ->update([
                'ledger_nature' => 'fixed_asset_cost',
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            echo "  G-276: backfilled {$affected} fixed-asset-cost ledger(s) with the 'fixed_asset_cost' nature.\n";
        } else {
            echo "  G-276: no ledgers needed backfilling (already had a nature assigned).\n";
        }
    }

    public function down(): void
    {
        // Revert only the ledgers we backfilled — set nature back to null.
        // Safe: a non-null nature on these 5 ledgers is a no-op for any code
        // path that existed before G-276 (the `fixed_asset_cost` nature was
        // not registered prior to this wave).
        DB::table('ledgers')
            ->whereIn('ledger_code', ['L-0201', 'L-0210', 'L-0220', 'L-0230', 'L-0240'])
            ->where('ledger_nature', 'fixed_asset_cost')
            ->update([
                'ledger_nature' => null,
                'updated_at' => now(),
            ]);
    }
};
