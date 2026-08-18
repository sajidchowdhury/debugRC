<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * Manual Journal — Phase 6 (Accounts Sub-Ledger).
 *
 * Accountants' custom journal entries with user-defined lines. Lifecycle:
 *   draft → submitted → approved → posted → reversed
 *               └── rejected (must resubmit)
 *
 * Unlike the other money modules (supplier/employee/etc.), manual journals:
 *   - Have NO entity_type/entity_id on the lines (accountant picks ledgers)
 *   - Must have Dr = Cr (enforced by DB trigger + service validation)
 *   - Support a draft state (saved without posting GL)
 *   - Use period validation on posting (cannot post to closed periods)
 *
 * The actual GL journal entry is stored in journal_entries (linked via
 * journal_entry_id). The manual_journals row is the "header" — the lines
 * live on manual_journal_lines (for draft + posted persistence) and are
 * mirrored to journal_lines when posted to GL (linked to the journal_entry_id).
 *
 * @property int $id
 * @property string $journal_code
 * @property string $journal_date
 * @property int $branch_id
 * @property string|null $description
 * @property string $total_debit
 * @property string $total_credit
 * @property string $status draft|submitted|approved|posted|reversed|rejected
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property int|null $reversed_by
 * @property string|null $reversed_at
 * @property string|null $reverse_reason
 */
class ManualJournal extends Model
{
    use SoftDeletes, BelongsToFiscalYear;

    protected $table = 'manual_journals';

    public $timestamps = true;

    /**
     * Valid status values (matches DB CHECK constraint).
     */
    public const STATUSES = ['draft', 'submitted', 'approved', 'posted', 'reversed', 'rejected'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'fiscal_year_id',
        'journal_code', 'journal_date', 'branch_id', 'description',
        'total_debit', 'total_credit', 'status', 'journal_entry_id',
        'created_by', 'reversed_by', 'reversed_at', 'reverse_reason',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'approval_comments', 'rejected_by', 'rejected_at',
    ];

    protected $casts = [
        'journal_date'  => 'date',
        'total_debit'   => 'decimal:2',
        'total_credit'  => 'decimal:2',
        'reversed_at'   => 'datetime',
        'submitted_at'  => 'datetime',
        'approved_at'   => 'datetime',
        'rejected_at'   => 'datetime',
        'branch_id'     => 'integer',
        'journal_entry_id' => 'integer',
        'created_by'    => 'integer',
        'reversed_by'   => 'integer',
        'submitted_by'  => 'integer',
        'approved_by'   => 'integer',
        'rejected_by'   => 'integer',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Manual journal lines (draft + posted persistence).
     * Phase 1.1: Lines are stored in manual_journal_lines for both draft and posted journals.
     */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManualJournalLine::class, 'manual_journal_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeDraft(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopePosted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'reversed');
    }

    public function scopeByBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('branch_id', $branchId);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getStatusBadge(): string
    {
        return [
            'draft'     => '<span class="badge bg-secondary"><i class="fas fa-pen me-1"></i>Draft</span>',
            'submitted' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending Approval</span>',
            'approved'  => '<span class="badge bg-info"><i class="fas fa-thumbs-up me-1"></i>Approved</span>',
            'posted'    => '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Posted</span>',
            'reversed'  => '<span class="badge bg-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
            'rejected'  => '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Rejected</span>',
        ][$this->status] ?? '<span class="badge bg-light text-dark">' . e($this->status) . '</span>';
    }

    /**
     * Get the latest approval request for this journal (if any).
     */
    public function approvalRequest()
    {
        return \App\Models\ApprovalRequest::where('entity_type', 'manual_journal')
            ->where('entity_id', $this->id)
            ->latest()
            ->first();
    }

    /**
     * Check if this journal can be submitted for approval.
     */
    public function canBeSubmitted(): bool
    {
        return $this->isDraft() || $this->isRejected();
    }

    /**
     * Check if this journal can be posted.
     * If an approval workflow applies, must be 'approved' first.
     * If no workflow applies, 'draft' can still be posted directly.
     */
    public function canBePosted(): bool
    {
        return $this->isApproved() || $this->isDraft();
    }
}
