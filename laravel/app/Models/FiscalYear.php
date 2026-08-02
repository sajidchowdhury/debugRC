<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * FiscalYear — a fiscal year definition.
 *
 * Each fiscal year has a configurable start/end date, a period type (monthly/quarterly/yearly),
 * and a status lifecycle: draft → active → closed → locked.
 *
 * Contains fiscal_periods (individual months/quarters within the year).
 *
 * Status lifecycle:
 *   draft    → Initial state, can be edited
 *   active   → Live, periods can be closed/reopened
 *   closed   → Year-end close completed, all periods locked
 *   locked   → Immutable, no changes allowed (superadmin can unlock)
 */
class FiscalYear extends Model
{
    use SoftDeletes;

    protected $table = 'fiscal_years';

    protected $fillable = [
        'name',
        'fiscal_year_code',
        'start_date',
        'end_date',
        'branch_id',
        'period_type',
        'status',
        'is_current',
        'description',
        'created_by',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_current' => 'boolean',
        'closed_at'  => 'datetime',
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

    public function periods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closeLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PeriodCloseLog::class, 'fiscal_year_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCurrent(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeForDate(\Illuminate\Database\Eloquent\Builder $query, string $date): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('start_date', '<=', $date)
                      ->where('end_date', '>=', $date);
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

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function maxPeriodNumber(): int
    {
        return match ($this->period_type) {
            'quarterly' => 4,
            'yearly'    => 1,
            default     => 12,
        };
    }

    public function getDurationInDays(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function getOpenPeriodsCount(): int
    {
        return $this->periods()->where('status', 'open')->count();
    }

    public function getClosedPeriodsCount(): int
    {
        return $this->periods()->whereIn('status', ['closed', 'locked'])->count();
    }

    public function getProgressPercent(): float
    {
        $total = $this->periods()->count();
        if ($total === 0) return 0;
        $closed = $this->getClosedPeriodsCount();
        return round(($closed / $total) * 100, 1);
    }

    public static function statusOptions(): array
    {
        return [
            'draft'  => 'Draft',
            'active' => 'Active',
            'closed' => 'Closed',
            'locked' => 'Locked',
        ];
    }

    public static function periodTypeOptions(): array
    {
        return [
            'monthly'   => 'Monthly',
            'quarterly' => 'Quarterly',
            'yearly'    => 'Yearly',
        ];
    }
}
