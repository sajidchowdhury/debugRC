<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Compliance\SystemPolicyService;
use Illuminate\Http\Request;

/**
 * System Policy Controller — Phase 11.
 *
 * Admin UI for activating/deactivating system policies.
 * Only superadmin can access (enforced by Gate).
 */
class SystemPolicyController extends Controller
{
    public function __construct(
        private SystemPolicyService $policyService
    ) {}

    /**
     * Show the policy management page.
     */
    public function index()
    {
        $currentPolicy = $this->policyService->getCurrentPolicy();
        $currentMode = $this->policyService->getCurrentMode();
        $history = $this->policyService->getHistory(30);

        return view('admin.compliance.index', [
            'title' => 'System Policy & Compliance',
            'currentPolicy' => $currentPolicy,
            'currentMode' => $currentMode,
            'isInvestigation' => $currentMode === 'INVESTIGATION',
            'history' => $history,
            'modes' => \App\Models\SystemPolicy::MODES,
        ]);
    }

    /**
     * Activate a policy.
     */
    public function activate(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required|string|in:NORMAL,INVESTIGATION',
            'reason' => 'required|string|min:10|max:500',
            'expires_at' => 'nullable|date|after:now',
        ]);

        // Gate check (defense-in-depth — service also checks).
        if (!auth()->user()?->isSuperadmin()) {
            return back()->with('error', 'Only superadmin can change system policy.');
        }

        try {
            if ($validated['mode'] === 'NORMAL') {
                $this->policyService->deactivate(auth()->id(), $validated['reason']);
            } else {
                $this->policyService->activate(
                    $validated['mode'],
                    auth()->id(),
                    $validated['reason'],
                    [], // metadata (fiscal year auto-computed)
                    'admin_panel',
                    $validated['expires_at'] ?? null ? new \DateTime($validated['expires_at']) : null
                );
            }

            return redirect()->route('admin.compliance.index')
                ->with('success', "System policy set to {$validated['mode']}.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Deactivate the current policy (return to NORMAL).
     */
    public function deactivate(Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        if (!auth()->user()?->isSuperadmin()) {
            return back()->with('error', 'Only superadmin can change system policy.');
        }

        $result = $this->policyService->deactivate(auth()->id(), $validated['reason']);

        return redirect()->route('admin.compliance.index')
            ->with('success', $result ? 'System policy deactivated. Normal operation resumed.' : 'Already in normal mode.');
    }
}
