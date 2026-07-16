<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Stock Adjustment — Phase 6.3.
 *
 * Two-phase flow (better than legacy immediate-post):
 *   1. Create (status=draft): header + items, NO stock movement, NO GL
 *   2. Confirm (status=confirmed): applies stock via StockService + posts GL journal
 *   3. Cancel (status=cancelled): if confirmed, reverses stock + GL; if draft, just marks cancelled
 *
 * Adjustment types:
 *   - increase: stock goes UP (Dr Inventory / Cr Surplus)
 *   - decrease: stock goes DOWN (Dr Shrinkage / Cr Inventory)
 *
 * @property int $id
 * @property string $adjustment_code
 * @property string $adjustment_date
 * @property int $warehouse_id
 * @property int $branch_id
 * @property string $adjustment_type increase|decrease
 * @property string $total_amount
 * @property string $reason
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id GL journal entry (set on confirm)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class StockAdjustment extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'stock_adjustments';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'adjustment_code',
        'adjustment_date',
        'warehouse_id',
        'branch_id',
        'adjustment_type',
        'total_amount',
        'reason',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'created_by',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Scope: adjustments for a specific branch.
     */
    public function scopeForBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Is this adjustment a draft (not yet confirmed)?
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Is this adjustment confirmed (stock moved + GL posted)?
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * Is this adjustment cancelled?
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Is this an increase adjustment (stock goes up)?
     */
    public function isIncrease(): bool
    {
        return $this->adjustment_type === 'increase';
    }

    /**
     * Is this a decrease adjustment (stock goes down)?
     */
    public function isDecrease(): bool
    {
        return $this->adjustment_type === 'decrease';
    }
}
