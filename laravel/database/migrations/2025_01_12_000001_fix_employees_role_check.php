<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee Module Phase 1 — Database fix: role CHECK constraint.
 *
 * Audit Finding (Phase 12): The legacy MySQL `employees.role` column accepted
 * the 'user' value (used by config/roles.php + EmployeeController::validationRules()
 * `in:superadmin,admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other`).
 * The PG schema (01_auth_and_master.sql) created the CHECK constraint WITHOUT
 * the 'user' value — so saving an employee with role='user' raised a CHECK
 * violation.
 *
 * This migration replaces the constraint with one that includes all 10
 * canonical roles from config/roles.php.
 *
 * The 9 HR columns (father_name, mother_name, date_of_birth, nid, designation,
 * department, bank_account, blood_group, etc.) were also flagged by the audit
 * but are NOT referenced by any active Laravel code (EmployeeController, Employee
 * model, views). They are confined to app/Archive/* + legacy/* — skipping
 * restoration per Phase 12 task instructions (documented in worklog).
 *
 * Idempotent: drops the existing constraint before re-adding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_role_check');
        DB::statement(
            "ALTER TABLE employees ADD CONSTRAINT employees_role_check " .
            "CHECK (role::text = ANY (ARRAY[" .
            "'admin','salesman','warehouse_manager','dispatcher','accountant'," .
            "'hr','manager','other','superadmin','user'" .
            "]::text[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_role_check');
        DB::statement(
            "ALTER TABLE employees ADD CONSTRAINT employees_role_check " .
            "CHECK (role::text = ANY (ARRAY[" .
            "'admin','salesman','warehouse_manager','dispatcher','accountant'," .
            "'hr','manager','other','superadmin'" .
            "]::text[]))"
        );
    }
};
