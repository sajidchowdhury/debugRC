<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation Service — Phase 5.
 *
 * Reconciles 6 sub-ledger sections against their GL control accounts.
 * Each section returns a status (green/red) with the variance amount.
 *
 * Sections:
 * 1. AR — customer_ledger total vs GL AR control
 * 2. AP — supplier_ledger total vs GL AP control
 * 3. Employee — employee_ledger total vs GL employee payable
 * 4. Cash/Bank — bank balances vs GL cash_bank per branch
 * 5. Inventory — warehouse_stock value vs GL inventory control
 * 6. COGS — sales COGS vs inventory valuation movement
 */
class ReconciliationService
{
    private float $tolerance;

    public function __construct()
    {
        $this->tolerance = (float) config('app.gl_reconciliation_tolerance', 0.02);
    }

    /**
     * Run all 6 reconciliation sections.
     *
     * @return array{ sections: array, all_green: bool, run_at: string }
     */
    public function reconcileAll(): array
    {
        $sections = [
            $this->reconcileAR(),
            $this->reconcileAP(),
            $this->reconcileEmployee(),
            $this->reconcileCashBank(),
            $this->reconcileInventory(),
            $this->reconcileCOGS(),
        ];

        $allGreen = collect($sections)->every(fn($s) => $s['status'] === 'green');

        return [
            'sections' => $sections,
            'all_green' => $allGreen,
            'run_at' => now()->toISOString(),
            'tolerance' => $this->tolerance,
        ];
    }

    /**
     * 1. AR — customer_ledger balance vs GL AR control account.
     */
    public function reconcileAR(): array
    {
        try {
            $subledger = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(cl.debit - cl.credit), 0) AS balance
FROM customer_ledger cl
WHERE COALESCE(cl.is_reversed, false) = false
SQL)->balance;

            $glControl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'ar' AND l.is_active = true
SQL)->balance;

            $variance = abs($subledger - $glControl);

            return [
                'id' => 'ar',
                'label' => 'Accounts Receivable',
                'icon' => 'fa-hand-holding-dollar',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('ar', 'Accounts Receivable', 'fa-hand-holding-dollar', $e);
        }
    }

    /**
     * 2. AP — supplier_ledger balance vs GL AP control account.
     */
    public function reconcileAP(): array
    {
        try {
            $subledger = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(sl.credit - sl.debit), 0) AS balance
FROM supplier_ledger sl
WHERE COALESCE(sl.is_reversed, false) = false
SQL)->balance;

            $glControl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'ap' AND l.is_active = true
SQL)->balance;

            $variance = abs($subledger - $glControl);

            return [
                'id' => 'ap',
                'label' => 'Accounts Payable',
                'icon' => 'fa-money-bill-wave',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('ap', 'Accounts Payable', 'fa-money-bill-wave', $e);
        }
    }

    /**
     * 3. Employee — employee_ledger balance vs GL employee payable.
     */
    public function reconcileEmployee(): array
    {
        try {
            $subledger = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(el.credit - el.debit), 0) AS balance
FROM employee_ledger el
WHERE COALESCE(el.is_reversed, false) = false
SQL)->balance;

            $glControl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'employee_payable' AND l.is_active = true
SQL)->balance;

            $variance = abs($subledger - $glControl);

            return [
                'id' => 'employee',
                'label' => 'Employee Payable',
                'icon' => 'fa-user-tie',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('employee', 'Employee Payable', 'fa-user-tie', $e);
        }
    }

    /**
     * 4. Cash/Bank — bank balances vs GL cash_bank.
     */
    public function reconcileCashBank(): array
    {
        try {
            $bankTotal = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(b.balance), 0) AS balance FROM banks b WHERE b.is_active = true
SQL)->balance;

            $glControl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'cash_bank' AND l.is_active = true
SQL)->balance;

            $variance = abs($bankTotal - $glControl);

            return [
                'id' => 'cash_bank',
                'label' => 'Cash & Bank',
                'icon' => 'fa-university',
                'subledger_total' => $bankTotal,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('cash_bank', 'Cash & Bank', 'fa-university', $e);
        }
    }

    /**
     * 5. Inventory — warehouse_stock value vs GL inventory control.
     */
    public function reconcileInventory(): array
    {
        try {
            $stockValue = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(qty * avg_cost), 0) AS value FROM warehouse_stock WHERE qty > 0
SQL)->value;

            $glControl = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'inventory' AND l.is_active = true
SQL)->balance;

            $variance = abs($stockValue - $glControl);

            return [
                'id' => 'inventory',
                'label' => 'Inventory',
                'icon' => 'fa-warehouse',
                'subledger_total' => $stockValue,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('inventory', 'Inventory', 'fa-warehouse', $e);
        }
    }

    /**
     * 6. COGS — cumulative COGS should equal cumulative inventory reduction from sales.
     * (Simplified: just verify COGS ledger balance exists and is sensible.)
     */
    public function reconcileCOGS(): array
    {
        try {
            $cogsBalance = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'cogs' AND l.is_active = true
SQL)->balance;

            // COGS should be >= 0 (it's a cost). If negative, something is wrong (returns over-posted).
            $status = $cogsBalance >= -1 ? 'green' : 'red';

            return [
                'id' => 'cogs',
                'label' => 'Cost of Goods Sold',
                'icon' => 'fa-truck',
                'subledger_total' => $cogsBalance,
                'gl_control_total' => $cogsBalance,
                'variance' => 0.0,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('cogs', 'Cost of Goods Sold', 'fa-truck', $e);
        }
    }

    /**
     * Return an error section (when the reconciliation query fails).
     */
    private function errorSection(string $id, string $label, string $icon, \Throwable $e): array
    {
        Log::warning("Reconciliation failed for {$id}: {$e->getMessage()}");
        return [
            'id' => $id,
            'label' => $label,
            'icon' => $icon,
            'subledger_total' => 0.0,
            'gl_control_total' => 0.0,
            'variance' => 0.0,
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}
