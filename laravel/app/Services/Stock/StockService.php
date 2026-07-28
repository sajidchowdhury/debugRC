<?php

namespace App\Services\Stock;

use App\Exceptions\WarehouseFrozenForCountException;
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

        // Phase 3 (Stock Take plan): outbound freeze guard.
        //
        // If this is an OUTBOUND movement (qty < 0) and the source warehouse
        // is currently frozen by an active stock-take session, reject the
        // movement with WarehouseFrozenForCountException naming the offending
        // session(s). Inbound movements (qty > 0 — purchases received, transfers
        // IN) are ALLOWED during a count; only stock LEAVING the warehouse
        // would corrupt the physical count.
        //
        // The stock-take's OWN variance application (reference_type='stock_take')
        // and reversals (reference_type='reversal') are explicitly exempt — the
        // whole point of the freeze is to let the count be posted/cancelled
        // while frozen, and reversals are corrections of prior movements, not
        // new outbound activity.
        //
        // The check is a single SELECT on the partial index idx_wh_is_frozen
        // (one row per frozen warehouse), so it is cheap for the common case
        // where nothing is frozen.
        if ($qty < 0
            && !in_array($referenceType, ['stock_take', 'reversal'], true)) {
            $this->assertWarehouseNotFrozen($warehouseId);
        }

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
     * Phase 6.3 (Stock Adjustment plan — back-dated reversal date, G10):
     *   The reversal stock_transaction's `transaction_date` is now taken from
     *   the $reversalDate param (defaults to the ORIGINAL transaction_date so
     *   the reversal lines up with the posting it undoes — not "today", which
     *   used to distort historical reports). When the requested date falls in
     *   a closed accounting period, the method falls back to today() and logs
     *   a warning (reversals can't be blocked outright — they're corrective).
     *
     * @param int $originalTransactionId
     * @param int $reversedBy
     * @param string $reason
     * @param string|null $reversalDate  Y-m-d. Defaults to the original
     *     transaction's transaction_date. Closed-period → today() + warning.
     * @return StockTransaction The reversal transaction.
     * @throws \RuntimeException If original not found, already reversed, or qty is zero.
     */
    public function reverseTransaction(
        int $originalTransactionId,
        int $reversedBy,
        string $reason = '',
        ?string $reversalDate = null
    ): StockTransaction {
        return DB::transaction(function () use ($originalTransactionId, $reversedBy, $reason, $reversalDate) {
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

            // Phase 6.3 — resolve the reversal date.
            //   1. Use $reversalDate if the caller passed one (stock-adjustment
            //      cancel passes the adjustment's adjustment_date so the
            //      reversal lines up with the original posting).
            //   2. Else default to the original transaction's transaction_date
            //      (so a back-dated Jan-1 posting reverses on Jan-1, not today).
            //   3. If that date falls in a closed accounting period, fall back
            //      to today() (reversals can't be blocked — they're corrective)
            //      and log a warning so the operator can investigate.
            $resolvedDate = $reversalDate ?? ($original->transaction_date ?? now()->format('Y-m-d'));
            $resolvedDate = $this->resolveReversalDate((int) $original->warehouse_id, $resolvedDate);

            $reversal = $this->applyTransaction([
                'warehouse_id' => (int) $original->warehouse_id,
                'product_id' => (int) $original->product_id,
                'qty' => $reverseQty,
                'rate' => $reverseRate,
                'reference_type' => 'reversal',
                'reference_id' => $originalTransactionId,
                'notes' => "Reversal of transaction #{$originalTransactionId}" . ($reason ? ": {$reason}" : ''),
                'transaction_date' => $resolvedDate,
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
     * Phase 6.3 — resolve the effective reversal date for a stock_transaction.
     *
     * The caller asks for $requestedDate (typically the original posting's
     * transaction_date, or the adjustment's adjustment_date). If that date
     * falls inside a CLOSED accounting period for the warehouse's branch,
     * the reversal cannot be back-dated there (it would post into a frozen
     * period) — so we fall back to today() and log a warning. Reversals are
     * corrective and can never be blocked outright; this fallback keeps them
     * working while making the date drift visible.
     *
     * Returns the resolved Y-m-d string.
     */
    private function resolveReversalDate(int $warehouseId, string $requestedDate): string
    {
        $today = now()->format('Y-m-d');

        // Normalize + validate the requested date (defensive — a malformed
        // string should not crash the reversal).
        try {
            $normalized = \Illuminate\Support\Carbon::parse($requestedDate)->format('Y-m-d');
        } catch (\Throwable $e) {
            Log::warning("StockService::resolveReversalDate — unparseable date '{$requestedDate}' for warehouse {$warehouseId}; falling back to today.");
            return $today;
        }

        // Look up the warehouse's branch (reversals are scoped by branch period).
        $branchId = DB::table('warehouses')->where('id', $warehouseId)->value('branch_id');
        if (!$branchId) {
            // No branch → no period close config → use the requested date as-is.
            return $normalized;
        }

        $closedThrough = DB::table('accounting_periods')
            ->where('branch_id', $branchId)
            ->value('closed_through_date');

        if (!$closedThrough) {
            // No period closed for this branch → unrestricted.
            return $normalized;
        }

        if ($normalized <= $closedThrough) {
            Log::warning(
                "StockService::resolveReversalDate — requested reversal date {$normalized} falls in a closed "
                . "period (closed_through {$closedThrough}) for branch {$branchId}; falling back to today ({$today})."
            );
            return $today;
        }

        return $normalized;
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
     * Phase 3 (Stock Take plan): assert a warehouse is not currently frozen
     * by an active stock-take session before allowing an OUTBOUND movement.
     *
     * Throws WarehouseFrozenForCountException naming the active session(s)
     * that are freezing the warehouse, so the calling controller can render a
     * clear 422 response. Cheap: one SELECT on the warehouses partial index +
     * one query to list the offending sessions (only when frozen).
     *
     * @throws WarehouseFrozenForCountException
     */
    private function assertWarehouseNotFrozen(int $warehouseId): void
    {
        $warehouse = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->select('id', 'warehouse_name', 'is_frozen_for_count')
            ->first();

        // No warehouse row (soft-deleted?) → cannot be frozen; let the caller
        // proceed and fail on its own FK/stock checks.
        if (!$warehouse || !(bool) $warehouse->is_frozen_for_count) {
            return;
        }

        // List the active sessions that freeze this warehouse.
        // Joins stock_take_warehouses (which warehouses) → stock_take_sessions
        // (the freeze flag + status) so we only name sessions that are BOTH
        // active AND have freeze_outbound=true.
        //
        // Phase 4: "active" now includes 'submitted' and 'approved' — a
        // session awaiting approval (or already approved but not yet posted)
        // has not yet applied any variance, so the outbound freeze must
        // remain in force. Only posted/cancelled/reversed sessions release
        // the freeze.
        $sessions = DB::table('stock_take_warehouses as stw')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
            ->where('stw.warehouse_id', $warehouseId)
            ->where('sts.freeze_outbound', true)
            ->whereIn('sts.status', ['draft', 'counting', 'submitted', 'approved'])
            ->orderBy('sts.id')
            ->select('sts.id', 'sts.session_code', 'sts.status')
            ->get()
            ->map(fn($s) => [
                'id'           => (int) $s->id,
                'session_code' => $s->session_code,
                'status'       => $s->status,
            ])
            ->all();

        throw new WarehouseFrozenForCountException(
            (int) $warehouse->id,
            $warehouse->warehouse_name,
            $sessions
        );
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
