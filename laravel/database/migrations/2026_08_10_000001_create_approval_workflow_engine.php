<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: Approval Workflow Engine
 *
 * Creates a generic, configurable multi-level approval system that can be
 * applied to any entity type (manual_journals, stock_adjustments, etc.).
 *
 * Design:
 *   - approval_workflows: defines which entity types require approval and at what thresholds
 *   - approval_steps: defines the approval levels (sequential or parallel) within each workflow
 *   - approval_requests: tracks the current state of an approval request for an entity
 *   - approval_actions: audit log of every approve/reject action taken
 *   - Also adds approval columns to manual_journals and expands its status CHECK
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. approval_workflows ────────────────────────────────────────
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                    // e.g. "Manual Journal Approval"
            $table->string('entity_type', 50);              // e.g. "manual_journal", "stock_adjustment"
            $table->unsignedDecimal('min_amount', 15, 2)->default(0); // Only require approval above this amount
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('requires_approval_levels')->default(1); // How many levels
            $table->string('branch_id')->nullable();        // null = all branches
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_type', 'branch_id', 'deleted_at'], 'uq_workflow_entity_branch');
            $table->index(['entity_type', 'is_active']);
        });

        // ── 2. approval_steps ────────────────────────────────────────────
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');           // 1 = first approval level, 2 = second, etc.
            $table->string('role', 50);                      // e.g. "manager", "admin"
            $table->boolean('is_parallel')->default(false);  // If true, ALL users with this role must approve
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'level'], 'uq_step_workflow_level');
        });

        // ── 3. approval_requests ─────────────────────────────────────────
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);              // e.g. "manual_journal"
            $table->unsignedBigInteger('entity_id');          // e.g. manual_journal.id
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('current_level')->default(1);
            $table->string('status', 20)->default('pending') // pending, approved, rejected, cancelled
                  ->check("status IN ('pending','approved','rejected','cancelled')");
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'idx_ar_entity');
            $table->index(['status', 'current_level'], 'idx_ar_status_level');
            $table->index('requested_by');
        });

        // ── 4. approval_actions ──────────────────────────────────────────
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');           // Which approval level this action was for
            $table->string('action', 20);                    // approved, rejected, commented
            $table->unsignedBigInteger('acted_by');
            $table->timestamp('acted_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->text('comments')->nullable();
            $table->string('role_at_time', 50)->nullable();  // Role of the actor at the time of action

            $table->index(['approval_request_id', 'level'], 'idx_aa_request_level');
            $table->index('acted_by');
        });

        // ── 5. Add approval columns to manual_journals ───────────────────
        // Drop existing CHECK constraint and add expanded one
        $constraint = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'manual_journals'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%draft%'
        ");
        if ($constraint) {
            DB::statement("ALTER TABLE manual_journals DROP CONSTRAINT {$constraint->conname}");
        }
        DB::statement("
            ALTER TABLE manual_journals
            ADD CONSTRAINT manual_journals_status_check
            CHECK (status IN ('draft','submitted','approved','posted','reversed','rejected'))
        ");

        Schema::table('manual_journals', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by')->nullable()->after('reversed_by');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_at');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_comments')->nullable()->after('approved_at');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approval_comments');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');

            $table->index('status');
            $table->index(['status', 'submitted_at'], 'idx_mj_submitted');
        });

        // ── 6. Seed default approval workflow for manual journals ─────────
        DB::table('approval_workflows')->insert([
            'name' => 'Manual Journal Approval',
            'entity_type' => 'manual_journal',
            'min_amount' => 0,
            'is_active' => true,
            'requires_approval_levels' => 1,
            'branch_id' => null,
            'description' => 'Default approval workflow for manual journal entries. All journals above min_amount require manager approval before posting.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workflowId = DB::getPdo()->lastInsertId();

        DB::table('approval_steps')->insert([
            'approval_workflow_id' => $workflowId,
            'level' => 1,
            'role' => 'manager',
            'is_parallel' => false,
            'description' => 'First-level approval by manager. Any manager can approve.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add a second level for admin (optional, for high-value journals)
        DB::table('approval_steps')->insert([
            'approval_workflow_id' => $workflowId,
            'level' => 2,
            'role' => 'admin',
            'is_parallel' => false,
            'description' => 'Second-level approval by admin. Only required for high-value journals.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── 7. Add notification events ───────────────────────────────────
        // These will be picked up by the NotificationService
        // (NotificationRule::EVENTS is a constant, so we add via config/seeder)
    }

    public function down(): void
    {
        Schema::table('manual_journals', function (Blueprint $table) {
            $table->dropIndex('idx_mj_submitted');
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'approved_by', 'approved_at', 'approval_comments',
                'rejected_by', 'rejected_at',
            ]);
        });

        // Restore original CHECK constraint
        $constraint = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'manual_journals'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%draft%'
        ");
        if ($constraint) {
            DB::statement("ALTER TABLE manual_journals DROP CONSTRAINT {$constraint->conname}");
        }
        DB::statement("
            ALTER TABLE manual_journals
            ADD CONSTRAINT manual_journals_status_check
            CHECK (status IN ('draft','posted','reversed'))
        ");

        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
