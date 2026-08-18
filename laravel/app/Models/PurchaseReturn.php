<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;
use App\Traits\AuditableMasterData;
use App\Traits\ApplySystemPolicyScope;

/**
 * Purchase Return — Phase 7.3.
 *
 * Returns goods to a supplier. Always against a GRN (purchase_receive_id).
 * Two-phase: draft → confirm → cancel.
 *
 * On confirm:
 *   - Stock OUT via StockService at ORIGINAL receive rate (not current avg_cost)
 *   - GL: Dr Accounts Payable / Cr Inventory (reverse of GRN)
 *   - Supplier ledger: debit entry (we owe the supplier less)
 *   - GRN item return_qty updated (tracks cumulative returns)
 *
 * Return qty cap: returnable_qty = received_qty - already_returned.
 *
 * @property int $id
 * @property string $return_code
 * @property string $return_date
 * @property int $purchase_receive_id
 * @property int $supplier_id
 * @property int $branch_id
 * @property int $warehouse_id
 * @property string $total_amount
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property string|null $reason
 * @property int|null $created_by
 */
class PurchaseReturn extends Model
{
    use SoftDeletes, AuditableMasterData, ApplySystemPolicyScope, BelongsToFiscalYear;

    protected $table = 'purchase_returns';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Phase 8 (BUG-40 fix): Apply BranchScope global scope so non-admin
     * users can only read returns from their own session branch. This
     * closes the cross-branch read leak in show()/slip() — findOrFail
     * now throws ModelNotFoundException (404) instead of returning the
     * other branch's record. Admins bypass the scope (see BranchScope).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * G-171 (AUDIT-TRAIL-2): the date column clamped by INVESTIGATION mode.
     */
    protected function policyDateColumn(): string
    {
        return 'return_date';
    }

    protected $fillable = [
        'fiscal_year_id',
        'return_code',
        'return_date',
        'purchase_receive_id',
        'supplier_id',
        'branch_id',
        'warehouse_id',
        'total_amount',
        'status',
        'confirmed_by',   // PURCHASING-3 G-039
        'confirmed_at',   // PURCHASING-3 G-039
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'notes',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'confirmed_at' => 'datetime',   // PURCHASING-3 G-039
        'purchase_receive_id' => 'integer',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'confirmed_by' => 'integer',   // PURCHASING-3 G-039
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'purchase_return_id');
    }

    public function purchaseReceive(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseReceive::class, 'purchase_receive_id');
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Phase 6: creator — used by the printable Return slip.
     */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
