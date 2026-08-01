<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Report Service — Phase 5.
 *
 * Executes the financial report queries against PostgreSQL.
 * Uses materialized views where available (for performance) and
 * falls back to direct queries for real-time / as-of-date data.
 *
 * Each method returns an array with:
 *   - 'meta': report metadata (title, dates, filters)
 *   - 'data': the report rows
 *   - 'totals': aggregate totals
 *   - 'checks': integrity checks (e.g., Dr=Cr for Trial Balance)
 */
class ReportService
{
    /**
     * Trial Balance — opening, period, closing per ledger.
     * Verifies total debits = total credits.
     *
     * Phase 4.1 fixes:
     *   - Includes l.opening_balance from the ledgers table (fiscal year start carry-forward)
     *   - Uses l.normal_balance to compute net opening/closing balance on the correct side
     *   - Excludes group-header ledgers (those with children) from data rows
     *   - Supports branch_id filtering for multi-branch reporting
     *   - Adds comprehensive integrity checks (opening+period=closing, sub-ledger reconciliation)
     *   - Returns hierarchy info (parent_id, has_children) for tree display
     *
     * @return array{ meta: array, data: \Illuminate\Support\Collection, totals: array, checks: array }
     */
    public function trialBalance(Carbon $fromDate, Carbon $toDate, ?string $accountType = null, bool $includeZero = false, ?int $branchId = null): array
    {
        // ── Core SQL ──────────────────────────────────────────────────────
        // The opening_balance column on ledgers stores the fiscal-year-start
        // carry-forward.  We add it to the opening Dr/Cr depending on the
        // account's normal_balance side (debit → opening_debit, credit →
        // opening_credit).  This ensures that a ledger with an opening
        // balance but no prior-period journal entries still shows the correct
        // opening figure.
        //
        // Phase 4.1 revision 2: Tally-style format with parent group name.
        $sql = <<<SQL
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.normal_balance,
    l.parent_id,
    l.is_control_account,
    l.control_account_type,
    l.opening_balance,
    -- Parent group name (for the "Parent Group" column)
    p.ledger_name AS parent_group,
    -- Has children? (group header vs posting account)
    EXISTS(SELECT 1 FROM ledgers child WHERE child.parent_id = l.id AND child.is_active = true) AS has_children,
    -- Opening: include the fiscal-year opening_balance on the normal side
    COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.debit ELSE 0 END), 0)
        + CASE WHEN COALESCE(l.normal_balance, 'debit') = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS opening_debit,
    COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.credit ELSE 0 END), 0)
        + CASE WHEN COALESCE(l.normal_balance, 'debit') = 'credit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS opening_credit,
    -- Period movement
    COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit ELSE 0 END), 0) AS period_debit,
    COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit ELSE 0 END), 0) AS period_credit,
    -- Closing: include opening_balance on the normal side
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit ELSE 0 END), 0)
        + CASE WHEN COALESCE(l.normal_balance, 'debit') = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS closing_debit,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit ELSE 0 END), 0)
        + CASE WHEN COALESCE(l.normal_balance, 'debit') = 'credit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS closing_credit
FROM ledgers l
LEFT JOIN ledgers p ON p.id = l.parent_id AND p.is_active = true
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.is_active = true
  AND l.deleted_at IS NULL
SQL;

        $params = [$fromDate, $fromDate, $fromDate, $toDate, $fromDate, $toDate, $toDate, $toDate];

        if ($accountType) {
            $sql .= ' AND l.account_type = ?';
            $params[] = $accountType;
        }

        if ($branchId) {
            $sql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $params[] = $branchId;
        }

        $sql .= " GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature, l.normal_balance, l.parent_id, l.is_control_account, l.control_account_type, l.opening_balance, p.ledger_name";
        $sql .= " ORDER BY CASE l.account_type WHEN 'Asset' THEN 1 WHEN 'Liability' THEN 2 WHEN 'Equity' THEN 3 WHEN 'Income' THEN 4 WHEN 'Expense' THEN 5 ELSE 0 END, l.ledger_code ASC";

        $rows = collect(DB::select($sql, $params));

        // ── Compute net balances using normal_balance ─────────────────────
        $rows = $rows->map(function ($r) {
            $nb = $r->normal_balance ?? 'debit';

            // Opening net balance
            $openingNet = round($r->opening_debit - $r->opening_credit, 2);
            if ($nb === 'debit') {
                $r->opening_balance = abs($openingNet);
                $r->opening_side    = $openingNet >= 0 ? 'Dr' : 'Cr';
            } else {
                $r->opening_balance = abs(-$openingNet);
                $r->opening_side    = $openingNet <= 0 ? 'Cr' : 'Dr';
            }

            // Closing net balance
            $closingNet = round($r->closing_debit - $r->closing_credit, 2);
            if ($nb === 'debit') {
                $r->closing_balance = abs($closingNet);
                $r->closing_side    = $closingNet >= 0 ? 'Dr' : 'Cr';
            } else {
                $r->closing_balance = abs(-$closingNet);
                $r->closing_side    = $closingNet <= 0 ? 'Cr' : 'Dr';
            }

            // Verify: opening + period_debit - period_credit = closing
            $expectedClosing = round($r->opening_debit - $r->opening_credit + $r->period_debit - $r->period_credit, 2);
            $actualClosing   = round($r->closing_debit - $r->closing_credit, 2);
            $r->balance_check = abs($expectedClosing - $actualClosing) < 0.01;

            return $r;
        });

        // ── Filter out group-header ledgers (those with children) ─────────
        // Group headers are structural accounts that should not receive
        // journal lines directly.  Their subtotals are computed by the view.
        $postingRows = $rows->filter(fn($r) => !$r->has_children);

        // ── Filter zero-balance accounts ──────────────────────────────────
        if (!$includeZero) {
            $postingRows = $postingRows->filter(fn($r) =>
                abs($r->opening_debit) > 0.005 || abs($r->opening_credit) > 0.005 ||
                abs($r->period_debit) > 0.005 || abs($r->period_credit) > 0.005 ||
                abs($r->closing_debit) > 0.005 || abs($r->closing_credit) > 0.005
            )->values();
        } else {
            $postingRows = $postingRows->values();
        }

        // ── Totals (only posting accounts) ────────────────────────────────
        $totals = [
            'opening_debit'  => $postingRows->sum('opening_debit'),
            'opening_credit' => $postingRows->sum('opening_credit'),
            'period_debit'   => $postingRows->sum('period_debit'),
            'period_credit'  => $postingRows->sum('period_credit'),
            'closing_debit'  => $postingRows->sum('closing_debit'),
            'closing_credit' => $postingRows->sum('closing_credit'),
        ];

        // ── Integrity checks ─────────────────────────────────────────────
        $checks = [
            'opening_balanced' => abs($totals['opening_debit'] - $totals['opening_credit']) < 0.01,
            'period_balanced'  => abs($totals['period_debit'] - $totals['period_credit']) < 0.01,
            'closing_balanced' => abs($totals['closing_debit'] - $totals['closing_credit']) < 0.01,
            'opening_diff'     => round($totals['opening_debit'] - $totals['opening_credit'], 2),
            'period_diff'      => round($totals['period_debit'] - $totals['period_credit'], 2),
            'closing_diff'     => round($totals['closing_debit'] - $totals['closing_credit'], 2),
        ];

        // Check: opening + period = closing for every account
        $balanceCheckFails = $postingRows->filter(fn($r) => !$r->balance_check)->count();
        $checks['all_accounts_balance'] = $balanceCheckFails === 0;
        $checks['balance_check_fails']  = $balanceCheckFails;

        // Sub-ledger reconciliation for control accounts
        $subledgerChecks = $this->trialBalanceSubledgerCheck($fromDate, $toDate, $branchId);
        $checks['subledger_reconciliation'] = $subledgerChecks;

        // Orphaned journal lines check
        $orphaned = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM journal_lines jl
WHERE NOT EXISTS (SELECT 1 FROM ledgers l WHERE l.id = jl.ledger_id AND l.is_active = true AND l.deleted_at IS NULL)
SQL);
        $checks['orphaned_journal_lines'] = (int) $orphaned->cnt;

        return [
            'meta' => [
                'title'        => 'Trial Balance',
                'from_date'    => $fromDate->format('Y-m-d'),
                'to_date'      => $toDate->format('Y-m-d'),
                'account_type' => $accountType,
                'include_zero' => $includeZero,
                'branch_id'    => $branchId,
            ],
            'data'   => $postingRows,
            'totals' => $totals,
            'checks' => $checks,
        ];
    }

    /**
     * Sub-ledger reconciliation for control accounts.
     * Compares the GL balance with the sub-ledger balance for AR, AP, and Employee Payable.
     */
    private function trialBalanceSubledgerCheck(Carbon $fromDate, Carbon $toDate, ?int $branchId = null): array
    {
        $checks = [];
        $branchFilter = $branchId ? ' AND cl.branch_id = ' . (int) $branchId : '';

        // AR reconciliation: GL balance vs customer_ledger sub-ledger
        // AR is a debit-normal account: GL balance = debit - credit
        $arGl = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS gl_balance
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
JOIN ledgers l ON l.id = jl.ledger_id AND l.ledger_nature = 'ar'
WHERE je.entry_date <= ?
SQL, [$toDate]);

        $arSub = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(cl.debit), 0) - COALESCE(SUM(cl.credit), 0) AS sub_balance
FROM customer_ledger cl
WHERE cl.transaction_date <= ? {$branchFilter}
SQL, [$toDate]);

        $arGlBal  = round((float) ($arGl->gl_balance ?? 0), 2);
        $arSubBal = round((float) ($arSub->sub_balance ?? 0), 2);
        $checks['ar'] = [
            'label'        => 'Accounts Receivable',
            'gl_balance'   => $arGlBal,
            'sub_balance'  => $arSubBal,
            'difference'   => round($arGlBal - $arSubBal, 2),
            'reconciled'   => abs($arGlBal - $arSubBal) < 0.01,
        ];

        // AP reconciliation: GL balance vs supplier_ledger sub-ledger
        // AP is a credit-normal account: GL balance = credit - debit
        $apGl = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS gl_balance
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
JOIN ledgers l ON l.id = jl.ledger_id AND l.ledger_nature = 'ap'
WHERE je.entry_date <= ?
SQL, [$toDate]);

        $apSub = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(sl.credit), 0) - COALESCE(SUM(sl.debit), 0) AS sub_balance
FROM supplier_ledger sl
WHERE sl.transaction_date <= ? {$branchFilter}
SQL, [$toDate]);

        $apGlBal  = round((float) ($apGl->gl_balance ?? 0), 2);
        $apSubBal = round((float) ($apSub->sub_balance ?? 0), 2);
        $checks['ap'] = [
            'label'        => 'Accounts Payable',
            'gl_balance'   => $apGlBal,
            'sub_balance'  => $apSubBal,
            'difference'   => round($apGlBal - $apSubBal, 2),
            'reconciled'   => abs($apGlBal - $apSubBal) < 0.01,
        ];

        // Employee Payable reconciliation: GL balance vs employee_ledger sub-ledger
        // Employee Payable is a credit-normal account: GL balance = credit - debit
        $epGl = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS gl_balance
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
JOIN ledgers l ON l.id = jl.ledger_id AND l.ledger_nature = 'employee_payable'
WHERE je.entry_date <= ?
SQL, [$toDate]);

        $epSub = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(el.credit), 0) - COALESCE(SUM(el.debit), 0) AS sub_balance
FROM employee_ledger el
WHERE el.transaction_date <= ? {$branchFilter}
SQL, [$toDate]);

        $epGlBal  = round((float) ($epGl->gl_balance ?? 0), 2);
        $epSubBal = round((float) ($epSub->sub_balance ?? 0), 2);
        $checks['employee_payable'] = [
            'label'        => 'Employee Payable',
            'gl_balance'   => $epGlBal,
            'sub_balance'  => $epSubBal,
            'difference'   => round($epGlBal - $epSubBal, 2),
            'reconciled'   => abs($epGlBal - $epSubBal) < 0.01,
        ];

        return $checks;
    }

    /**
     * Profit & Loss — multi-step format (Xero-style).
     *
     * Structure:
     *   1. Revenue                     (sales_revenue, other_income, transport_revenue, inventory_surplus)
     *   2. Less: Cost of Goods Sold    (cogs)
     *   3. = Gross Profit
     *   4. Less: Operating Expenses    (operating_expense, inventory_shrinkage, damage_loss, salary_expense)
     *   5. = Operating Income (EBIT)
     *   6. Less: Finance Costs         (finance_cost)
     *   7. = Net Income Before Tax
     *   8. = Net Income (After Tax)    [placeholder for tax — no tax ledger yet]
     *
     * Contra-revenue accounts (sales_return, sales_discount) reduce Revenue.
     * Each section shows individual ledger lines with net amounts.
     *
     * @return array{ meta: array, sections: array, totals: array }
     */
    public function profitAndLoss(Carbon $fromDate, Carbon $toDate, ?int $branchId = null, ?Carbon $compareFrom = null, ?Carbon $compareTo = null): array
    {
        // ── Section definitions (Xero multi-step) ────────────────────────
        $sections = [
            'revenue' => [
                'label'       => 'Revenue',
                'natures'     => ['sales_revenue', 'other_income', 'transport_revenue', 'inventory_surplus'],
                'contra'      => ['sales_return', 'sales_discount'],  // Reduce revenue
                'sort'        => 10,
                'is_subtotal' => false,
            ],
            'cost_of_sales' => [
                'label'       => 'Cost of Goods Sold',
                'natures'     => ['cogs'],
                'sort'        => 20,
                'is_subtotal' => false,
            ],
            'gross_profit' => [
                'label'       => 'Gross Profit',
                'sort'        => 25,
                'is_subtotal' => true,
            ],
            'operating_expenses' => [
                'label'       => 'Operating Expenses',
                'natures'     => ['operating_expense', 'inventory_shrinkage', 'damage_loss', 'salary_expense'],
                'sort'        => 30,
                'is_subtotal' => false,
            ],
            'operating_income' => [
                'label'       => 'Operating Income',
                'sort'        => 35,
                'is_subtotal' => true,
            ],
            'finance_costs' => [
                'label'       => 'Finance Costs',
                'natures'     => ['finance_cost'],
                'sort'        => 40,
                'is_subtotal' => false,
            ],
            'net_income_before_tax' => [
                'label'       => 'Net Income Before Tax',
                'sort'        => 45,
                'is_subtotal' => true,
            ],
            'net_income' => [
                'label'       => 'Net Income',
                'sort'        => 50,
                'is_subtotal' => true,
            ],
        ];

        $result = [];
        $totalRevenue = 0;
        $totalCogs = 0;
        $totalOpex = 0;
        $totalFinance = 0;

        // ── Fetch data for each data section ─────────────────────────────
        foreach ($sections as $key => $section) {
            if ($section['is_subtotal']) {
                continue; // Subtotals are computed later
            }

            $allNatures = array_merge($section['natures'] ?? [], $section['contra'] ?? []);
            if (empty($allNatures)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($allNatures), '?'));

            $sql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name, l.ledger_nature,
    COALESCE(SUM(jl.debit), 0) AS debit,
    COALESCE(SUM(jl.credit), 0) AS credit,
    COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS net_amount
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.entry_date BETWEEN ? AND ?
WHERE l.is_active = true AND l.deleted_at IS NULL AND l.ledger_nature IN ({$placeholders})
SQL;

            $params = array_merge([$fromDate, $toDate], $allNatures);

            if ($branchId) {
                $sql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
                $params[] = $branchId;
            }
            $sql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name, l.ledger_nature ORDER BY l.ledger_code ASC';

            $rows = collect(DB::select($sql, $params))->filter(fn($r) => abs($r->net_amount) > 0.005)->values();

            // Contra-revenue items reduce the section total
            $sectionTotal = 0;
            foreach ($rows as $row) {
                if (in_array($row->ledger_nature, $section['contra'] ?? [])) {
                    $sectionTotal += $row->net_amount; // Contra items are already negative for revenue
                } else {
                    $sectionTotal += $row->net_amount;
                }
            }

            // Accumulate totals
            if ($key === 'revenue') $totalRevenue = $sectionTotal;
            if ($key === 'cost_of_sales') $totalCogs = $sectionTotal;
            if ($key === 'operating_expenses') $totalOpex = $sectionTotal;
            if ($key === 'finance_costs') $totalFinance = $sectionTotal;

            $result[$key] = [
                'label' => $section['label'],
                'sort'  => $section['sort'],
                'rows'  => $rows,
                'total' => $sectionTotal,
            ];
        }

        // ── Compute subtotals ────────────────────────────────────────────
        $grossProfit = $totalRevenue - $totalCogs;
        $operatingIncome = $grossProfit - $totalOpex;
        $netIncomeBeforeTax = $operatingIncome - $totalFinance;
        $netIncome = $netIncomeBeforeTax; // No tax ledger yet

        $result['gross_profit'] = [
            'label' => 'Gross Profit',
            'sort'  => 25,
            'rows'  => collect(),
            'total' => $grossProfit,
        ];
        $result['operating_income'] = [
            'label' => 'Operating Income',
            'sort'  => 35,
            'rows'  => collect(),
            'total' => $operatingIncome,
        ];
        $result['net_income_before_tax'] = [
            'label' => 'Net Income Before Tax',
            'sort'  => 45,
            'rows'  => collect(),
            'total' => $netIncomeBeforeTax,
        ];
        $result['net_income'] = [
            'label' => 'Net Income',
            'sort'  => 50,
            'rows'  => collect(),
            'total' => $netIncome,
        ];

        // ── Margin calculations ──────────────────────────────────────────
        $grossMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
        $netMargin = $totalRevenue > 0 ? ($netIncome / $totalRevenue) * 100 : 0;

        return [
            'meta' => [
                'title'     => 'Profit & Loss Statement',
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date'   => $toDate->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'sections' => $result,
            'totals' => [
                'revenue'               => $totalRevenue,
                'cogs'                  => $totalCogs,
                'gross_profit'          => $grossProfit,
                'operating_expenses'    => $totalOpex,
                'operating_income'      => $operatingIncome,
                'finance_costs'         => $totalFinance,
                'net_income_before_tax' => $netIncomeBeforeTax,
                'net_income'            => $netIncome,
                'gross_margin_pct'      => round($grossMargin, 1),
                'net_margin_pct'        => round($netMargin, 1),
            ],
        ];
    }

    /**
     * Balance Sheet — Assets = Liabilities + Equity as of date.
     * Income/Expense balances roll into equity (unclosed current-period result).
     *
     * Phase 4.2: Added opening_balance, deleted_at filter, parent_group.
     *
     * @return array{ meta: array, assets: \Illuminate\Support\Collection, liabilities: \Illuminate\Support\Collection, equity: \Illuminate\Support\Collection, totals: array, checks: array }
     */
    public function balanceSheet(Carbon $asOfDate, ?int $branchId = null, bool $includeZero = false): array
    {
        $sql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
    l.normal_balance, l.opening_balance,
    p.ledger_name AS parent_group,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net_debit,
    COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS net_credit
FROM ledgers l
LEFT JOIN ledgers p ON p.id = l.parent_id AND p.is_active = true
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.entry_date <= ?
WHERE l.is_active = true AND l.deleted_at IS NULL
SQL;
        $params = [$asOfDate];
        if ($branchId) {
            $sql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature, l.normal_balance, l.opening_balance, p.ledger_name ORDER BY l.ledger_code ASC';

        $rows = collect(DB::select($sql, $params));

        $assets = $rows->where('account_type', 'Asset')->filter(fn($r) => $includeZero || abs($r->net_debit) > 0.005)->values();
        $liabilities = $rows->where('account_type', 'Liability')->filter(fn($r) => $includeZero || abs($r->net_credit) > 0.005)->values();
        $equity = $rows->where('account_type', 'Equity')->filter(fn($r) => $includeZero || abs($r->net_credit) > 0.005)->values();

        // Unclosed income/expense roll into equity (current-period result).
        $incomeRows = $rows->where('account_type', 'Income');
        $expenseRows = $rows->where('account_type', 'Expense');
        $netIncome = $incomeRows->sum('net_credit') - $expenseRows->sum('net_debit');

        $totalAssets = $assets->sum('net_debit');
        $totalLiabilities = $liabilities->sum('net_credit');
        $totalEquity = $equity->sum('net_credit') + $netIncome;

        return [
            'meta' => [
                'title' => 'Balance Sheet',
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_period_result' => $netIncome,
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'total_liabilities_equity' => $totalLiabilities + $totalEquity,
            ],
            'checks' => [
                'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
            ],
        ];
    }

    /**
     * Cash Flow Statement (indirect method).
     * Starts from net profit, adjusts for non-cash items + working capital changes.
     *
     * @return array{ meta: array, sections: array, totals: array }
     */
    public function cashFlow(Carbon $fromDate, Carbon $toDate, ?int $branchId = null): array
    {
        // ────────────────────────────────────────────────────────────────
        // Cash Flow Statement — Indirect Method (Xero-style)
        // ────────────────────────────────────────────────────────────────
        // Section 1: Operating Activities
        //   Starts from Net Profit, adds back non-cash items (depreciation),
        //   adjusts for working-capital changes (AR, AP, Inventory, Employee Payable).
        // Section 2: Investing Activities
        //   Net movement in non-current asset ledgers (Fixed Assets, etc.)
        // Section 3: Financing Activities
        //   Net movement in long-term liability & equity ledgers (loans, owner's equity)
        //   Excludes Retained Earnings (already captured via net profit).
        // Section 4: Net Cash Movement
        //   Opening cash + (Operating + Investing + Financing) = Closing cash
        //   Reconciled against actual GL cash/bank movement (integrity check).
        // ────────────────────────────────────────────────────────────────

        $openingDate = (clone $fromDate)->subDay();

        // ── 1. Net Profit from P&L ──────────────────────────────────────
        $pl = $this->profitAndLoss($fromDate, $toDate, $branchId);
        $netProfit = $pl['totals']['net_income'] ?? $pl['totals']['net_profit'] ?? 0;

        // ── 2. Depreciation (non-cash expense to add back) ──────────────
        $depSql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name,
    COALESCE(SUM(jl.debit), 0) AS debit,
    COALESCE(SUM(jl.credit), 0) AS credit,
    COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) AS net_amount
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.entry_date BETWEEN ? AND ?
WHERE l.is_active = true AND l.deleted_at IS NULL
    AND l.ledger_nature = 'operating_expense'
    AND (l.ledger_name ILIKE '%depreciation%' OR l.ledger_name ILIKE '%amortisation%' OR l.ledger_name ILIKE '%amortization%')
SQL;
        $depParams = [$fromDate, $toDate];
        if ($branchId) {
            $depSql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $depParams[] = $branchId;
        }
        $depSql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name ORDER BY l.ledger_name';

        $depRows = collect(DB::select($depSql, $depParams))->filter(fn($r) => abs($r->net_amount) > 0.005)->values();
        $depreciation = $depRows->sum('net_amount'); // debit balance = expense, add back

        // ── 3. Working capital changes (opening vs closing balances) ────
        //    For each working-capital ledger, calculate:
        //      opening = balance as of day before from_date
        //      closing = balance as of to_date
        //      change  = closing - opening
        //    Adjustment logic (indirect method):
        //      AR increase  → cash outflow (subtract)
        //      AP increase  → cash inflow  (add)
        //      INV increase → cash outflow (subtract)
        //      Employee Payable increase → cash inflow (add)
        $wcSql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0)
        + CASE WHEN l.normal_balance = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS opening_balance,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0)
        + CASE WHEN l.normal_balance = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END
        AS closing_balance
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.is_active = true AND l.deleted_at IS NULL
    AND l.ledger_nature IN ('ar', 'ap', 'inventory', 'employee_payable')
SQL;
        $wcParams = [$openingDate, $toDate];
        if ($branchId) {
            $wcSql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $wcParams[] = $branchId;
            $wcParams[] = $branchId;
        }
        $wcSql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature, l.normal_balance, l.opening_balance ORDER BY l.ledger_name';

        $wcRows = collect(DB::select($wcSql, $wcParams));

        // Build individual working-capital adjustment lines
        $wcAdjustments = [];
        $wcAdjustmentTotal = 0;

        $wcMapping = [
            'ar'              => ['label' => 'Accounts Receivable',        'sign' => -1],  // Asset: increase = outflow
            'inventory'        => ['label' => 'Inventory / Stock',          'sign' => -1],  // Asset: increase = outflow
            'ap'              => ['label' => 'Accounts Payable',           'sign' => +1],  // Liability: increase = inflow
            'employee_payable' => ['label' => 'Employee Payable',          'sign' => +1],  // Liability: increase = inflow
        ];

        foreach ($wcMapping as $nature => $cfg) {
            $rows = $wcRows->where('ledger_nature', $nature);
            $opening = $rows->sum('opening_balance');
            $closing = $rows->sum('closing_balance');
            $change = $closing - $opening;
            $adjustment = $change * $cfg['sign']; // cash effect

            $wcAdjustments[] = (object) [
                'nature'        => $nature,
                'label'         => $cfg['label'],
                'opening'       => $opening,
                'closing'       => $closing,
                'change'        => $change,
                'adjustment'    => $adjustment,
                'detail_rows'   => $rows->values(),
            ];
            $wcAdjustmentTotal += $adjustment;
        }

        $operatingCash = $netProfit + $depreciation + $wcAdjustmentTotal;

        // ── 4. Investing Activities ─────────────────────────────────────
        //    Non-current asset ledgers (parent = Fixed Assets, L-0200)
        //    Debit movement = purchase (cash outflow, negative)
        //    Credit movement = sale/disposal (cash inflow, positive)
        $invSql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name,
    COALESCE(SUM(jl.debit), 0) AS debit,
    COALESCE(SUM(jl.credit), 0) AS credit,
    COALESCE(SUM(jl.credit) - SUM(jl.debit), 0) AS net_amount
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.entry_date BETWEEN ? AND ?
WHERE l.is_active = true AND l.deleted_at IS NULL
    AND l.account_type = 'Asset'
    AND l.parent_id IN (SELECT id FROM ledgers WHERE ledger_code = 'L-0200' AND deleted_at IS NULL)
    AND l.ledger_nature NOT IN ('cash_bank', 'ar', 'inventory', 'interbranch_receivable')
SQL;
        $invParams = [$fromDate, $toDate];
        if ($branchId) {
            $invSql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $invParams[] = $branchId;
        }
        $invSql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name ORDER BY l.ledger_name';

        $investingRows = collect(DB::select($invSql, $invParams))->filter(fn($r) => abs($r->net_amount) > 0.005)->values();
        // net_amount = credit - debit → positive = cash inflow from sale, negative = cash outflow from purchase
        $investingCash = $investingRows->sum('net_amount');

        // ── 5. Financing Activities ─────────────────────────────────────
        //    Long-term liability ledgers + equity ledgers (excl. retained earnings)
        //    Credit movement = cash inflow (loan received, capital introduced)
        //    Debit movement = cash outflow (loan repaid, drawings)
        $finSql = <<<SQL
SELECT
    l.id, l.ledger_code, l.ledger_name, l.ledger_nature,
    COALESCE(SUM(jl.debit), 0) AS debit,
    COALESCE(SUM(jl.credit), 0) AS credit,
    COALESCE(SUM(jl.credit) - SUM(jl.debit), 0) AS net_amount
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.entry_date BETWEEN ? AND ?
WHERE l.is_active = true AND l.deleted_at IS NULL
    AND (
        l.parent_id IN (SELECT id FROM ledgers WHERE ledger_code = 'L-0400' AND deleted_at IS NULL)
        OR (l.account_type = 'Equity' AND l.ledger_nature NOT IN ('retained_earnings')
            AND l.parent_id IN (SELECT id FROM ledgers WHERE ledger_code = 'L-0500' AND deleted_at IS NULL))
    )
SQL;
        $finParams = [$fromDate, $toDate];
        if ($branchId) {
            $finSql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $finParams[] = $branchId;
        }
        $finSql .= ' GROUP BY l.id, l.ledger_code, l.ledger_name, l.ledger_nature ORDER BY l.ledger_name';

        $financingRows = collect(DB::select($finSql, $finParams))->filter(fn($r) => abs($r->net_amount) > 0.005)->values();
        // net_amount = credit - debit → positive = inflow, negative = outflow
        $financingCash = $financingRows->sum('net_amount');

        // ── 6. Cash/bank opening & closing balances ─────────────────────
        $cashBalSql = <<<SQL
SELECT
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0)
        + SUM(CASE WHEN l.normal_balance = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END)
        AS opening_balance,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0)
        + SUM(CASE WHEN l.normal_balance = 'debit' THEN COALESCE(l.opening_balance, 0) ELSE 0 END)
        AS closing_balance
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.is_active = true AND l.deleted_at IS NULL AND l.ledger_nature = 'cash_bank'
SQL;
        $cashBalParams = [$openingDate, $toDate];
        if ($branchId) {
            $cashBalSql .= ' AND (je.branch_id = ? OR je.branch_id IS NULL)';
            $cashBalParams[] = $branchId;
            $cashBalParams[] = $branchId;
        }
        $cashBalRow = DB::select($cashBalSql, $cashBalParams)[0];
        $cashOpening = (float) ($cashBalRow->opening_balance ?? 0);
        $cashClosing = (float) ($cashBalRow->closing_balance ?? 0);

        // Period cash movement from GL
        $cashMovSql = <<<SQL
SELECT
    COALESCE(SUM(jl.debit - jl.credit), 0) AS cash_movement
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'cash_bank' AND l.deleted_at IS NULL AND je.entry_date BETWEEN ? AND ?
SQL;
        $cashMovParams = [$fromDate, $toDate];
        if ($branchId) {
            $cashMovSql .= ' AND je.branch_id = ?';
            $cashMovParams[] = $branchId;
        }
        $cashMovement = (float) (DB::select($cashMovSql, $cashMovParams)[0]->cash_movement ?? 0);

        // ── 7. Net cash movement & reconciliation ────────────────────────
        $netCashChange = $operatingCash + $investingCash + $financingCash;
        $calculatedClosing = $cashOpening + $netCashChange;
        $plugDifference = $cashMovement - $netCashChange;

        return [
            'meta' => [
                'title' => 'Cash Flow Statement (Indirect Method)',
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'sections' => [
                'operating' => [
                    'label' => 'Cash Flow from Operating Activities',
                    'net_profit' => $netProfit,
                    'depreciation' => $depreciation,
                    'dep_rows' => $depRows,
                    'wc_adjustments' => $wcAdjustments,
                    'wc_adjustment_total' => $wcAdjustmentTotal,
                    'net' => $operatingCash,
                ],
                'investing' => [
                    'label' => 'Cash Flow from Investing Activities',
                    'rows' => $investingRows,
                    'net' => $investingCash,
                ],
                'financing' => [
                    'label' => 'Cash Flow from Financing Activities',
                    'rows' => $financingRows,
                    'net' => $financingCash,
                ],
            ],
            'totals' => [
                'operating_cash' => $operatingCash,
                'investing_cash' => $investingCash,
                'financing_cash' => $financingCash,
                'net_cash_change' => $netCashChange,
                'cash_opening' => $cashOpening,
                'cash_closing' => $cashClosing,
                'net_cash_movement' => $cashMovement,
                'plug_difference' => $plugDifference,
            ],
            'checks' => [
                'plugs_to_gl_cash' => abs($plugDifference) < 0.01,
                'closing_matches' => abs($calculatedClosing - $cashClosing) < 0.01,
            ],
        ];
    }

    /**
     * Receivable Aging — customer balances by age bucket (as of date).
     * Uses the mv_ar_aging materialized view for "as of today";
     * falls back to direct query for historical as-of dates.
     */
    public function receivableAging(Carbon $asOfDate, ?int $branchId = null): array
    {
        $isToday = $asOfDate->isToday();

        if ($isToday) {
            // Use the materialized view (fast).
            $query = DB::table('mv_ar_aging')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderBy('total_receivable', 'desc');
            $rows = $query->get();
        } else {
            // Direct query for historical as-of date.
            $sql = <<<SQL
SELECT
    c.id AS customer_id, c.customer_code, c.customer_name, c.mobile,
    cl.branch_id, COALESCE(b.branch_name, '—') AS branch_name,
    SUM(CASE WHEN (?::date - cl.transaction_date) <= 30 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (?::date - cl.transaction_date) BETWEEN 31 AND 60 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (?::date - cl.transaction_date) BETWEEN 61 AND 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (?::date - cl.transaction_date) > 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
    SUM(cl.debit - cl.credit) AS total_receivable
FROM customer_ledger cl
INNER JOIN customers c ON c.id = cl.customer_id
LEFT JOIN branches b ON b.id = cl.branch_id
WHERE cl.transaction_date <= ? AND COALESCE(cl.is_reversed, false) = false
SQL;
            $params = [$asOfDate, $asOfDate, $asOfDate, $asOfDate, $asOfDate];
            if ($branchId) {
                $sql .= ' AND (cl.branch_id = ? OR cl.branch_id IS NULL)';
                $params[] = $branchId;
            }
            $sql .= ' GROUP BY c.id, c.customer_code, c.customer_name, c.mobile, cl.branch_id, b.branch_name';
            $sql .= ' HAVING SUM(cl.debit - cl.credit) > 0.005 ORDER BY total_receivable DESC';
            $rows = collect(DB::select($sql, $params));
        }

        // GL AR control account balance (for reconciliation footnote).
        $glArBalance = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'ar' AND je.entry_date <= ?
SQL, [$asOfDate])->balance ?? 0;

        return [
            'meta' => [
                'title' => 'Receivable Aging',
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'source' => $isToday ? 'materialized_view' : 'direct_query',
            ],
            'data' => $rows,
            'totals' => [
                'bucket_0_30' => $rows->sum('bucket_0_30'),
                'bucket_31_60' => $rows->sum('bucket_31_60'),
                'bucket_61_90' => $rows->sum('bucket_61_90'),
                'bucket_90_plus' => $rows->sum('bucket_90_plus'),
                'total_receivable' => $rows->sum('total_receivable'),
                'gl_ar_control' => $glArBalance,
            ],
            'checks' => [
                'matches_gl' => abs($rows->sum('total_receivable') - $glArBalance) < 0.01,
            ],
        ];
    }

    /**
     * Payable Aging — supplier balances by age bucket (as of date).
     */
    public function payableAging(Carbon $asOfDate, ?int $branchId = null): array
    {
        $isToday = $asOfDate->isToday();

        if ($isToday) {
            $rows = DB::table('mv_ap_aging')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderBy('total_payable', 'desc')
                ->get();
        } else {
            $sql = <<<SQL
SELECT
    s.id AS supplier_id, s.supplier_code, s.supplier_name, s.mobile,
    sl.branch_id, COALESCE(b.branch_name, '—') AS branch_name,
    SUM(CASE WHEN (?::date - sl.transaction_date) <= 30 THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (?::date - sl.transaction_date) BETWEEN 31 AND 60 THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (?::date - sl.transaction_date) BETWEEN 61 AND 90 THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (?::date - sl.transaction_date) > 90 THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_90_plus,
    SUM(sl.credit - sl.debit) AS total_payable
FROM supplier_ledger sl
INNER JOIN suppliers s ON s.id = sl.supplier_id
LEFT JOIN branches b ON b.id = sl.branch_id
WHERE sl.transaction_date <= ? AND COALESCE(sl.is_reversed, false) = false
SQL;
            $params = [$asOfDate, $asOfDate, $asOfDate, $asOfDate, $asOfDate];
            if ($branchId) {
                $sql .= ' AND (sl.branch_id = ? OR sl.branch_id IS NULL)';
                $params[] = $branchId;
            }
            $sql .= ' GROUP BY s.id, s.supplier_code, s.supplier_name, s.mobile, sl.branch_id, b.branch_name';
            $sql .= ' HAVING SUM(sl.credit - sl.debit) > 0.005 ORDER BY total_payable DESC';
            $rows = collect(DB::select($sql, $params));
        }

        $glApBalance = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
FROM ledgers l
JOIN journal_lines jl ON jl.ledger_id = l.id
JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
WHERE l.ledger_nature = 'ap' AND je.entry_date <= ?
SQL, [$asOfDate])->balance ?? 0;

        return [
            'meta' => [
                'title' => 'Payable Aging',
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'source' => $isToday ? 'materialized_view' : 'direct_query',
            ],
            'data' => $rows,
            'totals' => [
                'bucket_0_30' => $rows->sum('bucket_0_30'),
                'bucket_31_60' => $rows->sum('bucket_31_60'),
                'bucket_61_90' => $rows->sum('bucket_61_90'),
                'bucket_90_plus' => $rows->sum('bucket_90_plus'),
                'total_payable' => $rows->sum('total_payable'),
                'gl_ap_control' => $glApBalance,
            ],
            'checks' => [
                'matches_gl' => abs($rows->sum('total_payable') - $glApBalance) < 0.01,
            ],
        ];
    }

    /**
     * General Ledger — account activity with running balance.
     */
    public function generalLedger(Carbon $fromDate, Carbon $toDate, ?int $ledgerId = null, ?int $branchId = null): array
    {
        $sql = <<<SQL
SELECT
    je.id AS journal_entry_id, je.entry_no, je.entry_date,
    je.reference_type, je.reference_id, je.description,
    je.branch_id, COALESCE(b.branch_name, '—') AS branch_name,
    je.is_reversed,
    jl.id AS journal_line_id, jl.ledger_id, l.ledger_code, l.ledger_name,
    jl.debit, jl.credit, jl.entity_type, jl.entity_id, jl.memo
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id
JOIN ledgers l ON l.id = jl.ledger_id
LEFT JOIN branches b ON b.id = je.branch_id
WHERE je.entry_date BETWEEN ? AND ?
    AND COALESCE(je.is_reversed, false) = false
SQL;
        $params = [$fromDate, $toDate];
        if ($ledgerId) {
            $sql .= ' AND jl.ledger_id = ?';
            $params[] = $ledgerId;
        }
        if ($branchId) {
            $sql .= ' AND je.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY je.entry_date, je.entry_no, jl.id';

        $rows = collect(DB::select($sql, $params));

        // Compute running balance per ledger.
        $running = [];
        $rows = $rows->map(function ($r) use (&$running) {
            $key = $r->ledger_id;
            $running[$key] = ($running[$key] ?? 0) + $r->debit - $r->credit;
            $r->running_balance = $running[$key];
            return $r;
        });

        return [
            'meta' => [
                'title' => 'General Ledger',
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
                'ledger_id' => $ledgerId,
                'branch_id' => $branchId,
            ],
            'data' => $rows,
            'totals' => [
                'total_debit' => $rows->sum('debit'),
                'total_credit' => $rows->sum('credit'),
            ],
            'checks' => [
                'balanced' => abs($rows->sum('debit') - $rows->sum('credit')) < 0.01,
            ],
        ];
    }

    /**
     * Journal Entries — searchable list of all journal entries.
     */
    public function journalEntries(Carbon $fromDate, Carbon $toDate, ?int $branchId = null, ?string $referenceType = null): array
    {
        $query = DB::table('mv_journal_entry_summary as j')
            ->whereBetween('j.entry_date', [$fromDate, $toDate])
            ->where('j.is_reversed', false)
            ->when($branchId, fn($q) => $q->where('j.branch_id', $branchId))
            ->when($referenceType, fn($q) => $q->where('j.reference_type', $referenceType))
            ->orderBy('j.entry_date', 'desc')
            ->orderBy('j.entry_no', 'desc');

        $rows = $query->paginate(50);

        return [
            'meta' => [
                'title' => 'Journal Entries',
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'reference_type' => $referenceType,
            ],
            'data' => $rows,
            'source' => 'materialized_view',
        ];
    }

    /**
     * Daily Cash Book — receipts vs payments in the period.
     */
    public function dailyCashBook(Carbon $fromDate, Carbon $toDate, ?int $branchId = null): array
    {
        $sql = <<<SQL
SELECT
    je.entry_date,
    je.entry_no, je.description, je.reference_type,
    l.ledger_name, l.ledger_nature,
    jl.debit, jl.credit,
    je.branch_id, b.branch_name
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id
JOIN ledgers l ON l.id = jl.ledger_id
LEFT JOIN branches b ON b.id = je.branch_id
WHERE je.entry_date BETWEEN ? AND ?
    AND COALESCE(je.is_reversed, false) = false
    AND l.ledger_nature = 'cash_bank'
SQL;
        $params = [$fromDate, $toDate];
        if ($branchId) {
            $sql .= ' AND je.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY je.entry_date, je.entry_no';

        $rows = collect(DB::select($sql, $params));
        $receipts = $rows->where('credit', '>', 0)->values();
        $payments = $rows->where('debit', '>', 0)->values();

        return [
            'meta' => [
                'title' => 'Day Book (Cash & Bank)',
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'receipts' => $receipts,
            'payments' => $payments,
            'totals' => [
                'total_receipts' => $receipts->sum('credit'),
                'total_payments' => $payments->sum('debit'),
                'net' => $receipts->sum('credit') - $payments->sum('debit'),
            ],
        ];
    }

    /**
     * Stock Valuation — current on-hand stock with value.
     * Uses mv_stock_valuation materialized view.
     */
    public function stockValuation(?int $branchId = null, ?int $warehouseId = null): array
    {
        $rows = DB::table('mv_stock_valuation')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('branch_name')
            ->orderBy('warehouse_name')
            ->orderBy('product_name')
            ->get();

        return [
            'meta' => ['title' => 'Stock Valuation'],
            'data' => $rows,
            'totals' => [
                'total_qty' => $rows->sum('on_hand_qty'),
                'total_value' => $rows->sum('stock_value'),
            ],
        ];
    }

    /**
     * Branch Intercompany — Due-from/Due-to balances per branch pair.
     * Uses mv_branch_intercompany materialized view.
     */
    public function branchIntercompany(?int $branchId = null): array
    {
        $rows = DB::table('mv_branch_intercompany')
            ->when($branchId, fn($q) => $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId))
            ->orderBy('from_branch_name')
            ->orderBy('to_branch_name')
            ->get();

        return [
            'meta' => ['title' => 'Branch Intercompany Ledger'],
            'data' => $rows,
            'totals' => [
                'total_debit' => $rows->sum('total_debit'),
                'total_credit' => $rows->sum('total_credit'),
                'net_balance' => $rows->sum('net_balance'),
                'total_outstanding' => $rows->sum('outstanding_amount'),
            ],
            'checks' => [
                'zero_sum' => abs($rows->sum('net_balance')) < 0.01, // Intercompany should net to zero across all branches
            ],
        ];
    }

    /**
     * Refresh all materialized views (called after journal postings + by scheduler).
     */
    public function refreshMaterializedViews(): void
    {
        DB::statement('SELECT refresh_all_report_views()');
    }
}
