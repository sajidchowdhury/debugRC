<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Commission Rule — Task 37.
 *
 * Defines the commission structure for a salesman. Each salesman can have
 * multiple rules over time (time-bounded), but only one ACTIVE rule at a time.
 *
 * Rule types:
 *   - flat: Single percentage rate on invoice total_amount
 *   - tiered: Progressive rates based on cumulative sales volume
 *   - product_group: Different rates per product group
 *   - target_bonus: Base rate + bonus when sales target is met
 *
 * TIME-BOUNDED RULES:
 *   Rules are effective from `effective_from` to `effective_to` (NULL = open-ended).
 *   When a rate changes, the old rule is closed (effective_to set) and a new
 *   rule is inserted. Historical commission entries always reference the rule
 *   that was active at the time of calculation, preserving accuracy.
 *
 * BRANCH SCOPING:
 *   If branch_id is NULL, the rule applies to all branches for this salesman.
 *   If branch_id is set, the rule only applies to invoices from that branch.
 *   A salesman can have different rates for different branches.
 *
 * EXCLUDE CONSTRAINT:
 *   The DB enforces that only one active open-ended rule exists per salesman
 *   at a time, using a GiST-based EXCLUDE constraint on (salesman_id, daterange).
 *
 * @property int $id
 * @property int $salesman_id
 * @property string $rule_type flat|tiered|product_group|target_bonus
 * @property string $rate Base/default commission rate (percentage, e.g., 1.5000 = 1.5%)
 * @property string $effective_from
 * @property string|null $effective_to
 * @property bool $is_active
 * @property int|null $branch_id
 * @property string|null $notes
 * @property int|null $created_by
 */
class CommissionRule extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'commission_rules';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Branch isolation: rules with a specific branch_id are scoped.
     * Global rules (branch_id = NULL) are visible to all.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'salesman_id', 'rule_type', 'rate', 'effective_from', 'effective_to',
        'is_active', 'branch_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'salesman_id' => 'integer',
        'branch_id' => 'integer',
        'created_by' => 'integer',
    ];

    // ===================== RELATIONSHIPS =====================

    public function salesman(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function tiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionRuleTier::class, 'commission_rule_id')
            ->orderBy('threshold');
    }

    public function productGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionRuleProductGroup::class, 'commission_rule_id');
    }

    public function targets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionRuleTarget::class, 'commission_rule_id');
    }

    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionEntry::class, 'commission_rule_id');
    }

    // ===================== HELPERS =====================

    public function isFlat(): bool
    {
        return $this->rule_type === 'flat';
    }

    public function isTiered(): bool
    {
        return $this->rule_type === 'tiered';
    }

    public function isProductGroup(): bool
    {
        return $this->rule_type === 'product_group';
    }

    public function isTargetBonus(): bool
    {
        return $this->rule_type === 'target_bonus';
    }

    public function isCurrentlyActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        $today = now()->toDateString();
        if ($this->effective_from > $today) {
            return false;
        }
        if ($this->effective_to !== null && $this->effective_to < $today) {
            return false;
        }
        return true;
    }

    /**
     * Scope: active rules that are currently in effect.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $today = now()->toDateString();
        return $query->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            });
    }

    /**
     * Scope: rules for a specific salesman.
     */
    public function scopeForSalesman(\Illuminate\Database\Eloquent\Builder $query, int $salesmanId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('salesman_id', $salesmanId);
    }
}
