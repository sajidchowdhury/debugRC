<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task 37: Implement salesman commission tracking.
 *
 * This migration creates the commission tracking infrastructure for the ERP
 * sales module. The system supports multiple commission structures:
 *
 *   1. FLAT: Fixed percentage on invoice total_amount
 *   2. TIERED: Progressive rates based on sales volume (e.g., 1% up to 50K,
 *      1.5% from 50K-100K, 2% above 100K)
 *   3. PRODUCT_GROUP: Different rates per product group (e.g., 2% on
 *      electronics, 1% on furniture)
 *   4. TARGET_BONUS: Base rate + bonus when monthly/quarterly target is met
 *
 * ARCHITECTURE DECISIONS:
 *
 * 1. Commission rules are time-bounded (effective_from / effective_to) — this
 *    allows rule changes without affecting historical calculations. When a rule
 *    expires, a new rule row is inserted with the updated rate.
 *
 * 2. Commission entries are computed on invoice PAYMENT, not on invoice creation.
 *    This is the standard practice for commission systems: a salesman only earns
 *    commission on invoices that have been paid. Draft/cancelled/reversed invoices
 *    generate no commission.
 *
 * 3. Commission entries are per-invoice-per-salesman. When a payment is allocated
 *    to an invoice, the commission is calculated proportionally based on the
 *    payment-to-total ratio. For a 1000 invoice with 1% commission:
 *      - First payment of 600 → commission = 6.00
 *      - Second payment of 400 → commission = 4.00
 *      - Total commission = 10.00 (1% of 1000)
 *
 * 4. Returns REVERSE commission: When a sales return is confirmed, a negative
 *    commission entry is created for the return amount at the original rate.
 *    This ensures commission reflects actual revenue.
 *
 * 5. Commission entries link to invoice_payment_allocations for full audit trail.
 *    Each entry traces back to exactly one allocation (or one return), making
 *    reconciliation straightforward.
 *
 * 6. GL integration: Confirmed commission entries generate a journal entry:
 *      Dr Commission Expense (operating_expense nature)
 *      Cr Employee Payable (employee_payable nature)
 *    This is posted when commission is marked as "confirmed" (typically at
 *    month-end batch processing).
 *
 * PARTITIONING COMPATIBILITY:
 *   commission_entries references sales_invoices (partitioned). The FK to
 *   sales_invoices is enforced via trigger (fn_fk_si_check), matching the
 *   pattern established in Task 34 for all child tables of sales_invoices.
 *
 * DEFERRABLE FK:
 *   All FKs are DEFERRABLE INITIALLY DEFERRED (per Task 35 pattern):
 *   - commission_entries → employees, branches, customer_payments,
 *     invoice_payment_allocations, sales_returns, journal_entries
 *   These are often created in the same transaction as their parents.
 *
 *   commission_rule_product_groups → product_groups is DEFERRABLE INITIALLY
 *   IMMEDIATE (product groups always pre-exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // PREREQUISITE: btree_gist extension
        //
        // The commission_rules table uses an EXCLUDE constraint with GiST
        // index that combines `salesman_id WITH =` (integer equality) and
        // a `daterange WITH &&` (range overlap). PostgreSQL's built-in GiST
        // supports range types natively, but integer equality in GiST
        // requires the btree_gist extension. We enable it here defensively
        // (IF NOT EXISTS is a no-op if already enabled by migration
        // 2025_01_21_000003_add_exclude_constraint_invoice_payment_allocations).
        // ══════════════════════════════════════════════════════════════
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // ══════════════════════════════════════════════════════════════
        // TABLE 1: commission_rules
        //
        // Defines the commission structure for each salesman.
        // A salesman can have multiple rules, but only one ACTIVE rule at a time
        // (enforced by EXCLUDE constraint).
        // ══════════════════════════════════════════════════════════════

        DB::statement(<<<'SQL'
            CREATE TABLE commission_rules (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                salesman_id integer NOT NULL REFERENCES employees(id),
                rule_type varchar(20) NOT NULL CHECK (rule_type IN ('flat','tiered','product_group','target_bonus')),
                rate numeric(8,4) NOT NULL DEFAULT 0,
                -- For 'flat': this is the single percentage rate (e.g., 1.5000 = 1.5%)
                -- For 'tiered': this is the base rate (tier overrides in commission_rule_tiers)
                -- For 'product_group': this is the default rate (group overrides in commission_rule_product_groups)
                -- For 'target_bonus': this is the base rate (target details in commission_rule_targets)
                effective_from date NOT NULL DEFAULT CURRENT_DATE,
                effective_to date,
                -- NULL means the rule is open-ended (currently active)
                is_active boolean NOT NULL DEFAULT true,
                branch_id integer REFERENCES branches(id),
                -- NULL means the rule applies to all branches; specific branch_id means branch-specific
                notes text,
                created_by integer,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT commission_rules_unique_active EXCLUDE (
                    salesman_id WITH =,
                    gist(
                        CASE WHEN is_active AND effective_to IS NULL
                             THEN daterange(effective_from, NULL, '[)')
                             ELSE daterange(NULL, NULL, '[]')  -- inactive/expired rows don't participate
                        END WITH &&
                    )
                ) WHERE (is_active AND effective_to IS NULL)
                -- Only one active open-ended rule per salesman at a time.
                -- Expired rules (effective_to set) or inactive rules don't conflict.
            )
SQL);

        // Index for looking up the active rule for a salesman
        DB::statement('CREATE INDEX idx_cr_salesman ON commission_rules(salesman_id, is_active, effective_from)');
        DB::statement('CREATE INDEX idx_cr_branch ON commission_rules(branch_id)');

        // ══════════════════════════════════════════════════════════════
        // TABLE 2: commission_rule_tiers
        //
        // Tiered commission rates: progressive rates based on cumulative sales.
        // Used when rule_type = 'tiered'.
        //
        // Example: 1% up to 50K, 1.5% from 50K-100K, 2% above 100K
        //   tier 1: threshold = 0,       rate = 1.0
        //   tier 2: threshold = 50000,   rate = 1.5
        //   tier 3: threshold = 100000,  rate = 2.0
        //
        // The rate applies to the PORTION of sales within the tier, not the
        // entire amount. This is the standard progressive/incremental model.
        // ══════════════════════════════════════════════════════════════

        DB::statement(<<<'SQL'
            CREATE TABLE commission_rule_tiers (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
                threshold numeric(14,2) NOT NULL DEFAULT 0,
                -- Cumulative sales amount at which this tier starts
                rate numeric(8,4) NOT NULL DEFAULT 0,
                -- Commission rate for the portion of sales in this tier
                sort_order integer NOT NULL DEFAULT 0,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT commission_rule_tiers_threshold_unique UNIQUE (commission_rule_id, threshold)
            )
SQL);

        DB::statement('CREATE INDEX idx_crt_rule ON commission_rule_tiers(commission_rule_id)');

        // ══════════════════════════════════════════════════════════════
        // TABLE 3: commission_rule_product_groups
        //
        // Per-product-group commission rates.
        // Used when rule_type = 'product_group'.
        //
        // Each row sets a commission rate for a specific product group.
        // Products in groups NOT listed here use the rule's default rate.
        // ══════════════════════════════════════════════════════════════

        DB::statement(<<<'SQL'
            CREATE TABLE commission_rule_product_groups (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
                product_group_id integer NOT NULL REFERENCES product_groups(id) ON DELETE CASCADE,
                rate numeric(8,4) NOT NULL DEFAULT 0,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT commission_rule_pg_unique UNIQUE (commission_rule_id, product_group_id)
            )
SQL);

        DB::statement('CREATE INDEX idx_crpg_rule ON commission_rule_product_groups(commission_rule_id)');
        DB::statement('CREATE INDEX idx_crpg_group ON commission_rule_product_groups(product_group_id)');

        // ══════════════════════════════════════════════════════════════
        // TABLE 4: commission_rule_targets
        //
        // Sales targets with bonus rates.
        // Used when rule_type = 'target_bonus'.
        //
        // The salesman earns the base rate on all sales. When cumulative sales
        // reach the target_amount, a BONUS rate applies to sales from that
        // point forward (in addition to the base rate).
        //
        // Example: Base 1%, target 100K, bonus_rate 2%
        //   Sales of 80K → commission = 800 (1% of 80K)
        //   Sales of 120K → commission = 1000 (1% of 100K) + 40 (2% of 20K) = 1040
        // ══════════════════════════════════════════════════════════════

        DB::statement(<<<'SQL'
            CREATE TABLE commission_rule_targets (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
                target_amount numeric(14,2) NOT NULL DEFAULT 0,
                -- Cumulative sales target that triggers the bonus
                bonus_rate numeric(8,4) NOT NULL DEFAULT 0,
                -- Additional rate applied to sales ABOVE the target
                period varchar(10) NOT NULL DEFAULT 'monthly' CHECK (period IN ('monthly','quarterly','yearly')),
                -- The period over which the target is measured
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT commission_rule_targets_rule_unique UNIQUE (commission_rule_id, period)
            )
SQL);

        DB::statement('CREATE INDEX idx_cxrt_rule ON commission_rule_targets(commission_rule_id)');

        // ══════════════════════════════════════════════════════════════
        // TABLE 5: commission_entries
        //
        // The core ledger of commission amounts owed to salesmen.
        // Each row represents one commission calculation event:
        //   - A payment allocation to an invoice (positive entry)
        //   - A sales return reversal (negative entry)
        //   - A manual adjustment (positive or negative)
        //
        // STATUS WORKFLOW:
        //   calculated → confirmed → paid
        //   Any status → reversed (if the underlying transaction is reversed)
        //
        // calculated: Auto-generated by the commission service when a payment
        //   is allocated. Not yet approved for payment.
        // confirmed: Approved by manager/admin at month-end batch processing.
        //   GL entry posted (Dr Commission Expense / Cr Employee Payable).
        // paid: The employee has been paid (employee_transactions record exists).
        // reversed: The underlying payment/return was reversed.
        //
        // PARTITIONING NOTE:
        //   commission_entries references sales_invoices (partitioned) via
        //   trigger-based FK enforcement (fn_fk_ce_si_check), following the
        //   same pattern as Task 34's child tables.
        // ══════════════════════════════════════════════════════════════

        DB::statement(<<<'SQL'
            CREATE TABLE commission_entries (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                salesman_id integer NOT NULL REFERENCES employees(id),
                branch_id integer NOT NULL REFERENCES branches(id),
                sales_invoice_id integer,
                -- FK to sales_invoices (partitioned) — enforced by trigger trg_fk_ce_si
                commission_rule_id integer NOT NULL REFERENCES commission_rules(id),
                -- The rule that was active when this commission was calculated

                -- SOURCE TRACKING — exactly one of these must be non-NULL
                allocation_id integer REFERENCES invoice_payment_allocations(id) ON DELETE SET NULL,
                -- Commission triggered by a payment allocation
                sales_return_id integer REFERENCES sales_returns(id) ON DELETE SET NULL,
                -- Commission reversed by a sales return

                -- AMOUNTS
                invoice_total numeric(14,2) DEFAULT 0,
                -- The invoice's total_amount at the time of calculation
                commission_base numeric(14,2) DEFAULT 0,
                -- The base amount used for calculation (e.g., allocated amount, not full invoice)
                commission_rate numeric(8,4) DEFAULT 0,
                -- The rate applied (snapshot from the rule at calculation time)
                commission_amount numeric(14,2) NOT NULL DEFAULT 0,
                -- The calculated commission = commission_base * commission_rate / 100
                -- Negative for return reversals

                -- STATUS
                status varchar(20) NOT NULL DEFAULT 'calculated'
                    CHECK (status IN ('calculated','confirmed','paid','reversed')),
                entry_date date NOT NULL DEFAULT CURRENT_DATE,
                -- Date of the commission calculation event

                -- GL INTEGRATION
                journal_entry_id integer REFERENCES journal_entries(id),
                -- Posted when status → confirmed

                -- REVERSAL TRACKING
                reversed_by_entry_id integer REFERENCES commission_entries(id),
                -- If this entry reverses another, points to the original entry
                is_reversed boolean NOT NULL DEFAULT false,
                reversed_at timestamp(0),
                reversed_by integer,
                reverse_reason text,

                -- PERIOD TAGGING
                commission_period varchar(7),
                -- Format: '2025-01' — used for monthly batching and reporting
                -- Automatically set from entry_date

                notes text,
                created_by integer,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
            )
SQL);

        // Indexes for commission_entries
        DB::statement('CREATE INDEX idx_ce_salesman ON commission_entries(salesman_id, entry_date)');
        DB::statement('CREATE INDEX idx_ce_branch ON commission_entries(branch_id, entry_date)');
        DB::statement('CREATE INDEX idx_ce_invoice ON commission_entries(sales_invoice_id)');
        DB::statement('CREATE INDEX idx_ce_allocation ON commission_entries(allocation_id)');
        DB::statement('CREATE INDEX idx_ce_return ON commission_entries(sales_return_id)');
        DB::statement('CREATE INDEX idx_ce_rule ON commission_entries(commission_rule_id)');
        DB::statement('CREATE INDEX idx_ce_status ON commission_entries(status)');
        DB::statement('CREATE INDEX idx_ce_period ON commission_entries(commission_period, salesman_id)');
        DB::statement('CREATE INDEX idx_ce_journal ON commission_entries(journal_entry_id)');

        // ──────────────────────────────────────────────────────────────
        // TRIGGER: FK enforcement for commission_entries → sales_invoices
        // (partitioned table, declarative FK not supported)
        // ──────────────────────────────────────────────────────────────

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_fk_ce_si_check()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.sales_invoice_id IS NOT NULL THEN
                    IF NOT EXISTS (SELECT 1 FROM sales_invoices WHERE id = NEW.sales_invoice_id) THEN
                        RAISE EXCEPTION 'Referential integrity: sales_invoice_id=% does not exist in sales_invoices', NEW.sales_invoice_id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_ce_si
                AFTER INSERT ON commission_entries
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW
                EXECUTE FUNCTION fn_fk_ce_si_check()
SQL);

        // ──────────────────────────────────────────────────────────────
        // TRIGGER: Auto-set commission_period from entry_date
        // ──────────────────────────────────────────────────────────────

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ce_set_period()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.commission_period IS NULL AND NEW.entry_date IS NOT NULL THEN
                    NEW.commission_period := to_char(NEW.entry_date, 'YYYY-MM');
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ce_set_period
                BEFORE INSERT ON commission_entries
                FOR EACH ROW
                EXECUTE FUNCTION fn_ce_set_period()
SQL);

        // ──────────────────────────────────────────────────────────────
        // TRIGGER: Auto-update updated_at
        // ──────────────────────────────────────────────────────────────

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ce_updated_at()
            RETURNS trigger AS $$
            BEGIN
                NEW.updated_at := CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ce_updated_at
                BEFORE UPDATE ON commission_entries
                FOR EACH ROW
                EXECUTE FUNCTION fn_ce_updated_at()
SQL);

        // ──────────────────────────────────────────────────────────────
        // TRIGGER: Validate source — exactly one of allocation_id or
        // sales_return_id must be non-NULL (unless manual adjustment)
        // ──────────────────────────────────────────────────────────────

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_ce_validate_source()
            RETURNS trigger AS $$
            BEGIN
                -- At least one source must be set (allocation or return)
                IF NEW.allocation_id IS NULL AND NEW.sales_return_id IS NULL AND NEW.notes NOT LIKE 'Manual adjustment:%' THEN
                    RAISE EXCEPTION 'Commission entry must reference either an allocation_id or a sales_return_id';
                END IF;
                -- Both cannot be set simultaneously
                IF NEW.allocation_id IS NOT NULL AND NEW.sales_return_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Commission entry cannot reference both allocation_id and sales_return_id';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ce_validate_source
                BEFORE INSERT ON commission_entries
                FOR EACH ROW
                EXECUTE FUNCTION fn_ce_validate_source()
SQL);

        // ──────────────────────────────────────────────────────────────
        // RLS (Row-Level Security) for commission_entries — branch isolation
        //
        // IMPORTANT: GUC names must match the rest of the RLS system
        // (migration 2025_01_20_000007_add_rls_branch_isolation.php):
        //   - app.branch_id  (set by SetAppBranchId middleware)
        //   - app.is_admin   (set by SetAppBranchId middleware)
        // The original draft used app.current_branch_id and
        // app.current_user_id which are NEVER set by the middleware —
        // that would have made every SELECT on commission_entries
        // return 0 rows for ALL users (admins too, since the bypass
        // also referenced the wrong GUC). Fixed to use the canonical
        // names so RLS actually works.
        // ──────────────────────────────────────────────────────────────

        DB::statement('ALTER TABLE commission_entries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commission_entries FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_entries_select ON commission_entries
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_entries_insert ON commission_entries
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_entries_update ON commission_entries
                FOR UPDATE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
                WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_entries_delete ON commission_entries
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        // Admin bypass policy — admin sees/modifies all branches.
        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_entries_admin ON commission_entries
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
SQL);

        // ──────────────────────────────────────────────────────────────
        // RLS for commission_rules (branch-scoped; NULL branch_id = global)
        // ──────────────────────────────────────────────────────────────

        DB::statement('ALTER TABLE commission_rules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commission_rules FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_rules_select ON commission_rules
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_rules_insert ON commission_rules
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_rules_update ON commission_rules
                FOR UPDATE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
                WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_rules_delete ON commission_rules
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id IS NULL
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_commission_rules_admin ON commission_rules
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
SQL);

        // ──────────────────────────────────────────────────────────────
        // Make new FKs DEFERRABLE (per Task 35 pattern)
        // ──────────────────────────────────────────────────────────────

        // Commission entries → employees, branches, journal_entries (INITIALLY DEFERRED)
        $this->makeDeferred([
            'commission_entries' => ['salesman_id', 'branch_id', 'journal_entry_id'],
        ], initially: 'DEFERRED');

        // Commission entries → commission_rules, invoice_payment_allocations,
        // sales_returns (INITIALLY DEFERRED — often created in same transaction)
        $this->makeDeferred([
            'commission_entries' => ['commission_rule_id', 'allocation_id', 'sales_return_id'],
        ], initially: 'DEFERRED');

        // Commission rule FKs (INITIALLY DEFERRED — rule + sub-rules created together)
        $this->makeDeferred([
            'commission_rules' => ['salesman_id', 'branch_id'],
        ], initially: 'DEFERRED');

        // Product group FKs (INITIALLY IMMEDIATE — groups always pre-exist)
        $this->makeDeferred([
            'commission_rule_product_groups' => ['product_group_id'],
        ], initially: 'IMMEDIATE');

        // ──────────────────────────────────────────────────────────────
        // Seed a default flat commission rule for existing salesmen
        // (0% rate — effectively no commission until explicitly configured)
        // ──────────────────────────────────────────────────────────────

        DB::statement(<<<'SQL'
            INSERT INTO commission_rules (salesman_id, rule_type, rate, effective_from, is_active, created_by)
            SELECT e.id, 'flat', 0, CURRENT_DATE, true, NULL
            FROM employees e
            WHERE e.role = 'salesman'
              AND e.is_active = true
              AND e.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM commission_rules cr
                  WHERE cr.salesman_id = e.id AND cr.is_active = true
              )
SQL);
    }

    /**
     * Make FK constraints DEFERRABLE for the specified table/column pairs.
     */
    private function makeDeferred(array $tableColumns, string $initially = 'DEFERRED'): void
    {
        foreach ($tableColumns as $table => $columns) {
            foreach ($columns as $column) {
                $constraintName = DB::selectOne(<<<SQL
                    SELECT c.conname
                    FROM pg_constraint c
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                    WHERE t.relname = ?
                      AND n.nspname = 'public'
                      AND c.contype = 'f'
                      AND a.attname = ?
                    LIMIT 1
                SQL, [$table, $column]);

                if ($constraintName) {
                    $name = $constraintName->conname;
                    DB::statement(
                        "ALTER TABLE {$table} ALTER CONSTRAINT {$name} DEFERRABLE INITIALLY {$initially}"
                    );
                } else {
                    Log::warning("Deferred FK: No FK constraint found for {$table}.{$column}");
                }
            }
        }
    }

    public function down(): void
    {
        // Drop in reverse order (child tables first)
        DB::statement('DROP TABLE IF EXISTS commission_rule_targets CASCADE');
        DB::statement('DROP TABLE IF EXISTS commission_rule_product_groups CASCADE');
        DB::statement('DROP TABLE IF EXISTS commission_rule_tiers CASCADE');
        DB::statement('DROP TABLE IF EXISTS commission_entries CASCADE');
        DB::statement('DROP TABLE IF EXISTS commission_rules CASCADE');

        // Drop trigger functions
        DB::statement('DROP FUNCTION IF EXISTS fn_fk_ce_si_check() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_ce_set_period() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_ce_updated_at() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_ce_validate_source() CASCADE');
    }
};
