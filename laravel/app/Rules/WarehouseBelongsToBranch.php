<?php

namespace App\Rules;

use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 10 (A4) — branch-ownership rule for warehouse assignments.
 *
 * Passes if the warehouse exists, is active, and belongs to the SAME
 * branch as the invoice identified by the `{invoiceId}` route param.
 *
 * A crafted POST submitting another branch's warehouse_id is rejected
 * with a 422 here — BEFORE the request reaches the controller body.
 * This is a defense-in-depth layer on top of:
 *   - the branch.isolation route middleware (RLS session scope), and
 *   - SalesChallanService::prepareGodown's own branch lookup.
 *
 * The rule is parameterised by the invoiceId route param, resolved via
 * $this->route('invoiceId') inside the Form Request's withValidator.
 */
class WarehouseBelongsToBranch implements ValidationRule
{
    public function __construct(
        public ?int $invoiceId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $warehouseId = (int) $value;
        if ($warehouseId <= 0) {
            return; // the required|integer|exists rules cover this
        }

        if (!$this->invoiceId || $this->invoiceId <= 0) {
            return; // no invoice context — skip (other rules still run)
        }

        $invoice = SalesInvoice::select('id', 'branch_id')->find($this->invoiceId);
        if (!$invoice) {
            return; // controller's findOrFail will 404
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

        if ((int) $warehouse->branch_id !== (int) $invoice->branch_id) {
            $fail('The selected warehouse does not belong to this invoice\'s branch.');
        }
    }
}
