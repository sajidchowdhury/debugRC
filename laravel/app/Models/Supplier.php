<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Supplier — maps to legacy `suppliers` table.
 * Vendors for purchase orders, GRN, payments, and supplier_ledger AP.
 */
class Supplier extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'suppliers';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'supplier_code',
        'supplier_name',
        'phone',
        'mobile',
        'email',
        'address',
        'branch_id',
        'contact_person',
        'opening_balance',
        'balance_type',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
