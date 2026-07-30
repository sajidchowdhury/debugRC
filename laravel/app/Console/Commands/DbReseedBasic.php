<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Re-run all data-seeding migrations to restore basic data.
 *
 * WHY THIS EXISTS
 * ───────────────
 * The `db:make-empty` command truncates every table (preserving the schema)
 * but KEEPS the `migrations` table intact. That means `php artisan migrate`
 * thinks all migrations already ran and won't re-execute the data-seeding
 * ones — leaving the DB empty with no way back via normal migrate.
 *
 * This command fixes that by:
 *   1. Deleting the `migrations` table rows for a curated list of DATA-ONLY
 *      seed migrations (the ones that INSERT employees, users, customers,
 *      suppliers, products, branches, warehouses, banks, ledgers, menus, etc).
 *   2. Calling `php artisan migrate` — Laravel sees those migrations as
 *      "pending" and re-runs them in timestamp order.
 *   3. The migrations are idempotent (ON CONFLICT DO UPDATE / updateOrInsert),
 *      so re-running them is safe even if some data already exists.
 *
 * WHAT GETS RESTORED
 * ──────────────────
 *   • Default Chart of Accounts (33 ledger heads)
 *   • 5 additional legacy ledger heads
 *   • Menus (52) + user_menu_permissions for superadmins
 *   • Notification rules (4)
 *   • Branches (5) + Warehouses (22)
 *   • Employees (146) + Users (136)  ← from admin_employee.sql
 *   • Products (1230) + Categories (25) + Groups (2) + UOM (6)
 *   • Banks (31)
 *   • Suppliers (107)
 *   • Customers (2448)
 *
 * SOURCE FILES (must be present in /var/www/legacy/ inside the container)
 * ───────────────────────────────────────────────────────────────────────
 *   • osudlagb_remotecenter.sql  — legacy MySQL dump (7.1 MB)
 *   • admin_employee.sql         — admin + setup_employee extract (102 KB)
 *
 * USAGE
 * ─────
 *   docker exec rcerp_app php artisan db:reseed-basic --force
 *
 * PREREQUISITE
 * ────────────
 *   Run `db:make-empty --force` FIRST to ensure a clean slate.
 *   (This command also works on a partially-populated DB because all
 *   migrations are idempotent, but starting clean avoids confusion.)
 */
class DbReseedBasic extends Command
{
    /**
     * Curated list of DATA-ONLY seed migrations to re-run.
     *
     * These migrations only INSERT/UPSERT data — none of them call
     * Schema::create or Schema::table. They are safe to re-run on a
     * DB where the schema already exists.
     *
     * Order doesn't matter here — Laravel runs them in filename
     * (timestamp) order when `php artisan migrate` is invoked.
     */
    private const RESEED_MIGRATIONS = [
        '2025_01_05_000001_seed_default_chart_of_accounts',
        '2025_01_09_000003_seed_return_notification_rules',
        '2025_01_10_000001_seed_menus_from_legacy',
        '2026_07_30_000005_migrate_legacy_admin_and_employee_data',
        '2026_07_30_000006_make_e0001_superadmin_with_all_menus',
        '2026_07_30_000007_make_emp0001_superadmin_with_all_menus',
        '2026_07_30_000008_migrate_legacy_product_and_category_data',
        '2026_07_30_000009_migrate_legacy_bank_data',
        '2026_07_30_000010_migrate_legacy_supplier_data',
        '2026_07_30_000011_migrate_legacy_customer_data',
        '2026_07_30_000012_migrate_legacy_branch_and_warehouse_data',
        '2026_07_30_000013_add_missing_legacy_ledger_heads',
    ];

    /**
     * Tables to show before/after row counts for in the verification step.
     */
    private const VERIFY_TABLES = [
        'branches',
        'warehouses',
        'employees',
        'users',
        'customers',
        'suppliers',
        'products',
        'product_categories',
        'banks',
        'ledgers',
        'menus',
        'user_menu_permissions',
        'notification_rules',
    ];

    /**
     * Legacy SQL dumps that the migrations depend on.
     * Checked before re-running to give a clear early error.
     */
    private const REQUIRED_LEGACY_FILES = [
        'osudlagb_remotecenter.sql',
        'admin_employee.sql',
    ];

    protected $signature = 'db:reseed-basic
                            {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Re-run all data-seeding migrations to restore basic data (employees, users, customers, products, etc.) from the legacy SQL dumps.';

    public function handle(): int
    {
        echo "\n=== DB RESEED (BASIC DATA) ===\n\n";

        // ── Step 0: Verify legacy source files exist ──
        $this->verifyLegacyFiles();

        // ── Step 1: Capture BEFORE row counts ──
        $before = $this->captureRowCounts();

        // ── Step 2: Show what will be re-run ──
        $this->listMigrations();

        // ── Step 3: Confirm ──
        if (!$this->option('force')) {
            if (!$this->confirm('Re-run these ' . count(self::RESEED_MIGRATIONS) . ' data-seeding migrations now?')) {
                echo "\n  Aborted. No changes made.\n";
                return self::SUCCESS;
            }
        }

        // ── Step 4: Delete migration records so Laravel treats them as "pending" ──
        $deleted = $this->deleteMigrationRecords();

        // ── Step 5: Run php artisan migrate ──
        echo "\nRunning php artisan migrate...\n";
        echo str_repeat('─', 60) . "\n";

        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output   = Artisan::output();

        echo $output;
        echo str_repeat('─', 60) . "\n";

        if ($exitCode !== 0) {
            echo "\n  ✗ artisan migrate exited with code {$exitCode}.\n";
            echo "  Some migrations may have failed. Check the output above.\n";
        } else {
            echo "\n  ✓ artisan migrate completed.\n";
        }

        // ── Step 6: Verify ──
        echo "\n=== VERIFICATION ===\n\n";
        $after = $this->captureRowCounts();

        echo "Row counts (before → after):\n";
        $allGood = true;
        foreach (self::VERIFY_TABLES as $table) {
            $b = $before[$table] ?? '?';
            $a = $after[$table]  ?? '?';
            $marker = ' ';
            if (is_int($b) && is_int($a) && $a === 0 && $b === 0) {
                $marker = '⚠';
                $allGood = false;
            } elseif (is_int($a) && $a > 0) {
                $marker = '✓';
            }
            printf("  %s %-28s %5s → %5s\n", $marker, $table, $b, $a);
        }

        echo "\n";
        if ($allGood && ($after['users'] ?? 0) > 0) {
            echo "✓ Restore complete. You should now be able to log in.\n";
        } else {
            echo "⚠ Some tables appear empty. Check the migration output above for errors.\n";
        }

        echo "\nDone.\n";
        return self::SUCCESS;
    }

    // ===================================================================
    // Step 0: Verify legacy source files
    // ===================================================================

    private function verifyLegacyFiles(): void
    {
        echo "Checking legacy source files...\n";

        $searchPaths = [
            '/var/www/legacy',
            dirname(base_path()) . '/legacy',
            base_path('legacy'),
            database_path('legacy'),
            database_path('sql'),
        ];

        $allFound = true;
        foreach (self::REQUIRED_LEGACY_FILES as $filename) {
            $found = false;
            foreach ($searchPaths as $dir) {
                $path = rtrim($dir, '/') . '/' . $filename;
                if (is_file($path)) {
                    $size = $this->formatBytes(filesize($path));
                    echo "  ✓ {$filename} ({$size}) — found at {$path}\n";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo "  ✗ {$filename} — NOT FOUND\n";
                echo "    Searched in:\n";
                foreach ($searchPaths as $dir) {
                    echo "      - " . rtrim($dir, '/') . "/\n";
                }
                $allFound = false;
            }
        }

        if (!$allFound) {
            echo "\n  ✗ Cannot proceed without the legacy SQL dumps.\n";
            echo "  Fix: copy the missing file(s) into /var/www/legacy/ (host: ./legacy/)\n";
            exit(1);
        }

        echo "\n";
    }

    // ===================================================================
    // Step 1: Capture row counts
    // ===================================================================

    /**
     * @return array<string,int>
     */
    private function captureRowCounts(): array
    {
        $counts = [];
        foreach (self::VERIFY_TABLES as $table) {
            try {
                if (Schema::hasTable($table)) {
                    $counts[$table] = (int) DB::table($table)->count();
                } else {
                    $counts[$table] = -1; // table doesn't exist
                }
            } catch (\Throwable $e) {
                $counts[$table] = -1;
            }
        }
        return $counts;
    }

    // ===================================================================
    // Step 2: List migrations
    // ===================================================================

    private function listMigrations(): void
    {
        echo "Data-seeding migrations to re-run (" . count(self::RESEED_MIGRATIONS) . "):\n";

        // Query which ones are currently marked as "ran" in the migrations table
        $ran = DB::table('migrations')
            ->whereIn('migration', self::RESEED_MIGRATIONS)
            ->pluck('migration')
            ->toArray();

        foreach (self::RESEED_MIGRATIONS as $i => $name) {
            $status = in_array($name, $ran) ? '[ran — will reset]' : '[pending]';
            printf("  %2d. %s %s\n", $i + 1, $name, $status);
        }

        if (empty($ran)) {
            echo "\n  Note: None of these are currently in the migrations table.\n";
            echo "  They will run as normal pending migrations.\n";
        }
        echo "\n";
    }

    // ===================================================================
    // Step 4: Delete migration records
    // ===================================================================

    private function deleteMigrationRecords(): int
    {
        $deleted = DB::table('migrations')
            ->whereIn('migration', self::RESEED_MIGRATIONS)
            ->delete();

        echo "Removed {$deleted} migration record(s) from the `migrations` table.\n";
        return $deleted;
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
