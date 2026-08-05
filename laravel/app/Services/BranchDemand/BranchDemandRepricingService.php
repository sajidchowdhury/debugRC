<?php

namespace App\Services\BranchDemand;

use App\Models\BranchDemand;
use App\Models\BranchDemandRepricing;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// FINANCE-2 (G-016): lazy resolution via `app(...)` — same pattern as
// BranchDemandService. Avoids constructor coupling to the shadow service.
use App\Services\BranchDemand\BranchDemandShadowService;

/**
 * Branch Demand Repricing Service — Phase 7.
 *
 * Handles the repricing adjustment mechanism for cross-branch demands.
 *
 * When the product price changes after goods have been sent, the branches
 * may agree to reprice the demand. This service:
 *   - Creates a repricing adjustment record (original → new total value)
 *   - Updates the demand's total_value
 *   - Posts a GL adjustment journal (dual: creditor + debtor)
 *   - Records a branch_ledger adjustment entry (with running balance)
 *   - Provides price range comparison for the weekly audit report
 *
 * GL Adjustment Rules:
 *
 *   When the adjustment is POSITIVE (new total > original total):
 *     ┌──────────────────────────────────────────────────────────────────────┐
 *     │ CREDITOR ADJUSTMENT JOURNAL — branch_id = to_branch_id (supplier) │
 *     │   Dr Due from Branches   (interbranch_receivable)  = adj_amount   │
 *     │   Cr Inventory           (inventory)               = adj_amount   │
 *     ├──────────────────────────────────────────────────────────────────────┤
 *     │ DEBTOR ADJUSTMENT JOURNAL — branch_id = from_branch_id (requester)│
 *     │   Dr Inventory           (inventory)               = adj_amount   │
 *     │   Cr Due to Branches     (interbranch_payable)     = adj_amount   │
 *     └──────────────────────────────────────────────────────────────────────┘
 *
 *   When the adjustment is NEGATIVE (new total < original total):
 *     ┌──────────────────────────────────────────────────────────────────────┐
 *     │ CREDITOR ADJUSTMENT JOURNAL — branch_id = to_branch_id (supplier) │
 *     │   Dr Inventory           (inventory)               = |adj_amount| │
 *     │   Cr Due from Branches   (interbranch_receivable)  = |adj_amount| │
 *     ├──────────────────────────────────────────────────────────────────────┤
 *     │ DEBTOR ADJUSTMENT JOURNAL — branch_id = from_branch_id (requester)│
 *     │   Dr Due to Branches     (interbranch_payable)     = |adj_amount| │
 *     │   Cr Inventory           (inventory)               = |adj_amount| │
 *     └──────────────────────────────────────────────────────────────────────┘
 */
class BranchDemandRepricingService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private BranchIntercompanyService $intercompanyService,
        private BranchDemandAuditLogger $auditLogger,
    ) {}

    // ===================== REPRICING ADJUSTMENT =====================

    /**
     * Create a repricing adjustment for a branch demand.
     *
     * Business rules:
     *   - The demand must be in 'received' status (goods have been sent)
     *   - The demand must not be reversed
     *   - The new total value must differ from the current total value
     *   - The new total value must be positive (>= 0)
     *   - The adjustment amount must not exceed the current outstanding balance
     *     for negative adjustments (cannot create a negative total_value)
     *
     * @param int $demandId
     * @param float $newTotalValue The new total value for the demand
     * @param string $reason The reason for the repricing
     * @param int|null $approvedBy User ID who approved the repricing (optional)
     * @param int $createdBy User ID who created the repricing
     * @return BranchDemandRepricing
     * @throws \RuntimeException If validation fails
     * @throws \InvalidArgumentException If input is invalid
     */
    public function createRepricingAdjustment(
        int $demandId,
        float $newTotalValue,
        string $reason,
        ?int $approvedBy,
        int $createdBy
    ): BranchDemandRepricing {
        // Input validation
        if ($newTotalValue < 0) {
            throw new \InvalidArgumentException('New total value cannot be negative.');
        }

        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('A reason is required for repricing adjustments.');
        }

        $repricing = DB::transaction(function () use ($demandId, $newTotalValue, $reason, $approvedBy, $createdBy) {
            // Lock the demand row
            $demand = DB::table('branch_demands')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                throw new \RuntimeException("Branch demand {$demandId} not found.");
            }

            // Status must be 'received' (goods have been sent)
            if ($demand->status !== 'received') {
                throw new \RuntimeException(
                    "Cannot reprice demand #{$demandId}: status is '{$demand->status}', expected 'received'. "
                    . "Only demands with goods already sent can be repriced."
                );
            }

            // Must not be reversed
            if ($demand->is_reversed) {
                throw new \RuntimeException(
                    "Cannot reprice demand #{$demandId}: the demand has been reversed."
                );
            }

            $originalTotalValue = (float) ($demand->total_value ?? 0);
            $adjustmentAmount = round($newTotalValue - $originalTotalValue, 2);

            // The new total value must differ from the current total value
            if (abs($adjustmentAmount) < 0.01) {
                throw new \RuntimeException(
                    "Cannot reprice demand #{$demandId}: the new total value ({$newTotalValue}) "
                    . "is the same as the current total value ({$originalTotalValue}). No adjustment needed."
                );
            }

            // For negative adjustments: the new total must not be less than the settlement amount
            // (cannot create a situation where settlement_amount > total_value)
            $settlementAmount = (float) ($demand->settlement_amount ?? 0);
            if ($newTotalValue < $settlementAmount) {
                throw new \RuntimeException(
                    "Cannot reprice demand #{$demandId}: the new total value ({$newTotalValue}) "
                    . "would be less than the already-settled amount ({$settlementAmount}). "
                    . "This would create a negative outstanding balance."
                );
            }

            // Create the repricing adjustment record
            $repricingId = DB::table('branch_demand_repricing')->insertGetId([
                'branch_demand_id'    => $demandId,
                'original_total_value' => $originalTotalValue,
                'new_total_value'     => $newTotalValue,
                'adjustment_amount'   => $adjustmentAmount,
                'reason'              => $reason,
                'approved_by'         => $approvedBy,
                'journal_entry_id'    => null, // Will be updated after GL posting
                'created_by'          => $createdBy,
                'created_at'          => now(),
            ]);

            // Update the demand's total_value
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->update([
                    'total_value' => $newTotalValue,
                    'updated_at'  => now(),
                ]);

            // Post GL adjustment journals
            $demandModel = BranchDemand::find($demandId);
            $jeIds = $this->postRepricingAdjustmentJournals(
                $demandModel,
                $adjustmentAmount,
                $createdBy
            );
            $creditorJeId = $jeIds['creditor_je_id'];
            $debtorJeId   = $jeIds['debtor_je_id'];

            // Record branch ledger adjustment
            // (uses the creditor JE id as the primary reference, consistent
            //  with the prior behavior; the debtor JE id is now persisted
            //  on the repricing row via journal_entry_id_debtor below).
            $this->recordRepricingLedgerEntry(
                $demandModel,
                $adjustmentAmount,
                $creditorJeId,
                $createdBy
            );

            // G-329: persist BOTH journal entry ids on the repricing row.
            // `journal_entry_id` is the creditor (supplier) side;
            // `journal_entry_id_debtor` is the debtor (requester) side.
            DB::table('branch_demand_repricing')
                ->where('id', $repricingId)
                ->update([
                    'journal_entry_id'        => $creditorJeId,
                    'journal_entry_id_debtor' => $debtorJeId,
                ]);

            Log::info('BranchDemand repricing adjustment created', [
                'demand_id'            => $demandId,
                'demand_code'         => $demand->demand_code,
                'original_total'      => $originalTotalValue,
                'new_total'           => $newTotalValue,
                'adjustment_amount'   => $adjustmentAmount,
                'repricing_id'        => $repricingId,
                'journal_entry_id'    => $creditorJeId,
                'journal_entry_id_debtor' => $debtorJeId,
                'created_by'          => $createdBy,
                'approved_by'         => $approvedBy,
            ]);

            // ★ Phase 8 — Audit log
            $this->auditLogger->log($demandId, 'reprice', (int) $demand->from_branch_id, [
                'demand_code'         => $demand->demand_code,
                'original_total'      => round($originalTotalValue, 2),
                'new_total'           => round($newTotalValue, 2),
                'adjustment_amount'   => round($adjustmentAmount, 2),
                'reason'              => $reason,
                'approved_by'         => $approvedBy,
                'repricing_id'        => $repricingId,
                'journal_entry_id'    => $creditorJeId,
                'journal_entry_id_debtor' => $debtorJeId,
                'from_branch_id'      => (int) $demand->from_branch_id,
                'to_branch_id'        => (int) $demand->to_branch_id,
            ], $createdBy);

            return BranchDemandRepricing::find($repricingId);
        });

        // FINANCE-2 (G-016): wire shadow-mode comparison after the commit.
        // Repricing mutates total_value + GL — the legacy system must agree
        // on the new total before cutover. Re-load the demand (the locked
        // row inside the txn is a stdClass, not a BranchDemand model).
        $this->dispatchShadowCompare('reprice', BranchDemand::find($demandId));

        return $repricing;
    }

    // ===================== GL ADJUSTMENT JOURNALS =====================

    /**
     * Post GL adjustment journals for a repricing.
     *
     * Creates a single journal entry on the creditor branch with two lines:
     *   - If positive adjustment: Dr Due from Branches / Cr Inventory
     *   - If negative adjustment: Dr Inventory / Cr Due from Branches
     *
     * And a matching journal entry on the debtor branch:
     *   - If positive adjustment: Dr Inventory / Cr Due to Branches
     *   - If negative adjustment: Dr Due to Branches / Cr Inventory
     *
     * FINANCE-3 (G-329): returns BOTH journal entry ids (creditor + debtor)
     * so the caller can persist them on `branch_demand_repricing`. The
     * table now has separate `journal_entry_id` (creditor) and
     * `journal_entry_id_debtor` columns — previously only the creditor id
     * was stored, leaving the debtor side untraceable from the audit row.
     *
     * @param BranchDemand $demand
     * @param float $adjustmentAmount Positive = increase, Negative = decrease
     * @param int $postedBy User ID
     * @return array{ creditor_je_id: int, debtor_je_id: int }
     */
    private function postRepricingAdjustmentJournals(
        BranchDemand $demand,
        float $adjustmentAmount,
        int $postedBy
    ): array {
        $absAmount = abs($adjustmentAmount);
        $isPositive = $adjustmentAmount > 0;

        $creditorBranchId = (int) $demand->to_branch_id;   // supplier
        $debtorBranchId = (int) $demand->from_branch_id;    // requester
        $demandCode = $demand->demand_code;
        $entryDate = $demand->demand_date ?? now()->format('Y-m-d');

        // Resolve ledger accounts
        $interbranchReceivableId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');
        $interbranchPayableId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        $inventoryId = $this->journalPosting->lookupLedgerByNature('inventory');

        if (!$interbranchReceivableId || !$interbranchPayableId || !$inventoryId) {
            throw new \RuntimeException(
                'Required GL accounts not found for repricing adjustment. '
                . 'Ensure interbranch_receivable, interbranch_payable, and inventory accounts exist.'
            );
        }

        $direction = $isPositive ? 'increase' : 'decrease';

        // 1. Creditor (supplier) adjustment journal
        if ($isPositive) {
            // Dr Due from Branches / Cr Inventory
            $creditorLines = [
                ['ledger_id' => $interbranchReceivableId, 'debit' => $absAmount, 'credit' => 0, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
                ['ledger_id' => $inventoryId, 'debit' => 0, 'credit' => $absAmount, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
            ];
        } else {
            // Dr Inventory / Cr Due from Branches
            $creditorLines = [
                ['ledger_id' => $inventoryId, 'debit' => $absAmount, 'credit' => 0, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
                ['ledger_id' => $interbranchReceivableId, 'debit' => 0, 'credit' => $absAmount, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
            ];
        }

        $creditorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'branch_demand_repricing',
            'reference_id'   => (int) $demand->id,
            'branch_id'      => $creditorBranchId,
            'description'    => "Demand #{$demandCode} repricing {$direction}: adjustment of " . number_format($absAmount, 2),
            'source'         => 'branch_demand_repricing',
            'created_by'     => $postedBy,
        ], $creditorLines);

        // 2. Debtor (requester) adjustment journal
        if ($isPositive) {
            // Dr Inventory / Cr Due to Branches
            $debtorLines = [
                ['ledger_id' => $inventoryId, 'debit' => $absAmount, 'credit' => 0, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
                ['ledger_id' => $interbranchPayableId, 'debit' => 0, 'credit' => $absAmount, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
            ];
        } else {
            // Dr Due to Branches / Cr Inventory
            $debtorLines = [
                ['ledger_id' => $interbranchPayableId, 'debit' => $absAmount, 'credit' => 0, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
                ['ledger_id' => $inventoryId, 'debit' => 0, 'credit' => $absAmount, 'memo' => "Repricing {$direction}: Demand #{$demandCode}"],
            ];
        }

        $debtorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'branch_demand_repricing',
            'reference_id'   => (int) $demand->id,
            'branch_id'      => $debtorBranchId,
            'description'    => "Demand #{$demandCode} repricing {$direction}: adjustment of " . number_format($absAmount, 2),
            'source'         => 'branch_demand_repricing',
            'created_by'     => $postedBy,
        ], $debtorLines);

        Log::info('BranchDemand repricing GL journals posted', [
            'demand_id'      => $demand->id,
            'demand_code'    => $demandCode,
            'adjustment'     => $adjustmentAmount,
            'creditor_je_id' => $creditorJeId,
            'debtor_je_id'   => $debtorJeId,
        ]);

        // G-329: return BOTH ids so the caller can persist them on
        // `branch_demand_repricing.journal_entry_id` (creditor) and
        // `journal_entry_id_debtor` (debtor).
        return [
            'creditor_je_id' => $creditorJeId,
            'debtor_je_id'   => $debtorJeId,
        ];
    }

    // ===================== BRANCH LEDGER ADJUSTMENT =====================

    /**
     * Record a branch ledger pair for a repricing adjustment.
     *
     * Positive adjustment: debtor owes more → debit increases
     * Negative adjustment: debtor owes less → credit decreases
     *
     * @param BranchDemand $demand
     * @param float $adjustmentAmount Positive = increase in debt, Negative = decrease
     * @param int $journalEntryId The creditor journal entry ID
     * @param int $postedBy User ID
     */
    private function recordRepricingLedgerEntry(
        BranchDemand $demand,
        float $adjustmentAmount,
        int $journalEntryId,
        int $postedBy
    ): void {
        $debtorBranchId = (int) $demand->from_branch_id;
        $creditorBranchId = (int) $demand->to_branch_id;
        $entryDate = $demand->demand_date ?? now()->format('Y-m-d');
        $absAmount = abs($adjustmentAmount);

        // Compute the running balance
        $previousBalance = $this->intercompanyService->getRunningBalance($debtorBranchId, $creditorBranchId);
        $newBalance = $previousBalance + $adjustmentAmount; // Positive = debtor owes more

        $direction = $adjustmentAmount > 0 ? 'increase' : 'decrease';

        if ($adjustmentAmount > 0) {
            // Debtor row: debit = adjustment (debtor owes more)
            DB::table('branch_ledger')->insert([
                'transaction_date' => $entryDate,
                'from_branch_id'   => $debtorBranchId,
                'to_branch_id'     => $creditorBranchId,
                'reference_type'   => 'demand_repricing',
                'reference_id'     => (int) $demand->id,
                'journal_entry_id' => $journalEntryId,
                'debit'            => $absAmount,
                'credit'           => 0,
                'running_balance'  => $newBalance,
                'remarks'          => "Demand #{$demand->demand_code} repricing {$direction}",
                'is_reversed'      => false,
                'created_by'       => $postedBy,
                'created_at'       => now(),
            ]);

            // Creditor row: credit = adjustment (creditor is owed more)
            DB::table('branch_ledger')->insert([
                'transaction_date' => $entryDate,
                'from_branch_id'   => $debtorBranchId,
                'to_branch_id'     => $creditorBranchId,
                'reference_type'   => 'demand_repricing',
                'reference_id'     => (int) $demand->id,
                'journal_entry_id' => $journalEntryId,
                'debit'            => 0,
                'credit'           => $absAmount,
                'running_balance'  => $newBalance,
                'remarks'          => "Demand #{$demand->demand_code} repricing {$direction}",
                'is_reversed'      => false,
                'created_by'       => $postedBy,
                'created_at'       => now(),
            ]);
        } else {
            // Debtor row: credit = adjustment (debtor owes less)
            DB::table('branch_ledger')->insert([
                'transaction_date' => $entryDate,
                'from_branch_id'   => $debtorBranchId,
                'to_branch_id'     => $creditorBranchId,
                'reference_type'   => 'demand_repricing',
                'reference_id'     => (int) $demand->id,
                'journal_entry_id' => $journalEntryId,
                'debit'            => 0,
                'credit'           => $absAmount,
                'running_balance'  => $newBalance,
                'remarks'          => "Demand #{$demand->demand_code} repricing {$direction}",
                'is_reversed'      => false,
                'created_by'       => $postedBy,
                'created_at'       => now(),
            ]);

            // Creditor row: debit = adjustment (creditor is owed less)
            DB::table('branch_ledger')->insert([
                'transaction_date' => $entryDate,
                'from_branch_id'   => $debtorBranchId,
                'to_branch_id'     => $creditorBranchId,
                'reference_type'   => 'demand_repricing',
                'reference_id'     => (int) $demand->id,
                'journal_entry_id' => $journalEntryId,
                'debit'            => $absAmount,
                'credit'           => 0,
                'running_balance'  => $newBalance,
                'remarks'          => "Demand #{$demand->demand_code} repricing {$direction}",
                'is_reversed'      => false,
                'created_by'       => $postedBy,
                'created_at'       => now(),
            ]);
        }

        Log::info('BranchDemand repricing ledger entry recorded', [
            'demand_id'      => $demand->id,
            'demand_code'    => $demand->demand_code,
            'adjustment'     => $adjustmentAmount,
            'new_balance'    => $newBalance,
        ]);
    }

    // ===================== PRICE RANGE COMPARISON =====================

    /**
     * Get price range comparison for a branch's demands.
     *
     * For each demand item that has been sent (has price_min/max/default),
     * compare the locked price range against the current price range from
     * product_price_history. Returns items where the price has changed.
     *
     * @param int $branchId The branch to check (defaults to all branches if 0)
     * @param string|null $dateFrom Optional date filter
     * @param string|null $dateTo Optional date filter
     * @return array Array of price range changes with impact analysis
     */
    public function getPriceRangeComparison(int $branchId = 0, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $today = now()->format('Y-m-d');

        // Get all sent demand items with price range data
        $query = DB::table('branch_demand_items as bdi')
            ->join('branch_demands as bd', 'bdi.branch_demand_id', '=', 'bd.id')
            ->join('products as p', 'bdi.product_id', '=', 'p.id')
            ->where('bd.status', 'received')
            ->where('bd.is_reversed', false)
            ->where('bdi.price_min', '>', 0)
            ->select([
                'bd.id as demand_id',
                'bd.demand_code',
                'bd.demand_date',
                'bd.from_branch_id',
                'bd.to_branch_id',
                'bdi.id as item_id',
                'bdi.product_id',
                'p.product_name',
                'p.product_code',
                'bdi.qty',
                'bdi.cost_rate',
                'bdi.price_min as locked_min',
                'bdi.price_max as locked_max',
                'bdi.price_default as locked_default',
            ]);

        // Branch filter
        if ($branchId > 0) {
            $query->where(function ($q) use ($branchId) {
                $q->where('bd.from_branch_id', $branchId)
                  ->orWhere('bd.to_branch_id', $branchId);
            });
        }

        // Date filters
        if ($dateFrom) {
            $query->where('bd.demand_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('bd.demand_date', '<=', $dateTo);
        }

        $items = $query->orderByDesc('bd.demand_date')->get();

        // Load current price ranges for all products
        $productIds = $items->pluck('product_id')->unique()->toArray();
        $currentRanges = $this->loadCurrentPriceRanges($productIds, $today);

        $changes = [];
        foreach ($items as $item) {
            $currentRange = $currentRanges[$item->product_id] ?? null;

            if (!$currentRange) {
                continue; // No current price range found
            }

            $lockedMin = (float) $item->locked_min;
            $lockedMax = (float) $item->locked_max;
            $lockedDefault = (float) $item->locked_default;
            $currentMin = (float) $currentRange['min'];
            $currentMax = (float) $currentRange['max'];
            $currentDefault = (float) $currentRange['default'];

            // Check if any price has changed
            $minChanged = abs($currentMin - $lockedMin) > 0.01;
            $maxChanged = abs($currentMax - $lockedMax) > 0.01;
            $defaultChanged = abs($currentDefault - $lockedDefault) > 0.01;

            if (!$minChanged && !$maxChanged && !$defaultChanged) {
                continue; // No price change
            }

            // Calculate the impact on the outstanding balance
            // Impact = (new_default - locked_default) * qty
            $defaultVariance = $currentDefault - $lockedDefault;
            $impactAmount = round($defaultVariance * (float) $item->qty, 2);

            // Calculate the margin variance (cost_rate vs current_default)
            $marginVariance = round($currentDefault - (float) $item->cost_rate, 2);

            $changes[] = [
                'demand_id'        => $item->demand_id,
                'demand_code'      => $item->demand_code,
                'demand_date'      => $item->demand_date,
                'product_id'       => $item->product_id,
                'product_name'     => $item->product_name,
                'product_code'     => $item->product_code,
                'qty'              => (float) $item->qty,
                'cost_rate'        => (float) $item->cost_rate,
                'locked_min'       => $lockedMin,
                'locked_max'       => $lockedMax,
                'locked_default'   => $lockedDefault,
                'current_min'      => $currentMin,
                'current_max'      => $currentMax,
                'current_default'  => $currentDefault,
                'default_variance' => round($defaultVariance, 2),
                'impact_amount'    => $impactAmount,
                'margin_variance'  => $marginVariance,
                'direction'        => $defaultVariance > 0 ? 'increase' : ($defaultVariance < 0 ? 'decrease' : 'none'),
            ];
        }

        return $changes;
    }

    /**
     * Get repricing history for a specific demand.
     *
     * @param int $demandId
     * @return \Illuminate\Support\Collection
     */
    public function getRepricingHistory(int $demandId): \Illuminate\Support\Collection
    {
        return DB::table('branch_demand_repricing')
            ->where('branch_demand_id', $demandId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get the total repricing adjustment amount for a demand.
     *
     * This is the sum of all adjustment_amount values across all repricing
     * records for the demand. Useful for understanding the cumulative impact
     * of repricing on the demand's total value.
     *
     * @param int $demandId
     * @return float
     */
    public function getCumulativeRepricingAdjustment(int $demandId): float
    {
        return (float) DB::table('branch_demand_repricing')
            ->where('branch_demand_id', $demandId)
            ->sum('adjustment_amount');
    }

    /**
     * Check if a sale price is within the locked price range for a demand item.
     *
     * This is used for visibility and accountability — NOT to prevent the sale.
     * Returns an array with the warning status and details.
     *
     * @param int $demandItemId
     * @param float $salePrice
     * @return array{ within_range: bool, warning: string|null, locked_min: float, locked_max: float, locked_default: float }
     */
    public function checkSalePriceRange(int $demandItemId, float $salePrice): array
    {
        $item = DB::table('branch_demand_items')
            ->where('id', $demandItemId)
            ->first();

        if (!$item) {
            return [
                'within_range' => false,
                'warning' => 'Demand item not found.',
                'locked_min' => 0,
                'locked_max' => 0,
                'locked_default' => 0,
            ];
        }

        $lockedMin = (float) ($item->price_min ?? 0);
        $lockedMax = (float) ($item->price_max ?? 0);
        $lockedDefault = (float) ($item->price_default ?? 0);

        // If no price range was recorded, cannot check
        if ($lockedMin <= 0 && $lockedMax <= 0) {
            return [
                'within_range' => true,
                'warning' => null,
                'locked_min' => $lockedMin,
                'locked_max' => $lockedMax,
                'locked_default' => $lockedDefault,
            ];
        }

        $withinRange = true;
        $warning = null;

        if ($salePrice < $lockedMin) {
            $withinRange = false;
            $warning = "Sale price ({$salePrice}) is below the locked minimum ({$lockedMin}). "
                . "This product was received via branch demand and the price is below the agreed range.";
        } elseif ($salePrice > $lockedMax) {
            $withinRange = false;
            $warning = "Sale price ({$salePrice}) is above the locked maximum ({$lockedMax}). "
                . "This product was received via branch demand and the price exceeds the agreed range.";
        }

        return [
            'within_range' => $withinRange,
            'warning' => $warning,
            'locked_min' => $lockedMin,
            'locked_max' => $lockedMax,
            'locked_default' => $lockedDefault,
        ];
    }

    /**
     * Get all demand items for a branch where the current sale price is
     * outside the locked price range. Used for the weekly audit report.
     *
     * @param int $branchId The debtor branch (requester) to check
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return array
     */
    public function getOutOfRangeSales(int $branchId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Get all demand items where the debtor branch has sold products
        // that were received via branch demand, and check if the sale price
        // is within the locked price range.
        //
        // This joins with sales_invoice_items to find actual sales.
        $query = DB::table('branch_demand_items as bdi')
            ->join('branch_demands as bd', 'bdi.branch_demand_id', '=', 'bd.id')
            ->join('products as p', 'bdi.product_id', '=', 'p.id')
            ->where('bd.from_branch_id', $branchId) // debtor/requester
            ->where('bd.status', 'received')
            ->where('bd.is_reversed', false)
            ->where('bdi.price_min', '>', 0)
            ->select([
                'bd.id as demand_id',
                'bd.demand_code',
                'bdi.product_id',
                'p.product_name',
                'p.product_code',
                'bdi.qty as demand_qty',
                'bdi.cost_rate',
                'bdi.price_min as locked_min',
                'bdi.price_max as locked_max',
                'bdi.price_default as locked_default',
            ]);

        if ($dateFrom) {
            $query->where('bd.demand_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('bd.demand_date', '<=', $dateTo);
        }

        $demandItems = $query->get();

        // For each demand item, find sales in the period and check if they
        // are within the locked price range
        $warnings = [];
        foreach ($demandItems as $di) {
            $salesQuery = DB::table('sales_invoice_items as sii')
                ->join('sales_invoices as si', 'sii.sales_invoice_id', '=', 'si.id')
                ->where('si.branch_id', $branchId)
                ->where('sii.product_id', $di->product_id)
                ->where('si.status', '!=', 'cancelled')
                ->select([
                    'si.id as invoice_id',
                    'si.invoice_code',
                    'si.invoice_date',
                    'sii.qty',
                    'sii.rate',
                    // FINANCE-2 (G-357): `sales_invoice_items` has NO `total`
                    // column — the correct column is `amount` (GENERATED
                    // STORED: qty × rate, per `04_sales.sql:114` and the
                    // SalesInvoiceItem model @property). The previous
                    // `sii.total` selection threw `SQLSTATE[42703]: column
                    // "sii.total" does not exist` on every repricing audit run.
                    'sii.amount',
                ]);

            if ($dateFrom) {
                $salesQuery->where('si.invoice_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $salesQuery->where('si.invoice_date', '<=', $dateTo);
            }

            $sales = $salesQuery->get();

            foreach ($sales as $sale) {
                $saleRate = (float) $sale->rate;
                $lockedMin = (float) $di->locked_min;
                $lockedMax = (float) $di->locked_max;

                if ($saleRate < $lockedMin || $saleRate > $lockedMax) {
                    $warnings[] = [
                        'demand_id'      => $di->demand_id,
                        'demand_code'    => $di->demand_code,
                        'product_name'   => $di->product_name,
                        'product_code'   => $di->product_code,
                        'invoice_id'     => $sale->invoice_id,
                        'invoice_code'   => $sale->invoice_code,
                        'invoice_date'   => $sale->invoice_date,
                        'sale_qty'       => (float) $sale->qty,
                        'sale_rate'      => $saleRate,
                        'locked_min'     => $lockedMin,
                        'locked_max'     => $lockedMax,
                        'locked_default' => (float) $di->locked_default,
                        'cost_rate'      => (float) $di->cost_rate,
                        'variance'       => round($saleRate - (float) $di->locked_default, 2),
                        'type'           => $saleRate < $lockedMin ? 'below_min' : 'above_max',
                    ];
                }
            }
        }

        return $warnings;
    }

    // ===================== PRIVATE HELPERS =====================

    /**
     * Load current effective price ranges for a set of products.
     *
     * Returns [product_id => ['min' => float, 'max' => float, 'default' => float]]
     */
    private function loadCurrentPriceRanges(array $productIds, string $asOfDate): array
    {
        if (empty($productIds)) {
            return [];
        }

        $rows = DB::table('product_price_history')
            ->whereIn('product_id', $productIds)
            ->where('effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $asOfDate);
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

    // ===================== SHADOW MODE (FINANCE-2 / G-016) =====================

    /**
     * Dispatch a non-blocking shadow-mode comparison after a repricing commits.
     *
     * FINANCE-2 (G-016): mirrors `BranchDemandService::dispatchShadowCompare`
     * — duplicated intentionally to avoid cross-service coupling. The shadow
     * service is resolved lazily; failures are logged + swallowed.
     *
     * @param string               $operation  always 'reprice' here (kept
     *        parametric for symmetry with the sibling helper).
     * @param \App\Models\BranchDemand $demand
     */
    private function dispatchShadowCompare(string $operation, BranchDemand $demand): void
    {
        if (!config('branch_demand_shadow.enabled', false)) {
            return;
        }

        try {
            $shadow = app(BranchDemandShadowService::class);

            $shadow->compareOperation(
                operation:    $operation,
                demandId:      (int) $demand->id,
                fromBranchId:  (int) $demand->from_branch_id,
                toBranchId:    (int) $demand->to_branch_id,
                laravelData:   [
                    'demand_code'       => $demand->demand_code,
                    'status'            => $demand->status,
                    'total_value'       => (float) ($demand->total_value ?? 0),
                    'settlement_amount' => (float) ($demand->settlement_amount ?? 0),
                ],
                comparedBy:    Auth::id(),
            );
        } catch (\Throwable $e) {
            Log::warning('Branch demand shadow comparison failed (non-blocking)', [
                'operation'  => $operation,
                'demand_id'  => $demand->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
