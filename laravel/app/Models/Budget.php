<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * Budget — the header for a budget definition.
 *
 * Each budget belongs to a fiscal year and optionally a branch.
 * Contains budget_lines (per-ledger, per-period amounts).
 *
 * Status lifecycle: draft → active → closed
 *                                    └── cancelled
 */
class Budget extends Model
{
    use SoftDeletes;

    protected $table = 'budgets';

    protected $fillable = [
        'name',
        'fiscal_year',
        'branch_id',
        'period_type',
        'status',
        'description',
        'total_amount',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    // ── Relationships ───────────────────────────────────────────────

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeForYear(\Illuminate\Database\Eloquent\Builder $query, string $year): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('fiscal_year', $year);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function maxPeriod(): int
    {
        return match ($this->period_type) {
            'quarterly' => 4,
            'yearly'    => 1,
            default     => 12,
        };
    }
}
