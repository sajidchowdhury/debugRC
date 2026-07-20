<?php

namespace Tests\Feature\Audit;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Global Audit Log Viewer tests — Phase 20-AUDIT-HEALTH (Task 1).
 *
 * Covers the cross-module audit viewer at /admin/audit which aggregates
 * `user_audit_log` entries from all master-data modules:
 *
 *   GET  /admin/audit           → index  (filterable, paginated)
 *   GET  /admin/audit/export    → CSV export (respects filters)
 *   GET  /admin/audit/{id}      → single entry detail with JSON diff
 *
 * Filters covered:
 *   - table  (branches, warehouses, products, …)
 *   - action (master_data_created/updated/deleted/restored)
 *   - user_id (who performed the action)
 *   - date range (from / to)
 *   - record_id (target record)
 *   - free-text search in details JSON
 */
class GlobalAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: insert a synthetic audit log row directly.
     */
    private function insertAuditEntry(array $overrides = []): object
    {
        $id = DB::table('user_audit_log')->insertGetId(array_merge([
            'user_id'     => null,
            'action'      => 'master_data_created',
            'details'     => json_encode([
                'table'     => 'branches',
                'record_id' => 1,
                'old'       => null,
                'new'       => ['branch_name' => 'Test Branch'],
            ]),
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'PHPUnit',
            'created_at'  => now(),
        ], $overrides));

        return DB::table('user_audit_log')->where('id', $id)->first();
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_global_audit_index_returns_200(): void
    {
        $response = $this->get(route('admin.audit.index'));

        $response->assertOk();
        $response->assertViewIs('admin.audit.index');
        $response->assertViewHas(['auditLogs', 'filters', 'tables', 'actions', 'users']);
    }

    public function test_global_audit_index_shows_entries_from_all_modules(): void
    {
        // Generate audit entries for 3 different modules by creating real models.
        $branch    = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product   = Product::factory()->create();

        $response = $this->get(route('admin.audit.index'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $tables = collect($auditLogs->items())
            ->map(fn ($log) => json_decode($log->details, true)['table'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertContains('branches', $tables, 'Global audit should include branches entries');
        $this->assertContains('warehouses', $tables, 'Global audit should include warehouses entries');
        $this->assertContains('products', $tables, 'Global audit should include products entries');
    }

    public function test_global_audit_index_requires_admin_role(): void
    {
        // Salesman should be redirected by EnsureRole middleware.
        $this->actingAsRole('salesman')
            ->get(route('admin.audit.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ====================================================================
    // FILTER: by table
    // ====================================================================

    public function test_global_audit_filter_by_table(): void
    {
        // Plant a branches-table entry and a warehouses-table entry.
        $this->insertAuditEntry([
            'action' => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 1, 'old' => null, 'new' => ['branch_name' => 'B1']]),
        ]);
        $this->insertAuditEntry([
            'action' => 'master_data_created',
            'details' => json_encode(['table' => 'warehouses', 'record_id' => 2, 'old' => null, 'new' => ['warehouse_name' => 'W1']]),
        ]);

        $response = $this->get(route('admin.audit.index', ['table' => 'branches']));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('branches', $details['table'], 'Filter by table should only return branches entries');
        });
    }

    // ====================================================================
    // FILTER: by action
    // ====================================================================

    public function test_global_audit_filter_by_action(): void
    {
        $this->insertAuditEntry([
            'action' => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 10, 'old' => null, 'new' => ['x' => 1]]),
        ]);
        $this->insertAuditEntry([
            'action' => 'master_data_deleted',
            'details' => json_encode(['table' => 'branches', 'record_id' => 11, 'old' => ['x' => 1], 'new' => null]),
        ]);

        $response = $this->get(route('admin.audit.index', ['action' => 'master_data_deleted']));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $auditLogs->each(function ($log) {
            $this->assertEquals('master_data_deleted', $log->action, 'Filter by action should only return deleted entries');
        });
    }

    // ====================================================================
    // FILTER: by user
    // ====================================================================

    public function test_global_audit_filter_by_user(): void
    {
        $userA = $this->makeRoleUser('admin');
        $userB = $this->makeRoleUser('admin');

        $this->insertAuditEntry([
            'user_id' => $userA->id,
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 20, 'old' => null, 'new' => ['x' => 1]]),
        ]);
        $this->insertAuditEntry([
            'user_id' => $userB->id,
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 21, 'old' => null, 'new' => ['x' => 2]]),
        ]);

        $response = $this->get(route('admin.audit.index', ['user_id' => $userA->id]));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $auditLogs->each(function ($log) use ($userA) {
            $this->assertEquals($userA->id, $log->user_id, 'Filter by user should only return that user\'s entries');
        });
    }

    // ====================================================================
    // FILTER: by date range
    // ====================================================================

    public function test_global_audit_filter_by_date_range(): void
    {
        // Entry dated 3 days ago.
        $old = $this->insertAuditEntry([
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 30, 'old' => null, 'new' => ['x' => 1]]),
            'created_at' => now()->subDays(3),
        ]);
        // Entry dated today.
        $recent = $this->insertAuditEntry([
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 31, 'old' => null, 'new' => ['x' => 2]]),
            'created_at' => now(),
        ]);

        $from = now()->subDay()->format('Y-m-d');
        $to   = now()->addDay()->format('Y-m-d');

        $response = $this->get(route('admin.audit.index', ['from' => $from, 'to' => $to]));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $ids = $auditLogs->pluck('id')->all();
        $this->assertContains($recent->id, $ids, 'Recent entry should be included in the date range');
        $this->assertNotContains($old->id, $ids, 'Old entry (3 days ago) should be excluded from the date range');
    }

    // ====================================================================
    // FILTER: search in details JSON
    // ====================================================================

    public function test_global_audit_search_in_details(): void
    {
        $needle = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 40, 'old' => null, 'new' => ['branch_name' => 'UNIQUENEEDLE12345']]),
        ]);
        $other = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 41, 'old' => null, 'new' => ['branch_name' => 'Some other value']]),
        ]);

        $response = $this->get(route('admin.audit.index', ['search' => 'UNIQUENEEDLE12345']));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');

        $ids = $auditLogs->pluck('id')->all();
        $this->assertContains($needle->id, $ids, 'Search should match the needle entry');
        $this->assertNotContains($other->id, $ids, 'Search should NOT match the unrelated entry');
    }

    // ====================================================================
    // SHOW (detail view with JSON diff)
    // ====================================================================

    public function test_global_audit_show_detail_returns_200(): void
    {
        $entry = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 50, 'old' => null, 'new' => ['branch_name' => 'Detail Test']]),
        ]);

        $response = $this->get(route('admin.audit.show', $entry->id));

        $response->assertOk();
        $response->assertViewIs('admin.audit.show');
        $response->assertViewHas(['entry', 'details', 'diff']);
    }

    public function test_global_audit_show_detail_displays_json_diff(): void
    {
        // Insert an update entry with both old and new values so the diff is non-trivial.
        $entry = $this->insertAuditEntry([
            'action'  => 'master_data_updated',
            'details' => json_encode([
                'table'     => 'branches',
                'record_id' => 60,
                'old'       => ['branch_name' => 'Old Name', 'phone' => '111'],
                'new'       => ['branch_name' => 'New Name', 'phone' => '222'],
            ]),
        ]);

        $response = $this->get(route('admin.audit.show', $entry->id));

        $response->assertOk();
        $diff = $response->viewData('diff');

        // The diff should include both branch_name and phone, both marked as changed.
        $this->assertIsArray($diff);

        $byField = collect($diff)->keyBy('field');
        $this->assertTrue($byField->has('branch_name'), 'Diff should include branch_name');
        $this->assertTrue($byField->has('phone'), 'Diff should include phone');

        $this->assertEquals('Old Name', $byField->get('branch_name')['old']);
        $this->assertEquals('New Name', $byField->get('branch_name')['new']);
        $this->assertEquals('changed', $byField->get('branch_name')['state']);

        $this->assertEquals('111', $byField->get('phone')['old']);
        $this->assertEquals('222', $byField->get('phone')['new']);
        $this->assertEquals('changed', $byField->get('phone')['state']);

        // The HTML response should contain the color-coded old/new values.
        $response->assertSee('New Name', false);
        $response->assertSee('Old Name', false);
    }

    // ====================================================================
    // EXPORT (CSV)
    // ====================================================================

    public function test_global_audit_export_returns_csv(): void
    {
        $entry = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 70, 'old' => null, 'new' => ['branch_name' => 'Export Test']]),
        ]);

        $response = $this->get(route('admin.audit.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);

        $content = $response->streamedContent();

        // UTF-8 BOM should be present.
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3), 'CSV should start with a UTF-8 BOM.');

        // Header row — fputcsv auto-quotes headers containing spaces. Check for
        // the unquoted forms (they all appear as substrings in the quoted output).
        $headerLine = strtok(substr($content, 3), "\n");
        foreach (['ID', 'Timestamp', 'User ID', 'Performer', 'Action', 'Table', 'Record ID', 'IP Address', 'User Agent', 'Summary'] as $expectedHeader) {
            $this->assertStringContainsString($expectedHeader, $headerLine, "CSV header should contain \"{$expectedHeader}\"");
        }

        // The seeded entry should appear in the CSV.
        $this->assertStringContainsString('Export Test', $content);
        $this->assertStringContainsString('master_data_created', $content);
    }

    public function test_global_audit_export_respects_filters(): void
    {
        // Entry to INCLUDE — table=branches, action=master_data_created.
        $included = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'branches', 'record_id' => 80, 'old' => null, 'new' => ['branch_name' => 'INCLUDE_ME']]),
        ]);

        // Entry to EXCLUDE — table=warehouses (filter asks for branches only).
        $excluded = $this->insertAuditEntry([
            'action'  => 'master_data_created',
            'details' => json_encode(['table' => 'warehouses', 'record_id' => 81, 'old' => null, 'new' => ['warehouse_name' => 'EXCLUDE_ME']]),
        ]);

        $response = $this->get(route('admin.audit.export', ['table' => 'branches']));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('INCLUDE_ME', $content, 'Export with table=branches filter should include the branches entry');
        $this->assertStringNotContainsString('EXCLUDE_ME', $content, 'Export with table=branches filter should NOT include the warehouses entry');
    }
}
