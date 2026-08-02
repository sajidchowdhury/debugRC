<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.4: Fixed Asset & Depreciation
 *
 * Creates tables to support the fixed asset register, depreciation scheduling,
 * and asset disposal (sale, write-off) with gain/loss calculation.
 *
 * Tables:
 *   1. fixed_assets — the main asset register
 *   2. asset_depreciation_schedules — monthly depreciation schedule per asset
 *   3. asset_disposals — disposal records with gain/loss
 *
 * Depreciation Methods:
 *   - straight_line:      (cost - salvage) / useful_life_months
 *   - declining_balance:  book_value * (declining_balance_rate / 100) / 12
 *   - units_of_production: (cost - salvage) / total_estimated_units * units_this_period
 *
 * Journal Entry for Depreciation:
 *   Dr Depreciation Expense   (dep_expense_ledger_id)
 *   Cr Accumulated Depreciation (dep_ledger_id)
 *
 * Journal Entry for Disposal (sale):
 *   Dr Cash/Bank              (disposal_proceeds)
 *   Dr Accumulated Depreciation (dep_ledger_id) — full accumulated amount
 *   Cr Fixed Asset             (asset_ledger_id) — original cost
 *   Dr/Cr Gain/Loss on Disposal — the difference
 *
 * Journal Entry for Disposal (write-off):
 *   Dr Accumulated Depreciation (dep_ledger_id)
 *   Dr Loss on Disposal        (if any remaining book value)
 *   Cr Fixed Asset             (asset_ledger_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. fixed_assets ─────────────────────────────────────────
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 30)->unique();   // e.g. "FA-2026-00001"
            $table->string('description', 255);
            $table->string('category', 50)->default('machinery')
                  ->check("category IN ('machinery','furniture','vehicle','office_equipment','computer','building','land','other')");

            // Acquisition details
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 15, 2);
            $table->decimal('salvage_value', 15, 2)->default(0);

            // Depreciation configuration
            $table->string('depreciation_method', 30)->default('straight_line')
                  ->check("depreciation_method IN ('straight_line','declining_balance','units_of_production')");
            $table->integer('useful_life_months')->default(60);  // 5 years default
            $table->decimal('declining_balance_rate', 5, 2)->default(20.00); // % per annum (e.g. 20% = double declining for 10yr)
            $table->decimal('total_estimated_units', 15, 2)->default(0);     // For units_of_production
            $table->decimal('units_produced_to_date', 15, 2)->default(0);    // Running total

            // Ledger mappings
            $table->unsignedBigInteger('asset_ledger_id');       // Fixed Asset account (e.g. Machinery & Equipment)
            $table->foreign('asset_ledger_id', 'fk_fa_asset_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            $table->unsignedBigInteger('dep_ledger_id');         // Accumulated Depreciation account
            $table->foreign('dep_ledger_id', 'fk_fa_dep_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            $table->unsignedBigInteger('dep_expense_ledger_id')->nullable(); // Depreciation Expense account
            $table->foreign('dep_expense_ledger_id', 'fk_fa_dep_expense_ledger')
                  ->references('id')->on('ledgers')->nullOnDelete();

            // Branch & location
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id', 'fk_fa_branch')
                  ->references('id')->on('branches')->restrictOnDelete();
            $table->string('location', 255)->nullable();  // Physical location description

            // Status
            $table->string('status', 20)->default('active')
                  ->check("status IN ('active','disposed','fully_depreciated')");

            // Calculated fields (updated by DepreciationService)
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->decimal('net_book_value', 15, 2)->default(0);
            $table->date('last_depreciation_date')->nullable();

            // Notes
            $table->text('notes')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('warranty_expiry', 30)->nullable();

            // Audit
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status'], 'idx_fa_branch_status');
            $table->index('category', 'idx_fa_category');
            $table->index('acquisition_date', 'idx_fa_acquisition_date');
            $table->index('asset_ledger_id', 'idx_fa_asset_ledger');
        });

        // ── 2. asset_depreciation_schedules ──────────────────────────
        Schema::create('asset_depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixed_asset_id');
            $table->foreign('fixed_asset_id', 'fk_ads_fixed_asset')
                  ->references('id')->on('fixed_assets')->cascadeOnDelete();

            $table->date('depreciation_date');           // The date of this depreciation run
            $table->date('period_from');                 // Start of the depreciation period
            $table->date('period_to');                   // End of the depreciation period

            $table->string('depreciation_method', 30);   // Snapshot of method used
            $table->decimal('opening_book_value', 15, 2)->default(0);
            $table->decimal('depreciation_amount', 15, 2)->default(0);
            $table->decimal('closing_book_value', 15, 2)->default(0);

            // For units_of_production
            $table->decimal('units_produced', 15, 2)->default(0);
            $table->decimal('rate_per_unit', 15, 6)->default(0);

            // For declining_balance
            $table->decimal('declining_balance_rate_used', 5, 2)->default(0);

            // Linked journal entry
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'fk_ads_journal_entry')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->string('status', 20)->default('pending')
                  ->check("status IN ('pending','posted','reversed')");

            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();

            $table->index(['fixed_asset_id', 'status'], 'idx_ads_asset_status');
            $table->index(['fixed_asset_id', 'period_from', 'period_to'], 'idx_ads_asset_period');
            $table->index('depreciation_date', 'idx_ads_dep_date');
            $table->index('journal_entry_id', 'idx_ads_journal_entry');
        });

        // ── 3. asset_disposals ───────────────────────────────────────
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('disposal_code', 30)->unique();   // e.g. "DSP-2026-00001"
            $table->unsignedBigInteger('fixed_asset_id');
            $table->foreign('fixed_asset_id', 'fk_ad_fixed_asset')
                  ->references('id')->on('fixed_assets')->cascadeOnDelete();

            $table->string('disposal_type', 20)
                  ->check("disposal_type IN ('sale','write_off','scrap','donation')");

            $table->date('disposal_date');

            // Financial details
            $table->decimal('disposal_proceeds', 15, 2)->default(0);   // Amount received
            $table->decimal('book_value_at_disposal', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation_at_disposal', 15, 2)->default(0);
            $table->decimal('gain_loss_amount', 15, 2)->default(0);
            $table->string('gain_loss_type', 10)->default('none')
                  ->check("gain_loss_type IN ('gain','loss','none')");

            // Ledger for proceeds (cash/bank)
            $table->unsignedBigInteger('proceeds_ledger_id')->nullable();
            $table->foreign('proceeds_ledger_id', 'fk_ad_proceeds_ledger')
                  ->references('id')->on('ledgers')->nullOnDelete();

            // Ledger for gain/loss on disposal
            $table->unsignedBigInteger('gain_loss_ledger_id')->nullable();
            $table->foreign('gain_loss_ledger_id', 'fk_ad_gain_loss_ledger')
                  ->references('id')->on('ledgers')->nullOnDelete();

            // Linked journal entry
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'fk_ad_journal_entry')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['fixed_asset_id', 'disposal_type'], 'idx_ad_asset_type');
            $table->index('disposal_date', 'idx_ad_disposal_date');
            $table->index('journal_entry_id', 'idx_ad_journal_entry');
        });

        // ── 4. Seed fixed asset CoA ledgers ─────────────────────────
        $this->seedFixedAssetLedgers();

        // ── 5. RLS policies ─────────────────────────────────────────
        foreach (['fixed_assets', 'asset_depreciation_schedules', 'asset_disposals'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_admin_policy ON {$tbl}
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
            ");
        }

        // ── 6. Partial unique index for asset_code ──────────────────
        DB::statement("
            CREATE UNIQUE INDEX uq_fa_asset_code_active
            ON fixed_assets (asset_code)
            WHERE deleted_at IS NULL
        ");
    }

    /**
     * Seed fixed asset ledger accounts into the Chart of Accounts.
     *
     * Creates:
     *   - L-0201: Tangible Fixed Assets (sub-group under L-0200)
     *   - L-0210: Machinery & Equipment
     *   - L-0220: Furniture & Fixtures
     *   - L-0230: Vehicles
     *   - L-0240: Office Equipment
     *   - L-0250: Accumulated Depreciation (contra-asset, credit normal balance)
     *   - L-0903: Depreciation Expense
     *   - L-0904: Loss on Asset Disposal
     *   - L-0804: Gain on Asset Disposal
     */
    private function seedFixedAssetLedgers(): void
    {
        $now = now();

        // Find the L-0200 Fixed Assets parent
        $fixedAssetsGroup = DB::table('ledgers')
            ->where('ledger_code', 'L-0200')
            ->first();

        if (!$fixedAssetsGroup) {
            // Create the Fixed Assets group if it doesn't exist
            $assetId = DB::table('ledgers')
                ->where('ledger_code', 'L-0001')
                ->value('id');

            if (!$assetId) {
                return; // Can't seed without the main ASSETS group
            }

            $fixedAssetsGroupId = DB::table('ledgers')->insertGetId([
                'ledger_code' => 'L-0200',
                'ledger_name' => 'Fixed Assets',
                'parent_id' => $assetId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_active' => true,
                'is_control_account' => false,
                'is_system' => false,
                'opening_balance' => 0,
                'sort_order' => 120,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $fixedAssetsGroupId = $fixedAssetsGroup->id;
        }

        // Find the expense parent (L-0005)
        $expenseId = DB::table('ledgers')
            ->where('ledger_code', 'L-0005')
            ->value('id');

        // Find the admin expenses parent (L-0900)
        $adminExpensesId = DB::table('ledgers')
            ->where('ledger_code', 'L-0900')
            ->value('id');

        // Find the other income parent (L-0800)
        $otherIncomeId = DB::table('ledgers')
            ->where('ledger_code', 'L-0800')
            ->value('id');

        // Find the income parent (L-0004)
        $incomeId = DB::table('ledgers')
            ->where('ledger_code', 'L-0004')
            ->value('id');

        $ledgers = [
            // Fixed Asset sub-types (under L-0200 Fixed Assets)
            [
                'ledger_code' => 'L-0201',
                'ledger_name' => 'Tangible Fixed Assets',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1210,
            ],
            [
                'ledger_code' => 'L-0210',
                'ledger_name' => 'Machinery & Equipment',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1220,
            ],
            [
                'ledger_code' => 'L-0220',
                'ledger_name' => 'Furniture & Fixtures',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1230,
            ],
            [
                'ledger_code' => 'L-0230',
                'ledger_name' => 'Vehicles',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1240,
            ],
            [
                'ledger_code' => 'L-0240',
                'ledger_name' => 'Office Equipment',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => null,
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1250,
            ],
            // Accumulated Depreciation (contra-asset — credit normal balance)
            [
                'ledger_code' => 'L-0250',
                'ledger_name' => 'Accumulated Depreciation',
                'parent_id' => $fixedAssetsGroupId,
                'account_type' => 'Asset',
                'ledger_nature' => 'accumulated_depreciation',
                'normal_balance' => 'credit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 1260,
            ],
            // Depreciation Expense (under Admin Expenses)
            [
                'ledger_code' => 'L-0903',
                'ledger_name' => 'Depreciation Expense',
                'parent_id' => $adminExpensesId ?? $expenseId,
                'account_type' => 'Expense',
                'ledger_nature' => 'depreciation_expense',
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 6130,
            ],
            // Loss on Asset Disposal (under Admin Expenses)
            [
                'ledger_code' => 'L-0904',
                'ledger_name' => 'Loss on Asset Disposal',
                'parent_id' => $adminExpensesId ?? $expenseId,
                'account_type' => 'Expense',
                'ledger_nature' => 'loss_on_disposal',
                'normal_balance' => 'debit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 6140,
            ],
            // Gain on Asset Disposal (under Other Income)
            [
                'ledger_code' => 'L-0804',
                'ledger_name' => 'Gain on Asset Disposal',
                'parent_id' => $otherIncomeId ?? $incomeId,
                'account_type' => 'Income',
                'ledger_nature' => 'gain_on_disposal',
                'normal_balance' => 'credit',
                'is_control_account' => false,
                'is_system' => false,
                'sort_order' => 4240,
            ],
        ];

        foreach ($ledgers as $ledger) {
            // Use ON CONFLICT for idempotent upsert
            DB::table('ledgers')->upsert(
                array_merge($ledger, [
                    'is_active' => true,
                    'opening_balance' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
                ['ledger_code'],  // Unique key for conflict detection
                [
                    'ledger_name',
                    'parent_id',
                    'account_type',
                    'ledger_nature',
                    'normal_balance',
                    'is_control_account',
                    'is_system',
                    'sort_order',
                    'is_active',
                    'updated_at',
                ]
            );
        }
    }

    public function down(): void
    {
        // Drop RLS
        foreach (['fixed_assets', 'asset_depreciation_schedules', 'asset_disposals'] as $tbl) {
            DB::statement("DROP POLICY IF EXISTS {$tbl}_admin_policy ON {$tbl}");
            DB::statement("ALTER TABLE {$tbl} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
        }

        // Drop partial unique index
        DB::statement("DROP INDEX IF EXISTS uq_fa_asset_code_active");

        // Remove seeded ledgers
        DB::table('ledgers')
            ->whereIn('ledger_code', ['L-0201', 'L-0210', 'L-0220', 'L-0230', 'L-0240', 'L-0250', 'L-0903', 'L-0904', 'L-0804'])
            ->delete();

        // Drop tables in reverse dependency order
        Schema::dropIfExists('asset_disposals');
        Schema::dropIfExists('asset_depreciation_schedules');
        Schema::dropIfExists('fixed_assets');
    }
};
