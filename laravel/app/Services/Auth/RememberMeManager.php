<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cookie;

/**
 * Remember-Me Manager — Phase 3.
 * Replicates legacy core/RememberMe.php behavior (selector:validator scheme).
 *
 * Legacy uses a separate `remember_tokens` table with:
 *   selector (indexed, looked up from cookie)
 *   token_hash (sha256 of validator, constant-time compared)
 *   user_id, expires_at
 *
 * Phase 3: Laravel's native remember-me uses users.remember_token column.
 * For the transition, we keep BOTH:
 *   - Laravel native (for Laravel-only pages)
 *   - Legacy remember_tokens table (so legacy PHP sees remember-me)
 *
 * In Phase 12 (cutover), the legacy table is dropped.
 */
class RememberMeManager
{
    private string $cookieName = 'remember_rcerp';
    private int $days;

    public function __construct()
    {
        $this->days = config('auth.remember_days', 30);
    }

    /**
     * Create a remember-me token for a user.
     * Stores the selector:validator hash in the DB and sets the cookie.
     */
    public function create(int $userId): void
    {
        try {
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $validatorHash = hash('sha256', $validator);
            $expiresAt = now()->addDays($this->days);

            DB::table('remember_tokens')->insert([
                'selector' => $selector,
                'token_hash' => $validatorHash,
                'user_id' => $userId,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            $cookieValue = $selector . ':' . $validator;
            Cookie::queue($this->cookieName, $cookieValue, $this->days * 24 * 60);
        } catch (\Throwable $e) {
            Log::error('RememberMeManager::create failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Attempt to restore a session from the remember-me cookie.
     * Returns the user_id if valid, or null.
     * Rotates the token on use (defense in depth).
     */
    public function attemptRestore(): ?int
    {
        $cookieValue = request()->cookie($this->cookieName);
        if (!$cookieValue || !str_contains($cookieValue, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookieValue, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        try {
            $row = DB::table('remember_tokens')
                ->where('selector', $selector)
                ->where('expires_at', '>', now())
                ->first();

            if (!$row) {
                $this->clearCookie();
                return null;
            }

            // Constant-time comparison of the validator hash.
            if (!hash_equals($row->token_hash, hash('sha256', $validator))) {
                $this->clearCookie();
                // Potential token theft — revoke all tokens for this user.
                $this->revokeAllForUser($row->user_id);
                return null;
            }

            // Rotate the token on use.
            $newSelector = bin2hex(random_bytes(16));
            $newValidator = bin2hex(random_bytes(32));
            DB::table('remember_tokens')
                ->where('selector', $selector)
                ->update([
                    'selector' => $newSelector,
                    'token_hash' => hash('sha256', $newValidator),
                    'updated_at' => now(),
                ]);

            $newCookieValue = $newSelector . ':' . $newValidator;
            Cookie::queue($this->cookieName, $newCookieValue, $this->days * 24 * 60);

            return (int) $row->user_id;
        } catch (\Throwable $e) {
            Log::warning('RememberMeManager::attemptRestore failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Revoke the current remember-me token (on logout).
     */
    public function revokeCurrent(): void
    {
        $cookieValue = request()->cookie($this->cookieName);
        if (!$cookieValue || !str_contains($cookieValue, ':')) {
            return;
        }

        [$selector] = explode(':', $cookieValue, 2);

        try {
            DB::table('remember_tokens')->where('selector', $selector)->delete();
        } catch (\Throwable $e) {
            Log::warning('RememberMeManager::revokeCurrent failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->clearCookie();
    }

    /**
     * Revoke all remember-me tokens for a user (on password change, admin force-logout).
     */
    public function revokeAllForUser(int $userId): void
    {
        try {
            DB::table('remember_tokens')->where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            Log::warning('RememberMeManager::revokeAllForUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clearCookie(): void
    {
        Cookie::queue(Cookie::forget($this->cookieName));
    }
}
