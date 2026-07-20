<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Customer — maps to legacy `customers` table.
 * Shop accounts for sales invoices, challan, payments, and customer_ledger AR.
 */
class Customer extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'customers';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'customer_code',
        'customer_name',
        'phone',
        'mobile',
        'email',
        'address',
        'branch_id',
        'sales_person_id',
        'credit_limit',
        'opening_balance',
        'balance_type',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function salesPerson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_person_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
