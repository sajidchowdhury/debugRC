<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: Intercompany & Consolidation
 *
 * Creates:
 *   1. companies            — legal entities that own one or more branches
 *   2. consolidation_runs   — audit trail of each consolidation execution
 *   3. elimination_rules    — configurable rules for which accounts to eliminate
 *   4. elimination_entries  — the actual elimination journal entries generated
 *
 * Also:
 *   - Adds company_id to branches table (nullable FK, null = no company assigned)
 *   - Adds is_elimination flag to ledgers table
 *   - Seeds elimination ledger accounts (elimination_receivable, elimination_payable,
 *     elimination_revenue, elimination_cogs, elimination_investment)
 *   - Adds elimination natures to LedgerNatureService EXTENDED_NATURES
 *   - RLS policies for company-scoped tables
 *   - Materialized view mv_consolidated_trial_balance for consolidated reporting
 *
 * Architecture:
 *   The system currently treats branches as operating locations within a single legal
 *   entity. Phase 8 introduces the concept of "companies" (legal entities) above
 *   branches. Each company can own multiple branches. Consolidation is the process
 *   of combining the financial statements of all companies into a single group view,
 *   with elimination of intercompany balances and transactions.
 *
 *   Key design decisions:
 *   - company_id on branches is nullable — branches without a company are treated
 *     as part of the "default" company (the original single entity)
 *   - Elimination rules are configurable — users define which ledger natures to
 *     eliminate (e.g., interbranch_receivable vs interbranch_payable)
 *   - Elimination entries are stored as regular journal entries with source='elimination'
 *     and a special reference_type='consolidation_elimination'
 *   - Consolidation runs are auditable — each run records who, when, which periods,
 *     and the status (draft/posted/reversed)
 *   - No multi-currency — all BDT (single currency). Future phases can add FX translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. companies ─────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_code', 20)->unique();       // e.g. "RC-GRP", "RC-PAT"
            $table->string('company_name', 100);                 // e.g. "RC Group Holdings"
            $table->string('legal_name', 150)->nullable();       // Registered legal name
            $table->string('tax_id', 50)->nullable();            // e.g. TIN/BIN
            $table->string('registration_no', 50)->nullable();   // Company registration number
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('currency', 3)->default('BDT');       // ISO 4217 currency code
            $table->boolean('is_consolidation_parent')->default(false);  // The parent company
            $table->decimal('ownership_pct', 5, 2)->default(100.00);     // Ownership % (for minority interest)
            $table->string('status', 20)->default('active')
                  ->check("status IN ('active','inactive','dormant')");
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_consolidation_parent'], 'idx_co_status_parent');
            $table->index('company_code');
        });

        // ── 2. consolidation_runs ────────────────────────────────────
        Schema::create('consolidation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_code', 30)->unique();           // e.g. "CONS-2026-07-001"
            $table->string('name', 100);                         // e.g. "Q2 2026 Consolidation"
            $table->date('period_from');                          // Start of consolidation period
            $table->date('period_to');                            // End of consolidation period
            $table->string('status', 20)->default('draft')
                  ->check("status IN ('draft','posted','reversed')");
            $table->unsignedBigInteger('fiscal_year_id')->nullable();  // Link to fiscal year
            $table->foreign('fiscal_year_id', 'fk_consolidation_fy')
                  ->references('id')->on('fiscal_years')->nullOnDelete();
            $table->json('company_ids')->nullable();             // Companies included in this run
            $table->json('elimination_summary')->nullable();     // Summary of elimination amounts
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'period_from', 'period_to'], 'idx_cr_status_period');
            $table->index('period_from');
            $table->index('period_to');
            $table->index('fiscal_year_id');
        });

        // ── 3. elimination_rules ─────────────────────────────────────
        Schema::create('elimination_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code', 30)->unique();          // e.g. "ELIM-IC-BAL"
            $table->string('rule_name', 100);                    // e.g. "Intercompany Balance Elimination"
            $table->string('rule_type', 30)->default('balance')
                  ->check("rule_type IN ('balance','revenue','investment','dividend','custom')");
            $table->string('description', 255)->nullable();

            // Debit-side ledger to eliminate (e.g., interbranch_receivable)
            $table->unsignedBigInteger('debit_ledger_id');
            $table->foreign('debit_ledger_id', 'fk_er_debit_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            // Credit-side ledger to eliminate (e.g., interbranch_payable)
            $table->unsignedBigInteger('credit_ledger_id');
            $table->foreign('credit_ledger_id', 'fk_er_credit_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            // Elimination contra accounts (where the offset posts)
            $table->unsignedBigInteger('elimination_debit_ledger_id')->nullable();
            $table->foreign('elimination_debit_ledger_id', 'fk_er_elim_debit_ledger')
                  ->references('id')->on('ledgers')->nullOnDelete();

            $table->unsignedBigInteger('elimination_credit_ledger_id')->nullable();
            $table->foreign('elimination_credit_ledger_id', 'fk_er_elim_credit_ledger')
                  ->references('id')->on('ledgers')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rule_type', 'is_active'], 'idx_er_type_active');
            $table->index('is_active');
        });

        // ── 4. elimination_entries ───────────────────────────────────
        Schema::create('elimination_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consolidation_run_id');
            $table->foreign('consolidation_run_id', 'fk_ee_consolidation_run')
                  ->references('id')->on('consolidation_runs')->cascadeOnDelete();

            $table->unsignedBigInteger('elimination_rule_id');
            $table->foreign('elimination_rule_id', 'fk_ee_elimination_rule')
                  ->references('id')->on('elimination_rules')->restrictOnDelete();

            // The journal entry that was created for this elimination
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'fk_ee_journal_entry')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            // Branch pair being eliminated
            $table->unsignedBigInteger('from_branch_id')->nullable();
            $table->foreign('from_branch_id', 'fk_ee_from_branch')
                  ->references('id')->on('branches')->nullOnDelete();

            $table->unsignedBigInteger('to_branch_id')->nullable();
            $table->foreign('to_branch_id', 'fk_ee_to_branch')
                  ->references('id')->on('branches')->nullOnDelete();

            // Ledger being eliminated (debit side)
            $table->unsignedBigInteger('debit_ledger_id');
            $table->foreign('debit_ledger_id', 'fk_ee_debit_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            // Ledger being eliminated (credit side)
            $table->unsignedBigInteger('credit_ledger_id');
            $table->foreign('credit_ledger_id', 'fk_ee_credit_ledger')
                  ->references('id')->on('ledgers')->restrictOnDelete();

            $table->decimal('elimination_amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['consolidation_run_id', 'elimination_rule_id'], 'idx_ee_run_rule');
            $table->index('journal_entry_id');
            $table->index(['from_branch_id', 'to_branch_id'], 'idx_ee_branch_pair');
        });

        // ── 5. Add company_id to branches ───────────────────────────
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('branch_name');
            $table->foreign('company_id', 'fk_branches_company')
                  ->references('id')->on('companies')->nullOnDelete();

            $table->index('company_id', 'idx_branches_company');
        });

        // ── 6. Add is_elimination flag to ledgers ───────────────────
        Schema::table('ledgers', function (Blueprint $table) {
            $table->boolean('is_elimination')->default(false)->after('is_system');
            $table->index('is_elimination', 'idx_ledgers_elimination');
        });

        // ── 7. Add source='elimination' to journal_entries check ────
        // The existing source column check constraint may not include 'elimination'.
        // We drop and recreate the constraint if it exists.
        // PostgreSQL doesn't support ALTER CONSTRAINT, so we drop and recreate.
        $hasSourceCheck = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.check_constraints cc
            JOIN information_schema.table_constraints tc ON tc.constraint_name = cc.constraint_name
            WHERE tc.table_name = 'journal_entries'
              AND tc.constraint_type = 'CHECK'
              AND cc.check_clause LIKE '%source%'
        ");

        if ($hasSourceCheck && (int) $hasSourceCheck->cnt > 0) {
            // Find and drop the existing source check constraint
            $constraints = DB::select("
                SELECT tc.constraint_name
                FROM information_schema.table_constraints tc
                WHERE tc.table_name = 'journal_entries'
                  AND tc.constraint_type = 'CHECK'
                  AND tc.constraint_name LIKE '%source%'
            ");
            foreach ($constraints as $c) {
                DB::statement("ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS {$c->constraint_name}");
            }
            // Recreate with 'elimination' included
            DB::statement("
                ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_check
                CHECK (source IN ('manual','auto','reversal','system','year_end_close','elimination'))
            ");
        } else {
            // No existing constraint — just add one
            DB::statement("
                ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_check
                CHECK (source IN ('manual','auto','reversal','system','year_end_close','elimination'))
            ");
        }

        // ── 8. Seed elimination ledger accounts ──────────────────────
        $this->seedEliminationLedgers();

        // ── 9. Seed default company ─────────────────────────────────
        $this->seedDefaultCompany();

        // ── 10. Seed default elimination rules ───────────────────────
        $this->seedDefaultEliminationRules();

        // ── 11. RLS policies ────────────────────────────────────────
        foreach (['companies'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_admin_policy ON {$tbl}
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
            ");
        }

        // Consolidation runs and elimination entries are admin-only
        foreach (['consolidation_runs', 'elimination_entries', 'elimination_rules'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_admin_policy ON {$tbl}
                USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR EXISTS (
                        SELECT 1 FROM users u
                        WHERE u.id = current_setting('app.branch_id', true)::int
                        AND u.role IN ('admin', 'superadmin', 'manager', 'accountant')
                    )
                )
            ");
        }

        // ── 12. Materialized view for consolidated trial balance ────
        $this->createConsolidatedTrialBalanceView();
    }

    /**
     * Seed elimination ledger accounts into the chart of accounts.
     *
     * These are contra accounts used during consolidation to offset
     * intercompany balances. They are marked is_elimination=true
     * and is_system=true so they cannot be accidentally deleted.
     *
     * Account structure:
     *   L-0106  Elimination Receivable    (Asset, debit)  — offsets interbranch_receivable
     *   L-0304  Elimination Payable       (Liability, credit) — offsets interbranch_payable
     *   L-0403  Elimination Revenue       (Equity, credit) — offsets intercompany revenue
     *   L-0504  Elimination COGS          (Equity, debit) — offsets intercompany COGS
     *   L-0404  Elimination Investment    (Equity, credit) — offsets investment in subsidiary
     */
    private function seedEliminationLedgers(): void
    {
        $sysUserId = DB::table('users')->value('id') ?? 1;

        $ledgers = [
            [
                'ledger_code' => 'L-0106',
                'ledger_name' => 'Elimination Receivable',
                'parent_id' => 0,
                'account_type' => 'Asset',
                'ledger_nature' => 'elimination_receivable',
                'normal_balance' => 'debit',
                'is_elimination' => true,
                'is_system' => true,
                'is_active' => true,
                'is_control_account' => false,
                'sort_order' => 60,
            ],
            [
                'ledger_code' => 'L-0304',
                'ledger_name' => 'Elimination Payable',
                'parent_id' => 0,
                'account_type' => 'Liability',
                'ledger_nature' => 'elimination_payable',
                'normal_balance' => 'credit',
                'is_elimination' => true,
                'is_system' => true,
                'is_active' => true,
                'is_control_account' => false,
                'sort_order' => 40,
            ],
            [
                'ledger_code' => 'L-0403',
                'ledger_name' => 'Elimination Revenue',
                'parent_id' => 0,
                'account_type' => 'Equity',
                'ledger_nature' => 'elimination_revenue',
                'normal_balance' => 'credit',
                'is_elimination' => true,
                'is_system' => true,
                'is_active' => true,
                'is_control_account' => false,
                'sort_order' => 30,
            ],
            [
                'ledger_code' => 'L-0504',
                'ledger_name' => 'Elimination COGS',
                'parent_id' => 0,
                'account_type' => 'Equity',
                'ledger_nature' => 'elimination_cogs',
                'normal_balance' => 'debit',
                'is_elimination' => true,
                'is_system' => true,
                'is_active' => true,
                'is_control_account' => false,
                'sort_order' => 40,
            ],
            [
                'ledger_code' => 'L-0404',
                'ledger_name' => 'Elimination Investment',
                'parent_id' => 0,
                'account_type' => 'Equity',
                'ledger_nature' => 'elimination_investment',
                'normal_balance' => 'credit',
                'is_elimination' => true,
                'is_system' => true,
                'is_active' => true,
                'is_control_account' => false,
                'sort_order' => 40,
            ],
        ];

        foreach ($ledgers as $ledger) {
            // Use ON CONFLICT to make it idempotent
            DB::table('ledgers')->upsert(
                array_merge($ledger, [
                    'created_by' => $sysUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                ['ledger_code'],
                [
                    'ledger_name' => $ledger['ledger_name'],
                    'ledger_nature' => $ledger['ledger_nature'],
                    'is_elimination' => $ledger['is_elimination'],
                    'is_system' => $ledger['is_system'],
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Seed a default company that owns all existing branches.
     * This ensures backward compatibility — existing branches are
     * automatically assigned to the default company.
     */
    private function seedDefaultCompany(): void
    {
        $sysUserId = DB::table('users')->value('id') ?? 1;

        // Create the default/parent company
        $companyId = DB::table('companies')->insertGetId([
            'company_code' => 'RC-GRP',
            'company_name' => 'RC Group',
            'legal_name' => 'RC Group Holdings Ltd.',
            'tax_id' => null,
            'registration_no' => null,
            'address' => null,
            'phone' => null,
            'email' => null,
            'currency' => 'BDT',
            'is_consolidation_parent' => true,
            'ownership_pct' => 100.00,
            'status' => 'active',
            'description' => 'Default consolidation parent company — owns all branches',
            'created_by' => $sysUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign all existing branches to this company
        DB::table('branches')->whereNull('deleted_at')->update([
            'company_id' => $companyId,
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed default elimination rules based on the existing intercompany
     * ledger accounts (L-0105 Due from Branches, L-0303 Due to Branches).
     */
    private function seedDefaultEliminationRules(): void
    {
        $sysUserId = DB::table('users')->value('id') ?? 1;

        // Get the intercompany ledger IDs
        $interbranchReceivable = DB::table('ledgers')->where('ledger_code', 'L-0105')->value('id');
        $interbranchPayable = DB::table('ledgers')->where('ledger_code', 'L-0303')->value('id');
        $eliminationReceivable = DB::table('ledgers')->where('ledger_code', 'L-0106')->value('id');
        $eliminationPayable = DB::table('ledgers')->where('ledger_code', 'L-0304')->value('id');

        if (!$interbranchReceivable || !$interbranchPayable) {
            return; // Can't create rules without the intercompany accounts
        }

        $rules = [
            [
                'rule_code' => 'ELIM-IC-BAL',
                'rule_name' => 'Intercompany Balance Elimination',
                'rule_type' => 'balance',
                'description' => 'Eliminates Due from Branches (L-0105) against Due to Branches (L-0303) to present a consolidated balance sheet without intercompany balances.',
                'debit_ledger_id' => $interbranchReceivable,
                'credit_ledger_id' => $interbranchPayable,
                'elimination_debit_ledger_id' => $eliminationPayable,
                'elimination_credit_ledger_id' => $eliminationReceivable,
                'is_active' => true,
                'sort_order' => 10,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('elimination_rules')->upsert(
                array_merge($rule, [
                    'created_by' => $sysUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                ['rule_code'],
                [
                    'rule_name' => $rule['rule_name'],
                    'description' => $rule['description'],
                    'is_active' => $rule['is_active'],
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Create the materialized view for consolidated trial balance.
     *
     * This view aggregates all branch-level trial balances and applies
     * elimination adjustments to produce a consolidated view.
     *
     * For the initial version, it shows:
     *   - Each ledger with its total debit/credit across all branches
     *   - The elimination adjustment amount
     *   - The net consolidated amount
     */
    private function createConsolidatedTrialBalanceView(): void
    {
        DB::statement(<<<'SQL'
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_consolidated_trial_balance AS
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.normal_balance,
    l.is_elimination,
    -- Aggregate across all branches
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    -- Elimination adjustment (from elimination entries)
    COALESCE(elim.elim_debit, 0) AS elimination_debit,
    COALESCE(elim.elim_credit, 0) AS elimination_credit,
    -- Consolidated (net of elimination)
    COALESCE(SUM(jl.debit), 0) - COALESCE(elim.elim_debit, 0) AS consolidated_debit,
    COALESCE(SUM(jl.credit), 0) - COALESCE(elim.elim_credit, 0) AS consolidated_credit
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.source != 'elimination'
LEFT JOIN LATERAL (
    SELECT
        SUM(elim_jl.debit) AS elim_debit,
        SUM(elim_jl.credit) AS elim_credit
    FROM elimination_entries ee
    JOIN consolidation_runs cr ON cr.id = ee.consolidation_run_id
    JOIN journal_entries elim_je ON elim_je.id = ee.journal_entry_id
    JOIN journal_lines elim_jl ON elim_jl.journal_entry_id = elim_je.id
    WHERE elim_jl.ledger_id = l.id
      AND cr.status = 'posted'
      AND COALESCE(elim_je.is_reversed, false) = false
) elim ON TRUE
WHERE l.is_active = true
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
         l.normal_balance, l.is_elimination, elim.elim_debit, elim.elim_credit
SQL);

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS mv_ctb_ledger_idx ON mv_consolidated_trial_balance (ledger_id)'
        );
    }

    public function down(): void
    {
        // Drop materialized view
        DB::statement("DROP MATERIALIZED VIEW IF EXISTS mv_consolidated_trial_balance CASCADE");

        // Drop RLS
        foreach (['companies', 'consolidation_runs', 'elimination_entries', 'elimination_rules'] as $tbl) {
            DB::statement("DROP POLICY IF EXISTS {$tbl}_admin_policy ON {$tbl}");
            DB::statement("ALTER TABLE {$tbl} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
        }

        // Drop source check constraint
        DB::statement("ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_check");

        // Remove is_elimination from ledgers
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropIndex('idx_ledgers_elimination');
            $table->dropColumn('is_elimination');
        });

        // Remove company_id from branches
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign('fk_branches_company');
            $table->dropIndex('idx_branches_company');
            $table->dropColumn('company_id');
        });

        // Drop tables in reverse dependency order
        Schema::dropIfExists('elimination_entries');
        Schema::dropIfExists('elimination_rules');
        Schema::dropIfExists('consolidation_runs');
        Schema::dropIfExists('companies');

        // Remove elimination ledger accounts
        DB::table('ledgers')->where('is_elimination', true)->delete();
    }
};
