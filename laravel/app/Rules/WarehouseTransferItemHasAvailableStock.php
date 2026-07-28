<?php

namespace App\Rules;

use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 2 — Pipeline-aware stock availability rule for warehouse transfer items.
 *
 * Validates that the requested transfer qty does not exceed the pipeline-aware
 * available qty at the source warehouse. The StockAvailabilityService subtracts
 * the sales pipeline (open invoice dispatches not yet challan-completed) from
 * physical qty, preventing over-commitment when transfers + sales compete for
 * the same physical stock.
 *
 * Usage in controller validation:
 *   'items.*.qty' => [
 *       'required', 'numeric', 'min:0.001',
 *       new WarehouseTransferItemHasAvailableStock($request->from_warehouse_id),
 *   ]
 *
 * The attribute path 'items.{idx}.qty' is used to find the corresponding
 * product_id from 'items.{idx}.product_id' in the request data.
 *
 * Unlike WarehouseHasStock (which is tied to SalesInvoice items), this rule
 * is specifically designed for the warehouse transfer context where items
 * are submitted as an array with product_id + qty pairs.
 */
class WarehouseTransferItemHasAvailableStock implements ValidationRule
{
    public function __construct(
        public ?int $fromWarehouseId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $qty = (float) $value;
        if ($qty <= 0) {
            return; // min:0.001 rule covers this
        }

        if (!$this->fromWarehouseId || $this->fromWarehouseId <= 0) {
            return; // No warehouse to check against — let required/exist rules handle this
        }

        // Extract the row index from the attribute path: items.{idx}.qty → idx
        $parts = explode('.', $attribute);
        $idx = (int) (isset($parts[1]) ? $parts[1] : 0);

        // Find the product_id for this row from request data
        $productId = (int) request()->input("items.{$idx}.product_id", 0);
        if ($productId <= 0) {
            return; // required|exists rule covers this; skip if product not yet set
        }

        // Resolve the service lazily from the container
        $availability = app(StockAvailabilityService::class);
        $stockService = app(StockService::class);

        $availableQty = (float) $availability->getWarehouseAvailableQty(
            $productId, $this->fromWarehouseId
        );

        if ($qty > $availableQty + 0.0001) {
            $physicalQty = (float) $stockService->getWarehouseQty($this->fromWarehouseId, $productId);
            $pipelineQty = max(0.0, $physicalQty - $availableQty);

            $fail(
                'Insufficient available stock for this product: '
                . 'available ' . number_format($availableQty, 2)
                . ' (physical ' . number_format($physicalQty, 2)
                . ', pipeline ' . number_format($pipelineQty, 2) . ')'
                . ' but requested ' . number_format($qty, 2) . '.'
            );
        }
    }
}
