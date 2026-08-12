<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASING-API-2 (G-116): ApprovalService integration for Purchase Orders.
 *
 * Mirrors the pattern established by `2026_08_10_000001_create_approval_workflow_engine.php`
 * for `manual_journals`: expands the `purchase_orders.status` CHECK to include
 * the 3 approval states, adds the 7 approval audit columns, and seeds a
 * default `purchase_order` workflow + step so the generic ApprovalService
 * engine can drive PO maker-checker.
 *
 * Lifecycle after this migration:
 *   draft → submitted → approved → sent → partial → received → cancelled
 *               └── rejected (must resubmit)
 *
 *   - draft:     created but not yet sent for approval nor to supplier
 *   - submitted: pending approval (an approval_requests row exists)
 *   - approved:  approved (auto or via workflow) — can now be marked sent
 *   - rejected:  approver declined — must edit + resubmit
 *   - sent:      sent to supplier, awaiting delivery
 *   - partial:   some items received via GRN
 *   - received:  all items fully received
 *   - cancelled: cancelled (only pre-receive states can be cancelled)
 *
 * Auto-approve: if no workflow applies (total_amount < min_amount) the PO
 * stays in `draft` and can be marked sent directly — backward-compatible
 * with the pre-approval flow.
 *
 * The seeded `min_amount` default is 50000 BDT (env-overridable via
 * PURCHASE_APPROVAL_THRESHOLD). After seeding, admins can tune the
 * threshold at runtime via /admin/approvals/workflows/{id} (the DB row
 * is the live source of truth; the env value only affects the INITIAL seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Expand purchase_orders.status CHECK ───────────────────────
        // Drop the existing CHECK (whatever its auto-generated name) and
        // re-add with the 3 new states. Idempotent: if the constraint already
        // includes 'submitted', the DROP finds the old one and we re-add the
        // expanded one (a no-op shape-wise if it already matches).
        $constraint = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'purchase_orders'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%draft%'
              AND pg_get_constraintdef(oid) NOT LIKE '%submitted%'
        ");
        if ($constraint) {
            DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT {$constraint->conname}");
            DB::statement("
                ALTER TABLE purchase_orders
                ADD CONSTRAINT purchase_orders_status_check
                CHECK (status IN ('draft','submitted','approved','rejected','sent','partial','received','cancelled'))
            ");
        }

        // ── 2. Add the 7 approval audit columns ──────────────────────────
        // Mirrors the manual_journals layout from 2026_08_10_000001 L110-121.
        // All nullable — null for rows that have never been submitted.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by')->nullable()->after('created_by');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_at');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_comments')->nullable()->after('approved_at');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approval_comments');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');

            // Index for the approval queue filter (status='submitted' + submitted_at ordering).
            $table->index('status');
            $table->index(['status', 'submitted_at'], 'idx_po_submitted');
        });

        // ── 3. Seed default approval workflow for purchase_orders ─────────
        // min_amount default: 50000 BDT (env-overridable for the INITIAL seed
        // only — after this migration runs, the DB row is the source of truth
        // and admins tune it via /admin/approvals/workflows/{id}).
        $threshold = (float) config('purchase.approval_threshold', 50000);

        $existing = DB::table('approval_workflows')
            ->where('entity_type', 'purchase_order')
            ->whereNull('branch_id')
            ->exists();

        if (!$existing) {
            DB::table('approval_workflows')->insert([
                'name' => 'Purchase Order Approval',
                'entity_type' => 'purchase_order',
                'min_amount' => $threshold,
                'is_active' => true,
                'requires_approval_levels' => 1,
                'branch_id' => null,
                'description' => "Default approval workflow for purchase orders. POs with total_amount >= {$threshold} require manager approval before they can be marked sent to the supplier. POs below the threshold are auto-approved (no approval needed). Adjust the threshold at runtime via /admin/approvals/workflows.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $workflowId = DB::getPdo()->lastInsertId();

            DB::table('approval_steps')->insert([
                'approval_workflow_id' => $workflowId,
                'level' => 1,
                'role' => 'manager',
                'is_parallel' => false,
                'description' => 'First-level approval by manager. Any manager (except the submitter) can approve. Segregation-of-duties is enforced by ApprovalRequest::canBeActedBy().',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove seeded workflow + steps (only the purchase_order ones).
        $workflowIds = DB::table('approval_workflows')
            ->where('entity_type', 'purchase_order')
            ->pluck('id');

        if ($workflowIds->isNotEmpty()) {
            DB::table('approval_steps')
                ->whereIn('approval_workflow_id', $workflowIds)
                ->delete();
            DB::table('approval_workflows')
                ->where('entity_type', 'purchase_order')
                ->delete();
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('idx_po_submitted');
            $table->dropIndex('status');
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'approved_by', 'approved_at', 'approval_comments',
                'rejected_by', 'rejected_at',
            ]);
        });

        // Restore original CHECK constraint (5 states, no approval states).
        $constraint = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'purchase_orders'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%submitted%'
        ");
        if ($constraint) {
            DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT {$constraint->conname}");
            DB::statement("
                ALTER TABLE purchase_orders
                ADD CONSTRAINT purchase_orders_status_check
                CHECK (status IN ('draft','sent','partial','received','cancelled'))
            ");
        }
    }
};
