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
 * @property string $adjustment_category Phase 2: structured reason category
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

    /**
     * Phase 2 — structured adjustment categories.
     * Mirrors the DB-level CHECK constraint `sa_category_check` (see
     * migration 2025_07_28_000020_add_category_to_stock_adjustments.php).
     * Kept here so the service, controller, and blade views all read from
     * a single source of truth.
     */
    public const ADJUSTMENT_CATEGORIES = [
        'opening_balance',
        'data_migration',
        'uom_correction',
        'post_conversion_fix',
        'legacy_cleanup',
        'reconciliation_variance',
        'other',
    ];

    /**
     * Human-readable labels for each category — used by the create-form
     * dropdown, the index badge, and the show-page detail row.
     */
    public const CATEGORY_LABELS = [
        'opening_balance'       => 'Opening Balance',
        'data_migration'        => 'Data Migration',
        'uom_correction'        => 'UOM / Unit-of-Measure Correction',
        'post_conversion_fix'   => 'Post-Conversion Fix',
        'legacy_cleanup'        => 'Legacy Cleanup',
        'reconciliation_variance' => 'Reconciliation Variance',
        'other'                 => 'Other',
    ];

    /**
     * Bootstrap-icons + badge classes for each category — used by the index
     * and show views to render a consistent coloured badge. Centralised here
     * so a future category addition only needs to touch this map.
     */
    public const CATEGORY_BADGES = [
        'opening_balance'       => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-flag'],
        'data_migration'        => ['cls' => 'bg-primary-subtle text-primary',      'icon' => 'fa-database'],
        'uom_correction'        => ['cls' => 'bg-warning-subtle text-warning',      'icon' => 'fa-ruler-combined'],
        'post_conversion_fix'   => ['cls' => 'bg-secondary-subtle text-secondary',  'icon' => 'fa-screwdriver-wrench'],
        'legacy_cleanup'        => ['cls' => 'bg-secondary-subtle text-secondary',  'icon' => 'fa-broom'],
        'reconciliation_variance' => ['cls' => 'bg-danger-subtle text-danger',      'icon' => 'fa-scale-balanced'],
        'other'                 => ['cls' => 'bg-light text-muted',                 'icon' => 'fa-ellipsis'],
    ];

    protected $fillable = [
        'adjustment_code',
        'adjustment_date',
        'warehouse_id',
        'branch_id',
        'adjustment_type',
        'adjustment_category',
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

    /**
     * Phase 2 — is this an opening-balance adjustment?
     *
     * Opening-balance adjustments route to `stock_transactions.reference_type
     * = 'opening_balance'` (not 'stock_adjustment') when confirmed, so the
     * immutable ledger can distinguish initial-onboarding stock from later
     * operational corrections. See StockAdjustmentService::confirmAdjustment.
     */
    public function isOpenBalance(): bool
    {
        return $this->adjustment_category === 'opening_balance';
    }

    /**
     * Phase 2 — human-readable label for the adjustment category.
     * Falls back to a prettified version of the raw value if the category
     * is somehow not in the canonical map (defensive — should never happen
     * because the DB CHECK constraint rejects unknown values).
     */
    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->adjustment_category]
            ?? ucfirst(str_replace('_', ' ', $this->adjustment_category ?? 'other'));
    }

    /**
     * Phase 2 — rendered HTML badge for the adjustment category.
     * Used by the index and show views so the badge style is consistent
     * everywhere and driven by the central CATEGORY_BADGES map.
     */
    public function categoryBadge(): string
    {
        $cat = $this->adjustment_category ?? 'other';
        $meta = self::CATEGORY_BADGES[$cat]
            ?? ['cls' => 'bg-light text-muted', 'icon' => 'fa-ellipsis'];
        $label = e($this->categoryLabel());
        $cls = e($meta['cls']);
        $icon = e($meta['icon']);
        return '<span class="badge ' . $cls . '">'
            . '<i class="fas ' . $icon . ' me-1"></i>' . $label
            . '</span>';
    }

    /**
     * Phase 2 — the reference_type that should be written to
     * stock_transactions when this adjustment is confirmed.
     *
     * Opening-balance adjustments use 'opening_balance' so the ledger can
     * distinguish them; all other categories use the generic
     * 'stock_adjustment' reference_type.
     */
    public function ledgerReferenceType(): string
    {
        return $this->isOpenBalance() ? 'opening_balance' : 'stock_adjustment';
    }
}
