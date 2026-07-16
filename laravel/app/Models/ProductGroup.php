<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

class ProductGroup extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'product_groups';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'group_name',
        'description',
        'sort_order',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class, 'group_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
