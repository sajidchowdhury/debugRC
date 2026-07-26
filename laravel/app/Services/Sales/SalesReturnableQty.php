<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Phase 1.1 — Shared returnable-qty calculator for Sales Returns.
 *
 * "Returnable qty" = (original invoice-item qty) − (qty already tied up in
 * non-reversed returns, regardless of whether those returns are still
 * 'created' or already 'confirmed').
 *
 * This logic previously lived inline in TWO places:
 *   1. SalesReturnController::getInvoiceDetails()  — AJAX pre-fill
 *   2. SalesReturnService::validateItems()          — runtime defense
 *
 * Phase 1 extracts it so the new StoreSalesReturnRequest::withValidator
 * can enforce the cap at the REQUEST layer (422 friendly error) BEFORE
 * the service runs — defense-in-depth matching the Purchase Return pattern.
 *
 * The service's inline copy is intentionally LEFT IN PLACE as a second
 * line of defense (e.g. for the mobile API which may not route through
 * the web Form Request). Refactoring the service to call this helper is
 * a Phase 5/6 cleanup task — out of scope for Phase 1.
 */
class SalesReturnableQty
{
    /**
     * Get the maximum qty that can still be returned for a single invoice
     * item across all non-reversed returns.
     *
     * @param int $invoiceItemId  sales_invoice_items.id
     * @return float              >= 0 (never negative)
     */
    public function getMaxReturnableQty(int $invoiceItemId): float
    {
        if ($invoiceItemId <= 0) {
            return 0.0;
        }

        $invoiceItem = DB::table('sales_invoice_items')
            ->where('id', $invoiceItemId)
            ->select('qty')
            ->first();

        if (!$invoiceItem) {
            return 0.0; // the exists:sales_invoice_items rule covers the 422
        }

        $alreadyReturned = (float) DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
            ->where('sri.sales_invoice_item_id', $invoiceItemId)
            ->whereIn('sr.status', ['created', 'confirmed'])
            ->where('sr.is_reversed', false)
            ->sum('sri.qty');

        return max(0.0, (float) $invoiceItem->qty - $alreadyReturned);
    }

    /**
     * Batch version — fetch returnable qty for many invoice-item IDs in a
     * single grouped query. Used by the AJAX invoice-details endpoint to
     * avoid N+1 when an invoice has many lines.
     *
     * @param array<int,int> $invoiceItemIds  keyed by anything; values are IDs
     * @return array<int,float>               keyed by invoice-item-id → returnable qty
     */
    public function getReturnableQtyMap(array $invoiceItemIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $invoiceItemIds)));
        if (empty($ids)) {
            return [];
        }

        // Original qty per invoice item.
        $items = DB::table('sales_invoice_items')
            ->whereIn('id', $ids)
            ->select('id', 'qty')
            ->get()
            ->keyBy('id');

        // Already-returned qty, grouped by invoice item id.
        $alreadyReturnedRows = DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
            ->whereIn('sri.sales_invoice_item_id', $ids)
            ->whereIn('sr.status', ['created', 'confirmed'])
            ->where('sr.is_reversed', false)
            ->selectRaw('sri.sales_invoice_item_id, SUM(sri.qty) AS already_returned')
            ->groupBy('sri.sales_invoice_item_id')
            ->get()
            ->keyBy('sales_invoice_item_id');

        $map = [];
        foreach ($ids as $id) {
            $original = isset($items[$id]) ? (float) $items[$id]->qty : 0.0;
            $already = isset($alreadyReturnedRows[$id]) ? (float) $alreadyReturnedRows[$id]->already_returned : 0.0;
            $map[$id] = max(0.0, $original - $already);
        }
        return $map;
    }
}
