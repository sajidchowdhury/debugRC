<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASING-3 — Add confirmed_by / confirmed_at columns to purchase_receives
 * and purchase_returns.
 *
 * Resolves 1 CRITICAL entry:
 *   - G-039 (purchase-return G11): No confirmed_by / confirmed_at columns.
 *     The confirmer's identity is recoverable only via user_audit_log
 *     (partitioned by month — slow join for historical queries). MAJOR
 *     for auditability.
 *
 * Also adds the same columns to purchase_receives for symmetry — the
 * purchase-receive.md G8 gap documents the same issue for GRNs. Although
 * that specific gap isn't tracked as a CRITICAL in ISSUES_REGISTER, the
 * service code already accepts a `$confirmedBy` parameter in
 * `confirmReceive()` but currently only logs it to user_audit_log. Adding
 * the columns to both tables lets the service persist the confirmer
 * identity directly on the row — fast O(1) lookup instead of a slow
 * month-partitioned audit-log join.
 *
 * Column placement:
 *   - confirmed_by integer (nullable — null for draft rows, populated on
 *     confirm). Mirrors created_by / reversed_by column type.
 *   - confirmed_at timestamp(0) (nullable — null for draft rows).
 *     Mirrors reversed_at column type.
 *
 * Both columns are placed AFTER status (logical grouping with the state
 * machine) and BEFORE journal_entry_id (keeps the reversal columns
 * reversed_by/reversed_at visually grouped at the end).
 *
 * No index added: the columns are nullable and the primary access pattern
 * is point lookups by PK (GRN/return detail pages). An index on
 * confirmed_by would only help "show me all GRNs confirmed by user X"
 * queries, which are rare and already served by user_audit_log's
 * (user_id, action) index.
 *
 * Idempotent: uses Schema::hasColumn guards so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        // purchase_receives — confirmed_by / confirmed_at
        if (!Schema::hasColumn('purchase_receives', 'confirmed_by')) {
            Schema::table('purchase_receives', function (Blueprint $table) {
                $table->integer('confirmed_by')
                    ->nullable()
                    ->after('status')
                    ->comment('PURCHASING-3 G-039: user who confirmed this GRN (null for draft)');
                $table->timestamp('confirmed_at', 0)
                    ->nullable()
                    ->after('confirmed_by')
                    ->comment('PURCHASING-3 G-039: when this GRN was confirmed (null for draft)');
            });
        }

        // purchase_returns — confirmed_by / confirmed_at
        if (!Schema::hasColumn('purchase_returns', 'confirmed_by')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->integer('confirmed_by')
                    ->nullable()
                    ->after('status')
                    ->comment('PURCHASING-3 G-039: user who confirmed this return (null for draft)');
                $table->timestamp('confirmed_at', 0)
                    ->nullable()
                    ->after('confirmed_by')
                    ->comment('PURCHASING-3 G-039: when this return was confirmed (null for draft)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_receives', 'confirmed_at')) {
            Schema::table('purchase_receives', function (Blueprint $table) {
                $table->dropColumn(['confirmed_at', 'confirmed_by']);
            });
        }
        if (Schema::hasColumn('purchase_returns', 'confirmed_at')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->dropColumn(['confirmed_at', 'confirmed_by']);
            });
        }
    }
};
