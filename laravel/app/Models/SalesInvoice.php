<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Traits\ApplySystemPolicyScope;
use App\Models\Scopes\BranchScope;

/**
 * Sales Invoice — Phase 8.2 + Task 34 (partitioned).
 *
 * Workflow:
 *   draft → confirmed (godown prep, Phase 8.3) → challan_issued (stock OUT, Phase 8.3)
 *   → paid (payment received, Phase 8.4) → reversed/cancelled
 *
 * On finalize (cart → invoice):
 *   - sales_invoice created (status=draft)
 *   - sales_invoice_items created (from cart items, warehouse_id=NULL — assigned at godown)
 *   - sales_invoice_dispatches created (ordered_qty, dispatched_qty=0, warehouse_id=NULL)
 *   - customer_ledger: debit entry (customer owes us more)
 *   - GL: Dr Accounts Receivable / Cr Sales Revenue (+ Dr Discount / Cr Transport if applicable)
 *   - Cart cleared
 *
 * Credit limit: checked before finalize. If exceeded, requires override reason.
 *
 * PARTITIONING (Task 34):
 *   Table is PARTITION BY RANGE (invoice_date), monthly partitions.
 *   PRIMARY KEY is (id, invoice_date) — both columns needed for partition routing.
 *   UNIQUE constraint is (invoice_code, invoice_date) — must include partition key.
 *   Child table FKs use trigger-based enforcement (fn_fk_si_check) instead of
 *   declarative FK because PG 12-17 does not support FK references TO partitioned tables.
 *
 * Performance note: For partition pruning, include invoice_date in WHERE clauses
 * when querying by date range. Simple find($id) still works (PG routes automatically).
 *
 * @property int $id
 * @property string $invoice_code
 * @property string $invoice_date
 * @property int $customer_id
 * @property int|null $salesman_id
 * @property string|null $sales_person
 * @property int $branch_id
 * @property string $sub_total
 * @property string $discount_amount
 * @property string $transport_cost
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $due_amount
 * @property string $payment_mode
 * @property string $status draft|confirmed|cancelled|reversed
 * @property bool $is_godown_prepared
 * @property string|null $godown_prepared_at
 * @property bool $is_blank_godown_printed
 * @property string|null $blank_godown_printed_at
 * @property int|null $blank_godown_printed_by
 * @property bool $is_challan_issued
 * @property string|null $challan_issued_at
 * @property int|null $journal_entry_id
 * @property int|null $cogs_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property bool $is_soft_hold
 * @property bool $call_a_day
 * @property string|null $notes
 * @property int|null $created_by
 */
class SalesInvoice extends Model
{
    use SoftDeletes, AuditableMasterData, ApplySystemPolicyScope;

    protected $table = 'sales_invoices';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * P0-8: Branch isolation global scope.
     * Non-admin users only see invoices from their session branch_id.
     * Admin/superadmin bypass (see all branches).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * G-171 (AUDIT-TRAIL-2): the date column clamped by INVESTIGATION mode.
     * sales_invoices is PARTITION BY RANGE (invoice_date), so clamping on
     * invoice_date also enables partition pruning (a pleasant side effect).
     */
    protected function policyDateColumn(): string
    {
        return 'invoice_date';
    }

    protected $fillable = [
        'invoice_code', 'invoice_date', 'customer_id', 'salesman_id', 'sales_person',
        'branch_id', 'sub_total', 'discount_amount', 'transport_cost', 'pre_challan_transport', 'total_amount', 'pre_challan_total',
        'paid_amount', 'payment_mode', 'status',
        'is_godown_prepared', 'godown_prepared_at',
        'is_blank_godown_printed', 'blank_godown_printed_at', 'blank_godown_printed_by',
        'is_challan_issued', 'challan_issued_at',
        'journal_entry_id', 'cogs_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'is_soft_hold', 'call_a_day', 'notes', 'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2', // GENERATED: total_amount - paid_amount
        'is_godown_prepared' => 'boolean',
        'is_blank_godown_printed' => 'boolean',
        'is_challan_issued' => 'boolean',
        'is_reversed' => 'boolean',
        'is_soft_hold' => 'boolean',
        'call_a_day' => 'boolean',
        'godown_prepared_at' => 'datetime',
        'blank_godown_printed_at' => 'datetime',
        'challan_issued_at' => 'datetime',
        'reversed_at' => 'datetime',
        'customer_id' => 'integer',
        'salesman_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'cogs_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
        'blank_godown_printed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id');
    }

    public function dispatches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoiceDispatch::class, 'sales_invoice_id');
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function salesman(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    /**
     * F-6: The user who created this invoice (sales_invoices.created_by → users.id).
     * Used by the smart-search whereHas('creator') to match invoices by
     * creator username. Mirrors the legacy "creator" lookup.
     */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * R19: payment allocations posted against this invoice
     * (via invoice_payment_allocations → customer_payments).
     * Used by the inline receive-payment modal on the sales-invoices
     * index page to render the "Payments on this invoice" history.
     */
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoicePaymentAllocation::class, 'invoice_id');
    }

    /**
     * Human dispatchers assigned to this invoice (many-to-many via sales_invoice_dispatchers).
     * NOT to be confused with dispatches() which tracks the product pipeline.
     */
    public function dispatchers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'sales_invoice_dispatchers', 'sales_invoice_id', 'employee_id')
            ->withPivot('dispatch_role');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * The challan issued against this invoice (Phase 8 — Reverse button on
     * the issue screen needs the challan id for the cancel route).
     * HasOne: an invoice has at most one non-reversed challan; in the rare
     * case of multiple (reverse + re-issue), the latest is returned via
     * latestOfMany(). The FK is sales_invoice_id on sales_challans.
     */
    public function challan(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\SalesChallan::class, 'sales_invoice_id')
            ->latestOfMany();
    }

    /**
     * Step 1 of the 3-step godown workflow: has the blank godown copy
     * (handwriting picking sheet) been printed with at least one
     * dispatcher attached? Set by SalesChallanController::storeBlankGodown()
     * and gated by SalesChallanController::godown() +
     * SalesChallanService::prepareGodown().
     */
    public function isBlankGodownPrinted(): bool { return (bool) $this->is_blank_godown_printed; }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isReversed(): bool { return $this->status === 'reversed' || $this->is_reversed; }
    public function isCalledItADay(): bool { return (bool) $this->call_a_day; }
}
