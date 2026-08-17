<?php

namespace Tests\Feature\Ledger;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Ledger CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle.
 *
 * Validates LedgerController (Phase 15: canDeactivate safety check +
 * system-ledger protection + pre-validation normalization) inheriting
 * from BaseMasterDataController.
 *
 * Phase 15 commit.
 */
class LedgerCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsLedgerDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Convenience: create a Ledger with overrides.
     */
    private function makeLedger(array $overrides = []): Ledger
    {
        return Ledger::factory()->create($overrides);
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_ledgers(): void
    {
        $this->makeLedger();
        $this->makeLedger();
        $this->makeLedger();

        $response = $this->get(route('admin.ledgers.index'));

        $response->assertOk();
        $response->assertViewIs('admin.ledgers.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_ledgers(): void
    {
        $ledger = $this->makeLedger();
        $ledger->delete();

        $response = $this->get(route('admin.ledgers.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        $this->makeLedger();
        $this->makeLedger();

        $response = $this->get(route('admin.ledgers.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_ledger_count(): void
    {
        $this->makeLedger();
        $this->makeLedger();
        $this->makeLedger(['is_active' => false]);

        $response = $this->get(route('admin.ledgers.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_created_ledger(): void
    {
        // Unique searchable ledger_name + search.value filter so the
        // DataTables response narrows to JUST this ledger.
        // LedgerController declares searchFields=['ledger_code',
        // 'ledger_name'], so the ILIKE filter matches against both.
        // Mirrors the fix applied to SupplierCrudTest in commit ee6341d.
        $searchToken = 'DT LEDGER LOOKUP ' . substr(uniqid(), -6);
        $ledger = $this->makeLedger(['ledger_name' => $searchToken]);

        $response = $this->get(route('admin.ledgers.index', [
            'draw'   => 1,
            'start'  => 0,
            'length' => 25,
            'search' => ['value' => $searchToken],
        ]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $ledger->id);
        $this->assertNotNull($row, 'DataTables response should include the created ledger');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.ledgers.create'));

        $response->assertOk();
        $response->assertViewIs('admin.ledgers.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'parents', 'accountTypes', 'natures']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_ledger_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-ST-001',
            'ledger_name'  => 'Test Ledger Store',
            'account_type' => 'Asset',
            'is_active'    => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ledgers', [
            'ledger_code'  => 'L-ST-001',
            'ledger_name'  => 'Test Ledger Store',
            'account_type' => 'Asset',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-REDIR-01',
            'ledger_name'  => 'Show Redirect Ledger',
            'account_type' => 'Asset',
        ]);

        $ledger = Ledger::where('ledger_code', 'L-REDIR-01')->first();
        $response->assertRedirect(route('admin.ledgers.show', $ledger));
        $response->assertSessionHas('success');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-MIN-001',
            'ledger_name'  => 'Minimal Ledger',
            'account_type' => 'Asset',
            // parent_id, ledger_nature, control_account_type, opening_balance omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ledgers', [
            'ledger_code'  => 'L-MIN-001',
            'ledger_name'  => 'Minimal Ledger',
        ]);
    }

    public function test_store_fails_on_duplicate_ledger_code(): void
    {
        Ledger::factory()->create(['ledger_code' => 'DUP-L-001']);

        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'DUP-L-001',
            'ledger_name'  => 'Duplicate Test',
            'account_type' => 'Asset',
        ]);

        $response->assertSessionHasErrors('ledger_code');
    }

    public function test_store_fails_when_ledger_name_missing(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-NAME-01',
            'account_type' => 'Asset',
        ]);

        $response->assertSessionHasErrors('ledger_name');
    }

    public function test_store_fails_when_account_type_missing(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-TYPE-01',
            'ledger_name'  => 'No Type Ledger',
        ]);

        $response->assertSessionHasErrors('account_type');
    }

    public function test_store_stores_numeric_opening_balance(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'     => 'L-BAL-001',
            'ledger_name'     => 'Balance Test Ledger',
            'account_type'    => 'Asset',
            'opening_balance' => 25000.75,
        ]);

        $ledger = Ledger::where('ledger_code', 'L-BAL-001')->first();
        $this->assertEquals('25000.75', (string) $ledger->opening_balance);
    }

    public function test_store_uppercases_ledger_code_before_unique_check(): void
    {
        // Phase 15: ledger_code is uppercased + trimmed BEFORE validation.
        // 'l-uc-01' becomes 'L-UC-01' before unique check.
        Ledger::factory()->create(['ledger_code' => 'UPPER-L-01']);

        // 'upper-l-01' should collide after normalization
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'upper-l-01',
            'ledger_name'  => 'Case Collision Test',
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_code');
    }

    public function test_store_uppercases_ledger_code_on_save(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'l-norm-01',
            'ledger_name'  => 'Normalize Save Test',
            'account_type' => 'Asset',
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('ledgers', [
            'ledger_code'  => 'L-NORM-01',
            'ledger_name'  => 'Normalize Save Test',
        ]);
    }

    public function test_store_trims_ledger_name_before_validation(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-TRIM-01',
            'ledger_name'  => '  Padded Ledger Name  ',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'  => 'L-TRIM-01',
            'ledger_name'  => 'Padded Ledger Name',
        ]);
    }

    public function test_store_lowercases_ledger_nature_on_save(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'   => 'L-NAT-01',
            'ledger_name'   => 'Nature Lower Test',
            'account_type'  => 'Asset',
            'ledger_nature' => 'CASH_BANK',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'   => 'L-NAT-01',
            'ledger_nature' => 'cash_bank',
        ]);
    }

    public function test_store_sets_created_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-CB-001',
            'ledger_name'  => 'Created By Test',
            'account_type' => 'Asset',
        ]);

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'  => 'L-CB-001',
            'created_by'   => $user->id,
        ]);
    }

    public function test_store_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-DEF-001',
            'ledger_name'  => 'Default Active Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $ledger = Ledger::where('ledger_code', 'L-DEF-001')->first();
        $this->assertTrue($ledger->is_active, 'Ledger should default to active when is_active is omitted');
    }

    public function test_store_with_normal_balance_persists(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'L-NB-01',
            'ledger_name'    => 'Normal Balance Test',
            'account_type'   => 'Liability',
            'ledger_nature'  => 'ap',
            'normal_balance' => 'credit',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'    => 'L-NB-01',
            'normal_balance' => 'credit',
        ]);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_ledger_details(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->get(route('admin.ledgers.show', $ledger));

        $response->assertOk();
        $response->assertViewIs('admin.ledgers.show');
        $response->assertViewHas('item');
        $this->assertEquals($ledger->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_parent_children_and_journal_lines(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->get(route('admin.ledgers.show', $ledger));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('parent'));
        $this->assertTrue($item->relationLoaded('children'));
        $this->assertTrue($item->relationLoaded('journalLines'));
    }

    public function test_show_works_for_soft_deleted_ledger(): void
    {
        $ledger = $this->makeLedger();
        $ledger->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.ledgers.show', $ledger));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_ledger(): void
    {
        $this->get(route('admin.ledgers.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_ledger(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->get(route('admin.ledgers.edit', $ledger));

        $response->assertOk();
        $response->assertViewIs('admin.ledgers.edit');
        $response->assertViewHas('item');
        $this->assertEquals($ledger->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_ledger_and_redirects_to_show(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => 'Updated Ledger Name',
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ]);

        $response->assertRedirect(route('admin.ledgers.show', $ledger));
        $this->assertDatabaseHas('ledgers', [
            'id'          => $ledger->id,
            'ledger_name' => 'Updated Ledger Name',
        ]);
    }

    public function test_update_allows_changing_ledger_code_to_unique_value(): void
    {
        $ledger = $this->makeLedger(['ledger_code' => 'OLD-L-01']);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'NEW-L-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ledgers', [
            'id'           => $ledger->id,
            'ledger_code'  => 'NEW-L-01',
        ]);
    }

    public function test_update_fails_on_duplicate_ledger_code_from_other_ledger(): void
    {
        Ledger::factory()->create(['ledger_code' => 'TAKEN-L-01']);
        $ledger = $this->makeLedger(['ledger_code' => 'OWN-L-01']);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'TAKEN-L-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ]);

        $response->assertSessionHasErrors('ledger_code');
    }

    public function test_update_allows_keeping_own_ledger_code(): void
    {
        $ledger = $this->makeLedger(['ledger_code' => 'KEEP-L-01']);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'KEEP-L-01',
            'ledger_name'  => 'Same Code New Name',
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ledgers', [
            'id'           => $ledger->id,
            'ledger_code'  => 'KEEP-L-01',
            'ledger_name'  => 'Same Code New Name',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Ledger with journal history → deactivation should be blocked
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($ledger->fresh()->is_active);
    }

    public function test_update_deactivates_ledger_when_no_blockers(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($ledger->fresh()->is_active);
    }

    public function test_update_blocked_when_ledger_has_child_ledgers(): void
    {
        $parent = $this->makeLedger();
        $this->insertChildLedger($parent->id);

        $response = $this->put(route('admin.ledgers.update', $parent), [
            'ledger_code'  => $parent->ledger_code,
            'ledger_name'  => $parent->ledger_name,
            'account_type' => $parent->account_type,
            'is_active'    => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($parent->fresh()->is_active);
    }

    public function test_update_blocked_when_ledger_is_sole_active_critical_nature(): void
    {
        // The seed data (database/sql/basic_data_snapshot.sql) ships with
        // 2+ active cash_bank ledgers (id 16 'Cash in Hand', id 37 'Bank
        // Accounts'). The 'sole active critical nature' safety check in
        // LedgerController::canDeactivate() only fires when the test's
        // ledger is the SOLE remaining active ledger for that nature.
        // Deactivate every other active cash_bank ledger first so this
        // test's new ledger is genuinely sole-active. Safe because
        // DatabaseTransactions rolls back per test.
        DB::table('ledgers')
            ->where('ledger_nature', 'cash_bank')
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $ledger = $this->makeLedger(['ledger_nature' => 'cash_bank']);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($ledger->fresh()->is_active);
    }

    public function test_update_uppercases_ledger_code_on_save(): void
    {
        $ledger = $this->makeLedger(['ledger_code' => 'UPD-OLD-L-01']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'upd-new-l-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'id'           => $ledger->id,
            'ledger_code'  => 'UPD-NEW-L-01',
        ]);
    }

    public function test_update_not_silently_flips_is_active_when_omitted(): void
    {
        $ledger = $this->makeLedger(['is_active' => true]);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => $ledger->ledger_name . ' updated',
            'account_type' => $ledger->account_type,
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($ledger->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // SYSTEM LEDGER PROTECTION (Phase 15 audit Phase 4 mandate)
    // ====================================================================

    public function test_update_blocks_non_description_fields_on_system_ledger(): void
    {
        $ledger = $this->makeLedger([
            'is_system'    => true,
            'ledger_code'  => 'SYS-L-01',
            'ledger_name'  => 'System Ledger Original',
            'account_type' => 'Asset',
        ]);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'SYS-L-CHANGED',
            'ledger_name'  => 'Hacked System Name',
            'account_type' => 'Liability',
            'is_active'    => false,
        ]);

        // Should NOT have changed anything except possibly description
        $ledger->refresh();
        $this->assertSame('SYS-L-01', $ledger->ledger_code);
        $this->assertSame('System Ledger Original', $ledger->ledger_name);
        $this->assertSame('Asset', $ledger->account_type);
        $this->assertTrue($ledger->is_active);
    }

    public function test_update_allows_description_change_on_system_ledger(): void
    {
        $ledger = $this->makeLedger([
            'is_system' => true,
            'description' => null,
        ]);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'description' => 'Updated system ledger description.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ledgers', [
            'id'          => $ledger->id,
            'description' => 'Updated system ledger description.',
        ]);
    }

    public function test_update_system_ledger_returns_success_message(): void
    {
        $ledger = $this->makeLedger(['is_system' => true]);

        $response = $this->put(route('admin.ledgers.update', $ledger), [
            'description' => 'New description.',
        ]);

        $response->assertSessionHas('success');
    }

    public function test_destroy_blocked_on_system_ledger(): void
    {
        $ledger = $this->makeLedger(['is_system' => true]);

        $response = $this->delete(route('admin.ledgers.destroy', $ledger));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('system ledger', session('error'));

        $ledger->refresh();
        $this->assertNull($ledger->deleted_at);
        $this->assertTrue($ledger->is_active);
    }

    public function test_destroy_blocked_on_system_ledger_even_with_no_dependencies(): void
    {
        // System ledger with no journal history, no children — STILL blocked.
        $ledger = $this->makeLedger(['is_system' => true]);

        $response = $this->delete(route('admin.ledgers.destroy', $ledger));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledger->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_ledger_with_no_blockers(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->delete(route('admin.ledgers.destroy', $ledger));

        $response->assertRedirect(route('admin.ledgers.index'));
        $response->assertSessionHas('success');

        $ledger->refresh();
        $this->assertNotNull($ledger->deleted_at);
        $this->assertFalse($ledger->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $ledger = $this->makeLedger();

        $this->delete(route('admin.ledgers.destroy', $ledger));

        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledger->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_ledger_has_journal_history(): void
    {
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $response = $this->delete(route('admin.ledgers.destroy', $ledger));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledger->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_ledger_has_child_ledgers(): void
    {
        $parent = $this->makeLedger();
        $this->insertChildLedger($parent->id);

        $response = $this->delete(route('admin.ledgers.destroy', $parent));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('ledgers', [
            'id'         => $parent->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_ledger_is_sole_active_critical_nature(): void
    {
        // See note in test_update_blocked_when_ledger_is_sole_active_
        // critical_nature — the seed ships with 2+ active cash_bank
        // ledgers; we deactivate them first so the test's new ledger is
        // genuinely sole-active for the canDeactivate() safety check to
        // fire. DatabaseTransactions rolls back per test.
        DB::table('ledgers')
            ->where('ledger_nature', 'cash_bank')
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $ledger = $this->makeLedger(['ledger_nature' => 'cash_bank']);

        $response = $this->delete(route('admin.ledgers.destroy', $ledger));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('ledgers', [
            'id'         => $ledger->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_ledger(): void
    {
        $ledger = $this->makeLedger();
        $ledger->delete();

        $response = $this->post(route('admin.ledgers.restore', $ledger));

        $response->assertRedirect(route('admin.ledgers.show', $ledger));
        $response->assertSessionHas('success');

        $ledger->refresh();
        $this->assertNull($ledger->deleted_at);
        $this->assertNull($ledger->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_ledger(): void
    {
        $ledger = $this->makeLedger(); // not deleted

        $response = $this->post(route('admin.ledgers.restore', $ledger));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_ledger(): void
    {
        $this->post(route('admin.ledgers.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_ledger_count_increments_after_store(): void
    {
        $initialCount = Ledger::count();

        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'L-COUNT-01',
            'ledger_name'  => 'Count Test Ledger',
            'account_type' => 'Asset',
        ]);

        $this->assertEquals($initialCount + 1, Ledger::count());
    }

    public function test_soft_deleted_ledger_excluded_from_default_index_query(): void
    {
        $toDelete = $this->makeLedger(['ledger_name' => 'Hide Me From Default']);
        $keep = $this->makeLedger(['ledger_name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.ledgers.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one ledger');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 15)
    // ====================================================================

    public function test_toggle_deactivates_active_ledger_with_no_blockers(): void
    {
        $ledger = $this->makeLedger();

        $response = $this->post(route('admin.ledgers.toggle', $ledger));

        $response->assertRedirect(route('admin.ledgers.index'));
        $response->assertSessionHas('success');

        $ledger->refresh();
        $this->assertFalse($ledger->is_active);
        $this->assertNotNull($ledger->deleted_at);
    }

    public function test_toggle_activates_inactive_ledger(): void
    {
        $ledger = $this->makeLedger();
        $ledger->delete();

        $response = $this->post(route('admin.ledgers.toggle', $ledger));

        $response->assertRedirect(route('admin.ledgers.index'));
        $ledger->refresh();
        $this->assertTrue($ledger->is_active);
        $this->assertNull($ledger->deleted_at);
    }

    public function test_toggle_blocked_when_ledger_has_journal_history(): void
    {
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $response = $this->post(route('admin.ledgers.toggle', $ledger));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('journal line', session('error'));
        $this->assertTrue($ledger->fresh()->is_active);
    }

    public function test_toggle_blocked_when_ledger_has_child_ledgers(): void
    {
        $parent = $this->makeLedger();
        $this->insertChildLedger($parent->id);

        $response = $this->post(route('admin.ledgers.toggle', $parent));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('child ledger', session('error'));
        $this->assertTrue($parent->fresh()->is_active);
    }

    public function test_toggle_blocked_when_ledger_is_sole_active_critical_nature(): void
    {
        // See note in test_update_blocked_when_ledger_is_sole_active_
        // critical_nature — the seed ships with 2+ active cash_bank
        // ledgers; we deactivate them first so the test's new ledger is
        // genuinely sole-active. DatabaseTransactions rolls back per test.
        DB::table('ledgers')
            ->where('ledger_nature', 'cash_bank')
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        $ledger = $this->makeLedger(['ledger_nature' => 'cash_bank']);

        $response = $this->post(route('admin.ledgers.toggle', $ledger));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('sole active', session('error'));
        $this->assertTrue($ledger->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_ledger(): void
    {
        $this->post(route('admin.ledgers.toggle', 999999))
            ->assertNotFound();
    }
}
