<?php

namespace App\Services\Sales;

use App\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.3 — Stock-reversal pre-check for Sales Return reversal.
 *
 * Before the service opens its DB::transaction to reverse a confirmed
 * return, this guard checks whether there is enough on-hand stock in each
 * warehouse to absorb the reversal (the reversal does stock OUT — the goods
 * that were restored at confirm time need to leave the warehouse again).
 *
 * Legacy equivalent: SalesReturnModel::getStockReversalBlockReason() +
 * buildStockReversalPreview(). Legacy shows the user a friendly
 * "Insufficient stock in {wh} for {product}: need X, have Y" message
 * BEFORE any write. Without this guard, Laravel throws a mid-transaction
 * RuntimeException with a less-helpful message + risks a partial transaction.
 *
 * Scope (per plan §1.3): only checks stock_transactions rows where
 *   reference_type = 'sales_return' AND reference_id = {return.id} AND
 *   is_reversed = false. These are the IN movements recorded at confirm
 *   time. Linked damage write-offs (reference_type='damage') are reversed
 *   separately by SalesReturnService::reverseLinkedDamageForReturn() and
 *   are NOT pre-checked here — that's an accepted limitation (damage goods
 *   were written off; reversing the write-off needs them back IN, which is
 *   a rarer edge case). Logged for a future phase.
 *
 * The guard is read-only — it never writes. Safe to call from a Form
 * Request's withValidator.
 */
class SalesReturnReversalGuard
{
    /**
     * Get a list of human-readable reasons why this return CANNOT be
     * reversed right now. Empty array = safe to reverse.
     *
     * @param int $returnId
     * @return list<string>  blocking reasons (empty = no blocks)
     */
    public function getBlockReasons(int $returnId): array
    {
        $return = SalesReturn::with('items')->find($returnId);
        if (!$return) {
            return ["Return {$returnId} not found."];
        }

        if (!$return->isConfirmed()) {
            return [sprintf(
                'Only confirmed returns can be reversed (current status: %s).',
                $return->status
            )];
        }

        // Each non-reversed sales_return stock movement is an IN (positive qty)
        // recorded at confirm time. Reversing it = stock OUT of the same qty.
        // The warehouse must have at least that much on hand right now.
        $stockTxs = DB::table('stock_transactions as st')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'st.warehouse_id')
                     ->on('ws.product_id', '=', 'st.product_id');
            })
            ->where('st.reference_type', 'sales_return')
            ->where('st.reference_id', $returnId)
            ->where('st.is_reversed', false)
            ->select([
                'st.warehouse_id',
                'st.product_id',
                'st.qty',
                'w.warehouse_name',
                'p.product_name',
                'p.product_code',
                DB::raw('COALESCE(ws.qty, 0) AS on_hand'),
            ])
            ->get();

        $reasons = [];
        foreach ($stockTxs as $tx) {
            $need = (float) $tx->qty;       // the IN qty that must come back OUT
            $have = (float) $tx->on_hand;
            if ($have + 0.0001 < $need) {
                $label = $tx->product_name
                    ?: ($tx->product_code ?: 'Product #' . $tx->product_id);
                $reasons[] = sprintf(
                    'Insufficient stock in %s for %s: need %s on hand, have %s. Adjust stock first or cancel the reversal.',
                    $tx->warehouse_name ?? 'Warehouse #' . $tx->warehouse_id,
                    $label,
                    number_format($need, 4),
                    number_format($have, 4)
                );
            }
        }

        return $reasons;
    }

    /**
     * Convenience: can this return be reversed right now?
     */
    public function canReverse(int $returnId): bool
    {
        return empty($this->getBlockReasons($returnId));
    }
}
