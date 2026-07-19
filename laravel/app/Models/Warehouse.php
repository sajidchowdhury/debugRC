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
        'created_by',
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
