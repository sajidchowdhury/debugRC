<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALES-AUDIT-1 — Add confirmed_by / confirmed_at columns to sales_returns.
 *
 * Resolves:
 *   - G-170 (sales-return G16 MAJOR): sales_returns has NO confirmed_at /
 *     confirmed_by columns. The confirmer's identity is recoverable only
 *     via user_audit_log (partitioned by month — slow join for historical
 *     queries). The printSlip controller method explicitly works around
 *     this by querying user_audit_log for action='return_confirmed'.
 *
 * Mirrors the pattern established by migration
 * 2026_09_03_000003_add_confirmed_by_at_to_purchase_tables.php (PURCHASING-3
 * G-039) for purchase_receives + purchase_returns. Same column types,
 * same placement (after status), same nullable semantics (null for
 * 'created' rows, populated on confirm).
 *
 * Column placement:
 *   - confirmed_by integer (nullable — null for draft rows, populated on
 *     confirm). Mirrors created_by / reversed_by column type. No FK to
 *     users(id) — mirrors the existing pattern (created_by, reversed_by
 *     are also bare integers; the audit trigger captures the snapshot).
 *   - confirmed_at timestamp(0) (nullable — null for draft rows).
 *     Mirrors reversed_at column type.
 *
 * Both columns are placed AFTER status (logical grouping with the state
 * machine) and BEFORE journal_entry_id (keeps the reversal columns
 * reversed_by/reversed_at visually grouped at the end).
 *
 * No index added: the columns are nullable and the primary access pattern
 * is point lookups by PK (return detail pages). An index on confirmed_by
 * would only help "show me all returns confirmed by user X" queries,
 * which are rare and already served by user_audit_log's (user_id, action)
 * index.
 *
 * Idempotent: uses Schema::hasColumn guards so re-running is a no-op.
 *
 * Followup (in SalesReturnService::confirmReturn): the UPDATE statement
 * at L236-243 now also sets confirmed_by + confirmed_at. The printSlip
 * controller method at Admin/SalesReturnController.php L145-159 keeps
 * the user_audit_log fallback for pre-migration rows (confirmed before
 * this column existed) and prefers the direct columns for new confirms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales_returns', 'confirmed_by')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->integer('confirmed_by')
                    ->nullable()
                    ->after('status')
                    ->comment('SALES-AUDIT-1 G-170: user who confirmed this return (null for draft)');
                $table->timestamp('confirmed_at', 0)
                    ->nullable()
                    ->after('confirmed_by')
                    ->comment('SALES-AUDIT-1 G-170: when this return was confirmed (null for draft)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_returns', 'confirmed_at')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->dropColumn(['confirmed_at', 'confirmed_by']);
            });
        }
    }
};
