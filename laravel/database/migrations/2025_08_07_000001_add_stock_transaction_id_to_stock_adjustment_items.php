<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 (Stock Adjustment plan) — Fix duplicate-product reversal (G11).
 *
 * Problem: cancelAdjustment() looked up the stock_transaction to reverse via
 *   `where reference_id = adjustment.id AND product_id = item.product_id
 *      AND is_reversed = false ->first()`.
 * When two line items in the SAME adjustment shared the same product_id
 * (no DB-level UNIQUE), the `.first()` reversal only undid ONE of them and
 * left the other's stock_transaction orphaned + irreversible — silently
 * corrupting the stock ledger.
 *
 * Fix (two parts):
 *   1. Add `stock_transaction_id` to `stock_adjustment_items` — the exact
 *      stock_transactions row created for this item on confirm. Captured
 *      at confirm time from `StockService::applyTransaction()`'s return
 *      value (it already returns the StockTransaction model — we just
 *      weren't using the id). Reversal then uses this id directly (exact
 *      row) instead of a product+reference lookup.
 *   2. Add `UNIQUE (stock_adjustment_id, product_id)` so the dup-product
 *      situation can NEVER recur at the DB level. Existing duplicate rows
 *      (if any) block the constraint; the migration detects this and
 *      SKIPS the constraint add with a logged warning rather than failing
 *      — the application-layer dedup guard (validateCreateInput, Phase 6.4)
 *      is the runtime gate. An operator can clean the historical dupes +
 *      re-run the constraint DDL manually.
 *
 * Idempotent: column + constraint are guarded by Schema::hasColumn +
 * pg_constraint introspection. Safe to re-run.
 *
 * References:
 *   - STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §Phase 6.2
 *   - app/Services/Stock/StockAdjustmentService.php  (confirmAdjustment
 *     captures stock_transaction_id; cancelAdjustment reverses by it)
 *   - app/Models/StockAdjustmentItem.php  ($fillable + stockTransaction())
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add stock_transaction_id column ──────────────────────────
        // Nullable FK → stock_transactions. Old rows (created before this
        // migration) stay NULL; cancelAdjustment falls back to the legacy
        // product+reference lookup for them (backward compatible).
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustment_items', 'stock_transaction_id')) {
                $table->unsignedBigInteger('stock_transaction_id')
                      ->nullable()
                      ->after('id');
            }
        });

        // Add the FK (deferred — stock_transactions is append-only, but
        // ON DELETE SET NULL keeps an item readable if its tx is ever purged).
        if (!$this->constraintExists('stock_adjustment_items', 'sai_stock_tx_fk')) {
            DB::statement(
                "ALTER TABLE stock_adjustment_items "
                . "ADD CONSTRAINT sai_stock_tx_fk "
                . "FOREIGN KEY (stock_transaction_id) REFERENCES stock_transactions(id) "
                . "ON DELETE SET NULL"
            );
        }

        // Index for the cancel-time lookup (reverse-by-item path).
        if (!$this->indexExists('stock_adjustment_items', 'idx_sai_stock_tx')) {
            DB::statement(
                "CREATE INDEX idx_sai_stock_tx ON stock_adjustment_items(stock_transaction_id) "
                . "WHERE stock_transaction_id IS NOT NULL"
            );
        }

        // ── 2. UNIQUE (stock_adjustment_id, product_id) ──────────────────
        // The DB-level backstop for the duplicate-product-per-adjustment bug.
        // Only added when no existing duplicates would violate it — otherwise
        // the migration logs a warning and skips (the application-layer dedup
        // guard in validateCreateInput is the runtime gate; an operator can
        // clean historical dupes and re-run this DDL manually).
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
            DB::statement("DROP INDEX idx_sai_stock_tx");
        }
        if ($this->constraintExists('stock_adjustment_items', 'sai_stock_tx_fk')) {
            DB::statement("ALTER TABLE stock_adjustment_items DROP CONSTRAINT sai_stock_tx_fk");
        }
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_adjustment_items', 'stock_transaction_id')) {
                $table->dropColumn('stock_transaction_id');
            }
        });
    }

    /**
     * Does a constraint (by name) exist on the given table?
     * Uses pg_constraint introspection (the Postgres-native way — works
     * regardless of how the constraint was created).
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
     * Does an index (by name) exist?
     * pg_indexes covers both plain indexes and unique-constraint-backed indexes.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1",
            [$indexName]
        );
        return $exists !== null;
    }
};
