<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Branch Phase 7/8 test helpers — direct table inserts.
 *
 * These helpers exist because the legacy `sales_invoices`, `branch_demands`,
 * and `warehouses` tables have many NOT NULL columns and foreign keys that
 * factories can't easily satisfy without pulling in the full Sales/Customer
 * module. Using DB::table()->insert() with the minimum required columns is
 * faster and more focused than building factory chains.
 */
trait InsertsBranchDependencies
{
    use ResolvesActiveFiscalYear;

    /**
     * Insert a customer row with the minimum required columns.
     * Returns the customer id.
     */
    protected function insertCustomer(int $branchId, string $code = null): int
    {
        $code = $code ?? 'CUST-' . uniqid();

        return DB::table('customers')->insertGetId([
            'customer_code' => $code,
            'customer_name' => 'Test Customer ' . $code,
            'branch_id'     => $branchId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Insert a sales invoice row with the minimum required columns.
     * Returns the invoice id.
     *
     * Schema: invoice_code (UK), invoice_date, customer_id (FK), branch_id (FK),
     *        status CHECK IN (draft,confirmed,cancelled,reversed), is_reversed bool,
     *        fiscal_year_id NOT NULL (added in S1 FY-isolation; see config/fiscal.php).
     *
     * Session 1 (FY isolation) added a NOT NULL `fiscal_year_id` column
     * to `sales_invoices`. If `$fiscalYearId` is not provided, the helper
     * auto-resolves to the current running FY (mirroring insertBranchDemand).
     */
    protected function insertSalesInvoice(
        int $branchId,
        string $status = 'confirmed',
        bool $isReversed = false,
        ?string $invoiceCode = null,
        ?int $fiscalYearId = null,
    ): int {
        // Ensure a customer exists for this branch (FK constraint).
        $customerId = $this->insertCustomer($branchId);

        $fiscalYearId ??= $this->resolveActiveFiscalYearId();

        return DB::table('sales_invoices')->insertGetId([
            'invoice_code'   => $invoiceCode ?? 'INV-' . uniqid(),
            'invoice_date'   => now()->toDateString(),
            'customer_id'    => $customerId,
            'branch_id'      => $branchId,
            'status'         => $status,
            'is_reversed'    => $isReversed,
            'fiscal_year_id' => $fiscalYearId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * Insert a branch demand row with the minimum required columns.
     * Returns the demand id.
     *
     * Session 1 (FY isolation) added a NOT NULL `fiscal_year_id` column
     * to `branch_demands`. If `$fiscalYearId` is not provided, the
     * helper auto-resolves to the current running FY (is_current=true
     * AND status=active), creating one if none exists yet. Callers
     * that need to attach the demand to a specific FY (e.g. a closed
     * FY for the closed-FY 403 test) can pass `$fiscalYearId` directly
     * or override it after insert.
     */
    protected function insertBranchDemand(
        int $fromBranchId,
        int $toBranchId,
        string $status = 'pending',
        ?string $demandCode = null,
        ?int $fiscalYearId = null,
    ): int {
        $fiscalYearId ??= $this->resolveActiveFiscalYearId();

        return DB::table('branch_demands')->insertGetId([
            'demand_code'     => $demandCode ?? 'BD-' . uniqid(),
            'demand_date'     => now()->toDateString(),
            'from_branch_id'  => $fromBranchId,
            'to_branch_id'    => $toBranchId,
            'status'          => $status,
            'is_reversed'     => false,
            'fiscal_year_id'  => $fiscalYearId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Insert a warehouse row with the minimum required columns.
     * Returns the warehouse id.
     */
    protected function insertWarehouse(
        int $branchId,
        bool $isActive = true,
        ?string $code = null,
    ): int {
        return DB::table('warehouses')->insertGetId([
            'warehouse_code' => $code ?? 'WH-' . uniqid(),
            'warehouse_name' => 'Test Warehouse ' . ($code ?? uniqid()),
            'branch_id'      => $branchId,
            'is_active'      => $isActive,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }
}
