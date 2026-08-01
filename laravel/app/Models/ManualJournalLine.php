<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Manual Journal Line — Phase 1.1 (Core Foundation Hardening).
 *
 * Stores line detail for manual journals (both draft and posted).
 * This enables the draft-to-post workflow: when a draft is saved, lines
 * are persisted here. When postJournal() is called, it reads the lines,
 * validates Dr=Cr, calls postToGL(), and marks the lines as "posted".
 *
 * Lifecycle:
 *   - Created with status='draft' when a manual journal is saved as draft
 *   - Updated to status='posted' when the journal is posted to GL
 *   - journal_line_id is set to the corresponding GL journal_lines row after posting
 *
 * @property int $id
 * @property int $manual_journal_id
 * @property int $ledger_id
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property string $status draft|posted
 * @property int|null $journal_line_id
 */
class ManualJournalLine extends Model
{
    protected $table = 'manual_journal_lines';

    public $timestamps = true;

    public const STATUSES = ['draft', 'posted'];

    protected $fillable = [
        'manual_journal_id',
        'ledger_id',
        'debit',
        'credit',
        'description',
        'status',
        'journal_line_id',
    ];

    protected $casts = [
        'debit'       => 'decimal:2',
        'credit'      => 'decimal:2',
        'manual_journal_id' => 'integer',
        'ledger_id'   => 'integer',
        'journal_line_id' => 'integer',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function manualJournal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ManualJournal::class, 'manual_journal_id');
    }

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    public function journalLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalLine::class, 'journal_line_id');
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
}
