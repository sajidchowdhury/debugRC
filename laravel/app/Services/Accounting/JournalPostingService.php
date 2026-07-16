<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Journal Posting Service — Phase 6.3 (minimal version).
 *
 * Creates journal_entries + journal_lines with Dr=Cr enforcement.
 * The DB trigger (enforce_balanced_journal_entry) rejects unbalanced entries.
 *
 * Full version (Phase 9) will add all ~40 posting methods. This minimal version
 * provides the core createJournalEntry() + lookupLedgerByNature() used by
 * Phase 6.3 stock adjustments.
 *
 * Entry number generation: JE-YYYY-NNNNNN using document_sequences
 * (atomic SELECT FOR UPDATE — fixes the legacy COUNT+1 race condition).
 */
class JournalPostingService
{
    /**
     * Create a balanced journal entry with the given lines.
     *
     * @param array $entry {
     *     entry_date: string (Y-m-d),
     *     reference_type: string,
     *     reference_id: int,
     *     branch_id: int,
     *     description: string,
     *     source: string (default 'manual'),
     *     created_by: int|null,
     * }
     * @param array $lines [] each: { ledger_id: int, debit: float, credit: float, memo: string|null }
     * @return int The journal_entry_id.
     * @throws \RuntimeException If Dr≠Cr or ledger_id not found.
     */
    public function createJournalEntry(array $entry, array $lines): int
    {
        // Validate balance: Dr must equal Cr.
        $totalDebit = collect($lines)->sum('debit');
        $totalCredit = collect($lines)->sum('credit');
        $totalDebit = round((float) $totalDebit, 2);
        $totalCredit = round((float) $totalCredit, 2);

        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new \RuntimeException(
                "Journal entry not balanced: debits={$totalDebit} credits={$totalCredit}"
            );
        }

        if (empty($lines)) {
            throw new \RuntimeException('Journal entry must have at least one line.');
        }

        // Generate atomic entry number: JE-YYYY-NNNNNN.
        $entryNo = $this->generateEntryNo();

        $entryDate = $entry['entry_date'] ?? now()->format('Y-m-d');

        // Insert the journal entry.
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert the journal lines.
        $lineRows = [];
        foreach ($lines as $line) {
            $lineRows[] = [
                'journal_entry_id' => $journalEntryId,
                'ledger_id' => (int) $line['ledger_id'],
                'debit' => (float) ($line['debit'] ?? 0),
                'credit' => (float) ($line['credit'] ?? 0),
                'entity_type' => $line['entity_type'] ?? null,
                'entity_id' => $line['entity_id'] ?? null,
                'memo' => $line['memo'] ?? null,
            ];
        }
        DB::table('journal_lines')->insert($lineRows);

        // Log the posting.
        DB::table('journal_posting_logs')->insert([
            'journal_entry_id' => $journalEntryId,
            'action' => 'posted',
            'performed_by' => $entry['created_by'] ?? null,
            'performed_at' => now(),
            'remarks' => "Posted via JournalPostingService::createJournalEntry",
        ]);

        return $journalEntryId;
    }

    /**
     * Reverse a journal entry (swap debits/credits, mark original is_reversed).
     *
     * @param int $journalEntryId
     * @param int $reversedBy
     * @param string $reason
     * @return int The reversal journal_entry_id.
     */
    public function reverseJournalEntry(int $journalEntryId, int $reversedBy, string $reason = ''): int
    {
        return DB::transaction(function () use ($journalEntryId, $reversedBy, $reason) {
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

            // Create the reversal entry.
            $reversalId = $this->createJournalEntry([
                'entry_date' => now()->format('Y-m-d'),
                'reference_type' => 'reversal',
                'reference_id' => $original->id,
                'branch_id' => $original->branch_id,
                'description' => 'Reversal of JE ' . $original->entry_no . ($reason ? ": {$reason}" : ''),
                'source' => 'reversal',
                'created_by' => $reversedBy,
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
     * The 7 critical natures must resolve to exactly one active ledger.
     *
     * @param string $nature e.g. 'inventory', 'inventory_shrinkage', 'inventory_surplus'
     * @return int|null
     */
    public function lookupLedgerByNature(string $nature): ?int
    {
        $ledger = DB::table('ledgers')
            ->where('ledger_nature', $nature)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        return $ledger ? (int) $ledger->id : null;
    }

    /**
     * Generate an atomic journal entry number: JE-YYYY-NNNNNN.
     *
     * Uses document_sequences with SELECT FOR UPDATE (fixes the legacy
     * COUNT+1 race condition — two concurrent postings could have generated
     * the same entry_no).
     */
    private function generateEntryNo(): string
    {
        $year = now()->format('Y');
        $periodKey = $year;
        $docType = 'journal_entry';

        return DB::transaction(function () use ($docType, $periodKey, $year) {
            // Lock the document_sequences row (or create if not exists).
            $seqRow = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->where('branch_id', 0)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $nextNumber = $seqRow ? ((int) $seqRow->last_number + 1) : 1;

            if ($seqRow) {
                DB::table('document_sequences')
                    ->where('id', $seqRow->id)
                    ->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            } else {
                DB::table('document_sequences')->insert([
                    'doc_type' => $docType,
                    'branch_id' => 0,
                    'period_key' => $periodKey,
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
            }

            return "JE-{$year}-" . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        });
    }
}
