<?php

namespace Tests\Unit\Services;

use App\Models\Ledger;
use App\Services\MasterData\CodeGenerator;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Phase 16: CodeGenerator service tests.
 *
 * Verifies that the auto-gen code service correctly generates sequential
 * codes (L-NNNN, P-NNNN, C-NNNN, etc.) for master-data entities.
 */
class CodeGeneratorTest extends TestCase
{
    use BuildsRoleUsers;

    public function test_ledger_code_starts_at_L_0001_when_empty(): void
    {
        $code = CodeGenerator::ledgerCode();

        $this->assertEquals('L-0001', $code);
    }

    public function test_ledger_code_increments_from_existing_max(): void
    {
        Ledger::factory()->create(['ledger_code' => 'L-0005']);

        $code = CodeGenerator::ledgerCode();

        $this->assertEquals('L-0006', $code);
    }

    public function test_ledger_code_handles_non_sequential_existing_codes(): void
    {
        Ledger::factory()->create(['ledger_code' => 'L-0003']);
        Ledger::factory()->create(['ledger_code' => 'L-0007']);
        Ledger::factory()->create(['ledger_code' => 'L-0002']);

        $code = CodeGenerator::ledgerCode();

        $this->assertEquals('L-0008', $code);
    }

    public function test_ledger_code_ignores_other_prefixes(): void
    {
        Ledger::factory()->create(['ledger_code' => 'L-0010']);
        Ledger::factory()->create(['ledger_code' => 'CUSTOM-001']);
        Ledger::factory()->create(['ledger_code' => 'AR-0001']);

        $code = CodeGenerator::ledgerCode();

        $this->assertEquals('L-0011', $code);
    }

    public function test_generate_with_custom_prefix_and_pad_length(): void
    {
        // Use an existing table (ledgers) to avoid "table not found" fallback
        $code = CodeGenerator::generate('ledgers', 'ledger_code', 'X-', 6);

        // Should start at X-000001 when no X- prefixed codes exist
        $this->assertStringStartsWith('X-', $code);
        $this->assertEquals(8, strlen($code)); // X- + 6 digits
    }

    public function test_product_code_uses_P_prefix(): void
    {
        $code = CodeGenerator::productCode();

        $this->assertStringStartsWith('P-', $code);
        $this->assertEquals(6, strlen($code)); // P- + 4 digits
    }

    public function test_customer_code_uses_CUS_prefix_with_year(): void
    {
        // Phase 17: customerCode is now CUS-YYYY-NNNNNN (matches legacy
        // CustomerController::generateCustomerCode format).
        $code = CodeGenerator::customerCode();

        $this->assertStringStartsWith('CUS-', $code);
        $year = now()->format('Y');
        $this->assertStringStartsWith("CUS-{$year}-", $code);
    }

    public function test_customer_code_starts_at_000001_for_current_year(): void
    {
        // Ensure no customers exist for this year so we test the empty case.
        $year = now()->format('Y');
        $prefix = "CUS-{$year}-";
        \App\Models\Customer::where('customer_code', 'LIKE', "{$prefix}%")->delete();

        $code = CodeGenerator::customerCode();

        $this->assertEquals("{$prefix}000001", $code);
    }

    public function test_customer_code_increments_within_year(): void
    {
        $year = now()->format('Y');
        $prefix = "CUS-{$year}-";
        \App\Models\Customer::factory()->create(['customer_code' => "{$prefix}000005"]);

        $code = CodeGenerator::customerCode();

        $this->assertEquals("{$prefix}000006", $code);
    }

    public function test_supplier_code_uses_SUP_prefix(): void
    {
        // Phase 17: supplierCode is now SUP-NNNNNN (matches legacy
        // SupplierController::generateSupplierCode format).
        $code = CodeGenerator::supplierCode();

        $this->assertStringStartsWith('SUP-', $code);
        $this->assertEquals(10, strlen($code)); // SUP- + 6 digits
    }

    public function test_employee_code_uses_EMP_prefix(): void
    {
        // Phase 17: employeeCode is now EMP-NNNNNN (6-digit pad, matches
        // legacy EmployeeController::generateEmployeeCode format).
        $code = CodeGenerator::employeeCode();

        $this->assertStringStartsWith('EMP-', $code);
        $this->assertEquals(10, strlen($code)); // EMP- + 6 digits
    }

    public function test_warehouse_code_uses_WH_prefix(): void
    {
        $code = CodeGenerator::warehouseCode();

        $this->assertStringStartsWith('WH-', $code);
    }

    public function test_ledger_store_auto_generates_code_when_blank(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $this->post(route('admin.ledgers.store'), [
            'ledger_name'  => 'Auto-Generated Code Test',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $ledger = Ledger::where('ledger_name', 'Auto-Generated Code Test')->first();
        $this->assertNotNull($ledger);
        $this->assertStringStartsWith('L-', $ledger->ledger_code);
        $this->assertNotEmpty($ledger->ledger_code);
    }

    public function test_ledger_store_uses_provided_code_when_not_blank(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $this->post(route('admin.ledgers.store'), [
            'ledger_code'  => 'CUSTOM-LEDGER-01',
            'ledger_name'  => 'Custom Code Test',
            'account_type' => 'Asset',
        ])->assertRedirect();

        $this->assertDatabaseHas('ledgers', [
            'ledger_code' => 'CUSTOM-LEDGER-01',
            'ledger_name' => 'Custom Code Test',
        ]);
    }
}
