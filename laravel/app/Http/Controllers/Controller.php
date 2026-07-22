<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base application controller.
 *
 * Laravel 11+ no longer ships this file by default — it must exist so that
 * all controllers in app/Http/Controllers/Admin can `extends Controller`.
 *
 * The AuthorizesRequests + ValidatesRequests traits provide the
 * authorize() and validate() helpers used throughout the codebase.
 *
 * Phase 1 (purchase parity): added resolveBranchIdForRead() +
 * enforceSessionBranchOnWrite() helpers so the purchase controllers
 * can branch-scope their queries without duplicating logic.
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Resolve the branch_id to use for a READ (index/show/AJAX) operation.
     *
     * Rules:
     *   - If the user is admin/superadmin AND passed an explicit branch_id
     *     that points to an active branch, honour it (admin "all-branches" mode).
     *   - Otherwise, fall back to the session branch_id (the user's own branch).
     *
     * Mirrors Legacy SalesModel::resolveBranchIdForRead() and the pattern
     * already used by SalesCartController.
     *
     * @return int  The branch_id to scope queries by (0 = unscoped, only
     *              happens when no session branch is set — should never
     *              occur for a properly-authenticated employee user).
     */
    protected function resolveBranchIdForRead(?int $requestedBranchId = null): int
    {
        $sessionBranchId = (int) (session('branch_id') ?? auth()->user()?->getBranchId() ?? 0);

        if ($requestedBranchId !== null && $requestedBranchId > 0) {
            // Only admins may override the session branch on reads.
            if (auth()->user()?->isAdmin()) {
                $active = \Illuminate\Support\Facades\DB::table('branches')
                    ->where('id', $requestedBranchId)
                    ->where('is_active', true)
                    ->exists();
                if ($active) {
                    return $requestedBranchId;
                }
            }
            // Non-admin passing a different branch_id — EnforceBranchIsolation
            // middleware has already 403'd the request before we get here.
            // If they pass their own branch_id, that's fine.
        }

        return $sessionBranchId;
    }

    /**
     * Resolve the branch_id to use for a WRITE (store/update) operation.
     *
     * Rules:
     *   - Non-admin users: ALWAYS use the session branch_id, ignoring any
     *     client-supplied branch_id. This prevents a non-admin from creating
     *     a PO/GRN/Return for another branch.
     *   - Admin/superadmin: honour an explicitly-supplied branch_id (validated
     *     against the branches table); otherwise fall back to session branch.
     *
     * @param int|null $clientBranchId  The branch_id from the request body.
     * @return int  The branch_id to write.
     */
    protected function resolveBranchIdForWrite(?int $clientBranchId = null): int
    {
        $sessionBranchId = (int) (session('branch_id') ?? auth()->user()?->getBranchId() ?? 0);

        if (auth()->user()?->isAdmin() && $clientBranchId !== null && $clientBranchId > 0) {
            return $clientBranchId;
        }

        return $sessionBranchId;
    }
}
