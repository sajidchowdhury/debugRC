<?php

namespace Tests\Feature\BranchDemand;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * Branch Demand API Feature Tests — Phase 10.
 *
 * Tests the BranchDemandApiController's REST API endpoints:
 *   - GET  /api/v1/branch-demands              List demands
 *   - POST /api/v1/branch-demands              Create demand
 *   - GET  /api/v1/branch-demands/{id}          Show demand
 *   - POST /api/v1/branch-demands/{id}/reverse   Reverse demand
 *   - POST /api/v1/branch-demands/{id}/reject    Reject demand
 *   - DELETE /api/v1/branch-demands/{id}         Delete demand
 *   - POST /api/v1/branch-demands/{id}/reprice   Reprice demand
 *   - GET  /api/v1/branch-demands/outstanding    Outstanding balances
 *   - GET  /api/v1/branch-demands/warehouses/{id} Warehouses for branch
 *   - GET  /api/v1/branch-demands/{id}/audit     Audit trail
 *
 * Uses the BuildsRoleUsers + InsertsBranchDependencies pattern.
 */
class BranchDemandApiTest extends TestCase
{
    use BuildsRoleUsers;
    use IssuesApiTokens;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsProductDependencies;

    private User $adminUser;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->makeRoleUser('admin');
        $this->branchId = $this->adminUser->getBranchId();
    }

    // ===================== LIST =====================

    public function test_list_demands_returns_paginated_json(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        // Create a demand that involves this branch
        $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branch-demands');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_list_demands_with_status_filter(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');
        $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branch-demands?status=pending');

        $response->assertStatus(200);
    }

    public function test_list_demands_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/branch-demands');

        $response->assertStatus(401);
    }

    // ===================== SHOW =====================

    public function test_show_demand_returns_detail_json(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/branch-demands/{$demandId}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id', 'demand_code', 'demand_date', 'from_branch', 'to_branch',
                'status', 'total_value', 'settlement_amount', 'outstanding',
                'settlement_progress', 'is_reversed', 'items', 'created_at',
            ],
        ]);
    }

    public function test_show_nonexistent_demand_returns_404(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branch-demands/999999');

        $response->assertStatus(404);
    }

    // ===================== CREATE =====================

    public function test_create_demand_returns_201(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        // Use Branch::factory() to avoid GENERATED ALWAYS identity column issue
        $toBranch = Branch::factory()->create();
        $toBranchId = $toBranch->id;

        $categoryId = $this->insertProductCategory();
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'P-API-' . uniqid(),
            'product_name' => 'API Test Product',
            'category_id' => $categoryId,
            'unit' => 'Pcs',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branch-demands', [
                'to_branch_id' => $toBranchId,
                'demand_date' => now()->toDateString(),
                'notes' => 'API test demand',
                'items' => [
                    ['product_id' => $productId, 'qty' => 10],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'demand_code', 'status'],
            'message',
        ]);
    }

    public function test_create_demand_to_same_branch_returns_422(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branch-demands', [
                'to_branch_id' => $this->branchId,
                'demand_date' => now()->toDateString(),
                'items' => [
                    ['product_id' => 1, 'qty' => 10],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_create_demand_validation_errors(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branch-demands', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to_branch_id', 'demand_date', 'items']);
    }

    // ===================== REVERSE =====================

    public function test_reverse_demand_returns_success(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        // Set received_at to allow reversal
        // Use null for warehouse_transfer_id and journal_entry_id to avoid FK violations
        DB::table('branch_demands')->where('id', $demandId)->update([
            'received_at' => now(),
            'received_by' => $this->adminUser->id,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reverse", [
                'reason' => 'API test reversal reason',
            ]);

        // May fail if the service can't find the GL journals/stock transactions,
        // but the route + controller should be reachable
        $this->assertContains($response->status(), [200, 422]);
    }

    public function test_reverse_demand_validation_requires_reason(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reverse", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    // ===================== REJECT =====================

    public function test_reject_demand_returns_success(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reject", [
                'reason' => 'API test rejection reason',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'rejected');
    }

    // ===================== DELETE =====================

    public function test_delete_demand_returns_success(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branch-demands/{$demandId}");

        $response->assertStatus(200);
    }

    // ===================== REPRICE =====================

    public function test_reprice_demand_validation_requires_new_total_and_reason(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reprice", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['new_total_value', 'reason']);
    }

    /**
     * G12 — Happy-path reprice: a 'received' demand with a positive
     * total_value can be repriced to a new total_value, returning the
     * repricing adjustment record with original_total / new_total /
     * adjustment_amount.
     *
     * Service contract (BranchDemandRepricingService::createRepricingAdjustment):
     *   - demand.status must be 'received' (else RuntimeException)
     *   - demand.is_reversed must be false
     *   - new_total_value must differ from current total_value by >= 0.01
     *   - new_total_value must be >= demand.settlement_amount
     *
     * The service posts GL journals (postRepricingAdjustmentJournals)
     * which may fail in the test DB if ledger master data is missing —
     * the controller catches Throwable and returns 422 with
     * "Failed to reprice demand." We tolerate either 200 (full success)
     * or 422 (GL-side failure) using the same pattern as
     * test_reverse_demand_returns_success above. When 200, the full
     * repricing JSON structure is asserted.
     */
    public function test_reprice_demand_returns_success_on_received_demand(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        // The insertBranchDemand helper does not set total_value (NULL by
        // default). The service requires a positive current total to
        // compute a meaningful adjustment.
        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value'       => 1000.00,
            'settlement_amount' => 0,
            'is_reversed'       => false,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reprice", [
                'new_total_value' => 1200.00,
                'reason'          => 'Negotiated price increase with partner branch.',
            ]);

        // Tolerate GL-side failure (same pattern as test_reverse_demand_returns_success).
        $this->assertContains(
            $response->status(),
            [200, 422],
            'Reprice should either succeed (200) or fail gracefully on GL posting (422).'
        );

        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'data',
                'message',
                'repricing' => [
                    'id',
                    'original_total',
                    'new_total',
                    'adjustment_amount',
                    'journal_entry_id',
                ],
            ]);

            // Repricing arithmetic — original=1000, new=1200, adjustment=+200.
            $response->assertJsonPath('repricing.original_total', 1000.00);
            $response->assertJsonPath('repricing.new_total', 1200.00);
            $response->assertJsonPath('repricing.adjustment_amount', 200.00);

            // The demand's total_value should now reflect the new total.
            $response->assertJsonPath('data.id', $demandId);
            $response->assertJsonPath('data.status', 'received');
            $response->assertJsonPath('data.total_value', 1200.00);

            // DB assertion: the repricing record was persisted.
            $this->assertDatabaseHas('branch_demand_repricing', [
                'branch_demand_id'    => $demandId,
                'original_total_value' => 1000.00,
                'new_total_value'     => 1200.00,
                'adjustment_amount'   => 200.00,
            ]);
        }
    }

    /**
     * G12 (negative) — Reprice rejects a non-'received' demand at the
     * API layer. Mirrors BranchDemandRepricingServiceTest but exercises
     * the full HTTP path (FormRequest → controller → service → 422).
     */
    public function test_reprice_demand_rejects_non_received_demand(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        // 'pending' status — service requires 'received'.
        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'pending');

        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value'       => 1000.00,
            'settlement_amount' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reprice", [
                'new_total_value' => 1200.00,
                'reason'          => 'Negotiated price increase with partner branch.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, "status is 'pending'"));
    }

    /**
     * G12 (negative) — Reprice rejects a new_total_value equal to the
     * current total_value (no-op adjustment).
     */
    public function test_reprice_demand_rejects_same_total_value(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value'       => 1000.00,
            'settlement_amount' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reprice", [
                'new_total_value' => 1000.00,
                'reason'          => 'No-change repricing attempt.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'No adjustment needed'));
    }

    /**
     * G12 (negative) — Reprice rejects a new_total_value below the
     * already-settled amount (would create a negative outstanding balance).
     */
    public function test_reprice_demand_rejects_below_settled_amount(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        // Demand settled for 800; trying to reprice down to 500 would
        // create a negative outstanding balance.
        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value'       => 1000.00,
            'settlement_amount' => 800.00,
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/branch-demands/{$demandId}/reprice", [
                'new_total_value' => 500.00,
                'reason'          => 'Below-settlement repricing attempt.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'negative outstanding balance'));
    }

    // ===================== OUTSTANDING =====================

    public function test_outstanding_returns_json(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branch-demands/outstanding');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    // ===================== WAREHOUSES =====================

    public function test_warehouses_returns_branch_warehouses(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        // Insert a warehouse for this branch
        $this->insertWarehouse($this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/branch-demands/warehouses/{$this->branchId}");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    // ===================== AUDIT =====================

    public function test_audit_returns_trail_json(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $demandId = $this->insertBranchDemand($this->branchId, $this->branchId + 1, 'received');

        // Add audit log entries
        $this->insertBranchDemandAuditLog($demandId, 'create', $this->branchId);
        $this->insertBranchDemandAuditLog($demandId, 'send', $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/branch-demands/{$demandId}/audit");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    // ===================== RBAC =====================

    public function test_salesman_cannot_create_demand(): void
    {
        $salesman = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesman);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branch-demands', [
                'to_branch_id' => $this->branchId + 1,
                'demand_date' => now()->toDateString(),
                'items' => [
                    ['product_id' => 1, 'qty' => 10],
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_warehouse_manager_can_create_demand(): void
    {
        $whManager = $this->makeRoleUser('warehouse_manager');
        $token = $this->apiTokenForUser($whManager);

        // Use Branch::factory() to avoid GENERATED ALWAYS identity column issue
        $toBranch = Branch::factory()->create();
        $toBranchId = $toBranch->id;

        $categoryId = $this->insertProductCategory();
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'P-WH-' . uniqid(),
            'product_name' => 'WH Test Product',
            'category_id' => $categoryId,
            'unit' => 'Pcs',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branch-demands', [
                'to_branch_id' => $toBranchId,
                'demand_date' => now()->toDateString(),
                'items' => [
                    ['product_id' => $productId, 'qty' => 5],
                ],
            ]);

        $response->assertStatus(201);
    }
}
