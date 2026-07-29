<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Damage Invoice — Phase 6.6.
 *
 * Records damaged/write-off stock. Two-phase flow:
 *   1. Create (draft): header + items, NO stock movement, NO GL
 *   2. Confirm: stock OUT via StockService + GL (Dr Damage Loss / Cr Inventory)
 *   3. Cancel: if confirmed, reverses stock + GL; if draft, marks cancelled
 *
 * GL posting (re-derived from double-entry):
 *   Dr Damage Loss (shrinkage) / Cr Inventory
 *   The loss is valued at the current avg_cost at time of damage.
 *
 * @property int $id
 * @property string $damage_code
 * @property string $damage_date
 * @property int $warehouse_id
 * @property int $branch_id
 * @property string $total_value
 * @property string $reason          Legacy free-text reason (kept for back-compat).
 * @property string $damage_type    One of DAMAGE_TYPES (Phase 1).
 * @property string|null $reason_code   Structured reason → damage_reasons.reason_code (Phase 1).
 * @property string|null $reason_detail  Optional extra context for the chosen reason_code (Phase 1).
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class DamageInvoice extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'damage_invoices';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * The six valid damage types (Phase 1 — Damage Category & Reason Taxonomy).
     *
     * Kept in sync with the DB CHECK constraint `damage_invoices_type_check`
     * (migration 2026_01_01_000001). The `damage_type` drives:
     *   - the create-form reason dropdown filter,
     *   - the GL loss-ledger selection in DamageService::postDamageGL,
     *   - the P&L split (damage_loss vs inventory_shrinkage),
     *   - future Phase 4 validation (missing → accountable employee required).
     */
    public const DAMAGE_TYPES = [
        'real_damage',     // physical breakage / spoilage / expiry / fire / water / transit
        'missing',         // not found in warehouse, no physical damage (core complaint)
        'theft',           // suspected / confirmed theft
        'quality_reject',  // failed QC
        'customer_return', // auto-created from a sales return
        'other',
    ];

    /**
     * Human-readable labels for each damage_type (UI badges / dropdowns).
     */
    public const DAMAGE_TYPE_LABELS = [
        'real_damage'     => 'Real damage',
        'missing'         => 'Missing / unaccounted',
        'theft'           => 'Theft',
        'quality_reject'  => 'Quality reject',
        'customer_return' => 'Customer return',
        'other'           => 'Other',
    ];

    /**
     * Bootstrap-coloured badge classes per damage_type for the UI.
     * (danger = physical loss, warning = accountability flag, dark = crime, etc.)
     */
    public const DAMAGE_TYPE_BADGE_CLASSES = [
        'real_damage'     => 'bg-danger-subtle text-danger',
        'missing'         => 'bg-warning-subtle text-warning',
        'theft'           => 'bg-dark-subtle text-dark',
        'quality_reject'  => 'bg-info-subtle text-info',
        'customer_return' => 'bg-secondary-subtle text-secondary',
        'other'           => 'bg-light text-muted',
    ];

    /**
     * FontAwesome icons per damage_type (UI badges).
     */
    public const DAMAGE_TYPE_ICONS = [
        'real_damage'     => 'fa-triangle-exclamation',
        'missing'         => 'fa-magnifying-glass',
        'theft'           => 'fa-user-secret',
        'quality_reject'  => 'fa-clipboard-check',
        'customer_return' => 'fa-rotate-left',
        'other'           => 'fa-circle-question',
    ];

    protected $fillable = [
        'damage_code',
        'damage_date',
        'warehouse_id',
        'branch_id',
        'sales_return_id',
        'total_value',
        'reason',
        'damage_type',
        'reason_code',
        'reason_detail',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'created_by',
    ];

    protected $casts = [
        'damage_date' => 'date',
        'total_value' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'warehouse_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DamageInvoiceItem::class, 'damage_invoice_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * The structured reason taxonomy row (Phase 1) — matched by reason_code.
     *
     * Named `reasonTaxonomy` (NOT `reason`) because the `reason` column
     * (legacy free-text) would shadow a `reason()` relation in Eloquent's
     * magic __get (attributes take precedence over relations). Eager-load
     * with ->with('reasonTaxonomy').
     *
     * Nullable: old rows / free-text-only damages have no reason_code.
     */
    public function reasonTaxonomy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DamageReason::class, 'reason_code', 'reason_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 1 — damage_type helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
