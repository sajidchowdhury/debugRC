<?php

namespace Tests\Feature\Api\V1\Sales;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Sales Return API Feature Tests — Task 37 / API-4 (G3 backfill).
 *
 * Tests the SalesReturnApiController's REST API endpoints:
 *   GET    /api/v1/sales/returns                       List returns (filtered)
 *   GET    /api/v1/sales/returns/invoice-details       Pre-populate return form
 *   GET    /api/v1/sales/returns/{id}                  Show return detail
 *   POST   /api/v1/sales/returns                       Create return (created state)
 *   POST   /api/v1/sales/returns/{id}/confirm          Confirm (stock IN + GL)
 *   POST   /api/v1/sales/returns/{id}/reverse          Reverse a confirmed return
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *   - non-salesman token on POST store → 403 (api.auth:salesman,manager,admin)
 *   - salesman token on POST confirm → 403 (api.auth:warehouse_manager,
 *     accountant,manager,admin — salesman is NOT in the confirm gate).
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 *
 * NOTE: the full create→confirm→reverse lifecycle requires heavy fixtures
 * (sales invoice items + challan stock_transaction snapshot for the
 * original_cost lookup + GL journals). Following the CommissionApiTest
 * pattern, this backfill covers AUTH + read-side HAPPY paths + VALIDATION
 * + state-transition role gating — the service-layer lifecycle is covered
 * by the existing tests/Feature/Sales/* suite.
 */
class SalesReturnApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens, InsertsBranchDependencies;

    private User $adminUser;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->makeRoleUser('admin');
        $this->branchId  = $this->adminUser->getBranchId();
    }

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_list_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/sales/returns')->assertUnauthorized();
    }

    public function test_list_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/sales/returns')
            ->assertUnauthorized();
    }

    public function test_store_returns_403_for_non_salesman_role(): void
    {
        // 'accountant' is NOT in api.auth:salesman,manager,admin on store.
        $accountant = $this->makeRoleUser('accountant');
        $token      = $this->apiTokenForUser($accountant);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/returns', [])
            ->assertForbidden();
    }

    public function test_confirm_returns_403_for_salesman(): void
    {
        // 'salesman' is NOT in api.auth:warehouse_manager,accountant,manager,admin.
        $salesman = $this->makeRoleUser('salesman');
        $token    = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/returns/1/confirm')
            ->assertForbidden();
    }

    // ====================================================================
    // LIST (index)
    // ====================================================================

    public function test_list_returns_paginated_json_with_valid_token(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/returns');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_list_supports_customer_filter(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);

        // Insert a return directly so the filter has something to match.
        $returnId = $this->insertSalesReturn($customerId, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/returns?customer_id={$customerId}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        // The SalesReturnResource nests customer as {id, name}, not as
        // a top-level customer_id. Check the nested relation instead.
        collect($data)->each(fn ($row) => $this->assertSame($customerId, $row['customer']['id']));
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_returns_return_detail_with_valid_token(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $returnId   = $this->insertSalesReturn($customerId, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/returns/{$returnId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $returnId);
        // Customer is nested in the resource, not a top-level customer_id.
        $response->assertJsonPath('data.customer.id', $customerId);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/returns/9999999')
            ->assertNotFound();
    }

    // ====================================================================
    // INVOICE-DETAILS (return form pre-population)
    // ====================================================================

    public function test_invoice_details_returns_200_with_invoice_data(): void
    {
        $token     = $this->apiTokenForUser($this->adminUser);
        $invoiceId = $this->insertSalesInvoice($this->branchId, 'confirmed');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/returns/invoice-details?sales_invoice_id={$invoiceId}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['invoice_id', 'invoice_code', 'customer', 'challan_id', 'items'],
        ]);
        $this->assertSame($invoiceId, $response->json('data.invoice_id'));
    }

    public function test_invoice_details_returns_422_when_invoice_id_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/returns/invoice-details')
            ->assertStatus(422);
    }

    // ====================================================================
    // STORE (validation path — heavy happy-path fixtures out of scope)
    // ====================================================================

    public function test_store_returns_422_when_required_field_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/returns', [
                // sales_invoice_id missing
                'customer_id' => $this->insertCustomer($this->branchId),
                'return_date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_store_returns_422_when_items_array_missing(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $invoiceId  = $this->insertSalesInvoice($this->branchId, 'confirmed');

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/returns', [
                'sales_invoice_id' => $invoiceId,
                'customer_id'      => $customerId,
                'return_date'      => now()->toDateString(),
                // items missing
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // REVERSE (validation path — min:10 chars enforced)
    // ====================================================================

    public function test_reverse_returns_422_when_reason_too_short(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $returnId   = $this->insertSalesReturn($customerId, $this->branchId);

        // 'short' is 5 chars — below the min:10 floor on the reason field.
        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/sales/returns/{$returnId}/reverse", [
                'reason' => 'short',
            ])
            ->assertStatus(422);
    }

    /**
     * Insert a sales_returns row with the minimum required columns.
     *
     * Bypasses SalesReturnService::createReturn so we can test the read
     * endpoints without standing up the full invoice-items + challan
     * stock_transaction snapshot chain. Mirrors the InsertsBranchDependencies
     * direct-DB::table pattern.
     */
    private function insertSalesReturn(int $customerId, int $branchId, string $status = 'created'): int
    {
        return DB::table('sales_returns')->insertGetId([
            'return_code'       => 'RET-' . substr(uniqid(), -6),
            'return_date'       => now()->toDateString(),
            'sales_invoice_id'  => $this->insertSalesInvoice($branchId, 'confirmed'),
            'customer_id'       => $customerId,
            'branch_id'         => $branchId,
            'total_amount'      => 0,
            'cogs_amount'       => 0,
            'status'            => $status,
            'is_reversed'       => false,
            'fiscal_year_id'    => $this->resolveActiveFiscalYearId(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
