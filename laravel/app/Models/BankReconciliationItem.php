<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BankReconciliationItem — a matched pair linking a bank statement line
 * to a system journal line.
 *
 * Supports:
 *   - One-to-one matching (one statement line = one journal line)
 *   - One-to-many matching (one statement line = multiple journal lines,
 *     e.g., a lump deposit covering several receipts)
 *   - Partial matching (matched_amount can be less than the full amount)
 *
 * Match type:
 *   auto   → System auto-matched by amount, date, reference
 *   manual → User manually matched the items
 */
class BankReconciliationItem extends Model
{
    protected $table = 'bank_reconciliation_items';

    protected $fillable = [
        'bank_reconciliation_id',
        'bank_statement_line_id',
        'journal_line_id',
        'journal_entry_id',
        'match_type',
        'matched_amount',
        'notes',
        'matched_by',
        'matched_at',
    ];

    protected $casts = [
        'matched_amount' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function reconciliation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function statementLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function journalLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalLine::class, 'journal_line_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function matcher(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isAutoMatched(): bool
    {
        return $this->match_type === 'auto';
    }

    public function isManualMatch(): bool
    {
        return $this->match_type === 'manual';
    }
}
