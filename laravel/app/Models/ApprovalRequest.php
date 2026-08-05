<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'approval_workflow_id',
        'current_level',
        'status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'current_level' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /* ── Relationships ─────────────────────────────────────── */

    public function workflow()
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function actions()
    {
        return $this->hasMany(ApprovalAction::class, 'approval_request_id')->orderBy('level')->orderBy('acted_at');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /* ── Scopes ────────────────────────────────────────────── */

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForEntity($query, string $entityType, int $entityId)
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    public function scopeForEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /* ── Helpers ───────────────────────────────────────────── */

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Get the current step that needs approval.
     */
    public function currentStep(): ?ApprovalStep
    {
        return $this->workflow?->getStepAtLevel($this->current_level);
    }

    /**
     * Check if a user can approve at the current level.
     * Segregation of duties: the requester cannot approve their own request.
     */
    public function canBeActedBy(User $user): bool
    {
        // Cannot approve own request
        if ($user->id === $this->requested_by) {
            return false;
        }

        $step = $this->currentStep();
        if (!$step) {
            return false;
        }

        // Check role
        $userRole = $user->getRole();
        return $step->canBeActedBy($userRole);
    }

    /**
     * Get the entity model this request is for.
     */
    public function getEntity()
    {
        // PURCHASING-API-2 (G-116): added 'purchase_order' to the modelMap
        // so the generic ApprovalService engine can resolve PO entities for
        // the approval queue + notification dispatch.
        $modelMap = [
            'manual_journal' => ManualJournal::class,
            'stock_adjustment' => \App\Models\StockAdjustment::class,
            'damage_invoice' => \App\Models\DamageInvoice::class,
            'purchase_order' => \App\Models\PurchaseOrder::class,
        ];

        $modelClass = $modelMap[$this->entity_type] ?? null;
        if (!$modelClass) {
            return null;
        }

        return $modelClass::find($this->entity_id);
    }
}
