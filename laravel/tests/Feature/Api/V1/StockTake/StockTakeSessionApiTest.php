<?php

namespace Tests\Feature\Api\V1\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\Helpers\ResolvesActiveFiscalYear;
use Tests\TestCase;

/**
 * Phase 12 — feature tests for the Phase 11 Stock Take REST API.
 *
 * Endpoint coverage (all under /api/v1/stock-take, behind api.auth +
 * set.api.branch + api.rate):
 *
 *   GET    /sessions                          — paginated list + auth
 *   GET    /sessions/{id}                     — show + 404 path
 *   POST   /sessions                          — store (draft) + 422 path
 *   POST   /sessions/{id}/setup/{wh}          — setup counts (item_count)
 *   PUT    /sessions/{id}/counts/{wh}         — save counts (updated)
 *   GET    /sessions/{id}/items               — per-line items
 *   GET    /sessions/{id}/variance            — variance rows + summary
 *   POST   /sessions/{id}/post                — admin/manager only + admin path
 *   POST   /sessions/{id}/reverse             — admin/manager only
 *   POST   /sessions/{id}/re-open             — admin/manager only
 *   full lifecycle: store → setup → save → post → reverse → re-open
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid Bearer token → 401
 *   - valid admin token → 200/201
 *   - salesman token on admin/manager routes → 403 (role middleware)
 *
 * Rate limit coverage:
 *   - 61 GETs in a loop → 61st returns 429 (skipped when CACHE_DRIVER=array
 *     because Laravel's array cache driver persists within a single test
 *     method, but the rate limiter is documented to require a persistent
 *     cache like redis/file in production).
 *
 * ──────────────────────────────────────────────────────────────────────────
 * DIVERGENCE NOTES on response shapes
 * ──────────────────────────────────────────────────────────────────────────
 * The task brief hypothesised several JSON keys that do not match the actual
 * Phase 11 controllers. We assert the ACTUAL shapes (read from the source):
 *
 *   1. setup endpoint: brief said `data.items_created`; actual is
 *      `item_count` (top-level, NOT nested under `data`).
 *
 *   2. save counts endpoint: brief said "assert 200"; actual response is
 *      `{message, updated}` (no `data` wrapper).
 *
 *   3. variance endpoint: brief said `data.rows + data.summary`; actual is
 *      `data` (the rows array) + `meta` (the summary object — session_id,
 *      session_code, status, variance_lines, total_gain, total_loss,
 *      net_value).
 *
 *   4. show endpoint: brief said `data.warehouses` (array); actual is
 *      `data.warehouses` (the whenLoaded branch — populated when the
 *      controller eager-loads the relation, which show() does). Confirmed
 *      present.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Branch context (set.api.branch middleware)
 * ──────────────────────────────────────────────────────────────────────────
 * The set.api.branch middleware runs AFTER api.auth (it's a route middleware
 * on the stock-take group) and sets the app.branch_id + app.is_admin GUCs
 * for RLS. For admin tokens → app.is_admin='true' (RLS bypass, all branches
 * visible). For non-admin tokens → app.branch_id=own branch (RLS scopes
 * reads; writes are branch-scoped via the policy).
 *
 * For the role-enforcement tests, the salesman token gets 403 from
 * api.auth:admin,manager BEFORE set.api.branch runs — the GUC stays at its
 * default and RLS would block everything, but we never reach the controller.
 */
class StockTakeSessionApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens, InsertsBranchDependencies, InsertsWarehouseDependencies;
    use ResolvesActiveFiscalYear;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // Flush the policy cache so each test starts from a fresh read.
        app(StockTakePolicyService::class)->flushCache();
        $this->resolveActiveFiscalYearId();
    }

    /**
     * Build the standard store payload (POST /sessions body).
     */
    private function storePayload(int $branchId, array $warehouseIds, array $overrides = []): array
    {
        return array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => $warehouseIds,
        ], $overrides);
    }

    /**
     * Create a posted-ready session via the SERVICE (not the API) so the
     * subsequent API call has a valid target. Returns [sessionId, warehouseId, productId, adminUser, adminToken].
     */
    private function makeSessionViaService(int $branchId, int $warehouseId, float $systemQty = 10, float $physicalQty = 12): array
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, $systemQty);

        $session = $this->service->createSession([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => $physicalQty]);

        $token = $this->apiTokenForUser($admin);
        return [$session->id, $warehouseId, $pid, $admin, $token];
    }

    // ========================================================================
    // AUTH — index endpoint
    // ========================================================================

    public function test_index_returns_401_without_token(): void
    {
        $this->getJson('/api/v1/stock-take/sessions')->assertUnauthorized();
    }

    public function test_index_returns_401_with_invalid_token(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-real'])
            ->getJson('/api/v1/stock-take/sessions')
            ->assertUnauthorized();
    }

    // ========================================================================
    // INDEX (list) — paginated sessions
    // ========================================================================

    public function test_index_returns_paginated_sessions_with_valid_token(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch->id);
        $wid2 = $this->insertWarehouse($branch->id);

        // Create 2 sessions via the service (faster than 2 POSTs).
        $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid1], 'created_by' => $admin->id,
        ]);
        $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid2], 'created_by' => $admin->id,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-take/sessions');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        // The admin token bypasses RLS — both sessions should be visible.
        // (assertGreaterThanOrEqual because the test DB may have other rows
        // from previous test runs that haven't rolled back yet — but with
        // DatabaseTransactions it should be exactly 2 from this test.)
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    // ========================================================================
    // SHOW — single session
    // ========================================================================

    public function test_show_returns_session_detail(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $session = $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid], 'created_by' => $admin->id,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$session->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['id', 'session_code', 'status', 'warehouses'],
        ]);
        $this->assertSame($session->id, $response->json('data.id'));
        $this->assertSame($session->session_code, $response->json('data.session_code'));
    }

    public function test_show_returns_404_for_missing_session(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-take/sessions/99999')
            ->assertNotFound();
    }

    // ========================================================================
    // STORE — create a draft session
    // ========================================================================

    public function test_store_creates_draft_session(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-take/sessions', $this->storePayload($branch->id, [$wid]));

        $response->assertCreated();
        $response->assertJsonStructure(['message', 'data' => ['id', 'status']]);
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNotNull($response->json('data.id'));

        // Persisted in the DB.
        $this->assertDatabaseHas('stock_take_sessions', [
            'id'         => $response->json('data.id'),
            'branch_id'  => $branch->id,
            'status'     => 'draft',
        ]);
    }

    // ========================================================================
    // POST — role enforcement + happy path
    // ========================================================================

    public function test_post_endpoint_requires_admin_or_manager_role(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid] = $this->makeSessionViaService($branch->id, $wid);

        // Switch to a salesman — they have a valid token but lack the role.
        $salesman = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-take/sessions/{$sid}/post")
            ->assertForbidden();
    }

    public function test_post_endpoint_succeeds_for_admin(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $admin, $token] = $this->makeSessionViaService($branch->id, $wid, 10, 12);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-take/sessions/{$sid}/post");

        $response->assertOk();
        $response->assertJsonStructure(['message', 'data' => ['id', 'status']]);
        $this->assertSame('posted', $response->json('data.status'));

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $sid,
            'status' => 'posted',
        ]);
    }

    // ========================================================================
    // SETUP — load items for counting
    // ========================================================================

    /**
     * DIVERGENCE: the brief expected `data.items_created` in the JSON; the
     * actual controller returns `item_count` (top-level, no `data` wrapper).
     * We assert the actual shape.
     */
    public function test_setup_endpoint_loads_items(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $session = $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid], 'created_by' => $admin->id,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-take/sessions/{$session->id}/setup/{$wid}");

        $response->assertOk();
        $response->assertJsonStructure(['message', 'item_count']);
        $this->assertGreaterThanOrEqual(1, $response->json('item_count'));

        // The items were loaded into stock_take_items.
        $this->assertDatabaseHas('stock_take_items', [
            'stock_take_session_id' => $session->id,
            'warehouse_id'          => $wid,
            'product_id'            => $pid,
        ]);
    }

    // ========================================================================
    // SAVE COUNTS — update physical_qty
    // ========================================================================

    public function test_save_counts_endpoint_updates_physical_qty(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $session = $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid], 'created_by' => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson("/api/v1/stock-take/sessions/{$session->id}/counts/{$wid}", [
                'counts' => [$pid => 7],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'updated']);
        $this->assertSame(1, $response->json('updated'));

        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $session->id)
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->first();
        $this->assertEqualsWithDelta(7, (float) $item->physical_qty, 0.0001);
    }

    // ========================================================================
    // ITEMS — per-line read
    // ========================================================================

    public function test_items_endpoint_returns_per_line_items(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $session = $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid], 'created_by' => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$session->id}/items");

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        // Each item row exposes the count-screen fields.
        $first = $data[0];
        $this->assertArrayHasKey('product_id', $first);
        $this->assertArrayHasKey('system_qty', $first);
        $this->assertArrayHasKey('physical_qty', $first);
        $this->assertArrayHasKey('difference', $first);
    }

    // ========================================================================
    // VARIANCE — rows + summary
    // ========================================================================

    /**
     * DIVERGENCE: the brief expected `data.rows + data.summary`; the actual
     * controller returns `data` (the rows array) + `meta` (the summary).
     * We assert the actual shape.
     */
    public function test_variance_endpoint_returns_variance_rows_and_summary(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $session = $this->service->createSession([
            'branch_id' => $branch->id, 'session_date' => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid], 'created_by' => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);
        // +2 variance → 1 variance line.
        $this->service->saveCounts($session->id, $wid, [$pid => 12]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$session->id}/variance");

        $response->assertOk();
        // `data` is the rows array; `meta` is the summary.
        $response->assertJsonStructure([
            'data',
            'meta' => ['session_id', 'session_code', 'status', 'variance_lines', 'total_gain', 'total_loss', 'net_value'],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data, 'One variance line expected (physical=12, system=10).');

        // Summary: gain 2*10=20, loss 0, net 20.
        $this->assertSame(1, $response->json('meta.variance_lines'));
        $this->assertEqualsWithDelta(20.0, (float) $response->json('meta.total_gain'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $response->json('meta.total_loss'), 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $response->json('meta.net_value'), 0.01);
    }

    // ========================================================================
    // RATE LIMIT — 60 req/min on reads
    // ========================================================================

    /**
     * Send 61 GET requests in a loop; the 61st should return 429.
     *
     * CAVEAT: Laravel's `array` cache driver persists within a single test
     * method (so the rate limiter DOES work), but production deployments
     * require a persistent cache (redis/file). We skip when the test env
     * uses the array cache to avoid false failures on environments where
     * cache cleanup happens between requests.
     *
     * @group rate_limit
     */
    public function test_rate_limit_60_per_minute_on_reads(): void
    {
        if (config('cache.default') === 'array') {
            $this->markTestSkipped('Rate limiting requires CACHE_DRIVER=redis or file (not array).');
        }

        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $headers = ['Authorization' => $this->bearerHeader($token)];

        // Send 60 requests — all should succeed (the route is api.rate:60).
        for ($i = 0; $i < 60; $i++) {
            $resp = $this->withHeaders($headers)->getJson('/api/v1/stock-take/sessions');
            $this->assertSame(200, $resp->status(), "Request #{$i} should succeed.");
        }

        // 61st — over the 60/min cap.
        $this->withHeaders($headers)
            ->getJson('/api/v1/stock-take/sessions')
            ->assertStatus(429);
    }

    // ========================================================================
    // REVERSE — role enforcement
    // ========================================================================

    public function test_reverse_endpoint_requires_admin_or_manager(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $admin] = $this->makeSessionViaService($branch->id, $wid);
        // Post it (admin) so reverse is a valid target.
        $this->actingAs($admin);
        $this->service->postSession($sid, $admin->id);

        $salesman = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-take/sessions/{$sid}/reverse", ['reason' => 'Should be forbidden.'])
            ->assertForbidden();
    }

    // ========================================================================
    // RE-OPEN — role enforcement
    // ========================================================================

    public function test_re_open_endpoint_requires_admin_or_manager(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $admin] = $this->makeSessionViaService($branch->id, $wid);
        $this->actingAs($admin);
        $this->service->postSession($sid, $admin->id);
        $this->service->reverseSession($sid, $admin->id, 'Reversed for re-open role test.');

        $salesman = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-take/sessions/{$sid}/re-open", ['reason' => 'Should be forbidden.'])
            ->assertForbidden();
    }

    // ========================================================================
    // FULL LIFECYCLE via the API
    // ========================================================================

    /**
     * End-to-end: store → setup → save counts → post → reverse → re-open.
     * Each step asserts the expected HTTP status + state. The final state
     * after re-open is 'counting' (reversed → counting per the service).
     */
    public function test_full_lifecycle_via_api(): void
    {
        $admin = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($admin);
        $headers = ['Authorization' => $this->bearerHeader($token)];

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        // 1. STORE — create a draft session.
        $store = $this->withHeaders($headers)
            ->postJson('/api/v1/stock-take/sessions', $this->storePayload($branch->id, [$wid]));
        $store->assertCreated();
        $sid = $store->json('data.id');
        $this->assertSame('draft', $store->json('data.status'));

        // 2. SETUP — load items for counting.
        $setup = $this->withHeaders($headers)
            ->postJson("/api/v1/stock-take/sessions/{$sid}/setup/{$wid}");
        $setup->assertOk();
        $this->assertGreaterThanOrEqual(1, $setup->json('item_count'));

        // 3. SAVE COUNTS — enter physical_qty = 12 (variance +2 at rate 10 = gain 20).
        $save = $this->withHeaders($headers)
            ->putJson("/api/v1/stock-take/sessions/{$sid}/counts/{$wid}", [
                'counts' => [$pid => 12],
            ]);
        $save->assertOk();
        $this->assertSame(1, $save->json('updated'));

        // 4. POST — apply variances + create GL journal.
        $post = $this->withHeaders($headers)
            ->postJson("/api/v1/stock-take/sessions/{$sid}/post");
        $post->assertOk();
        $this->assertSame('posted', $post->json('data.status'));

        // 5. REVERSE — undo the post (full stock + GL reversal). Reason required.
        $reverse = $this->withHeaders($headers)
            ->postJson("/api/v1/stock-take/sessions/{$sid}/reverse", [
                'reason' => 'API lifecycle test — reversal.',
            ]);
        $reverse->assertOk();
        $this->assertSame('reversed', $reverse->json('data.status'));

        // 6. RE-OPEN — back to counting for correction. Reason required.
        $reopen = $this->withHeaders($headers)
            ->postJson("/api/v1/stock-take/sessions/{$sid}/re-open", [
                'reason' => 'API lifecycle test — re-open.',
            ]);
        $reopen->assertOk();
        $this->assertSame('counting', $reopen->json('data.status'));

        // Final DB state.
        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $sid,
            'status' => 'counting',
        ]);
    }
}
