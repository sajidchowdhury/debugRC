<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User Performance Dashboard Controller — Phase 0 (Scaffolding) +
 * Phase 1 (Sales Performance Core) + Phase 2 (Collections & Returns) +
 * Phase 3 (Operational Efficiency & Productivity) +
 * Phase 4 (Commission, Stock Discipline & Accuracy) +
 * Phase 5 (Role-Aware Refinement & Approval Workload) +
 * Phase 6 (Polish, Performance & Post-Launch Gaps).
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
 *   filtered by `created_by = $userId`.
 * PHASE 2 — Collections & Returns: getCollectionKPIs, getReceivableAging,
 *   getReturnKPIs, getPaymentModeMix. customer_payments queries are filtered
 *   by `created_by = $userId` AND `payment_date BETWEEN` for partition
 *   pruning (customer_payments is NOT partitioned but follows the same
 *   date-range pattern). Outstanding/aging snapshots are point-in-time
 *   (no period filter) so they always reflect the user's current book.
 * PHASE 3 — Operational Efficiency & Productivity: getVelocityKPIs,
 *   getPipelineSnapshot, getWorkPattern, getActivitySummary,
 *   getNotificationEngagement. Velocity uses sales_invoices lifecycle
 *   timestamps (created_at → godown_prepared_at → challan_issued_at).
 *   Work pattern is a 24-bin hour-of-day histogram UNIONed across the
 *   user's activity tables (sales_invoices, customer_payments,
 *   sales_returns, sales_challans, stock_adjustments, damage_invoices).
 *   Pipeline snapshot is point-in-time. Activity summary derives
 *   cross-table active days + peak day. Notification engagement uses the
 *   notifications table keyed by user_id (NOT created_by).
 * PHASE 4 — Commission, Stock Discipline & Accuracy: getCommissionSummary,
 *   getStockDiscipline, getAccuracyKPIs. Commission uses salesman_id
 *   (employees.id); stock discipline uses created_by (activity) +
 *   accountable_employee_id (damage blame); accuracy uses created_by.
 * PHASE 5 — Role-Aware Refinement & Approval Workload: resolveRoleSections
 *   (role → section visibility map), getApprovalWorkload (manager-only
 *   pending/approved counts using existing approved_by columns on
 *   stock_adjustments + damage_invoices).
 * PHASE 6 — Polish, Performance & Post-Launch Gaps: 60s Cache::remember()
 *   on every metric (keyed perf:user:{uid}:{metric}:{period}:{rangeHash}),
 *   slow-query telemetry (>200ms logged to storage/logs/perf.log via
 *   Log::build on-demand channel), AJAX fragment refresh endpoint
 *   /dashboard/fragment for no-full-reload period/employee switching,
 *   composite partial indexes migration for high-traffic query patterns.
 *
 * ATTRIBUTION CONVENTION:
 *   - $targetUser->id         → for `created_by` queries (activity metrics)
 *                                AND for `notifications.user_id` queries
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
        $ctx = $this->resolveContext($request);

        // Edge case: logged-in user has no employee record → render the
        // scaffolding-only view with an error message.
        if (($ctx['scaffoldingOnly'] ?? false) || empty($ctx['targetEmployee'])) {
            return view('dashboard.performance', [
                'title'              => 'My Performance — Remote Center ERP',
                'user'               => $ctx['authUser'],
                'isSuperadmin'       => $ctx['isSuperadmin'],
                'targetEmployee'     => null,
                'targetUser'         => null,
                'employeeOptions'    => $ctx['employeeOptions'] ?? collect(),
                'period'             => $ctx['period'],
                'periodLabel'        => $ctx['periodLabel'],
                'range'              => $ctx['range'],
                'scaffoldingOnly'    => true,
                'errorMessage'       => 'Your user account is not linked to an employee record. Please contact an administrator.',
            ]);
        }

        // ============================================================
        // Phase 6 — CACHED METRIC LOADING
        // ============================================================
        // Every metric is wrapped in $this->cached() which:
        //   1. Calls Cache::remember(key, 60s, fn) where the key encodes
        //      userId/employeeId + metric name + period + range hash.
        //   2. Inside the cache miss branch, calls $this->timed(metric, fn)
        //      which times the query and logs slow ones (>200ms) to
        //      storage/logs/perf.log via Log::build on-demand channel.
        // The 60s TTL is the invalidation mechanism — short enough that
        // fresh data appears within a minute, long enough to amortize the
        // cost of the 25+ queries on repeat visits / AJAX refreshes.
        $userId         = $ctx['targetUser']?->id ?? 0;
        $employeeId     = $ctx['targetEmployee']->id;
        $period         = $ctx['period'];
        $range          = $ctx['range'];
        $txnTypeExists  = $ctx['customerPaymentsTxnType'];
        $role           = $ctx['targetEmployee']->role ?? 'other';
        $roleSections   = $this->resolveRoleSections($role);

        $salesKpis             = $this->cached('sales_kpis', $userId, $period, $range, fn() => $this->getSalesKPIs($userId, $range));
        $salesTrend            = $this->cached('sales_trend', $userId, $period, $range, fn() => $this->getSalesTrend($userId, $range));
        $salesByProductGroup   = $this->cached('sales_by_pg', $userId, $period, $range, fn() => $this->getSalesByProductGroup($userId, $range));
        $topCustomers          = $this->cached('top_customers', $userId, $period, $range, fn() => $this->getTopCustomers($userId, $range, 5));
        $customerAcquisition   = $this->cached('cust_acq', $userId, $period, $range, fn() => $this->getCustomerAcquisition($userId, $range));

        $collectionKpis    = $this->cached('collection_kpis', $userId, $period, $range, fn() => $this->getCollectionKPIs($userId, $range, $txnTypeExists));
        $receivableAging   = $this->cached('recv_aging', $userId, $period, $range, fn() => $this->getReceivableAging($userId));
        $returnKpis        = $this->cached('return_kpis', $userId, $period, $range, fn() => $this->getReturnKPIs($userId, $range));
        $paymentModeMix    = $this->cached('pmix', $userId, $period, $range, fn() => $this->getPaymentModeMix($userId, $range));

        $velocityKpis          = $this->cached('velocity', $userId, $period, $range, fn() => $this->getVelocityKPIs($userId, $range));
        $pipelineSnapshot      = $this->cached('pipeline', $userId, $period, $range, fn() => $this->getPipelineSnapshot($userId));
        $workPattern           = $this->cached('work_pattern', $userId, $period, $range, fn() => $this->getWorkPattern($userId, $range));
        $activitySummary       = $this->cached('activity', $userId, $period, $range, fn() => $this->getActivitySummary($userId, $range));
        $notificationEngagement = $this->cached('notif', $userId, $period, $range, fn() => $this->getNotificationEngagement($userId));

        $commissionSummary = $this->cached('commission', $employeeId, $period, $range, fn() => $this->getCommissionSummary($employeeId, $range));
        $stockDiscipline   = $this->cached('stock_disc', $userId, $period, $range, fn() => $this->getStockDiscipline($userId, $employeeId, $range));
        $accuracyKpis      = $this->cached('accuracy', $userId, $period, $range, fn() => $this->getAccuracyKPIs($userId, $range));

        $approvalWorkload = $roleSections['approval_workload']
            ? $this->cached('approval', $userId, $period, $range, fn() => $this->getApprovalWorkload($userId, $employeeId, $range))
            : [
                'adjustments_pending_my_approval' => 0,
                'adjustments_approved_by_me'      => 0,
                'damages_pending_my_approval'     => 0,
                'damages_approved_by_me'          => 0,
                'total_pending_value'             => 0.0,
            ];

        return view('dashboard.performance', [
            'title'                       => 'My Performance — Remote Center ERP',
            'user'                        => $ctx['authUser'],
            'isSuperadmin'                => $ctx['isSuperadmin'],
            'targetEmployee'              => $ctx['targetEmployee'],
            'targetUser'                  => $ctx['targetUser'],
            'employeeOptions'             => $ctx['employeeOptions'],
            'period'                      => $period,
            'periodLabel'                 => $ctx['periodLabel'],
            'range'                       => $range,
            'scaffoldingOnly'             => false,
            'customerPaymentsTxnType'     => $txnTypeExists,

            // Phase 1 data
            'salesKpis'             => $salesKpis,
            'salesTrend'            => $salesTrend,
            'salesByProductGroup'   => $salesByProductGroup,
            'topCustomers'          => $topCustomers,
            'customerAcquisition'   => $customerAcquisition,

            // Phase 2 data
            'collectionKpis'        => $collectionKpis,
            'receivableAging'       => $receivableAging,
            'returnKpis'            => $returnKpis,
            'paymentModeMix'        => $paymentModeMix,

            // Phase 3 data
            'velocityKpis'           => $velocityKpis,
            'pipelineSnapshot'       => $pipelineSnapshot,
            'workPattern'            => $workPattern,
            'activitySummary'        => $activitySummary,
            'notificationEngagement' => $notificationEngagement,

            // Phase 4 data
            'commissionSummary' => $commissionSummary,
            'stockDiscipline'   => $stockDiscipline,
            'accuracyKpis'      => $accuracyKpis,

            // Phase 5 data
            'roleSections'      => $roleSections,
            'approvalWorkload'  => $approvalWorkload,

            // Phase 6 flags
            'fragmentMode'      => false,
        ]);
    }

    /**
     * AJAX endpoint: return just the dashboard inner HTML (#perf-dashboard
     * container) for no-full-reload period/employee switching.
     *
     * Route: GET /dashboard/fragment
     * Name:  dashboard.fragment
     *
     * Phase 6 implementation. Same context resolution + cached metrics as
     * index(), but renders the view with $fragmentMode=true so the Blade
     * template skips @extends('layouts.admin') and outputs only the inner
     * dashboard markup. Returns JSON {html, period, periodLabel, range,
     * employeeId} so the caller can also update the URL via
     * history.pushState.
     *
     * On any error → 200 OK with {error: '...'} so the caller can fall
     * back to a full page reload (window.location = url).
     */
    public function fragmentAjax(Request $request)
    {
        try {
            $ctx = $this->resolveContext($request);

            if (($ctx['scaffoldingOnly'] ?? false) || empty($ctx['targetEmployee'])) {
                return response()->json([
                    'error' => 'no-employee',
                    'html'  => '',
                ], 200);
            }

            $userId         = $ctx['targetUser']?->id ?? 0;
            $employeeId     = $ctx['targetEmployee']->id;
            $period         = $ctx['period'];
            $range          = $ctx['range'];
            $txnTypeExists  = $ctx['customerPaymentsTxnType'];
            $role           = $ctx['targetEmployee']->role ?? 'other';
            $roleSections   = $this->resolveRoleSections($role);

            $salesKpis             = $this->cached('sales_kpis', $userId, $period, $range, fn() => $this->getSalesKPIs($userId, $range));
            $salesTrend            = $this->cached('sales_trend', $userId, $period, $range, fn() => $this->getSalesTrend($userId, $range));
            $salesByProductGroup   = $this->cached('sales_by_pg', $userId, $period, $range, fn() => $this->getSalesByProductGroup($userId, $range));
            $topCustomers          = $this->cached('top_customers', $userId, $period, $range, fn() => $this->getTopCustomers($userId, $range, 5));
            $customerAcquisition   = $this->cached('cust_acq', $userId, $period, $range, fn() => $this->getCustomerAcquisition($userId, $range));

            $collectionKpis    = $this->cached('collection_kpis', $userId, $period, $range, fn() => $this->getCollectionKPIs($userId, $range, $txnTypeExists));
            $receivableAging   = $this->cached('recv_aging', $userId, $period, $range, fn() => $this->getReceivableAging($userId));
            $returnKpis        = $this->cached('return_kpis', $userId, $period, $range, fn() => $this->getReturnKPIs($userId, $range));
            $paymentModeMix    = $this->cached('pmix', $userId, $period, $range, fn() => $this->getPaymentModeMix($userId, $range));

            $velocityKpis          = $this->cached('velocity', $userId, $period, $range, fn() => $this->getVelocityKPIs($userId, $range));
            $pipelineSnapshot      = $this->cached('pipeline', $userId, $period, $range, fn() => $this->getPipelineSnapshot($userId));
            $workPattern           = $this->cached('work_pattern', $userId, $period, $range, fn() => $this->getWorkPattern($userId, $range));
            $activitySummary       = $this->cached('activity', $userId, $period, $range, fn() => $this->getActivitySummary($userId, $range));
            $notificationEngagement = $this->cached('notif', $userId, $period, $range, fn() => $this->getNotificationEngagement($userId));

            $commissionSummary = $this->cached('commission', $employeeId, $period, $range, fn() => $this->getCommissionSummary($employeeId, $range));
            $stockDiscipline   = $this->cached('stock_disc', $userId, $period, $range, fn() => $this->getStockDiscipline($userId, $employeeId, $range));
            $accuracyKpis      = $this->cached('accuracy', $userId, $period, $range, fn() => $this->getAccuracyKPIs($userId, $range));

            $approvalWorkload = $roleSections['approval_workload']
                ? $this->cached('approval', $userId, $period, $range, fn() => $this->getApprovalWorkload($userId, $employeeId, $range))
                : [
                    'adjustments_pending_my_approval' => 0,
                    'adjustments_approved_by_me'      => 0,
                    'damages_pending_my_approval'     => 0,
                    'damages_approved_by_me'          => 0,
                    'total_pending_value'             => 0.0,
                ];

            $html = view('dashboard.performance', [
                'title'                       => 'My Performance — Remote Center ERP',
                'user'                        => $ctx['authUser'],
                'isSuperadmin'                => $ctx['isSuperadmin'],
                'targetEmployee'              => $ctx['targetEmployee'],
                'targetUser'                  => $ctx['targetUser'],
                'employeeOptions'             => $ctx['employeeOptions'],
                'period'                      => $period,
                'periodLabel'                 => $ctx['periodLabel'],
                'range'                       => $range,
                'scaffoldingOnly'             => false,
                'customerPaymentsTxnType'     => $txnTypeExists,

                'salesKpis'             => $salesKpis,
                'salesTrend'            => $salesTrend,
                'salesByProductGroup'   => $salesByProductGroup,
                'topCustomers'          => $topCustomers,
                'customerAcquisition'   => $customerAcquisition,

                'collectionKpis'        => $collectionKpis,
                'receivableAging'       => $receivableAging,
                'returnKpis'            => $returnKpis,
                'paymentModeMix'        => $paymentModeMix,

                'velocityKpis'           => $velocityKpis,
                'pipelineSnapshot'       => $pipelineSnapshot,
                'workPattern'            => $workPattern,
                'activitySummary'        => $activitySummary,
                'notificationEngagement' => $notificationEngagement,

                'commissionSummary' => $commissionSummary,
                'stockDiscipline'   => $stockDiscipline,
                'accuracyKpis'      => $accuracyKpis,

                'roleSections'      => $roleSections,
                'approvalWorkload'  => $approvalWorkload,

                'fragmentMode'      => true,
            ])->render();

            return response()->json([
                'html'         => $html,
                'period'       => $period,
                'periodLabel'  => $ctx['periodLabel'],
                'range'        => $range,
                'employeeId'   => $employeeId,
                'employeeName' => $ctx['targetEmployee']->name,
            ], 200, [
                // Suppress layout-level chrome from any middleware.
                'X-Perf-Fragment' => '1',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Phase 6 fragmentAjax failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'server-error',
                'message' => $e->getMessage(),
                'html'  => '',
            ], 200);
        }
    }

    /**
     * Phase 6 — Resolve all dashboard context (auth, target employee/user,
     * period range, employee options, G12 schema check) into a single
     * array. Shared by index() and fragmentAjax() so both code paths see
     * identical resolution logic.
     *
     * @return array{
     *   authUser: User, isSuperadmin: bool,
     *   targetEmployee: Employee|null, targetUser: User|null,
     *   employeeOptions: \Illuminate\Support\Collection,
     *   period: string, periodLabel: string, range: array{start:string,end:string},
     *   customerPaymentsTxnType: bool,
     *   scaffoldingOnly: bool
     * }
     */
    private function resolveContext(Request $request): array
    {
        $authUser = Auth::user();
        $isSuperadmin = $authUser->isSuperadmin();

        // ============================================================
        // 1. Resolve target employee + user
        // ============================================================
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

        if ($targetEmployeeId === null) {
            return [
                'authUser'        => $authUser,
                'isSuperadmin'    => $isSuperadmin,
                'targetEmployee'  => null,
                'targetUser'      => null,
                'employeeOptions' => collect(),
                'period'          => 'mtd',
                'periodLabel'     => 'Month to Date',
                'range'           => [
                    'start' => now()->startOfMonth()->toDateString(),
                    'end'   => now()->toDateString(),
                ],
                'customerPaymentsTxnType' => false,
                'scaffoldingOnly'         => true,
            ];
        }

        $targetEmployee = Employee::with('branch')->find($targetEmployeeId);
        $targetUser = User::where('employee_id', $targetEmployeeId)->first();

        [$period, $periodLabel, $range] = $this->resolvePeriod($request);

        $employeeOptions = collect();
        if ($isSuperadmin) {
            $employeeOptions = Employee::whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('name')
                ->select('id', 'name', 'employee_code', 'role', 'branch_id')
                ->with('branch:id,branch_name')
                ->get();
        }

        $customerPaymentsTxnType = $this->checkCustomerPaymentsTransactionType();

        return [
            'authUser'                => $authUser,
            'isSuperadmin'            => $isSuperadmin,
            'targetEmployee'          => $targetEmployee,
            'targetUser'              => $targetUser,
            'employeeOptions'         => $employeeOptions,
            'period'                  => $period,
            'periodLabel'             => $periodLabel,
            'range'                   => $range,
            'customerPaymentsTxnType' => $customerPaymentsTxnType,
            'scaffoldingOnly'         => false,
        ];
    }

    /**
     * Phase 6 — Cache::remember() wrapper with built-in slow-query
     * telemetry.
     *
     * Cache key format: perf:user:{id}:{metric}:{period}:{rangeHash}
     *   - {id} is userId for activity metrics, employeeId for portfolio
     *     metrics (commission). Both flows use the same key namespace.
     *   - {rangeHash} is md5(start_end) so custom ranges work.
     *
     * TTL: 60 seconds. Short enough that fresh data appears within a
     * minute, long enough to amortize the 25+ queries on repeat visits /
     * AJAX refreshes. The TTL is the invalidation mechanism — no Eloquent
     * observers needed.
     *
     * Inside the cache miss branch, $this->timed() wraps the callable and
     * logs slow ones (>200ms) to storage/logs/perf.log.
     *
     * @param string   $metric  Short metric key (e.g. 'sales_kpis')
     * @param int      $id      userId OR employeeId (depending on metric)
     * @param string   $period  Period key ('mtd', 'qtd', etc.)
     * @param array    $range   ['start' => YYYY-MM-DD, 'end' => YYYY-MM-DD]
     * @param callable $fn      The metric computation
     * @param int      $ttl     Cache TTL in seconds (default 60)
     */
    private function cached(string $metric, int $id, string $period, array $range, callable $fn, int $ttl = 60)
    {
        if ($id <= 0) {
            // No user → just compute (and time) without caching. Avoids
            // polluting the cache with junk keys for unauthenticated /
            // scaffolding-only requests.
            return $this->timed($metric, $fn);
        }
        $rangeHash = md5($range['start'] . '_' . $range['end']);
        $key = "perf:user:{$id}:{$metric}:{$period}:{$rangeHash}";
        return Cache::remember($key, $ttl, function () use ($metric, $fn) {
            return $this->timed($metric, $fn);
        });
    }

    /**
     * Phase 6 — Time a callable and log slow ones (>200ms) to
     * storage/logs/perf.log via Log::build on-demand channel.
     *
     * Uses Log::build() to create a one-off single-file channel pointing
     * at storage_path('logs/perf.log') — no need to modify config/logging.php.
     * The file accumulates slow-metric warnings across requests, making it
     * easy to spot the heaviest queries during a perf audit.
     */
    private function timed(string $label, callable $fn)
    {
        $start = microtime(true);
        try {
            return $fn();
        } finally {
            $elapsedMs = (microtime(true) - $start) * 1000;
            if ($elapsedMs > 200.0) {
                try {
                    $req = request();
                    $userId = $req?->user()?->id ?? 0;
                    $period = $req?->input('period', 'mtd');
                    $empId = $req?->input('employee_id', '-');
                    Log::build([
                        'driver' => 'single',
                        'path'   => storage_path('logs/perf.log'),
                        'level'  => 'warning',
                    ])->warning(sprintf(
                        '[perf] slow metric %s took %.1f ms (user=%d, employee_id=%s, period=%s)',
                        $label,
                        $elapsedMs,
                        $userId,
                        $empId,
                        $period
                    ));
                } catch (\Throwable $_) {
                    // Telemetry must never break the dashboard.
                }
            }
        }
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
     * Supported values: today, mtd, qtd, last30, custom.
     * For 'custom', reads ?from= and ?to= query params (YYYY-MM-DD); falls
     * back to MTD if either is missing/invalid.
     *
     * NOTE: 'ytd' (Year to Date) was removed per request — it scanned ~365
     * days of partitioned sales/payment data and was the slowest period
     * option. Old ?period=ytd links now fall through to the MTD default
     * (graceful degradation — no 500, no broken bookmarks).
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
                // 'ytd' and any unknown value land here as MTD (graceful
                // degradation for old bookmarks / shared links).
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

    // ============================================================
    // PHASE 2 — COLLECTIONS & RETURNS METRICS
    // ============================================================
    //
    // Phase 2 query conventions:
    //   - customer_payments (NOT partitioned):
    //       WHERE created_by = $userId
    //         AND payment_date BETWEEN $range   (period-bound metrics)
    //         AND is_reversed = false
    //         AND deleted_at IS NULL (column may not exist — guard via try/catch)
    //   - sales_returns (NOT partitioned):
    //       WHERE created_by = $userId
    //         AND return_date BETWEEN $range
    //         AND is_reversed = false
    //   - sales_invoices (partitioned):
    //       Period-bound: WHERE invoice_date BETWEEN $range
    //       Snapshot (outstanding/aging): no period filter — reflect current book.
    //
    // Collection Rate (C2) = Σ customer_payments.amount (created_by=?, period,
    //   transaction_type='receive') / NULLIF(Σ sales_invoices.total_amount
    //   (created_by=?, period), 0) * 100. NOT the company-wide ratio.
    //
    // Overdue (C4) uses an assumed 30-day term until G3 adds due_date.
    //   Label "Overdue (>30 days)" in the UI per the plan.
    //
    // Discount Allowed (C7) sums customer_payments.discount_amount for the
    //   user's payments in the period. (transaction_type='discount' rows are
    //   a separate write-off; C7 specifically tracks the inline discount
    //   field on receive-type payments.)

    /**
     * Collection KPIs for the user: count, value, rate, outstanding,
     * overdue count + value, discount allowed.
     *
     * @param  bool  $hasTxnType  Result of checkCustomerPaymentsTransactionType()
     *                            — when true, we filter transaction_type='receive'
     *                            so that 'discount'/'write_off'/'payment' rows
     *                            are excluded from the collection volume.
     * @return array{
     *   collection_count:int, collection_value:float, collection_rate:float,
     *   outstanding:float, overdue_count:int, overdue_value:float,
     *   discount_allowed:float, prev_collection_value:float, growth_pct:float
     * }
     */
    private function getCollectionKPIs(int $userId, array $range, bool $hasTxnType): array
    {
        $zero = [
            'collection_count'     => 0,
            'collection_value'     => 0.0,
            'collection_rate'      => 0.0,
            'outstanding'          => 0.0,
            'overdue_count'        => 0,
            'overdue_value'        => 0.0,
            'discount_allowed'     => 0.0,
            'prev_collection_value'=> 0.0,
            'growth_pct'           => 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // --- C1: collection count + value (transaction_type='receive' if column exists) ---
            $cpQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $cpQuery->where('transaction_type', 'receive');
            }
            $c1 = (clone $cpQuery)->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total')->first();
            $collectionCount = (int) ($c1->cnt ?? 0);
            $collectionValue = (float) ($c1->total ?? 0);

            // --- C7: discount allowed (sum of discount_amount on receive-type payments in period) ---
            $discountQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $discountQuery->where('transaction_type', 'receive');
            }
            $discountAllowed = (float) (clone $discountQuery)->sum('discount_amount');

            // --- C2: collection rate = collection / sales * 100 (same period, same user) ---
            $periodSales = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('total_amount');
            $collectionRate = $periodSales > 0
                ? round(($collectionValue / $periodSales) * 100, 1)
                : 0.0;

            // --- C3: my outstanding (point-in-time snapshot — ALL the user's invoices) ---
            $outstanding = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('due_amount');

            // --- C4: overdue (>30 days, assumed term per G3) ---
            //   Count + sum of due_amount on user's invoices older than 30 days
            //   with a positive balance.
            $overdueCutoff = now()->subDays(30)->toDateString();
            $overdueRow = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->where('due_amount', '>', 0)
                ->where('invoice_date', '<', $overdueCutoff)
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(due_amount),0) AS total')
                ->first();
            $overdueCount = (int) ($overdueRow->cnt ?? 0);
            $overdueValue = (float) ($overdueRow->total ?? 0);

            // --- Growth vs previous period (for the collection value delta pill) ---
            $prevRange = $this->previousPeriodRange($range);
            $prevQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$prevRange['start'], $prevRange['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $prevQuery->where('transaction_type', 'receive');
            }
            $prevCollectionValue = (float) (clone $prevQuery)->sum('amount');
            $growthPct = $prevCollectionValue > 0
                ? round((($collectionValue - $prevCollectionValue) / $prevCollectionValue) * 100, 1)
                : 0.0;

            return [
                'collection_count'      => $collectionCount,
                'collection_value'      => $collectionValue,
                'collection_rate'       => $collectionRate,
                'outstanding'           => $outstanding,
                'overdue_count'         => $overdueCount,
                'overdue_value'         => $overdueValue,
                'discount_allowed'      => $discountAllowed,
                'prev_collection_value' => $prevCollectionValue,
                'growth_pct'            => $growthPct,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getCollectionKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Receivable aging snapshot — 5 buckets, scoped to the user's book.
     *
     * Same CASE expression as LegacyDashboardController::getReceivableAging()
     * but with `AND created_by = $userId`. Point-in-time (no period filter).
     *
     * @return array{Current:float,1-30:float,31-60:float,61-90:float,90+:float,total:float}
     */
    private function getReceivableAging(int $userId): array
    {
        $empty = [
            'Current'  => 0.0,
            '1-30'     => 0.0,
            '31-60'    => 0.0,
            '61-90'    => 0.0,
            '90+'      => 0.0,
            'total'    => 0.0,
        ];
        if ($userId <= 0) {
            return $empty;
        }
        try {
            $rows = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->where('due_amount', '>', 0)
                ->selectRaw("
                    CASE
                        WHEN invoice_date >= CURRENT_DATE THEN 'Current'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '30 days' THEN '1-30'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '60 days' THEN '31-60'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '90 days' THEN '61-90'
                        ELSE '90+'
                    END AS bucket,
                    COALESCE(SUM(due_amount), 0) AS total_due
                ")
                ->groupBy('bucket')
                ->get();

            $buckets = $empty;
            foreach ($rows as $row) {
                if (array_key_exists($row->bucket, $buckets)) {
                    $buckets[$row->bucket] = (float) $row->total_due;
                }
            }
            $buckets['total'] = array_sum([
                $buckets['Current'], $buckets['1-30'], $buckets['31-60'],
                $buckets['61-90'], $buckets['90+'],
            ]);
            return $buckets;
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getReceivableAging failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Return KPIs for the user: count, value, rate, top return reasons.
     *
     * R1 = COUNT(sales_returns WHERE created_by=? AND period AND is_reversed=false)
     * R2 = SUM(total_amount WHERE same + status='confirmed')
     * R3 = R2 / NULLIF(period sales, 0) * 100
     * R5 = GROUP BY reason, top 5 by count (fallback: status if reason is mostly null)
     *
     * @return array{
     *   return_count:int, return_value:float, return_rate:float,
     *   prev_return_value:float, growth_pct:float,
     *   top_reasons: array<int, array{reason:string,count:int,value:float}>
     * }
     */
    private function getReturnKPIs(int $userId, array $range): array
    {
        $zero = [
            'return_count'      => 0,
            'return_value'      => 0.0,
            'return_rate'       => 0.0,
            'prev_return_value' => 0.0,
            'growth_pct'        => 0.0,
            'top_reasons'       => [],
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // --- R1: return count (all non-reversed returns) ---
            $returnCount = (int) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->count();

            // --- R2: return value (confirmed only) ---
            $returnValue = (float) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->where('status', 'confirmed')
                ->sum('total_amount');

            // --- R3: return rate = return value / period sales * 100 ---
            $periodSales = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('total_amount');
            $returnRate = $periodSales > 0
                ? round(($returnValue / $periodSales) * 100, 2)
                : 0.0;

            // --- Growth vs previous period ---
            $prevRange = $this->previousPeriodRange($range);
            $prevReturnValue = (float) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$prevRange['start'], $prevRange['end']])
                ->where('is_reversed', false)
                ->where('status', 'confirmed')
                ->sum('total_amount');
            $growthPct = $prevReturnValue > 0
                ? round((($returnValue - $prevReturnValue) / $prevReturnValue) * 100, 1)
                : 0.0;

            // --- R5: top return reasons ---
            //   Group by COALESCE(reason, '(No reason given)') so nulls
            //   bucket together. If almost all rows have null reason, the
            //   chart will still show one big bucket — that's the "coaching
            //   signal" the plan calls out.
            $reasons = DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->groupBy('reason_bucket')
                ->orderByDesc('cnt')
                ->limit(5)
                ->selectRaw("
                    COALESCE(NULLIF(TRIM(reason), ''), '(No reason given)') AS reason_bucket,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(total_amount), 0) AS total
                ")
                ->get();
            $topReasons = $reasons->map(fn ($r) => [
                'reason' => $r->reason_bucket,
                'count'  => (int) $r->cnt,
                'value'  => (float) $r->total,
            ])->values()->toArray();

            return [
                'return_count'      => $returnCount,
                'return_value'      => $returnValue,
                'return_rate'       => $returnRate,
                'prev_return_value' => $prevReturnValue,
                'growth_pct'        => $growthPct,
                'top_reasons'       => $topReasons,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getReturnKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Payment mode mix — C8: bank vs cash vs cheque vs mobile_banking.
     *
     * Returns counts + totals per payment_mode for the user's receive-type
     * payments in the period. Used for the donut chart + breakdown legend.
     *
     * @return array<int, array{mode:string,label:string,count:int,value:float,share:float}>
     */
    private function getPaymentModeMix(int $userId, array $range): array
    {
        if ($userId <= 0) {
            return [];
        }
        // Friendly labels + colors for the donut chart.
        $labels = [
            'cash'            => 'Cash',
            'bank'            => 'Bank Transfer',
            'cheque'          => 'Cheque',
            'mobile_banking'  => 'Mobile Banking',
            'adjustment'      => 'Adjustment',
        ];
        try {
            $rows = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->where('transaction_type', 'receive')
                ->groupBy('payment_mode')
                ->orderByDesc('total')
                ->selectRaw("
                    payment_mode,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(amount), 0) AS total
                ")
                ->get();

            $grand = $rows->sum(fn ($r) => (float) $r->total);
            return $rows->map(function ($r) use ($labels, $grand) {
                $mode = $r->payment_mode;
                return [
                    'mode'   => $mode,
                    'label'  => $labels[$mode] ?? ucfirst(str_replace('_', ' ', $mode)),
                    'count'  => (int) $r->cnt,
                    'value'  => (float) $r->total,
                    'share'  => $grand > 0 ? round(((float) $r->total / $grand) * 100, 1) : 0.0,
                ];
            })->values()->toArray();
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getPaymentModeMix failed: ' . $e->getMessage());
            return [];
        }
    }

    // ============================================================
    // PHASE 3 — OPERATIONAL EFFICIENCY & PRODUCTIVITY METRICS
    // ============================================================
    //
    // Phase 3 query conventions:
    //   - Velocity (O1/O2/O3/O4): sales_invoices lifecycle timestamps.
    //     WHERE created_by = $userId AND invoice_date BETWEEN $range
    //     (partition-pruned) AND is_reversed=false AND status NOT IN
    //     ('cancelled','reversed','draft') AND deleted_at IS NULL.
    //     Each AVG only counts rows where the relevant timestamp is set
    //     (e.g., AVG(invoice→godown) only for is_godown_prepared=true).
    //   - Pipeline (O5/O6/O8): point-in-time snapshot — no period filter.
    //   - Work pattern (A4): hourly histogram UNIONed across 6 activity
    //     tables. Each table contributes (hour, count) rows for the user
    //     within $range; results summed per hour. The histogram always
    //     returns 24 bins (0..23), zero-filled for empty hours.
    //   - Activity summary (A1/A2/A3): cross-table active days = COUNT
    //     DISTINCT date across the same 6 tables UNIONed. Transactions
    //     per day = total activity count / active days. Peak day = day
    //     with the highest total activity.
    //   - Notification engagement (A7): notifications.user_id = $userId,
    //     read_rate = is_read=true / total * 100. NO period filter
    //     (notifications are already scoped to the user).
    //
    // Every method wrapped in try/catch with safe defaults so a missing
    // table or column doesn't break the dashboard.

    /**
     * Velocity KPIs: invoice→godown, godown→challan, invoice→challan avg hours,
     * same-day dispatch %.
     *
     * O1 = AVG(EXTRACT(EPOCH FROM (godown_prepared_at - created_at))/3600)
     *      WHERE is_godown_prepared=true AND godown_prepared_at IS NOT NULL
     * O2 = AVG(EXTRACT(EPOCH FROM (challan_issued_at - godown_prepared_at))/3600)
     *      WHERE is_challan_issued=true AND challan_issued_at IS NOT NULL
     *      AND godown_prepared_at IS NOT NULL
     * O3 = AVG(EXTRACT(EPOCH FROM (challan_issued_at - created_at))/3600)
     *      WHERE is_challan_issued=true AND challan_issued_at IS NOT NULL
     * O4 = COUNT(*) WHERE challan_issued_at::date = invoice_date
     *      / NULLIF(COUNT(*) WHERE is_challan_issued=true, 0) * 100
     *
     * @return array{
     *   avg_invoice_to_godown_hrs:?float, avg_godown_to_challan_hrs:?float,
     *   avg_invoice_to_challan_hrs:?float, same_day_dispatch_pct:float,
     *   dispatched_count:int, total_invoices:int
     * }
     */
    private function getVelocityKPIs(int $userId, array $range): array
    {
        $zero = [
            'avg_invoice_to_godown_hrs'    => null,
            'avg_godown_to_challan_hrs'    => null,
            'avg_invoice_to_challan_hrs'   => null,
            'same_day_dispatch_pct'        => 0.0,
            'dispatched_count'             => 0,
            'total_invoices'               => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Single query — 4 aggregates in one pass for efficiency.
            $row = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) AS total_invoices,
                    COUNT(*) FILTER (WHERE is_challan_issued = true) AS dispatched_count,
                    AVG(EXTRACT(EPOCH FROM (godown_prepared_at - created_at)) / 3600)
                        FILTER (WHERE is_godown_prepared = true AND godown_prepared_at IS NOT NULL) AS avg_i2g,
                    AVG(EXTRACT(EPOCH FROM (challan_issued_at - godown_prepared_at)) / 3600)
                        FILTER (WHERE is_challan_issued = true AND challan_issued_at IS NOT NULL AND godown_prepared_at IS NOT NULL) AS avg_g2c,
                    AVG(EXTRACT(EPOCH FROM (challan_issued_at - created_at)) / 3600)
                        FILTER (WHERE is_challan_issued = true AND challan_issued_at IS NOT NULL) AS avg_i2c,
                    COUNT(*) FILTER (WHERE is_challan_issued = true AND challan_issued_at::date = invoice_date) AS same_day
                ")
                ->first();

            $total = (int) ($row->total_invoices ?? 0);
            $dispatched = (int) ($row->dispatched_count ?? 0);
            $sameDay = (int) ($row->same_day ?? 0);
            $sameDayPct = $dispatched > 0 ? round(($sameDay / $dispatched) * 100, 1) : 0.0;

            return [
                'avg_invoice_to_godown_hrs'  => $row->avg_i2g !== null ? round((float) $row->avg_i2g, 1) : null,
                'avg_godown_to_challan_hrs'  => $row->avg_g2c !== null ? round((float) $row->avg_g2c, 1) : null,
                'avg_invoice_to_challan_hrs' => $row->avg_i2c !== null ? round((float) $row->avg_i2c, 1) : null,
                'same_day_dispatch_pct'      => $sameDayPct,
                'dispatched_count'           => $dispatched,
                'total_invoices'             => $total,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getVelocityKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Pipeline snapshot — point-in-time view of the user's WIP.
     *
     * O5 = stale drafts (status='draft' AND created_at < CURRENT_DATE - 7)
     * O6 = open pipeline value (status='confirmed' AND is_challan_issued=false)
     * O8 = parked sales (call_a_day=true) — "removed from today list"
     *
     * @return array{
     *   stale_draft_count:int, open_pipeline_value:float,
     *   parked_sales_count:int, draft_count:int, confirmed_pending_dispatch:int
     * }
     */
    private function getPipelineSnapshot(int $userId): array
    {
        $zero = [
            'stale_draft_count'            => 0,
            'open_pipeline_value'          => 0.0,
            'parked_sales_count'           => 0,
            'draft_count'                  => 0,
            'confirmed_pending_dispatch'   => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Single query — 5 aggregates in one pass.
            $row = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE status = 'draft') AS draft_count,
                    COUNT(*) FILTER (WHERE status = 'draft' AND created_at < CURRENT_DATE - INTERVAL '7 days') AS stale_draft,
                    COUNT(*) FILTER (WHERE status = 'confirmed' AND is_challan_issued = false) AS confirmed_pending,
                    COALESCE(SUM(total_amount) FILTER (WHERE status = 'confirmed' AND is_challan_issued = false), 0) AS pipeline_value,
                    COUNT(*) FILTER (WHERE call_a_day = true) AS parked
                ")
                ->first();

            return [
                'stale_draft_count'          => (int) ($row->stale_draft ?? 0),
                'open_pipeline_value'        => (float) ($row->pipeline_value ?? 0),
                'parked_sales_count'         => (int) ($row->parked ?? 0),
                'draft_count'                => (int) ($row->draft_count ?? 0),
                'confirmed_pending_dispatch' => (int) ($row->confirmed_pending ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getPipelineSnapshot failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Work-pattern histogram — 24-bin hour-of-day distribution.
     *
     * UNIONs activity across 6 tables (sales_invoices, customer_payments,
     * sales_returns, sales_challans, stock_adjustments, damage_invoices),
     * each filtered by created_by=$userId AND created_at BETWEEN $range.
     * Returns a 24-element array [{hour:0..23, count}], zero-filled.
     *
     * @return array<int, array{hour:int, count:int}>
     */
    private function getWorkPattern(int $userId, array $range): array
    {
        $empty = array_map(fn ($h) => ['hour' => $h, 'count' => 0], range(0, 23));
        if ($userId <= 0) {
            return $empty;
        }
        // Build the UNION ALL query as a raw SQL — Laravel's query builder
        // doesn't compose UNIONs ergonomically. Each arm pulls the hour-of-day
        // and a count bucketed by hour for one table. If a table is missing
        // or has no created_at/created_by columns, the arm's catch will skip
        // it (the surrounding try/catch falls back to all-zero).
        $startTs = $range['start'] . ' 00:00:00';
        $endTs   = $range['end'] . ' 23:59:59';

        $arms = [
            'sales_invoices',
            'customer_payments',
            'sales_returns',
            'sales_challans',
            'stock_adjustments',
            'damage_invoices',
        ];
        $unionParts = [];
        foreach ($arms as $tbl) {
            $unionParts[] = "SELECT EXTRACT(HOUR FROM created_at)::int AS hr, COUNT(*) AS cnt
                             FROM {$tbl}
                             WHERE created_by = ? AND created_at BETWEEN ? AND ?
                             GROUP BY hr";
        }
        $sql = "SELECT hr, SUM(cnt) AS total
                FROM (" . implode(' UNION ALL ', $unionParts) . ") AS u
                GROUP BY hr";

        try {
            // Bind userId + range params N times (one per arm).
            $bindings = [];
            foreach ($arms as $_) {
                $bindings[] = $userId;
                $bindings[] = $startTs;
                $bindings[] = $endTs;
            }
            $rows = DB::select($sql, $bindings);

            $byHour = [];
            foreach ($rows as $r) {
                $byHour[(int) $r->hr] = (int) $r->total;
            }
            $result = [];
            for ($h = 0; $h < 24; $h++) {
                $result[] = ['hour' => $h, 'count' => $byHour[$h] ?? 0];
            }
            return $result;
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getWorkPattern failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Activity summary — transactions per day, cross-table active days,
     * peak day (the day with the most total activity by the user).
     *
     * A1 = total activity count / cross-table active days
     * A2 = COUNT(DISTINCT DATE(created_at)) UNIONed across the 6 tables
     * A3 = day with the most total activity (count + date)
     *
     * @return array{
     *   transactions_per_day:float, active_days_cross_table:int,
     *   total_activity:int, peak_day:?string, peak_day_count:int
     * }
     */
    private function getActivitySummary(int $userId, array $range): array
    {
        $zero = [
            'transactions_per_day'   => 0.0,
            'active_days_cross_table'=> 0,
            'total_activity'         => 0,
            'peak_day'               => null,
            'peak_day_count'         => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        $startTs = $range['start'] . ' 00:00:00';
        $endTs   = $range['end'] . ' 23:59:59';

        $arms = [
            'sales_invoices',
            'customer_payments',
            'sales_returns',
            'sales_challans',
            'stock_adjustments',
            'damage_invoices',
        ];
        // Arm 1: per-date activity counts (for peak day + total)
        $countParts = [];
        foreach ($arms as $tbl) {
            $countParts[] = "SELECT DATE(created_at) AS d, COUNT(*) AS cnt
                             FROM {$tbl}
                             WHERE created_by = ? AND created_at BETWEEN ? AND ?
                             GROUP BY DATE(created_at)";
        }
        $countSql = "SELECT d, SUM(cnt) AS total FROM (" .
                    implode(' UNION ALL ', $countParts) . ") AS u
                    GROUP BY d ORDER BY total DESC LIMIT 1";

        // Arm 2: distinct dates (cross-table active days)
        $distinctParts = [];
        foreach ($arms as $tbl) {
            $distinctParts[] = "SELECT DISTINCT DATE(created_at) AS d
                                FROM {$tbl}
                                WHERE created_by = ? AND created_at BETWEEN ? AND ?";
        }
        $distinctSql = "SELECT COUNT(*) AS cnt FROM (" .
                       implode(' UNION ALL ', $distinctParts) . ") AS u";

        try {
            $bindings1 = [];
            foreach ($arms as $_) {
                $bindings1[] = $userId;
                $bindings1[] = $startTs;
                $bindings1[] = $endTs;
            }
            $peakRow = DB::selectOne($countSql, $bindings1);

            $bindings2 = [];
            foreach ($arms as $_) {
                $bindings2[] = $userId;
                $bindings2[] = $startTs;
                $bindings2[] = $endTs;
            }
            $activeRow = DB::selectOne($distinctSql, $bindings2);

            // Total activity count (sum across all 6 tables in range)
            // — computed as a separate simple SUM query for clarity.
            $totalParts = [];
            foreach ($arms as $tbl) {
                $totalParts[] = "SELECT COUNT(*) AS cnt FROM {$tbl}
                                 WHERE created_by = ? AND created_at BETWEEN ? AND ?";
            }
            $totalSql = "SELECT SUM(cnt) AS total FROM (" .
                        implode(' UNION ALL ', $totalParts) . ") AS u";
            $bindings3 = [];
            foreach ($arms as $_) {
                $bindings3[] = $userId;
                $bindings3[] = $startTs;
                $bindings3[] = $endTs;
            }
            $totalRow = DB::selectOne($totalSql, $bindings3);

            $activeDays = (int) ($activeRow->cnt ?? 0);
            $totalActivity = (int) ($totalRow->total ?? 0);
            $txnsPerDay = $activeDays > 0 ? round($totalActivity / $activeDays, 1) : 0.0;

            return [
                'transactions_per_day'    => $txnsPerDay,
                'active_days_cross_table' => $activeDays,
                'total_activity'          => $totalActivity,
                'peak_day'                => isset($peakRow->d) ? (string) $peakRow->d : null,
                'peak_day_count'          => isset($peakRow->total) ? (int) $peakRow->total : 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getActivitySummary failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Notification engagement — read rate for the user's notifications.
     *
     * A7 = COUNT(*) WHERE is_read=true / NULLIF(COUNT(*), 0) * 100.
     * NO period filter — notifications are already scoped to user_id.
     * Also returns total + unread counts for the UI badge.
     *
     * @return array{read_rate:float, total:int, unread:int, read:int}
     */
    private function getNotificationEngagement(int $userId): array
    {
        $zero = ['read_rate' => 0.0, 'total' => 0, 'unread' => 0, 'read' => 0];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            $row = DB::table('notifications')
                ->where('user_id', $userId)
                ->selectRaw("
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE is_read = true) AS read_cnt,
                    COUNT(*) FILTER (WHERE is_read = false) AS unread_cnt
                ")
                ->first();
            $total = (int) ($row->total ?? 0);
            $read = (int) ($row->read_cnt ?? 0);
            $unread = (int) ($row->unread_cnt ?? 0);
            $rate = $total > 0 ? round(($read / $total) * 100, 1) : 0.0;
            return [
                'read_rate' => $rate,
                'total'     => $total,
                'unread'    => $unread,
                'read'      => $read,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getNotificationEngagement failed: ' . $e->getMessage());
            return $zero;
        }
    }

    // ============================================================
    // PHASE 4 — COMMISSION, STOCK DISCIPLINE & ACCURACY
    // ============================================================
    //
    // Phase 4 closes out the metric catalogue from the plan:
    //   - Commission & targets (salesman-role only; hidden for others).
    //   - Stock discipline (stock_adjustments, damage_invoices with
    //     accountable_employee_id, warehouse_transfers).
    //   - Accuracy / error-rate scorecard (reversed + cancelled counts
    //     across sales_invoices, customer_payments, sales_returns,
    //     sales_challans, damage_invoices).
    //
    // ATTRIBUTION:
    //   - Commission queries use salesman_id = $employeeId (NOT created_by)
    //     because commission_entries is a salesman ledger, not a user ledger.
    //   - Stock discipline + accuracy use created_by = $userId for activity
    //     metrics (who created the adjustment, the return, the invoice) AND
    //     accountable_employee_id = $employeeId for the damage-blame metric
    //     (K11 in the plan: "highlight in red if > 0").

    /**
     * Commission summary for the target employee (salesman).
     *
     * Pulls commission_entries grouped by status, plus the active rule +
     * target (if any). Returns net commission (sum of non-reversed entries),
     * status breakdown, attainment %, and the active rule metadata.
     *
     * No period filter on the status breakdown — commission_entries is a
     * ledger, not partitioned, so we filter by entry_date for the period
     * piece (the "calculated this period" amount) AND by status for the
     * lifetime piece (paid to date).
     *
     * @return array{
     *   net_commission: float, calculated: float, confirmed: float,
     *   paid: float, reversed: float, total_to_date: float,
     *   attainment_pct: float, target_amount: float, sales_to_date: float,
     *   has_rule: bool, rule_type: string|null, rate: float|null,
     *   period_label: string
     * }
     */
    private function getCommissionSummary(int $employeeId, array $range): array
    {
        $zero = [
            'net_commission'  => 0.0,
            'calculated'      => 0.0,
            'confirmed'       => 0.0,
            'paid'            => 0.0,
            'reversed'        => 0.0,
            'total_to_date'   => 0.0,
            'attainment_pct'  => 0.0,
            'target_amount'   => 0.0,
            'sales_to_date'   => 0.0,
            'has_rule'        => false,
            'rule_type'       => null,
            'rate'            => null,
            'period_label'    => '',
        ];
        if ($employeeId <= 0) {
            return $zero;
        }
        try {
            // ── 1. Lifetime status breakdown (paid to date, confirmed, etc.)
            //    Single query, FILTER clauses per status.
            $statusRow = DB::table('commission_entries')
                ->where('salesman_id', $employeeId)
                ->selectRaw("
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'calculated' AND is_reversed = false), 0) AS calculated,
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'confirmed'  AND is_reversed = false), 0) AS confirmed,
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'paid'       AND is_reversed = false), 0) AS paid,
                    COALESCE(SUM(commission_amount) FILTER (WHERE is_reversed = true), 0) AS reversed,
                    COALESCE(SUM(commission_amount) FILTER (WHERE is_reversed = false), 0) AS total_to_date
                ")
                ->first();

            // ── 2. Period commission (this period only, by entry_date)
            $periodRow = DB::table('commission_entries')
                ->where('salesman_id', $employeeId)
                ->whereBetween('entry_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("COALESCE(SUM(commission_amount), 0) AS period_total")
                ->first();
            $periodNet = (float) ($periodRow->period_total ?? 0);

            // ── 3. Active commission rule (one open-ended active rule per salesman,
            //    enforced by EXCLUDE constraint — so we can take ->first()).
            $rule = DB::table('commission_rules')
                ->where('salesman_id', $employeeId)
                ->where('is_active', true)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->first();

            $hasRule  = $rule !== null;
            $ruleType = $hasRule ? $rule->rule_type : null;
            $rate     = $hasRule ? (float) $rule->rate : null;

            // ── 4. Target (for 'target_bonus' rules) — monthly/quarterly/yearly.
            $targetAmount = 0.0;
            if ($hasRule && $ruleType === 'target_bonus') {
                $target = DB::table('commission_rule_targets')
                    ->where('commission_rule_id', $rule->id)
                    ->where('period', 'monthly')
                    ->first();
                $targetAmount = $target ? (float) $target->target_amount : 0.0;
            }

            // ── 5. Sales-to-date (this month) for attainment %.
            //    Uses salesman_id (the salesman's portfolio of invoices),
            //    not created_by. Filtered by this month for monthly target
            //    comparison.
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd   = now()->endOfMonth()->toDateString();
            $salesRow = DB::table('sales_invoices')
                ->where('salesman_id', $employeeId)
                ->whereBetween('invoice_date', [$monthStart, $monthEnd])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->selectRaw("COALESCE(SUM(total_amount), 0) AS month_sales")
                ->first();
            $salesToDate = (float) ($salesRow->month_sales ?? 0);

            $attainment = $targetAmount > 0
                ? round(min(150, ($salesToDate / $targetAmount) * 100), 1)
                : 0.0;

            return [
                'net_commission'  => $periodNet,
                'calculated'      => (float) ($statusRow->calculated ?? 0),
                'confirmed'       => (float) ($statusRow->confirmed ?? 0),
                'paid'            => (float) ($statusRow->paid ?? 0),
                'reversed'        => (float) ($statusRow->reversed ?? 0),
                'total_to_date'   => (float) ($statusRow->total_to_date ?? 0),
                'attainment_pct'  => $attainment,
                'target_amount'   => $targetAmount,
                'sales_to_date'   => $salesToDate,
                'has_rule'        => $hasRule,
                'rule_type'       => $ruleType,
                'rate'            => $rate,
                'period_label'    => now()->format('M Y'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getCommissionSummary failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Stock-discipline scorecard for the target user/employee.
     *
     * K-catalogue metrics:
     *   K1  adjustments_initiated   — COUNT(stock_adjustments) by created_by
     *   K2  adjustment_value        — SUM(total_amount) for 'decrease' adjustments
     *   K3  loss_adjustments        — subset of K1 with adjustment_type='decrease'
     *   K4  accountable_damages     — SUM(damage_invoice_items.qty*rate) where
     *                                 accountable_employee_id = $employeeId
     *                                 (K11 in plan; red highlight if > 0)
     *   K5  damage_recovery         — (not implemented; placeholder 0)
     *   K6  stock_take_variances    — COUNT(stock_adjustments with
     *                                 adjustment_category='reconciliation_variance')
     *   K7  transfers_initiated     — COUNT(warehouse_transfers) by created_by
     *
     * Partitioned-table safe: stock_adjustments and damage_invoices are
     * NOT partitioned in this schema, but we still date-filter for index
     * usage (adjustment_date / damage_date / transfer_date BETWEEN).
     *
     * @return array{
     *   adjustments_initiated: int, adjustment_value: float,
     *   loss_adjustments: int, accountable_damages: float,
     *   damage_recovery: float, stock_take_variances: int,
     *   transfers_initiated: int, accountable_damages_count: int
     * }
     */
    private function getStockDiscipline(int $userId, int $employeeId, array $range): array
    {
        $zero = [
            'adjustments_initiated'    => 0,
            'adjustment_value'         => 0.0,
            'loss_adjustments'         => 0,
            'accountable_damages'      => 0.0,
            'accountable_damages_count'=> 0,
            'damage_recovery'          => 0.0,
            'stock_take_variances'     => 0,
            'transfers_initiated'      => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── K1, K2, K3, K6: stock_adjustments aggregates in one query
            $saRow = DB::table('stock_adjustments')
                ->where('created_by', $userId)
                ->whereBetween('adjustment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("
                    COUNT(*) AS total_cnt,
                    COUNT(*) FILTER (WHERE adjustment_type = 'decrease') AS loss_cnt,
                    COALESCE(SUM(total_amount) FILTER (WHERE adjustment_type = 'decrease'), 0) AS loss_value,
                    COUNT(*) FILTER (WHERE adjustment_category = 'reconciliation_variance') AS variance_cnt
                ")
                ->first();

            // ── K4, K11: accountable damages (damage_invoices where this
            //    employee is the accountable party). damage_invoices is NOT
            //    partitioned but we date-filter on damage_date.
            //    Value = SUM(dii.qty * dii.rate) joined.
            $dmgRow = DB::table('damage_invoices as di')
                ->join('damage_invoice_items as dii', 'dii.damage_invoice_id', '=', 'di.id')
                ->where('di.accountable_employee_id', $employeeId)
                ->whereBetween('di.damage_date', [$range['start'], $range['end']])
                ->where('di.is_reversed', false)
                ->whereNotNull('di.accountable_employee_id')
                ->selectRaw("
                    COUNT(DISTINCT di.id) AS dmg_count,
                    COALESCE(SUM(dii.qty * dii.rate), 0) AS dmg_value
                ")
                ->first();

            // ── K7: warehouse transfers initiated
            $wtRow = DB::table('warehouse_transfers')
                ->where('created_by', $userId)
                ->whereBetween('transfer_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("COUNT(*) AS transfer_cnt")
                ->first();

            return [
                'adjustments_initiated'    => (int) ($saRow->total_cnt ?? 0),
                'adjustment_value'         => (float) ($saRow->loss_value ?? 0),
                'loss_adjustments'         => (int) ($saRow->loss_cnt ?? 0),
                'accountable_damages'      => (float) ($dmgRow->dmg_value ?? 0),
                'accountable_damages_count'=> (int) ($dmgRow->dmg_count ?? 0),
                'damage_recovery'          => 0.0, // placeholder — recovery tracking is post-launch
                'stock_take_variances'     => (int) ($saRow->variance_cnt ?? 0),
                'transfers_initiated'      => (int) ($wtRow->transfer_cnt ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getStockDiscipline failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Accuracy / error-rate scorecard for the target user.
     *
     * X-catalogue metrics (composite error rate X10):
     *   X1  reversed_invoices    — sales_invoices where is_reversed=true
     *   X2  cancelled_invoices   — sales_invoices where status='cancelled'
     *   X3  reversed_payments    — customer_payments where is_reversed=true
     *   X4  reversed_returns     — sales_returns where is_reversed=true
     *   X5  reversed_challans    — sales_challans where is_reversed=true
     *   X10 composite_error_rate = reversed+cancelled / total_attempts
     *
     * All counts are period-filtered on the table's primary date column
     * for partition pruning (sales_invoices is partitioned by invoice_date;
     * customer_payments by payment_date; sales_returns by return_date;
     * sales_challans by challan_date if present, else created_at).
     *
     * @return array{
     *   reversed_invoices: int, cancelled_invoices: int,
     *   reversed_payments: int, reversed_returns: int,
     *   reversed_challans: int, manual_journals: int,
     *   total_actions: int, composite_error_rate: float
     * }
     */
    private function getAccuracyKPIs(int $userId, array $range): array
    {
        $zero = [
            'reversed_invoices'   => 0,
            'cancelled_invoices'  => 0,
            'reversed_payments'   => 0,
            'reversed_returns'    => 0,
            'reversed_challans'   => 0,
            'manual_journals'     => 0,
            'total_actions'       => 0,
            'composite_error_rate'=> 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── X1, X2: sales_invoices — reversed + cancelled in one query
            //    (sales_invoices is partitioned — invoice_date BETWEEN required)
            $siRow = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X3: customer_payments — reversed (period-filtered by payment_date)
            $cpRow = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X4: sales_returns — reversed
            $srRow = DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X5: sales_challans — reversed (date filter via created_at)
            $scRow = DB::table('sales_challans')
                ->where('created_by', $userId)
                ->whereBetween('created_at', [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── Aggregate
            $revInv = (int) ($siRow->reversed_cnt ?? 0);
            $canInv = (int) ($siRow->cancelled_cnt ?? 0);
            $revPay = (int) ($cpRow->reversed_cnt ?? 0);
            $revRet = (int) ($srRow->reversed_cnt ?? 0);
            $revCha = (int) ($scRow->reversed_cnt ?? 0);

            $totalActions = (int) ($siRow->total_cnt ?? 0)
                          + (int) ($cpRow->total_cnt ?? 0)
                          + (int) ($srRow->total_cnt ?? 0)
                          + (int) ($scRow->total_cnt ?? 0);

            $errorCount = $revInv + $canInv + $revPay + $revRet + $revCha;
            $errorRate  = $totalActions > 0
                ? round(($errorCount / $totalActions) * 100, 2)
                : 0.0;

            return [
                'reversed_invoices'   => $revInv,
                'cancelled_invoices'  => $canInv,
                'reversed_payments'   => $revPay,
                'reversed_returns'    => $revRet,
                'reversed_challans'   => $revCha,
                'manual_journals'     => 0, // placeholder — manual_journal_entries table is post-launch
                'total_actions'       => $totalActions,
                'composite_error_rate'=> $errorRate,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getAccuracyKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    // ============================================================
    // PHASE 5 — ROLE-AWARE REFINEMENT & APPROVAL WORKLOAD
    // ============================================================
    //
    // Phase 5 makes the dashboard role-aware: each role sees only the
    // sections that are meaningful for their job. The plan calls for
    // migrations G4-G9 (godown_prepared_by, dispatched_by, received_by,
    // etc.) but those are LOW-PRIORITY schema gaps — the dashboard works
    // without them by falling back to created_by. They're deferred to a
    // future phase unless business explicitly requests them.
    //
    // What Phase 5 ships:
    //   1. resolveRoleSections() — map role → {section: bool} visibility
    //   2. getApprovalWorkload() — manager/admin/superadmin-only KPIs
    //      using the EXISTING approved_by / confirmed_by columns on
    //      stock_adjustments and damage_invoices.

    /**
     * Resolve which dashboard sections to render for the given role.
     *
     * Map per the Phase 5 plan:
     *   salesman           → sales + collections + returns + commission + operational + accuracy
     *   warehouse_manager  → stock_discipline + operational + accuracy
     *   dispatcher         → stock_discipline + operational + accuracy
     *   accountant         → collections + accuracy (+ operational for activity)
     *   manager            → all + approval_workload
     *   admin              → all + approval_workload
     *   superadmin         → all + approval_workload
     *   hr / other         → sales + collections + operational (their own work)
     *
     * Intentionally permissive: unknown roles get a sensible default
     * (sales + collections + operational + accuracy) rather than an
     * empty dashboard.
     *
     * @return array<string,bool>  Map of section_key => visible
     */
    private function resolveRoleSections(string $role): array
    {
        // Default: everything off, then turn on per role below.
        $sections = [
            'sales'              => false,
            'collections'        => false,
            'returns'            => false,
            'commission'         => false,
            'operational'        => false,
            'stock_discipline'   => false,
            'accuracy'           => false,
            'approval_workload'  => false,
        ];

        switch ($role) {
            case 'salesman':
                $sections['sales']            = true;
                $sections['collections']      = true;
                $sections['returns']          = true;
                $sections['commission']       = true;
                $sections['operational']      = true;
                $sections['accuracy']         = true;
                break;

            case 'warehouse_manager':
            case 'dispatcher':
                $sections['operational']      = true;
                $sections['stock_discipline'] = true;
                $sections['accuracy']         = true;
                break;

            case 'accountant':
                $sections['collections']      = true;
                $sections['returns']          = true;
                $sections['operational']      = true;
                $sections['accuracy']         = true;
                break;

            case 'manager':
            case 'admin':
            case 'superadmin':
                // All sections + approval workload.
                foreach ($sections as $k => $_) {
                    $sections[$k] = true;
                }
                break;

            case 'hr':
            case 'other':
            default:
                // Permissive default for unknown roles.
                $sections['sales']            = true;
                $sections['collections']      = true;
                $sections['operational']      = true;
                $sections['accuracy']         = true;
                break;
        }

        return $sections;
    }

    /**
     * Approval workload for manager / admin / superadmin roles.
     *
     * Pulls counts of:
     *   - Stock adjustments submitted but not yet approved (status='submitted')
     *     → "pending my approval" — these are branch-wide, not user-attributed.
     *   - Stock adjustments this user has approved in the period
     *     (approved_by = $userId, approved_at within range).
     *   - Damage invoices submitted but not yet approved (status='submitted')
     *     → "pending my approval" — same logic.
     *   - Damage invoices this user has approved in the period.
     *   - Total pending value = SUM(total_amount) of pending stock adjustments.
     *
     * Uses the EXISTING approved_by / submitted_by columns on
     * stock_adjustments (migration 2025_07_29_000001) and damage_invoices
     * (migration 2026_01_05_000001). No new migrations needed.
     *
     * Note on attribution: "pending my approval" is inherently branch-wide
     * (any manager in the branch can approve), so we don't filter by user.
     * The "approved by me" count IS user-attributed via approved_by.
     *
     * @return array{
     *   adjustments_pending_my_approval: int,
     *   adjustments_approved_by_me: int,
     *   damages_pending_my_approval: int,
     *   damages_approved_by_me: int,
     *   total_pending_value: float
     * }
     */
    private function getApprovalWorkload(int $userId, int $employeeId, array $range): array
    {
        $zero = [
            'adjustments_pending_my_approval' => 0,
            'adjustments_approved_by_me'      => 0,
            'damages_pending_my_approval'     => 0,
            'damages_approved_by_me'          => 0,
            'total_pending_value'             => 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── 1. Stock adjustments pending approval (branch-wide)
            //    status='submitted' means submitted-but-not-yet-approved.
            //    RLS auto-scopes to the user's branch.
            $saPending = DB::table('stock_adjustments')
                ->where('status', 'submitted')
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) AS cnt,
                    COALESCE(SUM(total_amount), 0) AS total_value
                ")
                ->first();

            // ── 2. Stock adjustments this user has approved in the period.
            //    approved_by references users.id (set in StockAdjustmentService::approve).
            //    approved_at is a timestamp — we filter by date range for the period.
            $saApproved = DB::table('stock_adjustments')
                ->where('approved_by', $userId)
                ->whereBetween('approved_at', [
                    $range['start'] . ' 00:00:00',
                    $range['end'] . ' 23:59:59',
                ])
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->count();

            // ── 3. Damage invoices pending approval (branch-wide)
            $dmgPending = DB::table('damage_invoices')
                ->where('status', 'submitted')
                ->where('is_reversed', false)
                ->count();

            // ── 4. Damage invoices this user has approved in the period
            $dmgApproved = DB::table('damage_invoices')
                ->where('approved_by', $userId)
                ->whereBetween('approved_at', [
                    $range['start'] . ' 00:00:00',
                    $range['end'] . ' 23:59:59',
                ])
                ->where('is_reversed', false)
                ->count();

            return [
                'adjustments_pending_my_approval' => (int) ($saPending->cnt ?? 0),
                'adjustments_approved_by_me'      => (int) $saApproved,
                'damages_pending_my_approval'     => (int) $dmgPending,
                'damages_approved_by_me'          => (int) $dmgApproved,
                'total_pending_value'             => (float) ($saPending->total_value ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 5 getApprovalWorkload failed: ' . $e->getMessage());
            return $zero;
        }
    }
}
