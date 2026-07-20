<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Warehouse Transfer — Phase 6.5.
 *
 * Two-phase flow:
 *   1. Create (draft): header + items, NO stock movement, NO GL
 *   2. Confirm: applies stock (source OUT + dest IN via StockService) + posts GL
 *      - Same-branch: NO GL (just inventory reallocation within the branch)
 *      - Cross-branch: TWO intercompany GL journals (creditor + debtor)
 *   3. Cancel: if confirmed, reverses stock + GL; if draft, just marks cancelled
 *
 * Cross-branch intercompany GL:
 *   - From-branch (creditor): Dr Due-to-Branch / Cr Inventory
 *   - To-branch (debtor): Dr Inventory / Cr Due-from-Branch
 * This creates the intercompany settlement tracked via branch_ledger.
 *
 * @property int $id
 * @property string $transfer_code
 * @property string $transfer_date
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property bool $is_interbranch
 * @property string $total_amount
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id From-branch (creditor) journal
 * @property int|null $journal_entry_id_debtor To-branch (debtor) journal
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
class WarehouseTransfer extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'warehouse_transfers';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'transfer_code',
        'transfer_date',
        'from_warehouse_id',
        'to_warehouse_id',
        'from_branch_id',
        'to_branch_id',
        'is_interbranch',
        'total_amount',
        'status',
        'journal_entry_id',
        'journal_entry_id_debtor',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'is_interbranch' => 'boolean',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'journal_entry_id_debtor' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WarehouseTransferItem::class, 'warehouse_transfer_id');
    }

    public function fromWarehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function fromBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function debtorJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id_debtor');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
