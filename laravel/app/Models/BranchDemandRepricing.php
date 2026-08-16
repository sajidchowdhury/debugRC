<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Branch Demand Repricing — tracks repricing adjustments for branch demands.
 *
 * When branches agree to reprice a demand (e.g., due to a product price change),
 * a repricing adjustment is created that:
 *   - Records the original and new total value
 *   - Calculates the adjustment amount
 *   - Posts a GL adjustment journal
 *   - Updates the branch ledger
 *
 * @property int $id
 * @property int $branch_demand_id
 * @property string $original_total_value
 * @property string $new_total_value
 * @property string $adjustment_amount
 * @property string|null $reason
 * @property int|null $approved_by
 * @property int|null $journal_entry_id        Creditor (supplier) adjustment JE id
 * @property int|null $journal_entry_id_debtor Debtor (requester) adjustment JE id (G-329)
 * @property int|null $created_by
 * @property string $created_at
 */
class BranchDemandRepricing extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'branch_demand_repricing';

    public $timestamps = false;

    protected $fillable = [
        'branch_demand_id',
        'original_total_value',
        'new_total_value',
        'adjustment_amount',
        'reason',
        'approved_by',
        'journal_entry_id',
        'journal_entry_id_debtor',  // G-329: debtor (requester) side
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'original_total_value' => 'decimal:2',
        'new_total_value' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'branch_demand_id' => 'integer',
        'approved_by' => 'integer',
        'journal_entry_id' => 'integer',
        'journal_entry_id_debtor' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function demand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'branch_demand_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * The debtor-side (requester) adjustment journal entry (G-329).
     */
    public function debtorJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id_debtor');
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
