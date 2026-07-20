<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P0-3 — Fix sales_returns + sales_return_items schema mismatches.
 *
 * Audit Findings C.3 + C.4:
 *
 * C.3: SalesReturnService::createReturn inserts `cogs_amount` (line 95)
 *      and `reason` (line 98) into sales_returns, but 04_sales.sql has
 *      only `notes` (not `reason`) and no `cogs_amount`.
 *
 * C.4: SalesReturnService, SalesReturnItem model, SalesReturnController,
 *      and create.blade.php all reference `sales_return_items.
 *      sales_invoice_item_id`, but 04_sales.sql does NOT define it.
 *      This column is essential for:
 *        - The returnable-qty cap (validateItems:459-476 queries
 *          `WHERE sri.sales_invoice_item_id = :id`)
 *        - The getInvoiceDetails AJAX endpoint (SalesReturnController:198)
 *        - The original_cost lookup validation
 *
 * Columns added to sales_returns:
 *   cogs_amount numeric(14,2) DEFAULT 0  — total COGS to reverse (snapshot)
 *   reason      text                     — return rationale (distinct from notes)
 *
 * Column added to sales_return_items:
 *   sales_invoice_item_id integer  — FK to sales_invoice_items(id) SET NULL
 *     (nullable so legacy/imported rows without the link still work)
 *
 * Index added:
 *   idx_sri_invoice_item on sales_return_items(sales_invoice_item_id)
 *     — speeds up the returnable-qty lookup
 *
 * Note: `reason` and `notes` coexist. `reason` is the user-supplied
 * rationale for the return; `notes` is for internal/accounting notes.
 * This matches the legacy pattern where both existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- sales_returns ---
        Schema::table('sales_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_returns', 'cogs_amount')) {
                $table->decimal('cogs_amount', 14, 2)->default(0)
                      ->after('total_amount');
            }
            if (!Schema::hasColumn('sales_returns', 'reason')) {
                $table->text('reason')->nullable()->after('notes');
            }
        });

        // --- sales_return_items ---
        Schema::table('sales_return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_return_items', 'sales_invoice_item_id')) {
                $table->integer('sales_invoice_item_id')->nullable()
                      ->after('sales_return_id');
                $table->foreign('sales_invoice_item_id', 'fk_sri_invoice_item')
                      ->references('id')->on('sales_invoice_items')
                      ->onDelete('set null');
            }
        });

        // Index for the returnable-qty lookup query.
        $idxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'sales_return_items' " .
            "AND indexname = 'idx_sri_invoice_item'"
        ))->count();

        if (!$idxExists) {
            DB::statement(
                'CREATE INDEX idx_sri_invoice_item ON sales_return_items (sales_invoice_item_id) ' .
                'WHERE sales_invoice_item_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sri_invoice_item');

        Schema::table('sales_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_return_items', 'sales_invoice_item_id')) {
                $table->dropForeign('fk_sri_invoice_item');
                $table->dropColumn('sales_invoice_item_id');
            }
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $toDrop = array_filter(
                ['cogs_amount', 'reason'],
                fn($col) => Schema::hasColumn('sales_returns', $col)
            );
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
