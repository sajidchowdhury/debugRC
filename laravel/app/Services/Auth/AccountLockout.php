<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Account Lockout — Phase 3.
 * Replicates legacy core/AccountLockout.php behavior.
 *
 * After AUTH_MAX_FAILED_ATTEMPTS (5) failed logins, the account is locked
 * for AUTH_LOCKOUT_MINUTES (15) minutes. The lock is stored on the users
 * table: failed_login_count + locked_until columns.
 */
class AccountLockout
{
    private int $maxFailed;
    private int $lockMinutes;

    public function __construct()
    {
        $this->maxFailed = config('auth.max_failed_attempts', 5);
        $this->lockMinutes = config('auth.lockout_minutes', 15);
    }

    /**
     * Check if the user account is currently locked.
     */
    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    /**
     * Get a human-readable lock message.
     */
    public function lockMessage(User $user): ?string
    {
        if (!$this->isLocked($user)) {
            return null;
        }

        $minutes = max(1, (int) now()->diffInMinutes($user->locked_until));
        return "This account is temporarily locked. Please try again in {$minutes} minute(s).";
    }

    /**
     * Record a failed login attempt. Locks the account if threshold reached.
     */
    public function recordFailure(int $userId): void
    {
        try {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'failed_login_count' => DB::raw('failed_login_count + 1'),
                    'locked_until' => DB::raw(
                        'CASE WHEN failed_login_count + 1 >= ' . $this->maxFailed
                        . ' THEN NOW() + INTERVAL \'' . $this->lockMinutes . ' minutes\''
                        . ' ELSE locked_until END'
                    ),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('AccountLockout::recordFailure failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear the lockout counter (on successful login).
     */
    public function clear(int $userId): void
    {
        try {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('AccountLockout::clear failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
