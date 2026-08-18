<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

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
 */
class SalesInvoiceItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'sales_invoice_items';

    public $timestamps = false;

    // G-160 (SALES-AUDIT-2): condition_state DROPPED — was always 'Good',
    // never 'Damage' at the invoice layer. Damage is tracked via
    // sales_return_items.condition_state + damage_invoices.
    protected $fillable = [
        'fiscal_year_id',
        'sales_invoice_id', 'product_id', 'warehouse_id',
        'qty', 'rate',
        // Session 5: price + cost snapshots + classification.
        'price_min', 'price_max', 'price_default',
        'cost_rate', 'price_classification',
        'branch_demand_item_id', 'below_min_override_id',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'sales_invoice_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        // Session 5: numeric snapshots.
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'price_default' => 'decimal:2',
        'cost_rate' => 'decimal:4',
        'branch_demand_item_id' => 'integer',
        'below_min_override_id' => 'integer',
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
