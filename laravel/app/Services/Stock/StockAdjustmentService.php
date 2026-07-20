<?php

namespace App\Services\Stock;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Adjustment Service — Phase 6.3.
 *
 * Two-phase flow (better than legacy immediate-post):
 *   1. createAdjustment(): creates header + items (status=draft). NO stock movement. NO GL.
 *   2. confirmAdjustment(): applies stock via StockService + posts GL journal. status → confirmed.
 *   3. cancelAdjustment(): if confirmed, reverses stock + GL; if draft, just marks cancelled.
 *
 * GL posting rules (re-derived from double-entry principles):
 *   - Increase (stock goes UP): Dr Inventory / Cr Inventory Surplus (gain)
 *   - Decrease (stock goes DOWN): Dr Inventory Shrinkage (loss) / Cr Inventory
 *
 * All operations are wrapped in DB::transaction() — if GL posting fails,
 * the stock movement rolls back too (atomicity contract from avg_cost_rule.md §4).
 */
class StockAdjustmentService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting
    ) {}

    /**
     * Phase 1: Create a draft stock adjustment (no stock movement, no GL).
     *
     * @param array $data {
     *     warehouse_id: int,
     *     adjustment_type: 'increase'|'decrease',
     *     adjustment_date: string (Y-m-d),
     *     reason: string,
     *     created_by: int,
     *     items: array each { product_id, qty, rate, reason }
     * }
     * @return StockAdjustment
     * @throws \InvalidArgumentException If validation fails.
     */
    public function createAdjustment(array $data): StockAdjustment
    {
        $this->validateCreateInput($data);

        $warehouseId = (int) $data['warehouse_id'];
        $adjustmentType = $data['adjustment_type'];
        $items = $data['items'];

        // Look up the branch from the warehouse.
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        if (!$warehouse) {
            throw new \InvalidArgumentException("Warehouse {$warehouseId} not found.");
        }
        $branchId = (int) $warehouse->branch_id;

        // Generate adjustment code: ADJ-YYYYMMDD-NNNN.
        $adjustmentCode = $this->generateAdjustmentCode();

        // Calculate total amount from items.
        $totalAmount = 0.0;
        $validatedItems = [];
        foreach ($items as $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $productId = (int) ($item['product_id'] ?? 0);
            if ($qty <= 0 || $productId <= 0) {
                continue;
            }
            $rate = (float) ($item['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);
            }
            $validatedItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'rate' => $rate,
                'reason' => trim((string) ($item['reason'] ?? '')),
            ];
            $totalAmount += $qty * $rate;
        }

        if (empty($validatedItems)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }

        // For decrease adjustments, pre-check availability (will be re-checked on confirm).
        if ($adjustmentType === 'decrease') {
            foreach ($validatedItems as $item) {
                $available = $this->stockService->getWarehouseQty($warehouseId, $item['product_id']);
                if ($item['qty'] > $available + 0.0001) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$item['product_id']}: "
                        . "available {$available}, requested {$item['qty']}"
                    );
                }
            }
        }

        return DB::transaction(function () use (
            $adjustmentCode, $warehouseId, $branchId, $adjustmentType,
            $totalAmount, $data, $validatedItems
        ) {
            // Create the adjustment header.
            $adjustmentId = DB::table('stock_adjustments')->insertGetId([
                'adjustment_code' => $adjustmentCode,
                'adjustment_date' => $data['adjustment_date'] ?? now()->format('Y-m-d'),
                'warehouse_id' => $warehouseId,
                'branch_id' => $branchId,
                'adjustment_type' => $adjustmentType,
                'total_amount' => round($totalAmount, 2),
                'reason' => trim((string) ($data['reason'] ?? '')),
                'status' => 'draft',
                'is_reversed' => false,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create the adjustment items.
            $itemRows = [];
            foreach ($validatedItems as $item) {
                $itemRows[] = [
                    'stock_adjustment_id' => $adjustmentId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                    'reason' => $item['reason'],
                ];
            }
            DB::table('stock_adjustment_items')->insert($itemRows);

            return StockAdjustment::with('items.product', 'warehouse.branch')->find($adjustmentId);
        });
    }

    /**
     * Phase 2: Confirm a draft adjustment — apply stock movements + post GL journal.
     *
     * @param int $adjustmentId
     * @param int $confirmedBy
     * @return StockAdjustment
     * @throws \RuntimeException If not draft, or stock/GL posting fails.
     */
    public function confirmAdjustment(int $adjustmentId, int $confirmedBy): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $confirmedBy) {
            $adjustment = StockAdjustment::with('items')->lockForUpdate()->find($adjustmentId);

            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if (!$adjustment->isDraft()) {
                throw new \RuntimeException("Only draft adjustments can be confirmed (current status: {$adjustment->status}).");
            }

            $warehouseId = $adjustment->warehouse_id;
            $sign = $adjustment->isIncrease() ? 1 : -1;

            // Apply stock movement for each item via StockService.
            foreach ($adjustment->items as $item) {
                $qtyChange = $sign * (float) $item->qty;

                $this->stockService->applyTransaction([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'qty' => $qtyChange,
                    'rate' => (float) $item->rate,
                    'reference_type' => 'stock_adjustment',
                    'reference_id' => $adjustment->id,
                    'notes' => 'Stock Adjustment #' . $adjustment->adjustment_code
                        . ($item->reason ? ' — ' . $item->reason : ''),
                    'transaction_date' => $adjustment->adjustment_date->format('Y-m-d'),
                    'created_by' => $confirmedBy,
                ]);
            }

            // Post the GL journal entry.
            $journalEntryId = $this->postAdjustmentGL($adjustment, $confirmedBy);

            // Update the adjustment status + journal_entry_id.
            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            return StockAdjustment::with('items.product', 'warehouse.branch', 'journalEntry')
                ->find($adjustmentId);
        });
    }

    /**
     * Phase 3: Cancel an adjustment.
     * - If confirmed: reverse stock movements + reverse GL journal. status → cancelled.
     * - If draft: just mark as cancelled (no stock/GL to reverse).
     *
     * @param int $adjustmentId
     * @param int $cancelledBy
     * @param string $reason
     * @return StockAdjustment
     * @throws \RuntimeException If already cancelled.
     */
    public function cancelAdjustment(int $adjustmentId, int $cancelledBy, string $reason = ''): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $cancelledBy, $reason) {
            $adjustment = StockAdjustment::with('items')->lockForUpdate()->find($adjustmentId);

            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if ($adjustment->isCancelled()) {
                throw new \RuntimeException("Adjustment is already cancelled.");
            }

            if ($adjustment->isConfirmed()) {
                // Reverse the GL journal entry.
                if ($adjustment->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $adjustment->journal_entry_id,
                        $cancelledBy,
                        "Stock adjustment cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement.
                foreach ($adjustment->items as $item) {
                    $stockTx = DB::table('stock_transactions')
                        ->where('reference_type', 'stock_adjustment')
                        ->where('reference_id', $adjustment->id)
                        ->where('product_id', $item->product_id)
                        ->where('is_reversed', false)
                        ->first();

                    if ($stockTx) {
                        $this->stockService->reverseTransaction(
                            $stockTx->id,
                            $cancelledBy,
                            "Stock adjustment cancelled: {$reason}"
                        );
                    }
                }

                // Mark the adjustment as reversed.
                DB::table('stock_adjustments')
                    ->where('id', $adjustmentId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            // Set status to cancelled.
            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            return StockAdjustment::with('items.product', 'warehouse.branch')
                ->find($adjustmentId);
        });
    }

    /**
     * Post the GL journal entry for a stock adjustment.
     *
     * Re-derived GL rules:
     *   - Increase (gain): Dr Inventory / Cr Inventory Surplus
     *   - Decrease (loss): Dr Inventory Shrinkage / Cr Inventory
     *
     * @param StockAdjustment $adjustment
     * @param int $createdBy
     * @return int journal_entry_id
     * @throws \RuntimeException If required ledgers not found.
     */
    private function postAdjustmentGL(StockAdjustment $adjustment, int $createdBy): int
    {
        $totalAmount = (float) $adjustment->total_amount;

        if ($totalAmount < 0.01) {
            // No GL posting for zero-amount adjustments.
            return 0;
        }

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory). Configure the chart of accounts.');
        }

        $lines = [];

        if ($adjustment->isIncrease()) {
            // Increase: Dr Inventory / Cr Inventory Surplus
            $surplusLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_surplus');
            if (!$surplusLedgerId) {
                throw new \RuntimeException('Inventory surplus ledger not found (nature: inventory_surplus).');
            }
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $totalAmount,
                'credit' => 0,
                'memo' => 'Stock adjustment increase — ' . $adjustment->adjustment_code,
            ];
            $lines[] = [
                'ledger_id' => $surplusLedgerId,
                'debit' => 0,
                'credit' => $totalAmount,
                'memo' => 'Stock adjustment surplus — ' . $adjustment->adjustment_code,
            ];
        } else {
            // Decrease: Dr Inventory Shrinkage / Cr Inventory
            $shrinkageLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_shrinkage');
            if (!$shrinkageLedgerId) {
                throw new \RuntimeException('Inventory shrinkage ledger not found (nature: inventory_shrinkage).');
            }
            $lines[] = [
                'ledger_id' => $shrinkageLedgerId,
                'debit' => $totalAmount,
                'credit' => 0,
                'memo' => 'Stock adjustment loss — ' . $adjustment->adjustment_code,
            ];
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0,
                'credit' => $totalAmount,
                'memo' => 'Stock adjustment decrease — ' . $adjustment->adjustment_code,
            ];
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $adjustment->adjustment_date->format('Y-m-d'),
            'reference_type' => 'stock_adjustment',
            'reference_id' => $adjustment->id,
            'branch_id' => $adjustment->branch_id,
            'description' => 'Stock Adjustment ' . $adjustment->adjustment_code
                . ' (' . $adjustment->adjustment_type . ')'
                . ($adjustment->reason ? ' — ' . $adjustment->reason : ''),
            'source' => 'stock_adjustment',
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Generate an atomic adjustment code: ADJ-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateAdjustmentCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'stock_adjustment',
            prefix:   'ADJ',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Validate the createAdjustment input.
     */
    private function validateCreateInput(array $data): void
    {
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (!in_array($data['adjustment_type'] ?? '', ['increase', 'decrease'], true)) {
            throw new \InvalidArgumentException('adjustment_type must be "increase" or "decrease".');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
    }
}
