<?php

namespace Tests\Unit\User;

use App\Http\Controllers\Admin\UserController;
use App\Models\User;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsUserDependencies;
use Tests\TestCase;

/**
 * User Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on UserController via reflection.
 *
 * Phase 14 safety check:
 *   - Blocks deactivation when the user has an active login session
 *     (last_login within the last 5 minutes).
 *
 * This mirrors the legacy guard against deactivating a user mid-session —
 * doing so would leave orphaned sessions and confuse the user (their
 * next request would fail auth with no clear explanation).
 */
class UserDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsUserDependencies;

    private UserController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(UserController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(User $user): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $user);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_user_with_no_login_history(): void
    {
        $user = $this->makeUser(['last_login' => null]);

        $result = $this->callCanDeactivate($user);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_user_with_stale_login(): void
    {
        // last_login > 5 minutes ago → safe to deactivate
        $user = $this->makeStaleLoggedInUser();

        $result = $this->callCanDeactivate($user);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_user_who_logged_in_exactly_5_minutes_ago(): void
    {
        // Boundary: last_login = 5 minutes ago should be ALLOWED
        // (the check is `$lastLogin->gt(now()->subMinutes(5))`, so a login
        // exactly 5 minutes ago is NOT strictly greater than 5 minutes ago).
        $user = $this->makeUser([
            'last_login' => now()->subMinutes(5),
        ]);

        $result = $this->callCanDeactivate($user);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_inactive_user(): void
    {
        // Already-inactive users can always be deactivated (no session).
        $user = $this->makeInactiveUser();

        $result = $this->callCanDeactivate($user);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker: Active session (last_login within 5 minutes)
    // ====================================================================

    public function test_cannot_deactivate_user_with_active_session(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $result = $this->callCanDeactivate($user);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('active login session', $result['message']);
    }

    public function test_cannot_deactivate_user_who_logged_in_one_minute_ago(): void
    {
        $user = $this->makeUser([
            'last_login' => now()->subMinute(),
        ]);

        $result = $this->callCanDeactivate($user);

        $this->assertFalse($result['ok']);
    }

    public function test_cannot_deactivate_user_who_logged_in_four_minutes_ago(): void
    {
        // 4 minutes < 5 minute threshold → still blocked
        $user = $this->makeUser([
            'last_login' => now()->subMinutes(4),
        ]);

        $result = $this->callCanDeactivate($user);

        $this->assertFalse($result['ok']);
    }

    public function test_active_session_blocker_message_mentions_5_minutes(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $result = $this->callCanDeactivate($user);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('5 minutes', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $user = $this->makeUser();

        $result = $this->callCanDeactivate($user);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $result = $this->callCanDeactivate($user);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $user = $this->makeUser();

        $result = $this->callCanDeactivate($user);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
