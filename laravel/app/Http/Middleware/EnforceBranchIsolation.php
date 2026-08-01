<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Branch Isolation — P0-8.
 *
 * This middleware validates that the branch_id in the incoming request
 * matches the authenticated user's session branch_id. It prevents a
 * non-admin user from forging a different branch_id in a POST body to
 * create/edit/cancel records for another branch.
 *
 * Legacy equivalent: Helper::assertInvoiceAccessible + resolveBranchIdForWrite
 * (legacy/app/helpers/Helper.php:219-246).
 *
 * Enforcement rules:
 *   - Non-admin users: request branch_id MUST equal session branch_id.
 *     If mismatch → 403 (JSON for AJAX, redirect for non-AJAX).
 *   - Admin/superadmin: bypass (allowed to operate on any branch), but
 *     the override is logged to user_audit_log with action 'branch_override'
 *     for audit trail.
 *
 * The middleware inspects these request inputs for branch_id:
 *   - request->input('branch_id')  (form fields)
 *   - request->route('invoiceId')  (URL params — resolved via the invoice)
 *   - request->route('id')         (resource IDs — resolved via the model)
 *
 * For URL-param routes (e.g., POST /admin/sales-invoices/{id}/cancel),
 * the middleware loads the referenced record's branch_id from the DB
 * and compares it to the session branch_id. This prevents a non-admin
 * from cancelling another branch's invoice by guessing its ID.
 *
 * Usage in routes:
 *   ->middleware('branch.isolation')
 *
 * Alias registered in bootstrap/app.php.
 */
class EnforceBranchIsolation
{
    /**
     * Map route parameter names to their corresponding table + column
     * for branch_id lookup when the route param is a model ID (not a
     * direct branch_id input).
     */
    private const ROUTE_PARAM_TABLE_MAP = [
        'invoiceId' => ['table' => 'sales_invoices', 'column' => 'id'],
        'id'        => null, // resolved dynamically based on route prefix
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        // Admin/superadmin bypass — but log the cross-branch override.
        if ($user->isAdmin()) {
            $this->logBranchOverrideIfCrossBranch($request, $user, $sessionBranchId);
            return $next($request);
        }

        // --- Non-admin: check branch_id in request input ---
        $requestBranchId = $this->resolveRequestBranchId($request);

        if ($requestBranchId !== null && $requestBranchId !== $sessionBranchId) {
            return $this->deny($request, 'You do not have access to records from another branch.');
        }

        // --- Non-admin: check branch_id from URL param (model ID) ---
        $urlParamBranchId = $this->resolveUrlParamBranchId($request);
        if ($urlParamBranchId !== null && $urlParamBranchId !== $sessionBranchId) {
            return $this->deny($request, 'You do not have access to this record (belongs to another branch).');
        }

        return $next($request);
    }

    /**
     * Resolve branch_id from request input (form fields / JSON body).
     */
    private function resolveRequestBranchId(Request $request): ?int
    {
        $branchId = $request->input('branch_id');
        if ($branchId === null || $branchId === '') {
            return null;
        }
        return (int) $branchId;
    }

    /**
     * Resolve branch_id from URL route parameters by loading the
     * referenced record from the DB.
     */
    private function resolveUrlParamBranchId(Request $request): ?int
    {
        // Check common route param names that carry a model ID.
        $route = $request->route();
        if (!$route) {
            return null;
        }

        $params = $route->parameters();

        // Try 'invoiceId' (used by sales-challans godown/issue routes).
        if (isset($params['invoiceId'])) {
            $invoiceId = (int) $params['invoiceId'];
            if ($invoiceId > 0) {
                $branchId = DB::table('sales_invoices')
                    ->where('id', $invoiceId)
                    ->value('branch_id');
                return $branchId !== null ? (int) $branchId : null;
            }
        }

        // Try 'id' — resolve based on the route's controller/method
        // by inferring the table from the URI prefix.
        if (isset($params['id'])) {
            $id = (int) $params['id'];
            if ($id <= 0) {
                return null;
            }
            $table = $this->inferTableFromUri($request->path());
            if ($table !== null) {
                $branchId = DB::table($table)->where('id', $id)->value('branch_id');
                return $branchId !== null ? (int) $branchId : null;
            }
        }

        // Phase 0 (Stock Take plan): the admin/stock-take routes use `{session}`
        // as the URL param name (not `{id}`). Resolve it the same way so that
        // branch.isolation actually protects POST {session}/post and
        // POST {session}/cancel for non-admin users (otherwise the middleware
        // would silently no-op on these routes — security theater).
        if (isset($params['session'])) {
            $sessionId = (int) $params['session'];
            if ($sessionId > 0) {
                $table = $this->inferTableFromUri($request->path());
                if ($table !== null) {
                    $branchId = DB::table($table)->where('id', $sessionId)->value('branch_id');
                    return $branchId !== null ? (int) $branchId : null;
                }
            }
        }

        return null;
    }

    /**
     * Infer the DB table from the request URI path.
     *
     * Phase 1 (purchase parity): added purchase-orders, purchase-receives,
     * purchase-returns so that non-admin users cannot access another branch's
     * PO/GRN/Return by guessing its URL id (e.g. /admin/purchase-orders/{id}).
     */
    private function inferTableFromUri(string $path): ?string
    {
        $path = strtolower($path);
        if (str_contains($path, 'sales-invoices') || str_contains($path, 'sales/sales-invoices')) {
            return 'sales_invoices';
        }
        if (str_contains($path, 'sales-challans')) {
            return 'sales_challans';
        }
        if (str_contains($path, 'sales-returns')) {
            return 'sales_returns';
        }
        if (str_contains($path, 'customer-payments')) {
            return 'customer_payments';
        }
        if (str_contains($path, 'supplier-transactions') || str_contains($path, 'supplier-payments')) {
            return 'supplier_payments';
        }
        if (str_contains($path, 'employee-transactions')) {
            return 'employee_transactions';
        }
        if (str_contains($path, 'manual-journals')) {
            return 'manual_journals';
        }
        // --- Phase 1 (purchase parity) ---
        if (str_contains($path, 'purchase-orders')) {
            return 'purchase_orders';
        }
        if (str_contains($path, 'purchase-receives')) {
            return 'purchase_receives';
        }
        if (str_contains($path, 'purchase-returns')) {
            return 'purchase_returns';
        }
        // --- Phase 0 (Stock Take plan): stock-take routes carry the session
        // id either as {id} (resource verbs) or {session} (custom verbs).
        // Both resolve to stock_take_sessions.branch_id.
        if (str_contains($path, 'stock-take')) {
            return 'stock_take_sessions';
        }
        // --- Phase 1 (Stock Adjustment plan): POST {id}/confirm and
        // POST {id}/cancel must resolve {id} → stock_adjustments.branch_id
        // so a non-admin cannot confirm/cancel another branch's adjustment
        // by guessing its URL id. (RLS is the DB-level backstop; this is the
        // request-level guard that produces a friendly 403 instead of a 404.)
        if (str_contains($path, 'stock-adjustments')) {
            return 'stock_adjustments';
        }
        // --- Phase 0 (Damage plan): POST {id}/confirm and POST {id}/cancel
        // must resolve {id} → damage_invoices.branch_id so a non-admin cannot
        // confirm/cancel another branch's damage by guessing its URL id.
        // GET admin/damages/{id} (show) also benefits — a non-admin viewing
        // another branch's damage gets a clean 403 here instead of a 404 from
        // RLS. (RLS on damage_invoices is the DB-level backstop.)
        if (str_contains($path, 'damages')) {
            return 'damage_invoices';
        }
        // --- Phase 9 (Branch Demand plan): Branch demands are cross-branch by
        // nature — they involve BOTH from_branch_id and to_branch_id. Standard
        // branch isolation (single branch_id column) does NOT apply. Instead,
        // we skip the table inference and let the controller authorize based on
        // the user's role in the demand (requester or supplier). The middleware
        // will still allow the request through (admin bypass or no branch_id
        // match), and the controller's own authorization check handles the rest.
        if (str_contains($path, 'branch-demands')) {
            return null; // Skip — cross-branch demands need special handling
        }
        // --- Phase 4 (Money Transfers): cross-branch by nature (from_branch_id +
        // to_branch_id). Skip single-branch_id inference — the controller
        // authorizes based on the user being from/to branch.
        if (str_contains($path, 'money-transfers')) {
            return null;
        }
        // --- Phase 4 (Other Incomes / Other Expenses): single branch_id ---
        if (str_contains($path, 'other-incomes')) {
            return 'other_incomes';
        }
        if (str_contains($path, 'other-expenses')) {
            return 'other_expenses';
        }
        return null;
    }

    /**
     * Log a cross-branch override by an admin (audit trail).
     * Only logs when the admin operates on a branch different from
     * their session branch.
     */
    private function logBranchOverrideIfCrossBranch(Request $request, $user, int $sessionBranchId): void
    {
        $requestBranchId = $this->resolveRequestBranchId($request);
        $urlParamBranchId = $this->resolveUrlParamBranchId($request);

        $targetBranchId = $requestBranchId ?? $urlParamBranchId;

        if ($targetBranchId !== null && $targetBranchId !== $sessionBranchId) {
            DB::table('user_audit_log')->insert([
                'user_id'         => $user->id,
                'action'          => 'branch_override',
                'target_user_id'  => null,
                'branch_id'       => $targetBranchId,
                'details'         => json_encode([
                    'session_branch_id' => $sessionBranchId,
                    'target_branch_id'  => $targetBranchId,
                    'method'            => $request->method(),
                    'path'              => $request->path(),
                    'ip'                => $request->ip(),
                ]),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
                'created_at'  => now(),
            ]);
        }
    }

    /**
     * Return a 403 denial — JSON for AJAX, redirect for non-AJAX.
     */
    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }
        return redirect()->route('dashboard')->with('error', $message);
    }
}
