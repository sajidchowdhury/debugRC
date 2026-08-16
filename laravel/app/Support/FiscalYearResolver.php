<?php

declare(strict_types=1);

namespace App\Support;

/**
 * FiscalYearResolver — resolves the currently-active fiscal year id.
 *
 * ⚠️  STUB — Session 2 implements the method bodies.
 *
 * When implemented (Session 2), this class will:
 *
 *   1. `activeId(): int` — return the cached active FY id from Redis:
 *
 *          return (int) cache()->remember(
 *              'active_fiscal_year_id',
 *              now()->addMinutes(5),
 *              fn () => FiscalYear::where('status', 'active')
 *                  ->orWhere('is_current', true)
 *                  ->orderByDesc('is_current')
 *                  ->value('id')
 *          );
 *
 *   2. `clearCache(): void` — invalidate the cache. Called by
 *      FiscalYearService::activate() and AccountingPeriodService::yearEndClose()
 *      so the next request resolves to the new active FY.
 *
 *   3. `currentRequestFyOverride(): ?int` — returns null in normal operation.
 *      Returns an explicit FY id ONLY when an authorised "view historical"
 *      path is invoked. Per the client's hard requirement, this path is
 *      denied to everyone through the UI — the method exists for completeness
 *      but always returns null in the default implementation.
 *
 * Session 1 declares this class but does NOT wire it into any code path.
 * Session 2 implements the bodies and adds the EnsureActiveFiscalYear
 * middleware that calls `activeId()` at request start.
 *
 * @see \App\Models\Concerns\BelongsToFiscalYear
 * @see \App\Http\Middleware\EnsureActiveFiscalYear (created in Session 2)
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 2
 */
class FiscalYearResolver
{
    /**
     * Return the currently-active fiscal year id.
     *
     * @todo Session 2 — implement with Redis caching (5-minute TTL).
     */
    public static function activeId(): int
    {
        // Session 2 implements:
        //   return (int) cache()->remember(
        //       'active_fiscal_year_id',
        //       now()->addMinutes(5),
        //       fn () => (int) \App\Models\FiscalYear::where('status', 'active')
        //           ->orWhere('is_current', true)
        //           ->orderByDesc('is_current')
        //           ->value('id')
        //   );
        //
        // For Session 1, return 0 as a sentinel — this method is not called
        // until Session 2 wires it into the trait + middleware.
        return 0;
    }

    /**
     * Invalidate the cached active FY id.
     *
     * @todo Session 2 — implement cache()->forget('active_fiscal_year_id').
     */
    public static function clearCache(): void
    {
        // Session 2 implements:
        //   cache()->forget('active_fiscal_year_id');
    }

    /**
     * Return an explicit FY override for the current request, or null.
     *
     * Per the client's hard requirement, this ALWAYS returns null in the
     * default implementation — there is no UI path to view historical data.
     *
     * @todo Session 2 — confirm this always returns null (no override path).
     */
    public static function currentRequestFyOverride(): ?int
    {
        return null;
    }
}
