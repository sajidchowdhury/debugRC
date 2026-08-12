<?php

namespace App\Helpers;

/**
 * Reports Catalog — Phase 5 + Phase 6 + Phase 1E.
 * Mirrors legacy app/helpers/ReportsCatalog.php.
 *
 * Metadata registry of all 32 reports across 6 categories.
 * Used by the reports index page (hub) + navigation.
 *
 * G-048 (CRITICAL, REPORTS-2): previously the docblock claimed "18 reports
 * across 5 categories" while categories() returned 25, and 7 report routes
 * existed as unreachable orphans (4 CTE reports, ABC, budget variance,
 * reconciliation hub). All 7 are now registered below — the catalog is the
 * single source of truth for report discovery.
 * G-050 (CRITICAL, REPORTS-2): the `branch_demand_weekly` entry previously
 * pointed at `admin.reports.branchDemandWeekly` — a 5-column stub. It now
 * points at `admin.branch-demands.weekly-report`, the real 23-column
 * BranchDemandWeeklyReportService report. The stub controller method is kept
 * as a redirect for backward compatibility with bookmarks.
 */
class ReportsCatalog
{
    /**
     * Get all reports grouped by category.
     *
     * @return array<int, array{ id: string, label: string, icon: string, accent: string, tagline: string, reports: array<int, array> }>
     */
    public static function categories(): array
    {
        return [
            [
                'id' => 'sales',
                'label' => 'Sales & Revenue',
                'icon' => 'fa-chart-line',
                'accent' => 'sales',
                'tagline' => 'Track invoices, collections, and customer momentum',
                'reports' => [
                    self::r('revenue_overview', 'Date Wise Sales Report', 'Date-wise invoice register with customer & salesman filters', 'admin.reports.revenueOverview', 'fa-file-invoice-dollar', ['invoice', 'export'], 30, true),
                    self::r('sales_funnel', 'Dispatch Report', 'Dispatch pipeline stages, conversion rates & delivery status', 'admin.reports.salesFunnel', 'fa-truck', ['dispatch', 'pipeline', 'delivery'], 30, true),
                    self::r('gross_margin', 'Invoice Details Report', 'Invoice-wise product details with margin & COGS breakdown', 'admin.reports.grossMarginCte', 'fa-list-alt', ['invoice', 'details', 'margin', 'export'], 30, true),
                    self::r('customer_performance', 'Customer Performance', '360° customer value, loyalty, CLV, churn risk', 'admin.reports.customerPerformance', 'fa-users', ['customer', 'clv', 'churn'], 30, true),
                ],
            ],
            [
                'id' => 'purchase',
                'label' => 'Purchase & Payables',
                'icon' => 'fa-truck-loading',
                'accent' => 'purchase',
                'tagline' => 'Supplier spend, GRN history, and what you still owe',
                'reports' => [
                    self::r('supplier_wise_purchase', 'Supplier-wise Purchase', 'Spend profile per supplier', 'admin.reports.supplierWisePurchase', 'fa-industry', ['supplier'], 30),
                    self::r('payable_aging', 'Supplier Due Report', 'Supplier outstanding balances by age bucket', 'admin.reports.payableAging', 'fa-clock', ['supplier', 'due', 'aging'], 0, true, 'as_of'),
                    self::r('receivable_aging', 'Customer Due Report', 'Customer outstanding balances by age bucket', 'admin.reports.receivableAging', 'fa-clock', ['customer', 'due', 'aging'], 0, true, 'as_of'),
                ],
            ],
            [
                'id' => 'inventory',
                'label' => 'Inventory & Stock',
                'icon' => 'fa-warehouse',
                'accent' => 'inventory',
                'tagline' => 'On-hand truth, valuation, and movement trails',
                'reports' => [
                    self::r('product_stock_analysis', 'Stock Report', 'On-hand stock valuation with branch & warehouse breakdown', 'admin.reports.productStockAnalysis', 'fa-boxes-stacked', ['stock', 'valuation'], 30),
                    self::r('product_movement', 'Product Movement Report', 'Chronological stock ledger for one or all SKUs', 'admin.reports.productMovement', 'fa-route', ['movement'], 30),
                    self::r('warehouse_transfer_summary', 'Warehouse Transfer Summary', 'Period aggregates, top products, warehouse pairs, and trends', 'admin.warehouse-transfers.summary', 'fa-right-left', ['transfer', 'warehouse', 'export'], 30, false, 'range'),
                    self::r('abc_report', 'ABC Classification', 'Pareto ranking of SKUs by value contribution (mv_product_abc_classification)', 'admin.stock-take.abc-report', 'fa-list-ol', ['abc', 'classification', 'inventory'], 0, false, 'range'),
                ],
            ],
            [
                'id' => 'finance',
                'label' => 'Finance & Control',
                'icon' => 'fa-scale-balanced',
                'accent' => 'finance',
                'tagline' => 'GL integrity, cash day book, and branch ledgers',
                'reports' => [
                    self::r('trial_balance', 'Trial Balance', 'Opening, period & closing — verify Dr = Cr', 'admin.reports.trialBalance', 'fa-scale-balanced', ['gl', 'export'], 0, true, 'range'),
                    self::r('profit_and_loss', 'Profit & Loss', 'Income − expense by ledger nature with optional compare', 'admin.reports.profitAndLoss', 'fa-chart-pie', ['gl', 'export'], 30, true, 'range'),
                    self::r('cash_flow', 'Cash Flow Statement', 'Indirect method from GL with bank register reconciliation', 'admin.reports.cashFlow', 'fa-water', ['gl', 'cash', 'export'], 30, false, 'range'),
                    self::r('balance_sheet', 'Balance Sheet', 'Assets = Liabilities + Equity as of date', 'admin.reports.balanceSheet', 'fa-building-columns', ['gl', 'export'], 0, true, 'as_of'),
                    self::r('general_ledger', 'General Ledger', 'Account activity with running balance & source links', 'admin.reports.generalLedger', 'fa-book-open', ['gl', 'export'], 30, true, 'range'),
                    self::r('journal_entries', 'Journal Entries', 'Search all JEs — filter by type, branch, user', 'admin.reports.journalEntries', 'fa-file-invoice', ['gl', 'export'], 30, true, 'range'),
                    self::r('daily_cash_book', 'Day Book (Cash & Bank)', 'Split view: receipts vs payments in the period', 'admin.reports.dailyCashBook', 'fa-book-open', ['cash'], 7, true, 'range'),
                    self::r('branch_intercompany', 'Branch Intercompany Ledger', 'Due between branches — settlement trail', 'admin.reports.branchIntercompany', 'fa-arrows-left-right', ['branch'], 30, false, 'range'),
                    self::r('branch_wise_ledger', 'Branch-wise Ledger', 'Per-branch GL activity summary', 'admin.reports.branchWiseLedger', 'fa-sitemap', ['branch', 'gl'], 30, false, 'range'),
                    self::r('budgets_variance', 'Budget Variance', 'Budget vs actual by ledger / cost center with drill-down', 'admin.budgets.variance', 'fa-chart-column', ['budget', 'variance', 'finance'], 30, false, 'range'),
                ],
            ],
            [
                'id' => 'operations',
                'label' => 'Operations',
                'icon' => 'fa-clipboard-check',
                'accent' => 'ops',
                'tagline' => 'Control reports outside the standard register',
                'reports' => [
                    self::r('sales_audit_checklist', 'Sales Audit Checklist', 'Invoice, payment, and dispatch control checks', 'admin.reports.salesAuditChecklist', 'fa-clipboard-check', ['control', 'audit'], 0, false, 'range'),
                    self::r('purchase_audit', 'Purchase Audit Checklist', 'GRN, supplier ledger, and purchase posting integrity', 'admin.purchase-audit.checklist', 'fa-truck', ['control', 'audit'], 0, false, 'range'),
                    self::r('stocktake_variance', 'Stock Take Variance', 'Line-level count vs system by session with GL drill-down', 'admin.reports.stocktakeVariance', 'fa-table', ['detail', 'variance'], 30, false, 'range'),
                    self::r('stocktake_weekly', 'Stock Take — Weekly Control', 'Posted sessions, gain/loss totals & top SKU variances', 'admin.reports.stocktakeWeekly', 'fa-chart-line', ['control', 'variance'], 7, false, 'range'),
                    self::r('branch_demand_weekly', 'Branch Demand & Approval Report', 'Branch-wise demand, approval status, settlement & floor stock', 'admin.branch-demands.weekly-report', 'fa-share-nodes', ['branch', 'demand', 'approval'], 7, false, 'range'),
                    self::r('damage_report', 'Damage Report', 'Damage cost by month, category, warehouse, employee & top products', 'admin.reports.damageReport', 'fa-triangle-exclamation', ['damage', 'loss', 'export'], 30, true, 'range'),
                    self::r('reconciliation_index', 'Reconciliation Hub', 'AR/AP/employee-payable 3-way subledger reconciliation vs GL control accounts', 'admin.reconciliation.index', 'fa-scale-balanced', ['reconciliation', 'gl', 'control'], 0, false, 'range'),
                ],
            ],
            [
                'id' => 'cte',
                'label' => 'Phase 1E — CTE Reports',
                'icon' => 'fa-bolt',
                'accent' => 'finance',
                'tagline' => 'Single-query PostgreSQL CTE aggregations — no PHP-side loops',
                'reports' => [
                    self::r('today_summary_cte', 'Today Summary (CTE)', 'All dashboard KPIs in one query — pending godown/challan counts', 'admin.reports.todaySummaryCte', 'fa-bolt', ['cte', 'dashboard'], 0, false, 'as_of'),
                    self::r('ar_aging_cte', 'Receivable Aging (CTE)', 'Customer due balances by age bucket — single SQL window query', 'admin.reports.arAgingCte', 'fa-clock-rotate-left', ['cte', 'aging', 'finance'], 0, false, 'as_of'),
                    self::r('general_ledger_cte', 'General Ledger (CTE)', 'Account activity with SQL window-function running balance', 'admin.reports.generalLedgerCte', 'fa-book-open-reader', ['cte', 'gl'], 30, false, 'range'),
                    self::r('gross_margin_cte', 'Gross Margin (CTE)', 'True per-product margin via challan-item → stock-transaction COGS join', 'admin.reports.grossMarginCte', 'fa-percent', ['cte', 'margin', 'cogs'], 30, false, 'range'),
                ],
            ],
        ];
    }

    /**
     * Get all reports as a flat array keyed by id.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        $flat = [];
        foreach (self::categories() as $cat) {
            foreach ($cat['reports'] as $r) {
                $r['category_id'] = $cat['id'];
                $r['category_label'] = $cat['label'];
                $r['category_icon'] = $cat['icon'];
                $r['category_accent'] = $cat['accent'];
                $flat[$r['id']] = $r;
            }
        }
        return $flat;
    }

    /**
     * Get a single report by id.
     */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Get featured reports (for the dashboard quick-access).
     *
     * @return array<int, array>
     */
    public static function featured(): array
    {
        return array_values(array_filter(self::all(), fn($r) => !empty($r['featured'])));
    }

    /**
     * Build the default run URL for a report with the given time lens.
     *
     * @param array $report
     * @param string $lens 'today' | 'mtd' | 'last7' | 'default'
     */
    public static function buildRunParams(array $report, string $lens = 'mtd'): array
    {
        $today = now()->format('Y-m-d');
        $filterType = $report['filter_type'] ?? 'range';

        if ($filterType === 'as_of') {
            return ['as_of_date' => $today, 'search' => '1'];
        }

        $days = (int) ($report['preset_days'] ?? 30);
        return match ($lens) {
            'today' => ['from_date' => $today, 'to_date' => $today, 'search' => '1'],
            'mtd' => ['from_date' => now()->startOfMonth()->format('Y-m-d'), 'to_date' => $today, 'search' => '1'],
            'last7' => ['from_date' => now()->subDays(6)->format('Y-m-d'), 'to_date' => $today, 'search' => '1'],
            default => [
                'from_date' => now()->subDays(max(1, $days))->format('Y-m-d'),
                'to_date' => $today,
                'search' => '1',
            ],
        };
    }

    /**
     * Define a report entry.
     */
    private static function r(
        string $id,
        string $title,
        string $tagline,
        string $route,
        string $icon,
        array $tags,
        int $presetDays,
        bool $featured = false,
        string $filterType = 'range',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'tagline' => $tagline,
            'route' => $route,
            'icon' => $icon,
            'tags' => $tags,
            'preset_days' => $presetDays,
            'featured' => $featured,
            'filter_type' => $filterType,
        ];
    }
}
