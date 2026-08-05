<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOWS-AUDIT-1 — Fix approval_workflows.branch_id type + add FK.
 *
 * Resolves:
 *   - G-183 (approval-workflow G8 MAJOR): approval_workflows.branch_id
 *     declared as string, not integer. Migration L33 (original
 *     2026_08_10_000001_create_approval_workflow_engine.php) had
 *     `$table->string('branch_id')->nullable()` — clearly a typo. Should
 *     be unsignedInteger (or foreignId). `findApplicable` does
 *     `->where('branch_id', $branchId)` where `$branchId` is `?int` —
 *     Postgres implicit-casts, but no FK enforcement and index efficiency
 *     is degraded (text comparison vs integer).
 *
 * Approach:
 *   1. Backfill guard — cast existing string branch_id values to integer
 *      (NULL stays NULL; numeric strings like '3' → 3). Any non-numeric
 *      string is logged + NULLed (rare — would only happen if a buggy
 *      seed wrote a non-integer string).
 *   2. Drop the 5 RLS policies on approval_workflows (created by migration
 *      2026_08_30_000002_add_rls_mvs_notifications_approvals.php). All 5
 *      policies reference branch_id via text comparison. PostgreSQL REFUSES
 *      ALTER COLUMN TYPE on a column referenced by a policy definition
 *      (SQLSTATE 0A000: "cannot alter type of a column used in a policy
 *      definition"). The policies must be dropped first + recreated in
 *      step 8 with an integer-cast condition.
 *   3. Drop the existing unique constraint uq_workflow_entity_branch
 *      (recreated after the type change — integer columns participate in
 *      unique constraints identically to strings).
 *   4. ALTER COLUMN TYPE integer USING branch_id::integer — Postgres
 *      validates every row during the cast (fails fast on bad data, but
 *      the backfill guard already NULLed bad rows).
 *   5. Recreate the unique constraint (same shape: entity_type, branch_id,
 *      deleted_at).
 *   6. Add FK branch_id → branches(id) ON DELETE CASCADE. A branch
 *      deletion cascades to its branch-specific workflows (the global
 *      workflows with branch_id=NULL are unaffected).
 *   7. (covered by step 2 drop) — no-op.
 *   8. Recreate the 5 RLS policies with the integer-cast condition
 *      `branch_id = current_setting('app.branch_id', true)::integer`
 *      (was text comparison `branch_id = current_setting('app.branch_id', true)`
 *      when branch_id was varchar — integer = text has no implicit cast
 *      operator in Postgres, so the cast is mandatory). Matches the
 *      canonical pattern used by shadow_demand_comparisons +
 *      employee_transactions RLS policies. Admin bypass + NULL branch_id
 *      (all-branches workflow) semantics unchanged.
 *
 * Idempotent: checks the current column type via information_schema
 * before altering. Re-running is a no-op once the column is integer.
 * Policy DROP IF EXISTS + CREATE are also idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Check current column type — skip if already integer.
        $colType = DB::table('information_schema.columns')
            ->where('table_name', 'approval_workflows')
            ->where('column_name', 'branch_id')
            ->value('data_type');

        if ($colType === 'integer') {
            // Already migrated — nothing to do.
            return;
        }

        if ($colType === null) {
            // Table or column missing — nothing to do (the create migration
            // hasn't run yet; this migration will be re-run after it does).
            return;
        }

        // 2. Backfill guard — NULL out any non-numeric string branch_id
        //    values so the ALTER COLUMN TYPE doesn't fail.
        //    A numeric string like '3' will cast cleanly; a non-numeric
        //    string like 'HQ' would cause a runtime error during ALTER.
        $nonNumeric = DB::table('approval_workflows')
            ->whereNotNull('branch_id')
            ->whereRaw("branch_id !~ '^[0-9]+$'")
            ->select(['id', 'name', 'branch_id'])
            ->get();

        if ($nonNumeric->isNotEmpty()) {
            $badIds = $nonNumeric->pluck('id')->all();
            DB::table('approval_workflows')
                ->whereIn('id', $badIds)
                ->update(['branch_id' => null]);

            // Best-effort audit log — table may not exist in test envs.
            try {
                DB::table('migrations_audit_log')->insert([
                    'migration'  => '2026_09_05_000007_fix_approval_workflows_branch_id_type',
                    'action'     => 'backfill_non_numeric_branch_id',
                    'details'    => json_encode([
                        'bad_count' => $nonNumeric->count(),
                        'bad_ids'   => $badIds,
                        'bad_values' => $nonNumeric->pluck('branch_id')->all(),
                    ]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Table missing — skip the audit row. The data fix itself
                // is already committed; this is just bookkeeping.
            }
        }

        // 3. Drop the 5 RLS policies on approval_workflows BEFORE altering
        //    the column type. PostgreSQL refuses ALTER COLUMN TYPE on a
        //    column referenced by a policy definition (SQLSTATE 0A000:
        //    "cannot alter type of a column used in a policy definition").
        //    The policies were created by migration
        //    2026_08_30_000002_add_rls_mvs_notifications_approvals.php and
        //    all reference branch_id via text comparison. They are
        //    recreated in step 8 below with an integer-cast condition.
        $this->dropApprovalWorkflowsRlsPolicies();

        // 4. Drop the existing unique constraint (will recreate after type change).
        //    The constraint name is uq_workflow_entity_branch (from the original migration).
        $uqExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'uq_workflow_entity_branch')
            ->where('table_name', 'approval_workflows')
            ->exists();

        if ($uqExists) {
            DB::statement('ALTER TABLE approval_workflows DROP CONSTRAINT uq_workflow_entity_branch');
        }

        // 5. Alter column type: string → integer USING branch_id::integer.
        //    PostgreSQL validates every row during the cast. Nullable stays nullable.
        //    (Safe now — no RLS policies or unique constraints reference branch_id.)
        DB::statement('ALTER TABLE approval_workflows ALTER COLUMN branch_id TYPE integer USING branch_id::integer');

        // 6. Recreate the unique constraint (integer column participates identically).
        //    NOTE: the original constraint used a partial unique on deleted_at (soft deletes).
        //    Recreate it as a unique on (entity_type, branch_id, deleted_at) — same shape.
        DB::statement(
            'ALTER TABLE approval_workflows ADD CONSTRAINT uq_workflow_entity_branch ' .
            'UNIQUE (entity_type, branch_id, deleted_at)'
        );

        // 7. Add FK branch_id → branches(id) ON DELETE CASCADE.
        //    A branch deletion cascades to its branch-specific workflows;
        //    global workflows (branch_id=NULL) are unaffected by the FK
        //    (NULL never matches).
        $fkExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'approval_workflows_branch_id_foreign')
            ->where('table_name', 'approval_workflows')
            ->exists();

        if (!$fkExists) {
            DB::statement(
                'ALTER TABLE approval_workflows ' .
                'ADD CONSTRAINT approval_workflows_branch_id_foreign ' .
                'FOREIGN KEY (branch_id) REFERENCES branches(id) ' .
                'ON DELETE CASCADE'
            );
        }

        // 8. Recreate the 5 RLS policies with the integer-cast condition.
        //    The original policies used text comparison
        //    (branch_id = current_setting('app.branch_id', true)) which
        //    worked when branch_id was varchar. Now that branch_id is
        //    integer, the comparison MUST cast the GUC text value to
        //    integer — integer = text has no implicit cast operator in
        //    Postgres, so without the cast every non-admin query on
        //    approval_workflows would error. Matches the canonical
        //    pattern used by shadow_demand_comparisons +
        //    employee_transactions RLS policies. NULL branch_id
        //    (all-branches workflow) still visible to everyone; admin
        //    bypass folded into each per-verb policy.
        $awConditionInt = "branch_id IS NULL OR branch_id = current_setting('app.branch_id', true)::integer";
        $this->createApprovalWorkflowsRlsPolicies($awConditionInt);
    }

    public function down(): void
    {
        // Drop the FK first.
        $fkExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'approval_workflows_branch_id_foreign')
            ->where('table_name', 'approval_workflows')
            ->exists();
        if ($fkExists) {
            DB::statement('ALTER TABLE approval_workflows DROP CONSTRAINT IF EXISTS approval_workflows_branch_id_foreign');
        }

        // Drop the integer unique constraint.
        $uqExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'uq_workflow_entity_branch')
            ->where('table_name', 'approval_workflows')
            ->exists();
        if ($uqExists) {
            DB::statement('ALTER TABLE approval_workflows DROP CONSTRAINT IF EXISTS uq_workflow_entity_branch');
        }

        // Drop the 5 RLS policies (integer-cast versions) BEFORE reverting
        // the column type to varchar — the same SQLSTATE 0A000 constraint
        // applies in reverse (Postgres refuses ALTER TYPE on a column
        // referenced by a policy, regardless of direction).
        $this->dropApprovalWorkflowsRlsPolicies();

        // Revert column type to string (the original buggy type).
        DB::statement("ALTER TABLE approval_workflows ALTER COLUMN branch_id TYPE varchar(255) USING branch_id::text");

        // Recreate the unique constraint (string column).
        DB::statement(
            'ALTER TABLE approval_workflows ADD CONSTRAINT uq_workflow_entity_branch ' .
            'UNIQUE (entity_type, branch_id, deleted_at)'
        );

        // Recreate the 5 RLS policies with the ORIGINAL text-comparison
        // condition (matches what migration 2026_08_30_000002 originally
        // created for the varchar column).
        $awConditionText = "branch_id IS NULL OR branch_id = current_setting('app.branch_id', true)";
        $this->createApprovalWorkflowsRlsPolicies($awConditionText);
    }

    // ============================================================
    // Helper methods — drop + recreate the 5 RLS policies on
    // approval_workflows. The policies were originally created by
    // migration 2026_08_30_000002_add_rls_mvs_notifications_approvals.php
    // using the canonical RLS pattern (GUC app.branch_id + app.is_admin,
    // per-verb policies + admin-bypass FOR ALL, DROP IF EXISTS for
    // idempotency). We mirror that exact pattern here, parameterised by
    // the branch-condition string so up() passes the integer-cast
    // version and down() passes the text-comparison version.
    // ============================================================

    /**
     * Drop the 5 RLS policies on approval_workflows. Idempotent
     * (DROP POLICY IF EXISTS). Must be called BEFORE altering
     * approval_workflows.branch_id column type in EITHER direction —
     * PostgreSQL refuses ALTER COLUMN TYPE on a column referenced by a
     * policy definition (SQLSTATE 0A000).
     */
    private function dropApprovalWorkflowsRlsPolicies(): void
    {
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_select ON approval_workflows');
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_insert ON approval_workflows');
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_update ON approval_workflows');
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_delete ON approval_workflows');
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_admin ON approval_workflows');
    }

    /**
     * Recreate the 5 RLS policies on approval_workflows. Mirrors the
     * exact policy names + structure from 2026_08_30_000002 but with a
     * type-correct condition passed by the caller:
     *   - up():   integer-cast  (branch_id = current_setting('app.branch_id', true)::integer)
     *   - down(): text-compare  (branch_id = current_setting('app.branch_id', true))
     *
     * Admin bypass is folded into each per-verb policy via
     * `current_setting('app.is_admin', true) = 'true' OR ({$condition})`,
     * matching the canonical RLS pattern. Each CREATE is preceded by
     * DROP IF EXISTS for idempotency.
     */
    private function createApprovalWorkflowsRlsPolicies(string $branchCondition): void
    {
        // SELECT
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_select ON approval_workflows');
        DB::statement(
            "CREATE POLICY rls_approval_workflows_select ON approval_workflows " .
            "FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR ({$branchCondition}))"
        );

        // INSERT
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_insert ON approval_workflows');
        DB::statement(
            "CREATE POLICY rls_approval_workflows_insert ON approval_workflows " .
            "FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$branchCondition}))"
        );

        // UPDATE (USING for existing row + WITH CHECK for new row)
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_update ON approval_workflows');
        DB::statement(
            "CREATE POLICY rls_approval_workflows_update ON approval_workflows " .
            "FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR ({$branchCondition})) " .
            "WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$branchCondition}))"
        );

        // DELETE
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_delete ON approval_workflows');
        DB::statement(
            "CREATE POLICY rls_approval_workflows_delete ON approval_workflows " .
            "FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR ({$branchCondition}))"
        );

        // Admin bypass (FOR ALL)
        DB::statement('DROP POLICY IF EXISTS rls_approval_workflows_admin ON approval_workflows');
        DB::statement(
            "CREATE POLICY rls_approval_workflows_admin ON approval_workflows " .
            "FOR ALL USING (current_setting('app.is_admin', true) = 'true') " .
            "WITH CHECK (current_setting('app.is_admin', true) = 'true')"
        );
    }
};
