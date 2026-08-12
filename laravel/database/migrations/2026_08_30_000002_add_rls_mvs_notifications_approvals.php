<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security/RLS cluster remediation — Sub-problem C (Session 5, revised).
 *
 * Resolves 3 ISSUES_REGISTER rows in the Security/RLS cluster by adding
 * Row-Level-Security policies on:
 *   - 3 notification tables (G-093 / G-179) — same gap, cross-referenced
 *   - 4 generic approval tables (G-188)
 *
 * Follows the canonical RLS pattern established in
 * `2025_01_20_000007_add_rls_branch_isolation.php` (and reused by Session 4's
 * `2026_08_30_000001_add_rls_missing_tables.php`):
 *   - Custom GUC parameters: `app.branch_id` (integer, set by SetAppBranchId
 *     middleware on every authenticated request) and `app.is_admin` (boolean
 *     string 'true'/'false', set by the same middleware).
 *   - `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY`
 *     (FORCE makes even the table owner subject to policies — without it, the
 *     Laravel DB user, which is typically the table owner, would bypass RLS).
 *   - Admin bypass folded into each per-verb policy via
 *     `current_setting('app.is_admin', true) = 'true' OR ({$condition})`.
 *   - Every CREATE POLICY is preceded by DROP POLICY IF EXISTS for idempotency.
 *
 * --------------------------------------------------------------------
 * IMPORTANT — Why G-044 (materialized views) is NOT in this migration
 * --------------------------------------------------------------------
 * The original version of this migration attempted to ENABLE + FORCE RLS on
 * 13 materialized views (mv_ar_aging, mv_ap_aging, mv_stock_valuation, etc.)
 * using `ALTER MATERIALIZED VIEW ... ENABLE ROW LEVEL SECURITY`. This FAILS
 * at runtime with:
 *
 *   SQLSTATE[42809]: Wrong object type: 7 ERROR: ALTER action ENABLE ROW
 *   SECURITY cannot be performed on relation "mv_ar_aging"
 *   DETAIL: This operation is not supported for materialized views.
 *
 * PostgreSQL does NOT support Row Level Security on materialized views —
 * RLS is only supported on tables (and views, but NOT materialized views).
 * MVs store pre-materialized physical rows; RLS policies on the underlying
 * tables do NOT propagate to MVs, and RLS cannot be enabled on the MV itself.
 *
 * G-044 remains OPEN. The correct fix for MV branch isolation is READ-SITE
 * FILTERING: every report controller/service that reads an MV must explicitly
 * filter `WHERE branch_id = ?` (the session branch_id) or join through a
 * session-filtered table. This is a code-level audit + enforcement task, not
 * a DB-level migration. See AI_CONTEXT/reports/materialized-views.md §G1 for
 * the documented approach.
 *
 * Gaps resolved:
 *
 *   G-093 (HIGH, architecture) + G-179 (HIGH, workflows) — same gap,
 *   cross-referenced. NO RLS on 3 notification tables:
 *     - `notifications`: Laravel-standard polymorphic (id, notifiable_id,
 *       notifiable_type, type, data, read_at, timestamps). NO `branch_id`.
 *     - `notification_rules`: admin-managed config (id, name, event, channel,
 *       is_active, times_fired, description, created_by, timestamps). NO
 *       `branch_id`. Route middleware `role:admin`.
 *     - `notification_rule_recipients`: admin-managed pivot (id,
 *       notification_rule_id FK, recipient_type, recipient_user_id, timestamps).
 *       NO `branch_id`.
 *
 *     Policy decisions:
 *       - `notifications` SELECT: admin-only. The user-scoped policy
 *         (`notifiable_id = current_setting('app.user_id', true)::bigint AND
 *         notifiable_type = 'App\\Models\\User'`) would be the correct
 *         long-term fix, BUT the `app.user_id` GUC is NOT set by any
 *         middleware in this codebase (verified by grep — only `app.branch_id`
 *         + `app.is_admin` + `app.request_*` audit-trail GUCs are set by
 *         `SetAppBranchId` / `SetApiBranchContext`). Using a non-existent GUC
 *         would make `current_setting('app.user_id', true)` return NULL, the
 *         `notifiable_id = NULL::bigint` comparison would be NULL (false), and
 *         the policy would block non-admins from reading ANY notification.
 *         Admin-only is the safe interim posture; a future task should add
 *         `app.user_id` to the middleware + replace this policy with the
 *         user-scoped variant. Documented limitation.
 *       - `notifications` INSERT: authenticated-user (`app.branch_id IS NOT
 *         NULL`) — the app creates notifications for users from many non-admin
 *         contexts (e.g. sales_invoice finalization → notify manager).
 *       - `notifications` UPDATE: admin-only. Same `app.user_id` gap as
 *         SELECT — non-admins cannot reliably mark their own notifications
 *         read at the DB layer without a user-id GUC. The Laravel
 *         `Auth::user()->notifications()->update(['read_at' => ...])` PHP
 *         layer scopes by notifiable_id, but the DB-level RLS layer cannot
 *         enforce it without `app.user_id`. Documented limitation.
 *       - `notifications` DELETE: admin-only (purge by admin / pg_cron).
 *       - `notification_rules` + `notification_rule_recipients`:
 *         admin-only for ALL verbs (SELECT/INSERT/UPDATE/DELETE). These are
 *         admin-managed config tables (route middleware `role:admin`); non-admin
 *         users have no business reading or modifying rule config.
 *
 *   G-188 (HIGH, workflows) — NO RLS on 4 generic approval tables.
 *     - `approval_workflows`: has `branch_id` column (declared as STRING,
 *       nullable — null = all branches). This is a known schema bug (G-183,
 *       branch_id should be integer), but the column IS present and usable
 *       for RLS via text comparison (the middleware sets `app.branch_id` as
 *       an integer-castable string like '1', '2'). Policy: `branch_id IS NULL
 *       OR branch_id = current_setting('app.branch_id', true)` + admin bypass.
 *       (null branch_id = all-branches workflow = visible to everyone.)
 *     - `approval_steps`: NO branch_id (inherits from workflow). Admin-only
 *       for ALL verbs — steps are config, rarely read directly by non-admins.
 *     - `approval_requests`: NO branch_id (only `requested_by` user_id).
 *       Admin-only for ALL verbs. The gap text explicitly states "a branch-
 *       scoped RLS policy would need to join via entity_type+entity_id to the
 *       underlying entity table — currently impossible at the DB level."
 *       Polymorphic `entity_id` cannot be FK'd, so a CASE statement per
 *       entity_type would be required for branch scoping — out of scope for
 *       this RLS remediation. Admin-only is the accepted limitation.
 *       Documented.
 *     - `approval_actions`: NO branch_id. Admin-only for ALL verbs (audit
 *       log — append-only by the ApprovalService, reads restricted to admins).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // === G-093 / G-179: 3 notification tables ===
        //
        // notifications: Laravel-standard polymorphic. The user-scoped policy
        // would use app.user_id GUC, but that GUC is NOT set by any
        // middleware (verified by grep on app/Http/Middleware/). Admin-only
        // SELECT/UPDATE/DELETE; INSERT is authenticated-user (app creates
        // notifications for users). Documented limitation in the migration
        // docblock above.
        //
        // notification_rules + notification_rule_recipients: admin-managed
        // config tables. Admin-only for ALL verbs.
        // ============================================================

        // --- notifications ---
        $this->enableRLS('notifications');
        // SELECT: admin-only (app.user_id GUC not set).
        $this->createSelectPolicy('notifications', 'false');
        // INSERT: any authenticated user (app creates notifications from many
        // non-admin contexts — sales_invoice finalize, etc.).
        $this->createInsertPolicy('notifications', "current_setting('app.branch_id', true) IS NOT NULL");
        // UPDATE: admin-only (can't scope by user_id without app.user_id GUC).
        $this->createUpdatePolicy('notifications', 'false');
        // DELETE: admin-only (purge by admin / pg_cron).
        $this->createDeletePolicy('notifications', 'false');
        $this->createAdminBypassPolicy('notifications');

        // --- notification_rules ---
        // Admin-managed config (route middleware role:admin). Admin-only for
        // ALL verbs. Condition = false → admin-only via the bypass folded
        // into each policy.
        $this->enableRLS('notification_rules');
        $this->createSelectPolicy('notification_rules', 'false');
        $this->createInsertPolicy('notification_rules', 'false');
        $this->createUpdatePolicy('notification_rules', 'false');
        $this->createDeletePolicy('notification_rules', 'false');
        $this->createAdminBypassPolicy('notification_rules');

        // --- notification_rule_recipients ---
        // Admin-managed pivot (route middleware role:admin). Admin-only for
        // ALL verbs. Same rationale as notification_rules.
        $this->enableRLS('notification_rule_recipients');
        $this->createSelectPolicy('notification_rule_recipients', 'false');
        $this->createInsertPolicy('notification_rule_recipients', 'false');
        $this->createUpdatePolicy('notification_rule_recipients', 'false');
        $this->createDeletePolicy('notification_rule_recipients', 'false');
        $this->createAdminBypassPolicy('notification_rule_recipients');

        // ============================================================
        // === G-188: 4 generic approval tables ===
        //
        // approval_workflows: branch-scoped via branch_id (string, nullable
        //   — null = all branches). Text comparison works because the
        //   middleware sets app.branch_id as an integer-castable string.
        // approval_steps: NO branch_id (inherits from workflow). Admin-only.
        // approval_requests: NO branch_id (only requested_by user_id).
        //   Admin-only — polymorphic entity_id prevents reliable branch
        //   join at the DB level.
        // approval_actions: NO branch_id. Admin-only (audit log).
        // ============================================================

        // --- approval_workflows ---
        // Schema (2026_08_10_000001:26-40): branch_id is STRING nullable.
        // Policy: admin OR branch_id IS NULL (all-branches workflow) OR
        // branch_id matches the current session's branch_id (text comparison).
        $this->enableRLS('approval_workflows');
        $awCondition = "branch_id IS NULL OR branch_id = current_setting('app.branch_id', true)";
        $this->createSelectPolicy('approval_workflows', $awCondition);
        $this->createInsertPolicy('approval_workflows', $awCondition);
        $this->createUpdatePolicy('approval_workflows', $awCondition);
        $this->createDeletePolicy('approval_workflows', $awCondition);
        $this->createAdminBypassPolicy('approval_workflows');

        // --- approval_steps ---
        // Schema: NO branch_id (inherits from approval_workflows via
        // approval_workflow_id FK). Admin-only for ALL verbs. Could use an
        // EXISTS subquery to approval_workflows.branch_id, but admin-only is
        // simpler + safer for config that's rarely read directly by non-admins.
        $this->enableRLS('approval_steps');
        $this->createSelectPolicy('approval_steps', 'false');
        $this->createInsertPolicy('approval_steps', 'false');
        $this->createUpdatePolicy('approval_steps', 'false');
        $this->createDeletePolicy('approval_steps', 'false');
        $this->createAdminBypassPolicy('approval_steps');

        // --- approval_requests ---
        // Schema: NO branch_id. Only `requested_by` (user_id) + polymorphic
        // (entity_type, entity_id). A branch-scoped RLS would need a CASE
        // statement per entity_type to join to the underlying entity table's
        // branch_id — out of scope. Admin-only for ALL verbs. Documented
        // limitation in the migration docblock above.
        $this->enableRLS('approval_requests');
        $this->createSelectPolicy('approval_requests', 'false');
        $this->createInsertPolicy('approval_requests', 'false');
        $this->createUpdatePolicy('approval_requests', 'false');
        $this->createDeletePolicy('approval_requests', 'false');
        $this->createAdminBypassPolicy('approval_requests');

        // --- approval_actions ---
        // Schema: NO branch_id. Audit log (approval_request_id FK + acted_by
        // user_id + level + action + comments + role_at_time). Admin-only for
        // ALL verbs. Append-only by the ApprovalService at the app layer;
        // admin-only at the DB layer (no non-admin write path).
        $this->enableRLS('approval_actions');
        $this->createSelectPolicy('approval_actions', 'false');
        $this->createInsertPolicy('approval_actions', 'false');
        $this->createUpdatePolicy('approval_actions', 'false');
        $this->createDeletePolicy('approval_actions', 'false');
        $this->createAdminBypassPolicy('approval_actions');
    }

    /**
     * Revert the migration.
     *
     * Drops all RLS policies created by up() and DISABLEs RLS on every
     * table. Does NOT re-create any prior state — none of these tables
     * had RLS before this migration (they were the gap).
     */
    public function down(): void
    {
        // === G-093 / G-179: 3 notification tables ===
        $notifTables = ['notifications', 'notification_rules', 'notification_rule_recipients'];
        foreach ($notifTables as $table) {
            $this->dropAllRlsPolicies($table);
        }

        // === G-188: 4 approval tables ===
        $approvalTables = [
            'approval_workflows',
            'approval_steps',
            'approval_requests',
            'approval_actions',
        ];
        foreach ($approvalTables as $table) {
            $this->dropAllRlsPolicies($table);
        }
    }

    // ============================================================
    // Helper methods — copied verbatim from the canonical migration
    // `2025_01_20_000007_add_rls_branch_isolation.php` (and Session 4's
    // `2026_08_30_000001_add_rls_missing_tables.php`) to ensure this
    // migration follows the exact same RLS pattern (GUC names, policy
    // naming, admin-bypass folding, idempotent DROP IF EXISTS).
    // ============================================================

    /**
     * Enable RLS on a table and force it even for the table owner.
     *
     * FORCE makes the table owner (postgres / the Laravel DB user) also
     * subject to RLS. Without it, the table owner bypasses all policies,
     * making RLS ineffective for the actual application DB connection.
     */
    private function enableRLS(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Create SELECT policy — rows matching the condition are visible.
     *
     * Drops any existing policy with the same name first to keep the
     * migration idempotent.
     */
    private function createSelectPolicy(string $table, string $condition): void
    {
        DB::statement("DROP POLICY IF EXISTS rls_{$table}_select ON {$table}");
        DB::statement(
            "CREATE POLICY rls_{$table}_select ON {$table}
             FOR SELECT
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create INSERT policy — rows matching the condition can be inserted.
     */
    private function createInsertPolicy(string $table, string $condition): void
    {
        DB::statement("DROP POLICY IF EXISTS rls_{$table}_insert ON {$table}");
        DB::statement(
            "CREATE POLICY rls_{$table}_insert ON {$table}
             FOR INSERT
             WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create UPDATE policy — rows matching the condition can be updated.
     * Both USING (existing row) and WITH CHECK (new row) must match.
     */
    private function createUpdatePolicy(string $table, string $condition): void
    {
        DB::statement("DROP POLICY IF EXISTS rls_{$table}_update ON {$table}");
        DB::statement(
            "CREATE POLICY rls_{$table}_update ON {$table}
             FOR UPDATE
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))
             WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create DELETE policy — rows matching the condition can be deleted.
     */
    private function createDeletePolicy(string $table, string $condition): void
    {
        DB::statement("DROP POLICY IF EXISTS rls_{$table}_delete ON {$table}");
        DB::statement(
            "CREATE POLICY rls_{$table}_delete ON {$table}
             FOR DELETE
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create admin bypass policy — admin users see/modify all rows.
     * This is a FOR ALL policy that applies to every operation.
     */
    private function createAdminBypassPolicy(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS rls_{$table}_admin ON {$table}");
        DB::statement(
            "CREATE POLICY rls_{$table}_admin ON {$table}
             FOR ALL
             USING (current_setting('app.is_admin', true) = 'true')
             WITH CHECK (current_setting('app.is_admin', true) = 'true')"
        );
    }

    /**
     * Drop all rls_* policies created by this migration's helpers and
     * DISABLE + NO FORCE RLS on the table. Used by down() for clean revert.
     */
    private function dropAllRlsPolicies(string $table): void
    {
        try {
            DB::statement("DROP POLICY IF EXISTS rls_{$table}_select ON {$table}");
            DB::statement("DROP POLICY IF EXISTS rls_{$table}_insert ON {$table}");
            DB::statement("DROP POLICY IF EXISTS rls_{$table}_update ON {$table}");
            DB::statement("DROP POLICY IF EXISTS rls_{$table}_delete ON {$table}");
            DB::statement("DROP POLICY IF EXISTS rls_{$table}_admin ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        } catch (\Throwable $e) {
            // Table may not exist or policy may not exist — ignore.
        }
    }
};
