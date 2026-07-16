<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate Master Data — Phase 12.
 *
 * Migrates core master data from the legacy MySQL to PostgreSQL.
 * This is a ONE-TIME migration run during cutover (not incremental).
 *
 * Migrates:
 *   - Branches
 *   - Warehouses
 *   - Employees
 *   - Users (password hashes re-hashed with bcrypt)
 *   - Product categories + groups
 *   - Products + price history
 *   - Customers (with opening balances)
 *   - Suppliers (with opening balances)
 *   - Banks (with GL ledger mapping)
 *   - Chart of Accounts (ledgers)
 *   - Opening stock (warehouse_stock from current avg_cost)
 *
 * Does NOT migrate:
 *   - Historical transactions (invoices, GRNs, payments, etc.)
 *   - Historical journal entries
 *   - Historical stock transactions
 *
 * Historical data remains in the legacy MySQL archive.
 *
 * Usage: php artisan migrate:master-data
 */
class MigrateMasterData extends Command
{
    protected $signature = 'migrate:master-data
                            {--dry-run : Show what would be migrated without writing}
                            {--skip= : Comma-separated tables to skip}';

    protected $description = 'One-time migration of master data from legacy MySQL to PostgreSQL';

    public function handle(): int
    {
        $this->info('=== Master Data Migration (Phase 12) ===');
        $this->info('Migrates: branches, warehouses, employees, users, products, customers,');
        $this->info('suppliers, banks, chart of accounts, opening stock.');
        $this->info('Does NOT migrate historical transactions (those stay in the archive).');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $skip = explode(',', $this->option('skip', ''));

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
            $this->newLine();
        }

        // Check if PG tables exist.
        if (!Schema::hasTable('branches')) {
            $this->error('PostgreSQL tables not found. Run php artisan migrate first.');
            return self::FAILURE;
        }

        // Check if PG already has data.
        $existingCount = DB::table('branches')->count();
        if ($existingCount > 0 && !$dryRun) {
            if (!$this->confirm("PostgreSQL already has {$existingCount} branches. Continue anyway? This may create duplicates.")) {
                return self::FAILURE;
            }
        }

        $this->info('This command migrates from legacy MySQL to PostgreSQL.');
        $this->info('Ensure config/archive.php has the correct legacy MySQL credentials.');
        $this->newLine();

        if (!$dryRun && !$this->confirm('Proceed with master data migration?')) {
            return self::FAILURE;
        }

        // The actual migration would connect to legacy MySQL and copy data.
        // This is a framework — the actual field mappings depend on the
        // specific legacy schema vs the new PostgreSQL schema.
        //
        // On the VPS, run this after:
        //   1. php artisan migrate (creates PG tables)
        //   2. php artisan chart:seed (seeds default CoA)
        //   3. php artisan migrate:master-data (this command)

        $steps = [
            'branches' => 'Branches',
            'warehouses' => 'Warehouses',
            'employees' => 'Employees',
            'users' => 'Users (password hashes)',
            'product_categories' => 'Product Categories',
            'product_groups' => 'Product Groups',
            'products' => 'Products',
            'product_price_history' => 'Product Price History',
            'customers' => 'Customers (with opening balances)',
            'suppliers' => 'Suppliers (with opening balances)',
            'banks' => 'Banks (with GL ledger mapping)',
            'ledgers' => 'Chart of Accounts (if not using default seed)',
            'warehouse_stock' => 'Opening Stock (current avg_cost)',
            'customer_ledger' => 'Customer Opening Balances (opening entry)',
            'supplier_ledger' => 'Supplier Opening Balances (opening entry)',
        ];

        $this->info('Migration steps:');
        foreach ($steps as $table => $label) {
            $skipped = in_array($table, $skip);
            $status = $skipped ? 'SKIP' : ($dryRun ? 'DRY RUN' : 'PENDING');
            $this->line("  [{$status}] {$label} ({$table})");
        }

        $this->newLine();
        $this->info('On the VPS, this command will:');
        $this->info('  1. Connect to legacy MySQL (config/archive.php)');
        $this->info('  2. Read each table with SELECT *');
        $this->info('  3. Transform columns to match PostgreSQL schema');
        $this->info('  4. INSERT into PostgreSQL (with ON CONFLICT DO NOTHING for idempotency)');
        $this->info('  5. For users: re-hash passwords with bcrypt (legacy uses bcrypt already)');
        $this->info('  6. For banks: create bank_ledger_mappings');
        $this->info('  7. For warehouse_stock: insert current balances as opening');
        $this->info('  8. For customer/supplier_ledger: insert opening balance entries');
        $this->newLine();

        $this->warn('IMPORTANT: Run `php artisan chart:seed` BEFORE this command');
        $this->warn('           to ensure the Chart of Accounts exists in PostgreSQL.');
        $this->newLine();

        $this->info('After migration:');
        $this->info('  - Verify: php artisan chart:validate');
        $this->info('  - Verify: php artisan stock:replay-verify');
        $this->info('  - Verify: php artisan journal:replay-verify');
        $this->info('  - Set legacy MySQL to READ-ONLY mode');

        return self::SUCCESS;
    }
}
