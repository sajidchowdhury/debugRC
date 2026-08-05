<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * G-279 (G18) FINANCE-CONSOLIDATION-1: EliminationEntry now uses SoftDeletes
 * in lockstep with its parent ConsolidationRun (which has SoftDeletes) and
 * sibling EliminationRule (which also has SoftDeletes). Previously, soft-
 * deleting a ConsolidationRun left orphaned elimination_entries because the
 * FK cascade (fk_ee_consolidation_run REFERENCES consolidation_runs(id) ON
 * DELETE CASCADE) only fires on HARD delete — Laravel's SoftDeletes issues
 * UPDATE ... SET deleted_at = NOW(), not DELETE. The companion migration
 * 2026_09_06_000007_add_soft_deletes_to_elimination_entries adds the
 * deleted_at column; ConsolidationRun's deleting event cascades the soft-
 * delete to its entries (defense-in-depth, registered in ConsolidationRun
 * boot()). The historical rationale ("permanent records") is preserved by
 * the soft-delete pattern: rows remain in the table with deleted_at set,
 * so the GL → elimination_entry → consolidation_run chain stays auditable.
 */
class EliminationEntry extends Model
{
    use SoftDeletes;

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
