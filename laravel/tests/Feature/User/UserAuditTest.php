<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsUserDependencies;
use Tests\TestCase;

/**
 * User Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every User CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse/Product/Customer/Supplier/
 * Employee/Bank: master_data_created/updated/deleted/restored actions
 * with details JSONB containing table='users' + record_id.
 *
 * Phase 14 commit.
 */
class UserAuditTest extends TestCase
{
    use BuildsRoleUsers, InsertsUserDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given user + action.
     */
    private function auditEntriesFor(User $user, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['users'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $user->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /**
     * Helper: create an Employee + Branch + User via the HTTP store route
     * so the audit 'created' entry is properly attributed.
     */
    private function createUserViaHttp(array $overrides = []): User
    {
        $branch = \App\Models\Branch::factory()->create();
        $employee = \App\Models\Employee::factory()->forBranch($branch->id)->create();

        $username = $overrides['username'] ?? 'audit_user_' . substr(uniqid(), -6);

        $this->post(route('admin.users.store'), array_merge([
            'username'    => $username,
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ], $overrides));

        return User::where('username', $username)->first();
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_create_user']);

        $entries = $this->auditEntriesFor($user, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_new_field_user']);

        $entry = $this->auditEntriesFor($user, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('users', $details['table']);
        $this->assertEquals($user->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('audit_new_field_user', $details['new']['username']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $user = $this->createUserViaHttp(['username' => 'audit_capture_user']);

        $entry = $this->auditEntriesFor($user, 'master_data_created')->first();

        $this->assertEquals($admin->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_update_user']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'audit_update_user_renamed',
            'is_active' => true,
        ]);

        $entries = $this->auditEntriesFor($user, 'master_data_updated');
        $this->assertGreaterThanOrEqual(1, $entries->count(), 'Expected at least 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_old_username']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'audit_new_username',
            'is_active' => true,
        ]);

        $entry = $this->auditEntriesFor($user, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('audit_old_username', $details['old']['username']);
        $this->assertEquals('audit_new_username', $details['new']['username']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $user = $this->createUserViaHttp([
            'username'    => 'stable_audit_user',
            'telegram_user_id' => null,
        ]);

        // Change only telegram_user_id; username should NOT appear in 'new'
        $this->put(route('admin.users.update', $user), [
            'username'         => 'stable_audit_user', // unchanged
            'telegram_user_id' => 999888,
            'is_active'        => true,
        ]);

        $entry = $this->auditEntriesFor($user, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertArrayHasKey('telegram_user_id', $details['new']);
        $this->assertArrayNotHasKey('username', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $user = $this->createUserViaHttp(['username' => 'no_change_audit_user']);

        // Submit the same username (no changes)
        $this->put(route('admin.users.update', $user), [
            'username'  => 'no_change_audit_user',
            'is_active' => true,
        ]);

        $entries = $this->auditEntriesFor($user, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_delete_user']);

        $this->delete(route('admin.users.destroy', $user));

        $entries = $this->auditEntriesFor($user, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_del_attrs_user']);

        $this->delete(route('admin.users.destroy', $user));

        $entry = $this->auditEntriesFor($user, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('audit_del_attrs_user', $details['old']['username']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_restore_user']);
        $user->delete();

        $this->post(route('admin.users.restore', $user));

        $entries = $this->auditEntriesFor($user, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_restore_attrs']);
        $user->delete();

        $this->post(route('admin.users.restore', $user));

        $entry = $this->auditEntriesFor($user, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('audit_restore_attrs', $details['new']['username']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/users/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $this->createUserViaHttp(['username' => 'audit_page_user']);

        $response = $this->get(route('admin.users.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.users.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple users with audit entries
        for ($i = 0; $i < 5; $i++) {
            $this->createUserViaHttp(['username' => 'audit_paginate_' . $i . '_' . substr(uniqid(), -6)]);
        }

        $response = $this->get(route('admin.users.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_users_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a user with its own audit entry
        $this->createUserViaHttp(['username' => 'audit_filter_user']);

        $response = $this->get(route('admin.users.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one user entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('users', $details['table'], 'Audit page should only show users table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $user = $this->createUserViaHttp(['username' => 'audit_performer_user']);

        $response = $this->get(route('admin.users.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $createdByUser = $auditLogs->first(function ($log) use ($user) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $user->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new user');
        $this->assertEquals($admin->id, $createdByUser->user_id);
        $this->assertEquals($admin->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $user = $this->createUserViaHttp(['username' => 'audit_target_id_user']);

        $response = $this->get(route('admin.users.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $entry = $auditLogs->first(function ($log) use ($user) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $user->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for user #{$user->id}");
        $this->assertEquals($user->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $user = $this->createUserViaHttp(['username' => 'lifecycle_audit_user']);
        $this->assertCount(1, $this->auditEntriesFor($user), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.users.update', $user), [
            'username'  => 'lifecycle_audit_user_renamed',
            'is_active' => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($user), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 14 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.users.destroy', $user));
        $this->assertCount(4, $this->auditEntriesFor($user), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.users.restore', $user));
        $this->assertCount(7, $this->auditEntriesFor($user), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
