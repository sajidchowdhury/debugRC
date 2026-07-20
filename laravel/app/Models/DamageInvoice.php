<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Damage Invoice — Phase 6.6.
 *
 * Records damaged/write-off stock. Two-phase flow:
 *   1. Create (draft): header + items, NO stock movement, NO GL
 *   2. Confirm: stock OUT via StockService + GL (Dr Damage Loss / Cr Inventory)
 *   3. Cancel: if confirmed, reverses stock + GL; if draft, marks cancelled
 *
 * GL posting (re-derived from double-entry):
 *   Dr Damage Loss (shrinkage) / Cr Inventory
 *   The loss is valued at the current avg_cost at time of damage.
 *
 * @property int $id
 * @property string $damage_code
 * @property string $damage_date
 * @property int $warehouse_id
 * @property int $branch_id
 * @property string $total_value
 * @property string $reason
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class DamageInvoice extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'damage_invoices';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'damage_code',
        'damage_date',
        'warehouse_id',
        'branch_id',
        'sales_return_id',
        'total_value',
        'reason',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'created_by',
    ];

    protected $casts = [
        'damage_date' => 'date',
        'total_value' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'warehouse_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DamageInvoiceItem::class, 'damage_invoice_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
