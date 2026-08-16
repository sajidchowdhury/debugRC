<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;
use App\Traits\AuditableMasterData;

/**
 * Purchase Order — Phase 7.1.
 *
 * A PO is a draft document — NO stock movement, NO GL journal.
 * The economic event is the GRN (Phase 7.2) which receives stock + posts GL.
 *
 * Status flow (post PURCHASING-API-2 / G-116):
 *   draft → submitted → approved → sent → partial → received → cancelled
 *               └── rejected (must edit + resubmit)
 *   - draft:     created but not sent for approval nor to supplier
 *   - submitted: pending approval (approval_requests row exists)
 *   - approved:  approved (auto or via workflow) — can be marked sent
 *   - rejected:  approver declined — must edit + resubmit
 *   - sent:      sent to supplier, awaiting delivery
 *   - partial:   some items received via GRN
 *   - received:  all items fully received
 *   - cancelled: cancelled (only pre-receive states can be cancelled)
 *
 * Auto-approve: if no workflow applies (total_amount < min_amount) the PO
 * stays in `draft` and can be marked sent directly — backward-compatible
 * with the pre-approval flow.
 *
 * The `received_qty` on items tracks how much has been received via GRN.
 * When received_qty >= qty for all items → status auto-updates to 'received'.
 * When some but not all → 'partial'.
 *
 * @property int $id
 * @property string $po_code
 * @property string $po_date
 * @property int $supplier_id
 * @property int $branch_id
 * @property int $warehouse_id PURCHASING-API-2 (G-123/G-124): now NOT NULL
 * @property string $sub_total
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string $status draft|submitted|approved|rejected|sent|partial|received|cancelled
 * @property string|null $expected_date
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $submitted_by  PURCHASING-API-2 (G-116)
 * @property string|null $submitted_at
 * @property int|null $approved_by
 * @property string|null $approved_at
 * @property string|null $approval_comments
 * @property int|null $rejected_by
 * @property string|null $rejected_at
 */
class PurchaseOrder extends Model
{
    use SoftDeletes, AuditableMasterData, BelongsToFiscalYear;

    protected $table = 'purchase_orders';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Phase 8 (BUG-40 fix): Apply BranchScope global scope so non-admin
     * users can only read POs from their own session branch. Closes the
     * cross-branch read leak in show()/edit() — findOrFail now throws
     * ModelNotFoundException (404) instead of returning another branch's
     * record. Admins bypass the scope (see BranchScope).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'po_code',
        'po_date',
        'supplier_id',
        'branch_id',
        'warehouse_id',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'expected_date',
        'notes',
        'created_by',
        // PURCHASING-API-2 (G-116): approval audit columns (added by
        // migration 2026_09_05_000003). Mirrors the ManualJournal layout.
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_comments',
        'rejected_by',
        'rejected_at',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_by' => 'integer',
        // PURCHASING-API-2 (G-116): approval audit column casts.
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'submitted_by' => 'integer',
        'approved_by' => 'integer',
        'rejected_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    /**
     * Phase 3 — GRNs created against this PO.
     * Used on the PO show page "Receives against this PO" list.
     */
    public function receives(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\PurchaseReceive::class, 'purchase_order_id')
            ->orderBy('receive_date', 'desc')
            ->orderBy('id', 'desc');
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

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
    public function isSent(): bool { return $this->status === 'sent'; }
    public function isPartial(): bool { return $this->status === 'partial'; }
    public function isReceived(): bool { return $this->status === 'received'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    /**
     * Can this PO be edited?
     * PURCHASING-API-2 (G-116): expanded to allow editing a rejected PO so
     * the user can fix the issues and resubmit. Mirrors ManualJournal's
     * canBeSubmitted() = isDraft() || isRejected() pattern.
     */
    public function canEdit(): bool { return $this->isDraft() || $this->isRejected(); }

    /**
     * Can this PO be cancelled? (any pre-receive state)
     * PURCHASING-API-2 (G-116): expanded to include submitted + approved —
     * a pending-approval or approved-but-not-yet-sent PO can be cancelled.
     * received/partial cannot (goods already in stock — must use a PRN).
     */
    public function canCancel(): bool
    {
        return $this->isDraft() || $this->isSubmitted() || $this->isApproved() || $this->isSent();
    }

    /**
     * Can this PO receive goods (GRN)? (sent or partial, not cancelled/received)
     */
    public function canReceive(): bool { return $this->isSent() || $this->isPartial(); }

    /**
     * Can this PO be submitted for approval?
     * PURCHASING-API-2 (G-116): mirrors ManualJournal::canBeSubmitted().
     * A draft PO can be submitted for the first time; a rejected PO can be
     * resubmitted (after edits). Other states cannot.
     */
    public function canBeSubmitted(): bool { return $this->isDraft() || $this->isRejected(); }

    /**
     * Can this PO be marked as sent to the supplier?
     * PURCHASING-API-2 (G-116): if an approval workflow applies, the PO
     * must be `approved` first. If no workflow applies (auto-approved),
     * a `draft` PO can be marked sent directly — backward-compatible with
     * the pre-approval flow. Mirrors ManualJournal::canBePosted().
     */
    public function canBeSent(): bool { return $this->isApproved() || $this->isDraft(); }

    /**
     * Get the latest approval request for this PO (if any).
     * PURCHASING-API-2 (G-116): mirrors ManualJournal::approvalRequest().
     */
    public function approvalRequest()
    {
        return \App\Models\ApprovalRequest::where('entity_type', 'purchase_order')
            ->where('entity_id', $this->id)
            ->latest()
            ->first();
    }
}
