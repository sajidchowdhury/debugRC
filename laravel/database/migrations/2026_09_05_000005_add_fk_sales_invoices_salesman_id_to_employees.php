<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES-AUDIT-1 — Add FK on sales_invoices.salesman_id → employees(id).
 *
 * Resolves:
 *   - G-165 (sales-invoice G12 MAJOR): sales_invoices.salesman_id has NO FK
 *     to employees(id). Orphan salesman_id values possible.
 *
 * Background: the column already exists (integer, nullable) and is indexed
 * (idx_si_salesman), but the database does NOT enforce referential integrity.
 * A deleted employee leaves dangling salesman_id references on historical
 * invoices — the SalesInvoice::salesman() relation returns null silently,
 * and the commission module (which joins on salesman_id) skips those rows
 * without warning.
 *
 * Approach: add the FK with ON DELETE SET NULL so deleting an employee
 * NULLs out their salesman_id references on historical invoices (preserves
 * the invoice row itself — only the salesman link is severed). This mirrors
 * the existing pattern on sales_invoices.sales_invoice_item_id →
 * sales_invoice_items(id) ON DELETE SET NULL (see 04_sales.sql L258).
 *
 * Partitioning note: sales_invoices is PARTITION BY RANGE (invoice_date).
 * PostgreSQL 12+ supports FK constraints on partitioned tables natively —
 * the constraint is declared on the parent and inherited by all partitions.
 * No per-partition DDL needed.
 *
 * Backfill guard: before adding the FK, NULL out any salesman_id values
 * that don't reference an existing employees.id row. Without this guard,
 * the ALTER TABLE would fail on the first orphan row (PostgreSQL rejects
 * the constraint if any existing row violates it). The guard logs a warning
 * with the affected invoice IDs so an admin can audit the cleanup.
 *
 * Idempotent: uses Schema::hasColumn-style guards via a FK existence check
 * (Laravel's Schema builder doesn't expose hasForeign on PostgreSQL cleanly,
 * so we query information_schema directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill guard — NULL out orphan salesman_id values.
        //    A NULL salesman_id is valid (the column is nullable); an
        //    orphan non-NULL value is NOT valid once the FK is added.
        $orphans = DB::table('sales_invoices as si')
            ->leftJoin('employees as e', 'e.id', '=', 'si.salesman_id')
            ->whereNotNull('si.salesman_id')
            ->whereNull('e.id')
            ->select('si.id', 'si.invoice_code', 'si.salesman_id')
            ->get();

        if ($orphans->isNotEmpty()) {
            // Log the cleanup so an admin can audit which invoices had
            // their salesman_id NULLed (rare event — only happens if an
            // employee was hard-deleted prior to the FK being added).
            $orphanIds = $orphans->pluck('id')->all();
            DB::table('sales_invoices')
                ->whereIn('id', $orphanIds)
                ->update(['salesman_id' => null]);

            // Best-effort log — table may not exist in test envs.
            try {
                DB::table('migrations_audit_log')->insert([
                    'migration'  => '2026_09_05_000005_add_fk_sales_invoices_salesman_id_to_employees',
                    'action'     => 'backfill_orphan_salesman_id',
                    'details'    => json_encode([
                        'orphan_count'    => $orphans->count(),
                        'orphan_invoice_ids' => $orphanIds,
                    ]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Table missing — skip the audit row. The data fix itself
                // is already committed; this is just bookkeeping.
            }
        }

        // 2. Add the FK constraint (skip if already exists).
        $fkExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'sales_invoices_salesman_id_foreign')
            ->where('table_name', 'sales_invoices')
            ->exists();

        if (!$fkExists) {
            // Use raw SQL — Laravel's Schema::foreign() on a partitioned
            // table can produce a name-mangled constraint. Explicit name
            // keeps the down() migration targetable.
            DB::statement(
                'ALTER TABLE sales_invoices ' .
                'ADD CONSTRAINT sales_invoices_salesman_id_foreign ' .
                'FOREIGN KEY (salesman_id) REFERENCES employees(id) ' .
                'ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        $fkExists = DB::table('information_schema.table_constraints')
            ->where('constraint_name', 'sales_invoices_salesman_id_foreign')
            ->where('table_name', 'sales_invoices')
            ->exists();

        if ($fkExists) {
            DB::statement(
                'ALTER TABLE sales_invoices ' .
                'DROP CONSTRAINT IF EXISTS sales_invoices_salesman_id_foreign'
            );
        }
        // NOTE: the backfill (NULLed orphan salesman_id values) is NOT
        // reversible — those values were already invalid (referenced
        // non-existent employees). Restoring them would re-create the
        // orphan state the FK was meant to prevent.
    }
};
