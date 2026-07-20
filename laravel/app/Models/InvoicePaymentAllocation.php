<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Invoice Payment Allocation — P1-4.
 *
 * Allocates a customer payment to a specific sales invoice (FIFO or
 * manual allocation). This is the SINGLE allocation table after P1-4
 * consolidated away the redundant customer_payment_settlements.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $payment_id
 * @property string $allocated_amount
 * @property \Illuminate\Support\Carbon $created_at
 */
class InvoicePaymentAllocation extends Model
{
    protected $table = 'invoice_payment_allocations';

    public $timestamps = false; // only created_at, no updated_at

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'payment_id', 'allocated_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'invoice_id' => 'integer',
        'payment_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'payment_id');
    }
}
