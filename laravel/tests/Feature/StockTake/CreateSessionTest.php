<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — happy-path feature tests for StockTakeService::createSession().
 *
 * Covers:
 *   - Basic persistence (session row + stock_take_warehouses rows) + Phase 8
 *     denormalization (branch_id + freeze_outbound mirrored onto stw rows).
 *   - Audit-log row written in the same transaction (action='create').
 *   - freeze_outbound=true path: sts.freeze_outbound=true, sts.frozen_at set,
 *     warehouses.is_frozen_for_count=true for the covered warehouses.
 *   - Validation guards: empty warehouse_ids, invalid branch_id, unsupported
 *     count_scope — each throws InvalidArgumentException with a clear message.
 *   - Cycle-count scope: count_scope='category' + count_scope_payload stored
 *     as jsonb containing the requested category_ids.
 *   - Duplicate warehouse_ids in the request — the service does NOT silently
 *     dedupe; it raises a RuntimeException (caught from the unique-constraint
 *     violation) so the caller gets a clear "already part of this session"
 *     message rather than a silent second row.
 *   - Overlapping frozen-session invariant: creating a second freeze=true
 *     session covering a warehouse already frozen by an active session is
 *     rejected with a friendly RuntimeException naming the conflict.
 *
 * The service is resolved from the container in setUp() so the constructor
 * dependencies (StockService, JournalPostingService, StockTakeAuditLogger,
 * StockTakePolicyService, AbcClassificationService) wire up automatically.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown, leaving the rcerp_test DB pristine.
 */
class CreateSessionTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
    }

    /**
     * Build the standard createSession payload for a single-branch, N-warehouse
     * session. Caller can override any key.
     */
    private function basePayload(int $branchId, array $warehouseIds, array $overrides = []): array
    {
        return array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => $warehouseIds,
            'notes'         => 'Phase 12 test session',
            'created_by'    => auth()->id(),
        ], $overrides);
    }

    public function test_create_session_persists_session_and_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch->id);
        $wid2 = $this->insertWarehouse($branch->id);

        $session = $this->service->createSession($this->basePayload($branch->id, [$wid1, $wid2]));

        $this->assertNotNull($session->id);
        $this->assertDatabaseHas('stock_take_sessions', [
            'id'            => $session->id,
            'branch_id'     => $branch->id,
            'status'        => 'draft',
            'is_reversed'   => false,
            'count_scope'   => 'full',
        ]);

        // Two stock_take_warehouses rows, each carrying the Phase 8
        // denormalized branch_id + freeze_outbound mirror.
        $stwRows = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $session->id)
            ->get();
        $this->assertCount(2, $stwRows);
        foreach ($stwRows as $stw) {
            $this->assertSame($branch->id, (int) $stw->branch_id);
            $this->assertFalse((bool) $stw->freeze_outbound);
        }
    }

    public function test_create_session_writes_audit_log(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $session = $this->service->createSession($this->basePayload($branch->id, [$wid]));

        $this->assertDatabaseHas('stock_take_audit_log', [
            'stock_take_session_id' => $session->id,
            'action'                => 'create',
            'from_status'           => null,
            'to_status'             => 'draft',
            'actor_id'              => auth()->id(),
            'branch_id'             => $branch->id,
        ]);
    }

    public function test_create_session_with_freeze_outbound_sets_frozen_at_and_marks_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch->id);
        $wid2 = $this->insertWarehouse($branch->id);

        $session = $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid1, $wid2],
            ['freeze_outbound' => true],
        ));

        // Session row: freeze_outbound=true, frozen_at not null.
        $stsRow = DB::table('stock_take_sessions')->where('id', $session->id)->first();
        $this->assertTrue((bool) $stsRow->freeze_outbound);
        $this->assertNotNull($stsRow->frozen_at);

        // Each covered warehouse: is_frozen_for_count=true.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid1)->value('is_frozen_for_count'));
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid2)->value('is_frozen_for_count'));
    }

    public function test_create_session_rejects_empty_warehouse_ids(): void
    {
        $branch = Branch::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createSession($this->basePayload($branch->id, []));
    }

    public function test_create_session_rejects_invalid_branch_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createSession($this->basePayload(0, [1]));
    }

    public function test_create_session_with_cycle_count_scope_category(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Insert an active product_category row (FK target for the scope payload).
        $catId = DB::table('product_categories')->insertGetId([
            'category_name' => 'Test Cat ' . uniqid(),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $session = $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            [
                'count_scope'         => 'category',
                'count_scope_payload' => ['category_ids' => [$catId]],
            ],
        ));

        $stsRow = DB::table('stock_take_sessions')->where('id', $session->id)->first();
        $this->assertSame('category', $stsRow->count_scope);

        // count_scope_payload is jsonb — decode + assert the category_ids are present.
        $payload = json_decode($stsRow->count_scope_payload, true);
        $this->assertIsArray($payload);
        $this->assertContains($catId, $payload['category_ids']);
    }

    public function test_create_session_rejects_invalid_count_scope(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported count_scope');
        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['count_scope' => 'bogus'],
        ));
    }

    /**
     * DIVERGENCE NOTE: the task spec hypothesised "the service dedupes; if it
     * doesn't, the unique constraint catches it" with the assertion that only
     * ONE stock_take_warehouses row exists. Reading the service: it does NOT
     * dedupe before insert; the second insert violates uk_stw_session_wh and
     * the service catches the 23505 SQLSTATE, re-throwing as a RuntimeException
     * with the message "already part of this stock-take session". The whole
     * transaction rolls back, so zero stw rows survive. We assert the
     * exception + the rollback (no session row, no stw rows).
     */
    public function test_create_session_dedupes_duplicate_warehouse_ids(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        try {
            $this->service->createSession($this->basePayload($branch->id, [$wid, $wid]));
            $this->fail('Expected RuntimeException for duplicate warehouse_id in the request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already part of this stock-take session', $e->getMessage());
        }

        // Transaction rolled back — no session row, no stw rows.
        $this->assertSame(0, DB::table('stock_take_sessions')->where('branch_id', $branch->id)->count());
        $this->assertSame(0, DB::table('stock_take_warehouses')->where('warehouse_id', $wid)->count());
    }

    public function test_create_session_prevents_overlapping_frozen_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Session A: freeze=true, covers $wid.
        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));

        // Session B: freeze=true, covers the SAME warehouse — must be rejected.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another active stock-take session already froze them');
        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));
    }
}
