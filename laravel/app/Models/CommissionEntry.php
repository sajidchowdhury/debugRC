<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Commission Entry — Task 37.
 *
 * The core ledger of commission amounts owed to salesmen.
 * Each row represents one commission calculation event:
 *   - A payment allocation to an invoice (positive entry)
 *   - A sales return reversal (negative entry)
 *   - A manual adjustment (positive or negative)
 *
 * STATUS WORKFLOW:
 *   calculated → confirmed → paid
 *   Any status → reversed (if the underlying transaction is reversed)
 *
 * calculated: Auto-generated when a payment is allocated. Not yet approved.
 * confirmed: Approved by manager at month-end. GL entry posted.
 * paid: Employee has been paid via employee_transactions.
 * reversed: Underlying payment/return was reversed.
 *
 * PARTITIONING NOTE:
 *   References sales_invoices (partitioned) via trigger-based FK enforcement
 *   (trg_fk_ce_si), following the same pattern as Task 34's child tables.
 *
 * @property int $id
 * @property int $salesman_id
 * @property int $branch_id
 * @property int|null $sales_invoice_id
 * @property int $commission_rule_id
 * @property int|null $allocation_id
 * @property int|null $sales_return_id
 * @property string $invoice_total
 * @property string $commission_base
 * @property string $commission_rate
 * @property string $commission_amount
 * @property string $status calculated|confirmed|paid|reversed
 * @property string $entry_date
 * @property int|null $journal_entry_id
 * @property int|null $reversed_by_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $commission_period Format: '2025-01'
 * @property string|null $notes
 * @property int|null $created_by
 */
class CommissionEntry extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'commission_entries';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Branch isolation global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'salesman_id', 'branch_id', 'sales_invoice_id', 'commission_rule_id',
        'allocation_id', 'sales_return_id',
        'invoice_total', 'commission_base', 'commission_rate', 'commission_amount',
        'status', 'entry_date',
        'journal_entry_id', 'reversed_by_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'commission_period', 'notes', 'created_by',
    ];

    protected $casts = [
        'invoice_total' => 'decimal:2',
        'commission_base' => 'decimal:2',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'entry_date' => 'date',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'salesman_id' => 'integer',
        'branch_id' => 'integer',
        'sales_invoice_id' => 'integer',
        'commission_rule_id' => 'integer',
        'allocation_id' => 'integer',
        'sales_return_id' => 'integer',
        'journal_entry_id' => 'integer',
        'reversed_by_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    // ===================== RELATIONSHIPS =====================

    public function salesman(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function salesInvoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function commissionRule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function allocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InvoicePaymentAllocation::class, 'allocation_id');
    }

    public function salesReturn(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function reversedByEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class, 'reversed_by_entry_id');
    }

    // ===================== STATUS HELPERS =====================

    public function isCalculated(): bool
    {
        return $this->status === 'calculated';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed' || $this->is_reversed;
    }

    public function isPositive(): bool
    {
        return $this->commission_amount > 0;
    }

    public function isReturnReversal(): bool
    {
        return $this->sales_return_id !== null;
    }

    /**
     * Scope: entries for a specific commission period (e.g., '2025-01').
     */
    public function scopeForPeriod(\Illuminate\Database\Eloquent\Builder $query, string $period): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('commission_period', $period);
    }

    /**
     * Scope: entries for a specific salesman.
     */
    public function scopeForSalesman(\Illuminate\Database\Eloquent\Builder $query, int $salesmanId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('salesman_id', $salesmanId);
    }

    /**
     * Scope: calculated entries pending confirmation.
     */
    public function scopePendingConfirmation(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'calculated');
    }
}
