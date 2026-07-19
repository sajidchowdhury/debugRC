<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Phase 18 — API documentation page + api:token Artisan command tests.
 *
 * Covers:
 *   - GET /api/docs returns 200 + HTML.
 *   - The docs page lists all 14 /api/v1/* endpoints.
 *   - The docs page contains a Bearer-token input for the Try-It panel.
 *   - `php artisan api:token {username}` issues a working token.
 *   - `php artisan api:token {username}` fails with exit code 1 for unknown users.
 */
class ApiDocTest extends TestCase
{
    use BuildsRoleUsers;

    // ====================================================================
    // DOCS PAGE
    // ====================================================================

    public function test_api_docs_page_returns_200(): void
    {
        $response = $this->get('/api/docs');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_api_docs_page_contains_all_endpoints(): void
    {
        $response = $this->get('/api/docs');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertNotEmpty($body, 'Docs page body is empty.');

        // Each of the 14 endpoints must appear in the page (method + path).
        $endpoints = [
            ['GET',    '/branches'],
            ['GET',    '/branches/{id}'],
            ['POST',   '/branches'],
            ['PUT',    '/branches/{id}'],
            ['DELETE', '/branches/{id}'],
            ['GET',    '/dashboard'],
            ['GET',    '/dashboard/sales-trend'],
            ['GET',    '/dashboard/top-products'],
            ['GET',    '/lookups/branches'],
            ['GET',    '/lookups/warehouses'],
            ['GET',    '/lookups/products'],
            ['GET',    '/lookups/customers'],
            ['GET',    '/lookups/suppliers'],
            ['GET',    '/lookups/ledgers'],
        ];

        foreach ($endpoints as [$method, $path]) {
            $this->assertStringContainsString(
                $method,
                $body,
                "Docs page missing HTTP method '{$method}' for endpoint '{$path}'.",
            );
            $this->assertStringContainsString(
                $path,
                $body,
                "Docs page missing path '{$path}' for method '{$method}'.",
            );
        }

        // The total count of 14 endpoints must be displayed in the heading.
        $this->assertStringContainsString('Endpoints (14)', $body);
    }

    public function test_api_docs_page_has_bearer_token_input(): void
    {
        $response = $this->get('/api/docs');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertNotEmpty($body);

        // The Try-It panel's Bearer token input field.
        $this->assertStringContainsString('id="bearerToken"', $body);
        $this->assertStringContainsString('type="password"', $body);

        // The Try-It panel must include the "Try it" button text.
        $this->assertStringContainsString('Try it', $body);
    }

    // ====================================================================
    // api:token COMMAND
    // ====================================================================

    public function test_generate_api_token_command_works(): void
    {
        // Create a known user (lowercase username, like the app does).
        $user = $this->makeRoleUser('manager');
        $username = $user->username;

        // Sanity: api_token should be null (or different) before.
        $user->refresh();
        $this->assertNull($user->api_token);

        // Run the command via Artisan::call() (as required by the task).
        $exitCode = Artisan::call('api:token', ['username' => $username]);
        $this->assertSame(0, $exitCode, 'api:token command should exit 0 on success.');

        // The output should contain the username + "API Token:" line.
        $output = Artisan::output();
        $this->assertStringContainsString($username, $output);
        $this->assertStringContainsString('API Token:', $output);

        // Pull the plain token out of the output (the line starts with
        // "  API Token:" — we just grab everything after that, trimmed).
        $this->assertMatchesRegularExpression(
            '/API Token:\s+(?P<token>[A-Za-z0-9]{64})/',
            $output,
            'Token in command output should be a 64-char alphanumeric string.',
        );
        preg_match('/API Token:\s+(?P<token>[A-Za-z0-9]{64})/', $output, $m);
        $plainToken = $m['token'];

        // The user row should now have a SHA-256 hash of the plain token.
        $user->refresh();
        $this->assertSame(hash('sha256', $plainToken), $user->api_token);

        // The plain token should actually authenticate the user via the API.
        $this->withHeaders(['Authorization' => 'Bearer ' . $plainToken])
            ->getJson('/api/v1/branches')
            ->assertOk();
    }

    public function test_generate_api_token_command_works_with_role_option(): void
    {
        $user = $this->makeRoleUser('salesman');
        $this->assertSame('salesman', $user->getRole());

        $exitCode = Artisan::call('api:token', [
            'username' => $user->username,
            '--role'   => 'manager',
        ]);
        $this->assertSame(0, $exitCode);

        $user->refresh();
        $employee = $user->employee;
        $this->assertNotNull($employee);
        $employee->refresh();
        $this->assertSame('manager', $employee->role);
    }

    public function test_generate_api_token_command_fails_for_unknown_user(): void
    {
        $exitCode = Artisan::call('api:token', ['username' => 'no_such_user_xyz_123']);
        $this->assertSame(1, $exitCode, 'api:token should exit 1 when the user is not found.');

        $output = Artisan::output();
        $this->assertStringContainsString('not found', $output);
    }

    public function test_generate_api_token_command_is_case_insensitive(): void
    {
        $user = $this->makeRoleUser('manager');
        $upperUsername = strtoupper($user->username);

        $exitCode = Artisan::call('api:token', ['username' => $upperUsername]);
        $this->assertSame(0, $exitCode, 'api:token should match the username case-insensitively.');

        $user->refresh();
        $this->assertNotNull($user->api_token);
    }
}
