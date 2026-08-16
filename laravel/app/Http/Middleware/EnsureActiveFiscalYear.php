<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FiscalYearResolver;
use Closure;
use Illuminate\Http\Request;

/**
 * EnsureActiveFiscalYear — resolves and caches the active FY id at
 * the start of every web request.
 *
 * Created in Session 2.
 *
 * Purpose
 * -------
 * The BelongsToFiscalYear trait's global scope calls
 * FiscalYearResolver::activeId() at QUERY TIME — meaning the first
 * Eloquent query in a request triggers the resolution (and the
 * potential RuntimeException if no active FY is configured). Without
 * this middleware, the exception would surface deep inside a
 * controller / view rendering, producing a confusing 500 error
 * page that doesn't clearly explain "you need to activate a fiscal
 * year."
 *
 * This middleware runs the resolution UP-FRONT, before the route's
 * controller executes. If no active FY exists, the request fails
 * fast with a clear, actionable message — for both web and API
 * callers. The middleware also has the side-effect of warming the
 * Redis cache so subsequent queries in the same request hit the
 * cache instead of re-querying fiscal_years.
 *
 * Exempt paths
 * ------------
 * The middleware skips:
 *   - Unauthenticated requests to /login, /logout, /password/* —
 *     these must remain accessible so the user can LOG IN to fix
 *     the problem (e.g., a super admin activating a new FY after
 *     year-end close).
 *   - The /up health-check endpoint.
 *   - The /admin/fiscal-years/* routes — so the super admin can
 *     reach the FY management UI to activate a new FY even when
 *     no active FY currently exists (the chicken-and-egg case
 *     immediately after year-end close).
 *
 * Fail-closed behaviour
 * ---------------------
 * If FiscalYearResolver::activeId() throws, the middleware:
 *   - For API requests: returns JSON 503 with a clear message.
 *   - For web requests: returns a 503 status page with a clear
 *     message instructing the user to contact an administrator.
 *
 * @see \App\Support\FiscalYearResolver
 * @see \App\Models\Concerns\BelongsToFiscalYear
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 2
 */
class EnsureActiveFiscalYear
{
    /**
     * Route prefixes that bypass the active-FY check.
     *
     * These are the chicken-and-egg paths: the user must be able to
     * reach the FY management UI to activate a new FY even when no
     * active FY currently exists.
     */
    private const EXEMPT_PREFIXES = [
        'admin/fiscal-years',
        'fiscal-years',
        'login',
        'logout',
        'password',
        'up',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Skip exemption paths — allow the request through without
        // resolving the active FY. This is critical for the FY
        // management UI to remain reachable after year-end close.
        $path = $request->path();
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix) || str_starts_with($path, '/' . $prefix)) {
                return $next($request);
            }
        }

        // Resolve the active FY up-front. This warms the Redis cache
        // and surfaces the "no active FY" error here rather than
        // deep inside a controller's Eloquent query.
        try {
            FiscalYearResolver::activeId();
        } catch (\RuntimeException $e) {
            return $this->failClosedResponse($request, $e->getMessage());
        }

        return $next($request);
    }

    /**
     * Build the fail-closed response when no active FY is configured.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $message
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    private function failClosedResponse(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'error'   => 'no_active_fiscal_year',
            ], 503);
        }

        // Web: render a simple 503 status page. We deliberately do NOT
        // redirect to /login (the user may already be logged in as
        // super admin and just needs to navigate to /admin/fiscal-years
        // to activate a new FY — which is exempt from this middleware).
        return response()->view(
            'errors.no-active-fiscal-year',
            ['message' => $message],
            503
        );
    }
}
