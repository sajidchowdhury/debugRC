<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unit of Measure — Phase 5 (Stock Adjustment plan).
 *
 * Master list of units (Pcs, Carton, KG, Bag, Dobe, Set). Seeded from the
 * products.unit CHECK enum by migration 2025_08_06_000001_create_uom_tables.php.
 *
 * A product's BASE UNIT is the UOM whose code matches products.unit
 * (e.g. a product with unit='Pcs' has base unit = the Pcs row here). The
 * base unit always has an implicit factor of 1 — no product_uom_conversions
 * row is required for the self-conversion.
 *
 * @property int $id
 * @property string $code   Pcs, Carton, KG, Bag, Dobe, Set
 * @property string $name   Human-readable label
 * @property string $type   count, weight, volume
 */
class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    public $timestamps = true;

    protected $fillable = ['code', 'name', 'type'];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'type' => 'string',
    ];

    /**
     * Conversions where this unit is the source (from_uom).
     */
    public function conversionsFrom(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductUomConversion::class, 'from_uom_id');
    }

    /**
     * Conversions where this unit is the target (to_uom).
     */
    public function conversionsTo(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductUomConversion::class, 'to_uom_id');
    }

    /**
     * Scope: find a unit by its code (case-sensitive — codes are canonical).
     */
    public function scopeByCode(\Illuminate\Database\Eloquent\Builder $query, string $code): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('code', $code);
    }
}
