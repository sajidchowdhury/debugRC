<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Branch — maps to legacy `branches` table.
 * RC_ERP has 4 branches: Head Office, Patuatuli, Nowabpur, Tarabo.
 */
class Branch extends Model
{
    use SoftDeletes;

    protected $table = 'branches';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'branch_code',
        'branch_name',
        'address',
        'phone',
        'email',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Employee::class, 'branch_id');
    }

    public function warehouses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }
}
