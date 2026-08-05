<?php

namespace App\Services\Accounting;

use App\Models\FixedAsset;
use App\Models\AssetDepreciationSchedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DepreciationService — Phase 9.4: Fixed Asset & Depreciation
 *
 * Calculates and posts monthly depreciation entries for fixed assets.
 *
 * Depreciation Methods:
 *   1. Straight Line:      (cost - salvage) / useful_life_months
 *   2. Declining Balance:   book_value * (rate / 100) / 12
 *   3. Units of Production: (cost - salvage) / total_estimated_units * units_this_period
 *
 * Journal Entry for Depreciation:
 *   Dr Depreciation Expense   (dep_expense_ledger_id or nature-resolved)
 *   Cr Accumulated Depreciation (dep_ledger_id)
 *
 * Workflow:
 *   1. calculateDepreciation() — compute the depreciation amount for an asset
 *   2. generateSchedule() — create schedule records for a period
 *   3. postDepreciation() — post a single schedule record to GL
 *   4. postMonthlyDepreciation() — batch post all pending schedules for a branch/period
 *   5. reverseDepreciation() — reverse a posted depreciation entry
 */
class DepreciationService
{
    public function __construct(
        private JournalPostingService $journalService,
        private LedgerNatureService $natureService,
    ) {}

    // ============================================================
    // CALCULATION METHODS
    // ============================================================

    /**
     * Calculate the depreciation amount for an asset for a given period.
     *
     * @param FixedAsset $asset
     * @param string $periodFrom Y-m-d
     * @param string $periodTo Y-m-d
     * @param float $unitsProduced Units produced in this period (for units_of_production)
     * @return array{ depreciation_amount: float, opening_book_value: float, closing_book_value: float, rate_per_unit: float, declining_balance_rate_used: float, units_produced: float }
     */
    public function calculateDepreciation(
        FixedAsset $asset,
        string $periodFrom,
        string $periodTo,
        float $unitsProduced = 0,
    ): array {
        $openingBookValue = (float) $asset->net_book_value;
        $depreciableAmount = (float) $asset->acquisition_cost - (float) $asset->salvage_value;

        // If book value has reached salvage value, no more depreciation
        if ($openingBookValue <= (float) $asset->salvage_value + 0.01) {
            return [
                'depreciation_amount' => 0,
                'opening_book_value' => $openingBookValue,
                'closing_book_value' => $openingBookValue,
                'rate_per_unit' => 0,
                'declining_balance_rate_used' => 0,
                'units_produced' => $unitsProduced,
            ];
        }

        $depreciationAmount = 0;
        $ratePerUnit = 0;
        $decliningBalanceRateUsed = 0;

        switch ($asset->depreciation_method) {
            case 'straight_line':
                $depreciationAmount = $this->calculateStraightLine($asset, $depreciableAmount);
                break;

            case 'declining_balance':
                $depreciationAmount = $this->calculateDecliningBalance($asset, $openingBookValue);
                $decliningBalanceRateUsed = (float) $asset->declining_balance_rate;
                break;

            case 'units_of_production':
                $result = $this->calculateUnitsOfProduction($asset, $depreciableAmount, $unitsProduced);
                $depreciationAmount = $result['depreciation_amount'];
                $ratePerUnit = $result['rate_per_unit'];
                break;
        }

        // Ensure we don't depreciate below salvage value
        $maxDepreciation = $openingBookValue - (float) $asset->salvage_value;
        if ($depreciationAmount > $maxDepreciation) {
            $depreciationAmount = max(0, round($maxDepreciation, 2));
        }

        $closingBookValue = round($openingBookValue - $depreciationAmount, 2);

        return [
            'depreciation_amount' => round($depreciationAmount, 2),
            'opening_book_value' => $openingBookValue,
            'closing_book_value' => $closingBookValue,
            'rate_per_unit' => round($ratePerUnit, 6),
            'declining_balance_rate_used' => $decliningBalanceRateUsed,
            'units_produced' => $unitsProduced,
        ];
    }

    /**
     * Straight Line depreciation: (cost - salvage) / useful_life_months
     */
    private function calculateStraightLine(FixedAsset $asset, float $depreciableAmount): float
    {
        if ($asset->useful_life_months <= 0) {
            return 0;
        }
        return round($depreciableAmount / $asset->useful_life_months, 2);
    }

    /**
     * Declining Balance depreciation: book_value * (rate / 100) / 12
     *
     * The rate is annual, so we divide by 12 for monthly.
     * Common rates: 20% (double declining for 10yr), 25% (double declining for 8yr)
     */
    private function calculateDecliningBalance(FixedAsset $asset, float $bookValue): float
    {
        $annualRate = (float) $asset->declining_balance_rate;
        $monthlyRate = $annualRate / 12;
        return round($bookValue * ($monthlyRate / 100), 2);
    }

    /**
     * Units of Production depreciation: (cost - salvage) / total_estimated_units * units_this_period
     */
    private function calculateUnitsOfProduction(FixedAsset $asset, float $depreciableAmount, float $unitsProduced): array
    {
        $totalEstimatedUnits = (float) $asset->total_estimated_units;
        if ($totalEstimatedUnits <= 0) {
            return ['depreciation_amount' => 0, 'rate_per_unit' => 0];
        }

        $ratePerUnit = $depreciableAmount / $totalEstimatedUnits;
        $depreciationAmount = $ratePerUnit * $unitsProduced;

        return [
            'depreciation_amount' => round($depreciationAmount, 2),
            'rate_per_unit' => round($ratePerUnit, 6),
        ];
    }

    // ============================================================
    // SCHEDULE GENERATION
    // ============================================================

    /**
     * Generate a depreciation schedule record for an asset for a given period.
     *
     * @param FixedAsset $asset
     * @param string $periodFrom Y-m-d
     * @param string $periodTo Y-m-d
     * @param float $unitsProduced Units produced in this period (for units_of_production)
     * @return AssetDepreciationSchedule|null Null if no depreciation needed
     */
    public function generateSchedule(
        FixedAsset $asset,
        string $periodFrom,
        string $periodTo,
        float $unitsProduced = 0,
    ): ?AssetDepreciationSchedule {
        // Skip if asset is not active
        if (!$asset->canBeDepreciated()) {
            return null;
        }

        // Skip if a schedule already exists for this period
        $existing = AssetDepreciationSchedule::where('fixed_asset_id', $asset->id)
            ->where('period_from', $periodFrom)
            ->where('period_to', $periodTo)
            ->where('status', '!=', 'reversed')
            ->first();

        if ($existing) {
            return null; // Already scheduled for this period
        }

        $calculation = $this->calculateDepreciation($asset, $periodFrom, $periodTo, $unitsProduced);

        // Skip if no depreciation amount
        if ($calculation['depreciation_amount'] <= 0) {
            return null;
        }

        $depreciationDate = $periodTo; // Use end of period as the depreciation date

        $schedule = AssetDepreciationSchedule::create([
            'fixed_asset_id' => $asset->id,
            'depreciation_date' => $depreciationDate,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'depreciation_method' => $asset->depreciation_method,
            'opening_book_value' => $calculation['opening_book_value'],
            'depreciation_amount' => $calculation['depreciation_amount'],
            'closing_book_value' => $calculation['closing_book_value'],
            'units_produced' => $calculation['units_produced'],
            'rate_per_unit' => $calculation['rate_per_unit'],
            'declining_balance_rate_used' => $calculation['declining_balance_rate_used'],
            'status' => 'pending',
        ]);

        return $schedule;
    }

    /**
     * Generate schedules for all active assets for a given period.
     *
     * @param string $periodFrom Y-m-d
     * @param string $periodTo Y-m-d
     * @param int|null $branchId Optional branch filter
     * @return int Number of schedules generated
     */
    public function generateSchedulesForPeriod(string $periodFrom, string $periodTo, ?int $branchId = null): int
    {
        $query = FixedAsset::active();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $assets = $query->get();
        $generated = 0;

        foreach ($assets as $asset) {
            $schedule = $this->generateSchedule($asset, $periodFrom, $periodTo);
            if ($schedule) {
                $generated++;
            }
        }

        return $generated;
    }

    // ============================================================
    // POSTING METHODS
    // ============================================================

    /**
     * Post a single depreciation schedule to the GL.
     *
     * Journal Entry:
     *   Dr Depreciation Expense   (dep_expense_ledger_id)
     *   Cr Accumulated Depreciation (dep_ledger_id)
     *
     * The entire method body is wrapped in a single DB::transaction so that
     * if any step fails (e.g., the asset UPDATE is blocked by an RLS WITH
     * CHECK policy, or the schedule UPDATE throws), the JE creation + the
     * schedule status update are rolled back together. This preserves GL ↔
     * sub-ledger consistency (closes G13 / G-023).
     *
     * @param AssetDepreciationSchedule $schedule
     * @param int|null $userId
     * @return int The journal_entry_id
     * @throws \RuntimeException If schedule is already posted or asset is invalid
     */
    public function postDepreciation(AssetDepreciationSchedule $schedule, ?int $userId = null): int
    {
        return DB::transaction(function () use ($schedule, $userId) {
            if (!$schedule->isPending()) {
                throw new \RuntimeException("Schedule #{$schedule->id} is not pending (status: {$schedule->status}).");
            }

            $asset = $schedule->fixedAsset;
            if (!$asset) {
                throw new \RuntimeException("Asset not found for schedule #{$schedule->id}.");
            }

            // Resolve the depreciation expense ledger
            $depExpenseLedgerId = $asset->dep_expense_ledger_id
                ?? $this->natureService->resolveLedgerByNature('depreciation_expense');

            if (!$depExpenseLedgerId) {
                throw new \RuntimeException("No depreciation expense ledger found. Please configure L-0903 or assign a dep_expense_ledger_id to asset {$asset->asset_code}.");
            }

            // G-355 (G28) FINANCE-FA-1: mirror the BR23 dep_expense_ledger_id
            // fallback pattern. The 'accumulated_depreciation' nature is
            // registered in LedgerNatureService::EXTENDED_NATURES (lines
            // 195-200) and resolves to L-0250. The fallback is dormant in the
            // web path (FixedAssetController::store requires dep_ledger_id at
            // L153), but useful for artisan/seed/test paths that construct
            // assets directly without going through the controller.
            $depLedgerId = $asset->dep_ledger_id
                ?? $this->natureService->resolveLedgerByNature('accumulated_depreciation');
            if (!$depLedgerId) {
                throw new \RuntimeException("No accumulated depreciation ledger found for asset {$asset->asset_code}. Please configure L-0250 with the 'accumulated_depreciation' nature, or assign a dep_ledger_id to the asset.");
            }

            $depreciationAmount = (float) $schedule->depreciation_amount;

            if ($depreciationAmount <= 0) {
                throw new \RuntimeException("Depreciation amount is zero for schedule #{$schedule->id}.");
            }

            $userId = $userId ?? Auth::id();

            // Create the journal entry
            $journalEntryId = $this->journalService->createJournalEntry(
                [
                    'entry_date' => $schedule->depreciation_date->format('Y-m-d'),
                    'reference_type' => 'fixed_asset_depreciation',
                    'reference_id' => $asset->id,
                    'branch_id' => $asset->branch_id,
                    'description' => "Depreciation for {$asset->asset_code} - {$asset->description} ({$schedule->period_from} to {$schedule->period_to})",
                    'source' => 'fixed_asset_depreciation',
                    'created_by' => $userId,
                ],
                [
                    [
                        'ledger_id' => $depExpenseLedgerId,
                        'debit' => $depreciationAmount,
                        'credit' => 0,
                        'memo' => "Depreciation expense - {$asset->asset_code}",
                    ],
                    [
                        'ledger_id' => $depLedgerId,
                        'debit' => 0,
                        'credit' => $depreciationAmount,
                        'memo' => "Accumulated depreciation - {$asset->asset_code}",
                    ],
                ]
            );

            // Update the schedule
            $schedule->update([
                'journal_entry_id' => $journalEntryId,
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            // Update the asset's accumulated depreciation and book value
            $newAccumulatedDep = (float) $asset->accumulated_depreciation + $depreciationAmount;
            $newBookValue = (float) $asset->acquisition_cost - $newAccumulatedDep;

            // Check if fully depreciated
            $newStatus = $asset->status;
            if ($newBookValue <= (float) $asset->salvage_value + 0.01) {
                $newBookValue = (float) $asset->salvage_value;
                $newStatus = 'fully_depreciated';
            }

            $asset->update([
                'accumulated_depreciation' => $newAccumulatedDep,
                'net_book_value' => $newBookValue,
                'last_depreciation_date' => $schedule->depreciation_date,
                'status' => $newStatus,
            ]);

            // Update units produced for units_of_production method
            if ($asset->depreciation_method === 'units_of_production' && $schedule->units_produced > 0) {
                $asset->update([
                    'units_produced_to_date' => (float) $asset->units_produced_to_date + (float) $schedule->units_produced,
                ]);
            }

            return $journalEntryId;
        });
    }

    /**
     * Post all pending depreciation schedules for a given period.
     *
     * @param string $periodFrom Y-m-d
     * @param string $periodTo Y-m-d
     * @param int|null $branchId Optional branch filter
     * @return array{ posted: int, failed: int, errors: array }
     */
    public function postMonthlyDepreciation(string $periodFrom, string $periodTo, ?int $branchId = null): array
    {
        $query = AssetDepreciationSchedule::where('status', 'pending')
            ->where('period_from', '>=', $periodFrom)
            ->where('period_to', '<=', $periodTo);

        if ($branchId) {
            $query->whereHas('fixedAsset', fn($q) => $q->where('branch_id', $branchId));
        }

        $schedules = $query->get();

        $posted = 0;
        $failed = 0;
        $errors = [];

        foreach ($schedules as $schedule) {
            try {
                $this->postDepreciation($schedule);
                $posted++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'schedule_id' => $schedule->id,
                    'asset_code' => $schedule->fixedAsset?->asset_code ?? 'Unknown',
                    'error' => $e->getMessage(),
                ];
                Log::error('Depreciation posting failed', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'posted' => $posted,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    // ============================================================
    // REVERSAL METHODS
    // ============================================================

    /**
     * Reverse a posted depreciation entry.
     *
     * This reverses the journal entry and restores the asset's accumulated
     * depreciation and net book value.
     *
     * @param AssetDepreciationSchedule $schedule
     * @param int $userId
     * @param string $reason
     * @return int The reversal journal_entry_id
     */
    public function reverseDepreciation(AssetDepreciationSchedule $schedule, int $userId, string $reason = ''): int
    {
        if (!$schedule->isPosted()) {
            throw new \RuntimeException("Schedule #{$schedule->id} is not posted (status: {$schedule->status}).");
        }

        if (!$schedule->journal_entry_id) {
            throw new \RuntimeException("Schedule #{$schedule->id} has no linked journal entry.");
        }

        return DB::transaction(function () use ($schedule, $userId, $reason) {
            // G-109 (FINANCE-1): calls JournalPostingService::reverseJournalEntry
            // DIRECTLY (not JournalReversalService::reverseByJournalEntry).
            // Rationale: depreciation JEs have NO sub-ledger entries (no
            // customer/supplier/employee ledger rows reference them), so the
            // cascade that JournalReversalService performs is unnecessary.
            // This is an intentional, documented deviation from the canonical
            // reversal pattern in `accounting/reversal-vs-cancellation.md`
            // — see BR30. Same deviation in AssetDisposalService::reverseDisposal.
            $reversalId = $this->journalService->reverseJournalEntry(
                $schedule->journal_entry_id,
                $userId,
                "Reversal of depreciation: {$reason}"
            );

            // Restore the asset's accumulated depreciation and book value
            $asset = $schedule->fixedAsset;
            if ($asset) {
                $depreciationAmount = (float) $schedule->depreciation_amount;
                $newAccumulatedDep = max(0, (float) $asset->accumulated_depreciation - $depreciationAmount);
                $newBookValue = (float) $asset->acquisition_cost - $newAccumulatedDep;

                // If asset was fully depreciated, reactivate it
                $newStatus = $asset->status;
                if ($asset->isFullyDepreciated() && $newBookValue > (float) $asset->salvage_value + 0.01) {
                    $newStatus = 'active';
                }

                $asset->update([
                    'accumulated_depreciation' => $newAccumulatedDep,
                    'net_book_value' => $newBookValue,
                    'status' => $newStatus,
                ]);

                // Restore units produced for units_of_production method
                if ($asset->depreciation_method === 'units_of_production' && $schedule->units_produced > 0) {
                    $asset->update([
                        'units_produced_to_date' => max(0, (float) $asset->units_produced_to_date - (float) $schedule->units_produced),
                    ]);
                }
            }

            // Update the schedule
            $schedule->update([
                'status' => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reverse_reason' => $reason,
            ]);

            return $reversalId;
        });
    }

    // ============================================================
    // REPORTING METHODS
    // ============================================================

    /**
     * Get the depreciation schedule for an asset (all posted entries).
     *
     * @param FixedAsset $asset
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAssetDepreciationHistory(FixedAsset $asset)
    {
        return AssetDepreciationSchedule::where('fixed_asset_id', $asset->id)
            ->orderBy('period_from')
            ->get();
    }

    /**
     * Get a summary of all assets and their depreciation status for a branch.
     *
     * @param int|null $branchId
     * @return array
     */
    public function getAssetDepreciationSummary(?int $branchId = null): array
    {
        $query = FixedAsset::with(['assetLedger', 'depLedger', 'branch']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $assets = $query->get();

        $summary = [
            'total_assets' => $assets->count(),
            'total_cost' => $assets->sum('acquisition_cost'),
            'total_accumulated_depreciation' => $assets->sum('accumulated_depreciation'),
            'total_net_book_value' => $assets->sum('net_book_value'),
            'active_count' => $assets->where('status', 'active')->count(),
            'disposed_count' => $assets->where('status', 'disposed')->count(),
            'fully_depreciated_count' => $assets->where('status', 'fully_depreciated')->count(),
            'by_category' => $assets->groupBy('category')->map(fn($group) => [
                'count' => $group->count(),
                'total_cost' => $group->sum('acquisition_cost'),
                'total_accumulated_depreciation' => $group->sum('accumulated_depreciation'),
                'total_net_book_value' => $group->sum('net_book_value'),
            ])->toArray(),
        ];

        return $summary;
    }

    /**
     * Get the projected depreciation schedule for an asset (future periods).
     *
     * @param FixedAsset $asset
     * @param int $monthsAhead Number of months to project
     * @return array
     */
    public function getProjectedDepreciation(FixedAsset $asset, int $monthsAhead = 12): array
    {
        $projections = [];
        $currentBookValue = (float) $asset->net_book_value;
        $currentDate = $asset->last_depreciation_date
            ? \Carbon\Carbon::parse($asset->last_depreciation_date)->addMonth()
            : \Carbon\Carbon::parse($asset->acquisition_date)->addMonth();

        for ($i = 0; $i < $monthsAhead; $i++) {
            $periodFrom = $currentDate->copy()->startOfMonth()->format('Y-m-d');
            $periodTo = $currentDate->copy()->endOfMonth()->format('Y-m-d');

            $calculation = $this->calculateDepreciation(
                $asset->fresh(),
                $periodFrom,
                $periodTo
            );

            // For declining balance, use the projected book value
            if ($asset->depreciation_method === 'declining_balance') {
                $annualRate = (float) $asset->declining_balance_rate;
                $monthlyRate = $annualRate / 12;
                $depreciationAmount = round($currentBookValue * ($monthlyRate / 100), 2);

                $maxDepreciation = $currentBookValue - (float) $asset->salvage_value;
                if ($depreciationAmount > $maxDepreciation) {
                    $depreciationAmount = max(0, round($maxDepreciation, 2));
                }
            } else {
                $depreciationAmount = $calculation['depreciation_amount'];
            }

            if ($depreciationAmount <= 0) {
                break;
            }

            $projections[] = [
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'depreciation_amount' => $depreciationAmount,
                'opening_book_value' => $currentBookValue,
                'closing_book_value' => round($currentBookValue - $depreciationAmount, 2),
            ];

            $currentBookValue = round($currentBookValue - $depreciationAmount, 2);
            $currentDate->addMonth();
        }

        return $projections;
    }
}
