<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EliminationEntry — a single elimination adjustment within a consolidation run.
 *
 * Each entry represents the elimination of a specific intercompany balance
 * between two branches. The entry records:
 *   - Which consolidation run it belongs to
 *   - Which elimination rule was applied
 *   - The journal entry created for this elimination
 *   - The branch pair being eliminated
 *   - The debit and credit ledgers being eliminated
 *   - The elimination amount
 *
 * Elimination entries are NOT soft-deleted — they are permanent records
 * of the consolidation process. If a consolidation is reversed, the
 * journal_entry is reversed but the elimination_entry record remains.
 */
class EliminationEntry extends Model
{
    protected $table = 'elimination_entries';

    protected $fillable = [
        'consolidation_run_id',
        'elimination_rule_id',
        'journal_entry_id',
        'from_branch_id',
        'to_branch_id',
        'debit_ledger_id',
        'credit_ledger_id',
        'elimination_amount',
        'description',
    ];

    protected $casts = [
        'elimination_amount' => 'decimal:2',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function consolidationRun(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConsolidationRun::class, 'consolidation_run_id');
    }

    public function eliminationRule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EliminationRule::class, 'elimination_rule_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function fromBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function debitLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'debit_ledger_id');
    }

    public function creditLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'credit_ledger_id');
    }
}
