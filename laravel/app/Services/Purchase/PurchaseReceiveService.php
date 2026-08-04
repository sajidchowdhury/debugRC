<?php

namespace App\Services\Purchase;

use App\Models\PurchaseReceive;
use App\Models\PurchaseReturn;
use App\Models\PurchaseOrder;
use App\Services\Auth\UserAuditLogger;
use App\Services\Stock\StockService;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalReversalService;
use App\Services\Accounting\SubLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Receive (GRN) Service — Phase 7.2.
 *
 * The economic event of the purchase module. Two-phase flow:
 *   1. createReceive(): draft (header + items, no stock/GL/supplier_ledger)
 *   2. confirmReceive(): stock IN + GL (Dr Inventory / Cr AP) + supplier_ledger
 *      credit + PO received_qty update
 *   3. cancelReceive(): if confirmed, reverses all; if draft, marks cancelled
 *
 * GL posting (re-derived from double-entry):
 *   Dr Inventory (nature: inventory) — at purchase rate
 *   Cr Accounts Payable (nature: ap) — liability to supplier
 *
 * Supplier ledger: credit entry increases what we owe the supplier.
 *
 * Stock movement: IN at purchase rate → avg_cost recalculated (IN rule).
 *
 * PO received_qty: updated via PurchaseOrderService::updateReceivedQty(),
 * which auto-updates PO status (partial/received).
 */
class PurchaseReceiveService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private PurchaseOrderService $poService
    ) {}

    /**
     * Phase 1: Create a draft GRN (no stock, no GL, no supplier_ledger).
     *
     * @param array $data {
     *     purchase_order_id: int|null (null = direct receive),
     *     supplier_id: int,
     *     branch_id: int,
     *     warehouse_id: int,
     *     receive_date: string (Y-m-d),
     *     notes: string|null,
     *     discount_amount: float,
     *     tax_amount: float,
     *     created_by: int,
     *     items: array each { product_id, warehouse_id, qty, rate, purchase_order_item_id }
     * }
     * @return PurchaseReceive
     */
    public function createReceive(array $data): PurchaseReceive
    {
        $this->validateCreateInput($data);

        $items = $this->validateItems($data['items']);
        $subTotal = collect($items)->sum(fn($i) => $i['qty'] * $i['rate']);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $tax = (float) ($data['tax_amount'] ?? 0);
        $total = $subTotal - $discount + $tax;

        $receiveCode = $this->generateReceiveCode();

        // If against a PO, pull supplier + branch from the PO.
        $poId = $data['purchase_order_id'] ?? null;
        $supplierId = (int) $data['supplier_id'];
        $branchId = (int) $data['branch_id'];

        if ($poId) {
            $po = DB::table('purchase_orders')->where('id', $poId)->first();
            if (!$po) {
                throw new \InvalidArgumentException("PO {$poId} not found.");
            }
            // Phase 8 (BUG-39 fix): Verify the PO is in a receivable state
            // (sent or partial). Without this guard, a GRN could be created
            // against a draft PO (jumping it directly to partial/received),
            // an already-received PO (over-receiving beyond ordered qty), or
            // a cancelled PO (resurrecting it). The controller's create()
            // method already calls canReceive() on the PO pre-fill path, but
            // the service is also reachable from jobs/tests/other controllers
            // — defense in depth.
            if (!in_array($po->status, ['sent', 'partial'], true)) {
                throw new \RuntimeException(
                    "PO {$poId} cannot receive goods (current status: {$po->status}). "
                    . "Allowed statuses: sent, partial."
                );
            }
            $supplierId = (int) $po->supplier_id;
            $branchId = (int) $po->branch_id;
        }

        return DB::transaction(function () use (
            $data, $items, $subTotal, $discount, $tax, $total,
            $receiveCode, $poId, $supplierId, $branchId
        ) {
            $receiveId = DB::table('purchase_receives')->insertGetId([
                'receive_code' => $receiveCode,
                'receive_date' => $data['receive_date'] ?? now()->format('Y-m-d'),
                'purchase_order_id' => $poId,
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'status' => 'draft',
                'is_reversed' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // PURCHASING-2 (G-035): manually fire the master_data audit since
            // DB::table()->insertGetId bypasses Eloquent events. The
            // AuditableMasterData trait's static::created listener would have
            // logged this row had we used PurchaseReceive::create($row).
            $receiveRow = [
                'receive_code' => $receiveCode,
                'receive_date' => $data['receive_date'] ?? now()->format('Y-m-d'),
                'purchase_order_id' => $poId,
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'status' => 'draft',
                'is_reversed' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ];
            PurchaseReceive::logManualAudit('purchase_receives', $receiveId, 'created', null, $receiveRow);

            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'purchase_receive_id' => $receiveId,
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'qty' => $item['qty'],
                    'return_qty' => 0,
                    'rate' => $item['rate'],
                ];
            }
            DB::table('purchase_receive_items')->insert($itemRows);

            $receive = PurchaseReceive::with(['items.product', 'supplier', 'branch', 'warehouse', 'purchaseOrder'])
                ->find($receiveId);

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $data['created_by'] ?? null,
                action: 'purchase_receive_created',
                targetUserId: $receiveId,
                details: [
                    'receive_code'       => $receiveCode,
                    'branch_id'          => $branchId,
                    'supplier_id'        => $supplierId,
                    'purchase_order_id'  => $poId,
                    'total'              => round($total, 2),
                    'item_count'         => count($items),
                ]
            );

            return $receive;
        });
    }

    /**
     * Phase 2: Confirm a draft GRN — apply stock + GL + supplier_ledger + PO update.
     *
     * @param int $receiveId
     * @param int $confirmedBy
     * @return PurchaseReceive
     * @throws \RuntimeException If not draft, or any posting fails.
     */
    public function confirmReceive(int $receiveId, int $confirmedBy): PurchaseReceive
    {
        return DB::transaction(function () use ($receiveId, $confirmedBy) {
            $receive = PurchaseReceive::with('items')->lockForUpdate()->find($receiveId);

            if (!$receive) {
                throw new \RuntimeException("GRN {$receiveId} not found.");
            }
            if (!$receive->isDraft()) {
                throw new \RuntimeException("Only draft GRNs can be confirmed (current: {$receive->status}).");
            }

            $receiveDate = $receive->receive_date->format('Y-m-d');

            // 1. Apply stock IN for each item via StockService.
            foreach ($receive->items as $item) {
                $this->stockService->applyTransaction([
                    'warehouse_id' => $item->warehouse_id,
                    'product_id' => $item->product_id,
                    'qty' => (float) $item->qty, // positive = IN
                    'rate' => (float) $item->rate, // purchase rate → avg_cost recalculated
                    'reference_type' => 'purchase_receive',
                    'reference_id' => $receive->id,
                    'notes' => 'GRN ' . $receive->receive_code,
                    'transaction_date' => $receiveDate,
                    'created_by' => $confirmedBy,
                ]);
            }

            // 2. Post GL: Dr Inventory / Cr Accounts Payable.
            $journalEntryId = $this->postReceiveGL($receive, $confirmedBy);

            // 3. Post supplier_ledger credit via SubLedgerService (we owe the supplier more).
            $this->subLedger->postSupplierLedgerEntry([
                'supplier_id' => $receive->supplier_id,
                'branch_id' => $receive->branch_id,
                'transaction_date' => $receive->receive_date->format('Y-m-d'),
                'transaction_type' => 'purchase_receive',
                'reference_type' => 'purchase_receive',
                'reference_id' => $receive->id,
                'debit' => 0,
                'credit' => (float) $receive->total_amount,
                'description' => 'GRN ' . $receive->receive_code . ($receive->notes ? ' — ' . $receive->notes : ''),
                'journal_entry_id' => $journalEntryId,
                'created_by' => $confirmedBy,
            ]);

            // 4. Update PO received_qty (if against a PO).
            // PURCHASING-3 (G-037): pass purchase_order_item_id (not product_id)
            // so the PO item is located by PK — unambiguous even when a PO has
            // the same product on multiple lines. Skip GRN items with null
            // purchase_order_item_id (direct receives or unmatched lines).
            if ($receive->purchase_order_id) {
                foreach ($receive->items as $item) {
                    if (!$item->purchase_order_item_id) {
                        // Defensive: log + skip. Shouldn't happen for PO-linked
                        // receives since the controller pre-fills the FK, but a
                        // direct service call (jobs/tests) could omit it.
                        Log::warning(
                            'PurchaseReceiveService::confirmReceive — GRN item has no purchase_order_item_id, skipping PO received_qty update',
                            [
                                'receive_id' => $receiveId,
                                'receive_code' => $receive->receive_code,
                                'po_id' => $receive->purchase_order_id,
                                'product_id' => $item->product_id,
                                'qty' => $item->qty,
                            ]
                        );
                        continue;
                    }
                    $this->poService->updateReceivedQty(
                        (int) $item->purchase_order_item_id,
                        (float) $item->qty
                    );
                }
            }

            // 5. Update GRN status.
            // PURCHASING-3 (G-039): persist confirmed_by / confirmed_at on the
            // row so the confirmer's identity is a fast O(1) PK lookup, not a
            // slow month-partitioned user_audit_log join.
            $receiveUpdate = [
                'status' => 'confirmed',
                'journal_entry_id' => $journalEntryId,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ];
            // PURCHASING-2 (G-035): capture old before update so we can log
            // the master_data audit row the AuditableMasterData trait would
            // have written had we used $receive->update($receiveUpdate).
            $oldReceive = (array) DB::table('purchase_receives')->where('id', $receiveId)->first();
            DB::table('purchase_receives')
                ->where('id', $receiveId)
                ->update($receiveUpdate);
            PurchaseReceive::logManualAudit(
                'purchase_receives', $receiveId, 'updated',
                array_intersect_key($oldReceive, $receiveUpdate),
                $receiveUpdate
            );

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $confirmedBy,
                action: 'purchase_receive_confirmed',
                targetUserId: $receiveId,
                details: [
                    'receive_code'      => $receive->receive_code,
                    'branch_id'         => (int) $receive->branch_id,
                    'supplier_id'       => (int) $receive->supplier_id,
                    'total'             => (float) $receive->total_amount,
                    'journal_entry_id'  => $journalEntryId,
                    'po_id'             => $receive->purchase_order_id,
                ]
            );

            return PurchaseReceive::with([
                'items.product', 'supplier', 'branch', 'warehouse', 'purchaseOrder',
                'journalEntry.lines.ledger'
            ])->find($receiveId);
        });
    }

    /**
     * Phase 3: Cancel a GRN.
     * - If confirmed: reverse stock + GL + supplier_ledger + PO received_qty.
     * - If draft: just mark cancelled.
     *
     * @param int $receiveId
     * @param int $cancelledBy
     * @param string $reason
     * @return PurchaseReceive
     */
    public function cancelReceive(int $receiveId, int $cancelledBy, string $reason = ''): PurchaseReceive
    {
        return DB::transaction(function () use ($receiveId, $cancelledBy, $reason) {
            $receive = PurchaseReceive::with('items')->lockForUpdate()->find($receiveId);

            if (!$receive) {
                throw new \RuntimeException("GRN {$receiveId} not found.");
            }
            if ($receive->isCancelled()) {
                throw new \RuntimeException("GRN is already cancelled.");
            }

            // BUG-5 fix (Phase 0): Block cancel if active (non-reversed, confirmed)
            // returns exist on this GRN. Without this guard, cancelling the GRN
            // would re-add stock that was already returned to the supplier —
            // creating inconsistent state (stock present but supplier ledger says
            // it was returned). User must reverse the returns first.
            //
            // Legacy parity: legacy PurchaseReceiveModel::cancelReceive has the
            // same guard. We mirror it here.
            if ($receive->isConfirmed()) {
                $activeReturns = PurchaseReturn::where('purchase_receive_id', $receiveId)
                    ->where('is_reversed', false)
                    ->where('status', 'confirmed')
                    ->count();
                if ($activeReturns > 0) {
                    throw new \RuntimeException(
                        "Cannot cancel GRN: {$activeReturns} active return(s) exist against it. "
                        . "Reverse them first."
                    );
                }
            }

            if ($receive->isConfirmed()) {
                // Reverse GL + linked supplier_ledger via JournalReversalService (cascade).
                if ($receive->journal_entry_id) {
                    $this->journalReversal->reverseByJournalEntry(
                        $receive->journal_entry_id, $cancelledBy,
                        "GRN cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement.
                $stockTxs = DB::table('stock_transactions')
                    ->where('reference_type', 'purchase_receive')
                    ->where('reference_id', $receiveId)
                    ->where('is_reversed', false)
                    ->get();

                foreach ($stockTxs as $tx) {
                    $this->stockService->reverseTransaction(
                        $tx->id, $cancelledBy,
                        "GRN cancelled: {$reason}"
                    );
                }

                // Reverse PO received_qty (decrement by the received qty).
                // PURCHASING-3 (G-037): pass purchase_order_item_id (not product_id).
                if ($receive->purchase_order_id) {
                    foreach ($receive->items as $item) {
                        if (!$item->purchase_order_item_id) {
                            // Defensive: log + skip. Mirrors the confirmReceive path.
                            Log::warning(
                                'PurchaseReceiveService::cancelReceive — GRN item has no purchase_order_item_id, skipping PO received_qty decrement',
                                [
                                    'receive_id' => $receiveId,
                                    'receive_code' => $receive->receive_code,
                                    'po_id' => $receive->purchase_order_id,
                                    'product_id' => $item->product_id,
                                    'qty' => $item->qty,
                                ]
                            );
                            continue;
                        }
                        $this->decrementPoReceivedQty(
                            (int) $item->purchase_order_item_id,
                            (float) $item->qty
                        );
                    }
                }

                // PURCHASING-2 (G-035): capture old BEFORE the reversal-field
                // update so we can log the master_data audit row.
                $reverseUpdate = [
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $cancelledBy,
                    'reverse_reason' => $reason,
                ];
                $oldReceiveForReverse = (array) DB::table('purchase_receives')->where('id', $receiveId)->first();
                DB::table('purchase_receives')
                    ->where('id', $receiveId)
                    ->update($reverseUpdate);
                PurchaseReceive::logManualAudit(
                    'purchase_receives', $receiveId, 'updated',
                    array_intersect_key($oldReceiveForReverse, $reverseUpdate),
                    $reverseUpdate
                );
            }

            // PURCHASING-2 (G-035): capture old for the status='cancelled' update
            // (separate from the reversal-field update above).
            $oldReceiveForCancel = (array) DB::table('purchase_receives')->where('id', $receiveId)->first();
            $cancelUpdate = ['status' => 'cancelled', 'updated_at' => now()];
            DB::table('purchase_receives')
                ->where('id', $receiveId)
                ->update($cancelUpdate);
            PurchaseReceive::logManualAudit(
                'purchase_receives', $receiveId, 'updated',
                array_intersect_key($oldReceiveForCancel, $cancelUpdate),
                $cancelUpdate
            );

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $cancelledBy,
                action: 'purchase_receive_cancelled',
                targetUserId: $receiveId,
                details: [
                    'receive_code' => $receive->receive_code,
                    'reason'       => $reason,
                    'was_confirmed' => $receive->isConfirmed(),
                ]
            );

            return PurchaseReceive::find($receiveId);
        });
    }

    /**
     * Post GL: Dr Inventory / Cr Accounts Payable.
     *
     * @return int journal_entry_id
     */
    private function postReceiveGL(PurchaseReceive $receive, int $createdBy): int
    {
        $amount = (float) $receive->total_amount;
        if ($amount < 0.01) {
            return 0;
        }

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        $apLedgerId = $this->journalPosting->lookupLedgerByNature('ap');

        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }
        if (!$apLedgerId) {
            throw new \RuntimeException('Accounts Payable ledger not found (nature: ap).');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $receive->receive_date->format('Y-m-d'),
            'reference_type' => 'purchase_receive',
            'reference_id' => $receive->id,
            'branch_id' => $receive->branch_id,
            'description' => 'GRN ' . $receive->receive_code
                . ($receive->purchase_order_id ? ' (against PO ' . $receive->purchaseOrder?->po_code . ')' : ' (direct)')
                . ($receive->notes ? ' — ' . $receive->notes : ''),
            'source' => 'purchase_receive',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $amount, 'credit' => 0,
                'entity_type' => 'purchase_receive',
                'entity_id' => $receive->id,
                'memo' => 'Inventory received — ' . $receive->receive_code,
            ],
            [
                'ledger_id' => $apLedgerId,
                'debit' => 0, 'credit' => $amount,
                'entity_type' => 'supplier',
                'entity_id' => $receive->supplier_id,
                'memo' => 'Payable to supplier — ' . $receive->receive_code,
            ],
        ]);
    }

    /**
     * Decrement PO received_qty (on GRN cancel).
     *
     * PURCHASING-3 (G-037): signature changed to accept purchase_order_item_id
     * instead of (poId, productId). Mirrors the updateReceivedQty refactor —
     * lookup by PK so duplicate-product PO lines are unambiguous.
     *
     * @param int   $poItemId purchase_order_items.id
     * @param float $qty      qty being reversed (positive)
     */
    private function decrementPoReceivedQty(int $poItemId, float $qty): void
    {
        $item = DB::table('purchase_order_items')
            ->where('id', $poItemId)
            ->lockForUpdate()
            ->first();

        if (!$item) return;

        $poId = (int) $item->purchase_order_id;

        $newReceived = max(0, (float) $item->received_qty - $qty);
        DB::table('purchase_order_items')
            ->where('id', $item->id)
            ->update(['received_qty' => $newReceived]);

        // Recalculate PO status.
        $allItems = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->get();
        $anyReceived = $allItems->some(fn($i) => (float) $i->received_qty > 0.0001);
        $allReceived = $allItems->every(fn($i) => (float) $i->received_qty >= (float) $i->qty - 0.0001);

        $newStatus = $allReceived ? 'received' : ($anyReceived ? 'partial' : 'sent');
        // PURCHASING-2 (G-034/G-035): capture old + log manual master_data
        // audit for the PO status flip driven by GRN cancellation. Mirrors
        // the audit hook in PurchaseOrderService::updateReceivedQty (the
        // GRN-confirm path) so both directions are audited.
        $poStatusUpdate = ['status' => $newStatus, 'updated_at' => now()];
        $oldPo = (array) DB::table('purchase_orders')->where('id', $poId)->first();
        DB::table('purchase_orders')->where('id', $poId)->update($poStatusUpdate);
        PurchaseOrder::logManualAudit(
            'purchase_orders', $poId, 'updated',
            array_intersect_key($oldPo, $poStatusUpdate),
            $poStatusUpdate
        );
    }

    /**
     * Generate atomic GRN code: GRN-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateReceiveCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'purchase_receive',
            prefix:   'GRN',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    private function validateCreateInput(array $data): void
    {
        if (empty($data['supplier_id']) && empty($data['purchase_order_id'])) {
            throw new \InvalidArgumentException('Either supplier_id or purchase_order_id is required.');
        }
        if (empty($data['branch_id']) && empty($data['purchase_order_id'])) {
            throw new \InvalidArgumentException('branch_id is required for direct receives.');
        }
        if (empty($data['warehouse_id'])) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
    }

    private function validateItems(array $items): array
    {
        $validated = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $rate = (float) ($item['rate'] ?? 0);
            $warehouseId = (int) ($item['warehouse_id'] ?? 0);
            if ($productId <= 0 || $qty <= 0 || $warehouseId <= 0) continue;
            $validated[] = [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'qty' => $qty,
                'rate' => $rate,
                'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
            ];
        }
        if (empty($validated)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }
        return $validated;
    }
}
