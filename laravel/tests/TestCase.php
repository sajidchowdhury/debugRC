<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Base test case for RC_ERP v2 — Branch Phase 7 testing foundation.
 *
 * Design decisions:
 *
 * 1. **DatabaseTransactions** is used instead of RefreshDatabase because the
 *    project's baseline migration (`2025_01_01_000001_create_rcerp_schema.php`)
 *    executes large raw PostgreSQL SQL files which are slow to replay per-test.
 *    Transactions roll back after every test, leaving the dev DB pristine.
 *
 * 2. The legacy session bridge middleware (`SyncLegacySession`) and the
 *    `CheckCredentialVersion` middleware depend on Redis (legacy PHP session
 *    store). In the test environment we disable them so tests can run without
 *    a Redis dependency. `CheckSystemPolicy` is harmless (DB-backed) but we
 *    stub the policy service to a no-op so investigation-mode scopes never
 *    interfere with master-data queries.
 *
 * 3. The `role` middleware alias is preserved so RBAC tests exercise the real
 *    `EnsureRole` middleware.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Boots the application and prepares the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Remove middleware that depends on Redis, external services, or
        // investigation-mode behavior. We keep the `role` alias so RBAC
        // tests exercise EnsureRole directly.
        $this->withoutMiddleware([
            \App\Http\Middleware\SyncLegacySession::class,
            \App\Http\Middleware\CheckCredentialVersion::class,
            \App\Http\Middleware\CheckSystemPolicy::class,
            // Laravel 12 CSRF guard. Tests don't issue real browser tokens,
            // so POST/PUT/DELETE in feature tests would 419 without this.
            // (Previous behaviour was masked by the BlockWritesDuringInvestigation
            // 500 crash on missing `system_policy_mode` binding — now that
            // middleware fails-open, CSRF is the next gate the request hits.)
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        // Ensure tests always run as if on the web guard.
        $this->withSession(['credential_version' => '1']);
    }

    /**
     * Creates the application.
     *
     * Required by Illuminate\Foundation\Testing\TestCase contract.
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
