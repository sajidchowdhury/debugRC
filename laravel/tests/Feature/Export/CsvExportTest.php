<?php

namespace Tests\Feature\Export;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBankDependencies;
use Tests\TestCase;

/**
 * Phase 18 — CSV export feature tests.
 *
 * Covers the 9 master-data modules that expose a server-side CSV export
 * endpoint via BaseMasterDataController::export():
 *
 *   GET /admin/branches/export    (admin, manager, warehouse_manager)
 *   GET /admin/warehouses/export  (admin, manager, warehouse_manager)
 *   GET /admin/products/export    (admin, manager, warehouse_manager)
 *   GET /admin/customers/export   (admin, manager, salesman)
 *   GET /admin/suppliers/export   (admin, manager, accountant)
 *   GET /admin/employees/export   (admin, manager, hr)
 *   GET /admin/banks/export       (admin, manager, accountant)
 *   GET /admin/users/export       (admin, manager)
 *   GET /admin/ledgers/export     (admin, accountant)
 *
 * Each test verifies:
 *   - The route returns 200 with text/csv Content-Type + BOM.
 *   - The CSV contains the expected header row.
 *   - The CSV contains at least one data row matching the seeded record.
 *   - Special characters (commas, quotes, newlines) are properly escaped.
 *
 * Auth + RBAC tests:
 *   - Unauthenticated → redirect to login.
 *   - Salesman (denied on branches) → redirect to dashboard.
 */
class CsvExportTest extends TestCase
{
    use BuildsRoleUsers, InsertsBankDependencies;

    // ====================================================================
    // SHARED ASSERTIONS
    // ====================================================================

    /**
     * Assert the response looks like a CSV download: 200, text/csv,
     * attachment Content-Disposition, starts with UTF-8 BOM.
     */
    private function assertCsvResponse($response): string
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);

        $content = $response->streamedContent();

        // UTF-8 BOM should be present (first 3 bytes).
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3), 'CSV is missing UTF-8 BOM.');

        return $content;
    }

    /**
     * Parse a CSV string (after BOM) into an array of rows,
     * each row an array of cell strings. Uses fgetcsv on a memory
     * stream so embedded newlines / quotes / commas in quoted fields
     * are handled correctly per RFC 4180.
     *
     * @return list<list<string|null>>
     */
    private function parseCsv(string $content): array
    {
        // Strip the BOM.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open memory stream for CSV parsing.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            // fgetcsv returns [''] for a trailing blank line — skip.
            if ($row === [null] || $row === ['']) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    // ====================================================================
    // PER-MODULE EXPORT TESTS
    // ====================================================================

    public function test_branch_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $branch = Branch::factory()->create([
            'branch_code' => 'EXBR-' . substr(uniqid(), -4),
            'branch_name' => 'Exportable Branch',
            'phone'       => '01700000000',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.branches.export')));
        $rows = $this->parseCsv($content);

        // Header row.
        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Branch Name', $rows[0]);

        // Find the seeded branch row.
        $found = false;
        foreach ($rows as $row) {
            if (in_array('EXBR-', [$row[0]], true) || str_starts_with((string) $row[0], 'EXBR-')) {
                $found = true;
                $this->assertSame('Exportable Branch', $row[1]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded branch {$branch->branch_code} not found in CSV output.");
    }

    public function test_warehouse_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $branch = Branch::factory()->create(['branch_name' => 'WH-Export-Branch']);
        $wh = Warehouse::factory()->forBranch($branch->id)->create([
            'warehouse_code' => 'EXWH-' . substr(uniqid(), -4),
            'warehouse_name' => 'Exportable Warehouse',
            'location'       => 'Building 7, Floor 2',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.warehouses.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Warehouse Name', $rows[0]);
        $this->assertContains('Branch Name', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXWH-')) {
                $found = true;
                $this->assertSame('Exportable Warehouse', $row[1]);
                $this->assertSame('WH-Export-Branch', $row[2]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded warehouse {$wh->warehouse_code} not found in CSV output.");
    }

    public function test_product_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $product = Product::factory()->create([
            'product_code' => 'EXP-' . substr(uniqid(), -4),
            'product_name' => 'Exportable Product',
            'unit'         => 'Pcs',
            'sales_rate'   => 99.50,
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.products.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Product Name', $rows[0]);
        $this->assertContains('Unit', $rows[0]);
        $this->assertContains('Sales Rate', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXP-')) {
                $found = true;
                $this->assertSame('Exportable Product', $row[1]);
                $this->assertSame('Pcs', $row[2]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded product {$product->product_code} not found in CSV output.");
    }

    public function test_customer_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $branch = Branch::factory()->create(['branch_name' => 'Cust-Export-Branch']);
        $customer = Customer::factory()->forBranch($branch->id)->create([
            'customer_code' => 'EXCUS-' . substr(uniqid(), -4),
            'customer_name' => 'Exportable Customer',
            'mobile'        => '01800000000',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.customers.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Customer Name', $rows[0]);
        $this->assertContains('Branch Name', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXCUS-')) {
                $found = true;
                $this->assertSame('Exportable Customer', $row[1]);
                $this->assertSame('Cust-Export-Branch', $row[5]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded customer {$customer->customer_code} not found in CSV output.");
    }

    public function test_supplier_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $supplier = Supplier::factory()->create([
            'supplier_code' => 'EXSUP-' . substr(uniqid(), -4),
            'supplier_name' => 'Exportable Supplier',
            'phone'         => '01900000000',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.suppliers.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Supplier Name', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXSUP-')) {
                $found = true;
                $this->assertSame('Exportable Supplier', $row[1]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded supplier {$supplier->supplier_code} not found in CSV output.");
    }

    public function test_employee_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $branch = Branch::factory()->create(['branch_name' => 'Emp-Export-Branch']);
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create([
            'employee_code' => 'EXEMP-' . substr(uniqid(), -4),
            'name'          => 'Exportable Employee',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.employees.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Name', $rows[0]);
        $this->assertContains('Role', $rows[0]);
        $this->assertContains('Branch Name', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXEMP-')) {
                $found = true;
                $this->assertSame('Exportable Employee', $row[1]);
                $this->assertSame('salesman', $row[2]);
                $this->assertSame('Emp-Export-Branch', $row[3]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded employee {$employee->employee_code} not found in CSV output.");
    }

    public function test_bank_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $bank = Bank::factory()->create([
            'bank_name'      => 'Exportable Bank',
            'account_number' => 'EXBANK-' . substr(uniqid(), -4),
            'branch_name'    => 'Dhanmondi Branch',
            'balance'        => 12345.67,
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.banks.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Bank Name', $rows[0]);
        $this->assertContains('Account Number', $rows[0]);
        $this->assertContains('Bank Branch Name', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[1], 'EXBANK-')) {
                $found = true;
                $this->assertSame('Exportable Bank', $row[0]);
                $this->assertSame('Dhanmondi Branch', $row[3]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded bank {$bank->account_number} not found in CSV output.");
    }

    public function test_user_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create([
            'name' => 'Linked Export Employee',
        ]);
        $user = User::factory()->forEmployee($employee->id)->create([
            'username' => 'expuser_' . substr(uniqid(), -4),
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.users.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Username', $rows[0]);
        $this->assertContains('Employee Name', $rows[0]);
        $this->assertContains('Role', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'expuser_')) {
                $found = true;
                $this->assertSame('Linked Export Employee', $row[1]);
                $this->assertSame('salesman', $row[2]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded user {$user->username} not found in CSV output.");
    }

    public function test_ledger_export_returns_csv(): void
    {
        $this->actingAsRole('admin');
        $ledger = Ledger::factory()->withNature('cash_bank')->create([
            'ledger_code'   => 'EXLED-' . substr(uniqid(), -4),
            'ledger_name'   => 'Exportable Ledger',
            'is_system'     => false,
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.ledgers.export')));
        $rows = $this->parseCsv($content);

        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Ledger Name', $rows[0]);
        $this->assertContains('Account Type', $rows[0]);
        $this->assertContains('Ledger Nature', $rows[0]);

        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'EXLED-')) {
                $found = true;
                $this->assertSame('Exportable Ledger', $row[1]);
                $this->assertSame('Asset', $row[2]);
                $this->assertSame('cash_bank', $row[3]);
                break;
            }
        }
        $this->assertTrue($found, "Seeded ledger {$ledger->ledger_code} not found in CSV output.");
    }

    // ====================================================================
    // AUTH + RBAC
    // ====================================================================

    public function test_export_requires_auth(): void
    {
        // No actingAs — should redirect to login.
        $this->get(route('admin.branches.export'))
            ->assertRedirect(route('login'));
    }

    public function test_export_requires_manager_role(): void
    {
        // Salesman has no access to branches (admin/manager/warehouse_manager).
        $this->actingAsRole('salesman')
            ->get(route('admin.branches.export'))
            ->assertRedirect(route('dashboard'));
    }

    // ====================================================================
    // CSV CONTENT-LEVEL ASSERTIONS
    // ====================================================================

    public function test_csv_contains_headers(): void
    {
        $this->actingAsRole('admin');
        Branch::factory()->create();

        $content = $this->assertCsvResponse($this->get(route('admin.branches.export')));
        $rows = $this->parseCsv($content);

        // The header row is the first row.
        $this->assertSame(
            ['Code', 'Branch Name', 'Address', 'Phone', 'Email', 'Active', 'Created At'],
            $rows[0],
        );
    }

    public function test_csv_contains_data_rows(): void
    {
        $this->actingAsRole('admin');
        Branch::factory()->create(['branch_name' => 'Unique Data Row Branch']);
        Branch::factory()->create(['branch_name' => 'Another Data Row Branch']);

        $content = $this->assertCsvResponse($this->get(route('admin.branches.export')));
        $rows = $this->parseCsv($content);

        // First row is header → at least 3 rows total (1 header + 2 data).
        $this->assertGreaterThanOrEqual(3, count($rows));

        // Both seeded branches must appear in the data rows.
        $dataRows = array_slice($rows, 1);
        $names = array_map(fn ($r) => $r[1] ?? '', $dataRows);
        $this->assertContains('Unique Data Row Branch', $names);
        $this->assertContains('Another Data Row Branch', $names);
    }

    public function test_csv_handles_special_characters(): void
    {
        $this->actingAsRole('admin');

        // Plant a branch with commas, double-quotes, and an embedded newline.
        // These all need to be properly escaped in the CSV (RFC 4180).
        Branch::factory()->create([
            'branch_code' => 'SP-' . substr(uniqid(), -4),
            'branch_name' => 'Comma, Quote " and Newline Branch',
            'address'     => "Line 1\nLine 2",
            'phone'       => '01700000000',
        ]);

        $content = $this->assertCsvResponse($this->get(route('admin.branches.export')));
        $rows = $this->parseCsv($content);

        // Header.
        $this->assertSame(
            ['Code', 'Branch Name', 'Address', 'Phone', 'Email', 'Active', 'Created At'],
            $rows[0],
        );

        // Find the special-character row.
        $found = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row[0], 'SP-')) {
                $found = true;
                $this->assertSame('Comma, Quote " and Newline Branch', $row[1]);
                $this->assertSame("Line 1\nLine 2", $row[2]);
                break;
            }
        }
        $this->assertTrue($found, 'Special-character branch row not found in CSV output.');
    }
}
