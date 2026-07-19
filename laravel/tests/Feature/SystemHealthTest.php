<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * System Health Dashboard tests — Phase 20-AUDIT-HEALTH (Task 2).
 *
 * Covers /admin/system-health which aggregates infrastructure + application
 * health metrics into a single admin dashboard:
 *
 *   GET /admin/system-health → SystemHealthController@index
 *
 * The dashboard must:
 *   - Return 200 for an admin user
 *   - Deny non-admin roles (redirect to dashboard via EnsureRole middleware)
 *   - Display database status (connection, table count, rows, size)
 *   - Display Redis status (connection, memory, clients, hit ratio)
 *   - Display module stats for the 9 master-data modules
 *   - Display recent activity (last 10 audit log entries + last 10 logins)
 */
class SystemHealthTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    public function test_system_health_index_returns_200(): void
    {
        $response = $this->get(route('admin.system-health.index'));

        $response->assertOk();
        $response->assertViewIs('admin.system-health.index');
        $response->assertViewHas([
            'database', 'redis', 'application', 'modules',
            'recentAudit', 'recentLogins', 'testSuite',
            'queue', 'cache',
        ]);
    }

    public function test_system_health_requires_admin_role(): void
    {
        // Salesman should be redirected by EnsureRole middleware.
        $this->actingAsRole('salesman')
            ->get(route('admin.system-health.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_system_health_shows_database_status(): void
    {
        $response = $this->get(route('admin.system-health.index'));

        $response->assertOk();
        $database = $response->viewData('database');

        // The test DB must be reachable for the test suite itself to work,
        // so we expect a healthy connection here.
        $this->assertIsArray($database);
        $this->assertArrayHasKey('status', $database);
        $this->assertArrayHasKey('connected', $database);
        $this->assertArrayHasKey('table_count', $database);
        $this->assertArrayHasKey('total_rows', $database);
        $this->assertArrayHasKey('db_size', $database);

        $this->assertTrue($database['connected'], 'Database should be connected during tests.');
        $this->assertSame('healthy', $database['status']);
        $this->assertGreaterThan(0, $database['table_count'], 'Test DB should have tables.');

        // The view should also render the database card.
        $response->assertSee('Database', false);
        $response->assertSee('Connected', false);
    }

    public function test_system_health_shows_redis_status(): void
    {
        $response = $this->get(route('admin.system-health.index'));

        $response->assertOk();
        $redis = $response->viewData('redis');

        $this->assertIsArray($redis);
        $this->assertArrayHasKey('status', $redis);
        $this->assertArrayHasKey('connected', $redis);

        // In the test env, PREDIS_DISABLED=true, so the controller should
        // gracefully report a degraded status (not 500).
        // If the test env ever flips to enabling Redis, we just verify the
        // connection flag is a boolean.
        $this->assertIsBool($redis['connected']);

        // The view should also render the Redis card.
        $response->assertSee('Redis', false);
    }

    public function test_system_health_shows_module_stats(): void
    {
        // Seed at least one branch so the modules grid has non-zero data.
        Branch::factory()->create();

        $response = $this->get(route('admin.system-health.index'));

        $response->assertOk();
        $modules = $response->viewData('modules');

        $this->assertIsArray($modules);
        $this->assertNotEmpty($modules, 'Module health grid should have entries');

        // Each module row should have the required keys.
        foreach ($modules as $module) {
            $this->assertArrayHasKey('table', $module);
            $this->assertArrayHasKey('label', $module);
            $this->assertArrayHasKey('active', $module);
            $this->assertArrayHasKey('inactive', $module);
            $this->assertArrayHasKey('total', $module);
            $this->assertArrayHasKey('status', $module);
        }

        // The branches module must appear and have at least one row.
        $branchesModule = collect($modules)->firstWhere('table', 'branches');
        $this->assertNotNull($branchesModule, 'Module grid should include the branches table');
        $this->assertGreaterThan(0, $branchesModule['total'], 'Branches module should have at least one row after seeding');

        // The view should render the module grid header.
        $response->assertSee('Master-data module health', false);
        $response->assertSee('Branches', false);
    }

    public function test_system_health_shows_recent_activity(): void
    {
        // Seed a master-data audit entry + a login event.
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 1, 'old' => null, 'new' => ['branch_name' => 'Recent Activity Test']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'login_success',
            'details'    => json_encode(['username' => 'someone']),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $response = $this->get(route('admin.system-health.index'));

        $response->assertOk();
        $recentAudit = $response->viewData('recentAudit');
        $recentLogins = $response->viewData('recentLogins');

        // Recent audit panel must include the master_data_created entry.
        $auditActions = $recentAudit->pluck('action')->all();
        $this->assertContains('master_data_created', $auditActions, 'Recent audit panel should include the master_data_created entry');

        // Recent logins panel must include the login_success entry.
        $loginActions = $recentLogins->pluck('action')->all();
        $this->assertContains('login_success', $loginActions, 'Recent logins panel should include the login_success entry');

        // The view should render both panel headers.
        $response->assertSee('Recent master-data activity', false);
        $response->assertSee('Recent login activity', false);
    }
}
