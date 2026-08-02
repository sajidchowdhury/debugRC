<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * FixedAsset — Phase 9.4: Fixed Asset & Depreciation
 *
 * Represents a fixed asset in the register. Each asset has:
 *   - An acquisition cost and date
 *   - A depreciation method and schedule
 *   - Ledger mappings for the asset account, accumulated depreciation, and expense
 *   - A status lifecycle: active → disposed / fully_depreciated
 *
 * Depreciation Methods:
 *   - straight_line:      (cost - salvage) / useful_life_months
 *   - declining_balance:  book_value * (rate / 100) / 12
 *   - units_of_production: (cost - salvage) / total_estimated_units * units_this_period
 *
 * @property int $id
 * @property string $asset_code
 * @property string $description
 * @property string $category
 * @property string $acquisition_date
 * @property float $acquisition_cost
 * @property float $salvage_value
 * @property string $depreciation_method
 * @property int $useful_life_months
 * @property float $declining_balance_rate
 * @property float $total_estimated_units
 * @property float $units_produced_to_date
 * @property int $asset_ledger_id
 * @property int $dep_ledger_id
 * @property int|null $dep_expense_ledger_id
 * @property int $branch_id
 * @property string $status
 * @property float $accumulated_depreciation
 * @property float $net_book_value
 * @property string|null $last_depreciation_date
 */
class FixedAsset extends Model
{
    use SoftDeletes;

    protected $table = 'fixed_assets';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'asset_code',
        'description',
        'category',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'depreciation_method',
        'useful_life_months',
        'declining_balance_rate',
        'total_estimated_units',
        'units_produced_to_date',
        'asset_ledger_id',
        'dep_ledger_id',
        'dep_expense_ledger_id',
        'branch_id',
        'location',
        'status',
        'accumulated_depreciation',
        'net_book_value',
        'last_depreciation_date',
        'notes',
        'serial_number',
        'warranty_expiry',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'acquisition_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'declining_balance_rate' => 'decimal:2',
        'total_estimated_units' => 'decimal:2',
        'units_produced_to_date' => 'decimal:2',
        'branch_id' => 'integer',
        'accumulated_depreciation' => 'decimal:2',
        'net_book_value' => 'decimal:2',
        'last_depreciation_date' => 'date',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function assetLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'asset_ledger_id');
    }

    public function depLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'dep_ledger_id');
    }

    public function depExpenseLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'dep_expense_ledger_id');
    }

    public function depreciationSchedules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetDepreciationSchedule::class, 'fixed_asset_id');
    }

    public function disposals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetDisposal::class, 'fixed_asset_id');
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

    public function scopeDisposed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'disposed');
    }

    public function scopeFullyDepreciated(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'fully_depreciated');
    }

    public function scopeByCategory(\Illuminate\Database\Eloquent\Builder $query, string $category): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('category', $category);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisposed(): bool
    {
        return $this->status === 'disposed';
    }

    public function isFullyDepreciated(): bool
    {
        return $this->status === 'fully_depreciated';
    }

    public function canBeDepreciated(): bool
    {
        return $this->isActive() && $this->net_book_value > $this->salvage_value;
    }

    public function canBeDisposed(): bool
    {
        return $this->isActive() || $this->isFullyDepreciated();
    }

    public function getUsefulLifeYears(): float
    {
        return round($this->useful_life_months / 12, 1);
    }

    public function getMonthlyStraightLineDepreciation(): float
    {
        if ($this->depreciation_method !== 'straight_line') {
            return 0;
        }
        if ($this->useful_life_months <= 0) {
            return 0;
        }
        return round(($this->acquisition_cost - $this->salvage_value) / $this->useful_life_months, 2);
    }

    public function getDepreciationPercentage(): float
    {
        if ($this->acquisition_cost <= 0) {
            return 0;
        }
        return round(($this->accumulated_depreciation / $this->acquisition_cost) * 100, 2);
    }

    public static function categoryOptions(): array
    {
        return [
            'machinery' => 'Machinery & Equipment',
            'furniture' => 'Furniture & Fixtures',
            'vehicle' => 'Vehicles',
            'office_equipment' => 'Office Equipment',
            'computer' => 'Computer & IT Equipment',
            'building' => 'Buildings',
            'land' => 'Land',
            'other' => 'Other',
        ];
    }

    public static function methodOptions(): array
    {
        return [
            'straight_line' => 'Straight Line',
            'declining_balance' => 'Declining Balance',
            'units_of_production' => 'Units of Production',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            'active' => 'Active',
            'disposed' => 'Disposed',
            'fully_depreciated' => 'Fully Depreciated',
        ];
    }

    public function getStatusBadge(): string
    {
        return [
            'active' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>',
            'disposed' => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Disposed</span>',
            'fully_depreciated' => '<span class="badge bg-warning text-dark"><i class="fas fa-minus-circle me-1"></i>Fully Depreciated</span>',
        ][$this->status] ?? '<span class="badge bg-light text-dark">' . e($this->status) . '</span>';
    }

    public function getCategoryLabel(): string
    {
        return self::categoryOptions()[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    public function getMethodLabel(): string
    {
        return self::methodOptions()[$this->depreciation_method] ?? ucfirst(str_replace('_', ' ', $this->depreciation_method));
    }
}
