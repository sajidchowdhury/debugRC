<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User Performance Dashboard Controller — Phase 0 (Scaffolding).
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
 * PHASE 0 SCOPE (this file):
 *   - Resolve $targetUser / $targetEmployee from the request.
 *   - Resolve the selected period (today/mtd/qtd/ytd/last30/custom).
 *   - Load $employeeOptions for the super-admin select box.
 *   - Render the performance.blade.php view with an empty KPI/chart grid.
 *     The grid sections will be filled in Phases 1-5; for now we ship the
 *     scaffolding so the route works end-to-end and the super-admin
 *     switcher is verifiable.
 *
 * PHASE 1+ will add the metric methods (getSalesKPIs, getSalesTrend,
 * getCollectionsKPIs, etc.) — each taking $userId as the first parameter.
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
        // 5. Render the view
        // ============================================================
        // Phase 0 ships an EMPTY grid. Phase 1+ will populate $salesKpis,
        // $collectionKpis, etc. here and pass them down.
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
            'scaffoldingOnly'             => true,
            'customerPaymentsTxnType'     => $customerPaymentsTxnType,
        ]);
    }

    /**
     * AJAX endpoint: placeholder for the chart-refresh route.
     *
     * Route: GET /dashboard/sales-trend
     * Name:  dashboard.salesTrend
     *
     * Phase 0 returns an empty array. Phase 1 will implement per-user
     * sales-trend data here. Kept now so the route registration in
     * routes/web.php doesn't reference a missing method.
     */
    public function salesTrendAjax(Request $request)
    {
        $days = min(max((int) $request->input('days', 7), 7), 90);
        // Phase 1 will return per-user trend data here.
        return response()->json(['data' => [], 'days' => $days, 'phase' => 0]);
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
}
