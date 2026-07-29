<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditableMasterData;

/**
 * Branch Demand — Cross-branch product supply request.
 *
 * Business flow:
 *   1. CREATE: Branch B (requester/debtor) creates a demand to Branch A (supplier/creditor).
 *      Status = 'pending'. No stock movement, no GL.
 *   2. SEND: Branch A's warehouse manager selects per-item FROM/TO warehouses and sends goods.
 *      Status = 'received'. Stock moves, GL posted, branch ledger updated.
 *   3. CONFIRM RECEIPT: Branch B's warehouse manager confirms receipt (Phase 5).
 *      Sets received_at / received_by. Required before reversal.
 *   4. SETTLE: Bank customer payments and inter-branch money transfers auto-settle
 *      open demands in FIFO order (oldest first).
 *   5. REVERSE: Undo a sent/received demand (stock restored, GL reversed, ledger reversed).
 *      Status = 'reversed'. Blocked until receipt is confirmed (Phase 5).
 *
 * Terminology:
 *   - from_branch_id = requester (debtor) — the branch that NEEDS the products
 *   - to_branch_id   = supplier (creditor) — the branch that SUPPLIES the products
 *
 * Note: The naming convention follows the legacy system where `from_branch` is
 * the branch creating the demand (requester) and `to_branch` is the branch
 * fulfilling it (supplier). This is the opposite of the stock movement direction.
 *
 * @property int $id
 * @property string $demand_code
 * @property string $demand_date
 * @property int $from_branch_id Requester (debtor)
 * @property int $to_branch_id Supplier (creditor)
 * @property string $status pending|received|rejected|reversed
 * @property float|null $total_value Locked total of qty × cost_rate at send time
 * @property float $settlement_amount Running total of FIFO settlements
 * @property int|null $warehouse_transfer_id FK to documentary warehouse_transfers row
 * @property int|null $journal_entry_id Creditor-branch fulfillment journal
 * @property int|null $journal_entry_id_debtor Debtor-branch fulfillment journal
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $received_at Warehouse manager receipt confirmation timestamp
 * @property int|null $received_by Warehouse manager who confirmed receipt
 * @property string|null $notes
 * @property int|null $created_by
 */
class BranchDemand extends Model
{
    use AuditableMasterData;

    protected $table = 'branch_demands';

    public $timestamps = true;

    protected $fillable = [
        'demand_code',
        'demand_date',
        'from_branch_id',
        'to_branch_id',
        'status',
        'total_value',
        'settlement_amount',
        'warehouse_transfer_id',
        'journal_entry_id',
        'journal_entry_id_debtor',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'received_at',
        'received_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'demand_date' => 'date',
        'total_value' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'received_at' => 'datetime',
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'warehouse_transfer_id' => 'integer',
        'journal_entry_id' => 'integer',
        'journal_entry_id_debtor' => 'integer',
        'received_by' => 'integer',
        'reversed_by' => 'integer',
        'created_by' => 'integer',
    ];

    // ===================== RELATIONSHIPS =====================

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchDemandItem::class, 'branch_demand_id');
    }

    public function fromBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function warehouseTransfer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'warehouse_transfer_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function debtorJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id_debtor');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function receivedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }

    public function reversedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reversed_by');
    }

    public function repricingAdjustments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchDemandRepricing::class, 'branch_demand_id');
    }

    public function moneyTransferSettlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchDemandMoneyTransferSettlement::class, 'demand_id');
    }

    public function customerPaymentSettlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchDemandCustomerPaymentSettlement::class, 'demand_id');
    }

    // ===================== SCOPES =====================

    public function scopePending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeReceived(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'received');
    }

    public function scopeNotReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Scope: demands where my branch is the requester (debtor).
     */
    public function scopeMyDemands(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('from_branch_id', $branchId);
    }

    /**
     * Scope: demands where my branch is the supplier (creditor).
     */
    public function scopeDemandsForMe(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('to_branch_id', $branchId);
    }

    /**
     * Scope: demands involving my branch (either direction).
     */
    public function scopeForBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) use ($branchId) {
            $q->where('from_branch_id', $branchId)
              ->orWhere('to_branch_id', $branchId);
        });
    }

    /**
     * Scope: open demands with outstanding balance (not fully settled).
     */
    public function scopeWithOutstanding(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'received')
                     ->where('is_reversed', false)
                     ->whereColumn('total_value', '>', 'settlement_amount');
    }

    /**
     * Scope: received demands awaiting receipt confirmation (received_at IS NULL).
     * These are demands where goods have been sent but the receiving
     * warehouse manager has not yet acknowledged receipt.
     */
    public function scopePendingReceipt(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'received')
                     ->where('is_reversed', false)
                     ->whereNull('received_at');
    }

    /**
     * Scope: received demands where the requesting branch (from_branch_id)
     * is the given branch and receipt is still pending.
     * Used by the receiving warehouse manager's "Pending Receipt" view.
     */
    public function scopePendingReceiptForBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('from_branch_id', $branchId)
                     ->where('status', 'received')
                     ->where('is_reversed', false)
                     ->whereNull('received_at');
    }

    // ===================== HELPERS =====================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed' || $this->is_reversed;
    }

    public function isReceiptConfirmed(): bool
    {
        return $this->received_at !== null;
    }

    /**
     * Outstanding amount = total_value - settlement_amount.
     */
    public function outstanding(): float
    {
        return max(0, (float) ($this->total_value ?? 0) - (float) ($this->settlement_amount ?? 0));
    }

    /**
     * Settlement progress as a percentage (0-100).
     */
    public function settlementProgress(): float
    {
        $total = (float) ($this->total_value ?? 0);
        if ($total <= 0) {
            return 0;
        }
        return min(100, round(((float) ($this->settlement_amount ?? 0) / $total) * 100, 1));
    }
}
