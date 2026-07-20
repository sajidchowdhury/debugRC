<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Damage Invoice Item — Phase 6.6.
 *
 * @property int $id
 * @property int $damage_invoice_id
 * @property int $product_id
 * @property string $qty
 * @property string $rate
 */
class DamageInvoiceItem extends Model
{
    protected $table = 'damage_invoice_items';

    public $timestamps = false;

    protected $fillable = [
        'damage_invoice_id',
        'product_id',
        'qty',
        'rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
    ];

    public function damage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DamageInvoice::class, 'damage_invoice_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function amount(): float
    {
        return (float) $this->qty * (float) $this->rate;
    }
}
