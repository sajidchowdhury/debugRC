<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.1 — Core Foundation Hardening: Create manual_journal_lines table.
 *
 * Problem: Draft journals store only totals (total_debit, total_credit) in the
 * manual_journals header. Line detail (ledger_id, debit, credit per line) is
 * lost when the form is submitted as draft. The postJournal() method throws
 * because it has no lines to post.
 *
 * Solution: This table persists line detail for BOTH draft and posted journals.
 * When a draft is saved, lines are inserted here. When postJournal() is called,
 * it reads the lines from this table, validates Dr=Cr, calls postToGL(), and
 * marks the draft lines as "posted". This matches the "park document" pattern
 * in SAP and the "Optional voucher" pattern in Tally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('manual_journal_id')->index();
            $table->unsignedBigInteger('ledger_id')->index();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft, posted — tracks whether this line has been posted to GL');
            $table->unsignedBigInteger('journal_line_id')->nullable()
                ->comment('FK to journal_lines after posting to GL');
            $table->timestamps();

            // Foreign key to manual_journals
            $table->foreign('manual_journal_id')
                ->references('id')->on('manual_journals')
                ->cascadeOnDelete();

            // Check constraints: debit >= 0, credit >= 0, not both zero
            // (Applied via DB::statement after table creation)

            $table->index(['manual_journal_id', 'status'], 'idx_mjl_journal_status');
            $table->index('ledger_id', 'idx_mjl_ledger');
        });

        // Add check constraints using raw SQL (PostgreSQL-specific)
        DB::statement("
            ALTER TABLE manual_journal_lines
            ADD CONSTRAINT mjl_debit_non_negative CHECK (debit >= 0),
            ADD CONSTRAINT mjl_credit_non_negative CHECK (credit >= 0),
            ADD CONSTRAINT mjl_not_both_zero CHECK (debit > 0 OR credit > 0)
        ");

        // Add comment to the table
        DB::statement("
            COMMENT ON TABLE manual_journal_lines IS 'Phase 1.1: Stores line detail for manual journals (both draft and posted). Enables draft-to-post workflow.'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_journal_lines');
    }
};
