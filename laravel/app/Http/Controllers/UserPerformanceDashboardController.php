<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User Performance Dashboard Controller — Phase 0 (Scaffolding) +
 * Phase 1 (Sales Performance Core).
 *
 * Replaces {@see LegacyDashboardController} for the `/dashboard` route.
 *
 * KEY DESIGN PRINCIPLES (per docs/USER_PERFORMANCE_DASHBOARD_PLAN.md):
 *   1. NO company-wide metrics anywhere. Every metric is attributed to a
 *      single user via `created_by` (or the appropriate per-table
 *      attribution column).
 *   2. Default view = the logged-in user's own performance.
 *   3. Super-admin (role = 'superadmin') sees an employee <select> at the
 *      top. By default it loads the admin's own performance. Picking
 *      another employee reloads the dashboard for that employee via
 *      ?employee_id=X.
 *   4. Non-admin users who manually navigate to ?employee_id=X are
 *      silently ignored — they always see their own performance.
 *
 * PHASE 0 — scaffolding (employee/period resolution, super-admin select,
 *   G12 schema-gap runtime check, empty grid).
 * PHASE 1 — Sales Performance Core: getSalesKPIs, getSalesTrend,
 *   getSalesByProductGroup, getTopCustomers, getCustomerAcquisition.
 *   All queries are partition-pruned (WHERE invoice_date BETWEEN ...) and
 *   filtered by `created_by = $userId`. Phases 2-4 will add collections,
 *   returns, work-pattern, commission, accuracy.
 *
 * ATTRIBUTION CONVENTION:
 *   - $targetUser->id         → for `created_by` queries (activity metrics)
 *   - $targetEmployee->id     → for `salesman_id` / `accountable_employee_id`
 *                                queries (portfolio / accountability metrics)
 *
 * RLS NOTE:
 *   Non-admin queries are auto-scoped by RLS to session('branch_id').
 *   Super-admin queries bypass RLS so cross-branch employee switching works.
 *   We do NOT manually add `->where('branch_id', ...)` anywhere — RLS handles
 *   branch isolation implicitly.
 */
class UserPerformanceDashboardController extends Controller
{
    /**
     * Render the user performance dashboard.
     *
     * Route: GET /dashboard
     * Name:  dashboard
     */
    public function index(Request $request)
    {
        $authUser = Auth::user();
        $isSuperadmin = $authUser->isSuperadmin();

        // ============================================================
        // 1. Resolve target employee + user
        // ============================================================
        // Default: the logged-in user's own employee.
        // Super-admin override: ?employee_id=X (only honored if valid).
        $authEmployeeId = $authUser->employee?->id;
        $targetEmployeeId = $authEmployeeId;

        $requestedEmployeeId = $request->integer('employee_id');
        if ($isSuperadmin && $requestedEmployeeId > 0) {
            $exists = Employee::where('id', $requestedEmployeeId)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                $targetEmployeeId = $requestedEmployeeId;
            }
        }

        // If the logged-in user has no employee record (edge case), bail
        // gracefully — this should never happen in production because
        // users.employee_id is NOT NULL UNIQUE, but we guard anyway.
        if ($targetEmployeeId === null) {
            return view('dashboard.performance', [
                'title'              => 'My Performance — Remote Center ERP',
                'user'               => $authUser,
                'isSuperadmin'       => $isSuperadmin,
                'targetEmployee'     => null,
                'targetUser'         => null,
                'employeeOptions'    => collect(),
                'period'             => 'mtd',
                'periodLabel'        => 'Month to Date',
                'range'              => ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()],
                'scaffoldingOnly'    => true,
                'errorMessage'       => 'Your user account is not linked to an employee record. Please contact an administrator.',
            ]);
        }

        $targetEmployee = Employee::with('branch')->find($targetEmployeeId);
        $targetUser = User::where('employee_id', $targetEmployeeId)->first();

        // ============================================================
        // 2. Resolve period range
        // ============================================================
        [$period, $periodLabel, $range] = $this->resolvePeriod($request);

        // ============================================================
        // 3. Load employee options for super-admin <select>
        // ============================================================
        // Only loaded for super-admin — non-admins never see the box.
        // Ordered by name; the current target is marked selected in the view.
        $employeeOptions = collect();
        if ($isSuperadmin) {
            $employeeOptions = Employee::whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('name')
                ->select('id', 'name', 'employee_code', 'role', 'branch_id')
                ->with('branch:id,branch_name')
                ->get();
        }

        // ============================================================
        // 4. Phase 0 — Runtime verification of G12 schema gap
        // ============================================================
        // Per the plan: verify whether customer_payments.transaction_type
        // exists at runtime so Phase 2 (Collections) knows whether to use it
        // for write-off / discount / refund metrics (C9).
        $customerPaymentsTxnType = $this->checkCustomerPaymentsTransactionType();

        // ============================================================
        // 5. Phase 1 — Sales Performance Core metrics
        // ============================================================
        // Every query is scoped to the resolved target user (created_by = $userId)
        // AND the resolved period range. Partition-pruned via invoice_date BETWEEN.
        $userId = $targetUser?->id ?? 0;
        $salesKpis             = $this->getSalesKPIs($userId, $range);
        $salesTrend            = $this->getSalesTrend($userId, $range);
        $salesByProductGroup   = $this->getSalesByProductGroup($userId, $range);
        $topCustomers          = $this->getTopCustomers($userId, $range, 5);
        $customerAcquisition   = $this->getCustomerAcquisition($userId, $range);

        // ============================================================
        // 6. Render the view
        // ============================================================
        return view('dashboard.performance', [
            'title'                       => 'My Performance — Remote Center ERP',
            'user'                        => $authUser,
            'isSuperadmin'                => $isSuperadmin,
            'targetEmployee'              => $targetEmployee,
            'targetUser'                  => $targetUser,
            'employeeOptions'             => $employeeOptions,
            'period'                      => $period,
            'periodLabel'                 => $periodLabel,
            'range'                       => $range,
            'scaffoldingOnly'             => false,
            'customerPaymentsTxnType'     => $customerPaymentsTxnType,

            // Phase 1 data
            'salesKpis'             => $salesKpis,
            'salesTrend'            => $salesTrend,
            'salesByProductGroup'   => $salesByProductGroup,
            'topCustomers'          => $topCustomers,
            'customerAcquisition'   => $customerAcquisition,
        ]);
    }

    /**
     * AJAX endpoint: per-user sales trend for chart refresh.
     *
     * Route: GET /dashboard/sales-trend
     * Name:  dashboard.salesTrend
     *
     * Phase 1 implementation: returns daily invoice count + total sales for
     * the resolved target user over the last N days (7/30/90 toggle).
     * Honors ?employee_id=X for super-admin (same resolution as index()).
     */
    public function salesTrendAjax(Request $request)
    {
        $days = min(max((int) $request->input('days', 7), 7), 90);

        // Resolve target user the same way as index() — super-admin can
        // view any employee's trend; non-admin only sees their own.
        $authUser = Auth::user();
        $targetEmployeeId = $authUser->employee?->id;
        if ($authUser->isSuperadmin()) {
            $requested = (int) $request->input('employee_id', 0);
            if ($requested > 0 && Employee::where('id', $requested)->whereNull('deleted_at')->exists()) {
                $targetEmployeeId = $requested;
            }
        }
        $targetUser = User::where('employee_id', $targetEmployeeId ?? 0)->first();
        $userId = $targetUser?->id ?? 0;

        $range = [
            'start' => now()->subDays($days - 1)->toDateString(),
            'end'   => now()->toDateString(),
        ];

        return response()->json([
            'data'  => $this->getSalesTrend($userId, $range),
            'days'  => $days,
            'phase' => 1,
        ]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * Resolve the selected period into a [$period, $label, [$start, $end]] tuple.
     *
     * Supported values: today, mtd, qtd, ytd, last30, custom.
     * For 'custom', reads ?from= and ?to= query params (YYYY-MM-DD); falls
     * back to MTD if either is missing/invalid.
     */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->input('period', 'mtd');
        $today = now()->toDateString();

        switch ($period) {
            case 'today':
                return ['today', 'Today', ['start' => $today, 'end' => $today]];
            case 'qtd':
                return [
                    'qtd',
                    'Quarter to Date (' . now()->format('M Y') . ')',
                    ['start' => now()->startOfQuarter()->toDateString(), 'end' => $today],
                ];
            case 'ytd':
                return [
                    'ytd',
                    'Year to Date (' . now()->format('Y') . ')',
                    ['start' => now()->startOfYear()->toDateString(), 'end' => $today],
                ];
            case 'last30':
                return [
                    'last30',
                    'Last 30 Days',
                    ['start' => now()->subDays(29)->toDateString(), 'end' => $today],
                ];
            case 'custom':
                $from = $request->input('from');
                $to = $request->input('to');
                // Validate YYYY-MM-DD format and that from <= to.
                if ($this->isValidDate($from) && $this->isValidDate($to) && $from <= $to) {
                    return ['custom', 'Custom Range', ['start' => $from, 'end' => $to]];
                }
                // Invalid custom range → fall through to MTD.
                $period = 'mtd';
                // no break
            case 'mtd':
            default:
                return [
                    'mtd',
                    'Month to Date (' . now()->format('M Y') . ')',
                    ['start' => now()->startOfMonth()->toDateString(), 'end' => $today],
                ];
        }
    }

    /**
     * Lightweight YYYY-MM-DD validator (no Carbon overhead).
     */
    private function isValidDate(?string $date): bool
    {
        if ($date === null) {
            return false;
        }
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    /**
     * G12 schema-gap check: does customer_payments.transaction_type exist?
     *
     * Phase 2 (Collections) uses this column for write-off / discount / refund
     * metrics (C9). The column is referenced in the CustomerPayment model
     * (isReceive, isDiscount, isWriteOff, isRefund helpers) but is not in
     * the baseline 06_payment_and_misc.sql — it was added by a later
     * migration. Verify at runtime so Phase 2 knows whether to use it.
     *
     * Returns true if the column exists, false otherwise. Logs the result.
     * Catches all errors so a missing table or permission issue doesn't
     * take down the dashboard.
     */
    private function checkCustomerPaymentsTransactionType(): bool
    {
        try {
            $exists = DB::table('information_schema.columns')
                ->where('table_name', 'customer_payments')
                ->where('column_name', 'transaction_type')
                ->exists();
            Log::info('Phase 0 G12 check: customer_payments.transaction_type exists = ' . ($exists ? 'true' : 'false'));
            return $exists;
        } catch (\Throwable $e) {
            Log::warning('Phase 0 G12 check failed: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // PHASE 1 — SALES PERFORMANCE METRICS
    // ============================================================
    //
    // All Phase 1 queries follow the same pattern:
    //   - WHERE created_by = $userId             (per-user attribution)
    //   - AND invoice_date BETWEEN $range bounds (partition pruning)
    //   - AND is_reversed = false                (exclude reversals)
    //   - AND status NOT IN ('cancelled','reversed','draft')  (active only)
    //   - AND deleted_at IS NULL                 (soft-deletes excluded)
    //
    // Every method is wrapped in try/catch so a single broken query can't
    // take down the whole dashboard — returns a zeroed default instead.

    /**
     * Compute the previous-period range (same length, immediately before $range).
     * Used for growth-pct comparisons.
     *
     * Examples:
     *   range = [2026-07-01, 2026-07-31] (MTD, 31 days) → prev = [2026-06-01, 2026-06-30]
     *   range = [2026-07-15, 2026-07-15] (today, 1 day)  → prev = [2026-07-14, 2026-07-14]
     */
    private function previousPeriodRange(array $range): array
    {
        try {
            $end = \Carbon\Carbon::parse($range['end']);
            $start = \Carbon\Carbon::parse($range['start']);
            $length = $end->diffInDays($start) + 1; // inclusive
            $prevEnd = $end->copy()->subDays($length);
            $prevStart = $prevEnd->copy()->subDays($length - 1);
            return ['start' => $prevStart->toDateString(), 'end' => $prevEnd->toDateString()];
        } catch (\Throwable $e) {
            return ['start' => $range['start'], 'end' => $range['end']];
        }
    }

    /**
     * Sales KPIs: count, total, AOV, growth %, active days, peak day.
     *
     * @return array{invoice_count:int,total_sales:float,aov:float,growth_pct:float,active_days:int,peak_day_value:float,peak_day_date:?string,prev_total_sales:float}
     */
    private function getSalesKPIs(int $userId, array $range): array
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
    private function getSalesTrend(int $userId, array $range): array
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
    private function getSalesByProductGroup(int $userId, array $range, int $limit = 8): array
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
    private function getTopCustomers(int $userId, array $range, int $limit = 5): array
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
    private function getCustomerAcquisition(int $userId, array $range): array
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
