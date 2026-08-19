<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: Add 'fulfilled' and 'cancelled' back to the branch_demands status
 * CHECK constraint.
 *
 * Migration 2026_07_29_000010 removed 'fulfilled' and 'cancelled' from the
 * allowed status values, but the application (BranchDeactivationUnitTest,
 * BranchToggleTest) legitimately uses these statuses. Re-add them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE branch_demands
            DROP CONSTRAINT IF EXISTS branch_demands_status_check
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD CONSTRAINT branch_demands_status_check
            CHECK (status IN ('pending','approved','received','rejected','fulfilled','cancelled','reversed'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE branch_demands
            DROP CONSTRAINT IF EXISTS branch_demands_status_check
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD CONSTRAINT branch_demands_status_check
            CHECK (status IN ('pending','received','rejected','reversed'))
        ");
    }
};
