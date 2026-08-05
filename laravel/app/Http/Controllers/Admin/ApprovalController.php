<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\ApproveRequest;
use App\Http\Requests\Approval\RejectRequest;
use App\Http\Requests\Approval\UpdateWorkflowRequest;
use App\Http\Requests\Approval\QueueIndexRequest;
use App\Services\Approval\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;

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
    public function queue(QueueIndexRequest $request)
    {
        $user = auth()->user();
        $entityType = $request->validated('entity_type');

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
    public function approve(ApproveRequest $request, int $id)
    {
        $approvalRequest = ApprovalRequest::findOrFail($id);

        $result = $this->approvalService->approve($approvalRequest, $request->validated('comments'));

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('admin.approvals.queue')
            ->with('success', $result['message']);
    }

    /**
     * Reject a request from the queue.
     */
    public function reject(RejectRequest $request, int $id)
    {
        $approvalRequest = ApprovalRequest::findOrFail($id);

        $result = $this->approvalService->reject($approvalRequest, $request->validated('reason'));

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
    public function updateWorkflow(UpdateWorkflowRequest $request, int $id)
    {
        $workflow = ApprovalWorkflow::findOrFail($id);

        $workflow->update($request->only(['is_active', 'min_amount', 'name', 'description']));

        return back()->with('success', "Workflow '{$workflow->name}' updated.");
    }
}
