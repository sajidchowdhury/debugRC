<?php

namespace Tests\Feature\Supplier;

use App\Models\Branch;
use App\Models\Supplier;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Supplier Validation tests — verifies the validation rules defined in
 * SupplierController::validationRules().
 *
 * Rules (Phase 11):
 *   - supplier_code:    nullable|string|max:30|unique:suppliers,supplier_code,{id}
 *   - supplier_name:    required|string|max:200
 *   - phone:            nullable|string|max:30
 *   - mobile:           nullable|string|max:30
 *   - email:            nullable|email|max:100
 *   - address:          nullable|string
 *   - branch_id:        nullable|exists:branches,id
 *   - contact_person:   nullable|string|max:100
 *   - opening_balance:  nullable|numeric
 *   - balance_type:     nullable|in:debit,credit
 *   - is_active:        boolean
 *
 * Phase 11 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - supplier_code is uppercased + trimmed BEFORE validation
 *     (case-insensitive unique check)
 */
class SupplierValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // supplier_code — nullable (auto-generated when blank)
    // ====================================================================

    public function test_supplier_code_is_optional_on_store(): void
    {
        // When supplier_code is omitted, the controller auto-generates one.
        $this->post(route('admin.suppliers.store'), [
            'supplier_name' => 'Auto Code Supplier',
        ])->assertRedirect();
    }

    public function test_supplier_code_is_optional_on_update(): void
    {
        $supplier = Supplier::factory()->create();

        // On update, omitting supplier_code does NOT trigger required error
        // because the rule is 'nullable'. (However, the underlying column is
        // NOT NULL so this would normally fail at the DB level — but the
        // factory-created supplier already has a code, so we don't null it.)
        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_name' => 'Updated Name Only',
            'is_active'     => true,
        ])->assertRedirect();
    }

    // ====================================================================
    // supplier_code — max length 30
    // ====================================================================

    public function test_supplier_code_max_length_30(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => str_repeat('A', 31), // 31 chars
            'supplier_name' => 'Too Long Code',
        ])->assertSessionHasErrors('supplier_code');
    }

    public function test_supplier_code_accepts_exactly_30_chars(): void
    {
        $code = str_repeat('A', 30);
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => $code,
            'supplier_name' => 'Exactly 30 Chars',
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['supplier_name' => 'Exactly 30 Chars']);
    }

    // ====================================================================
    // supplier_code — unique (case-insensitive after normalization)
    // ====================================================================

    public function test_supplier_code_must_be_unique_on_store(): void
    {
        Supplier::factory()->create(['supplier_code' => 'UNIQ-SUP-001']);

        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'UNIQ-SUP-001',
            'supplier_name' => 'Duplicate',
        ])->assertSessionHasErrors('supplier_code');
    }

    public function test_supplier_code_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 11: supplier_code is uppercased + trimmed BEFORE validation.
        // 'uniq-sup-002' becomes 'UNIQ-SUP-002' before unique check, so it
        // SHOULD collide with existing 'UNIQ-SUP-002'.
        Supplier::factory()->create(['supplier_code' => 'UNIQ-SUP-002']);

        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'uniq-sup-002',
            'supplier_name' => 'Case Collision Test',
        ])->assertSessionHasErrors('supplier_code');
    }

    public function test_supplier_code_normalized_to_uppercase_on_store(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'norm-sup-01',
            'supplier_name' => 'Normalization Test',
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'NORM-SUP-01',
            'supplier_name' => 'Normalization Test',
        ]);
    }

    public function test_supplier_code_normalized_to_uppercase_on_update(): void
    {
        $supplier = Supplier::factory()->create(['supplier_code' => 'UPD-OLD-01']);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'upd-new-01',
            'supplier_name' => $supplier->supplier_name,
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'id'            => $supplier->id,
            'supplier_code' => 'UPD-NEW-01',
        ]);
    }

    public function test_supplier_code_unique_allows_keeping_own_code_on_update(): void
    {
        $supplier = Supplier::factory()->create(['supplier_code' => 'KEEP-SUP-02']);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'KEEP-SUP-02',
            'supplier_name' => 'Same Code Update',
            'is_active'     => true,
        ])->assertRedirect();
    }

    public function test_supplier_code_unique_rejects_other_suppliers_code_on_update(): void
    {
        Supplier::factory()->create(['supplier_code' => 'TAKEN-SUP-02']);
        $supplier = Supplier::factory()->create(['supplier_code' => 'OWN-SUP-02']);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'TAKEN-SUP-02',
            'supplier_name' => 'Steal Other Code',
            'is_active'     => true,
        ])->assertSessionHasErrors('supplier_code');
    }

    // ====================================================================
    // supplier_name — required, max 200
    // ====================================================================

    public function test_supplier_name_is_required_on_store(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'NAME-REQ-SUP-01',
        ])->assertSessionHasErrors('supplier_name');
    }

    public function test_supplier_name_is_required_on_update(): void
    {
        $supplier = Supplier::factory()->create();

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            // supplier_name omitted
            'is_active'     => true,
        ])->assertSessionHasErrors('supplier_name');
    }

    public function test_supplier_name_max_length_200(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'NAME-LONG-SUP-01',
            'supplier_name' => str_repeat('X', 201),
        ])->assertSessionHasErrors('supplier_name');
    }

    public function test_supplier_name_accepts_exactly_200_chars(): void
    {
        $name = str_repeat('X', 200);
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'NAME-200-SUP-01',
            'supplier_name' => $name,
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'NAME-200-SUP-01',
            'supplier_name' => $name,
        ]);
    }

    public function test_supplier_name_is_trimmed_on_store(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'TRIM-SUP-01',
            'supplier_name' => '  Padded Name  ',
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'TRIM-SUP-01',
            'supplier_name' => 'Padded Name',
        ]);
    }

    // ====================================================================
    // phone / mobile — nullable, max 30
    // ====================================================================

    public function test_phone_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'PH-OPT-SUP-01',
            'supplier_name' => 'No Phone',
        ])->assertRedirect();
    }

    public function test_phone_max_length_30(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'PH-LONG-SUP-01',
            'supplier_name' => 'Phone Too Long',
            'phone'         => str_repeat('1', 31),
        ])->assertSessionHasErrors('phone');
    }

    public function test_mobile_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'MO-OPT-SUP-01',
            'supplier_name' => 'No Mobile',
        ])->assertRedirect();
    }

    public function test_mobile_max_length_30(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'MO-LONG-SUP-01',
            'supplier_name' => 'Mobile Too Long',
            'mobile'        => str_repeat('2', 31),
        ])->assertSessionHasErrors('mobile');
    }

    // ====================================================================
    // email — nullable, email format, max 100
    // ====================================================================

    public function test_email_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'EM-OPT-SUP-01',
            'supplier_name' => 'No Email',
        ])->assertRedirect();
    }

    public function test_email_must_be_valid_format(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'EM-BAD-SUP-01',
            'supplier_name' => 'Bad Email',
            'email'         => 'not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_max_length_100(): void
    {
        $longEmail = str_repeat('a', 90) . '@example.com'; // > 100 chars
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'EM-LONG-SUP-01',
            'supplier_name' => 'Email Too Long',
            'email'         => $longEmail,
        ])->assertSessionHasErrors('email');
    }

    public function test_email_accepts_valid_format(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'EM-OK-SUP-01',
            'supplier_name' => 'Valid Email',
            'email'         => 'supplier@example.com',
        ])->assertRedirect();
    }

    // ====================================================================
    // address — nullable string
    // ====================================================================

    public function test_address_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AD-OPT-SUP-01',
            'supplier_name' => 'No Address',
        ])->assertRedirect();
    }

    public function test_address_accepts_long_text(): void
    {
        $longAddress = str_repeat('Address line. ', 50);
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'AD-LONG-SUP-01',
            'supplier_name' => 'Long Address',
            'address'       => $longAddress,
        ])->assertRedirect();
    }

    // ====================================================================
    // branch_id — nullable + exists
    // ====================================================================

    public function test_branch_id_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BR-OPT-SUP-01',
            'supplier_name' => 'No Branch',
        ])->assertRedirect();
    }

    public function test_branch_id_must_exist(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BR-BAD-SUP-01',
            'supplier_name' => 'Bad Branch',
            'branch_id'     => 999999,
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_id_accepts_valid_id(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BR-OK-SUP-01',
            'supplier_name' => 'Valid Branch',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // contact_person — nullable, max 100
    // ====================================================================

    public function test_contact_person_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'CP-OPT-SUP-01',
            'supplier_name' => 'No Contact Person',
        ])->assertRedirect();
    }

    public function test_contact_person_max_length_100(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code'   => 'CP-LONG-SUP-01',
            'supplier_name'   => 'Contact Too Long',
            'contact_person'  => str_repeat('c', 101),
        ])->assertSessionHasErrors('contact_person');
    }

    public function test_contact_person_accepts_exactly_100_chars(): void
    {
        $name = str_repeat('c', 100);
        $this->post(route('admin.suppliers.store'), [
            'supplier_code'   => 'CP-100-SUP-01',
            'supplier_name'   => 'Contact 100',
            'contact_person'  => $name,
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'supplier_code'  => 'CP-100-SUP-01',
            'contact_person' => $name,
        ]);
    }

    // ====================================================================
    // opening_balance — nullable + numeric (no min:0 constraint)
    // ====================================================================

    public function test_opening_balance_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'OB-OPT-SUP-01',
            'supplier_name' => 'No Opening Balance',
        ])->assertRedirect();
    }

    public function test_opening_balance_must_be_numeric(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code'   => 'OB-BAD-SUP-01',
            'supplier_name'   => 'Bad Opening Balance',
            'opening_balance' => 'free',
        ])->assertSessionHasErrors('opening_balance');
    }

    public function test_opening_balance_accepts_zero_and_negative(): void
    {
        // No min:0 constraint — negative opening balances (debit balance
        // brought forward — i.e. supplier has an advance) are allowed.
        $this->post(route('admin.suppliers.store'), [
            'supplier_code'   => 'OB-ZN-SUP-01',
            'supplier_name'   => 'Zero Negative Opening',
            'opening_balance' => -100.50,
        ])->assertRedirect();
    }

    // ====================================================================
    // balance_type — nullable + enum debit/credit
    // ====================================================================

    public function test_balance_type_is_optional(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BT-OPT-SUP-01',
            'supplier_name' => 'No Balance Type',
        ])->assertRedirect();
    }

    public function test_balance_type_accepts_debit(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BT-DB-SUP-01',
            'supplier_name' => 'Debit Balance',
            'balance_type'  => 'debit',
        ])->assertRedirect();
    }

    public function test_balance_type_accepts_credit(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BT-CR-SUP-01',
            'supplier_name' => 'Credit Balance',
            'balance_type'  => 'credit',
        ])->assertRedirect();
    }

    public function test_balance_type_rejects_invalid_value(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BT-BAD-SUP-01',
            'supplier_name' => 'Bad Balance Type',
            'balance_type'  => 'prepaid',
        ])->assertSessionHasErrors('balance_type');
    }

    public function test_balance_type_case_sensitive_check(): void
    {
        // Validation rule uses `in:` which is case-sensitive — 'DEBIT' should fail.
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'BT-CASE-SUP-01',
            'supplier_name' => 'Uppercase Balance Type',
            'balance_type'  => 'DEBIT',
        ])->assertSessionHasErrors('balance_type');
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'ACT-TRUE-SUP-01',
            'supplier_name' => 'Active True',
            'is_active'     => true,
        ])->assertRedirect();

        $supplier = Supplier::where('supplier_code', 'ACT-TRUE-SUP-01')->first();
        $this->assertTrue($supplier->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'ACT-FALSE-SUP-01',
            'supplier_name' => 'Active False',
            'is_active'     => false,
        ])->assertRedirect();

        $supplier = Supplier::where('supplier_code', 'ACT-FALSE-SUP-01')->first();
        $this->assertFalse($supplier->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'ACT-DEF-SUP-01',
            'supplier_name' => 'Default Active',
        ])->assertRedirect();

        $supplier = Supplier::where('supplier_code', 'ACT-DEF-SUP-01')->first();
        $this->assertTrue($supplier->is_active, 'Supplier should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        // Phase 11 fix: omitting is_active on update should NOT change is_active.
        $supplier = Supplier::factory()->create(['is_active' => true]);

        $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => 'Some Update',
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($supplier->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_name' => '',                 // required
            'email'         => 'not-an-email',     // invalid email
            'branch_id'     => 999999,             // does not exist
        ]);

        $response->assertSessionHasErrors(['supplier_name', 'email', 'branch_id']);
    }
}
