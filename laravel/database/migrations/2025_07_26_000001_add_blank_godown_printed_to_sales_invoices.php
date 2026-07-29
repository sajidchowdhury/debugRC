<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3-step godown workflow — track "blank godown copy printed" state.
 *
 * The warehouse manager's real-world workflow is:
 *   1. Print a BLANK godown copy (handwriting picking sheet) — REQUIRES a
 *      dispatcher to be selected first. The dispatcher carries the blank
 *      sheet to the warehouse floor, fills in warehouse/CTN by hand, then
 *      returns to the system.
 *   2. Create the godown copy (product-wise warehouse assignment + stock
 *      check + transport cost edit) — only possible AFTER the blank copy
 *      has been printed.
 *   3. Create the challan copy (stock OUT + COGS) — only possible AFTER
 *      the godown copy has been created.
 *
 * Steps 2 and 3 were already enforced (is_godown_prepared gates challan
 * issue). Step 1 was missing entirely — the print-blank-godown route was
 * a stateless read-only render with no dispatcher requirement and no
 * downstream gating.
 *
 * This migration adds the three columns that record Step 1's completion:
 *   - is_blank_godown_printed   boolean, default false
 *   - blank_godown_printed_at   timestamp, null until first print
 *   - blank_godown_printed_by   integer (users.id), null until first print
 *
 * Backward compatibility: existing invoices (is_godown_prepared=true from
 * before this migration) are NOT blocked — the controller/service guards
 * use `!is_blank_godown_printed && !is_godown_prepared` so legacy rows
 * sail through. Only NEW invoices (draft, not yet godown-prepared) are
 * forced through the print-blank-godown-first gate.
 *
 * Idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales_invoices', 'is_blank_godown_printed')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->boolean('is_blank_godown_printed')->default(false)
                      ->after('is_godown_prepared');
            });
        }

        if (!Schema::hasColumn('sales_invoices', 'blank_godown_printed_at')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->timestamp('blank_godown_printed_at')->nullable()
                      ->after('is_blank_godown_printed');
            });
        }

        if (!Schema::hasColumn('sales_invoices', 'blank_godown_printed_by')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->integer('blank_godown_printed_by')->nullable()
                      ->after('blank_godown_printed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $toDrop = array_filter(
                ['is_blank_godown_printed', 'blank_godown_printed_at', 'blank_godown_printed_by'],
                fn($col) => Schema::hasColumn('sales_invoices', $col)
            );
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
