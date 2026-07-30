<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add legacy Chart-of-Accounts ledger heads that are NOT already present in
 * the Laravel default CoA (seeded by 2025_01_05_000001_seed_default_chart_of_accounts).
 *
 * User directive:
 *   "look at Chart of Accounts see if there have other ledger head ac head to
 *    add. right now we have default one. i want to keep them as it is."
 *
 * Strategy:
 *   - Parse legacy/osudlagb_remotecenter.sql `ledgers` table dump.
 *   - For each legacy row, check if its ledger_code already exists in PG.
 *     If yes → SKIP (default stays intact).
 *     If no  → INSERT the missing head.
 *   - Map legacy nature names to Laravel's canonical nature names
 *     (customer_receivable → ar, supplier_payable → ap, payroll_expense →
 *     salary_expense, financial_expense → finance_cost, asset/liability/
 *     equity/expense → null for group heads).
 *   - Resolve parent_id by looking up the parent's ledger_code in PG — do
 *     NOT assume legacy IDs match Laravel IDs (they don't, because Laravel's
 *     default seeder used insertGetId with auto-assigned IDs).
 *   - Idempotent: ON CONFLICT (ledger_code) DO NOTHING.
 *
 * The legacy SQL dump contains 37 ledger rows. After filtering out duplicates
 * and code conflicts with Laravel defaults, 5 new heads are inserted:
 *
 *   L-0102  Bank Accounts (Control)         — Asset, cash_bank, control=bank
 *   L-0903  Utilities                        — Expense, operating_expense
 *   L-0904  Depreciation                     — Expense, operating_expense
 *   L-1001  Transportation & Delivery        — Expense, operating_expense
 *   L-1002  Marketing & Advertising          — Expense, operating_expense
 *
 * Skipped (duplicate purpose, different code):
 *   L-0006  EXPENSES                         — Laravel has L-0005 EXPENSES
 *   L-1010  Sales Discount Allowed           — Laravel has L-0703 same nature
 *   L-ST-SUR Inventory Surplus (Stock Take)  — Laravel has L-0802 same nature
 *   L-ST-SHR Inventory Shrinkage (Stock Take)— Laravel has L-0502 same nature
 *
 * Skipped (code conflict — legacy code means something different in Laravel):
 *   L-0005  COGS (legacy) vs EXPENSES (Laravel)
 *   L-0901  Salaries & Wages (legacy) vs General Operating Expense (Laravel)
 *   L-0902  Rent Expense (legacy) vs Salary Expense (Laravel)
 *   L-0302  Due to Branches (legacy) vs Employee Payable (Laravel)
 *   L-0105  Employee Advances (legacy) vs Due from Branches (Laravel)
 *
 * To run: php artisan migrate
 */
return new class extends Migration
{
    /**
     * The 5 legacy ledger heads to add. Each is keyed by its legacy
     * ledger_code. parent_code is resolved at runtime by looking up the
     * parent's ledger_code in PG.
     */
    private const NEW_LEDGER_HEADS = [
        [
            'ledger_code'         => 'L-0102',
            'ledger_name'         => 'Bank Accounts (Control)',
            'description'         => 'Control account for bank transactions. Each bank account maps to a sub-ledger under this head.',
            'parent_code'         => 'L-0100', // Current Assets
            'account_type'        => 'Asset',
            'ledger_nature'       => 'cash_bank',
            'normal_balance'      => 'debit',
            'is_control_account'  => true,
            'control_account_type'=> 'bank',
            'sort_order'          => 1120,
        ],
        [
            'ledger_code'         => 'L-0903',
            'ledger_name'         => 'Utilities',
            'description'         => 'Electricity, water, gas, internet, telephone expenses.',
            'parent_code'         => 'L-0900', // Administrative Expenses
            'account_type'        => 'Expense',
            'ledger_nature'       => 'operating_expense',
            'normal_balance'      => 'debit',
            'is_control_account'  => false,
            'control_account_type'=> null,
            'sort_order'          => 6130,
        ],
        [
            'ledger_code'         => 'L-0904',
            'ledger_name'         => 'Depreciation',
            'description'         => 'Depreciation of fixed assets.',
            'parent_code'         => 'L-0900', // Administrative Expenses
            'account_type'        => 'Expense',
            'ledger_nature'       => 'operating_expense',
            'normal_balance'      => 'debit',
            'is_control_account'  => false,
            'control_account_type'=> null,
            'sort_order'          => 6140,
        ],
        [
            'ledger_code'         => 'L-1001',
            'ledger_name'         => 'Transportation & Delivery',
            'description'         => 'Transport, delivery, freight, courier expenses.',
            'parent_code'         => 'L-1000', // Selling & Distribution Expenses
            'account_type'        => 'Expense',
            'ledger_nature'       => 'operating_expense',
            'normal_balance'      => 'debit',
            'is_control_account'  => false,
            'control_account_type'=> null,
            'sort_order'          => 6210,
        ],
        [
            'ledger_code'         => 'L-1002',
            'ledger_name'         => 'Marketing & Advertising',
            'description'         => 'Advertising, promotions, marketing campaign expenses.',
            'parent_code'         => 'L-1000', // Selling & Distribution Expenses
            'account_type'        => 'Expense',
            'ledger_nature'       => 'operating_expense',
            'normal_balance'      => 'debit',
            'is_control_account'  => false,
            'control_account_type'=> null,
            'sort_order'          => 6220,
        ],
    ];

    public function up(): void
    {
        $now = now();

        echo "┌── Add missing legacy ledger heads ───────────────────────────────\n";
        echo "│ Strategy: ON CONFLICT (ledger_code) DO NOTHING — existing default\n";
        echo "│ CoA ledgers are NEVER modified or replaced.\n";
        echo "│\n";

        // ---- Phase 1: CHECK — report current state ----
        $existingCount = DB::table('ledgers')->count();
        $existingCodes = DB::table('ledgers')->pluck('ledger_code')->all();
        echo sprintf("│ Current ledgers in PG: %d rows\n", $existingCount);
        echo "│\n";

        // ---- Phase 2: resolve parent_id for each new head ----
        echo "│ Resolving parent IDs by ledger_code lookup:\n";
        $toInsert = [];
        foreach (self::NEW_LEDGER_HEADS as $head) {
            $parentId = DB::table('ledgers')
                ->where('ledger_code', $head['parent_code'])
                ->value('id');

            if (!$parentId) {
                echo sprintf("│   ⚠ SKIP %s — parent %s not found in PG\n",
                    $head['ledger_code'], $head['parent_code']);
                continue;
            }

            // Check if this ledger_code already exists (don't touch it).
            if (in_array($head['ledger_code'], $existingCodes, true)) {
                echo sprintf("│   ✓ KEEP %s — already exists in PG (default CoA preserved)\n",
                    $head['ledger_code']);
                continue;
            }

            echo sprintf("│   + ADD  %s — parent %s (id=%d)\n",
                $head['ledger_code'], $head['parent_code'], $parentId);

            $toInsert[] = [
                'ledger_code'          => $head['ledger_code'],
                'ledger_name'          => $head['ledger_name'],
                'description'          => $head['description'],
                'parent_id'            => $parentId,
                'account_type'         => $head['account_type'],
                'ledger_nature'        => $head['ledger_nature'],
                'normal_balance'       => $head['normal_balance'],
                'is_active'            => true,
                'is_system'            => true, // Match legacy semantics (legacy had is_system=1 for all)
                'is_control_account'   => $head['is_control_account'],
                'control_account_type' => $head['control_account_type'],
                'opening_balance'      => 0,
                'sort_order'           => $head['sort_order'],
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        echo "│\n";

        // ---- Phase 3: INSERT (idempotent — ON CONFLICT DO NOTHING) ----
        if (empty($toInsert)) {
            echo "│ Nothing to insert — all candidate heads already exist.\n";
            echo "└──────────────────────────────────────────────────────────────────\n";
            return;
        }

        $inserted = 0;
        foreach ($toInsert as $row) {
            try {
                // insertOrIgnore = INSERT ... ON CONFLICT DO NOTHING
                $affected = DB::table('ledgers')->insertOrIgnore($row);
                $inserted += $affected;
                if ($affected) {
                    echo sprintf("│ ✓ Inserted %s — %s\n", $row['ledger_code'], $row['ledger_name']);
                } else {
                    echo sprintf("│ • Skipped %s (already existed)\n", $row['ledger_code']);
                }
            } catch (\Throwable $e) {
                echo sprintf("│ ✗ FAILED %s — %s\n", $row['ledger_code'], $e->getMessage());
            }
        }

        // ---- Phase 4: sync sequence ----
        DB::statement(
            "SELECT setval('ledgers_id_seq', GREATEST((SELECT MAX(id) FROM ledgers), 1), true)"
        );

        // ---- Phase 5: VERIFY ----
        $finalCount = DB::table('ledgers')->count();
        echo "│\n";
        echo sprintf("│ Inserted: %d new ledger head(s)\n", $inserted);
        echo sprintf("│ Before:   %d ledgers\n", $existingCount);
        echo sprintf("│ After:    %d ledgers\n", $finalCount);
        echo "└──────────────────────────────────────────────────────────────────\n";
    }

    public function down(): void
    {
        $codes = array_column(self::NEW_LEDGER_HEADS, 'ledger_code');

        echo "Removing legacy ledger heads added by this migration: " . implode(', ', $codes) . "\n";

        // Only delete the specific ledger codes we added — never touch defaults.
        DB::table('ledgers')
            ->whereIn('ledger_code', $codes)
            ->delete();

        DB::statement(
            "SELECT setval('ledgers_id_seq', GREATEST((SELECT MAX(id) FROM ledgers), 1), true)"
        );
    }
};
