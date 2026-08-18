<?php

namespace App\Services\Accounting;

use App\Services\Compliance\SystemPolicyService;
use App\Support\FiscalYearResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Journal Posting Service — Phase 9.2 (full version).
 *
 * The crown jewel of the accounting engine. Creates journal_entries +
 * journal_lines with Dr=Cr enforcement (DB trigger + service validation),
 * atomic entry number generation, period validation, and all posting methods
 * used by every business module.
 *
 * Core methods:
 *   - createJournalEntry(): the atomic create (Dr=Cr, entry_no, posting log)
 *   - reverseJournalEntry(): append-only reversal (swap Dr/Cr, mark original)
 *   - lookupLedgerByNature(): resolve a nature to its ledger_id
 *   - validatePeriod(): check posting date is within an open period
 *
 * All business modules (sales, purchase, stock, accounting) call
 * createJournalEntry() with the appropriate lines — they don't call
 * the posting methods directly. The posting methods are convenience
 * wrappers that build the correct lines for each business event.
 *
 * AUDIT-TRAIL-3 (G-175): createJournalEntry() calls
 * SystemPolicyService::assertWriteAllowed() at the top — this is the single
 * GL chokepoint (reverseJournalEntry calls it internally, so reversals are
 * also blocked). When INVESTIGATION mode is active, ALL GL postings are
 * blocked with SystemPolicyWriteBlockedException (caught by the
 * bootstrap/app.php exception handler — 422 JSON for API/AJAX,
 * redirect-back-with-error for web). This is the service-layer
 * defense-in-depth that catches writes bypassing HTTP middleware (console
 * commands, queued jobs, scheduled tasks).
 *
 * See docs/migration/journal_posting_rules.md for the full rules.
 */
class JournalPostingService
{
    public function __construct(
        private LedgerNatureService $natureService,
        private SystemPolicyService $policyService
    ) {}

    // ============================================================
    // CORE METHODS
    // ============================================================

    /**
     * Create a balanced journal entry with the given lines.
     *
     * Validates: Dr=Cr, lines non-empty, period open, ledger active.
     * Generates atomic entry_no via DocumentSequenceService (advisory locks — Task 20).
     * Logs the posting to journal_posting_logs.
     *
     * @param array $entry {
     *     entry_date: string (Y-m-d),
     *     reference_type: string,
     *     reference_id: int,
     *     branch_id: int,
     *     description: string,
     *     source: string (default 'manual'),
     *     created_by: int|null,
     *     skip_period_check: bool (default false — for reversals/replay),
     * }
     * @param array $lines [] each: { ledger_id: int, debit: float, credit: float, memo: string|null, entity_type: string|null, entity_id: int|null }
     * @return int The journal_entry_id.
     * @throws \RuntimeException If Dr≠Cr, lines empty, period closed, or ledger inactive.
     */
    public function createJournalEntry(array $entry, array $lines): int
    {
        // AUDIT-TRAIL-3 (G-175): enforce the INVESTIGATION-mode write freeze
        // at the GL chokepoint. reverseJournalEntry() calls this method
        // internally, so reversals are also blocked. Console commands, queued
        // jobs, and scheduled tasks that bypass the HTTP middleware are
        // caught here. Fail-open if the policy lookup itself throws (cache
        // outage) — see SystemPolicyService::assertWriteAllowed().
        $this->policyService->assertWriteAllowed(
            'journal_entry_create',
            $entry['reference_type'] ?? null
        );

        // 1. Validate balance: Dr must equal Cr.
        $totalDebit = round((float) collect($lines)->sum('debit'), 2);
        $totalCredit = round((float) collect($lines)->sum('credit'), 2);

        if (empty($lines)) {
            throw new \RuntimeException('Journal entry must have at least one line.');
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new \RuntimeException(
                "Journal entry not balanced: debits={$totalDebit} credits={$totalCredit} (diff=" . ($totalDebit - $totalCredit) . ")"
            );
        }

        // 2. Validate period (unless skipped for reversals/replay).
        if (empty($entry['skip_period_check']) && !empty($entry['branch_id'])) {
            $this->validatePeriod($entry['entry_date'] ?? now()->format('Y-m-d'), (int) $entry['branch_id']);
        }

        // 3. Validate all ledger_ids are active.
        $ledgerIds = array_unique(array_map(fn($l) => (int) $l['ledger_id'], $lines));
        $activeCount = DB::table('ledgers')
            ->whereIn('id', $ledgerIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        if ($activeCount !== count($ledgerIds)) {
            $inactiveIds = array_diff($ledgerIds, DB::table('ledgers')
                ->whereIn('id', $ledgerIds)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray()
            );
            throw new \RuntimeException(
                "Journal entry references inactive ledger(s): " . implode(', ', $inactiveIds)
            );
        }

        // 4. Generate atomic entry number.
        $entryNo = $this->generateEntryNo();
        $entryDate = $entry['entry_date'] ?? now()->format('Y-m-d');

        // 5. Insert the journal entry.
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'entry_no' => $entryNo,
            'entry_date' => $entryDate,
            'reference_type' => $entry['reference_type'] ?? null,
            'reference_id' => $entry['reference_id'] ?? null,
            'branch_id' => $entry['branch_id'] ?? null,
            'description' => $entry['description'] ?? null,
            'source' => $entry['source'] ?? 'manual',
            'is_reversed' => false,
            'created_by' => $entry['created_by'] ?? null,
            'fiscal_year_id' => FiscalYearResolver::activeId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Insert the journal lines.
        $lineRows = [];
        foreach ($lines as $line) {
            $lineRows[] = [
                'journal_entry_id' => $journalEntryId,
                'entry_date' => $entryDate,   // Phase 6.2: denormalized for partition-wise joins
                'ledger_id' => (int) $line['ledger_id'],
                'debit' => (float) ($line['debit'] ?? 0),
                'credit' => (float) ($line['credit'] ?? 0),
                'entity_type' => $line['entity_type'] ?? null,
                'entity_id' => $line['entity_id'] ?? null,
                'memo' => $line['memo'] ?? null,
                'dimension_value_id' => $line['dimension_value_id'] ?? null,
                'fiscal_year_id' => FiscalYearResolver::activeId(),
            ];
        }
        DB::table('journal_lines')->insert($lineRows);

        // 7. Log the posting.
        DB::table('journal_posting_logs')->insert([
            'journal_entry_id' => $journalEntryId,
            'action' => 'posted',
            'performed_by' => $entry['created_by'] ?? null,
            'performed_at' => now(),
            'remarks' => $entry['source'] ?? 'manual',
        ]);

        // 8. REPORTS-AUDIT-7 (G-238 / materialized-views.md G15): optionally
        // refresh report MVs after the posting. Default OFF (the 5-minute
        // scheduler is the canonical refresh path). When the config flag is
        // true, the refresh runs synchronously here — wrapped in try/catch
        // so a refresh failure does NOT roll back the journal posting (the
        // JE + lines are already committed; the MV refresh is best-effort).
        if (config('reports.dashboard.refresh_mvs_after_posting', false)) {
            try {
                app(\App\Services\Reports\ReportService::class)->refreshMaterializedViews();
            } catch (\Throwable $e) {
                Log::warning('JournalPostingService: on-demand MV refresh failed (non-fatal — scheduler will catch up)', [
                    'journal_entry_id' => $journalEntryId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $journalEntryId;
    }

    /**
     * Reverse a journal entry (swap debits/credits, mark original is_reversed).
     * Append-only: the original is never mutated (except the is_reversed flag).
     *
     * Phase 6.3 (Stock Adjustment plan — back-dated reversal date, G10):
     *   The reversal JE's `entry_date` is now taken from $entryDate (defaults
     *   to the ORIGINAL JE's entry_date so the reversal lines up with the
     *   posting it undoes — not "today", which used to distort historical
     *   P&L). skip_period_check stays true (reversals can post to closed
     *   periods — they're corrective, not new postings); the caller is
     *   responsible for choosing a sensible date.
     *
     * @param int $journalEntryId
     * @param int $reversedBy
     * @param string $reason
     * @param string|null $entryDate  Y-m-d. Defaults to the original JE's
     *     entry_date (so a back-dated Jan-1 posting reverses on Jan-1).
     * @return int The reversal journal_entry_id.
     */
    public function reverseJournalEntry(
        int $journalEntryId,
        int $reversedBy,
        string $reason = '',
        ?string $entryDate = null
    ): int {
        return DB::transaction(function () use ($journalEntryId, $reversedBy, $reason, $entryDate) {
            $original = DB::table('journal_entries')
                ->where('id', $journalEntryId)
                ->lockForUpdate()
                ->first();

            if (!$original) {
                throw new \RuntimeException("Journal entry {$journalEntryId} not found.");
            }
            if ($original->is_reversed) {
                throw new \RuntimeException("Journal entry {$journalEntryId} is already reversed.");
            }

            $originalLines = DB::table('journal_lines')
                ->where('journal_entry_id', $journalEntryId)
                ->get();

            // Build reversal lines (swap debit/credit).
            $reversalLines = $originalLines->map(function ($line) {
                return [
                    'ledger_id' => $line->ledger_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'entity_type' => $line->entity_type,
                    'entity_id' => $line->entity_id,
                    'memo' => 'Reversal: ' . ($line->memo ?? ''),
                ];
            })->toArray();

            // Phase 6.3 — reversal entry_date defaults to the ORIGINAL entry's
            // date (not today), so the reversal lines up with the posting it
            // undoes. The caller (e.g. StockAdjustmentService::cancelAdjustment)
            // passes the adjustment's adjustment_date. skip_period_check stays
            // true — reversals are corrective and may post into closed periods.
            $resolvedEntryDate = $entryDate ?? ($original->entry_date ?? now()->format('Y-m-d'));

            // Create the reversal entry (skip period check — reversals can post to closed periods).
            $reversalId = $this->createJournalEntry([
                'entry_date' => $resolvedEntryDate,
                'reference_type' => 'reversal',
                'reference_id' => $original->id,
                'branch_id' => $original->branch_id,
                'description' => 'Reversal of JE ' . $original->entry_no . ($reason ? ": {$reason}" : ''),
                'source' => 'reversal',
                'created_by' => $reversedBy,
                'skip_period_check' => true,
            ], $reversalLines);

            // Mark the original as reversed.
            DB::table('journal_entries')
                ->where('id', $journalEntryId)
                ->update([
                    'is_reversed' => true,
                    'reversal_of_entry_id' => $reversalId,
                    'reversed_at' => now(),
                    'reversed_by' => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at' => now(),
                ]);

            // Log the reversal.
            DB::table('journal_posting_logs')->insert([
                'journal_entry_id' => $journalEntryId,
                'action' => 'reversed',
                'performed_by' => $reversedBy,
                'performed_at' => now(),
                'remarks' => "Reversed by JE #{$reversalId}: {$reason}",
            ]);

            return $reversalId;
        });
    }

    /**
     * Look up the active ledger_id for a given ledger_nature.
     * Uses LedgerNatureService for resolution (with damage_loss fallback).
     *
     * @param string $nature e.g. 'inventory', 'ar', 'ap', 'cogs'
     * @return int|null
     */
    public function lookupLedgerByNature(string $nature): ?int
    {
        return $this->natureService->resolveLedgerByNature($nature);
    }

    /**
     * Convenience wrapper: post a journal entry from a single array.
     *
     * Accepts the same structure as createJournalEntry() but with `lines`
     * embedded in the entry array. This is the preferred call format for
     * business modules (employee transactions, money transfers, etc.).
     *
     * @param array $data {
     *     entry_date: string (Y-m-d),
     *     reference_type: string,
     *     reference_id: int,
     *     branch_id: int,
     *     description: string,
     *     source: string,
     *     created_by: int|null,
     *     lines: array of { ledger_id: int, debit: float, credit: float, memo: string|null },
     * }
     * @return int The journal_entry_id.
     */
    public function postJournalEntry(array $data): int
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        return $this->createJournalEntry($data, $lines);
    }

    /**
     * Post commission expense GL entry: Dr commission_expense / Cr commission_payable.
     *
     * Called by CommissionService::confirmPeriod at period-end to recognize the
     * commission liability for a salesman's net confirmed entries.
     *
     * Requires the `commission_expense` (Dr, Expense) and `commission_payable`
     * (Cr, Liability) ledger natures to be registered in LedgerNatureService
     * (EXTENDED_NATURES) AND resolved to active ledgers in the chart of
     * accounts. Without both, the posting throws with an actionable message
     * (rather than the previous BadMethodCallException — sales G1).
     *
     * A negative net commission (return reversals exceed earnings for the
     * period) swaps Dr/Cr: Dr commission_payable / Cr commission_expense,
     * reducing both the liability and the expense.
     *
     * @param array $data {
     *     amount: float (net commission — may be negative for net-reversal periods),
     *     salesman_name: string (for the line memo),
     *     period: string (e.g. '2025-01' — used in description + reference),
     *     description: string,
     *     salesman_id: int|null (used as reference_id for traceability),
     *     branch_id: int|null (defaults null → skips per-branch period check;
     *         commission confirmation is an admin action that may span branches),
     *     created_by: int|null,
     * }
     * @return object { id: int } The new journal_entry_id, wrapped to match
     *     the `$je->id` access pattern in CommissionService::confirmPeriod.
     * @throws \RuntimeException If a commission ledger nature is not registered
     *     or resolves to no active ledger, or if the JE fails Dr=Cr / balance
     *     validation (delegated to createJournalEntry).
     */
    public function postCommissionExpense(array $data): object
    {
        $amount       = (float) ($data['amount'] ?? 0);
        $period       = (string) ($data['period'] ?? now()->format('Y-m'));
        $description  = $data['description'] ?? "Commission for {$period}";
        $salesmanName = $data['salesman_name'] ?? '';
        $salesmanId   = $data['salesman_id'] ?? null;
        $branchId     = $data['branch_id'] ?? null;
        $createdBy    = $data['created_by'] ?? null;

        $expenseLedgerId = $this->lookupLedgerByNature('commission_expense');
        $payableLedgerId = $this->lookupLedgerByNature('commission_payable');

        if ($expenseLedgerId === null) {
            throw new \RuntimeException(
                "Cannot post commission expense: no active ledger with nature 'commission_expense'. "
                . "Register the ledger in the chart of accounts (ledger_nature='commission_expense', is_active=true). "
                . "See LedgerNatureService::EXTENDED_NATURES."
            );
        }
        if ($payableLedgerId === null) {
            throw new \RuntimeException(
                "Cannot post commission expense: no active ledger with nature 'commission_payable'. "
                . "Register the ledger in the chart of accounts (ledger_nature='commission_payable', is_active=true). "
                . "See LedgerNatureService::EXTENDED_NATURES."
            );
        }

        $absAmount = abs($amount);
        $memoExpense = "Commission expense — {$salesmanName} ({$period})";
        $memoPayable = "Commission payable — {$salesmanName} ({$period})";

        // Net-negative period (returns exceed earnings): reduce the liability
        // and the expense instead of accruing more.
        $lines = $amount >= 0
            ? [
                ['ledger_id' => $expenseLedgerId, 'debit' => $absAmount, 'credit' => 0,          'memo' => $memoExpense],
                ['ledger_id' => $payableLedgerId, 'debit' => 0,          'credit' => $absAmount, 'memo' => $memoPayable],
            ]
            : [
                ['ledger_id' => $payableLedgerId, 'debit' => $absAmount, 'credit' => 0,          'memo' => "Commission reversal — {$salesmanName} ({$period})"],
                ['ledger_id' => $expenseLedgerId, 'debit' => 0,          'credit' => $absAmount, 'memo' => "Commission reversal — {$salesmanName} ({$period})"],
            ];

        $entry = [
            'entry_date'   => now()->format('Y-m-d'),
            'reference_type' => 'commission_period',
            'reference_id' => (int) ($salesmanId ?? 0),
            'branch_id'    => $branchId,
            'description'  => $description,
            'source'       => 'commission_confirm',
            'created_by'   => $createdBy,
            // Commission confirmation is an admin period-end action that may
            // span branches (entries are grouped by salesman, not branch).
            // With branch_id=null the per-branch period-close guard is
            // naturally skipped (see createJournalEntry step 2).
            'skip_period_check' => $branchId === null,
        ];

        $journalEntryId = $this->createJournalEntry($entry, $lines);

        return (object) ['id' => $journalEntryId];
    }

    /**
     * Validate that the posting date falls within an open accounting period.
     *
     * P2-1: Admin bypass — when config('accounting.period_close_admin_override')
     * is true AND the authenticated user is admin/superadmin, the check is
     * skipped (but the override is logged to user_audit_log for audit trail).
     *
     * Reversals bypass this check entirely via the 'skip_period_check' flag
     * in createJournalEntry (so a reversal against a closed-period posting
     * can still proceed).
     *
     * @param string $postingDate (Y-m-d)
     * @param int $branchId
     * @throws \RuntimeException If the period is closed for this branch.
     */
    public function validatePeriod(string $postingDate, int $branchId): void
    {
        $closedThrough = DB::table('accounting_periods')
            ->where('branch_id', $branchId)
            ->value('closed_through_date');

        if (!$closedThrough || $postingDate > $closedThrough) {
            return; // Period is open for this date.
        }

        // P2-1: Admin bypass — check config + user role.
        if (config('accounting.period_close_admin_override', false)) {
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user && $user->isAdmin()) {
                // Log the override for audit trail.
                DB::table('user_audit_log')->insert([
                    'user_id' => $user->id,
                    'action' => 'period_close_override',
                    'target_user_id' => null,
                    'branch_id' => $branchId,
                    'details' => json_encode([
                        'posting_date' => $postingDate,
                        'closed_through' => $closedThrough,
                        'branch_id' => $branchId,
                        'reason' => 'Admin override: posting to closed period',
                    ]),
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                    'created_at' => now(),
                ]);

                return; // Bypass — admin allowed to post.
            }
        }

        throw new \RuntimeException(
            "Posting date {$postingDate} falls within a closed accounting period "
            . "(closed through {$closedThrough} for branch {$branchId}). "
            . "Reopen the period or use a later date."
            . (config('accounting.period_close_admin_override', false)
                ? ' (Admin override is enabled — contact an admin.)'
                : '')
        );
    }

    /**
     * Find a journal entry by reference (reference_type + reference_id).
     * Returns the first non-reversed entry, or null.
     *
     * @param string $referenceType
     * @param int $referenceId
     * @return object|null
     */
    public function findJournalEntryByReference(string $referenceType, int $referenceId): ?object
    {
        return DB::table('journal_entries')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('is_reversed', false)
            ->first();
    }

    /**
     * Get a journal entry with its lines (for display/verification).
     *
     * @param int $journalEntryId
     * @return array{ entry: object|null, lines: \Illuminate\Support\Collection }
     */
    public function getEntryWithLines(int $journalEntryId): array
    {
        $entry = DB::table('journal_entries')->where('id', $journalEntryId)->first();
        $lines = collect();

        if ($entry) {
            $lines = DB::table('journal_lines as jl')
                ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                ->where('jl.journal_entry_id', $journalEntryId)
                ->select('jl.*', 'l.ledger_code', 'l.ledger_name', 'l.account_type', 'l.ledger_nature')
                ->orderBy('jl.id')
                ->get();
        }

        return ['entry' => $entry, 'lines' => $lines];
    }

    /**
     * Generate an atomic journal entry number: JE-YYYY-NNNNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     * Journal entries use year-scoped sequences (periodKey = year) with 6-digit padding.
     */
    private function generateEntryNo(): string
    {
        $year = now()->format('Y');

        return DocumentSequenceService::nextCode(
            docType:  'journal_entry',
            prefix:   'JE',
            datePart: $year,
            padLength: 6,
            periodKey: $year,
        );
    }

    // ============================================================
    // BATCH VERIFICATION METHODS (for replay/manual verify commands)
    // ============================================================

    /**
     * Verify that all journal entries are balanced (Dr=Cr per entry).
     * Returns a list of unbalanced entry IDs.
     *
     * @return array{ total_entries: int, unbalanced_count: int, unbalanced_ids: array }
     */
    public function verifyAllEntriesBalanced(): array
    {
        $unbalanced = DB::select(<<<SQL
SELECT je.id, je.entry_no,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
GROUP BY je.id, je.entry_no
HAVING ABS(COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)) > 0.01
ORDER BY je.id
SQL);

        $totalEntries = (int) DB::table('journal_entries')->where('is_reversed', false)->count();

        return [
            'total_entries' => $totalEntries,
            'unbalanced_count' => count($unbalanced),
            'unbalanced_ids' => array_map(fn($r) => ['id' => $r->id, 'entry_no' => $r->entry_no, 'debit' => $r->total_debit, 'credit' => $r->total_credit], $unbalanced),
        ];
    }

    /**
     * Get a summary of all journal entries by reference_type.
     * Used by the replay command to know what to replay.
     *
     * @return array reference_type => count
     */
    public function getEntryCountsByReferenceType(): array
    {
        return DB::table('journal_entries')
            ->where('is_reversed', false)
            ->whereNotNull('reference_type')
            ->select('reference_type', DB::raw('COUNT(*) as count'))
            ->groupBy('reference_type')
            ->pluck('count', 'reference_type')
            ->toArray();
    }

    /**
     * Get the total debit/credit across all non-reversed entries.
     * For the Trial Balance check.
     *
     * @return array{ total_debit: float, total_credit: float, balanced: bool }
     */
    public function getTotalDebitsCredits(): array
    {
        $result = DB::selectOne(<<<SQL
SELECT
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.is_reversed = false
SQL);

        $debit = (float) ($result->total_debit ?? 0);
        $credit = (float) ($result->total_credit ?? 0);

        return [
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balanced' => abs($debit - $credit) < 0.01,
        ];
    }
}
