<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Purchase\PurchaseAuditService;
use Illuminate\Http\Request;

/**
 * Purchase Audit Controller — Phase 6.
 *
 * Port of legacy app/controllers/PurchaseAuditController.php.
 *
 * Two routes:
 *   GET admin/purchase-audit      → checklist() — renders the HTML dashboard
 *   GET admin/purchase-audit/run  → runChecks() — returns JSON for AJAX refresh
 *
 * RBAC (legacy route_roles.php PurchaseAuditController matrix):
 *   index / checklist / run_checks → admin, manager, accountant
 *
 * Branch scoping: non-admins always audit their session branch only.
 * Admins may pass ?branch_id=0 to audit all branches at once.
 */
class PurchaseAuditController extends Controller
{
    /**
     * HTML checklist page. Mirrors legacy PurchaseAudit/checklist.php.
     */
    public function checklist(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead(
            $request->input('branch_id') ? (int) $request->input('branch_id') : null
        );

        $report = (new PurchaseAuditService($branchId > 0 ? $branchId : null))->runHealthChecks();

        $branchName = 'All branches';
        if ($branchId > 0) {
            $branch = \App\Models\Branch::find($branchId);
            $branchName = $branch?->branch_name ?? ('Branch #' . $branchId);
        }

        return view('admin.purchase-audit.checklist', [
            'title'      => 'Purchase Module Audit Checklist',
            'report'     => $report,
            'branch_name' => $branchName,
            'branch_id'  => $branchId,
        ]);
    }

    /**
     * JSON refresh endpoint for the "Re-run checks" button.
     */
    public function runChecks(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead(
            $request->input('branch_id') ? (int) $request->input('branch_id') : null
        );

        $report = (new PurchaseAuditService($branchId > 0 ? $branchId : null))->runHealthChecks();

        return response()->json($report);
    }
}
