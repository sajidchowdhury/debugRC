<?php

namespace App\Services\Reports;

use App\Facades\CsvExporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CTE Report Service — Phase 1E (Task 32).
 *
 * Executes PostgreSQL CTE-based functions for complex multi-table
 * aggregation queries. These functions replace multiple separate SQL
 * roundtrips or PHP-side computation with single-function calls that
 * return structured JSON.
 *
 * Available CTE functions:
 *   - rcerp_today_summary(branch_id, date) → All dashboard KPIs
 *   - rcerp_ar_aging_cte(as_of_date, branch_id) → AR aging + GL check
 *   - rcerp_general_ledger_cte(from, to, ledger_id, branch_id) → GL with running balance
 *   - rcerp_gross_margin_cte(from, to, branch_id) → Margin analysis
 *
 * Each method returns an array with:
 *   - 'meta': report metadata (title, dates, filters, source)
 *   - 'data': the report rows (decoded from JSON)
 *   - 'totals': aggregate totals
 *   - 'checks': integrity checks
 *
 * REPORTS-AUDIT-6 (G-288 / cte-reports.md G15): added exportCsv()
 * method that flattens the structured CTE response into a multi-section
 * CSV via CsvExporter::exportFromRows() with `prepend_rows` (title +
 * period + currency) + per-report row generator. The 4 CTE controller
 * methods accept a `?format=csv` query toggle that delegates to this
 * method.
 */
class CteReportService
{
    /**
     * Today's Summary — All dashboard KPIs in a single CTE query.
     *
     * Replaces DashboardController::getRevenueKPIs() which made 6+
     * separate SQL queries. Returns:
     *   - Today's invoices, revenue, due
     *   - MTD invoices, revenue, collection, due, collection rate
     *   - All-time outstanding
     *   - Revenue growth vs previous month
     *   - Pending godown/challan counts
     *   - Top 5 customers, top 5 products (MTD)
     *   - AR aging buckets
     *   - Branch revenue comparison
     *
     * @param Carbon|null $date      Report date (default: today)
     * @param int|null    $branchId  Branch filter (null = all branches)
     * @return array
     */
    public function todaySummary(?Carbon $date = null, ?int $branchId = null): array
    {
        $date ??= Carbon::today();

        try {
            $result = DB::selectOne(
                "SELECT rcerp_today_summary(?, ?) AS result",
                [$branchId, $date->toDateString()]
            );

            if (!$result || !$result->result) {
                return $this->emptyTodaySummary($date, $branchId);
            }

            $data = json_decode($result->result, true);

            return [
                'meta' => [
                    'title' => "Today's Summary (CTE)",
                    'date' => $date->toDateString(),
                    'branch_id' => $branchId,
                    'source' => 'cte_function',
                ],
                'today' => $data['today'] ?? [],
                'mtd' => $data['mtd'] ?? [],
                'outstanding' => $data['outstanding'] ?? [],
                'growth' => $data['growth'] ?? [],
                'pending' => $data['pending'] ?? [],
                'top_customers' => $data['top_customers'] ?? [],
                'top_products' => $data['top_products'] ?? [],
                'ar_aging' => $data['ar_aging'] ?? [],
                'branch_revenue' => $data['branch_revenue'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('CTE Report: todaySummary failed', [
                'error' => $e->getMessage(),
                'date' => $date->toDateString(),
                'branch_id' => $branchId,
            ]);
            return $this->emptyTodaySummary($date, $branchId);
        }
    }

    /**
     * AR Aging (CTE) — Proper sub-ledger based aging with GL reconciliation.
     *
     * Replaces the dual-path approach in ReportService::receivableAging()
     * (materialized view for today, direct SQL for historical). The CTE
     * function always does a proper sub-ledger query regardless of date.
     *
     * Returns:
     *   - Per-customer aging buckets (0-30, 31-60, 61-90, 90+)
     *   - GL AR control account balance
     *   - Reconciliation check (sub-ledger vs GL)
     *   - Top 20 overdue invoices
     *   - Aging breakdown by branch
     *
     * Canonical AR aging per G-139/G-142 (REPORTS-AUDIT-2). The non-CTE
     * ReportService::receivableAging is kept as a deprecation-policy
     * fallback for the MV-accelerated today's-aging path.
     *
     * @param Carbon   $asOfDate  As-of date for aging calculation
     * @param int|null $branchId  Branch filter
     * @return array
     *
     * @see \App\Services\Reports\ReportService::receivableAging()
     */
    public function arAging(Carbon $asOfDate, ?int $branchId = null): array
    {
        try {
            $result = DB::selectOne(
                "SELECT rcerp_ar_aging_cte(?, ?) AS result",
                [$asOfDate->toDateString(), $branchId]
            );

            if (!$result || !$result->result) {
                return $this->emptyArAging($asOfDate, $branchId);
            }

            $data = json_decode($result->result, true);

            return [
                'meta' => $data['meta'] ?? [
                    'title' => 'Receivable Aging (CTE)',
                    'as_of_date' => $asOfDate->toDateString(),
                    'branch_id' => $branchId,
                    'source' => 'cte_function',
                ],
                'data' => collect($data['customers'] ?? []),
                'totals' => $data['totals'] ?? [],
                'checks' => $data['checks'] ?? [],
                'overdue_invoices' => collect($data['overdue_invoices'] ?? []),
                'aging_by_branch' => collect($data['aging_by_branch'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('CTE Report: arAging failed', [
                'error' => $e->getMessage(),
                'as_of_date' => $asOfDate->toDateString(),
            ]);
            return $this->emptyArAging($asOfDate, $branchId);
        }
    }

    /**
     * General Ledger (CTE) — With SQL window-function running balance.
     *
     * Replaces the PHP-side running balance computation in
     * ReportService::generalLedger() which iterated over rows in a
     * PHP loop. The CTE uses SUM() OVER (PARTITION BY ... ORDER BY ...)
     * for the running balance, computed entirely in SQL.
     *
     * Returns:
     *   - Journal lines with running balance per ledger
     *   - Opening/closing balances per ledger
     *   - Total debit/credit
     *   - Balance check (Dr = Cr)
     *
     * Canonical General Ledger per G-147/G-149 (REPORTS-AUDIT-2). The
     * non-CTE ReportService::generalLedger is kept as a deprecation-policy
     * fallback when the CTE function is unavailable.
     *
     * @param Carbon   $fromDate
     * @param Carbon   $toDate
     * @param int|null $ledgerId
     * @param int|null $branchId
     * @return array
     *
     * @see \App\Services\Reports\ReportService::generalLedger()
     */
    public function generalLedger(
        Carbon $fromDate,
        Carbon $toDate,
        ?int $ledgerId = null,
        ?int $branchId = null
    ): array {
        try {
            $result = DB::selectOne(
                "SELECT rcerp_general_ledger_cte(?, ?, ?, ?) AS result",
                [$fromDate->toDateString(), $toDate->toDateString(), $ledgerId, $branchId]
            );

            if (!$result || !$result->result) {
                return $this->emptyGeneralLedger($fromDate, $toDate, $ledgerId, $branchId);
            }

            $data = json_decode($result->result, true);

            return [
                'meta' => $data['meta'] ?? [
                    'title' => 'General Ledger (CTE)',
                    'from_date' => $fromDate->toDateString(),
                    'to_date' => $toDate->toDateString(),
                    'ledger_id' => $ledgerId,
                    'branch_id' => $branchId,
                    'source' => 'cte_window_function',
                ],
                'data' => collect($data['entries'] ?? []),
                'ledger_summary' => collect($data['ledger_summary'] ?? []),
                'totals' => $data['totals'] ?? [],
                'checks' => $data['checks'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('CTE Report: generalLedger failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->emptyGeneralLedger($fromDate, $toDate, $ledgerId, $branchId);
        }
    }

    /**
     * Gross Margin (CTE) — Per-invoice and per-product margin analysis.
     *
     * Replaces the simplified ReportController::grossMargin() which
     * used a single challan's issue_cost. The CTE version joins
     * invoice items → challan items → stock transactions for accurate
     * per-product COGS, and adds a per-product margin summary.
     *
     * Returns:
     *   - Per-invoice margin (revenue, COGS, gross profit, margin%)
     *   - Per-product margin summary
     *   - Grand totals with overall margin%
     *
     * Canonical Gross Margin per G-143/G-146 (REPORTS-AUDIT-2). The
     * non-CTE ReportController::grossMargin is retained as a 301
     * redirect-only route to this CTE route.
     *
     * @param Carbon   $fromDate
     * @param Carbon   $toDate
     * @param int|null $branchId
     * @return array
     *
     * @see \App\Http\Controllers\Admin\ReportController::grossMargin()
     */
    public function grossMargin(
        Carbon $fromDate,
        Carbon $toDate,
        ?int $branchId = null
    ): array {
        try {
            $result = DB::selectOne(
                "SELECT rcerp_gross_margin_cte(?, ?, ?) AS result",
                [$fromDate->toDateString(), $toDate->toDateString(), $branchId]
            );

            if (!$result || !$result->result) {
                return $this->emptyGrossMargin($fromDate, $toDate, $branchId);
            }

            $data = json_decode($result->result, true);

            return [
                'meta' => $data['meta'] ?? [
                    'title' => 'Gross Margin Analysis (CTE)',
                    'from_date' => $fromDate->toDateString(),
                    'to_date' => $toDate->toDateString(),
                    'branch_id' => $branchId,
                    'source' => 'cte_function',
                ],
                'invoice_margin' => collect($data['invoice_margin'] ?? []),
                'product_margin' => collect($data['product_margin'] ?? []),
                'totals' => $data['totals'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('CTE Report: grossMargin failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->emptyGrossMargin($fromDate, $toDate, $branchId);
        }
    }

    // ============================================================
    // Empty fallbacks (when CTE function fails or tables don't exist)
    // ============================================================

    private function emptyTodaySummary(Carbon $date, ?int $branchId): array
    {
        return [
            'meta' => ['title' => "Today's Summary", 'date' => $date->toDateString(), 'branch_id' => $branchId, 'source' => 'fallback'],
            'today' => ['invoice_count' => 0, 'total_sales' => 0, 'total_due' => 0],
            'mtd' => ['invoice_count' => 0, 'total_sales' => 0, 'total_due' => 0, 'total_collection' => 0, 'collection_rate' => 0],
            'outstanding' => ['total_outstanding' => 0],
            'growth' => ['prev_month_sales' => 0, 'revenue_growth_pct' => 0],
            'pending' => ['pending_godown' => 0, 'pending_challan' => 0, 'draft_count' => 0],
            'top_customers' => [], 'top_products' => [],
            'ar_aging' => ['bucket_0_30' => 0, 'bucket_31_60' => 0, 'bucket_61_90' => 0, 'bucket_90_plus' => 0],
            'branch_revenue' => [],
        ];
    }

    private function emptyArAging(Carbon $asOfDate, ?int $branchId): array
    {
        return [
            'meta' => ['title' => 'Receivable Aging', 'as_of_date' => $asOfDate->toDateString(), 'branch_id' => $branchId, 'source' => 'fallback'],
            'data' => collect(), 'totals' => [], 'checks' => [],
            'overdue_invoices' => collect(), 'aging_by_branch' => collect(),
        ];
    }

    private function emptyGeneralLedger(Carbon $from, Carbon $to, ?int $ledgerId, ?int $branchId): array
    {
        return [
            'meta' => ['title' => 'General Ledger', 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'source' => 'fallback'],
            'data' => collect(), 'ledger_summary' => collect(), 'totals' => [], 'checks' => [],
        ];
    }

    private function emptyGrossMargin(Carbon $from, Carbon $to, ?int $branchId): array
    {
        return [
            'meta' => ['title' => 'Gross Margin', 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'source' => 'fallback'],
            'invoice_margin' => collect(), 'product_margin' => collect(), 'totals' => [],
        ];
    }

    // ============================================================
    // CSV Export — REPORTS-AUDIT-6 (G-288 / cte-reports.md G15)
    // ============================================================

    /**
     * Flatten the structured CTE response into a multi-section CSV.
     *
     * Each CTE report returns a structured array with `meta` / `data` /
     * `totals` / `checks` (and report-specific extras like `today` /
     * `mtd` / `outstanding` for today_summary). This method dispatches
     * to a per-report flattener that yields rows in a CSV-friendly
     * multi-section layout (title + period + currency → per-section
     * headers + data rows → totals/checks).
     *
     * The CSV is produced via CsvExporter::exportFromRows() with
     * `prepend_rows` (title block) + the per-report row generator +
     * `append_rows` (totals/checks block where applicable). BOM +
     * Content-Type + RFC 4180 escaping handled by the canonical
     * service. The audit-log row is written by the calling controller
     * (the service does not have access to the request filter context).
     *
     * Supported $reportName values:
     *   - 'today_summary'  → todaySummary() response
     *   - 'ar_aging'       → arAging() response
     *   - 'general_ledger' → generalLedger() response
     *   - 'gross_margin'   → grossMargin() response
     *
     * @param  string $reportName One of the 4 supported names above.
     * @param  array  $data       The structured CTE response array.
     * @return StreamedResponse
     *
     * @throws \InvalidArgumentException When $reportName is not recognized.
     */
    public function exportCsv(string $reportName, array $data): StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        switch ($reportName) {
            case 'today_summary':
                return $this->exportTodaySummaryCsv($data, $currency);

            case 'ar_aging':
                return $this->exportArAgingCsv($data, $currency);

            case 'general_ledger':
                return $this->exportGeneralLedgerCsv($data, $currency);

            case 'gross_margin':
                return $this->exportGrossMarginCsv($data, $currency);

            default:
                throw new \InvalidArgumentException(
                    "CteReportService::exportCsv: unknown report name [{$reportName}]. "
                    . 'Supported: today_summary, ar_aging, general_ledger, gross_margin.'
                );
        }
    }

    /**
     * Flatten today_summary into a CSV (1 row per KPI).
     *
     * Layout: title + date + currency → KPI section header + per-KPI
     * (label, value, currency) rows for each sub-section (today / mtd /
     * outstanding / growth / pending / ar_aging) → top_customers +
     * top_products + branch_revenue data rows.
     */
    private function exportTodaySummaryCsv(array $data, string $currency): StreamedResponse
    {
        $meta = $data['meta'] ?? [];

        $prependRows = [
            ["Today's Summary (CTE)"],
            ['Date', $meta['date'] ?? ''],
            ['Branch ID', $meta['branch_id'] ?? 'all'],
            ['Currency', $currency],
            [],
        ];

        $rowGenerator = function () use ($data, $currency): \Generator {
            $sections = [
                'today'       => 'TODAY',
                'mtd'         => 'MONTH-TO-DATE',
                'outstanding' => 'OUTSTANDING',
                'growth'      => 'GROWTH vs LAST MONTH',
                'pending'     => 'PENDING',
                'ar_aging'    => 'AR AGING BUCKETS',
            ];

            foreach ($sections as $key => $label) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    continue;
                }
                yield [$label, 'Value', $currency];
                foreach ($data[$key] as $k => $v) {
                    $val = is_scalar($v) ? (string) $v : json_encode($v);
                    yield [(string) $k, $val, $currency];
                }
                yield [];
            }

            // Top customers.
            if (!empty($data['top_customers'])) {
                yield ['TOP CUSTOMERS'];
                yield ['Customer ID', 'Customer Name', 'Total Sales', $currency];
                foreach ($data['top_customers'] as $c) {
                    yield [
                        $c['customer_id'] ?? $c['id'] ?? '',
                        $c['customer_name'] ?? $c['name'] ?? '',
                        number_format((float) ($c['total_sales'] ?? $c['sales'] ?? 0), 2, '.', ''),
                        $currency,
                    ];
                }
                yield [];
            }

            // Top products.
            if (!empty($data['top_products'])) {
                yield ['TOP PRODUCTS'];
                yield ['Product ID', 'Product Name', 'Total Qty', 'Total Value', $currency];
                foreach ($data['top_products'] as $p) {
                    yield [
                        $p['product_id'] ?? $p['id'] ?? '',
                        $p['product_name'] ?? $p['name'] ?? '',
                        number_format((float) ($p['total_qty'] ?? $p['qty'] ?? 0), 2, '.', ''),
                        number_format((float) ($p['total_value'] ?? $p['value'] ?? 0), 2, '.', ''),
                        $currency,
                    ];
                }
                yield [];
            }

            // Branch revenue.
            if (!empty($data['branch_revenue'])) {
                yield ['BRANCH REVENUE'];
                yield ['Branch ID', 'Branch Name', 'Revenue', $currency];
                foreach ($data['branch_revenue'] as $b) {
                    yield [
                        $b['branch_id'] ?? $b['id'] ?? '',
                        $b['branch_name'] ?? $b['name'] ?? '',
                        number_format((float) ($b['revenue'] ?? 0), 2, '.', ''),
                        $currency,
                    ];
                }
            }
        };

        $filename = CsvExporter::filename('Today_Summary_CTE', [$meta['date'] ?? 'today']);

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
        ]);
    }

    /**
     * Flatten ar_aging into a CSV (1 row per customer with aging buckets).
     *
     * Layout: title + as-of + currency → header row (Customer Code /
     * Customer Name / 4 aging buckets / Total / Currency) → per-customer
     * rows → totals + GL reconciliation block.
     */
    private function exportArAgingCsv(array $data, string $currency): StreamedResponse
    {
        $meta = $data['meta'] ?? [];

        $prependRows = [
            ['Receivable Aging (CTE)'],
            ['As of', $meta['as_of_date'] ?? ''],
            ['Branch ID', $meta['branch_id'] ?? 'all'],
            ['Currency', $currency],
            [],
        ];

        $headerRow = [
            'Customer ID', 'Customer Code', 'Customer Name',
            'Bucket 0-30', 'Bucket 31-60', 'Bucket 61-90', 'Bucket 90+',
            'Total Receivable', 'Currency',
        ];

        $rowGenerator = function () use ($data, $currency): \Generator {
            foreach ($data['data'] ?? [] as $row) {
                $r = is_array($row) ? (object) $row : $row;
                yield [
                    $r->customer_id ?? '',
                    $r->customer_code ?? '',
                    $r->customer_name ?? '',
                    number_format((float) ($r->bucket_0_30 ?? 0), 2, '.', ''),
                    number_format((float) ($r->bucket_31_60 ?? 0), 2, '.', ''),
                    number_format((float) ($r->bucket_61_90 ?? 0), 2, '.', ''),
                    number_format((float) ($r->bucket_90_plus ?? 0), 2, '.', ''),
                    number_format((float) ($r->total_receivable ?? 0), 2, '.', ''),
                    $currency,
                ];
            }
        };

        $appendRows = $this->buildArAgingAppendRows($data, $currency);

        $filename = CsvExporter::filename('AR_Aging_CTE', [$meta['as_of_date'] ?? 'today']);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows'  => $appendRows,
        ]);
    }

    /**
     * Build the append-rows block for the AR aging CSV (totals + checks).
     */
    private function buildArAgingAppendRows(array $data, string $currency): array
    {
        $t = $data['totals'] ?? [];
        $c = $data['checks'] ?? [];
        $rows = [];

        if (!empty($t)) {
            $rows[] = [];
            $rows[] = [
                'GRAND TOTALS', '', '',
                number_format((float) ($t['bucket_0_30'] ?? 0), 2, '.', ''),
                number_format((float) ($t['bucket_31_60'] ?? 0), 2, '.', ''),
                number_format((float) ($t['bucket_61_90'] ?? 0), 2, '.', ''),
                number_format((float) ($t['bucket_90_plus'] ?? 0), 2, '.', ''),
                number_format((float) ($t['total_receivable'] ?? 0), 2, '.', ''),
                $currency,
            ];
        }

        if (!empty($c)) {
            $rows[] = [];
            $rows[] = ['CHECKS'];
            foreach ($c as $k => $v) {
                $val = is_bool($v) ? ($v ? 'YES' : 'NO') : (is_scalar($v) ? (string) $v : json_encode($v));
                $rows[] = [(string) $k, $val];
            }
        }

        return $rows;
    }

    /**
     * Flatten general_ledger into a CSV (1 row per journal line with
     * running balance).
     *
     * Layout: title + period + currency → header row (Entry Date / JE
     * Code / Ledger / Description / Debit / Credit / Running Balance /
     * Currency) → per-line rows → totals + checks block.
     */
    private function exportGeneralLedgerCsv(array $data, string $currency): StreamedResponse
    {
        $meta = $data['meta'] ?? [];

        $prependRows = [
            ['General Ledger (CTE)'],
            ['Period', ($meta['from_date'] ?? '') . ' to ' . ($meta['to_date'] ?? '')],
            ['Ledger ID', $meta['ledger_id'] ?? 'all'],
            ['Branch ID', $meta['branch_id'] ?? 'all'],
            ['Currency', $currency],
            [],
        ];

        $headerRow = [
            'Entry Date', 'JE Code', 'Ledger Code', 'Ledger Name',
            'Description', 'Debit', 'Credit', 'Running Balance', 'Currency',
        ];

        $rowGenerator = function () use ($data, $currency): \Generator {
            foreach ($data['data'] ?? [] as $row) {
                $r = is_array($row) ? (object) $row : $row;
                yield [
                    $r->entry_date ?? '',
                    $r->je_code ?? $r->journal_code ?? '',
                    $r->ledger_code ?? '',
                    $r->ledger_name ?? '',
                    $r->description ?? '',
                    number_format((float) ($r->debit ?? 0), 2, '.', ''),
                    number_format((float) ($r->credit ?? 0), 2, '.', ''),
                    number_format((float) ($r->running_balance ?? 0), 2, '.', ''),
                    $currency,
                ];
            }
        };

        $appendRows = $this->buildGeneralLedgerAppendRows($data, $currency);

        $filename = CsvExporter::filename(
            'General_Ledger_CTE',
            [$meta['from_date'] ?? 'all', 'to', $meta['to_date'] ?? 'all']
        );

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows'  => $appendRows,
        ]);
    }

    /**
     * Build the append-rows block for the GL CSV (totals + checks).
     */
    private function buildGeneralLedgerAppendRows(array $data, string $currency): array
    {
        $t = $data['totals'] ?? [];
        $c = $data['checks'] ?? [];
        $rows = [];

        if (!empty($t)) {
            $rows[] = [];
            $rows[] = ['GRAND TOTALS'];
            foreach ($t as $k => $v) {
                $val = is_scalar($v) ? (string) $v : json_encode($v);
                $rows[] = [(string) $k, $val, $currency];
            }
        }

        if (!empty($c)) {
            $rows[] = [];
            $rows[] = ['CHECKS'];
            foreach ($c as $k => $v) {
                $val = is_bool($v) ? ($v ? 'YES' : 'NO') : (is_scalar($v) ? (string) $v : json_encode($v));
                $rows[] = [(string) $k, $val];
            }
        }

        return $rows;
    }

    /**
     * Flatten gross_margin into a CSV (1 row per invoice with revenue /
     * COGS / margin).
     *
     * Layout: title + period + currency → INVOICE MARGIN section header
     * + per-invoice rows → PRODUCT MARGIN section + per-product rows →
     * totals block.
     */
    private function exportGrossMarginCsv(array $data, string $currency): StreamedResponse
    {
        $meta = $data['meta'] ?? [];

        $prependRows = [
            ['Gross Margin Analysis (CTE)'],
            ['Period', ($meta['from_date'] ?? '') . ' to ' . ($meta['to_date'] ?? '')],
            ['Branch ID', $meta['branch_id'] ?? 'all'],
            ['Currency', $currency],
            [],
        ];

        $rowGenerator = function () use ($data, $currency): \Generator {
            // Invoice-level margin.
            yield ['INVOICE MARGIN'];
            yield [
                'Invoice ID', 'Invoice Code', 'Invoice Date',
                'Revenue', 'COGS', 'Gross Profit', 'Margin %', $currency,
            ];
            foreach ($data['invoice_margin'] ?? [] as $row) {
                $r = is_array($row) ? (object) $row : $row;
                $revenue = (float) ($r->revenue ?? 0);
                $cogs    = (float) ($r->cogs ?? 0);
                $profit  = (float) ($r->gross_profit ?? ($revenue - $cogs));
                $pct     = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                yield [
                    $r->invoice_id ?? $r->id ?? '',
                    $r->invoice_code ?? '',
                    $r->invoice_date ?? '',
                    number_format($revenue, 2, '.', ''),
                    number_format($cogs, 2, '.', ''),
                    number_format($profit, 2, '.', ''),
                    number_format($pct, 1, '.', '') . '%',
                    $currency,
                ];
            }
            yield [];

            // Product-level margin.
            yield ['PRODUCT MARGIN'];
            yield [
                'Product ID', 'Product Code', 'Product Name',
                'Revenue', 'COGS', 'Gross Profit', 'Margin %', $currency,
            ];
            foreach ($data['product_margin'] ?? [] as $row) {
                $r = is_array($row) ? (object) $row : $row;
                $revenue = (float) ($r->revenue ?? 0);
                $cogs    = (float) ($r->cogs ?? 0);
                $profit  = (float) ($r->gross_profit ?? ($revenue - $cogs));
                $pct     = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                yield [
                    $r->product_id ?? $r->id ?? '',
                    $r->product_code ?? '',
                    $r->product_name ?? '',
                    number_format($revenue, 2, '.', ''),
                    number_format($cogs, 2, '.', ''),
                    number_format($profit, 2, '.', ''),
                    number_format($pct, 1, '.', '') . '%',
                    $currency,
                ];
            }
        };

        $appendRows = $this->buildGrossMarginAppendRows($data, $currency);

        $filename = CsvExporter::filename(
            'Gross_Margin_CTE',
            [$meta['from_date'] ?? 'all', 'to', $meta['to_date'] ?? 'all']
        );

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
            'append_rows'  => $appendRows,
        ]);
    }

    /**
     * Build the append-rows block for the gross margin CSV (totals).
     */
    private function buildGrossMarginAppendRows(array $data, string $currency): array
    {
        $t = $data['totals'] ?? [];
        if (empty($t)) {
            return [];
        }

        $rows = [
            [],
            ['GRAND TOTALS'],
        ];
        foreach ($t as $k => $v) {
            $val = is_scalar($v) ? (string) $v : json_encode($v);
            $rows[] = [(string) $k, $val, $currency];
        }

        return $rows;
    }
}
