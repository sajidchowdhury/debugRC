<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Journal Manual Verification — Phase 9.2.
 *
 * Shows 10 sample journal entries with their full Dr/Cr lines for the
 * accountant to manually verify. Like stock:manual-verify but for GL.
 *
 * Usage:
 *   php artisan journal:manual-verify
 *   php artisan journal:manual-verify --type=sales_invoice
 *   php artisan journal:manual-verify --count=20
 *
 * The accountant reviews:
 *   1. Each entry's Dr/Cr lines match the expected posting rule
 *   2. The entry is balanced (Dr=Cr)
 *   3. The reference links to the correct source transaction
 *   4. The ledger natures used are correct for the business event
 */
class JournalManualVerify extends Command
{
    protected $signature = 'journal:manual-verify
                            {--type= : Filter by reference_type (e.g. sales_invoice, purchase_receive)}
                            {--count=10 : Number of sample entries to show}';

    protected $description = 'Show 10 sample journal entries with full lines for accountant review';

    public function handle(JournalPostingService $postingService): int
    {
        $this->info('=== Journal Manual Verification (Phase 9.2) ===');
        $this->info('Shows sample journal entries with full Dr/Cr lines for accountant review.');
        $this->newLine();

        $referenceType = $this->option('type');
        $count = (int) $this->option('count');

        // Build query.
        $query = DB::table('journal_entries as je')
            ->leftJoin('branches as b', 'b.id', '=', 'je.branch_id')
            ->where('je.is_reversed', false)
            ->select('je.id', 'je.entry_no', 'je.entry_date', 'je.reference_type',
                     'je.reference_id', 'je.branch_id', 'je.description', 'je.source',
                     'b.branch_name')
            ->when($referenceType, fn($q) => $q->where('je.reference_type', $referenceType))
            ->orderBy('je.entry_date', 'desc')
            ->orderBy('je.id', 'desc')
            ->limit($count);

        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->warn('No journal entries found.');
            return self::FAILURE;
        }

        $allBalanced = true;

        foreach ($entries as $entry) {
            $this->newLine();
            $this->info(str_repeat('=', 80));
            $this->info("JE #{$entry->id} — {$entry->entry_no} — {$entry->entry_date}");
            $this->info(str_repeat('=', 80));
            $this->line("  Reference: {$entry->reference_type} #{$entry->reference_id}");
            $this->line("  Branch:    {$entry->branch_name} (ID: {$entry->branch_id})");
            $this->line("  Source:    {$entry->source}");
            $this->line("  Description: {$entry->description}");

            // Get lines.
            $lines = DB::table('journal_lines as jl')
                ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                ->where('jl.journal_entry_id', $entry->id)
                ->select('jl.*', 'l.ledger_code', 'l.ledger_name', 'l.account_type', 'l.ledger_nature')
                ->orderBy('jl.id')
                ->get();

            $tableRows = [];
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($lines as $line) {
                $tableRows[] = [
                    $line->ledger_code,
                    $line->ledger_name,
                    $line->account_type,
                    $line->ledger_nature ?? '—',
                    number_format((float) $line->debit, 2),
                    number_format((float) $line->credit, 2),
                    $line->memo ?? '—',
                ];
                $totalDebit += (float) $line->debit;
                $totalCredit += (float) $line->credit;
            }

            $this->table(
                ['Code', 'Ledger', 'Type', 'Nature', 'Debit', 'Credit', 'Memo'],
                $tableRows
            );

            $balanced = abs($totalDebit - $totalCredit) < 0.01;
            $this->info("  Totals: Dr=" . number_format($totalDebit, 2) . " Cr=" . number_format($totalCredit, 2));

            if ($balanced) {
                $this->info("  ✓ BALANCED");
            } else {
                $this->error("  ✗ NOT BALANCED (diff=" . number_format($totalDebit - $totalCredit, 2) . ")");
                $allBalanced = false;
            }
        }

        // Summary.
        $this->newLine();
        $this->info(str_repeat('=', 80));
        $this->info('=== Manual Verification Summary ===');
        $this->info(str_repeat('=', 80));

        if ($allBalanced) {
            $this->info("✓ All {$entries->count()} sample entries are balanced.");
            $this->newLine();
            $this->info('Accountant sign-off checklist:');
            $this->info('  1. Review each entry above — confirm Dr/Cr match the posting rules.');
            $this->info('  2. Verify reference links to the correct source transaction.');
            $this->info('  3. Confirm ledger natures used are correct for each business event.');
            $this->info('  4. Check the entry description is meaningful.');
            $this->info('  5. Sign journal_posting_rules.md §7 (sign-off section).');
            return self::SUCCESS;
        } else {
            $this->error('✗ Some entries are not balanced — investigate before sign-off.');
            return self::FAILURE;
        }
    }
}
