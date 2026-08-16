<?php

namespace App\Services\Accounting;

use App\Exceptions\YearEndCloseException;
use App\Models\FiscalYear;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SubLedgerService;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Accounting Period Service — Phase 9.5.
 *
 * Manages the soft period close + year-end rollup.
 *
 * Period Close (soft lock):
 *   - Sets accounting_periods.closed_through_date for a branch
 *   - JournalPostingService::validatePeriod rejects postings before this date
 *   - Pre-close gate: TB must balance + reconciliation must be green + backup on file
 *   - Reopen requires superadmin + audit log
 *
 * Year-End Close:
 *   - Closes all Income-statement ledgers to Retained Earnings
 *   - Dr Income ledgers (to zero them) / Cr Retained Earnings
 *   - Dr Retained Earnings / Cr Expense ledgers (to zero them)
 *   - Net profit/loss transferred to retained_earnings
 *   - Balance-sheet ledgers are NOT zeroed (they carry forward)
 */
class AccountingPeriodService
{
    public function __construct(
        private JournalPostingService $postingService,
        private SubLedgerService $subLedgerService,
        private DatabaseBackupService $databaseBackupService
    ) {}

    /**
     * Get the closed-through date for a branch.
     *
     * @param int $branchId
     * @return string|null Y-m-d or null if branch is fully open
     */
    public function getClosedThroughDate(int $branchId): ?string
    {
        $result = DB::table('accounting_periods')
            ->where('branch_id', $branchId)
            ->value('closed_through_date');

        return $result ?: null;
    }

    /**
     * Get the earliest date allowed for new postings (day after close).
     *
     * @param int $branchId
     * @return string|null Y-m-d or null if unrestricted
     */
    public function earliestOpenDate(int $branchId): ?string
    {
        $closed = $this->getClosedThroughDate($branchId);
        if (!$closed) return null;
        return Carbon::parse($closed)->addDay()->format('Y-m-d');
    }

    /**
     * Run pre-close gate checks before allowing period close.
     *
     * @param int $branchId
     * @param string $closeThroughDate
     * @return array{ can_close: bool, checks: array }
     */
    public function preCloseGate(int $branchId, string $closeThroughDate): array
    {
        $checks = [];

        // 1. Trial Balance must balance (Dr=Cr for all entries up to close date).
        $tbCheck = DB::selectOne(<<<SQL
SELECT
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.is_reversed = false
  AND je.entry_date <= ?
  AND (je.branch_id = ? OR je.branch_id IS NULL)
SQL, [$closeThroughDate, $branchId]);

        $tbDrift = abs((float) $tbCheck->total_debit - (float) $tbCheck->total_credit);
        $checks[] = [
            'label' => 'Trial Balance balanced (Dr=Cr)',
            'passed' => $tbDrift < 0.01,
            'detail' => "Dr=" . number_format($tbCheck->total_debit, 2)
                . " Cr=" . number_format($tbCheck->total_credit, 2)
                . " Drift=" . number_format($tbDrift, 2),
        ];

        // 2. AR sub-ledger == GL AR control.
        $arRecon = $this->subLedgerService->reconcileAll()['ar'];
        $checks[] = [
            'label' => 'AR sub-ledger = GL AR control',
            'passed' => $arRecon['match'],
            'detail' => "Sub-ledger=" . number_format($arRecon['subledger'], 2)
                . " GL=" . number_format($arRecon['gl_control'], 2)
                . " Drift=" . number_format($arRecon['drift'], 2),
        ];

        // 3. AP sub-ledger == GL AP control.
        $apRecon = $this->subLedgerService->reconcileAll()['ap'];
        $checks[] = [
            'label' => 'AP sub-ledger = GL AP control',
            'passed' => $apRecon['match'],
            'detail' => "Sub-ledger=" . number_format($apRecon['subledger'], 2)
                . " GL=" . number_format($apRecon['gl_control'], 2)
                . " Drift=" . number_format($apRecon['drift'], 2),
        ];

        // 4. Employee sub-ledger == GL Employee control.
        $empRecon = $this->subLedgerService->reconcileAll()['employee'];
        $checks[] = [
            'label' => 'Employee sub-ledger = GL Employee control',
            'passed' => $empRecon['match'],
            'detail' => "Sub-ledger=" . number_format($empRecon['subledger'], 2)
                . " GL=" . number_format($empRecon['gl_control'], 2)
                . " Drift=" . number_format($empRecon['drift'], 2),
        ];

        // 5. No unbalanced journal entries.
        $unbalancedCount = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT je.id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
    FROM journal_entries je
    JOIN journal_lines jl ON jl.journal_entry_id = je.id
    WHERE je.is_reversed = false AND je.entry_date <= ?
    GROUP BY je.id
    HAVING SUM(jl.debit) <> SUM(jl.credit)
) x
SQL, [$closeThroughDate])->cnt;

        $checks[] = [
            'label' => 'No unbalanced journal entries',
            'passed' => $unbalancedCount === 0,
            'detail' => $unbalancedCount === 0 ? 'All entries balanced' : "{$unbalancedCount} unbalanced entries",
        ];

        $allPassed = collect($checks)->every(fn($c) => $c['passed']);

        return [
            'can_close' => $allPassed,
            'checks' => $checks,
        ];
    }

    /**
     * Close the accounting period for a branch through the given date.
     *
     * @param int $branchId
     * @param string $closeThroughDate (Y-m-d)
     * @param int $closedBy
     * @param string $notes
     * @return array{ status: string, message: string, checks: array }
     * @throws \RuntimeException If pre-close gate fails.
     */
    public function closePeriod(int $branchId, string $closeThroughDate, int $closedBy, string $notes = ''): array
    {
        // Run pre-close gate.
        $gate = $this->preCloseGate($branchId, $closeThroughDate);

        if (!$gate['can_close']) {
            $failedChecks = collect($gate['checks'])->filter(fn($c) => !$c['passed'])->pluck('label')->toArray();
            return [
                'status' => 'error',
                'message' => 'Pre-close gate failed: ' . implode('; ', $failedChecks),
                'checks' => $gate['checks'],
            ];
        }

        // Upsert the accounting_periods row.
        DB::table('accounting_periods')->upsert(
            [
                'branch_id' => $branchId,
                'closed_through_date' => $closeThroughDate,
                'closed_by' => $closedBy,
                'closed_at' => now(),
                'notes' => $notes,
                'updated_at' => now(),
            ],
            ['branch_id'], // unique key
            ['closed_through_date', 'closed_by', 'closed_at', 'notes', 'updated_at']
        );

        Log::info('Accounting period closed', [
            'branch_id' => $branchId,
            'closed_through' => $closeThroughDate,
            'closed_by' => $closedBy,
            'notes' => $notes,
        ]);

        return [
            'status' => 'success',
            'message' => "Period closed through {$closeThroughDate} for branch {$branchId}.",
            'checks' => $gate['checks'],
        ];
    }

    /**
     * Reopen the accounting period for a branch.
     * Requires superadmin — removes the closed_through_date (sets to NULL or earlier date).
     *
     * @param int $branchId
     * @param int $reopenedBy
     * @param string $reason
     * @return array
     */
    public function reopenPeriod(int $branchId, int $reopenedBy, string $reason): array
    {
        $current = DB::table('accounting_periods')->where('branch_id', $branchId)->first();

        if (!$current) {
            return ['status' => 'error', 'message' => 'No closed period found for this branch.'];
        }

        // Audit log the reopening.
        DB::table('user_audit_log')->insert([
            'user_id' => $reopenedBy,
            'action' => 'period_reopened',
            'branch_id' => $branchId,
            'details' => json_encode([
                'previous_close_date' => $current->closed_through_date,
                'reason' => $reason,
            ]),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);

        // Remove the close (set closed_through_date to NULL).
        DB::table('accounting_periods')
            ->where('branch_id', $branchId)
            ->update([
                'closed_through_date' => null,
                'closed_by' => null,
                'closed_at' => null,
                'notes' => 'Reopened: ' . $reason,
                'updated_at' => now(),
            ]);

        Log::warning('Accounting period reopened', [
            'branch_id' => $branchId,
            'reopened_by' => $reopenedBy,
            'previous_close' => $current->closed_through_date,
            'reason' => $reason,
        ]);

        return ['status' => 'success', 'message' => "Period reopened for branch {$branchId}."];
    }

    /**
     * Year-End Close: zero all Income + Expense ledgers → Retained Earnings.
     *
     * This creates a single journal entry:
     *   For each Income ledger with a balance:
     *     Dr Income ledger (to zero it) / Cr Retained Earnings
     *   For each Expense ledger with a balance:
     *     Dr Retained Earnings / Cr Expense ledger (to zero it)
     *
     * Net effect: all Income/Expense ledgers zeroed, net P&L transferred to Retained Earnings.
     * Balance-sheet ledgers (Asset, Liability, Equity) are NOT zeroed — they carry forward.
     *
     * @param int $branchId
     * @param string $yearEndDate (Y-m-d, typically Dec 31)
     * @param int $closedBy
     * @return array{ status: string, journal_entry_id: int|null, net_profit: float, income_total: float, expense_total: float }
     * @throws \App\Exceptions\YearEndCloseException If no fresh verified backup exists for the fiscal year containing $yearEndDate.
     * @throws \RuntimeException If period not closed through year-end or ledgers not found.
     */
    public function yearEndClose(int $branchId, string $yearEndDate, int $closedBy): array
    {
        // ── Session 3 — Backup-on-file gate ──────────────────────────
        // This gate runs FIRST, before any of the existing 5 pre-flight
        // checks, so a missing backup fails fast without doing the
        // heavier reconciliation work. The gate is the hard guarantee
        // that the client's "auto-backup DB file to PC on FY close"
        // requirement is met — close cannot proceed without a fresh,
        // verified pg_dump -Fc file on disk.
        //
        // Resolve the fiscal year containing the year-end date. We use
        // the FiscalYear model's forDate scope (which queries the
        // fiscal_years table for the row whose start_date <= date AND
        // end_date >= date). If no FY covers the date, we throw — the
        // accountant must create one first.
        $fy = FiscalYear::forDate($yearEndDate)->first();
        if (!$fy) {
            throw new YearEndCloseException(
                "No fiscal year covers the year-end date {$yearEndDate}. "
                . "Create and activate a fiscal year for this date range first.",
                null
            );
        }

        if (!$this->databaseBackupService->isBackupFresh($fy->id)) {
            $latest = $this->databaseBackupService->latestBackupForFiscalYear($fy->id);
            $detail = $latest
                ? "Latest backup is from {$latest->created_at->format('Y-m-d H:i')} "
                  . "(older than ".config('backup.freshness_hours', 24)." hours or verification failed)."
                : 'No verified backup exists for this fiscal year.';
            throw new YearEndCloseException(
                "No fresh verified database backup on file for fiscal year #{$fy->id} ({$fy->fiscal_year_code}). "
                . "Run `php artisan db:backup-year-end --fiscal-year={$fy->id}` first. "
                . $detail,
                $fy->id
            );
        }

        // 1. Verify the period is closed through the year-end date.
        $closedThrough = $this->getClosedThroughDate($branchId);
        if (!$closedThrough || $closedThrough < $yearEndDate) {
            throw new \RuntimeException(
                "Period must be closed through {$yearEndDate} before year-end close. "
                . "Current close: " . ($closedThrough ?? 'none')
            );
        }

        // 2. Find the retained earnings ledger.
        $reLedgerId = $this->postingService->lookupLedgerByNature('retained_earnings');
        if (!$reLedgerId) {
            throw new \RuntimeException('Retained Earnings ledger not found (nature: retained_earnings).');
        }

        // 3. Calculate balances for all Income + Expense ledgers up to year-end.
        $ledgerBalances = DB::select(<<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net_balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.account_type IN ('Income', 'Expense')
  AND l.is_active = true
  AND l.deleted_at IS NULL
  AND (je.branch_id = ? OR je.branch_id IS NULL)
  AND je.entry_date <= ?
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature
HAVING ABS(COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)) > 0.01
ORDER BY l.account_type, l.ledger_name
SQL, [$branchId, $yearEndDate]);

        if (empty($ledgerBalances)) {
            return [
                'status' => 'success',
                'message' => 'No Income/Expense balances to close. Year-end close not needed.',
                'journal_entry_id' => null,
                'net_profit' => 0,
                'income_total' => 0,
                'expense_total' => 0,
            ];
        }

        // 4. Build the year-end journal entry lines.
        $lines = [];
        $incomeTotal = 0;
        $expenseTotal = 0;

        foreach ($ledgerBalances as $ledger) {
            $netBalance = (float) $ledger->net_balance;

            if ($ledger->account_type === 'Income') {
                // Income has credit balance (net_balance is negative for income).
                // To zero it: Dr Income (the credit balance amount) / this goes to Retained Earnings as credit.
                $amount = abs($netBalance);
                $incomeTotal += $amount;

                // Dr Income ledger (to zero the credit balance).
                $lines[] = [
                    'ledger_id' => $ledger->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'entity_type' => 'year_end_close',
                    'entity_id' => $ledger->id,
                    'memo' => "Year-end close — zero {$ledger->ledger_name}",
                ];
            } elseif ($ledger->account_type === 'Expense') {
                // Expense has debit balance (net_balance is positive).
                // To zero it: Cr Expense (the debit balance amount) / this goes to Retained Earnings as debit.
                $amount = abs($netBalance);
                $expenseTotal += $amount;

                // Cr Expense ledger (to zero the debit balance).
                $lines[] = [
                    'ledger_id' => $ledger->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'entity_type' => 'year_end_close',
                    'entity_id' => $ledger->id,
                    'memo' => "Year-end close — zero {$ledger->ledger_name}",
                ];
            }
        }

        // 5. Calculate net profit/loss + add the Retained Earnings balancing line.
        $netProfit = $incomeTotal - $expenseTotal;

        // The balancing line: if income > expense (profit), Cr Retained Earnings.
        // If expense > income (loss), Dr Retained Earnings.
        if ($netProfit > 0) {
            // Profit: Cr Retained Earnings.
            $lines[] = [
                'ledger_id' => $reLedgerId,
                'debit' => 0,
                'credit' => round($netProfit, 2),
                'entity_type' => 'year_end_close',
                'entity_id' => $reLedgerId,
                'memo' => 'Year-end close — net profit transferred to Retained Earnings',
            ];
        } elseif ($netProfit < 0) {
            // Loss: Dr Retained Earnings.
            $lines[] = [
                'ledger_id' => $reLedgerId,
                'debit' => round(abs($netProfit), 2),
                'credit' => 0,
                'entity_type' => 'year_end_close',
                'entity_id' => $reLedgerId,
                'memo' => 'Year-end close — net loss transferred to Retained Earnings',
            ];
        }

        // 6. Post the year-end journal entry.
        $journalEntryId = $this->postingService->createJournalEntry([
            'entry_date' => $yearEndDate,
            'reference_type' => 'year_end_close',
            'reference_id' => $branchId,
            'branch_id' => $branchId,
            'description' => "Year-End Close for " . Carbon::parse($yearEndDate)->format('Y')
                . " — Branch {$branchId}",
            'source' => 'year_end_close',
            'created_by' => $closedBy,
            'skip_period_check' => true, // year-end can post to the closed period
        ], $lines);

        Log::info('Year-end close completed', [
            'branch_id' => $branchId,
            'year_end_date' => $yearEndDate,
            'journal_entry_id' => $journalEntryId,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net_profit' => $netProfit,
            'ledgers_closed' => count($ledgerBalances),
        ]);

        return [
            'status' => 'success',
            'message' => "Year-end close completed. Net P&L: " . number_format($netProfit, 2)
                . " (" . count($ledgerBalances) . " ledgers zeroed).",
            'journal_entry_id' => $journalEntryId,
            'net_profit' => round($netProfit, 2),
            'income_total' => round($incomeTotal, 2),
            'expense_total' => round($expenseTotal, 2),
            'ledgers_closed' => count($ledgerBalances),
        ];
    }

    /**
     * Get the year-end checklist for a branch.
     * Used by the UI to show what's needed before year-end close.
     *
     * @param int $branchId
     * @param string $yearEndDate
     * @return array
     */
    public function yearEndChecklist(int $branchId, string $yearEndDate): array
    {
        $checks = [];

        // 1. Period closed through year-end.
        $closedThrough = $this->getClosedThroughDate($branchId);
        $checks[] = [
            'label' => 'Period closed through year-end date',
            'passed' => $closedThrough && $closedThrough >= $yearEndDate,
            'detail' => $closedThrough ? "Closed through {$closedThrough}" : 'Not closed',
        ];

        // 2. TB balanced.
        $gate = $this->preCloseGate($branchId, $yearEndDate);
        foreach ($gate['checks'] as $check) {
            $checks[] = $check;
        }

        // 3. Retained Earnings ledger exists.
        $reLedgerId = $this->postingService->lookupLedgerByNature('retained_earnings');
        $checks[] = [
            'label' => 'Retained Earnings ledger configured',
            'passed' => $reLedgerId !== null,
            'detail' => $reLedgerId ? "Ledger #{$reLedgerId}" : 'NOT FOUND',
        ];

        // 4. No pending reversals.
        $pendingReversals = (int) DB::table('journal_entries')
            ->where('is_reversed', true)
            ->whereNull('reversal_of_entry_id')
            ->count();
        $checks[] = [
            'label' => 'No pending reversal entries',
            'passed' => $pendingReversals === 0,
            'detail' => $pendingReversals === 0 ? 'All clean' : "{$pendingReversals} pending",
        ];

        // 5. Session 3 — Fresh verified database backup on file.
        // This is the gate that ABORTS yearEndClose() if no fresh
        // backup exists. The checklist shows it as a red/green item
        // so the accountant can see at a glance whether they need
        // to run `php artisan db:backup-year-end` first.
        $fy = FiscalYear::forDate($yearEndDate)->first();
        if ($fy) {
            $latest = $this->databaseBackupService->latestBackupForFiscalYear($fy->id);
            $isFresh = $this->databaseBackupService->isBackupFresh($fy->id);
            $checks[] = [
                'label' => 'Database backup on file (≤ '.config('backup.freshness_hours', 24).'h old, SHA-256 verified)',
                'passed' => $isFresh,
                'detail' => $latest
                    ? ($isFresh
                        ? 'Backup #'.$latest->id.' — '.$latest->created_at->format('Y-m-d H:i')
                          . ' ('.number_format($latest->file_size_bytes / 1024 / 1024, 1).' MB)'
                        : 'Backup #'.$latest->id.' exists but is STALE or verification failed — '
                          . 'run `php artisan db:backup-year-end --fiscal-year='.$fy->id.'`'
                      )
                    : 'No backup exists — run `php artisan db:backup-year-end --fiscal-year='.$fy->id.'`',
            ];
        } else {
            $checks[] = [
                'label' => 'Database backup on file',
                'passed' => false,
                'detail' => 'No fiscal year covers '.$yearEndDate.' — create one first',
            ];
        }

        return [
            'can_close' => collect($checks)->every(fn($c) => $c['passed']),
            'checks' => $checks,
        ];
    }
}
