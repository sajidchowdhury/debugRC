<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shadow Demand Comparisons Table — Phase 10 (Shadow Mode).
 *
 * Stores comparison results between the Laravel Branch Demand system
 * and the legacy BranchIntercompanyService (MySQL). Each row represents
 * a single demand operation that was executed by both systems and
 * compared for consistency.
 *
 * Shadow mode states:
 *   - OFF:     No comparison. Normal operation.
 *   - PASSIVE: Laravel is primary. After each demand operation, the
 *              legacy system also processes the same operation. Results
 *              are compared and logged. Diffs trigger alerts but don't
 *              block operations.
 *   - ACTIVE:  Both systems process every operation simultaneously.
 *              The legacy system's result is the "gold" reference.
 *              If Laravel's result differs, an alert is raised.
 *
 * Cutover readiness: zero diffs for 7 consecutive days.
 *
 * RLS policies mirror the shadow_transfer_comparisons pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_demand_comparisons', function (Blueprint $table) {
            $table->id();

            // The demand operation being compared
            $table->string('operation', 30);        // create|send|confirm_receipt|reverse|settle|reprice
            $table->integer('branch_demand_id')->nullable();
            $table->string('demand_code', 100)->nullable();

            // Branch scope (for RLS filtering)
            $table->integer('from_branch_id')->nullable();
            $table->integer('to_branch_id')->nullable();

            // Comparison result
            $table->string('diff_status', 30);      // match|diff|missing_legacy|missing_laravel|error
            $table->jsonb('diff_details')->nullable(); // Detailed diff breakdown

            // Laravel-side data snapshot
            $table->jsonb('laravel_data')->nullable();   // Laravel demand state after operation

            // Legacy-side data snapshot
            $table->jsonb('legacy_data')->nullable();    // Legacy demand state after operation

            // Metadata
            $table->string('shadow_mode', 10)->default('passive'); // passive|active
            $table->timestamp('compared_at')->default(DB::raw('now()'));
            $table->integer('compared_by')->nullable();   // User who triggered the comparison

            $table->timestamps();

            // Indexes
            $table->index('branch_demand_id');
            $table->index('diff_status');
            $table->index('operation');
            $table->index('from_branch_id');
            $table->index('to_branch_id');
            $table->index('compared_at');
            $table->index(['diff_status', 'compared_at']);
        });

        // RLS on shadow_demand_comparisons — branch-scoped reads
        DB::statement("
            ALTER TABLE shadow_demand_comparisons ENABLE ROW LEVEL SECURITY;
        ");

        // Branch-scoped read policy: users can see comparisons where
        // their branch is either the from_branch or to_branch
        // (No TO clause — applies to the current DB role, matching the project's
        //  existing RLS pattern in add_rls_branch_isolation & branch_demand_audit_log)
        DB::statement("
            CREATE POLICY shadow_demand_comparisons_branch_read
                ON shadow_demand_comparisons FOR SELECT
                USING (
                    from_branch_id = current_setting('app.branch_id', true)::integer
                    OR to_branch_id = current_setting('app.branch_id', true)::integer
                    OR current_setting('app.is_admin', true) = 'true'
                );
        ");

        // Insert allowed for all users (shadow service writes)
        DB::statement("
            CREATE POLICY shadow_demand_comparisons_insert
                ON shadow_demand_comparisons FOR INSERT
                WITH CHECK (true);
        ");

        // No UPDATE or DELETE through RLS (comparisons are immutable)
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS shadow_demand_comparisons_branch_read ON shadow_demand_comparisons");
        DB::statement("DROP POLICY IF EXISTS shadow_demand_comparisons_insert ON shadow_demand_comparisons");
        DB::statement("ALTER TABLE shadow_demand_comparisons DISABLE ROW LEVEL SECURITY");

        Schema::dropIfExists('shadow_demand_comparisons');
    }
};
