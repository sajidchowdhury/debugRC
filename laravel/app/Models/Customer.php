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

    // ───────── Reverse Relationships (Customer 360 Hub) ─────────

    /**
     * All sales invoices for this customer (including reversed/cancelled).
     * Use ->where('is_reversed', false)->whereNotIn('status', ['cancelled']) for active only.
     */
    public function salesInvoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    /**
     * All payments received from this customer.
     */
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerPayment::class, 'customer_id');
    }

    /**
     * Customer sub-ledger entries (AR running balance).
     */
    public function ledgerEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerLedger::class, 'customer_id');
    }

    /**
     * All sales returns for this customer.
     */
    public function salesReturns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesReturn::class, 'customer_id');
    }
}
