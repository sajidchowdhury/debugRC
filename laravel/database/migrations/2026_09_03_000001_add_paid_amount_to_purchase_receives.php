<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASING-1 — Add paid_amount column to purchase_receives.
 *
 * Resolves 2 CRITICAL entries:
 *   - G-024 (purchase-audit G1): paid_amount column missing on purchase_receives.
 *     SupplierTransactionService::allocateToGRN throws at runtime, so the
 *     payment-allocation audit trail is never built.
 *   - G-025 (purchase-receive G1): purchase_receives.paid_amount column
 *     referenced but never created. SQLSTATE[42703] Undefined column thrown
 *     by allocateToGRN (L570-575) and reversePayment (L265-271) on every
 *     supplier payment allocated against a GRN.
 *
 * Why numeric(14,2) DEFAULT 0:
 *   Mirrors sales_invoices.paid_amount (see 2025_01_20_000000_add_generated_columns.php
 *   and 2025_01_21_000004_set_up_table_partitioning.php L750). The supplier-
 *     payment-against-GRN workflow accumulates allocations incrementally:
 *
 *       allocateToGRN  → paid_amount = paid_amount + allocatedAmount
 *       reversePayment → paid_amount = GREATEST(0, paid_amount - settled_amount)
 *
 *   Default 0 is required so existing rows (created before this migration)
 *   do not have NULL — DB::raw('paid_amount + N') would yield NULL otherwise.
 *
 * Index:
 *   idx_pr_paid — partial index WHERE paid_amount > 0. Lets the audit checklist
 *   "partially-paid GRNs" query skip fully-unpaid rows cheaply. Mirrors the
 *   idx_si_paid pattern in 04_sales.sql.
 *
 * Idempotent: uses Schema::hasColumn guard so re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_receives', 'paid_amount')) {
            return;
        }

        Schema::table('purchase_receives', function (Blueprint $table) {
            // Place after total_amount for logical column ordering (matches
            // sales_invoices layout: total_amount → paid_amount → due_amount).
            // decimal() emits NUMERIC(14,2) on PostgreSQL — same storage as
            // sales_invoices.paid_amount (see 2025_01_20_000000).
            $table->decimal('paid_amount', 14, 2)
                ->default(0)
                ->after('total_amount')
                ->comment('Accumulated supplier-payment allocations (G-024/G-025)');
        });

        // Partial index — only rows with allocations. Cheap to maintain, cheap
        // to scan for the audit checklist's "partially-paid GRNs" view.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_pr_paid '
            . 'ON purchase_receives (paid_amount) '
            . 'WHERE paid_amount > 0'
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_receives', 'paid_amount')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_pr_paid');
        Schema::table('purchase_receives', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
