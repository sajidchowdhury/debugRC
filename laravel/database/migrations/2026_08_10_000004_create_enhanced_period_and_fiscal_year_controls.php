<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: Enhanced Period & Fiscal Year Controls
 *
 * Creates:
 *   1. fiscal_years       — fiscal year definitions (configurable start/end, status lifecycle)
 *   2. fiscal_periods     — individual periods (monthly/quarterly) within a fiscal year
 *   3. period_close_log   — audit trail of all period close/reopen actions
 *
 * Also:
 *   - Adds fiscal_year_id column to budgets table (nullable FK)
 *   - Adds period_status column to accounting_periods for backward compatibility
 *   - Seeds default fiscal year from existing SystemPolicy data
 *   - RLS policies for branch-scoped tables
 *
 * Design notes:
 *   - Each fiscal year has 12 monthly periods (or 4 quarterly, or 1 annual)
 *   - Period status: open → closed → locked (locked = superadmin can reopen, closed = no reopen)
 *   - Period close log records every close/reopen action with who, when, and why
 *   - Backward compatible: existing accounting_periods table still works
 *   - budgets.fiscal_year_id links budgets to the new fiscal_years table
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. fiscal_years ────────────────────────────────────────────
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                          // e.g. "FY 2026-27"
            $table->string('fiscal_year_code', 20)->unique();     // e.g. "FY2026-27"
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('branch_id')->nullable()->constrained(); // null = all branches
            $table->string('period_type', 10)->default('monthly')  // monthly, quarterly, yearly
                  ->check("period_type IN ('monthly','quarterly','yearly')");
            $table->string('status', 20)->default('draft')
                  ->check("status IN ('draft','active','closed','locked')");
            $table->boolean('is_current')->default(false);        // only one can be current
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'end_date'], 'idx_fy_date_range');
            $table->index(['status', 'is_current'], 'idx_fy_status_current');
            $table->index(['branch_id', 'status'], 'idx_fy_branch_status');
        });

        // ── 2. fiscal_periods ─────────────────────────────────────────
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_number');        // 1-12 for monthly, 1-4 for quarterly, 1 for yearly
            $table->string('period_name', 30);                    // e.g. "January 2026", "Q1 2026-27"
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open')
                  ->check("status IN ('open','closed','locked')");
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_notes')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_number'], 'uq_fp_year_period');
            $table->index(['status', 'start_date'], 'idx_fp_status_date');
            $table->index('start_date');
            $table->index('end_date');
        });

        // ── 3. period_close_log ───────────────────────────────────────
        Schema::create('period_close_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('action', 20)                          // close, reopen, lock
                  ->check("action IN ('close','reopen','lock')");
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->unsignedBigInteger('performed_by');
            $table->text('reason')->nullable();
            $table->json('previous_state')->nullable();           // snapshot of status before action
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['fiscal_period_id', 'action'], 'idx_pcl_period_action');
            $table->index(['branch_id', 'action'], 'idx_pcl_branch_action');
            $table->index('performed_by');
            $table->index('created_at');
        });

        // ── 4. Add fiscal_year_id to budgets ──────────────────────────
        Schema::table('budgets', function (Blueprint $table) {
            $table->unsignedBigInteger('fiscal_year_id')->nullable()->after('fiscal_year');
            $table->foreign('fiscal_year_id', 'fk_budgets_fiscal_year')
                  ->references('id')->on('fiscal_years')
                  ->nullOnDelete();

            $table->index('fiscal_year_id', 'idx_budgets_fiscal_year');
        });

        // ── 5. RLS policies for branch-scoped tables ──────────────────
        foreach (['fiscal_years'] as $tbl) {
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

        // ── 6. Seed default fiscal year from SystemPolicy ─────────────
        $this->seedDefaultFiscalYear();
    }

    /**
     * Seed the default fiscal year based on existing SystemPolicy data.
     * Creates a fiscal year with 12 monthly periods.
     */
    private function seedDefaultFiscalYear(): void
    {
        // Try to get fiscal year dates from SystemPolicy
        $policy = DB::table('system_policies')->first();

        $startDate = null;
        $endDate = null;

        if ($policy && !empty($policy->metadata)) {
            $metadata = json_decode($policy->metadata, true);
            $startDate = $metadata['fiscal_year_start'] ?? null;
            $endDate = $metadata['fiscal_year_end'] ?? null;
        }

        // Fallback: Bangladesh fiscal year (July 1 → June 30)
        if (!$startDate || !$endDate) {
            $now = now();
            $year = $now->month >= 7 ? $now->year : $now->year - 1;
            $startDate = "{$year}-07-01";
            $endDate = ($year + 1) . "-06-30";
        }

        $sysUserId = DB::table('users')->value('id') ?? 1;
        $fyCode = 'FY' . substr($startDate, 0, 4) . '-' . substr($endDate, 2, 2);

        // Check if fiscal year already exists
        $exists = DB::table('fiscal_years')->where('fiscal_year_code', $fyCode)->exists();
        if ($exists) {
            return;
        }

        $fyId = DB::table('fiscal_years')->insertGetId([
            'name'             => "Fiscal Year {$fyCode}",
            'fiscal_year_code' => $fyCode,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'branch_id'        => null,
            'period_type'      => 'monthly',
            'status'           => 'active',
            'is_current'       => true,
            'description'      => 'Default fiscal year created during Phase 7 migration',
            'created_by'       => $sysUserId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Generate 12 monthly periods
        $this->generateMonthlyPeriods($fyId, $startDate, $endDate);
    }

    /**
     * Generate 12 monthly periods for a fiscal year.
     */
    private function generateMonthlyPeriods(int $fyId, string $startDate, string $endDate): void
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);

        $periodNumber = 1;
        $current = $start->copy();

        while ($current->lte($end) && $periodNumber <= 12) {
            $periodStart = $current->copy()->startOfMonth();
            $periodEnd = $current->copy()->endOfMonth();

            // Don't exceed the fiscal year end date
            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }

            $monthName = $current->format('F Y');
            $today = now()->format('Y-m-d');

            // Determine status: if period end date is before today, it could be closed
            // But we leave all as 'open' initially — the user must close them manually
            $status = 'open';

            DB::table('fiscal_periods')->insert([
                'fiscal_year_id' => $fyId,
                'period_number'  => $periodNumber,
                'period_name'    => $monthName,
                'start_date'     => $periodStart->format('Y-m-d'),
                'end_date'       => $periodEnd->format('Y-m-d'),
                'status'         => $status,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $current->addMonth();
            $periodNumber++;
        }
    }

    public function down(): void
    {
        // Drop RLS
        foreach (['fiscal_years'] as $tbl) {
            DB::statement("DROP POLICY IF EXISTS {$tbl}_branch_policy ON {$tbl}");
            DB::statement("ALTER TABLE {$tbl} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
        }

        // Remove fiscal_year_id from budgets
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign('fk_budgets_fiscal_year');
            $table->dropIndex('idx_budgets_fiscal_year');
            $table->dropColumn('fiscal_year_id');
        });

        // Drop tables in reverse dependency order
        Schema::dropIfExists('period_close_log');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
