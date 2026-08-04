<?php

namespace App\Services\Purchase;

use App\Services\Accounting\DocumentSequenceService;
use App\Services\Auth\UserAuditLogger;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Order Service — Phase 7.1.
 *
 * POs are draft documents — NO stock movement, NO GL journal.
 * The economic event is the GRN (Phase 7.2).
 *
 * Operations:
 *   - createOrder: create a draft PO (header + items)
 *   - updateOrder: edit a draft PO (only draft status)
 *   - cancelOrder: cancel a draft/sent PO
 *   - markAsSent: mark a draft PO as sent to supplier
 *   - updateReceivedQty: called by GRN (Phase 7.2) to update received_qty + auto-status
 *
 * PO code generation: PO-YYYYMMDD-NNNN using document_sequences
 * with advisory locks (Task 20 — replaces SELECT FOR UPDATE).
 */
class PurchaseOrderService
{
    /**
     * Create a draft purchase order.
     *
     * @param array $data {
     *     supplier_id: int,
     *     branch_id: int,
     *     warehouse_id: int|null,
     *     po_date: string (Y-m-d),
     *     expected_date: string|null,
     *     notes: string|null,
     *     discount_amount: float,
     *     tax_amount: float,
     *     created_by: int,
     *     items: array each { product_id, qty, rate }
     * }
     * @return PurchaseOrder
     */
    public function createOrder(array $data): PurchaseOrder
    {
        $this->validateCreateInput($data);

        $items = $this->validateItems($data['items']);
        $subTotal = collect($items)->sum(fn($i) => $i['qty'] * $i['rate']);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $tax = (float) ($data['tax_amount'] ?? 0);
        $total = $subTotal - $discount + $tax;

        $poCode = $this->generatePoCode();

        return DB::transaction(function () use ($data, $items, $subTotal, $discount, $tax, $total, $poCode) {
            $poId = DB::table('purchase_orders')->insertGetId([
                'po_code' => $poCode,
                'po_date' => $data['po_date'] ?? now()->format('Y-m-d'),
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'status' => 'draft',
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'received_qty' => 0,
                    'rate' => $item['rate'],
                ];
            }
            DB::table('purchase_order_items')->insert($itemRows);

            // PURCHASING-2 (G-034): manually fire the master_data audit since
            // DB::table()->insertGetId bypasses Eloquent events. The
            // AuditableMasterData trait's static::created listener would have
            // logged this row had we used PurchaseOrder::create($row).
            $poRow = [
                'po_code' => $poCode,
                'po_date' => $data['po_date'] ?? now()->format('Y-m-d'),
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'status' => 'draft',
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ];
            PurchaseOrder::logManualAudit('purchase_orders', $poId, 'created', null, $poRow);

            $po = PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])->find($poId);

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $data['created_by'] ?? null,
                action: 'purchase_order_created',
                targetUserId: $poId,
                details: [
                    'po_code'     => $poCode,
                    'branch_id'   => (int) ($data['branch_id'] ?? 0),
                    'supplier_id' => (int) ($data['supplier_id'] ?? 0),
                    'total'       => round($total, 2),
                    'item_count'  => count($items),
                ]
            );

            return $po;
        });
    }

    /**
     * Update a draft purchase order (only draft status can be edited).
     *
     * @param int $poId
     * @param array $data
     * @return PurchaseOrder
     * @throws \RuntimeException If PO is not draft.
     */
    public function updateOrder(int $poId, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($poId, $data) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->find($poId);

            if (!$po) {
                throw new \RuntimeException("Purchase order {$poId} not found.");
            }
            if (!$po->canEdit()) {
                throw new \RuntimeException("Only draft POs can be edited (current: {$po->status}).");
            }

            $this->validateCreateInput($data);
            $items = $this->validateItems($data['items']);
            $subTotal = collect($items)->sum(fn($i) => $i['qty'] * $i['rate']);
            $discount = (float) ($data['discount_amount'] ?? 0);
            $tax = (float) ($data['tax_amount'] ?? 0);
            $total = $subTotal - $discount + $tax;

            // Update header.
            $poUpdate = [
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'po_date' => $data['po_date'] ?? $po->po_date,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'updated_at' => now(),
            ];
            // PURCHASING-2 (G-034): capture old before update so we can log
            // the master_data audit row the AuditableMasterData trait would
            // have written had we used $po->update($poUpdate).
            $oldPo = (array) DB::table('purchase_orders')->where('id', $poId)->first();
            DB::table('purchase_orders')->where('id', $poId)->update($poUpdate);
            PurchaseOrder::logManualAudit(
                'purchase_orders', $poId, 'updated',
                array_intersect_key($oldPo, $poUpdate),
                $poUpdate
            );

            // Delete existing items + re-insert (simpler than diffing).
            DB::table('purchase_order_items')->where('purchase_order_id', $poId)->delete();
            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'received_qty' => 0,
                    'rate' => $item['rate'],
                ];
            }
            DB::table('purchase_order_items')->insert($itemRows);

            $po = PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])->find($poId);

            // Phase 6: audit log.
            UserAuditLogger::log(
                userId: $data['created_by'] ?? auth()->id(),
                action: 'purchase_order_updated',
                targetUserId: $poId,
                details: [
                    'po_code'     => $po->po_code,
                    'branch_id'   => (int) ($data['branch_id'] ?? 0),
                    'supplier_id' => (int) ($data['supplier_id'] ?? 0),
                    'total'       => round($total, 2),
                    'item_count'  => count($items),
                ]
            );

            return $po;
        });
    }

    /**
     * Mark a draft PO as sent to supplier.
     */
    public function markAsSent(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::find($poId);
        if (!$po) {
            throw new \RuntimeException("PO {$poId} not found.");
        }
        if (!$po->isDraft()) {
            throw new \RuntimeException("Only draft POs can be marked as sent (current: {$po->status}).");
        }

        // PURCHASING-2 (G-034): capture old + log manual master_data audit.
        $sentUpdate = ['status' => 'sent', 'updated_at' => now()];
        $oldPo = (array) DB::table('purchase_orders')->where('id', $poId)->first();
        DB::table('purchase_orders')->where('id', $poId)->update($sentUpdate);
        PurchaseOrder::logManualAudit(
            'purchase_orders', $poId, 'updated',
            array_intersect_key($oldPo, $sentUpdate),
            $sentUpdate
        );

        // Phase 6: audit log.
        UserAuditLogger::log(
            userId: auth()->id(),
            action: 'purchase_order_sent',
            targetUserId: $poId,
            details: [
                'po_code' => $po->po_code,
            ]
        );

        return PurchaseOrder::find($poId);
    }

    /**
     * Cancel a draft or sent PO.
     */
    public function cancelOrder(int $poId, int $cancelledBy, string $reason = ''): PurchaseOrder
    {
        $po = PurchaseOrder::find($poId);
        if (!$po) {
            throw new \RuntimeException("PO {$poId} not found.");
        }
        if (!$po->canCancel()) {
            throw new \RuntimeException("Only draft or sent POs can be cancelled (current: {$po->status}).");
        }

        // PURCHASING-2 (G-034): capture old + log manual master_data audit.
        $cancelUpdate = [
            'status' => 'cancelled',
            'notes' => trim(($po->notes ?? '') . "\n\n[Cancelled] " . $reason),
            'updated_at' => now(),
        ];
        $oldPo = (array) DB::table('purchase_orders')->where('id', $poId)->first();
        DB::table('purchase_orders')->where('id', $poId)->update($cancelUpdate);
        PurchaseOrder::logManualAudit(
            'purchase_orders', $poId, 'updated',
            array_intersect_key($oldPo, $cancelUpdate),
            $cancelUpdate
        );

        // Phase 6: audit log.
        UserAuditLogger::log(
            userId: $cancelledBy,
            action: 'purchase_order_cancelled',
            targetUserId: $poId,
            details: [
                'po_code' => $po->po_code,
                'reason'  => $reason,
            ]
        );

        return PurchaseOrder::find($poId);
    }

    /**
     * Update received_qty on a PO item (called by GRN in Phase 7.2).
     * Auto-updates PO status: partial if some received, received if all fully received.
     *
     * @param int $poId
     * @param int $productId
     * @param float $additionalReceivedQty
     * @return PurchaseOrder
     */
    public function updateReceivedQty(int $poId, int $productId, float $additionalReceivedQty): PurchaseOrder
    {
        return DB::transaction(function () use ($poId, $productId, $additionalReceivedQty) {
            $item = DB::table('purchase_order_items')
                ->where('purchase_order_id', $poId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                throw new \RuntimeException("PO item not found for PO {$poId}, product {$productId}.");
            }

            $newReceived = (float) $item->received_qty + $additionalReceivedQty;
            DB::table('purchase_order_items')
                ->where('id', $item->id)
                ->update(['received_qty' => $newReceived]);

            // Check if all items are fully received.
            $allItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $poId)
                ->get();

            $allReceived = $allItems->every(fn($i) => (float) $i->received_qty >= (float) $i->qty - 0.0001);
            $anyReceived = $allItems->some(fn($i) => (float) $i->received_qty > 0.0001);

            $newStatus = $allReceived ? 'received' : ($anyReceived ? 'partial' : null);

            if ($newStatus) {
                // PURCHASING-2 (G-034): log manual master_data audit for the
                // status flip driven by GRN receive / cancel.
                $statusUpdate = ['status' => $newStatus, 'updated_at' => now()];
                $oldPo = (array) DB::table('purchase_orders')->where('id', $poId)->first();
                DB::table('purchase_orders')
                    ->where('id', $poId)
                    ->update($statusUpdate);
                PurchaseOrder::logManualAudit(
                    'purchase_orders', $poId, 'updated',
                    array_intersect_key($oldPo, $statusUpdate),
                    $statusUpdate
                );
            }

            return PurchaseOrder::with(['items.product', 'supplier', 'branch'])->find($poId);
        });
    }

    /**
     * Generate atomic PO code: PO-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generatePoCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'purchase_order',
            prefix:   'PO',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    private function validateCreateInput(array $data): void
    {
        if (empty($data['supplier_id']) || (int) $data['supplier_id'] <= 0) {
            throw new \InvalidArgumentException('supplier_id is required.');
        }
        if (empty($data['branch_id']) || (int) $data['branch_id'] <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
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
            if ($productId <= 0 || $qty <= 0) continue;
            $validated[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'rate' => $rate,
            ];
        }
        if (empty($validated)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }
        return $validated;
    }
}
