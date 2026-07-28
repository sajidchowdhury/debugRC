<?php

namespace App\Services\Stock;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Adjustment Service — Phase 6.3 + Phase 3 (approval workflow)
 *                            + Phase 4 (dedicated audit log).
 *
 * Phase 3 lifecycle (maker-checker):
 *   1. createAdjustment(): creates header + items (status=draft). NO stock movement. NO GL.
 *   2. submitAdjustment(): draft → submitted (accountant submits for approval).
 *      If !requiresApproval(), auto-advances to 'approved' inline.
 *   3. approveAdjustment(): submitted → approved (admin/manager approves).
 *      Segregation of duties: approved_by !== submitted_by.
 *   4. rejectAdjustment(): submitted → draft (rejected with a comment).
 *   5. confirmAdjustment(): approved (or draft when !requiresApproval) → confirmed.
 *      Applies stock via StockService + posts GL journal. Sets confirmed_by/at/confirm_reason (G9).
 *   6. cancelAdjustment(): any non-terminal → cancelled. If confirmed, reverses
 *      stock + GL. ALWAYS stores cancel_reason (G15).
 *
 * GL posting rules (re-derived from double-entry principles):
 *   - Increase (stock goes UP): Dr Inventory / Cr Inventory Surplus (gain)
 *   - Decrease (stock goes DOWN): Dr Inventory Shrinkage (loss) / Cr Inventory
 *
 * All operations are wrapped in DB::transaction() — if GL posting fails,
 * the stock movement rolls back too (atomicity contract from avg_cost_rule.md §4).
 *
 * Phase 4 (audit log): every lifecycle method writes exactly one
 * stock_adjustment_audit_log row via StockAdjustmentAuditLogger, inside the
 * same DB::transaction as the data change — so a rolled-back confirm also
 * rolls back its audit row. This replaces the dead AuditableMasterData
 * trait on the model (which never fired because this service writes via
 * DB::table(), bypassing Eloquent model events).
 */
class StockAdjustmentService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private StockAdjustmentPolicyService $policy,
        private StockAdjustmentAuditLogger $audit
    ) {}

    /**
     * Phase 1: Create a draft stock adjustment (no stock movement, no GL).
     *
     * @param array $data {
     *     warehouse_id: int,
     *     adjustment_type: 'increase'|'decrease',
     *     adjustment_category: string (Phase 2 — one of StockAdjustment::ADJUSTMENT_CATEGORIES),
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
        $adjustmentCategory = $data['adjustment_category']; // Phase 2 — validated above
        $items = $data['items'];

        // Look up the branch from the warehouse.
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        if (!$warehouse) {
            throw new \InvalidArgumentException("Warehouse {$warehouseId} not found.");
        }
        $branchId = (int) $warehouse->branch_id;

        // Phase 3 — block back-dating into a closed accounting period.
        $adjustmentDate = $data['adjustment_date'] ?? now()->format('Y-m-d');
        if ($this->policy->isWithinClosedPeriod($branchId, $adjustmentDate)) {
            throw new \InvalidArgumentException(
                "Adjustment date {$adjustmentDate} falls inside a closed accounting period for this branch."
            );
        }

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
            $adjustmentCategory, $adjustmentDate, $totalAmount, $data, $validatedItems
        ) {
            // Create the adjustment header.
            $adjustmentId = DB::table('stock_adjustments')->insertGetId([
                'adjustment_code' => $adjustmentCode,
                'adjustment_date' => $adjustmentDate,
                'warehouse_id' => $warehouseId,
                'branch_id' => $branchId,
                'adjustment_type' => $adjustmentType,
                'adjustment_category' => $adjustmentCategory, // Phase 2
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

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch')->find($adjustmentId);

            // Phase 4 — write one audit row for the create action, inside
            // this transaction so it commits/rolls back with the data change.
            $this->audit->log($adjustment, 'create', [
                'adjustment_code'    => $adjustmentCode,
                'adjustment_type'    => $adjustmentType,
                'adjustment_category'=> $adjustmentCategory,
                'warehouse_id'       => $warehouseId,
                'total_amount'       => round($totalAmount, 2),
                'items_count'        => count($validatedItems),
            ]);

            return $adjustment;
        });
    }

    /**
     * Phase 3: Submit a draft adjustment for approval (draft → submitted).
     *
     * Validates the caller's role via the policy. Sets submitted_by/at and
     * appends the optional comment to approval_comments. If the policy says
     * this adjustment does NOT require approval (below auto-approve threshold,
     * or gate off and below force-approve threshold), the adjustment is
     * auto-advanced to 'approved' inline so the drafter can confirm next.
     *
     * @param int         $adjustmentId
     * @param int         $userId   The submitting user's id.
     * @param string|null $comment  Optional submit note.
     * @return StockAdjustment
     * @throws \RuntimeException  If not draft, or the user lacks the submit role.
     */
    public function submitAdjustment(int $adjustmentId, int $userId, ?string $comment = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $userId, $comment) {
            $adjustment = StockAdjustment::lockForUpdate()->find($adjustmentId);
            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if (!$adjustment->isDraft()) {
                throw new \RuntimeException(
                    "Only draft adjustments can be submitted (current status: {$adjustment->status})."
                );
            }

            $user = User::find($userId);
            if (!$user || !$this->policy->canSubmit($user)) {
                throw new \RuntimeException('You do not have permission to submit stock adjustments for approval.');
            }

            $now = now();
            $commentLine = $this->appendComment(
                $adjustment->approval_comments,
                'SUBMITTED by user #' . $userId . ($comment ? ': ' . trim($comment) : '')
            );

            // Decide whether to auto-advance to 'approved'.
            $requiresApproval = $this->policy->requiresApproval($adjustment);

            if (!$requiresApproval) {
                // Auto-approve inline — below threshold / gate off. The
                // submitter IS the approver here (system-mediated), so the
                // segregation-of-duties check is bypassed by design.
                DB::table('stock_adjustments')
                    ->where('id', $adjustmentId)
                    ->update([
                        'status'             => 'approved',
                        'submitted_by'       => $userId,
                        'submitted_at'       => $now,
                        'approved_by'        => $userId,
                        'approved_at'        => $now,
                        'approval_comments'  => $commentLine . "\n[AUTO-APPROVED — below threshold]",
                        'updated_at'         => $now,
                    ]);
            } else {
                DB::table('stock_adjustments')
                    ->where('id', $adjustmentId)
                    ->update([
                        'status'             => 'submitted',
                        'submitted_by'       => $userId,
                        'submitted_at'       => $now,
                        'approval_comments'  => $commentLine,
                        'updated_at'         => $now,
                    ]);
            }

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch')->find($adjustmentId);

            // Phase 4 — one audit row for the submit action. When the policy
            // auto-advanced to 'approved' (below threshold), record that in
            // the payload so the timeline shows the auto-approval — we do
            // NOT write a separate 'approve' row because no human approver
            // acted (the system mediated the auto-approval).
            $this->audit->log($adjustment, 'submit', [
                'comment'            => $comment,
                'auto_approved'      => !$requiresApproval,
                'requires_approval'  => $requiresApproval,
            ]);

            return $adjustment;
        });
    }

    /**
     * Phase 3: Approve a submitted adjustment (submitted → approved).
     *
     * Validates the caller's role via the policy. Enforces segregation of
     * duties: the approver CANNOT be the same user who submitted. Sets
     * approved_by/at and appends the (required) comment to approval_comments.
     *
     * @param int    $adjustmentId
     * @param int    $userId   The approving user's id.
     * @param string $comment  Required approval note.
     * @return StockAdjustment
     * @throws \RuntimeException  If not submitted, lacks approve role, or self-approves.
     */
    public function approveAdjustment(int $adjustmentId, int $userId, string $comment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $userId, $comment) {
            $adjustment = StockAdjustment::lockForUpdate()->find($adjustmentId);
            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if (!$adjustment->isSubmitted()) {
                throw new \RuntimeException(
                    "Only submitted adjustments can be approved (current status: {$adjustment->status})."
                );
            }

            $user = User::find($userId);
            if (!$user || !$this->policy->canApprove($user)) {
                throw new \RuntimeException('You do not have permission to approve stock adjustments.');
            }

            // Segregation of duties: the submitter cannot approve their own submission.
            if ($this->policy->isSubmitter($user, $adjustment)) {
                throw new \RuntimeException(
                    'Segregation of duties: you cannot approve an adjustment you submitted.'
                );
            }

            $comment = trim($comment);
            if ($comment === '') {
                throw new \RuntimeException('An approval comment is required.');
            }

            $commentLine = $this->appendComment(
                $adjustment->approval_comments,
                'APPROVED by user #' . $userId . ': ' . $comment
            );

            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status'             => 'approved',
                    'approved_by'        => $userId,
                    'approved_at'        => now(),
                    'approval_comments'  => $commentLine,
                    'updated_at'         => now(),
                ]);

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch')->find($adjustmentId);

            // Phase 4 — one audit row for the approve action.
            $this->audit->log($adjustment, 'approve', [
                'comment' => $comment,
            ]);

            return $adjustment;
        });
    }

    /**
     * Phase 3: Reject a submitted adjustment (submitted → draft).
     *
     * The adjustment returns to 'draft' so the drafter can revise and
     * re-submit. The (required) rejection reason is appended to
     * approval_comments with a [REJECTED] marker so it is visible in the
     * audit trail and the show page. submitted_by/at are preserved (the
     * submission happened); approved_by/at are cleared.
     *
     * @param int    $adjustmentId
     * @param int    $userId   The rejecting user's id (must be an approver).
     * @param string $comment  Required rejection reason.
     * @return StockAdjustment
     * @throws \RuntimeException  If not submitted, or lacks approve role.
     */
    public function rejectAdjustment(int $adjustmentId, int $userId, string $comment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $userId, $comment) {
            $adjustment = StockAdjustment::lockForUpdate()->find($adjustmentId);
            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if (!$adjustment->isSubmitted()) {
                throw new \RuntimeException(
                    "Only submitted adjustments can be rejected (current status: {$adjustment->status})."
                );
            }

            $user = User::find($userId);
            if (!$user || !$this->policy->canApprove($user)) {
                throw new \RuntimeException('You do not have permission to reject stock adjustments.');
            }

            $comment = trim($comment);
            if ($comment === '') {
                throw new \RuntimeException('A rejection reason is required.');
            }

            $commentLine = $this->appendComment(
                $adjustment->approval_comments,
                '[REJECTED] by user #' . $userId . ': ' . $comment
            );

            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status'             => 'draft',
                    // Clear any prior approved_by/at (there shouldn't be any on
                    // a submitted row, but defensive).
                    'approved_by'        => null,
                    'approved_at'        => null,
                    'approval_comments'  => $commentLine,
                    'updated_at'         => now(),
                ]);

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch')->find($adjustmentId);

            // Phase 4 — one audit row for the reject action.
            $this->audit->log($adjustment, 'reject', [
                'comment' => $comment,
            ]);

            return $adjustment;
        });
    }

    /**
     * Phase 3: Confirm an adjustment — apply stock movements + post GL journal.
     *
     * Requires status = 'approved' (or 'draft' when the policy says this
     * adjustment does not need approval — one-step confirm). Sets
     * confirmed_by/at + confirm_reason (G9 fix — the posting action is now
     * attributed and the previously-discarded confirm_reason is persisted).
     *
     * @param int         $adjustmentId
     * @param int         $confirmedBy
     * @param string|null $confirmReason  Optional note from the confirmer.
     * @return StockAdjustment
     * @throws \RuntimeException  If not confirmable, or stock/GL posting fails.
     */
    public function confirmAdjustment(int $adjustmentId, int $confirmedBy, ?string $confirmReason = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $confirmedBy, $confirmReason) {
            $adjustment = StockAdjustment::with('items')->lockForUpdate()->find($adjustmentId);

            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }

            $requiresApproval = $this->policy->requiresApproval($adjustment);
            if (!$adjustment->canBeConfirmed($requiresApproval)) {
                throw new \RuntimeException(
                    "Adjustment cannot be confirmed from status '{$adjustment->status}'."
                    . ($requiresApproval
                        ? ' It must be submitted and approved first.'
                        : '')
                );
            }

            $warehouseId = $adjustment->warehouse_id;
            $sign = $adjustment->isIncrease() ? 1 : -1;

            // Phase 2 (G17 fix): opening-balance adjustments write their
            // stock_transactions rows with reference_type='opening_balance'
            // instead of the generic 'stock_adjustment'. This lets the
            // immutable ledger distinguish initial-onboarding stock from
            // later operational corrections (and powers opening-balance
            // reports / reconciliation queries). All other categories keep
            // reference_type='stock_adjustment'.
            //
            // 'opening_balance' is already in StockTransaction::REFERENCE_TYPES
            // (and the DB CHECK constraint — see migration
            // 2025_07_26_000002_add_reversal_to_stock_transactions_reference_type_check),
            // so no whitelist change is needed.
            $referenceType = $adjustment->ledgerReferenceType();

            // Apply stock movement for each item via StockService.
            foreach ($adjustment->items as $item) {
                $qtyChange = $sign * (float) $item->qty;

                $this->stockService->applyTransaction([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'qty' => $qtyChange,
                    'rate' => (float) $item->rate,
                    'reference_type' => $referenceType,
                    'reference_id' => $adjustment->id,
                    'notes' => 'Stock Adjustment #' . $adjustment->adjustment_code
                        . ($item->reason ? ' — ' . $item->reason : ''),
                    'transaction_date' => $adjustment->adjustment_date->format('Y-m-d'),
                    'created_by' => $confirmedBy,
                ]);
            }

            // Post the GL journal entry.
            $journalEntryId = $this->postAdjustmentGL($adjustment, $confirmedBy);

            // Update the adjustment status + journal_entry_id + Phase 3 attribution.
            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status'          => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'confirmed_by'    => $confirmedBy,   // G9
                    'confirmed_at'    => now(),           // G9
                    'confirm_reason'  => $confirmReason ? trim($confirmReason) : null, // G9
                    'updated_at'      => now(),
                ]);

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch', 'journalEntry')
                ->find($adjustmentId);

            // Phase 4 — one audit row for the confirm action (the high-impact
            // transition: stock moved + GL posted). Captures the journal
            // entry id + stock-ledger reference_type so the audit row is
            // self-contained for forensic review.
            $this->audit->log($adjustment, 'confirm', [
                'confirm_reason'   => $confirmReason ? trim($confirmReason) : null,
                'journal_entry_id' => $journalEntryId,
                'total_amount'     => (float) $adjustment->total_amount,
                'items_count'      => $adjustment->items->count(),
                'reference_type'   => $referenceType,
            ]);

            return $adjustment;
        });
    }

    /**
     * Phase 3: Cancel an adjustment.
     * - If confirmed: reverse stock movements + reverse GL journal. status → cancelled.
     * - If draft/submitted/approved: just mark as cancelled (no stock/GL to reverse).
     *
     * G15 fix: cancel_reason is ALWAYS persisted, regardless of the prior
     * status. (Previously only confirmed cancels stored the reason — in
     * reverse_reason — and draft cancels silently discarded it.) For a
     * confirmed-cancel, reverse_reason is ALSO populated (it records why
     * the stock+GL reversal happened); for a non-confirmed cancel, only
     * cancel_reason is set.
     *
     * @param int    $adjustmentId
     * @param int    $cancelledBy
     * @param string $reason  Required cancel reason.
     * @return StockAdjustment
     * @throws \RuntimeException If already cancelled, or reason is empty.
     */
    public function cancelAdjustment(int $adjustmentId, int $cancelledBy, string $reason = ''): StockAdjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A cancel reason is required.');
        }

        return DB::transaction(function () use ($adjustmentId, $cancelledBy, $reason) {
            $adjustment = StockAdjustment::with('items')->lockForUpdate()->find($adjustmentId);

            if (!$adjustment) {
                throw new \RuntimeException("Stock adjustment {$adjustmentId} not found.");
            }
            if ($adjustment->isCancelled()) {
                throw new \RuntimeException("Adjustment is already cancelled.");
            }
            // Confirmed adjustments can be cancelled (reversed). Draft /
            // submitted / approved adjustments can be cancelled (abandoned).
            // No other terminal states exist.

            // Phase 4 — capture the pre-cancel status so the audit payload
            // records whether stock+GL was reversed (confirmed-cancel) or the
            // adjustment was simply abandoned (draft/submitted/approved).
            $priorStatus  = $adjustment->status;
            $wasConfirmed = $adjustment->isConfirmed();

            if ($wasConfirmed) {
                // Reverse the GL journal entry.
                if ($adjustment->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $adjustment->journal_entry_id,
                        $cancelledBy,
                        "Stock adjustment cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement.
                //
                // Phase 2 (G17 fix): an opening-balance adjustment's stock
                // movements were written with reference_type='opening_balance',
                // not 'stock_adjustment'. Look up by reference_id alone (and
                // restrict to the two reference_types this module can produce)
                // so cancellations work regardless of category.
                foreach ($adjustment->items as $item) {
                    $stockTx = DB::table('stock_transactions')
                        ->whereIn('reference_type', ['stock_adjustment', 'opening_balance'])
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

            // Set status to cancelled + ALWAYS store cancel_reason (G15).
            // For a confirmed-cancel, reverse_reason was already set above;
            // cancel_reason carries the same text (dedicated column for the
            // "why was this cancelled" question, populated on EVERY cancel).
            DB::table('stock_adjustments')
                ->where('id', $adjustmentId)
                ->update([
                    'status'        => 'cancelled',
                    'cancel_reason' => $reason, // G15 — always populated
                    'updated_at'    => now(),
                ]);

            $adjustment = StockAdjustment::with('items.product', 'warehouse.branch')
                ->find($adjustmentId);

            // Phase 4 — one audit row for the cancel action. 'reversed' in
            // the payload records whether stock+GL was rolled back (a
            // confirmed-cancel). We do NOT write a separate 'reverse' row;
            // the 'reverse' action vocab is reserved for a future explicit
            // un-cancel/reverse flow (Phase 6).
            $this->audit->log($adjustment, 'cancel', [
                'cancel_reason' => $reason,
                'reversed'      => $wasConfirmed,
                'prior_status'  => $priorStatus,
            ]);

            return $adjustment;
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
     *
     * Phase 2: adjustment_category is now REQUIRED and must be one of the
     * seven canonical categories (StockAdjustment::ADJUSTMENT_CATEGORIES).
     * The DB CHECK constraint (sa_category_check) is the backstop; this
     * front-line check gives a clean error message before any DB write.
     */
    private function validateCreateInput(array $data): void
    {
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (!in_array($data['adjustment_type'] ?? '', ['increase', 'decrease'], true)) {
            throw new \InvalidArgumentException('adjustment_type must be "increase" or "decrease".');
        }
        // Phase 2 — category is mandatory.
        $category = $data['adjustment_category'] ?? '';
        if (!in_array($category, StockAdjustment::ADJUSTMENT_CATEGORIES, true)) {
            throw new \InvalidArgumentException(
                'adjustment_category is required and must be one of: '
                . implode(', ', StockAdjustment::ADJUSTMENT_CATEGORIES) . '.'
            );
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
    }

    /**
     * Phase 3 — append a timestamped line to the approval_comments trail.
     *
     * Each submit / approve / reject / auto-approve event appends one line
     * so the full maker-checker history is visible in a single column.
     * Kept as a private helper so the format is consistent across the
     * three lifecycle methods.
     */
    private function appendComment(?string $existing, string $line): string
    {
        $ts = now()->format('Y-m-d H:i');
        $newLine = '[' . $ts . '] ' . $line;
        if ($existing && trim($existing) !== '') {
            return $existing . "\n" . $newLine;
        }
        return $newLine;
    }
}
