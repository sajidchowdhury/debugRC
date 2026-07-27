<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Warehouse — maps to legacy `warehouses` table.
 * Each warehouse belongs to a branch.
 */
class Warehouse extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'warehouses';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'warehouse_code',
        'warehouse_name',
        'branch_id',
        'location',
        'is_active',
        'is_frozen_for_count',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_frozen_for_count' => 'boolean',
    ];

    /**
     * Phase 3: is this warehouse currently frozen by an active stock-take
     * session? Mirrors the denormalized `is_frozen_for_count` flag maintained
     * by StockTakeService::refreshWarehouseFreezeFlags.
     */
    public function isFrozenForCount(): bool
    {
        return (bool) $this->is_frozen_for_count;
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Scope: active, non-deleted warehouses.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
