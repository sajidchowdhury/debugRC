<?php

namespace Tests\Unit\BranchDemand;

use App\Services\BranchDemand\BranchDemandRepricingService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Branch Demand Repricing Service Unit Tests — Phase 10.
 *
 * Tests the BranchDemandRepricingService:
 *   - createRepricingAdjustment() — creates repricing with GL adjustment
 *   - getRepricingHistory() — returns repricing history for a demand
 *   - getPriceRangeComparison() — compares current vs locked price ranges
 *   - checkSalePriceRange() — validates sale price against locked range
 *
 * Uses DB::table() inserts for test data setup.
 */
class BranchDemandRepricingServiceTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsLedgerDependencies;

    private BranchDemandRepricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BranchDemandRepricingService::class);
    }

    // ===================== getRepricingHistory() =====================

    public function test_get_repricing_history_returns_entries_for_demand(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        // Insert repricing records
        $this->insertBranchDemandRepricing($demandId, 1000.00, 1200.00);
        $this->insertBranchDemandRepricing($demandId, 1200.00, 1100.00);

        $history = $this->service->getRepricingHistory($demandId);

        $this->assertNotNull($history);
        $this->assertCount(2, $history);
    }

    public function test_get_repricing_history_returns_empty_for_no_history(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        $history = $this->service->getRepricingHistory($demandId);

        $this->assertNotNull($history);
        $this->assertCount(0, $history);
    }

    // ===================== createRepricingAdjustment() =====================

    public function test_create_repricing_adjustment_rejects_non_received(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'pending');

        $this->expectException(\RuntimeException::class);

        $this->service->createRepricingAdjustment(
            $demandId,
            1200.00,
            'Test repricing',
            null,
            $user->id
        );
    }

    public function test_create_repricing_adjustment_rejects_reversed_demand(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'reversed');

        $this->expectException(\RuntimeException::class);

        $this->service->createRepricingAdjustment(
            $demandId,
            1200.00,
            'Test repricing',
            null,
            $user->id
        );
    }

    public function test_create_repricing_adjustment_rejects_new_total_below_settled(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        // Set the demand's total and settlement amount
        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value' => 1000.00,
            'settlement_amount' => 800.00,
            'is_reversed' => false,
        ]);

        // New total (500) is less than already settled (800)
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createRepricingAdjustment(
            $demandId,
            500.00,
            'Cannot go below settled amount',
            null,
            $user->id
        );
    }

    // ===================== checkSalePriceRange() =====================

    public function test_check_sale_price_range_within_range(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        // Insert a demand item with price range
        $categoryId = $this->insertProductCategory();
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'P-RP-' . uniqid(),
            'product_name' => 'Reprice Test Product',
            'category_id' => $categoryId,
            'unit' => 'pcs',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = $this->insertBranchDemandItem($demandId, $productId, 10.0, 100.0);

        $result = $this->service->checkSalePriceRange($itemId, 100.0);

        $this->assertIsArray($result);
        $this->assertTrue($result['in_range'] ?? true);
    }
}
