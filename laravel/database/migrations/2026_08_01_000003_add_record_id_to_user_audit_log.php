<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add record_id column to user_audit_log.
 *
 * Root-cause fix for SQLSTATE[25P02] on Money Transfers:
 *
 * MoneyTransferService::logAudit() inserts a 'record_id' column that did not
 * exist on user_audit_log. PostgreSQL threw SQLSTATE[42703] (undefined column),
 * which was caught by a try/catch that swallowed the exception — leaving the
 * transaction in an aborted state. The next SQL (MoneyTransfer::find) then got
 * SQLSTATE[25P02] ("current transaction is aborted, commands ignored until end
 * of transaction block").
 *
 * This migration adds the missing column so the INSERT succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE user_audit_log
            ADD COLUMN IF NOT EXISTS record_id integer
        ");

        DB::statement("
            COMMENT ON COLUMN user_audit_log.record_id IS
            'ID of the affected record (e.g. money_transfer.id, journal_entry.id)'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE user_audit_log
            DROP COLUMN IF EXISTS record_id
        ");
    }
};
