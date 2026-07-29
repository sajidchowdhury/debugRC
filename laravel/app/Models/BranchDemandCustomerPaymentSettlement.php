<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Branch Demand Customer Payment Settlement — tracks which bank customer
 * payments have settled which branch demands (FIFO).
 *
 * When a customer payment with payment_mode = 'bank' is recorded at the
 * debtor branch, it auto-settles open branch demands in FIFO order.
 * Cash payments do NOT settle demands (they use money transfers instead).
 *
 * @property int $id
 * @property int $payment_id FK to customer_payments
 * @property int $demand_id FK to branch_demands
 * @property string $settled_amount
 * @property string $created_at
 */
class BranchDemandCustomerPaymentSettlement extends Model
{
    protected $table = 'branch_demand_customer_payment_settlements';

    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'demand_id',
        'settled_amount',
        'created_at',
    ];

    protected $casts = [
        'settled_amount' => 'decimal:2',
        'payment_id' => 'integer',
        'demand_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'payment_id');
    }

    public function demand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'demand_id');
    }
}
