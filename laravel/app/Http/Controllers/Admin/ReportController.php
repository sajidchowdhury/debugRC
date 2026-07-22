<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\ReportsCatalog;
use App\Services\Reports\ReportService;
use App\Services\Reports\CteReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Report Controller — Phase 5.
 *
 * Serves the reports hub + 18 financial/operational reports.
 * Uses ReportService for query execution (which uses materialized views).
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private CteReportService $cteReportService
    ) {}

    /**
     * Reports hub — catalog of all 18 reports grouped by category.
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
    public function trialBalance(Request $request)
    {
        $data = $this->parseDateRange($request);
        $accountType = $request->input('account_type');
        $includeZero = $request->boolean('include_zero');

        $report = $this->reportService->trialBalance(
            $data['from'], $data['to'], $accountType, $includeZero
        );

        return view('admin.reports.trial_balance', array_merge($report, [
            'accountTypes' => ['Asset', 'Liability', 'Equity', 'Income', 'Expense'],
        ]));
    }

    /**
     * Profit & Loss.
     */
    public function profitAndLoss(Request $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->profitAndLoss($data['from'], $data['to'], $branchId);

        return view('admin.reports.profit_and_loss', $report);
    }

    /**
     * Balance Sheet.
     */
    public function balanceSheet(Request $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $includeZero = $request->boolean('include_zero');

        $report = $this->reportService->balanceSheet($asOf, $branchId, $includeZero);

        return view('admin.reports.balance_sheet', $report);
    }

    /**
     * Cash Flow Statement.
     */
    public function cashFlow(Request $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->cashFlow($data['from'], $data['to'], $branchId);

        return view('admin.reports.cash_flow', $report);
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
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
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
    public function receivableAging(Request $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $report = $this->reportService->receivableAging($asOf, $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.receivable_aging', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Payable Aging.
     */
    public function payableAging(Request $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

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
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
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

    public function grossMargin(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::select(<<<SQL
SELECT
    si.id, si.invoice_code, si.invoice_date, c.customer_name,
    si.total_amount AS revenue,
    COALESCE(sc.issue_cost, 0) AS cogs,
    si.total_amount - COALESCE(sc.issue_cost, 0) AS gross_profit,
    CASE WHEN si.total_amount > 0
        THEN ROUND(((si.total_amount - COALESCE(sc.issue_cost, 0)) / si.total_amount * 100)::numeric, 2)
        ELSE 0 END AS margin_pct
FROM sales_invoices si
LEFT JOIN customers c ON c.id = si.customer_id
LEFT JOIN sales_challans sc ON sc.sales_invoice_id = si.id
WHERE si.invoice_date BETWEEN ? AND ?
    AND si.status NOT IN ('draft', 'cancelled')
    AND si.deleted_at IS NULL
ORDER BY si.invoice_date DESC
SQL, [$data['from'], $data['to']]);

        return view('admin.reports.gross_margin', [
            'meta' => ['title' => 'Gross Margin', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => collect($rows),
            'totals' => [
                'revenue' => collect($rows)->sum('revenue'),
                'cogs' => collect($rows)->sum('cogs'),
                'gross_profit' => collect($rows)->sum('gross_profit'),
            ],
        ]);
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
        $report = $this->reportService->stockValuation(
            $request->input('branch_id') ? (int) $request->input('branch_id') : null,
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

    public function stocktakeVariance(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::table('stock_take_sessions as sts')
            ->join('branches as b', 'b.id', '=', 'sts.branch_id')
            ->whereBetween('sts.session_date', [$data['from'], $data['to']])
            ->select('sts.id', 'sts.session_code', 'sts.session_date', 'sts.status', 'b.branch_name')
            ->orderBy('sts.session_date', 'desc')
            ->paginate(25);

        return view('admin.reports.stocktake_variance', [
            'meta' => ['title' => 'Stock Take Variance', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => $rows,
        ]);
    }

    public function branchDemandWeekly(Request $request)
    {
        $data = $this->parseDateRange($request);
        $rows = \Illuminate\Support\Facades\DB::table('branch_demands as bd')
            ->join('branches as fb', 'fb.id', '=', 'bd.from_branch_id')
            ->join('branches as tb', 'tb.id', '=', 'bd.to_branch_id')
            ->whereBetween('bd.demand_date', [$data['from'], $data['to']])
            ->select('bd.id', 'bd.demand_code', 'bd.demand_date', 'bd.status', 'fb.branch_name as from_branch', 'tb.branch_name as to_branch')
            ->orderBy('bd.demand_date', 'desc')
            ->paginate(25);

        return view('admin.reports.branch_demand_weekly', [
            'meta' => ['title' => 'Branch Demand — Weekly', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => $rows,
        ]);
    }

    // ============================================================
    // Phase 1E (Task 32): CTE-Based Reports
    // These use PostgreSQL CTE functions for single-query complex
    // aggregation, replacing multiple SQL roundtrips.
    // ============================================================

    /**
     * Today's Summary (CTE) — All dashboard KPIs in one query.
     */
    public function todaySummaryCte(Request $request)
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
    public function arAgingCte(Request $request)
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
    public function generalLedgerCte(Request $request)
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
    public function grossMarginCte(Request $request)
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
