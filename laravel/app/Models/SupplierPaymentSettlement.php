<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplier Payment Settlement — GRN allocation.
 *
 * Links a supplier payment to one or more purchase receives (GRNs).
 * Each settlement row records how much of the payment was allocated
 * to a specific GRN.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $purchase_receive_id
 * @property string $settled_amount
 */
class SupplierPaymentSettlement extends Model
{
    protected $table = 'supplier_payment_settlements';

    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'purchase_receive_id',
        'settled_amount',
    ];

    protected $casts = [
        'settled_amount' => 'decimal:2',
        'payment_id' => 'integer',
        'purchase_receive_id' => 'integer',
    ];

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }

    public function purchaseReceive(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\PurchaseReceive::class, 'purchase_receive_id');
    }
}
