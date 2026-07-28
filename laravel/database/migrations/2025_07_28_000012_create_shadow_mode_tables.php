<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.3 — Shadow Mode: Transfer comparison tables.
 *
 * Creates two tables:
 *
 *   1. shadow_transfer_comparisons — stores the result of each comparison
 *      between a legacy transfer and its Laravel equivalent. Each row
 *      represents one transfer operation (create/confirm/cancel) compared
 *      across both systems.
 *
 *   2. shadow_cutover_log — tracks daily cutover readiness checks.
 *      Records whether all comparisons on a given day had zero diffs,
 *      enabling the "7 consecutive zero-diff days" cutover criterion.
 *
 * These tables are only written when shadow_mode.enabled = true.
 * When shadow mode is OFF, they remain empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_transfer_comparisons', function (Blueprint $table) {
            $table->id();

            // The Laravel transfer being compared.
            $table->unsignedBigInteger('laravel_transfer_id')->index();
            $table->string('laravel_transfer_code', 30);

            // The legacy transfer being compared (null if not found in legacy).
            $table->unsignedBigInteger('legacy_transfer_id')->nullable();
            $table->string('legacy_transfer_code', 50)->nullable();

            // Operation being compared.
            $table->string('operation', 20); // 'create', 'confirm', 'cancel'

            // Comparison mode at the time of this run.
            $table->string('mode', 10); // 'passive' or 'active'

            // Overall diff status: 'match', 'diff', 'missing_legacy', 'missing_laravel', 'error'
            $table->string('diff_status', 20)->default('match');

            // Detailed diff breakdown (JSON).
            // Structure:
            // {
            //   "stock_movements": {"status": "match", "details": [...]},
            //   "gl_postings": {"status": "match", ...},
            //   "status": {"laravel": "confirmed", "legacy": "confirmed", "match": true},
            //   "avg_cost": {"status": "match", "details": [...]},
            //   "reversal_order": {"status": "match", ...},
            // }
            $table->jsonb('diff_details')->nullable();

            // Summary counts.
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('match_count')->default(0);
            $table->unsignedInteger('diff_count')->default(0);

            // Branch context.
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // Timestamps.
            $table->timestamp('compared_at')->useCurrent();
            $table->timestamps();

            // Indexes for dashboard queries.
            $table->index(['diff_status', 'compared_at']);
            $table->index(['operation', 'diff_status']);
            $table->index(['branch_id', 'compared_at']);
        });

        Schema::create('shadow_cutover_log', function (Blueprint $table) {
            $table->id();

            // The date being checked.
            $table->date('check_date')->unique();

            // Number of comparisons run on this date.
            $table->unsignedInteger('comparisons_total')->default(0);

            // Number with zero diffs.
            $table->unsignedInteger('comparisons_match')->default(0);

            // Number with diffs.
            $table->unsignedInteger('comparisons_diff')->default(0);

            // Number of missing legacy data.
            $table->unsignedInteger('comparisons_missing_legacy')->default(0);

            // Number of errors.
            $table->unsignedInteger('comparisons_error')->default(0);

            // Is this day "clean" (zero diffs across all comparisons)?
            $table->boolean('is_clean_day')->default(false);

            // Consecutive clean days up to and including this date.
            $table->unsignedInteger('consecutive_clean_days')->default(0);

            // Is cutover ready (consecutive_clean_days >= threshold)?
            $table->boolean('cutover_ready')->default(false);

            // Who ran the check.
            $table->unsignedBigInteger('checked_by')->nullable();

            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_cutover_log');
        Schema::dropIfExists('shadow_transfer_comparisons');
    }
};
