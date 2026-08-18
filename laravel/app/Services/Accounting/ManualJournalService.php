<?php

namespace App\Services\Accounting;

use App\Models\ApprovalRequest;
use App\Models\ManualJournal;
use App\Support\FiscalYearResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manual Journal Service — Phase 1.1 (Core Foundation Hardening).
 *
 * Handles the full lifecycle of manual journal entries:
 *   create (draft or posted) → post (draft → posted) → reverse (posted → reversed)
 *
 * Key rules (from the plan):
 *   - User selects specific ledgers (not automatic nature lookup)
 *   - Must have Dr = Cr (enforced by service validation; DB trigger as backstop)
 *   - Supports draft → posted → reversed lifecycle
 *   - Period validation on posting (cannot post to closed periods)
 *   - No entity_type/entity_id on journal lines (accountant's choice)
 *
 * Phase 1.1 Changes:
 *   - Lines are now persisted in manual_journal_lines table for BOTH draft and posted journals
 *   - postJournal() now works: reads draft lines, validates, posts to GL, marks lines as posted
 *   - This matches the "park document" pattern in SAP B1 and "Optional voucher" in Tally
 *
 * GL posting:
 *   - Draft: lines saved to manual_journal_lines (status='draft'), no GL journal entry created
 *   - Post:  creates a journal_entries row + journal_lines rows via
 *            JournalPostingService::createJournalEntry(), then marks manual_journal_lines as posted
 *   - Reverse: reverses the linked journal_entries row via
 *            JournalReversalService::reverseByJournalEntry()
 */
class ManualJournalService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private AccountingPeriodService $periodService,
        private DocumentSequenceService $sequenceService,
    ) {}

    public const STATUSES = ['draft', 'posted', 'reversed'];

    // ============================================================
    // CREATE (draft or post in one step)
    // ============================================================

    /**
     * Create a manual journal.
     *
     * @param array $data {
     *     journal_date: string (Y-m-d),
     *     branch_id: int,
     *     description: string,
     *     post: bool (true = post immediately, false = save as draft),
     *     lines: array<int, {ledger_id: int, debit: float, credit: float, description: string}>,
     *     created_by: int|null,
     * }
     * @return ManualJournal
     */
    public function createJournal(array $data): ManualJournal
    {
        $lines = $this->validateAndNormalizeLines($data['lines'] ?? []);
        $branchId = (int) $data['branch_id'];
        $journalDate = $data['journal_date'] ?? now()->format('Y-m-d');
        $post = (bool) ($data['post'] ?? false);

        // Calculate totals.
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        // Enforce Dr = Cr (only required when posting; drafts can be unbalanced
        // while the accountant is still working).
        if ($post) {
            $this->assertBalanced($totalDebit, $totalCredit);
            $this->assertPeriodOpen($branchId, $journalDate);
        }

        $journalCode = $this->generateJournalCode();

        return DB::transaction(function () use ($lines, $branchId, $journalDate, $post, $totalDebit, $totalCredit, $data, $journalCode) {
            // 1. Insert manual_journals header.
            $journalId = DB::table('manual_journals')->insertGetId([
                'journal_code'  => $journalCode,
                'journal_date'  => $journalDate,
                'branch_id'     => $branchId,
                'description'   => $data['description'] ?? null,
                'total_debit'   => $totalDebit,
                'total_credit'  => $totalCredit,
                'status'        => $post ? 'posted' : 'draft',
                'journal_entry_id' => null, // filled in if posting
                'created_by'    => $data['created_by'] ?? null,
                'fiscal_year_id' => FiscalYearResolver::activeId(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // 2. Persist lines to manual_journal_lines (for BOTH draft and posted).
            $this->persistLines($journalId, $lines, $post ? 'posted' : 'draft');

            // 3. If posting, create the GL journal entry + lines.
            if ($post) {
                $journal = ManualJournal::find($journalId);
                $journalEntryId = $this->postToGL($journal, $lines, (int) ($data['created_by'] ?? 0));

                DB::table('manual_journals')->where('id', $journalId)->update([
                    'journal_entry_id' => $journalEntryId,
                    'updated_at'        => now(),
                ]);

                // Link the manual_journal_lines to the GL journal_lines.
                $this->linkDraftLinesToGL($journalId, $journalEntryId);
            }

            // 4. Audit log.
            $this->logAudit('manual_journal_created', (int) ($data['created_by'] ?? 0), $journalId, [
                'journal_code' => $journalCode,
                'status'       => $post ? 'posted' : 'draft',
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'line_count'   => count($lines),
            ]);

            return ManualJournal::with(['branch', 'journalEntry.lines.ledger', 'createdBy', 'lines.ledger'])->find($journalId);
        });
    }

    // ============================================================
    // POST (draft → posted)
    // ============================================================

    /**
     * Post a draft manual journal to the GL.
     *
     * Phase 1.1: Now fully functional — reads draft lines from manual_journal_lines,
     * validates Dr=Cr, posts to GL, marks lines as posted.
     *
     * @param int $journalId
     * @param int $postedBy
     * @return ManualJournal
     */
    public function postJournal(int $journalId, int $postedBy): ManualJournal
    {
        return DB::transaction(function () use ($journalId, $postedBy) {
            $journal = ManualJournal::with('lines')->lockForUpdate()->find($journalId);

            if (!$journal) {
                throw new \RuntimeException("Manual journal {$journalId} not found.");
            }
            // G-077 (CRITICAL, WORKFLOWS-APPROVAL): previously this guard was
            // `if (!$journal->isDraft())` — which rejected 'approved' journals,
            // dead-ending the entire approval workflow. A user who submitted +
            // got approval could NOT post (the UI showed a Post button via
            // `canBePosted()` which returns true for approved, but the service
            // threw). Now matches the model's own contract: `canBePosted()`
            // returns true for both 'draft' AND 'approved'. Draft = post
            // directly (no approval needed); approved = post after approval
            // gate cleared. Both paths converge on the same GL posting logic.
            if (!$journal->canBePosted()) {
                throw new \RuntimeException("Only draft or approved journals can be posted (current status: {$journal->status}).");
            }

            // Load draft lines from manual_journal_lines.
            $draftLines = $journal->lines()->where('status', 'draft')->get();

            if ($draftLines->isEmpty()) {
                throw new \RuntimeException(
                    "Cannot post draft journal {$journalId}: no draft lines found. "
                    . "The journal must have at least 2 lines with ledger and amount."
                );
            }

            // Convert to the format expected by postToGL.
            // G-321: include dimension_value_id so the draft→post path carries
            // the dimension tag through to GL (the create() path already does
            // via validateAndNormalizeLines; this mirrors it for postJournal()).
            $lines = $draftLines->map(function ($line) {
                return [
                    'ledger_id'   => (int) $line->ledger_id,
                    'debit'       => (float) $line->debit,
                    'credit'      => (float) $line->credit,
                    'description' => $line->description ?? '',
                    'dimension_value_id' => $line->dimension_value_id ?? null,
                ];
            })->toArray();

            // Re-validate balance + period.
            $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
            $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);
            $this->assertBalanced($totalDebit, $totalCredit);
            $this->assertPeriodOpen((int) $journal->branch_id, $journal->journal_date->format('Y-m-d'));

            // Post to GL.
            $journalEntryId = $this->postToGL($journal, $lines, $postedBy);

            // Update the manual journal header.
            DB::table('manual_journals')->where('id', $journalId)->update([
                'status'           => 'posted',
                'journal_entry_id' => $journalEntryId,
                'total_debit'      => $totalDebit,
                'total_credit'     => $totalCredit,
                'updated_at'       => now(),
            ]);

            // Mark draft lines as posted and link to GL journal_lines.
            $this->linkDraftLinesToGL($journalId, $journalEntryId);

            // Audit log.
            $this->logAudit('manual_journal_posted', $postedBy, $journalId, [
                'journal_code' => $journal->journal_code,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'line_count'   => count($lines),
            ]);

            return ManualJournal::with(['branch', 'journalEntry.lines.ledger', 'createdBy', 'lines.ledger'])->find($journalId);
        });
    }

    // ============================================================
    // REVERSE (posted → reversed)
    // ============================================================

    /**
     * Reverse a posted manual journal.
     *
     * @param int $journalId
     * @param int $reversedBy
     * @param string $reason
     * @return ManualJournal
     */
    public function reverseJournal(int $journalId, int $reversedBy, string $reason = ''): ManualJournal
    {
        return DB::transaction(function () use ($journalId, $reversedBy, $reason) {
            $journal = ManualJournal::lockForUpdate()->find($journalId);

            if (!$journal) {
                throw new \RuntimeException("Manual journal {$journalId} not found.");
            }
            if (!$journal->isPosted()) {
                throw new \RuntimeException("Only posted journals can be reversed (current status: {$journal->status}).");
            }
            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse the GL journal entry.
            if ($journal->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $journal->journal_entry_id,
                    $reversedBy,
                    "Manual journal reversal: {$reason}"
                );
            }

            // 2. Mark the manual journal as reversed.
            DB::table('manual_journals')->where('id', $journalId)->update([
                'status'         => 'reversed',
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => $reason,
                'updated_at'     => now(),
            ]);

            // 3. Cascade to approval_requests — G-250 (MEDIUM-WAVE-3).
            // The approval that authorized this journal is now void because the
            // underlying journal was reversed. Mark the approval_request row as
            // 'cancelled' (an existing status in the approval_requests CHECK
            // constraint — see 11_approval_workflow.sql L75) with the
            // rejection_reason column recording WHY it was cancelled. This
            // mirrors the cleanup_orphan_approval_requests() SQL function in
            // 11_approval_workflow.sql L190-196 which also uses status='cancelled'
            // + rejection_reason for non-pending voiding. The approval timeline
            // is now honest: instead of showing 'approved' forever for a journal
            // that no longer has effect, it shows 'cancelled' with the reversal
            // reason.
            //
            // Using the ApprovalRequest model directly (NOT
            // ApprovalService::cancel()) because cancel() requires Auth::user(),
            // requires the request to be pending, and would call
            // updateEntityStatus() to reset the manual journal back to 'draft'
            // (undoing the reversal we just did). Direct model update avoids all
            // three issues. Only 'approved' rows are touched — pending/rejected/
            // already-cancelled rows are left as-is.
            $approvalRequestsCancelled = ApprovalRequest::where('entity_type', 'manual_journal')
                ->where('entity_id', $journalId)
                ->where('status', 'approved')
                ->update([
                    'status'           => 'cancelled',
                    'rejection_reason' => 'Manual journal reversed on '
                        . now()->format('Y-m-d H:i:s')
                        . ": {$reason}",
                    'updated_at'       => now(),
                ]);

            // 4. Audit log.
            $this->logAudit('manual_journal_reversed', $reversedBy, $journalId, [
                'journal_code'                => $journal->journal_code,
                'reason'                      => $reason,
                'approval_requests_cancelled' => $approvalRequestsCancelled,
            ]);

            return ManualJournal::find($journalId);
        });
    }

    // ============================================================
    // QUERY HELPERS
    // ============================================================

    /**
     * Get filtered manual journals with pagination.
     */
    public function getFilteredJournals(array $filters = [], ?int $branchId = null, int $perPage = 25)
    {
        $query = ManualJournal::with(['branch', 'createdBy', 'journalEntry.lines.ledger', 'lines.ledger'])
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->where('journal_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn($q, $d) => $q->where('journal_date', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn($q, $bid) => $q->where('branch_id', $bid))
            ->when(($filters['status'] ?? null) && $filters['status'] !== 'all', fn($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('journal_code', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%");
            });

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderBy('journal_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get summary stats for the index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = ManualJournal::query();
        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        return [
            'total'         => (clone $baseQuery)->count(),
            'drafts'        => (clone $baseQuery)->where('status', 'draft')->count(),
            'posted'        => (clone $baseQuery)->where('status', 'posted')->count(),
            'reversed'      => (clone $baseQuery)->where('status', 'reversed')->count(),
            'total_debit'   => (float) (clone $baseQuery)->where('status', 'posted')->sum('total_debit'),
            'total_credit'  => (float) (clone $baseQuery)->where('status', 'posted')->sum('total_credit'),
        ];
    }

    // ============================================================
    // GL POSTING
    // ============================================================

    /**
     * Post the GL journal entry + lines for a manual journal.
     *
     * @return int journal_entry_id
     */
    private function postToGL(ManualJournal $journal, array $lines, int $createdBy): int
    {
        $glLines = [];
        foreach ($lines as $line) {
            $glLines[] = [
                'ledger_id' => (int) $line['ledger_id'],
                'debit'     => (float) $line['debit'],
                'credit'    => (float) $line['credit'],
                // NO entity_type/entity_id — manual journals are accountant-defined
                'memo'      => $line['description'] ?: null,
                // G-321 (MEDIUM-WAVE-3): pass-through to JournalPostingService::createJournalEntry,
                // which already reads $line['dimension_value_id'] ?? null at L156 and
                // populates journal_lines.dimension_value_id. Null when the line is
                // not dimension-tagged (the common case).
                'dimension_value_id' => $line['dimension_value_id'] ?? null,
            ];
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date'     => $journal->journal_date->format('Y-m-d'),
            'reference_type' => 'manual_journal',
            'reference_id'   => $journal->id,
            'branch_id'      => $journal->branch_id,
            'description'    => $journal->description ?: "Manual journal {$journal->journal_code}",
            'source'         => 'manual_journal',
            'created_by'     => $createdBy,
        ], $glLines);
    }

    // ============================================================
    // LINE PERSISTENCE (Phase 1.1)
    // ============================================================

    /**
     * Persist lines to manual_journal_lines table.
     *
     * @param int $journalId
     * @param array $lines Normalized lines from validateAndNormalizeLines()
     * @param string $status 'draft' or 'posted'
     */
    private function persistLines(int $journalId, array $lines, string $status = 'draft'): void
    {
        $lineRows = [];
        foreach ($lines as $line) {
            $lineRows[] = [
                'manual_journal_id'  => $journalId,
                'ledger_id'          => (int) $line['ledger_id'],
                // G-321 (MEDIUM-WAVE-3): persist the dimension tag on the draft
                // line so it survives draft→post. Null when not tagged.
                'dimension_value_id' => $line['dimension_value_id'] ?? null,
                'debit'              => (float) $line['debit'],
                'credit'             => (float) $line['credit'],
                'description'        => $line['description'] ?? null,
                'status'             => $status,
                'journal_line_id'    => null, // filled in after GL posting
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        DB::table('manual_journal_lines')->insert($lineRows);
    }

    /**
     * After GL posting, link each manual_journal_line to its corresponding GL journal_line.
     *
     * This is done by matching on ledger_id + debit + credit within the same journal_entry_id.
     * We also mark the lines as 'posted'.
     *
     * @param int $journalId
     * @param int $journalEntryId
     */
    private function linkDraftLinesToGL(int $journalId, int $journalEntryId): void
    {
        // Get the GL journal lines for this entry.
        $glLines = DB::table('journal_lines')
            ->where('journal_entry_id', $journalEntryId)
            ->orderBy('id')
            ->get();

        // Get the manual journal lines.
        $mjLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journalId)
            ->orderBy('id')
            ->get();

        // Match by position (order of insertion is preserved).
        foreach ($mjLines as $idx => $mjLine) {
            if (isset($glLines[$idx])) {
                DB::table('manual_journal_lines')
                    ->where('id', $mjLine->id)
                    ->update([
                        'status'          => 'posted',
                        'journal_line_id' => $glLines[$idx]->id,
                        'updated_at'      => now(),
                    ]);
            }
        }
    }

    // ============================================================
    // VALIDATION HELPERS
    // ============================================================

    /**
     * Validate + normalize the lines array.
     * - At least 2 lines required
     * - Each line must have ledger_id > 0 and (debit > 0 OR credit > 0), not both
     * - Filter out empty lines
     */
    private function validateAndNormalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $ledgerId = (int) ($line['ledger_id'] ?? 0);
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            // Skip empty lines (no ledger or no amount).
            if ($ledgerId <= 0 || ($debit <= 0 && $credit <= 0)) {
                continue;
            }

            // A line cannot have both debit and credit > 0.
            if ($debit > 0 && $credit > 0) {
                throw new \RuntimeException(
                    "A journal line cannot have both debit and credit > 0 (ledger_id: {$ledgerId})."
                );
            }

            $normalized[] = [
                'ledger_id'   => $ledgerId,
                'debit'       => round($debit, 2),
                'credit'      => round($credit, 2),
                'description' => (string) ($line['description'] ?? ''),
                // G-321 (MEDIUM-WAVE-3): preserve the dimension tag through
                // normalization. 0/empty → null (nullable column).
                'dimension_value_id' => (int) ($line['dimension_value_id'] ?? 0) ?: null,
            ];
        }

        if (count($normalized) < 2) {
            throw new \RuntimeException('At least 2 journal lines are required.');
        }

        return $normalized;
    }

    /**
     * Assert that total debits equal total credits.
     */
    private function assertBalanced(float $totalDebit, float $totalCredit): void
    {
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new \RuntimeException(
                "Journal is not balanced: debits={$totalDebit} credits={$totalCredit} "
                . "(diff=" . round($totalDebit - $totalCredit, 2) . ")"
            );
        }
    }

    /**
     * Assert that the journal date falls in an open accounting period.
     */
    private function assertPeriodOpen(int $branchId, string $date): void
    {
        $earliestOpen = $this->periodService->earliestOpenDate($branchId);
        if ($earliestOpen !== null && $date < $earliestOpen) {
            $closedThrough = \Carbon\Carbon::parse($earliestOpen)->subDay()->format('Y-m-d');
            throw new \RuntimeException(
                "Cannot post to {$date} — the accounting period is closed through {$closedThrough}. "
                . "Earliest open date is {$earliestOpen}."
            );
        }
    }

    // ============================================================
    // DOCUMENT SEQUENCE
    // ============================================================

    /**
     * Generate a unique journal code: MJ-YYYY-NNNNN.
     */
    private function generateJournalCode(): string
    {
        return $this->sequenceService->nextCode(
            docType: 'manual_journal',
            prefix: 'MJ',
            datePart: now()->format('Y'),
            padLength: 5,
            periodKey: now()->format('Y'),
        );
    }

    // ============================================================
    // AUDIT LOG
    // ============================================================

    private function logAudit(string $action, int $userId, int $recordId, array $details = []): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'user_id'    => $userId,
                'action'     => $action,
                'details'    => json_encode(array_merge($details, ['record_id' => $recordId])),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to log manual journal audit: {$e->getMessage()}");
            // Re-throw if inside a transaction to avoid SQLSTATE[25P02]
            if (DB::transactionLevel() > 0) {
                throw $e;
            }
        }
    }
}
