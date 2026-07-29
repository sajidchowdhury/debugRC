<?php

namespace App\Services\Sales;

use App\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.3 + Phase 6.1 — Stock-reversal pre-check for Sales Return reversal.
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
 * Three public entry points (Phase 6.1):
 *   - getBlockReasons($id): array  — structured tuples per the plan
 *     `[{warehouse_name, product_name, product_code, needed, available, shortfall}, …]`
 *     for each stock_movement that can't be reversed. Empty = no stock blocks.
 *   - getBlockMessages($id): array — formatted human-readable strings (wraps
 *     getBlockReasons + prepends any status block). Used by the Form Request
 *     to fail fast with 422 AND by the AJAX reverse-preview endpoint.
 *   - getPreview($id): array — a snapshot of what will be reversed (stock
 *     movements with current on-hand for context, customer ledger, both GL
 *     journals, linked damage invoices). Powers the reverse-preview modal.
 *   - canReverse($id): bool — convenience for the service defense-in-depth.
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
     * Structured block tuples: one per stock_movement that can't be reversed
     * due to insufficient current warehouse_stock. Each tuple:
     *   {warehouse_id, warehouse_name, product_id, product_name, product_code,
     *    needed, available, shortfall}
     * Empty array = no stock blocks (safe to reverse, stock-wise).
     *
     * NOTE: returns ONLY stock-shortage blocks. Status/not-found blocks are
     * reported separately via getStatusBlock() / getBlockMessages() so the
     * tuple shape stays uniform (per plan 6.1).
     *
     * @param int $returnId
     * @return list<array{warehouse_id:int, warehouse_name:string, product_id:int, product_name:string, product_code:string, needed:float, available:float, shortfall:float}>
     */
    public function getBlockReasons(int $returnId): array
    {
        $return = SalesReturn::find($returnId);
        if (!$return || !$return->isConfirmed()) {
            // Status block is handled by getStatusBlock(); no stock tuples to report.
            return [];
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

        $blocks = [];
        foreach ($stockTxs as $tx) {
            $need  = (float) $tx->qty;       // the IN qty that must come back OUT
            $have  = (float) $tx->on_hand;
            $short = $need - $have;
            if ($short > 0.0001) {
                $blocks[] = [
                    'warehouse_id'   => (int) $tx->warehouse_id,
                    'warehouse_name' => $tx->warehouse_name ?? ('Warehouse #' . $tx->warehouse_id),
                    'product_id'     => (int) $tx->product_id,
                    'product_name'   => $tx->product_name
                        ?: ($tx->product_code ?: 'Product #' . $tx->product_id),
                    'product_code'   => $tx->product_code ?? '',
                    'needed'         => $need,
                    'available'      => $have,
                    'shortfall'      => $short,
                ];
            }
        }

        return $blocks;
    }

    /**
     * Status-level blocker (not-found / not-confirmed). Null = no status block.
     */
    public function getStatusBlock(int $returnId): ?string
    {
        $return = SalesReturn::find($returnId);
        if (!$return) {
            return "Return {$returnId} not found.";
        }
        if (!$return->isConfirmed()) {
            return sprintf(
                'Only confirmed returns can be reversed (current status: %s).',
                $return->status
            );
        }
        return null;
    }

    /**
     * Formatted human-readable blocking messages (status + stock shortages).
     * Used by ReverseSalesReturnRequest::withValidator() to fail fast with
     * 422, and by the reverse-preview AJAX endpoint for the Swal body.
     *
     * @return list<string>
     */
    public function getBlockMessages(int $returnId): array
    {
        $messages = [];

        $status = $this->getStatusBlock($returnId);
        if ($status !== null) {
            $messages[] = $status;
        }

        foreach ($this->getBlockReasons($returnId) as $b) {
            $messages[] = sprintf(
                'Insufficient stock in %s for %s: need %s on hand, have %s. Adjust stock first or cancel the reversal.',
                $b['warehouse_name'],
                $b['product_name'],
                number_format($b['needed'], 4),
                number_format($b['available'], 4)
            );
        }

        return $messages;
    }

    /**
     * Preview of what will be reversed if the reversal proceeds. Returns:
     *   {
     *     return: {id, return_code, status, total_amount, cogs_amount, is_reversed},
     *     stock_movements: [{id, product_name, product_code, warehouse_name,
     *                        qty (IN, positive), on_hand, will_be_short}],
     *     customer_ledger: [{id, transaction_date, debit, credit, is_reversed}],
     *     gl_journals: [{type:'revenue'|'cogs', entry_no, entry_date, is_reversed}],
     *     linked_damage_invoices: [{id, damage_code, warehouse_name, damage_date,
     *                               total_value, is_reversed, items_count}]
     *   }
     *
     * Powers the reverse-preview modal on the show page (Phase 6.2).
     *
     * @return array{
     *   return: array,
     *   stock_movements: list<array>,
     *   customer_ledger: list<array>,
     *   gl_journals: list<array>,
     *   linked_damage_invoices: list<array>
     * }
     */
    public function getPreview(int $returnId): array
    {
        $return = SalesReturn::with([
            'items.damageInvoice.warehouse',
            'journalEntry', 'cogsJournalEntry',
        ])->find($returnId);

        if (!$return) {
            return [
                'return' => ['id' => $returnId, 'not_found' => true],
                'stock_movements' => [],
                'customer_ledger' => [],
                'gl_journals' => [],
                'linked_damage_invoices' => [],
            ];
        }

        // Stock movements that will be reversed (the IN movements from confirm).
        $stockMovements = DB::table('stock_transactions as st')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'st.warehouse_id')
                     ->on('ws.product_id', '=', 'st.product_id');
            })
            ->where('st.reference_type', 'sales_return')
            ->where('st.reference_id', $returnId)
            ->where('st.is_reversed', false)
            ->orderBy('st.id')
            ->select([
                'st.id', 'st.warehouse_id', 'st.product_id', 'st.qty',
                'st.rate', 'st.total_value', 'st.transaction_date',
                'w.warehouse_name', 'p.product_name', 'p.product_code',
                DB::raw('COALESCE(ws.qty, 0) AS on_hand'),
            ])
            ->get()
            ->map(fn ($tx) => [
                'id'             => (int) $tx->id,
                'product_name'   => $tx->product_name ?? ('Product #' . $tx->product_id),
                'product_code'   => $tx->product_code ?? '',
                'warehouse_name' => $tx->warehouse_name ?? ('Warehouse #' . $tx->warehouse_id),
                'qty'            => (float) $tx->qty,
                'rate'           => (float) $tx->rate,
                'total_value'    => (float) $tx->total_value,
                'on_hand'        => (float) $tx->on_hand,
                'will_be_short'  => ((float) $tx->qty - (float) $tx->on_hand) > 0.0001,
            ])
            ->all();

        // Customer ledger entries that will be reversed.
        $customerLedger = DB::table('customer_ledger')
            ->where('reference_type', 'sales_return')
            ->where('reference_id', $returnId)
            ->where('is_reversed', false)
            ->orderBy('id')
            ->get(['id', 'transaction_date', 'debit', 'credit', 'is_reversed'])
            ->map(fn ($cl) => [
                'id'               => (int) $cl->id,
                'transaction_date' => $cl->transaction_date,
                'debit'            => (float) $cl->debit,
                'credit'           => (float) $cl->credit,
                'is_reversed'      => (bool) $cl->is_reversed,
            ])
            ->all();

        // Both GL journals (revenue + COGS) that will be reversed.
        $glJournals = [];
        if ($return->journalEntry) {
            $glJournals[] = [
                'type'        => 'revenue',
                'entry_no'    => $return->journalEntry->entry_no,
                'entry_date'  => $return->journalEntry->entry_date,
                'description' => $return->journalEntry->description,
                'is_reversed' => (bool) $return->journalEntry->is_reversed,
            ];
        }
        if ($return->cogsJournalEntry) {
            $glJournals[] = [
                'type'        => 'cogs',
                'entry_no'    => $return->cogsJournalEntry->entry_no,
                'entry_date'  => $return->cogsJournalEntry->entry_date,
                'description' => $return->cogsJournalEntry->description,
                'is_reversed' => (bool) $return->cogsJournalEntry->is_reversed,
            ];
        }

        // Linked damage invoices (Damage-condition write-offs) that will be reversed first.
        $linkedDamageInvoices = $return->items
            ->filter(fn ($i) => $i->isDamage() && $i->damageInvoice)
            ->map(fn ($i) => $i->damageInvoice)
            ->unique('id')
            ->values()
            ->map(fn ($di) => [
                'id'             => (int) $di->id,
                'damage_code'    => $di->damage_code,
                'warehouse_name' => $di->warehouse?->warehouse_name ?? '—',
                'damage_date'    => $di->damage_date,
                'total_value'    => (float) $di->total_value,
                'is_reversed'    => (bool) $di->is_reversed,
                'items_count'    => $return->items->filter(fn ($i) => $i->damage_invoice_id === $di->id)->count(),
            ])
            ->all();

        return [
            'return' => [
                'id'           => (int) $return->id,
                'return_code'  => $return->return_code,
                'status'       => $return->status,
                'total_amount' => (float) $return->total_amount,
                'cogs_amount'  => (float) $return->cogs_amount,
                'is_reversed'  => (bool) $return->is_reversed,
            ],
            'stock_movements'         => array_values($stockMovements),
            'customer_ledger'         => array_values($customerLedger),
            'gl_journals'             => $glJournals,
            'linked_damage_invoices'  => array_values($linkedDamageInvoices),
        ];
    }

    /**
     * Convenience: can this return be reversed right now? (No status block
     * AND no stock shortages.) Used by the service as a defense-in-depth
     * check inside the transaction.
     */
    public function canReverse(int $returnId): bool
    {
        return $this->getStatusBlock($returnId) === null
            && empty($this->getBlockReasons($returnId));
    }
}
