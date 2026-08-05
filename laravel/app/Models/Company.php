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

    public function consolidationRuns(): \Illuminate\Database\Eloquent\Builder
    {
        // G-278 (G17) FINANCE-CONSOLIDATION-1: the prior implementation used
        // `hasMany(ConsolidationRun::class, 'created_by')` which incorrectly
        // linked companies.id to consolidation_runs.created_by (a user ID,
        // not a company ID). consolidation_runs has no company_id column — it
        // stores the included companies as a JSON array in company_ids. The
        // fixed method uses JSON containment (whereJsonContains) to match
        // runs where this company's ID appears in the company_ids array.
        //
        // Returns a Builder (not a HasMany Relation) since the JSON-containment
        // query is not a traditional FK relationship. Callers can chain scopes
        // or call get()/paginate() on the result:
        //   $company->consolidationRuns()->posted()->orderByDesc('id')->get();
        return ConsolidationRun::whereJsonContains('company_ids', $this->id);
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
