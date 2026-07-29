<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 (Stock Adjustment plan) — Fix duplicate-product reversal (G11).
 *
 * PROPER FIX (revision): COMPOSITE FK into the partitioned stock_transactions.
 *
 * ── Problem this revision solves ─────────────────────────────────────────
 * The original version of this migration created a single-column FK:
 *
 *     ALTER TABLE stock_adjustment_items
 *       ADD CONSTRAINT sai_stock_tx_fk
 *       FOREIGN KEY (stock_transaction_id) REFERENCES stock_transactions(id)
 *       ON DELETE SET NULL;
 *
 * which FAILED on PostgreSQL with:
 *
 *     SQLSTATE[42830]: Invalid foreign key: 7 ERROR: there is no unique
 *     constraint matching given keys for referenced table "stock_transactions"
 *
 * ── Root cause ───────────────────────────────────────────────────────────
 * stock_transactions is PARTITION BY RANGE (transaction_date) (see
 * 03_stock.sql + migration 2025_01_21_000004). PostgreSQL REQUIRES every
 * UNIQUE / PRIMARY KEY on a partitioned table to include ALL partitioning
 * columns (PG docs §5.11.2: "When establishing a unique constraint for a
 * partitioned table, all the columns of the partition key must be included").
 *
 * Consequence: stock_transactions' PK is COMPOSITE — (id, transaction_date).
 * There is NO unique constraint on (id) alone, and one CANNOT be added (PG
 * rejects `UNIQUE(id)` on a partitioned table). A FK may only reference
 * columns backed by a UNIQUE/PK, so `REFERENCES stock_transactions(id)` is
 * structurally impossible.
 *
 * This is NOT a bug — it is the deliberate PG design that lets FK validation
 * be performed against a single partition (cheaply), which is exactly what
 * makes the append-only ledger scale to LARGE DATA: monthly partitions enable
 * partition pruning on date-range queries, keep per-partition indexes small,
 * and allow O(1) archival via DETACH PARTITION. Dropping partitioning, or
 * trying to add UNIQUE(id), would both destroy that scalability — and the
 * latter is impossible anyway.
 *
 * ── The proper, long-term, large-data-friendly solution: COMPOSITE FK ─────
 * Reference the table's real primary key (id, transaction_date):
 *
 *   stock_adjustment_items.stock_transaction_id    → stock_transactions.id
 *   stock_adjustment_items.stock_transaction_date  → stock_transactions.transaction_date
 *   FK (stock_transaction_id, stock_transaction_date)
 *      REFERENCES stock_transactions(id, transaction_date)
 *      ON DELETE SET NULL
 *
 * Why this is correct and scalable:
 *   • Preserves partitioning — the #1 scalability lever for the ledger.
 *   • True DB-level referential integrity (no app-only workaround).
 *   • Idiomatic PG pattern for referencing a partitioned table.
 *   • ON DELETE SET NULL works: PG nulls BOTH columns for a composite FK.
 *   • Minimal/additive: one new nullable `date` column; the (NULL, NULL) pair
 *     is valid, so pre-Phase-6.2 item rows (both columns NULL) stay legal.
 *
 * ── Second latent bug fixed here: column TYPE ────────────────────────────
 * The original migration declared stock_transaction_id as unsignedBigInteger
 * (= PostgreSQL `bigint`). But stock_transactions.id is `integer GENERATED
 * ALWAYS AS IDENTITY` (= `integer`). PostgreSQL requires FK columns to match
 * the referenced column type EXACTLY, so even without the partitioning issue
 * the FK would have failed on type mismatch. This revision declares the
 * column as `integer` and defensively normalises any pre-existing bigint
 * column to integer (covers a half-applied state from the failed run).
 *
 * ── Application changes that ship with this fix ──────────────────────────
 * StockAdjustmentService::confirmAdjustment() now persists BOTH
 * stock_transaction_id AND stock_transaction_date on each item (the date is
 * the adjustment_date, which is exactly what was written to
 * stock_transactions.transaction_date for that row). cancelAdjustment()
 * needs NO change: reversal marks is_reversed=true on the original row (it
 * does NOT delete it), so the composite FK stays satisfied.
 *
 * Idempotent: column add (IF NOT EXISTS) + type normalisation + constraint
 * and index existence guards. Safe to re-run.
 *
 * References:
 *   - STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §Phase 6.2 + fix note
 *   - app/Services/Stock/StockAdjustmentService.php  (confirm writes both)
 *   - app/Models/StockAdjustmentItem.php  ($fillable + $casts)
 *   - PostgreSQL docs §5.11.2 "Partitioned Tables" — unique-constraint rule
 *   - PostgreSQL docs §5.4  "Foreign Keys" — composite FK semantics
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. stock_transaction_id + stock_transaction_date columns ─────
        // Both nullable: old rows (pre-Phase-6.2) keep (NULL, NULL), which is
        // a valid composite-FK state. confirmAdjustment populates both
        // atomically. Type = integer to match stock_transactions.id.
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustment_items', 'stock_transaction_id')) {
                $table->integer('stock_transaction_id')
                      ->nullable()
                      ->after('id');
            }
            if (!Schema::hasColumn('stock_adjustment_items', 'stock_transaction_date')) {
                $table->date('stock_transaction_date')
                      ->nullable()
                      ->after('stock_transaction_id');
            }
        });

        // Defensive type normalisation: if a previous (pre-fix) run left a
        // `bigint` stock_transaction_id column, cast it to integer so the
        // composite FK below can match stock_transactions.id (integer). Safe
        // because the column is empty for any pre-fix row (confirm never
        // completed the FK write — the original migration always failed).
        if (Schema::hasColumn('stock_adjustment_items', 'stock_transaction_id')) {
            $type = $this->columnType('stock_adjustment_items', 'stock_transaction_id');
            if ($type === 'bigint') {
                DB::statement(
                    "ALTER TABLE stock_adjustment_items "
                    . "ALTER COLUMN stock_transaction_id TYPE integer "
                    . "USING stock_transaction_id::integer"
                );
            }
        }

        // ── 2. Composite FK → stock_transactions(id, transaction_date) ───
        // The proper way to reference a RANGE-partitioned table whose PK is
        // (id, transaction_date). ON DELETE SET NULL nulls BOTH columns.
        if (!$this->constraintExists('stock_adjustment_items', 'sai_stock_tx_fk')) {
            DB::statement(
                "ALTER TABLE stock_adjustment_items "
                . "ADD CONSTRAINT sai_stock_tx_fk "
                . "FOREIGN KEY (stock_transaction_id, stock_transaction_date) "
                . "REFERENCES stock_transactions(id, transaction_date) "
                . "ON DELETE SET NULL"
            );
        }

        // ── 3. Composite partial index ───────────────────────────────────
        // Powers (a) the cancel-time reverse-by-item lookup and (b) the
        // ON DELETE SET NULL row-finder (so a stock_transactions DELETE does
        // not seq-scan stock_adjustment_items). Partial (WHERE ... IS NOT
        // NULL) keeps it tiny — only confirmed items are indexed.
        if (!$this->indexExists('stock_adjustment_items', 'idx_sai_stock_tx')) {
            DB::statement(
                "CREATE INDEX idx_sai_stock_tx ON stock_adjustment_items "
                . "(stock_transaction_id, stock_transaction_date) "
                . "WHERE stock_transaction_id IS NOT NULL"
            );
        }

        // ── 4. UNIQUE (stock_adjustment_id, product_id) ──────────────────
        // DB-level backstop for the duplicate-product-per-adjustment bug (G11).
        // The application-layer dedup guard (validateCreateInput) is the
        // runtime gate; this is the invariant. Only added when no existing
        // duplicates would violate it — otherwise the migration logs a
        // warning and skips (operator can clean dupes + re-run this DDL).
        if (!$this->constraintExists('stock_adjustment_items', 'sai_adj_product_unique')) {
            $dupCount = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT stock_adjustment_id, product_id, COUNT(*) AS n
    FROM stock_adjustment_items
    GROUP BY stock_adjustment_id, product_id
    HAVING COUNT(*) > 1
) d
SQL);
            $duplicates = (int) ($dupCount->cnt ?? 0);

            if ($duplicates > 0) {
                Log::warning(
                    "Phase 6.2 migration: skipped adding UNIQUE(stock_adjustment_id, product_id) "
                    . "on stock_adjustment_items — {$duplicates} duplicate group(s) exist. "
                    . "Clean them manually and re-run: "
                    . "ALTER TABLE stock_adjustment_items ADD CONSTRAINT sai_adj_product_unique "
                    . "UNIQUE (stock_adjustment_id, product_id);"
                );
            } else {
                DB::statement(
                    "ALTER TABLE stock_adjustment_items "
                    . "ADD CONSTRAINT sai_adj_product_unique "
                    . "UNIQUE (stock_adjustment_id, product_id)"
                );
            }
        }
    }

    public function down(): void
    {
        if ($this->constraintExists('stock_adjustment_items', 'sai_adj_product_unique')) {
            DB::statement("ALTER TABLE stock_adjustment_items DROP CONSTRAINT sai_adj_product_unique");
        }
        if ($this->indexExists('stock_adjustment_items', 'idx_sai_stock_tx')) {
            DB::statement("DROP INDEX IF EXISTS idx_sai_stock_tx");
        }
        if ($this->constraintExists('stock_adjustment_items', 'sai_stock_tx_fk')) {
            DB::statement("ALTER TABLE stock_adjustment_items DROP CONSTRAINT IF EXISTS sai_stock_tx_fk");
        }
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_adjustment_items', 'stock_transaction_date')) {
                $table->dropColumn('stock_transaction_date');
            }
            if (Schema::hasColumn('stock_adjustment_items', 'stock_transaction_id')) {
                $table->dropColumn('stock_transaction_id');
            }
        });
    }

    /**
     * Does a constraint (by name) exist on the given table? Uses pg_constraint
     * introspection — works regardless of how the constraint was created.
     */
    private function constraintExists(string $table, string $constraintName): bool
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = ? LIMIT 1",
            [$constraintName]
        );
        return $exists !== null;
    }

    /**
     * Does an index (by name) exist? pg_indexes covers plain indexes and
     * unique-constraint-backed indexes alike.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1",
            [$indexName]
        );
        return $exists !== null;
    }

    /**
     * The PostgreSQL data type of a column (information_schema.columns).
     * Used to detect a pre-fix `bigint` stock_transaction_id that needs
     * normalising to `integer` before the composite FK is added.
     */
    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "SELECT data_type FROM information_schema.columns "
            . "WHERE table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return $row?->data_type;
    }
};
