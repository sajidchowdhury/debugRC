<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Models\User;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — CSV Export feature tests for WarehouseTransfer.
 *
 * Covers:
 *   1. CSV export returns valid CSV with BOM prefix and correct headers.
 *   2. CSV export respects date filters (from_date, to_date).
 *   3. CSV export respects status filter (only confirmed transfers).
 *   4. CSV export enforces branch isolation for non-admin users.
 *
 * The export endpoint is admin.warehouse-transfers.export which streams
 * a CSV via Response::stream() with UTF-8 BOM prefix. The WarehouseTransfer
 * model has a global scope (WarehouseTransferBranchScope) that filters by
 * branch for non-admin users.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and
 * rolls back on tearDown, leaving the rcerp_test DB pristine.
 */
class ExportTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $transferService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->transferService = app(WarehouseTransferService::class);
    }

    /**
     * Build the standard createTransfer payload. Caller can override any key.
     */
    private function basePayload(
        int $fromWarehouseId,
        int $toWarehouseId,
        array $items,
        array $overrides = [],
    ): array {
        return array_merge([
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Phase 7 export test',
            'created_by'        => auth()->id(),
            'items'             => $items,
        ], $overrides);
    }

    /**
     * Assert the response looks like a CSV download: 200, text/csv,
     * starts with UTF-8 BOM. Returns the raw content for further parsing.
     */
    private function assertCsvResponse($response): string
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        // UTF-8 BOM should be present (first 3 bytes).
        $this->assertSame(
            chr(0xEF) . chr(0xBB) . chr(0xBF),
            substr($content, 0, 3),
            'CSV is missing UTF-8 BOM.'
        );

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
        if (str_starts_with($content, chr(0xEF) . chr(0xBB) . chr(0xBF))) {
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

    /**
     * Helper: set up a complete valid same-branch transfer scenario.
     * Creates branch, 2 warehouses, product, stock, and returns
     * the created draft transfer with all IDs.
     *
     * @return array{branch: Branch, fromWhId: int, toWhId: int, productId: int, transfer: \App\Models\WarehouseTransfer}
     */
    private function setupValidTransferScenario(array $overrides = []): array
    {
        $branch = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Physical stock at source = 200 (enough for any test qty).
        $this->insertWarehouseStock($fromWhId, $productId, 200);

        $payloadOverrides = array_merge([
            'items' => [['product_id' => $productId, 'qty' => 10, 'rate' => 10]],
        ], $overrides);

        $transfer = $this->transferService->createTransfer(
            $this->basePayload($fromWhId, $toWhId, $payloadOverrides['items'], $overrides)
        );

        return compact('branch', 'fromWhId', 'toWhId', 'productId', 'transfer');
    }

    // ====================================================================
    // Test scenarios
    // ====================================================================

    /**
     * SCENARIO 1: CSV export returns valid CSV with BOM prefix and
     * correct headers.
     */
    public function test_csv_export_returns_valid_csv_with_bom(): void
    {
        $scenario = $this->setupValidTransferScenario();

        $response = $this->get(route('admin.warehouse-transfers.export'));
        $content = $this->assertCsvResponse($response);
        $rows = $this->parseCsv($content);

        // Header row should contain the expected columns.
        $this->assertContains('Date', $rows[0]);
        $this->assertContains('Code', $rows[0]);
        $this->assertContains('From WH', $rows[0]);
        $this->assertContains('To WH', $rows[0]);
        $this->assertContains('Branch', $rows[0]);
        $this->assertContains('Status', $rows[0]);

        // At least one data row (the transfer we created).
        $this->assertGreaterThanOrEqual(2, count($rows), 'Expected header + at least 1 data row');

        // Find the transfer code in the data rows.
        $found = false;
        foreach (array_slice($rows, 1) as $row) {
            if ($row[1] === $scenario['transfer']->transfer_code) {
                $found = true;
                $this->assertSame('draft', $row[9]); // Status column
                break;
            }
        }
        $this->assertTrue($found, 'Transfer code not found in CSV export');
    }

    /**
     * SCENARIO 2: CSV export respects date filters.
     *
     * Create transfers with different dates, export with a date filter
     * → only transfers within the date range appear.
     */
    public function test_csv_export_respects_date_filters(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        $this->insertWarehouseStock($fromWhId, $productId, 200);

        // Create a transfer dated 5 days ago.
        $oldTransfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 5, 'rate' => 10]],
            ['transfer_date' => now()->subDays(5)->format('Y-m-d')],
        ));

        // Create a transfer dated today.
        $todayTransfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 10, 'rate' => 10]],
        ));

        // Export with from_date = yesterday → only today's transfer should appear.
        $response = $this->get(route('admin.warehouse-transfers.export', [
            'from_date' => now()->subDay()->format('Y-m-d'),
        ]));
        $content = $this->assertCsvResponse($response);
        $rows = $this->parseCsv($content);

        $codesInExport = [];
        foreach (array_slice($rows, 1) as $row) {
            $codesInExport[] = $row[1]; // Code column
        }

        // Today's transfer should be present.
        $this->assertContains($todayTransfer->transfer_code, $codesInExport,
            'Today transfer should appear when from_date=yesterday');

        // Old transfer (5 days ago) should NOT be present.
        $this->assertNotContains($oldTransfer->transfer_code, $codesInExport,
            'Old transfer should NOT appear when from_date=yesterday');
    }

    /**
     * SCENARIO 3: CSV export respects status filter.
     *
     * Create draft and confirmed transfers, filter by status=confirmed
     * → only confirmed transfers appear in the CSV.
     */
    public function test_csv_export_respects_status_filter(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        $this->insertWarehouseStock($fromWhId, $productId, 200);

        // Create a draft transfer.
        $draftTransfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 5, 'rate' => 10]],
        ));

        // Create and confirm a second transfer.
        $confirmedTransfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 10, 'rate' => 10]],
        ));
        $this->transferService->confirmTransfer($confirmedTransfer->id, auth()->id());

        // Export with status=confirmed.
        $response = $this->get(route('admin.warehouse-transfers.export', [
            'status' => 'confirmed',
        ]));
        $content = $this->assertCsvResponse($response);
        $rows = $this->parseCsv($content);

        $codesInExport = [];
        foreach (array_slice($rows, 1) as $row) {
            $codesInExport[] = $row[1]; // Code column
        }

        // Confirmed transfer should be present.
        $this->assertContains($confirmedTransfer->transfer_code, $codesInExport,
            'Confirmed transfer should appear when status=confirmed');

        // Draft transfer should NOT be present.
        $this->assertNotContains($draftTransfer->transfer_code, $codesInExport,
            'Draft transfer should NOT appear when status=confirmed');
    }

    /**
     * SCENARIO 4: CSV export enforces branch isolation for non-admin users.
     *
     * Create transfers in two different branches. A non-admin user from
     * branch A should only see branch A transfers in the export.
     */
    public function test_csv_export_with_branch_isolation(): void
    {
        // Branch A — where our non-admin user belongs.
        $branchA = Branch::factory()->create();
        $fromWhA = $this->insertWarehouse($branchA->id);
        $toWhA   = $this->insertWarehouse($branchA->id);
        $productA = $this->insertProduct();
        $this->insertWarehouseStock($fromWhA, $productA, 200);

        // Branch B — a different branch.
        $branchB = Branch::factory()->create();
        $fromWhB = $this->insertWarehouse($branchB->id);
        $toWhB   = $this->insertWarehouse($branchB->id);
        $productB = $this->insertProduct();
        $this->insertWarehouseStock($fromWhB, $productB, 200);

        // Create transfers in both branches (as admin, bypassing branch scope).
        $transferA = $this->transferService->createTransfer($this->basePayload(
            $fromWhA, $toWhA,
            [['product_id' => $productA, 'qty' => 10, 'rate' => 10]],
        ));

        $transferB = $this->transferService->createTransfer($this->basePayload(
            $fromWhB, $toWhB,
            [['product_id' => $productB, 'qty' => 10, 'rate' => 10]],
        ));

        // Create a warehouse_manager user from branch A.
        $userA = $this->makeRoleUser('warehouse_manager', [], [], $branchA);

        // Set session branch_id for the non-admin user (required by global scope).
        $response = $this->actingAs($userA)
            ->withSession(['branch_id' => $branchA->id, 'credential_version' => '1'])
            ->get(route('admin.warehouse-transfers.export'));

        $content = $this->assertCsvResponse($response);
        $rows = $this->parseCsv($content);

        $codesInExport = [];
        foreach (array_slice($rows, 1) as $row) {
            $codesInExport[] = $row[1]; // Code column
        }

        // Branch A transfer should be visible.
        $this->assertContains($transferA->transfer_code, $codesInExport,
            'Branch A transfer should be visible to branch A user');

        // Branch B transfer should NOT be visible.
        $this->assertNotContains($transferB->transfer_code, $codesInExport,
            'Branch B transfer should NOT be visible to branch A user');
    }
}
