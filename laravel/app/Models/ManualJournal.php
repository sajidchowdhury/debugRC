<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

/**
 * Manual Journal — Phase 6 (Accounts Sub-Ledger).
 *
 * Accountants' custom journal entries with user-defined lines. Lifecycle:
 *   draft → posted → reversed
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
 * @property string $status draft|posted|reversed
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property int|null $reversed_by
 * @property string|null $reversed_at
 * @property string|null $reverse_reason
 */
class ManualJournal extends Model
{
    use SoftDeletes;

    protected $table = 'manual_journals';

    public $timestamps = true;

    /**
     * Valid status values (matches DB CHECK constraint).
     */
    public const STATUSES = ['draft', 'posted', 'reversed'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'journal_code', 'journal_date', 'branch_id', 'description',
        'total_debit', 'total_credit', 'status', 'journal_entry_id',
        'created_by', 'reversed_by', 'reversed_at', 'reverse_reason',
    ];

    protected $casts = [
        'journal_date'  => 'date',
        'total_debit'   => 'decimal:2',
        'total_credit'  => 'decimal:2',
        'reversed_at'   => 'datetime',
        'branch_id'     => 'integer',
        'journal_entry_id' => 'integer',
        'created_by'    => 'integer',
        'reversed_by'   => 'integer',
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

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function getStatusBadge(): string
    {
        return [
            'draft'    => '<span class="badge bg-secondary"><i class="fas fa-pen me-1"></i>Draft</span>',
            'posted'   => '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Posted</span>',
            'reversed' => '<span class="badge bg-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$this->status] ?? '<span class="badge bg-light text-dark">' . e($this->status) . '</span>';
    }
}
