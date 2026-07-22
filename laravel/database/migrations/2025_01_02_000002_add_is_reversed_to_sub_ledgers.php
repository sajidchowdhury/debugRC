<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.3 — Add reversal tracking to sub-ledger tables.
 *
 * Adds:
 *  - is_reversed
 *  - reversed_at
 *  - reversed_by
 *
 * These columns allow ledger entries to be reversed without deleting them,
 * preserving a complete audit trail.
 *
 * IMPORTANT:
 * The customer_ledger covering index (idx_cl_balance_covering) depends on
 * customer_ledger.is_reversed. Therefore the index is created here instead
 * of in the baseline schema migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {

            if (!Schema::hasColumn($table, 'is_reversed')) {

                Schema::table($table, function (Blueprint $table) {

                    $table->boolean('is_reversed')
                        ->default(false)
                        ->after('balance');

                    $table->timestamp('reversed_at')
                        ->nullable()
                        ->after('is_reversed');

                    $table->unsignedBigInteger('reversed_by')
                        ->nullable()
                        ->after('reversed_at');
                });
            }
        }

        // Covering index for customer balance queries.
        // This index depends on the is_reversed column added above.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
            ON customer_ledger (customer_id, is_reversed)
            INCLUDE (debit, credit)
        ");
    }

    public function down(): void
    {
        DB::statement("
            DROP INDEX IF EXISTS idx_cl_balance_covering
        ");

        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {

            if (Schema::hasColumn($table, 'is_reversed')) {

                Schema::table($table, function (Blueprint $table) {

                    $table->dropColumn([
                        'is_reversed',
                        'reversed_at',
                        'reversed_by',
                    ]);
                });
            }
        }
    }
};