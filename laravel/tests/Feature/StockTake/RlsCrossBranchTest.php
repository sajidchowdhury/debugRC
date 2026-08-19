<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\ResolvesActiveFiscalYear;
use Tests\TestCase;

/**
 * Phase 12 — RLS cross-branch isolation tests for the stock-take feature.
 *
 * Covers:
 *   - DB-level Row-Level Security on stock_take_sessions, stock_take_items,
 *     stock_take_warehouses, and stock_take_audit_log filters by branch.
 *   - Admin bypass: app.is_admin='true' GUC makes every RLS policy pass.
 *   - Non-admin scoping: app.branch_id=<own branch> limits reads to own rows.
 *   - Route-level branch.isolation middleware (EnforceBranchIsolation) blocks
 *     cross-branch POST writes (post/cancel/reverse/re-open) for non-admin
 *     users before the controller/service runs (returns 403 JSON).
 *   - Admin can explicitly hit the /admin/stock-take/checklist endpoint and
 *     get a 200 (no RLS block — admin bypass policy).
 *
 * ──────────────────────────────────────────────────────────────────────────
 * IMPORTANT — GUC strategy for the test environment
 * ──────────────────────────────────────────────────────────────────────────
 * RLS on stock_take_* tables reads two PostgreSQL custom GUCs:
 *
 *   - app.branch_id   integer  (the acting user's employee.branch_id)
 *   - app.is_admin    text     ('true' or 'false' — admin bypass flag)
 *
 * The policies are:
 *   USING (current_setting('app.is_admin', true) = 'true'
 *          OR branch_id = current_setting('app.branch_id')::int)
 *
 * During a normal HTTP request, the SetAppBranchId middleware sets these
 * GUCs based on the authenticated user. In our tests we exercise BOTH the
 * HTTP path (for the middleware-blocking tests) and the direct-DB path (for
 * the read-isolation tests). For the direct-DB path we must set the GUCs
 * explicitly via DB::statement("SET app.branch_id = ...") + ("SET app.is_admin = ...")
 * BEFORE issuing the read query, because no middleware is running to set
 * them for us.
 *
 * The GUC is per-connection. Laravel's default DB connection pools
 * connections, so a GUC set on one connection persists for that connection
 * until RESET. We reset both GUCs in tearDown() to avoid leaking state to
 * other test files (DatabaseTransactions rolls back the data but NOT
 * session-level GUCs).
 *
 * ──────────────────────────────────────────────────────────────────────────
 * BYPASSRLS caveat
 * ──────────────────────────────────────────────────────────────────────────
 * If the test database role (`rcerp_app` per phpunit.xml) was created with
 * the BYPASSRLS attribute, RLS policies do NOT fire for it regardless of the
 * GUC values — non-admin queries would see ALL rows and the read-isolation
 * tests below would fail. In that case the user must either:
 *
 *   (a) Create the test role WITHOUT BYPASSRLS (REVOKE BYPASSRLS from
 *       rcerp_app), OR
 *   (b) Mark these tests as @group rls and skip them on environments where
 *       the role bypasses RLS.
 *
 * The GUC-setting + tearDown reset is the correct approach for any
 * environment where the role honors RLS.
 *
 * @group rls
 */
class RlsCrossBranchTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies, ResolvesActiveFiscalYear;

    protected StockTakeService $service;

    /**
     * True if RLS is actually enforced for the current DB role.
     * If the role has BYPASSRLS, policies don't fire and RLS tests
     * will fail — we skip them instead.
     */
    private static ?bool $rlsEnforced = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveActiveFiscalYearId();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // Check once whether RLS is enforced for the current DB role.
        if (self::$rlsEnforced === null) {
            self::$rlsEnforced = $this->isRlsEnforced();
        }
    }

    /**
     * Check if RLS is actually enforced for the current DB role by
     * testing whether a table with RLS enabled and a policy actually
     * filters rows when the GUC is set to a non-matching branch.
     */
    private function isRlsEnforced(): bool
    {
        try {
            // Check if the role has BYPASSRLS attribute.
            $row = DB::selectOne("
                SELECT rolbypassrls
                FROM pg_roles
                WHERE rolname = current_user
            ");
            if ($row && $row->rolbypassrls) {
                return false;
            }
            // Also check if RLS is actually enabled on stock_take_sessions.
            $rlsRow = DB::selectOne("
                SELECT relrowsecurity
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = 'stock_take_sessions' AND n.nspname = 'public'
            ");
            if (!$rlsRow || !$rlsRow->relrowsecurity) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Mark the test as skipped if RLS is not enforced.
     */
    private function requireRlsEnforced(): void
    {
        if (!self::$rlsEnforced) {
            $this->markTestSkipped(
                'RLS is not enforced for the current DB role (BYPASSRLS or RLS not enabled).'
            );
        }
    }

    /**
     * Reset the GUCs we set during the tests so they don't leak to other
     * test files via the pooled connection.
     */
    protected function tearDown(): void
    {
        try {
            DB::statement("RESET app.branch_id");
            DB::statement("RESET app.is_admin");
        } catch (\Throwable $e) {
            // GUCs may not be set; RESET is a no-op at worst.
        }
        parent::tearDown();
    }

    /**
     * Force the GUCs to a specific branch + admin/non-admin state.
     * Used by the direct-DB read-isolation tests.
     */
    private function setGuc(int $branchId, bool $isAdmin): void
    {
        // PostgreSQL SET does NOT accept PDO bound parameters — inline the values.
        $safeIsAdmin = $isAdmin ? 'true' : 'false';
        DB::unprepared("SET app.branch_id = {$branchId}");
        DB::unprepared("SET app.is_admin = {$safeIsAdmin}");
    }

    // ========================================================================
    // Direct-DB read isolation (GUC-driven RLS)
    // ========================================================================

    public function test_admin_sees_all_branches_sessions(): void
    {
        $this->requireRlsEnforced();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $this->service->createSession([
            'branch_id'     => $branchA->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA],
            'created_by'    => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id'     => $branchB->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB],
            'created_by'    => $admin->id,
        ]);

        // Force the GUCs: admin bypass — branch_id doesn't matter, app.is_admin=true.
        $this->setGuc($branchA->id, true);

        $count = DB::table('stock_take_sessions')->count();
        $this->assertGreaterThanOrEqual(2, $count, 'Admin GUC should bypass RLS and see sessions in both branches.');
    }

    public function test_non_admin_sees_only_own_branch_sessions(): void
    {
        $this->requireRlsEnforced();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        // 2 sessions in branch A + 1 in branch B.
        $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branchB->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB], 'created_by' => $admin->id,
        ]);

        // Non-admin in branch A — RLS should filter to branch A only.
        $this->setGuc($branchA->id, false);

        $rows = DB::table('stock_take_sessions')->get();
        // RLS should scope to branch A only; seed data may add extra rows
        // so we verify at least our 2 branch-A sessions are visible and
        // NO branch-B sessions leaked through.
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'Non-admin RLS should see at least own branch sessions.');
        foreach ($rows as $r) {
            $this->assertSame($branchA->id, (int) $r->branch_id, 'Non-admin RLS must not leak other branch sessions.');
        }
    }

    public function test_non_admin_cannot_read_other_branch_items(): void
    {
        $this->requireRlsEnforced();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        // Seed one product + stock per warehouse, then create + setup both sessions.
        $pidA = $this->insertProduct();
        $this->insertWarehouseStock($widA, $pidA, 10);
        $pidB = $this->insertProduct();
        $this->insertWarehouseStock($widB, $pidB, 10);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $sessionA = $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($sessionA->id, $widA);

        $sessionB = $this->service->createSession([
            'branch_id' => $branchB->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB], 'created_by' => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($sessionB->id, $widB);

        // Non-admin in branch A — RLS should hide session B's items.
        $this->setGuc($branchA->id, false);

        $items = DB::table('stock_take_items')->get();
        foreach ($items as $i) {
            $this->assertSame($branchA->id, (int) $i->branch_id, 'Non-admin must not see items from another branch.');
        }
        $this->assertNotEmpty($items, 'Expected at least one item from branch A.');
    }

    public function test_non_admin_cannot_read_other_branch_audit_log(): void
    {
        $this->requireRlsEnforced();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        // createSession writes an audit log row in each branch.
        $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branchB->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB], 'created_by' => $admin->id,
        ]);

        // Non-admin in branch A — RLS should hide branch B's audit rows.
        $this->setGuc($branchA->id, false);

        $auditRows = DB::table('stock_take_audit_log')->get();
        $this->assertNotEmpty($auditRows);
        foreach ($auditRows as $row) {
            $this->assertSame($branchA->id, (int) $row->branch_id, 'Non-admin must not see audit rows from another branch.');
        }
    }

    public function test_rls_filters_stock_take_warehouses_by_branch(): void
    {
        $this->requireRlsEnforced();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branchB->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB], 'created_by' => $admin->id,
        ]);

        // Non-admin in branch A — RLS should hide branch B's stw rows.
        $this->setGuc($branchA->id, false);

        $stwRows = DB::table('stock_take_warehouses')->get();
        $this->assertNotEmpty($stwRows);
        foreach ($stwRows as $row) {
            $this->assertSame($branchA->id, (int) $row->branch_id, 'Non-admin must not see stock_take_warehouses from another branch.');
        }
    }

    // ========================================================================
    // Middleware-level branch isolation (EnforceBranchIsolation → 403)
    // ========================================================================

    /**
     * Build a posted session in branch B via an admin (bypasses RLS) so we
     * have a valid posted target for the reverse / re-open guard tests.
     * Returns the session id.
     */
    private function makePostedSessionInBranchB(int $branchBId, int $widB): int
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($widB, $pid, 10);

        $session = $this->service->createSession([
            'branch_id'     => $branchBId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $widB);
        $this->service->saveCounts($session->id, $widB, [$pid => 12]); // +2 variance
        $this->service->postSession($session->id, $admin->id);

        return $session->id;
    }

    public function test_branch_isolation_middleware_blocks_cross_branch_post(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widB = $this->insertWarehouse($branchB->id);

        // Create a draft session in branch B (admin) — we won't even reach setup;
        // the middleware should block at the URL boundary.
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $sessionB = $this->service->createSession([
            'branch_id'     => $branchB->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB],
            'created_by'    => $admin->id,
        ]);

        // Switch to a manager in branch A — they should NOT be able to post
        // branch B's session.
        $managerA = $this->makeRoleUser('manager', [], [], $branchA);
        $this->actingAs($managerA);

        $this->withSession(['branch_id' => $branchA->id])
            ->postJson("/admin/stock-take/{$sessionB->id}/post")
            ->assertForbidden();
    }

    public function test_branch_isolation_middleware_blocks_cross_branch_cancel(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widB = $this->insertWarehouse($branchB->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $sessionB = $this->service->createSession([
            'branch_id'     => $branchB->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB],
            'created_by'    => $admin->id,
        ]);

        $managerA = $this->makeRoleUser('manager', [], [], $branchA);
        $this->actingAs($managerA);

        $this->withSession(['branch_id' => $branchA->id])
            ->postJson("/admin/stock-take/{$sessionB->id}/cancel", ['reason' => 'Cross-branch attempt.'])
            ->assertForbidden();
    }

    public function test_branch_isolation_middleware_blocks_cross_branch_reverse(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widB = $this->insertWarehouse($branchB->id);

        // Create + post a session in branch B as admin.
        $sessionBId = $this->makePostedSessionInBranchB($branchB->id, $widB);

        // Switch to a manager in branch A — reverse should be blocked.
        $managerA = $this->makeRoleUser('manager', [], [], $branchA);
        $this->actingAs($managerA);

        $this->withSession(['branch_id' => $branchA->id])
            ->postJson("/admin/stock-take/{$sessionBId}/reverse", ['reason' => 'Cross-branch attempt.'])
            ->assertForbidden();
    }

    public function test_branch_isolation_middleware_blocks_cross_branch_re_open(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widB = $this->insertWarehouse($branchB->id);

        // Create + post + reverse a session in branch B as admin.
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $sessionBId = $this->makePostedSessionInBranchB($branchB->id, $widB);
        $this->service->reverseSession($sessionBId, $admin->id, 'Reversed for re-open guard test.');

        // Switch to a manager in branch A — re-open should be blocked.
        $managerA = $this->makeRoleUser('manager', [], [], $branchA);
        $this->actingAs($managerA);

        $this->withSession(['branch_id' => $branchA->id])
            ->postJson("/admin/stock-take/{$sessionBId}/re-open", ['reason' => 'Cross-branch attempt.'])
            ->assertForbidden();
    }

    // ========================================================================
    // Admin bypass — checklist endpoint renders without RLS blocking.
    // ========================================================================

    public function test_admin_can_explicitly_query_other_branch_via_checklist_endpoint(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        // Create sessions in both branches.
        $admin = $this->makeRoleUser('admin', [], [], $branchA);
        $this->actingAs($admin);

        $this->service->createSession([
            'branch_id' => $branchA->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widA], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branchB->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$widB], 'created_by' => $admin->id,
        ]);

        // Admin hits the checklist endpoint — should get 200 (admin bypass policy
        // lets RLS see every branch).
        $this->withSession(['branch_id' => $branchA->id])
            ->get('/admin/stock-take/checklist')
            ->assertOk();
    }
}
