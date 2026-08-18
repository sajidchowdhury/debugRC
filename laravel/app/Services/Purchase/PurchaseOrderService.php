<?php

namespace App\Services\Purchase;

use App\Services\Accounting\DocumentSequenceService;
use App\Services\Auth\UserAuditLogger;
use App\Services\Approval\ApprovalService;
use App\Models\PurchaseOrder;
use App\Support\FiscalYearResolver;
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
 *
 * PURCHASING-API-2 (G-116): ApprovalService integration. POs above the
 * configured threshold (config('purchase.approval_threshold'), default
 * 50000 BDT) require manager approval before they can be marked sent.
 * POs below the threshold are auto-approved (no approval needed) and can
 * be marked sent directly from draft — backward-compatible with the
 * pre-approval flow.
 */
class PurchaseOrderService
{
    public function __construct(
        private ApprovalService $approvalService
    ) {}
    /**
     * Create a draft purchase order.
     *
     * @param array $data {
     *     supplier_id: int,
     *     branch_id: int,
     *     warehouse_id: int,  (PURCHASING-API-2 G-123: now REQUIRED, not nullable)
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
                // PURCHASING-API-2 (G-123/G-124): warehouse_id is now NOT NULL.
                // validateCreateInput() enforces this; the cast is defensive.
                'warehouse_id' => (int) $data['warehouse_id'],
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => round($total, 2),
                'status' => 'draft',
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'fiscal_year_id' => FiscalYearResolver::activeId(),
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
                    'fiscal_year_id' => FiscalYearResolver::activeId(),
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
                // PURCHASING-API-2 (G-123/G-124): warehouse_id now NOT NULL.
                'warehouse_id' => (int) $data['warehouse_id'],
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
                // PURCHASING-API-2 (G-123/G-124): warehouse_id now NOT NULL.
                'warehouse_id' => (int) $data['warehouse_id'],
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
                    'fiscal_year_id' => FiscalYearResolver::activeId(),
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
     * Submit a draft (or rejected) PO for maker-checker approval.
     *
     * PURCHASING-API-2 (G-116): delegates to the generic ApprovalService
     * engine. If a workflow applies (total_amount >= the seeded
     * approval_workflows.min_amount), an approval_requests row is created
     * and the PO status is set to 'submitted' — the approver's queue at
     * /admin/approvals picks it up automatically. If no workflow applies
     * (total_amount < threshold), the PO is auto-approved and stays in
     * 'draft' (can be marked sent directly).
     *
     * Mirrors ManualJournalController::submitForApproval (L237-265) — the
     * controller calls this service method, which wraps ApprovalService.
     *
     * @return array {
     *     auto_approved: bool,   — true if no workflow applied (PO stays draft)
     *     workflow: ?ApprovalWorkflow,
     *     request: ?ApprovalRequest,  — the created pending request (if any)
     *     already_submitted: bool     — true if a pending request already existed
     * }
     */
    public function submitForApproval(int $poId): array
    {
        $po = PurchaseOrder::find($poId);
        if (!$po) {
            throw new \RuntimeException("PO {$poId} not found.");
        }
        if (!$po->canBeSubmitted()) {
            throw new \RuntimeException(
                "Only draft or rejected POs can be submitted for approval (current: {$po->status})."
            );
        }

        $result = $this->approvalService->submitForApproval(
            'purchase_order',
            $poId,
            (float) $po->total_amount,
            $po->branch_id
        );

        if ($result['auto_approved']) {
            // No workflow applies — auto-approve. Stamp the PO as approved
            // so canBeSent() returns true via isApproved(). (canBeSent also
            // accepts isDraft, but stamping approved makes the intent clear
            // and matches ManualJournalController::submitForApproval L253-257.)
            $po->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            UserAuditLogger::log(
                userId: auth()->id(),
                action: 'purchase_order_auto_approved',
                targetUserId: $poId,
                details: [
                    'po_code'   => $po->po_code,
                    'total'     => (float) $po->total_amount,
                    'threshold' => (float) config('purchase.approval_threshold', 50000),
                ]
            );
        } else {
            UserAuditLogger::log(
                userId: auth()->id(),
                action: 'purchase_order_submitted',
                targetUserId: $poId,
                details: [
                    'po_code'      => $po->po_code,
                    'total'        => (float) $po->total_amount,
                    'workflow_id'  => $result['workflow']?->id,
                    'request_id'   => $result['request']?->id,
                ]
            );
        }

        return $result;
    }

    /**
     * Mark a draft or approved PO as sent to supplier.
     *
     * PURCHASING-API-2 (G-116): the gate is now `canBeSent()` instead of
     * `isDraft()`. If an approval workflow applied, the PO must be
     * `approved` first. If no workflow applied (auto-approved, e.g.
     * total_amount < threshold), a `draft` PO can be marked sent directly —
     * backward-compatible with the pre-approval flow. Mirrors
     * ManualJournal::canBePosted().
     */
    public function markAsSent(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::find($poId);
        if (!$po) {
            throw new \RuntimeException("PO {$poId} not found.");
        }
        if (!$po->canBeSent()) {
            throw new \RuntimeException(
                "Only draft or approved POs can be marked as sent (current: {$po->status}). "
                . "If the PO is pending approval, wait for the approver. If rejected, edit + resubmit."
            );
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
     * Cancel a draft, submitted, approved, or sent PO.
     *
     * PURCHASING-API-2 (G-116): canCancel() expanded to include submitted +
     * approved states. If the PO has a pending approval request, it is
     * cancelled too (so the approver doesn't see a stale request).
     */
    public function cancelOrder(int $poId, int $cancelledBy, string $reason = ''): PurchaseOrder
    {
        $po = PurchaseOrder::find($poId);
        if (!$po) {
            throw new \RuntimeException("PO {$poId} not found.");
        }
        if (!$po->canCancel()) {
            throw new \RuntimeException("Only draft, submitted, approved, or sent POs can be cancelled (current: {$po->status}).");
        }

        // PURCHASING-API-2 (G-116): cancel any pending approval request so
        // the approver's queue doesn't show a stale entry. The approval_requests
        // row is marked 'cancelled' (not deleted — audit trail preserved).
        $pendingApproval = \App\Models\ApprovalRequest::where('entity_type', 'purchase_order')
            ->where('entity_id', $poId)
            ->where('status', 'pending')
            ->first();
        if ($pendingApproval) {
            $this->approvalService->cancel($pendingApproval);
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
     * PURCHASING-3 (G-037): signature changed to accept purchase_order_item_id
     * instead of (poId, productId). The old signature located the PO item via
     * `where('purchase_order_id', $poId)->where('product_id', $productId)->first()`,
     * which silently credited the FIRST matching line when a PO had duplicate
     * products on multiple lines — leaving the other line at received_qty=0 and
     * producing wrong PO status flips. The GRN item already carries
     * `purchase_order_item_id` (FK to purchase_order_items.id), so we lookup
     * directly by PK — unambiguous even with duplicate products.
     *
     * PURCHASING-3 (G-038): over-receive guard added. Throws if the cumulative
     * received_qty would exceed the ordered qty (with a 0.0001 tolerance for
     * floating-point noise). The audit checklist detects over-receives after
     * the fact; this guard PREVENTS them at the service boundary.
     *
     * @param int   $poItemId            purchase_order_items.id (FK from GRN item)
     * @param float $additionalReceivedQty qty being received now (positive)
     * @return PurchaseOrder
     * @throws \RuntimeException If PO item not found, or over-receive guard trips.
     */
    public function updateReceivedQty(int $poItemId, float $additionalReceivedQty): PurchaseOrder
    {
        return DB::transaction(function () use ($poItemId, $additionalReceivedQty) {
            $item = DB::table('purchase_order_items')
                ->where('id', $poItemId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                throw new \RuntimeException("PO item not found for po_item_id {$poItemId}.");
            }

            $poId = (int) $item->purchase_order_id;

            // PURCHASING-3 (G-038): over-receive guard.
            // PURCHASING-API-1 (G-118): tolerance now read from config/purchase.php.
            $overReceiveTolerance = (float) config('purchase.over_receive_tolerance', 0.0001);
            $newReceived = (float) $item->received_qty + $additionalReceivedQty;
            $orderedQty = (float) $item->qty;
            if ($newReceived > $orderedQty + $overReceiveTolerance) {
                throw new \RuntimeException(
                    "Over-receive guard tripped for PO item {$poItemId}: "
                    . "ordered {$orderedQty}, already received {$item->received_qty}, "
                    . "attempting to add {$additionalReceivedQty} → total {$newReceived} "
                    . "exceeds ordered by " . round($newReceived - $orderedQty, 4) . "."
                );
            }

            DB::table('purchase_order_items')
                ->where('id', $item->id)
                ->update(['received_qty' => $newReceived]);

            // Check if all items are fully received.
            $allItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $poId)
                ->get();

            // PURCHASING-API-1 (G-118): status-flip tolerance now read from config/purchase.php.
            $statusTolerance = (float) config('purchase.below_tolerance_status_threshold', 0.0001);
            $allReceived = $allItems->every(fn($i) => (float) $i->received_qty >= (float) $i->qty - $statusTolerance);
            $anyReceived = $allItems->some(fn($i) => (float) $i->received_qty > $statusTolerance);

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
     * Generate atomic PO code: <prefix>-YYYYMMDD-<padded-seq>.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     *
     * PURCHASING-API-1 (G-118): prefix + pad length + docType are now read
     * from `config/purchase.php` (env-overridable). Previously hardcoded
     * as 'PO' / 4 / 'purchase_order'.
     */
    private function generatePoCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  config('purchase.po_doc_type', 'purchase_order'),
            prefix:   config('purchase.po_prefix', 'PO'),
            datePart: now()->format('Ymd'),
            padLength: (int) config('purchase.po_code_pad_length', 4),
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
        // PURCHASING-API-2 (G-123/G-124): warehouse_id is now required.
        // Previously nullable at the schema level (mismatch with GRN which
        // is NOT NULL). The migration set the column NOT NULL; this service
        // guard fails fast before the INSERT hits the DB constraint.
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
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
