<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\Branch;
use App\Services\Sales\BelowMinApprovalService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * BelowMinApprovalService Unit Tests — Session 10.
 *
 * Verifies the S6 below-min admin override workflow:
 *
 *   - approve() succeeds with valid admin credentials + sufficient reason.
 *   - approve() succeeds with valid manager credentials (manager is allowed).
 *   - approve() throws on invalid credentials (brute-force defense).
 *   - approve() throws when approver role is insufficient (cashier cannot
 *     approve — privilege-escalation defense).
 *   - approve() throws when reason is < 10 chars (audit-quality defense).
 *   - approve() throws when rate is NOT below min (no override needed).
 *   - isValidOverride() returns true for a real audit log row, false
 *     otherwise (used by SalesInvoiceService::finalizeFromCart to gate
 *     finalization of below-min_pending lines).
 *
 * The risk register (S6 row) flags "Below-min approval modal is bypassed
 * by a custom API client (no UI enforcement)" as Low-likelihood /
 * Medium-impact, with mitigation: "The BelowMinApprovalService::approve()
 * method is the source of truth, not the modal. The cart's finalize()
 * method hard-fails if any line is below_min_pending without an attached
 * below_min_override_id. API clients cannot bypass this."
 *
 * The finalize-block test is a Feature test (separate file — see
 * BranchPnlReportControllerTest for the pattern). This file covers
 * the service-level unit tests.
 *
 * @see \App\Services\Sales\BelowMinApprovalService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md
 *      Risk Register row "Below-min approval modal is bypassed..."
 */
class BelowMinApprovalServiceTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsProductDependencies;

    private BelowMinApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BelowMinApprovalService::class);
    }

    private function insertProductWithPriceRange(float $minRate, float $maxRate, float $defaultRate): int
    {
        $categoryId = $this->insertProductCategory();
        $groupId    = $this->insertProductGroup();

        $productId = DB::table('products')->insertGetId([
            'product_code'  => 'P-' . substr(uniqid(), -6),
            'product_name'  => 'Test Product ' . uniqid(),
            'category_id'   => $categoryId,
            'group_id'      => $groupId,
            'unit'          => 'Pcs',  // CHECK (unit IN ('Pcs','Carton','KG','Bag','Dobe','Set'))
            'purchase_rate' => $minRate,
            'sales_rate'    => $defaultRate,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Insert a product_price_history row effective today.
        DB::table('product_price_history')->insert([
            'product_id'    => $productId,
            'min_rate'      => $minRate,
            'max_rate'      => $maxRate,
            'default_rate'  => $defaultRate,
            'effective_from'=> now()->toDateString(),
            'effective_to'  => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $productId;
    }

    private function baseApproveData(int $productId, float $minRate, float $maxRate, float $defaultRate, array $overrides = []): array
    {
        $branch = Branch::factory()->create();

        return array_merge([
            'approver_username'  => '',  // set by test
            'approver_password'  => 'password',  // BuildsRoleUsers default
            'product_id'         => $productId,
            'product_name'       => 'Test Product',
            'requested_rate'     => $minRate - 1.0,  // 1 below min
            'min_rate'           => $minRate,
            'max_rate'           => $maxRate,
            'default_rate'       => $defaultRate,
            'reason'             => 'Customer is a long-term wholesale buyer with negotiated discount.',
            'customer_id'        => 1,
            'branch_id'          => $branch->id,
            'cart_id'            => null,
            'sale_line_index'    => 0,
            'cashier_user_id'    => 1,
        ], $overrides);
    }

    // ===================== approve() — happy paths =====================

    public function test_approve_succeeds_with_admin_credentials(): void
    {
        $admin = $this->makeRoleUser('admin');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
        ]);

        $result = $this->service->approve($data);

        $this->assertArrayHasKey('audit_log_id', $result);
        $this->assertArrayHasKey('approver_user_id', $result);
        $this->assertSame($admin->id, $result['approver_user_id']);
        $this->assertGreaterThan(0, $result['audit_log_id']);

        // Verify the audit log row was actually written.
        $auditRow = DB::table('user_audit_log')->where('id', $result['audit_log_id'])->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('below_min_override', $auditRow->action);
        $this->assertSame($admin->id, $auditRow->user_id);
    }

    public function test_approve_succeeds_with_manager_credentials(): void
    {
        $manager = $this->makeRoleUser('manager');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $manager->username,
        ]);

        $result = $this->service->approve($data);

        $this->assertSame($manager->id, $result['approver_user_id']);
    }

    // ===================== approve() — failure paths =====================

    public function test_approve_throws_on_invalid_credentials(): void
    {
        $admin = $this->makeRoleUser('admin');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
            'approver_password' => 'wrong-password',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid approver credentials/');

        $this->service->approve($data);
    }

    public function test_approve_throws_when_approver_role_is_insufficient(): void
    {
        // Privilege-escalation defense: a cashier cannot approve even
        // with valid credentials.
        $cashier = $this->makeRoleUser('salesman');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $cashier->username,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/role|insufficient|unauthorized/i');

        $this->service->approve($data);
    }

    public function test_approve_throws_when_reason_is_too_short(): void
    {
        $admin = $this->makeRoleUser('admin');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
            'reason'            => 'short',  // < 10 chars
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reason.*10.*characters/i');

        $this->service->approve($data);
    }

    public function test_approve_throws_when_rate_is_not_below_min(): void
    {
        $admin = $this->makeRoleUser('admin');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
            'requested_rate'    => 10.0,  // = min, NOT below
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not below min|no override needed/i');

        $this->service->approve($data);
    }

    public function test_approve_throws_when_approver_is_inactive(): void
    {
        $admin = $this->makeRoleUser('admin');
        DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);

        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);
        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found|inactive/i');

        $this->service->approve($data);
    }

    // ===================== isValidOverride() =====================

    public function test_is_valid_override_returns_true_for_real_audit_log_row(): void
    {
        $admin = $this->makeRoleUser('admin');
        $productId = $this->insertProductWithPriceRange(10.0, 15.0, 12.0);

        $data = $this->baseApproveData($productId, 10.0, 15.0, 12.0, [
            'approver_username' => $admin->username,
        ]);
        $result = $this->service->approve($data);

        $this->assertTrue($this->service->isValidOverride($result['audit_log_id']));
    }

    public function test_is_valid_override_returns_false_for_nonexistent_id(): void
    {
        $this->assertFalse($this->service->isValidOverride(999999999));
    }

    public function test_is_valid_override_returns_false_for_zero_id(): void
    {
        $this->assertFalse($this->service->isValidOverride(0));
    }

    public function test_is_valid_override_returns_false_for_wrong_action(): void
    {
        // Insert an audit log row with a DIFFERENT action (not below_min_override).
        $auditId = DB::table('user_audit_log')->insertGetId([
            'user_id'    => 1,
            'action'     => 'some_other_action',
            'created_at' => now(),
        ]);

        $this->assertFalse(
            $this->service->isValidOverride($auditId),
            'An audit log row with a non-below_min_override action must NOT validate.'
        );
    }
}
