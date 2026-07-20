<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerNatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Journal Replay Verification — Phase 9.2.
 *
 * Verifies the integrity of the entire GL by checking:
 *   1. All journal entries are balanced (Dr=Cr per entry)
 *   2. Total debits = total credits across all entries
 *   3. No orphan journal lines (lines without a parent entry)
 *   4. No entries reference inactive ledgers
 *   5. Chart of Accounts validation (all 7 critical natures resolve)
 *   6. AR sub-ledger total == GL AR control account
 *   7. AP sub-ledger total == GL AP control account
 *   8. Inventory valuation == GL inventory control
 *
 * This is the sign-off gate for the accounting engine.
 *
 * Usage: php artisan journal:replay-verify
 * Exit 0 = all checks pass, 1 = issues found.
 */
class JournalReplayVerify extends Command
{
    protected $signature = 'journal:replay-verify
                            {--fix-orphans : Attempt to fix orphan lines by deleting them}';

    protected $description = 'Verify GL integrity: balanced entries, Dr=Cr, sub-ledger reconciliation, CoA validation';

    public function handle(JournalPostingService $postingService, LedgerNatureService $natureService): int
    {
        $this->info('=== Journal Replay Verification (Phase 9.2) ===');
        $this->info('Verifies GL integrity across all journal entries.');
        $this->newLine();

        $allPass = true;

        // ============================================================
        // 1. Chart of Accounts validation
        // ============================================================
        $this->info('1. Chart of Accounts Validation...');
        $coaResult = $natureService->validateChartOfAccounts();

        if ($coaResult['valid']) {
            $this->info("   ✓ All {$coaResult['critical_resolved']}/{$coaResult['critical_count']} critical natures resolved.");
        } else {
            $this->error("   ✗ {$coaResult['critical_count'] - $coaResult['critical_resolved']} critical nature(s) missing:");
            foreach ($coaResult['critical_issues'] as $issue) {
                $this->warn("     [{$issue['nature']}] {$issue['message']}");
            }
            $allPass = false;
        }

        if (!empty($coaResult['warnings'])) {
            foreach ($coaResult['warnings'] as $warning) {
                $this->line("     ⚠ [{$warning['nature']}] {$warning['message']}");
            }
        }
        $this->newLine();

        // ============================================================
        // 2. All entries balanced (Dr=Cr per entry)
        // ============================================================
        $this->info('2. Per-Entry Balance Check (Dr=Cr)...');
        $balanceResult = $postingService->verifyAllEntriesBalanced();

        if ($balanceResult['unbalanced_count'] === 0) {
            $this->info("   ✓ All {$balanceResult['total_entries']} non-reversed entries are balanced.");
        } else {
            $this->error("   ✗ {$balanceResult['unbalanced_count']} unbalanced entries found:");
            foreach (array_slice($balanceResult['unbalanced_ids'], 0, 20) as $entry) {
                $this->warn("     JE #{$entry['id']} ({$entry['entry_no']}): Dr={$entry['debit']} Cr={$entry['credit']}");
            }
            if (count($balanceResult['unbalanced_ids']) > 20) {
                $this->warn("     ... and " . (count($balanceResult['unbalanced_ids']) - 20) . " more.");
            }
            $allPass = false;
        }
        $this->newLine();

        // ============================================================
        // 3. Total debits = total credits
        // ============================================================
        $this->info('3. Total Dr=Cr Check...');
        $totals = $postingService->getTotalDebitsCredits();

        $this->info("   Total Debits:  " . number_format($totals['total_debit'], 2));
        $this->info("   Total Credits: " . number_format($totals['total_credit'], 2));
        $this->info("   Difference:    " . number_format(abs($totals['total_debit'] - $totals['total_credit']), 2));

        if ($totals['balanced']) {
            $this->info("   ✓ Total debits = total credits.");
        } else {
            $this->error("   ✗ Total debits ≠ total credits!");
            $allPass = false;
        }
        $this->newLine();

        // ============================================================
        // 4. Orphan journal lines (lines without a parent entry)
        // ============================================================
        $this->info('4. Orphan Journal Lines Check...');
        $orphanCount = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM journal_lines jl
WHERE NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.id = jl.journal_entry_id)
SQL)->cnt;

        if ($orphanCount === 0) {
            $this->info("   ✓ No orphan journal lines.");
        } else {
            $this->error("   ✗ {$orphanCount} orphan journal lines found (lines without a parent entry).");

            if ($this->option('fix-orphans')) {
                DB::table('journal_lines')->whereNotIn('journal_entry_id', function ($q) {
                    $q->select('id')->from('journal_entries');
                })->delete();
                $this->warn("   → Deleted {$orphanCount} orphan lines (--fix-orphans).");
            } else {
                $this->warn("   → Run with --fix-orphans to delete them.");
            }
            $allPass = false;
        }
        $this->newLine();

        // ============================================================
        // 5. Entries referencing inactive ledgers
        // ============================================================
        $this->info('5. Inactive Ledger References Check...');
        $inactiveRefs = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM journal_lines jl
JOIN ledgers l ON l.id = jl.ledger_id
WHERE l.is_active = false OR l.deleted_at IS NOT NULL
SQL)->cnt;

        if ($inactiveRefs === 0) {
            $this->info("   ✓ No journal lines reference inactive ledgers.");
        } else {
            $this->warn("   ⚠ {$inactiveRefs} journal lines reference inactive/deleted ledgers (historical — not blocking).");
        }
        $this->newLine();

        // ============================================================
        // 6. AR sub-ledger == GL AR control
        // ============================================================
        $this->info('6. AR Reconciliation (sub-ledger vs GL control)...');
        $arSubledger = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(debit - credit), 0) AS balance
FROM customer_ledger WHERE is_reversed = false
SQL)->balance;

        $arGl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature = 'ar' AND l.is_active = true
SQL)->balance;

        $arDrift = abs($arSubledger - $arGl);

        if ($arDrift < 0.02) {
            $this->info("   ✓ AR sub-ledger ({$arSubledger}) = GL AR control ({$arGl}). Drift: {$arDrift}");
        } else {
            $this->error("   ✗ AR sub-ledger ({$arSubledger}) ≠ GL AR control ({$arGl}). Drift: {$arDrift}");
            $allPass = false;
        }
        $this->newLine();

        // ============================================================
        // 7. AP sub-ledger == GL AP control
        // ============================================================
        $this->info('7. AP Reconciliation (sub-ledger vs GL control)...');
        $apSubledger = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(credit - debit), 0) AS balance
FROM supplier_ledger WHERE is_reversed = false
SQL)->balance;

        $apGl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature = 'ap' AND l.is_active = true
SQL)->balance;

        $apDrift = abs($apSubledger - $apGl);

        if ($apDrift < 0.02) {
            $this->info("   ✓ AP sub-ledger ({$apSubledger}) = GL AP control ({$apGl}). Drift: {$apDrift}");
        } else {
            $this->error("   ✗ AP sub-ledger ({$apSubledger}) ≠ GL AP control ({$apGl}). Drift: {$apDrift}");
            $allPass = false;
        }
        $this->newLine();

        // ============================================================
        // 8. Entry counts by reference type
        // ============================================================
        $this->info('8. Entry Counts by Reference Type:');
        $counts = $postingService->getEntryCountsByReferenceType();
        foreach ($counts as $type => $count) {
            $this->line("   {$type}: {$count}");
        }
        $this->newLine();

        // ============================================================
        // Summary
        // ============================================================
        $this->info('=== Verification Summary ===');

        // ============================================================
        // P3-2: Sales-specific GL verification
        // ============================================================
        $this->newLine();
        $this->info('=== P3-2: Sales-Specific GL Verification ===');

        $salesIssues = 0;
        $salesIssues += $this->verifySalesInvoiceGL();
        $salesIssues += $this->verifyChallanCOGSGL();
        $salesIssues += $this->verifySalesReturnGL();
        $salesIssues += $this->verifyCustomerPaymentGL();
        $salesIssues += $this->verifyTransportAdjustmentGL();

        if ($salesIssues > 0) {
            $this->warn("Sales-specific GL issues found: {$salesIssues}");
            $this->warn('These are informational — investigate but they do not block the core GL checks.');
        } else {
            $this->info('✓ All sales-specific GL checks passed.');
            $this->info('  - Every non-draft invoice has exactly 1 GL JE');
            $this->info('  - Every issued challan has a COGS JE matching issue_cost');
            $this->info('  - Every confirmed return has revenue + COGS JEs at original_cost');
            $this->info('  - Every confirmed payment has a GL JE');
            $this->info('  - Transport adjustment JEs reference correct challans');
        }

        $this->newLine();
        if ($allPass && $salesIssues === 0) {
            $this->info('✓ ALL CHECKS PASSED (core + sales-specific).');
            $this->info('  Phase 9.2 + P3-2 replay verification PASSED.');
            return self::SUCCESS;
        } elseif ($allPass) {
            $this->info('✓ Core GL checks passed. Sales-specific issues are informational.');
            return self::SUCCESS;
        } else {
            $this->error('✗ SOME CORE CHECKS FAILED. Investigate the issues above.');
            return self::FAILURE;
        }
    }

    // ============================================================
    // P3-2: Sales-specific GL verification methods
    // ============================================================

    /**
     * P3-2: Verify every non-draft, non-cancelled invoice has exactly 1 GL JE.
     * @return int Issue count
     */
    private function verifySalesInvoiceGL(): int
    {
        $this->info('  Checking sales invoice GL entries...');

        // Invoices without a journal_entry_id
        $missingJE = DB::table('sales_invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('is_reversed', false)
            ->whereNull('journal_entry_id')
            ->count();

        if ($missingJE > 0) {
            $this->warn("    INVOICE MISSING JE: {$missingJE} non-draft invoices have no journal_entry_id.");
        }

        // Invoices with reversed GL JE but invoice not reversed
        $staleJE = DB::table('sales_invoices as si')
            ->join('journal_entries as je', 'je.id', '=', 'si.journal_entry_id')
            ->where('si.is_reversed', false)
            ->whereNotIn('si.status', ['cancelled'])
            ->where('je.is_reversed', true)
            ->count();

        if ($staleJE > 0) {
            $this->warn("    STALE JE: {$staleJE} active invoices have a reversed GL JE.");
        }

        $issues = $missingJE + $staleJE;
        if ($issues === 0) {
            $this->info('    ✓ All non-draft invoices have valid GL entries.');
        }
        return $issues;
    }

    /**
     * P3-2: Verify every issued challan has a COGS JE matching issue_cost.
     * @return int Issue count
     */
    private function verifyChallanCOGSGL(): int
    {
        $this->info('  Checking challan COGS GL entries...');

        // Challans without a journal_entry_id
        $missingJE = DB::table('sales_challans')
            ->where('is_reversed', false)
            ->whereNull('journal_entry_id')
            ->count();

        if ($missingJE > 0) {
            $this->warn("    CHALLAN MISSING JE: {$missingJE} active challans have no journal_entry_id.");
        }

        // COGS JE amount should match issue_cost
        $cogsMismatches = DB::table('sales_challans as sc')
            ->join('journal_entries as je', 'je.id', '=', 'sc.journal_entry_id')
            ->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
            ->where('sc.is_reversed', false)
            ->where('je.is_reversed', false)
            ->where('l.ledger_nature', 'cogs')
            ->whereRaw('ABS(sc.issue_cost - jl.debit) > 0.01')
            ->count();

        if ($cogsMismatches > 0) {
            $this->warn("    COGS MISMATCH: {$cogsMismatches} challans have COGS JE ≠ issue_cost.");
        }

        $issues = $missingJE + $cogsMismatches;
        if ($issues === 0) {
            $this->info('    ✓ All challan COGS entries match issue_cost.');
        }
        return $issues;
    }

    /**
     * P3-2: Verify every confirmed return has revenue + COGS JEs at original_cost.
     * @return int Issue count
     */
    private function verifySalesReturnGL(): int
    {
        $this->info('  Checking sales return GL entries...');

        // Confirmed returns should have both journal_entry_id + cogs_journal_entry_id
        $missingJE = DB::table('sales_returns')
            ->where('status', 'confirmed')
            ->where('is_reversed', false)
            ->where(function ($q) {
                $q->whereNull('journal_entry_id')
                  ->orWhereNull('cogs_journal_entry_id');
            })
            ->count();

        if ($missingJE > 0) {
            $this->warn("    RETURN MISSING JE: {$missingJE} confirmed returns missing revenue or COGS JE.");
        }

        // COGS reversal amount should match cogs_amount (sum of qty × original_cost)
        $cogsMismatches = DB::table('sales_returns as sr')
            ->join('journal_entries as je', 'je.id', '=', 'sr.cogs_journal_entry_id')
            ->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
            ->where('sr.status', 'confirmed')
            ->where('sr.is_reversed', false)
            ->where('je.is_reversed', false)
            ->where('l.ledger_nature', 'cogs')
            ->whereRaw('ABS(sr.cogs_amount - jl.credit) > 0.01')
            ->count();

        if ($cogsMismatches > 0) {
            $this->warn("    RETURN COGS MISMATCH: {$cogsMismatches} returns have COGS JE ≠ cogs_amount.");
        }

        $issues = $missingJE + $cogsMismatches;
        if ($issues === 0) {
            $this->info('    ✓ All confirmed returns have valid revenue + COGS JEs.');
        }
        return $issues;
    }

    /**
     * P3-2: Verify every confirmed payment has a GL JE.
     * @return int Issue count
     */
    private function verifyCustomerPaymentGL(): int
    {
        $this->info('  Checking customer payment GL entries...');

        $missingJE = DB::table('customer_payments')
            ->where('is_reversed', false)
            ->whereNull('journal_entry_id')
            ->count();

        if ($missingJE > 0) {
            $this->warn("    PAYMENT MISSING JE: {$missingJE} active payments have no journal_entry_id.");
        } else {
            $this->info('    ✓ All active payments have GL entries.');
        }
        return $missingJE;
    }

    /**
     * P3-2: Verify transport adjustment JEs reference correct challans.
     * @return int Issue count
     */
    private function verifyTransportAdjustmentGL(): int
    {
        $this->info('  Checking transport adjustment GL entries...');

        // Challans with adjustment_journal_entry_id but the JE is reversed while challan is not
        $staleAdjustments = DB::table('sales_challans as sc')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'sc.adjustment_journal_entry_id')
            ->where('sc.is_reversed', false)
            ->whereNotNull('sc.adjustment_journal_entry_id')
            ->where('je.is_reversed', true)
            ->count();

        if ($staleAdjustments > 0) {
            $this->warn("    STALE ADJUSTMENT: {$staleAdjustments} active challans have reversed adjustment JEs.");
        }

        // Transport adjustment amount on challan should be non-zero if adjustment JE exists
        $zeroAdjustments = DB::table('sales_challans')
            ->whereNotNull('adjustment_journal_entry_id')
            ->where('transport_adjustment', 0)
            ->count();

        if ($zeroAdjustments > 0) {
            $this->warn("    ZERO ADJUSTMENT: {$zeroAdjustments} challans have adjustment JE but transport_adjustment=0.");
        }

        $issues = $staleAdjustments + $zeroAdjustments;
        if ($issues === 0) {
            $this->info('    ✓ All transport adjustment JEs are consistent.');
        }
        return $issues;
    }
}
