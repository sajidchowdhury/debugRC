<?php

namespace Tests\Feature\Ledger;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Ledger Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Ledger CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product/Customer/Supplier/
 * Employee/Bank/User: master_data_created/updated/deleted/restored actions
 * with details JSONB containing table='ledgers' + record_id.
 *
 * Phase 15 commit.
 */
class LedgerAuditTest extends TestCase
{
    use BuildsRoleUsers, InsertsLedgerDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given ledger + action.
     */
    private function auditEntriesFor(Ledger $ledger, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['ledgers'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $ledger->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * Helper: create a Ledger via the HTTP store route so the audit
     * 'created' entry is properly attributed.
     */
    private function createLedgerViaHttp(array $overrides = []): Ledger
    {
        $code = $overrides['ledger_code'] ?? 'AUDIT-L-' . substr(uniqid(), -6);

        $this->post(route('admin.ledgers.store'), array_merge([
            'ledger_code'  => $code,
            'ledger_name'  => 'Audit Ledger ' . $code,
            'account_type' => 'Asset',
        ], $overrides));

        // Phase 15: ledger_code is uppercased + trimmed on save — query
        // with the post-normalization value.
        return Ledger::where('ledger_code', strtoupper(trim($code)))->first();
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-C-01']);

        $entries = $this->auditEntriesFor($ledger, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-C-02']);

        $entry = $this->auditEntriesFor($ledger, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('ledgers', $details['table']);
        $this->assertEquals($ledger->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-C-02', $details['new']['ledger_code']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-C-03']);

        $entry = $this->auditEntriesFor($ledger, 'master_data_created')->first();

        $this->assertEquals($admin->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-U-01']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'AUDIT-U-01',
            'ledger_name'  => 'Audit Ledger Renamed',
            'account_type' => 'Asset',
            'is_active'    => true,
        ]);

        $entries = $this->auditEntriesFor($ledger, 'master_data_updated');
        $this->assertGreaterThanOrEqual(1, $entries->count(), 'Expected at least 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-U-02']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'AUDIT-U-02',
            'ledger_name'  => 'Audit New Name',
            'account_type' => 'Asset',
            'is_active'    => true,
        ]);

        $entry = $this->auditEntriesFor($ledger, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Audit Ledger AUDIT-U-02', $details['old']['ledger_name']);
        $this->assertEquals('Audit New Name', $details['new']['ledger_name']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $ledger = $this->createLedgerViaHttp([
            'ledger_code'  => 'AUDIT-U-03',
            'ledger_name'  => 'Stable Audit Ledger',
        ]);

        // Change only account_type; ledger_name should NOT appear in 'new'
        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'AUDIT-U-03',
            'ledger_name'  => 'Stable Audit Ledger', // unchanged
            'account_type' => 'Liability',           // changed
            'is_active'    => true,
        ]);

        $entry = $this->auditEntriesFor($ledger, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertArrayHasKey('account_type', $details['new']);
        $this->assertArrayNotHasKey('ledger_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-U-04']);

        // Submit the same data (no changes)
        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'AUDIT-U-04',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ]);

        $entries = $this->auditEntriesFor($ledger, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-D-01']);

        $this->delete(route('admin.ledgers.destroy', $ledger));

        $entries = $this->auditEntriesFor($ledger, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-D-02']);

        $this->delete(route('admin.ledgers.destroy', $ledger));

        $entry = $this->auditEntriesFor($ledger, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-D-02', $details['old']['ledger_code']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-R-01']);
        $ledger->delete();

        $this->post(route('admin.ledgers.restore', $ledger));

        $entries = $this->auditEntriesFor($ledger, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-R-02']);
        $ledger->delete();

        $this->post(route('admin.ledgers.restore', $ledger));

        $entry = $this->auditEntriesFor($ledger, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-R-02', $details['new']['ledger_code']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/ledgers/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-P-01']);

        $response = $this->get(route('admin.ledgers.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.ledgers.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple ledgers with audit entries
        for ($i = 0; $i < 5; $i++) {
            $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-PG-' . $i . '-' . substr(uniqid(), -6)]);
        }

        $response = $this->get(route('admin.ledgers.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_ledgers_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'banks', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a ledger with its own audit entry
        $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-PF-01']);

        $response = $this->get(route('admin.ledgers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one ledger entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('ledgers', $details['table'], 'Audit page should only show ledgers table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-PN-01']);

        $response = $this->get(route('admin.ledgers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $createdEntry = $auditLogs->first(function ($log) use ($ledger) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $ledger->id;
        });

        $this->assertNotNull($createdEntry, 'Audit page should contain a master_data_created entry for the new ledger');
        $this->assertEquals($admin->id, $createdEntry->user_id);
        $this->assertEquals($admin->employee->name, $createdEntry->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-TI-01']);

        $response = $this->get(route('admin.ledgers.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $entry = $auditLogs->first(function ($log) use ($ledger) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $ledger->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for ledger #{$ledger->id}");
        $this->assertEquals($ledger->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $ledger = $this->createLedgerViaHttp(['ledger_code' => 'AUDIT-LC-01']);
        $this->assertCount(1, $this->auditEntriesFor($ledger), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'AUDIT-LC-01',
            'ledger_name'  => 'Lifecycle Renamed',
            'account_type' => 'Asset',
            'is_active'    => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($ledger), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (destroy() calls save() to set is_active=false
        //    + deleted_by, which fires 'updated'; then delete() fires 'deleted').
        $this->delete(route('admin.ledgers.destroy', $ledger));
        $this->assertCount(4, $this->auditEntriesFor($ledger), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.ledgers.restore', $ledger));
        $this->assertCount(7, $this->auditEntriesFor($ledger), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
