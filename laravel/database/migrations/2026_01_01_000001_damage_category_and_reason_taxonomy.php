<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Damage Phase 1 — Damage Category & Reason Taxonomy.
 *
 * Solves the #1 accountability gap surfaced in the GAP analysis: the Laravel
 * damage module lumped EVERY write-off into a single free-text `reason` field,
 * making it impossible to distinguish **real damage** (physical breakage /
 * spoilage / expiry) from **missing / unaccounted stock** — which was the
 * user's core complaint ("employees just can't find it and declare it as
 * damage, no accountability").
 *
 * This migration introduces:
 *
 *   1. A structured `damage_type` enum column on `damage_invoices`:
 *        real_damage     — physical breakage / spoilage / expiry / fire / water / transit
 *        missing         — not found in warehouse, no physical damage (the core complaint)
 *        theft           — suspected / confirmed theft
 *        quality_reject  — failed QC
 *        customer_return — auto-created from a sales return (existing linked-damage flow)
 *        other           — fallback
 *      Enforced by a DB-level CHECK constraint so no typo / drift is possible.
 *
 *   2. A split of the old free-text `reason` into:
 *        reason_code   varchar(50) nullable — references damage_reasons.reason_code
 *        reason_detail text nullable         — optional extra context
 *      The legacy `reason` text column is KEPT for backward compatibility and
 *      for migrated rows that only have free-text.
 *
 *   3. A `damage_reasons` taxonomy table (global master data — not
 *      branch-scoped) seeded with ~15 standard reason codes mapped to their
 *      damage_type. This powers a structured dropdown on the create form that
 *      filters by the selected damage_type.
 *
 *   4. RLS on `damage_reasons`: SELECT visible to everyone (it's reference
 *      data); INSERT/UPDATE/DELETE restricted to admin (master-data
 *      stewardship). Mirrors the defense-in-depth philosophy used on the
 *      transactional tables.
 *
 * Backfill strategy:
 *   - Rows with a non-null `sales_return_id` (auto-created linked damages)
 *     → `damage_type = 'customer_return'`.
 *   - Everything else → `damage_type = 'real_damage'` (safe default — the
 *     historical behavior treated all manual damages as physical write-offs).
 *
 * Idempotent: every step is guarded by hasColumn / pg_constraint / pg_indexes
 * / ON CONFLICT checks so re-running is safe.
 *
 * GL impact (wired in DamageService::postDamageGL, not here):
 *   The loss ledger is now chosen by damage_type:
 *     real_damage / quality_reject / customer_return / other → damage_loss
 *     missing / theft                                        → inventory_shrinkage
 *   Both natures are already in ReportService's operating_expenses rollup
 *   (Phase 0 fix), so the P&L now splits damage by type automatically.
 *
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md  Phase 1
 * @see app/Models/DamageInvoice.php        DAMAGE_TYPES constant
 * @see app/Models/DamageReason.php
 * @see app/Services/Stock/DamageService.php  createDamage + postDamageGL
 */
return new class extends Migration
{
    /**
     * The six valid damage types. Kept in sync with
     * DamageInvoice::DAMAGE_TYPES and the CHECK constraints below.
     */
    private const DAMAGE_TYPES = [
        'real_damage',
        'missing',
        'theft',
        'quality_reject',
        'customer_return',
        'other',
    ];

    /**
     * Standard reason taxonomy seeded into damage_reasons.
     * reason_code => [label, damage_type, sort_order]
     *
     * Branches can later add their own via the (future) admin master-data UI;
     * these are the system defaults every install gets.
     */
    private const SEED_REASONS = [
        // real_damage — physical write-offs
        ['reason_code' => 'breakage_forklift',     'label' => 'Breakage — forklift / handling', 'damage_type' => 'real_damage',    'sort_order' => 10],
        ['reason_code' => 'breakage_handling',     'label' => 'Breakage — manual handling',     'damage_type' => 'real_damage',    'sort_order' => 11],
        ['reason_code' => 'expiry_shelf_life',     'label' => 'Expired — past shelf life',      'damage_type' => 'real_damage',    'sort_order' => 12],
        ['reason_code' => 'spoilage_damaged_pack', 'label' => 'Spoilage — damaged packaging',   'damage_type' => 'real_damage',    'sort_order' => 13],
        ['reason_code' => 'water_damage',          'label' => 'Water / moisture damage',         'damage_type' => 'real_damage',    'sort_order' => 14],
        ['reason_code' => 'fire_damage',           'label' => 'Fire damage',                     'damage_type' => 'real_damage',    'sort_order' => 15],
        ['reason_code' => 'transit_damage',        'label' => 'Transit / transport damage',      'damage_type' => 'real_damage',    'sort_order' => 16],

        // missing — unaccounted stock (the core accountability gap)
        ['reason_code' => 'not_found_warehouse',          'label' => 'Not found in warehouse (unaccounted)', 'damage_type' => 'missing', 'sort_order' => 20],
        ['reason_code' => 'not_found_after_dispatch',     'label' => 'Missing after dispatch',               'damage_type' => 'missing', 'sort_order' => 21],
        ['reason_code' => 'stock_count_short',            'label' => 'Shortage found during stock count',    'damage_type' => 'missing', 'sort_order' => 22],

        // theft
        ['reason_code' => 'suspected_theft', 'label' => 'Suspected theft (under investigation)', 'damage_type' => 'theft', 'sort_order' => 30],
        ['reason_code' => 'confirmed_theft', 'label' => 'Confirmed theft (reported)',            'damage_type' => 'theft', 'sort_order' => 31],

        // quality_reject
        ['reason_code' => 'qc_fail_inbound',     'label' => 'QC fail — inbound goods rejected', 'damage_type' => 'quality_reject', 'sort_order' => 40],
        ['reason_code' => 'qc_fail_production',  'label' => 'QC fail — production defect',      'damage_type' => 'quality_reject', 'sort_order' => 41],

        // customer_return — auto-linked (manual reason codes for standalone return-damages)
        ['reason_code' => 'returned_damaged', 'label' => 'Customer return — damaged', 'damage_type' => 'customer_return', 'sort_order' => 50],
        ['reason_code' => 'returned_expired', 'label' => 'Customer return — expired',  'damage_type' => 'customer_return', 'sort_order' => 51],

        // other
        ['reason_code' => 'other', 'label' => 'Other (explain in details)', 'damage_type' => 'other', 'sort_order' => 90],
    ];

    private const TYPE_CHECK_ON_INVOICES  = 'damage_invoices_type_check';
    private const TYPE_CHECK_ON_REASONS   = 'damage_reasons_type_check';

    public function up(): void
    {
        // ============================================================
        // 1. Add damage_type + reason_code + reason_detail to damage_invoices
        // ============================================================

        if (!Schema::hasColumn('damage_invoices', 'damage_type')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                // NOT NULL DEFAULT 'real_damage' so the ALTER itself backfills
                // existing rows with a safe value. A targeted UPDATE below
                // reclassifies the sales-return-linked rows to 'customer_return'.
                $table->string('damage_type', 30)
                      ->default('real_damage')
                      ->after('status');
            });
        }

        if (!Schema::hasColumn('damage_invoices', 'reason_code')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->string('reason_code', 50)
                      ->nullable()
                      ->after('reason');
            });
        }

        if (!Schema::hasColumn('damage_invoices', 'reason_detail')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->text('reason_detail')
                      ->nullable()
                      ->after('reason_code');
            });
        }

        // ============================================================
        // 2. Backfill damage_type on existing rows
        //    - sales-return-linked → 'customer_return'
        //    - everything else     → 'real_damage' (the DEFAULT already did
        //      this, but the explicit UPDATE guarantees correctness even if a
        //      row somehow landed with NULL / an unexpected value).
        // ============================================================
        DB::table('damage_invoices')
            ->whereNull('damage_type')
            ->orWhereNotIn('damage_type', self::DAMAGE_TYPES)
            ->update(['damage_type' => 'real_damage']);

        DB::table('damage_invoices')
            ->whereNotNull('sales_return_id')
            ->where('sales_return_id', '>', 0)
            ->update(['damage_type' => 'customer_return']);

        // ============================================================
        // 3. CHECK constraint on damage_invoices.damage_type (DB enum)
        // ============================================================
        $this->dropCheckConstraint('damage_invoices', self::TYPE_CHECK_ON_INVOICES);
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            self::DAMAGE_TYPES,
        ));
        DB::statement(<<<SQL
            ALTER TABLE damage_invoices
            ADD CONSTRAINT {$this->escIdent(self::TYPE_CHECK_ON_INVOICES)}
            CHECK (damage_type IN ({$values}))
        SQL);

        // ============================================================
        // 4. Index on damage_invoices.damage_type (list-page filter)
        // ============================================================
        $idxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = 'damage_invoices' AND indexname = 'idx_dmg_type'"
        ))->count();
        if (!$idxExists) {
            DB::statement(
                'CREATE INDEX idx_dmg_type ON damage_invoices(damage_type)'
            );
        }

        // ============================================================
        // 5. Create damage_reasons taxonomy table (global master data)
        // ============================================================
        if (!Schema::hasTable('damage_reasons')) {
            Schema::create('damage_reasons', function (Blueprint $table) {
                $table->id();  // bigIncrements → bigint identity (matches the
                               // GENERATED ALWAYS AS IDENTITY pattern on other
                               // tables when migrated via Eloquent)
                $table->string('reason_code', 50)->unique();
                $table->string('label', 120);
                $table->string('damage_type', 30);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                // Soft deletes: a reason referenced by historical damages must
                // never be hard-deleted (would orphan damage_invoices.reason_code
                // lookups). Deactivate (is_active=false) or soft-delete instead.
                $table->softDeletes();
            });
        }

        // CHECK constraint on damage_reasons.damage_type — same enum.
        $this->dropCheckConstraint('damage_reasons', self::TYPE_CHECK_ON_REASONS);
        DB::statement(<<<SQL
            ALTER TABLE damage_reasons
            ADD CONSTRAINT {$this->escIdent(self::TYPE_CHECK_ON_REASONS)}
            CHECK (damage_type IN ({$values}))
        SQL);

        // Helpful indexes for the create-form dropdown (filter by type, order).
        $this->ensureIndex('damage_reasons', 'idx_dr_type_active',
            'CREATE INDEX idx_dr_type_active ON damage_reasons(damage_type, is_active, sort_order)');

        // ============================================================
        // 6. Seed standard reasons (idempotent via ON CONFLICT)
        //    Run BEFORE enabling RLS so the inserts always succeed
        //    regardless of the app.is_admin GUC state at migration time.
        // ============================================================
        $now = now();
        foreach (self::SEED_REASONS as $r) {
            DB::table('damage_reasons')->updateOrInsert(
                ['reason_code' => $r['reason_code']],
                [
                    'label'       => $r['label'],
                    'damage_type' => $r['damage_type'],
                    'is_active'   => true,
                    'sort_order'  => $r['sort_order'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );
        }

        // ============================================================
        // 7. RLS on damage_reasons — master-data stewardship
        //    SELECT: visible to everyone (reference data)
        //    INSERT/UPDATE/DELETE: admin only (app.is_admin = 'true')
        //
        //    damage_reasons has NO branch_id (it's global), so the policy is
        //    role-based, not branch-based. Admins manage the taxonomy; everyone
        //    else reads it. This is defense-in-depth on top of the (future)
        //    admin-only management route.
        // ============================================================
        DB::statement('ALTER TABLE damage_reasons ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE damage_reasons FORCE ROW LEVEL SECURITY');

        DB::statement("DROP POLICY IF EXISTS rls_damage_reasons_select ON damage_reasons");
        DB::statement(
            "CREATE POLICY rls_damage_reasons_select ON damage_reasons
             FOR SELECT USING (true)"
        );

        DB::statement("DROP POLICY IF EXISTS rls_damage_reasons_insert ON damage_reasons");
        DB::statement(
            "CREATE POLICY rls_damage_reasons_insert ON damage_reasons
             FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true')"
        );

        DB::statement("DROP POLICY IF EXISTS rls_damage_reasons_update ON damage_reasons");
        DB::statement(
            "CREATE POLICY rls_damage_reasons_update ON damage_reasons
             FOR UPDATE USING (current_setting('app.is_admin', true) = 'true')
             WITH CHECK (current_setting('app.is_admin', true) = 'true')"
        );

        DB::statement("DROP POLICY IF EXISTS rls_damage_reasons_delete ON damage_reasons");
        DB::statement(
            "CREATE POLICY rls_damage_reasons_delete ON damage_reasons
             FOR DELETE USING (current_setting('app.is_admin', true) = 'true')"
        );
    }

    public function down(): void
    {
        // --- RLS teardown on damage_reasons ---
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("DROP POLICY IF EXISTS rls_damage_reasons_{$op} ON damage_reasons");
        }
        DB::statement('ALTER TABLE damage_reasons NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE damage_reasons DISABLE ROW LEVEL SECURITY');

        // --- Drop damage_reasons table ---
        $this->dropCheckConstraint('damage_reasons', self::TYPE_CHECK_ON_REASONS);
        Schema::dropIfExists('damage_reasons');

        // --- Remove damage_type / reason_code / reason_detail from damage_invoices ---
        DB::statement('DROP INDEX IF EXISTS idx_dmg_type');
        $this->dropCheckConstraint('damage_invoices', self::TYPE_CHECK_ON_INVOICES);

        Schema::table('damage_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('damage_invoices', 'reason_detail')) {
                $table->dropColumn('reason_detail');
            }
            if (Schema::hasColumn('damage_invoices', 'reason_code')) {
                $table->dropColumn('reason_code');
            }
            if (Schema::hasColumn('damage_invoices', 'damage_type')) {
                $table->dropColumn('damage_type');
            }
        });
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Drop a CHECK constraint by name if it exists (idempotent).
     */
    private function dropCheckConstraint(string $table, string $constraint): void
    {
        $exists = DB::table('pg_constraint')
            ->where('conname', $constraint)
            ->where('contype', 'c')
            ->exists();
        if ($exists) {
            DB::statement(
                'ALTER TABLE ' . $this->escIdent($table) .
                ' DROP CONSTRAINT ' . $this->escIdent($constraint)
            );
        }
    }

    /**
     * Create an index only if it doesn't already exist.
     */
    private function ensureIndex(string $table, string $indexName, string $ddl): void
    {
        $exists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        ))->count();
        if (!$exists) {
            DB::statement($ddl);
        }
    }

    /**
     * Quote a PostgreSQL identifier (table / constraint name) safely.
     */
    private function escIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
};
