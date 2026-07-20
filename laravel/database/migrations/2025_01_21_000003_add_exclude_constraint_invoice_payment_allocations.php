<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 33: Add EXCLUDE constraint + referential integrity for invoice_payment_allocations.
 *
 * This migration adds three layers of database-level protection:
 *
 *   1. CHECK (allocated_amount > 0) — Prevents zero/negative allocations at DB level.
 *      Previously enforced only in PHP (CustomerPaymentService::allocateToInvoice).
 *
 *   2. EXCLUDE USING gist (invoice_id WITH =, payment_id WITH =)
 *      — Prevents duplicate (invoice_id, payment_id) pairs. Each payment can be
 *      allocated to a given invoice at most once. Without this, application bugs
 *      or race conditions could insert duplicate allocation rows for the same
 *      invoice+payment, inflating the invoice's paid_amount.
 *
 *   3. FK payment_id → customer_payments(id) — Missing referential integrity.
 *      The original schema (05_purchase.sql) omitted this FK, allowing orphaned
 *      allocation rows if a payment is hard-deleted.
 *
 *   4. Trigger trg_ipa_no_overallocation — Prevents the SUM of allocations for
 *      an invoice from exceeding its total_amount. The EXCLUDE constraint alone
 *      cannot enforce sum-based invariants; a trigger is the correct PG mechanism.
 *      This is the "no_overallocation" invariant documented in §7.12.
 *
 * Prerequisite: btree_gist extension (for = operator on integer in GiST index).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // 0. Enable btree_gist extension (required for EXCLUDE with = on integers)
        // ──────────────────────────────────────────────
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // ──────────────────────────────────────────────
        // 1. CHECK constraint: allocated_amount must be positive
        // ──────────────────────────────────────────────
        // Refund allocations (payment type) store the positive amount and
        // the sign logic is handled in CustomerPaymentService, so the
        // allocation row itself is always positive.
        DB::statement(
            "ALTER TABLE invoice_payment_allocations
             ADD CONSTRAINT ipa_allocated_amount_positive
             CHECK (allocated_amount > 0)"
        );

        // ──────────────────────────────────────────────
        // 2. EXCLUDE constraint: one allocation per (invoice_id, payment_id)
        // ──────────────────────────────────────────────
        // Using GiST index with btree_gist so we can combine = (integer)
        // operators in the same exclusion constraint.
        // This prevents:
        //   - Duplicate allocation rows for the same invoice+payment
        //   - Race-condition double-inserts when two requests allocate
        //     the same payment to the same invoice concurrently
        DB::statement(
            "ALTER TABLE invoice_payment_allocations
             ADD CONSTRAINT ipa_unique_invoice_payment
             EXCLUDE USING gist (
                 invoice_id WITH =,
                 payment_id WITH =
             )"
        );

        // ──────────────────────────────────────────────
        // 3. FK constraint: payment_id → customer_payments(id)
        // ──────────────────────────────────────────────
        // The original schema (05_purchase.sql) had invoice_id FK but
        // missed payment_id FK. This prevents orphaned allocation rows.
        // ON DELETE CASCADE: when a payment is deleted, its allocations
        // are automatically removed (though the app uses is_reversed soft-delete).
        DB::statement(
            "ALTER TABLE invoice_payment_allocations
             ADD CONSTRAINT ipa_payment_id_foreign
             FOREIGN KEY (payment_id) REFERENCES customer_payments(id)
             ON DELETE CASCADE"
        );

        // ──────────────────────────────────────────────
        // 4. Trigger: prevent over-allocation of an invoice
        // ──────────────────────────────────────────────
        // The EXCLUDE constraint prevents duplicate rows but cannot enforce
        // SUM-based invariants. This trigger checks that after each INSERT,
        // the total allocated amount for an invoice does not exceed its
        // total_amount. This is the "no_overallocation" constraint from §7.12.
        //
        // Design notes:
        //   - Uses AFTER INSERT trigger so the new row is visible in the query.
        //   - Checks against total_amount on sales_invoices (the source of truth
        //     for the invoice's billing amount).
        //   - Joins with customer_payments to exclude reversed payments from the sum,
        //     matching the same logic used in CustomerPaymentService::allocateToInvoice.
        //   - RAISE EXCEPTION aborts the transaction, preventing the over-allocation.

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ipa_no_overallocation()
            RETURNS trigger AS $$
            DECLARE
                v_total_allocated numeric(14,2);
                v_invoice_total  numeric(14,2);
            BEGIN
                SELECT COALESCE(SUM(ipa.allocated_amount), 0)
                INTO v_total_allocated
                FROM invoice_payment_allocations ipa
                JOIN customer_payments cp ON cp.id = ipa.payment_id AND cp.is_reversed = false
                WHERE ipa.invoice_id = NEW.invoice_id;

                SELECT total_amount
                INTO v_invoice_total
                FROM sales_invoices
                WHERE id = NEW.invoice_id;

                IF v_total_allocated > v_invoice_total + 0.01 THEN
                    RAISE EXCEPTION
                        'Over-allocation prevented: invoice % total_amount is %, but allocated amount is %',
                        NEW.invoice_id, v_invoice_total, v_total_allocated;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(
            "CREATE CONSTRAINT TRIGGER trg_ipa_no_overallocation
             AFTER INSERT ON invoice_payment_allocations
             DEFERRABLE INITIALLY IMMEDIATE
             FOR EACH ROW
             EXECUTE FUNCTION fn_ipa_no_overallocation()"
        );

        // ──────────────────────────────────────────────
        // 5. Refresh planner statistics
        // ──────────────────────────────────────────────
        DB::statement('ANALYZE invoice_payment_allocations');
    }

    public function down(): void
    {
        // Drop trigger + function
        DB::statement('DROP TRIGGER IF EXISTS trg_ipa_no_overallocation ON invoice_payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS fn_ipa_no_overallocation()');

        // Drop constraints (reverse order)
        DB::statement('ALTER TABLE invoice_payment_allocations DROP CONSTRAINT IF EXISTS ipa_payment_id_foreign');
        DB::statement('ALTER TABLE invoice_payment_allocations DROP CONSTRAINT IF EXISTS ipa_unique_invoice_payment');
        DB::statement('ALTER TABLE invoice_payment_allocations DROP CONSTRAINT IF EXISTS ipa_allocated_amount_positive');

        // Note: btree_gist extension is NOT dropped as other features may depend on it.
    }
};
