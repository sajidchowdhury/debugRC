<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (Stock Take plan) — Add journal_line_id to stock_take_items.
 *
 * Per-line GL traceability: each variance item should link to the specific
 * journal_lines row that recorded its GL impact. This enables "drill from
 * a variance line to the exact GL line" — a capability the legacy system
 * lacked (it only had session-level journal_entry_id).
 *
 * The column is nullable (populated during postSession, null for items
 * with no variance or before posting). The FK to journal_lines(id) is
 * immediate-checked (not DEFERRABLE) — this is safe because the journal
 * entry + lines are created BEFORE the items are updated with journal_line_id,
 * all within the same DB::transaction.
 *
 * ON DELETE SET NULL: journal_lines are append-only (reversals create new
 * lines, never delete), but if a journal_entries row is ever hard-deleted
 * (ON DELETE CASCADE from journal_entries → journal_lines), the items'
 * journal_line_id becomes null rather than blocking the delete.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §7 Phase 1, scope item 4
 *   - app/Services/Stock/StockTakeService.php  postSession (journal_line_id capture)
 *   - database/sql/02_accounting.sql  journal_lines schema (lines 51-63)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_take_items', 'journal_line_id')) {
                $table->integer('journal_line_id')->nullable()->after('is_applied');
            }
        });

        // Add FK (immediate-checked; safe because lines are created before items are updated).
        $fkExists = collect(DB::select("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'sti_journal_line_id_fk'
              AND table_name = 'stock_take_items'
        "))->count();

        if (!$fkExists && Schema::hasColumn('stock_take_items', 'journal_line_id')) {
            DB::statement(
                'ALTER TABLE stock_take_items ' .
                'ADD CONSTRAINT sti_journal_line_id_fk ' .
                'FOREIGN KEY (journal_line_id) REFERENCES journal_lines(id) ON DELETE SET NULL'
            );
        }

        // Partial index: only rows with a journal_line_id (posted variance items).
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'stock_take_items' AND indexname = 'idx_sti_journal_line'"
        ))->count()) {
            DB::statement(
                'CREATE INDEX idx_sti_journal_line ON stock_take_items (journal_line_id) WHERE journal_line_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sti_journal_line');

        $fkExists = collect(DB::select("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'sti_journal_line_id_fk'
              AND table_name = 'stock_take_items'
        "))->count();

        if ($fkExists) {
            DB::statement('ALTER TABLE stock_take_items DROP CONSTRAINT IF EXISTS sti_journal_line_id_fk');
        }

        Schema::table('stock_take_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_take_items', 'journal_line_id')) {
                $table->dropColumn('journal_line_id');
            }
        });
    }
};
