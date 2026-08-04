<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security/RLS cluster remediation — Sub-problem C (Session 4).
 *
 * Resolves 5 ISSUES_REGISTER rows in the Security/RLS cluster by adding or
 * fixing per-verb Row-Level-Security policies on ~12 tables that were either
 * missing RLS entirely, had an inconsistent GUC key, or had an over-restrictive
 * admin-only policy that blocked legitimate non-admin users.
 *
 * Follows the canonical RLS pattern established in
 * `2025_01_20_000007_add_rls_branch_isolation.php`:
 *   - Custom GUC parameters: `app.branch_id` (integer, set by SetAppBranchId
 *     middleware on every authenticated request) and `app.is_admin` (boolean
 *     string 'true'/'false', set by the same middleware).
 *   - `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY`
 *     (FORCE makes even the table owner subject to policies — without it, the
 *     Laravel DB user, which is typically the table owner, would bypass RLS).
 *   - Per-verb policies: `rls_{table}_select` (FOR SELECT USING),
 *     `rls_{table}_insert` (FOR INSERT WITH CHECK),
 *     `rls_{table}_update` (FOR UPDATE USING + WITH CHECK),
 *     `rls_{table}_delete` (FOR DELETE USING).
 *   - Admin bypass folded into each per-verb policy via
 *     `current_setting('app.is_admin', true) = 'true' OR ({$condition})`.
 *   - Every CREATE POLICY is preceded by DROP POLICY IF EXISTS for idempotency.
 *
 * Gaps resolved:
 *
 *   G-022 (CRITICAL, finance) — NO RLS on 5 branch_demand-related tables:
 *     `branch_demand_items`, `branch_demand_repricing`,
 *     `branch_demand_customer_payment_settlements`,
 *     `branch_demand_money_transfer_settlements`, `shadow_cutover_log`.
 *     The first 4 have a FK to `branch_demands` (column name is
 *     `branch_demand_id` on items+repricing, `demand_id` on the two settlement
 *     tables) but NO direct branch column → use a correlated EXISTS subquery to
 *     `branch_demands` mirroring the dual-branch condition
 *     (`from_branch_id` OR `to_branch_id`). `shadow_cutover_log` has NO branch
 *     column at all (it is a daily cutover-readiness diagnostic table) → uses
 *     an admin-only policy (condition = `false`), acceptable per the task spec
 *     for operational diagnostic tables.
 *
 *   G-015 (CRITICAL, finance) — RLS admin-only on 4 consolidation tables:
 *     `companies`, `consolidation_runs`, `elimination_entries`,
 *     `elimination_rules`. The original migration
 *     (`2026_08_11_000001_create_intercompany_and_consolidation.php`) created a
 *     single `{table}_admin_policy` (FOR ALL, admin-only) per table. The route
 *     middleware is `role:accountant,manager,admin`, so accountants/managers
 *     SHOULD have access but were blocked. These are corporate-level tables
 *     (NO `branch_id` column), so the correct policy is "any authenticated
 *     branch user" — `app.is_admin = 'true' OR app.branch_id IS NOT NULL`. RLS
 *     here only blocks unauthenticated/direct-SQL access; the route middleware
 *     handles role differentiation. The old `{table}_admin_policy` is DROPPED.
 *
 *   G-095 (HIGH, finance) — Fixed-assets 3 tables admin-only RLS:
 *     `fixed_assets`, `asset_depreciation_schedules`, `asset_disposals`.
 *     The original migration (`2026_08_13_000001_create_fixed_assets.php`)
 *     created a single `{table}_admin_policy` (admin-only) per table. Route
 *     middleware is `role:accountant,manager,admin`. `fixed_assets` has a
 *     `branch_id` column → single-branch condition. The two child tables have
 *     only `fixed_asset_id` (no branch_id) → correlated EXISTS subquery to
 *     `fixed_assets`. The old `{table}_admin_policy` is DROPPED.
 *
 *   G-174 (HIGH, security) — NO RLS on `system_policies`. The table had NO RLS
 *     at all, allowing direct DB-level modification of the active policy,
 *     bypassing `SystemPolicyService::activate()/deactivate()` (which writes
 *     audit log + dispatches event + invalidates cache). The route middleware
 *     is `role:superadmin` (G-173, resolved in Session 1), so admin-only RLS
 *     is the CORRECT behavior here (unlike G-015/G-095 where it was a bug).
 *     Per-verb policies with condition = `false` → admin-only via the bypass
 *     folded into each policy.
 *
 *   G-347 (HIGH, finance) — Inconsistent GUC key in `branch_demand_audit_log`
 *     RLS. The `bdal_branch_read` SELECT policy used
 *     `current_setting('app.current_branch_id')::bigint` but the canonical GUC
 *     name is `app.branch_id` (set by SetAppBranchId middleware). The mismatch
 *     meant the policy NEVER matched non-admin users (the GUC
 *     `app.current_branch_id` is never set), so non-admins saw zero audit rows.
 *     Fix: DROP the old `bdal_branch_read` and recreate with
 *     `branch_id = current_setting('app.branch_id')::int`. Also added FORCE
 *     ROW LEVEL SECURITY (the original migration only did ENABLE, not FORCE —
 *     without FORCE the table owner bypasses RLS, making the policy
 *     ineffective for the actual app DB user). The other 4 policies
 *     (`bdal_admin_read`, `bdal_insert`, `bdal_no_update`, `bdal_no_delete`)
 *     are left unchanged — they are correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // === G-022: branch_demand child tables ===
        // 5 tables. The first 4 use a correlated EXISTS subquery to
        // branch_demands (dual-branch: from_branch_id OR to_branch_id).
        // shadow_cutover_log has no branch column → admin-only (false).
        // ============================================================

        // --- branch_demand_items ---
        // Schema (03_stock.sql:732 + 2026_07_29_000011_align): has
        // branch_demand_id FK to branch_demands(id), NO branch columns.
        $this->enableRLS('branch_demand_items');
        $bdiCondition = "EXISTS (
            SELECT 1 FROM branch_demands bd
            WHERE bd.id = branch_demand_id
              AND (
                bd.from_branch_id = current_setting('app.branch_id')::int
                OR bd.to_branch_id = current_setting('app.branch_id')::int
              )
        )";
        $this->createSelectPolicy('branch_demand_items', $bdiCondition);
        $this->createInsertPolicy('branch_demand_items', $bdiCondition);
        $this->createUpdatePolicy('branch_demand_items', $bdiCondition);
        $this->createDeletePolicy('branch_demand_items', $bdiCondition);
        $this->createAdminBypassPolicy('branch_demand_items');

        // --- branch_demand_repricing ---
        // Schema (2026_07_29_000016): has branch_demand_id FK to
        // branch_demands(id), NO branch columns. Append-only (has only
        // created_at, no updated_at) → UPDATE/DELETE use USING(false) to
        // enforce immutability at the DB layer.
        $this->enableRLS('branch_demand_repricing');
        $bdrCondition = "EXISTS (
            SELECT 1 FROM branch_demands bd
            WHERE bd.id = branch_demand_id
              AND (
                bd.from_branch_id = current_setting('app.branch_id')::int
                OR bd.to_branch_id = current_setting('app.branch_id')::int
              )
        )";
        $this->createSelectPolicy('branch_demand_repricing', $bdrCondition);
        $this->createInsertPolicy('branch_demand_repricing', $bdrCondition);
        // Append-only: block UPDATE/DELETE for non-admins (admin bypass still
        // applies via the helper's folded `app.is_admin` check).
        $this->createUpdatePolicy('branch_demand_repricing', 'false');
        $this->createDeletePolicy('branch_demand_repricing', 'false');
        $this->createAdminBypassPolicy('branch_demand_repricing');

        // --- branch_demand_customer_payment_settlements ---
        // Schema (2026_07_29_000015): column is `demand_id` (NOT
        // `branch_demand_id`), FK to branch_demands(id). Append-only.
        $this->enableRLS('branch_demand_customer_payment_settlements');
        $bdcpsCondition = "EXISTS (
            SELECT 1 FROM branch_demands bd
            WHERE bd.id = demand_id
              AND (
                bd.from_branch_id = current_setting('app.branch_id')::int
                OR bd.to_branch_id = current_setting('app.branch_id')::int
              )
        )";
        $this->createSelectPolicy('branch_demand_customer_payment_settlements', $bdcpsCondition);
        $this->createInsertPolicy('branch_demand_customer_payment_settlements', $bdcpsCondition);
        $this->createUpdatePolicy('branch_demand_customer_payment_settlements', 'false');
        $this->createDeletePolicy('branch_demand_customer_payment_settlements', 'false');
        $this->createAdminBypassPolicy('branch_demand_customer_payment_settlements');

        // --- branch_demand_money_transfer_settlements ---
        // Schema (2026_07_29_000014): column is `demand_id` (NOT
        // `branch_demand_id`), FK to branch_demands(id). Append-only.
        $this->enableRLS('branch_demand_money_transfer_settlements');
        $bdmtsCondition = "EXISTS (
            SELECT 1 FROM branch_demands bd
            WHERE bd.id = demand_id
              AND (
                bd.from_branch_id = current_setting('app.branch_id')::int
                OR bd.to_branch_id = current_setting('app.branch_id')::int
              )
        )";
        $this->createSelectPolicy('branch_demand_money_transfer_settlements', $bdmtsCondition);
        $this->createInsertPolicy('branch_demand_money_transfer_settlements', $bdmtsCondition);
        $this->createUpdatePolicy('branch_demand_money_transfer_settlements', 'false');
        $this->createDeletePolicy('branch_demand_money_transfer_settlements', 'false');
        $this->createAdminBypassPolicy('branch_demand_money_transfer_settlements');

        // --- shadow_cutover_log ---
        // Schema (2025_07_28_000012): NO branch column at all (daily
        // cutover-readiness diagnostic: check_date, comparisons_*,
        // is_clean_day, cutover_ready, checked_by). Admin-only is the
        // correct policy — this is an operational diagnostic table per the
        // task spec. Condition = false → admin-only via the bypass.
        $this->enableRLS('shadow_cutover_log');
        $this->createSelectPolicy('shadow_cutover_log', 'false');
        $this->createInsertPolicy('shadow_cutover_log', 'false');
        $this->createUpdatePolicy('shadow_cutover_log', 'false');
        $this->createDeletePolicy('shadow_cutover_log', 'false');
        $this->createAdminBypassPolicy('shadow_cutover_log');

        // ============================================================
        // === G-015: consolidation corporate-level tables ===
        // 4 tables: companies, consolidation_runs, elimination_entries,
        // elimination_rules. NO branch_id column (corporate-level). The
        // original migration created a single `{table}_admin_policy`
        // (admin-only) per table. We DROP that and replace with per-verb
        // policies whose condition is "any authenticated branch user"
        // (app.is_admin OR app.branch_id IS NOT NULL). RLS here blocks
        // unauthenticated/direct-SQL access only; route middleware handles
        // role differentiation (role:accountant,manager,admin).
        // ============================================================

        // Authenticated-user condition: admin OR any non-NULL app.branch_id
        // (SetAppBranchId middleware sets app.branch_id on every authenticated
        // request, so non-NULL == authenticated).
        $authCondition = "current_setting('app.branch_id', true) IS NOT NULL";

        foreach (['companies', 'consolidation_runs', 'elimination_entries', 'elimination_rules'] as $tbl) {
            // Drop the old admin-only policy created by
            // 2026_08_11_000001_create_intercompany_and_consolidation.php.
            DB::statement("DROP POLICY IF EXISTS {$tbl}_admin_policy ON {$tbl}");

            $this->enableRLS($tbl);
            $this->createSelectPolicy($tbl, $authCondition);
            $this->createInsertPolicy($tbl, $authCondition);
            $this->createUpdatePolicy($tbl, $authCondition);
            $this->createDeletePolicy($tbl, $authCondition);
            $this->createAdminBypassPolicy($tbl);
        }

        // ============================================================
        // === G-095: fixed-assets tables ===
        // 3 tables. The original migration created a single
        // `{table}_admin_policy` (admin-only) per table. We DROP that and
        // replace with branch-scoped per-verb policies.
        //  - fixed_assets: has branch_id → single-branch condition.
        //  - asset_depreciation_schedules: has fixed_asset_id only →
        //    EXISTS subquery to fixed_assets.
        //  - asset_disposals: has fixed_asset_id only → EXISTS subquery.
        // ============================================================

        // --- fixed_assets ---
        DB::statement("DROP POLICY IF EXISTS fixed_assets_admin_policy ON fixed_assets");
        $this->enableRLS('fixed_assets');
        $faCondition = "branch_id = current_setting('app.branch_id')::int";
        $this->createSelectPolicy('fixed_assets', $faCondition);
        $this->createInsertPolicy('fixed_assets', $faCondition);
        $this->createUpdatePolicy('fixed_assets', $faCondition);
        $this->createDeletePolicy('fixed_assets', $faCondition);
        $this->createAdminBypassPolicy('fixed_assets');

        // --- asset_depreciation_schedules ---
        // Schema: fixed_asset_id FK to fixed_assets(id), NO branch_id.
        // EXISTS to fixed_assets.branch_id. (fixed_assets uses SoftDeletes,
        // but the policy intentionally does NOT filter on deleted_at — RLS
        // checks branch ownership, not soft-delete state. The Eloquent
        // SoftDeletes scope handles the latter at the query layer.)
        DB::statement("DROP POLICY IF EXISTS asset_depreciation_schedules_admin_policy ON asset_depreciation_schedules");
        $this->enableRLS('asset_depreciation_schedules');
        $adsCondition = "EXISTS (
            SELECT 1 FROM fixed_assets fa
            WHERE fa.id = fixed_asset_id
              AND fa.branch_id = current_setting('app.branch_id')::int
        )";
        $this->createSelectPolicy('asset_depreciation_schedules', $adsCondition);
        $this->createInsertPolicy('asset_depreciation_schedules', $adsCondition);
        $this->createUpdatePolicy('asset_depreciation_schedules', $adsCondition);
        $this->createDeletePolicy('asset_depreciation_schedules', $adsCondition);
        $this->createAdminBypassPolicy('asset_depreciation_schedules');

        // --- asset_disposals ---
        // Schema: fixed_asset_id FK to fixed_assets(id), NO branch_id.
        DB::statement("DROP POLICY IF EXISTS asset_disposals_admin_policy ON asset_disposals");
        $this->enableRLS('asset_disposals');
        $adCondition = "EXISTS (
            SELECT 1 FROM fixed_assets fa
            WHERE fa.id = fixed_asset_id
              AND fa.branch_id = current_setting('app.branch_id')::int
        )";
        $this->createSelectPolicy('asset_disposals', $adCondition);
        $this->createInsertPolicy('asset_disposals', $adCondition);
        $this->createUpdatePolicy('asset_disposals', $adCondition);
        $this->createDeletePolicy('asset_disposals', $adCondition);
        $this->createAdminBypassPolicy('asset_disposals');

        // ============================================================
        // === G-174: system_policies (admin-only — CORRECT) ===
        // The table had NO RLS at all. Route middleware is role:superadmin
        // (G-173, resolved in Session 1), so admin-only RLS is the INTENDED
        // behavior (unlike G-015/G-095 where admin-only was a bug). The
        // table has no branch_id and is intentionally superadmin-only.
        // Per-verb policies with condition = false → admin-only via the
        // bypass folded into each policy. This blocks direct DB-level
        // modification by any non-admin role, forcing all writes through
        // SystemPolicyService::activate()/deactivate() (which writes the
        // audit log + dispatches the event + invalidates the cache).
        // ============================================================

        $this->enableRLS('system_policies');
        $this->createSelectPolicy('system_policies', 'false');
        $this->createInsertPolicy('system_policies', 'false');
        $this->createUpdatePolicy('system_policies', 'false');
        $this->createDeletePolicy('system_policies', 'false');
        $this->createAdminBypassPolicy('system_policies');

        // ============================================================
        // === G-347: branch_demand_audit_log GUC key fix ===
        // The original `bdal_branch_read` SELECT policy used
        // `current_setting('app.current_branch_id')::bigint` but the
        // canonical GUC name is `app.branch_id`. The mismatch meant non-admin
        // users saw zero audit rows. Fix: DROP the old policy and recreate
        // with `current_setting('app.branch_id')::int` (matching the
        // canonical migration's cast). Also add FORCE ROW LEVEL SECURITY
        // (the original migration only did ENABLE, not FORCE — without FORCE
        // the table owner bypasses RLS). The other 4 policies
        // (bdal_admin_read, bdal_insert, bdal_no_update, bdal_no_delete)
        // are left unchanged.
        // ============================================================

        // Ensure FORCE is applied (idempotent — no-op if already forced).
        DB::statement('ALTER TABLE branch_demand_audit_log FORCE ROW LEVEL SECURITY');

        // Drop + recreate the buggy SELECT policy with the canonical GUC key.
        DB::statement('DROP POLICY IF EXISTS bdal_branch_read ON branch_demand_audit_log');
        DB::statement("
            CREATE POLICY bdal_branch_read ON branch_demand_audit_log
                FOR SELECT
                USING (
                    branch_id = current_setting('app.branch_id')::int
                    OR current_setting('app.is_admin', true) = 'true'
                )
        ");
    }

    /**
     * Revert the migration.
     *
     * For newly-enabled tables (G-022 child tables + shadow_cutover_log,
     * G-174 system_policies): drop all policies created by up() + DISABLE
     * RLS. Reverts to the pre-migration "no RLS" state.
     *
     * For G-015 (consolidation) and G-095 (fixed-assets) tables: drop the
     * new per-verb `rls_*` policies + the old `{table}_admin_policy` (which
     * was already dropped in up()). Does NOT re-create the old admin-only
     * policy — the old policy was the bug we fixed. Reverting to "no RLS"
     * is the clean revert (a forward-fix migration can re-add a correct
     * policy if needed).
     *
     * For G-347 (branch_demand_audit_log): drop the fixed `bdal_branch_read`
     * policy. Does NOT re-create the buggy `app.current_branch_id` version
     * — the bug was the problem. The other 4 policies (bdal_admin_read,
     * bdal_insert, bdal_no_update, bdal_no_delete) are left as-is.
     */
    public function down(): void
    {
        // === G-022: branch_demand child tables + shadow_cutover_log ===
        // Drop all rls_* policies + DISABLE RLS.
        $g022Tables = [
            'branch_demand_items',
            'branch_demand_repricing',
            'branch_demand_customer_payment_settlements',
            'branch_demand_money_transfer_settlements',
            'shadow_cutover_log',
        ];
        foreach ($g022Tables as $table) {
            $this->dropAllRlsPolicies($table);
        }

        // === G-015: consolidation tables ===
        // Drop rls_* policies + the old {table}_admin_policy (already dropped
        // in up(), but DROP IF EXISTS is idempotent). Do NOT re-create the
        // old admin-only policy.
        $g015Tables = ['companies', 'consolidation_runs', 'elimination_entries', 'elimination_rules'];
        foreach ($g015Tables as $table) {
            $this->dropAllRlsPolicies($table);
            DB::statement("DROP POLICY IF EXISTS {$table}_admin_policy ON {$table}");
        }

        // === G-095: fixed-assets tables ===
        // Same pattern as G-015.
        $g095Tables = ['fixed_assets', 'asset_depreciation_schedules', 'asset_disposals'];
        foreach ($g095Tables as $table) {
            $this->dropAllRlsPolicies($table);
            DB::statement("DROP POLICY IF EXISTS {$table}_admin_policy ON {$table}");
        }

        // === G-174: system_policies ===
        $this->dropAllRlsPolicies('system_policies');

        // === G-347: branch_demand_audit_log ===
        // Drop the FIXED bdal_branch_read. Do NOT re-create the buggy
        // app.current_branch_id version. Leave the other 4 policies as-is.
        DB::statement('DROP POLICY IF EXISTS bdal_branch_read ON branch_demand_audit_log');
    }

    // ============================================================
    // Helper methods — copied verbatim from the canonical migration
    // `2025_01_20_000007_add_rls_branch_isolation.php` to ensure this
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
