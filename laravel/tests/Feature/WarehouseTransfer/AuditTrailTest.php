<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Services\Stock\WarehouseTransferAuditService;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — Audit Trail feature tests for WarehouseTransfer.
 *
 * Covers:
 *   1. Audit log entries written for create, confirm, and cancel events.
 *   2. Health checks detecting cross-branch violations.
 *   3. Health checks detecting missing stock movements.
 *   4. Per-transfer checks passing for a valid same-branch confirmed transfer.
 *
 * The audit log table is `user_audit_log`. The WarehouseTransferAuditLogger
 * writes entries with actions: transfer_created, transfer_confirmed,
 * transfer_cancelled. The `details` JSON column contains transfer_id,
 * transfer_code, and other context.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and
 * rolls back on tearDown, leaving the rcerp_test DB pristine.
 */
class AuditTrailTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $transferService;
    protected WarehouseTransferAuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->transferService = app(WarehouseTransferService::class);
        $this->auditService = app(WarehouseTransferAuditService::class);
    }

    /**
     * Build the standard createTransfer payload. Caller can override any key.
     */
    private function basePayload(
        int $fromWarehouseId,
        int $toWarehouseId,
        array $items,
        array $overrides = [],
    ): array {
        return array_merge([
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Phase 7 audit test',
            'created_by'        => auth()->id(),
            'items'             => $items,
        ], $overrides);
    }

    /**
     * Helper: set up a complete valid same-branch transfer scenario.
     * Creates branch, 2 warehouses, product, stock, and returns
     * the created draft transfer with all IDs.
     *
     * @return array{branch: Branch, fromWhId: int, toWhId: int, productId: int, transfer: \App\Models\WarehouseTransfer}
     */
    private function setupValidTransferScenario(): array
    {
        $branch = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Physical stock at source = 200 (enough for any test qty).
        $this->insertWarehouseStock($fromWhId, $productId, 200);

        $transfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 10, 'rate' => 10]],
        ));

        return compact('branch', 'fromWhId', 'toWhId', 'productId', 'transfer');
    }

    // ====================================================================
    // Audit log tests
    // ====================================================================

    /**
     * SCENARIO 1: Creating a transfer writes a transfer_created audit log.
     */
    public function test_create_transfer_writes_audit_log(): void
    {
        $scenario = $this->setupValidTransferScenario();
        $transfer = $scenario['transfer'];

        // Check user_audit_log for the transfer_created event.
        $log = DB::table('user_audit_log')
            ->where('action', 'transfer_created')
            ->where('user_id', auth()->id())
            ->first();

        $this->assertNotNull($log, 'Expected a transfer_created audit log entry');

        $details = json_decode($log->details, true);
        $this->assertSame($transfer->id, $details['transfer_id']);
        $this->assertSame($transfer->transfer_code, $details['transfer_code']);
        $this->assertSame($scenario['fromWhId'], $details['from_warehouse_id']);
        $this->assertSame($scenario['toWhId'], $details['to_warehouse_id']);
        $this->assertSame('draft', $details['status']);
    }

    /**
     * SCENARIO 2: Confirming a transfer writes a transfer_confirmed audit log.
     */
    public function test_confirm_transfer_writes_audit_log(): void
    {
        $scenario = $this->setupValidTransferScenario();
        $transfer = $scenario['transfer'];

        // Confirm the draft transfer.
        $confirmed = $this->transferService->confirmTransfer($transfer->id, auth()->id());
        $this->assertSame('confirmed', $confirmed->status);

        // Check user_audit_log for the transfer_confirmed event.
        $log = DB::table('user_audit_log')
            ->where('action', 'transfer_confirmed')
            ->where('user_id', auth()->id())
            ->first();

        $this->assertNotNull($log, 'Expected a transfer_confirmed audit log entry');

        $details = json_decode($log->details, true);
        $this->assertSame($transfer->id, $details['transfer_id']);
        $this->assertSame($transfer->transfer_code, $details['transfer_code']);
        $this->assertSame('confirmed', $details['status']);
    }

    /**
     * SCENARIO 3: Cancelling a transfer writes a transfer_cancelled audit log
     * with the cancellation reason.
     */
    public function test_cancel_transfer_writes_audit_log(): void
    {
        $scenario = $this->setupValidTransferScenario();
        $transfer = $scenario['transfer'];

        // Cancel the draft transfer with a reason.
        $reason = 'Phase 7 test cancellation — stock error';
        $cancelled = $this->transferService->cancelTransfer($transfer->id, auth()->id(), $reason);
        $this->assertSame('cancelled', $cancelled->status);

        // Check user_audit_log for the transfer_cancelled event.
        $log = DB::table('user_audit_log')
            ->where('action', 'transfer_cancelled')
            ->where('user_id', auth()->id())
            ->first();

        $this->assertNotNull($log, 'Expected a transfer_cancelled audit log entry');

        $details = json_decode($log->details, true);
        $this->assertSame($transfer->id, $details['transfer_id']);
        $this->assertSame($transfer->transfer_code, $details['transfer_code']);
        $this->assertSame('cancelled', $details['status']);
        $this->assertSame($reason, $details['reason']);
        $this->assertSame('draft', $details['previous_status']);
    }

    // ====================================================================
    // Health check tests
    // ====================================================================

    /**
     * SCENARIO 4: Health checks detect cross-branch transfers.
     *
     * We manually insert a cross-branch warehouse_transfer (from and to
     * warehouses in different branches) via DB::table, bypassing the
     * service's same-branch enforcement. Then runHealthChecks() should
     * flag it as a cross_branch_manual violation.
     */
    public function test_health_checks_find_cross_branch_violations(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = $this->insertWarehouse($branchA->id);
        $whB = $this->insertWarehouse($branchB->id);
        $productId = $this->insertProduct();

        // Manually insert a cross-branch transfer (bypasses service validation).
        $transferId = DB::table('warehouse_transfers')->insertGetId([
            'transfer_code'   => 'WT-CROSS-' . substr(uniqid(), -6),
            'transfer_date'   => now()->format('Y-m-d'),
            'from_warehouse_id' => $whA,
            'to_warehouse_id'   => $whB,
            'from_branch_id'  => $branchA->id,
            'to_branch_id'    => $branchB->id,
            'is_interbranch'  => true,
            'status'          => 'confirmed',
            'is_reversed'     => false,
            'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
            'created_by'      => auth()->id(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('warehouse_transfer_items')->insert([
            'warehouse_transfer_id' => $transferId,
            'product_id'            => $productId,
            'qty'                   => 10,
            'rate'                  => 10,
            'fiscal_year_id'        => $this->resolveActiveFiscalYearId(),
        ]);

        // Run health checks (no branch filter = check all).
        $result = $this->auditService->runHealthChecks();

        // Find the cross_branch_manual item in the same_branch section.
        $sameBranchSection = null;
        foreach ($result['sections'] as $section) {
            if ($section['id'] === 'same_branch') {
                $sameBranchSection = $section;
                break;
            }
        }

        $this->assertNotNull($sameBranchSection, 'Expected same_branch section in health check results');

        $crossBranchItem = null;
        foreach ($sameBranchSection['items'] as $item) {
            if ($item['id'] === 'cross_branch_manual') {
                $crossBranchItem = $item;
                break;
            }
        }

        $this->assertNotNull($crossBranchItem, 'Expected cross_branch_manual item in same_branch section');
        $this->assertSame('fail', $crossBranchItem['status'], 'Cross-branch transfer should be flagged as fail');
    }

    /**
     * SCENARIO 5: Health checks detect confirmed transfers without stock movements.
     *
     * We create a draft transfer via the service, then manually set its
     * status to 'confirmed' without creating stock_transactions rows.
     * This simulates a data integrity issue where stock wasn't actually moved.
     * runHealthChecks() should flag it as missing stock movements.
     */
    public function test_health_checks_find_missing_stock_movements(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        $this->insertWarehouseStock($fromWhId, $productId, 200);

        // Create a draft transfer via the service (valid).
        $transfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 10, 'rate' => 10]],
        ));

        // Simulate data integrity issue: manually set status to 'confirmed'
        // without creating stock_transactions rows.
        DB::table('warehouse_transfers')
            ->where('id', $transfer->id)
            ->update(['status' => 'confirmed', 'updated_at' => now()]);

        // Run health checks for this branch.
        $result = $this->auditService->runHealthChecks($branch->id);

        // Find the stock movements section.
        $stockSection = null;
        foreach ($result['sections'] as $section) {
            if ($section['id'] === 'stock_gl') {
                $stockSection = $section;
                break;
            }
        }

        $this->assertNotNull($stockSection, 'Expected stock_gl section in health check results');

        $postedStockItem = null;
        foreach ($stockSection['items'] as $item) {
            if ($item['id'] === 'posted_stock') {
                $postedStockItem = $item;
                break;
            }
        }

        $this->assertNotNull($postedStockItem, 'Expected posted_stock item in stock_gl section');
        $this->assertSame('fail', $postedStockItem['status'], 'Confirmed transfer without stock movements should be flagged as fail');
    }

    /**
     * SCENARIO 6: Per-transfer checks pass for a valid same-branch
     * confirmed transfer.
     *
     * We create a draft, confirm it (which creates stock movements),
     * then run runTransferChecks() and verify all checks pass.
     */
    public function test_per_transfer_checks_pass_for_valid_transfer(): void
    {
        $scenario = $this->setupValidTransferScenario();
        $transfer = $scenario['transfer'];

        // Confirm the draft — creates stock movements.
        $confirmed = $this->transferService->confirmTransfer($transfer->id, auth()->id());
        $this->assertSame('confirmed', $confirmed->status);

        // Run per-transfer checks.
        $result = $this->auditService->runTransferChecks($transfer->id);

        $this->assertNotEmpty($result['items'], 'Expected at least some check items');

        // Same-branch check should pass.
        $sameBranchItem = null;
        foreach ($result['items'] as $item) {
            if ($item['id'] === 'same_branch') {
                $sameBranchItem = $item;
                break;
            }
        }
        $this->assertNotNull($sameBranchItem);
        $this->assertSame('pass', $sameBranchItem['status']);

        // Stock movements check should pass (confirmed transfer has movements).
        $stockItem = null;
        foreach ($result['items'] as $item) {
            if ($item['id'] === 'stock') {
                $stockItem = $item;
                break;
            }
        }
        $this->assertNotNull($stockItem);
        $this->assertSame('pass', $stockItem['status']);

        // No GL on internal transfer check should pass (same-branch, no journal).
        $glItem = null;
        foreach ($result['items'] as $item) {
            if ($item['id'] === 'gl_internal') {
                $glItem = $item;
                break;
            }
        }
        $this->assertNotNull($glItem);
        $this->assertSame('pass', $glItem['status']);

        // Summary should have no failures.
        $this->assertSame(0, $result['summary']['fail'], 'No failures expected for valid transfer');
    }
}
