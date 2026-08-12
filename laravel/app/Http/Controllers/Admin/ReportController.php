<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
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
use App\Services\Sales\SalesAuditService;
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
    use WritesExportAuditLog;

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
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->trialBalance(
            $data['from'], $data['to'], $accountType, $includeZero, $branchId
        );

        // CSV export
        if ($request->input('export') === 'csv') {
            // Audit log: row count is the ledger-line count (data section).
            $this->logExport('trial_balance', [
                'from_date' => $report['meta']['from_date'],
                'to_date' => $report['meta']['to_date'],
                'branch_id' => $report['meta']['branch_id'],
                'account_type' => $accountType,
                'include_zero' => $includeZero,
            ], rowCount: is_countable($report['data']) ? count($report['data']) : 0, byteSize: 0);

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
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows() using the new `prepend_rows` +
     * `append_rows` options. The trial-balance CSV has a multi-section
     * layout (title rows → column header → ledger data rows → totals →
     * INTEGRITY CHECKS → optional SUB-LEDGER RECONCILIATION) that does
     * not fit the simple header+data shape. The prepend_rows option
     * carries the title + period + generated-timestamp block; the
     * append_rows option carries the totals + integrity-checks +
     * sub-ledger reconciliation blocks. Column order, column labels,
     * section ordering, and blank separator rows are preserved exactly.
     * BOM + Content-Type + RFC 4180 escaping handled by the canonical
     * service.
     */
    private function exportTrialBalanceCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // REPORTS-AUDIT-6 (G-236 / csv-export.md G15): Currency column added
        // at the end of every row so auditors can disambiguate amounts
        // without guessing. Trial balance is a single-currency report (the
        // whole report runs in the configured base currency) so every row
        // carries the same Currency value. Branch Code column is NOT added
        // here — trial balance aggregates across the selected branch(es)
        // at the report level, not per-ledger-row. The Branch ID is
        // surfaced in the title block (prepend rows) when set.
        $currency = (string) config('accounting.currency', 'BDT');

        // Title rows — written AFTER the BOM, BEFORE the column header.
        $prependRows = [
            ['Trial Balance Report'],
            ['Period: ' . $report['meta']['from_date'] . ' to ' . $report['meta']['to_date']],
        ];
        if (!empty($report['meta']['branch_id'])) {
            $prependRows[] = ['Branch ID: ' . $report['meta']['branch_id']];
        }
        $prependRows[] = ['Currency: ' . $currency];
        $prependRows[] = ['Generated: ' . now()->format('Y-m-d H:i:s')];
        $prependRows[] = []; // blank separator row before the column header

        $headerRow = [
            'Code', 'Ledger Name', 'Type', 'Nature', 'Normal Balance',
            'Opening Dr', 'Opening Cr', 'Opening Balance', 'Opening Side',
            'Period Dr', 'Period Cr',
            'Closing Dr', 'Closing Cr', 'Closing Balance', 'Closing Side',
            'Currency',
        ];

        $rowGenerator = $this->buildTrialBalanceCsvRows($report['data'], $currency);

        // Append rows — written AFTER the data rows.
        $appendRows = $this->buildTrialBalanceAppendRows($report, $currency);

        $filename = CsvExporter::filename('Trial_Balance', [$report['meta']['from_date'], 'to', $report['meta']['to_date']]);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows' => $appendRows,
        ]);
    }

    /**
     * Build the data-row generator for the trial-balance CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportTrialBalanceCsv() method body (the linter cannot parse `yield`
     * inside an inline closure expression).
     *
     * REPORTS-AUDIT-6 (G-236): a Currency column is appended to each row.
     *
     * @param  iterable<int, object> $data
     * @param  string                $currency Currency code from config('accounting.currency').
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildTrialBalanceCsvRows(iterable $data, string $currency): \Generator
    {
        foreach ($data as $row) {
            yield [
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
                $currency,
            ];
        }
    }

    /**
     * Build the append-rows array for the trial-balance CSV export.
     *
     * Carries the GRAND TOTAL row, the INTEGRITY CHECKS block, and the
     * optional SUB-LEDGER RECONCILIATION block. Each row is a flat array
     * of cell values (variable column count per section is fine — fputcsv
     * writes whatever cells it receives).
     *
     * REPORTS-AUDIT-6 (G-236): the GRAND TOTAL row now carries a trailing
     * Currency cell so its column count matches the header + data rows.
     *
     * @param  array  $report
     * @param  string $currency Currency code from config('accounting.currency').
     * @return array<int, array<int,mixed>>
     */
    private function buildTrialBalanceAppendRows(array $report, string $currency): array
    {
        $rows = [];

        // Totals row (with a blank separator row before it).
        $rows[] = []; // blank separator
        $t = $report['totals'];
        $rows[] = [
            'GRAND TOTAL', '', '', '', '',
            number_format($t['opening_debit'], 2, '.', ''),
            number_format($t['opening_credit'], 2, '.', ''),
            '', '',
            number_format($t['period_debit'], 2, '.', ''),
            number_format($t['period_credit'], 2, '.', ''),
            number_format($t['closing_debit'], 2, '.', ''),
            number_format($t['closing_credit'], 2, '.', ''),
            '', '',
            $currency,
        ];

        // Integrity checks block (with a blank separator row before it).
        $rows[] = []; // blank separator
        $rows[] = ['INTEGRITY CHECKS'];
        $c = $report['checks'];
        $rows[] = ['Opening balanced', $c['opening_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['opening_diff']];
        $rows[] = ['Period balanced', $c['period_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['period_diff']];
        $rows[] = ['Closing balanced', $c['closing_balanced'] ? 'YES' : 'NO', 'Diff: ' . $c['closing_diff']];
        $rows[] = ['All accounts balance', $c['all_accounts_balance'] ? 'YES' : 'NO', 'Fails: ' . $c['balance_check_fails']];
        $rows[] = ['Orphaned journal lines', (string) $c['orphaned_journal_lines']];

        // Optional sub-ledger reconciliation block.
        if (!empty($c['subledger_reconciliation'])) {
            $rows[] = []; // blank separator
            $rows[] = ['SUB-LEDGER RECONCILIATION'];
            foreach ($c['subledger_reconciliation'] as $sl) {
                $rows[] = [
                    $sl['label'],
                    $sl['reconciled'] ? 'RECONCILED' : 'OUT OF BALANCE',
                    'GL: ' . number_format($sl['gl_balance'], 2),
                    'Sub: ' . number_format($sl['sub_balance'], 2),
                    'Diff: ' . number_format($sl['difference'], 2),
                ];
            }
        }

        return $rows;
    }

    /**
     * Profit & Loss.
     *
     * REPORTS-AUDIT-6 (G-236 / csv-export.md G15): added `?export=csv`
     * toggle. The CSV export is a multi-section layout (title + period +
     * currency → per-section ledger rows + section totals → GRAND TOTALS
     * + margin %). Each row carries a trailing Currency cell. Branch Code
     * column is not added — P&L aggregates at the report level, not per
     * ledger row; the Branch ID is surfaced in the title block when set.
     */
    public function profitAndLoss(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->profitAndLoss($data['from'], $data['to'], $branchId);

        if ($request->input('export') === 'csv') {
            $this->logExport('profit_and_loss', [
                'from_date' => $report['meta']['from_date'],
                'to_date' => $report['meta']['to_date'],
                'branch_id' => $branchId,
            ], rowCount: 0, byteSize: 0);

            return $this->exportProfitAndLossCsv($report);
        }

        return view('admin.reports.profit_and_loss', $report);
    }

    /**
     * Export Profit & Loss Statement as CSV download.
     *
     * REPORTS-AUDIT-6 (G-236 / csv-export.md G15): multi-section layout
     * (title + period + currency → per-section ledger rows + section
     * totals → GRAND TOTALS + margin %) via CsvExporter::exportFromRows
     * with `prepend_rows` (title block) + `append_rows` (GRAND TOTALS +
     * margin block). Each row carries a trailing Currency cell. BOM +
     * Content-Type + RFC 4180 escaping handled by the canonical service.
     */
    private function exportProfitAndLossCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        $prependRows = [
            ['Profit & Loss Statement (Multi-step)'],
            ['Period', $report['meta']['from_date'] . ' to ' . $report['meta']['to_date']],
            ['Currency', $currency],
            [],
        ];

        $rowGenerator = $this->buildProfitAndLossCsvRows($report, $currency);

        $appendRows = $this->buildProfitAndLossAppendRows($report, $currency);

        $filename = CsvExporter::filename('Profit_and_Loss', [$report['meta']['from_date'], 'to', $report['meta']['to_date']]);

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows' => $appendRows,
        ]);
    }

    /**
     * Build the row generator for the P&L CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportProfitAndLossCsv() method body (the linter cannot parse
     * `yield` inside an inline closure expression).
     *
     * Yields the per-section ledger rows + section totals in the order
     * defined by $report['sections'] (already sorted by `sort` key in
     * ReportService::profitAndLoss). Each amount row carries a trailing
     * Currency cell.
     *
     * @param  array  $report
     * @param  string $currency Currency code from config('accounting.currency').
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildProfitAndLossCsvRows(array $report, string $currency): \Generator
    {
        foreach ($report['sections'] as $key => $section) {
            yield [strtoupper($section['label']), 'Amount (' . $currency . ')', $currency];

            if (!empty($section['rows'])) {
                foreach ($section['rows'] as $row) {
                    yield [
                        '    ' . $row->ledger_code . ' - ' . $row->ledger_name,
                        number_format($row->net_amount, 2, '.', ''),
                        $currency,
                    ];
                }
            }

            yield [
                'Total ' . $section['label'],
                number_format($section['total'], 2, '.', ''),
                $currency,
            ];
            yield [];
        }
    }

    /**
     * Build the append-rows array for the P&L CSV export.
     *
     * Carries the GRAND TOTALS block (revenue / cogs / gross profit /
     * opex / operating income / finance costs / net income before tax /
     * net income) and the margin % block.
     *
     * @param  array  $report
     * @param  string $currency
     * @return array<int, array<int,mixed>>
     */
    private function buildProfitAndLossAppendRows(array $report, string $currency): array
    {
        $t = $report['totals'];
        $rows = [];

        $rows[] = ['GRAND TOTALS', 'Amount (' . $currency . ')', $currency];
        $rows[] = ['Total Revenue', number_format($t['revenue'], 2, '.', ''), $currency];
        $rows[] = ['Total COGS', number_format($t['cogs'], 2, '.', ''), $currency];
        $rows[] = ['Gross Profit', number_format($t['gross_profit'], 2, '.', ''), $currency];
        $rows[] = ['Total Operating Expenses', number_format($t['operating_expenses'], 2, '.', ''), $currency];
        $rows[] = ['Operating Income', number_format($t['operating_income'], 2, '.', ''), $currency];
        $rows[] = ['Total Finance Costs', number_format($t['finance_costs'], 2, '.', ''), $currency];
        $rows[] = ['Net Income Before Tax', number_format($t['net_income_before_tax'], 2, '.', ''), $currency];
        $rows[] = ['Net Income', number_format($t['net_income'], 2, '.', ''), $currency];
        $rows[] = [];
        $rows[] = ['MARGIN %'];
        $rows[] = ['Gross Margin %', number_format($t['gross_margin_pct'], 1, '.', '') . '%'];
        $rows[] = ['Net Margin %', number_format($t['net_margin_pct'], 1, '.', '') . '%'];

        return $rows;
    }

    /**
     * Balance Sheet.
     *
     * REPORTS-AUDIT-6 (G-236 / csv-export.md G15): added `?export=csv`
     * toggle. The CSV export is a multi-section layout (title + as-of
     * date + currency → Assets section → Liabilities section → Equity
     * section → TOTALS + balance check). Each row carries a trailing
     * Currency cell. Branch Code column is not added — Balance Sheet
     * aggregates at the report level; the Branch ID is surfaced in the
     * title block when set.
     */
    public function balanceSheet(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        $branchId = $this->resolveBranchScope($request);
        $includeZero = $request->boolean('include_zero');

        $report = $this->reportService->balanceSheet($asOf, $branchId, $includeZero);

        if ($request->input('export') === 'csv') {
            $this->logExport('balance_sheet', [
                'as_of_date' => $report['meta']['as_of_date'],
                'branch_id' => $branchId,
                'include_zero' => $includeZero,
            ], rowCount: 0, byteSize: 0);

            return $this->exportBalanceSheetCsv($report);
        }

        return view('admin.reports.balance_sheet', $report);
    }

    /**
     * Export Balance Sheet as CSV download.
     *
     * REPORTS-AUDIT-6 (G-236 / csv-export.md G15): multi-section layout
     * (title + as-of + currency → Assets → Liabilities → Equity → TOTALS
     * + balance check) via CsvExporter::exportFromRows with `prepend_rows`
     * (title block) + `append_rows` (TOTALS + balance check). Each row
     * carries a trailing Currency cell.
     */
    private function exportBalanceSheetCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        $prependRows = [
            ['Balance Sheet'],
            ['As of', $report['meta']['as_of_date']],
            ['Currency', $currency],
            [],
        ];

        $rowGenerator = $this->buildBalanceSheetCsvRows($report, $currency);

        $appendRows = $this->buildBalanceSheetAppendRows($report, $currency);

        $filename = CsvExporter::filename('Balance_Sheet', [$report['meta']['as_of_date']]);

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows' => $appendRows,
        ]);
    }

    /**
     * Build the row generator for the balance-sheet CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportBalanceSheetCsv() method body.
     *
     * Yields the Assets / Liabilities / Equity sections in order. Each
     * ledger row is rendered as `code - name` + net amount + Currency.
     * Each section ends with a Total row + blank separator.
     *
     * @param  array  $report
     * @param  string $currency
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildBalanceSheetCsvRows(array $report, string $currency): \Generator
    {
        // Assets
        yield ['ASSETS', 'Amount (' . $currency . ')', $currency];
        foreach ($report['assets'] as $row) {
            yield [
                '    ' . $row->ledger_code . ' - ' . $row->ledger_name,
                number_format($row->net_debit, 2, '.', ''),
                $currency,
            ];
        }
        yield ['Total Assets', number_format($report['totals']['total_assets'], 2, '.', ''), $currency];
        yield [];

        // Liabilities
        yield ['LIABILITIES', 'Amount (' . $currency . ')', $currency];
        foreach ($report['liabilities'] as $row) {
            yield [
                '    ' . $row->ledger_code . ' - ' . $row->ledger_name,
                number_format($row->net_credit, 2, '.', ''),
                $currency,
            ];
        }
        yield ['Total Liabilities', number_format($report['totals']['total_liabilities'], 2, '.', ''), $currency];
        yield [];

        // Equity
        yield ['EQUITY', 'Amount (' . $currency . ')', $currency];
        foreach ($report['equity'] as $row) {
            yield [
                '    ' . $row->ledger_code . ' - ' . $row->ledger_name,
                number_format($row->net_credit, 2, '.', ''),
                $currency,
            ];
        }
        yield ['Current Period Result (unclosed income - expense)', number_format($report['current_period_result'], 2, '.', ''), $currency];
        yield ['Total Equity', number_format($report['totals']['total_equity'], 2, '.', ''), $currency];
        yield [];
    }

    /**
     * Build the append-rows array for the balance-sheet CSV export.
     *
     * Carries the TOTAL LIABILITIES + EQUITY row + the Balance Check block.
     *
     * @param  array  $report
     * @param  string $currency
     * @return array<int, array<int,mixed>>
     */
    private function buildBalanceSheetAppendRows(array $report, string $currency): array
    {
        $t = $report['totals'];
        $c = $report['checks'];

        return [
            ['TOTAL LIABILITIES + EQUITY', number_format($t['total_liabilities_equity'], 2, '.', ''), $currency],
            [],
            ['BALANCE CHECK'],
            ['Total Assets', number_format($t['total_assets'], 2, '.', ''), $currency],
            ['Total Liabilities + Equity', number_format($t['total_liabilities_equity'], 2, '.', ''), $currency],
            ['Reconciled', $c['balanced'] ? 'YES' : 'NO'],
        ];
    }

    /**
     * Cash Flow Statement (Indirect Method — Xero-style).
     */
    public function cashFlow(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->cashFlow($data['from'], $data['to'], $branchId);

        // CSV export
        if ($request->query('export') === 'csv') {
            // Audit log: row count is the sum of operating + investing +
            // financing + net-cash + integrity rows (the variable-width
            // sections make a precise count uninteresting — pass 0; the
            // audit row records that an export happened, with filters).
            $this->logExport('cash_flow', [
                'from_date' => $report['meta']['from_date'],
                'to_date' => $report['meta']['to_date'],
                'branch_id' => $branchId,
            ], rowCount: 0, byteSize: 0);

            return $this->exportCashFlowCsv($report);
        }

        return view('admin.reports.cash_flow', $report);
    }

    /**
     * Export Cash Flow Statement as CSV download.
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11 + G25 side effect):
     * refactored to delegate to CsvExporter::exportFromRows(). The cash-
     * flow CSV has a multi-section layout (title + period → operating
     * activities → investing activities → financing activities → net cash
     * movement → INTEGRITY CHECK) with NO single global column header —
     * each section has its own 2-cell [label, amount] shape. We use:
     *   - prepend_rows: title + period + blank separator
     *   - headerRow: [] (skipped — no global column header)
     *   - rows: all the section content via buildCashFlowCsvRows()
     *   - append_rows: [] (none — everything is in the rows stream)
     * BOM + Content-Type + RFC 4180 escaping handled by the canonical
     * service. The previous inline implementation FORGOT to write the
     * BOM (Gap G25 — csv-export.md MEDIUM severity) — the refactor
     * closes G25 as a side effect because CsvExporter::exportFromRows
     * always writes the BOM via config('reports.csv.bom').
     *
     * REPORTS-AUDIT-6 (G-236 / csv-export.md G15): a Currency cell is
     * appended to each amount row so the CSV carries currency context
     * (per-row; cash flow is single-currency so every row carries the
     * same value). Label-only rows + blank separator rows are unchanged
     * (1-cell or 0-cell — fputcsv handles variable column counts). The
     * title block also gains a `Currency: BDT` row.
     */
    private function exportCashFlowCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        $prependRows = [
            ['Cash Flow Statement (Indirect Method)'],
            ['Period', $report['meta']['from_date'] . ' to ' . $report['meta']['to_date']],
            ['Currency', $currency],
            [], // blank separator
        ];

        $rowGenerator = $this->buildCashFlowCsvRows($report, $currency);

        $filename = CsvExporter::filename('cash_flow', [$report['meta']['from_date'], 'to', $report['meta']['to_date']]);

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
        ]);
    }

    /**
     * Build the row generator for the cash-flow CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportCashFlowCsv() method body (the linter cannot parse `yield`
     * inside an inline closure expression).
     *
     * Yields every row of the operating/investing/financing/net-cash/
     * integrity sections in order. Each row is a 1-, 2-, or 3-cell array
     * (label rows = 1 cell; amount rows = 2 cells + 1 Currency cell since
     * REPORTS-AUDIT-6) — fputcsv writes whatever cells it receives, so
     * the variable column count is preserved exactly as in the prior
     * inline implementation, just with Currency appended to amount rows.
     *
     * @param  array  $report
     * @param  string $currency Currency code from config('accounting.currency').
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildCashFlowCsvRows(array $report, string $currency): \Generator
    {
        $op = $report['sections']['operating'];
        $inv = $report['sections']['investing'];
        $fin = $report['sections']['financing'];

        // Operating Activities
        yield ['CASH FLOW FROM OPERATING ACTIVITIES', 'Amount (' . $currency . ')'];
        yield ['Net Profit (from P&L)', number_format($op['net_profit'], 2), $currency];
        yield ['(+) Depreciation & Amortization', number_format($op['depreciation'], 2), $currency];
        yield ['Changes in Working Capital:'];
        foreach ($op['wc_adjustments'] as $wc) {
            $direction = $wc->change >= 0 ? 'Increase' : 'Decrease';
            yield ['    ' . $direction . ' in ' . $wc->label, number_format($wc->adjustment, 2), $currency];
        }
        yield ['Total Working Capital Adjustments', number_format($op['wc_adjustment_total'], 2), $currency];
        yield ['Net Cash from Operating Activities', number_format($op['net'], 2), $currency];
        yield [];

        // Investing Activities
        yield ['CASH FLOW FROM INVESTING ACTIVITIES', 'Amount (' . $currency . ')'];
        foreach ($inv['rows'] as $row) {
            $label = $row->net_amount < 0 ? 'Purchase of ' . $row->ledger_name : 'Sale of ' . $row->ledger_name;
            yield ['    ' . $label, number_format($row->net_amount, 2), $currency];
        }
        if ($inv['rows']->isEmpty()) {
            yield ['    (No investing activity in this period)', '0.00', $currency];
        }
        yield ['Net Cash from Investing Activities', number_format($inv['net'], 2), $currency];
        yield [];

        // Financing Activities
        yield ['CASH FLOW FROM FINANCING ACTIVITIES', 'Amount (' . $currency . ')'];
        foreach ($fin['rows'] as $row) {
            $label = $row->net_amount > 0 ? 'Proceeds from ' . $row->ledger_name : 'Repayment of ' . $row->ledger_name;
            yield ['    ' . $label, number_format($row->net_amount, 2), $currency];
        }
        if ($fin['rows']->isEmpty()) {
            yield ['    (No financing activity in this period)', '0.00', $currency];
        }
        yield ['Net Cash from Financing Activities', number_format($fin['net'], 2), $currency];
        yield [];

        // Net Cash Movement
        yield ['NET CASH MOVEMENT', 'Amount (' . $currency . ')'];
        yield ['Opening Cash Balance', number_format($report['totals']['cash_opening'], 2), $currency];
        yield ['Net Increase / (Decrease) in Cash', number_format($report['totals']['net_cash_change'], 2), $currency];
        yield ['Closing Cash Balance', number_format($report['totals']['cash_closing'], 2), $currency];
        yield [];

        // Integrity check
        yield ['INTEGRITY CHECK'];
        yield ['GL Cash Movement', number_format($report['totals']['net_cash_movement'], 2), $currency];
        yield ['Plug Difference', number_format($report['totals']['plug_difference'], 2), $currency];
        yield ['Reconciled', $report['checks']['plugs_to_gl_cash'] ? 'YES' : 'NO'];
    }

    /**
     * General Ledger.
     */
    public function generalLedger(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $ledgerId = $request->input('ledger_id') ? (int) $request->input('ledger_id') : null;
        $branchId = $this->resolveBranchScope($request);

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
    public function journalEntries(ReportRangeRequest $request)
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
    public function dailyCashBook(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->dailyCashBook($data['from'], $data['to'], $branchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.daily_cash_book', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Receivable Aging.
     *
     * REPORTS-AUDIT-6 (G-236 + G-239 / csv-export.md G15 + G16): added
     * `?export=csv` toggle. The CSV export is a per-customer row layout
     * with Branch Code + Branch Name + Currency columns (AR aging is the
     * only one of the 5 financial exports in this wave that has per-row
     * branch data — the others aggregate at the report level).
     */
    public function receivableAging(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        // G-044: read-site branch filtering — non-admins pinned to session branch.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->reportService->receivableAging($asOf, $branchId);

        if ($request->input('export') === 'csv') {
            $this->logExport('receivable_aging', [
                'as_of_date' => $report['meta']['as_of_date'],
                'branch_id' => $branchId,
            ], rowCount: is_countable($report['data']) ? count($report['data']) : 0, byteSize: 0);

            return $this->exportReceivableAgingCsv($report);
        }

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.receivable_aging', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * Export Receivable Aging as CSV download.
     *
     * REPORTS-AUDIT-6 (G-236 + G-239 / csv-export.md G15 + G16): per-
     * customer row layout with Branch Code + Branch Name + Currency
     * columns. The report's data rows already include `branch_id` +
     * `branch_name` (from the SQL JOIN in ReportService::receivableAging);
     * we look up the matching `branch_code` from the branches table once
     * at the start of the export (cheap — at most a few dozen branches)
     * so each row can carry both the code and the name. Customers with
     * no branch (head-office-only) get empty strings for both fields.
     */
    private function exportReceivableAgingCsv(array $report): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        $prependRows = [
            ['Receivable Aging Report'],
            ['As of', $report['meta']['as_of_date']],
            ['Currency', $currency],
            [],
        ];

        // Look up branch_code by branch_id once (cheap cache so the row
        // generator does not re-query for every customer row).
        $branchCodes = \Illuminate\Support\Facades\DB::table('branches')
            ->pluck('branch_code', 'id');

        $headerRow = [
            'Customer Code', 'Customer Name', 'Mobile',
            'Branch Code', 'Branch Name',
            'Bucket 0-30', 'Bucket 31-60', 'Bucket 61-90', 'Bucket 90+',
            'Total Receivable', 'Currency',
        ];

        $rowGenerator = $this->buildReceivableAgingCsvRows($report['data'], $branchCodes, $currency);

        $appendRows = $this->buildReceivableAgingAppendRows($report, $currency);

        $filename = CsvExporter::filename('Receivable_Aging', [$report['meta']['as_of_date']]);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows' => $appendRows,
        ]);
    }

    /**
     * Build the row generator for the receivable-aging CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportReceivableAgingCsv() method body.
     *
     * Each row carries: customer code/name/mobile, branch code/name (looked
     * up from $branchCodes by branch_id; empty string when branch_id is
     * null), the 4 aging buckets, total receivable, and the currency code.
     *
     * @param  iterable<int, object>        $rows
     * @param  \Illuminate\Support\Collection<int,string> $branchCodes Map of branch_id => branch_code.
     * @param  string                        $currency
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildReceivableAgingCsvRows(iterable $rows, $branchCodes, string $currency): \Generator
    {
        foreach ($rows as $row) {
            $branchId = $row->branch_id ?? null;
            $branchCode = $branchId ? (string) ($branchCodes[$branchId] ?? '') : '';
            $branchName = $row->branch_name ?? '';
            // The SQL uses COALESCE(b.branch_name, '—') which yields the
            // em-dash placeholder when branch_id is null. For CSV export
            // we prefer the empty string so downstream consumers can
            // filter "no branch" rows with a simple empty-string check.
            if ($branchName === '—' || $branchName === null) {
                $branchName = '';
            }

            yield [
                $row->customer_code ?? '',
                $row->customer_name ?? '',
                $row->mobile ?? '',
                $branchCode,
                $branchName,
                number_format((float) $row->bucket_0_30, 2, '.', ''),
                number_format((float) $row->bucket_31_60, 2, '.', ''),
                number_format((float) $row->bucket_61_90, 2, '.', ''),
                number_format((float) $row->bucket_90_plus, 2, '.', ''),
                number_format((float) $row->total_receivable, 2, '.', ''),
                $currency,
            ];
        }
    }

    /**
     * Build the append-rows array for the receivable-aging CSV export.
     *
     * Carries the GRAND TOTALS row (sum of each bucket across customers)
     * and the GL RECONCILIATION block.
     *
     * @param  array  $report
     * @param  string $currency
     * @return array<int, array<int,mixed>>
     */
    private function buildReceivableAgingAppendRows(array $report, string $currency): array
    {
        $t = $report['totals'];
        $c = $report['checks'];

        return [
            [],
            ['GRAND TOTALS', '', '', '', '',
                number_format($t['bucket_0_30'], 2, '.', ''),
                number_format($t['bucket_31_60'], 2, '.', ''),
                number_format($t['bucket_61_90'], 2, '.', ''),
                number_format($t['bucket_90_plus'], 2, '.', ''),
                number_format($t['total_receivable'], 2, '.', ''),
                $currency],
            [],
            ['GL RECONCILIATION'],
            ['Sub-ledger total', number_format($t['total_receivable'], 2, '.', ''), $currency],
            ['GL AR control account', number_format($t['gl_ar_control'], 2, '.', ''), $currency],
            ['Reconciled', $c['matches_gl'] ? 'YES' : 'NO'],
        ];
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
    public function branchIntercompany(ReportRangeRequest $request)
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
    public function branchWiseLedger(ReportRangeRequest $request)
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

    public function revenueOverview(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchScope($request);

        $query = \Illuminate\Support\Facades\DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'si.branch_id')
            ->leftJoin('employees as e', 'e.id', '=', 'si.salesman_id')
            ->whereBetween('si.invoice_date', [$data['from'], $data['to']])
            ->whereNull('si.deleted_at')
            ->when($branchId, fn($q) => $q->where('si.branch_id', $branchId))
            ->select(
                'si.id', 'si.invoice_code', 'si.invoice_date', 'si.status',
                'si.total_amount', 'si.paid_amount', 'si.due_amount',
                'c.customer_name', 'b.branch_name', 'e.name as salesman_name'
            )
            ->orderBy('si.invoice_date', 'desc');

        $rows = $query->paginate(50);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.revenue_overview', [
            'meta' => ['title' => 'Date Wise Sales Report', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'data' => $rows,
            'branches' => $branches,
            'selectedBranchId' => $branchId,
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
    public function grossMargin(ReportRangeRequest $request)
    {
        return redirect()->route('admin.reports.grossMarginCte', $request->query(), 301);
    }

    public function customerPerformance(ReportRangeRequest $request)
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

    public function supplierWisePurchase(ReportRangeRequest $request)
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

    public function productStockAnalysis(ReportRangeRequest $request)
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

    public function productMovement(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchScope($request);
        $productId = $request->input('product_id') ? (int) $request->input('product_id') : null;
        $warehouseId = $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        $query = \Illuminate\Support\Facades\DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->whereBetween('st.transaction_date', [$data['from'], $data['to']])
            ->when($productId, fn($q) => $q->where('st.product_id', $productId))
            ->when($warehouseId, fn($q) => $q->where('st.warehouse_id', $warehouseId))
            ->when($branchId, fn($q) => $q->where('st.branch_id', $branchId))
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
    public function salesAuditChecklist(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchIdForRead($this->resolveBranchScope($request));

        $report = (new SalesAuditService(
            $branchId > 0 ? $branchId : null,
            $data['from']->format('Y-m-d'),
            $data['to']->format('Y-m-d')
        ))->runHealthChecks();

        $branchName = 'All branches';
        if ($branchId > 0) {
            $branchName = \App\Models\Branch::find($branchId)?->branch_name ?? ("Branch #{$branchId}");
        }

        return view('admin.reports.sales_audit_checklist', [
            'meta' => ['title' => 'Sales Audit Checklist', 'from_date' => $data['from']->format('Y-m-d'), 'to_date' => $data['to']->format('Y-m-d')],
            'report' => $report,
            'branch_name' => $branchName,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * JSON refresh endpoint for the "Re-run checks" button on the sales
     * audit checklist. Returns the full 12-section report so the front-end
     * can re-render sections + summary chips in place.
     */
    public function salesAuditRun(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $branchId = $this->resolveBranchIdForRead($this->resolveBranchScope($request));

        $report = (new SalesAuditService(
            $branchId > 0 ? $branchId : null,
            $data['from']->format('Y-m-d'),
            $data['to']->format('Y-m-d')
        ))->runHealthChecks();

        return response()->json($report);
    }

    public function purchaseAudit(ReportRangeRequest $request)
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
            'branch_id'     => $this->resolveBranchScope($request),
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
            'branch_id'     => $this->resolveBranchScope($request),
            'warehouse_id'  => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'product_id'    => $request->filled('product_id') ? (int) $request->input('product_id') : null,
        ];

        $rows = $this->stocktakeVarianceReport->getVarianceLines($filters);

        // Audit log: row count is known precisely (the report materialises
        // all matching rows so the count is accurate).
        $this->logExport('stocktake_variance', $filters, rowCount: count($rows), byteSize: 0);

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
    public function stocktakeWeekly(ReportRangeRequest $request)
    {
        $from = $request->input('from_date')
            ? Carbon::parse($request->input('from_date'))->format('Y-m-d')
            : Carbon::now()->subDays(6)->format('Y-m-d');
        $to = $request->input('to_date')
            ? Carbon::parse($request->input('to_date'))->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $branchId = $this->resolveBranchScope($request);

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
    public function stocktakeWeeklyExport(ReportRangeRequest $request)
    {
        $from = $request->input('from_date')
            ? Carbon::parse($request->input('from_date'))->format('Y-m-d')
            : Carbon::now()->subDays(6)->format('Y-m-d');
        $to = $request->input('to_date')
            ? Carbon::parse($request->input('to_date'))->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $branchId = $this->resolveBranchScope($request);

        $report = $this->stocktakeWeeklyReport->getWeekly($from, $to, $branchId);

        // Audit log: row count is the session count (the weekly CSV has
        // one row per session — count() is precise).
        $this->logExport('stocktake_weekly', [
            'from_date' => $from,
            'to_date' => $to,
            'branch_id' => $branchId,
        ], rowCount: is_countable($report['sessions'] ?? null) ? count($report['sessions']) : 0, byteSize: 0);

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

    public function branchDemandWeekly(ReportRangeRequest $request)
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
     *
     * REPORTS-AUDIT-6 (G-288 / cte-reports.md G15): added `?format=csv`
     * toggle. When set, delegates to CteReportService::exportCsv() which
     * flattens the structured CTE response into a multi-section CSV via
     * CsvExporter::exportFromRows(). The `format` field is validated by
     * ReportAsOfRequest (nullable|string|in:csv,json,html).
     */
    public function todaySummaryCte(ReportAsOfRequest $request)
    {
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::today();
        // REPORTS-AUDIT-7 (G-224 / cte-reports.md G12): use resolveBranchScope()
        // so non-admin users cannot bypass branch isolation by passing ?branch_id=
        // (empty) to get NULL (all branches). Admins retain the ability to view
        // any branch or all branches.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->cteReportService->todaySummary($date, $branchId);

        if ($request->input('format') === 'csv') {
            $this->logExport('cte_today_summary', [
                'date' => $report['meta']['date'] ?? '',
                'branch_id' => $branchId,
            ], rowCount: 0, byteSize: 0);

            return $this->cteReportService->exportCsv('today_summary', $report);
        }

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.today_summary_cte', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * AR Aging (CTE) — Proper sub-ledger based aging with GL reconciliation.
     *
     * REPORTS-AUDIT-6 (G-288 / cte-reports.md G15): added `?format=csv`
     * toggle. When set, delegates to CteReportService::exportCsv() which
     * produces a per-customer row CSV with aging buckets + totals +
     * GL reconciliation block.
     */
    public function arAgingCte(ReportAsOfRequest $request)
    {
        $asOf = $this->parseAsOfDate($request);
        // REPORTS-AUDIT-7 (G-224): resolveBranchScope() pins non-admin users to
        // their session branch_id (defense-in-depth against ?branch_id= bypass).
        $branchId = $this->resolveBranchScope($request);

        $report = $this->cteReportService->arAging($asOf, $branchId);

        if ($request->input('format') === 'csv') {
            $this->logExport('cte_ar_aging', [
                'as_of_date' => $report['meta']['as_of_date'] ?? '',
                'branch_id' => $branchId,
            ], rowCount: is_countable($report['data']) ? count($report['data']) : 0, byteSize: 0);

            return $this->cteReportService->exportCsv('ar_aging', $report);
        }

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.ar_aging_cte', array_merge($report, [
            'branches' => $branches,
        ]));
    }

    /**
     * General Ledger (CTE) — With SQL window-function running balance.
     *
     * REPORTS-AUDIT-6 (G-288 / cte-reports.md G15): added `?format=csv`
     * toggle. When set, delegates to CteReportService::exportCsv() which
     * produces a per-journal-line CSV with running balance + totals +
     * checks block.
     */
    public function generalLedgerCte(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        $ledgerId = $request->input('ledger_id') ? (int) $request->input('ledger_id') : null;
        // REPORTS-AUDIT-7 (G-224): resolveBranchScope() pins non-admin users to
        // their session branch_id.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->cteReportService->generalLedger($data['from'], $data['to'], $ledgerId, $branchId);

        if ($request->input('format') === 'csv') {
            $this->logExport('cte_general_ledger', [
                'from_date' => $report['meta']['from_date'] ?? '',
                'to_date' => $report['meta']['to_date'] ?? '',
                'ledger_id' => $ledgerId,
                'branch_id' => $branchId,
            ], rowCount: is_countable($report['data']) ? count($report['data']) : 0, byteSize: 0);

            return $this->cteReportService->exportCsv('general_ledger', $report);
        }

        $ledgers = \App\Models\Ledger::active()->orderBy('ledger_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.reports.general_ledger_cte', array_merge($report, [
            'ledgers' => $ledgers,
            'branches' => $branches,
        ]));
    }

    /**
     * Gross Margin (CTE) — Per-invoice and per-product margin with accurate COGS.
     *
     * REPORTS-AUDIT-6 (G-288 / cte-reports.md G15): added `?format=csv`
     * toggle. When set, delegates to CteReportService::exportCsv() which
     * produces a per-invoice + per-product margin CSV with revenue /
     * COGS / profit / margin %.
     */
    public function grossMarginCte(ReportRangeRequest $request)
    {
        $data = $this->parseDateRange($request);
        // REPORTS-AUDIT-7 (G-224): resolveBranchScope() pins non-admin users to
        // their session branch_id.
        $branchId = $this->resolveBranchScope($request);

        $report = $this->cteReportService->grossMargin($data['from'], $data['to'], $branchId);

        if ($request->input('format') === 'csv') {
            $this->logExport('cte_gross_margin', [
                'from_date' => $report['meta']['from_date'] ?? '',
                'to_date' => $report['meta']['to_date'] ?? '',
                'branch_id' => $branchId,
            ], rowCount: 0, byteSize: 0);

            return $this->cteReportService->exportCsv('gross_margin', $report);
        }

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
    public function damageReport(ReportRangeRequest $request)
    {
        $data     = $this->parseDateRange($request);
        $from     = $data['from']->format('Y-m-d');
        $to       = $data['to']->format('Y-m-d');
        $branchId = $this->resolveBranchScope($request);

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
    public function damageReportExport(ReportRangeRequest $request)
    {
        $data     = $this->parseDateRange($request);
        $from     = $data['from']->format('Y-m-d');
        $to       = $data['to']->format('Y-m-d');
        $branchId = $this->resolveBranchScope($request);

        $filters = [
            'from'       => $from,
            'to'         => $to,
            'branch_id'  => $branchId,
            'warehouse_id'        => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            'damage_type'         => $request->input('damage_type'),
            'status'              => $request->input('status'),
            'accountable_employee_id' => $request->filled('accountable_employee_id') ? (int) $request->input('accountable_employee_id') : null,
        ];

        // LOW-WAVE-2 (G-300 / csv-export.md G27): pass an explicit 10000-row
        // cap for the export path (vs. the 500-row default for the page view).
        // Damage invoices per period are typically tens of rows; 10000 is a
        // safety guard against runaway queries on very wide date ranges, not
        // a meaningful bound on realistic exports. null would disable the cap
        // entirely (use only if a future caller needs true unbounded export).
        $rows = $this->damageReportService->getDetailLines($filters, 10000);

        // Audit log: row count is known precisely (capped at 10000 — silent
        // truncation risk remains only above 10000 rows, which is well beyond
        // any realistic damage report size).
        $this->logExport('damage_report', $filters, rowCount: count($rows), byteSize: 0);

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

        // Apply fiscal year restriction for non-superadmins
        return $this->resolveFiscalScope($request, $from, $to);
    }

    /**
     * Parse as_of_date from request (defaults to today).
     */
    private function parseAsOfDate(Request $request): Carbon
    {
        $asOf = $request->input('as_of_date')
            ? Carbon::parse($request->input('as_of_date'))
            : Carbon::now();

        // Apply fiscal year restriction for non-superadmins
        $user = $request->user();
        if (!$user?->isSuperadmin()) {
            $fiscalYear = \App\Models\FiscalYear::where('is_current', true)
                ->where('status', 'open')
                ->first();
            if ($fiscalYear) {
                $fyEnd = Carbon::parse($fiscalYear->end_date);
                // Cannot query beyond fiscal year end
                if ($asOf->gt($fyEnd)) {
                    $asOf = $fyEnd;
                }
            }
        }

        return $asOf;
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

        // Only superadmin can view any branch (or all branches when null).
        if ($user?->isSuperadmin()) {
            return $requestBranchId;
        }

        // Non-superadmins: pin to session branch_id. If they explicitly
        // requested their own branch, honor it; otherwise force the
        // session branch (defense in depth against crafted requests).
        return $sessionBranchId > 0 ? $sessionBranchId : null;
    }

    /**
     * Apply fiscal year restriction for non-superadmins.
     *
     * Superadmin can query any date range.
     * All other roles are restricted to the current fiscal year.
     * Returns the clamped ['from' => Carbon, 'to' => Carbon] array.
     */
    private function resolveFiscalScope(Request $request, Carbon $from, Carbon $to): array
    {
        $user = $request->user();

        // Superadmin: no fiscal restriction
        if ($user?->isSuperadmin()) {
            return ['from' => $from, 'to' => $to];
        }

        // Find the current fiscal year
        $fiscalYear = \App\Models\FiscalYear::where('is_current', true)
            ->where('status', 'open')
            ->first();

        if (!$fiscalYear) {
            // No active fiscal year found — allow the request as-is
            return ['from' => $from, 'to' => $to];
        }

        $fyStart = Carbon::parse($fiscalYear->start_date);
        $fyEnd = Carbon::parse($fiscalYear->end_date);

        // Clamp the requested range to fiscal year boundaries
        $clampedFrom = $from->lt($fyStart) ? $fyStart : $from;
        $clampedTo = $to->gt($fyEnd) ? $fyEnd : $to;

        return ['from' => $clampedFrom, 'to' => $clampedTo];
    }

    /**
     * Compute sales audit checklist checks.
     *
     * @deprecated HIGH-WAVE-2-A (G-154): superseded by
     *             {@see \App\Services\Sales\SalesAuditService::runHealthChecks()}.
     *             The service expands the 3-section inline checklist into a
     *             12-section report (invoices, challans, returns, payments,
     *             commission, customer ledger, transport, RLS, stale drafts,
     *             GL journal links, audit trail). Retained as a private
     *             fallback for any legacy callers; new code should use the
     *             service directly. Verified 2026-09-08: no external callers
     *             (grep'd `computeSalesAuditChecks` across laravel/app —
     *             only this definition references the name).
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
