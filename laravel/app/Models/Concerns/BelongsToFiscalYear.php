<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToFiscalYear — trait for operational models that carry a
 * `fiscal_year_id` column.
 *
 * ⚠️  STUB — Session 2 implements the method bodies.
 *
 * When implemented (Session 2), this trait will:
 *   1. Register a global Eloquent scope that automatically filters every
 *      query by the currently-active fiscal year:
 *
 *          static::addGlobalScope('current_fy', function (Builder $q) {
 *              $q->where('fiscal_year_id', FiscalYearResolver::activeId());
 *          });
 *
 *      This is the application-layer guarantee that no user — including
 *      super admin — sees closed-FY data through the normal query path.
 *
 *   2. Provide a `fiscalYear()` BelongsTo relation.
 *
 *   3. Provide a `scopeWithoutFiscalYearScope()` escape hatch for
 *      authorised admin/audit code paths. Every call site must be
 *      reviewed against FiscalYearPolicy::viewHistoricalData().
 *
 * Session 1 declares this trait but does NOT apply it to any model yet.
 * Applying the trait (and thus activating the global scope) is the
 * first task of Session 2.
 *
 * @see \App\Support\FiscalYearResolver
 * @see \App\Policies\FiscalYearPolicy (created in Session 2)
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 1 & 2
 */
trait BelongsToFiscalYear
{
    /**
     * Boot the trait. Session 2 will register the global scope here.
     *
     * @todo Session 2 — implement `static::addGlobalScope('current_fy', ...)`.
     */
    protected static function bootBelongsToFiscalYear(): void
    {
        // Intentionally empty in Session 1.
        // Session 2 adds:
        //   static::addGlobalScope('current_fy', function (Builder $builder) {
        //       $builder->where('fiscal_year_id', FiscalYearResolver::activeId());
        //   });
    }

    /**
     * Relation to the FiscalYear this row belongs to.
     *
     * @todo Session 2 — verify the FK column name and return type.
     */
    public function fiscalYear(): BelongsTo
    {
        // Session 2 implements:
        //   return $this->belongsTo(\App\Models\FiscalYear::class);
        return $this->belongsTo(\App\Models\FiscalYear::class);
    }

    /**
     * Escape hatch: remove the current_fy global scope for this query.
     *
     * ⚠️  SECURITY: Every call site MUST be reviewed against
     *    FiscalYearPolicy::viewHistoricalData(). Use of this scope
     *    without policy authorisation is a security violation.
     *
     * @todo Session 2 — verify alias matches the global scope name.
     */
    public function scopeWithoutFiscalYearScope(Builder $builder): Builder
    {
        // Session 2 implements:
        //   return $builder->withoutGlobalScope('current_fy');
        return $builder->withoutGlobalScope('current_fy');
    }
}
