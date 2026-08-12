<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES-AUDIT-2 (G-160) — Drop dead `sales_invoice_items.condition_state` column.
 *
 * Resolves:
 *   - G-160 (sales-invoice G8 MAJOR): the `condition_state` column on
 *     `sales_invoice_items` exists but is NEVER used at the invoice layer.
 *     It is always 'Good' — the SalesInvoiceService hardcodes 'Good' on
 *     create (L211) and reads `$item['condition_state'] ?? 'Good'` on
 *     update (L591), but the web edit form submits it as a hidden input
 *     always set to 'Good' (edit.blade.php L80, L357). No invoice ever
 *     has a 'Damage' item — damage is tracked via `damage_invoices` +
 *     `sales_return_items.condition_state` (which IS actively used by
 *     StoreSalesReturnRequest).
 *
 * Approach:
 *   1. Drop the `condition_state` column from `sales_invoice_items`.
 *   2. The column has a CHECK constraint (`condition_state IN ('Good','Damage')`)
 *      — PostgreSQL auto-drops the CHECK when the column is dropped
 *      (no separate DROP CONSTRAINT needed).
 *   3. No data loss — every row has 'Good' (the DEFAULT). The column
 *      carries zero information.
 *
 * Idempotent: Schema::hasColumn guard ensures re-running is a no-op.
 *
 * NOTE: `sales_return_items.condition_state` (04_sales.sql L267) is a
 * DIFFERENT column on a DIFFERENT table — it IS actively used
 * (StoreSalesReturnRequest validates `items.*.condition_state`) and is
 * NOT touched by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_invoice_items', 'condition_state')) {
            DB::statement('ALTER TABLE sales_invoice_items DROP COLUMN condition_state');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sales_invoice_items', 'condition_state')) {
            DB::statement(
                "ALTER TABLE sales_invoice_items " .
                "ADD COLUMN condition_state varchar(10) DEFAULT 'Good' " .
                "CHECK (condition_state IN ('Good','Damage'))"
            );
        }
    }
};
