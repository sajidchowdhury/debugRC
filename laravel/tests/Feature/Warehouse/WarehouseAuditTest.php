<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Warehouse Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Warehouse CRUD mutation.
 *
 * Same audit pattern as Branch: master_data_created/updated/deleted/restored
 * actions with details JSONB containing table='warehouses' + record_id.
 */
class WarehouseAuditTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function auditEntriesFor(Warehouse $warehouse, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['warehouses'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $warehouse->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function test_store_writes_created_audit_entry(): void
    {
        $branch = Branch::factory()->create();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'AUDIT-WH-C-01',
            'warehouse_name' => 'Audit Create Test',
            'branch_id'      => $branch->id,
        ]);

        $warehouse = Warehouse::where('warehouse_code', 'AUDIT-WH-C-01')->first();
        $entries = $this->auditEntriesFor($warehouse, 'master_data_created');
        $this->assertCount(1, $entries);
    }

    public function test_created_audit_entry_has_correct_details_payload(): void
    {
        $branch = Branch::factory()->create();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'AUDIT-WH-D-01',
            'warehouse_name' => 'Audit Details Test',
            'branch_id'      => $branch->id,
        ]);

        $warehouse = Warehouse::where('warehouse_code', 'AUDIT-WH-D-01')->first();
        $entry = $this->auditEntriesFor($warehouse, 'master_data_created')->first();
        $details = json_decode($entry->details, true);

        $this->assertEquals('warehouses', $details['table']);
        $this->assertEquals($warehouse->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertEquals('AUDIT-WH-D-01', $details['new']['warehouse_code']);
    }

    public function test_update_writes_updated_audit_entry(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => $warehouse->warehouse_code,
            'warehouse_name' => 'Updated Name',
            'branch_id'      => $branch->id,
            'is_active'      => true,
        ]);

        $entries = $this->auditEntriesFor($warehouse, 'master_data_updated');
        $this->assertCount(1, $entries);
    }

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->delete(route('admin.warehouses.destroy', $warehouse));

        $entries = $this->auditEntriesFor($warehouse, 'master_data_deleted');
        $this->assertCount(1, $entries);
    }

    public function test_restore_writes_restored_audit_entry(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $warehouse->delete();

        $this->post(route('admin.warehouses.restore', $warehouse));

        $entries = $this->auditEntriesFor($warehouse, 'master_data_restored');
        $this->assertCount(1, $entries);
    }

    public function test_audit_page_displays_warehouse_entries_only(): void
    {
        $branch = Branch::factory()->create();
        Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.warehouses.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.warehouses.audit');
        $auditLogs = $response->viewData('auditLogs');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('warehouses', $details['table']);
        });
    }

    public function test_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);
        $branch = Branch::factory()->create();

        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'AUDIT-WH-U-01',
            'warehouse_name' => 'User Capture Test',
            'branch_id'      => $branch->id,
        ]);

        $warehouse = Warehouse::where('warehouse_code', 'AUDIT-WH-U-01')->first();
        $entry = $this->auditEntriesFor($warehouse, 'master_data_created')->first();
        $this->assertEquals($user->id, $entry->user_id);
    }
}
