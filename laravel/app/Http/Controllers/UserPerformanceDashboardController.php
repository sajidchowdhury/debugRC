<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dashboard\PerformanceDashboardRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Dashboard\SalesPerformanceMetricsService;
use App\Services\Dashboard\CollectionMetricsService;
use App\Services\Dashboard\OperationalMetricsService;
use App\Services\Dashboard\CommissionMetricsService;
use App\Services\Dashboard\StockDisciplineMetricsService;
use App\Services\Dashboard\ApprovalWorkloadService;

/**
 * User Performance Dashboard Controller — Phase 0 (Scaffolding) +
 * Phase 1 (Sales Performance Core) + Phase 2 (Collections & Returns) +
 * Phase 3 (Operational Efficiency & Productivity) +
 * Phase 4 (Commission, Stock Discipline & Accuracy) +
 * Phase 5 (Role-Aware Refinement & Approval Workload) +
 * Phase 6 (Polish, Performance & Post-Launch Gaps).
 *
 * Replaces the legacy dashboard (deleted in REPORTS-AUDIT-3 G-136 — see
 * git history) for the `/dashboard` route.
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
 *
 * REFACTOR (G-144 / dashboards.md G9, HIGH-WAVE-3):
 *   The 16 private metric methods were extracted into 6 service classes in
 *   App\Services\Dashboard\. This controller is now a thin orchestrator:
 *   it resolves context (auth, employee, period), delegates metric
 *   computation to the injected services via the cached()/timed() wrappers,
 *   and renders the view. The controller shrank from ~2273L to ~700L.
 *   Each service can now be unit-tested in isolation (mocked DB facade).
 */
class UserPerformanceDashboardController extends Controller
{

    public function __construct(
        private SalesPerformanceMetricsService $salesMetrics,
        private CollectionMetricsService $collectionMetrics,
        private OperationalMetricsService $operationalMetrics,
        private CommissionMetricsService $commissionMetrics,
        private StockDisciplineMetricsService $stockDisciplineMetrics,
        private ApprovalWorkloadService $approvalWorkload,
    ) {}

    /**
     * Render the user performance dashboard.
     *
     * Route: GET /dashboard
     * Name:  dashboard
     */
    public function index(PerformanceDashboardRequest $request)
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

        $salesKpis             = $this->cached('sales_kpis', $userId, $period, $range, fn() => $this->salesMetrics->getSalesKPIs($userId, $range));
        $salesTrend            = $this->cached('sales_trend', $userId, $period, $range, fn() => $this->salesMetrics->getSalesTrend($userId, $range));
        $salesByProductGroup   = $this->cached('sales_by_pg', $userId, $period, $range, fn() => $this->salesMetrics->getSalesByProductGroup($userId, $range));
        $topCustomers          = $this->cached('top_customers', $userId, $period, $range, fn() => $this->salesMetrics->getTopCustomers($userId, $range, 5));
        $customerAcquisition   = $this->cached('cust_acq', $userId, $period, $range, fn() => $this->salesMetrics->getCustomerAcquisition($userId, $range));

        $collectionKpis    = $this->cached('collection_kpis', $userId, $period, $range, fn() => $this->collectionMetrics->getCollectionKPIs($userId, $range, $txnTypeExists));
        $receivableAging   = $this->cached('recv_aging', $userId, $period, $range, fn() => $this->collectionMetrics->getReceivableAging($userId));
        $returnKpis        = $this->cached('return_kpis', $userId, $period, $range, fn() => $this->collectionMetrics->getReturnKPIs($userId, $range));
        $paymentModeMix    = $this->cached('pmix', $userId, $period, $range, fn() => $this->collectionMetrics->getPaymentModeMix($userId, $range));

        $velocityKpis          = $this->cached('velocity', $userId, $period, $range, fn() => $this->operationalMetrics->getVelocityKPIs($userId, $range));
        $pipelineSnapshot      = $this->cached('pipeline', $userId, $period, $range, fn() => $this->operationalMetrics->getPipelineSnapshot($userId));
        $workPattern           = $this->cached('work_pattern', $userId, $period, $range, fn() => $this->operationalMetrics->getWorkPattern($userId, $range));
        $activitySummary       = $this->cached('activity', $userId, $period, $range, fn() => $this->operationalMetrics->getActivitySummary($userId, $range));
        $notificationEngagement = $this->cached('notif', $userId, $period, $range, fn() => $this->operationalMetrics->getNotificationEngagement($userId));

        $commissionSummary = $this->cached('commission', $employeeId, $period, $range, fn() => $this->commissionMetrics->getCommissionSummary($employeeId, $range));
        $stockDiscipline   = $this->cached('stock_disc', $userId, $period, $range, fn() => $this->stockDisciplineMetrics->getStockDiscipline($userId, $employeeId, $range));
        $accuracyKpis      = $this->cached('accuracy', $userId, $period, $range, fn() => $this->stockDisciplineMetrics->getAccuracyKPIs($userId, $range));

        $approvalWorkload = $roleSections['approval_workload']
            ? $this->cached('approval', $userId, $period, $range, fn() => $this->approvalWorkload->getApprovalWorkload($userId, $employeeId, $range))
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
    public function fragmentAjax(PerformanceDashboardRequest $request)
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

            $salesKpis             = $this->cached('sales_kpis', $userId, $period, $range, fn() => $this->salesMetrics->getSalesKPIs($userId, $range));
            $salesTrend            = $this->cached('sales_trend', $userId, $period, $range, fn() => $this->salesMetrics->getSalesTrend($userId, $range));
            $salesByProductGroup   = $this->cached('sales_by_pg', $userId, $period, $range, fn() => $this->salesMetrics->getSalesByProductGroup($userId, $range));
            $topCustomers          = $this->cached('top_customers', $userId, $period, $range, fn() => $this->salesMetrics->getTopCustomers($userId, $range, 5));
            $customerAcquisition   = $this->cached('cust_acq', $userId, $period, $range, fn() => $this->salesMetrics->getCustomerAcquisition($userId, $range));

            $collectionKpis    = $this->cached('collection_kpis', $userId, $period, $range, fn() => $this->collectionMetrics->getCollectionKPIs($userId, $range, $txnTypeExists));
            $receivableAging   = $this->cached('recv_aging', $userId, $period, $range, fn() => $this->collectionMetrics->getReceivableAging($userId));
            $returnKpis        = $this->cached('return_kpis', $userId, $period, $range, fn() => $this->collectionMetrics->getReturnKPIs($userId, $range));
            $paymentModeMix    = $this->cached('pmix', $userId, $period, $range, fn() => $this->collectionMetrics->getPaymentModeMix($userId, $range));

            $velocityKpis          = $this->cached('velocity', $userId, $period, $range, fn() => $this->operationalMetrics->getVelocityKPIs($userId, $range));
            $pipelineSnapshot      = $this->cached('pipeline', $userId, $period, $range, fn() => $this->operationalMetrics->getPipelineSnapshot($userId));
            $workPattern           = $this->cached('work_pattern', $userId, $period, $range, fn() => $this->operationalMetrics->getWorkPattern($userId, $range));
            $activitySummary       = $this->cached('activity', $userId, $period, $range, fn() => $this->operationalMetrics->getActivitySummary($userId, $range));
            $notificationEngagement = $this->cached('notif', $userId, $period, $range, fn() => $this->operationalMetrics->getNotificationEngagement($userId));

            $commissionSummary = $this->cached('commission', $employeeId, $period, $range, fn() => $this->commissionMetrics->getCommissionSummary($employeeId, $range));
            $stockDiscipline   = $this->cached('stock_disc', $userId, $period, $range, fn() => $this->stockDisciplineMetrics->getStockDiscipline($userId, $employeeId, $range));
            $accuracyKpis      = $this->cached('accuracy', $userId, $period, $range, fn() => $this->stockDisciplineMetrics->getAccuracyKPIs($userId, $range));

            $approvalWorkload = $roleSections['approval_workload']
                ? $this->cached('approval', $userId, $period, $range, fn() => $this->approvalWorkload->getApprovalWorkload($userId, $employeeId, $range))
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
    private function cached(string $metric, int $id, string $period, array $range, callable $fn, ?int $ttl = null)
    {
        // REPORTS-AUDIT-7 (G-225/G-226 / dashboards.md G12): TTL now sourced
        // from config('reports.dashboard.cache_ttl_seconds', 60) so deployments
        // can tune without a code change. Null falls through to the config
        // value (keeps backward compat for any caller still passing null).
        $ttl = $ttl ?? (int) config('reports.dashboard.cache_ttl_seconds', 60);
        if ($id <= 0) {
            // No user -> just compute (and time) without caching. Avoids
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
            // REPORTS-AUDIT-7 (G-225/G-226): threshold sourced from config
            // so deployments can tune the slow-query log noise without a code change.
            $thresholdMs = (float) config('reports.dashboard.slow_query_threshold_ms', 200.0);
            if ($elapsedMs > $thresholdMs) {
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
    public function salesTrendAjax(PerformanceDashboardRequest $request)
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
            'data'  => $this->salesMetrics->getSalesTrend($userId, $range),
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
    // ROLE-AWARE SECTION VISIBILITY (was Phase 5)
    // ============================================================
    //
    // resolveRoleSections() maps a role string to a {section: bool} visibility
    // map used by index() and fragmentAjax() to decide which dashboard sections
    // to render. The companion metric method getApprovalWorkload() now lives in
    // App\Services\Dashboard\ApprovalWorkloadService (G-144 refactor).

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
     * REPORTS-AUDIT-3 (G-148): the default case is RESTRICTIVE for unknown
     * roles (no sections enabled) + Log::warning so an unknown role shows up
     * in the logs for follow-up. Previously the default was permissive (sales
     * + collections + operational + accuracy) which silently granted any
     * newly-invented role a sensible-looking dashboard. Known roles listed
     * above keep their current section visibility — only the catch-all
     * default changed.
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
                // Explicit known role: same permissive set as before
                // (sales + collections + operational + accuracy) so existing
                // behavior for these roles is preserved.
                $sections['sales']            = true;
                $sections['collections']      = true;
                $sections['operational']      = true;
                $sections['accuracy']         = true;
                break;

            default:
                // Unknown role: NO sections enabled (REPORTS-AUDIT-3 G-148).
                // Previously the default was permissive (4 sections enabled),
                // which silently granted any newly-invented role a
                // sensible-looking dashboard. Log::warning so an unknown
                // role is visible in logs for follow-up (the user will see
                // an empty dashboard — administrators can add an explicit
                // case for the new role above to grant appropriate access).
                Log::warning("Unknown role {$role} denied dashboard sections");
                break;
        }

        return $sections;
    }
}
