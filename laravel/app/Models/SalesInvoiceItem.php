<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Invoice Item — Phase 8.2.
 *
 * @property int $id
 * @property int $sales_invoice_id
 * @property int $product_id
 * @property int|null $warehouse_id (NULL until godown assigns, Phase 8.3)
 * @property string $qty
 * @property string $rate
 * @property string $amount GENERATED: qty × rate
 * @property string|null $condition_state
 */
class SalesInvoiceItem extends Model
{
    protected $table = 'sales_invoice_items';

    public $timestamps = false;

    protected $fillable = [
        'sales_invoice_id', 'product_id', 'warehouse_id',
        'qty', 'rate', 'condition_state',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'sales_invoice_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
