<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\DimensionValueBranchScope;

/**
 * DimensionValue — a specific value within a dimension.
 *
 * E.g. "Department" dimension → "Sales Dept", "Admin Dept", "HR Dept" values.
 * Each value can optionally belong to a specific branch (null = all branches).
 *
 * Linked to journal_lines via dimension_value_id, enabling segment reporting.
 */
class DimensionValue extends Model
{
    use SoftDeletes;

    protected $table = 'dimension_values';

    protected $fillable = [
        'dimension_id',
        'code',
        'name',
        'branch_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // FINANCE-3 (G-319): use DimensionValueBranchScope (NULL-branch inclusion)
        // instead of the generic BranchScope (hard equality). NULL-branch rows
        // are company-wide defaults that every non-admin user must see.
        static::addGlobalScope(new DimensionValueBranchScope);
    }

    // ── Relationships ───────────────────────────────────────────────

    public function dimension(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Dimension::class, 'dimension_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Accounting\JournalLine::class, 'dimension_value_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function scopeForDimension(\Illuminate\Database\Eloquent\Builder $query, int $dimensionId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('dimension_id', $dimensionId);
    }
}
