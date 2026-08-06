<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Dashboard\Concerns\PeriodRangeHelpers;

/**
 * Sales Performance Metrics Service — Dashboard Phase 1.
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the 5 sales-performance metric methods. Each method
 * is a pure query — no caching (the controller's cached() wrapper handles
 * that), no auth (the controller resolves the target user before calling).
 *
 * Attribution: all queries filter by `created_by = $userId`.
 *
 * All Phase 1 queries follow the same pattern:
 *   - WHERE created_by = $userId             (per-user attribution)
 *   - AND invoice_date BETWEEN $range bounds (partition pruning)
 *   - AND is_reversed = false                (exclude reversals)
 *   - AND status NOT IN ('cancelled','reversed','draft')  (active only)
 *   - AND deleted_at IS NULL                 (soft-deletes excluded)
 *
 * Every method is wrapped in try/catch so a single broken query can't
 * take down the whole dashboard — returns a zeroed default instead.
 */
class SalesPerformanceMetricsService
{
    use PeriodRangeHelpers;

    /**
     * Sales KPIs: count, total, AOV, growth %, active days, peak day.
     *
     * @return array{invoice_count:int,total_sales:float,aov:float,growth_pct:float,active_days:int,peak_day_value:float,peak_day_date:?string,prev_total_sales:float}
     */
    public function getSalesKPIs(int $userId, array $range): array
    {
        $zero = [
            'invoice_count'    => 0,
            'total_sales'      => 0.0,
            'aov'              => 0.0,
            'growth_pct'       => 0.0,
            'active_days'      => 0,
            'peak_day_value'   => 0.0,
            'peak_day_date'    => null,
            'prev_total_sales' => 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Current period aggregate
            $curr = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total')
                ->first();

            // Previous period aggregate for growth %
            $prevRange = $this->previousPeriodRange($range);
            $prevTotal = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$prevRange['start'], $prevRange['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('total_amount');

            // Per-day breakdown for active-days + peak-day calc
            $days = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->groupBy('invoice_date')
                ->orderByDesc('daily_total')
                ->selectRaw("invoice_date::text AS d, SUM(total_amount) AS daily_total")
                ->get();

            $totalSales = (float) ($curr->total ?? 0);
            $invoiceCount = (int) ($curr->cnt ?? 0);
            $growthPct = $prevTotal > 0
                ? round((($totalSales - $prevTotal) / $prevTotal) * 100, 1)
                : 0.0;
            $peak = $days->first();

            return [
                'invoice_count'    => $invoiceCount,
                'total_sales'      => $totalSales,
                'aov'              => $invoiceCount > 0 ? round($totalSales / $invoiceCount, 2) : 0.0,
                'growth_pct'       => $growthPct,
                'active_days'      => $days->count(),
                'peak_day_value'   => $peak ? (float) $peak->daily_total : 0.0,
                'peak_day_date'    => $peak?->d,
                'prev_total_sales' => $prevTotal,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 1 getSalesKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Sales trend: daily invoice count + total sales across the range.
     * Returns a contiguous series — missing days are zero-filled so the
     * chart shows a continuous timeline.
     *
     * @return array<int, array{date:string,invoice_count:int,total_sales:float}>
     */
    public function getSalesTrend(int $userId, array $range): array
    {
        $empty = [];
        if ($userId <= 0) {
            return $empty;
        }
        try {
            $rows = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
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

            // Build a contiguous date series from $range['start'] to $range['end']
            $series = [];
            $cursor = \Carbon\Carbon::parse($range['start']);
            $end = \Carbon\Carbon::parse($range['end']);
            while ($cursor <= $end) {
                $dStr = $cursor->toDateString();
                $row = $rows->get($dStr);
                $series[] = [
                    'date'          => $dStr,
                    'invoice_count' => $row ? (int) $row->invoice_count : 0,
                    'total_sales'   => $row ? (float) $row->total_sales : 0.0,
                ];
                $cursor->addDay();
            }
            return $series;
        } catch (\Throwable $e) {
            Log::warning('Phase 1 getSalesTrend failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Sales by product group — top groups by the user's revenue this period.
     * Joins sales_invoice_items → products → product_groups.
     *
     * @return array<int, array{group_name:string,revenue:float,qty:float,share:float}>
     *   Sorted desc by revenue; share = % of user's total revenue.
     */
    public function getSalesByProductGroup(int $userId, array $range, int $limit = 8): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = DB::table('sales_invoice_items as sii')
                ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
                ->join('products as p', 'p.id', '=', 'sii.product_id')
                ->leftJoin('product_groups as pg', 'pg.id', '=', 'p.group_id')
                ->where('si.created_by', $userId)
                ->whereBetween('si.invoice_date', [$range['start'], $range['end']])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('si.deleted_at')
                ->groupBy('pg.id', 'pg.group_name')
                ->orderByDesc('revenue')
                ->limit($limit)
                ->selectRaw("
                    COALESCE(pg.group_name, '(Uncategorized)') AS group_name,
                    COALESCE(SUM(sii.amount), 0) AS revenue,
                    COALESCE(SUM(sii.qty), 0) AS qty
                ")
                ->get();

            $total = $rows->sum(fn ($r) => (float) $r->revenue);
            return $rows->map(fn ($r) => [
                'group_name' => $r->group_name,
                'revenue'    => (float) $r->revenue,
                'qty'        => (float) $r->qty,
                'share'      => $total > 0 ? round(((float) $r->revenue / $total) * 100, 1) : 0.0,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            Log::warning('Phase 1 getSalesByProductGroup failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The user's top-N customers by revenue this period.
     * (NOT a global top-N — scoped to si.created_by = $userId.)
     *
     * @return array<int, array{id:int,name:string,invoice_count:int,revenue:float,due:float,share:float}>
     */
    public function getTopCustomers(int $userId, array $range, int $limit = 5): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = DB::table('sales_invoices as si')
                ->join('customers as c', 'c.id', '=', 'si.customer_id')
                ->where('si.created_by', $userId)
                ->whereBetween('si.invoice_date', [$range['start'], $range['end']])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('si.deleted_at')
                ->groupBy('c.id', 'c.customer_name')
                ->orderByDesc('revenue')
                ->limit($limit)
                ->selectRaw("
                    c.id,
                    c.customer_name AS name,
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(si.total_amount), 0) AS revenue,
                    COALESCE(SUM(si.due_amount), 0) AS due
                ")
                ->get();

            $total = $rows->sum(fn ($r) => (float) $r->revenue);
            return $rows->map(fn ($r) => [
                'id'            => (int) $r->id,
                'name'          => $r->name,
                'invoice_count' => (int) $r->invoice_count,
                'revenue'       => (float) $r->revenue,
                'due'           => (float) $r->due,
                'share'         => $total > 0 ? round(((float) $r->revenue / $total) * 100, 1) : 0.0,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            Log::warning('Phase 1 getTopCustomers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Customer acquisition metrics — active customers, new customers,
     * repeat customers, repeat rate.
     *
     * "New" = customers who appear in this period for the first time
     * across the user's history (i.e., the user had no prior invoice for
     * that customer before $range['start']).
     *
     * "Repeat" = customers with ≥2 invoices by this user in the period.
     *
     * @return array{active:int,new:int,repeat:int,repeat_rate:float,new_rate:float}
     */
    public function getCustomerAcquisition(int $userId, array $range): array
    {
        $zero = ['active' => 0, 'new' => 0, 'repeat' => 0, 'repeat_rate' => 0.0, 'new_rate' => 0.0];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Per-customer invoice counts within the period
            $perCustomer = DB::table('sales_invoices as si')
                ->where('si.created_by', $userId)
                ->whereBetween('si.invoice_date', [$range['start'], $range['end']])
                ->where('si.is_reversed', false)
                ->whereNotIn('si.status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('si.deleted_at')
                ->groupBy('si.customer_id')
                ->selectRaw('si.customer_id, COUNT(*) AS cnt')
                ->get();

            $active = $perCustomer->count();
            $repeat = $perCustomer->filter(fn ($r) => $r->cnt >= 2)->count();

            // New customers: customers in $perCustomer that have NO invoice
            // by this user before $range['start'].
            $customerIds = $perCustomer->pluck('customer_id')->all();
            $new = 0;
            if (!empty($customerIds)) {
                $existingBefore = DB::table('sales_invoices')
                    ->where('created_by', $userId)
                    ->where('invoice_date', '<', $range['start'])
                    ->where('is_reversed', false)
                    ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                    ->whereNull('deleted_at')
                    ->whereIn('customer_id', $customerIds)
                    ->distinct()
                    ->pluck('customer_id')
                    ->all();
                $new = count(array_diff($customerIds, $existingBefore));
            }

            return [
                'active'      => $active,
                'new'         => $new,
                'repeat'      => $repeat,
                'repeat_rate' => $active > 0 ? round(($repeat / $active) * 100, 1) : 0.0,
                'new_rate'    => $active > 0 ? round(($new / $active) * 100, 1) : 0.0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 1 getCustomerAcquisition failed: ' . $e->getMessage());
            return $zero;
        }
    }
}
