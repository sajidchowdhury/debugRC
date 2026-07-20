<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;

/**
 * One-command setup for RC_ERP_v2.
 *
 * Runs: schema load → migrations → admin user seed → verification.
 *
 * Usage:
 *   php artisan rcerp:setup
 *
 * This command is idempotent — safe to run multiple times.
 */
class SetupRcerp extends Command
{
    protected $signature = 'rcerp:setup
                            {--force : Skip confirmation prompts}
                            {--skip-schema : Skip loading SQL schema files}
                            {--skip-migrate : Skip running migrations}
                            {--skip-admin : Skip creating admin user}';

    protected $description = 'One-command setup: load schema, run migrations, create admin user';

    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║          RC_ERP_v2 — One-Command Setup                   ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        $force = $this->option('force');

        // Step 1: Verify database connection
        $this->info('Step 1: Verifying database connection...');
        try {
            DB::connection()->getPdo();
            $dbName = DB::connection()->getDatabaseName();
            $this->info("  ✓ Connected to database: {$dbName}");
        } catch (\Throwable $e) {
            $this->error("  ✗ Cannot connect to database: {$e->getMessage()}");
            $this->error('  Check your .env file — DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD');
            return 1;
        }
        $this->newLine();

        // Step 2: Load SQL schema files
        if (!$this->option('skip-schema')) {
            $this->info('Step 2: Loading SQL schema files...');
            $sqlDir = base_path('database/sql');
            $sqlFiles = [
                '01_auth_and_master.sql',
                '02_accounting.sql',
                '03_stock.sql',
                '04_sales.sql',
                '05_purchase.sql',
                '06_payment_and_misc.sql',
                '07_views_triggers_constraints.sql',
            ];

            foreach ($sqlFiles as $file) {
                $path = "{$sqlDir}/{$file}";
                if (!File::exists($path)) {
                    $this->warn("  ⚠ File not found: {$file} — skipping");
                    continue;
                }

                // Check if schema already loaded (tables exist)
                $tableCount = DB::select("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'public'")[0]->cnt;
                if ($file === '01_auth_and_master.sql' && $tableCount > 10) {
                    $this->info("  ✓ Schema already loaded ({$tableCount} tables exist) — skipping");
                    break;
                }

                $this->info("  Loading {$file}...");
                try {
                    $sql = File::get($path);
                    DB::unprepared($sql);
                    $this->info("  ✓ {$file} loaded");
                } catch (\Throwable $e) {
                    // Check if it's a "already exists" error
                    if (str_contains($e->getMessage(), 'already exists')) {
                        $this->info("  ✓ {$file} — tables already exist, skipping");
                    } else {
                        $this->warn("  ⚠ {$file}: {$e->getMessage()}");
                    }
                }
            }
        }
        $this->newLine();

        // Step 3: Run migrations
        if (!$this->option('skip-migrate')) {
            $this->info('Step 3: Running migrations...');

            // Create migrations table if it doesn't exist
            DB::statement('CREATE TABLE IF NOT EXISTS migrations (id integer PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');

            $this->call('migrate', ['--force' => true]);
        }
        $this->newLine();

        // Step 4: Create admin user
        if (!$this->option('skip-admin')) {
            $this->info('Step 4: Creating admin user...');

            try {
                // Create Head Office branch
                $branch = Branch::firstOrCreate(
                    ['branch_code' => 'HO'],
                    [
                        'branch_name' => 'Head Office',
                        'address' => '123 Main Street, Dhaka',
                        'phone' => '02-1234567',
                        'email' => 'ho@rcerp.com',
                        'is_active' => true,
                    ]
                );
                $this->info("  ✓ Branch: {$branch->branch_name} (id={$branch->id})");

                // Create admin employee
                $employee = Employee::firstOrCreate(
                    ['employee_code' => 'EMP-0001'],
                    [
                        'name' => 'System Administrator',
                        'role' => 'admin',
                        'branch_id' => $branch->id,
                        'phone' => '01711111111',
                        'email' => 'admin@rcerp.com',
                        'salary' => 100000,
                        'joining_date' => '2024-01-01',
                        'is_active' => true,
                    ]
                );
                $this->info("  ✓ Employee: {$employee->name} (id={$employee->id})");

                // Create admin user
                $user = User::firstOrCreate(
                    ['username' => 'admin'],
                    [
                        'employee_id' => $employee->id,
                        'password_hash' => Hash::make('password123'),
                        'is_active' => true,
                        'credential_version' => 1,
                    ]
                );
                $this->info("  ✓ User: {$user->username} / password123 (id={$user->id})");
            } catch (\Throwable $e) {
                $this->warn("  ⚠ Admin user creation: {$e->getMessage()}");
            }
        }
        $this->newLine();

        // Step 5: Verification
        $this->info('Step 5: Verification...');
        try {
            $tableCount = DB::select("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'public'")[0]->cnt;
            $this->info("  ✓ Database tables: {$tableCount}");

            $branchCount = Branch::count();
            $this->info("  ✓ Branches: {$branchCount}");

            $employeeCount = Employee::count();
            $this->info("  ✓ Employees: {$employeeCount}");

            $userCount = User::count();
            $this->info("  ✓ Users: {$userCount}");
        } catch (\Throwable $e) {
            $this->warn("  ⚠ Verification: {$e->getMessage()}");
        }
        $this->newLine();

        // Done
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║                    Setup Complete!                        ║');
        $this->info('╠══════════════════════════════════════════════════════════╣');
        $this->info('║  Start the server:                                       ║');
        $this->info('║    php artisan serve                                      ║');
        $this->info('║                                                          ║');
        $this->info('║  Open in browser:                                         ║');
        $this->info('║    http://localhost:8000/login                             ║');
        $this->info('║                                                          ║');
        $this->info('║  Login credentials:                                       ║');
        $this->info('║    Username: admin                                        ║');
        $this->info('║    Password: password123                                  ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');

        return 0;
    }
}
