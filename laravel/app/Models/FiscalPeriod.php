<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FiscalPeriod — an individual period (month/quarter) within a fiscal year.
 *
 * Each fiscal year has 12 monthly periods, 4 quarterly periods, or 1 annual period.
 * Period status: open → closed → locked
 *
 * Status lifecycle:
 *   open   → Period is open for posting
 *   closed → Period is closed (no posting allowed, can be reopened by admin)
 *   locked → Period is locked (cannot be reopened, only superadmin can unlock)
 */
class FiscalPeriod extends Model
{
    protected $table = 'fiscal_periods';

    protected $fillable = [
        'fiscal_year_id',
        'period_number',
        'period_name',
        'start_date',
        'end_date',
        'status',
        'closed_by',
        'closed_at',
        'close_notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'closed_at'  => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function fiscalYear(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closeLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PeriodCloseLog::class, 'fiscal_period_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeOpen(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeLocked(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'locked');
    }

    public function scopeForDate(\Illuminate\Database\Eloquent\Builder $query, string $date): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('start_date', '<=', $date)
                      ->where('end_date', '>=', $date);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function containsDate(string $date): bool
    {
        return $this->start_date->format('Y-m-d') <= $date
            && $this->end_date->format('Y-m-d') >= $date;
    }

    public function getDurationInDays(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'open'   => '<span class="badge bg-success">Open</span>',
            'closed' => '<span class="badge bg-warning text-dark">Closed</span>',
            'locked' => '<span class="badge bg-danger">Locked</span>',
            default  => '<span class="badge bg-secondary">' . ucfirst($this->status) . '</span>',
        };
    }

    public static function statusOptions(): array
    {
        return [
            'open'   => 'Open',
            'closed' => 'Closed',
            'locked' => 'Locked',
        ];
    }
}
