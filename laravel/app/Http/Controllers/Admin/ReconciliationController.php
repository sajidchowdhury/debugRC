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
    public function index(Request $request)
    {
        $asOfDate = $request->input('as_of_date');
        $result = $this->reconciliationService->reconcileAll($asOfDate);

        return view('admin.reports.reconciliation', array_merge($result, [
            'as_of_date' => $asOfDate,
        ]));
    }

    /**
     * Re-run the reconciliation (AJAX endpoint).
     */
    public function refresh(Request $request)
    {
        $asOfDate = $request->input('as_of_date');
        $result = $this->reconciliationService->reconcileAll($asOfDate);

        return response()->json($result);
    }

    /**
     * Get a single section's reconciliation with drill-down (AJAX).
     */
    public function section(Request $request, string $sectionId)
    {
        $result = $this->reconciliationService->reconcileSection($sectionId);

        if (!$result) {
            return response()->json(['error' => 'Unknown section: ' . $sectionId], 404);
        }

        return response()->json($result);
    }
}
