<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P1-5 — Add linking columns for sales return ↔ damage invoice linkage.
 *
 * Legacy migration 039 (039_sales_return_damage_link.sql) added:
 *   - sales_return_items.damage_invoice_id (links return line → damage invoice)
 *   - damage_invoices.sales_return_id (links damage invoice → sales return)
 *
 * These enable the "linked damage write-off" feature: when a sales return
 * has 'Damage' condition items, a damage_invoices row is auto-created to
 * write off the damaged goods (stock OUT + GL Dr damage_loss / Cr inventory).
 *
 * ALSO fixes a pre-existing schema gap: damage_invoices was missing the
 * `total_value` and `status` columns that DamageService::createDamage and
 * the DamageInvoice model already reference. Without these, DamageService
 * would crash on createDamage — blocking P1-5.
 *
 * This migration is idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- damage_invoices: add missing columns + sales_return_id link ---

        // total_value — referenced by DamageService::createDamage:101 + model fillable,
        // but missing from 03_stock.sql schema.
        if (!Schema::hasColumn('damage_invoices', 'total_value')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->decimal('total_value', 14, 2)->default(0)->after('branch_id');
            });
        }

        // status — referenced by DamageService (draft/confirmed/cancelled) + model,
        // but missing from 03_stock.sql schema.
        if (!Schema::hasColumn('damage_invoices', 'status')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->string('status', 20)->default('draft')
                      ->check('status IN (\'draft\', \'confirmed\', \'cancelled\')')
                      ->after('total_value');
            });
        }

        // sales_return_id — links damage invoice back to the sales return
        // that triggered it (P1-5 linkage, legacy migration 039).
        if (!Schema::hasColumn('damage_invoices', 'sales_return_id')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->integer('sales_return_id')->nullable()
                      ->after('warehouse_id');

                $table->foreign('sales_return_id', 'fk_dmg_sales_return')
                      ->references('id')->on('sales_returns')
                      ->onDelete('set null');
            });

            DB::statement(
                'CREATE INDEX idx_dmg_sales_return ON damage_invoices (sales_return_id) ' .
                'WHERE sales_return_id IS NOT NULL'
            );
        }

        // --- sales_return_items: add damage_invoice_id link ---

        if (!Schema::hasColumn('sales_return_items', 'damage_invoice_id')) {
            Schema::table('sales_return_items', function (Blueprint $table) {
                $table->integer('damage_invoice_id')->nullable()
                      ->after('original_cost');

                $table->foreign('damage_invoice_id', 'fk_sri_damage_invoice')
                      ->references('id')->on('damage_invoices')
                      ->onDelete('set null');
            });

            DB::statement(
                'CREATE INDEX idx_sri_damage_invoice ON sales_return_items (damage_invoice_id) ' .
                'WHERE damage_invoice_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_dmg_sales_return');
        DB::statement('DROP INDEX IF EXISTS idx_sri_damage_invoice');

        Schema::table('damage_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('damage_invoices', 'sales_return_id')) {
                $table->dropForeign('fk_dmg_sales_return');
                $table->dropColumn('sales_return_id');
            }
            if (Schema::hasColumn('damage_invoices', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('damage_invoices', 'total_value')) {
                $table->dropColumn('total_value');
            }
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_return_items', 'damage_invoice_id')) {
                $table->dropForeign('fk_sri_damage_invoice');
                $table->dropColumn('damage_invoice_id');
            }
        });
    }
};
