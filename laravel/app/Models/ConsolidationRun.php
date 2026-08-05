<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ConsolidationRun — tracks each consolidation execution.
 *
 * A consolidation run combines the financial statements of all companies
 * (or selected companies) into a single group view. It generates
 * elimination entries to remove intercompany balances and transactions.
 *
 * Status lifecycle:
 *   draft    → Elimination entries calculated but not posted
 *   posted   → Elimination journal entries posted to the GL
 *   reversed → Elimination entries reversed (undo the consolidation)
 *
 * Each run is linked to a fiscal year and period range.
 */
class ConsolidationRun extends Model
{
    use SoftDeletes;

    protected $table = 'consolidation_runs';

    // G-279 (G18) FINANCE-CONSOLIDATION-1: cascade soft-delete to elimination
    // entries. The FK fk_ee_consolidation_run REFERENCES consolidation_runs(id)
    // ON DELETE CASCADE only fires on HARD delete — Laravel's SoftDeletes issues
    // UPDATE ... SET deleted_at = NOW(), not DELETE, so without this event
    // listener, soft-deleting a run would leave orphaned elimination_entries
    // (still queryable, still pointing at a "deleted" run). This listener
    // iterates the run's eliminationEntries and soft-deletes each one in
    // lockstep. The EliminationEntry model now uses SoftDeletes (G-279) +
    // migration 2026_09_06_000007 adds the deleted_at column.
    protected static function booted(): void
    {
        static::deleting(function (ConsolidationRun $run) {
            if ($run->isForceDeleting()) {
                // Force delete — let the DB FK ON DELETE CASCADE handle children.
                return;
            }
            // Soft delete — cascade to children (Eloquent SoftDeletes, not DB cascade).
            $run->eliminationEntries()->each(fn (EliminationEntry $entry) => $entry->delete());
        });
    }

    protected $fillable = [
        'run_code',
        'name',
        'period_from',
        'period_to',
        'status',
        'fiscal_year_id',
        'company_ids',
        'elimination_summary',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reverse_reason',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'company_ids' => 'array',
        'elimination_summary' => 'array',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function fiscalYear(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function eliminationEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EliminationEntry::class, 'consolidation_run_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeDraft(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopePosted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'posted');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function canPost(): bool
    {
        return $this->isDraft();
    }

    public function canReverse(): bool
    {
        return $this->isPosted();
    }

    public function getTotalEliminationAmount(): float
    {
        return (float) $this->eliminationEntries()->sum('elimination_amount');
    }

    public function getEntryCount(): int
    {
        return $this->eliminationEntries()->count();
    }

    public static function statusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'posted' => 'Posted',
            'reversed' => 'Reversed',
        ];
    }
}
