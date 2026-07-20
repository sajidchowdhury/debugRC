<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 — avg_cost_drift + warehouse_stock_shadow tables.
 *
 * Two tables for the replay verification infrastructure:
 *
 * 1. avg_cost_drift — logs every (warehouse_id, product_id) where the
 *    replay's computed qty or avg_cost diverges from the live
 *    warehouse_stock by more than the tolerance. Each drift row captures
 *    the live vs shadow values + the last transaction that touched the
 *    product (for investigation).
 *
 * 2. warehouse_stock_shadow — a persistent copy of the replay result,
 *    so the accountant can inspect it side-by-side with the live table
 *    after running `php artisan stock:replay-verify`. The replay command
 *    truncates + repopulates this table on each run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. avg_cost_drift — drift log (investigation table)
        // ============================================================
        if (!Schema::hasTable('avg_cost_drift')) {
            Schema::create('avg_cost_drift', function (Blueprint $table) {
                $table->id();
                $table->integer('warehouse_id');
                $table->integer('product_id');
                $table->decimal('live_qty', 14, 4);
                $table->decimal('shadow_qty', 14, 4);
                $table->decimal('qty_drift', 14, 4);
                $table->decimal('live_avg_cost', 12, 2);
                $table->decimal('shadow_avg_cost', 12, 2);
                $table->decimal('cost_drift', 12, 2);
                $table->integer('last_transaction_id')->nullable();
                $table->string('last_reference_type', 30)->nullable();
                $table->integer('last_reference_id')->nullable();
                $table->text('investigation_notes')->nullable();
                $table->string('status', 20)->default('open'); // open, investigated, resolved
                $table->timestamp('detected_at')->useCurrent();
                $table->timestamp('resolved_at')->nullable();

                $table->index(['warehouse_id', 'product_id']);
                $table->index('status');
                $table->index('detected_at');
            });
        }

        // ============================================================
        // 2. warehouse_stock_shadow — replay result snapshot
        // ============================================================
        if (!Schema::hasTable('warehouse_stock_shadow')) {
            Schema::create('warehouse_stock_shadow', function (Blueprint $table) {
                $table->integer('warehouse_id');
                $table->integer('product_id');
                $table->decimal('qty', 14, 4)->default(0);
                $table->decimal('avg_cost', 12, 2)->default(0);
                $table->integer('transaction_count')->default(0);
                $table->integer('last_transaction_id')->nullable();
                $table->timestamp('replayed_at')->useCurrent();

                $table->primary(['warehouse_id', 'product_id']);
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_shadow');
        Schema::dropIfExists('avg_cost_drift');
    }
};
