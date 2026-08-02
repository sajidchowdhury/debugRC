<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BudgetLine — per-ledger, per-period budget amount.
 *
 * Each row represents one cell in the budget spreadsheet grid:
 *   - ledger_id = which account (row)
 *   - period    = which month/quarter (column)
 *   - amount    = the budgeted figure
 */
class BudgetLine extends Model
{
    protected $table = 'budget_lines';

    protected $fillable = [
        'budget_id',
        'ledger_id',
        'period',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period' => 'integer',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function budget(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }
}
