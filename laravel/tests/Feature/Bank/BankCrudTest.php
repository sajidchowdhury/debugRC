<?php

namespace Tests\Feature\Bank;

use App\Models\Bank;
use App\Models\BankLedgerMapping;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBankDependencies;
use Tests\TestCase;

/**
 * Bank CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle.
 *
 * Validates BankController (Phase 13: canDeactivate safety check +
 * pre-validation normalization + ledger mapping sync + unique account_number)
 * inheriting from BaseMasterDataController.
 */
class BankCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsBankDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Convenience: create a Bank with overrides.
     */
    private function makeBank(array $overrides = []): Bank
    {
        return Bank::factory()->create($overrides);
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_banks(): void
    {
        $this->makeBank();
        $this->makeBank();
        $this->makeBank();

        $response = $this->get(route('admin.banks.index'));

        $response->assertOk();
        $response->assertViewIs('admin.banks.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_banks(): void
    {
        $bank = $this->makeBank();
        $bank->delete();

        $response = $this->get(route('admin.banks.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        $this->makeBank();
        $this->makeBank();

        $response = $this->get(route('admin.banks.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_bank_count(): void
    {
        $this->makeBank();
        $this->makeBank();
        $this->makeBank(['is_active' => false]);

        $response = $this->get(route('admin.banks.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_created_bank(): void
    {
        $bank = $this->makeBank();

        $response = $this->get(route('admin.banks.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $bank->id);
        $this->assertNotNull($row, 'DataTables response should include the created bank');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.banks.create'));

        $response->assertOk();
        $response->assertViewIs('admin.banks.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'ledgers']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_bank_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Test Bank Store',
            'account_number' => 'ACC-ST-001',
            'account_holder' => 'Test Holder',
            'is_active'      => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('banks', [
            'bank_name'      => 'Test Bank Store',
            'account_number' => 'ACC-ST-001',
            'account_holder' => 'Test Holder',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Show Redirect Bank',
            'account_number' => 'ACC-REDIR-01',
        ]);

        $bank = Bank::where('bank_name', 'Show Redirect Bank')->first();
        $response->assertRedirect(route('admin.banks.show', $bank));
        $response->assertSessionHas('success');
    }

    public function test_store_accepts_only_bank_name(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'bank_name' => 'Minimal Bank',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('banks', [
            'bank_name' => 'Minimal Bank',
        ]);
    }

    public function test_store_fails_on_duplicate_account_number(): void
    {
        Bank::factory()->create(['account_number' => 'DUP-ACC-001']);

        $response = $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Duplicate Test',
            'account_number' => 'DUP-ACC-001',
        ]);

        $response->assertSessionHasErrors('account_number');
    }

    public function test_store_fails_when_bank_name_missing(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'account_number' => 'NO-NAME-01',
        ]);

        $response->assertSessionHasErrors('bank_name');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'bank_name' => 'Minimal Bank Two',
            // account_number, account_holder, branch_name, balance, ledger_id omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('banks', [
            'bank_name' => 'Minimal Bank Two',
        ]);
    }

    public function test_store_stores_numeric_balance(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Balance Test Bank',
            'balance'   => 25000.75,
        ]);

        $bank = Bank::where('bank_name', 'Balance Test Bank')->first();
        $this->assertEquals('25000.75', (string) $bank->balance);
    }

    public function test_store_uppercases_account_number_before_unique_check(): void
    {
        // Phase 13: account_number is uppercased + trimmed BEFORE validation.
        // 'acc-01' becomes 'ACC-01' before unique check.
        Bank::factory()->create(['account_number' => 'UPPER-ACC-01']);

        // 'upper-acc-01' should collide after normalization
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Case Collision Test',
            'account_number' => 'upper-acc-01',
        ])->assertSessionHasErrors('account_number');
    }

    public function test_store_uppercases_account_number_on_save(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Normalize Save Test',
            'account_number' => 'acc-norm-01',
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('banks', [
            'bank_name'      => 'Normalize Save Test',
            'account_number' => 'ACC-NORM-01',
        ]);
    }

    public function test_store_trims_bank_name_before_validation(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => '  Padded Bank Name  ',
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', [
            'bank_name' => 'Padded Bank Name',
        ]);
    }

    public function test_store_sets_created_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Created By Test',
        ]);

        $this->assertDatabaseHas('banks', [
            'bank_name'   => 'Created By Test',
            'created_by'  => $user->id,
        ]);
    }

    public function test_store_with_ledger_id_creates_bank_ledger_mapping(): void
    {
        $ledgerId = $this->insertLedger();

        $this->post(route('admin.banks.store'), [
            'bank_name'  => 'Ledger Linked Bank',
            'ledger_id'  => $ledgerId,
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Ledger Linked Bank')->first();
        $this->assertDatabaseHas('bank_ledger_mappings', [
            'bank_id'   => $bank->id,
            'ledger_id' => $ledgerId,
        ]);
    }

    public function test_store_without_ledger_id_does_not_create_mapping(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Ledger Bank',
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'No Ledger Bank')->first();
        $this->assertDatabaseMissing('bank_ledger_mappings', [
            'bank_id' => $bank->id,
        ]);
    }

    public function test_store_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Default Active Bank',
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Default Active Bank')->first();
        $this->assertTrue($bank->is_active, 'Bank should default to active when is_active is omitted');
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_bank_details(): void
    {
        $bank = $this->makeBank();

        $response = $this->get(route('admin.banks.show', $bank));

        $response->assertOk();
        $response->assertViewIs('admin.banks.show');
        $response->assertViewHas('item');
        $this->assertEquals($bank->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_ledger_and_mapping(): void
    {
        $bank = $this->makeBank();

        $response = $this->get(route('admin.banks.show', $bank));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('ledger'));
        $this->assertTrue($item->relationLoaded('ledgerMapping'));
    }

    public function test_show_works_for_soft_deleted_bank(): void
    {
        $bank = $this->makeBank();
        $bank->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.banks.show', $bank));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_bank(): void
    {
        $this->get(route('admin.banks.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_bank(): void
    {
        $bank = $this->makeBank();

        $response = $this->get(route('admin.banks.edit', $bank));

        $response->assertOk();
        $response->assertViewIs('admin.banks.edit');
        $response->assertViewHas('item');
        $this->assertEquals($bank->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_bank_and_redirects_to_show(): void
    {
        $bank = $this->makeBank();

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => 'Updated Bank Name',
            'is_active'  => true,
        ]);

        $response->assertRedirect(route('admin.banks.show', $bank));
        $this->assertDatabaseHas('banks', [
            'id'        => $bank->id,
            'bank_name' => 'Updated Bank Name',
        ]);
    }

    public function test_update_allows_changing_account_number_to_unique_value(): void
    {
        $bank = $this->makeBank(['account_number' => 'OLD-ACC-01']);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => $bank->bank_name,
            'account_number' => 'NEW-ACC-01',
            'is_active'      => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('banks', [
            'id'             => $bank->id,
            'account_number' => 'NEW-ACC-01',
        ]);
    }

    public function test_update_fails_on_duplicate_account_number_from_other_bank(): void
    {
        Bank::factory()->create(['account_number' => 'TAKEN-ACC-01']);
        $bank = $this->makeBank(['account_number' => 'OWN-ACC-01']);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => $bank->bank_name,
            'account_number' => 'TAKEN-ACC-01',
            'is_active'      => true,
        ]);

        $response->assertSessionHasErrors('account_number');
    }

    public function test_update_allows_keeping_own_account_number(): void
    {
        $bank = $this->makeBank(['account_number' => 'KEEP-ACC-01']);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Same Acc New Name',
            'account_number' => 'KEEP-ACC-01',
            'is_active'      => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('banks', [
            'id'             => $bank->id,
            'account_number' => 'KEEP-ACC-01',
            'bank_name'      => 'Same Acc New Name',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Bank with non-zero balance → deactivation should be blocked
        $bank = $this->makeBank(['balance' => 500.00]);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            'is_active'   => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_update_deactivates_bank_when_no_blockers(): void
    {
        $bank = $this->makeBank(['balance' => 0]);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            'is_active'   => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($bank->fresh()->is_active);
    }

    public function test_update_blocked_when_bank_has_active_ledger_mapping(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $response = $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            'is_active'   => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_update_uppercases_account_number_on_save(): void
    {
        $bank = $this->makeBank(['account_number' => 'UPD-OLD-ACC-01']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => $bank->bank_name,
            'account_number' => 'upd-new-acc-01',
            'is_active'      => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', [
            'id'             => $bank->id,
            'account_number' => 'UPD-NEW-ACC-01',
        ]);
    }

    public function test_update_creates_ledger_mapping_when_ledger_id_added(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            'ledger_id'  => $ledgerId,
            'is_active'  => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('bank_ledger_mappings', [
            'bank_id'   => $bank->id,
            'ledger_id' => $ledgerId,
        ]);
    }

    public function test_update_clears_ledger_mapping_when_ledger_id_removed(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            // ledger_id intentionally omitted — but since the form sends
            // an empty string, syncLedgerMapping deletes the mapping.
            'ledger_id'  => '',
            'is_active'  => true,
        ])->assertRedirect();

        $this->assertDatabaseMissing('bank_ledger_mappings', [
            'bank_id' => $bank->id,
        ]);
    }

    public function test_update_replaces_ledger_mapping_when_ledger_id_changed(): void
    {
        $bank = $this->makeBank();
        $oldLedgerId = $this->insertLedger();
        $newLedgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $oldLedgerId);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => $bank->bank_name,
            'ledger_id'  => $newLedgerId,
            'is_active'  => true,
        ])->assertRedirect();

        // Mapping should now point to newLedgerId (only one row, UNIQUE on bank_id).
        $this->assertDatabaseMissing('bank_ledger_mappings', [
            'bank_id'   => $bank->id,
            'ledger_id' => $oldLedgerId,
        ]);
        $this->assertDatabaseHas('bank_ledger_mappings', [
            'bank_id'   => $bank->id,
            'ledger_id' => $newLedgerId,
        ]);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_bank_with_no_blockers(): void
    {
        $bank = $this->makeBank();

        $response = $this->delete(route('admin.banks.destroy', $bank));

        $response->assertRedirect(route('admin.banks.index'));
        $response->assertSessionHas('success');

        $bank->refresh();
        $this->assertNotNull($bank->deleted_at);
        $this->assertFalse($bank->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $bank = $this->makeBank();

        $this->delete(route('admin.banks.destroy', $bank));

        $this->assertDatabaseHas('banks', [
            'id'         => $bank->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_bank_has_non_zero_balance(): void
    {
        $bank = $this->makeBank(['balance' => 1000.00]);

        $response = $this->delete(route('admin.banks.destroy', $bank));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('banks', [
            'id'         => $bank->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_bank_has_active_ledger_mapping(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $response = $this->delete(route('admin.banks.destroy', $bank));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('banks', [
            'id'         => $bank->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_bank(): void
    {
        $bank = $this->makeBank();
        $bank->delete();

        $response = $this->post(route('admin.banks.restore', $bank));

        $response->assertRedirect(route('admin.banks.show', $bank));
        $response->assertSessionHas('success');

        $bank->refresh();
        $this->assertNull($bank->deleted_at);
        $this->assertNull($bank->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_bank(): void
    {
        $bank = $this->makeBank(); // not deleted

        $response = $this->post(route('admin.banks.restore', $bank));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_bank(): void
    {
        $this->post(route('admin.banks.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_bank_count_increments_after_store(): void
    {
        $initialCount = Bank::count();

        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Count Test Bank',
        ]);

        $this->assertEquals($initialCount + 1, Bank::count());
    }

    public function test_soft_deleted_bank_excluded_from_default_index_query(): void
    {
        $toDelete = $this->makeBank(['bank_name' => 'Hide Me From Default']);
        $keep = $this->makeBank(['bank_name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.banks.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one bank');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 13)
    // ====================================================================

    public function test_toggle_deactivates_active_bank_with_no_blockers(): void
    {
        $bank = $this->makeBank();

        $response = $this->post(route('admin.banks.toggle', $bank));

        $response->assertRedirect(route('admin.banks.index'));
        $response->assertSessionHas('success');

        $bank->refresh();
        $this->assertFalse($bank->is_active);
        $this->assertNotNull($bank->deleted_at);
    }

    public function test_toggle_activates_inactive_bank(): void
    {
        $bank = $this->makeBank();
        $bank->delete();

        $response = $this->post(route('admin.banks.toggle', $bank));

        $response->assertRedirect(route('admin.banks.index'));
        $bank->refresh();
        $this->assertTrue($bank->is_active);
        $this->assertNull($bank->deleted_at);
    }

    public function test_toggle_blocked_when_bank_has_non_zero_balance(): void
    {
        $bank = $this->makeBank(['balance' => 100.00]);

        $response = $this->post(route('admin.banks.toggle', $bank));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('balance', session('error'));
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_toggle_blocked_when_bank_has_active_ledger_mapping(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $response = $this->post(route('admin.banks.toggle', $bank));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('ledger mapping', session('error'));
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_bank(): void
    {
        $this->post(route('admin.banks.toggle', 999999))
            ->assertNotFound();
    }
}
