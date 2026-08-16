<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\BelowMinApprovalService;
use Illuminate\Http\Request;

/**
 * Sales Below-Min Approval Controller — Session 6.
 *
 * Endpoints for the below-min admin-override workflow:
 *
 *   POST /admin/sales/below-min-approvals
 *        Body: approver_username, approver_password, product_id,
 *              product_name?, requested_rate, min_rate, max_rate,
 *              default_rate, reason (≥ 10 chars), customer_id,
 *              branch_id, cart_id?, sale_line_index?
 *        Returns: { status, audit_log_id, approver_user_id, message }
 *        Auth: role:admin,manager (the APPROVER's session must be
 *        admin/manager — but the credentials in the body are re-checked
 *        fresh, so a cashier can call this endpoint while their own
 *        session is active; the body credentials determine the approver).
 *
 *   GET  /admin/sales/below-min-approvals
 *        Query: branch_id? (defaults to session branch)
 *        Returns: { status, approvals: [...] }
 *        Auth: role:admin,manager
 *
 * The approve endpoint is called from the SweetAlert2 modal in
 * cart.blade.php when the cashier enters a below-min rate. The JS
 * captures the returned audit_log_id and passes it to /cart/add as
 * `below_min_override_id`.
 *
 * Why re-authenticate instead of trusting the session?
 * - The cashier's session could be hijacked. Re-authentication with
 *   the approver's password ensures the admin/manager actually
 *   authorized THIS specific below-min sale.
 * - The plan's acceptance test "try to approve with a user whose
 *   role was changed from manager to cashier between request and
 *   approval — should fail with a 403" is satisfied because the
 *   role check runs at approval time against the FRESH user record.
 *
 * @see \App\Services\Sales\BelowMinApprovalService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 6
 */
class SalesBelowMinApprovalController extends Controller
{
    public function __construct(
        private BelowMinApprovalService $belowMinApprovalService
    ) {}

    /**
     * Approve a below-min sale line.
     *
     * Returns the audit_log_id on success. The caller (JS) then passes
     * this id to /cart/add as `below_min_override_id`.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'approver_username' => 'required|string|max:100',
            'approver_password' => 'required|string|max:255',
            'product_id'        => 'required|integer|min:1',
            'product_name'      => 'nullable|string|max:255',
            'requested_rate'    => 'required|numeric|min:0',
            'min_rate'          => 'required|numeric|min:0',
            'max_rate'          => 'required|numeric|min:0',
            'default_rate'      => 'required|numeric|min:0',
            'reason'            => 'required|string|min:10|max:500',
            'customer_id'       => 'required|integer|min:1',
            'branch_id'         => 'required|integer|min:1',
            'cart_id'           => 'nullable|integer',
            'sale_line_index'   => 'nullable|integer',
        ]);

        try {
            $result = $this->belowMinApprovalService->approve([
                'approver_username' => $validated['approver_username'],
                'approver_password' => $validated['approver_password'],
                'product_id'        => $validated['product_id'],
                'product_name'      => $validated['product_name'] ?? null,
                'requested_rate'    => $validated['requested_rate'],
                'min_rate'          => $validated['min_rate'],
                'max_rate'          => $validated['max_rate'],
                'default_rate'      => $validated['default_rate'],
                'reason'            => $validated['reason'],
                'customer_id'       => $validated['customer_id'],
                'branch_id'         => $validated['branch_id'],
                'cart_id'           => $validated['cart_id'] ?? null,
                'sale_line_index'   => $validated['sale_line_index'] ?? null,
                'cashier_user_id'   => auth()->id(),
            ]);

            return response()->json([
                'status'            => 'success',
                'message'           => 'Below-min override approved.',
                'audit_log_id'      => $result['audit_log_id'],
                'approver_user_id'  => $result['approver_user_id'],
            ]);
        } catch (\InvalidArgumentException $e) {
            // Validation failures (reason too short, rate not below min, etc.)
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            // Auth failures (bad credentials, insufficient role) → 403
            // matches the plan's acceptance test for privilege escalation.
            $message = $e->getMessage();
            $isAuthFailure = str_contains($message, 'credentials')
                          || str_contains($message, 'role is not sufficient')
                          || str_contains($message, 'not found or inactive');

            return response()->json([
                'status'  => 'error',
                'message' => $message,
            ], $isAuthFailure ? 403 : 400);
        }
    }

    /**
     * List recent below-min approvals for the current branch.
     *
     * Used by an optional audit dashboard. The cashier UI does not
     * call this — it's for managers/admins to review recent overrides.
     */
    public function index(Request $request)
    {
        $branchId = (int) ($request->query('branch_id') ?? session('branch_id') ?? 0);

        if ($branchId <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'branch_id is required (query param or session).',
            ], 422);
        }

        $approvals = $this->belowMinApprovalService->pendingForBranch($branchId);

        return response()->json([
            'status'     => 'success',
            'branch_id'  => $branchId,
            'approvals'  => $approvals,
        ]);
    }
}
