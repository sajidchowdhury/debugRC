<?php

namespace App\Services\Stock;

use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Service — Phase 6.5.
 *
 * Two-phase flow:
 *   1. createTransfer(): creates draft (header + items, no stock/GL)
 *   2. confirmTransfer(): applies stock (source OUT + dest IN) + posts GL
 *   3. cancelTransfer(): if confirmed, reverses; if draft, marks cancelled
 *
 * GL posting rules (re-derived from intercompany accounting principles):
 *
 * SAME-BRANCH transfer (from_branch == to_branch):
 *   - Stock moves: source OUT at avg_cost, dest IN at same avg_cost
 *   - NO GL journal (inventory is just reallocated within the same branch;
 *     the branch's total inventory doesn't change)
 *
 * CROSS-BRANCH transfer (from_branch != to_branch):
 *   - Stock moves: source OUT at avg_cost, dest IN at same avg_cost
 *   - TWO intercompany GL journals:
 *     a) From-branch (creditor): Dr Due-to-Branch / Cr Inventory
 *        (from-branch loses inventory, gains a receivable from to-branch)
 *     b) To-branch (debtor): Dr Inventory / Cr Due-from-Branch
 *        (to-branch gains inventory, owes from-branch)
 *   - This creates the intercompany settlement tracked via branch_ledger.
 *   - The Due-from/Due-to-Branch accounts must net to zero across all branches.
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   - Source OUT: rate = current avg_cost (cost flows out at average)
 *   - Dest IN: rate = source avg_cost (transferred at source cost, not dest's avg)
 *     This ensures the dest branch receives the stock at the same cost the
 *     source branch had it — no phantom gain/loss on transfer.
 */
class WarehouseTransferService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting
    ) {}

    /**
     * Phase 1: Create a draft warehouse transfer (no stock movement, no GL).
     *
     * @param array $data {
     *     from_warehouse_id: int,
     *     to_warehouse_id: int,
     *     transfer_date: string (Y-m-d),
     *     notes: string|null,
     *     created_by: int,
     *     items: array each { product_id, qty, rate }
     * }
     * @return WarehouseTransfer
     */
    public function createTransfer(array $data): WarehouseTransfer
    {
        $this->validateCreateInput($data);

        $fromWarehouseId = (int) $data['from_warehouse_id'];
        $toWarehouseId = (int) $data['to_warehouse_id'];

        // Resolve branches for both warehouses.
        $warehouses = DB::table('warehouses')
            ->whereIn('id', [$fromWarehouseId, $toWarehouseId])
            ->pluck('branch_id', 'id');

        $fromBranchId = (int) $warehouses[$fromWarehouseId];
        $toBranchId = (int) $warehouses[$toWarehouseId];
        $isInterbranch = $fromBranchId !== $toBranchId;

        // Build + validate line items.
        $totalAmount = 0.0;
        $validatedItems = [];
        foreach ($data['items'] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            if ($productId <= 0 || $qty <= 0) continue;

            $rate = (float) ($item['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stockService->getWarehouseAvgCost($fromWarehouseId, $productId);
            }

            $validatedItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'rate' => $rate,
            ];
            $totalAmount += $qty * $rate;
        }

        if (empty($validatedItems)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }

        // Pre-check availability at source (will be re-checked on confirm).
        foreach ($validatedItems as $item) {
            $available = $this->stockService->getWarehouseQty($fromWarehouseId, $item['product_id']);
            if ($item['qty'] > $available + 0.0001) {
                throw new \RuntimeException(
                    "Insufficient stock at source for product {$item['product_id']}: "
                    . "available {$available}, requested {$item['qty']}"
                );
            }
        }

        $transferCode = $this->generateTransferCode();

        return DB::transaction(function () use (
            $transferCode, $data, $fromWarehouseId, $toWarehouseId,
            $fromBranchId, $toBranchId, $isInterbranch, $totalAmount, $validatedItems
        ) {
            $transferId = DB::table('warehouse_transfers')->insertGetId([
                'transfer_code' => $transferCode,
                'transfer_date' => $data['transfer_date'] ?? now()->format('Y-m-d'),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'is_interbranch' => $isInterbranch,
                'total_amount' => round($totalAmount, 2),
                'status' => 'draft',
                'is_reversed' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemRows = [];
            foreach ($validatedItems as $item) {
                $itemRows[] = [
                    'warehouse_transfer_id' => $transferId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                ];
            }
            DB::table('warehouse_transfer_items')->insert($itemRows);

            return WarehouseTransfer::with(['items.product', 'fromWarehouse.branch', 'toWarehouse.branch'])
                ->find($transferId);
        });
    }

    /**
     * Phase 2: Confirm a draft transfer — apply stock + post GL.
     *
     * @param int $transferId
     * @param int $confirmedBy
     * @return WarehouseTransfer
     * @throws \RuntimeException If not draft, or stock/GL posting fails.
     */
    public function confirmTransfer(int $transferId, int $confirmedBy): WarehouseTransfer
    {
        return DB::transaction(function () use ($transferId, $confirmedBy) {
            $transfer = WarehouseTransfer::with('items')->lockForUpdate()->find($transferId);

            if (!$transfer) {
                throw new \RuntimeException("Transfer {$transferId} not found.");
            }
            if (!$transfer->isDraft()) {
                throw new \RuntimeException("Only draft transfers can be confirmed (current: {$transfer->status}).");
            }

            $fromWh = $transfer->from_warehouse_id;
            $toWh = $transfer->to_warehouse_id;
            $transferDate = $transfer->transfer_date->format('Y-m-d');

            // Apply stock movements for each item.
            foreach ($transfer->items as $item) {
                $rate = (float) $item->rate;
                $qty = (float) $item->qty;

                // Source OUT (negative qty, at current avg_cost — rate is the cost).
                $this->stockService->applyTransaction([
                    'warehouse_id' => $fromWh,
                    'product_id' => $item->product_id,
                    'qty' => -$qty, // negative = OUT
                    'rate' => $rate,
                    'reference_type' => 'warehouse_transfer',
                    'reference_id' => $transfer->id,
                    'notes' => 'Transfer OUT ' . $transfer->transfer_code,
                    'transaction_date' => $transferDate,
                    'created_by' => $confirmedBy,
                ]);

                // Dest IN (positive qty, at SOURCE avg_cost — transferred at source cost).
                $this->stockService->applyTransaction([
                    'warehouse_id' => $toWh,
                    'product_id' => $item->product_id,
                    'qty' => $qty, // positive = IN
                    'rate' => $rate, // source avg_cost (preserves cost integrity)
                    'reference_type' => 'warehouse_transfer',
                    'reference_id' => $transfer->id,
                    'notes' => 'Transfer IN ' . $transfer->transfer_code,
                    'transaction_date' => $transferDate,
                    'created_by' => $confirmedBy,
                ]);
            }

            // Post GL (only if cross-branch).
            $journalEntryId = null;
            $journalEntryIdDebtor = null;

            if ($transfer->is_interbranch) {
                [$journalEntryId, $journalEntryIdDebtor] = $this->postIntercompanyGL($transfer, $confirmedBy);
            }

            // Update transfer status.
            DB::table('warehouse_transfers')
                ->where('id', $transferId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'journal_entry_id_debtor' => $journalEntryIdDebtor,
                    'updated_at' => now(),
                ]);

            return WarehouseTransfer::with([
                'items.product', 'fromWarehouse.branch', 'toWarehouse.branch',
                'journalEntry.lines.ledger', 'debtorJournalEntry.lines.ledger'
            ])->find($transferId);
        });
    }

    /**
     * Phase 3: Cancel a transfer.
     * - If confirmed: reverse stock + GL.
     * - If draft: just mark cancelled.
     *
     * @param int $transferId
     * @param int $cancelledBy
     * @param string $reason
     * @return WarehouseTransfer
     */
    public function cancelTransfer(int $transferId, int $cancelledBy, string $reason = ''): WarehouseTransfer
    {
        return DB::transaction(function () use ($transferId, $cancelledBy, $reason) {
            $transfer = WarehouseTransfer::with('items')->lockForUpdate()->find($transferId);

            if (!$transfer) {
                throw new \RuntimeException("Transfer {$transferId} not found.");
            }
            if ($transfer->isCancelled()) {
                throw new \RuntimeException("Transfer is already cancelled.");
            }

            if ($transfer->isConfirmed()) {
                // Reverse GL (both journals if interbranch).
                if ($transfer->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $transfer->journal_entry_id, $cancelledBy,
                        "Transfer cancelled: {$reason}"
                    );
                }
                if ($transfer->journal_entry_id_debtor) {
                    $this->journalPosting->reverseJournalEntry(
                        $transfer->journal_entry_id_debtor, $cancelledBy,
                        "Transfer cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement (source OUT + dest IN).
                $stockTxs = DB::table('stock_transactions')
                    ->where('reference_type', 'warehouse_transfer')
                    ->where('reference_id', $transferId)
                    ->where('is_reversed', false)
                    ->get();

                foreach ($stockTxs as $tx) {
                    $this->stockService->reverseTransaction(
                        $tx->id, $cancelledBy,
                        "Transfer cancelled: {$reason}"
                    );
                }

                DB::table('warehouse_transfers')
                    ->where('id', $transferId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            DB::table('warehouse_transfers')
                ->where('id', $transferId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return WarehouseTransfer::find($transferId);
        });
    }

    /**
     * Post intercompany GL for a cross-branch transfer.
     * Creates TWO journal entries:
     *   1. From-branch (creditor): Dr Due-to-Branch / Cr Inventory
     *   2. To-branch (debtor): Dr Inventory / Cr Due-from-Branch
     *
     * @return array [creditor_journal_id, debtor_journal_id]
     * @throws \RuntimeException If required ledgers not found.
     */
    private function postIntercompanyGL(WarehouseTransfer $transfer, int $createdBy): array
    {
        $amount = (float) $transfer->total_amount;
        if ($amount < 0.01) {
            return [null, null];
        }

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        $dueFromLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');
        $dueToLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');

        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }
        if (!$dueFromLedgerId) {
            throw new \RuntimeException('Interbranch receivable ledger not found (nature: interbranch_receivable).');
        }
        if (!$dueToLedgerId) {
            throw new \RuntimeException('Interbranch payable ledger not found (nature: interbranch_payable).');
        }

        $transferDate = $transfer->transfer_date->format('Y-m-d');
        $code = $transfer->transfer_code;

        // 1. From-branch (creditor): Dr Due-to-Branch / Cr Inventory
        // From-branch loses inventory, gains a payable to to-branch.
        $creditorEntryId = $this->journalPosting->createJournalEntry([
            'entry_date' => $transferDate,
            'reference_type' => 'warehouse_transfer',
            'reference_id' => $transfer->id,
            'branch_id' => $transfer->from_branch_id,
            'description' => "Transfer OUT {$code} — to {$transfer->toBranch->branch_name}",
            'source' => 'warehouse_transfer',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $dueToLedgerId,
                'debit' => $amount, 'credit' => 0,
                'memo' => "Stock transfer to {$transfer->toBranch->branch_name} — {$code}",
            ],
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $amount,
                'memo' => "Stock out — {$code}",
            ],
        ]);

        // 2. To-branch (debtor): Dr Inventory / Cr Due-from-Branch
        // To-branch gains inventory, owes from-branch.
        $debtorEntryId = $this->journalPosting->createJournalEntry([
            'entry_date' => $transferDate,
            'reference_type' => 'warehouse_transfer',
            'reference_id' => $transfer->id,
            'branch_id' => $transfer->to_branch_id,
            'description' => "Transfer IN {$code} — from {$transfer->fromBranch->branch_name}",
            'source' => 'warehouse_transfer',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $amount, 'credit' => 0,
                'memo' => "Stock in — {$code}",
            ],
            [
                'ledger_id' => $dueFromLedgerId,
                'debit' => 0, 'credit' => $amount,
                'memo' => "Stock transfer from {$transfer->fromBranch->branch_name} — {$code}",
            ],
        ]);

        // Record the intercompany settlement in branch_ledger.
        DB::table('branch_ledger')->insert([
            'from_branch_id' => $transfer->from_branch_id,
            'to_branch_id' => $transfer->to_branch_id,
            'transaction_date' => $transferDate,
            'transaction_type' => 'warehouse_transfer',
            'reference_type' => 'warehouse_transfer',
            'reference_id' => $transfer->id,
            'amount' => $amount,
            'description' => "Transfer {$code}",
            'journal_entry_id' => $creditorEntryId,
            'is_settled' => false,
            'created_at' => now(),
        ]);

        return [$creditorEntryId, $debtorEntryId];
    }

    /**
     * Generate atomic transfer code: WT-YYYYMMDD-NNNN.
     */
    private function generateTransferCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'warehouse_transfer';

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

            return "WT-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    private function validateCreateInput(array $data): void
    {
        if (empty($data['from_warehouse_id']) || (int) $data['from_warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('from_warehouse_id is required.');
        }
        if (empty($data['to_warehouse_id']) || (int) $data['to_warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('to_warehouse_id is required.');
        }
        if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
            throw new \InvalidArgumentException('From and To warehouses must be different.');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
    }
}
