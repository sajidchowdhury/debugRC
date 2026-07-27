<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property string $status draft|counting|posted|cancelled
 * @property int|null $journal_entry_id GL journal (set on post)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $frozen_at      Phase 3: when outbound freeze took effect
 * @property bool $freeze_outbound       Phase 3: true = lock warehouses during count
 * @property array|null $count_snapshot  Phase 3: jsonb product list at setup time
 * @property string|null $notes
 * @property int|null $created_by
 */
class StockTakeSession extends Model
{
    use SoftDeletes, AuditableMasterData;

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

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isCounting(): bool { return $this->status === 'counting'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    /**
     * Phase 3: is this session actively freezing its warehouses?
     * True when freeze_outbound is on AND the session is still in a counting
     * state (draft/counting). Once posted or cancelled the freeze is released.
     */
    public function isActivelyFreezing(): bool
    {
        return (bool) $this->freeze_outbound
            && in_array($this->status, ['draft', 'counting'], true);
    }
}
