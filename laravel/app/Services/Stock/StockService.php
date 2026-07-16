<?php

namespace App\Services\Stock;

use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Service — Phase 6.1.
 *
 * The single entry point for all warehouse stock movements. Re-derived from
 * first principles (see docs/migration/avg_cost_rule.md) — NOT copied from
 * the legacy PHP.
 *
 * Every movement is an atomic DB transaction:
 *   1. INSERT stock_transaction (immutable ledger)
 *   2. SELECT warehouse_stock FOR UPDATE (lock)
 *   3. UPDATE warehouse_stock qty + avg_cost (apply movement)
 *   4. COMMIT (or ROLLBACK on any failure)
 *
 * The caller wraps the ENTIRE business operation (e.g., sales challan finalize
 * = sales_invoice update + stock_transaction + warehouse_stock + GL journal)
 * in one outer transaction. This service's inner queries participate in that
 * outer transaction (no nested commit).
 */
class StockService
{
    /**
     * Floating-point tolerance for qty comparisons.
     * Matches the DB CHECK constraint (qty >= -0.0001).
     */
    private const QTY_TOLERANCE = 0.0001;

    /**
     * Apply a stock movement: log the transaction + update warehouse_stock.
     *
     * This is the ONLY method that should modify warehouse_stock. All modules
     * (purchase, sales, adjustments, transfers, damages) call this.
     *
     * @param array $data {
     *     warehouse_id: int,
     *     product_id: int,
     *     qty: float (signed: positive = IN, negative = OUT),
     *     rate: float (unit cost — see avg_cost_rule.md §3 for semantics),
     *     reference_type: string (one of StockTransaction::REFERENCE_TYPES),
     *     reference_id: int,
     *     branch_demand_item_id: int|null,
     *     notes: string|null,
     *     transaction_date: string|null (default today),
     *     created_by: int|null,
     * }
     * @return StockTransaction The created transaction row.
     * @throws \RuntimeException If insufficient stock on an OUT movement.
     * @throws \InvalidArgumentException If reference_type is invalid or qty is zero.
     */
    public function applyTransaction(array $data): StockTransaction
    {
        $this->validate($data);

        $warehouseId = (int) $data['warehouse_id'];
        $productId = (int) $data['product_id'];
        $qty = (float) $data['qty'];
        $rate = (float) ($data['rate'] ?? 0);
        $referenceType = $data['reference_type'];
        $referenceId = (int) $data['reference_id'];

        $transactionDate = $data['transaction_date'] ?? now()->format('Y-m-d');

        // Step 1: Insert the immutable ledger row.
        $transactionId = DB::table('stock_transactions')->insertGetId([
            'transaction_date' => $transactionDate,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'qty' => $qty,
            'rate' => $rate,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'branch_demand_item_id' => $data['branch_demand_item_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_reversed' => false,
            'created_by' => $data['created_by'] ?? null,
            'created_at' => now(),
        ]);

        // Step 2: Lock the warehouse_stock row (FOR UPDATE).
        $stockRow = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $oldQty = $stockRow ? (float) $stockRow->qty : 0.0;
        $oldAvgCost = $stockRow ? (float) $stockRow->avg_cost : 0.0;

        // Step 3: Compute new qty + avg_cost per the re-derived rule.
        if ($qty > 0) {
            // === STOCK IN — recalculate moving average cost ===
            $newQty = $oldQty + $qty;
            $newAvgCost = $this->computeAvgCostOnIn($oldQty, $oldAvgCost, $qty, $rate);
        } else {
            // === STOCK OUT — reduce qty at current avg_cost (avg_cost unchanged) ===
            $outQty = abs($qty);
            $newQty = $oldQty - $outQty;

            if ($newQty < -self::QTY_TOLERANCE) {
                throw new \RuntimeException(
                    "Insufficient stock for product {$productId} in warehouse {$warehouseId}. "
                    . "Available: {$oldQty}, Requested: {$outQty}"
                );
            }
            $newAvgCost = $oldAvgCost;
        }

        // Step 4: Upsert warehouse_stock (insert if not exists, update if exists).
        DB::table('warehouse_stock')->upsert(
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'qty' => $newQty,
                'avg_cost' => $newAvgCost,
                'updated_at' => now(),
            ],
            ['warehouse_id', 'product_id'],
            ['qty', 'avg_cost', 'updated_at']
        );

        return StockTransaction::find($transactionId);
    }

    /**
     * Compute the new average cost on a stock-IN movement.
     *
     * Re-derived formula (see avg_cost_rule.md §2):
     *   new_avg = (old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)
     */
    private function computeAvgCostOnIn(float $oldQty, float $oldAvgCost, float $inQty, float $inRate): float
    {
        $newQty = $oldQty + $inQty;
        if ($newQty <= 0) {
            return $inRate;
        }
        $oldValue = $oldQty * $oldAvgCost;
        $inValue = $inQty * $inRate;
        return ($oldValue + $inValue) / $newQty;
    }

    /**
     * Reverse a stock transaction: create an opposite-sign movement + mark original.
     *
     * Reversals are append-only — the original is never mutated except for
     * the is_reversed flag.
     *
     * @param int $originalTransactionId
     * @param int $reversedBy
     * @param string $reason
     * @return StockTransaction The reversal transaction.
     * @throws \RuntimeException If original not found, already reversed, or qty is zero.
     */
    public function reverseTransaction(int $originalTransactionId, int $reversedBy, string $reason = ''): StockTransaction
    {
        return DB::transaction(function () use ($originalTransactionId, $reversedBy, $reason) {
            $original = DB::table('stock_transactions')
                ->where('id', $originalTransactionId)
                ->lockForUpdate()
                ->first();

            if (!$original) {
                throw new \RuntimeException("Stock transaction {$originalTransactionId} not found.");
            }
            if ($original->is_reversed) {
                throw new \RuntimeException("Stock transaction {$originalTransactionId} is already reversed.");
            }

            $originalQty = (float) $original->qty;
            if (abs($originalQty) < self::QTY_TOLERANCE) {
                throw new \RuntimeException("Stock transaction {$originalTransactionId} has zero qty — nothing to reverse.");
            }

            $reverseQty = -$originalQty;
            $reverseRate = (float) $original->rate;

            if ($reverseRate <= 0) {
                $reverseRate = $this->getWarehouseAvgCost(
                    (int) $original->warehouse_id,
                    (int) $original->product_id
                );
            }

            $reversal = $this->applyTransaction([
                'warehouse_id' => (int) $original->warehouse_id,
                'product_id' => (int) $original->product_id,
                'qty' => $reverseQty,
                'rate' => $reverseRate,
                'reference_type' => 'reversal',
                'reference_id' => $originalTransactionId,
                'notes' => "Reversal of transaction #{$originalTransactionId}" . ($reason ? ": {$reason}" : ''),
                'transaction_date' => now()->format('Y-m-d'),
                'created_by' => $reversedBy,
            ]);

            DB::table('stock_transactions')
                ->where('id', $originalTransactionId)
                ->update([
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $reversedBy,
                    'reverse_reason' => $reason,
                ]);

            return $reversal;
        });
    }

    /**
     * Get the current moving-average cost for a product in a warehouse.
     */
    public function getWarehouseAvgCost(int $warehouseId, int $productId): float
    {
        $row = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $row ? (float) $row->avg_cost : 0.0;
    }

    /**
     * Get the current on-hand qty for a product in a warehouse.
     */
    public function getWarehouseQty(int $warehouseId, int $productId): float
    {
        $row = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $row ? (float) $row->qty : 0.0;
    }

    /**
     * Lock warehouse_stock rows for a branch's products (FOR UPDATE).
     * Call inside an open transaction before checking availability.
     *
     * @param int $branchId
     * @param array<int> $productIds
     */
    public function lockBranchProductsForUpdate(int $branchId, array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($branchId <= 0 || $productIds === []) {
            return;
        }

        DB::table('warehouse_stock as ws')
            ->join('warehouses as w', function ($join) use ($branchId) {
                $join->on('w.id', '=', 'ws.warehouse_id')
                     ->where('w.branch_id', '=', $branchId);
            })
            ->whereIn('ws.product_id', $productIds)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Validate the applyTransaction input.
     */
    private function validate(array $data): void
    {
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (empty($data['product_id']) || (int) $data['product_id'] <= 0) {
            throw new \InvalidArgumentException('product_id is required.');
        }
        if (!isset($data['qty']) || abs((float) $data['qty']) < self::QTY_TOLERANCE) {
            throw new \InvalidArgumentException('qty must be non-zero.');
        }
        if (empty($data['reference_type']) || !in_array($data['reference_type'], StockTransaction::REFERENCE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid reference_type: ' . ($data['reference_type'] ?? '(missing)'));
        }
        if (empty($data['reference_id']) || (int) $data['reference_id'] <= 0) {
            throw new \InvalidArgumentException('reference_id is required.');
        }
    }
}
