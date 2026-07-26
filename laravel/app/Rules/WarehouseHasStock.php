<?php

namespace App\Rules;

use App\Models\SalesInvoice;
use App\Services\Stock\StockAvailabilityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 10 (A4) — pipeline-aware stock-availability rule.
 *
 * Passes if StockAvailabilityService::getWarehouseAvailableQty(pid, wid,
 * excludeInvoiceId) >= the line's demand qty. The pipeline-aware
 * availability subtracts the open sales dispatch pipeline (from OTHER
 * invoices) so a crafty attacker cannot over-assign stock that is
 * already committed elsewhere.
 *
 * The demand qty is resolved from sales_invoice_items.qty for the item
 * identified by the array key (warehouse_assignments[{itemId}]).
 *
 * The excludeInvoiceId is the current invoice's id so the invoice
 * being edited does not reserve against its own open dispatch rows
 * (mirrors StockAvailabilityService usage in the godown GET).
 */
class WarehouseHasStock implements ValidationRule
{
    public function __construct(
        public ?int $invoiceId = null,
        private readonly ?StockAvailabilityService $availability = null,
    ) {
        // resolve at validate-time so the rule is DI-constructible
        $this->availability ??= app(StockAvailabilityService::class);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $warehouseId = (int) $value;
        if ($warehouseId <= 0) {
            return; // required + WarehouseBelongsToBranch cover this
        }

        if (!$this->invoiceId || $this->invoiceId <= 0) {
            return;
        }

        $invoice = SalesInvoice::with(['items:id,sales_invoice_id,product_id,qty'])
            ->select('id')
            ->find($this->invoiceId);
        if (!$invoice) {
            return;
        }

        // The attribute is warehouse_assignments.{itemId} — extract itemId.
        $parts = explode('.', $attribute);
        $itemId = (int) (end($parts) ?: 0);
        if ($itemId <= 0) {
            return;
        }

        $item = $invoice->items->firstWhere('id', $itemId);
        if (!$item) {
            $fail('The invoice item for this warehouse assignment was not found.');
            return;
        }

        $demand = (float) $item->qty;
        $available = (float) $this->availability->getWarehouseAvailableQty(
            (int) $item->product_id,
            $warehouseId,
            (int) $this->invoiceId
        );

        if ($available < $demand) {
            $fail(
                'Insufficient stock in this warehouse: available '
                . number_format($available, 2)
                . ' but this line demands '
                . number_format($demand, 2) . '.'
            );
        }
    }
}
