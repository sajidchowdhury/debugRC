<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Return Item — Phase 8.5 + Phase 0.3 (condition helpers).
 *
 * @property int $id
 * @property int $sales_return_id
 * @property int $sales_invoice_item_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $qty
 * @property string $rate (sales rate — for revenue reversal)
 * @property string $amount GENERATED: qty × rate
 * @property string $original_cost (ORIGINAL avg_cost at time of challan — for COGS reversal + stock IN)
 * @property string $condition_state 'Good' | 'Damage' — Damage = auto-creates a linked damage_invoice (no stock IN for that line)
 * @property int|null $damage_invoice_id FK to damage_invoices.id (set when condition_state='Damage' and the linked write-off has been created)
 */
class SalesReturnItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'sales_return_items';

    public $timestamps = false;

    protected $fillable = [
        'sales_return_id', 'sales_invoice_item_id', 'product_id', 'warehouse_id',
        'qty', 'rate', 'original_cost', 'damage_invoice_id', 'condition_state',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'original_cost' => 'decimal:2',
        'sales_return_id' => 'integer',
        'sales_invoice_item_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'damage_invoice_id' => 'integer',
        'condition_state' => 'string',
    ];

    public function salesReturn(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Linked damage invoice (set when condition_state='Damage' and the
     * auto write-off has been created via DamageService). Null for Good lines.
     */
    public function damageInvoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DamageInvoice::class, 'damage_invoice_id');
    }

    // ===================== CONDITION HELPERS (Phase 0.3) =====================
    // Mirrors PurchaseReturnItem::isDamage/isGood/conditionLabel.
    // Good  = stock IN + COGS reversal + revenue reversal (default).
    // Damage = NO stock IN; auto-creates a linked damage_invoice write-off
    //          (goods are unsellable, written off to a damage expense account).

    /**
     * True if this return line is a Damage condition (no stock IN — instead a
     * linked damage_invoice is auto-created to write off the unsellable goods).
     */
    public function isDamage(): bool
    {
        return strcasecmp((string) $this->condition_state, 'Damage') === 0;
    }

    /**
     * True if this return line is a Good condition (stock IN + COGS reversal +
     * revenue reversal — the default behavior).
     */
    public function isGood(): bool
    {
        return !$this->isDamage();
    }

    /**
     * Human-readable condition label for blade views.
     */
    public function conditionLabel(): string
    {
        return $this->isDamage() ? 'Damage' : 'Good';
    }
}
