<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Http\Request;

/**
 * Accounting Period Controller — Phase 9.5.
 *
 * Period close + reopen + year-end close.
 * Access: admin/superadmin only.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(
        private AccountingPeriodService $periodService
    ) {}

    /**
     * Show the period close page with pre-close gate checks.
     */
    public function index(Request $request)
    {
        $branchId = (int) session('branch_id', 0);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Allow admin to view any branch.
        if (auth()->user()?->isAdmin() && $request->input('branch_id')) {
            $branchId = (int) $request->input('branch_id');
        }

        $closedThrough = $this->periodService->getClosedThroughDate($branchId);
        $earliestOpen = $this->periodService->earliestOpenDate($branchId);

        // Run pre-close gate for today (informational).
        $today = now()->format('Y-m-d');
        $gate = $this->periodService->preCloseGate($branchId, $today);

        // Year-end checklist.
        $yearEnd = now()->format('Y') . '-12-31';
        $yearEndChecklist = $this->periodService->yearEndChecklist($branchId, $yearEnd);

        return view('admin.accounting.period-close', [
            'title' => 'Accounting Period Close',
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'closedThrough' => $closedThrough,
            'earliestOpen' => $earliestOpen,
            'preCloseChecks' => $gate['checks'],
            'canClose' => $gate['can_close'],
            'yearEndChecks' => $yearEndChecklist['checks'],
            'canYearEndClose' => $yearEndChecklist['can_close'],
            'yearEndDate' => $yearEnd,
        ]);
    }

    /**
     * Close the period through a given date.
     */
    public function close(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'close_through_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $result = $this->periodService->closePeriod(
            (int) $validated['branch_id'],
            $validated['close_through_date'],
            auth()->id(),
            $validated['notes'] ?? ''
        );

        if ($result['status'] === 'success') {
            return redirect()->route('admin.accounting.period-close')
                ->with('success', $result['message']);
        }

        return back()->with('error', $result['message'])->with('preCloseChecks', $result['checks']);
    }

    /**
     * Reopen the period (superadmin only).
     */
    public function reopen(Request $request)
    {
        if (!auth()->user()?->isSuperadmin()) {
            return back()->with('error', 'Only superadmin can reopen accounting periods.');
        }

        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'reason' => 'required|string|min:10|max:500',
        ]);

        $result = $this->periodService->reopenPeriod(
            (int) $validated['branch_id'],
            auth()->id(),
            $validated['reason']
        );

        if ($result['status'] === 'success') {
            return redirect()->route('admin.accounting.period-close')
                ->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Execute year-end close.
     */
    public function yearEndClose(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'year_end_date' => 'required|date',
        ]);

        try {
            $result = $this->periodService->yearEndClose(
                (int) $validated['branch_id'],
                $validated['year_end_date'],
                auth()->id()
            );

            return redirect()->route('admin.accounting.period-close')
                ->with('success', $result['message']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
