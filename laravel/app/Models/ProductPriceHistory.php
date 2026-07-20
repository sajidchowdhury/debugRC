<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'min_rate',
        'max_rate',
        'default_rate',
        'effective_from',
        'effective_to',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'min_rate' => 'decimal:2',
        'max_rate' => 'decimal:2',
        'default_rate' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'created_at' => 'datetime',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Scope: prices effective on a given date.
     */
    public function scopeEffectiveOn(\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
    }
}
