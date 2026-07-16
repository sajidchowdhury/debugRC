<?php

namespace App\Services\Purchase;

use App\Models\PurchaseReceive;
use App\Services\Stock\StockService;
use App\Services\Accounting\JournalPostingService;
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

            return PurchaseReceive::with(['items.product', 'supplier', 'branch', 'warehouse', 'purchaseOrder'])
                ->find($receiveId);
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

            // 3. Post supplier_ledger credit (we owe the supplier more).
            $this->postSupplierLedgerCredit($receive, $journalEntryId, $confirmedBy);

            // 4. Update PO received_qty (if against a PO).
            if ($receive->purchase_order_id) {
                foreach ($receive->items as $item) {
                    $this->poService->updateReceivedQty(
                        $receive->purchase_order_id,
                        $item->product_id,
                        (float) $item->qty
                    );
                }
            }

            // 5. Update GRN status.
            DB::table('purchase_receives')
                ->where('id', $receiveId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

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

            if ($receive->isConfirmed()) {
                // Reverse GL.
                if ($receive->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $receive->journal_entry_id, $cancelledBy,
                        "GRN cancelled: {$reason}"
                    );
                }

                // Reverse supplier_ledger (debit entry to reduce what we owe).
                $this->reverseSupplierLedgerCredit($receive, $cancelledBy, $reason);

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
                if ($receive->purchase_order_id) {
                    foreach ($receive->items as $item) {
                        $this->decrementPoReceivedQty(
                            $receive->purchase_order_id,
                            $item->product_id,
                            (float) $item->qty
                        );
                    }
                }

                DB::table('purchase_receives')
                    ->where('id', $receiveId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            DB::table('purchase_receives')
                ->where('id', $receiveId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

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
     * Post supplier_ledger credit entry (we owe the supplier more).
     */
    private function postSupplierLedgerCredit(PurchaseReceive $receive, ?int $journalEntryId, int $createdBy): void
    {
        $amount = (float) $receive->total_amount;
        if ($amount < 0.01 || !$receive->supplier_id) {
            return;
        }

        // Get current balance.
        $currentBalance = (float) DB::table('supplier_ledger')
            ->where('supplier_id', $receive->supplier_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance + $amount; // credit increases what we owe

        DB::table('supplier_ledger')->insert([
            'supplier_id' => $receive->supplier_id,
            'branch_id' => $receive->branch_id,
            'transaction_date' => $receive->receive_date->format('Y-m-d'),
            'transaction_type' => 'purchase_receive',
            'reference_type' => 'purchase_receive',
            'reference_id' => $receive->id,
            'debit' => 0,
            'credit' => $amount,
            'balance' => $newBalance,
            'description' => 'GRN ' . $receive->receive_code . ($receive->notes ? ' — ' . $receive->notes : ''),
            'journal_entry_id' => $journalEntryId,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse supplier_ledger (debit entry to reduce what we owe on cancel).
     */
    private function reverseSupplierLedgerCredit(PurchaseReceive $receive, int $cancelledBy, string $reason): void
    {
        $amount = (float) $receive->total_amount;
        if ($amount < 0.01 || !$receive->supplier_id) {
            return;
        }

        $currentBalance = (float) DB::table('supplier_ledger')
            ->where('supplier_id', $receive->supplier_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance - $amount; // debit reduces what we owe

        DB::table('supplier_ledger')->insert([
            'supplier_id' => $receive->supplier_id,
            'branch_id' => $receive->branch_id,
            'transaction_date' => now()->format('Y-m-d'),
            'transaction_type' => 'purchase_receive_reversal',
            'reference_type' => 'purchase_receive',
            'reference_id' => $receive->id,
            'debit' => $amount,
            'credit' => 0,
            'balance' => $newBalance,
            'description' => 'GRN reversal ' . $receive->receive_code . ": {$reason}",
            'created_by' => $cancelledBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Decrement PO received_qty (on GRN cancel).
     */
    private function decrementPoReceivedQty(int $poId, int $productId, float $qty): void
    {
        $item = DB::table('purchase_order_items')
            ->where('purchase_order_id', $poId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (!$item) return;

        $newReceived = max(0, (float) $item->received_qty - $qty);
        DB::table('purchase_order_items')
            ->where('id', $item->id)
            ->update(['received_qty' => $newReceived]);

        // Recalculate PO status.
        $allItems = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->get();
        $anyReceived = $allItems->some(fn($i) => (float) $i->received_qty > 0.0001);
        $allReceived = $allItems->every(fn($i) => (float) $i->received_qty >= (float) $i->qty - 0.0001);

        $newStatus = $allReceived ? 'received' : ($anyReceived ? 'partial' : 'sent');
        DB::table('purchase_orders')->where('id', $poId)->update(['status' => $newStatus, 'updated_at' => now()]);
    }

    /**
     * Generate atomic GRN code: GRN-YYYYMMDD-NNNN.
     */
    private function generateReceiveCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'purchase_receive';

        return DB::transaction(function () use ($docType, $periodKey, $datePart) {
            $seqRow = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->where('branch_id', 0)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $nextNumber = $seqRow ? ((int) $seqRow->last_number + 1) : 1;

            if ($seqRow) {
                DB::table('document_sequences')->where('id', $seqRow->id)
                    ->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            } else {
                DB::table('document_sequences')->insert([
                    'doc_type' => $docType, 'branch_id' => 0,
                    'period_key' => $periodKey, 'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
            }

            return "GRN-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
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
