<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer Payment Settlement — Phase 8.4.
 * Allocates a payment to specific invoices (FIFO or manual allocation).
 *
 * @property int $id
 * @property int $payment_id
 * @property int $invoice_id
 * @property string $allocated_amount
 */
class CustomerPaymentSettlement extends Model
{
    protected $table = 'customer_payment_settlements';

    public $timestamps = false;

    protected $fillable = ['payment_id', 'invoice_id', 'allocated_amount'];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'payment_id' => 'integer',
        'invoice_id' => 'integer',
    ];

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'payment_id');
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
