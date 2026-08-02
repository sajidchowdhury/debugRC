<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the pgcrypto extension for the financial audit trail.
 *
 * The fn_financial_audit_trigger() function uses digest() for SHA-256
 * cryptographic chaining of audit records. digest() is provided by the
 * pgcrypto extension which is not enabled by default in PostgreSQL.
 *
 * This migration must run BEFORE the audit trigger migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pgcrypto');
    }
};
