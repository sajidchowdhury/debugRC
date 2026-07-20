<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24: Add full-text search (tsvector + GIN) for products and customers.
 *
 * Replaces LIKE '%term%' / ILIKE '%term%' pattern-matching searches with
 * PostgreSQL full-text search. Benefits:
 *
 *   1. Index-accelerated: GIN index enables sub-millisecond lookups on
 *      millions of rows, vs. full sequential scan with LIKE.
 *   2. Ranking: ts_rank() returns best matches first, not random order.
 *   3. Stemming: "running" matches "run", "cement" matches "cements".
 *   4. Weighted columns: product_name gets weight A (highest), product_code
 *      weight B; customer_name weight A, customer_code B, phone/mobile C,
 *      address D. This means name matches outrank code matches outrank
 *      phone/address matches.
 *
 * Implementation:
 *   - GENERATED ALWAYS AS ... STORED tsvector columns: automatically
 *     maintained by PostgreSQL on every INSERT/UPDATE — no triggers needed.
 *   - GIN indexes on the tsvector columns for fast @@ tsquery lookups.
 *   - ANALYZE after creation to refresh planner statistics.
 *
 * Note on 'simple' vs 'english' dictionary:
 *   We use 'simple' because product codes and customer names are typically
 *   short identifiers or Bengali-transliterated names where stemming
 *   ("running" → "run") is counter-productive. 'simple' just lowercases
 *   and splits on whitespace, which is exactly what we need for:
 *     - product_code lookups (e.g., "PRD-001" must match exactly)
 *     - Bengali names (no English stemming rules apply)
 *     - Phone numbers (must not be "stemmed")
 *   If English-language product descriptions are added later, a separate
 *   'english' tsvector column can be added alongside.
 *
 * Uses IF NOT EXISTS for idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // PRODUCTS: add search_vector GENERATED tsvector column
        // ──────────────────────────────────────────────
        // product_name: weight A (highest — primary match)
        // product_code: weight B (secondary — code lookups)
        //
        // Example query this enables:
        //   SELECT *, ts_rank(search_vector, plainto_tsquery('simple', 'cement')) AS rank
        //   FROM products
        //   WHERE search_vector @@ plainto_tsquery('simple', 'cement')
        //   ORDER BY rank DESC LIMIT 30;
        DB::statement(
            "ALTER TABLE products ADD COLUMN IF NOT EXISTS search_vector tsvector
             GENERATED ALWAYS AS (
                 setweight(to_tsvector('simple', coalesce(product_name, '')), 'A') ||
                 setweight(to_tsvector('simple', coalesce(product_code, '')), 'B')
             ) STORED"
        );

        // GIN index for fast tsquery lookups on products
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_products_search
             ON products USING GIN (search_vector)"
        );

        // ──────────────────────────────────────────────
        // CUSTOMERS: add search_vector GENERATED tsvector column
        // ──────────────────────────────────────────────
        // customer_name: weight A (highest — primary match)
        // customer_code: weight B (secondary — code lookups)
        // phone, mobile:  weight C (tertiary — phone number search)
        // address:        weight D (lowest — address keyword match)
        //
        // Note: 'shop_name' from legacy MySQL is not in the PG schema.
        // customer_name serves the same purpose (shop/person name).
        //
        // Example query this enables:
        //   SELECT *, ts_rank(search_vector, plainto_tsquery('simple', 'rahman')) AS rank
        //   FROM customers
        //   WHERE search_vector @@ plainto_tsquery('simple', 'rahman')
        //   ORDER BY rank DESC LIMIT 30;
        DB::statement(
            "ALTER TABLE customers ADD COLUMN IF NOT EXISTS search_vector tsvector
             GENERATED ALWAYS AS (
                 setweight(to_tsvector('simple', coalesce(customer_name, '')), 'A') ||
                 setweight(to_tsvector('simple', coalesce(customer_code, '')), 'B') ||
                 setweight(to_tsvector('simple', coalesce(phone, '')), 'C') ||
                 setweight(to_tsvector('simple', coalesce(mobile, '')), 'C') ||
                 setweight(to_tsvector('simple', coalesce(address, '')), 'D')
             ) STORED"
        );

        // GIN index for fast tsquery lookups on customers
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_customers_search
             ON customers USING GIN (search_vector)"
        );

        // Refresh planner statistics for the newly indexed columns
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        // Drop GIN indexes first (depend on the column)
        DB::statement("DROP INDEX IF EXISTS idx_products_search");
        DB::statement("DROP INDEX IF EXISTS idx_customers_search");

        // Drop GENERATED columns (must use ALTER TABLE ... DROP COLUMN)
        DB::statement("ALTER TABLE products DROP COLUMN IF EXISTS search_vector");
        DB::statement("ALTER TABLE customers DROP COLUMN IF EXISTS search_vector");
    }
};
