<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.3 — Add is_reversed column to sub-ledger tables.
 *
 * The sub-ledger tables (customer_ledger, supplier_ledger, employee_ledger)
 * need an is_reversed column so that reversals can be tracked (same pattern
 * as journal_entries.is_reversed). When a business transaction is cancelled,
 * the sub-ledger entry is marked is_reversed=true (NOT deleted — audit trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {
            if (!Schema::hasColumn($table, 'is_reversed')) {
                Schema::table($table, function (Blueprint $bp) use ($table) {
                    $bp->boolean('is_reversed')->default(false)->after('balance');
                    $bp->timestamp('reversed_at')->nullable()->after('is_reversed');
                    $bp->integer('reversed_by')->nullable()->after('reversed_at');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {
            if (Schema::hasColumn($table, 'is_reversed')) {
                Schema::table($table, function (Blueprint $bp) {
                    $bp->dropColumn(['is_reversed', 'reversed_at', 'reversed_by']);
                });
            }
        }
    }
};
