<?php

namespace App\Http\Middleware;

use App\Services\Compliance\SystemPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check System Policy Middleware — Phase 11.
 *
 * Every authenticated request passes through this middleware.
 * It loads the current policy ONCE (from cache) and shares it with
 * the application via:
 *   - app()->singleton (DI container)
 *   - view shared variable ($systemPolicy)
 *   - request attributes
 *
 * This eliminates scattered if(investigation_mode) checks — controllers
 * and views can access the policy via the service or the shared variable.
 *
 * The middleware does NOT enforce restrictions itself — that's done by
 * the ApplySystemPolicyScope trait on models. The middleware just loads
 * the policy so the scopes can access it.
 */
class CheckSystemPolicy
{
    public function __construct(
        private SystemPolicyService $policyService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Load current policy (cached — O(1) on normal requests).
        //
        // DEFENSE-IN-DEPTH: if the underlying service call throws (cache
        // outage, DB unavailable, empty system_policies table in a fresh
        // test environment), fall back to NORMAL mode so downstream
        // middleware (BlockWritesDuringInvestigation) still has the
        // `system_policy_mode` binding to read. Locking up every
        // request because the policy lookup failed is far worse than
        // temporarily running unbridled — matches the documented
        // fail-open posture of SystemPolicyService itself.
        try {
            $policy = $this->policyService->getCurrentPolicy();
            $mode = $this->policyService->getCurrentMode();
        } catch (\Throwable $e) {
            $policy = null;
            $mode = 'NORMAL';
            \Illuminate\Support\Facades\Log::warning('CheckSystemPolicy: failed to load policy, defaulting to NORMAL', [
                'error' => $e->getMessage(),
            ]);
        }

        // Share with the application.
        app()->instance('system_policy', $policy);
        app()->instance('system_policy_mode', $mode);

        // Share with all views.
        view()->share('systemPolicy', $policy);
        view()->share('systemPolicyMode', $mode);
        view()->share('isInvestigation', $mode === 'INVESTIGATION');

        // For investigation mode: check if policy has expired.
        if ($policy && $policy->expires_at && $policy->expires_at->isPast()) {
            // Auto-deactivate expired policy.
            $this->policyService->deactivate(
                $policy->activated_by ?? 1,
                'Policy expired automatically at ' . $policy->expires_at->toISOString()
            );
        }

        return $next($request);
    }
}
