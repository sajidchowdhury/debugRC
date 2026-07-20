<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User Audit Logger — Phase 3.
 * Replicates legacy core/UserAudit.php behavior.
 *
 * Dual-write: logs user-related actions to BOTH:
 *   1. user_audit_log table (PG, jsonb details column)
 *   2. logs/user_audit.log file (JSON lines, for defense in depth)
 *
 * Actions tracked: login_success, login_failed, logout, password_change,
 * role_change, user_created, user_updated, user_deleted, account_locked, etc.
 */
class UserAuditLogger
{
    /**
     * Log a user audit event.
     *
     * @param int|null $userId The user performing the action.
     * @param string $action The action name (e.g. 'login_success').
     * @param int|null $targetUserId The user being acted upon (for admin actions).
     * @param array<string, mixed> $details Additional context.
     */
    public static function log(
        ?int $userId,
        string $action,
        ?int $targetUserId = null,
        array $details = []
    ): void {
        $request = request();
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        $userAgent = $userAgent !== null
            ? mb_substr(preg_replace('/[\r\n\t]/', ' ', $userAgent), 0, 255)
            : null;

        // Enrich with branch_id if available from the session.
        $branchId = session('branch_id');

        try {
            DB::table('user_audit_log')->insert([
                'user_id' => $userId,
                'action' => $action,
                'target_user_id' => $targetUserId,
                'branch_id' => $branchId,
                'details' => json_encode($details),
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('UserAuditLogger: DB insert failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        // Dual-write to file (JSON lines, for defense in depth).
        try {
            $logEntry = json_encode([
                'timestamp' => now()->toISOString(),
                'user_id' => $userId,
                'action' => $action,
                'target_user_id' => $targetUserId,
                'branch_id' => $branchId,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'details' => $details,
            ], JSON_UNESCAPED_UNICODE);

            $logPath = storage_path('logs/user_audit.log');
            file_put_contents($logPath, $logEntry . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            Log::error('UserAuditLogger: file write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
