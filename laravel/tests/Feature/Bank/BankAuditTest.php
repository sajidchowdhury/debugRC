<?php

namespace Tests\Feature\Bank;

use App\Models\Bank;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Bank Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Bank CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product/Customer/Supplier/Employee:
 * master_data_created/updated/deleted/restored actions with details JSONB
 * containing table='banks' + record_id.
 *
 * Phase 13 commit.
 */
class BankAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given bank + action.
     */
    private function auditEntriesFor(Bank $bank, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['banks'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $bank->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * Convenience: create a Bank with overrides.
     */
    private function makeBank(array $overrides = []): Bank
    {
        return Bank::factory()->create($overrides);
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Audit Create Bank',
            'account_number' => 'AUDIT-BK-C-01',
        ]);

        $bank = Bank::where('bank_name', 'Audit Create Bank')->first();

        $entries = $this->auditEntriesFor($bank, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Audit New Field Bank',
            'account_number' => 'AUDIT-BK-N-01',
        ]);

        $bank = Bank::where('bank_name', 'Audit New Field Bank')->first();
        $entry = $this->auditEntriesFor($bank, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('banks', $details['table']);
        $this->assertEquals($bank->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Audit New Field Bank', $details['new']['bank_name']);
        $this->assertEquals('AUDIT-BK-N-01', $details['new']['account_number']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'User Capture Bank',
            'account_number' => 'AUDIT-BK-U-01',
        ]);

        $bank = Bank::where('bank_name', 'User Capture Bank')->first();
        $entry = $this->auditEntriesFor($bank, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $bank = $this->makeBank(['bank_name' => 'Old Bank Name']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => 'New Bank Name',
            'is_active'  => true,
        ]);

        $entries = $this->auditEntriesFor($bank, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $bank = $this->makeBank([
            'bank_name'      => 'Original Bank Name',
            'account_holder' => null,
        ]);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Updated Bank Name',
            'account_holder' => 'New Holder',
            'is_active'      => true,
        ]);

        $entry = $this->auditEntriesFor($bank, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Bank Name', $details['old']['bank_name']);
        $this->assertEquals('Updated Bank Name', $details['new']['bank_name']);
        $this->assertEquals('New Holder', $details['new']['account_holder']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $bank = $this->makeBank([
            'bank_name'      => 'Stable Bank Name',
            'account_holder' => null,
        ]);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Stable Bank Name', // unchanged
            'account_holder' => 'Some Holder', // changed
            'is_active'      => true,
        ]);

        $entry = $this->auditEntriesFor($bank, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'account_holder' but NOT
        // 'bank_name' (since it wasn't changed).
        $this->assertArrayHasKey('account_holder', $details['new']);
        $this->assertArrayNotHasKey('bank_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $bank = $this->makeBank([
            'bank_name'      => 'Same Bank Name',
            'account_holder' => 'Same Holder',
        ]);

        // Submit the same values
        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Same Bank Name',
            'account_holder' => 'Same Holder',
            'is_active'      => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($bank, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $bank = $this->makeBank();

        $this->delete(route('admin.banks.destroy', $bank));

        $entries = $this->auditEntriesFor($bank, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $bank = $this->makeBank([
            'bank_name'      => 'Audit Delete Bank',
            'account_number' => 'AUDIT-BK-DEL-01',
        ]);

        $this->delete(route('admin.banks.destroy', $bank));

        $entry = $this->auditEntriesFor($bank, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('Audit Delete Bank', $details['old']['bank_name']);
        $this->assertEquals('AUDIT-BK-DEL-01', $details['old']['account_number']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $bank = $this->makeBank();
        $bank->delete();

        $this->post(route('admin.banks.restore', $bank));

        $entries = $this->auditEntriesFor($bank, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $bank = $this->makeBank(['bank_name' => 'Audit Restore Bank']);
        $bank->delete();

        $this->post(route('admin.banks.restore', $bank));

        $entry = $this->auditEntriesFor($bank, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Audit Restore Bank', $details['new']['bank_name']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/banks/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $this->makeBank();

        $response = $this->get(route('admin.banks.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.banks.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple banks with audit entries
        Bank::factory()->count(60)->create();

        $response = $this->get(route('admin.banks.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_banks_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a bank with its own audit entry to ensure the
        // page returns non-empty results to assert against.
        $this->makeBank();

        $response = $this->get(route('admin.banks.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one bank entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('banks', $details['table'], 'Audit page should only show banks table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Performer Name Bank',
            'account_number' => 'AUDIT-BK-PERF-01',
        ]);
        $bank = Bank::where('bank_name', 'Performer Name Bank')->first();
        $this->assertNotNull($bank);

        $response = $this->get(route('admin.banks.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS bank's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($bank) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $bank->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new bank');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $bank = $this->makeBank();

        $response = $this->get(route('admin.banks.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this bank's creation.
        $entry = $auditLogs->first(function ($log) use ($bank) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $bank->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for bank #{$bank->id}");
        $this->assertEquals($bank->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Lifecycle Bank',
            'account_number' => 'LIFE-BK-01',
        ]);
        $bank = Bank::where('bank_name', 'Lifecycle Bank')->first();
        $this->assertCount(1, $this->auditEntriesFor($bank), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => 'Lifecycle Bank Updated',
            'is_active'  => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($bank), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 13 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.banks.destroy', $bank));
        $this->assertCount(4, $this->auditEntriesFor($bank), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.banks.restore', $bank));
        $this->assertCount(7, $this->auditEntriesFor($bank), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
