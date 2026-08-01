<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3A — Add collected_by column to customer_payments.
 *
 * The legacy system tracks who collected the cash (e.g., salesman,
 * cashier). This field references the employees table, matching the
 * supplier_payments.collected_by column that already exists.
 *
 * Idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_payments', 'collected_by')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->integer('collected_by')->nullable()->after('bank_id');
            });

            // Add FK constraint separately (PG requires integer type match).
            // The employees table uses integer PK (GENERATED ALWAYS AS IDENTITY).
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_payments', 'collected_by')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->dropColumn('collected_by');
            });
        }
    }
};
