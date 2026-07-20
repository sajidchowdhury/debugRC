<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Phase 13 — Lookup API tests.
 *
 * Covers the 6 lookup endpoints used to populate mobile-app dropdowns:
 *   - GET /api/v1/lookups/branches   active branches (id + code + name)
 *   - GET /api/v1/lookups/warehouses  warehouses (?branch_id filter)
 *   - GET /api/v1/lookups/products    active products (id + code + name + price)
 *   - GET /api/v1/lookups/customers   active customers (id + code + name + mobile)
 *   - GET /api/v1/lookups/suppliers   active suppliers (id + code + name + mobile)
 *   - GET /api/v1/lookups/ledgers      active ledgers (id + code + name + type + nature)
 */
class LookupApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_branches_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/branches')->assertUnauthorized();
    }

    public function test_warehouses_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/warehouses')->assertUnauthorized();
    }

    public function test_products_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/products')->assertUnauthorized();
    }

    public function test_customers_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/customers')->assertUnauthorized();
    }

    public function test_suppliers_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/suppliers')->assertUnauthorized();
    }

    public function test_ledgers_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/lookups/ledgers')->assertUnauthorized();
    }

    // ====================================================================
    // BRANCHES LOOKUP
    // ====================================================================

    public function test_branches_lookup_returns_only_active_branches_with_slim_fields(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $active = Branch::factory()->create(['branch_name' => 'Active Branch Lookup']);
        $inactive = Branch::factory()->create(['branch_name' => 'Inactive Branch Lookup']);
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/branches');

        $response->assertOk();
        $data = collect($response->json('data'));

        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));

        // Each row should have only the slim fields (id + branch_code + branch_name).
        $row = $data->firstWhere('id', $active->id);
        $this->assertArrayHasKey('branch_code', $row);
        $this->assertArrayHasKey('branch_name', $row);
        $this->assertArrayNotHasKey('address', $row);
        $this->assertArrayNotHasKey('phone', $row);
    }

    // ====================================================================
    // WAREHOUSES LOOKUP
    // ====================================================================

    public function test_warehouses_lookup_returns_all_active_when_no_branch_filter(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = Warehouse::factory()->forBranch($branchA->id)->create();
        $whB = Warehouse::factory()->forBranch($branchB->id)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/warehouses');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $whA->id));
        $this->assertTrue($data->contains('id', $whB->id));
    }

    public function test_warehouses_lookup_filters_by_branch_id(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = Warehouse::factory()->forBranch($branchA->id)->create();
        $whB = Warehouse::factory()->forBranch($branchB->id)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/lookups/warehouses?branch_id={$branchA->id}");

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $whA->id));
        $this->assertFalse($data->contains('id', $whB->id));
    }

    public function test_warehouses_lookup_excludes_inactive_warehouses(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();
        $active = Warehouse::factory()->forBranch($branch->id)->create(['warehouse_name' => 'Live WH']);
        $inactive = Warehouse::factory()->forBranch($branch->id)->create(['warehouse_name' => 'Dead WH']);
        $inactive->is_active = false;
        $inactive->save();
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/lookups/warehouses?branch_id={$branch->id}");

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));
    }

    // ====================================================================
    // PRODUCTS LOOKUP
    // ====================================================================

    public function test_products_lookup_returns_only_active_products(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $active = Product::factory()->create(['product_name' => 'Lookup Active Product']);
        $inactive = Product::factory()->create(['product_name' => 'Lookup Inactive Product']);
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/products');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));

        $row = $data->firstWhere('id', $active->id);
        $this->assertArrayHasKey('product_code', $row);
        $this->assertArrayHasKey('product_name', $row);
        $this->assertArrayHasKey('unit', $row);
        $this->assertArrayHasKey('sales_rate', $row);
        $this->assertArrayNotHasKey('purchase_rate', $row);
    }

    // ====================================================================
    // CUSTOMERS LOOKUP
    // ====================================================================

    public function test_customers_lookup_returns_only_active_customers(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $active = Customer::factory()->create(['customer_name' => 'Lookup Active Customer']);
        $inactive = Customer::factory()->create(['customer_name' => 'Lookup Inactive Customer']);
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/customers');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));

        $row = $data->firstWhere('id', $active->id);
        $this->assertArrayHasKey('customer_code', $row);
        $this->assertArrayHasKey('customer_name', $row);
        $this->assertArrayHasKey('mobile', $row);
        $this->assertArrayNotHasKey('address', $row);
    }

    // ====================================================================
    // SUPPLIERS LOOKUP
    // ====================================================================

    public function test_suppliers_lookup_returns_only_active_suppliers(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $active = Supplier::factory()->create(['supplier_name' => 'Lookup Active Supplier']);
        $inactive = Supplier::factory()->create(['supplier_name' => 'Lookup Inactive Supplier']);
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/suppliers');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));

        $row = $data->firstWhere('id', $active->id);
        $this->assertArrayHasKey('supplier_code', $row);
        $this->assertArrayHasKey('supplier_name', $row);
        $this->assertArrayHasKey('mobile', $row);
        $this->assertArrayNotHasKey('address', $row);
    }

    // ====================================================================
    // LEDGERS LOOKUP
    // ====================================================================

    public function test_ledgers_lookup_returns_only_active_ledgers(): void
    {
        $user  = $this->makeRoleUser('accountant');
        $token = $this->apiTokenForUser($user);

        $active = Ledger::factory()->create([
            'ledger_name' => 'Lookup Active Ledger',
            'account_type' => 'Asset',
        ]);
        $inactive = Ledger::factory()->create([
            'ledger_name' => 'Lookup Inactive Ledger',
            'account_type' => 'Asset',
        ]);
        $inactive->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/ledgers');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->contains('id', $active->id));
        $this->assertFalse($data->contains('id', $inactive->id));

        $row = $data->firstWhere('id', $active->id);
        $this->assertArrayHasKey('ledger_code', $row);
        $this->assertArrayHasKey('ledger_name', $row);
        $this->assertArrayHasKey('account_type', $row);
        $this->assertArrayHasKey('ledger_nature', $row);
        $this->assertArrayNotHasKey('opening_balance', $row);
        $this->assertArrayNotHasKey('description', $row);
    }
}
