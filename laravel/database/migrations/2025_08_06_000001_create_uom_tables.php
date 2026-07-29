<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (Stock Adjustment plan) — Unit-of-Measure (UOM) Handling.
 *
 * Goal: allow the accountant to enter quantities in any UOM (Carton, Pcs, KG)
 * and have the system convert to the product's base unit before posting to
 * stock. This directly enables the "system/unit-of-measure errors" use case
 * (G7 — Carton/Pcs confusion causing 10x stock errors).
 *
 * Schema changes:
 *   1. CREATE TABLE units_of_measure (id, code, name, type)
 *      — the master list of units. Seeded from the existing products.unit
 *        CHECK enum (Pcs, Carton, KG, Bag, Dobe, Set).
 *   2. CREATE TABLE product_uom_conversions (product_id, from_uom_id,
 *      to_uom_id, factor) — per-product conversion factors. to_uom is usually
 *      the product's base unit (the UOM whose code matches products.unit).
 *      factor: 1 from_uom = factor to_uom (e.g. 1 Carton = 12 Pcs → factor 12).
 *   3. ADD COLUMN uom_id, qty_entered, qty_base, uom_factor to
 *      stock_adjustment_items (all nullable so old rows + non-UOM callers
 *      keep working). qty_entered = what the user typed; qty_base = converted
 *      to the product's base unit; uom_factor = snapshot of the factor at
 *      creation time (audit immutability — if the conversion factor changes
 *      later, historical adjustments keep the factor they were posted with).
 *   4. Backfill existing items: qty_base = qty, qty_entered = qty,
 *      uom_factor = 1, uom_id = the product's base unit (looked up by
 *      matching products.unit → units_of_measure.code). Existing quantities
 *      were always entered in base units (there was no UOM before), so the
 *      backfill is a faithful 1:1 mapping.
 *
 * The product's BASE UNIT is the UOM whose code matches products.unit
 * (e.g. a product with unit='Pcs' has base unit = the Pcs row in
 * units_of_measure). The base unit always has an implicit factor of 1 — no
 * product_uom_conversions row is required for the self-conversion. Custom
 * conversions (Carton→Pcs etc.) are added per-product by an admin (a
 * management UI is out of scope for Phase 5; the infrastructure + the
 * adjustment flow is the deliverable).
 *
 * References:
 *   - STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §Phase 5
 *   - app/Models/UnitOfMeasure.php
 *   - app/Models/ProductUomConversion.php
 *   - app/Services/Stock/UomConversionService.php
 *   - app/Services/Stock/StockAdjustmentService.php  (validateCreateInput,
 *     createAdjustment, confirmAdjustment)
 *   - app/Http/Controllers/Admin/StockAdjustmentController.php
 *     (getProductUoms AJAX endpoint)
 *   - resources/views/admin/stock-adjustments/{create,show}.blade.php
 */
return new class extends Migration
{
    /**
     * The 6 unit codes from the products.unit CHECK constraint
     * (database/sql/01_auth_and_master.sql). Used to seed units_of_measure
     * so the master list matches exactly what products already reference.
     */
    private const SEED_UNITS = [
        ['code' => 'Pcs',    'name' => 'Pieces',   'type' => 'count'],
        ['code' => 'Carton', 'name' => 'Carton',   'type' => 'count'],
        ['code' => 'KG',     'name' => 'Kilogram', 'type' => 'weight'],
        ['code' => 'Bag',    'name' => 'Bag',      'type' => 'count'],
        ['code' => 'Dobe',   'name' => 'Dobe',     'type' => 'count'],
        ['code' => 'Set',    'name' => 'Set',      'type' => 'count'],
    ];

    public function up(): void
    {
        // ── 1. units_of_measure ──────────────────────────────────────────
        if (!Schema::hasTable('units_of_measure')) {
            Schema::create('units_of_measure', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();   // Pcs, Carton, KG, Bag, Dobe, Set
                $table->string('name', 60);
                $table->string('type', 20);             // count, weight, volume
                $table->timestamps(0);
            });
        }

        // Seed the master list (idempotent — updateOrInsert so re-running is
        // safe; name/type are refreshed, created_at is preserved on update).
        foreach (self::SEED_UNITS as $u) {
            $exists = DB::table('units_of_measure')->where('code', $u['code'])->exists();
            DB::table('units_of_measure')->updateOrInsert(
                ['code' => $u['code']],
                [
                    'name'       => $u['name'],
                    'type'       => $u['type'],
                    'updated_at' => now(),
                    'created_at' => $exists ? DB::raw('created_at') : now(),
                ]
            );
        }

        // ── 2. product_uom_conversions ───────────────────────────────────
        if (!Schema::hasTable('product_uom_conversions')) {
            Schema::create('product_uom_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')
                    ->constrained('products')
                    ->cascadeOnDelete();
                $table->foreignId('from_uom_id')
                    ->constrained('units_of_measure');
                $table->foreignId('to_uom_id')
                    ->constrained('units_of_measure'); // usually the base unit
                $table->decimal('factor', 14, 6);       // 1 from_uom = factor to_uom
                $table->timestamps(0);
                // One conversion per (product, from_uom, to_uom) direction.
                $table->unique(['product_id', 'from_uom_id', 'to_uom_id'], 'puc_product_from_to_unique');
            });
        }

        // ── 3. UOM columns on stock_adjustment_items ─────────────────────
        // All nullable: old rows and non-UOM callers (API, future code paths)
        // keep working. The service treats null uom_id as "base unit, factor 1".
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustment_items', 'uom_id')) {
                $table->foreignId('uom_id')
                    ->nullable()
                    ->after('qty')
                    ->constrained('units_of_measure')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('stock_adjustment_items', 'qty_entered')) {
                $table->decimal('qty_entered', 14, 4)->nullable()->after('uom_id');
            }
            if (!Schema::hasColumn('stock_adjustment_items', 'qty_base')) {
                $table->decimal('qty_base', 14, 4)->nullable()->after('qty_entered');
            }
            if (!Schema::hasColumn('stock_adjustment_items', 'uom_factor')) {
                $table->decimal('uom_factor', 14, 6)->nullable()->after('qty_base');
            }
        });

        // ── 4. Backfill existing items ───────────────────────────────────
        // Pre-Phase-5 quantities were always in the product's base unit (there
        // was no UOM selection). Map each item to its product's base UOM
        // (by matching products.unit → units_of_measure.code) and set
        // qty_entered = qty_base = qty, factor = 1. Rows whose product has a
        // unit not in the seed list (should not happen — CHECK constraint
        // enforces the 6 values) are left null and fall back to the service's
        // "no UOM = base unit" path.
        DB::statement(<<<SQL
UPDATE stock_adjustment_items AS sai
SET uom_id      = u.id,
    qty_entered = sai.qty,
    qty_base    = sai.qty,
    uom_factor  = 1
FROM products AS p
JOIN units_of_measure AS u ON u.code = p.unit
WHERE sai.product_id = p.id
  AND sai.uom_id IS NULL
SQL);
    }

    public function down(): void
    {
        // Drop the UOM columns from stock_adjustment_items (FK first, then
        // the columns — Schema::dropColumn handles the FK drop on PG).
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn(['uom_id', 'qty_entered', 'qty_base', 'uom_factor']);
        });

        // Drop the conversion + unit tables.
        Schema::dropIfExists('product_uom_conversions');
        Schema::dropIfExists('units_of_measure');
    }
};
