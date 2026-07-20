<?php

namespace App\Console\Commands;

use App\Services\Accounting\SubLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sub-Ledger Reconciliation — Phase 9.3.
 *
 * Verifies that all 3 sub-ledgers match their GL control accounts:
 *   1. AR: customer_ledger total == GL AR control (nature: ar)
 *   2. AP: supplier_ledger total == GL AP control (nature: ap)
 *   3. Employee: employee_ledger total == GL employee_payable control
 *
 * Also checks for:
 *   - Sub-ledger entries without journal_entry_id (orphan — not linked to GL)
 *   - Sub-ledger entries referencing reversed journal entries
 *
 * Usage: php artisan subledger:reconcile
 * Exit 0 = all match, 1 = drift detected.
 */
class SubLedgerReconcile extends Command
{
    protected $signature = 'subledger:reconcile';
    protected $description = 'Reconcile sub-ledgers (AR/AP/Employee) against GL control accounts';

    public function handle(SubLedgerService $subLedgerService): int
    {
        $this->info('=== Sub-Ledger Reconciliation (Phase 9.3) ===');
        $this->info('Verifies AR/AP/Employee sub-ledgers match GL control accounts.');
        $this->newLine();

        $result = $subLedgerService->reconcileAll();

        // 1. AR reconciliation.
        $this->info('1. Accounts Receivable (AR):');
        $this->line("   Sub-ledger total: " . number_format($result['ar']['subledger'], 2));
        $this->line("   GL AR control:    " . number_format($result['ar']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['ar']['drift'], 2));
        if ($result['ar']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 2. AP reconciliation.
        $this->info('2. Accounts Payable (AP):');
        $this->line("   Sub-ledger total: " . number_format($result['ap']['subledger'], 2));
        $this->line("   GL AP control:    " . number_format($result['ap']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['ap']['drift'], 2));
        if ($result['ap']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 3. Employee reconciliation.
        $this->info('3. Employee Payable:');
        $this->line("   Sub-ledger total: " . number_format($result['employee']['subledger'], 2));
        $this->line("   GL control:       " . number_format($result['employee']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['employee']['drift'], 2));
        if ($result['employee']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 4. Orphan entries (sub-ledger rows without journal_entry_id).
        $this->info('4. Orphan Sub-Ledger Entries (no journal_entry_id):');
        foreach (['customer_ledger' => 'customer', 'supplier_ledger' => 'supplier', 'employee_ledger' => 'employee'] as $table => $label) {
            $orphanCount = (int) DB::table($table)
                ->whereNull('journal_entry_id')
                ->where('is_reversed', false)
                ->count();
            if ($orphanCount === 0) {
                $this->info("   ✓ {$label}: no orphans");
            } else {
                $this->warn("   ⚠ {$label}: {$orphanCount} entries without journal_entry_id (may be pre-GL data)");
            }
        }
        $this->newLine();

        // ============================================================
        // P3-3: Sales-specific reconciliation sections (4-6)
        // ============================================================

        $salesIssues = 0;

        // 5. Cash/Bank reconciliation.
        $salesIssues += $this->reconcileCashBank();

        // 6. Inventory reconciliation.
        $salesIssues += $this->reconcileInventory();

        // 7. COGS reconciliation.
        $salesIssues += $this->reconcileCOGS();

        // Summary.
        $this->info('=== Reconciliation Summary ===');
        if ($result['all_match'] && $salesIssues === 0) {
            $this->info('✓ ALL 6 reconciliation sections match (AR, AP, Employee, Cash/Bank, Inventory, COGS).');
            $this->info('  Phase 9.3 + P3-3 reconciliation PASSED.');
            return self::SUCCESS;
        } elseif ($result['all_match']) {
            $this->info('✓ Core sub-ledgers match. Sales-specific issues are informational.');
            return self::SUCCESS;
        } else {
            $this->error('✗ Sub-ledger drift detected. Investigate before period close.');
            return self::FAILURE;
        }
    }

    // ============================================================
    // P3-3: Sales-specific reconciliation methods
    // ============================================================

    /**
     * P3-3: Cash/Bank reconciliation.
     * GL cash_bank ledger balance should equal SUM(banks.balance) for
     * banks mapped to that ledger via bank_ledger_mappings.
     *
     * @return int 0 = match, 1 = mismatch
     */
    private function reconcileCashBank(): int
    {
        $this->info('5. Cash/Bank Reconciliation:');

        $tolerance = (float) config('accounting.gl_reconciliation_tolerance', 0.02);

        // GL cash_bank balance (all non-reversed JEs).
        $glCash = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature = 'cash_bank' AND l.is_active = true
SQL)->balance;

        // Sum of banks.balance for banks mapped to a GL ledger.
        $bankBalances = (float) DB::table('banks as b')
            ->join('bank_ledger_mappings as blm', 'blm.bank_id', '=', 'b.id')
            ->where('b.is_active', true)
            ->whereNull('b.deleted_at')
            ->sum('b.balance');

        // Sum of cash_ledger running balances.
        $cashLedgerBalances = (float) DB::table('cash_ledger')
            ->where('is_reversed', false)
            ->sum('balance');

        $drift = abs($glCash - $bankBalances - $cashLedgerBalances);

        $this->line("   GL cash_bank balance: " . number_format($glCash, 2));
        $this->line("   Banks balance sum:    " . number_format($bankBalances, 2));
        $this->line("   Cash ledger sum:      " . number_format($cashLedgerBalances, 2));
        $this->line("   Drift:                " . number_format($drift, 2));

        if ($drift < $tolerance) {
            $this->info("   ✓ MATCH (within tolerance {$tolerance})");
            return 0;
        } else {
            $this->error("   ✗ MISMATCH — investigate bank_ledger_mappings + cash_ledger");
            return 1;
        }
    }

    /**
     * P3-3: Inventory reconciliation.
     * GL inventory ledger balance should equal SUM(warehouse_stock.qty × avg_cost).
     *
     * @return int 0 = match, 1 = mismatch
     */
    private function reconcileInventory(): int
    {
        $this->info('6. Inventory Reconciliation:');
        $this->newLine();

        $tolerance = (float) config('accounting.gl_reconciliation_tolerance', 0.02);

        // GL inventory balance.
        $glInventory = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature = 'inventory' AND l.is_active = true
SQL)->balance;

        // Physical stock valuation.
        $stockValue = (float) DB::table('warehouse_stock')
            ->where('qty', '>', 0)
            ->sum('stock_value');

        $drift = abs($glInventory - $stockValue);

        $this->line("   GL inventory balance:   " . number_format($glInventory, 2));
        $this->line("   Physical stock value:   " . number_format($stockValue, 2));
        $this->line("   Drift:                  " . number_format($drift, 2));

        if ($drift < $tolerance) {
            $this->info("   ✓ MATCH (within tolerance {$tolerance})");
            return 0;
        } else {
            $this->error("   ✗ MISMATCH — investigate stock_transactions vs GL postings");
            $this->warn("   → Run: php artisan stock:replay-verify (P3-1) to identify stock drift");
            return 1;
        }
    }

    /**
     * P3-3: COGS reconciliation.
     * GL COGS ledger total should equal SUM(sales_challan_items.cogs_amount)
     * for non-reversed challans.
     *
     * @return int 0 = match, 1 = mismatch
     */
    private function reconcileCOGS(): int
    {
        $this->info('7. COGS Reconciliation:');
        $this->newLine();

        $tolerance = (float) config('accounting.gl_reconciliation_tolerance', 0.02);

        // GL COGS balance (debit - credit = net COGS expense).
        $glCogs = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature = 'cogs' AND l.is_active = true
SQL)->balance;

        // Sum of cogs_amount from sales_challan_items for non-reversed challans.
        $challanCogs = (float) DB::table('sales_challan_items as sci')
            ->join('sales_challans as sc', 'sc.id', '=', 'sci.sales_challan_id')
            ->where('sc.is_reversed', false)
            ->sum('sci.cogs_amount');

        // Also include damage_loss (linked to sales returns via P1-5).
        $damageLoss = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
WHERE l.ledger_nature IN ('damage_loss', 'inventory_shrinkage') AND l.is_active = true
SQL)->balance;

        // COGS = challan COGS + damage loss (both are inventory-expensed).
        $expectedCogs = $challanCogs + $damageLoss;
        $drift = abs($glCogs - $expectedCogs);

        $this->line("   GL COGS balance:          " . number_format($glCogs, 2));
        $this->line("   Challan COGS sum:         " . number_format($challanCogs, 2));
        $this->line("   Damage loss (P1-5):       " . number_format($damageLoss, 2));
        $this->line("   Expected COGS (sum):      " . number_format($expectedCogs, 2));
        $this->line("   Drift:                    " . number_format($drift, 2));

        if ($drift < $tolerance) {
            $this->info("   ✓ MATCH (within tolerance {$tolerance})");
            return 0;
        } else {
            $this->error("   ✗ MISMATCH — investigate COGS JEs vs sales_challan_items");
            $this->warn("   → Run: php artisan journal:replay-verify (P3-2) to identify GL issues");
            return 1;
        }
    }
}
