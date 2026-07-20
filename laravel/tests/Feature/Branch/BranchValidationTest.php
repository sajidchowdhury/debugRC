<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Branch Validation tests — verifies the Phase 5 validation rules
 * defined in BranchController::validationRules().
 *
 * Rules (Phase 5 commit `d466dfe`):
 *   - branch_code: required|string|max:20|regex:/^[A-Za-z0-9\-_.]+$/|unique:branches,branch_code,{id}
 *   - branch_name: required|string|max:100
 *   - address:     nullable|string
 *   - phone:       nullable|string|max:20
 *   - email:       nullable|email|max:100
 *   - is_active:   boolean
 *
 * Phase 5 also added:
 *   - branch_code is normalized to UPPERCASE on store + update
 *   - branch_name + phone + email + address are trimmed
 *   - Active-branch check on create (legacy rule: cannot create with is_active=false)
 *   - Deactivation check on update (handled in BranchToggleTest)
 */
class BranchValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // branch_code — required
    // ====================================================================

    public function test_branch_code_is_required_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_name' => 'Missing Code Branch',
        ])->assertSessionHasErrors('branch_code');
    }

    public function test_branch_code_is_required_on_update(): void
    {
        $branch = Branch::factory()->create();

        $this->put(route('admin.branches.update', $branch), [
            'branch_name' => 'Updated Name',
        ])->assertSessionHasErrors('branch_code');
    }

    // ====================================================================
    // branch_code — max length 20
    // ====================================================================

    public function test_branch_code_max_length_20(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => str_repeat('A', 21), // 21 chars
            'branch_name' => 'Too Long Code',
        ])->assertSessionHasErrors('branch_code');
    }

    public function test_branch_code_accepts_exactly_20_chars(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => str_repeat('A', 20),
            'branch_name' => 'Exactly 20 Chars',
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', ['branch_name' => 'Exactly 20 Chars']);
    }

    // ====================================================================
    // branch_code — regex pattern (Phase 5)
    // ====================================================================

    public function test_branch_code_accepts_alphanumeric_dashes_underscores_dots(): void
    {
        $codes = ['HO-001', 'PT_001', 'NW.001', 'TR-1.0', 'Branch_01-02'];

        foreach ($codes as $code) {
            $this->post(route('admin.branches.store'), [
                'branch_code' => $code,
                'branch_name' => 'Code Test ' . $code,
            ])->assertRedirect();
        }
    }

    public function test_branch_code_rejects_spaces(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'HO 001',
            'branch_name' => 'Space Test',
        ])->assertSessionHasErrors('branch_code');
    }

    public function test_branch_code_rejects_special_characters(): void
    {
        $invalidCodes = ['HO@001', 'PT#001', 'NW/001', 'TR*001', 'BR!001', 'HO+001', 'HO=001'];

        foreach ($invalidCodes as $code) {
            $this->post(route('admin.branches.store'), [
                'branch_code' => $code,
                'branch_name' => 'Special Char Test ' . $code,
            ])->assertSessionHasErrors('branch_code');
        }
    }

    public function test_branch_code_rejects_empty_string(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => '',
            'branch_name' => 'Empty Code',
        ])->assertSessionHasErrors('branch_code');
    }

    // ====================================================================
    // branch_code — unique (Phase 5)
    // ====================================================================

    public function test_branch_code_must_be_unique_on_store(): void
    {
        Branch::factory()->create(['branch_code' => 'UNIQ-001']);

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'UNIQ-001',
            'branch_name' => 'Duplicate',
        ])->assertSessionHasErrors('branch_code');
    }

    public function test_branch_code_unique_check_is_case_insensitive_due_to_uppercasing(): void
    {
        // Phase 5: branch_code is uppercased on store, so 'uniq-002' becomes 'UNIQ-002'.
        Branch::factory()->create(['branch_code' => 'UNIQ-002']);

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'uniq-002', // will be uppercased to 'UNIQ-002'
            'branch_name' => 'Case Collision',
        ])->assertSessionHasErrors('branch_code');
    }

    public function test_branch_code_unique_allows_keeping_own_code_on_update(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'KEEP-02']);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'KEEP-02',
            'branch_name' => 'Same Code Update',
        ])->assertRedirect();
    }

    public function test_branch_code_unique_rejects_other_branches_code_on_update(): void
    {
        Branch::factory()->create(['branch_code' => 'TAKEN-02']);
        $branch = Branch::factory()->create(['branch_code' => 'OWN-02']);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'TAKEN-02',
            'branch_name' => 'Steal Other Code',
        ])->assertSessionHasErrors('branch_code');
    }

    // ====================================================================
    // branch_name — required, max 100
    // ====================================================================

    public function test_branch_name_is_required_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'NAME-REQ-01',
        ])->assertSessionHasErrors('branch_name');
    }

    public function test_branch_name_max_length_100(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'NAME-LONG-01',
            'branch_name' => str_repeat('X', 101),
        ])->assertSessionHasErrors('branch_name');
    }

    public function test_branch_name_accepts_exactly_100_chars(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'NAME-100-01',
            'branch_name' => str_repeat('X', 100),
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', [
            'branch_code' => 'NAME-100-01',
            'branch_name' => str_repeat('X', 100),
        ]);
    }

    // ====================================================================
    // phone — max 20
    // ====================================================================

    public function test_phone_max_length_20(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'PHONE-LONG-01',
            'branch_name' => 'Phone Long',
            'phone'       => str_repeat('1', 21),
        ])->assertSessionHasErrors('phone');
    }

    public function test_phone_accepts_exactly_20_chars(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'PHONE-20-01',
            'branch_name' => 'Phone 20',
            'phone'       => str_repeat('1', 20),
        ])->assertRedirect();
    }

    public function test_phone_is_optional(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'PHONE-OPT-01',
            'branch_name' => 'No Phone',
        ])->assertRedirect();
    }

    // ====================================================================
    // email — must be valid, max 100
    // ====================================================================

    public function test_email_must_be_valid_format(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'EMAIL-BAD-01',
            'branch_name' => 'Bad Email',
            'email'       => 'not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_max_length_100(): void
    {
        $longEmail = str_repeat('a', 90) . '@example.com'; // > 100 chars

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'EMAIL-LONG-01',
            'branch_name' => 'Long Email',
            'email'       => $longEmail,
        ])->assertSessionHasErrors('email');
    }

    public function test_email_accepts_valid_format(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'EMAIL-OK-01',
            'branch_name' => 'Valid Email',
            'email'       => 'valid@example.com',
        ])->assertRedirect();
    }

    public function test_email_is_optional(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'EMAIL-OPT-01',
            'branch_name' => 'No Email',
        ])->assertRedirect();
    }

    // ====================================================================
    // address — optional string
    // ====================================================================

    public function test_address_is_optional(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'ADDR-OPT-01',
            'branch_name' => 'No Address',
        ])->assertRedirect();
    }

    public function test_address_accepts_long_text(): void
    {
        $longAddress = str_repeat('This is a long address. ', 50);

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'ADDR-LONG-01',
            'branch_name' => 'Long Address',
            'address'     => $longAddress,
        ])->assertRedirect();
    }

    // ====================================================================
    // is_active — boolean
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'ACTIVE-01',
            'branch_name' => 'Active Branch',
            'is_active'   => true,
        ])->assertRedirect();

        $branch = Branch::where('branch_code', 'ACTIVE-01')->first();
        $this->assertTrue($branch->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'INACTIVE-01',
            'branch_name' => 'Inactive Branch',
            'is_active'   => false,
        ])->assertRedirect();

        $branch = Branch::where('branch_code', 'INACTIVE-01')->first();
        $this->assertFalse($branch->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'DEFAULT-01',
            'branch_name' => 'Default Active',
        ])->assertRedirect();

        $branch = Branch::where('branch_code', 'DEFAULT-01')->first();
        $this->assertTrue($branch->is_active);
    }

    // ====================================================================
    // Normalization (Phase 5)
    // ====================================================================

    public function test_branch_code_uppercased_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'mixed-case-01',
            'branch_name' => 'Mixed Case',
        ]);

        $this->assertDatabaseHas('branches', ['branch_code' => 'MIXED-CASE-01']);
        $this->assertDatabaseMissing('branches', ['branch_code' => 'mixed-case-01']);
    }

    public function test_branch_name_trimmed_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'TRIM-01',
            'branch_name' => '  Trimmed Name  ',
        ]);

        $this->assertDatabaseHas('branches', ['branch_name' => 'Trimmed Name']);
    }

    public function test_phone_trimmed_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'TRIM-PHONE-01',
            'branch_name' => 'Trim Phone',
            'phone'       => '  01711111111  ',
        ]);

        $this->assertDatabaseHas('branches', ['phone' => '01711111111']);
    }

    public function test_email_trimmed_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'TRIM-EMAIL-01',
            'branch_name' => 'Trim Email',
            'email'       => '  test@example.com  ',
        ]);

        $this->assertDatabaseHas('branches', ['email' => 'test@example.com']);
    }

    public function test_address_trimmed_on_store(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'TRIM-ADDR-01',
            'branch_name' => 'Trim Address',
            'address'     => '  123 Trimmed St  ',
        ]);

        $this->assertDatabaseHas('branches', ['address' => '123 Trimmed St']);
    }

    public function test_normalization_applies_on_update_too(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'NORM-UPD-01']);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'norm-upd-01',
            'branch_name' => '  Updated Trimmed  ',
        ]);

        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_code' => 'NORM-UPD-01',
            'branch_name' => 'Updated Trimmed',
        ]);
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'INVALID CODE!', // bad regex
            'branch_name' => '',              // required
            'email'       => 'not-an-email',  // bad format
        ]);

        $response->assertSessionHasErrors(['branch_code', 'branch_name', 'email']);
    }
}
