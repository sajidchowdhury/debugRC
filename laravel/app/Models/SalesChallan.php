<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Sales Challan — Phase 8.3.
 *
 * Created when a sales invoice's godown is finalized (stock OUT + COGS).
 * The challan is the delivery note — goods leave the warehouse.
 *
 * Two-step flow (from Phase 8.2 draft invoice):
 *   1. prepareGodown: assign warehouse_id to invoice items + dispatches
 *      (status → confirmed, is_godown_prepared=true)
 *   2. issueChallan: stock OUT via StockService at avg_cost + GL Dr COGS / Cr Inventory
 *      (status stays confirmed, is_challan_issued=true, challan created)
 *
 * On issueChallan, for each dispatch line:
 *   - Stock OUT via StockService (reference_type='sales_challan', rate=avg_cost)
 *   - avg_cost UNCHANGED on OUT (standard moving-average rule)
 *   - GL: Dr COGS / Cr Inventory (cumulative for all lines in one journal)
 *
 * @property int $id
 * @property string $challan_code
 * @property string $challan_date
 * @property int $sales_invoice_id
 * @property int $branch_id
 * @property string|null $transport_name
 * @property string|null $vehicle_number
 * @property string|null $driver_name
 * @property string $transport_cost
 * @property int|null $journal_entry_id (COGS journal)
 * @property int|null $adjustment_journal_entry_id (transport adjustment)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class SalesChallan extends Model
{
    use SoftDeletes, AuditableMasterData, BelongsToFiscalYear;

    protected $table = 'sales_challans';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * P0-8: Branch isolation global scope.
     * Non-admin users only see challans from their session branch_id.
     * Admin/superadmin bypass (see all branches).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'challan_code', 'challan_date', 'sales_invoice_id', 'branch_id',
        'transport_name', 'transport_phone', 'vehicle_number', 'driver_name',
        'transport_cost', 'transport_adjustment',
        'journal_entry_id', 'adjustment_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'issue_cost', 'is_dispatch_soft_hold',
        'created_by',
    ];

    protected $casts = [
        'challan_date' => 'date',
        'transport_cost' => 'decimal:2',
        'transport_adjustment' => 'decimal:2',
        'issue_cost' => 'decimal:2',
        'is_reversed' => 'boolean',
        'is_dispatch_soft_hold' => 'boolean',
        'reversed_at' => 'datetime',
        'sales_invoice_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'adjustment_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function salesInvoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function adjustmentJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'adjustment_journal_entry_id');
    }

    /**
     * Per-line issue-cost items (P0-5).
     * Each row snapshots the avg_cost used when stock was issued OUT for this challan.
     */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesChallanItem::class, 'sales_challan_id');
    }

    public function isReversed(): bool { return $this->is_reversed; }
}
