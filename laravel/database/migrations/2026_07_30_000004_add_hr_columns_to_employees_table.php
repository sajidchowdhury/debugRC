<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add HR columns back to the employees table for legacy data migration.
 *
 * The original MySQL `employees` table had 9 extra HR columns that were
 * intentionally dropped during the MySQL→PostgreSQL migration (Phase 2)
 * because no active Laravel code referenced them. However, the legacy
 * database still contains data in these columns, and the business needs
 * to preserve that information.
 *
 * This migration adds the columns back as nullable so existing PG rows
 * are unaffected, and the legacy-migration command can populate them.
 *
 * Columns added:
 *   - father_name      varchar(100)  — employee's father's name
 *   - mother_name      varchar(100)  — employee's mother's name
 *   - date_of_birth    date          — date of birth
 *   - nid              varchar(30)   — national ID number
 *   - designation      varchar(100)  — job designation/title
 *   - department       varchar(100)  — department name
 *   - bank_account     varchar(50)   — bank account number for salary
 *   - blood_group      varchar(10)   — blood group (e.g. A+, O-)
 *   - mobile           varchar(20)   — mobile number (separate from phone)
 *
 * Note: The legacy MySQL table had `mobile` as a separate column from
 * the PG `phone` column. We add `mobile` back as a separate column.
 * The legacy migration command will map mobile → mobile (not phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'father_name')) {
                $table->string('father_name', 100)->nullable()->after('name');
            }
            if (!Schema::hasColumn('employees', 'mother_name')) {
                $table->string('mother_name', 100)->nullable()->after('father_name');
            }
            if (!Schema::hasColumn('employees', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('mother_name');
            }
            if (!Schema::hasColumn('employees', 'nid')) {
                $table->string('nid', 30)->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('employees', 'designation')) {
                $table->string('designation', 100)->nullable()->after('role');
            }
            if (!Schema::hasColumn('employees', 'department')) {
                $table->string('department', 100)->nullable()->after('designation');
            }
            if (!Schema::hasColumn('employees', 'bank_account')) {
                $table->string('bank_account', 50)->nullable()->after('department');
            }
            if (!Schema::hasColumn('employees', 'blood_group')) {
                $table->string('blood_group', 10)->nullable()->after('bank_account');
            }
            if (!Schema::hasColumn('employees', 'mobile')) {
                $table->string('mobile', 20)->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $columns = ['father_name', 'mother_name', 'date_of_birth', 'nid',
                        'designation', 'department', 'bank_account', 'blood_group', 'mobile'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
