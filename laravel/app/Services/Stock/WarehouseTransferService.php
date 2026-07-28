<?php

namespace App\Services\Stock;

use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\WarehouseTransferAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Service — Phase 6.5 + Phase 1 + Phase 2 + Phase 3 + Phase 4.
 *
 * Two-phase flow:
 *   1. createTransfer(): creates draft (header + items, no stock/GL)
 *   2. confirmTransfer(): applies stock (source OUT + dest IN) + posts GL
 *   3. cancelTransfer(): if confirmed, reverses; if draft, marks cancelled
 *
 * SAME-BRANCH ONLY — Phase 1 enforcement:
 *   This module ONLY handles same-branch (inner-branch) transfers.
 *   Cross-branch transfers MUST go through the Branch Demand module.
 *
 * PIPELINE-AWARE AVAILABILITY — Phase 2 enforcement:
 *   Both create and confirm use StockAvailabilityService which subtracts
 *   the sales pipeline (open invoice dispatches) from physical qty.
 *   This prevents over-commitment: if 100 units are physical but 30 are
 *   already reserved by pending sales invoices, only 70 are available
 *   for transfer. Without this, transfers + sales could compete for
 *   the same physical stock, causing shortages on challan delivery.
 *
 * REVERSAL SAFETY — Phase 3 enforcement:
 *   When cancelling a confirmed transfer, stock movements are reversed in
 *   the correct order: dest IN (positive qty) reversed FIRST, then source
 *   OUT (negative qty). This prevents "insufficient stock at receiver"
 *   errors — if the dest warehouse only has 5 units and we reverse the
 *   source OUT first (adding 10 back to source), the dest would need to
 *   give back 10 but only has 5. By reversing dest IN first (removing 10
 *   from dest → dest goes from 5 to -5 which is caught by the tolerance
 *   check), we ensure stock integrity.
 *   Also: demand-linked transfers cannot be cancelled via this module;
 *   warehouse frozen-for-count blocks draft creation.
 *
 * AUDIT TRAIL — Phase 4 enforcement:
 *   Every transfer create/confirm/cancel logs a user_audit_log entry via
 *   WarehouseTransferAuditLogger (dual-write: DB + file). This closes
 *   gap G4 ("No dedicated audit trail — service uses DB::table()
 *   bypassing Eloquent events"). The AuditableMasterData trait on the
 *   WarehouseTransfer model only catches Eloquent-model-level changes;
 *   since the service uses DB::table() for efficiency, explicit audit
 *   logging is required.
 *
 * GL posting rules (same-branch only):
 *   - Stock moves: source OUT at avg_cost, dest IN at same avg_cost
 *   - NO GL journal (inventory is just reallocated within the same branch;
 *     the branch's total inventory doesn't change)
 *
 * NOTE: The postIntercompanyGL() method is retained for potential future
 * use by the Branch Demand module, but it should NEVER be called from
 * this WarehouseTransfer module due to same-branch enforcement.
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
        private StockAvailabilityService $stockAvailabilityService,
        private JournalPostingService $journalPosting,
        private WarehouseTransferAuditLogger $auditLogger
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

        if (!isset($warehouses[$fromWarehouseId]) || !isset($warehouses[$toWarehouseId])) {
            throw new \InvalidArgumentException('Invalid warehouse selection — one or both warehouses not found.');
        }

        $fromBranchId = (int) $warehouses[$fromWarehouseId];
        $toBranchId   = (int) $warehouses[$toWarehouseId];

        // ★ Phase 1 — Same-branch enforcement (CRITICAL):
        // WarehouseTransfer module ONLY handles same-branch transfers.
        // Cross-branch transfers must go through Branch Demand module.
        if ($fromBranchId !== $toBranchId) {
            Log::warning('Cross-branch warehouse transfer attempt blocked', [
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id'   => $toWarehouseId,
                'from_branch_id'    => $fromBranchId,
                'to_branch_id'      => $toBranchId,
                'created_by'        => $data['created_by'] ?? null,
            ]);
            throw new \InvalidArgumentException(
                'Both warehouses must belong to the same branch. ' .
                'Cross-branch transfers must go through the Branch Demand module.'
            );
        }

        $isInterbranch = false; // Always false for same-branch transfers

        // ★ Phase 3 — Warehouse freeze check:
        // If the source warehouse is currently frozen for a stock-take count,
        // draft creation is blocked. Transfers OUT of a frozen warehouse would
        // corrupt the physical count. The StockService::applyTransaction()
        // already checks this at confirm time — this check adds protection
        // at draft creation time so the user sees the error immediately.
        $fromWarehouse = Warehouse::find($fromWarehouseId);
        if ($fromWarehouse && $fromWarehouse->is_frozen_for_count) {
            Log::warning('Warehouse frozen for count — transfer creation blocked', [
                'from_warehouse_id'    => $fromWarehouseId,
                'warehouse_name'       => $fromWarehouse->warehouse_name,
                'is_frozen_for_count'  => true,
            ]);
            throw new \RuntimeException(
                'Source warehouse "' . $fromWarehouse->warehouse_name . '" is currently frozen for stock counting. ' .
                'Transfers cannot be created until the count is completed.'
            );
        }

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

        // ★ Phase 2 — Pipeline-aware availability check at source.
        // Uses StockAvailabilityService which subtracts the sales pipeline
        // (open invoice dispatches not yet challan-completed) from physical qty.
        // This prevents over-commitment when transfers + sales compete for the
        // same physical stock.
        // Availability is re-checked at confirm time (stock may change between
        // draft creation and confirm).
        foreach ($validatedItems as $item) {
            $available = $this->stockAvailabilityService->getWarehouseAvailableQty(
                $item['product_id'], $fromWarehouseId
            );
            if ($item['qty'] > $available + 0.0001) {
                $physical = $this->stockService->getWarehouseQty($fromWarehouseId, $item['product_id']);
                $pipeline = $physical - $available;
                throw new \RuntimeException(
                    "Insufficient available stock at source for product {$item['product_id']}: "
                    . "available {$available} (physical {$physical}, pipeline {$pipeline}), "
                    . "requested {$item['qty']}"
                );
            }
        }

        $transferCode = $this->generateTransferCode();

        return DB::transaction(function () use (
            $transferCode, $data, $fromWarehouseId, $toWarehouseId,
            $fromBranchId, $toBranchId, $isInterbranch, $validatedItems
        ) {
            $transferId = DB::table('warehouse_transfers')->insertGetId([
                'transfer_code' => $transferCode,
                'transfer_date' => $data['transfer_date'] ?? now()->format('Y-m-d'),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'is_interbranch' => $isInterbranch,
                // No total_amount column — derived from items.qty * rate via
                // the WarehouseTransfer::getTotalAmountAttribute() accessor.
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

            // ★ Phase 4 — Audit: log transfer creation.
            $this->auditLogger->transferCreated(
                (int) ($data['created_by'] ?? 0),
                $transferId,
                $transferCode,
                $fromWarehouseId,
                $toWarehouseId,
                $fromBranchId,
                count($validatedItems),
                $totalAmount
            );

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

            // ★ Phase 1 — Defense-in-depth: confirm should NEVER proceed on cross-branch
            if ($transfer->from_branch_id !== $transfer->to_branch_id) {
                Log::warning('Cross-branch warehouse transfer confirm attempt blocked', [
                    'transfer_id'   => $transferId,
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id'   => $transfer->to_branch_id,
                    'confirmed_by'   => $confirmedBy,
                ]);
                throw new \RuntimeException(
                    'Cannot confirm a cross-branch transfer. ' .
                    'Use the Branch Demand module instead.'
                );
            }

            // Since same-branch only, is_interbranch should always be false.
            // If somehow true (data inconsistency), block it.
            if ($transfer->is_interbranch) {
                Log::warning('Interbranch flag on same-branch transfer detected', [
                    'transfer_id' => $transferId,
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id' => $transfer->to_branch_id,
                ]);
                throw new \RuntimeException(
                    'Data inconsistency: transfer marked as interbranch but branches match. ' .
                    'Contact administrator.'
                );
            }

            $fromWh = $transfer->from_warehouse_id;
            $toWh = $transfer->to_warehouse_id;
            $transferDate = $transfer->transfer_date->format('Y-m-d');

            // ★ Phase 2 — Final pipeline-aware availability check at confirm time.
            // Stock may have changed between draft creation and confirm.
            // This is the definitive check: if stock is insufficient (including
            // pipeline deductions for other sales), the confirm is rejected.
            foreach ($transfer->items as $item) {
                $available = $this->stockAvailabilityService->getWarehouseAvailableQty(
                    (int) $item->product_id, (int) $fromWh
                );
                if ((float) $item->qty > $available + 0.0001) {
                    $physical = $this->stockService->getWarehouseQty((int) $fromWh, (int) $item->product_id);
                    $pipeline = $physical - $available;
                    throw new \RuntimeException(
                        "Insufficient available stock for product {$item->product_id}: "
                        . "available {$available} (physical {$physical}, pipeline {$pipeline}), "
                        . "requested {$item->qty}. "
                        . "Stock may have changed since the draft was created."
                    );
                }
            }

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

            // Same-branch transfer: NO GL posting.
            // (interbranch GL is handled by the Branch Demand module, not here)
            $journalEntryId = null;
            $journalEntryIdDebtor = null;

            // is_interbranch is always false due to same-branch enforcement,
            // but we keep the conditional as a safety net.
            if ($transfer->is_interbranch) {
                // This should NEVER happen due to the defense-in-depth check above.
                // If it does, something is very wrong — don't post GL.
                Log::error('Interbranch GL posting blocked on warehouse transfer', [
                    'transfer_id' => $transferId,
                ]);
                throw new \RuntimeException('Cross-branch GL posting is not allowed in this module.');
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

            // ★ Phase 4 — Audit: log transfer confirmation.
            $this->auditLogger->transferConfirmed(
                $confirmedBy,
                $transferId,
                $transfer->transfer_code,
                (int) $fromWh,
                (int) $toWh,
                (int) $transfer->from_branch_id,
                $transfer->items->count(),
                (float) $transfer->total_amount
            );

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

            // ★ Phase 3 — Reason required for confirmed-transfer cancellation:
            // Drafts can be cancelled without a reason (no stock/GL impact),
            // but confirmed transfers MUST have a reason because reversal
            // creates audit trail entries that reference it.
            if ($transfer->isConfirmed() && trim($reason) === '') {
                throw new \RuntimeException('A cancellation reason is required');
            }

            // ★ Phase 3 — Demand-linked reversal protection:
            // Transfers linked to a Branch Demand cannot be cancelled via
            // WarehouseTransfer. They must be cancelled through the Branch
            // Demand module, which has its own workflow (cancel demand →
            // reverse the linked transfer).
            // branch_demand_id column added via migration
            // 2025_07_28_000011_add_branch_demand_id_to_warehouse_transfers.
            if ($transfer->branch_demand_id) {
                Log::warning('Demand-linked warehouse transfer cancel attempt blocked', [
                    'transfer_id'       => $transferId,
                    'branch_demand_id'  => $transfer->branch_demand_id,
                    'cancelled_by'      => $cancelledBy,
                ]);
                throw new \RuntimeException(
                    'This transfer is linked to a branch demand (ID: ' . $transfer->branch_demand_id . '). ' .
                    'Reverse the demand instead of the transfer — the Branch Demand module ' .
                    'handles the full reversal workflow.'
                );
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

                // ★ Phase 3 — Reverse stock movements in SAFE ORDER.
                // Dest IN (positive qty) movements are reversed FIRST,
                // then source OUT (negative qty) movements.
                // This prevents "insufficient stock at receiver" errors.
                // Example: dest has 5 units from a 10-unit transfer.
                // If we reverse source OUT first (dest still has 5),
                // then reverse dest IN (dest needs to give back 10 but only has 5).
                // By reversing dest IN first, the sequence is safe.
                $stockTxs = $this->sortMovementsForReversal(
                    DB::table('stock_transactions')
                        ->where('reference_type', 'warehouse_transfer')
                        ->where('reference_id', $transferId)
                        ->where('is_reversed', false)
                        ->get()
                );

                foreach ($stockTxs as $tx) {
                    Log::info('Reversing stock transaction for transfer cancellation', [
                        'transaction_id' => $tx->id,
                        'warehouse_id'   => $tx->warehouse_id,
                        'product_id'     => $tx->product_id,
                        'qty'            => $tx->qty,
                        'transfer_id'    => $transferId,
                        'reversal_order' => (float) $tx->qty > 0 ? 'dest-IN-first' : 'source-OUT-second',
                    ]);
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

            // ★ Phase 4 — Audit: log transfer cancellation.
            $this->auditLogger->transferCancelled(
                $cancelledBy,
                $transferId,
                $transfer->transfer_code,
                (int) $transfer->from_branch_id,
                $transfer->status, // previous status (before cancel update)
                $transfer->isConfirmed(), // was it reversed?
                $reason
            );

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
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateTransferCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'warehouse_transfer',
            prefix:   'WT',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Sort stock movements for safe reversal.
     * Destination IN (positive qty) movements are reversed FIRST,
     * then source OUT (negative qty) movements.
     * This prevents "insufficient stock at receiver" errors during reversal.
     *
     * Why: when a transfer is confirmed, the sequence is:
     *   1. Source OUT: source warehouse loses qty (negative)
     *   2. Dest IN:    dest warehouse gains qty (positive)
     *
     * When reversing, we must undo in the OPPOSITE order:
     *   1. Reverse Dest IN first:  dest gives back the qty it received
     *      (dest qty decreases, but source still hasn't gotten its qty back)
     *   2. Reverse Source OUT then: source gets its qty back
     *      (source qty increases)
     *
     * If we reversed in the WRONG order (source OUT first):
     *   - Source gets its qty back (fine)
     *   - Dest needs to give back qty, but may have insufficient stock
     *     if other operations consumed the received stock.
     *
     * Secondary sort: by ID descending (most recent first within same
     * sign group) — mirrors legacy sortMovementsForReversal().
     *
     * @param \Illuminate\Support\Collection $movements
     * @return \Illuminate\Support\Collection
     */
    private function sortMovementsForReversal($movements)
    {
        return $movements->sort(function ($a, $b) {
            $qa = (float) $a->qty;
            $qb = (float) $b->qty;
            // Positive qty (dest IN) reversed first → sort them before negative
            if ($qa > 0 && $qb <= 0) return -1;
            if ($qa <= 0 && $qb > 0) return 1;
            // Secondary: by ID descending (most recent first)
            return (int) $b->id <=> (int) $a->id;
        })->values();
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
