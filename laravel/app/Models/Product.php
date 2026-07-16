<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Product — maps to legacy `products` table.
 * Electronics inventory items with category, group, pricing, and stock tracking.
 */
class Product extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'products';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'product_code',
        'product_name',
        'category_id',
        'group_id',
        'unit',
        'purchase_rate',
        'sales_rate',
        'min_stock',
        'max_stock',
        'reorder_level',
        'product_image',
        'is_active',
        'condition_state',
        'deleted_by',
    ];

    protected $casts = [
        'purchase_rate' => 'decimal:2',
        'sales_rate' => 'decimal:2',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    public function priceHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'product_id');
    }

    /**
     * Get the current-effective price record (effective_from <= today, effective_to IS NULL or >= today).
     */
    public function currentPrice(): ?ProductPriceHistory
    {
        return $this->priceHistory()
            ->where('effective_from', '<=', today())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
            })
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
