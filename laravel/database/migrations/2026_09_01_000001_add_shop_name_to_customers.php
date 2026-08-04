<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G1 (Sales CRITICAL) fix — add the `shop_name` column back to `customers`.
 *
 * Background: the legacy MySQL `customers` table had a `shop_name` column
 * (the actual business/shop name, distinct from `customer_name`). The new
 * PostgreSQL schema deliberately dropped it — see migration
 * `2026_07_30_000011_migrate_legacy_customer_data.php` header note:
 * "new schema has only customer_name (no shop_name column)" — and the ETL
 * prefers `shop_name` into `customer_name` during legacy import.
 *
 * HOWEVER — the application layer still SELECTs and renders `c.shop_name`
 * everywhere:
 *   - SalesCartService::getCustomerDetails / getCustomerSummary (L398, L487)
 *   - SalesCartController::searchCustomer / listDrafts / getCustomerDetails (L84, L96, L213)
 *   - cart.blade.php + sales-invoices/_receive_modal_body.blade.php
 *   - public/assets/js/{CustomerTransaction,sales-create,sales,challan-index}.js
 *
 * This caused a runtime `SQLSTATE[42703]: column "shop_name" does not exist`
 * on every cart customer-search / list-drafts / customer-details AJAX call.
 *
 * The ops ETL script `database/etl/post_load_fixes.sql` "FIX 12 (P2-4)"
 * already established the remediation pattern (add column + backfill from
 * customer_name). This migration codifies that fixup as a proper, versioned
 * migration so a fresh `php artisan migrate` produces a working schema
 * without relying on a manual post-load SQL step.
 *
 * The column is nullable varchar(200) (mirrors `customer_name`) and is
 * backfilled from `customer_name` so every existing row has a displayable
 * shop name immediately. Going forward, new customers carry `shop_name`
 * alongside `customer_name` (the customer master UI already collects it).
 *
 * Closes: AI_CONTEXT ISSUES_REGISTER G-056, G-057
 *         (sales G1 — `customers.shop_name` DDL drift).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent — the ETL post_load_fixes.sql "FIX 12" may have added
        // the column already on a production-converted database. Guard so
        // `migrate` is safe to re-run on such databases.
        $exists = DB::table('information_schema.columns')
            ->where('table_name', 'customers')
            ->where('column_name', 'shop_name')
            ->exists();

        if ($exists) {
            return;
        }

        DB::statement('ALTER TABLE customers ADD COLUMN shop_name varchar(200)');

        // Backfill from customer_name so every row has a displayable shop name.
        // (Legacy import already coalesced shop_name→customer_name, so this
        // round-trips the display value back into the dedicated column.)
        DB::statement('UPDATE customers SET shop_name = customer_name WHERE shop_name IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP COLUMN IF EXISTS shop_name');
    }
};
