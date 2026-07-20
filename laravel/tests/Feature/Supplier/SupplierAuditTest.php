<?php

namespace Tests\Feature\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Supplier Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Supplier CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product/Customer: master_data_created/
 * updated/deleted/restored actions with details JSONB containing
 * table='suppliers' + record_id.
 *
 * Phase 11 commit.
 */
class SupplierAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given supplier + action.
     */
    private function auditEntriesFor(Supplier $supplier, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['suppliers'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $supplier->id]);

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
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AUDIT-SUP-C-01',
            'supplier_name' => 'Audit Create Test',
        ]);

        $supplier = Supplier::where('supplier_code', 'AUDIT-SUP-C-01')->first();

        $entries = $this->auditEntriesFor($supplier, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AUDIT-SUP-N-01',
            'supplier_name' => 'Audit New Field Test',
        ]);

        $supplier = Supplier::where('supplier_code', 'AUDIT-SUP-N-01')->first();
        $entry = $this->auditEntriesFor($supplier, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('suppliers', $details['table']);
        $this->assertEquals($supplier->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-SUP-N-01', $details['new']['supplier_code']);
        $this->assertEquals('Audit New Field Test', $details['new']['supplier_name']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AUDIT-SUP-U-01',
            'supplier_name' => 'User Capture Test',
        ]);

        $supplier = Supplier::where('supplier_code', 'AUDIT-SUP-U-01')->first();
        $entry = $this->auditEntriesFor($supplier, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $supplier = Supplier::factory()->create(['supplier_name' => 'Old Name']);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => 'New Name',
            'is_active'     => true,
        ]);

        $entries = $this->auditEntriesFor($supplier, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $supplier = Supplier::factory()->create([
            'supplier_name'   => 'Original Name',
            'contact_person'  => null,
        ]);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code'  => $supplier->supplier_code,
            'supplier_name'  => 'Updated Name',
            'contact_person' => 'Jane Doe',
            'is_active'      => true,
        ]);

        $entry = $this->auditEntriesFor($supplier, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Name', $details['old']['supplier_name']);
        $this->assertEquals('Updated Name', $details['new']['supplier_name']);
        $this->assertEquals('Jane Doe', $details['new']['contact_person']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $supplier = Supplier::factory()->create([
            'supplier_name'  => 'Stable Name',
            'contact_person' => null,
        ]);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code'  => $supplier->supplier_code,
            'supplier_name'  => 'Stable Name', // unchanged
            'contact_person' => 'Bob Smith', // changed
            'is_active'      => true,
        ]);

        $entry = $this->auditEntriesFor($supplier, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'contact_person' but NOT
        // 'supplier_name' (since it wasn't changed).
        $this->assertArrayHasKey('contact_person', $details['new']);
        $this->assertArrayNotHasKey('supplier_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $supplier = Supplier::factory()->create([
            'supplier_name'  => 'Same Name',
            'contact_person' => 'Stable Person',
        ]);

        // Submit the same values
        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code'  => $supplier->supplier_code,
            'supplier_name'  => 'Same Name',
            'contact_person' => 'Stable Person',
            'is_active'      => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($supplier, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $supplier = Supplier::factory()->create();

        $this->delete(route('admin.suppliers.destroy', $supplier));

        $entries = $this->auditEntriesFor($supplier, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $supplier = Supplier::factory()->create([
            'supplier_code' => 'AUDIT-SUP-DEL-01',
            'supplier_name' => 'Delete Audit Test',
        ]);

        $this->delete(route('admin.suppliers.destroy', $supplier));

        $entry = $this->auditEntriesFor($supplier, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-SUP-DEL-01', $details['old']['supplier_code']);
        $this->assertEquals('Delete Audit Test', $details['old']['supplier_name']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $this->post(route('admin.suppliers.restore', $supplier));

        $entries = $this->auditEntriesFor($supplier, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $supplier = Supplier::factory()->create(['supplier_code' => 'AUDIT-SUP-RST-01']);
        $supplier->delete();

        $this->post(route('admin.suppliers.restore', $supplier));

        $entry = $this->auditEntriesFor($supplier, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-SUP-RST-01', $details['new']['supplier_code']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/suppliers/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->get(route('admin.suppliers.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.suppliers.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple suppliers with audit entries
        Supplier::factory()->count(60)->create();

        $response = $this->get(route('admin.suppliers.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_suppliers_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a supplier with its own audit entry to ensure the
        // page returns non-empty results to assert against.
        Supplier::factory()->create();

        $response = $this->get(route('admin.suppliers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one supplier entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('suppliers', $details['table'], 'Audit page should only show suppliers table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AUDIT-SUP-PERF-01',
            'supplier_name' => 'Performer Name Test',
        ]);
        $supplier = Supplier::where('supplier_code', 'AUDIT-SUP-PERF-01')->first();
        $this->assertNotNull($supplier);

        $response = $this->get(route('admin.suppliers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS supplier's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($supplier) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $supplier->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new supplier');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->get(route('admin.suppliers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this supplier's creation.
        $entry = $auditLogs->first(function ($log) use ($supplier) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $supplier->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for supplier #{$supplier->id}");
        $this->assertEquals($supplier->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'LIFE-SUP-01',
            'supplier_name' => 'Lifecycle Test',
        ]);
        $supplier = Supplier::where('supplier_code', 'LIFE-SUP-01')->first();
        $this->assertCount(1, $this->auditEntriesFor($supplier), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'LIFE-SUP-01',
            'supplier_name' => 'Lifecycle Test Updated',
            'is_active'     => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($supplier), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 11 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.suppliers.destroy', $supplier));
        $this->assertCount(4, $this->auditEntriesFor($supplier), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.suppliers.restore', $supplier));
        $this->assertCount(7, $this->auditEntriesFor($supplier), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
