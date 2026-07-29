<?php

namespace App\Http\Resources\Api\V1\StockAdjustment;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock Adjustment API Resource — Phase 9 (API Routes & Mobile Support).
 *
 * Mobile-optimized JSON shape for a stock adjustment. Mirrors the
 * WarehouseTransferResource layout (header → warehouse/branch → lifecycle
 * attribution → items → audit timeline) so a mobile client gets a
 * consistent envelope across inventory modules.
 *
 * Includes every phase's payload:
 *   - Phase 2: adjustment_category (+ label) so consumers can slice by
 *     opening_balance / data_migration / uom_correction / etc.
 *   - Phase 3: the full approval-workflow attribution (submitted_by/at,
 *     approved_by/at, confirmed_by/at, approval_comments) and lifecycle
 *     flags (is_draft / is_submitted / is_approved / is_confirmed /
 *     is_cancelled / is_pending_approval / is_terminal).
 *   - Phase 5: per-item UOM fields via StockAdjustmentItemResource.
 *   - Phase 6: reversal-safety fields (is_reversed, reversed_at,
 *     reversed_by, reverse_reason, cancel_reason) so an auditor sees the
 *     full reversal trail.
 *   - Phase 4: the audit timeline (audit_logs) when eager-loaded — each
 *     row carries action, actor, payload, ip, timestamp. This is the
 *     forensic trail; omit it from list responses (only show() loads it).
 *
 * Excludes: internal Eloquent state, the SoftDeletes `deleted_at` column,
 * and the AuditableMasterData trait's bookkeeping (which is dead for this
 * model anyway — see the StockAdjustment class doc).
 */
class StockAdjustmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'adjustment_code'    => $this->adjustment_code,
            'adjustment_date'    => $this->adjustment_date?->format('Y-m-d'),

            // Warehouse + branch (branch_id is denormalized on the
            // adjustment for RLS scoping; the warehouse's own branch is
            // the authoritative location of the stock).
            'warehouse'          => $this->whenLoaded('warehouse', fn() => [
                'id'        => $this->warehouse?->id,
                'name'      => $this->warehouse?->warehouse_name,
                'branch_id' => $this->warehouse?->branch_id,
            ]),
            'branch'             => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch?->id,
                'name' => $this->branch?->branch_name,
            ]),
            'branch_id'          => $this->branch_id,

            // Type + category (Phase 2). increase = stock goes UP;
            // decrease = stock goes DOWN.
            'adjustment_type'    => $this->adjustment_type,
            'adjustment_category' => $this->adjustment_category,
            'category_label'     => $this->categoryLabel(),

            // Value (decimal:2 in the model). 0 for drafts (no GL yet).
            'total_amount'       => (float) ($this->total_amount ?? 0),
            'reason'             => $this->reason,

            // Lifecycle (Phase 3). The status string is the canonical
            // value (draft|submitted|approved|confirmed|cancelled|rejected);
            // the *_flag booleans are conveniences for mobile clients that
            // want to branch on state without re-implementing the string
            // compare. is_pending_approval aliases is_submitted (awaiting
            // an approver's decision).
            'status'             => $this->status,
            'status_label'       => $this->statusLabel(),
            'is_draft'           => $this->isDraft(),
            'is_submitted'       => $this->isSubmitted(),
            'is_approved'        => $this->isApproved(),
            'is_confirmed'       => $this->isConfirmed(),
            'is_cancelled'       => $this->isCancelled(),
            'is_pending_approval' => $this->isPendingApproval(),
            'is_terminal'        => $this->isTerminal(),
            'is_increase'        => $this->isIncrease(),
            'is_decrease'        => $this->isDecrease(),
            'is_open_balance'    => $this->isOpenBalance(),

            // GL linkage (set on confirm; null otherwise).
            'journal_entry_id'   => $this->journal_entry_id,

            // Reversal trail (Phase 6). is_reversed=true means the
            // confirmed adjustment was later cancelled and its stock+GL
            // movements were reversed. reverse_reason is the GL-side
            // reason; cancel_reason is the business-side reason (G15 —
            // always set on cancel).
            'is_reversed'        => (bool) $this->is_reversed,
            'reversed_at'        => $this->reversed_at?->toIso8601String(),
            'reversed_by'        => $this->reversed_by,
            'reverse_reason'     => $this->reverse_reason,
            'cancel_reason'      => $this->cancel_reason,

            // Phase 3 approval-workflow attribution. Each *_by is a user
            // id; the corresponding *_at is the ISO-8601 timestamp.
            // approval_comments is the running trail of submit/approve/
            // reject notes (free-text, appended by the service).
            'submitted_by'       => $this->submitted_by,
            'submitted_at'       => $this->submitted_at?->toIso8601String(),
            'submitted_by_user'  => $this->whenLoaded('submittedBy', fn() => $this->submittedBy ? [
                'id'   => $this->submittedBy->id,
                'name' => $this->submittedBy->name,
            ] : null),
            'approved_by'        => $this->approved_by,
            'approved_at'        => $this->approved_at?->toIso8601String(),
            'approved_by_user'   => $this->whenLoaded('approvedBy', fn() => $this->approvedBy ? [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'confirmed_by'       => $this->confirmed_by,
            'confirmed_at'       => $this->confirmed_at?->toIso8601String(),
            'confirmed_by_user'  => $this->whenLoaded('confirmedBy', fn() => $this->confirmedBy ? [
                'id'   => $this->confirmedBy->id,
                'name' => $this->confirmedBy->name,
            ] : null),
            'confirm_reason'     => $this->confirm_reason,
            'approval_comments'  => $this->approval_comments,

            // Created-by (Phase 8 — the drafter; shown on the print voucher
            // as "Prepared by").
            'created_by'         => $this->created_by,
            'created_by_user'    => $this->whenLoaded('createdBy', fn() => $this->createdBy ? [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),

            // Items (Phase 5 UOM + Phase 6.2 reversal linkage serialized
            // by the item resource). Only loaded on show(); index omits.
            'items'              => StockAdjustmentItemResource::collection($this->whenLoaded('items')),

            // Audit timeline (Phase 4). Only loaded on show() — the list
            // endpoint omits this to keep the payload small. Each row:
            //   {id, action, actor_id, actor_role, payload, ip_address,
            //    user_agent, created_at, actor?{id,name}}
            'audit_logs'         => $this->whenLoaded('auditLogs', fn() => $this->auditLogs->map(fn($log) => [
                'id'           => $log->id,
                'action'       => $log->action,
                'actor_id'     => $log->actor_id,
                'actor_role'   => $log->actor_role,
                // The actor relation is eager-loaded by the controller's
                // show() method ('auditLogs.actor'). We check
                // relationLoaded() on the model (NOT whenLoaded — that's a
                // JsonResource method and $log here is the raw model).
                'actor'        => $log->relationLoaded('actor') && $log->actor ? [
                    'id'   => $log->actor->id,
                    'name' => $log->actor->name,
                ] : null,
                'payload'      => $log->payload,
                'ip_address'   => $log->ip_address,
                'user_agent'   => $log->user_agent,
                'created_at'   => $log->created_at?->toIso8601String(),
            ])->values()),

            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
