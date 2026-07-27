<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Stock Take plan) — Approval workflow & segregation of duties.
 *
 * Inserts a configurable approval gate between counting and posting:
 *
 *   draft → counting → submitted → approved → posted
 *                       ↑   │
 *                       └───┘ (reject → back to counting)
 *
 * Segregation of duties is enforced at the service layer:
 *   - The counter who submits (submitted_by) CANNOT approve their own count.
 *   - approve() throws if approved_by === submitted_by.
 *
 * The approval gate is configurable via the new `stock_take_policies` table:
 *   - stock_take.require_approval        (bool)    — gate on/off
 *   - stock_take.auto_approve_below_value(numeric) — skip gate when |gain|+|loss|
 *                                                     value is below this threshold
 *   - stock_take.approver_roles          (jsonb)   — roles allowed to approve
 *   - stock_take.variance_threshold_block (numeric) — force approval even when
 *                                                      require_approval=false if
 *                                                      total variance value ≥ this
 *
 * NOTE on `system_policies`: the existing `system_policies` table is a single-
 * active-row mode table (NORMAL/INVESTIGATION/...) — NOT a generic key/value
 * config store. Reusing it for stock-take config would conflate two unrelated
 * concerns, so Phase 4 introduces a dedicated `stock_take_policies` table.
 * The Phase 4 plan text names `system_policies` as the config home; this
 * implementation honours the INTENT (runtime-configurable, audit-friendly,
 * admin-editable) using a purpose-built table instead. The .MD plan is
 * updated to reflect this clarification.
 *
 * Schema changes:
 *   1. Drop & re-add the status CHECK on stock_take_sessions to allow
 *      'submitted' and 'approved' (the two new active states) plus
 *      'reversed' (reserved for Phase 10's reversal-vs-cancel distinction;
 *      harmless to allow now, forward-compatible).
 *   2. ADD COLUMN submitted_by, submitted_at, approved_by, approved_at,
 *      approval_comments on stock_take_sessions.
 *   3. CREATE TABLE stock_take_policies (key/value config with jsonb value).
 *      Seeded with the four Phase 4 defaults.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §Phase 4
 *   - app/Services/Stock/StockTakeService.php  (submit/approve/reject)
 *   - app/Services/Stock/StockTakePolicyService.php  (config reads)
 *   - app/Http/Controllers/Admin/StockTakeController.php  (routes)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Expand the status CHECK constraint ───────────────────────
        // The original inline CHECK (from 03_stock.sql) was auto-named
        // `stock_take_sessions_status_check` by PostgreSQL. Drop & re-add
        // with the expanded allow-list. Allow 'reversed' now (reserved for
        // Phase 10) so a later migration doesn't need to touch the CHECK
        // again.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE stock_take_sessions "
            . "ADD CONSTRAINT stock_take_sessions_status_check "
            . "CHECK (status IN ('draft','counting','submitted','approved','posted','cancelled','reversed'))"
        );

        // ── 2. Approval-workflow columns on stock_take_sessions ─────────
        // Plain `integer` for the *_by columns (no FK) — mirrors the
        // existing reversed_by / created_by pattern on this table and avoids
        // FK-deferral complications during user deletion. The application
        // layer resolves the integer to a User model via the audit log /
        // show-page join.
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_take_sessions', 'submitted_by')) {
                $table->integer('submitted_by')->nullable()->after('reverse_reason');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'approved_by')) {
                $table->integer('approved_by')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'approval_comments')) {
                $table->text('approval_comments')->nullable()->after('approved_at');
            }
        });

        // Partial index: only submitted sessions — powers the "awaiting my
        // approval" worklist query for approvers.
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'stock_take_sessions' AND indexname = 'idx_sts_submitted'"
        ))->count()) {
            DB::statement(
                "CREATE INDEX idx_sts_submitted ON stock_take_sessions (branch_id, submitted_at) "
                . "WHERE status = 'submitted'"
            );
        }

        // ── 3. stock_take_policies — runtime-configurable knobs ─────────
        // Lightweight key/value table: one row per policy key. The value is
        // jsonb so a single column can carry bool / numeric / string / array
        // (approver_roles is a jsonb array of role strings). The
        // StockTakePolicyService caches all rows in memory for 5 min.
        if (!Schema::hasTable('stock_take_policies')) {
            Schema::create('stock_take_policies', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80);
                $table->jsonb('value');
                $table->text('description')->nullable();
                $table->integer('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique('key', 'stock_take_policies_key_unique');
            });
        }

        // Seed the four Phase 4 defaults. INSERT ... ON CONFLICT DO NOTHING
        // so re-running the migration (e.g. on a re-deploy) is idempotent.
        $now = now()->toDateTimeString();
        $defaults = [
            [
                'key'         => 'stock_take.require_approval',
                'value'       => json_encode(false),
                'description' => 'When true, counting sessions must be submitted and approved before they can be posted.',
            ],
            [
                'key'         => 'stock_take.auto_approve_below_value',
                'value'       => json_encode(0),
                'description' => 'When require_approval=true, sessions whose total |gain|+|loss| value is strictly below this threshold are auto-approved inline at post time (actor = system). 0 disables auto-approval.',
            ],
            [
                'key'         => 'stock_take.approver_roles',
                'value'       => json_encode(['admin', 'manager']),
                'description' => 'Roles permitted to approve a submitted stock-take session. Order is irrelevant. Default: admin, manager.',
            ],
            [
                'key'         => 'stock_take.variance_threshold_block',
                'value'       => json_encode(0),
                'description' => 'When require_approval=false, sessions whose total |gain|+|loss| value is ≥ this threshold are STILL forced through approval. 0 disables the threshold (no force-approve).',
            ],
        ];
        foreach ($defaults as $d) {
            DB::table('stock_take_policies')->updateOrInsert(
                ['key' => $d['key']],
                [
                    'value'       => $d['value'],
                    'description' => $d['description'],
                    'updated_at'  => $now,
                    'created_at'  => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Drop the policies table (also drops the seeded defaults).
        Schema::dropIfExists('stock_take_policies');

        // Drop the submitted-session partial index.
        DB::statement('DROP INDEX IF EXISTS idx_sts_submitted');

        // Drop the approval-workflow columns.
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'approved_by', 'approved_at', 'approval_comments',
            ]);
        });

        // Restore the original (Phase 0/3) status CHECK.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE stock_take_sessions "
            . "ADD CONSTRAINT stock_take_sessions_status_check "
            . "CHECK (status IN ('draft','counting','posted','cancelled'))"
        );
    }

    /**
     * Drop the status CHECK constraint regardless of its current name.
     * PostgreSQL auto-names an inline CHECK as `{table}_{column}_check`,
     * but a prior migration may have created it with a custom name. We
     * introspect pg_constraint to find it reliably.
     */
    private function dropStatusCheckConstraint(): void
    {
        $constraints = DB::select(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'stock_take_sessions'::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) ILIKE '%status IN (%'"
        );
        foreach ($constraints as $c) {
            DB::statement('ALTER TABLE stock_take_sessions DROP CONSTRAINT IF EXISTS ' . $c->conname);
        }
        // Fallback: drop the canonical auto-name if it still exists.
        DB::statement('ALTER TABLE stock_take_sessions DROP CONSTRAINT IF EXISTS stock_take_sessions_status_check');
    }
};
