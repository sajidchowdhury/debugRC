<?php

namespace App\Services\Accounting;

use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\PeriodCloseLog;
use App\Services\Accounting\AccountingPeriodService;
use App\Support\FiscalYearResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * FiscalYearService — Phase 7: Enhanced Period & Fiscal Year Controls
 *
 * Manages the lifecycle of fiscal years and their periods:
 *   - Create fiscal years with auto-generated periods
 *   - Activate/close/lock fiscal years
 *   - Close/reopen individual periods with pre-close gate checks
 *   - Full audit trail via period_close_log
 *   - Backward compatible with existing accounting_periods table
 *
 * Inspired by SAP's fiscal year variant system and Xero's period lock feature.
 */
class FiscalYearService
{
    public function __construct(
        private AccountingPeriodService $legacyPeriodService,
        private SubLedgerService $subLedgerService
    ) {}

    // ── Fiscal Year CRUD ────────────────────────────────────────────

    /**
     * Create a new fiscal year with auto-generated periods.
     *
     * @param array $data { name, fiscal_year_code, start_date, end_date, branch_id?, period_type, description?, created_by }
     * @return FiscalYear
     */
    public function createFiscalYear(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = 'draft';
            $data['is_current'] = false;

            $fy = FiscalYear::create($data);

            // Generate periods based on period_type
            $this->generatePeriods($fy);

            return $fy->fresh('periods');
        });
    }

    /**
     * Activate a draft fiscal year. Makes it the current fiscal year.
     * Only one fiscal year can be current at a time (per branch or global).
     */
    public function activateFiscalYear(FiscalYear $fy): FiscalYear
    {
        if ($fy->status !== 'draft') {
            throw new \RuntimeException("Only draft fiscal years can be activated. Current status: {$fy->status}");
        }

        return DB::transaction(function () use ($fy) {
            // Deactivate any other current fiscal year for the same branch scope
            $query = FiscalYear::where('is_current', true);
            if ($fy->branch_id) {
                $query->where('branch_id', $fy->branch_id);
            } else {
                $query->whereNull('branch_id');
            }
            $query->update(['is_current' => false]);

            $fy->update([
                'status'      => 'active',
                'is_current'  => true,
            ]);

            // Session 2: invalidate the active-FY cache so the very next
            // request resolves to the newly-activated FY rather than the
            // previously-cached value (or null).
            FiscalYearResolver::clearCache();

            return $fy->fresh();
        });
    }

    /**
     * Close a fiscal year (year-end close). All periods must be closed first.
     * Delegates to AccountingPeriodService for the actual year-end journal entry.
     */
    public function closeFiscalYear(FiscalYear $fy, int $closedBy): array
    {
        if ($fy->status !== 'active') {
            throw new \RuntimeException("Only active fiscal years can be closed. Current status: {$fy->status}");
        }

        // Check all periods are closed or locked
        $openPeriods = $fy->periods()->where('status', 'open')->count();
        if ($openPeriods > 0) {
            throw new \RuntimeException("Cannot close fiscal year: {$openPeriods} period(s) are still open. Close all periods first.");
        }

        // Execute year-end close via legacy service
        $branchId = $fy->branch_id ?? (int) session('branch_id', 0);
        $yearEndResult = $this->legacyPeriodService->yearEndClose(
            $branchId,
            $fy->end_date->format('Y-m-d'),
            $closedBy
        );

        // Update fiscal year status
        $fy->update([
            'status'    => 'closed',
            'closed_by' => $closedBy,
            'closed_at' => now(),
        ]);

        // Lock all periods
        $fy->periods()->update(['status' => 'locked']);

        // Log the action
        $this->logAction(null, $fy, $branchId, 'close', $fy->start_date, $fy->end_date, $closedBy, 'Fiscal year closed');

        // Session 2: invalidate the active-FY cache. The closed FY is no
        // longer active, so the BelongsToFiscalYear global scope on every
        // operational model must NOT resolve to this FY id on the next
        // request. If a new FY was already activated (typical flow),
        // clearCache() lets the next activeId() call pick up the new FY.
        // If no new FY is active yet, the next request will fail-closed
        // with a clear "no active fiscal year" error — which is the
        // intended signal to the accountant to activate the next FY.
        FiscalYearResolver::clearCache();

        return $yearEndResult;
    }

    /**
     * Lock a fiscal year — prevents any changes (superadmin can unlock).
     */
    public function lockFiscalYear(FiscalYear $fy, int $lockedBy, string $reason): FiscalYear
    {
        if (!in_array($fy->status, ['active', 'closed'])) {
            throw new \RuntimeException("Cannot lock fiscal year with status: {$fy->status}");
        }

        $fy->update(['status' => 'locked']);
        $fy->periods()->update(['status' => 'locked']);

        $branchId = $fy->branch_id ?? (int) session('branch_id', 0);
        $this->logAction(null, $fy, $branchId, 'lock', $fy->start_date, $fy->end_date, $lockedBy, $reason);

        // Session 2: invalidate the active-FY cache. Locking a FY removes
        // it from the active candidate pool (status='active' filter in
        // FiscalYearResolver::activeId()), so the cached value (if any)
        // is now stale.
        FiscalYearResolver::clearCache();

        return $fy->fresh();
    }

    // ── Period Operations ───────────────────────────────────────────

    /**
     * Close an individual period. Runs pre-close gate checks.
     *
     * @param FiscalPeriod $period
     * @param int $closedBy
     * @param string $notes
     * @return array{ status: string, message: string, checks: array }
     */
    public function closePeriod(FiscalPeriod $period, int $closedBy, string $notes = ''): array
    {
        if (!$period->isOpen()) {
            return ['status' => 'error', 'message' => "Period is already {$period->status}."];
        }

        $fy = $period->fiscalYear;
        if (!$fy || !$fy->isActive()) {
            return ['status' => 'error', 'message' => 'Fiscal year must be active to close periods.'];
        }

        // Run pre-close gate checks (using legacy service)
        $branchId = $fy->branch_id ?? (int) session('branch_id', 0);
        $gate = $this->legacyPeriodService->preCloseGate($branchId, $period->end_date->format('Y-m-d'));

        if (!$gate['can_close']) {
            $failedChecks = collect($gate['checks'])->filter(fn($c) => !$c['passed'])->pluck('label')->toArray();
            return [
                'status'  => 'error',
                'message' => 'Pre-close gate failed: ' . implode('; ', $failedChecks),
                'checks'  => $gate['checks'],
            ];
        }

        // Close the period
        $previousState = ['status' => $period->status];
        $period->update([
            'status'      => 'closed',
            'closed_by'   => $closedBy,
            'closed_at'   => now(),
            'close_notes' => $notes,
        ]);

        // Also update the legacy accounting_periods table for backward compatibility
        DB::table('accounting_periods')->upsert(
            [
                'branch_id'           => $branchId,
                'closed_through_date' => $period->end_date->format('Y-m-d'),
                'closed_by'           => $closedBy,
                'closed_at'           => now(),
                'notes'               => $notes ?: "Period {$period->period_name} closed",
                'updated_at'          => now(),
            ],
            ['branch_id'],
            ['closed_through_date', 'closed_by', 'closed_at', 'notes', 'updated_at']
        );

        // Log the action
        $this->logAction($period, $fy, $branchId, 'close', $period->start_date, $period->end_date, $closedBy, $notes);

        Log::info('Fiscal period closed', [
            'fiscal_year_id' => $fy->id,
            'period_id'      => $period->id,
            'period_name'    => $period->period_name,
            'closed_by'      => $closedBy,
        ]);

        return [
            'status'  => 'success',
            'message' => "Period '{$period->period_name}' closed successfully.",
            'checks'  => $gate['checks'],
        ];
    }

    /**
     * Reopen a closed period. Requires admin/superadmin.
     * Locked periods cannot be reopened (only superadmin can unlock).
     */
    public function reopenPeriod(FiscalPeriod $period, int $reopenedBy, string $reason): array
    {
        if ($period->isLocked()) {
            return ['status' => 'error', 'message' => 'Locked periods cannot be reopened. Unlock the fiscal year first.'];
        }

        if (!$period->isClosed()) {
            return ['status' => 'error', 'message' => 'Only closed periods can be reopened.'];
        }

        $fy = $period->fiscalYear;
        $branchId = $fy->branch_id ?? (int) session('branch_id', 0);

        $previousState = ['status' => $period->status];
        $period->update([
            'status'      => 'open',
            'closed_by'   => null,
            'closed_at'   => null,
            'close_notes' => null,
        ]);

        // Update legacy accounting_periods table
        // Find the latest closed period before this one to set the correct closed_through_date
        $latestClosedPeriod = FiscalPeriod::where('fiscal_year_id', $fy->id)
            ->where('status', 'closed')
            ->orderByDesc('period_number')
            ->first();

        DB::table('accounting_periods')->where('branch_id', $branchId)->update([
            'closed_through_date' => $latestClosedPeriod ? $latestClosedPeriod->end_date->format('Y-m-d') : null,
            'updated_at'          => now(),
        ]);

        // Log the action
        $this->logAction($period, $fy, $branchId, 'reopen', $period->start_date, $period->end_date, $reopenedBy, $reason);

        Log::warning('Fiscal period reopened', [
            'fiscal_year_id' => $fy->id,
            'period_id'      => $period->id,
            'period_name'    => $period->period_name,
            'reopened_by'    => $reopenedBy,
            'reason'         => $reason,
        ]);

        return ['status' => 'success', 'message' => "Period '{$period->period_name}' reopened."];
    }

    // ── Period Generation ───────────────────────────────────────────

    /**
     * Generate periods for a fiscal year based on its period_type.
     */
    private function generatePeriods(FiscalYear $fy): void
    {
        match ($fy->period_type) {
            'quarterly' => $this->generateQuarterlyPeriods($fy),
            'yearly'    => $this->generateYearlyPeriod($fy),
            default     => $this->generateMonthlyPeriods($fy),
        };
    }

    /**
     * Generate 12 monthly periods for a fiscal year.
     */
    private function generateMonthlyPeriods(FiscalYear $fy): void
    {
        $start = Carbon::parse($fy->start_date);
        $end = Carbon::parse($fy->end_date);
        $periodNumber = 1;
        $current = $start->copy();

        while ($current->lte($end) && $periodNumber <= 12) {
            $periodStart = $current->copy()->startOfMonth();
            $periodEnd = $current->copy()->endOfMonth();

            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }

            FiscalPeriod::create([
                'fiscal_year_id' => $fy->id,
                'period_number'  => $periodNumber,
                'period_name'    => $current->format('F Y'),
                'start_date'     => $periodStart->format('Y-m-d'),
                'end_date'       => $periodEnd->format('Y-m-d'),
                'status'         => 'open',
            ]);

            $current->addMonth();
            $periodNumber++;
        }
    }

    /**
     * Generate 4 quarterly periods for a fiscal year.
     */
    private function generateQuarterlyPeriods(FiscalYear $fy): void
    {
        $start = Carbon::parse($fy->start_date);
        $end = Carbon::parse($fy->end_date);
        $periodNumber = 1;
        $current = $start->copy();

        while ($current->lte($end) && $periodNumber <= 4) {
            $periodStart = $current->copy()->startOfMonth();
            $periodEnd = $current->copy()->addMonths(2)->endOfMonth();

            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }

            FiscalPeriod::create([
                'fiscal_year_id' => $fy->id,
                'period_number'  => $periodNumber,
                'period_name'    => "Q{$periodNumber} " . $fy->fiscal_year_code,
                'start_date'     => $periodStart->format('Y-m-d'),
                'end_date'       => $periodEnd->format('Y-m-d'),
                'status'         => 'open',
            ]);

            $current->addMonths(3);
            $periodNumber++;
        }
    }

    /**
     * Generate 1 annual period for a fiscal year.
     */
    private function generateYearlyPeriod(FiscalYear $fy): void
    {
        FiscalPeriod::create([
            'fiscal_year_id' => $fy->id,
            'period_number'  => 1,
            'period_name'    => "Annual " . $fy->fiscal_year_code,
            'start_date'     => $fy->start_date->format('Y-m-d'),
            'end_date'       => $fy->end_date->format('Y-m-d'),
            'status'         => 'open',
        ]);
    }

    // ── Audit Logging ───────────────────────────────────────────────

    /**
     * Log a period/fiscal year action to the period_close_log table.
     */
    private function logAction(
        ?FiscalPeriod $period,
        ?FiscalYear $fy,
        ?int $branchId,
        string $action,
        $startDate,
        $endDate,
        int $performedBy,
        ?string $reason
    ): PeriodCloseLog {
        return PeriodCloseLog::create([
            'fiscal_period_id'  => $period?->id,
            'fiscal_year_id'    => $fy?->id,
            'branch_id'         => $branchId,
            'action'            => $action,
            'period_start_date' => $startDate,
            'period_end_date'   => $endDate,
            'performed_by'      => $performedBy,
            'reason'            => $reason,
            'previous_state'    => $period ? ['status' => $period->status] : null,
            'ip_address'        => request()?->ip(),
        ]);
    }

    // ── Query Helpers ───────────────────────────────────────────────

    /**
     * Get the current fiscal year for a given branch (or global).
     */
    public function getCurrentFiscalYear(?int $branchId = null): ?FiscalYear
    {
        $query = FiscalYear::where('is_current', true)
            ->where('status', 'active');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')
                  ->orWhere('branch_id', $branchId);
            });
        }

        return $query->first();
    }

    /**
     * Get the period that contains a given date.
     */
    public function getPeriodForDate(string $date, ?int $branchId = null): ?FiscalPeriod
    {
        return FiscalPeriod::whereHas('fiscalYear', function ($q) use ($branchId) {
            $q->where('status', 'active');
            if ($branchId) {
                $q->where(function ($sub) use ($branchId) {
                    $sub->whereNull('branch_id')
                        ->orWhere('branch_id', $branchId);
                });
            }
        })
        ->where('start_date', '<=', $date)
        ->where('end_date', '>=', $date)
        ->first();
    }

    /**
     * Check if a date is within an open period.
     * Used by JournalPostingService for enhanced period validation.
     */
    public function isDateInOpenPeriod(string $date, ?int $branchId = null): bool
    {
        $period = $this->getPeriodForDate($date, $branchId);
        return $period?->isOpen() ?? true; // If no period found, allow posting
    }

    /**
     * G-281 (G20) FINANCE-5: Assert that a posting date is within an open fiscal period.
     *
     * Throws RuntimeException if the date is NOT in an open period. Mirrors the
     * private ManualJournalService::assertPeriodOpen pattern but uses the public
     * FiscalYearService (which queries fiscal_periods.status, not just
     * accounting_periods.closed_through_date). Called by BranchDemandService
     * send/reprice paths to enforce the fiscal_period.status check that the
     * JournalPostingService::validatePeriod check does NOT cover.
     *
     * @param string $date Y-m-d posting date
     * @param int|null $branchId Optional branch filter
     * @throws \RuntimeException When the date is not in an open period
     */
    public function assertPeriodOpen(string $date, ?int $branchId = null): void
    {
        if (! $this->isDateInOpenPeriod($date, $branchId)) {
            throw new \RuntimeException(
                "Posting date {$date} is not within an open fiscal period"
                . ($branchId ? " for branch {$branchId}." : '.')
                . ' Reopen the period or use a different date.'
            );
        }
    }

    /**
     * Get the close log history for a fiscal year.
     */
    public function getCloseLogHistory(int $fiscalYearId, int $limit = 50): array
    {
        return PeriodCloseLog::where('fiscal_year_id', $fiscalYearId)
            ->with(['fiscalPeriod', 'performer', 'branch'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
