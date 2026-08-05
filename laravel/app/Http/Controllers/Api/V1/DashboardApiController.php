<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — dashboard summary API for mobile apps / AI sidecars.
 *
 * Endpoints:
 *   GET /api/v1/dashboard             summary stats (counts + today's sales)
 *   GET /api/v1/dashboard/sales-trend last 7 days sales totals
 *   GET /api/v1/dashboard/top-products top 10 products by sales (last 30d)
 *
 * All endpoints require a Bearer token (ApiAuth middleware).
 */
class DashboardApiController extends Controller
{
    /**
     * GET /api/v1/dashboard — summary stats for the mobile home screen.
     *
     * Returns counts of active master-data entities + today's sales totals
     * (computed from non-reversed, non-cancelled sales invoices).
     */
    public function index(): JsonResponse
    {
        // REPORTS-AUDIT-7 (G-237 / dashboards.md G15): wrap the 8 DB queries
        // in a 30-second cache so a mobile app polling every 5s does not
        // generate 8 DB queries per request (960 queries/min at the 120
        // req/min rate limit). The cache key is scoped per user id so
        // branch-specific data does not leak across users.
        $userId = request()?->user()?->id ?? 0;
        $data = Cache::remember("api:dashboard:summary:user:{$userId}", 30, function () {
            $today = now()->toDateString();

            // Active master-data counts.
            $activeBranches   = Branch::active()->count();
            $activeWarehouses = Warehouse::active()->count();
            $activeProducts   = Product::active()->count();
            $activeCustomers  = Customer::active()->count();
            $activeSuppliers  = Supplier::active()->count();
            $activeEmployees  = Employee::active()->count();

            // Today sales — non-reversed, non-cancelled.
            $todaySales = DB::table('sales_invoices')
                ->where('invoice_date', $today)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS total_sales')
                ->first();

            // Today collection — non-reversed customer payments.
            $todayCollection = (float) DB::table('customer_payments')
                ->whereDate('payment_date', $today)
                ->where('is_reversed', false)
                ->sum('amount');

            return [
                'counts' => [
                    'active_branches'   => $activeBranches,
                    'active_warehouses'  => $activeWarehouses,
                    'active_products'    => $activeProducts,
                    'active_customers'   => $activeCustomers,
                    'active_suppliers'   => $activeSuppliers,
                    'active_employees'   => $activeEmployees,
                ],
                'today' => [
                    'date'           => $today,
                    'invoice_count'  => (int) $todaySales->invoice_count,
                    'total_sales'    => (float) $todaySales->total_sales,
                    'collection'     => $todayCollection,
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/dashboard/sales-trend — last 7 days sales totals.
     *
     * Returns one entry per day with `date`, `invoice_count`, `total_sales`.
     * Days with no sales are filled with zeros so chart libraries can draw a
     * continuous line.
     */
    public function salesTrend(): JsonResponse
    {
        // REPORTS-AUDIT-7 (G-237): 5-minute cache — the 7-day trend does not
        // change minute-to-minute, so a 300s cache absorbs polling traffic.
        $data = Cache::remember('api:dashboard:sales-trend:7d', 300, function () {
            $days = 7;
            $start = now()->subDays($days - 1)->toDateString();
            $end   = now()->toDateString();

            $rows = DB::table('sales_invoices')
                ->whereBetween('invoice_date', [$start, $end])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->selectRaw("
                    invoice_date::text AS date,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(total_amount), 0) AS total_sales
                ")
                ->groupBy('invoice_date')
                ->orderBy('invoice_date')
                ->get()
                ->keyBy('date');

            // Fill missing days with zero.
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $row  = $rows->get($date);
                $series[] = [
                    'date'          => $date,
                    'invoice_count' => $row ? (int) $row->invoice_count : 0,
                    'total_sales'   => $row ? (float) $row->total_sales : 0.0,
                ];
            }

            return ['data' => $series, 'meta' => ['range_days' => $days, 'start' => $start, 'end' => $end]];
        });

        return response()->json($data);
    }

    /**
     * GET /api/v1/dashboard/top-products — top 10 products by sales.
     *
     * Aggregates sales_invoice_items over the last 30 days, joined with
     * sales_invoices (for the date + status filter) and products (for the
     * code/name). Returns id/code/name/qty_sold/revenue.
     */
    public function topProducts(): JsonResponse
    {
        // REPORTS-AUDIT-7 (G-237): 15-minute cache — top-10 products over a
        // 30-day window change slowly, so a 900s cache is safe.
        $data = Cache::remember('api:dashboard:top-products:30d', 900, function () {
            $since = now()->subDays(30)->toDateString();

            $rows = DB::table('sales_invoice_items as sii')
                ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
                ->join('products as p', 'p.id', '=', 'sii.product_id')
                ->where('si.invoice_date', '>=', $since)
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed'])
                ->selectRaw("
                    p.id AS product_id,
                    p.product_code,
                    p.product_name,
                    SUM(sii.qty) AS qty_sold,
                    SUM(sii.qty * sii.rate) AS revenue
                ")
                ->groupBy('p.id', 'p.product_code', 'p.product_name')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get();

            $items = $rows->map(fn ($r) => [
                'product_id'   => (int) $r->product_id,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'qty_sold'     => (float) $r->qty_sold,
                'revenue'      => (float) $r->revenue,
            ])->values();

            return ['data' => $items, 'meta' => ['range_days' => 30, 'since' => $since]];
        });

        return response()->json($data);
    }
}
