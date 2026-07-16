<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Warehouse — maps to legacy `warehouses` table.
 * Each warehouse belongs to a branch.
 */
class Warehouse extends Model
{
    use SoftDeletes;

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
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
