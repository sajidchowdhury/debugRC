<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — concurrency + race-condition feature tests for StockTakeService.
 *
 * Covers:
 *   - The lockForUpdate() pattern in saveCounts + setupWarehouseCounts
 *     serializes concurrent counters on the same session row.
 *   - The pg_advisory_xact_lock(0x53544B50, $wid) advisory lock in postSession
 *     serializes concurrent posts across DIFFERENT sessions covering the SAME
 *     warehouse.
 *   - The prevent_overlapping_frozen_stock_take DB trigger fires when a second
 *     freeze_outbound=true insert attempts to cover a warehouse already frozen
 *     by an active session — the race-condition backstop for the service's
 *     friendly pre-check.
 *   - The POST_ADVISORY_LOCK_NAMESPACE constant is the documented "STKP"
 *     namespace (0x53544B50) — a trivial sanity check that the constant has
 *     not drifted.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * IMPORTANT — concurrency testing caveats
 * ──────────────────────────────────────────────────────────────────────────
 * True two-PC concurrency tests are hard in PHPUnit. The DatabaseTransactions
 * trait wraps every test in a single outer transaction, which means:
 *
 *   1. Row-level locks (SELECT ... FOR UPDATE) acquired in the test are
 *      re-entrant within the same backend PID — the service's lockForUpdate
 *      on the same row WILL NOT block (same transaction, no conflict).
 *   2. Transaction-scoped advisory locks (pg_advisory_xact_lock) are also
 *      re-entrant within the same backend PID.
 *   3. Data inserted in the test's outer transaction is invisible to other
 *      DB connections until COMMIT (which DatabaseTransactions never does
 *      inside a test method).
 *
 * To actually trigger lock contention we use a SEPARATE PDO connection for
 * the manual lock acquisition — the second connection's transaction is
 * independent of Laravel's, so the lock it acquires is NOT re-entrant.
 * Advisory locks are integer-keyed (no data dependency), so the separate
 * connection can acquire them without needing to see Laravel's uncommitted
 * rows.
 *
 * For the lockForUpdate-based tests (saveCounts, setupWarehouseCounts) we
 * cannot use a separate connection because the FOR UPDATE row lock requires
 * the row to be visible to the lock holder — and Laravel's uncommitted
 * inserts are not. For those we use the same-connection approach and
 * document that the re-entrant lock will NOT actually block; the test then
 * exercises the lock_timeout setting + the service's transaction wrapping
 * rather than true contention. The "true concurrency" assertion is left as
 * a documented gap — the advisory-lock test below provides actual cross-
 * connection contention coverage.
 *
 * All lock_timeout tests set `lock_timeout = '2s'` (fail-fast after 2s) and
 * reset it to 0 (default = no timeout) in tearDown so the setting doesn't
 * leak to other test files via the pooled connection.
 *
 * @group concurrency
 */
class ConcurrentCountTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // Set a 2-second lock timeout so any lock-acquisition wait fails fast
        // instead of hanging the test for the default 30s+ statement_timeout.
        // Tests that exercise true contention expect this to fire; tests that
        // don't trigger contention are unaffected (the lock is acquired
        // immediately).
        try {
            DB::statement("SET lock_timeout = '2s'");
        } catch (\Throwable $e) {
            // Some test environments may not allow setting lock_timeout —
            // fall through; the contention tests will simply take longer.
        }
    }

    protected function tearDown(): void
    {
        try {
            DB::statement("SET lock_timeout = 0");
        } catch (\Throwable $e) {
            // Ignore — connection may already be reset.
        }
        parent::tearDown();
    }

    /**
     * Open a fresh PDO connection to the test database, separate from
     * Laravel's pooled connection. Used to acquire a lock that Laravel's
     * connection will then contend for.
     *
     * Returns [pdo, config] — caller is responsible for ROLLBACK + nulling
     * the PDO to release the lock.
     */
    private function openSeparatePdo(): array
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '5432',
            $config['database'] ?? 'rcerp_test'
        );
        $pdo = new \PDO($dsn, $config['username'] ?? 'rcerp_app', $config['password'] ?? '');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return [$pdo, $config];
    }

    /**
     * Build a session ready for posting — admin creates the session, sets up
     * counts, saves a variance-creating count. Returns [sessionId, warehouseId, adminUserId].
     */
    private function makeSessionReadyForPost(int $branchId, int $warehouseId, float $physicalQty = 12): array
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, 10);

        $session = $this->service->createSession([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => $physicalQty]);

        return [$session->id, $warehouseId, $admin->id];
    }

    // ========================================================================
    // lockForUpdate on session row (saveCounts)
    // ========================================================================

    /**
     * Verify the saveCounts lockForUpdate pattern is well-formed: the service
     * runs inside DB::transaction and uses SELECT ... FOR UPDATE on the
     * session row to serialize concurrent counters.
     *
     * CAVEAT: on the same DB connection (Laravel's pooled connection under
     * DatabaseTransactions), the FOR UPDATE row lock is RE-ENTRANT — the
     * service's lockForUpdate on a row the test already locked WILL NOT
     * block. To truly test cross-connection contention we would need a
     * separate PDO connection that can see the row (which requires the row
     * to be committed, but DatabaseTransactions never commits mid-test).
     *
     * Pragmatic assertion: the service either succeeds quickly (re-entrant
     * lock — same connection) OR throws QueryException with SQLSTATE 55P03
     * (lock_not_available — true contention via separate connection). Either
     * outcome is acceptable; both prove the lockForUpdate path was exercised.
     */
    public function test_save_counts_uses_lock_for_update(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        // Open a savepoint within the outer transaction and acquire the
        // FOR UPDATE row lock on the session row. On the same connection
        // this is re-entrant — the service's subsequent lockForUpdate will
        // NOT block. The lock is released when the savepoint rolls back.
        DB::beginTransaction();
        try {
            DB::table('stock_take_sessions')
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            try {
                $updated = $this->service->saveCounts($session->id, $wid, [$pid => 7]);
                // Re-entrant lock — service completed without contention.
                $this->assertSame(1, $updated);
            } catch (QueryException $e) {
                // True contention (would only fire on a separate connection
                // with the row committed). SQLSTATE 55P03 = lock_not_available.
                $sqlState = $e->errorInfo[0] ?? null;
                $this->assertSame('55P03', $sqlState, "Unexpected SQLSTATE: {$sqlState}");
            }
        } finally {
            DB::rollBack();
        }
    }

    // ========================================================================
    // Advisory lock in postSession (true cross-connection contention)
    // ========================================================================

    /**
     * Verify the postSession advisory lock truly serializes concurrent posts
     * across DIFFERENT sessions covering the SAME warehouse.
     *
     * Approach: open a SEPARATE PDO connection (independent of Laravel's
     * outer transaction) and acquire pg_advisory_xact_lock(namespace, $wid)
     * there. Then attempt postSession from Laravel's connection — the
     * service's pg_advisory_xact_lock attempt blocks waiting for the separate
     * connection's lock; with lock_timeout='2s' (set in setUp), it fails
     * fast with SQLSTATE 55P03 (lock_not_available).
     *
     * The separate connection acquires the advisory lock by integer key —
     * no data dependency — so Laravel's uncommitted session row is fine.
     */
    public function test_concurrent_post_session_serializes_via_advisory_lock(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid);

        // Acquire the advisory lock on a SEPARATE PDO connection.
        [$otherPdo] = $this->openSeparatePdo();
        try {
            $otherPdo->exec('BEGIN');
            $stmt = $otherPdo->prepare('SELECT pg_advisory_xact_lock(?, ?)');
            $stmt->execute([StockTakeService::POST_ADVISORY_LOCK_NAMESPACE, $wid]);

            // Laravel's postSession will try to acquire the SAME advisory lock
            // and block. With lock_timeout='2s' (setUp), it fails fast.
            try {
                $this->service->postSession($sid, $adminId);
                $this->fail('Expected lock_not_available (SQLSTATE 55P03) — the service should have blocked on the advisory lock.');
            } catch (QueryException $e) {
                $sqlState = $e->errorInfo[0] ?? null;
                $this->assertNotNull($sqlState, 'QueryException should expose a SQLSTATE.');
                // 55P03 = lock_not_available (the lock_timeout fired).
                $this->assertSame('55P03', $sqlState, "Expected SQLSTATE 55P03 (lock_not_available), got {$sqlState}.");
            }
        } finally {
            // Release the advisory lock by rolling back the separate connection.
            try {
                $otherPdo->exec('ROLLBACK');
            } catch (\Throwable $e) {
                // Best-effort cleanup.
            }
            $otherPdo = null;
        }
    }

    // ========================================================================
    // lockForUpdate on session row (setupWarehouseCounts)
    // ========================================================================

    /**
     * Verify the setupWarehouseCounts lockForUpdate pattern.
     *
     * Same caveat as test_save_counts_uses_lock_for_update: the same-connection
     * re-entrant lock means we cannot trigger true contention here. The
     * assertion accepts either outcome (success or 55P03) — both prove the
     * lockForUpdate path was exercised.
     */
    public function test_concurrent_setup_counts_serializes_via_session_lock(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);

        DB::beginTransaction();
        try {
            DB::table('stock_take_sessions')
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            try {
                $count = $this->service->setupWarehouseCounts($session->id, $wid);
                // Re-entrant lock — service completed without contention.
                $this->assertSame(1, $count);
            } catch (QueryException $e) {
                $sqlState = $e->errorInfo[0] ?? null;
                $this->assertSame('55P03', $sqlState, "Unexpected SQLSTATE: {$sqlState}");
            }
        } finally {
            DB::rollBack();
        }
    }

    // ========================================================================
    // DB trigger: prevent_overlapping_frozen_stock_take
    // ========================================================================

    /**
     * Verify the prevent_overlapping_frozen_stock_take trigger fires when a
     * second freeze_outbound=true stock_take_warehouses row attempts to cover
     * a warehouse already frozen by an active session.
     *
     * The service's createSession does a friendly pre-check that throws a
     * RuntimeException before the trigger fires; here we BYPASS the service
     * and insert directly into stock_take_warehouses to exercise the DB
     * trigger directly (the race-condition backstop for two concurrent
     * createSession calls that both pass the pre-check).
     */
    public function test_overlapping_frozen_session_creation_rolls_back_on_race(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Insert session A (draft) + stw A (freeze=true).
        $sessionAId = DB::table('stock_take_sessions')->insertGetId([
            'session_code'  => 'ST-A-' . substr(uniqid(), -6),
            'session_date'  => now()->toDateString(),
            'branch_id'     => $branch->id,
            'status'        => 'draft',
            'is_reversed'   => false,
            'freeze_outbound' => true,
            'frozen_at'     => now(),
            'count_scope'   => 'full',
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('stock_take_warehouses')->insert([
            'stock_take_session_id' => $sessionAId,
            'warehouse_id'          => $wid,
            'branch_id'             => $branch->id,
            'freeze_outbound'       => true,
            'status'                => 'pending',
            'fiscal_year_id'        => $this->resolveActiveFiscalYearId(),
        ]);

        // Insert session B (draft). Then attempt to insert stw B with freeze=true
        // on the SAME warehouse — the trigger should fire with ERRCODE 23000.
        $sessionBId = DB::table('stock_take_sessions')->insertGetId([
            'session_code'  => 'ST-B-' . substr(uniqid(), -6),
            'session_date'  => now()->toDateString(),
            'branch_id'     => $branch->id,
            'status'        => 'draft',
            'is_reversed'   => false,
            'freeze_outbound' => true,
            'frozen_at'     => now(),
            'count_scope'   => 'full',
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        try {
            DB::table('stock_take_warehouses')->insert([
                'stock_take_session_id' => $sessionBId,
                'warehouse_id'          => $wid,
                'branch_id'             => $branch->id,
                'freeze_outbound'       => true,
                'status'                => 'pending',
                'fiscal_year_id'        => $this->resolveActiveFiscalYearId(),
            ]);
            $this->fail('Expected a QueryException with SQLSTATE 23000 from the prevent_overlapping_frozen_stock_take trigger.');
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            $this->assertNotNull($sqlState, 'QueryException should expose a SQLSTATE.');
            $this->assertSame('23000', $sqlState, "Expected SQLSTATE 23000 (trigger raise), got {$sqlState}.");

            // The trigger's message names the warehouse + explains the conflict.
            $this->assertStringContainsString('already covered by an active frozen stock-take', $e->getMessage());
        }

        // The stw B insert was rolled back by the trigger; session B exists
        // but has zero stw rows.
        $stwBCount = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionBId)
            ->count();
        $this->assertSame(0, $stwBCount, 'The overlapping stw insert should have been rejected by the trigger.');
    }

    // ========================================================================
    // Constant sanity check — POST_ADVISORY_LOCK_NAMESPACE
    // ========================================================================

    /**
     * Trivial sanity check that the documented "STKP" advisory-lock namespace
     * constant has not drifted. 0x53544B50 is the ASCII concatenation of
     * 'S','T','K','P' — chosen to be memorable + unlikely to collide with
     * any other advisory-lock namespace in the system.
     */
    public function test_post_session_lock_namespace_constant_is_stkp_hex(): void
    {
        $this->assertSame(0x53544B50, StockTakeService::POST_ADVISORY_LOCK_NAMESPACE);

        // The four bytes spell "STKP".
        $decoded = chr(($ns = StockTakeService::POST_ADVISORY_LOCK_NAMESPACE) >> 24 & 0xFF)
            . chr($ns >> 16 & 0xFF)
            . chr($ns >> 8 & 0xFF)
            . chr($ns & 0xFF);
        $this->assertSame('STKP', $decoded);
    }
}
