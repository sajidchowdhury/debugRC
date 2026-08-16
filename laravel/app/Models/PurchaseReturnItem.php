<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Purchase Return Item — Phase 7.3 + Phase 5 (condition).
 *
 * @property int $id
 * @property int $purchase_return_id
 * @property int|null $purchase_receive_item_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $qty
 * @property string $rate
 * @property string $amount GENERATED: qty × rate
 * @property string $condition 'Good' | 'Damage' — Damage = no stock movement
 */
class PurchaseReturnItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'purchase_return_items';

    public $timestamps = false;

    protected $fillable = [
        'purchase_return_id',
        'purchase_receive_item_id',
        'product_id',
        'warehouse_id',
        'qty',
        'rate',
        'condition',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'purchase_return_id' => 'integer',
        'purchase_receive_item_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'condition' => 'string',
    ];

    /**
     * Phase 5: True if this return line is a Damage condition (no stock
     * movement — supplier claim only). GL + supplier_ledger still posted.
     */
    public function isDamage(): bool
    {
        return strcasecmp((string) $this->condition, 'Damage') === 0;
    }

    /**
     * Phase 5: True if this return line is a Good condition (stock OUT +
     * GL + supplier_ledger — the default pre-Phase-5 behavior).
     */
    public function isGood(): bool
    {
        return !$this->isDamage();
    }

    public function return(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function amount(): float
    {
        return (float) $this->qty * (float) $this->rate;
    }

    /**
     * Phase 5: Human-readable condition label for blade views.
     */
    public function conditionLabel(): string
    {
        return $this->isDamage() ? 'Damage' : 'Good';
    }
}
