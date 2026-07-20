<?php

namespace Tests\Feature\Bank;

use App\Models\Bank;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBankDependencies;
use Tests\TestCase;

/**
 * Bank Validation tests — verifies the validation rules defined in
 * BankController::validationRules().
 *
 * Rules (Phase 13):
 *   - bank_name:      required|string|max:100
 *   - account_number: nullable|string|max:50|unique:banks,account_number,{id}
 *   - account_holder: nullable|string|max:100
 *   - branch_name:    nullable|string|max:100
 *   - balance:        nullable|numeric
 *   - is_active:      boolean
 *   - ledger_id:      nullable|exists:ledgers,id
 *
 * Phase 13 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - account_number is uppercased + trimmed BEFORE validation
 *     (case-insensitive unique check)
 *   - bank_name is trimmed BEFORE validation
 */
class BankValidationTest extends TestCase
{
    use BuildsRoleUsers, InsertsBankDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // bank_name — required, max 100
    // ====================================================================

    public function test_bank_name_is_required_on_store(): void
    {
        $this->post(route('admin.banks.store'), [
            'account_number' => 'NO-NAME-BK-01',
        ])->assertSessionHasErrors('bank_name');
    }

    public function test_bank_name_is_required_on_update(): void
    {
        $bank = Bank::factory()->create();

        $this->put(route('admin.banks.update', $bank), [
            // bank_name omitted
            'account_number' => $bank->account_number,
            'is_active'      => true,
        ])->assertSessionHasErrors('bank_name');
    }

    public function test_bank_name_max_length_100(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => str_repeat('X', 101),
        ])->assertSessionHasErrors('bank_name');
    }

    public function test_bank_name_accepts_exactly_100_chars(): void
    {
        $name = str_repeat('X', 100);

        $this->post(route('admin.banks.store'), [
            'bank_name' => $name,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', ['bank_name' => $name]);
    }

    public function test_bank_name_is_trimmed_on_store(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => '  Padded Bank Name  ',
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', [
            'bank_name' => 'Padded Bank Name',
        ]);
    }

    public function test_bank_name_is_trimmed_on_update(): void
    {
        $bank = Bank::factory()->create(['bank_name' => 'Original Name']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => '  Updated Trimmed  ',
            'is_active'  => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', [
            'id'        => $bank->id,
            'bank_name' => 'Updated Trimmed',
        ]);
    }

    // ====================================================================
    // account_number — nullable, max 50, unique (case-insensitive)
    // ====================================================================

    public function test_account_number_is_optional_on_store(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Acc Num Bank',
        ])->assertRedirect();
    }

    public function test_account_number_is_optional_on_update(): void
    {
        $bank = Bank::factory()->create();

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => 'Updated Name Only',
            'is_active'  => true,
        ])->assertRedirect();
    }

    public function test_account_number_max_length_50(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Too Long Acc Num',
            'account_number' => str_repeat('A', 51),
        ])->assertSessionHasErrors('account_number');
    }

    public function test_account_number_accepts_exactly_50_chars(): void
    {
        $acc = str_repeat('A', 50);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Acc Num 50 Chars',
            'account_number' => $acc,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', ['account_number' => $acc]);
    }

    public function test_account_number_must_be_unique_on_store(): void
    {
        Bank::factory()->create(['account_number' => 'UNIQ-ACC-001']);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Duplicate Acc',
            'account_number' => 'UNIQ-ACC-001',
        ])->assertSessionHasErrors('account_number');
    }

    public function test_account_number_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 13: account_number is uppercased + trimmed BEFORE validation.
        // 'uniq-acc-002' becomes 'UNIQ-ACC-002' before unique check, so it
        // SHOULD collide with existing 'UNIQ-ACC-002'.
        Bank::factory()->create(['account_number' => 'UNIQ-ACC-002']);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Case Collision Bank',
            'account_number' => 'uniq-acc-002',
        ])->assertSessionHasErrors('account_number');
    }

    public function test_account_number_normalized_to_uppercase_on_store(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Normalization Bank',
            'account_number' => 'acc-norm-01',
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('banks', [
            'bank_name'      => 'Normalization Bank',
            'account_number' => 'ACC-NORM-01',
        ]);
    }

    public function test_account_number_normalized_to_uppercase_on_update(): void
    {
        $bank = Bank::factory()->create(['account_number' => 'UPD-OLD-01']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => $bank->bank_name,
            'account_number' => 'upd-new-01',
            'is_active'      => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', [
            'id'             => $bank->id,
            'account_number' => 'UPD-NEW-01',
        ]);
    }

    public function test_account_number_unique_allows_keeping_own_on_update(): void
    {
        $bank = Bank::factory()->create(['account_number' => 'KEEP-ACC-02']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Same Acc Update',
            'account_number' => 'KEEP-ACC-02',
            'is_active'      => true,
        ])->assertRedirect();
    }

    public function test_account_number_unique_rejects_other_banks_on_update(): void
    {
        Bank::factory()->create(['account_number' => 'TAKEN-ACC-02']);
        $bank = Bank::factory()->create(['account_number' => 'OWN-ACC-02']);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'      => 'Steal Other Acc',
            'account_number' => 'TAKEN-ACC-02',
            'is_active'      => true,
        ])->assertSessionHasErrors('account_number');
    }

    public function test_account_number_allows_multiple_nulls(): void
    {
        // NULL account_number is allowed for multiple banks (PG treats NULL
        // as not-equal in unique constraints).
        Bank::factory()->create(['account_number' => null]);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Null Acc Bank One',
            'account_number' => null,
        ])->assertRedirect();

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Null Acc Bank Two',
            'account_number' => null,
        ])->assertRedirect();
    }

    // ====================================================================
    // account_holder — nullable, max 100
    // ====================================================================

    public function test_account_holder_is_optional(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Holder Bank',
        ])->assertRedirect();
    }

    public function test_account_holder_max_length_100(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Holder Too Long',
            'account_holder' => str_repeat('H', 101),
        ])->assertSessionHasErrors('account_holder');
    }

    public function test_account_holder_accepts_exactly_100_chars(): void
    {
        $holder = str_repeat('H', 100);

        $this->post(route('admin.banks.store'), [
            'bank_name'      => 'Holder 100 Chars',
            'account_holder' => $holder,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', ['account_holder' => $holder]);
    }

    // ====================================================================
    // branch_name — nullable, max 100
    // ====================================================================

    public function test_branch_name_is_optional(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Branch Name',
        ])->assertRedirect();
    }

    public function test_branch_name_max_length_100(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'   => 'Branch Name Too Long',
            'branch_name' => str_repeat('B', 101),
        ])->assertSessionHasErrors('branch_name');
    }

    public function test_branch_name_accepts_exactly_100_chars(): void
    {
        $branch = str_repeat('B', 100);

        $this->post(route('admin.banks.store'), [
            'bank_name'   => 'Branch 100 Chars',
            'branch_name' => $branch,
        ])->assertRedirect();

        $this->assertDatabaseHas('banks', ['branch_name' => $branch]);
    }

    // ====================================================================
    // balance — nullable + numeric
    // ====================================================================

    public function test_balance_is_optional(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Balance Bank',
        ])->assertRedirect();
    }

    public function test_balance_must_be_numeric(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Bad Balance',
            'balance'   => 'free',
        ])->assertSessionHasErrors('balance');
    }

    public function test_balance_accepts_zero(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Zero Balance Bank',
            'balance'   => 0,
        ])->assertRedirect();
    }

    public function test_balance_accepts_positive_value(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Positive Balance Bank',
            'balance'   => 5000.75,
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Positive Balance Bank')->first();
        $this->assertEquals('5000.75', (string) $bank->balance);
    }

    public function test_balance_accepts_negative_value(): void
    {
        // Bank can be overdrawn — no min:0 constraint (unlike salary).
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Negative Balance Bank',
            'balance'   => -100.50,
        ])->assertRedirect();
    }

    // ====================================================================
    // ledger_id — nullable, must exist in ledgers table
    // ====================================================================

    public function test_ledger_id_is_optional(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'No Ledger Bank',
        ])->assertRedirect();
    }

    public function test_ledger_id_must_exist(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name'  => 'Bad Ledger Bank',
            'ledger_id'  => 999999,
        ])->assertSessionHasErrors('ledger_id');
    }

    public function test_ledger_id_accepts_valid_id(): void
    {
        $ledgerId = $this->insertLedger();

        $this->post(route('admin.banks.store'), [
            'bank_name'  => 'Valid Ledger Bank',
            'ledger_id'  => $ledgerId,
        ])->assertRedirect();
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Active True Bank',
            'is_active' => true,
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Active True Bank')->first();
        $this->assertTrue($bank->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Active False Bank',
            'is_active' => false,
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Active False Bank')->first();
        $this->assertFalse($bank->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.banks.store'), [
            'bank_name' => 'Default Active Bank',
        ])->assertRedirect();

        $bank = Bank::where('bank_name', 'Default Active Bank')->first();
        $this->assertTrue($bank->is_active, 'Bank should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        // Phase 13 fix: omitting is_active on update should NOT change is_active.
        $bank = Bank::factory()->create(['is_active' => true]);

        $this->put(route('admin.banks.update', $bank), [
            'bank_name'  => 'Some Update',
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($bank->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.banks.store'), [
            'bank_name'   => '',                  // required
            'account_holder' => str_repeat('X', 101), // max:100
            'branch_name' => str_repeat('B', 101),    // max:100
            'balance'     => 'free',              // numeric
            'ledger_id'   => 999999,              // exists
        ]);

        $response->assertSessionHasErrors(['bank_name', 'account_holder', 'branch_name', 'balance', 'ledger_id']);
    }
}
