<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Diagnostic command for the Customer DataTables AJAX endpoint.
 *
 * When the customer index page shows "DataTables warning: table id=customerTable
 * - Ajax error", the actual server-side error is swallowed by DataTables'
 * generic error handler. This command reproduces the exact same query the
 * controller runs, so the real exception is printed to the terminal.
 *
 * Usage:
 *   php artisan customers:diagnose
 *
 * What it checks:
 *   1. Can we count customers? (RLS, schema, connection)
 *   2. Can we fetch the first 25 customers with eager-loaded relations?
 *   3. Can we JSON-serialize the result without errors?
 *   4. Are there rows with NULL created_at (which can break Carbon casts)?
 *   5. Are there orphaned branch_id / sales_person_id FK references?
 *   6. What does the raw PDO driver return for the search_vector column?
 *
 * Run this after `php artisan migrate` if the customer index page is broken.
 */
class CustomersDiagnose extends Command
{
    protected $signature = 'customers:diagnose
                            {--branch= : Override session branch_id for the diagnostic}
                            {--admin : Bypass RLS (set app.is_admin = true)}
                            {--limit=25 : Number of rows to fetch}';

    protected $description = 'Diagnose why the customer DataTables AJAX endpoint fails';

    public function handle(): int
    {
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('  Customer DataTables Diagnostic');
        $this->info('════════════════════════════════════════════════════════════');
        $this->newLine();

        // ── 1. Set RLS context (mimic SetAppBranchId middleware) ──
        $branchId = (int) ($this->option('branch') ?: 0);
        $isAdmin  = $this->option('admin') ? 'true' : 'false';

        try {
            DB::statement("SET app.branch_id = ?", [$branchId]);
            DB::statement("SET app.is_admin = ?", [$isAdmin]);
            $this->line("  ✓ RLS context set: app.branch_id={$branchId}, app.is_admin={$isAdmin}");
        } catch (\Throwable $e) {
            $this->error("  ✗ Failed to set RLS context: " . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // ── 2. Check table structure ──
        $this->info('[1/6] Table structure check');
        $columns = DB::select("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name = 'customers'
            ORDER BY ordinal_position
        ");
        $this->table(['Column', 'Type', 'Nullable', 'Default'], array_map(function ($c) {
            return [$c->column_name, $c->data_type, $c->is_nullable, $c->column_default];
        }, $columns));
        $this->newLine();

        // ── 3. Count customers (RLS visible) ──
        $this->info('[2/6] Count customers (with current RLS context)');
        try {
            $visible = DB::selectOne("SELECT count(*) AS n FROM customers WHERE deleted_at IS NULL");
            $total   = DB::selectOne("SELECT count(*) AS n FROM customers");
            $this->line("  ✓ Visible (deleted_at IS NULL): {$visible->n}");
            $this->line("  ✓ Total (including soft-deleted): {$total->n}");
            if ($visible->n == 0 && $total->n > 0) {
                $this->warn("  ! All customers are soft-deleted OR RLS is hiding them.");
                $this->line("    Try: php artisan customers:diagnose --admin");
            }
        } catch (\Throwable $e) {
            $this->error("  ✗ Count query failed: " . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // ── 4. Eloquent fetch + eager load (mimic controller) ──
        $limit = (int) $this->option('limit');
        $this->info("[3/6] Eloquent fetch with eager-load (limit={$limit})");
        try {
            $items = Customer::with(['branch', 'salesPerson'])
                ->whereNull('deleted_at')
                ->limit($limit)
                ->get();
            $this->line("  ✓ Fetched " . $items->count() . " customer models");
        } catch (\Throwable $e) {
            $this->error("  ✗ Eloquent fetch failed: " . $e->getMessage());
            $this->line("  Exception class: " . get_class($e));
            $this->line("  File: " . $e->getFile() . ":" . $e->getLine());
            return 1;
        }
        $this->newLine();

        // ── 5. JSON serialization ──
        $this->info('[4/6] JSON serialization (the most common failure point)');
        try {
            $payload = [
                'draw'            => 1,
                'recordsTotal'    => $items->count(),
                'recordsFiltered' => $items->count(),
                'data'            => $items,
            ];
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->line("  ✓ JSON encoded successfully (" . strlen($json) . " bytes)");
        } catch (\JsonException $e) {
            $this->error("  ✗ JSON encoding failed: " . $e->getMessage());

            // Find which row is the culprit
            $this->line("  Probing each customer to find the bad row...");
            foreach ($items as $i => $c) {
                try {
                    json_encode($c, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (\JsonException $je) {
                    $this->error("  ✗ Row #{$i} (id={$c->id}, code={$c->customer_code}) fails: " . $je->getMessage());
                    $this->line("    Attributes dump:");
                    foreach ($c->getAttributes() as $k => $v) {
                        $type = gettype($v);
                        $display = is_string($v) ? mb_substr($v, 0, 60) : (is_scalar($v) ? var_export($v, true) : "($type)");
                        $this->line("      {$k} ({$type}): {$display}");
                    }
                    break;
                }
            }
            return 1;
        }
        $this->newLine();

        // ── 6. Data integrity checks ──
        $this->info('[5/6] Data integrity checks');

        // NULL created_at?
        $nullCreated = DB::selectOne("SELECT count(*) AS n FROM customers WHERE created_at IS NULL");
        $this->line("  • Customers with NULL created_at: {$nullCreated->n}");

        // Orphaned branch_id?
        $orphanBranches = DB::selectOne("
            SELECT count(*) AS n FROM customers c
            WHERE c.branch_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = c.branch_id)
        ");
        $this->line("  • Customers with orphaned branch_id (FK mismatch): {$orphanBranches->n}");

        // Orphaned sales_person_id?
        $orphanSales = DB::selectOne("
            SELECT count(*) AS n FROM customers c
            WHERE c.sales_person_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.id = c.sales_person_id)
        ");
        $this->line("  • Customers with orphaned sales_person_id: {$orphanSales->n}");

        // Duplicate customer_codes?
        $dupes = DB::select("
            SELECT customer_code, count(*) AS n
            FROM customers
            GROUP BY customer_code
            HAVING count(*) > 1
            LIMIT 5
        ");
        if (!empty($dupes)) {
            $this->warn("  ! Duplicate customer_code values found:");
            foreach ($dupes as $d) {
                $this->line("      {$d->customer_code}: {$d->n} rows");
            }
        } else {
            $this->line("  • Duplicate customer_code values: none");
        }
        $this->newLine();

        // ── 7. search_vector column type probe ──
        $this->info('[6/6] search_vector column probe');
        $svCol = DB::selectOne("
            SELECT data_type, udt_name
            FROM information_schema.columns
            WHERE table_name = 'customers' AND column_name = 'search_vector'
        ");
        if ($svCol) {
            $this->line("  • search_vector type: {$svCol->data_type} ({$svCol->udt_name})");
            $sample = DB::selectOne("SELECT id, search_vector::text AS sv FROM customers WHERE deleted_at IS NULL LIMIT 1");
            if ($sample) {
                $this->line("  • Sample (id={$sample->id}): " . mb_substr($sample->sv ?? 'NULL', 0, 80));
            }
        } else {
            $this->line("  • search_vector column: NOT PRESENT (full-text search will use ILIKE fallback)");
        }
        $this->newLine();

        // ── Summary ──
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('  Diagnostic complete');
        $this->info('════════════════════════════════════════════════════════════');
        $this->line("  If the customer index page still shows 'Ajax error':");
        $this->line("    1. Check storage/logs/laravel.log — the controller now logs");
        $this->line("       the full exception with SQL + bindings.");
        $this->line("    2. If RLS is hiding rows (count=0 with --admin flag missing),");
        $this->line("       log in as an admin/superadmin user.");
        $this->line("    3. If JSON encoding failed above, the bad row is shown — fix");
        $this->line("       the data directly with UPDATE customers SET ... WHERE id=... ");
        $this->newLine();

        return 0;
    }
}
