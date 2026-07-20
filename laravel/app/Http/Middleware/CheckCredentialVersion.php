<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Auth\CredentialVersion;
use App\Session\LegacySessionBridge;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Credential Version — Phase 3.
 *
 * Runs AFTER auth middleware. If the session's credential_version doesn't
 * match the DB value (meaning password or role was changed), the session
 * is invalidated and the user is redirected to login.
 *
 * This is the defense-in-depth check that runs on EVERY authenticated request,
 * catching credential changes that happened while the user was logged in.
 */
class CheckCredentialVersion
{
    public function __construct(
        private LegacySessionBridge $bridge
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $userId = (int) Auth::id();
        $sessionVersion = (string) session('credential_version', '');

        if (!CredentialVersion::isValid($userId, $sessionVersion)) {
            // Credential changed — log out and redirect.
            $sessionId = LegacySessionBridge::getSessionIdFromRequest($request);
            $this->bridge->destroy($sessionId);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session ended because your account credentials were changed. Please sign in again.',
                ], 401);
            }

            return redirect()->route('login')
                ->with('warning', 'Your session ended because your account credentials were changed. Please sign in again.');
        }

        return $next($request);
    }
}
