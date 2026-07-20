<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation Service — Phase 9.6 (enhanced from Phase 5).
 *
 * Reconciles 6 sub-ledger sections against their GL control accounts.
 * Each section returns a status (green/red/error) with the variance amount
 * and drill-down details for investigation.
 *
 * Sections:
 * 1. AR — customer_ledger total vs GL AR control
 * 2. AP — supplier_ledger total vs GL AP control
 * 3. Employee — employee_ledger total vs GL employee payable
 * 4. Cash/Bank — bank balances vs GL cash_bank per branch
 * 5. Inventory — warehouse_stock value vs GL inventory control
 * 6. COGS — cumulative COGS vs cumulative stock-out value from sales
 *
 * Phase 9.6 enhancements:
 *   - Drill-down details per section (top entities contributing to variance)
 *   - COGS reconciliation improved: compares GL COGS vs Σ stock_transactions
 *     where reference_type='sales_challan' (the actual stock-out at cost)
 *   - Period-scoped reconciliation (optional as_of_date parameter)
 *   - Summary counts (green/red/error per section)
 *   - Reconciliation history logging (for audit trail)
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
     * @param string|null $asOfDate Optional: reconcile as of a specific date (for historical)
     * @return array{ sections: array, all_green: bool, run_at: string, tolerance: float, summary: array }
     */
    public function reconcileAll(?string $asOfDate = null): array
    {
        $sections = [
            $this->reconcileAR($asOfDate),
            $this->reconcileAP($asOfDate),
            $this->reconcileEmployee($asOfDate),
            $this->reconcileCashBank($asOfDate),
            $this->reconcileInventory($asOfDate),
            $this->reconcileCOGS($asOfDate),
        ];

        $allGreen = collect($sections)->every(fn($s) => $s['status'] === 'green');

        $summary = [
            'green' => collect($sections)->where('status', 'green')->count(),
            'red' => collect($sections)->where('status', 'red')->count(),
            'error' => collect($sections)->where('status', 'error')->count(),
            'total' => count($sections),
        ];

        // Log the reconciliation run for audit trail.
        try {
            DB::table('user_audit_log')->insert([
                'user_id' => auth()->id(),
                'action' => 'reconciliation_run',
                'details' => json_encode([
                    'all_green' => $allGreen,
                    'summary' => $summary,
                    'as_of_date' => $asOfDate,
                    'sections' => collect($sections)->map(fn($s) => [
                        'id' => $s['id'],
                        'status' => $s['status'],
                        'variance' => $s['variance'] ?? 0,
                    ])->toArray(),
                ]),
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Don't fail reconciliation if audit log fails.
            Log::warning('Reconciliation audit log failed: ' . $e->getMessage());
        }

        return [
            'sections' => $sections,
            'all_green' => $allGreen,
            'run_at' => now()->toISOString(),
            'tolerance' => $this->tolerance,
            'summary' => $summary,
        ];
    }

    /**
     * 1. AR — customer_ledger balance vs GL AR control account.
     * With drill-down: top 10 customers by outstanding balance.
     */
    public function reconcileAR(?string $asOfDate = null): array
    {
        try {
            $dateFilter = $asOfDate ? "AND cl.transaction_date <= '{$asOfDate}'" : '';
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';

            $subledger = (float) DB::selectOne("
SELECT COALESCE(SUM(cl.debit - cl.credit), 0) AS balance
FROM customer_ledger cl
WHERE COALESCE(cl.is_reversed, false) = false {$dateFilter}
")->balance;

            $glControl = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'ar' AND l.is_active = true
")->balance;

            $variance = abs($subledger - $glControl);

            // Drill-down: top 10 customers by outstanding balance.
            $drillDown = [];
            if ($variance > $this->tolerance) {
                $drillDown = DB::select("
SELECT c.id, c.customer_code, c.customer_name,
    COALESCE(SUM(cl.debit - cl.credit), 0) AS balance
FROM customer_ledger cl
JOIN customers c ON c.id = cl.customer_id
WHERE COALESCE(cl.is_reversed, false) = false {$dateFilter}
GROUP BY c.id, c.customer_code, c.customer_name
HAVING ABS(COALESCE(SUM(cl.debit - cl.credit), 0)) > 0.01
ORDER BY ABS(COALESCE(SUM(cl.debit - cl.credit), 0)) DESC
LIMIT 10
");
            }

            return [
                'id' => 'ar',
                'label' => 'Accounts Receivable',
                'icon' => 'fa-hand-holding-dollar',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'drill_down' => $drillDown,
                'drill_down_type' => 'customer',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('ar', 'Accounts Receivable', 'fa-hand-holding-dollar', $e);
        }
    }

    /**
     * 2. AP — supplier_ledger balance vs GL AP control account.
     * With drill-down: top 10 suppliers by outstanding balance.
     */
    public function reconcileAP(?string $asOfDate = null): array
    {
        try {
            $dateFilter = $asOfDate ? "AND sl.transaction_date <= '{$asOfDate}'" : '';
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';

            $subledger = (float) DB::selectOne("
SELECT COALESCE(SUM(sl.credit - sl.debit), 0) AS balance
FROM supplier_ledger sl
WHERE COALESCE(sl.is_reversed, false) = false {$dateFilter}
")->balance;

            $glControl = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'ap' AND l.is_active = true
")->balance;

            $variance = abs($subledger - $glControl);

            $drillDown = [];
            if ($variance > $this->tolerance) {
                $drillDown = DB::select("
SELECT s.id, s.supplier_code, s.supplier_name,
    COALESCE(SUM(sl.credit - sl.debit), 0) AS balance
FROM supplier_ledger sl
JOIN suppliers s ON s.id = sl.supplier_id
WHERE COALESCE(sl.is_reversed, false) = false {$dateFilter}
GROUP BY s.id, s.supplier_code, s.supplier_name
HAVING ABS(COALESCE(SUM(sl.credit - sl.debit), 0)) > 0.01
ORDER BY ABS(COALESCE(SUM(sl.credit - sl.debit), 0)) DESC
LIMIT 10
");
            }

            return [
                'id' => 'ap',
                'label' => 'Accounts Payable',
                'icon' => 'fa-money-bill-wave',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'drill_down' => $drillDown,
                'drill_down_type' => 'supplier',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('ap', 'Accounts Payable', 'fa-money-bill-wave', $e);
        }
    }

    /**
     * 3. Employee — employee_ledger balance vs GL employee payable.
     * With drill-down: top 10 employees by outstanding balance.
     */
    public function reconcileEmployee(?string $asOfDate = null): array
    {
        try {
            $dateFilter = $asOfDate ? "AND el.transaction_date <= '{$asOfDate}'" : '';
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';

            $subledger = (float) DB::selectOne("
SELECT COALESCE(SUM(el.credit - el.debit), 0) AS balance
FROM employee_ledger el
WHERE COALESCE(el.is_reversed, false) = false {$dateFilter}
")->balance;

            $glControl = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'employee_payable' AND l.is_active = true
")->balance;

            $variance = abs($subledger - ($glControl ?? 0));

            $drillDown = [];
            if ($variance > $this->tolerance) {
                $drillDown = DB::select("
SELECT e.id, e.employee_code, e.name,
    COALESCE(SUM(el.credit - el.debit), 0) AS balance
FROM employee_ledger el
JOIN employees e ON e.id = el.employee_id
WHERE COALESCE(el.is_reversed, false) = false {$dateFilter}
GROUP BY e.id, e.employee_code, e.name
HAVING ABS(COALESCE(SUM(el.credit - el.debit), 0)) > 0.01
ORDER BY ABS(COALESCE(SUM(el.credit - el.debit), 0)) DESC
LIMIT 10
");
            }

            return [
                'id' => 'employee',
                'label' => 'Employee Payable',
                'icon' => 'fa-user-tie',
                'subledger_total' => $subledger,
                'gl_control_total' => $glControl ?? 0,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'drill_down' => $drillDown,
                'drill_down_type' => 'employee',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('employee', 'Employee Payable', 'fa-user-tie', $e);
        }
    }

    /**
     * 4. Cash/Bank — bank balances vs GL cash_bank.
     * With drill-down: per-bank balance vs GL.
     */
    public function reconcileCashBank(?string $asOfDate = null): array
    {
        try {
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';

            $bankTotal = (float) DB::selectOne("
SELECT COALESCE(SUM(b.balance), 0) AS balance FROM banks b WHERE b.is_active = true
")->balance;

            $glControl = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'cash_bank' AND l.is_active = true
")->balance;

            $variance = abs($bankTotal - $glControl);

            // Drill-down: per-bank.
            $drillDown = [];
            if ($variance > $this->tolerance) {
                $drillDown = DB::select("
SELECT b.id, b.bank_name, b.account_number, b.balance
FROM banks b
WHERE b.is_active = true
ORDER BY ABS(b.balance) DESC
LIMIT 10
");
            }

            return [
                'id' => 'cash_bank',
                'label' => 'Cash & Bank',
                'icon' => 'fa-university',
                'subledger_total' => $bankTotal,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'drill_down' => $drillDown,
                'drill_down_type' => 'bank',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('cash_bank', 'Cash & Bank', 'fa-university', $e);
        }
    }

    /**
     * 5. Inventory — warehouse_stock value vs GL inventory control.
     * With drill-down: top 10 products by stock value.
     */
    public function reconcileInventory(?string $asOfDate = null): array
    {
        try {
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';

            $stockValue = (float) DB::selectOne("
SELECT COALESCE(SUM(qty * avg_cost), 0) AS value FROM warehouse_stock WHERE qty > 0
")->value;

            $glControl = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'inventory' AND l.is_active = true
")->balance;

            $variance = abs($stockValue - $glControl);

            // Drill-down: top 10 products by stock value.
            $drillDown = [];
            if ($variance > $this->tolerance) {
                $drillDown = DB::select("
SELECT p.id, p.product_code, p.product_name,
    ws.qty, ws.avg_cost, (ws.qty * ws.avg_cost) AS stock_value
FROM warehouse_stock ws
JOIN products p ON p.id = ws.product_id
WHERE ws.qty > 0
ORDER BY (ws.qty * ws.avg_cost) DESC
LIMIT 10
");
            }

            return [
                'id' => 'inventory',
                'label' => 'Inventory',
                'icon' => 'fa-warehouse',
                'subledger_total' => $stockValue,
                'gl_control_total' => $glControl,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'drill_down' => $drillDown,
                'drill_down_type' => 'product',
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('inventory', 'Inventory', 'fa-warehouse', $e);
        }
    }

    /**
     * 6. COGS — GL COGS balance vs Σ stock_transactions (sales_challan at cost).
     *
     * Phase 9.6 improvement: compares the GL COGS ledger balance against
     * the actual stock-out value from stock_transactions where
     * reference_type='sales_challan' (the source of truth for COGS).
     *
     * GL COGS should equal: Σ(qty × rate) from stock_transactions
     * where reference_type='sales_challan' AND is_reversed=false
     * MINUS Σ(qty × rate) from stock_transactions
     * where reference_type='sales_return' AND is_reversed=false
     * (returns reverse COGS)
     */
    public function reconcileCOGS(?string $asOfDate = null): array
    {
        try {
            $jeDateFilter = $asOfDate ? "AND je.entry_date <= '{$asOfDate}'" : '';
            $stDateFilter = $asOfDate ? "AND st.transaction_date <= '{$asOfDate}'" : '';

            // GL COGS balance (debit - credit).
            $glCogs = (float) DB::selectOne("
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false {$jeDateFilter}
WHERE l.ledger_nature = 'cogs' AND l.is_active = true
")->balance;

            // Stock-out value from sales challans (the actual COGS).
            $challanCogs = (float) DB::selectOne("
SELECT COALESCE(SUM(ABS(st.qty) * st.rate), 0) AS cogs
FROM stock_transactions st
WHERE st.reference_type = 'sales_challan'
  AND st.is_reversed = false
  AND st.qty < 0
  {$stDateFilter}
")->cogs;

            // Stock-in value from sales returns (reverses COGS).
            $returnCogs = (float) DB::selectOne("
SELECT COALESCE(SUM(st.qty * st.rate), 0) AS cogs
FROM stock_transactions st
WHERE st.reference_type = 'sales_return'
  AND st.is_reversed = false
  AND st.qty > 0
  {$stDateFilter}
")->cogs;

            // Net stock-based COGS = challan COGS - return COGS.
            $stockCogs = $challanCogs - $returnCogs;

            $variance = abs($glCogs - $stockCogs);

            return [
                'id' => 'cogs',
                'label' => 'Cost of Goods Sold',
                'icon' => 'fa-truck',
                'subledger_total' => $stockCogs,
                'gl_control_total' => $glCogs,
                'variance' => $variance,
                'status' => $variance <= $this->tolerance ? 'green' : 'red',
                'detail' => [
                    'challan_cogs' => $challanCogs,
                    'return_cogs' => $returnCogs,
                    'net_stock_cogs' => $stockCogs,
                    'gl_cogs' => $glCogs,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->errorSection('cogs', 'Cost of Goods Sold', 'fa-truck', $e);
        }
    }

    /**
     * Get a single section's reconciliation (for AJAX drill-down).
     *
     * @param string $sectionId
     * @return array|null
     */
    public function reconcileSection(string $sectionId): ?array
    {
        return match ($sectionId) {
            'ar' => $this->reconcileAR(),
            'ap' => $this->reconcileAP(),
            'employee' => $this->reconcileEmployee(),
            'cash_bank' => $this->reconcileCashBank(),
            'inventory' => $this->reconcileInventory(),
            'cogs' => $this->reconcileCOGS(),
            default => null,
        };
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
