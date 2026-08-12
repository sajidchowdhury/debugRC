<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Performance Dashboard Controller.
 *
 * Full 360° customer analytics: CLV, churn risk, segmentation,
 * revenue distribution, AOV, retention, and lifetime metrics.
 *
 * Follows the SalesFunnelController pattern — index() orchestrates
 * multiple private data methods, each with try/catch for graceful
 * empty-table fallback.
 *
 * Business Logic (ported from legacy ReportController):
 *   - CLV = AOV × purchase_frequency × 3  (3-year multiplier)
 *   - Churn risk = days_since_last_order → percentage → category
 *   - Segments: High Value (top 20% revenue), Loyal (repeat > 3),
 *     At Risk (churn > 60%), New (first order < 90 days)
 *   - Retention = active / (active + lost) × 100
 */
class CustomerPerformanceController extends Controller
{
    /**
     * Main dashboard view.
     */
    public function index(ReportRangeRequest $request)
    {
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $salesmanId = $request->input('salesman_id') ? (int) $request->input('salesman_id') : null;

        // Default 365-day range (matching legacy), not MTD
        $from = $request->input('from_date')
            ? \Carbon\Carbon::parse($request->input('from_date'))
            : \Carbon\Carbon::now()->subDays(365);
        $to = $request->input('to_date')
            ? \Carbon\Carbon::parse($request->input('to_date'))
            : \Carbon\Carbon::now();

        // ============================================================
        // 1. KPI cards
        // ============================================================
        $kpis = $this->getKPIs($branchId, $salesmanId, $from, $to);

        // ============================================================
        // 2. Customer segmentation (pie/donut chart data)
        // ============================================================
        $segmentation = $this->getSegmentation($branchId, $salesmanId, $from, $to);

        // ============================================================
        // 3. Churn distribution (donut chart data)
        // ============================================================
        $churnDist = $this->getChurnDistribution($branchId, $salesmanId, $from, $to);

        // ============================================================
        // 4. Top customers (bar chart + table)
        // ============================================================
        $topCustomers = $this->getTopCustomers(15, $branchId, $salesmanId, $from, $to);

        // ============================================================
        // 5. CLV trend (6-month line chart)
        // ============================================================
        $clvTrend = $this->getCLVTrend($branchId);

        // ============================================================
        // 6. Revenue by segment (horizontal bar)
        // ============================================================
        $revenueBySegment = $this->getRevenueBySegment($branchId, $salesmanId, $from, $to);

        // ============================================================
        // 7. Customer detail table (full list with CLV + churn)
        // ============================================================
        $customerTable = $this->getCustomerTable($branchId, $salesmanId, $from, $to);

        // Filter options
        $branches = DB::table('branches')->whereNull('deleted_at')->orderBy('branch_name')->get();
        $salesmen = DB::table('employees')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get();

        return view('admin.reports.customer_performance', [
            'meta' => [
                'title' => 'Customer Performance',
                'from_date' => $from->format('Y-m-d'),
                'to_date' => $to->format('Y-m-d'),
            ],
            'kpis' => $kpis,
            'segmentation' => $segmentation,
            'churnDist' => $churnDist,
            'topCustomers' => $topCustomers,
            'clvTrend' => $clvTrend,
            'revenueBySegment' => $revenueBySegment,
            'customerTable' => $customerTable,
            'branches' => $branches,
            'salesmen' => $salesmen,
            'selectedBranch' => $branchId,
            'selectedSalesman' => $salesmanId,
        ]);
    }

    // ============================================================
    // DATA METHODS
    // ============================================================

    /**
     * KPI cards: active customers, avg CLV, churn %, repeat rate, AOV, etc.
     */
    private function getKPIs(?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled','draft')";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $salesmanFilter = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $params[] = $branchId;
            if ($salesmanId) $params[] = $salesmanId;

            // Active customers (invoiced in period)
            $activeStats = DB::selectOne("
                SELECT
                    COUNT(DISTINCT si.customer_id) AS active_customers,
                    COALESCE(SUM(si.total_amount), 0) AS total_revenue,
                    COALESCE(AVG(si.total_amount), 0) AS aov,
                    COUNT(*) AS total_invoices
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter $salesmanFilter
            ", $params);

            $activeCustomers = (int) ($activeStats->active_customers ?? 0);
            $totalRevenue = (float) ($activeStats->total_revenue ?? 0);
            $aov = $activeCustomers > 0 ? round($totalRevenue / (int) ($activeStats->total_invoices ?? 1), 2) : 0;

            // Repeat customers (2+ invoices in period)
            $repeatStats = DB::selectOne("
                SELECT COUNT(*) AS repeat_customers FROM (
                    SELECT si.customer_id
                    FROM sales_invoices si
                    WHERE $baseWhere $branchFilter $salesmanFilter
                    GROUP BY si.customer_id
                    HAVING COUNT(*) >= 2
                ) sub
            ", $params);
            $repeatCustomers = (int) ($repeatStats->repeat_customers ?? 0);
            $repeatRate = $activeCustomers > 0 ? round(($repeatCustomers / $activeCustomers) * 100, 1) : 0;

            // Purchase frequency (avg invoices per active customer)
            $purchaseFreq = $activeCustomers > 0
                ? round((int) ($activeStats->total_invoices ?? 0) / $activeCustomers, 1)
                : 0;

            // Average CLV = AOV × frequency × 3 (3-year multiplier, same as legacy)
            $avgClv = round($aov * $purchaseFreq * 3, 0);

            // Lost customers (had invoice before period but none in period)
            $prevStart = (clone $from)->subYear()->toDateString();
            $prevEnd = $from->toDateString();
            $prePeriodParams = [$prevStart, $prevEnd];
            $prePeriodBranch = $branchId ? "AND si.branch_id = ?" : "";
            $prePeriodSalesman = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $prePeriodParams[] = $branchId;
            if ($salesmanId) $prePeriodParams[] = $salesmanId;

            $beforePeriod = DB::selectOne("
                SELECT COUNT(DISTINCT si.customer_id) AS cnt
                FROM sales_invoices si
                WHERE si.invoice_date BETWEEN ? AND ?
                    AND si.is_reversed = false AND si.deleted_at IS NULL
                    AND si.status NOT IN ('cancelled','draft')
                    $prePeriodBranch $prePeriodSalesman
            ", $prePeriodParams);
            $beforeCount = (int) ($beforePeriod->cnt ?? 0);

            // Customers in prior period but NOT in current period = lost
            $lostCustomers = max(0, $beforeCount - $activeCustomers);

            // Overall churn rate
            $totalPool = $beforeCount + $activeCustomers;
            $overallChurn = $totalPool > 0 ? round(($lostCustomers / $totalPool) * 100, 1) : 0;

            // Retention rate
            $retentionRate = $totalPool > 0 ? round((($totalPool - $lostCustomers) / $totalPool) * 100, 1) : 0;

            return [
                'active_customers'  => $activeCustomers,
                'total_revenue'     => $totalRevenue,
                'aov'               => $aov,
                'repeat_rate'       => $repeatRate,
                'repeat_customers'  => $repeatCustomers,
                'purchase_freq'     => $purchaseFreq,
                'avg_clv'           => $avgClv,
                'overall_churn'     => $overallChurn,
                'retention_rate'    => $retentionRate,
                'lost_customers'    => $lostCustomers,
            ];
        } catch (\Throwable $e) {
            return [
                'active_customers' => 0, 'total_revenue' => 0, 'aov' => 0,
                'repeat_rate' => 0, 'repeat_customers' => 0, 'purchase_freq' => 0,
                'avg_clv' => 0, 'overall_churn' => 0, 'retention_rate' => 0,
                'lost_customers' => 0,
            ];
        }
    }

    /**
     * Customer segmentation: High Value, Loyal, At Risk, New.
     */
    private function getSegmentation(?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled','draft')";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $salesmanFilter = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $params[] = $branchId;
            if ($salesmanId) $params[] = $salesmanId;

            // Compute per-customer metrics
            $rows = DB::select("
                SELECT
                    si.customer_id,
                    COALESCE(SUM(si.total_amount), 0) AS period_revenue,
                    COUNT(*) AS invoice_count,
                    MAX(si.invoice_date) AS last_order,
                    MIN(si.invoice_date) AS first_order
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter $salesmanFilter
                GROUP BY si.customer_id
            ", $params);

            $segments = [
                'High Value' => 0,
                'Loyal'      => 0,
                'At Risk'    => 0,
                'New'        => 0,
            ];

            $revenueBySegment = [
                'High Value' => 0,
                'Loyal'      => 0,
                'At Risk'    => 0,
                'New'        => 0,
            ];

            // Sort by revenue to find top 20%
            $sorted = collect($rows)->sortByDesc('period_revenue');
            $top20Count = max(1, (int) round($sorted->count() * 0.2));
            $top20Ids = $sorted->take($top20Count)->pluck('customer_id')->toArray();

            foreach ($rows as $r) {
                $daysSinceLast = now()->diffInDays(\Carbon\Carbon::parse($r->last_order));
                $daysSinceFirst = now()->diffInDays(\Carbon\Carbon::parse($r->first_order));
                $churnPct = min(100, $daysSinceLast * 2); // Simplified: ~50 days = 100%

                if (in_array($r->customer_id, $top20Ids)) {
                    $segments['High Value']++;
                    $revenueBySegment['High Value'] += (float) $r->period_revenue;
                } elseif ($r->invoice_count >= 4) {
                    $segments['Loyal']++;
                    $revenueBySegment['Loyal'] += (float) $r->period_revenue;
                } elseif ($churnPct > 60) {
                    $segments['At Risk']++;
                    $revenueBySegment['At Risk'] += (float) $r->period_revenue;
                } elseif ($daysSinceFirst <= 90) {
                    $segments['New']++;
                    $revenueBySegment['New'] += (float) $r->period_revenue;
                } else {
                    // Default: treat remaining as At Risk
                    $segments['At Risk']++;
                    $revenueBySegment['At Risk'] += (float) $r->period_revenue;
                }
            }

            return [
                'counts'  => $segments,
                'revenue' => $revenueBySegment,
            ];
        } catch (\Throwable $e) {
            return [
                'counts'  => ['High Value' => 0, 'Loyal' => 0, 'At Risk' => 0, 'New' => 0],
                'revenue' => ['High Value' => 0, 'Loyal' => 0, 'At Risk' => 0, 'New' => 0],
            ];
        }
    }

    /**
     * Churn distribution: Low, Medium, High buckets.
     */
    private function getChurnDistribution(?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled','draft')";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $salesmanFilter = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $params[] = $branchId;
            if ($salesmanId) $params[] = $salesmanId;

            $rows = DB::select("
                SELECT
                    si.customer_id,
                    MAX(si.invoice_date) AS last_order
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter $salesmanFilter
                GROUP BY si.customer_id
            ", $params);

            $buckets = ['Low' => 0, 'Medium' => 0, 'High' => 0];
            foreach ($rows as $r) {
                $daysSince = now()->diffInDays(\Carbon\Carbon::parse($r->last_order));
                if ($daysSince <= 30) {
                    $buckets['Low']++;
                } elseif ($daysSince <= 90) {
                    $buckets['Medium']++;
                } else {
                    $buckets['High']++;
                }
            }

            return $buckets;
        } catch (\Throwable $e) {
            return ['Low' => 0, 'Medium' => 0, 'High' => 0];
        }
    }

    /**
     * Top N customers with CLV + churn risk.
     */
    private function getTopCustomers(int $limit, ?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled','draft')";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $salesmanFilter = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $params[] = $branchId;
            if ($salesmanId) $params[] = $salesmanId;

            $rows = DB::select("
                SELECT
                    c.id,
                    c.customer_code,
                    c.customer_name,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(si.total_amount), 0) AS period_revenue,
                    COALESCE(SUM(si.paid_amount), 0) AS total_paid,
                    COALESCE(SUM(si.due_amount), 0) AS total_due,
                    AVG(si.total_amount) AS aov,
                    MAX(si.invoice_date) AS last_order,
                    MIN(si.invoice_date) AS first_order,
                    COALESCE(SUM(CASE WHEN si2.lifetime_rev IS NOT NULL THEN si2.lifetime_rev ELSE 0 END), 0) AS lifetime_rev
                FROM customers c
                INNER JOIN sales_invoices si ON si.customer_id = c.id
                LEFT JOIN LATERAL (
                    SELECT SUM(si2.total_amount) AS lifetime_rev
                    FROM sales_invoices si2
                    WHERE si2.customer_id = c.id
                        AND si2.is_reversed = false
                        AND si2.deleted_at IS NULL
                        AND si2.status NOT IN ('cancelled','draft')
                ) si2 ON true
                WHERE $baseWhere $branchFilter $salesmanFilter
                    AND c.deleted_at IS NULL
                GROUP BY c.id, c.customer_code, c.customer_name
                ORDER BY period_revenue DESC
                LIMIT $limit
            ", $params);

            return array_map(fn($r) => [
                'id'             => (int) $r->id,
                'code'           => $r->customer_code,
                'name'           => $r->customer_name,
                'invoices'       => (int) $r->invoice_count,
                'revenue'        => (float) $r->period_revenue,
                'paid'           => (float) $r->total_paid,
                'due'            => (float) $r->total_due,
                'aov'            => round((float) $r->aov, 2),
                'last_order'     => $r->last_order,
                'first_order'    => $r->first_order,
                'lifetime_rev'   => (float) ($r->lifetime_rev ?? 0),
                'clv'            => round((float) $r->aov * (int) $r->invoice_count * 3, 0),
                'churn_risk'     => $this->computeChurnCategory($r->last_order),
                'churn_days'     => now()->diffInDays(\Carbon\Carbon::parse($r->last_order)),
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * CLV trend: monthly average CLV over 6 months.
     */
    private function getCLVTrend(?int $branchId): array
    {
        try {
            $series = [];
            for ($m = 5; $m >= 0; $m--) {
                $monthStart = now()->subMonths($m)->startOfMonth()->toDateString();
                $monthEnd = now()->subMonths($m)->endOfMonth()->toDateString();
                $monthLabel = now()->subMonths($m)->format('M Y');

                $branchFilter = $branchId ? "AND branch_id = ?" : "";
                $params = [$monthStart, $monthEnd];
                if ($branchId) $params[] = $branchId;

                $stats = DB::selectOne("
                    SELECT
                        COUNT(*) AS invoice_count,
                        COUNT(DISTINCT customer_id) AS customer_count,
                        COALESCE(AVG(total_amount), 0) AS avg_aov
                    FROM sales_invoices
                    WHERE invoice_date BETWEEN ? AND ?
                        AND is_reversed = false AND deleted_at IS NULL
                        AND status NOT IN ('cancelled','draft')
                        $branchFilter
                ", $params);

                $invCount = (int) ($stats->invoice_count ?? 0);
                $custCount = (int) ($stats->customer_count ?? 1);
                $avgAov = (float) ($stats->avg_aov ?? 0);
                $freq = $custCount > 0 ? round($invCount / $custCount, 1) : 0;
                $avgClv = round($avgAov * $freq * 3, 0);

                $series[] = [
                    'month'   => $monthLabel,
                    'avg_clv' => $avgClv,
                    'aov'     => round($avgAov, 0),
                    'freq'    => $freq,
                ];
            }
            return $series;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Revenue by segment: for horizontal bar chart.
     */
    private function getRevenueBySegment(?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $seg = $this->getSegmentation($branchId, $salesmanId, $from, $to);
        return $seg['revenue'];
    }

    /**
     * Full customer table with CLV, churn, segment.
     */
    private function getCustomerTable(?int $branchId, ?int $salesmanId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled','draft')";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $salesmanFilter = $salesmanId ? "AND si.salesman_id = ?" : "";
            if ($branchId) $params[] = $branchId;
            if ($salesmanId) $params[] = $salesmanId;

            $rows = DB::select("
                SELECT
                    c.id,
                    c.customer_code,
                    c.customer_name,
                    c.phone,
                    c.mobile,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(si.total_amount), 0) AS period_revenue,
                    COALESCE(SUM(si.paid_amount), 0) AS total_paid,
                    COALESCE(SUM(si.due_amount), 0) AS total_due,
                    AVG(si.total_amount) AS aov,
                    MAX(si.invoice_date) AS last_order,
                    MIN(si.invoice_date) AS first_order
                FROM customers c
                INNER JOIN sales_invoices si ON si.customer_id = c.id
                WHERE $baseWhere $branchFilter $salesmanFilter
                    AND c.deleted_at IS NULL
                GROUP BY c.id, c.customer_code, c.customer_name, c.phone, c.mobile
                HAVING COUNT(*) > 0
                ORDER BY period_revenue DESC
                LIMIT 100
            ", $params);

            // Sort for top 20%
            $sorted = collect($rows)->sortByDesc('period_revenue');
            $top20Count = max(1, (int) round($sorted->count() * 0.2));
            $top20Ids = $sorted->take($top20Count)->pluck('id')->toArray();

            return array_map(fn($r) => [
                'id'           => (int) $r->id,
                'code'         => $r->customer_code,
                'name'         => $r->customer_name,
                'phone'        => $r->phone ?? $r->mobile ?? '—',
                'invoices'     => (int) $r->invoice_count,
                'revenue'      => (float) $r->period_revenue,
                'paid'         => (float) $r->total_paid,
                'due'          => (float) $r->total_due,
                'aov'          => round((float) $r->aov, 2),
                'last_order'   => $r->last_order,
                'first_order'  => $r->first_order,
                'clv'          => round((float) $r->aov * (int) $r->invoice_count * 3, 0),
                'churn_cat'    => $this->computeChurnCategory($r->last_order),
                'churn_days'   => now()->diffInDays(\Carbon\Carbon::parse($r->last_order)),
                'segment'      => $this->computeSegment((int) $r->id, (int) $r->invoice_count, $r->last_order, $r->first_order, $top20Ids),
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compute churn category from last order date.
     * Low: < 30 days, Medium: 31-90 days, High: > 90 days.
     */
    private function computeChurnCategory(?string $lastOrder): string
    {
        if (!$lastOrder) return 'High';
        $days = now()->diffInDays(\Carbon\Carbon::parse($lastOrder));
        if ($days <= 30) return 'Low';
        if ($days <= 90) return 'Medium';
        return 'High';
    }

    /**
     * Compute customer segment.
     */
    private function computeSegment(int $id, int $invoiceCount, ?string $lastOrder, ?string $firstOrder, array $top20Ids): string
    {
        if (in_array($id, $top20Ids)) return 'High Value';
        if ($invoiceCount >= 4) return 'Loyal';
        $daysSinceLast = $lastOrder ? now()->diffInDays(\Carbon\Carbon::parse($lastOrder)) : 999;
        if ($daysSinceLast > 90) return 'At Risk';
        if ($firstOrder && now()->diffInDays(\Carbon\Carbon::parse($firstOrder)) <= 90) return 'New';
        return 'At Risk';
    }
}
