<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Controller — Phase 3 + Revenue Overview.
 *
 * Provides the main dashboard with:
 *   - Basic entity stats (customers, products, invoices, challans)
 *   - Revenue KPIs: today's sales, MTD revenue, collection rate, outstanding
 *   - Sales trend data (last 7/30 days) for Chart.js
 *   - Top customers and top products for mini-tables
 *   - Branch revenue comparison for Chart.js bar chart
 *
 * The Revenue Overview section uses Chart.js (loaded via CDN) to render:
 *   1. Sales Trend line chart (7-day or 30-day toggle)
 *   2. Revenue vs Collection comparison chart
 *   3. Branch revenue breakdown bar chart
 *   4. Customer receivable aging donut chart
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $branchId = session('branch_id');

        // ============================================================
        // Basic stats (best effort — tables may be empty during testing)
        // ============================================================
        $stats = [];
        try {
            $stats['customers'] = DB::table('customers')->whereNull('deleted_at')->count();
            $stats['products'] = DB::table('products')->whereNull('deleted_at')->count();
            $stats['invoices_today'] = DB::table('sales_invoices')
                ->where('invoice_date', today())
                ->whereNull('deleted_at')
                ->count();
            $stats['pending_challans'] = DB::table('sales_invoices')
                ->where('is_godown_prepared', false)
                ->where('status', 'confirmed')
                ->count();
        } catch (\Throwable $e) {
            // Tables may not exist yet during early testing.
        }

        // ============================================================
        // Revenue KPIs
        // ============================================================
        $kpis = $this->getRevenueKPIs();

        // ============================================================
        // Chart data: sales trend (last 30 days)
        // ============================================================
        $salesTrend = $this->getSalesTrend(30);

        // ============================================================
        // Chart data: branch revenue comparison (MTD)
        // ============================================================
        $branchRevenue = $this->getBranchRevenue();

        // ============================================================
        // Chart data: receivable aging buckets
        // ============================================================
        $agingData = $this->getReceivableAging();

        // ============================================================
        // Top 5 customers by MTD revenue
        // ============================================================
        $topCustomers = $this->getTopCustomers(5);

        // ============================================================
        // Top 5 products by MTD quantity sold
        // ============================================================
        $topProducts = $this->getTopProducts(5);

        return view('dashboard.index', [
            'title' => 'Dashboard — Remote Center ERP',
            'user' => $user,
            'stats' => $stats,
            'kpis' => $kpis,
            'salesTrend' => $salesTrend,
            'branchRevenue' => $branchRevenue,
            'agingData' => $agingData,
            'topCustomers' => $topCustomers,
            'topProducts' => $topProducts,
            'legacyUrl' => config('app.legacy_url', '/'),
        ]);
    }

    /**
     * AJAX endpoint: sales trend for the last N days (for chart refresh).
     * Accepts ?days=7|30|90 via query string.
     */
    public function salesTrendAjax(Request $request)
    {
        $days = min(max((int) $request->input('days', 7), 7), 90);
        return response()->json(['data' => $this->getSalesTrend($days)]);
    }

    // ============================================================
    // PRIVATE DATA METHODS
    // ============================================================

    /**
     * Revenue KPIs: today, MTD, collection rate, outstanding.
     */
    private function getRevenueKPIs(): array
    {
        try {
            $today = now()->toDateString();
            $monthStart = now()->startOfMonth()->toDateString();

            // Today's sales
            $todaySales = DB::table('sales_invoices')
                ->where('invoice_date', $today)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS total_sales')
                ->first();

            // MTD revenue
            $mtdSales = DB::table('sales_invoices')
                ->whereBetween('invoice_date', [$monthStart, $today])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS total_sales')
                ->first();

            // MTD collection
            $mtdCollection = (float) DB::table('customer_payments')
                ->whereBetween('payment_date', [$monthStart, $today])
                ->where('is_reversed', false)
                ->sum('amount');

            // MTD total due (outstanding)
            $mtdDue = (float) DB::table('sales_invoices')
                ->whereBetween('invoice_date', [$monthStart, $today])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->whereNull('deleted_at')
                ->sum('due_amount');

            // All-time outstanding (total due across all active invoices)
            $totalOutstanding = (float) DB::table('sales_invoices')
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('due_amount');

            // Collection rate = collection / (revenue) * 100
            $mtdRevenue = (float) ($mtdSales->total_sales ?? 0);
            $collectionRate = $mtdRevenue > 0 ? round(($mtdCollection / $mtdRevenue) * 100, 1) : 0;

            // Previous month revenue (for growth calculation)
            $prevMonthStart = now()->subMonth()->startOfMonth()->toDateString();
            $prevMonthEnd = now()->subMonth()->endOfMonth()->toDateString();
            $prevMonthSales = (float) DB::table('sales_invoices')
                ->whereBetween('invoice_date', [$prevMonthStart, $prevMonthEnd])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->whereNull('deleted_at')
                ->sum('total_amount');

            $revenueGrowth = $prevMonthSales > 0
                ? round((($mtdRevenue - $prevMonthSales) / $prevMonthSales) * 100, 1)
                : 0;

            return [
                'today_invoices'   => (int) ($todaySales->invoice_count ?? 0),
                'today_revenue'    => (float) ($todaySales->total_sales ?? 0),
                'mtd_invoices'     => (int) ($mtdSales->invoice_count ?? 0),
                'mtd_revenue'      => $mtdRevenue,
                'mtd_collection'   => $mtdCollection,
                'mtd_due'          => $mtdDue,
                'total_outstanding'=> $totalOutstanding,
                'collection_rate'  => $collectionRate,
                'revenue_growth'   => $revenueGrowth,
            ];
        } catch (\Throwable $e) {
            return [
                'today_invoices' => 0, 'today_revenue' => 0,
                'mtd_invoices' => 0, 'mtd_revenue' => 0,
                'mtd_collection' => 0, 'mtd_due' => 0,
                'total_outstanding' => 0,
                'collection_rate' => 0, 'revenue_growth' => 0,
            ];
        }
    }

    /**
     * Sales trend: daily invoice count + total_sales for last N days.
     * Returns array of ['date', 'invoice_count', 'total_sales'].
     */
    private function getSalesTrend(int $days = 30): array
    {
        try {
            $start = now()->subDays($days - 1)->toDateString();
            $end = now()->toDateString();

            $rows = DB::table('sales_invoices')
                ->whereBetween('invoice_date', [$start, $end])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->whereNull('deleted_at')
                ->selectRaw("
                    invoice_date::text AS date,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(total_amount), 0) AS total_sales
                ")
                ->groupBy('invoice_date')
                ->orderBy('invoice_date')
                ->get()
                ->keyBy('date');

            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $row = $rows->get($date);
                $series[] = [
                    'date'          => $date,
                    'invoice_count' => $row ? (int) $row->invoice_count : 0,
                    'total_sales'   => $row ? (float) $row->total_sales : 0.0,
                ];
            }

            return $series;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Branch revenue comparison: MTD revenue per branch.
     */
    private function getBranchRevenue(): array
    {
        try {
            $monthStart = now()->startOfMonth()->toDateString();
            $today = now()->toDateString();

            $rows = DB::table('sales_invoices as si')
                ->join('branches as b', 'b.id', '=', 'si.branch_id')
                ->whereBetween('si.invoice_date', [$monthStart, $today])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed'])
                ->whereNull('si.deleted_at')
                ->groupBy('b.id', 'b.branch_name')
                ->orderByDesc('revenue')
                ->selectRaw("
                    b.branch_name,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(si.total_amount), 0) AS revenue
                ")
                ->get();

            return $rows->map(fn ($r) => [
                'branch'    => $r->branch_name,
                'invoices'  => (int) $r->invoice_count,
                'revenue'   => (float) $r->revenue,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Receivable aging: bucketed outstanding amounts.
     */
    private function getReceivableAging(): array
    {
        try {
            $buckets = [
                'Current'     => 0,
                '1-30 days'   => 0,
                '31-60 days'  => 0,
                '61-90 days'  => 0,
                '90+ days'    => 0,
            ];

            $rows = DB::table('sales_invoices')
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->where('due_amount', '>', 0)
                ->selectRaw("
                    CASE
                        WHEN invoice_date >= CURRENT_DATE THEN 'Current'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '30 days' THEN '1-30 days'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '60 days' THEN '31-60 days'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '90 days' THEN '61-90 days'
                        ELSE '90+ days'
                    END AS bucket,
                    SUM(due_amount) AS total_due
                ")
                ->groupBy('bucket')
                ->get();

            foreach ($rows as $row) {
                if (isset($buckets[$row->bucket])) {
                    $buckets[$row->bucket] = (float) $row->total_due;
                }
            }

            return $buckets;
        } catch (\Throwable $e) {
            return ['Current' => 0, '1-30 days' => 0, '31-60 days' => 0, '61-90 days' => 0, '90+ days' => 0];
        }
    }

    /**
     * Top N customers by MTD revenue.
     */
    private function getTopCustomers(int $limit = 5): array
    {
        try {
            $monthStart = now()->startOfMonth()->toDateString();
            $today = now()->toDateString();

            $rows = DB::table('sales_invoices as si')
                ->join('customers as c', 'c.id', '=', 'si.customer_id')
                ->whereBetween('si.invoice_date', [$monthStart, $today])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed'])
                ->whereNull('si.deleted_at')
                ->groupBy('c.id', 'c.customer_name')
                ->orderByDesc('total_revenue')
                ->limit($limit)
                ->selectRaw("
                    c.customer_name,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(si.total_amount), 0) AS total_revenue,
                    COALESCE(SUM(si.due_amount), 0) AS total_due
                ")
                ->get();

            return $rows->map(fn ($r) => [
                'name'       => $r->customer_name,
                'invoices'   => (int) $r->invoice_count,
                'revenue'    => (float) $r->total_revenue,
                'due'        => (float) $r->total_due,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Top N products by MTD quantity sold.
     */
    private function getTopProducts(int $limit = 5): array
    {
        try {
            $monthStart = now()->startOfMonth()->toDateString();
            $today = now()->toDateString();

            $rows = DB::table('sales_invoice_items as sii')
                ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
                ->join('products as p', 'p.id', '=', 'sii.product_id')
                ->whereBetween('si.invoice_date', [$monthStart, $today])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed'])
                ->whereNull('si.deleted_at')
                ->groupBy('p.id', 'p.product_name', 'p.product_code')
                ->orderByDesc('qty_sold')
                ->limit($limit)
                ->selectRaw("
                    p.product_code,
                    p.product_name,
                    SUM(sii.qty) AS qty_sold,
                    SUM(sii.qty * sii.rate) AS revenue
                ")
                ->get();

            return $rows->map(fn ($r) => [
                'code'    => $r->product_code,
                'name'    => $r->product_name,
                'qty'     => (float) $r->qty_sold,
                'revenue' => (float) $r->revenue,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
