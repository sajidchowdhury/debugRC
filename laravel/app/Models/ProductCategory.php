<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

class ProductCategory extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'product_categories';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'category_name',
        'description',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
