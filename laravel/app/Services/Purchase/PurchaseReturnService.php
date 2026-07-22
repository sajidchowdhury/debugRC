<?php

namespace App\Services\Purchase;

use App\Models\PurchaseReturn;
use App\Services\Auth\UserAuditLogger;
use App\Services\Stock\StockService;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalReversalService;
use App\Services\Accounting\SubLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Return Service — Phase 7.3.
 *
 * Returns goods to a supplier. Always against a GRN (purchase_receive_id).
 * Two-phase: draft → confirm → cancel.
 *
 * On confirm (3 operations, all atomic):
 *   1. Stock OUT via StockService at ORIGINAL receive rate (from GRN item)
 *      — SKIPPED for Damage condition lines (Phase 5).
 *   2. GL: Dr Accounts Payable / Cr Inventory (reverse of GRN) — posted for
 *      ALL items (Good + Damage) since Damage still reduces AP.
 *   3. Supplier ledger: debit entry (we owe the supplier less) — posted for
 *      ALL items.
 *   4. GRN item return_qty updated (cumulative returns tracking) — for ALL
 *      items (both Good and Damage reduce the supplier-returnable quota).
 *
 * Rate semantics: stock leaves at the ORIGINAL receive rate from the GRN
 * (NOT current avg_cost). This preserves cost integrity — the return
 * reverses the exact cost at which the stock was received.
 *
 * Return qty cap: returnable_qty = received_qty - already_returned.
 *
 * Phase 5 (Damage condition):
 *   - Good = stock OUT + GL + supplier_ledger + GRN return_qty++
 *   - Damage = NO stock movement + GL + supplier_ledger + GRN return_qty++
 *     (supplier claim only — stock was never received in usable condition,
 *      so it never entered warehouse_stock, so no OUT movement needed)
 */
class PurchaseReturnService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger
    ) {}

    /**
     * Phase 1: Create a draft purchase return (no stock, no GL, no supplier_ledger).
     *
     * @param array $data {
     *     purchase_receive_id: int (required — always against a GRN),
     *     return_date: string (Y-m-d),
     *     reason: string|null,
     *     created_by: int,
     *     items: array each { product_id, warehouse_id, qty, rate, purchase_receive_item_id }
     * }
     * @return PurchaseReturn
     */
    public function createReturn(array $data): PurchaseReturn
    {
        if (empty($data['purchase_receive_id'])) {
            throw new \InvalidArgumentException('purchase_receive_id is required (returns must be against a GRN).');
        }

        $receiveId = (int) $data['purchase_receive_id'];
        $receive = DB::table('purchase_receives')->where('id', $receiveId)->first();
        if (!$receive) {
            throw new \InvalidArgumentException("GRN {$receiveId} not found.");
        }
        if ($receive->status !== 'confirmed') {
            throw new \RuntimeException("Can only return against confirmed GRNs (current: {$receive->status}).");
        }

        $items = $this->validateItems($data['items'], $receiveId);
        $totalAmount = collect($items)->sum(fn($i) => $i['qty'] * $i['rate']);

        $returnCode = $this->generateReturnCode();
        $supplierId = (int) $receive->supplier_id;
        $branchId = (int) $receive->branch_id;
        // BUG-4 fix (Phase 0): purchase_returns.warehouse_id is NOT NULL in the
        // schema, but the original createReturn() did not write it — every
        // INSERT was failing. Inherit the GRN's header warehouse_id so the row
        // is persisted. Per-line warehouse_id (on purchase_return_items) is
        // still authoritative for the stock OUT movement; this header value is
        // the "default warehouse" for the return document as a whole — same
        // pattern Laravel uses for purchase_receives.
        $warehouseId = (int) $receive->warehouse_id;

        return DB::transaction(function () use (
            $data, $items, $totalAmount, $returnCode,
            $receiveId, $supplierId, $branchId, $warehouseId
        ) {
            $returnId = DB::table('purchase_returns')->insertGetId([
                'return_code' => $returnCode,
                'return_date' => $data['return_date'] ?? now()->format('Y-m-d'),
                'purchase_receive_id' => $receiveId,
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'total_amount' => round($totalAmount, 2),
                'status' => 'draft',
                'is_reversed' => false,
                'reason' => $data['reason'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'purchase_return_id' => $returnId,
                    'purchase_receive_item_id' => $item['purchase_receive_item_id'],
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                    // Phase 5: persist condition (default Good for back-compat).
                    'condition' => $item['condition'] ?? 'Good',
                ];
            }
            DB::table('purchase_return_items')->insert($itemRows);

            $return = PurchaseReturn::with(['items.product', 'supplier', 'branch', 'purchaseReceive'])
                ->find($returnId);

            // Phase 6: audit log.
            $goodCount = collect($items)->filter(fn($i) => ($i['condition'] ?? 'Good') === 'Good')->count();
            $damageCount = count($items) - $goodCount;
            UserAuditLogger::log(
                userId: $data['created_by'] ?? null,
                action: 'purchase_return_created',
                targetUserId: $returnId,
                details: [
                    'return_code'         => $returnCode,
                    'branch_id'           => $branchId,
                    'supplier_id'         => $supplierId,
                    'purchase_receive_id' => $receiveId,
                    'total'               => round($totalAmount, 2),
                    'item_count'          => count($items),
                    'good_lines'          => $goodCount,
                    'damage_lines'        => $damageCount,
                ]
            );

            return $return;
        });
    }

    /**
     * Phase 2: Confirm a draft return — stock OUT + GL + supplier_ledger + GRN update.
     *
     * @param int $returnId
     * @param int $confirmedBy
     * @return PurchaseReturn
     */
    public function confirmReturn(int $returnId, int $confirmedBy): PurchaseReturn
    {
        return DB::transaction(function () use ($returnId, $confirmedBy) {
            $return = PurchaseReturn::with('items')->lockForUpdate()->find($returnId);

            if (!$return) {
                throw new \RuntimeException("Return {$returnId} not found.");
            }
            if (!$return->isDraft()) {
                throw new \RuntimeException("Only draft returns can be confirmed (current: {$return->status}).");
            }

            $returnDate = $return->return_date->format('Y-m-d');

            // 1. Stock OUT for each item at the ORIGINAL receive rate.
            //    Phase 5: Damage condition items SKIP stock movement entirely
            //    (supplier claim only — stock was never usable). GL + ledger
            //    still posted below for ALL items (Good + Damage).
            foreach ($return->items as $item) {
                if ($item->isDamage()) {
                    // Damage: skip stock OUT. Still increment GRN return_qty so
                    // the supplier-returnable quota is correctly tracked.
                    if ($item->purchase_receive_item_id) {
                        DB::table('purchase_receive_items')
                            ->where('id', $item->purchase_receive_item_id)
                            ->update([
                                'return_qty' => DB::raw('COALESCE(return_qty, 0) + ' . (float) $item->qty),
                            ]);
                    }
                    continue;
                }

                $this->stockService->applyTransaction([
                    'warehouse_id' => $item->warehouse_id,
                    'product_id' => $item->product_id,
                    'qty' => -(float) $item->qty, // negative = OUT
                    'rate' => (float) $item->rate, // ORIGINAL receive rate (cost flows out, avg unchanged on OUT)
                    'reference_type' => 'purchase_return',
                    'reference_id' => $return->id,
                    'notes' => 'Purchase Return ' . $return->return_code,
                    'transaction_date' => $returnDate,
                    'created_by' => $confirmedBy,
                ]);

                // 2. Update GRN item return_qty (cumulative tracking).
                if ($item->purchase_receive_item_id) {
                    DB::table('purchase_receive_items')
                        ->where('id', $item->purchase_receive_item_id)
                        ->update([
                            'return_qty' => DB::raw('COALESCE(return_qty, 0) + ' . (float) $item->qty),
                        ]);
                }
            }

            // 3. Post GL: Dr AP / Cr Inventory.
            $journalEntryId = $this->postReturnGL($return, $confirmedBy);

            // 4. Post supplier_ledger debit via SubLedgerService (we owe less).
            $this->subLedger->postSupplierLedgerEntry([
                'supplier_id' => $return->supplier_id,
                'branch_id' => $return->branch_id,
                'transaction_date' => $return->return_date->format('Y-m-d'),
                'transaction_type' => 'purchase_return',
                'reference_type' => 'purchase_return',
                'reference_id' => $return->id,
                'debit' => (float) $return->total_amount,
                'credit' => 0,
                'description' => 'Purchase Return ' . $return->return_code . ($return->reason ? ' — ' . $return->reason : ''),
                'journal_entry_id' => $journalEntryId,
                'created_by' => $confirmedBy,
            ]);

            // 5. Update return status.
            DB::table('purchase_returns')
                ->where('id', $returnId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // Phase 6: audit log.
            $goodCount = $return->items->filter(fn($i) => $i->isGood())->count();
            $damageCount = $return->items->count() - $goodCount;
            UserAuditLogger::log(
                userId: $confirmedBy,
                action: 'purchase_return_confirmed',
                targetUserId: $returnId,
                details: [
                    'return_code'      => $return->return_code,
                    'branch_id'        => (int) $return->branch_id,
                    'supplier_id'      => (int) $return->supplier_id,
                    'total'            => (float) $return->total_amount,
                    'journal_entry_id' => $journalEntryId,
                    'good_lines'       => $goodCount,
                    'damage_lines'     => $damageCount,
                ]
            );

            return PurchaseReturn::with([
                'items.product', 'supplier', 'branch', 'purchaseReceive',
                'journalEntry.lines.ledger'
            ])->find($returnId);
        });
    }

    /**
     * Phase 3: Cancel a return.
     * - If confirmed: reverse stock + GL + supplier_ledger + GRN return_qty.
     * - If draft: just mark cancelled.
     */
    public function cancelReturn(int $returnId, int $cancelledBy, string $reason = ''): PurchaseReturn
    {
        return DB::transaction(function () use ($returnId, $cancelledBy, $reason) {
            $return = PurchaseReturn::with('items')->lockForUpdate()->find($returnId);

            if (!$return) {
                throw new \RuntimeException("Return {$returnId} not found.");
            }
            if ($return->isCancelled()) {
                throw new \RuntimeException("Return is already cancelled.");
            }

            if ($return->isConfirmed()) {
                // Reverse GL + linked supplier_ledger via JournalReversalService (cascade).
                // Phase 5: GL reversal cascades for ALL items (Good + Damage)
                // because the journal_entry_id links the whole return document.
                if ($return->journal_entry_id) {
                    $this->journalReversal->reverseByJournalEntry(
                        $return->journal_entry_id, $cancelledBy,
                        "Return cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement.
                // Phase 5: Damage items never created a stock movement, so the
                // stock_transactions query below naturally returns only Good
                // items' transactions — no extra branching needed here.
                $stockTxs = DB::table('stock_transactions')
                    ->where('reference_type', 'purchase_return')
                    ->where('reference_id', $returnId)
                    ->where('is_reversed', false)
                    ->get();

                foreach ($stockTxs as $tx) {
                    $this->stockService->reverseTransaction(
                        $tx->id, $cancelledBy,
                        "Return cancelled: {$reason}"
                    );
                }

                // Decrement GRN item return_qty.
                // Phase 5: decrement for ALL items (Good + Damage) since both
                // had their return_qty incremented on confirm.
                foreach ($return->items as $item) {
                    if ($item->purchase_receive_item_id) {
                        DB::table('purchase_receive_items')
                            ->where('id', $item->purchase_receive_item_id)
                            ->update([
                                'return_qty' => DB::raw('GREATEST(0, COALESCE(return_qty, 0) - ' . (float) $item->qty . ')'),
                            ]);
                    }
                }

                DB::table('purchase_returns')
                    ->where('id', $returnId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            DB::table('purchase_returns')
                ->where('id', $returnId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $cancelledBy,
                action: 'purchase_return_reversed',
                targetUserId: $returnId,
                details: [
                    'return_code'  => $return->return_code,
                    'reason'       => $reason,
                    'was_confirmed' => $return->isConfirmed(),
                ]
            );

            return PurchaseReturn::find($returnId);
        });
    }

    /**
     * Post GL: Dr Accounts Payable / Cr Inventory.
     * (Reverse of GRN's Dr Inventory / Cr AP.)
     */
    private function postReturnGL(PurchaseReturn $return, int $createdBy): int
    {
        $amount = (float) $return->total_amount;
        if ($amount < 0.01) {
            return 0;
        }

        $apLedgerId = $this->journalPosting->lookupLedgerByNature('ap');
        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');

        if (!$apLedgerId) {
            throw new \RuntimeException('Accounts Payable ledger not found (nature: ap).');
        }
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $return->return_date->format('Y-m-d'),
            'reference_type' => 'purchase_return',
            'reference_id' => $return->id,
            'branch_id' => $return->branch_id,
            'description' => 'Purchase Return ' . $return->return_code
                . ' (GRN ' . $return->purchaseReceive?->receive_code . ')'
                . ($return->reason ? ' — ' . $return->reason : ''),
            'source' => 'purchase_return',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $apLedgerId,
                'debit' => $amount, 'credit' => 0,
                'entity_type' => 'supplier',
                'entity_id' => $return->supplier_id,
                'memo' => 'AP reduced — ' . $return->return_code,
            ],
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $amount,
                'entity_type' => 'purchase_return',
                'entity_id' => $return->id,
                'memo' => 'Inventory out (cost) — ' . $return->return_code,
            ],
        ]);
    }

    /**
     * Generate atomic return code: PR-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateReturnCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'purchase_return',
            prefix:   'PR',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Validate items — check returnable_qty cap (received_qty - already_returned).
     */
    private function validateItems(array $items, int $receiveId): array
    {
        $validated = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $warehouseId = (int) ($item['warehouse_id'] ?? 0);
            $receiveItemId = (int) ($item['purchase_receive_item_id'] ?? 0);

            if ($productId <= 0 || $qty <= 0 || $warehouseId <= 0) continue;

            // Check returnable_qty cap.
            if ($receiveItemId > 0) {
                $receiveItem = DB::table('purchase_receive_items')->where('id', $receiveItemId)->first();
                if ($receiveItem) {
                    $returnable = (float) $receiveItem->qty - (float) ($receiveItem->return_qty ?? 0);
                    if ($qty > $returnable + 0.0001) {
                        throw new \RuntimeException(
                            "Return qty {$qty} exceeds returnable qty {$returnable} for product {$productId}."
                        );
                    }
                }
            }

            // Use the ORIGINAL receive rate (from the GRN item).
            $rate = (float) ($item['rate'] ?? 0);
            if ($rate <= 0 && $receiveItemId > 0) {
                $receiveItem = DB::table('purchase_receive_items')->where('id', $receiveItemId)->first();
                $rate = $receiveItem ? (float) $receiveItem->rate : 0;
            }

            $validated[] = [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'qty' => $qty,
                'rate' => $rate,
                'purchase_receive_item_id' => $receiveItemId > 0 ? $receiveItemId : null,
                // Phase 5: persist condition; normalize to 'Good' default.
                'condition' => $this->normalizeCondition($item['condition'] ?? 'Good'),
            ];
        }
        if (empty($validated)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }
        return $validated;
    }

    /**
     * Phase 5: normalize the condition value to 'Good' or 'Damage'.
     * Accepts case-insensitive input; defaults to 'Good'.
     */
    private function normalizeCondition(string $value): string
    {
        $v = trim($value);
        if ($v === '') return 'Good';
        return strcasecmp($v, 'Damage') === 0 ? 'Damage' : 'Good';
    }
}
