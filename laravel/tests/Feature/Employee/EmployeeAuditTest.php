<?php

namespace Tests\Feature\Employee;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Employee Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Employee CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product/Customer/Supplier:
 * master_data_created/updated/deleted/restored actions with details JSONB
 * containing table='employees' + record_id.
 *
 * Phase 12 commit.
 */
class EmployeeAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given employee + action.
     */
    private function auditEntriesFor(Employee $employee, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['employees'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $employee->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * Convenience: create a Branch + Employee pair (avoids branch_id NOT NULL).
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        $branch = Branch::factory()->create();

        return Employee::factory()->forBranch($branch->id)->create($overrides);
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AUDIT-EMP-C-01',
            'name'          => 'Audit Create Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $employee = Employee::where('employee_code', 'AUDIT-EMP-C-01')->first();

        $entries = $this->auditEntriesFor($employee, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AUDIT-EMP-N-01',
            'name'          => 'Audit New Field Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $employee = Employee::where('employee_code', 'AUDIT-EMP-N-01')->first();
        $entry = $this->auditEntriesFor($employee, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('employees', $details['table']);
        $this->assertEquals($employee->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-EMP-N-01', $details['new']['employee_code']);
        $this->assertEquals('Audit New Field Test', $details['new']['name']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branch = Branch::factory()->create();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AUDIT-EMP-U-01',
            'name'          => 'User Capture Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $employee = Employee::where('employee_code', 'AUDIT-EMP-U-01')->first();
        $entry = $this->auditEntriesFor($employee, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $employee = $this->makeEmployee(['name' => 'Old Name']);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'New Name',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);

        $entries = $this->auditEntriesFor($employee, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $employee = $this->makeEmployee([
            'name'   => 'Original Name',
            'phone'  => null,
        ]);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Updated Name',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'phone'         => '01711000000',
            'is_active'     => true,
        ]);

        $entry = $this->auditEntriesFor($employee, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Name', $details['old']['name']);
        $this->assertEquals('Updated Name', $details['new']['name']);
        $this->assertEquals('01711000000', $details['new']['phone']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $employee = $this->makeEmployee([
            'name'  => 'Stable Name',
            'phone' => null,
        ]);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Stable Name', // unchanged
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'phone'         => '01911000000', // changed
            'is_active'     => true,
        ]);

        $entry = $this->auditEntriesFor($employee, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'phone' but NOT
        // 'name' (since it wasn't changed).
        $this->assertArrayHasKey('phone', $details['new']);
        $this->assertArrayNotHasKey('name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $employee = $this->makeEmployee([
            'name'  => 'Same Name',
            'phone' => '01711000000',
        ]);

        // Submit the same values
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Same Name',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'phone'         => '01711000000',
            'is_active'     => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($employee, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $employee = $this->makeEmployee();

        $this->delete(route('admin.employees.destroy', $employee));

        $entries = $this->auditEntriesFor($employee, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $employee = $this->makeEmployee([
            'employee_code' => 'AUDIT-EMP-DEL-01',
            'name'          => 'Delete Audit Test',
        ]);

        $this->delete(route('admin.employees.destroy', $employee));

        $entry = $this->auditEntriesFor($employee, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-EMP-DEL-01', $details['old']['employee_code']);
        $this->assertEquals('Delete Audit Test', $details['old']['name']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        $this->post(route('admin.employees.restore', $employee));

        $entries = $this->auditEntriesFor($employee, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $employee = $this->makeEmployee(['employee_code' => 'AUDIT-EMP-RST-01']);
        $employee->delete();

        $this->post(route('admin.employees.restore', $employee));

        $entry = $this->auditEntriesFor($employee, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-EMP-RST-01', $details['new']['employee_code']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/employees/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.employees.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple employees with audit entries
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->count(60)->create();

        $response = $this->get(route('admin.employees.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_employees_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create an employee with its own audit entry to ensure the
        // page returns non-empty results to assert against.
        $this->makeEmployee();

        $response = $this->get(route('admin.employees.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one employee entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('employees', $details['table'], 'Audit page should only show employees table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branch = Branch::factory()->create();

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AUDIT-EMP-PERF-01',
            'name'          => 'Performer Name Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);
        $employee = Employee::where('employee_code', 'AUDIT-EMP-PERF-01')->first();
        $this->assertNotNull($employee);

        $response = $this->get(route('admin.employees.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS employee's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($employee) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $employee->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new employee');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this employee's creation.
        $entry = $auditLogs->first(function ($log) use ($employee) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $employee->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for employee #{$employee->id}");
        $this->assertEquals($employee->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        $branch = Branch::factory()->create();

        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.employees.store'), [
            'employee_code' => 'LIFE-EMP-01',
            'name'          => 'Lifecycle Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);
        $employee = Employee::where('employee_code', 'LIFE-EMP-01')->first();
        $this->assertCount(1, $this->auditEntriesFor($employee), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'LIFE-EMP-01',
            'name'          => 'Lifecycle Test Updated',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($employee), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 12 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.employees.destroy', $employee));
        $this->assertCount(4, $this->auditEntriesFor($employee), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.employees.restore', $employee));
        $this->assertCount(7, $this->auditEntriesFor($employee), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
