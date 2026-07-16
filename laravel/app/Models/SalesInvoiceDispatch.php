<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Invoice Dispatch — Phase 8.2.
 *
 * Tracks the dispatch pipeline per product: ordered_qty vs dispatched_qty.
 * warehouse_id is NULL until godown assigns it (Phase 8.3).
 * When dispatched_qty = ordered_qty, the product is fully dispatched.
 *
 * This is the "pipeline" that reduces available stock (StockAvailabilityService).
 *
 * @property int $id
 * @property int $sales_invoice_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $ordered_qty
 * @property string $dispatched_qty
 * @property int|null $created_by
 */
class SalesInvoiceDispatch extends Model
{
    protected $table = 'sales_invoice_dispatches';

    public $timestamps = false;

    protected $fillable = [
        'sales_invoice_id', 'product_id', 'warehouse_id',
        'ordered_qty', 'dispatched_qty', 'created_by',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:4',
        'dispatched_qty' => 'decimal:4',
        'sales_invoice_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Remaining qty to dispatch = ordered - dispatched.
     */
    public function remainingQty(): float
    {
        return max(0, (float) $this->ordered_qty - (float) $this->dispatched_qty);
    }
}
