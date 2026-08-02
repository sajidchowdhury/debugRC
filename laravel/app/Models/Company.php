<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company — a legal entity that owns one or more branches.
 *
 * Phase 8 introduces the concept of companies above branches.
 * Each company can own multiple branches. The consolidation parent
 * company is the one that consolidates the financial statements
 * of all companies in the group.
 *
 * Status lifecycle:
 *   active   → Operating normally
 *   inactive → No longer active (but data retained)
 *   dormant  → Dormant / non-trading
 */
class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    protected $fillable = [
        'company_code',
        'company_name',
        'legal_name',
        'tax_id',
        'registration_no',
        'address',
        'phone',
        'email',
        'currency',
        'is_consolidation_parent',
        'ownership_pct',
        'status',
        'description',
        'created_by',
    ];

    protected $casts = [
        'is_consolidation_parent' => 'boolean',
        'ownership_pct' => 'decimal:2',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function branches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Branch::class, 'company_id');
    }

    public function consolidationRuns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ConsolidationRun::class, 'created_by');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeConsolidationParent(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_consolidation_parent', true);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isConsolidationParent(): bool
    {
        return $this->is_consolidation_parent;
    }

    public function hasMinorityInterest(): bool
    {
        return $this->ownership_pct < 100.00;
    }

    public function getMinorityInterestPct(): float
    {
        return max(0, 100.00 - $this->ownership_pct);
    }

    public static function statusOptions(): array
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'dormant' => 'Dormant',
        ];
    }
}
