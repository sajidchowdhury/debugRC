<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FiscalYear;
use Illuminate\Support\Facades\Cache;

/**
 * FiscalYearResolver — resolves the currently-active fiscal year id.
 *
 * Implemented in Session 2. This class is the single source of truth for
 * "which fiscal year is the running one" across the entire application.
 * Every operational model that uses the BelongsToFiscalYear trait calls
 * FiscalYearResolver::activeId() inside its global scope, so the value
 * returned here silently filters every Eloquent query against the
 * `fiscal_year_id` column added in Session 1.
 *
 * Caching strategy
 * ----------------
 * The active FY id changes extremely rarely — only on
 * FiscalYearService::activateFiscalYear() (draft → active) and on
 * FiscalYearService::closeFiscalYear() + activate(next). We therefore
 * cache the value in Redis for 5 minutes as a safety net (so a cache
 * stampede during traffic spikes doesn't hit the DB), AND we explicitly
 * invalidate the cache via clearCache() inside both lifecycle methods.
 * The 5-minute TTL is the upper bound on "how stale can the cached FY id
 * be" if someone forgets to call clearCache() — it self-heals.
 *
 * Resolution rule
 * ---------------
 *   1. Prefer a row with status='active' AND is_current=true (the
 *      canonical "running FY" — set by FiscalYearService::activateFiscalYear).
 *   2. Fall back to status='active' alone (covers the edge case where
 *      is_current flag was not yet flipped during a transition).
 *   3. Fall back to is_current=true alone (covers legacy data imported
 *      without status='active').
 *
 * If no row matches any of the above, we throw a RuntimeException. This
 * is a fail-closed posture — better to crash loudly than to silently
 * let every operational query return zero rows (which would look like
 * "no data" and confuse users into thinking data was lost).
 *
 * Override path
 * -------------
 * currentRequestFyOverride() always returns null. Per the client's hard
 * requirement, there is NO UI path that lets any user — including super
 * admin — view closed/locked fiscal year data. The method exists for
 * completeness and to make the policy intent explicit in code; it is
 * not called from any code path.
 *
 * @see \App\Models\Concerns\BelongsToFiscalYear
 * @see \App\Http\Middleware\EnsureActiveFiscalYear
 * @see \App\Services\Accounting\FiscalYearService::activateFiscalYear()
 * @see \App\Services\Accounting\FiscalYearService::closeFiscalYear()
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 2
 */
class FiscalYearResolver
{
    /**
     * Cache key for the active fiscal year id.
     */
    public const CACHE_KEY = 'active_fiscal_year_id';

    /**
     * Cache TTL in seconds (5 minutes — self-healing upper bound).
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Return the currently-active fiscal year id.
     *
     * @return int The active fiscal year id. Never returns 0 or null —
     *             throws if no active fiscal year is configured.
     *
     * @throws \RuntimeException When no active or current fiscal year
     *                           exists in the database. This is a
     *                           fail-closed posture — the application
     *                           cannot function without an active FY.
     */
    public static function activeId(): int
    {
        return (int) Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): int {
                // Resolution rule: status='active' AND is_current=true first,
                // then status='active' alone, then is_current=true alone.
                //
                // IMPORTANT: bypass BranchScope global scope. FiscalYear with
                // branch_id=NULL is a global/shared fiscal year visible to all
                // branches. The BranchScope would filter it out for non-admin
                // users (where branch_id = user's branch != NULL), causing
                // activeId() to throw "No active fiscal year found" for
                // non-admin requests — which cascades as 500 errors across
                // dashboard, reports, and API endpoints.
                $id = FiscalYear::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where(function ($q) {
                        $q->where('status', 'active')
                          ->orWhere('is_current', true);
                    })
                    ->orderByRaw("CASE WHEN status = 'active' AND is_current = true THEN 0
                                      WHEN status = 'active' THEN 1
                                      ELSE 2 END")
                    ->value('id');

                if (!$id) {
                    throw new \RuntimeException(
                        'No active fiscal year found. '
                        . 'An administrator must create and activate a fiscal year '
                        . 'before any operational transaction can be processed.'
                    );
                }

                return (int) $id;
            }
        );
    }

    /**
     * Invalidate the cached active FY id.
     *
     * Called by:
     *   - FiscalYearService::activateFiscalYear() — after a draft FY becomes active.
     *   - FiscalYearService::closeFiscalYear()    — after an active FY becomes closed.
     *   - FiscalYearService::lockFiscalYear()     — after a FY becomes locked.
     *
     * This ensures the very next request resolves to the new state rather
     * than the cached (now-stale) value.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Return an explicit FY override for the current request, or null.
     *
     * Per the client's hard requirement, this ALWAYS returns null in the
     * default implementation — there is no UI path to view historical
     * (closed/locked) fiscal year data. The FiscalYearPolicy::viewHistoricalData()
     * gate hard-denies for everyone, including super admin (Gate::before()
     * is amended to NOT bypass this specific ability).
     *
     * The method exists to make the security intent explicit in code:
     * any future feature that wants to allow historical view MUST first
     * amend FiscalYearPolicy::viewHistoricalData() AND amend
     * Gate::before() to allow the bypass for that specific ability —
     * at which point this method would return the overridden FY id
     * sourced from an authorized request context.
     *
     * @return int|null Always null in the default implementation.
     */
    public static function currentRequestFyOverride(): ?int
    {
        return null;
    }
}
