<?php

namespace Tests\Feature\Ledger;

use App\Models\Ledger;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Ledger Validation tests — verifies the validation rules defined in
 * LedgerController::validationRules().
 *
 * Rules (Phase 15):
 *   - ledger_code:          required|string|max:20|unique:ledgers,ledger_code,{id}
 *   - ledger_name:          required|string|max:100
 *   - parent_id:            nullable|integer
 *   - account_type:         required|in:Asset,Liability,Equity,Income,Expense
 *   - ledger_nature:        nullable|string|max:50 (must be in known natures)
 *   - is_control_account:   boolean
 *   - control_account_type: nullable|string|max:30
 *   - normal_balance:       nullable|in:debit,credit (consistency-checked
 *                            against ledger_nature when both provided)
 *   - is_active:            boolean
 *   - opening_balance:      nullable|numeric
 *   - sort_order:           nullable|integer
 *   - description:          nullable|string
 *
 * Phase 15 hardening:
 *   - ledger_code uppercased + trimmed BEFORE validation (case-insensitive unique)
 *   - ledger_name trimmed BEFORE validation
 *   - ledger_nature lowercased + trimmed BEFORE validation
 *   - normal_balance lowercased + trimmed BEFORE validation
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - account_type ↔ ledger_nature ↔ normal_balance consistency check
 *     (Phase 6 audit mandate): when nature is recognized, the provided
 *     account_type and normal_balance must match the nature's metadata.
 */
class LedgerValidationTest extends TestCase
{
    use BuildsRoleUsers, InsertsLedgerDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // ledger_code — required, max 20, unique (case-insensitive)
    // ====================================================================

    public function test_ledger_code_auto_generated_when_blank_on_store(): void
    {
        // Phase 16: ledger_code is now auto-generated (L-NNNN) when blank.
        // So omitting it should NOT produce a validation error — it should
        // succeed and create a ledger with an auto-generated code.
        $this->post(route('admin.ledgers.store'), [
            'ledger_name'  => 'Auto Code Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_name' => 'Auto Code Ledger',
        ]);
    }

    public function test_ledger_code_is_required_on_update(): void
    {
        $ledger = Ledger::factory()->create();

        $this->put(route('admin.ledgers.update', $ledger), [
            // ledger_code omitted
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
        ])->assertSessionHasErrors('ledger_code');
    }

    public function test_ledger_code_max_length_20(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => str_repeat('x', 21),
            'ledger_name'  => 'Long Code Ledger',
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_code');
    }

    public function test_ledger_code_accepts_exactly_20_chars(): void
    {
        $code = str_repeat('L', 20);
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => $code,
            'ledger_name'  => 'Max Code Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', ['ledger_code' => $code]);
    }

    public function test_ledger_code_must_be_unique_on_store(): void
    {
        Ledger::factory()->create(['ledger_code' => 'UNIQ-L-001']);
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'UNIQ-L-001',
            'ledger_name'  => 'Dup Code Ledger',
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_code');
    }

    public function test_ledger_code_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 15: ledger_code is uppercased + trimmed BEFORE validation.
        // 'uniq-l-002' becomes 'UNIQ-L-002' before unique check.
        Ledger::factory()->create(['ledger_code' => 'UNIQ-L-002']);
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'uniq-l-002',
            'ledger_name'  => 'Case Collision',
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_code');
    }

    public function test_ledger_code_normalized_to_uppercase_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'mixed-case-01',
            'ledger_name'  => 'Normalize Code Test',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'MIXED-CASE-01',
        ]);
    }

    public function test_ledger_code_normalized_to_uppercase_on_update(): void
    {
        $ledger = Ledger::factory()->create(['ledger_code' => 'NORM-OLD-01']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'norm-new-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'id'          => $ledger->id,
            'ledger_code' => 'NORM-NEW-01',
        ]);
    }

    public function test_ledger_code_trimmed_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => '  TRIM-01  ',
            'ledger_name'  => 'Trim Code Test',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'TRIM-01',
        ]);
    }

    public function test_ledger_code_unique_allows_keeping_own_on_update(): void
    {
        $ledger = Ledger::factory()->create(['ledger_code' => 'KEEP-OWN-01']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'KEEP-OWN-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ])->assertRedirect();
    }

    public function test_ledger_code_unique_rejects_other_ledgers_on_update(): void
    {
        Ledger::factory()->create(['ledger_code' => 'TAKEN-L-01']);
        $ledger = Ledger::factory()->create(['ledger_code' => 'OWN-L-01']);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => 'TAKEN-L-01',
            'ledger_name'  => $ledger->ledger_name,
            'account_type' => $ledger->account_type,
            'is_active'    => true,
        ])->assertSessionHasErrors('ledger_code');
    }

    // ====================================================================
    // ledger_name — required, max 100
    // ====================================================================

    public function test_ledger_name_is_required_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-NAME-V-01',
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_name');
    }

    public function test_ledger_name_max_length_100(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NAME-MAX-01',
            'ledger_name'  => str_repeat('x', 101),
            'account_type' => 'Asset',
        ])->assertSessionHasErrors('ledger_name');
    }

    public function test_ledger_name_accepts_exactly_100_chars(): void
    {
        $name = str_repeat('N', 100);
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NAME-OK-01',
            'ledger_name'  => $name,
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', ['ledger_name' => $name]);
    }

    public function test_ledger_name_trimmed_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'TRIM-NAME-01',
            'ledger_name'  => '  Padded Name  ',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_name' => 'Padded Name',
        ]);
    }

    // ====================================================================
    // account_type — required, must be in the allowed list
    // ====================================================================

    public function test_account_type_is_required_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-TYPE-V-01',
            'ledger_name'  => 'No Type Ledger',
        ])->assertSessionHasErrors('account_type');
    }

    public function test_account_type_must_be_in_allowed_list(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'BAD-TYPE-01',
            'ledger_name'  => 'Bad Type Ledger',
            'account_type' => 'NotAType',
        ])->assertSessionHasErrors('account_type');
    }

    public function test_account_type_accepts_all_5_valid_values(): void
    {
        foreach (['Asset', 'Liability', 'Equity', 'Income', 'Expense'] as $type) {
            $code = 'TYPE-' . strtoupper(substr($type, 0, 4)) . '-' . substr(uniqid(), -4);
            $this->post(route('admin.ledgers.store'), [
                'ledger_code'  => $code,
                'ledger_name'  => $type . ' Ledger',
                'account_type' => $type,
            ])->assertRedirect();

            // ledger_code is uppercased + trimmed on save — match the
            // post-normalization value.
            $this->assertDatabaseHas('ledgers', [
                'ledger_code'  => strtoupper($code),
                'account_type' => $type,
            ]);
        }
    }

    // ====================================================================
    // ledger_nature — nullable, must be in known natures list
    // ====================================================================

    public function test_ledger_nature_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-NAT-01',
            'ledger_name'  => 'No Nature Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_ledger_nature_rejects_unknown_value(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'   => 'BAD-NAT-01',
            'ledger_name'   => 'Bad Nature Ledger',
            'account_type'  => 'Asset',
            'ledger_nature' => 'totally_made_up_nature',
        ])->assertSessionHasErrors('ledger_nature');
    }

    public function test_ledger_nature_accepts_all_known_values(): void
    {
        foreach (array_keys(Ledger::natureMetadata()) as $nature) {
            $code = 'NAT-' . strtoupper(substr($nature, 0, 6)) . '-' . substr(uniqid(), -4);
            $meta = Ledger::natureMetadata()[$nature];

            $this->post(route('admin.ledgers.store'), [
                'ledger_code'    => $code,
                'ledger_name'    => str_replace('_', ' ', $nature) . ' Ledger',
                'account_type'   => $meta['account_type'],
                'ledger_nature'  => $nature,
                'normal_balance' => $meta['normal_balance'],
            ])->assertRedirect();

            $this->assertDatabaseHas('ledgers', [
                'ledger_code'   => strtoupper($code),
                'ledger_nature' => $nature,
            ]);
        }
    }

    public function test_ledger_nature_normalized_to_lowercase_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'   => 'NAT-LOW-01',
            'ledger_name'   => 'Lower Nature Test',
            'account_type'  => 'Asset',
            'ledger_nature' => 'CASH_BANK',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'   => 'NAT-LOW-01',
            'ledger_nature' => 'cash_bank',
        ]);
    }

    public function test_ledger_nature_normalized_to_lowercase_on_update(): void
    {
        $ledger = Ledger::factory()->create();

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'   => $ledger->ledger_code,
            'ledger_name'   => $ledger->ledger_name,
            'account_type'  => 'Liability',
            'ledger_nature' => 'AP',
            'normal_balance' => 'CREDIT',
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'id'             => $ledger->id,
            'ledger_nature'  => 'ap',
            'normal_balance' => 'credit',
        ]);
    }

    // ====================================================================
    // normal_balance — nullable, in:debit,credit, consistency-checked
    // ====================================================================

    public function test_normal_balance_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NO-NB-01',
            'ledger_name'  => 'No NB Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_normal_balance_must_be_debit_or_credit(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'BAD-NB-01',
            'ledger_name'    => 'Bad NB Ledger',
            'account_type'   => 'Asset',
            'normal_balance' => 'wrong',
        ])->assertSessionHasErrors('normal_balance');
    }

    public function test_normal_balance_accepts_debit(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'NB-DB-01',
            'ledger_name'    => 'Debit NB Ledger',
            'account_type'   => 'Asset',
            'normal_balance' => 'debit',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'    => 'NB-DB-01',
            'normal_balance' => 'debit',
        ]);
    }

    public function test_normal_balance_accepts_credit(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'NB-CR-01',
            'ledger_name'    => 'Credit NB Ledger',
            'account_type'   => 'Liability',
            'normal_balance' => 'credit',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'    => 'NB-CR-01',
            'normal_balance' => 'credit',
        ]);
    }

    public function test_normal_balance_normalized_to_lowercase_on_store(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'NB-NORM-01',
            'ledger_name'    => 'NB Normalize Test',
            'account_type'   => 'Asset',
            'normal_balance' => 'DEBIT',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'    => 'NB-NORM-01',
            'normal_balance' => 'debit',
        ]);
    }

    // ====================================================================
    // account_type ↔ ledger_nature ↔ normal_balance consistency (Phase 6)
    // ====================================================================

    public function test_normal_balance_inconsistent_with_nature_is_rejected(): void
    {
        // cash_bank nature expects debit normal_balance.
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'INCON-NB-01',
            'ledger_name'    => 'Inconsistent NB',
            'account_type'   => 'Asset',
            'ledger_nature'  => 'cash_bank',
            'normal_balance' => 'credit', // wrong — should be debit
        ])->assertSessionHasErrors('normal_balance');
    }

    public function test_normal_balance_consistent_with_nature_is_accepted(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'CON-NB-01',
            'ledger_name'    => 'Consistent NB',
            'account_type'   => 'Asset',
            'ledger_nature'  => 'cash_bank',
            'normal_balance' => 'debit',
        ])->assertRedirect();
    }

    public function test_normal_balance_consistency_check_skipped_when_nature_null(): void
    {
        // No nature → any normal_balance is allowed (as long as in debit/credit).
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'    => 'NONAT-NB-01',
            'ledger_name'    => 'No Nature Credit',
            'account_type'   => 'Liability',
            'normal_balance' => 'credit',
        ])->assertRedirect();
    }

    public function test_normal_balance_consistency_check_skipped_when_balance_null(): void
    {
        // No normal_balance → no consistency check.
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'   => 'NONB-NAT-01',
            'ledger_name'   => 'No NB Nature',
            'account_type'  => 'Asset',
            'ledger_nature' => 'cash_bank',
        ])->assertRedirect();
    }

    // ====================================================================
    // parent_id — nullable, integer
    // ====================================================================

    public function test_parent_id_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NOPAR-01',
            'ledger_name'  => 'No Parent Ledger',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_parent_id_must_be_integer(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'BADPAR-01',
            'ledger_name'  => 'Bad Parent Ledger',
            'account_type' => 'Asset',
            'parent_id'    => 'not-an-int',
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_parent_id_accepts_integer(): void
    {
        $parent = Ledger::factory()->create();

        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'OKPAR-01',
            'ledger_name'  => 'Has Parent Ledger',
            'account_type' => 'Asset',
            'parent_id'    => $parent->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'OKPAR-01',
            'parent_id'   => $parent->id,
        ]);
    }

    // ====================================================================
    // opening_balance, sort_order — nullable, numeric/integer
    // ====================================================================

    public function test_opening_balance_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NOOB-01',
            'ledger_name'  => 'No Opening Balance',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_opening_balance_must_be_numeric(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'     => 'BADOB-01',
            'ledger_name'     => 'Bad Opening Balance',
            'account_type'    => 'Asset',
            'opening_balance' => 'not-a-number',
        ])->assertSessionHasErrors('opening_balance');
    }

    public function test_opening_balance_accepts_numeric(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'     => 'OKOB-01',
            'ledger_name'     => 'Numeric Opening Balance',
            'account_type'    => 'Asset',
            'opening_balance' => 1234.56,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'     => 'OKOB-01',
            'opening_balance' => 1234.56,
        ]);
    }

    public function test_sort_order_must_be_integer(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'BADSO-01',
            'ledger_name'  => 'Bad Sort Order',
            'account_type' => 'Asset',
            'sort_order'   => 'not-an-int',
        ])->assertSessionHasErrors('sort_order');
    }

    public function test_sort_order_accepts_integer(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'OKSO-01',
            'ledger_name'  => 'Integer Sort Order',
            'account_type' => 'Asset',
            'sort_order'   => 42,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'OKSO-01',
            'sort_order'  => 42,
        ]);
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'IA-TRUE-01',
            'ledger_name'  => 'Active True',
            'account_type' => 'Asset',
            'is_active'    => true,
        ])->assertRedirect();

        $ledger = Ledger::where('ledger_code', 'IA-TRUE-01')->first();
        $this->assertTrue($ledger->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'IA-FALSE-01',
            'ledger_name'  => 'Active False',
            'account_type' => 'Asset',
            'is_active'    => false,
        ])->assertRedirect();

        $ledger = Ledger::where('ledger_code', 'IA-FALSE-01')->first();
        $this->assertFalse($ledger->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'IA-DEF-01',
            'ledger_name'  => 'Default Active',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $ledger = Ledger::where('ledger_code', 'IA-DEF-01')->first();
        $this->assertTrue($ledger->is_active, 'Ledger should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        $ledger = Ledger::factory()->create(['is_active' => true]);

        $this->put(route('admin.ledgers.update', $ledger), [
            'ledger_code'  => $ledger->ledger_code,
            'ledger_name'  => $ledger->ledger_name . ' updated',
            'account_type' => $ledger->account_type,
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($ledger->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // is_control_account, control_account_type
    // ====================================================================

    public function test_is_control_account_accepts_boolean(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'        => 'CA-TRUE-01',
            'ledger_name'        => 'Control True',
            'account_type'       => 'Asset',
            'is_control_account' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'        => 'CA-TRUE-01',
            'is_control_account' => true,
        ]);
    }

    public function test_control_account_type_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NOCT-01',
            'ledger_name'  => 'No Control Type',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_control_account_type_accepts_string(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'          => 'CAT-01',
            'ledger_name'          => 'Has Control Type',
            'account_type'         => 'Asset',
            'control_account_type' => 'customer',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code'          => 'CAT-01',
            'control_account_type' => 'customer',
        ]);
    }

    // ====================================================================
    // description (Phase 15)
    // ====================================================================

    public function test_description_is_optional(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'NODESC-01',
            'ledger_name'  => 'No Description',
            'account_type' => 'Asset',
        ])->assertRedirect();
    }

    public function test_description_accepts_string(): void
    {
        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'DESC-01',
            'ledger_name'  => 'Has Description',
            'account_type' => 'Asset',
            'description'  => 'A test ledger for the audit module.',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'DESC-01',
            'description' => 'A test ledger for the audit module.',
        ]);
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.ledgers.store'), [
            'ledger_code'     => 'INVALID CODE!',        // regex/invalid (not auto-generated when provided)
            'ledger_name'     => '',                     // required
            'account_type'    => 'NotAType',             // in
            'opening_balance' => 'not-a-number',         // numeric
            'sort_order'      => 'not-an-int',           // integer
        ]);

        $response->assertSessionHasErrors([
            'ledger_name',
            'account_type',
            'opening_balance',
            'sort_order',
        ]);
    }
}
