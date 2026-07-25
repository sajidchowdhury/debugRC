<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountLockout;
use App\Services\Auth\CredentialVersion;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\RememberMeManager;
use App\Services\Auth\UserAuditLogger;
use App\Services\Notification\NotificationService;
use App\Session\LegacySessionBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated Session Controller — Phase 3.
 *
 * Simplified login flow (NO 2FA / NO OTP per Phase 0 decision):
 *   1. Validate username + password.
 *   2. Rate-limit by username + IP.
 *   3. Check account lockout.
 *   4. Verify bcrypt password.
 *   5. Clear lockout + rate-limit counters.
 *   6. Regenerate session (fixation prevention).
 *   7. Log in via Laravel Auth.
 *   8. Write to legacy session (so legacy PHP sees the login).
 *   9. Set remember-me cookie if requested.
 *  10. Audit log.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private LoginRateLimiter $rateLimiter,
        private AccountLockout $lockout,
        private RememberMeManager $rememberMe,
        private LegacySessionBridge $bridge,
        private NotificationService $notifications
    ) {}

    /**
     * Show the login form.
     */
    public function create(Request $request)
    {
        if (Auth::check()) {
            return redirect()->intended(route('dashboard'));
        }

        return view('auth.login', [
            'title' => 'Login — Remote Center ERP',
        ]);
    }

    /**
     * Handle login POST.
     */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember_me' => ['boolean'],
        ]);

        $username = trim($credentials['username']);
        $password = $credentials['password'];
        $rememberMe = !empty($credentials['remember_me']);

        // Rate-limit by username.
        if ($this->rateLimiter->isLimited($username)) {
            UserAuditLogger::log(null, 'login_failed', null, [
                'username' => $username,
                'reason' => 'rate_limited',
            ]);
            throw ValidationException::withMessages([
                'username' => 'Too many failed login attempts. Please try again in a few minutes.',
            ]);
        }

        // Load the user with employee + branch.
        $user = User::active()
            ->with(['employee', 'employee.branch'])
            ->where('username', $username)
            ->first();

        // Check account lockout.
        if ($user && $this->lockout->isLocked($user)) {
            UserAuditLogger::log(null, 'login_failed', $user->id, [
                'username' => $username,
                'reason' => 'account_locked',
            ]);
            throw ValidationException::withMessages([
                'username' => $this->lockout->lockMessage($user) ?? 'This account is temporarily locked.',
            ]);
        }

        // Verify password.
        if (!$user || !Hash::check($password, $user->password_hash)) {
            if ($user) {
                $this->lockout->recordFailure($user->id);
            }
            $this->rateLimiter->recordFailure($username);

            UserAuditLogger::log(null, 'login_failed', $user?->id, [
                'username' => $username,
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'username' => 'Invalid username or password.',
            ]);
        }

        // Verify employee is active.
        if (!$user->employee || !$user->employee->is_active) {
            UserAuditLogger::log(null, 'login_failed', $user->id, [
                'username' => $username,
                'reason' => 'employee_inactive',
            ]);
            throw ValidationException::withMessages([
                'username' => 'Your employee account is inactive. Please contact the administrator.',
            ]);
        }

        // Success — clear lockout + rate-limit.
        $this->lockout->clear($user->id);
        $this->rateLimiter->clear($username);

        // Rehash if needed (bcrypt cost upgrade).
        if (Hash::needsRehash($user->password_hash)) {
            $user->password_hash = Hash::make($password);
            $user->save();
            CredentialVersion::bump($user->id);
        }

        // Regenerate session (prevents session fixation).
        $request->session()->regenerate(true);

        // Log into Laravel.
        Auth::login($user, $rememberMe);

        // Populate Laravel session with user context (for Blade views + middleware).
        $version = CredentialVersion::fetch($user->id) ?? '';
        session([
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
        ]);

        // Write to legacy PHP session (so legacy pages see the login).
        $sessionId = LegacySessionBridge::getSessionIdFromRequest($request);
        if ($sessionId !== '') {
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
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->employee?->role ?? 'user',
                    'branch_id' => $user->employee?->branch_id,
                ],
            ]);
        }

        // Set remember-me cookie if requested.
        if ($rememberMe) {
            $this->rememberMe->create($user->id);
        }

        // Update last login (best effort).
        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'last_login' => now(),
                    'last_login_ip' => $request->ip(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update last_login', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        UserAuditLogger::log($user->id, 'login_success', null, [
            'username' => $username,
        ]);

        // F-18c: Notify configured recipients that a user logged in.
        // Best-effort — never blocks the login redirect.
        try {
            $this->notifications->dispatch(
                'user_login',
                "User {$user->username} logged in.",
                'user',
                $user->id,
                [],
                [
                    'created_by' => (int) $user->id,
                    'branch_id'  => (int) ($user->employee?->branch_id ?? 0),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Notification dispatch failed (user_login)', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        $request->session()->regenerateToken();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Handle logout.
     */
    public function destroy(Request $request)
    {
        $userId = Auth::id();
        // F-18c: capture branch context BEFORE Auth::logout() + session
        // invalidate() clear it, so the user_logout notification can still
        // resolve context-aware recipient types (e.g. warehouse_manager_of_branch).
        $branchId = (int) (session('branch_id') ?? auth()->user()?->employee?->branch_id ?? 0);
        $username = auth()->user()?->username ?? ('user#' . $userId);

        // Revoke remember-me.
        $this->rememberMe->revokeCurrent();

        // Destroy legacy PHP session.
        $sessionId = LegacySessionBridge::getSessionIdFromRequest($request);
        $this->bridge->destroy($sessionId);

        // Log out of Laravel.
        Auth::logout();

        // Invalidate + regenerate Laravel session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($userId) {
            UserAuditLogger::log((int) $userId, 'logout');

            // F-18c: Notify configured recipients that a user logged out.
            // Best-effort — never blocks the logout redirect.
            try {
                $this->notifications->dispatch(
                    'user_logout',
                    "User {$username} logged out.",
                    'user',
                    (int) $userId,
                    [],
                    [
                        'created_by' => (int) $userId,
                        'branch_id'  => $branchId,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Notification dispatch failed (user_logout)', [
                    'user_id' => (int) $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('login')
            ->with('success', 'You have been logged out successfully.');
    }
}
