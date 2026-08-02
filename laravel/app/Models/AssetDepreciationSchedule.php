<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AssetDepreciationSchedule — Phase 9.4: Fixed Asset & Depreciation
 *
 * Tracks each monthly depreciation calculation for a fixed asset.
 * Each schedule record can be posted (creates a journal entry) or reversed.
 *
 * Status lifecycle:
 *   pending → posted → reversed
 *
 * @property int $id
 * @property int $fixed_asset_id
 * @property string $depreciation_date
 * @property string $period_from
 * @property string $period_to
 * @property string $depreciation_method
 * @property float $opening_book_value
 * @property float $depreciation_amount
 * @property float $closing_book_value
 * @property float $units_produced
 * @property float $rate_per_unit
 * @property float $declining_balance_rate_used
 * @property int|null $journal_entry_id
 * @property string $status
 */
class AssetDepreciationSchedule extends Model
{
    protected $table = 'asset_depreciation_schedules';

    public $timestamps = true;

    protected $fillable = [
        'fixed_asset_id',
        'depreciation_date',
        'period_from',
        'period_to',
        'depreciation_method',
        'opening_book_value',
        'depreciation_amount',
        'closing_book_value',
        'units_produced',
        'rate_per_unit',
        'declining_balance_rate_used',
        'journal_entry_id',
        'status',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reverse_reason',
    ];

    protected $casts = [
        'depreciation_date' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
        'opening_book_value' => 'decimal:2',
        'depreciation_amount' => 'decimal:2',
        'closing_book_value' => 'decimal:2',
        'units_produced' => 'decimal:2',
        'rate_per_unit' => 'decimal:6',
        'declining_balance_rate_used' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function fixedAsset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopePending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeForPeriod(\Illuminate\Database\Eloquent\Builder $query, string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('period_from', '>=', $from)->where('period_to', '<=', $to);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
