<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\AuditableMasterData;

/**
 * Stock Take Session — Phase 6.4.
 *
 * Workflow:
 *   1. createSession: header + selected warehouses (status=draft)
 *   2. saveCount: per-warehouse physical counts (status → counting)
 *   3. postSession: apply variances via StockService + post GL (status → posted)
 *   4. cancel: if posted, reverse; if draft/counting, just mark cancelled
 *
 * Variance = physical_qty − system_qty (GENERATED column in stock_take_items).
 *   - Positive variance (physical > system): stock IN at current avg_cost → gain
 *   - Negative variance (physical < system): stock OUT at current avg_cost → loss
 *
 * @property int $id
 * @property string $session_code
 * @property string $session_date
 * @property int $branch_id
 * @property string $status draft|counting|submitted|approved|posted|cancelled|reversed
 * @property int|null $journal_entry_id GL journal (set on post)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $frozen_at      Phase 3: when outbound freeze took effect
 * @property bool $freeze_outbound       Phase 3: true = lock warehouses during count
 * @property array|null $count_snapshot  Phase 3: jsonb product list at setup time
 * @property int|null $submitted_by      Phase 4: user who submitted for approval
 * @property string|null $submitted_at   Phase 4: when submitted
 * @property int|null $approved_by       Phase 4: user who approved (must differ from submitted_by)
 * @property string|null $approved_at    Phase 4: when approved
 * @property string|null $approval_comments Phase 4: approval/rejection comments
 * @property string $count_scope            Phase 5: full|category|abc|group|ad_hoc|negative_only|zero_only
 * @property array|null $count_scope_payload Phase 5: scope params jsonb (category_ids/abc_classes/group_ids/product_ids)
 * @property int $re_open_count              Phase 10: # of times this session was re-opened after reversal
 * @property string|null $last_reopened_at   Phase 10: timestamp of the most recent re-open
 * @property int|null $last_reopened_by      Phase 10: user who performed the most recent re-open
 * @property int|null $reversal_of_entry_id  Phase 10: journal_entry_id of the PRIOR post when reversed (audit chain)
 * @property string|null $notes
 * @property int|null $created_by
 */
class StockTakeSession extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'stock_take_sessions';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'session_code',
        'session_date',
        'branch_id',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'frozen_at',
        'freeze_outbound',
        'count_snapshot',
        // Phase 4: approval workflow columns.
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'approval_comments',
        // Phase 5: cycle count scope.
        'count_scope',
        'count_scope_payload',
        // Phase 10: reversal vs cancellation + re-open after reversal.
        're_open_count',
        'last_reopened_at',
        'last_reopened_by',
        'reversal_of_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'session_date' => 'date',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'frozen_at' => 'datetime',
        'freeze_outbound' => 'boolean',
        'count_snapshot' => 'array',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
        // Phase 4: approval workflow casts.
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'submitted_by' => 'integer',
        'approved_by' => 'integer',
        // Phase 5: cycle count scope. count_scope_payload is jsonb → array.
        'count_scope_payload' => 'array',
        // Phase 10: reversal vs cancellation + re-open after reversal.
        're_open_count' => 'integer',
        'last_reopened_at' => 'datetime',
        'last_reopened_by' => 'integer',
        'reversal_of_entry_id' => 'integer',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockTakeWarehouse::class, 'stock_take_session_id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockTakeItem::class, 'stock_take_session_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Phase 5: is this a full-warehouse count (the pre-Phase-5 default),
     * or a narrowed cycle-count scope?
     */
    public function isFullCount(): bool { return ($this->count_scope ?? 'full') === 'full'; }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isCounting(): bool { return $this->status === 'counting'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    // Phase 10: reversal vs cancellation distinction. 'reversed' is a
    // terminal-ish state for POSTED sessions only — full stock + GL reversal
    // happened. Re-openable (reversed → counting) up to max_reopens.
    public function isReversed(): bool { return $this->status === 'reversed'; }
    // Phase 4: approval-workflow states.
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }

    /**
     * Phase 3: is this session actively freezing its warehouses?
     * True when freeze_outbound is on AND the session is still in a counting
     * state. Once posted or cancelled the freeze is released.
     *
     * Phase 4: the active-freeze state set expands to include 'submitted' and
     * 'approved' — a session that has been submitted for approval (or already
     * approved) but NOT yet posted is still mid-count from the warehouse's
     * perspective: no variances have been applied, stock is still "in flux",
     * and outbound movements must remain blocked until the post commits.
     */
    public function isActivelyFreezing(): bool
    {
        return (bool) $this->freeze_outbound
            && in_array(
                $this->status,
                ['draft', 'counting', 'submitted', 'approved'],
                true
            );
    }
}
