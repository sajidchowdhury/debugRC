<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Branch Switch Controller — Phase 5.
 *
 * Handles switching the active branch for the authenticated user's session.
 * The SetAppBranchId middleware reads session('branch_id') on the NEXT
 * request and sets the PostgreSQL app.branch_id GUC for Row-Level Security.
 *
 * Authorization: only admin/superadmin/manager roles can switch branches
 * (regular salesmen/dispatchers are locked to their home branch). This
 * matches the existing RBAC pattern in routes/web.php.
 */
class BranchSwitchController extends Controller
{
    public function switch(Request $request)
    {
        $user = Auth::user();

        // Authorization — only roles that can see multiple branches
        $role = $user?->getRole() ?? 'user';
        if (!in_array($role, ['admin', 'superadmin', 'manager'])) {
            return back()->with('error', 'You do not have permission to switch branches.');
        }

        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $branch = Branch::active()->find($validated['branch_id']);
        if (!$branch) {
            return back()->with('error', 'Selected branch is not available.');
        }

        // Set session — SetAppBranchId middleware picks this up next request
        session([
            'branch_id' => $branch->id,
            'branch_name' => $branch->branch_name,
            'branch_code' => $branch->branch_code,
        ]);

        return back()->with('success', "Switched to branch: {$branch->branch_name}");
    }
}
