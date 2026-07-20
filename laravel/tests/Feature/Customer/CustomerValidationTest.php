<?php

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Customer Validation tests — verifies the validation rules defined in
 * CustomerController::validationRules().
 *
 * Rules (Phase 10):
 *   - customer_code:    nullable|string|max:30|unique:customers,customer_code,{id}
 *   - customer_name:    required|string|max:200
 *   - phone:            nullable|string|max:30
 *   - mobile:           nullable|string|max:30
 *   - email:            nullable|email|max:100
 *   - address:          nullable|string
 *   - branch_id:        nullable|exists:branches,id
 *   - sales_person_id:  nullable|exists:employees,id
 *   - credit_limit:     nullable|numeric|min:0
 *   - opening_balance:  nullable|numeric
 *   - balance_type:     nullable|in:debit,credit
 *   - is_active:        boolean
 *
 * Phase 10 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - customer_code is uppercased + trimmed BEFORE validation
 *     (case-insensitive unique check)
 */
class CustomerValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // customer_code — nullable (auto-generated when blank)
    // ====================================================================

    public function test_customer_code_is_optional_on_store(): void
    {
        // When customer_code is omitted, the controller auto-generates one.
        $this->post(route('admin.customers.store'), [
            'customer_name' => 'Auto Code Customer',
        ])->assertRedirect();
    }

    public function test_customer_code_is_optional_on_update(): void
    {
        $customer = Customer::factory()->create();

        // On update, omitting customer_code does NOT trigger required error
        // because the rule is 'nullable'. (However, the underlying column is
        // NOT NULL so this would normally fail at the DB level — but the
        // factory-created customer already has a code, so we don't null it.)
        $this->put(route('admin.customers.update', $customer), [
            'customer_name' => 'Updated Name Only',
            'is_active'     => true,
        ])->assertRedirect();
    }

    // ====================================================================
    // customer_code — max length 30
    // ====================================================================

    public function test_customer_code_max_length_30(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => str_repeat('A', 31), // 31 chars
            'customer_name' => 'Too Long Code',
        ])->assertSessionHasErrors('customer_code');
    }

    public function test_customer_code_accepts_exactly_30_chars(): void
    {
        $code = str_repeat('A', 30);
        $this->post(route('admin.customers.store'), [
            'customer_code' => $code,
            'customer_name' => 'Exactly 30 Chars',
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', ['customer_name' => 'Exactly 30 Chars']);
    }

    // ====================================================================
    // customer_code — unique (case-insensitive after normalization)
    // ====================================================================

    public function test_customer_code_must_be_unique_on_store(): void
    {
        Customer::factory()->create(['customer_code' => 'UNIQ-CUST-001']);

        $this->post(route('admin.customers.store'), [
            'customer_code' => 'UNIQ-CUST-001',
            'customer_name' => 'Duplicate',
        ])->assertSessionHasErrors('customer_code');
    }

    public function test_customer_code_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 10: customer_code is uppercased + trimmed BEFORE validation.
        // 'uniq-cust-002' becomes 'UNIQ-CUST-002' before unique check, so it
        // SHOULD collide with existing 'UNIQ-CUST-002'.
        Customer::factory()->create(['customer_code' => 'UNIQ-CUST-002']);

        $this->post(route('admin.customers.store'), [
            'customer_code' => 'uniq-cust-002',
            'customer_name' => 'Case Collision Test',
        ])->assertSessionHasErrors('customer_code');
    }

    public function test_customer_code_normalized_to_uppercase_on_store(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'norm-cust-01',
            'customer_name' => 'Normalization Test',
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('customers', [
            'customer_code' => 'NORM-CUST-01',
            'customer_name' => 'Normalization Test',
        ]);
    }

    public function test_customer_code_normalized_to_uppercase_on_update(): void
    {
        $customer = Customer::factory()->create(['customer_code' => 'UPD-OLD-01']);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'upd-new-01',
            'customer_name' => $customer->customer_name,
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'id'            => $customer->id,
            'customer_code' => 'UPD-NEW-01',
        ]);
    }

    public function test_customer_code_unique_allows_keeping_own_code_on_update(): void
    {
        $customer = Customer::factory()->create(['customer_code' => 'KEEP-CUST-02']);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'KEEP-CUST-02',
            'customer_name' => 'Same Code Update',
            'is_active'     => true,
        ])->assertRedirect();
    }

    public function test_customer_code_unique_rejects_other_customers_code_on_update(): void
    {
        Customer::factory()->create(['customer_code' => 'TAKEN-CUST-02']);
        $customer = Customer::factory()->create(['customer_code' => 'OWN-CUST-02']);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => 'TAKEN-CUST-02',
            'customer_name' => 'Steal Other Code',
            'is_active'     => true,
        ])->assertSessionHasErrors('customer_code');
    }

    // ====================================================================
    // customer_name — required, max 200
    // ====================================================================

    public function test_customer_name_is_required_on_store(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'NAME-REQ-CUST-01',
        ])->assertSessionHasErrors('customer_name');
    }

    public function test_customer_name_is_required_on_update(): void
    {
        $customer = Customer::factory()->create();

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            // customer_name omitted
            'is_active'     => true,
        ])->assertSessionHasErrors('customer_name');
    }

    public function test_customer_name_max_length_200(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'NAME-LONG-CUST-01',
            'customer_name' => str_repeat('X', 201),
        ])->assertSessionHasErrors('customer_name');
    }

    public function test_customer_name_accepts_exactly_200_chars(): void
    {
        $name = str_repeat('X', 200);
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'NAME-200-CUST-01',
            'customer_name' => $name,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'customer_code' => 'NAME-200-CUST-01',
            'customer_name' => $name,
        ]);
    }

    public function test_customer_name_is_trimmed_on_store(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'TRIM-CUST-01',
            'customer_name' => '  Padded Name  ',
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'customer_code' => 'TRIM-CUST-01',
            'customer_name' => 'Padded Name',
        ]);
    }

    // ====================================================================
    // phone / mobile — nullable, max 30
    // ====================================================================

    public function test_phone_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'PH-OPT-CUST-01',
            'customer_name' => 'No Phone',
        ])->assertRedirect();
    }

    public function test_phone_max_length_30(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'PH-LONG-CUST-01',
            'customer_name' => 'Phone Too Long',
            'phone'         => str_repeat('1', 31),
        ])->assertSessionHasErrors('phone');
    }

    public function test_mobile_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'MO-OPT-CUST-01',
            'customer_name' => 'No Mobile',
        ])->assertRedirect();
    }

    public function test_mobile_max_length_30(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'MO-LONG-CUST-01',
            'customer_name' => 'Mobile Too Long',
            'mobile'        => str_repeat('2', 31),
        ])->assertSessionHasErrors('mobile');
    }

    // ====================================================================
    // email — nullable, email format, max 100
    // ====================================================================

    public function test_email_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'EM-OPT-CUST-01',
            'customer_name' => 'No Email',
        ])->assertRedirect();
    }

    public function test_email_must_be_valid_format(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'EM-BAD-CUST-01',
            'customer_name' => 'Bad Email',
            'email'         => 'not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_max_length_100(): void
    {
        $longEmail = str_repeat('a', 90) . '@example.com'; // > 100 chars
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'EM-LONG-CUST-01',
            'customer_name' => 'Email Too Long',
            'email'         => $longEmail,
        ])->assertSessionHasErrors('email');
    }

    public function test_email_accepts_valid_format(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'EM-OK-CUST-01',
            'customer_name' => 'Valid Email',
            'email'         => 'customer@example.com',
        ])->assertRedirect();
    }

    // ====================================================================
    // address — nullable string
    // ====================================================================

    public function test_address_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AD-OPT-CUST-01',
            'customer_name' => 'No Address',
        ])->assertRedirect();
    }

    public function test_address_accepts_long_text(): void
    {
        $longAddress = str_repeat('Address line. ', 50);
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'AD-LONG-CUST-01',
            'customer_name' => 'Long Address',
            'address'       => $longAddress,
        ])->assertRedirect();
    }

    // ====================================================================
    // branch_id — nullable + exists
    // ====================================================================

    public function test_branch_id_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BR-OPT-CUST-01',
            'customer_name' => 'No Branch',
        ])->assertRedirect();
    }

    public function test_branch_id_must_exist(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BR-BAD-CUST-01',
            'customer_name' => 'Bad Branch',
            'branch_id'     => 999999,
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_id_accepts_valid_id(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BR-OK-CUST-01',
            'customer_name' => 'Valid Branch',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // sales_person_id — nullable + exists
    // ====================================================================

    public function test_sales_person_id_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'SP-OPT-CUST-01',
            'customer_name' => 'No Sales Person',
        ])->assertRedirect();
    }

    public function test_sales_person_id_must_exist(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'SP-BAD-CUST-01',
            'customer_name'   => 'Bad Sales Person',
            'sales_person_id' => 999999,
        ])->assertSessionHasErrors('sales_person_id');
    }

    public function test_sales_person_id_accepts_valid_employee_id(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();

        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'SP-OK-CUST-01',
            'customer_name'   => 'Valid Sales Person',
            'sales_person_id' => $employee->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // credit_limit — nullable + numeric + min:0
    // ====================================================================

    public function test_credit_limit_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'CL-OPT-CUST-01',
            'customer_name' => 'No Credit Limit',
        ])->assertRedirect();
    }

    public function test_credit_limit_must_be_numeric(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'CL-BAD-CUST-01',
            'customer_name' => 'Bad Credit Limit',
            'credit_limit'  => 'not-a-number',
        ])->assertSessionHasErrors('credit_limit');
    }

    public function test_credit_limit_rejects_negative(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'CL-NEG-CUST-01',
            'customer_name' => 'Negative Credit Limit',
            'credit_limit'  => -1,
        ])->assertSessionHasErrors('credit_limit');
    }

    public function test_credit_limit_accepts_zero(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'CL-ZERO-CUST-01',
            'customer_name' => 'Zero Credit Limit',
            'credit_limit'  => 0,
        ])->assertRedirect();
    }

    // ====================================================================
    // opening_balance — nullable + numeric (no min:0 constraint)
    // ====================================================================

    public function test_opening_balance_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'OB-OPT-CUST-01',
            'customer_name' => 'No Opening Balance',
        ])->assertRedirect();
    }

    public function test_opening_balance_must_be_numeric(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'OB-BAD-CUST-01',
            'customer_name'   => 'Bad Opening Balance',
            'opening_balance' => 'free',
        ])->assertSessionHasErrors('opening_balance');
    }

    public function test_opening_balance_accepts_zero_and_negative(): void
    {
        // No min:0 constraint — negative opening balances (credit balance
        // brought forward) are allowed.
        $this->post(route('admin.customers.store'), [
            'customer_code'   => 'OB-ZN-CUST-01',
            'customer_name'   => 'Zero Negative Opening',
            'opening_balance' => -100.50,
        ])->assertRedirect();
    }

    // ====================================================================
    // balance_type — nullable + enum debit/credit
    // ====================================================================

    public function test_balance_type_is_optional(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BT-OPT-CUST-01',
            'customer_name' => 'No Balance Type',
        ])->assertRedirect();
    }

    public function test_balance_type_accepts_debit(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BT-DB-CUST-01',
            'customer_name' => 'Debit Balance',
            'balance_type'  => 'debit',
        ])->assertRedirect();
    }

    public function test_balance_type_accepts_credit(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BT-CR-CUST-01',
            'customer_name' => 'Credit Balance',
            'balance_type'  => 'credit',
        ])->assertRedirect();
    }

    public function test_balance_type_rejects_invalid_value(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BT-BAD-CUST-01',
            'customer_name' => 'Bad Balance Type',
            'balance_type'  => 'prepaid',
        ])->assertSessionHasErrors('balance_type');
    }

    public function test_balance_type_case_sensitive_check(): void
    {
        // Validation rule uses `in:` which is case-sensitive — 'DEBIT' should fail.
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'BT-CASE-CUST-01',
            'customer_name' => 'Uppercase Balance Type',
            'balance_type'  => 'DEBIT',
        ])->assertSessionHasErrors('balance_type');
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'ACT-TRUE-CUST-01',
            'customer_name' => 'Active True',
            'is_active'     => true,
        ])->assertRedirect();

        $customer = Customer::where('customer_code', 'ACT-TRUE-CUST-01')->first();
        $this->assertTrue($customer->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'ACT-FALSE-CUST-01',
            'customer_name' => 'Active False',
            'is_active'     => false,
        ])->assertRedirect();

        $customer = Customer::where('customer_code', 'ACT-FALSE-CUST-01')->first();
        $this->assertFalse($customer->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.customers.store'), [
            'customer_code' => 'ACT-DEF-CUST-01',
            'customer_name' => 'Default Active',
        ])->assertRedirect();

        $customer = Customer::where('customer_code', 'ACT-DEF-CUST-01')->first();
        $this->assertTrue($customer->is_active, 'Customer should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        // Phase 10 fix: omitting is_active on update should NOT change is_active.
        $customer = Customer::factory()->create(['is_active' => true]);

        $this->put(route('admin.customers.update', $customer), [
            'customer_code' => $customer->customer_code,
            'customer_name' => 'Some Update',
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($customer->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.customers.store'), [
            'customer_name' => '',                 // required
            'email'         => 'not-an-email',     // invalid email
            'branch_id'     => 999999,             // does not exist
        ]);

        $response->assertSessionHasErrors(['customer_name', 'email', 'branch_id']);
    }
}
