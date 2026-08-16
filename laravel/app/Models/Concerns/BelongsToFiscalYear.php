<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\FiscalYear;
use App\Support\FiscalYearResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * BelongsToFiscalYear — trait for operational models that carry a
 * `fiscal_year_id` column.
 *
 * Implemented in Session 2.
 *
 * This trait is the APPLICATION-LAYER READ BLOCK for fiscal year
 * isolation (Q1, Gap 2). When applied to a model, every Eloquent
 * query against that model automatically gets:
 *
 *     WHERE fiscal_year_id = <active fiscal year id>
 *
 * appended by a global scope named `current_fy`. The active FY id is
 * resolved once per request by FiscalYearResolver::activeId() (cached
 * in Redis for 5 minutes, invalidated on FY lifecycle transitions).
 *
 * Effect: no user — including super admin — can read closed/locked
 * fiscal year data through any normal Eloquent query path. The only
 * way to bypass the scope is scopeWithoutFiscalYearScope(), and every
 * call site of that method MUST be reviewed against
 * FiscalYearPolicy::viewHistoricalData() (which hard-denies for
 * everyone — including super admin, whose Gate::before() bypass is
 * amended to NOT cover the viewHistoricalData ability).
 *
 * Escape hatch usage policy
 * -------------------------
 * scopeWithoutFiscalYearScope() is for:
 *   - Authorised audit/compliance code paths (none currently exist —
 *     the client requirement is that NO UI path allows historical
 *     view).
 *   - Year-end close / archive / restore commands that need to touch
 *     the soon-to-be-closed FY's rows directly.
 *
 * It is NOT for:
 *   - "Just show me last year's data" — denied by policy.
 *   - Reports spanning multiple FYs — denied by policy (the only
 *     multi-FY data visible through the UI is master data, which is
 *     not scoped by this trait).
 *
 * Trait application
 * -----------------
 * Apply the trait to every operational model listed in
 * config/fiscal.php `tables`. Master-data models (Product, Customer,
 * Supplier, User, Branch, Warehouse, Ledger) MUST NOT use this trait —
 * they are cross-FY by design. Audit-log models (StockAdjustmentAuditLog,
 * StockTakeAuditLog, ExportAuditLog, etc.) MUST NOT use this trait —
 * audit logs must remain queryable across FYs for compliance.
 *
 * The single table in config/fiscal.php without a corresponding
 * Eloquent model is `branch_ledger` — it is queried via DB::table()
 * in BranchDemandService and related inter-branch services. Those
 * service queries MUST explicitly filter by FiscalYearResolver::activeId()
 * until a BranchLedger model is introduced (tracked as a follow-up
 * in the Session 2 confirmation doc).
 *
 * @see \App\Support\FiscalYearResolver
 * @see \App\Policies\FiscalYearPolicy
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 2
 */
trait BelongsToFiscalYear
{
    /**
     * Boot the trait — register the `current_fy` global scope.
     *
     * Laravel auto-invokes `boot{TraitName}()` on model boot. The
     * global scope uses a closure that resolves FiscalYearResolver::activeId()
     * at QUERY TIME (not at boot time), so cache invalidation between
     * requests takes effect immediately.
     *
     * If FiscalYearResolver::activeId() throws (no active FY configured),
     * the exception propagates up through the query — this is the
     * intended fail-closed behaviour. The EnsureActiveFiscalYear
     * middleware (registered in bootstrap/app.php) calls activeId()
     * at request start so the exception surfaces as a clean 500 with
     * a clear message rather than a confusing query-time crash.
     */
    protected static function bootBelongsToFiscalYear(): void
    {
        static::addGlobalScope('current_fy', function (Builder $builder): void {
            $builder->where('fiscal_year_id', FiscalYearResolver::activeId());
        });
    }

    /**
     * Relation to the FiscalYear this row belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fiscalYear(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Escape hatch: remove the `current_fy` global scope for this query.
     *
     * ⚠️  SECURITY: Every call site MUST be reviewed against
     *    FiscalYearPolicy::viewHistoricalData(). Use of this scope
     *    without policy authorisation is a security violation and
     *    defeats the entire fiscal-year isolation guarantee (Q1 Gap 2).
     *
     * Authorised use cases (none currently exist in the UI layer —
     * all are console commands or service-layer internals):
     *   - Year-end close / archive commands that operate on the
     *     about-to-be-closed FY's rows.
     *   - Restore-from-backup commands.
     *   - Migration / data-repair scripts run by an administrator.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutFiscalYearScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope('current_fy');
    }
}
