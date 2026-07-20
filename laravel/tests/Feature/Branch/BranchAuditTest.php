<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Branch Audit Log tests — verifies that the AuditableMasterData trait
 * writes entries to user_audit_log for every CRUD mutation.
 *
 * Trait location: app/Traits/AuditableMasterData.php
 *
 * Audit actions logged:
 *   - master_data_created  (on store)
 *   - master_data_updated  (on update, with old + new diff)
 *   - master_data_deleted  (on destroy/soft-delete)
 *   - master_data_restored (on restore)
 *
 * Audit log columns:
 *   user_id, action, target_user_id, branch_id, details (jsonb),
 *   ip_address, user_agent, created_at
 *
 * details JSONB payload:
 *   { table: 'branches', record_id: <id>, old: {...}|null, new: {...}|null }
 *
 * Phase 4 commit `11b598d` fixed the audit viewer query to join users →
 * employees for performer name.
 */
class BranchAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given branch + action.
     */
    private function auditEntriesFor(Branch $branch, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['branches'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $branch->id]);

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
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'AUDIT-CREATE-01',
            'branch_name' => 'Audit Create Test',
        ]);

        $branch = Branch::where('branch_code', 'AUDIT-CREATE-01')->first();

        $entries = $this->auditEntriesFor($branch, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'AUDIT-NEW-01',
            'branch_name' => 'Audit New Field Test',
        ]);

        $branch = Branch::where('branch_code', 'AUDIT-NEW-01')->first();
        $entry = $this->auditEntriesFor($branch, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('branches', $details['table']);
        $this->assertEquals($branch->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-NEW-01', $details['new']['branch_code']);
        $this->assertEquals('Audit New Field Test', $details['new']['branch_name']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'AUDIT-USER-01',
            'branch_name' => 'User Capture Test',
        ]);

        $branch = Branch::where('branch_code', 'AUDIT-USER-01')->first();
        $entry = $this->auditEntriesFor($branch, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $branch = Branch::factory()->create(['branch_name' => 'Old Name']);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => 'New Name',
            'is_active'   => true,
        ]);

        $entries = $this->auditEntriesFor($branch, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $branch = Branch::factory()->create([
            'branch_name' => 'Original Name',
            'phone'       => null,
        ]);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => 'Updated Name',
            'phone'       => '01799999999',
            'is_active'   => true,
        ]);

        $entry = $this->auditEntriesFor($branch, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Name', $details['old']['branch_name']);
        $this->assertEquals('Updated Name', $details['new']['branch_name']);
        $this->assertEquals('01799999999', $details['new']['phone']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $branch = Branch::factory()->create([
            'branch_name' => 'Stable Name',
            'phone'       => null,
        ]);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => 'Stable Name', // unchanged
            'phone'       => '01799999999', // changed
            'is_active'   => true,
        ]);

        $entry = $this->auditEntriesFor($branch, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'phone' but NOT
        // 'branch_name' (since it wasn't changed).
        $this->assertArrayHasKey('phone', $details['new']);
        $this->assertArrayNotHasKey('branch_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $branch = Branch::factory()->create([
            'branch_name' => 'Same Name',
            'phone'       => '01711111111',
        ]);

        // Submit the same values
        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => 'Same Name',
            'phone'       => '01711111111',
            'is_active'   => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($branch, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $branch = Branch::factory()->create();

        $this->delete(route('admin.branches.destroy', $branch));

        $entries = $this->auditEntriesFor($branch, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'AUDIT-DEL-01',
            'branch_name' => 'Delete Audit Test',
        ]);

        $this->delete(route('admin.branches.destroy', $branch));

        $entry = $this->auditEntriesFor($branch, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-DEL-01', $details['old']['branch_code']);
        $this->assertEquals('Delete Audit Test', $details['old']['branch_name']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        $this->post(route('admin.branches.restore', $branch));

        $entries = $this->auditEntriesFor($branch, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'AUDIT-RST-01']);
        $branch->delete();

        $this->post(route('admin.branches.restore', $branch));

        $entry = $this->auditEntriesFor($branch, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-RST-01', $details['new']['branch_code']);
    }

    // ====================================================================
    // TOGGLE → produces both deleted + restored entries as state flips
    // ====================================================================

    public function test_toggle_deactivate_writes_deleted_audit_entry(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.branches.toggle', $branch));

        $entries = $this->auditEntriesFor($branch, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Toggle-deactivate should write master_data_deleted entry');
    }

    public function test_toggle_activate_writes_restored_audit_entry(): void
    {
        // Use destroy() so the branch is properly soft-deleted (sets deleted_at)
        // AND is_active=false. Then toggle-activate will fire restored + updated.
        $branch = Branch::factory()->create();
        $this->delete(route('admin.branches.destroy', $branch));

        $this->post(route('admin.branches.toggle', $branch));

        $entries = $this->auditEntriesFor($branch, 'master_data_restored');
        $this->assertCount(1, $entries, 'Toggle-activate should write master_data_restored entry');
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/branches/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        // Generate an audit entry
        $branch = Branch::factory()->create();

        $response = $this->get(route('admin.branches.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.branches.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple branches with audit entries
        Branch::factory()->count(60)->create();

        $response = $this->get(route('admin.branches.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_branches_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'warehouses', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $response = $this->get(route('admin.branches.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('branches', $details['table'], 'Audit page should only show branches table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'AUDIT-PERF-01',
            'branch_name' => 'Performer Name Test',
        ]);
        $branch = Branch::where('branch_code', 'AUDIT-PERF-01')->first();
        $this->assertNotNull($branch);

        $response = $this->get(route('admin.branches.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS branch's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($branch) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $branch->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new branch');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->get(route('admin.branches.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this branch's creation.
        $entry = $auditLogs->first(function ($log) use ($branch) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $branch->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for branch #{$branch->id}");
        $this->assertEquals($branch->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_5_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'LIFE-01',
            'branch_name' => 'Lifecycle Test',
        ]);
        $branch = Branch::where('branch_code', 'LIFE-01')->first();
        $this->assertCount(1, $this->auditEntriesFor($branch), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'LIFE-01',
            'branch_name' => 'Lifecycle Test Updated',
            'is_active'   => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($branch), 'After update: 2 audit entries');

        // 3. TOGGLE-DEACTIVATE → 2 entries:
        //    - master_data_updated (is_active=false, deleted_by set)
        //    - master_data_deleted (soft-delete)
        $this->post(route('admin.branches.toggle', $branch));
        $this->assertCount(4, $this->auditEntriesFor($branch), 'After toggle-deactivate: 4 audit entries (updated + deleted)');

        // 4. TOGGLE-ACTIVATE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore's internal save)
        //    - master_data_restored (restore event)
        //    - master_data_updated (is_active=true, deleted_by=null)
        $this->post(route('admin.branches.toggle', $branch));
        $this->assertCount(7, $this->auditEntriesFor($branch), 'After toggle-activate: 7 audit entries (updated + restored + updated)');
    }
}
