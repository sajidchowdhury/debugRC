<?php

namespace App\Http\Middleware;

use App\Exceptions\SystemPolicyWriteBlockedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block Writes During Investigation — AUDIT-TRAIL-3 (G-172 + G-175).
 *
 * The HTTP-layer write block. When the system is in INVESTIGATION mode, all
 * non-GET requests are blocked with a SystemPolicyWriteBlockedException
 * (rendered as 422 JSON for API/AJAX callers, redirect-back-with-error for
 * web — see bootstrap/app.php exception handler).
 *
 * INVESTIGATION mode is the forensic posture that "freezes the books" — the
 * investigator must examine a stable, uncontaminated data set. Allowing
 * writes during an investigation would (a) contaminate the evidence and
 * (b) defeat the read-side clamping done by ApplySystemPolicyScope (G-171):
 * if writes were allowed, the clamp would hide newly-created rows on the
 * next read, producing confusing "I created it but cannot see it" behavior.
 *
 * ALLOWLIST (URI prefix match, case-sensitive):
 *   - login, logout, forgot, reset    — auth flows must remain usable so
 *                                        users can authenticate / sign out.
 *   - admin/compliance*               — the superadmin must be able to
 *                                        DEACTIVATE investigation mode
 *                                        (otherwise the system is locked
 *                                        out — a self-inflicted DoS).
 *   - api/docs*                       — public API docs (read-only HTML).
 *   - up                              — Laravel health check (read-only).
 *
 * GET / HEAD / OPTIONS requests are always allowed (read-side clamping is
 * the job of ApplySystemPolicyScope, not this middleware).
 *
 * REGISTRATION: appended to the global middleware stack in bootstrap/app.php
 * AFTER CheckSystemPolicy (which loads + shares the active policy via
 * app('system_policy_mode')). Runs for both web + API requests.
 *
 * DEFENSE-IN-DEPTH: this middleware only catches HTTP-driven writes. Console
 * commands, scheduled jobs, and queue workers bypass HTTP middleware
 * entirely. The service-layer hook
 * SystemPolicyService::assertWriteAllowed() (called from
 * JournalPostingService::createJournalEntry) catches those. Together, the
 * two layers fully enforce the INVESTIGATION-mode write freeze.
 */
class BlockWritesDuringInvestigation
{
    /**
     * URI prefixes that are ALWAYS allowed (even during INVESTIGATION mode).
     * Matched against the request path (without leading slash).
     *
     * @var array<int, string>
     */
    private const ALLOWED_PREFIXES = [
        'login',
        'logout',
        'forgot',
        'reset',
        'admin/compliance',
        'api/docs',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Read-only verbs are never blocked.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // 2. Allowlisted URIs are never blocked (auth + compliance toggle +
        //    public docs + health check). Match either the exact segment
        //    (e.g. 'login') or a segment prefix (e.g. 'admin/compliance/...').
        $path = ltrim($request->path(), '/');
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $next($request);
            }
        }

        // 3. Check the active system policy mode (warmed by CheckSystemPolicy
        //    middleware which runs BEFORE this one in the global stack).
        //
        //    DEFENSE-IN-DEPTH (fail-open): if the upstream CheckSystemPolicy
        //    middleware failed to register the `system_policy_mode` binding
        //    (e.g. SystemPolicyService::getCurrentPolicy() threw on an empty
        //    system_policies table in test/fresh-install environments), we
        //    treat the mode as 'NORMAL' and allow the write through. This
        //    mirrors the documented fail-open posture of SystemPolicyService
        //    itself: "Worst case = INVESTIGATION mode temporarily does not
        //    block writes, which is preferable to a total GL posting
        //    failure." Locking up every POST/PUT/DELETE in the app with a
        //    500 because of a missing container binding is far worse than
        //    temporarily not blocking writes when no policy is configured.
        if (app()->bound('system_policy_mode')) {
            $mode = app('system_policy_mode');
        } else {
            $mode = 'NORMAL';
        }
        if ($mode === 'INVESTIGATION') {
            throw new SystemPolicyWriteBlockedException(
                $mode,
                'http_request',
                $request->method() . ' ' . $request->path()
            );
        }

        return $next($request);
    }
}
