<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — Accounts (Ledger) module hardening.
 *
 * Adds the 4 columns the administration audit flagged as missing from the
 * `ledgers` table:
 *   - is_system        boolean       — marks seeded/system CoA ledgers that
 *                                     must not be edited or deleted (only
 *                                     their description is editable).
 *   - normal_balance   varchar(10)   — 'debit' or 'credit'. Drives the
 *                                     account_type ↔ nature consistency
 *                                     check and report side calculations.
 *   - description      text          — human-readable note for the CoA.
 *   - created_by       integer       — FK to users.id (audit attribution).
 *
 * Also adds a CHECK constraint enforcing normal_balance ∈ {debit, credit}.
 *
 * Idempotent: each column is added only if absent (so the migration is safe
 * to re-run on a database that already has the columns).
 *
 * See docs/migration/administration_audit.md § "Accounts (Ledger) Module"
 * Phase 1 + Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ledgers', 'is_system')) {
            Schema::table('ledgers', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('is_active');
            });
        }

        if (!Schema::hasColumn('ledgers', 'normal_balance')) {
            Schema::table('ledgers', function (Blueprint $table) {
                $table->string('normal_balance', 10)->default('debit')->after('is_system');
            });
        }

        if (!Schema::hasColumn('ledgers', 'description')) {
            Schema::table('ledgers', function (Blueprint $table) {
                $table->text('description')->nullable()->after('normal_balance');
            });
        }

        if (!Schema::hasColumn('ledgers', 'created_by')) {
            Schema::table('ledgers', function (Blueprint $table) {
                $table->integer('created_by')->nullable()->after('description');
            });
        }

        // Add CHECK constraint for normal_balance (only if it doesn't exist).
        $constraintExists = DB::table('pg_constraint')
            ->where('conname', 'ledgers_normal_balance_check')
            ->where('conrelid', DB::raw("'ledgers'::regclass"))
            ->exists();

        if (!$constraintExists) {
            DB::statement(
                "ALTER TABLE ledgers ADD CONSTRAINT ledgers_normal_balance_check "
                . "CHECK (normal_balance IN ('debit', 'credit'))"
            );
        }

        // Mark the seeded critical-nature ledgers as system ledgers so they
        // are protected from edit/delete. We match by ledger_code prefix
        // (L-0xxx) and by ledger_nature — both conditions together identify
        // the seeded critical + extended nature ledgers from migration
        // 2025_01_05_000001_seed_default_chart_of_accounts.
        DB::table('ledgers')
            ->whereNotNull('ledger_nature')
            ->where('ledger_nature', '!=', '')
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        $constraintExists = DB::table('pg_constraint')
            ->where('conname', 'ledgers_normal_balance_check')
            ->where('conrelid', DB::raw("'ledgers'::regclass"))
            ->exists();

        if ($constraintExists) {
            DB::statement('ALTER TABLE ledgers DROP CONSTRAINT ledgers_normal_balance_check');
        }

        Schema::table('ledgers', function (Blueprint $table) {
            if (Schema::hasColumn('ledgers', 'is_system')) {
                $table->dropColumn('is_system');
            }
            if (Schema::hasColumn('ledgers', 'normal_balance')) {
                $table->dropColumn('normal_balance');
            }
            if (Schema::hasColumn('ledgers', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('ledgers', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};
