<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalWorkflow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'entity_type',
        'min_amount',
        'is_active',
        'requires_approval_levels',
        'branch_id',
        'description',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_approval_levels' => 'integer',
        // WORKFLOWS-AUDIT-1 (G-183): branch_id is now integer (was string).
        // Cast ensures Eloquent returns int|null instead of string.
        'branch_id' => 'integer',
    ];

    /* ── Relationships ─────────────────────────────────────── */

    public function steps()
    {
        return $this->hasMany(ApprovalStep::class, 'approval_workflow_id')->orderBy('level');
    }

    public function requests()
    {
        return $this->hasMany(ApprovalRequest::class, 'approval_workflow_id');
    }

    /* ── Scopes ────────────────────────────────────────────── */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /* ── Helpers ───────────────────────────────────────────── */

    /**
     * Find the applicable workflow for a given entity type, amount, and branch.
     * Returns null if no workflow applies (meaning approval is not required).
     */
    public static function findApplicable(string $entityType, float $amount, ?int $branchId = null): ?self
    {
        return static::active()
            ->forEntity($entityType)
            ->where('min_amount', '<=', $amount)
            ->where(fn($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByDesc('min_amount')    // Most specific (highest threshold) first
            ->orderByRaw('CASE WHEN branch_id IS NOT NULL THEN 0 ELSE 1 END') // Branch-specific first
            ->first();
    }

    /**
     * Check if this workflow requires approval for the given amount.
     */
    public function requiresApprovalForAmount(float $amount): bool
    {
        return $this->is_active && $amount >= (float) $this->min_amount;
    }

    /**
     * Get the step at a given level.
     */
    public function getStepAtLevel(int $level): ?ApprovalStep
    {
        return $this->steps->firstWhere('level', $level);
    }

    /**
     * Get the maximum approval level.
     */
    public function maxLevel(): int
    {
        return $this->steps->max('level') ?? 0;
    }
}
