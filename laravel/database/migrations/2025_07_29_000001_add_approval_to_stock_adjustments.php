<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (Stock Adjustment plan) — Approval Workflow & Maker-Checker.
 *
 * Inserts a configurable approval gate between drafting and posting:
 *
 *   draft → submitted → approved → confirmed
 *                ↑    │
 *                └────┘ (reject → back to draft)
 *
 * Segregation of duties is enforced at the service layer:
 *   - The accountant who submits (submitted_by) CANNOT approve their own
 *     adjustment — approveAdjustment() throws if approved_by === submitted_by.
 *   - Only admin/manager roles may approve (configurable).
 *
 * The approval gate is configurable via config/stock_adjustment.php:
 *   - require_approval              (bool)    — gate on/off
 *   - auto_approve_below_value      (numeric) — skip gate below this value
 *   - approver_roles                (array)   — roles allowed to approve
 *   - submitter_roles               (array)   — roles allowed to submit
 *   - max_value_without_secondary_approval (numeric) — force-approve ≥ this value
 *   - block_closed_period           (bool)    — reject back-dated into closed period
 *
 * Schema changes:
 *   1. Drop & re-add the status CHECK on stock_adjustments to allow
 *      'submitted', 'approved', and 'rejected' (the three new states).
 *   2. ADD COLUMN submitted_by, submitted_at, approved_by, approved_at,
 *      approval_comments on stock_adjustments (the maker-checker trail).
 *   3. ADD COLUMN confirmed_by, confirmed_at, confirm_reason (G9 — the
 *      posting action was previously unattributed; confirm_reason was
 *      accepted by the controller but discarded).
 *   4. ADD COLUMN cancel_reason (G15 — draft cancels silently discarded
 *      the cancel_reason; now every cancel — draft or confirmed — stores it).
 *   5. CREATE INDEX idx_sa_submitted (partial) — powers the "awaiting my
 *      approval" worklist query for approvers.
 *
 * NOTE: `reverse_reason` is kept as-is. It records why a CONFIRMED
 * adjustment's stock+GL was reversed (set on confirmed-cancel). The new
 * `cancel_reason` records why the adjustment was cancelled (set on EVERY
 * cancel — draft or confirmed). For a confirmed-cancel both are populated
 * from the same input; for a draft-cancel only cancel_reason is set.
 *
 * References:
 *   - STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §Phase 3
 *   - app/Services/Stock/StockAdjustmentService.php  (submit/approve/reject)
 *   - app/Services/Stock/StockAdjustmentPolicyService.php  (config reads)
 *   - app/Http/Controllers/Admin/StockAdjustmentController.php  (routes)
 *   - config/stock_adjustment.php  (policy knobs)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Expand the status CHECK constraint ───────────────────────
        // The original inline CHECK (from 03_stock.sql) was auto-named
        // `stock_adjustments_status_check` by PostgreSQL. Drop & re-add with
        // the expanded allow-list covering the three new approval states.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE stock_adjustments "
            . "ADD CONSTRAINT stock_adjustments_status_check "
            . "CHECK (status IN ('draft','submitted','approved','confirmed','cancelled','rejected'))"
        );

        // ── 2. Approval-workflow columns ────────────────────────────────
        // Plain `integer` for the *_by columns (no FK) — mirrors the existing
        // reversed_by / created_by pattern on this table and avoids FK-deferral
        // complications during user deletion. The application layer resolves
        // the integer to a User model via the show-page display.
        Schema::table('stock_adjustments', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustments', 'submitted_by')) {
                $table->integer('submitted_by')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('stock_adjustments', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            }
            if (!Schema::hasColumn('stock_adjustments', 'approved_by')) {
                $table->integer('approved_by')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('stock_adjustments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('stock_adjustments', 'approval_comments')) {
                $table->text('approval_comments')->nullable()->after('approved_at');
            }

            // G9 — attribute the posting action (confirm) to a user + timestamp
            // and persist the optional confirm_reason the controller already
            // collects but was discarding.
            if (!Schema::hasColumn('stock_adjustments', 'confirmed_by')) {
                $table->integer('confirmed_by')->nullable()->after('approval_comments');
            }
            if (!Schema::hasColumn('stock_adjustments', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            }
            if (!Schema::hasColumn('stock_adjustments', 'confirm_reason')) {
                $table->text('confirm_reason')->nullable()->after('confirmed_at');
            }

            // G15 — every cancel (draft OR confirmed) stores a reason. Previously
            // only confirmed cancels stored the reason (in reverse_reason); draft
            // cancels silently discarded it. Placed after reverse_reason for
            // logical grouping with the other reversal/cancel columns.
            if (!Schema::hasColumn('stock_adjustments', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('reverse_reason');
            }
        });

        // ── 3. Partial index: submitted adjustments ─────────────────────
        // Powers the "awaiting my approval" worklist query for approvers.
        // Only indexes rows in the 'submitted' state (small, hot subset).
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'stock_adjustments' AND indexname = 'idx_sa_submitted'"
        ))->count()) {
            DB::statement(
                "CREATE INDEX idx_sa_submitted ON stock_adjustments (branch_id, submitted_at) "
                . "WHERE status = 'submitted'"
            );
        }
    }

    public function down(): void
    {
        // Drop the submitted-state partial index.
        DB::statement('DROP INDEX IF EXISTS idx_sa_submitted');

        // Drop the approval-workflow + attribution columns.
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'approved_by', 'approved_at', 'approval_comments',
                'confirmed_by', 'confirmed_at', 'confirm_reason',
                'cancel_reason',
            ]);
        });

        // Restore the original (Phase 0/6.3) status CHECK.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE stock_adjustments "
            . "ADD CONSTRAINT stock_adjustments_status_check "
            . "CHECK (status IN ('draft','confirmed','cancelled'))"
        );
    }

    /**
     * Drop the status CHECK constraint regardless of its current name.
     * PostgreSQL auto-names an inline CHECK as `{table}_{column}_check`,
     * but a prior migration may have created it with a custom name. We
     * introspect pg_constraint to find it reliably (mirrors the
     * add_approval_workflow_to_stock_take_sessions migration pattern).
     */
    private function dropStatusCheckConstraint(): void
    {
        $constraints = DB::select(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'stock_adjustments'::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) ILIKE '%status IN (%'"
        );
        foreach ($constraints as $c) {
            DB::statement('ALTER TABLE stock_adjustments DROP CONSTRAINT IF EXISTS ' . $c->conname);
        }
        // Fallback: drop the canonical auto-name if it still exists.
        DB::statement('ALTER TABLE stock_adjustments DROP CONSTRAINT IF EXISTS stock_adjustments_status_check');
    }
};
