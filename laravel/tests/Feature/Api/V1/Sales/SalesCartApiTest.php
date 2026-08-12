<?php

namespace Tests\Feature\Api\V1\Sales;

use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Sales Cart API Feature Tests — Task 37 / API-4 (G3 backfill).
 *
 * Tests the SalesCartApiController's REST API endpoints:
 *   GET    /api/v1/sales/cart              Load cart for a customer
 *   POST   /api/v1/sales/cart              Add item to cart (salesman/manager/admin)
 *   PUT    /api/v1/sales/cart              Update cart item (salesman/manager/admin)
 *   DELETE /api/v1/sales/cart/{productId}  Remove item from cart
 *   POST   /api/v1/sales/cart/clear        Clear entire cart
 *   POST   /api/v1/sales/cart/validate     Validate cart (pre-finalize check)
 *   POST   /api/v1/sales/cart/soft-hold    Toggle soft-hold
 *   GET    /api/v1/sales/cart/availability Check product stock availability
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *   - non-salesman/manager/admin token on POST → 403 (route-level
 *     `api.auth:salesman,manager,admin` gate added in G-086).
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 *
 * NOTE: the cart is per-user-per-customer-per-branch (R6 unique key). The
 * full add→update→remove→clear happy-path lifecycle requires sales-pipeline
 * + price-range fixtures that are out of scope for this backfill; the
 * existing CommissionApiTest pattern is followed — AUTH + VALIDATION +
 * the read-side happy paths (show empty cart + availability lookup) which
 * exercise the controller + middleware without depending on the heavy
 * SalesCartService validation pipeline.
 */
class SalesCartApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;
    use InsertsBranchDependencies, InsertsWarehouseDependencies, InsertsProductDependencies;

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

    public function test_show_cart_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/sales/cart')->assertUnauthorized();
    }

    public function test_show_cart_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/sales/cart')
            ->assertUnauthorized();
    }

    public function test_store_cart_item_returns_403_for_non_salesman_role(): void
    {
        // 'accountant' is NOT in the api.auth:salesman,manager,admin gate
        // on POST /sales/cart (G-086 route-level role enforcement).
        $accountant = $this->makeRoleUser('accountant');
        $token      = $this->apiTokenForUser($accountant);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart', [
                'customer_id' => 1,
                'product_id'  => 1,
                'qty'         => 1,
                'rate'        => 10.00,
            ])
            ->assertForbidden();
    }

    // ====================================================================
    // SHOW (read cart)
    // ====================================================================

    public function test_show_cart_returns_200_with_empty_cart_for_new_customer(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/cart?customer_id={$customerId}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['cart', 'items', 'subtotal', 'validation'],
        ]);
        $this->assertSame(0, $response->json('data.subtotal'));
        $this->assertEmpty($response->json('data.items'));
    }

    public function test_show_cart_returns_422_when_customer_id_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/cart')
            ->assertStatus(422);
    }

    public function test_show_cart_returns_422_when_customer_does_not_exist(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/cart?customer_id=9999999')
            ->assertStatus(422);
    }

    // ====================================================================
    // AVAILABILITY (read stock info for a product)
    // ====================================================================

    public function test_availability_returns_200_with_stock_info_for_product(): void
    {
        $token     = $this->apiTokenForUser($this->adminUser);
        $productId = $this->insertProduct();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/cart/availability?product_id={$productId}");

        $response->assertOk();
        $response->assertJsonStructure([
            'product_id',
            'branch_id',
            'available_qty',
            'warehouse_breakdown',
        ]);
        $this->assertSame($productId, $response->json('product_id'));
    }

    public function test_availability_returns_422_when_product_id_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/cart/availability')
            ->assertStatus(422);
    }

    // ====================================================================
    // STORE (validation path — heavy happy-path fixtures out of scope)
    // ====================================================================

    public function test_store_cart_item_returns_422_when_required_field_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart', [
                'customer_id' => $this->insertCustomer($this->branchId),
                // product_id missing
                'qty'  => 1,
                'rate' => 10.00,
            ])
            ->assertStatus(422);
    }

    public function test_store_cart_item_returns_422_for_invalid_qty(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $productId  = $this->insertProduct();

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart', [
                'customer_id' => $customerId,
                'product_id'  => $productId,
                'qty'         => 0,     // min:0.001
                'rate'        => 10.00,
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // CLEAR + SOFT-HOLD (validation paths)
    // ====================================================================

    public function test_clear_cart_returns_422_when_customer_id_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart/clear', [])
            ->assertStatus(422);
    }

    public function test_soft_hold_returns_422_when_soft_hold_missing(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart/soft-hold', [
                'customer_id' => $customerId,
                // soft_hold missing
            ])
            ->assertStatus(422);
    }

    public function test_validate_cart_returns_200_with_validation_payload(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/cart/validate', [
                'customer_id' => $customerId,
            ]);

        // Empty cart → valid:true (nothing to fail validation).
        $response->assertOk();
        $response->assertJsonStructure(['valid', 'message', 'stock_errors', 'rate_errors']);
    }
}
