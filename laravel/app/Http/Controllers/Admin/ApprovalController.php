<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Approval\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use Illuminate\Http\Request;

/**
 * Approval Controller — Phase 5
 *
 * Handles the approval queue and approval workflow management.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalService $approvalService
    ) {}

    /**
     * Show the approval queue — pending items for the current user.
     */
    public function queue(Request $request)
    {
        $user = auth()->user();
        $entityType = $request->input('entity_type');

        $pendingRequests = $this->approvalService->getPendingQueueForUser($user, $entityType);

        // Load entity details for each request
        $pendingRequests->each(function ($req) {
            $req->entity = $req->getEntity();
        });

        $myRequests = ApprovalRequest::where('requested_by', $user->id)
            ->where('status', 'pending')
            ->with(['workflow', 'actions'])
            ->orderByDesc('requested_at')
            ->get()
            ->each(fn($r) => $r->entity = $r->getEntity());

        $workflows = ApprovalWorkflow::with('steps')->active()->get();

        return view('admin.approvals.queue', [
            'title' => 'Approval Queue',
            'pendingRequests' => $pendingRequests,
            'myRequests' => $myRequests,
            'workflows' => $workflows,
            'entityType' => $entityType,
        ]);
    }

    /**
     * Approve a request from the queue.
     */
    public function approve(Request $request, int $id)
    {
        $approvalRequest = ApprovalRequest::findOrFail($id);

        $result = $this->approvalService->approve($approvalRequest, $request->input('comments'));

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('admin.approvals.queue')
            ->with('success', $result['message']);
    }

    /**
     * Reject a request from the queue.
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $approvalRequest = ApprovalRequest::findOrFail($id);

        $result = $this->approvalService->reject($approvalRequest, $request->input('reason'));

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('admin.approvals.queue')
            ->with('success', $result['message']);
    }

    /**
     * Show approval workflow configuration.
     */
    public function workflows()
    {
        $workflows = ApprovalWorkflow::with('steps')->orderBy('entity_type')->orderBy('name')->get();

        return view('admin.approvals.workflows', [
            'title' => 'Approval Workflows',
            'workflows' => $workflows,
        ]);
    }

    /**
     * Update a workflow's settings.
     */
    public function updateWorkflow(Request $request, int $id)
    {
        $workflow = ApprovalWorkflow::findOrFail($id);

        $request->validate([
            'is_active' => 'boolean',
            'min_amount' => 'numeric|min:0',
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $workflow->update($request->only(['is_active', 'min_amount', 'name', 'description']));

        return back()->with('success', "Workflow '{$workflow->name}' updated.");
    }
}
