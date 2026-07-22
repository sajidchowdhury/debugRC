<?php

namespace Tests\Helpers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * User Phase 14 test helpers — direct table inserts and factory shortcuts
 * for user-specific test states (locked, inactive, recently-logged-in, etc.).
 *
 * Used by:
 *  - tests/Unit/User/UserDeactivationUnitTest
 *  - tests/Feature/User/UserRbacTest
 *  - tests/Feature/User/UserCrudTest
 *  - tests/Feature/User/UserAuditTest
 *  - tests/Feature/User/UserValidationTest
 *
 * NOTE: Tests\Helpers\BuildsRoleUsers already creates Employee + User chains
 * via factories for RBAC tests. The User CRUD/Audit/Validation test classes
 * use BOTH traits — BuildsRoleUsers for authenticated role users + this
 * trait for direct inserts of locked/inactive user states.
 *
 * NOTE (2026-07-22): The Telegram helper was removed when R24/R25 were
 * dropped per user request. Migration 2025_01_20_000010_drop_fcm_and_telegram_fields
 * drops the users.telegram_user_id column.
 */
trait InsertsUserDependencies
{
    /**
     * Create a User tied to a fresh Employee + Branch.
     *
     * Convenience wrapper that mirrors BuildsRoleUsers::makeRoleUser()
     * without authenticating. Useful for creating "subject" users that
     * an admin will then act upon.
     */
    protected function makeUser(array $userOverrides = [], array $employeeOverrides = []): User
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()
            ->forBranch($branch->id)
            ->create($employeeOverrides);

        return User::factory()
            ->forEmployee($employee->id)
            ->create(array_merge([
                'username'      => 'subject_' . substr(uniqid(), -6),
                'password_hash' => Hash::make('password'),
            ], $userOverrides));
    }

    /**
     * Create a locked-out User (locked_until = 30 minutes in the future).
     */
    protected function makeLockedUser(array $overrides = []): User
    {
        return $this->makeUser(array_merge([
            'locked_until'        => now()->addMinutes(30),
            'failed_login_count'  => 5,
        ], $overrides));
    }

    /**
     * Create an inactive User (is_active = false).
     */
    protected function makeInactiveUser(array $overrides = []): User
    {
        return $this->makeUser(array_merge([
            'is_active' => false,
        ], $overrides));
    }

    /**
     * Create a User whose last_login was within the last 5 minutes
     * (i.e. an "active session" — blocks deactivation).
     */
    protected function makeRecentlyLoggedInUser(array $overrides = []): User
    {
        return $this->makeUser(array_merge([
            'last_login'    => now()->subMinute(),
            'last_login_ip' => '127.0.0.1',
        ], $overrides));
    }

    /**
     * Create a User whose last_login was more than 5 minutes ago
     * (i.e. an "expired session" — safe to deactivate).
     */
    protected function makeStaleLoggedInUser(array $overrides = []): User
    {
        return $this->makeUser(array_merge([
            'last_login'    => now()->subHours(2),
            'last_login_ip' => '127.0.0.1',
        ], $overrides));
    }

    /**
     * Insert a user_audit_log row directly (bypasses UserAuditLogger service).
     *
     * Used to seed audit-log entries for securityAudit() tests.
     *
     * @return int  The user_audit_log.id
     */
    protected function insertUserAuditLog(
        ?int $userId,
        string $action,
        ?int $targetUserId = null,
        array $details = [],
        ?string $ip = '127.0.0.1',
    ): int {
        return DB::table('user_audit_log')->insertGetId([
            'user_id'        => $userId,
            'action'         => $action,
            'target_user_id' => $targetUserId,
            'branch_id'      => null,
            'details'        => json_encode($details),
            'ip_address'     => $ip,
            'user_agent'     => 'PHPUnit',
            'created_at'     => now(),
        ]);
    }
}
