<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credential Version — Phase 3.
 * Replicates legacy core/CredentialVersion.php behavior.
 *
 * A monotonic counter on the users table. When bumped (on password change,
 * role change, or admin force-logout), all other sessions for that user
 * are invalidated because the session's stored credential_version no longer
 * matches the DB value. Comparison uses hash_equals() for constant-time.
 */
class CredentialVersion
{
    /**
     * Fetch the current credential_version for a user.
     */
    public static function fetch(int $userId): ?string
    {
        try {
            $version = DB::table('users')
                ->where('id', $userId)
                ->value('credential_version');
            return $version !== null ? (string) $version : null;
        } catch (\Throwable $e) {
            Log::error('CredentialVersion::fetch failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Bump the credential_version (invalidates all other sessions).
     * Called on password change, role change, or admin force-logout.
     */
    public static function bump(int $userId): void
    {
        try {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'credential_version' => DB::raw('credential_version + 1'),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('CredentialVersion::bump failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the session's stored version matches the DB version.
     * Uses hash_equals() for constant-time comparison.
     */
    public static function isValid(int $userId, string $sessionVersion): bool
    {
        $currentVersion = self::fetch($userId);
        if ($currentVersion === null) {
            return false;
        }
        if ($sessionVersion === '') {
            return false;
        }
        return hash_equals($currentVersion, $sessionVersion);
    }
}
