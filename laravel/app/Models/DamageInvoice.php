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
 * @property string $reason          Legacy free-text reason (kept for back-compat).
 * @property string $damage_type    One of DAMAGE_TYPES (Phase 1).
 * @property string|null $reason_code   Structured reason → damage_reasons.reason_code (Phase 1).
 * @property string|null $reason_detail  Optional extra context for the chosen reason_code (Phase 1).
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 * @property int|null $witness_employee_id      Phase 4 — corroborating employee (required for theft).
 * @property int|null $accountable_employee_id  Phase 4 — responsible employee (required for missing).
 * @property string $recovery_amount            Phase 4 — BDT recovered from the accountable employee.
 * @property int|null $employee_ledger_entry_id  Phase 4 — link to the recovery employee_ledger row.
 * @property int|null $recovery_journal_entry_id Phase 4 — link to the recovery GL journal entry.
 * @property int|null $submitted_by              Phase 5 — user who pushed the draft into the approval queue.
 * @property string|null $submitted_at           Phase 5 — when the draft was submitted.
 * @property int|null $approved_by               Phase 5 — user who approved (or auto-approved at submit).
 * @property string|null $approved_at            Phase 5 — when approved.
 * @property int|null $approval_rejected_by      Phase 5 — user who rejected the submission.
 * @property string|null $approval_rejected_at   Phase 5 — when rejected.
 * @property string|null $approval_notes         Phase 5 — approver/rejecter note (rejection reason).
 */
class DamageInvoice extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'damage_invoices';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * The six valid damage types (Phase 1 — Damage Category & Reason Taxonomy).
     *
     * Kept in sync with the DB CHECK constraint `damage_invoices_type_check`
     * (migration 2026_01_01_000001). The `damage_type` drives:
     *   - the create-form reason dropdown filter,
     *   - the GL loss-ledger selection in DamageService::postDamageGL,
     *   - the P&L split (damage_loss vs inventory_shrinkage),
     *   - future Phase 4 validation (missing → accountable employee required).
     */
    public const DAMAGE_TYPES = [
        'real_damage',     // physical breakage / spoilage / expiry / fire / water / transit
        'missing',         // not found in warehouse, no physical damage (core complaint)
        'theft',           // suspected / confirmed theft
        'quality_reject',  // failed QC
        'customer_return', // auto-created from a sales return
        'other',
    ];

    /**
     * Human-readable labels for each damage_type (UI badges / dropdowns).
     */
    public const DAMAGE_TYPE_LABELS = [
        'real_damage'     => 'Real damage',
        'missing'         => 'Missing / unaccounted',
        'theft'           => 'Theft',
        'quality_reject'  => 'Quality reject',
        'customer_return' => 'Customer return',
        'other'           => 'Other',
    ];

    /**
     * Bootstrap-coloured badge classes per damage_type for the UI.
     * (danger = physical loss, warning = accountability flag, dark = crime, etc.)
     */
    public const DAMAGE_TYPE_BADGE_CLASSES = [
        'real_damage'     => 'bg-danger-subtle text-danger',
        'missing'         => 'bg-warning-subtle text-warning',
        'theft'           => 'bg-dark-subtle text-dark',
        'quality_reject'  => 'bg-info-subtle text-info',
        'customer_return' => 'bg-secondary-subtle text-secondary',
        'other'           => 'bg-light text-muted',
    ];

    /**
     * FontAwesome icons per damage_type (UI badges).
     */
    public const DAMAGE_TYPE_ICONS = [
        'real_damage'     => 'fa-triangle-exclamation',
        'missing'         => 'fa-magnifying-glass',
        'theft'           => 'fa-user-secret',
        'quality_reject'  => 'fa-clipboard-check',
        'customer_return' => 'fa-rotate-left',
        'other'           => 'fa-circle-question',
    ];

    protected $fillable = [
        'damage_code',
        'damage_date',
        'warehouse_id',
        'branch_id',
        'sales_return_id',
        'total_value',
        'reason',
        'damage_type',
        'reason_code',
        'reason_detail',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'created_by',
        // Phase 4 — witness / accountable / recovery.
        'witness_employee_id',
        'accountable_employee_id',
        'recovery_amount',
        'employee_ledger_entry_id',
        'recovery_journal_entry_id',
        // Phase 5 — approval workflow (maker-checker).
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_rejected_by',
        'approval_rejected_at',
        'approval_notes',
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
        // Phase 4 — accountability / recovery columns.
        'witness_employee_id' => 'integer',
        'accountable_employee_id' => 'integer',
        'recovery_amount' => 'decimal:2',
        'employee_ledger_entry_id' => 'integer',
        'recovery_journal_entry_id' => 'integer',
        // Phase 5 — approval workflow timestamps.
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'approval_rejected_at' => 'datetime',
        'submitted_by' => 'integer',
        'approved_by' => 'integer',
        'approval_rejected_by' => 'integer',
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

    /**
     * Evidence attachments (Phase 3 — Photo / Evidence Attachments).
     *
     * Photos / PDFs uploaded as proof of the damage. Eager-load with
     * ->with('attachments.uploadedBy') on the detail page. Countable inline
     * via withCount('attachments') for the index / integrity checks.
     */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DamageAttachment::class, 'damage_invoice_id')
            ->orderBy('id');
    }

    /**
     * The structured reason taxonomy row (Phase 1) — matched by reason_code.
     *
     * Named `reasonTaxonomy` (NOT `reason`) because the `reason` column
     * (legacy free-text) would shadow a `reason()` relation in Eloquent's
     * magic __get (attributes take precedence over relations). Eager-load
     * with ->with('reasonTaxonomy').
     *
     * Nullable: old rows / free-text-only damages have no reason_code.
     */
    public function reasonTaxonomy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DamageReason::class, 'reason_code', 'reason_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — Witness & Accountable Employee
    |--------------------------------------------------------------------------
    | The named responsible parties for a damage. `missing`-type damages MUST
    | have an accountable employee; `theft`-type damages MUST have a witness.
    | Enforced in DamageService::createDamage. Both relations eager-load with
    | ->with('witnessEmployee.branch', 'accountableEmployee.branch').
    */

    /**
     * The employee who corroborates a theft / sensitive write-off (Phase 4).
     * Required for damage_type='theft'. Optional otherwise.
     */
    public function witnessEmployee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'witness_employee_id');
    }

    /**
     * The employee responsible for the loss (Phase 4). Required for
     * damage_type='missing'. Optional (recommended) otherwise. The
     * accountable employee is the target of the recovery flow
     * (postEmployeeRecovery debits their employee_ledger).
     */
    public function accountableEmployee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accountable_employee_id');
    }

    /**
     * The employee_ledger row created by the recovery (Phase 4). Nullable —
     * set once by DamageService::postEmployeeRecovery. Reversed on cancel
     * so the employee doesn't owe us for a write-off that was reversed.
     */
    public function employeeLedgerEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EmployeeLedger::class, 'employee_ledger_entry_id');
    }

    /**
     * The GL journal entry posted by the recovery (Phase 4). Nullable — set
     * once by postEmployeeRecovery. Reversed on cancel alongside the main
     * damage JE. Stored explicitly (in addition to employee_ledger_entry_id,
     * which also carries journal_entry_id) so cancelDamage can reverse it
     * directly without an extra lookup.
     */
    public function recoveryJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'recovery_journal_entry_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 5 — Approval Workflow (Maker-Checker + Threshold Escalation)
    |--------------------------------------------------------------------------
    | The three named users in the approval timeline. submitted_by is set by
    | submitForApproval; approved_by by approve (or by submitForApproval's
    | auto-approve shortcut); approval_rejected_by by reject. All three
    | resolve to a User via the integer ID (no FK — mirrors reversed_by /
    | created_by on this table). Eager-load with ->with('submitter',
    | 'approver', 'rejecter') on the detail page.
    */

    /**
     * The user who pushed this draft into the approval queue (Phase 5).
     * Set once by submitForApproval; never overwritten (a rejected damage
     * is terminal — create a new damage to re-submit).
     */
    public function submitter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The user who approved the submission (Phase 5). Set by approve() OR
     * by submitForApproval's auto-approve shortcut (when the submitter is
     * admin/manager AND total ≤ config('damage.approval.threshold')).
     * Enforced: approver ≠ submitter (segregation of duties) — the service
     * throws if approved_by === submitted_by for non-auto-approved rows.
     */
    public function approver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The user who rejected the submission (Phase 5). A rejected damage is
     * terminal — it cannot be re-submitted or confirmed; create a new
     * damage instead. rejection_reason lives in approval_notes.
     */
    public function rejecter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_rejected_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 1 — damage_type helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }

    /**
     * Whether the damage is in a pre-confirm state (no stock movement, no GL).
     *
     * Phase 5 introduced `submitted` and `approved` between draft and
     * confirmed. Both behave like draft from the stock/GL perspective:
     * nothing has been posted yet. Used by DamageIntegrityService to treat
     * all three states uniformly as "not yet posted".
     */
    public function isPreConfirm(): bool
    {
        return in_array($this->status, ['draft', 'submitted', 'approved'], true);
    }

    /**
     * Whether the damage is in a terminal state (no further transitions).
     *
     * `cancelled` and `rejected` are both terminal — neither can be
     * re-opened. A cancelled damage keeps its audit trail (stock/GL
     * reversals if it was confirmed); a rejected damage never posted
     * anything (it was blocked at the approval gate).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['cancelled', 'rejected'], true);
    }

    /**
     * Whether the damage is currently awaiting a manager's approval decision.
     *
     * True only when status='submitted' — the worklist state. Powers the
     * "Awaiting my approval" stat card on the index page + the partial
     * index idx_dmg_submitted.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Whether the damage was auto-approved at submit time (Phase 5).
     *
     * True when submitted_by === approved_by AND approved_at is within a
     * few seconds of submitted_at (the auto-approve shortcut stamps both
     * in the same transaction). Used by the timeline UI to render an
     * "Auto-approved (below threshold)" badge instead of a manual approval.
     */
    public function wasAutoApproved(): bool
    {
        if (!$this->submitted_by || !$this->approved_by) {
            return false;
        }
        if ((int) $this->submitted_by !== (int) $this->approved_by) {
            return false;
        }
        if (!$this->submitted_at || !$this->approved_at) {
            return false;
        }
        // Same user + both timestamps set → auto-approve shortcut. (A manual
        // approval by the same user is blocked by the segregation-of-duties
        // rule in DamageService::approve, so this condition is unambiguous.)
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — recovery helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a recovery has been posted against this damage
     * (employee_ledger debit + GL credit). A recovered damage carries a
     * non-zero recovery_amount + a linked employee_ledger row.
     */
    public function hasRecovery(): bool
    {
        return (float) $this->recovery_amount > 0
            && !empty($this->employee_ledger_entry_id);
    }

    /**
     * Whether this damage is eligible for employee recovery — confirmed,
     * has an accountable employee, and no recovery has been posted yet.
     * (Recovery is a one-shot: once posted it can only be reversed by
     * cancelling the damage, not re-run.)
     */
    public function isRecoverable(): bool
    {
        return $this->isConfirmed()
            && !empty($this->accountable_employee_id)
            && !$this->hasRecovery();
    }
}
