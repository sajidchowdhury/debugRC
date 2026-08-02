<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: Budgeting & Cost Centers
 *
 * Creates:
 *   1. budgets         — header table for budget definitions (fiscal year, branch, status)
 *   2. budget_lines    — per-ledger, per-period budget amounts (the spreadsheet grid)
 *   3. dimensions      — dimension types (cost_center, profit_center, department, project, location)
 *   4. dimension_values — individual values within each dimension (e.g. "Sales Dept", "Project Alpha")
 *
 * Also adds:
 *   - dimension_value_id column to journal_lines (nullable FK to dimension_values)
 *   - budget_vs_actual database view for efficient variance reporting
 *   - RLS policies for the new branch-scoped tables
 *   - Seeds default dimensions (Department, Project, Location)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. budgets ──────────────────────────────────────────────────
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('fiscal_year', 9);                    // e.g. "2026" or "2026-27"
            $table->foreignId('branch_id')->nullable()->constrained(); // null = all branches
            $table->string('period_type', 10)->default('monthly'); // monthly, quarterly, yearly
            $table->string('status', 20)->default('draft')
                  ->check("status IN ('draft','active','closed','cancelled')");
            $table->text('description')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fiscal_year', 'branch_id'], 'idx_budgets_year_branch');
            $table->index(['status', 'fiscal_year'], 'idx_budgets_status_year');
        });

        // ── 2. budget_lines ─────────────────────────────────────────────
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained();
            $table->unsignedSmallInteger('period');         // 1-12 for monthly, 1-4 for quarterly, 1 for yearly
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'ledger_id', 'period'], 'uq_bl_budget_ledger_period');
            $table->index(['ledger_id', 'period'], 'idx_bl_ledger_period');
        });

        // ── 3. dimensions ───────────────────────────────────────────────
        Schema::create('dimensions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 30)->default('cost_center')
                  ->check("type IN ('cost_center','profit_center','department','project','location')");
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active'], 'idx_dim_type_active');
        });

        // ── 4. dimension_values ─────────────────────────────────────────
        Schema::create('dimension_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dimension_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('branch_id')->nullable()->constrained(); // null = all branches
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['dimension_id', 'is_active'], 'idx_dv_dim_active');
            $table->index('branch_id');
        });

        // Partial unique index: prevents duplicate active codes within a dimension.
        // Standard UNIQUE(dimension_id, code, deleted_at) fails on PostgreSQL because
        // NULL != NULL, so two rows with the same (dimension_id, code) and deleted_at=NULL
        // would both be allowed. A partial index WHERE deleted_at IS NULL solves this.
        DB::statement("
            CREATE UNIQUE INDEX uq_dv_dim_code_active
            ON dimension_values (dimension_id, code)
            WHERE deleted_at IS NULL
        ");

        // ── 5. Add dimension_value_id to journal_lines ──────────────────
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('dimension_value_id')->nullable()->after('memo');
            $table->foreign('dimension_value_id', 'fk_jl_dim_value')
                  ->references('id')->on('dimension_values')
                  ->nullOnDelete();

            $table->index('dimension_value_id', 'idx_jl_dim_value');
        });

        // ── 6. Create budget_vs_actual view ─────────────────────────────
        // Compares budget_lines against actual journal_lines for the same
        // ledger + period. The view joins budget_lines with the actual
        // posted amounts from journal_lines → journal_entries.
        DB::statement("
            CREATE OR REPLACE VIEW budget_vs_actual AS
            SELECT
                bl.id AS budget_line_id,
                b.id AS budget_id,
                b.name AS budget_name,
                b.fiscal_year,
                b.branch_id AS budget_branch_id,
                bl.ledger_id,
                l.ledger_code,
                l.ledger_name,
                l.account_type,
                l.normal_balance,
                bl.period,
                bl.amount AS budget_amount,
                COALESCE(actual.actual_amount, 0) AS actual_amount,
                bl.amount - COALESCE(actual.actual_amount, 0) AS variance_amount,
                CASE
                    WHEN bl.amount = 0 THEN NULL
                    ELSE ROUND(
                        ((bl.amount - COALESCE(actual.actual_amount, 0)) / bl.amount) * 100,
                        2
                    )
                END AS variance_percent
            FROM budget_lines bl
            JOIN budgets b ON b.id = bl.budget_id
            JOIN ledgers l ON l.id = bl.ledger_id
            LEFT JOIN LATERAL (
                SELECT SUM(
                    CASE l.normal_balance
                        WHEN 'debit'  THEN jl2.debit  - jl2.credit
                        WHEN 'credit' THEN jl2.credit - jl2.debit
                    END
                ) AS actual_amount
                FROM journal_lines jl2
                JOIN journal_entries je2 ON je2.id = jl2.journal_entry_id
                WHERE jl2.ledger_id = bl.ledger_id
                  AND je2.is_reversed = false
                  AND EXTRACT(YEAR FROM je2.entry_date)::text = b.fiscal_year
                  AND EXTRACT(MONTH FROM je2.entry_date) = bl.period
                  AND (b.branch_id IS NULL OR je2.branch_id = b.branch_id)
            ) actual ON true
            WHERE b.deleted_at IS NULL
              AND l.deleted_at IS NULL
        ");

        // ── 7. RLS policies for branch-scoped tables ────────────────────
        foreach (['budgets'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_branch_policy ON {$tbl}
                USING (
                    branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::int
                    OR current_setting('app.is_admin', true) = 'true'
                )
            ");
        }

        foreach (['dimension_values'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_branch_policy ON {$tbl}
                USING (
                    branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::int
                    OR current_setting('app.is_admin', true) = 'true'
                )
            ");
        }

        // ── 8. Seed default dimensions ──────────────────────────────────
        $sysUserId = DB::table('users')->value('id') ?? 1;

        $deptDimId = DB::table('dimensions')->insertGetId([
            'name' => 'Department',
            'type' => 'department',
            'code' => 'DEPT',
            'is_active' => true,
            'description' => 'Departmental cost tracking for segment reporting',
            'created_by' => $sysUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projDimId = DB::table('dimensions')->insertGetId([
            'name' => 'Project',
            'type' => 'project',
            'code' => 'PROJ',
            'is_active' => true,
            'description' => 'Project-based cost allocation for job costing',
            'created_by' => $sysUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locDimId = DB::table('dimensions')->insertGetId([
            'name' => 'Location',
            'type' => 'location',
            'code' => 'LOC',
            'is_active' => true,
            'description' => 'Location-based revenue and expense tracking',
            'created_by' => $sysUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed default department values
        $departments = [
            ['code' => 'ADMIN', 'name' => 'Administration'],
            ['code' => 'SALES', 'name' => 'Sales'],
            ['code' => 'ACCOUNTS', 'name' => 'Accounts'],
            ['code' => 'WAREHOUSE', 'name' => 'Warehouse'],
            ['code' => 'HR', 'name' => 'Human Resources'],
        ];

        foreach ($departments as $dept) {
            DB::table('dimension_values')->insert([
                'dimension_id' => $deptDimId,
                'code' => $dept['code'],
                'name' => $dept['name'],
                'branch_id' => null,
                'is_active' => true,
                'created_by' => $sysUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Drop partial unique index first
        DB::statement("DROP INDEX IF EXISTS uq_dv_dim_code_active");

        // Drop view
        DB::statement("DROP VIEW IF EXISTS budget_vs_actual");

        // Drop RLS
        foreach (['budgets', 'dimension_values'] as $tbl) {
            DB::statement("DROP POLICY IF EXISTS {$tbl}_branch_policy ON {$tbl}");
            DB::statement("ALTER TABLE {$tbl} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
        }

        // Drop dimension_value_id from journal_lines
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign('fk_jl_dim_value');
            $table->dropIndex('idx_jl_dim_value');
            $table->dropColumn('dimension_value_id');
        });

        // Drop tables in reverse dependency order
        Schema::dropIfExists('dimension_values');
        Schema::dropIfExists('dimensions');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
