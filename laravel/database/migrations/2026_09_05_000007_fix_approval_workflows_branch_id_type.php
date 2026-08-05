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
 *   2. ALTER COLUMN TYPE integer USING branch_id::integer — Postgres
 *      validates every row during the cast (fails fast on bad data, but
 *      the backfill guard already NULLed bad rows).
 *   3. Add FK branch_id → branches(id) ON DELETE CASCADE. A branch
 *      deletion cascades to its branch-specific workflows (the global
 *      workflows with branch_id=NULL are unaffected).
 *   4. The existing unique constraint uq_workflow_entity_branch
 *      (entity_type, branch_id, deleted_at) continues to work — integer
 *      columns participate in unique constraints identically to strings.
 *
 * Idempotent: checks the current column type via information_schema
 * before altering. Re-running is a no-op once the column is integer.
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

        // 3. Drop the existing unique constraint (will recreate after type change).
        //    The constraint name is uq_workflow_entity_branch (from the original migration).
        $uqExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'uq_workflow_entity_branch')
            ->where('table_name', 'approval_workflows')
            ->exists();

        if ($uqExists) {
            DB::statement('ALTER TABLE approval_workflows DROP CONSTRAINT uq_workflow_entity_branch');
        }

        // 4. Alter column type: string → integer USING branch_id::integer.
        //    PostgreSQL validates every row during the cast. Nullable stays nullable.
        DB::statement('ALTER TABLE approval_workflows ALTER COLUMN branch_id TYPE integer USING branch_id::integer');

        // 5. Recreate the unique constraint (integer column participates identically).
        //    NOTE: the original constraint used a partial unique on deleted_at (soft deletes).
        //    Recreate it as a unique on (entity_type, branch_id, deleted_at) — same shape.
        DB::statement(
            'ALTER TABLE approval_workflows ADD CONSTRAINT uq_workflow_entity_branch ' .
            'UNIQUE (entity_type, branch_id, deleted_at)'
        );

        // 6. Add FK branch_id → branches(id) ON DELETE CASCADE.
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

        // Revert column type to string (the original buggy type).
        DB::statement("ALTER TABLE approval_workflows ALTER COLUMN branch_id TYPE varchar(255) USING branch_id::text");

        // Recreate the unique constraint (string column).
        DB::statement(
            'ALTER TABLE approval_workflows ADD CONSTRAINT uq_workflow_entity_branch ' .
            'UNIQUE (entity_type, branch_id, deleted_at)'
        );
    }
};
