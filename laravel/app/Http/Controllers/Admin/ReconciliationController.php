<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\ReconciliationService;
use Illuminate\Http\Request;

/**
 * Reconciliation Controller — Phase 5.
 *
 * The reconciliation hub ties 6 sub-ledger sections to their GL control
 * accounts with a configurable tolerance. All sections must be green
 * before an accounting period can be closed.
 */
class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $reconciliationService
    ) {}

    /**
     * Show the reconciliation hub.
     */
    public function index()
    {
        $result = $this->reconciliationService->reconcileAll();

        return view('admin.reports.reconciliation', $result);
    }

    /**
     * Re-run the reconciliation (AJAX endpoint).
     */
    public function refresh()
    {
        $result = $this->reconciliationService->reconcileAll();

        return response()->json($result);
    }
}
