<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsCustomerDependencies;
use Tests\TestCase;

/**
 * Customer CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle.
 *
 * Validates CustomerController (Phase 10: canDeactivate safety check +
 * auto-generated customer_code + pre-validation normalization) inheriting
 * from BaseMasterDataController.
 */
class CustomerCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsCustomerDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_customers(): void
    {
        Customer::factory()->count(3)->create();

        $response = $this->get(route('admin.customers.index'));

        $response->assertOk();
        $response->assertViewIs('admin.customers.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_customers(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $response = $this->get(route('admin.customers.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        Customer::factory()->count(2)->create();

        $response = $this->get(route('admin.customers.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_customer_count(): void
    {
        Customer::factory()->count(2)->create();
        Customer::factory()->inactive()->create();

        $response = $this->get(route('admin.customers.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_branch_and_salesperson_names(): void
    {
        $branch = Branch::factory()->create();
        $salesPerson = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        $customer = Customer::factory()
            ->forBranch($branch->id)
            ->forSalesPerson($salesPerson->id)
            ->create();

        $response = $this->get(route('admin.customers.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $customer->id);
        $this->assertNotNull($row, 'DataTables response should include the created customer');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.customers.create'));

        $response->assertOk();
        $response->assertViewIs('admin.customers.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'branches', 'salesPersons']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_customer_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            'customer_code' => 'CUST-ST-001',
            'customer_name' => 'Test Customer Store',
            'mobile'        => '01711000000',
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'customer_code' => 'CUST-ST-001',
            'customer_name' => 'Test Customer Store',
            'mobile'        => '01711000000',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            'customer_code' => 'CUST-REDIR-01',
            'customer_name' => 'Show Redirect Test',
        ]);

        $customer = Customer::where('customer_code', 'CUST-REDIR-01')->first();
        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHas('success');
    }

    public function test_store_auto_generates_customer_code_when_blank(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            // customer_code intentionally omitted
            'customer_name' => 'Auto Code Customer',
        ]);

        $response->assertRedirect();
        $customer = Customer::where('customer_name', 'Auto Code Customer')->first();
        $this->assertNotNull($customer);
        $this->assertMatchesRegularExpression('/^CUS-\d{4}-\d{6}$/', $customer->customer_code);
    }

    public function test_store_auto_generates_customer_code_when_empty_string(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => '',
            'customer_name' => 'Empty Code Customer',
        ]);

        $customer = Customer::where('customer_name', 'Empty Code Customer')->first();
        $this->assertNotNull($customer);
        $this->assertNotEmpty($customer->customer_code);
    }

    public function test_store_fails_on_duplicate_customer_code(): void
    {
        Customer::factory()->create(['customer_code' => 'DUP-CUST-001']);

        $response = $this->post(route('admin.customers.store'), [
            'customer_code' => 'DUP-CUST-001',
            'customer_name' => 'Duplicate Test',
        ]);

        $response->assertSessionHasErrors('customer_code');
    }

    public function test_store_fails_when_customer_name_missing(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            'customer_code' => 'MISSING-NAME-CUST-01',
        ]);

        $response->assertSessionHasErrors('customer_name');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            'customer_code' => 'MIN-CUST-01',
            'customer_name' => 'Minimal Customer',
            // phone, mobile, email, address, branch_id, sales_person_id omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'customer_code' => 'MIN-CUST-01',
            'customer_name' => 'Minimal Customer',
        ]);
    }

    public function test_store_links_to_branch_and_salesperson(): void
    {
        $branch = Branch::factory()->create();
        $salesPerson = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();

        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'CUST-BS-01',
            'customer_name'   => 'Branch+SP Customer',
            'branch_id'       => $branch->id,
            'sales_person_id' => $salesPerson->id,
        ]);

        $this->assertDatabaseHas('customers', [
            'customer_code'   => 'CUST-BS-01',
            'branch_id'       => $branch->id,
            'sales_person_id' => $salesPerson->id,
        ]);
    }

    public function test_store_stores_numeric_credit_limit_and_opening_balance(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'CUST-RATE-01',
            'customer_name'   => 'Rate Test',
            'credit_limit'    => 5000.50,
            'opening_balance' => 1200.75,
            'balance_type'    => 'debit',
        ]);

        $customer = Customer::where('customer_code', 'CUST-RATE-01')->first();
        $this->assertEquals('5000.50', (string) $customer->credit_limit);
        $this->assertEquals('1200.75', (string) $customer->opening_balance);
        $this->assertEquals('debit', $customer->balance_type);
    }

    public function test_store_uppercases_customer_code_before_unique_check(): void
    {
        // Phase 10: customer_code is uppercased + trimmed BEFORE validation.
        // 'lower-01' becomes 'LOWER-01' before unique check.
        Customer::factory()->create(['customer_code' => 'UPPER-01']);

        // 'upper-01' should collide after normalization
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'upper-01',
            'customer_name' => 'Case Collision Test',
        ])->assertSessionHasErrors('customer_code');
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_customer_details(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->get(route('admin.customers.show', $customer));

        $response->assertOk();
        $response->assertViewIs('admin.customers.show');
        $response->assertViewHas('item');
        $this->assertEquals($customer->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_branch_and_salesperson(): void
    {
        $branch = Branch::factory()->create();
        $salesPerson = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        $customer = Customer::factory()
            ->forBranch($branch->id)
            ->forSalesPerson($salesPerson->id)
            ->create();

        $response = $this->get(route('admin.customers.show', $customer));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('branch'));
        $this->assertTrue($item->relationLoaded('salesPerson'));
    }

    public function test_show_works_for_soft_deleted_customer(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.customers.show', $customer));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_customer(): void
    {
        $this->get(route('admin.customers.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_customer(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->get(route('admin.customers.edit', $customer));

        $response->assertOk();
        $response->assertViewIs('admin.customers.edit');
        $response->assertViewHas('item');
        $this->assertEquals($customer->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_customer_and_redirects_to_show(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'Updated Customer Name',
            'is_active'     => true,
        ]);

        $response->assertRedirect(route('admin.customers.show', $customer));
        $this->assertDatabaseHas('customers', [
            'id'            => $customer->id,
            'customer_name' => 'Updated Customer Name',
        ]);
    }

    public function test_update_allows_changing_customer_code_to_unique_value(): void
    {
        $customer = Customer::factory()->create(['customer_code' => 'OLD-CUST-01']);

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'NEW-CUST-01',
            'customer_name' => $customer->customer_name,
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id'            => $customer->id,
            'customer_code' => 'NEW-CUST-01',
        ]);
    }

    public function test_update_fails_on_duplicate_customer_code_from_other_customer(): void
    {
        Customer::factory()->create(['customer_code' => 'TAKEN-CUST-01']);
        $customer = Customer::factory()->create(['customer_code' => 'OWN-CUST-01']);

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'TAKEN-CUST-01',
            'customer_name' => $customer->customer_name,
            'is_active'     => true,
        ]);

        $response->assertSessionHasErrors('customer_code');
    }

    public function test_update_allows_keeping_own_customer_code(): void
    {
        $customer = Customer::factory()->create(['customer_code' => 'KEEP-CUST-01']);

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'KEEP-CUST-01',
            'customer_name' => 'New Name Same Code',
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id'            => $customer->id,
            'customer_code' => 'KEEP-CUST-01',
            'customer_name' => 'New Name Same Code',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Customer with outstanding AR balance → deactivation should be blocked
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 250.00, 'debit');

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => $customer->customer_name,
            'is_active'     => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_update_deactivates_customer_when_no_blockers(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => $customer->customer_name,
            'is_active'     => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($customer->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_customer_with_no_blockers(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->delete(route('admin.customers.destroy', $customer));

        $response->assertRedirect(route('admin.customers.index'));
        $response->assertSessionHas('success');

        $customer->refresh();
        $this->assertNotNull($customer->deleted_at);
        $this->assertFalse($customer->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $customer = Customer::factory()->create();

        $this->delete(route('admin.customers.destroy', $customer));

        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_customer_has_outstanding_ar_balance(): void
    {
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 1000.00, 'debit');

        $response = $this->delete(route('admin.customers.destroy', $customer));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_customer_has_open_sales_invoice(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed');

        $response = $this->delete(route('admin.customers.destroy', $customer));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_customer(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $response = $this->post(route('admin.customers.restore', $customer));

        $response->assertRedirect(route('admin.customers.show', $customer));
        $response->assertSessionHas('success');

        $customer->refresh();
        $this->assertNull($customer->deleted_at);
        $this->assertNull($customer->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_customer(): void
    {
        $customer = Customer::factory()->create(); // not deleted

        $response = $this->post(route('admin.customers.restore', $customer));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_customer(): void
    {
        $this->post(route('admin.customers.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_customer_count_increments_after_store(): void
    {
        $initialCount = Customer::count();

        $this->post(route('admin.customers.store'), [
            'customer_code' => 'COUNT-CUST-01',
            'customer_name' => 'Count Test',
        ]);

        $this->assertEquals($initialCount + 1, Customer::count());
    }

    public function test_soft_deleted_customer_excluded_from_default_index_query(): void
    {
        $toDelete = Customer::factory()->create(['customer_name' => 'Hide Me From Default']);
        $keep = Customer::factory()->create(['customer_name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.customers.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one customer');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 10)
    // ====================================================================

    public function test_toggle_deactivates_active_customer_with_no_blockers(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->post(route('admin.customers.toggle', $customer));

        $response->assertRedirect(route('admin.customers.index'));
        $response->assertSessionHas('success');

        $customer->refresh();
        $this->assertFalse($customer->is_active);
        $this->assertNotNull($customer->deleted_at);
    }

    public function test_toggle_activates_inactive_customer(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $response = $this->post(route('admin.customers.toggle', $customer));

        $response->assertRedirect(route('admin.customers.index'));
        $customer->refresh();
        $this->assertTrue($customer->is_active);
        $this->assertNull($customer->deleted_at);
    }

    public function test_toggle_blocked_when_customer_has_outstanding_balance(): void
    {
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 100.00, 'debit');

        $response = $this->post(route('admin.customers.toggle', $customer));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('AR balance', session('error'));
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_customer(): void
    {
        $this->post(route('admin.customers.toggle', 999999))
            ->assertNotFound();
    }
}
