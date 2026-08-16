<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Stock Adjustment — Phase 6.3.
 *
 * Phase 3 lifecycle (maker-checker approval workflow):
 *   1. Create (status=draft): header + items, NO stock movement, NO GL
 *   2. Submit (draft→submitted): accountant submits for approval. If below
 *      the auto-approve threshold, the service auto-advances to 'approved'.
 *   3. Approve (submitted→approved): admin/manager approves. Reject returns to draft.
 *   4. Confirm (approved→confirmed): applies stock via StockService + posts GL.
 *      When !requiresApproval, can be confirmed directly from draft.
 *   5. Cancel (any non-terminal→cancelled): if confirmed, reverses stock + GL;
 *      always stores cancel_reason (G15).
 *
 * Adjustment types:
 *   - increase: stock goes UP (Dr Inventory / Cr Surplus)
 *   - decrease: stock goes DOWN (Dr Shrinkage / Cr Inventory)
 *
 * @property int $id
 * @property string $adjustment_code
 * @property string $adjustment_date
 * @property int $warehouse_id
 * @property int $branch_id
 * @property string $adjustment_type increase|decrease
 * @property string $adjustment_category Phase 2: structured reason category
 * @property string $total_amount
 * @property string $reason
 * @property string $status draft|submitted|approved|confirmed|cancelled|rejected
 * @property int|null $journal_entry_id GL journal entry (set on confirm)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason  why the stock+GL was reversed (confirmed-cancel)
 * @property string|null $cancel_reason  why the adjustment was cancelled (G15 — always set on cancel)
 * @property int|null $submitted_by   Phase 3 — who submitted for approval
 * @property string|null $submitted_at
 * @property int|null $approved_by    Phase 3 — who approved
 * @property string|null $approved_at
 * @property string|null $approval_comments  Phase 3 — submit/approve/reject comments trail
 * @property int|null $confirmed_by   Phase 3 (G9) — who posted stock+GL
 * @property string|null $confirmed_at
 * @property string|null $confirm_reason  Phase 3 (G9) — why the posting was done
 * @property int|null $created_by
 */
class StockAdjustment extends Model
{
    // Phase 4 — AuditableMasterData is DEAD for this model: the service
    // writes header/items via DB::table(), bypassing the Eloquent model
    // events the trait hooks into (so its created/updated/saved listeners
    // never fire). It is left in place for safety (removing it could affect
    // other code paths that touch the model directly) but the source of
    // truth for the audit trail is now the dedicated stock_adjustment_audit_log
    // table, written explicitly by StockAdjustmentAuditLogger inside the
    // same DB::transaction as each lifecycle transition. See
    // app/Models/StockAdjustmentAuditLog and the auditLogs() relation below.
    use SoftDeletes, AuditableMasterData, BelongsToFiscalYear;

    protected $table = 'stock_adjustments';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Phase 2 — structured adjustment categories.
     * Mirrors the DB-level CHECK constraint `sa_category_check` (see
     * migration 2025_07_28_000020_add_category_to_stock_adjustments.php).
     * Kept here so the service, controller, and blade views all read from
     * a single source of truth.
     */
    public const ADJUSTMENT_CATEGORIES = [
        'opening_balance',
        'data_migration',
        'uom_correction',
        'post_conversion_fix',
        'legacy_cleanup',
        'reconciliation_variance',
        'other',
    ];

    /**
     * Human-readable labels for each category — used by the create-form
     * dropdown, the index badge, and the show-page detail row.
     */
    public const CATEGORY_LABELS = [
        'opening_balance'       => 'Opening Balance',
        'data_migration'        => 'Data Migration',
        'uom_correction'        => 'UOM / Unit-of-Measure Correction',
        'post_conversion_fix'   => 'Post-Conversion Fix',
        'legacy_cleanup'        => 'Legacy Cleanup',
        'reconciliation_variance' => 'Reconciliation Variance',
        'other'                 => 'Other',
    ];

    /**
     * Bootstrap-icons + badge classes for each category — used by the index
     * and show views to render a consistent coloured badge. Centralised here
     * so a future category addition only needs to touch this map.
     */
    public const CATEGORY_BADGES = [
        'opening_balance'       => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-flag'],
        'data_migration'        => ['cls' => 'bg-primary-subtle text-primary',      'icon' => 'fa-database'],
        'uom_correction'        => ['cls' => 'bg-warning-subtle text-warning',      'icon' => 'fa-ruler-combined'],
        'post_conversion_fix'   => ['cls' => 'bg-secondary-subtle text-secondary',  'icon' => 'fa-screwdriver-wrench'],
        'legacy_cleanup'        => ['cls' => 'bg-secondary-subtle text-secondary',  'icon' => 'fa-broom'],
        'reconciliation_variance' => ['cls' => 'bg-danger-subtle text-danger',      'icon' => 'fa-scale-balanced'],
        'other'                 => ['cls' => 'bg-light text-muted',                 'icon' => 'fa-ellipsis'],
    ];

    /**
     * Phase 3 — canonical status values. Mirrors the DB-level CHECK
     * constraint `stock_adjustments_status_check` (see migration
     * 2025_07_29_000001_add_approval_to_stock_adjustments.php).
     */
    public const STATUSES = [
        'draft', 'submitted', 'approved', 'confirmed', 'cancelled', 'rejected',
    ];

    /**
     * Phase 3 — human-readable status labels.
     */
    public const STATUS_LABELS = [
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'approved'  => 'Approved',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'rejected'  => 'Rejected',
    ];

    /**
     * Phase 3 — Bootstrap badge classes + FontAwesome icons for each status.
     * Centralised so the index table, the show-page header, and the
     * lifecycle stepper all render consistent badges.
     */
    public const STATUS_BADGES = [
        'draft'     => ['cls' => 'bg-warning-subtle text-warning',  'icon' => 'fa-pen-to-square'],
        'submitted' => ['cls' => 'bg-info-subtle text-info',        'icon' => 'fa-paper-plane'],
        'approved'  => ['cls' => 'bg-primary-subtle text-primary',  'icon' => 'fa-circle-check'],
        'confirmed' => ['cls' => 'bg-success-subtle text-success',  'icon' => 'fa-circle-check'],
        'cancelled' => ['cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'fa-ban'],
        'rejected'  => ['cls' => 'bg-danger-subtle text-danger',    'icon' => 'fa-circle-xmark'],
    ];

    protected $fillable = [
        'adjustment_code',
        'adjustment_date',
        'warehouse_id',
        'branch_id',
        'adjustment_type',
        'adjustment_category',
        'total_amount',
        'reason',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'cancel_reason',          // Phase 3 (G15)
        'submitted_by',           // Phase 3
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_comments',
        'confirmed_by',           // Phase 3 (G9)
        'confirmed_at',
        'confirm_reason',
        'created_by',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
        // Phase 3 — approval-workflow timestamps + attribution ids.
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'submitted_by' => 'integer',
        'approved_by' => 'integer',
        'confirmed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
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

    /**
     * Phase 8 — the user who created (drafted) this adjustment. Used by the
     * print voucher's "Prepared by" signature line. Mirrors the existing
     * submittedBy/approvedBy/confirmedBy relations.
     */
    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Phase 3 — the user who submitted this adjustment for approval.
     */
    public function submittedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Phase 3 — the user who approved this adjustment.
     */
    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Phase 3 (G9) — the user who confirmed (posted stock+GL) this adjustment.
     */
    public function confirmedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Phase 4 — the dedicated audit-log rows for this adjustment (the real
     * audit trail; supersedes the dead AuditableMasterData trait). Ordered
     * chronologically by id (monotonic with created_at) for the show-page
     * timeline. Written explicitly by StockAdjustmentAuditLogger inside the
     * same DB::transaction as each lifecycle transition.
     */
    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockAdjustmentAuditLog::class, 'stock_adjustment_id')
            ->orderBy('id');
    }

    /**
     * Scope: adjustments for a specific branch.
     */
    public function scopeForBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Is this adjustment a draft (not yet submitted/approved/confirmed)?
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Phase 3 — has this adjustment been submitted for approval (awaiting
     * an approver's decision)?
     */
    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Phase 3 — has this adjustment been approved (ready to confirm/post)?
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Phase 3 — is this adjustment pending approval (in the submitted state,
     * waiting on an approver)? Convenience alias for the UI.
     */
    public function isPendingApproval(): bool
    {
        return $this->isSubmitted();
    }

    /**
     * Phase 3 — was this adjustment rejected by an approver?
     * (Rejected is a transient flag — rejectAdjustment returns the row to
     * 'draft' so the drafter can revise — so this is mostly historical /
     * used when reading rejection comments from approval_comments.)
     */
    public function isRejected(): bool
    {
        // After rejectAdjustment runs, status returns to 'draft'. This helper
        // is kept for forward-compat if a future phase introduces a persistent
        // 'rejected' terminal state. For now it inspects the approval_comments
        // trail for a rejection marker.
        return $this->status === 'rejected'
            || ($this->isDraft()
                && $this->approval_comments
                && str_contains($this->approval_comments, '[REJECTED]'));
    }

    /**
     * Is this adjustment confirmed (stock moved + GL posted)?
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * Is this adjustment cancelled?
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Phase 3 — is the adjustment in a terminal state (no further lifecycle
     * transitions possible)? Confirmed is NOT terminal (it can be cancelled
     * / reversed); only cancelled is terminal.
     */
    public function isTerminal(): bool
    {
        return $this->isCancelled();
    }

    /**
     * Phase 3 — can this adjustment be confirmed (posted to stock+GL) right
     * now, given the current approval-policy knob?
     *
     * - If approval is required for this adjustment: only 'approved' rows
     *   can be confirmed.
     * - If approval is NOT required (below auto-approve threshold, or the
     *   gate is off and below the force-approve threshold): 'draft' rows
     *   can be confirmed directly (one-step confirm).
     *
     * The $approvalRequired flag is supplied by the caller (the service)
     * via StockAdjustmentPolicyService::requiresApproval() — the model
     * cannot resolve the policy itself (no DI in model methods).
     */
    public function canBeConfirmed(bool $approvalRequired = true): bool
    {
        if ($this->isApproved()) {
            return true;
        }
        if ($this->isDraft() && !$approvalRequired) {
            return true;
        }
        return false;
    }

    /**
     * Is this an increase adjustment (stock goes up)?
     */
    public function isIncrease(): bool
    {
        return $this->adjustment_type === 'increase';
    }

    /**
     * Is this a decrease adjustment (stock goes down)?
     */
    public function isDecrease(): bool
    {
        return $this->adjustment_type === 'decrease';
    }

    /**
     * Phase 2 — is this an opening-balance adjustment?
     *
     * Opening-balance adjustments route to `stock_transactions.reference_type
     * = 'opening_balance'` (not 'stock_adjustment') when confirmed, so the
     * immutable ledger can distinguish initial-onboarding stock from later
     * operational corrections. See StockAdjustmentService::confirmAdjustment.
     */
    public function isOpenBalance(): bool
    {
        return $this->adjustment_category === 'opening_balance';
    }

    /**
     * Phase 2 — human-readable label for the adjustment category.
     * Falls back to a prettified version of the raw value if the category
     * is somehow not in the canonical map (defensive — should never happen
     * because the DB CHECK constraint rejects unknown values).
     */
    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->adjustment_category]
            ?? ucfirst(str_replace('_', ' ', $this->adjustment_category ?? 'other'));
    }

    /**
     * Phase 2 — rendered HTML badge for the adjustment category.
     * Used by the index and show views so the badge style is consistent
     * everywhere and driven by the central CATEGORY_BADGES map.
     */
    public function categoryBadge(): string
    {
        $cat = $this->adjustment_category ?? 'other';
        $meta = self::CATEGORY_BADGES[$cat]
            ?? ['cls' => 'bg-light text-muted', 'icon' => 'fa-ellipsis'];
        $label = e($this->categoryLabel());
        $cls = e($meta['cls']);
        $icon = e($meta['icon']);
        return '<span class="badge ' . $cls . '">'
            . '<i class="fas ' . $icon . ' me-1"></i>' . $label
            . '</span>';
    }

    /**
     * Phase 2 — the reference_type that should be written to
     * stock_transactions when this adjustment is confirmed.
     *
     * Opening-balance adjustments use 'opening_balance' so the ledger can
     * distinguish them; all other categories use the generic
     * 'stock_adjustment' reference_type.
     */
    public function ledgerReferenceType(): string
    {
        return $this->isOpenBalance() ? 'opening_balance' : 'stock_adjustment';
    }

    /**
     * Phase 3 — human-readable label for the status.
     */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status]
            ?? ucfirst(str_replace('_', ' ', $this->status ?? 'draft'));
    }

    /**
     * Phase 3 — rendered HTML badge for the status. Driven by the central
     * STATUS_BADGES map so the index table, the show-page header, and the
     * lifecycle stepper all stay consistent.
     */
    public function statusBadge(): string
    {
        $meta = self::STATUS_BADGES[$this->status]
            ?? ['cls' => 'bg-light text-dark', 'icon' => 'fa-circle-question'];
        $label = e($this->statusLabel());
        $cls = e($meta['cls']);
        $icon = e($meta['icon']);
        return '<span class="badge ' . $cls . '">'
            . '<i class="fas ' . $icon . ' me-1"></i>' . $label
            . '</span>';
    }
}
