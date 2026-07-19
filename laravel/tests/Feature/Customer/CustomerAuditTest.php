<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Customer Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Customer CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product: master_data_created/updated/
 * deleted/restored actions with details JSONB containing
 * table='customers' + record_id.
 *
 * Phase 10 commit.
 */
class CustomerAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given customer + action.
     */
    private function auditEntriesFor(Customer $customer, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['customers'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $customer->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AUDIT-CUST-C-01',
            'customer_name' => 'Audit Create Test',
        ]);

        $customer = Customer::where('customer_code', 'AUDIT-CUST-C-01')->first();

        $entries = $this->auditEntriesFor($customer, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AUDIT-CUST-N-01',
            'customer_name' => 'Audit New Field Test',
        ]);

        $customer = Customer::where('customer_code', 'AUDIT-CUST-N-01')->first();
        $entry = $this->auditEntriesFor($customer, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('customers', $details['table']);
        $this->assertEquals($customer->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-CUST-N-01', $details['new']['customer_code']);
        $this->assertEquals('Audit New Field Test', $details['new']['customer_name']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AUDIT-CUST-U-01',
            'customer_name' => 'User Capture Test',
        ]);

        $customer = Customer::where('customer_code', 'AUDIT-CUST-U-01')->first();
        $entry = $this->auditEntriesFor($customer, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $customer = Customer::factory()->create(['customer_name' => 'Old Name']);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'New Name',
            'is_active'     => true,
        ]);

        $entries = $this->auditEntriesFor($customer, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $customer = Customer::factory()->create([
            'customer_name'  => 'Original Name',
            'credit_limit'   => null,
        ]);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'Updated Name',
            'credit_limit'  => 999.99,
            'is_active'     => true,
        ]);

        $entry = $this->auditEntriesFor($customer, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Name', $details['old']['customer_name']);
        $this->assertEquals('Updated Name', $details['new']['customer_name']);
        $this->assertEquals('999.99', $details['new']['credit_limit']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $customer = Customer::factory()->create([
            'customer_name' => 'Stable Name',
            'credit_limit'  => null,
        ]);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'Stable Name', // unchanged
            'credit_limit'  => 12.34, // changed
            'is_active'     => true,
        ]);

        $entry = $this->auditEntriesFor($customer, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'credit_limit' but NOT
        // 'customer_name' (since it wasn't changed).
        $this->assertArrayHasKey('credit_limit', $details['new']);
        $this->assertArrayNotHasKey('customer_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $customer = Customer::factory()->create([
            'customer_name' => 'Same Name',
            'credit_limit'  => 50.00,
        ]);

        // Submit the same values
        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'Same Name',
            'credit_limit'  => 50.00,
            'is_active'     => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($customer, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $customer = Customer::factory()->create();

        $this->delete(route('admin.customers.destroy', $customer));

        $entries = $this->auditEntriesFor($customer, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $customer = Customer::factory()->create([
            'customer_code' => 'AUDIT-CUST-DEL-01',
            'customer_name' => 'Delete Audit Test',
        ]);

        $this->delete(route('admin.customers.destroy', $customer));

        $entry = $this->auditEntriesFor($customer, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-CUST-DEL-01', $details['old']['customer_code']);
        $this->assertEquals('Delete Audit Test', $details['old']['customer_name']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->post(route('admin.customers.restore', $customer));

        $entries = $this->auditEntriesFor($customer, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $customer = Customer::factory()->create(['customer_code' => 'AUDIT-CUST-RST-01']);
        $customer->delete();

        $this->post(route('admin.customers.restore', $customer));

        $entry = $this->auditEntriesFor($customer, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-CUST-RST-01', $details['new']['customer_code']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/customers/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->get(route('admin.customers.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.customers.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple customers with audit entries
        Customer::factory()->count(60)->create();

        $response = $this->get(route('admin.customers.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_customers_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a customer with its own audit entry to ensure the
        // page returns non-empty results to assert against.
        Customer::factory()->create();

        $response = $this->get(route('admin.customers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one customer entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('customers', $details['table'], 'Audit page should only show customers table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AUDIT-CUST-PERF-01',
            'customer_name' => 'Performer Name Test',
        ]);
        $customer = Customer::where('customer_code', 'AUDIT-CUST-PERF-01')->first();
        $this->assertNotNull($customer);

        $response = $this->get(route('admin.customers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS customer's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($customer) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $customer->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new customer');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->get(route('admin.customers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this customer's creation.
        $entry = $auditLogs->first(function ($log) use ($customer) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $customer->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for customer #{$customer->id}");
        $this->assertEquals($customer->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'LIFE-CUST-01',
            'customer_name' => 'Lifecycle Test',
        ]);
        $customer = Customer::where('customer_code', 'LIFE-CUST-01')->first();
        $this->assertCount(1, $this->auditEntriesFor($customer), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'LIFE-CUST-01',
            'customer_name' => 'Lifecycle Test Updated',
            'is_active'     => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($customer), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 10 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.customers.destroy', $customer));
        $this->assertCount(4, $this->auditEntriesFor($customer), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.customers.restore', $customer));
        $this->assertCount(7, $this->auditEntriesFor($customer), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
