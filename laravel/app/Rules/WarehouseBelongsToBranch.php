<?php

namespace App\Rules;

use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Branch-ownership rule for warehouse assignments.
 *
 * Originally Phase 10 (A4) — for sales invoice warehouse validation.
 * Extended in Phase 1 to support direct branch_id parameter for
 * warehouse transfer same-branch enforcement.
 *
 * Two usage modes:
 *   1. Invoice context: new WarehouseBelongsToBranch($invoiceId)
 *      - Validates warehouse belongs to the invoice's branch
 *   2. Direct branch: new WarehouseBelongsToBranch($branchId)
 *      - Validates warehouse belongs to the specified branch
 *      - Used by WarehouseTransferController for same-branch enforcement
 *
 * A crafted POST submitting another branch's warehouse_id is rejected
 * with a 422 here — BEFORE the request reaches the controller body.
 * This is a defense-in-depth layer on top of:
 *   - the branch.isolation route middleware (RLS session scope),
 *   - the service-level same-branch check, and
 *   - the PostgreSQL trigger on warehouse_transfers.
 */
class WarehouseBelongsToBranch implements ValidationRule
{
    /**
     * @param int|null $contextId  Either an invoice_id (legacy) or a branch_id (Phase 1).
     * @param string   $mode       'invoice' for invoice context, 'branch' for direct branch_id.
     */
    public function __construct(
        public ?int $contextId = null,
        public string $mode = 'invoice',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $warehouseId = (int) $value;
        if ($warehouseId <= 0) {
            return; // the required|integer|exists rules cover this
        }

        $warehouse = Warehouse::select('id', 'branch_id', 'is_active')->find($warehouseId);
        if (!$warehouse) {
            $fail('The selected warehouse does not exist.');
            return;
        }

        if (!$warehouse->is_active) {
            $fail('The selected warehouse is not active.');
            return;
        }

        // Resolve the expected branch_id based on mode
        $expectedBranchId = null;

        if ($this->mode === 'branch') {
            // Phase 1: Direct branch_id comparison for warehouse transfers
            $expectedBranchId = $this->contextId;
        } else {
            // Legacy: Invoice context — resolve branch from the invoice
            if (!$this->contextId || $this->contextId <= 0) {
                return; // no context — skip (other rules still run)
            }

            $invoice = SalesInvoice::select('id', 'branch_id')->find($this->contextId);
            if (!$invoice) {
                return; // controller's findOrFail will 404
            }
            $expectedBranchId = (int) $invoice->branch_id;
        }

        // If no expected branch resolved, skip (admin mode — no restriction)
        if ($expectedBranchId === null || $expectedBranchId <= 0) {
            return;
        }

        if ((int) $warehouse->branch_id !== (int) $expectedBranchId) {
            $fail('The selected warehouse does not belong to your branch. Cross-branch transfers must go through Branch Demand.');
        }
    }
}
