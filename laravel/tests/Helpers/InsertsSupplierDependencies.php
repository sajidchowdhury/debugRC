<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Supplier Phase 11 test helpers — direct table inserts for supplier-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy (or that would force pulling in the entire Purchase module).
 *
 * Used by:
 *  - tests/Unit/Supplier/SupplierDeactivationUnitTest
 *  - tests/Feature/Supplier/SupplierCrudTest
 *  - tests/Feature/Supplier/SupplierAuditTest
 *  - tests/Feature/Supplier/SupplierValidationTest
 *
 * NOTE: Tests\Helpers\InsertsBranchDependencies already has an insertSupplier()
 * method with a different signature. Supplier test classes intentionally use
 * ONLY this trait (not InsertsBranchDependencies) to avoid trait-method
 * collision. Branches are obtained via `Branch::factory()->create()` instead.
 */
trait InsertsSupplierDependencies
{
    use ResolvesActiveFiscalYear;
    /**
     * Insert a supplier row with the minimum required columns.
     * Returns the supplier id.
     *
     * @param  int|null  $branchId  FK to branches.id (nullable in schema but tests usually pass one)
     * @param  array     $overrides Column overrides merged on top of the defaults.
     */
    protected function insertSupplier(?int $branchId = null, array $overrides = []): int
    {
        $code = $overrides['supplier_code'] ?? 'SUP-DEP-' . substr(uniqid(), -6);

        return DB::table('suppliers')->insertGetId(array_merge([
            'supplier_code' => $code,
            'supplier_name' => 'Dep Supplier ' . $code,
            'branch_id'     => $branchId,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));
    }

    /**
     * Insert a supplier_ledger row simulating an AP transaction.
     *
     * Schema: supplier_id (FK NOT NULL), transaction_date (NOT NULL),
     *         transaction_type (NOT NULL), debit/credit (default 0).
     *
     * Pass $type='credit' to record an AP increase (e.g. GRN posted — we owe supplier).
     * Pass $type='debit'  to record an AP decrease (e.g. payment to supplier).
     *
     * @param  string  $type  'debit' or 'credit'
     * @return int  The supplier_ledger.id
     */
    protected function insertSupplierLedger(int $supplierId, float $amount, string $type = 'credit'): int
    {
        $row = [
            'supplier_id'      => $supplierId,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => $type === 'debit' ? 'payment' : 'invoice',
            'reference_type'   => $type === 'debit' ? 'payment' : 'invoice',
            'reference_id'     => 0,
            'description'      => 'Phase 11 test ledger entry',
            'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
            'created_at'       => now(),
        ];

        if ($type === 'debit') {
            $row['debit'] = $amount;
        } else {
            $row['credit'] = $amount;
        }

        return DB::table('supplier_ledger')->insertGetId($row);
    }

    /**
     * Insert a purchase_order row referencing a supplier.
     *
     * Builds the minimum chain: supplier → PO.
     * Schema requires po_code (UK), po_date, supplier_id (FK), branch_id (FK),
     * status CHECK in (draft/sent/partial/received/cancelled).
     *
     * @param  string  $status  One of draft/sent/partial/received/cancelled.
     * @return int  The purchase_orders.id
     */
    protected function insertPurchaseOrderForSupplier(
        int $supplierId,
        int $branchId,
        string $status = 'draft',
    ): int {
        return DB::table('purchase_orders')->insertGetId([
            'po_code'     => 'PO-SUP-' . substr(uniqid(), -6),
            'po_date'     => now()->toDateString(),
            'supplier_id' => $supplierId,
            'branch_id'   => $branchId,
            'status'      => $status,
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Insert a purchase_receive (GRN) row referencing a supplier.
     *
     * Schema requires receive_code (UK), receive_date, supplier_id (FK NOT NULL),
     * branch_id (FK NOT NULL), warehouse_id (FK NOT NULL), is_reversed bool.
     *
     * Useful for testing deactivation safety against open GRNs (although
     * canDeactivate currently checks ledger balance + PO status, this helper
     * exists for completeness so future tests can exercise it).
     *
     * @param  bool    $isReversed Whether the receive is reversed.
     * @return int  The purchase_receives.id
     */
    protected function insertPurchaseReceiveForSupplier(
        int $supplierId,
        int $branchId,
        bool $isReversed = false,
        ?int $warehouseId = null,
    ): int {
        return DB::table('purchase_receives')->insertGetId([
            'receive_code' => 'GRN-SUP-' . substr(uniqid(), -6),
            'receive_date' => now()->toDateString(),
            'supplier_id'  => $supplierId,
            'branch_id'    => $branchId,
            'warehouse_id' => $warehouseId ?? 1,
            'is_reversed'  => $isReversed,
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
