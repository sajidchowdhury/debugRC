<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verify Branch Demand Schema — checks that all required tables, columns,
 * and constraints exist for the Branch Demand feature to work correctly.
 *
 * Run: php artisan branch-demand:verify-schema
 *
 * This command helps diagnose the "send goods not working" issue by
 * checking that all migrations have been applied.
 */
class VerifyBranchDemandSchema extends Command
{
    protected $signature = 'branch-demand:verify-schema';

    protected $description = 'Verify that all Branch Demand database tables, columns, and constraints exist';

    public function handle(): int
    {
        $this->info('=== Branch Demand Schema Verification ===');
        $this->newLine();

        $errors = 0;

        // 1. Check branch_demands table
        $this->info('1. Checking branch_demands table...');
        if (!Schema::hasTable('branch_demands')) {
            $this->error('   ✗ branch_demands table does NOT exist!');
            $this->warn('   → Run: php artisan migrate');
            $errors++;
        } else {
            $requiredColumns = [
                'id', 'demand_code', 'demand_date', 'from_branch_id', 'to_branch_id',
                'status', 'total_value', 'settlement_amount', 'warehouse_transfer_id',
                'journal_entry_id', 'journal_entry_id_debtor', 'is_reversed',
                'reversed_at', 'reversed_by', 'reverse_reason', 'received_at',
                'received_by', 'notes', 'created_by', 'created_at', 'updated_at',
            ];
            $missingColumns = [];
            foreach ($requiredColumns as $col) {
                if (!Schema::hasColumn('branch_demands', $col)) {
                    $missingColumns[] = $col;
                }
            }
            if (empty($missingColumns)) {
                $this->info('   ✓ branch_demands table has all required columns');
            } else {
                $this->error('   ✗ branch_demands table is missing columns: ' . implode(', ', $missingColumns));
                $this->warn('   → Run migration 2026_07_29_000010_align_branch_demands_table');
                $errors++;
            }
        }

        // 2. Check branch_demand_items table
        $this->info('2. Checking branch_demand_items table...');
        if (!Schema::hasTable('branch_demand_items')) {
            $this->error('   ✗ branch_demand_items table does NOT exist!');
            $this->warn('   → Run: php artisan migrate');
            $errors++;
        } else {
            $requiredColumns = [
                'id', 'branch_demand_id', 'product_id', 'qty', 'cost_rate',
                'from_warehouse_id', 'to_warehouse_id',
                'price_min', 'price_max', 'price_default',
            ];
            $missingColumns = [];
            foreach ($requiredColumns as $col) {
                if (!Schema::hasColumn('branch_demand_items', $col)) {
                    $missingColumns[] = $col;
                }
            }
            if (empty($missingColumns)) {
                $this->info('   ✓ branch_demand_items table has all required columns');
            } else {
                $this->error('   ✗ branch_demand_items table is missing columns: ' . implode(', ', $missingColumns));
                $this->warn('   → Run migration 2026_07_29_000011_align_branch_demand_items_table');
                $errors++;
            }
        }

        // 3. Check branch_ledger table
        $this->info('3. Checking branch_ledger table...');
        if (!Schema::hasTable('branch_ledger')) {
            $this->error('   ✗ branch_ledger table does NOT exist!');
            $this->warn('   → Run migration 2026_07_29_000013_create_branch_ledger_table');
            $errors++;
        } else {
            $this->info('   ✓ branch_ledger table exists');
        }

        // 4. Check branch_demand_repricing table
        $this->info('4. Checking branch_demand_repricing table...');
        if (!Schema::hasTable('branch_demand_repricing')) {
            $this->error('   ✗ branch_demand_repricing table does NOT exist!');
            $this->warn('   → Run migration 2026_07_29_000016_create_branch_demand_repricing_table');
            $errors++;
        } else {
            $this->info('   ✓ branch_demand_repricing table exists');
        }

        // 5. Check branch_demand_audit_log table
        $this->info('5. Checking branch_demand_audit_log table...');
        if (!Schema::hasTable('branch_demand_audit_log')) {
            $this->error('   ✗ branch_demand_audit_log table does NOT exist!');
            $this->warn('   → Run migration 2026_07_29_000017_create_branch_demand_audit_log_table');
            $errors++;
        } else {
            $this->info('   ✓ branch_demand_audit_log table exists');
        }

        // 6. Check stock_transactions reference_type constraint
        $this->info('6. Checking stock_transactions reference_type constraint...');
        try {
            $constraint = DB::selectOne("
                SELECT pg_get_constraintdef(oid) AS definition
                FROM pg_constraint
                WHERE conrelid = 'stock_transactions'::regclass
                  AND contype = 'c'
                  AND conname LIKE '%reference_type%'
                LIMIT 1
            ");
            if ($constraint) {
                $def = $constraint->definition;
                if (str_contains($def, 'demand_send') && str_contains($def, 'demand_receive')) {
                    $this->info('   ✓ stock_transactions reference_type constraint includes demand_send/demand_receive');
                } else {
                    $this->error('   ✗ stock_transactions reference_type constraint does NOT include demand_send/demand_receive!');
                    $this->warn('   → Run migration 2026_07_29_000012_add_demand_reference_types_to_stock_transactions');
                    $this->warn('   → THIS IS LIKELY THE CAUSE OF "SEND GOODS NOT WORKING"!');
                    $errors++;
                }
            } else {
                $this->warn('   ⚠ No reference_type CHECK constraint found on stock_transactions');
            }
        } catch (\Throwable $e) {
            $this->warn('   ⚠ Could not check constraint: ' . $e->getMessage());
        }

        // 7. Check stock_transactions has branch_demand_item_id column
        $this->info('7. Checking stock_transactions.branch_demand_item_id column...');
        if (Schema::hasColumn('stock_transactions', 'branch_demand_item_id')) {
            $this->info('   ✓ stock_transactions.branch_demand_item_id column exists');
        } else {
            $this->warn('   ⚠ stock_transactions.branch_demand_item_id column does NOT exist (optional, but recommended)');
        }

        // 8. Check ledgers table for interbranch accounts
        $this->info('8. Checking ledgers table for interbranch accounts...');
        try {
            if (!Schema::hasTable('ledgers')) {
                $this->error('   ✗ ledgers table does NOT exist!');
                $this->warn('   → GL journal posting will be skipped.');
                $errors++;
            } else {
                $query = DB::table('ledgers')->where('is_active', true);
                if (Schema::hasColumn('ledgers', 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }
                $receivable = (clone $query)->where('ledger_nature', 'interbranch_receivable')->exists();
                $payable = (clone $query)->where('ledger_nature', 'interbranch_payable')->exists();

                if ($receivable && $payable) {
                    $this->info('   ✓ Both interbranch_receivable and interbranch_payable accounts exist');
                } else {
                    $missing = [];
                    if (!$receivable) $missing[] = 'interbranch_receivable';
                    if (!$payable) $missing[] = 'interbranch_payable';
                    $this->warn('   ⚠ Missing ledger accounts: ' . implode(', ', $missing));
                    $this->warn('   → GL journal posting will be skipped when sending goods');
                    $this->warn('   → Run migration 2025_01_05_000001_seed_default_chart_of_accounts');
                }
            }
        } catch (\Throwable $e) {
            $this->error('   ✗ Could not check ledgers table: ' . $e->getMessage());
            $errors++;
        }

        // 9. Check menus table for branch demand entries
        $this->info('9. Checking menus table for Branch Demand entries...');
        try {
            $menuExists = DB::table('menus')
                ->where('controller', 'branchdemand')
                ->exists();
            if ($menuExists) {
                $this->info('   ✓ Branch Demand menu entries exist');
            } else {
                $this->error('   ✗ Branch Demand menu entries do NOT exist!');
                $this->warn('   → Run migration 2026_07_29_000018_add_branch_demand_sidebar_menu');
                $errors++;
            }
        } catch (\Throwable $e) {
            $this->error('   ✗ Could not check menus table: ' . $e->getMessage());
            $errors++;
        }

        // 10. Check warehouse_stock table
        $this->info('10. Checking warehouse_stock table...');
        if (Schema::hasTable('warehouse_stock')) {
            $this->info('   ✓ warehouse_stock table exists');
        } else {
            $this->error('   ✗ warehouse_stock table does NOT exist!');
            $errors++;
        }

        $this->newLine();

        if ($errors === 0) {
            $this->info('✅ All schema checks passed! The Branch Demand feature should work correctly.');
            $this->info('   If send goods still fails, check the Laravel log for detailed error messages.');
            return self::SUCCESS;
        } else {
            $this->error("❌ Found {$errors} schema issue(s). Run 'php artisan migrate' to apply missing migrations.");
            return self::FAILURE;
        }
    }
}
