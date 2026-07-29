<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Damage Reason — Phase 1 (Damage plan).
 *
 * Global master-data taxonomy of structured reason codes for damage invoices.
 * Each reason is mapped to a `damage_type` (real_damage / missing / theft /
 * quality_reject / customer_return / other) so the create-form dropdown can
 * filter reasons by the selected type.
 *
 * This table is NOT branch-scoped — reasons are company-wide reference data.
 * Writes are restricted to admins via RLS (see migration
 * 2026_01_01_000001_damage_category_and_reason_taxonomy) and via the (future)
 * admin master-data management route.
 *
 * The taxonomy is seeded with ~15 standard reasons at install time. Branches
 * can extend it later (Phase 1+ management UI TBD).
 *
 * @property int $id
 * @property string $reason_code  Unique key (referenced by damage_invoices.reason_code)
 * @property string $label        Human-readable label shown in dropdowns
 * @property string $damage_type  One of DamageInvoice::DAMAGE_TYPES
 * @property bool $is_active
 * @property int $sort_order
 */
class DamageReason extends Model
{
    use SoftDeletes;

    protected $table = 'damage_reasons';

    public $timestamps = true;

    protected $fillable = [
        'reason_code',
        'label',
        'damage_type',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only active reasons (for dropdowns).
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Filter by damage_type.
     */
    public function scopeForType($query, string $damageType)
    {
        return $query->where('damage_type', $damageType);
    }

    /**
     * Ordered by sort_order then label (for stable dropdown rendering).
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Load all active reasons grouped by damage_type, ready for the create
     * form's type-filtered dropdown. Returns an associative array:
     *   ['real_damage' => [ ['code'=>...,'label'=>...], ... ], 'missing' => [...], ...]
     */
    public static function groupedByType(): array
    {
        $grouped = [];
        foreach (DamageInvoice::DAMAGE_TYPES as $type) {
            $grouped[$type] = [];
        }

        $rows = static::active()->ordered()->get(['reason_code', 'label', 'damage_type']);
        foreach ($rows as $r) {
            if (!isset($grouped[$r->damage_type])) {
                $grouped[$r->damage_type] = [];
            }
            $grouped[$r->damage_type][] = [
                'code'  => $r->reason_code,
                'label' => $r->label,
            ];
        }

        return $grouped;
    }
}
