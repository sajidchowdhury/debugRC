<?php

namespace App\Services\Approval;

use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\ManualJournal;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Approval Service — Phase 5
 *
 * Generic multi-level approval engine that integrates with any entity type.
 * Currently wired for Manual Journals but designed to be extensible.
 *
 * Lifecycle:
 *   1. Entity created (draft)
 *   2. submitForApproval() → checks workflow, creates request, sets status=submitted
 *   3. approve() → advances to next level or final-approves → entity status=approved
 *   4. Entity posts to GL (requires status=approved)
 *   5. reject() → sets status=rejected (terminal, must resubmit)
 *
 * Segregation of duties: the person who submitted cannot approve their own request.
 */
class ApprovalService
{
    public function __construct(
        private ?NotificationService $notificationService = null,
    ) {}

    /**
     * Check if an entity requires approval before posting.
     * Returns the applicable workflow, or null if no approval needed.
     */
    public function getRequiredWorkflow(string $entityType, float $amount, ?int $branchId = null): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::findApplicable($entityType, $amount, $branchId);
    }

    /**
     * Submit an entity for approval.
     * If no workflow applies, returns ['auto_approved' => true] (no approval needed).
     * If a workflow applies, creates an approval request and sets entity status to 'submitted'.
     */
    public function submitForApproval(string $entityType, int $entityId, float $amount, ?int $branchId = null): array
    {
        $workflow = $this->getRequiredWorkflow($entityType, $amount, $branchId);

        // No workflow applies → auto-approve
        if (!$workflow || !$workflow->requiresApprovalForAmount($amount)) {
            return ['auto_approved' => true, 'workflow' => null];
        }

        $user = Auth::user();

        return DB::transaction(function () use ($entityType, $entityId, $workflow, $user) {
            // Check if there's already a pending request
            $existing = ApprovalRequest::forEntity($entityType, $entityId)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                return ['auto_approved' => false, 'workflow' => $workflow, 'request' => $existing, 'already_submitted' => true];
            }

            // Create approval request
            $request = ApprovalRequest::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'approval_workflow_id' => $workflow->id,
                'current_level' => 1,
                'status' => 'pending',
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            // Update entity status
            $this->updateEntityStatus($entityType, $entityId, 'submitted');

            // Dispatch notification
            $this->notifyApprovers($request, 'submitted');

            Log::info("Approval request created", [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'workflow_id' => $workflow->id,
                'requested_by' => $user->id,
            ]);

            return ['auto_approved' => false, 'workflow' => $workflow, 'request' => $request];
        });
    }

    /**
     * Approve an approval request at the current level.
     * If this is the final level, marks the request as fully approved.
     */
    public function approve(ApprovalRequest $request, ?string $comments = null): array
    {
        $user = Auth::user();

        if (!$request->isPending()) {
            return ['success' => false, 'message' => 'Request is not pending.'];
        }

        if (!$request->canBeActedBy($user)) {
            return ['success' => false, 'message' => 'You are not authorized to approve this request, or you cannot approve your own submission.'];
        }

        return DB::transaction(function () use ($request, $user, $comments) {
            $workflow = $request->workflow;
            $currentLevel = $request->current_level;
            $maxLevel = $workflow->maxLevel();

            // Record the approval action
            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'level' => $currentLevel,
                'action' => 'approved',
                'acted_by' => $user->id,
                'acted_at' => now(),
                'comments' => $comments,
                'role_at_time' => $user->getRole(),
            ]);

            // Check if this was the final level
            if ($currentLevel >= $maxLevel) {
                // Final approval
                $request->update([
                    'status' => 'approved',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);

                // Update entity status
                $this->updateEntityStatus($request->entity_type, $request->entity_id, 'approved');

                // Notify the requester
                $this->notifyRequester($request, 'approved');

                Log::info("Approval request fully approved", [
                    'request_id' => $request->id,
                    'entity_type' => $request->entity_type,
                    'entity_id' => $request->entity_id,
                    'approved_by' => $user->id,
                ]);

                return ['success' => true, 'message' => 'Fully approved. The entity can now be posted.', 'final' => true];
            } else {
                // Advance to next level
                $nextLevel = $currentLevel + 1;
                $request->update(['current_level' => $nextLevel]);

                // Notify next level approvers
                $this->notifyApprovers($request, 'next_level');

                Log::info("Approval request advanced to level {$nextLevel}", [
                    'request_id' => $request->id,
                    'entity_type' => $request->entity_type,
                    'entity_id' => $request->entity_id,
                    'level' => $nextLevel,
                    'approved_by' => $user->id,
                ]);

                return ['success' => true, 'message' => "Level {$currentLevel} approved. Advanced to level {$nextLevel}.", 'final' => false];
            }
        });
    }

    /**
     * Reject an approval request.
     * This is terminal — the entity must be resubmitted.
     */
    public function reject(ApprovalRequest $request, string $reason): array
    {
        $user = Auth::user();

        if (!$request->isPending()) {
            return ['success' => false, 'message' => 'Request is not pending.'];
        }

        if (!$request->canBeActedBy($user)) {
            return ['success' => false, 'message' => 'You are not authorized to reject this request.'];
        }

        return DB::transaction(function () use ($request, $user, $reason) {
            // Record the rejection action
            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'level' => $request->current_level,
                'action' => 'rejected',
                'acted_by' => $user->id,
                'acted_at' => now(),
                'comments' => $reason,
                'role_at_time' => $user->getRole(),
            ]);

            // Update request
            $request->update([
                'status' => 'rejected',
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            // Update entity status
            $this->updateEntityStatus($request->entity_type, $request->entity_id, 'rejected');

            // Notify the requester
            $this->notifyRequester($request, 'rejected');

            Log::info("Approval request rejected", [
                'request_id' => $request->id,
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'rejected_by' => $user->id,
                'reason' => $reason,
            ]);

            return ['success' => true, 'message' => 'Request rejected.'];
        });
    }

    /**
     * Cancel a pending approval request (by the requester).
     * Sets the entity back to draft.
     */
    public function cancel(ApprovalRequest $request): array
    {
        $user = Auth::user();

        if (!$request->isPending()) {
            return ['success' => false, 'message' => 'Only pending requests can be cancelled.'];
        }

        if ($request->requested_by !== $user->id && !$user->isAdmin()) {
            return ['success' => false, 'message' => 'Only the requester or admin can cancel.'];
        }

        return DB::transaction(function () use ($request, $user) {
            $request->update(['status' => 'cancelled']);

            $this->updateEntityStatus($request->entity_type, $request->entity_id, 'draft');

            Log::info("Approval request cancelled", [
                'request_id' => $request->id,
                'cancelled_by' => $user->id,
            ]);

            return ['success' => true, 'message' => 'Request cancelled. Entity set back to draft.'];
        });
    }

    /**
     * Get the pending approval queue for a given user.
     * Returns requests where the current level requires the user's role.
     */
    public function getPendingQueueForUser($user, ?string $entityType = null)
    {
        $role = $user->getRole();

        $query = ApprovalRequest::with(['workflow', 'requester', 'actions'])
            ->where('status', 'pending')
            ->where('requested_by', '!=', $user->id); // Segregation of duties

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        $requests = $query->orderBy('requested_at')->get();

        // Filter to only show requests where the current level matches the user's role
        return $requests->filter(function ($request) use ($role) {
            $step = $request->currentStep();
            if (!$step) {
                return false;
            }
            return $step->canBeActedBy($role);
        });
    }

    /**
     * Get the approval history for a specific entity.
     */
    public function getApprovalHistory(string $entityType, int $entityId)
    {
        return ApprovalRequest::with(['workflow', 'actions.actor', 'requester'])
            ->forEntity($entityType, $entityId)
            ->orderByDesc('requested_at')
            ->get();
    }

    /**
     * Update the status of the related entity.
     *
     * G-075 (CRITICAL, WORKFLOWS-APPROVAL): previously this method only
     * implemented the `manual_journal` case — stock_adjustment and
     * damage_invoice were in the modelMap but silently no-op'd here, so the
     * generic engine could not actually drive their status transitions. Now
     * all 3 entity types are handled, each mapping the generic approval
     * statuses (submitted / approved / rejected / draft) to their own
     * bespoke column names. This removes the "1 entity only" architectural
     * inconsistency — the generic engine is now usable for all entities in
     * the modelMap, not just manual_journal.
     */
    private function updateEntityStatus(string $entityType, int $entityId, string $status): void
    {
        $user = Auth::user();

        switch ($entityType) {
            case 'manual_journal':
                $journal = ManualJournal::find($entityId);
                if (!$journal) break;

                $updateData = ['status' => $status];

                if ($status === 'submitted') {
                    $updateData['submitted_by'] = $user->id;
                    $updateData['submitted_at'] = now();
                } elseif ($status === 'approved') {
                    $updateData['approved_by'] = $user->id;
                    $updateData['approved_at'] = now();
                } elseif ($status === 'rejected') {
                    $updateData['rejected_by'] = $user->id;
                    $updateData['rejected_at'] = now();
                } elseif ($status === 'draft') {
                    // Reset approval fields when going back to draft
                    $updateData['submitted_by'] = null;
                    $updateData['submitted_at'] = null;
                    $updateData['approved_by'] = null;
                    $updateData['approved_at'] = null;
                    $updateData['rejected_by'] = null;
                    $updateData['rejected_at'] = null;
                }

                $journal->update($updateData);
                break;

            case 'stock_adjustment':
                // Pattern B entity: uses submitted_by/at + approved_by/at +
                // approval_comments. No dedicated rejected_by/at column —
                // rejection is captured via status='rejected' + the
                // approval_comments text field (which stores the reason).
                $adjustment = \App\Models\StockAdjustment::find($entityId);
                if (!$adjustment) break;

                $updateData = ['status' => $status];

                if ($status === 'submitted') {
                    $updateData['submitted_by'] = $user->id;
                    $updateData['submitted_at'] = now();
                } elseif ($status === 'approved') {
                    $updateData['approved_by'] = $user->id;
                    $updateData['approved_at'] = now();
                } elseif ($status === 'rejected') {
                    // stock_adjustments has no rejected_by/at columns;
                    // the approval_comments field + status='rejected'
                    // captures the rejection audit.
                } elseif ($status === 'draft') {
                    $updateData['submitted_by'] = null;
                    $updateData['submitted_at'] = null;
                    $updateData['approved_by'] = null;
                    $updateData['approved_at'] = null;
                }

                $adjustment->update($updateData);
                break;

            case 'damage_invoice':
                // Pattern B entity: uses approval_rejected_by/at (NOT
                // rejected_by/at) + approval_notes (NOT approval_comments).
                $damage = \App\Models\DamageInvoice::find($entityId);
                if (!$damage) break;

                $updateData = ['status' => $status];

                if ($status === 'submitted') {
                    $updateData['submitted_by'] = $user->id;
                    $updateData['submitted_at'] = now();
                } elseif ($status === 'approved') {
                    $updateData['approved_by'] = $user->id;
                    $updateData['approved_at'] = now();
                } elseif ($status === 'rejected') {
                    $updateData['approval_rejected_by'] = $user->id;
                    $updateData['approval_rejected_at'] = now();
                } elseif ($status === 'draft') {
                    $updateData['submitted_by'] = null;
                    $updateData['submitted_at'] = null;
                    $updateData['approved_by'] = null;
                    $updateData['approved_at'] = null;
                    $updateData['approval_rejected_by'] = null;
                    $updateData['approval_rejected_at'] = null;
                }

                $damage->update($updateData);
                break;
        }
    }

    /**
     * Notify approvers at the current level.
     */
    private function notifyApprovers(ApprovalRequest $request, string $event): void
    {
        try {
            $step = $request->currentStep();
            if (!$step) return;

            $entity = $request->getEntity();
            $entityLabel = $entity ? ($entity->journal_code ?? $entity->code ?? "#{$request->entity_id}") : "#{$request->entity_id}";

            $eventType = match ($event) {
                'submitted' => 'approval_request_submitted',
                'next_level' => 'approval_request_next_level',
                default => 'approval_request_submitted',
            };

            if ($this->notificationService) {
                $this->notificationService->dispatch(
                    $eventType,
                    "Approval required for {$request->entity_type} {$entityLabel} (Level {$request->current_level})",
                    $request->entity_type,
                    $request->entity_id,
                    ['level' => $request->current_level, 'role' => $step->role],
                    ['branch_id' => $entity?->branch_id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send approval notification", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notify the requester about the outcome.
     */
    private function notifyRequester(ApprovalRequest $request, string $outcome): void
    {
        try {
            $entity = $request->getEntity();
            $entityLabel = $entity ? ($entity->journal_code ?? $entity->code ?? "#{$request->entity_id}") : "#{$request->entity_id}";

            $eventType = match ($outcome) {
                'approved' => 'approval_request_approved',
                'rejected' => 'approval_request_rejected',
                default => 'approval_request_approved',
            };

            $message = match ($outcome) {
                'approved' => "Your {$request->entity_type} {$entityLabel} has been fully approved.",
                'rejected' => "Your {$request->entity_type} {$entityLabel} has been rejected.",
                default => "Your {$request->entity_type} {$entityLabel} approval status changed.",
            };

            if ($this->notificationService) {
                $this->notificationService->dispatch(
                    $eventType,
                    $message,
                    $request->entity_type,
                    $request->entity_id,
                    ['outcome' => $outcome, 'requester_id' => $request->requested_by],
                    ['specific_user' => $request->requested_by]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send requester notification", ['error' => $e->getMessage()]);
        }
    }
}
