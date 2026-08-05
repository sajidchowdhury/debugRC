<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\ReportsCatalog;
use App\Http\Requests\Reports\ReportAsOfRequest;
use App\Http\Requests\Reports\ReportRangeRequest;
use App\Http\Requests\Reports\StocktakeVarianceRequest;
use App\Services\Reports\ReportService;
use App\Services\Reports\CteReportService;
use App\Services\Stock\StockTakeVarianceReport;
use App\Services\Stock\StockTakeWeeklyReport;
use App\Services\Reports\DamageReportService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Report Controller — Phase 5.
 *
 * Serves the reports hub + 23 financial/operational reports.
 * Uses ReportService for query execution (which uses materialized views).
 *
 * Phase 6 (Stock Take plan): real variance + weekly reports with CSV
 * export and per-line GL drill-down (replaces the previous stubs).
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private CteReportService $cteReportService,
        private StockTakeVarianceReport $stocktakeVarianceReport,
        private StockTakeWeeklyReport $stocktakeWeeklyReport,
        private DamageReportService $damageReportService,
        private JournalPostingService $journalPosting,
    ) {}

    /**
     * Reports hub — catalog of all 23 reports grouped by category.
     */
    public function index()
    {
        return view('admin.reports.index', [
            'title' => 'Reports — Remote Center ERP',
            'categories' => ReportsCatalog::categories(),
            'featured' => ReportsCatalog::featured(),
        ]);
    }

    /**
     * Trial Balance.
     */
    public function trialBalance(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $accountType = $request->input('account_type');
        $includeZero = $request->boolean('include_zero');
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->trialBalance(
            $data['from'], $data['to'], $accountType, $includeZero, $branchId
        );

        // CSV export
        if ($request->input('export') === 'csv') {
            return $this->exportTrialBalanceCsv($report);
        }

        // Branch list for filter dropdown
        $branches = DB::table('branches')->where('is_active', true)->orderBy('branch_name')->get(['id', 'branch_name']);

        return view('admin.reports.trial_balance', array_merge($report, [
            'accountTypes' => ['Asset', 'Liability', 'Equity', 'Income', 'Expense'],
            'branches'     => $branches,
        ]));
    }

    /**
     * Export Trial Balance as CSV download.
     */
    private function exportTrialBalanceCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'Trial_Balance_' . $report['meta']['from_date'] . '_to_' . $report['meta']['to_date'];

        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
        ];

        return response()->stream(function () use ($report) {
            $output = fopen('php://output', 'w');

            // BOM for Excel UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header
            fputcsv($output, ['Trial Balance Report']);
            fputcsv($output, ['Period: ' . $report['meta']['from_date'] . ' to ' . $report['meta']['to_date']]);
            if ($report['meta']['branch_id']) {
                fputcsv($output, ['Branch ID: ' . $report['meta']['branch_id']]);
            }
            fputcsv($output, ['Generated: ' . now()->format('Y-m-d H:i:s')]);
            fputcsv($output, []);

            // Column headers
            fputcsv($output, [
                'Code', 'Ledger Name', 'Type', 'Nature', 'Normal Balance',
                'Opening Dr', 'Opening Cr', 'Opening Balance', 'Opening Side',
                'Period Dr', 'Period Cr',
                'Closing Dr', 'Closing Cr', 'Closing Balance', 'Closing Side',
            ]);

            // Data rows
            foreach ($report['data'] as $row) {
                fputcsv($output, [
                    $row->ledger_code,
                    $row->ledger_name,
                    $row->account_type,
                    $row->ledger_nature ?? '',
                    $row->normal_balance ?? 'debit',
                    number_format($row->opening_debit, 2, '.', ''),
                    number_format($row->opening_credit, 2, '.', ''),
                    number_format($row->opening_balance, 2, '.', ''),
                    $row->opening_side,
                    number_format($row->period_debit, 2, '.', ''),
                    number_format($row->period_credit, 2, '.', ''),
                    number_format($row->closing_debit, 2, '.', ''),
                    number_format($row->closing_credit, 2, '.', ''),
                    number_format($row->closing_balance, 2, '.', ''),
                    $row->closing_side,
                ]);
            }

            // Totals
            fputcsv($output, []);
            $t = $report['totals'];
            fputcsv($output, [
                'GRAND TOTAL', '', '', '', '',
                number_format($t['opening_debit'], 2, '.', ''),
                number_format($t['opening_credit'], 2, '.', ''),
                '', '',
                number_format($t['period_debit'], 2, '.', ''),
                number_format($t['period_credit'], 2, '.', ''),
                number_format($t['closing_debit'], 2, '.', ''),
                number_format($t['closing_credit'], 2, '.', ''),
                '', '',
            ]);

            // Integrity checks
            fputcsv($output, []);
            fputcsv($output, ['INTEGRITY CHECKS']);
            $c = $report['checks'];
            fputcsv($output, ['Opening balanced', $c['opening_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['opening_diff']]);
            fputcsv($output, ['Period balanced', $c['period_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['period_diff']]);
            fputcsv($output, ['Closing balanced', $c['closing_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['closing_diff']]);
            fputcsv($output, ['All accounts balance', $c['all_accounts_balance'] ? 'YES' : 'NO', 'Fails: ' . $c['balance_check_fails']]);
            fputcsv($output, ['Orphaned journal lines', (string) $c['orphaned_journal_lines']]);

            if (!empty($c['subledger_reconciliation'])) {
                fputcsv($output, []);
                fputcsv($output, ['SUB-LEDGER RECONCILIATION']);
                foreach ($c['subledger_reconciliation'] as $key => $sl) {
                    fputcsv($output, [
                        $sl['label'],
                        $sl['reconciled'] ? 'RECONCILED' : 'OUT OF BALANCE',
                        'GL: ' . number_format($sl['gl_balance'], 2),
                        'Sub: ' . number_format($sl['sub_balance'], 2),
                        'Diff: ' . number_format($sl['difference'], 2),
                    ]);
                }
            }

            fclose($output);
        }, 200, $headers);
    }

    /**
     * Profit & Loss.
     */
    public function profitAndLoss(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->profitAndLoss($data['from'], $data['to'], $branchId);

        return view('admin.reports.profit_and_loss', $report);
    }

    /**
     * Balance Sheet.
     */
    public function balanceSheet(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $includeZero = $request->boolean('include_zero');

        $report = $this->reportService->balanceSheet($asOf, $branchId, $includeZero);

        return view('admin.reports.balance_sheet', $report);
    }

    /**
     * Cash Flow Statement (Indirect Method — Xero-style).
     */
    public function cashFlow(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->cashFlow($data['from'], $data['to'], $branchId);

        // CSV export
        if ($request->query('export') === 'csv') {
            return $this->exportCashFlowCsv($report);
        }

        return view('admin.reports.cash_flow', $report);
    }

    /**
     * Export Cash Flow Statement as CSV download.
     */
    private function exportCashFlowCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = 'cash_flow_' . $report['meta']['from_date'] . '_to_' . $report['meta']['to_date'] . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->streamDownload(function () use ($report) {
            $fh = fopen('php://output', 'w');
            // BOM for Excel
            fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $op = $report['sections']['operating'];
            $inv = $report['sections']['investing'];
            $fin = $report['sections']['financing'];

            // Title
            fputcsv($fh, ['Cash Flow Statement (Indirect Method)']);
            fputcsv($fh, ['Period', $report['meta']['from_date'] . ' to ' . $report['meta']['to_date']]);
            fputcsv($fh, []);

            // Operating Activities
            fputcsv($fh, ['CASH FLOW FROM OPERATING ACTIVITIES', 'Amount (Tk)']);
            fputcsv($fh, ['Net Profit (from P&L)', number_format($op['net_profit'], 2)]);
            fputcsv($fh, ['(+) Depreciation & Amortization', number_format($op['depreciation'], 2)]);
            fputcsv($fh, ['Changes in Working Capital:']);
            foreach ($op['wc_adjustments'] as $wc) {
                $direction = $wc->change >= 0 ? 'Increase' : 'Decrease';
                fputcsv($fh, ['    ' . $direction . ' in ' . $wc->label, number_format($wc->adjustment, 2)]);
            }
            fputcsv($fh, ['Total Working Capital Adjustments', number_format($op['wc_adjustment_total'], 2)]);
            fputcsv($fh, ['Net Cash from Operating Activities', number_format($op['net'], 2)]);
            fputcsv($fh, []);

            // Investing Activities
            fputcsv($fh, ['CASH FLOW FROM INVESTING ACTIVITIES', 'Amount (Tk)']);
            foreach ($inv['rows'] as $row) {
                $label = $row->net_amount < 0 ? 'Purchase of ' . $row->ledger_name : 'Sale of ' . $row->ledger_name;
                fputcsv($fh, ['    ' . $label, number_format($row->net_amount, 2)]);
            }
            if ($inv['rows']->isEmpty()) {
                fputcsv($fh, ['    (No investing activity in this period)', '0.00']);
            }
            fputcsv($fh, ['Net Cash from Investing Activities', number_format($inv['net'], 2)]);
            fputcsv($fh, []);

            // Financing Activities
            fputcsv($fh, ['CASH FLOW FROM FINANCING ACTIVITIES', 'Amount (Tk)']);
            foreach ($fin['rows'] as $row) {
                $label = $row->net_amount > 0 ? 'Proceeds from ' . $row->ledger_name : 'Repayment of ' . $row->ledger_name;
                fputcsv($fh, ['    ' . $label, number_format($row->net_amount, 2)]);
            }
            if ($fin['rows']->isEmpty()) {
                fputcsv($fh, ['    (No financing activity in this period)', '0.00']);
            }
            fputcsv($fh, ['Net Cash from Financing Activities', number_format($fin['net'], 2)]);
            fputcsv($fh, []);

            // Net Cash Movement
            fputcsv($fh, ['NET CASH MOVEMENT', 'Amount (Tk)']);
            fputcsv($fh, ['Opening Cash Balance', number_format($report['totals']['cash_opening'], 2)]);
            fputcsv($fh, ['Net Increase / (Decrease) in Cash', number_format($report['totals']['net_cash_change'], 2)]);
            fputcsv($fh, ['Closing Cash Balance', number_format($report['totals']['cash_closing'], 2)]);
            fputcsv($fh, []);

            // Integrity check
            fputcsv($fh, ['INTEGRITY CHECK']);
            fputcsv($fh, ['GL Cash Movement', number_format($report['totals']['net_cash_movement'], 2)]);
            fputcsv($fh, ['Plug Difference', number_format($report['totals']['plug_difference'], 2)]);
            fputcsv($fh, ['Reconciled', $report['checks']['plugs_to_gl_cash'] ? 'YES' : 'NO']);

            fclose($fh);
        }, $filename, $headers);
    }

    /**
     * General Ledger.
     */
    public function generalLedger(Request $request)
    {
        $data = $this->parseDateRange($request);
        $ledgerId = $request->input('ledger_id') ? (int) $request->input('ledger_id') : null;
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->generalLedger($data['from'], $data['to'], $ledgerId, $branchId);

        $ledgers = \App\Models\Ledger::active()->orderBy('ledger_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.general_ledger', array_merge($report, [
            'ledgers' => $ledgers,
            'branches' => $branches,
        ]));
    }

    /**
     * Journal Entries.
     */
    public function journalEntries(Request $request)
    {
        $data = $this->parseDateRange($request);
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);
        $referenceType = $request->input('reference_type');

        $report = $this->reportService->journalEntries($data['from'], $data['to'], $branchId, $referenceType);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.journal_entries', array_merge($report, [
            'branches' => $branches,
            'referenceTypes' => [
                'sales_invoice' => 'Sales Invoice',
                'sales_challan' => 'Sales Challan',
                'sales_return' => 'Sales Return',
                'purchase_receive' => 'Purchase Receive',
                'purchase_return' => 'Purchase Return',
                'customer_payment' => 'Customer Payment',
                'supplier_payment' => 'Supplier Payment',
                'stock_adjustment' => 'Stock Adjustment',
                'warehouse_transfer' => 'Warehouse Transfer',
                'damage' => 'Damage',
                'manual_journal' => 'Manual Journal',
                'other_income' => 'Other Income',
                'other_expense' => 'Other Expense',
                'money_transfer' => 'Money Transfer',
                'employee_transaction' => 'Employee Transaction',
            ],
        ]));
    }

    /**
     * Daily Cash Book.
     */
    public function dailyCashBook(Request $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->dailyCashBook($data['from'], $data['to'], $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.daily_cash_book', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Receivable Aging.
     */
    public function receivableAging(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->receivableAging($asOf, $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.receivable_aging', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Payable Aging.
     */
    public function payableAging(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->payableAging($asOf, $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.payable_aging', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Branch Intercompany Ledger.
     */
    public function branchIntercompany(Request $request)
    {
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);
        $report = $this->reportService->branchIntercompany($branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.branch_intercompany', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Branch-wise Ledger (per-branch GL activity summary).
     */
    public function branchWiseLedger(Request $request)
    {
        $data = $this->parseDateRange($request);

        $sql = <<<SQL
SELECT
    je.branch_id, b.branch_name,
    l.account_type, l.ledger_nature,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
JOIN ledgers l ON l.id = jl.ledger_id
LEFT JOIN branches b ON b.id = je.branch_id
WHERE je.entry_date BETWEEN ? AND ?
    AND COALESCE(je.is_reversed, false) = false
    AND je.branch_id IS NOT NULL
GROUP BY je.branch_id, b.branch_name, l.account_type, l.ledger_nature
ORDER BY b.branch_name, l.account_type
SQL;
        $rows = collect(\Illuminate\Support\Facades\DB::select($sql, [$data['from'], $data['to']]));

        // Group by branch.
        $byBranch = $rows->groupBy('branch_name');

        return view('admin.reports.branch_wise_ledger', [
            'meta' => [
                'title' => 'Branch-wise Ledger',
                'from_date' => $data['from']->format('Y-m-d'),
                'to_date' => $data['to']->format('Y-m-d'),
            ],
            'branches' => $byBranch,
            'totals' => [
                'total_debit' => $rows->sum('total_debit'),
                'total_credit' => $rows->sum('total_credit'),
            ],
        ]);
    }

    // ============================================================
    // PLACEHOLDER REPORTS — simple "coming in Phase 5.x" views for now.
    // These will be fully implemented in subsequent sub-phases.
    // ============================================================

    public function revenueOverview(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'si.branch_id')
            ->leftJoin('employees as e', 'e.id', '=', 'si.salesman_id')
            ->whereBetween('si.invoice_date', [$data['from'], $data['to']])
            ->whereNull('si.deleted_at')
            ->select(
                'si.id', 'si.invoice_code', 'si.invoice_date', 'si.status',
                'si.total_amount', 'si.paid_amount', 'si.due_amount',
                'c.customer_name', 'b.branch_name', 'e.name as salesman_name'
            )
            ->orderBy('si.invoice_date', 'desc')
            ->paginate(50);

        return view('admin.reports.revenue_overview', [
            'meta' => ['title' => 'Revenue Overview', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => $rows,
        ]);
    }

    /**
     * @deprecated Use grossMarginCte() instead. The non-CTE version used an
     *             inaccurate single-column COGS (sales_challans.issue_cost — a
     *             parent-challan summary column, not a per-item COGS). The CTE
     *             version (rcerp_gross_margin_cte) joins
     *             invoice_items → sales_challan_items → stock_transactions
     *             for true per-product COGS. This method is retained as a 301
     *             redirect so existing bookmarks + URL query params (from_date,
     *             to_date, branch_id) survive the deprecation hop.
     *
     *             G-143/G-146 (REPORTS-AUDIT-2): catalog entry `gross_margin`
     *             now points at admin.reports.grossMarginCte; the
     *             admin.reports.grossMargin route is preserved as a
     *             redirect-only route for backward compatibility.
     *
     * @see \App\Http\Controllers\Admin\ReportController::grossMarginCte()
     * @see \App\Services\Reports\CteReportService::grossMargin()
     */
    public function grossMargin(Request $request)
    {
        return redirect()->route('admin.reports.grossMarginCte', $request->query(), 301);
    }

    public function customerPerformance(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::select(<<<SQL
SELECT
    c.id, c.customer_code, c.customer_name,
    COUNT(DISTINCT si.id) AS invoice_count,
    COALESCE(SUM(si.total_amount), 0) AS total_revenue,
    COALESCE(SUM(si.paid_amount), 0) AS total_paid,
    COALESCE(SUM(si.due_amount), 0) AS total_due,
    MAX(si.invoice_date) AS last_invoice_date
FROM customers c
LEFT JOIN sales_invoices si ON si.customer_id = c.id
    AND si.invoice_date BETWEEN ? AND ?
    AND si.status NOT IN ('draft', 'cancelled')
    AND si.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.id, c.customer_code, c.customer_name
HAVING COUNT(DISTINCT si.id) > 0
ORDER BY total_revenue DESC
SQL, [$data['from'], $data['to']]);

        return view('admin.reports.customer_performance', [
            'meta' => ['title' => 'Customer Performance', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => collect($rows),
        ]);
    }

    public function supplierWisePurchase(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::select(<<<SQL
SELECT
    s.id, s.supplier_code, s.supplier_name,
    COUNT(DISTINCT pr.id) AS receive_count,
    COALESCE(SUM(pr.total_amount), 0) AS total_purchase,
    MAX(pr.receive_date) AS last_receive_date
FROM suppliers s
LEFT JOIN purchase_receives pr ON pr.supplier_id = s.id
    AND pr.receive_date BETWEEN ? AND ?
    AND pr.is_reversed = false
WHERE s.deleted_at IS NULL
GROUP BY s.id, s.supplier_code, s.supplier_name
HAVING COUNT(DISTINCT pr.id) > 0
ORDER BY total_purchase DESC
SQL, [$data['from'], $data['to']]);

        return view('admin.reports.supplier_wise_purchase', [
            'meta' => ['title' => 'Supplier-wise Purchase', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => collect($rows),
        ]);
    }

    public function productStockAnalysis(Request $request)
    {
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);
        $report = $this->reportService->stockValuation(
            $branchId,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null,
        );

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.product_stock_analysis', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    public function productMovement(Request $request)
    {
        $data = $this->parseDateRange($request);
        $productId = $request->input('product_id') ? (int) $request->input('product_id') : null;
        $warehouseId = $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        $query = \Illuminate\Support\Facades\DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->whereBetween('st.transaction_date', [$data['from'], $data['to']])
            ->when($productId, fn($q) => $q->where('st.product_id', $productId))
            ->when($warehouseId, fn($q) => $q->where('st.warehouse_id', $warehouseId))
            ->select(
                'st.transaction_date', 'st.reference_type', 'st.reference_id',
                'st.qty', 'st.rate', 'st.total_value',
                'p.product_code', 'p.product_name',
                'w.warehouse_name'
            )
            ->orderBy('st.transaction_date')
            ->orderBy('st.id');

        $rows = $query->paginate(100);

        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.product_movement', [
            'meta' => ['title' => 'Product Movement', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => $rows,
            'products' => $products,
            'branches' => $branches,
        ]);
    }

    // Operations report placeholders (audit checklists) — simpler views.
    public function salesAuditChecklist(Request $request)
    {
        $data = $this->parseDateRange($request);
        $checks = $this->computeSalesAuditChecks($data['from'], $data['to']);
        return view('admin.reports.sales_audit_checklist', [
            'meta' => ['title' => 'Sales Audit Checklist', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'checks' => $checks,
        ]);
    }

    public function purchaseAudit(Request $request)
    {
        // Phase 6: redirect the old stub URL to the real checklist dashboard.
        return redirect()->route('admin.purchase-audit.checklist');
    }

    // ============================================================
    // Phase 6 (Stock Take plan): Variance + Weekly control reports.
    // Replaces the previous session-listing stubs with real per-line
    // variance numbers, summary totals, CSV export (Excel-friendly BOM),
    // and a per-line GL drill-down modal. Branch isolation is enforced
    // by RLS on stock_take_sessions (no manual WHERE branch_id needed).
    // ============================================================

    /**
     * Stock Take Variance detail report — every count line where
     * physical ≠ system, with filters (session / branch / warehouse /
     * product / date range) and CSV export.
     */
    public function stocktakeVariance(StocktakeVarianceRequest $request)
    {
        $data = $this->parseDateRange($request);

        $filters = [
            'from'          => $data['from']->format('Y-m-d'),
            'to'            => $data['to']->format('Y-m-d'),
            'session_id'    => $request->filled('session_id') ? (int) $request->input('session_id') : null,
            'branch_id'     => $request->filled('branch_id') ? (int) $request->input('branch_id') : null,
            'warehouse_id'  => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'product_id'    => $request->filled('product_id') ? (int) $request->input('product_id') : null,
        ];

        $rows    = $this->stocktakeVarianceReport->getVarianceLines($filters);
        $summary = $this->stocktakeVarianceReport->summarize($rows);

        // Paginate manually (the report materialises all matching rows so we
        // can compute accurate totals across the full result set).
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 50;
        $paged   = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $paged,
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // Filter dropdowns (RLS-scoped by branch automatically).
        $sessions   = $this->stocktakeVarianceReport->getSessionsList();
        $branches   = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::orderBy('warehouse_name')->get();

        return view('admin.reports.stocktake_variance', [
            'meta'        => [
                'title'     => 'Stock Take Variance',
                'from_date' => $data['from']->format('Y-m-d'),
                'to_date'   => $data['to']->format('Y-m-d'),
            ],
            'data'        => $paginator,
            'summary'     => $summary,
            'filters'     => $filters,
            'sessions'    => $sessions,
            'branches'    => $branches,
            'warehouses'  => $warehouses,
            'is_admin'    => $this->currentUserIsAdmin(),
        ]);
    }

    /**
     * CSV export of the variance detail report (Excel-friendly, BOM-prefixed).
     */
    public function stocktakeVarianceExport(StocktakeVarianceRequest $request)
    {
        $data = $this->parseDateRange($request);

        $filters = [
            'from'          => $data['from']->format('Y-m-d'),
            'to'            => $data['to']->format('Y-m-d'),
            'session_id'    => $request->filled('session_id') ? (int) $request->input('session_id') : null,
            'branch_id'     => $request->filled('branch_id') ? (int) $request->input('branch_id') : null,
            'warehouse_id'  => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'product_id'    => $request->filled('product_id') ? (int) $request->input('product_id') : null,
        ];

        $rows = $this->stocktakeVarianceReport->getVarianceLines($filters);
        return $this->stocktakeVarianceReport->exportCsv($rows);
    }

    /**
     * AJAX: return the journal entry + lines for a stock-take session
     * (GL drill-down). Used by the variance report's "View GL" modal.
     *
     * The session must have a posted journal_entry_id; otherwise an empty
     * payload is returned. RLS scopes the session row.
     */
    public function stocktakeVarianceJournal(Request $request, int $session)
    {
        $sessionId = (int) $session;
        $sessionRow = \Illuminate\Support\Facades\DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->select('id', 'session_code', 'journal_entry_id', 'status', 'is_reversed')
            ->first();

        if (!$sessionRow || empty($sessionRow->journal_entry_id)) {
            return response()->json(['entry' => null, 'lines' => [], 'session' => $sessionRow]);
        }

        ['entry' => $entry, 'lines' => $lines] = $this->journalPosting->getEntryWithLines(
            (int) $sessionRow->journal_entry_id
        );

        return response()->json([
            'session' => $sessionRow,
            'entry'   => $entry,
            'lines'   => $lines,
        ]);
    }

    /**
     * Stock Take Weekly control report — posted/reversed/in-flight sessions
     * in the period with gain/loss totals and top-variance SKUs.
     */
    public function stocktakeWeekly(Request $request)
    {
        $from = $request->input('from_date')
            ? Carbon::parse($request->input('from_date'))->format('Y-m-d')
            : Carbon::now()->subDays(6)->format('Y-m-d');
        $to = $request->input('to_date')
            ? Carbon::parse($request->input('to_date'))->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->stocktakeWeeklyReport->getWeekly($from, $to, $branchId);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.stocktake_weekly', array_merge($report, [
            'meta'     => [
                'title'     => 'Stock Take — Weekly Control',
                'from_date' => $from,
                'to_date'   => $to,
            ],
            'branches' => $branches,
            'is_admin' => $this->currentUserIsAdmin(),
        ]));
    }

    /**
     * CSV export of the weekly control report (Excel-friendly, BOM-prefixed).
     */
    public function stocktakeWeeklyExport(Request $request)
    {
        $from = $request->input('from_date')
            ? Carbon::parse($request->input('from_date'))->format('Y-m-d')
            : Carbon::now()->subDays(6)->format('Y-m-d');
        $to = $request->input('to_date')
            ? Carbon::parse($request->input('to_date'))->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->stocktakeWeeklyReport->getWeekly($from, $to, $branchId);
        return $this->stocktakeWeeklyReport->exportCsv($report);
    }

    /**
     * Whether the authenticated user is an admin (sees all branches).
     * Phase 6 (Stock Take plan): non-admin users only see their own
     * branch's data — enforced by RLS, but we also hide the branch
     * filter dropdown for non-admins (legacy parity).
     */
    private function currentUserIsAdmin(): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        return $user && (method_exists($user, 'isAdmin') ? $user->isAdmin() : ($user->role ?? null) === 'admin');
    }

    public function branchDemandWeekly(Request $request)
    {
        // G-050 (CRITICAL, REPORTS-2): this method was a 5-column stub that
        // paginated raw branch_demands rows. The REAL 23-column weekly report
        // lives at admin.branch-demands.weekly-report →
        // BranchDemandReportController::weekly →
        // BranchDemandWeeklyReportService::generateDailyReport. The
        // ReportsCatalog entry now points directly at the real route; this
        // stub is retained only as a redirect for any bookmarks/links still
        // targeting admin.reports.branchDemandWeekly, forwarding the query
        // string so date filters survive the hop.
        return redirect()->route('admin.branch-demands.weekly-report', $request->query());
    }

    // ============================================================
    // Phase 1E (Task 32): CTE-Based Reports
    // These use PostgreSQL CTE functions for single-query complex
    // aggregation, replacing multiple SQL roundtrips.
    // ============================================================

    /**
     * Today's Summary (CTE) — All dashboard KPIs in one query.
     */
    public function todaySummaryCte(ReportAsOfRequest $request)
    {
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::today();
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->cteReportService->todaySummary($date, $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.today_summary_cte', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * AR Aging (CTE) — Proper sub-ledger based aging with GL reconciliation.
     */
    public function arAgingCte(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->cteReportService->arAging($asOf, $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.ar_aging_cte', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * General Ledger (CTE) — With SQL window-function running balance.
     */
    public function generalLedgerCte(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $ledgerId = $request->input('ledger_id') ? (int) $request->input('ledger_id') : null;
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->cteReportService->generalLedger($data['from'], $data['to'], $ledgerId, $branchId);

        $ledgers = \App\Models\Ledger::active()->orderBy('ledger_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.general_ledger_cte', array_merge($report, [
            'ledgers' => $ledgers,
            'branches' => $branches,
        ]));
    }

    /**
     * Gross Margin (CTE) — Per-invoice and per-product margin with accurate COGS.
     */
    public function grossMarginCte(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->cteReportService->grossMargin($data['from'], $data['to'], $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.gross_margin_cte', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    // ============================================================
    // Phase 6 (Damage plan): Dedicated Damage Report
    // ============================================================

    /**
     * Damage Report — multi-dimensional breakdown of damage/loss data.
     *
     * Renders KPI cards + monthly trend line + category donut + warehouse bar
     * + employee ranking table + top-products table + status distribution +
     * detail line table with CSV export.
     */
    public function damageReport(Request $request)
    {
        $data     = $this->parseDateRange($request);
        $from     = $data['from']->format('Y-m-d');
        $to       = $data['to']->format('Y-m-d');
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $filters = [
            'from'       => $from,
            'to'         => $to,
            'branch_id'  => $branchId,
            'warehouse_id'        => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'damage_type'         => $request->input('damage_type'),
            'status'              => $request->input('status'),
            'accountable_employee_id' => $request->filled('accountable_employee_id') ? (int) $request->input('accountable_employee_id') : null,
        ];

        $kpi          = $this->damageReportService->kpi($from, $to, $branchId);
        $monthly      = $this->damageReportService->monthlyTrend($from, $to, $branchId);
        $byWarehouse  = $this->damageReportService->byWarehouse($from, $to, $branchId);
        $byCategory   = $this->damageReportService->byCategory($from, $to, $branchId);
        $byEmployee   = $this->damageReportService->byEmployee($from, $to, $branchId);
        $topProducts  = $this->damageReportService->topProducts($from, $to, 20, $branchId);
        $byStatus     = $this->damageReportService->byStatus($from, $to, $branchId);
        $detail       = $this->damageReportService->getDetailLines($filters);
        $summary      = $this->damageReportService->summarize($detail);

        $branches    = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses  = \App\Models\Warehouse::active()->orderBy('warehouse_name')->get();
        $employees   = \App\Models\Employee::active()->orderBy('name')->limit(500)->get();
        $damageTypes = \App\Models\DamageInvoice::DAMAGE_TYPES;

        return view('admin.reports.damage_report', [
            'meta'        => ['title' => 'Damage Report', 'from_date' => $from, 'to_date' => $to, 'branch_id' => $branchId],
            'kpi'         => $kpi,
            'monthly'     => $monthly,
            'byWarehouse' => $byWarehouse,
            'byCategory'  => $byCategory,
            'byEmployee'  => $byEmployee,
            'topProducts' => $topProducts,
            'byStatus'    => $byStatus,
            'detail'      => $detail,
            'summary'     => $summary,
            'filters'     => $filters,
            'branches'    => $branches,
            'warehouses'  => $warehouses,
            'employees'   => $employees,
            'damageTypes' => $damageTypes,
            'is_admin'    => $this->currentUserIsAdmin(),
        ]);
    }

    /**
     * CSV export of damage detail lines.
     */
    public function damageReportExport(Request $request)
    {
        $data     = $this->parseDateRange($request);
        $from     = $data['from']->format('Y-m-d');
        $to       = $data['to']->format('Y-m-d');
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $filters = [
            'from'       => $from,
            'to'         => $to,
            'branch_id'  => $branchId,
            'warehouse_id'        => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'damage_type'         => $request->input('damage_type'),
            'status'              => $request->input('status'),
            'accountable_employee_id' => $request->filled('accountable_employee_id') ? (int) $request->input('accountable_employee_id') : null,
        ];

        $rows = $this->damageReportService->getDetailLines($filters);

        return $this->damageReportService->exportCsv($rows);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Parse from_date / to_date from request (defaults to month-to-date).
     *
     * @return array{ from: Carbon, to: Carbon }
     */
    private function parseDateRange(Request $request): array
    {
        $from = $request->input('from_date')
            ? Carbon::parse($request->input('from_date'))
            : Carbon::now()->startOfMonth();
        $to = $request->input('to_date')
            ? Carbon::parse($request->input('to_date'))
            : Carbon::now();
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Parse as_of_date from request (defaults to today).
     */
    private function parseAsOfDate(Request $request): Carbon
    {
        return $request->input('as_of_date')
            ? Carbon::parse($request->input('as_of_date'))
            : Carbon::now();
    }

    /**
     * Resolve the branch scope for MV-reading report methods.
     *
     * REPORTS-1 (G-044): PostgreSQL does NOT support Row Level Security
     * on materialized views (RLS is only for tables + regular views).
     * The previous fix attempt (commit 278a03d) tried `ALTER MATERIALIZED
     * VIEW ... ENABLE ROW LEVEL SECURITY` and failed at runtime with
     * `55000: Wrong object type: ENABLE ROW SECURITY cannot be performed
     * on relation "mv_ar_aging"`. That migration was reverted.
     *
     * The correct fix is READ-SITE FILTERING: every controller method
     * that reads an MV must explicitly filter `WHERE branch_id = ?`.
     * The previous code used `->when($branchId, ...)` which made the
     * filter OPTIONAL — a caller passing null branch_id got ALL
     * branches' data. This helper makes the filter MANDATORY for
     * non-admin users (pinned to session branch_id) while preserving
     * the admin "all branches" view (null).
     *
     * Logic:
     *   - Admin user with explicit branch_id in request → that branch.
     *   - Admin user with no branch_id in request → null (all branches).
     *   - Non-admin user with no branch_id → session branch_id (forced).
     *   - Non-admin user with branch_id != session → session branch_id
     *     (defense in depth — EnforceBranchIsolation middleware should
     *     already have blocked this, but we double-guard at the read site).
     *
     * @return int|null The branch_id to filter by, or null for "all
     *                  branches" (admin only).
     */
    private function resolveBranchScope(Request $request): ?int
    {
        $user = $request->user();
        $sessionBranchId = (int) (session('branch_id') ?? $user?->getBranchId() ?? 0);
        $requestBranchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        // Admins can view any branch (or all branches when null).
        if ($user?->isAdmin()) {
            return $requestBranchId;
        }

        // Non-admins: pin to session branch_id. If they explicitly
        // requested their own branch, honor it; otherwise force the
        // session branch (defense in depth against crafted requests).
        return $sessionBranchId > 0 ? $sessionBranchId : null;
    }

    /**
     * Compute sales audit checklist checks.
     */
    private function computeSalesAuditChecks(Carbon $from, Carbon $to): array
    {
        $checks = [];

        // 1. Invoices without GL journal entries.
        $missingGl = \Illuminate\Support\Facades\DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM sales_invoices si
WHERE si.invoice_date BETWEEN ? AND ?
    AND si.status NOT IN ('draft', 'cancelled')
    AND si.journal_entry_id IS NULL
    AND si.deleted_at IS NULL
SQL, [$from, $to]);
        $checks[] = [
            'label' => 'Invoices without GL journal entry',
            'count' => (int) $missingGl->cnt,
            'status' => $missingGl->cnt == 0 ? 'pass' : 'fail',
        ];

        // 2. Invoices with unbalanced journal entries (should be 0 — DB trigger enforces).
        $unbalanced = \Illuminate\Support\Facades\DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT je.id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
    FROM journal_entries je
    JOIN journal_lines jl ON jl.journal_entry_id = je.id
    WHERE je.reference_type = 'sales_invoice'
        AND je.entry_date BETWEEN ? AND ?
    GROUP BY je.id
    HAVING SUM(jl.debit) <> SUM(jl.credit)
) x
SQL, [$from, $to]);
        $checks[] = [
            'label' => 'Unbalanced sales journal entries',
            'count' => (int) $unbalanced->cnt,
            'status' => $unbalanced->cnt == 0 ? 'pass' : 'fail',
        ];

        // 3. Draft invoices older than 14 days (stale drafts).
        $staleDrafts = \Illuminate\Support\Facades\DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM sales_invoices si
WHERE si.invoice_date < (CURRENT_DATE - INTERVAL '14 days')
    AND si.status = 'draft'
    AND si.deleted_at IS NULL
SQL);
        $checks[] = [
            'label' => 'Stale draft invoices (>14 days)',
            'count' => (int) $staleDrafts->cnt,
            'status' => $staleDrafts->cnt == 0 ? 'pass' : 'warn',
        ];

        return $checks;
    }
}
