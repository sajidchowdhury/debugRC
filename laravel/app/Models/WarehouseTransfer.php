<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\WarehouseTransferBranchScope;

/**
 * Warehouse Transfer — Phase 6.5 + Phase 1 + Phase 3.
 *
 * Two-phase flow:
 *   1. Create (draft): header + items, NO stock movement, NO GL
 *   2. Confirm: applies stock (source OUT + dest IN via StockService)
 *      - Same-branch ONLY: NO GL (just inventory reallocation within the branch)
 *      - Cross-branch transfers are BLOCKED — must use Branch Demand module
 *   3. Cancel: if confirmed, reverses stock; if draft, just marks cancelled
 *
 * Phase 1 — Same-branch enforcement (defense-in-depth):
 *   - WarehouseTransferBranchScope: Eloquent global scope filtering by branch
 *   - WarehouseBelongsToBranch: validation rule on create form
 *   - Controller-level branch guard: checks before service call
 *   - Service-level enforcement: throws InvalidArgumentException if branches differ
 *   - PostgreSQL trigger: DB-level enforcement (enforce_same_branch_transfer)
 *
 * Phase 3 — Reversal safety & ordering:
 *   - sortMovementsForReversal: dest IN reversed before source OUT
 *   - Demand-linked reversal protection: branch_demand_id check
 *   - Warehouse freeze check: source warehouse frozen blocks draft creation
 *
 * NOTE: Cross-branch intercompany GL is handled by Branch Demand module,
 * not by WarehouseTransfer. The postIntercompanyGL() method is retained
 * for potential Branch Demand use but is NEVER called from WarehouseTransfer.
 *
 * @property int $id
 * @property string $transfer_code
 * @property string $transfer_date
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property bool $is_interbranch
 * @property int|null $branch_demand_id FK to branch_demands (if linked to a demand)
 * @property float $total_amount Computed: sum(items.qty * items.rate) — no DB column.
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

    /** Phase 1: Apply WarehouseTransferBranchScope for branch isolation. */
    protected static function booted(): void
    {
        static::addGlobalScope(new WarehouseTransferBranchScope);
    }

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
        'branch_demand_id', // Phase 3: FK to branch_demands — demand-linked reversal protection
        // 'total_amount' is intentionally absent — it is a computed accessor
        // (sum of items.qty * items.rate), not a persisted column.
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
        // 'total_amount' has no cast — it is a computed accessor (see below).
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'branch_demand_id' => 'integer',
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

    /**
     * Phase 3: The Branch Demand that this transfer is linked to (if any).
     * Transfers linked to a Branch Demand cannot be cancelled via
     * WarehouseTransfer — they must be cancelled through the Branch
     * Demand module which handles the full reversal workflow.
     */
    public function branchDemand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'branch_demand_id');
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

    /**
     * Computed total = sum(qty * rate) over loaded line items.
     *
     * The warehouse_transfers table has NO total_amount column by design —
     * line items (warehouse_transfer_items.qty * rate) are the source of
     * truth. Callers must eager-load 'items' to avoid an N+1. All current
     * callers (index controller, create/confirm services) already do.
     *
     * @return float
     */
    public function getTotalAmountAttribute(): float
    {
        return (float) $this->items->sum(fn ($item) => (float) $item->qty * (float) $item->rate);
    }
}
