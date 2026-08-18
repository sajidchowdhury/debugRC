<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\Helpers\ResolvesActiveFiscalYear;
use Tests\TestCase;

/**
 * Phase 13 — Dashboard summary API tests.
 *
 * Covers:
 *   - GET /api/v1/dashboard             summary stats (counts + today's sales)
 *   - GET /api/v1/dashboard/sales-trend  last 7 days sales totals (gap-filled)
 *   - GET /api/v1/dashboard/top-products top 10 products by revenue (last 30d)
 *
 * Auth: all endpoints require a valid Bearer token.
 */
class DashboardApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;
    use ResolvesActiveFiscalYear;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush the dashboard cache so each test sees freshly seeded data
        // instead of stale cached results from a prior test/request.
        \Illuminate\Support\Facades\Cache::flush();
    }

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_sales_trend_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/sales-trend')->assertUnauthorized();
    }

    public function test_dashboard_top_products_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/top-products')->assertUnauthorized();
    }

    // ====================================================================
    // INDEX — summary stats
    // ====================================================================

    public function test_dashboard_returns_summary_stats_structure(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'counts' => [
                    'active_branches', 'active_warehouses', 'active_products',
                    'active_customers', 'active_suppliers', 'active_employees',
                ],
                'today' => ['date', 'invoice_count', 'total_sales', 'collection'],
            ],
        ]);
    }

    public function test_dashboard_counts_reflect_active_master_data(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        // Seed at least one of each.
        $branch     = Branch::factory()->create();
        $warehouse  = Warehouse::factory()->forBranch($branch->id)->create();
        $product    = Product::factory()->create();
        $customer   = Customer::factory()->create();
        $supplier   = Supplier::factory()->create();
        $employee   = Employee::factory()->forBranch($branch->id)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $counts = $response->json('data.counts');
        $this->assertGreaterThanOrEqual(1, $counts['active_branches']);
        $this->assertGreaterThanOrEqual(1, $counts['active_warehouses']);
        $this->assertGreaterThanOrEqual(1, $counts['active_products']);
        $this->assertGreaterThanOrEqual(1, $counts['active_customers']);
        $this->assertGreaterThanOrEqual(1, $counts['active_suppliers']);
        $this->assertGreaterThanOrEqual(1, $counts['active_employees']);
    }

    public function test_dashboard_today_block_reports_today_invoice_count_and_total(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        // Insert a single sales invoice for today.
        // G-294 (LOW-WAVE-2-B2): refactored from DB::table('sales_invoices')->insert([...])
        // to SalesInvoice::factory()->create([...]) so the test exercises the
        // real Eloquent model path (fillable guards + boot events: AuditableMasterData
        // logs to user_audit_log; ApplySystemPolicyScope + BranchScope global scopes).
        // `due_amount` is a GENERATED ALWAYS AS (total_amount - paid_amount) STORED
        // column — the factory omits it (an explicit value would raise SQLSTATE 428C9).
        $branch   = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        SalesInvoice::factory()
            ->forCustomerBranch($customer->id, $branch->id)
            ->create([
                'invoice_code' => 'INV-API-' . uniqid(),
                'invoice_date' => now()->toDateString(),
                'sub_total'    => 100,
                'total_amount' => 250.50,
                'paid_amount'  => 0,
                'status'       => 'confirmed',
            ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $today = $response->json('data.today');
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertGreaterThanOrEqual(1, $today['invoice_count']);
        $this->assertGreaterThanOrEqual(250.50, (float) $today['total_sales']);
    }

    // ====================================================================
    // SALES TREND
    // ====================================================================

    public function test_sales_trend_returns_7_entries_with_gap_fill(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard/sales-trend');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['range_days', 'start', 'end']]);

        $data = $response->json('data');
        $this->assertCount(7, $data);

        // Each entry should have the 3 expected keys.
        collect($data)->each(function ($entry) {
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('invoice_count', $entry);
            $this->assertArrayHasKey('total_sales', $entry);
        });

        // Dates should be in ascending order across the last 7 days.
        $dates = array_column($data, 'date');
        $this->assertSame(now()->subDays(6)->toDateString(), $dates[0]);
        $this->assertSame(now()->toDateString(), $dates[6]);
    }

    public function test_sales_trend_aggregates_today_invoice_totals(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        // G-294 (LOW-WAVE-2-B2): refactored DB::table insert → SalesInvoice::factory()
        // (see test_dashboard_today_block_reports_today_invoice_count_and_total above
        // for rationale). `due_amount` is GENERATED — omitted from the override array.
        $branch   = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        SalesInvoice::factory()
            ->forCustomerBranch($customer->id, $branch->id)
            ->create([
                'invoice_code' => 'INV-API-TREND-' . uniqid(),
                'invoice_date' => now()->toDateString(),
                'sub_total'    => 80,
                'total_amount' => 80,
                'paid_amount'  => 0,
                'status'       => 'confirmed',
            ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard/sales-trend');

        $response->assertOk();
        $data = collect($response->json('data'));
        $todayRow = $data->firstWhere('date', now()->toDateString());
        $this->assertNotNull($todayRow);
        $this->assertGreaterThanOrEqual(1, $todayRow['invoice_count']);
    }

    // ====================================================================
    // TOP PRODUCTS
    // ====================================================================

    public function test_top_products_returns_at_most_10_rows(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard/top-products');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['range_days', 'since']]);
        $data = $response->json('data');
        $this->assertLessThanOrEqual(10, count($data));
    }

    public function test_top_products_returns_products_with_sales_in_last_30d(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $branch   = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $product  = Product::factory()->create();

        // G-294 (LOW-WAVE-2-B2): refactored DB::table('sales_invoices')->insertGetId
        // → SalesInvoice::factory()->create() (exercises fillable + boot events).
        // `due_amount` is GENERATED (total_amount - paid_amount) — omitted.
        // The items row still uses DB::table() because SalesInvoiceItem has no
        // factory yet (documented as a follow-up; the cited gap is only the 3
        // sales_invoices insert sites).
        $invoice = SalesInvoice::factory()
            ->forCustomerBranch($customer->id, $branch->id)
            ->create([
                'invoice_code' => 'INV-API-TOP-' . uniqid(),
                'invoice_date' => now()->toDateString(),
                'sub_total'    => 200,
                'total_amount' => 200,
                'paid_amount'  => 0,
                'status'       => 'confirmed',
            ]);
        $invoiceId = $invoice->id;
        DB::table('sales_invoice_items')->insert([
            'sales_invoice_id' => $invoiceId,
            'product_id'       => $product->id,
            'qty'              => 4,
            'rate'             => 50,
            'condition_state'  => 'Good',
            // S12: sales_invoice_items is a fiscal-scoped child table
            // (config/fiscal.php: parent=['sales_invoices', 'sales_invoice_id',
            // 'invoice_date']) — NOT NULL after S1 FY-isolation. Parent
            // sales_invoices row already carries FY via SalesInvoiceFactory.
            'fiscal_year_id'   => $this->resolveActiveFiscalYearId(),
        ]);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/dashboard/top-products');

        $response->assertOk();
        $data = collect($response->json('data'));

        $row = $data->firstWhere('product_id', $product->id);
        $this->assertNotNull($row, 'Top products should include the product we just sold.');
        $this->assertSame(4.0, (float) $row['qty_sold']);
        $this->assertSame(200.0, (float) $row['revenue']);
    }
}
