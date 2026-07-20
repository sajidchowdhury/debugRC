<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Session\LegacySessionBridge;
use App\Services\Auth\CredentialVersion;
use App\Services\Auth\RememberMeManager;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sync Legacy Session — Phase 3.
 *
 * This middleware runs BEFORE Laravel's auth middleware on every request.
 * It reads the legacy PHP session (from Redis) and, if a user is logged in
 * there, logs them into Laravel's auth system.
 *
 * This is the core of the shared-session bridge: a user who logs in via
 * legacy PHP is automatically authenticated in Laravel, and vice versa.
 *
 * Flow:
 *   1. Read the PHPSESSID cookie from the request.
 *   2. Read the legacy session from Redis (PHPREDIS_SESSION:<id>).
 *   3. If user_id is present in the legacy session AND Laravel auth is not set:
 *      a. Load the User from DB.
 *      b. Check credential_version matches (constant-time).
 *      c. If valid, Auth::loginUsingId($userId).
 *      d. If invalid, destroy the legacy session (password was changed).
 *   4. If Laravel auth IS set but legacy session has no user_id:
 *      (This happens after Laravel login — the AuthController writes the
 *       legacy session, so this case is rare.)
 *   5. If neither is set, try remember-me cookie.
 */
class SyncLegacySession
{
    public function __construct(
        private LegacySessionBridge $bridge,
        private RememberMeManager $rememberMe
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Skip for non-web routes (API, artisan, etc.)
        if ($request->is('api/*') || app()->runningInConsole()) {
            return $next($request);
        }

        // If Laravel already has an authenticated user, nothing to do.
        if (Auth::check()) {
            return $next($request);
        }

        // Try to restore from legacy session.
        $sessionId = LegacySessionBridge::getSessionIdFromRequest($request);
        if ($sessionId !== '') {
            $legacyData = $this->bridge->read($sessionId);

            $userId = (int) ($legacyData['user_id'] ?? 0);
            if ($userId > 0) {
                $this->loginFromLegacy($userId, $legacyData, $sessionId);
                return $next($request);
            }
        }

        // No legacy session — try remember-me cookie.
        $rememberedUserId = $this->rememberMe->attemptRestore();
        if ($rememberedUserId !== null) {
            $user = User::active()->find($rememberedUserId);
            if ($user && $user->employee && $user->employee->is_active) {
                Auth::login($user, true);
                // Write to legacy session so legacy PHP sees the login.
                $this->writeLegacySession($user, $sessionId);
            }
        }

        return $next($request);
    }

    /**
     * Log the user into Laravel based on legacy session data.
     */
    private function loginFromLegacy(int $userId, array $legacyData, string $sessionId): void
    {
        $user = User::active()->with('employee')->find($userId);

        if (!$user || !$user->employee || !$user->employee->is_active) {
            // User no longer exists or is inactive — destroy the legacy session.
            $this->bridge->destroy($sessionId);
            return;
        }

        // Check credential_version (constant-time comparison).
        $sessionVersion = (string) ($legacyData['credential_version'] ?? '');
        if (!CredentialVersion::isValid($userId, $sessionVersion)) {
            // Password or role was changed — invalidate the session.
            $this->bridge->destroy($sessionId);
            $this->rememberMe->revokeAllForUser($userId);
            Log::info('Session invalidated due to credential version mismatch', [
                'user_id' => $userId,
            ]);
            return;
        }

        // Log the user into Laravel.
        Auth::login($user);

        // Populate Laravel session with legacy data (for Blade views).
        session([
            'user_id' => $userId,
            'username' => $legacyData['username'] ?? $user->username,
            'employee_id' => $legacyData['employee_id'] ?? $user->employee_id,
            'employee_name' => $legacyData['employee_name'] ?? $user->employee?->name,
            'role' => $legacyData['role'] ?? $user->employee?->role,
            'branch_id' => $legacyData['branch_id'] ?? $user->employee?->branch_id,
            'branch_name' => $legacyData['branch_name'] ?? $user->employee?->branch?->branch_name,
            'credential_version' => $sessionVersion,
        ]);
    }

    /**
     * Write user data to the legacy session (for remember-me restoration).
     */
    private function writeLegacySession(User $user, string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        $version = CredentialVersion::fetch($user->id) ?? '';
        $this->bridge->write($sessionId, [
            'user_id' => $user->id,
            'username' => $user->username,
            'employee_id' => $user->employee_id,
            'employee_name' => $user->employee?->name,
            'role' => $user->employee?->role ?? 'user',
            'branch_id' => $user->employee?->branch_id,
            'branch_name' => $user->employee?->branch?->branch_name,
            'logged_in_at' => time(),
            'photo' => $user->employee?->photo ?? '',
            'credential_version' => $version,
            'csrf_token' => bin2hex(random_bytes(32)),
            'last_regeneration' => time(),
        ]);
    }
}
