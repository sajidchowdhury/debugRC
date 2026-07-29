<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Product UOM Conversion — Phase 5 (Stock Adjustment plan).
 *
 * Per-product conversion factor between two units of measure.
 *   1 from_uom = factor × to_uom
 *
 * to_uom is usually the product's base unit (the UOM whose code matches
 * products.unit). For example, a product with base unit 'Pcs' might have:
 *   (from=Carton, to=Pcs, factor=12)  → 1 Carton = 12 Pcs
 *   (from=Bag,    to=Pcs, factor=50)  → 1 Bag = 50 Pcs
 *
 * The base-unit self-conversion (from=base, to=base, factor=1) is implicit
 * — it does NOT need a row here. UomConversionService::resolveFactor()
 * returns factor=1 when fromUomId === the product's base unit id.
 *
 * @property int $id
 * @property int $product_id
 * @property int $from_uom_id
 * @property int $to_uom_id
 * @property string $factor  1 from_uom = factor to_uom
 */
class ProductUomConversion extends Model
{
    protected $table = 'product_uom_conversions';

    public $timestamps = true;

    protected $fillable = ['product_id', 'from_uom_id', 'to_uom_id', 'factor'];

    protected $casts = [
        'product_id'  => 'integer',
        'from_uom_id' => 'integer',
        'to_uom_id'   => 'integer',
        'factor'      => 'decimal:6',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function fromUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'from_uom_id');
    }

    public function toUom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'to_uom_id');
    }
}
