<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Product Audit Log tests — verifies AuditableMasterData trait writes
 * user_audit_log entries for every Product CRUD mutation.
 *
 * Same audit pattern as Branch/Warehouse: master_data_created/updated/
 * deleted/restored actions with details JSONB containing
 * table='products' + record_id.
 *
 * Phase 9 commit.
 */
class ProductAuditTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: count audit log entries for a given product + action.
     */
    private function auditEntriesFor(Product $product, ?string $action = null): \Illuminate\Support\Collection
    {
        $query = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", ['products'])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $product->id]);

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    // ====================================================================
    // CREATE → master_data_created audit entry
    // ====================================================================

    public function test_store_writes_created_audit_entry(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'AUDIT-PRD-C-01',
            'product_name' => 'Audit Create Test',
            'unit'         => 'Pcs',
        ]);

        $product = Product::where('product_code', 'AUDIT-PRD-C-01')->first();

        $entries = $this->auditEntriesFor($product, 'master_data_created');
        $this->assertCount(1, $entries, 'Expected 1 master_data_created audit entry');

        $entry = $entries->first();
        $this->assertNotNull($entry->user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
    }

    public function test_created_audit_entry_has_null_old_and_full_new_in_details(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'AUDIT-PRD-N-01',
            'product_name' => 'Audit New Field Test',
            'unit'         => 'Pcs',
        ]);

        $product = Product::where('product_code', 'AUDIT-PRD-N-01')->first();
        $entry = $this->auditEntriesFor($product, 'master_data_created')->first();

        $details = json_decode($entry->details, true);

        $this->assertEquals('products', $details['table']);
        $this->assertEquals($product->id, $details['record_id']);
        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-PRD-N-01', $details['new']['product_code']);
        $this->assertEquals('Audit New Field Test', $details['new']['product_name']);
    }

    public function test_created_audit_entry_captures_authenticated_user_id(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $this->post(route('admin.products.store'), [
            'product_code' => 'AUDIT-PRD-U-01',
            'product_name' => 'User Capture Test',
            'unit'         => 'Pcs',
        ]);

        $product = Product::where('product_code', 'AUDIT-PRD-U-01')->first();
        $entry = $this->auditEntriesFor($product, 'master_data_created')->first();

        $this->assertEquals($user->id, $entry->user_id);
    }

    // ====================================================================
    // UPDATE → master_data_updated audit entry with old + new diff
    // ====================================================================

    public function test_update_writes_updated_audit_entry(): void
    {
        $product = Product::factory()->create(['product_name' => 'Old Name']);

        $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'New Name',
            'unit'         => $product->unit,
            'is_active'    => true,
        ]);

        $entries = $this->auditEntriesFor($product, 'master_data_updated');
        $this->assertCount(1, $entries, 'Expected 1 master_data_updated audit entry');
    }

    public function test_updated_audit_entry_captures_old_and_new_values(): void
    {
        $product = Product::factory()->create([
            'product_name' => 'Original Name',
            'sales_rate'   => null,
        ]);

        $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'Updated Name',
            'unit'         => $product->unit,
            'sales_rate'   => 99.99,
            'is_active'    => true,
        ]);

        $entry = $this->auditEntriesFor($product, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('Original Name', $details['old']['product_name']);
        $this->assertEquals('Updated Name', $details['new']['product_name']);
        $this->assertEquals('99.99', $details['new']['sales_rate']);
    }

    public function test_updated_audit_entry_only_includes_changed_fields_in_new(): void
    {
        $product = Product::factory()->create([
            'product_name' => 'Stable Name',
            'sales_rate'   => null,
        ]);

        $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'Stable Name', // unchanged
            'unit'         => $product->unit,
            'sales_rate'   => 12.34, // changed
            'is_active'    => true,
        ]);

        $entry = $this->auditEntriesFor($product, 'master_data_updated')->first();
        $details = json_decode($entry->details, true);

        // The AuditableMasterData trait uses getChanges() which returns only
        // changed attributes — so 'new' should contain 'sales_rate' but NOT
        // 'product_name' (since it wasn't changed).
        $this->assertArrayHasKey('sales_rate', $details['new']);
        $this->assertArrayNotHasKey('product_name', $details['new']);
    }

    public function test_update_with_no_changes_does_not_write_audit_entry(): void
    {
        $product = Product::factory()->create([
            'product_name' => 'Same Name',
            'sales_rate'   => 50.00,
        ]);

        // Submit the same values
        $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'Same Name',
            'unit'         => $product->unit,
            'sales_rate'   => 50.00,
            'is_active'    => true,
        ]);

        // The trait only logs if wasChanged() is true.
        $entries = $this->auditEntriesFor($product, 'master_data_updated');
        $this->assertCount(0, $entries, 'No audit entry should be written when nothing changed');
    }

    // ====================================================================
    // DESTROY → master_data_deleted audit entry
    // ====================================================================

    public function test_destroy_writes_deleted_audit_entry(): void
    {
        $product = Product::factory()->create();

        $this->delete(route('admin.products.destroy', $product));

        $entries = $this->auditEntriesFor($product, 'master_data_deleted');
        $this->assertCount(1, $entries, 'Expected 1 master_data_deleted audit entry');
    }

    public function test_deleted_audit_entry_has_old_attributes_and_null_new(): void
    {
        $product = Product::factory()->create([
            'product_code' => 'AUDIT-PRD-DEL-01',
            'product_name' => 'Delete Audit Test',
        ]);

        $this->delete(route('admin.products.destroy', $product));

        $entry = $this->auditEntriesFor($product, 'master_data_deleted')->first();
        $details = json_decode($entry->details, true);

        $this->assertIsArray($details['old']);
        $this->assertNull($details['new']);
        $this->assertEquals('AUDIT-PRD-DEL-01', $details['old']['product_code']);
        $this->assertEquals('Delete Audit Test', $details['old']['product_name']);
    }

    // ====================================================================
    // RESTORE → master_data_restored audit entry
    // ====================================================================

    public function test_restore_writes_restored_audit_entry(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->post(route('admin.products.restore', $product));

        $entries = $this->auditEntriesFor($product, 'master_data_restored');
        $this->assertCount(1, $entries, 'Expected 1 master_data_restored audit entry');
    }

    public function test_restored_audit_entry_has_null_old_and_full_new(): void
    {
        $product = Product::factory()->create(['product_code' => 'AUDIT-PRD-RST-01']);
        $product->delete();

        $this->post(route('admin.products.restore', $product));

        $entry = $this->auditEntriesFor($product, 'master_data_restored')->first();
        $details = json_decode($entry->details, true);

        $this->assertNull($details['old']);
        $this->assertIsArray($details['new']);
        $this->assertEquals('AUDIT-PRD-RST-01', $details['new']['product_code']);
    }

    // ====================================================================
    // AUDIT VIEWER (GET /admin/products/audit)
    // ====================================================================

    public function test_audit_page_displays_audit_entries(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('admin.products.audit'));

        $response->assertOk();
        $response->assertViewIs('admin.products.audit');
        $response->assertViewHas('auditLogs');
    }

    public function test_audit_page_paginates_results(): void
    {
        // Generate multiple products with audit entries
        Product::factory()->count(60)->create();

        $response = $this->get(route('admin.products.audit'));

        $response->assertOk();
        $auditLogs = $response->viewData('auditLogs');
        $this->assertLessThanOrEqual(50, $auditLogs->count(), 'Audit page should paginate at 50 entries');
    }

    public function test_audit_page_filters_to_products_table_only(): void
    {
        // Create an audit entry for a different table
        DB::table('user_audit_log')->insert([
            'user_id'    => null,
            'action'     => 'master_data_created',
            'details'    => json_encode(['table' => 'branches', 'record_id' => 999, 'old' => null, 'new' => ['foo' => 'bar']]),
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        // Also create a product with its own audit entry to ensure the
        // page returns non-empty results to assert against.
        Product::factory()->create();

        $response = $this->get(route('admin.products.audit'));

        $auditLogs = $response->viewData('auditLogs');
        $this->assertGreaterThan(0, $auditLogs->count(), 'Audit page should contain at least one product entry');
        $auditLogs->each(function ($log) {
            $details = json_decode($log->details, true);
            $this->assertEquals('products', $details['table'], 'Audit page should only show products table entries');
        });
    }

    public function test_audit_page_shows_performer_name_from_user_employee_join(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        // Use the HTTP store route so the audit entry is attributed to $user.
        $this->post(route('admin.products.store'), [
            'product_code' => 'AUDIT-PRD-PERF-01',
            'product_name' => 'Performer Name Test',
            'unit'         => 'Pcs',
        ]);
        $product = Product::where('product_code', 'AUDIT-PRD-PERF-01')->first();
        $this->assertNotNull($product);

        $response = $this->get(route('admin.products.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry for THIS product's creation (avoids matching
        // entries from prior tests where user_id may be null).
        $createdByUser = $auditLogs->first(function ($log) use ($product) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $product->id;
        });

        $this->assertNotNull($createdByUser, 'Audit page should contain a master_data_created entry for the new product');
        $this->assertEquals($user->id, $createdByUser->user_id);
        $this->assertEquals($user->employee->name, $createdByUser->performed_by_name);
    }

    public function test_audit_page_extracts_target_id_from_details_jsonb(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('admin.products.audit'));

        $auditLogs = $response->viewData('auditLogs');
        // Find the audit entry specifically for this product's creation.
        $entry = $auditLogs->first(function ($log) use ($product) {
            return $log->action === 'master_data_created'
                && (int) $log->target_id === $product->id;
        });

        $this->assertNotNull($entry, "Audit page should contain a master_data_created entry for product #{$product->id}");
        $this->assertEquals($product->id, (int) $entry->target_id);
    }

    // ====================================================================
    // AUDIT INVARIANT — every mutation produces exactly one audit entry
    // ====================================================================

    public function test_full_lifecycle_produces_audit_entries(): void
    {
        // 1. CREATE → 1 entry (master_data_created)
        $this->post(route('admin.products.store'), [
            'product_code' => 'LIFE-PRD-01',
            'product_name' => 'Lifecycle Test',
            'unit'         => 'Pcs',
        ]);
        $product = Product::where('product_code', 'LIFE-PRD-01')->first();
        $this->assertCount(1, $this->auditEntriesFor($product), 'After create: 1 audit entry');

        // 2. UPDATE → 1 entry (master_data_updated)
        $this->put(route('admin.products.update', $product), [
            'product_code' => 'LIFE-PRD-01',
            'product_name' => 'Lifecycle Test Updated',
            'unit'         => 'Pcs',
            'is_active'    => true,
        ]);
        $this->assertCount(2, $this->auditEntriesFor($product), 'After update: 2 audit entries');

        // 3. DESTROY → 2 entries (Phase 9 destroy() calls save() to set
        //    is_active=false + deleted_by, which fires 'updated'; then delete()
        //    fires 'deleted').
        $this->delete(route('admin.products.destroy', $product));
        $this->assertCount(4, $this->auditEntriesFor($product), 'After destroy: 4 audit entries (updated + deleted)');

        // 4. RESTORE → 3 entries:
        //    - master_data_updated (deleted_at cleared by restore())
        //    - master_data_restored (restore event)
        //    - master_data_updated (deleted_by=null from the subsequent save())
        $this->post(route('admin.products.restore', $product));
        $this->assertCount(7, $this->auditEntriesFor($product), 'After restore: 7 audit entries (updated + restored + updated)');
    }
}
