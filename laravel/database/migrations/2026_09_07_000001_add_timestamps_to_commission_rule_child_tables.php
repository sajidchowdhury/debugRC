<?php

/**
 * G-305 (G16) LOW-E: add `updated_at` to the 3 commission-rule child tables.
 *
 * Source: AI_CONTEXT/sales/commission.md §11 G16 (around L459).
 *
 * The 3 commission-rule child tables — `commission_rule_tiers`,
 * `commission_rule_product_groups`, `commission_rule_targets` — were
 * created by migration `2025_01_22_000001_create_commission_tracking.php`
 * with ONLY `created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP`. They have
 * NO `updated_at` column, so audit trail for tier-rate / group-rate /
 * target changes is impossible. When a salesman's tier rate is edited,
 * the change is invisible — `created_at` never moves and there's no
 * `updated_at` to capture it.
 *
 * This migration adds a nullable `updated_at` column to each of the 3
 * tables (verified: `created_at` already exists on all 3 — see migration
 * 2025_01_22_000001 L180, L203, L236 — so only `updated_at` is added).
 * The corresponding Eloquent models (`CommissionRuleTier`,
 * `CommissionRuleProductGroup`, `CommissionRuleTarget`) drop
 * `public $timestamps = false;` + the `CREATED_AT`/`UPDATED_AT = null`
 * constants so Eloquent's timestamp magic fires on future creates/updates.
 *
 * The column is added as nullable (no NOT NULL constraint, no DEFAULT)
 * so existing rows survive the ALTER without backfill. New rows will get
 * `updated_at = NOW()` populated automatically by Eloquent on insert +
 * update; existing rows remain NULL (acceptable — they predate the
 * audit-trail feature, and we cannot synthesize a meaningful timestamp).
 *
 * Cross-ref (commission.md §11 G16 note): `CommissionService::createRule`
 * uses Eloquent `CommissionRuleTier::create()` / `::create()` on the
 * other two child models — these DO fire model events, so once the
 * `$timestamps = false` flag is removed from the 3 models, all future
 * creates + edits will populate `updated_at` automatically. Raw
 * `DB::table('commission_rule_tiers')->insert(...)` call sites (if any)
 * would still bypass timestamps — none were found in the codebase at
 * migration time, but the schema prerequisite (this migration) is
 * orthogonal to that follow-up audit.
 *
 * Idempotent: `Schema::hasColumn` guards each ADD COLUMN.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── TABLE 1: commission_rule_tiers ──────────────────────────────
        // Migration 2025_01_22_000001 L172-182: has `created_at`, no `updated_at`.
        Schema::table('commission_rule_tiers', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_rule_tiers', 'updated_at')) {
                $table->timestamp('updated_at', 0)->nullable()->after('created_at');
            }
        });

        // ── TABLE 2: commission_rule_product_groups ─────────────────────
        // Migration 2025_01_22_000001 L198-206: has `created_at`, no `updated_at`.
        Schema::table('commission_rule_product_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_rule_product_groups', 'updated_at')) {
                $table->timestamp('updated_at', 0)->nullable()->after('created_at');
            }
        });

        // ── TABLE 3: commission_rule_targets ────────────────────────────
        // Migration 2025_01_22_000001 L227-238: has `created_at`, no `updated_at`.
        Schema::table('commission_rule_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_rule_targets', 'updated_at')) {
                $table->timestamp('updated_at', 0)->nullable()->after('created_at');
            }
        });

        echo "  G-305: added nullable updated_at column to commission_rule_tiers, commission_rule_product_groups, commission_rule_targets.\n";
    }

    public function down(): void
    {
        // Drop the column if it exists. Safe to call on tables that no longer
        // have the column (idempotent). NOTE: existing NULL updated_at values
        // are lost — they predate the audit-trail feature anyway.
        foreach ([
            'commission_rule_tiers',
            'commission_rule_product_groups',
            'commission_rule_targets',
        ] as $table) {
            if (Schema::hasColumn($table, 'updated_at')) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN updated_at");
            }
        }
    }
};
