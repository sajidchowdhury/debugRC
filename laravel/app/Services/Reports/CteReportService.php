<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
}
