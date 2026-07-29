<?php

namespace App\Services\BranchDemand;

use App\Models\BranchDemand;
use App\Models\BranchDemandItem;
use App\Models\WarehouseTransfer;
use App\Services\MasterData\CodeGenerator;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand Service — Phase 2 + Phase 3.
 *
 * Full demand lifecycle: create, send, reverse, delete, reject.
 *
 * Business flow:
 *   1. CREATE: Branch B (requester/debtor) creates a demand to Branch A (supplier/creditor).
 *      Status = 'pending'. No stock movement, no GL.
 *   2. SEND: Branch A's warehouse manager selects per-item FROM/TO warehouses and sends goods.
 *      Status = 'received'. Stock moves (OUT from supplier, IN to requester), WT created.
 *      Phase 3: GL journals posted (creditor + debtor), branch ledger updated.
 *   3. REVERSE: Undo a sent/received demand (stock restored, GL reversed, ledger reversed).
 *      Status = 'reversed'.
 *   4. DELETE: Remove a pending demand (only if status='pending').
 *   5. REJECT: Reject a pending demand (status='rejected').
 *
 * Terminology:
 *   - from_branch_id = requester (debtor) — the branch that NEEDS the products
 *   - to_branch_id   = supplier (creditor) — the branch that SUPPLIES the products
 *
 * Note: The naming convention follows the legacy system where `from_branch` is
 * the branch creating the demand (requester) and `to_branch` is the branch
 * fulfilling it (supplier). This is the opposite of the stock movement direction.
 *
 * Stock movement direction:
 *   - Stock OUT from supplier warehouse (to_branch's warehouse)
 *   - Stock IN to requester warehouse (from_branch's warehouse)
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   - Source OUT: rate = current avg_cost of the supplier warehouse (cost flows out at average)
 *   - Dest IN: rate = same avg_cost (transferred at source cost, no phantom gain/loss)
 */
class BranchDemandService
{
    private const QTY_TOLERANCE = 0.0001;

    public function __construct(
        private StockService $stockService,
        private StockAvailabilityService $stockAvailabilityService,
        private BranchIntercompanyService $intercompanyService,
    ) {}

    // ===================== CREATE =====================

    /**
     * Create a new branch demand with items.
     *
     * @param array $data {
     *     from_branch_id: int,  (requester/debtor)
     *     to_branch_id: int,    (supplier/creditor)
     *     demand_date: string (Y-m-d),
     *     notes: string|null,
     *     created_by: int,
     * }
     * @param array $items [ { product_id: int, qty: float } ]
     * @return BranchDemand
     * @throws \InvalidArgumentException If validation fails.
     */
    public function createDemand(array $data, array $items): BranchDemand
    {
        $this->validateCreateInput($data, $items);

        $fromBranchId = (int) $data['from_branch_id'];
        $toBranchId = (int) $data['to_branch_id'];

        // Ensure branches are different
        if ($fromBranchId === $toBranchId) {
            throw new \InvalidArgumentException('Requester and supplier branches must be different.');
        }

        // Ensure both branches exist and are active
        $branchCount = DB::table('branches')
            ->whereIn('id', [$fromBranchId, $toBranchId])
            ->where('is_active', true)
            ->count();

        if ($branchCount !== 2) {
            throw new \InvalidArgumentException('Both branches must exist and be active.');
        }

        $demandCode = CodeGenerator::generate('branch_demands', 'demand_code', 'BD-');

        return DB::transaction(function () use ($data, $items, $demandCode, $fromBranchId, $toBranchId) {
            // Create the demand header
            $demandId = DB::table('branch_demands')->insertGetId([
                'demand_code'    => $demandCode,
                'demand_date'    => $data['demand_date'],
                'from_branch_id' => $fromBranchId,
                'to_branch_id'   => $toBranchId,
                'status'         => 'pending',
                'total_value'    => null,
                'settlement_amount' => 0,
                'is_reversed'    => false,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $data['created_by'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Create demand items
            foreach ($items as $item) {
                DB::table('branch_demand_items')->insert([
                    'branch_demand_id' => $demandId,
                    'product_id'       => (int) $item['product_id'],
                    'qty'              => (float) $item['qty'],
                    'cost_rate'        => 0,
                    'from_warehouse_id' => null,
                    'to_warehouse_id'  => null,
                    'price_min'        => 0,
                    'price_max'        => 0,
                    'price_default'    => 0,
                    'notes'            => $item['notes'] ?? null,
                ]);
            }

            Log::info('BranchDemand created', [
                'demand_id'   => $demandId,
                'demand_code' => $demandCode,
                'from_branch' => $fromBranchId,
                'to_branch'   => $toBranchId,
                'items_count' => count($items),
                'created_by'  => $data['created_by'] ?? null,
            ]);

            return BranchDemand::find($demandId);
        });
    }

    // ===================== SEND =====================

    /**
     * Send goods for a branch demand — the core send flow.
     *
     * This is the most complex operation in the Branch Demand module:
     *   1. Validate demand is pending
     *   2. For each item: validate from_warehouse_id and to_warehouse_id
     *   3. Pipeline-aware availability check using StockAvailabilityService
     *   4. Lock cost_rate = source warehouse avg_cost
     *   5. Record price range (min, max, default) from product_price_history
     *   6. Stock OUT from supplier warehouse (reference_type = 'demand_send')
     *   7. Stock IN to requester warehouse (reference_type = 'demand_receive')
     *   8. Create documentary warehouse_transfers row
     *   9. Update branch_demand_items with from_warehouse_id, to_warehouse_id, cost_rate
     *  10. Update branch_demands with total_value, warehouse_transfer_id, status='received'
     *
     * @param int $demandId
     * @param array $items [ { id: int, from_warehouse_id: int, to_warehouse_id: int } ]
     * @param int $sentBy User ID who sent the goods
     * @return BranchDemand
     * @throws \RuntimeException If demand not pending or stock insufficient.
     * @throws \InvalidArgumentException If warehouse validation fails.
     */
    public function sendGoodsWithWarehouses(int $demandId, array $items, int $sentBy): BranchDemand
    {
        return DB::transaction(function () use ($demandId, $items, $sentBy) {
            // Lock the demand row
            $demand = DB::table('branch_demands')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                throw new \RuntimeException("Branch demand {$demandId} not found.");
            }

            if ($demand->status !== 'pending') {
                throw new \RuntimeException(
                    "Cannot send goods for demand #{$demandId}: status is '{$demand->status}', expected 'pending'."
                );
            }

            if ($demand->is_reversed) {
                throw new \RuntimeException("Cannot send goods for a reversed demand #{$demandId}.");
            }

            $fromBranchId = (int) $demand->from_branch_id; // requester (debtor)
            $toBranchId = (int) $demand->to_branch_id;     // supplier (creditor)

            // Load all demand items
            $demandItems = DB::table('branch_demand_items')
                ->where('branch_demand_id', $demandId)
                ->get()
                ->keyBy('id');

            // Validate items input
            $this->validateSendItems($items, $demandItems, $fromBranchId, $toBranchId);

            // Build the send plan: compute cost_rate, price range, check availability
            $sendPlan = $this->buildSendPlan($items, $demandItems, $toBranchId, $fromBranchId);

            // Execute stock movements
            $totalValue = 0.0;

            foreach ($sendPlan as $planItem) {
                $itemQty = (float) $planItem['qty'];
                $costRate = (float) $planItem['cost_rate'];
                $fromWarehouseId = (int) $planItem['from_warehouse_id'];
                $toWarehouseId = (int) $planItem['to_warehouse_id'];
                $productId = (int) $planItem['product_id'];
                $demandItemId = (int) $planItem['id'];

                // ★ Pipeline-aware availability check at source warehouse
                $available = $this->stockAvailabilityService->getWarehouseAvailableQty(
                    $productId, $fromWarehouseId
                );
                if ($itemQty > $available + self::QTY_TOLERANCE) {
                    $physical = $this->stockService->getWarehouseQty($fromWarehouseId, $productId);
                    $pipeline = $physical - $available;
                    throw new \RuntimeException(
                        "Insufficient available stock at source warehouse {$fromWarehouseId} for product {$productId}: "
                        . "available {$available} (physical {$physical}, pipeline {$pipeline}), "
                        . "requested {$itemQty}"
                    );
                }

                // Stock OUT from supplier warehouse (reference_type = 'demand_send')
                $this->stockService->applyTransaction([
                    'warehouse_id'          => $fromWarehouseId,
                    'product_id'            => $productId,
                    'qty'                   => -$itemQty, // negative = OUT
                    'rate'                  => $costRate,
                    'reference_type'        => 'demand_send',
                    'reference_id'          => $demandId,
                    'branch_demand_item_id' => $demandItemId,
                    'notes'                 => "Demand #{$demand->demand_code} send: OUT from warehouse {$fromWarehouseId}",
                    'transaction_date'      => $demand->demand_date,
                    'created_by'            => $sentBy,
                ]);

                // Stock IN to requester warehouse (reference_type = 'demand_receive')
                $this->stockService->applyTransaction([
                    'warehouse_id'          => $toWarehouseId,
                    'product_id'            => $productId,
                    'qty'                   => $itemQty, // positive = IN
                    'rate'                  => $costRate,
                    'reference_type'        => 'demand_receive',
                    'reference_id'          => $demandId,
                    'branch_demand_item_id' => $demandItemId,
                    'notes'                 => "Demand #{$demand->demand_code} send: IN to warehouse {$toWarehouseId}",
                    'transaction_date'      => $demand->demand_date,
                    'created_by'            => $sentBy,
                ]);

                $lineTotal = $itemQty * $costRate;
                $totalValue += $lineTotal;

                // Update demand item with warehouse selections, cost_rate, and price range
                DB::table('branch_demand_items')
                    ->where('id', $demandItemId)
                    ->update([
                        'from_warehouse_id' => $fromWarehouseId,
                        'to_warehouse_id'   => $toWarehouseId,
                        'cost_rate'         => $costRate,
                        'price_min'         => $planItem['price_min'],
                        'price_max'         => $planItem['price_max'],
                        'price_default'     => $planItem['price_default'],
                    ]);
            }

            // Create documentary warehouse_transfers row
            $warehouseTransferId = $this->createDocumentaryWarehouseTransfer(
                $demand, $sendPlan, $sentBy
            );

            // Update the demand header
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->update([
                    'status'                => 'received',
                    'total_value'           => round($totalValue, 2),
                    'warehouse_transfer_id' => $warehouseTransferId,
                    'updated_at'            => now(),
                ]);

            // ★ Phase 3 — Post intercompany GL journals and branch ledger
            $demandModel = BranchDemand::find($demandId);
            $this->intercompanyService->postDemandFulfillmentJournals($demandModel, $sentBy);

            Log::info('BranchDemand goods sent', [
                'demand_id'             => $demandId,
                'demand_code'           => $demand->demand_code,
                'total_value'           => $totalValue,
                'warehouse_transfer_id' => $warehouseTransferId,
                'items_count'           => count($sendPlan),
                'sent_by'               => $sentBy,
            ]);

            return BranchDemand::find($demandId);
        });
    }

    // ===================== REVERSE =====================

    /**
     * Reverse a sent/received demand — full reversal.
     *
     * This reverses ALL stock movements for the demand:
     *   1. Find all stock_transactions linked to this demand (demand_send + demand_receive)
     *   2. Reverse them in the correct order: demand_receive (IN) first, then demand_send (OUT)
     *      This prevents "insufficient stock at receiver" errors.
     *   3. Cancel the documentary warehouse_transfers row
     *   4. Mark demand as reversed
     *
     * NOTE: GL reversal and branch ledger reversal are handled in Phase 3
     * (BranchIntercompanyService). This service only handles stock reversal.
     *
     * @param int $demandId
     * @param string $reason
     * @param int $reversedBy User ID who performed the reversal
     * @return BranchDemand
     * @throws \RuntimeException If demand cannot be reversed.
     */
    public function reverseDemand(int $demandId, string $reason, int $reversedBy): BranchDemand
    {
        return DB::transaction(function () use ($demandId, $reason, $reversedBy) {
            // Lock the demand row
            $demand = DB::table('branch_demands')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                throw new \RuntimeException("Branch demand {$demandId} not found.");
            }

            if ($demand->status !== 'received') {
                throw new \RuntimeException(
                    "Cannot reverse demand #{$demandId}: status is '{$demand->status}', expected 'received'."
                );
            }

            if ($demand->is_reversed) {
                throw new \RuntimeException("Demand #{$demandId} is already reversed.");
            }

            // Find all stock transactions linked to this demand.
            // We need to reverse them in the correct order:
            //   demand_receive (IN) first → then demand_send (OUT)
            // This prevents "insufficient stock at receiver" errors.
            $receiveTransactions = DB::table('stock_transactions')
                ->where('reference_id', $demandId)
                ->where('reference_type', 'demand_receive')
                ->where('is_reversed', false)
                ->orderBy('id')
                ->get();

            $sendTransactions = DB::table('stock_transactions')
                ->where('reference_id', $demandId)
                ->where('reference_type', 'demand_send')
                ->where('is_reversed', false)
                ->orderBy('id')
                ->get();

            // Reverse demand_receive (IN) first — removes stock from requester
            foreach ($receiveTransactions as $st) {
                $this->stockService->reverseTransaction(
                    (int) $st->id,
                    $reversedBy,
                    "Reversal of demand #{$demand->demand_code}: {$reason}",
                    $demand->demand_date
                );
            }

            // Reverse demand_send (OUT) second — returns stock to supplier
            foreach ($sendTransactions as $st) {
                $this->stockService->reverseTransaction(
                    (int) $st->id,
                    $reversedBy,
                    "Reversal of demand #{$demand->demand_code}: {$reason}",
                    $demand->demand_date
                );
            }

            // Cancel the documentary warehouse_transfers row
            if ($demand->warehouse_transfer_id) {
                $this->cancelDocumentaryWarehouseTransfer(
                    (int) $demand->warehouse_transfer_id, $reversedBy, $reason
                );
            }

            // ★ Phase 3 — Reverse intercompany GL journals and branch ledger
            $demandModel = BranchDemand::find($demandId);
            $this->intercompanyService->reverseDemandJournals($demandModel, $reversedBy, $reason);
            $this->intercompanyService->reverseLedgerByReference(
                'demand_transfer',
                $demandId,
                $reversedBy,
                $reason,
                $demand->demand_date
            );

            // Update the demand header
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->update([
                    'status'         => 'reversed',
                    'is_reversed'    => true,
                    'reversed_at'    => now(),
                    'reversed_by'    => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at'     => now(),
                ]);

            Log::info('BranchDemand reversed', [
                'demand_id'            => $demandId,
                'demand_code'         => $demand->demand_code,
                'reversed_by'         => $reversedBy,
                'reason'              => $reason,
                'stock_reversed_count' => $receiveTransactions->count() + $sendTransactions->count(),
            ]);

            return BranchDemand::find($demandId);
        });
    }

    // ===================== DELETE =====================

    /**
     * Delete a pending demand (only if status='pending').
     *
     * Once goods have been sent (status='received'), the demand must be
     * reversed, not deleted. This prevents accidental data loss.
     *
     * @param int $demandId
     * @throws \RuntimeException If demand is not pending.
     */
    public function deleteDraftDemand(int $demandId): void
    {
        DB::transaction(function () use ($demandId) {
            $demand = DB::table('branch_demands')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                throw new \RuntimeException("Branch demand {$demandId} not found.");
            }

            if ($demand->status !== 'pending') {
                throw new \RuntimeException(
                    "Cannot delete demand #{$demandId}: status is '{$demand->status}'. Only pending demands can be deleted."
                );
            }

            // Delete items first (cascade)
            DB::table('branch_demand_items')
                ->where('branch_demand_id', $demandId)
                ->delete();

            // Delete the demand
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->delete();

            Log::info('BranchDemand deleted (draft)', [
                'demand_id'   => $demandId,
                'demand_code' => $demand->demand_code,
            ]);
        });
    }

    // ===================== REJECT =====================

    /**
     * Reject a pending demand.
     *
     * Only pending demands can be rejected. The supplier branch
     * uses this to decline a demand request.
     *
     * @param int $demandId
     * @param string $reason
     * @param int $rejectedBy User ID who rejected the demand
     * @return BranchDemand
     * @throws \RuntimeException If demand is not pending.
     */
    public function rejectDemand(int $demandId, string $reason, int $rejectedBy): BranchDemand
    {
        return DB::transaction(function () use ($demandId, $reason, $rejectedBy) {
            $demand = DB::table('branch_demands')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                throw new \RuntimeException("Branch demand {$demandId} not found.");
            }

            if ($demand->status !== 'pending') {
                throw new \RuntimeException(
                    "Cannot reject demand #{$demandId}: status is '{$demand->status}', expected 'pending'."
                );
            }

            DB::table('branch_demands')
                ->where('id', $demandId)
                ->update([
                    'status'     => 'rejected',
                    'notes'      => trim(($demand->notes ?? '') . "\n[Rejected: {$reason}]"),
                    'updated_at' => now(),
                ]);

            Log::info('BranchDemand rejected', [
                'demand_id'   => $demandId,
                'demand_code' => $demand->demand_code,
                'rejected_by' => $rejectedBy,
                'reason'      => $reason,
            ]);

            return BranchDemand::find($demandId);
        });
    }

    // ===================== PRIVATE HELPERS =====================

    /**
     * Validate the create demand input.
     */
    private function validateCreateInput(array $data, array $items): void
    {
        if (empty($data['from_branch_id']) || (int) $data['from_branch_id'] <= 0) {
            throw new \InvalidArgumentException('from_branch_id (requester) is required.');
        }
        if (empty($data['to_branch_id']) || (int) $data['to_branch_id'] <= 0) {
            throw new \InvalidArgumentException('to_branch_id (supplier) is required.');
        }
        if (empty($data['demand_date'])) {
            throw new \InvalidArgumentException('demand_date is required.');
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        foreach ($items as $i => $item) {
            if (empty($item['product_id']) || (int) $item['product_id'] <= 0) {
                throw new \InvalidArgumentException("Item {$i}: product_id is required.");
            }
            if (empty($item['qty']) || (float) $item['qty'] <= 0) {
                throw new \InvalidArgumentException("Item {$i}: qty must be positive.");
            }
        }
    }

    /**
     * Validate the send items input against the demand items.
     *
     * Ensures:
     *   - Each send item maps to a valid demand item
     *   - from_warehouse_id belongs to the supplier branch (to_branch_id)
     *   - to_warehouse_id belongs to the requester branch (from_branch_id)
     */
    private function validateSendItems(
        array $items,
        \Illuminate\Support\Collection $demandItems,
        int $fromBranchId,
        int $toBranchId
    ): void {
        // Collect all warehouse IDs for batch validation
        $fromWarehouseIds = collect($items)->pluck('from_warehouse_id')->unique()->filter()->toArray();
        $toWarehouseIds = collect($items)->pluck('to_warehouse_id')->unique()->filter()->toArray();
        $allWarehouseIds = array_unique(array_merge($fromWarehouseIds, $toWarehouseIds));

        $warehouses = DB::table('warehouses')
            ->whereIn('id', $allWarehouseIds)
            ->pluck('branch_id', 'id');

        foreach ($items as $i => $item) {
            $demandItemId = (int) ($item['id'] ?? 0);

            if (!$demandItems->has($demandItemId)) {
                throw new \InvalidArgumentException("Item {$i}: demand item {$demandItemId} not found in this demand.");
            }

            $fromWarehouseId = (int) ($item['from_warehouse_id'] ?? 0);
            $toWarehouseId = (int) ($item['to_warehouse_id'] ?? 0);

            if ($fromWarehouseId <= 0) {
                throw new \InvalidArgumentException("Item {$i}: from_warehouse_id is required.");
            }
            if ($toWarehouseId <= 0) {
                throw new \InvalidArgumentException("Item {$i}: to_warehouse_id is required.");
            }

            // from_warehouse_id must belong to the SUPPLIER branch (to_branch_id)
            // Stock moves OUT from the supplier's warehouse
            $fromWarehouseBranch = $warehouses[$fromWarehouseId] ?? null;
            if ($fromWarehouseBranch === null) {
                throw new \InvalidArgumentException("Item {$i}: from_warehouse_id {$fromWarehouseId} not found.");
            }
            if ((int) $fromWarehouseBranch !== $toBranchId) {
                throw new \InvalidArgumentException(
                    "Item {$i}: from_warehouse_id {$fromWarehouseId} does not belong to supplier branch {$toBranchId}."
                );
            }

            // to_warehouse_id must belong to the REQUESTER branch (from_branch_id)
            // Stock moves IN to the requester's warehouse
            $toWarehouseBranch = $warehouses[$toWarehouseId] ?? null;
            if ($toWarehouseBranch === null) {
                throw new \InvalidArgumentException("Item {$i}: to_warehouse_id {$toWarehouseId} not found.");
            }
            if ((int) $toWarehouseBranch !== $fromBranchId) {
                throw new \InvalidArgumentException(
                    "Item {$i}: to_warehouse_id {$toWarehouseId} does not belong to requester branch {$fromBranchId}."
                );
            }
        }
    }

    /**
     * Build the send plan: compute cost_rate, price range for each item.
     *
     * For each item:
     *   - cost_rate = source warehouse avg_cost (locked at send time)
     *   - price_min/max/default = from product_price_history (current effective range)
     */
    private function buildSendPlan(
        array $items,
        \Illuminate\Support\Collection $demandItems,
        int $supplierBranchId,
        int $requesterBranchId
    ): array {
        $plan = [];

        // Pre-load current price ranges for all products
        $productIds = $demandItems->pluck('product_id')->unique()->toArray();
        $priceRanges = $this->loadCurrentPriceRanges($productIds);

        foreach ($items as $item) {
            $demandItemId = (int) $item['id'];
            $demandItem = $demandItems[$demandItemId];

            $fromWarehouseId = (int) $item['from_warehouse_id'];
            $toWarehouseId = (int) $item['to_warehouse_id'];
            $productId = (int) $demandItem->product_id;

            // Lock cost_rate = source warehouse avg_cost
            $costRate = $this->stockService->getWarehouseAvgCost($fromWarehouseId, $productId);

            if ($costRate <= 0) {
                // Fallback: if no avg_cost recorded, use the product's default cost
                $product = DB::table('products')->where('id', $productId)->first();
                $costRate = $product ? (float) ($product->cost_price ?? 0) : 0;

                if ($costRate <= 0) {
                    Log::warning('BranchDemand: zero cost_rate for product', [
                        'product_id'        => $productId,
                        'from_warehouse_id' => $fromWarehouseId,
                        'demand_item_id'    => $demandItemId,
                    ]);
                }
            }

            // Get price range from product_price_history
            $priceRange = $priceRanges[$productId] ?? ['min' => 0, 'max' => 0, 'default' => 0];

            $plan[] = [
                'id'                => $demandItemId,
                'product_id'        => $productId,
                'qty'               => (float) $demandItem->qty,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id'   => $toWarehouseId,
                'cost_rate'         => $costRate,
                'price_min'         => $priceRange['min'],
                'price_max'         => $priceRange['max'],
                'price_default'     => $priceRange['default'],
            ];
        }

        return $plan;
    }

    /**
     * Load current effective price ranges for a set of products.
     *
     * Returns [product_id => ['min' => float, 'max' => float, 'default' => float]]
     */
    private function loadCurrentPriceRanges(array $productIds): array
    {
        $today = now()->format('Y-m-d');

        $rows = DB::table('product_price_history')
            ->whereIn('product_id', $productIds)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $today);
            })
            ->orderByDesc('effective_from')
            ->get();

        $ranges = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            // Take the most recent effective range for each product
            if (!isset($ranges[$pid])) {
                $ranges[$pid] = [
                    'min'     => (float) $row->min_rate,
                    'max'     => (float) $row->max_rate,
                    'default' => (float) $row->default_rate,
                ];
            }
        }

        return $ranges;
    }

    /**
     * Create a documentary warehouse_transfers row for the cross-branch demand.
     *
     * This creates a WT record that links the demand to the physical stock
     * movement. The WT is confirmed immediately (status='confirmed') since
     * the stock has already been moved by the demand send flow.
     *
     * The WT is cross-branch by definition (since Branch Demand is
     * cross-branch only). The same-branch enforcement in WarehouseTransfer
     * is bypassed because we create this row directly via DB::table(),
     * not through WarehouseTransferService.
     */
    private function createDocumentaryWarehouseTransfer(
        object $demand,
        array $sendPlan,
        int $sentBy
    ): int {
        // Use the first item's from_warehouse_id and to_warehouse_id for the WT header.
        // In a multi-item demand, these may differ per item — the WT is documentary.
        $firstItem = $sendPlan[0];
        $fromWarehouseId = (int) $firstItem['from_warehouse_id'];
        $toWarehouseId = (int) $firstItem['to_warehouse_id'];

        $transferCode = CodeGenerator::generate('warehouse_transfers', 'transfer_code', 'WT-');

        $wtId = DB::table('warehouse_transfers')->insertGetId([
            'transfer_code'     => $transferCode,
            'transfer_date'     => $demand->demand_date,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'from_branch_id'    => (int) $demand->to_branch_id,   // supplier
            'to_branch_id'      => (int) $demand->from_branch_id, // requester
            'is_interbranch'    => true,
            'branch_demand_id'  => (int) $demand->id,
            'status'            => 'confirmed',
            'is_reversed'       => false,
            'notes'             => "Documentary WT for Branch Demand #{$demand->demand_code}",
            'created_by'        => $sentBy,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Create WT items
        foreach ($sendPlan as $planItem) {
            DB::table('warehouse_transfer_items')->insert([
                'warehouse_transfer_id' => $wtId,
                'product_id'            => (int) $planItem['product_id'],
                'qty'                   => (float) $planItem['qty'],
                'rate'                  => (float) $planItem['cost_rate'],
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }

        return $wtId;
    }

    /**
     * Cancel the documentary warehouse_transfers row when a demand is reversed.
     *
     * The WT is marked as cancelled (not reversed through WarehouseTransferService
     * because that service enforces same-branch only). We directly update the
     * WT status since the actual stock reversal is handled by this service.
     */
    private function cancelDocumentaryWarehouseTransfer(
        int $warehouseTransferId,
        int $reversedBy,
        string $reason
    ): void {
        DB::table('warehouse_transfers')
            ->where('id', $warehouseTransferId)
            ->update([
                'status'         => 'cancelled',
                'is_reversed'    => true,
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => "Demand reversal: {$reason}",
                'updated_at'     => now(),
            ]);

        Log::info('Documentary WarehouseTransfer cancelled for demand reversal', [
            'warehouse_transfer_id' => $warehouseTransferId,
            'reversed_by'           => $reversedBy,
        ]);
    }
}
