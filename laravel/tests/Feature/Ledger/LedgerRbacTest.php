<?php

namespace Tests\Feature\Ledger;

use App\Models\Ledger;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Ledger RBAC tests — verifies that every Ledger route enforces the correct
 * role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 15):
 *
 *   GET    /admin/ledgers                          admin, accountant
 *   GET    /admin/ledgers/{id}                     admin, accountant
 *   GET    /admin/ledgers/create                   admin
 *   POST   /admin/ledgers                          admin
 *   GET    /admin/ledgers/{id}/edit                admin
 *   PUT    /admin/ledgers/{id}                     admin
 *   DELETE /admin/ledgers/{id}                     admin
 *   POST   /admin/ledgers/{id}/restore             admin
 *   POST   /admin/ledgers/{id}/toggle              admin
 *   GET    /admin/ledgers/audit                    admin, accountant
 *
 * Ledgers are accounting-domain master data: admin has full control;
 * accountant has read-only access (they need to view the CoA for
 * reconciliation and reports). Manager/salesman/warehouse_manager/
 * dispatcher/hr do not.
 */
class LedgerRbacTest extends TestCase
{
    use BuildsRoleUsers, InsertsLedgerDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one ledger so resource routes resolve.
        $this->insertLedger();
    }

    // ====================================================================
    // READ routes — admin, accountant allowed
    // ====================================================================

    public function test_admin_can_access_ledger_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.ledgers.index'))
            ->assertOk();
    }

    public function test_accountant_can_access_ledger_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.ledgers.index'))
            ->assertOk();
    }

    public function test_manager_cannot_access_ledger_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.ledgers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_ledger_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.ledgers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_ledger_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.ledgers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_ledger_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.ledgers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_ledger_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.ledgers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.ledgers.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_and_accountant(): void
    {
        $ledger = Ledger::firstOrFail();

        $this->actingAsRole('admin');
        $this->get(route('admin.ledgers.show', $ledger))->assertOk();

        $this->actingAsRole('accountant');
        $this->get(route('admin.ledgers.show', $ledger))->assertOk();
    }

    public function test_show_route_denies_manager(): void
    {
        $ledger = Ledger::firstOrFail();

        $this->actingAsRole('manager')
            ->get(route('admin.ledgers.show', $ledger))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_salesman(): void
    {
        $ledger = Ledger::firstOrFail();

        $this->actingAsRole('salesman')
            ->get(route('admin.ledgers.show', $ledger))
            ->assertRedirect(route('dashboard'));
    }

    public function test_audit_route_allows_admin_and_accountant(): void
    {
        $this->actingAsRole('admin');
        $this->get(route('admin.ledgers.audit'))->assertOk();

        $this->actingAsRole('accountant');
        $this->get(route('admin.ledgers.audit'))->assertOk();
    }

    public function test_audit_route_denies_manager(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.ledgers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_audit_route_denies_salesman(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.ledgers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_ledger_routes(): void
    {
        $ledger = Ledger::firstOrFail();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.ledgers.index'))->assertOk();
        $this->get(route('admin.ledgers.show', $ledger))->assertOk();
        $this->get(route('admin.ledgers.create'))->assertOk();
        $this->get(route('admin.ledgers.edit', $ledger))->assertOk();
        $this->get(route('admin.ledgers.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.ledgers.create'))
            ->assertOk();
    }

    public function test_accountant_cannot_access_create_form(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.ledgers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.ledgers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_store_ledger(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.ledgers.store'), [
                'ledger_code'  => 'UNAUTH-L-001',
                'ledger_name'  => 'Unauthorized Store',
                'account_type' => 'Asset',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('ledgers', ['ledger_code' => 'UNAUTH-L-001']);
    }

    public function test_manager_cannot_store_ledger(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.ledgers.store'), [
                'ledger_code'  => 'UNAUTH-L-002',
                'ledger_name'  => 'Unauthorized Store 2',
                'account_type' => 'Asset',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('ledgers', ['ledger_code' => 'UNAUTH-L-002']);
    }

    public function test_accountant_cannot_access_edit_form(): void
    {
        $ledger = Ledger::firstOrFail();

        $this->actingAsRole('accountant')
            ->get(route('admin.ledgers.edit', $ledger))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_update_ledger(): void
    {
        $ledger = Ledger::firstOrFail();

        $this->actingAsRole('accountant')
            ->put(route('admin.ledgers.update', $ledger), [
                'ledger_code'  => $ledger->ledger_code,
                'ledger_name'  => 'Hacked Name',
                'account_type' => $ledger->account_type,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('ledgers', [
            'id'          => $ledger->id,
            'ledger_name' => 'Hacked Name',
        ]);
    }

    public function test_accountant_cannot_destroy_ledger(): void
    {
        $ledgerId = $this->insertLedger(['ledger_code' => 'DESTROY-RBAC-01']);

        $this->actingAsRole('accountant')
            ->delete(route('admin.ledgers.destroy', $ledgerId))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledgerId,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_destroy_ledger(): void
    {
        $ledgerId = $this->insertLedger(['ledger_code' => 'DESTROY-RBAC-02']);

        $this->actingAsRole('manager')
            ->delete(route('admin.ledgers.destroy', $ledgerId))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledgerId,
            'deleted_at' => null,
        ]);
    }

    public function test_accountant_cannot_toggle_ledger(): void
    {
        $ledgerId = $this->insertLedger(['ledger_code' => 'TOGGLE-RBAC-01']);

        $this->actingAsRole('accountant')
            ->post(route('admin.ledgers.toggle', $ledgerId))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledgerId,
            'is_active'  => true,
            'deleted_at' => null,
        ]);
    }

    public function test_accountant_cannot_restore_ledger(): void
    {
        $ledgerId = $this->insertLedger([
            'ledger_code' => 'RESTORE-RBAC-01',
            'is_active'   => false,
            'deleted_at'  => now(),
        ]);

        $this->actingAsRole('accountant')
            ->post(route('admin.ledgers.restore', $ledgerId))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull(
            Ledger::withTrashed()->find($ledgerId)->deleted_at
        );
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $ledgerId = $this->insertLedger(['ledger_code' => 'JSON-403-01']);

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.ledgers.destroy', $ledgerId))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.ledgers.store'), [
                'ledger_code'  => 'JSON-403-02',
                'ledger_name'  => 'Forbidden Store',
                'account_type' => 'Asset',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $ledgerId = $this->insertLedger(['ledger_code' => 'UNAUTH-401-01']);

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.ledgers.destroy', $ledgerId))
            ->assertUnauthorized();
    }
}
