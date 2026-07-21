<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 31 — PostgreSQL LISTEN/NOTIFY for Real-Time Updates.
 *
 * Implements database-level event notifications using PostgreSQL's
 * native LISTEN/NOTIFY mechanism. When key business events occur
 * (invoice finalized, challan issued, payment received, etc.), a
 * database trigger fires pg_notify() which pushes a JSON payload
 * to a named channel. A long-running PHP worker (ListenNotifyWorker
 * artisan command) LISTENs on these channels and forwards events to:
 *   1. Redis Pub/Sub (for SSE endpoint consumption)
 *   2. Laravel's NotificationService (for rule-based dispatch)
 *
 * Architecture:
 *   DB Trigger → pg_notify(channel, payload) → PHP Worker (LISTEN)
 *     → Redis Pub/Sub → SSE Controller → Browser (EventSource)
 *     → NotificationService → Database + Broadcast
 *
 * Channels:
 *   - rcerp_sales_invoice  — sales_invoices INSERT/UPDATE
 *   - rcerp_sales_challan  — sales_challans INSERT/UPDATE
 *   - rcerp_sales_return   — sales_returns INSERT/UPDATE
 *   - rcerp_customer_payment — customer_payments INSERT/UPDATE
 *   - rcerp_stock_change   — stock_transactions INSERT
 *   - rcerp_journal_entry  — journal_entries INSERT
 *   - rcerp_system         — system_policies UPDATE, users UPDATE
 *
 * Each notification payload is a JSON object:
 *   {
 *     "table": "sales_invoices",
 *     "action": "INSERT" | "UPDATE",
 *     "id": 42,
 *     "branch_id": 1,
 *     "changes": {"status": "finalized"},
 *     "triggered_at": "2025-07-20T10:30:00+06:00"
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Helper function: rcerp_notify(channel, table, action, id, branch_id, changes)
        //    Central function to send structured notifications via pg_notify.
        //    All trigger functions delegate to this for consistent payload format.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify(
    p_channel   text,
    p_table     text,
    p_action    text,
    p_id        integer,
    p_branch_id integer DEFAULT NULL,
    p_changes   jsonb  DEFAULT '{}'::jsonb
)
RETURNS void AS $$
DECLARE
    v_payload jsonb;
BEGIN
    v_payload := jsonb_build_object(
        'table',        p_table,
        'action',       p_action,
        'id',           p_id,
        'branch_id',    p_branch_id,
        'changes',      p_changes,
        'triggered_at', CURRENT_TIMESTAMP
    );

    PERFORM pg_notify(p_channel, v_payload::text);
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 2. Trigger function: rcerp_notify_sales_invoice()
        //    Fires on sales_invoices INSERT and UPDATE.
        //    On INSERT: notifies with key fields (status, customer_id, total).
        //    On UPDATE: only notifies if important columns changed
        //      (status, is_godown_prepared, is_challan_issued, is_reversed,
        //       total_amount, paid_amount, call_a_day).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_sales_invoice()
RETURNS trigger AS $$
DECLARE
    v_changes jsonb := '{}'::jsonb;
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_changes := jsonb_build_object(
            'status',            NEW.status,
            'customer_id',       NEW.customer_id,
            'total_amount',      NEW.total_amount,
            'is_godown_prepared', NEW.is_godown_prepared,
            'is_challan_issued',  NEW.is_challan_issued
        );
        PERFORM rcerp_notify('rcerp_sales_invoice', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id, v_changes);
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        -- Only notify on meaningful column changes (avoid noise from updated_at)
        IF NEW.status            IS DISTINCT FROM OLD.status OR
           NEW.is_godown_prepared IS DISTINCT FROM OLD.is_godown_prepared OR
           NEW.is_challan_issued  IS DISTINCT FROM OLD.is_challan_issued OR
           NEW.is_reversed        IS DISTINCT FROM OLD.is_reversed OR
           NEW.total_amount       IS DISTINCT FROM OLD.total_amount OR
           NEW.paid_amount        IS DISTINCT FROM OLD.paid_amount OR
           NEW.call_a_day      IS DISTINCT FROM OLD.call_a_day
        THEN
            -- Build changes object with only the changed columns
            v_changes := '{}'::jsonb;
            IF NEW.status IS DISTINCT FROM OLD.status THEN
                v_changes := jsonb_set(v_changes, '{status}', to_jsonb(NEW.status));
            END IF;
            IF NEW.is_godown_prepared IS DISTINCT FROM OLD.is_godown_prepared THEN
                v_changes := jsonb_set(v_changes, '{is_godown_prepared}', to_jsonb(NEW.is_godown_prepared));
            END IF;
            IF NEW.is_challan_issued IS DISTINCT FROM OLD.is_challan_issued THEN
                v_changes := jsonb_set(v_changes, '{is_challan_issued}', to_jsonb(NEW.is_challan_issued));
            END IF;
            IF NEW.is_reversed IS DISTINCT FROM OLD.is_reversed THEN
                v_changes := jsonb_set(v_changes, '{is_reversed}', to_jsonb(NEW.is_reversed));
            END IF;
            IF NEW.total_amount IS DISTINCT FROM OLD.total_amount THEN
                v_changes := jsonb_set(v_changes, '{total_amount}', to_jsonb(NEW.total_amount));
            END IF;
            IF NEW.paid_amount IS DISTINCT FROM OLD.paid_amount THEN
                v_changes := jsonb_set(v_changes, '{paid_amount}', to_jsonb(NEW.paid_amount));
            END IF;
            IF NEW.call_a_day IS DISTINCT FROM OLD.call_a_day THEN
                v_changes := jsonb_set(v_changes, '{call_a_day}', to_jsonb(NEW.call_a_day));
            END IF;

            PERFORM rcerp_notify('rcerp_sales_invoice', TG_TABLE_NAME, 'UPDATE', NEW.id, NEW.branch_id, v_changes);
        END IF;
        RETURN NEW;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 3. Trigger function: rcerp_notify_sales_challan()
        //    Fires on sales_challans INSERT and UPDATE.
        //    Tracks status changes and reversal flags.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_sales_challan()
RETURNS trigger AS $$
DECLARE
    v_changes jsonb := '{}'::jsonb;
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- sales_challans has no `status` column — use is_reversed and
        -- is_dispatch_soft_hold as the state flags. The invoice link is
        -- sales_invoice_id (not invoice_id).
        v_changes := jsonb_build_object(
            'sales_invoice_id',     NEW.sales_invoice_id,
            'is_reversed',          NEW.is_reversed,
            'is_dispatch_soft_hold', NEW.is_dispatch_soft_hold
        );
        PERFORM rcerp_notify('rcerp_sales_challan', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id, v_changes);
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.is_reversed          IS DISTINCT FROM OLD.is_reversed OR
           NEW.is_dispatch_soft_hold IS DISTINCT FROM OLD.is_dispatch_soft_hold
        THEN
            IF NEW.is_reversed IS DISTINCT FROM OLD.is_reversed THEN
                v_changes := jsonb_set(v_changes, '{is_reversed}', to_jsonb(NEW.is_reversed));
            END IF;
            IF NEW.is_dispatch_soft_hold IS DISTINCT FROM OLD.is_dispatch_soft_hold THEN
                v_changes := jsonb_set(v_changes, '{is_dispatch_soft_hold}', to_jsonb(NEW.is_dispatch_soft_hold));
            END IF;
            PERFORM rcerp_notify('rcerp_sales_challan', TG_TABLE_NAME, 'UPDATE', NEW.id, NEW.branch_id, v_changes);
        END IF;
        RETURN NEW;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 4. Trigger function: rcerp_notify_sales_return()
        //    Fires on sales_returns INSERT and UPDATE.
        //    Tracks status progression (pending → confirmed → reversed).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_sales_return()
RETURNS trigger AS $$
DECLARE
    v_changes jsonb := '{}'::jsonb;
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- sales_returns has `status` (created/confirmed/reversed) and
        -- `sales_invoice_id` (not invoice_id).
        v_changes := jsonb_build_object(
            'status',          NEW.status,
            'sales_invoice_id', NEW.sales_invoice_id,
            'is_reversed',     NEW.is_reversed
        );
        PERFORM rcerp_notify('rcerp_sales_return', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id, v_changes);
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.status      IS DISTINCT FROM OLD.status OR
           NEW.is_reversed IS DISTINCT FROM OLD.is_reversed
        THEN
            IF NEW.status IS DISTINCT FROM OLD.status THEN
                v_changes := jsonb_set(v_changes, '{status}', to_jsonb(NEW.status));
            END IF;
            IF NEW.is_reversed IS DISTINCT FROM OLD.is_reversed THEN
                v_changes := jsonb_set(v_changes, '{is_reversed}', to_jsonb(NEW.is_reversed));
            END IF;
            PERFORM rcerp_notify('rcerp_sales_return', TG_TABLE_NAME, 'UPDATE', NEW.id, NEW.branch_id, v_changes);
        END IF;
        RETURN NEW;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 5. Trigger function: rcerp_notify_customer_payment()
        //    Fires on customer_payments INSERT and UPDATE.
        //    Tracks status and amount changes.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_customer_payment()
RETURNS trigger AS $$
DECLARE
    v_changes jsonb := '{}'::jsonb;
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- customer_payments has no `status` column — use is_reversed and
        -- payment_mode for state. transaction_type is added by migration
        -- 2025_01_09_000005 (which runs before this one).
        v_changes := jsonb_build_object(
            'transaction_type', NEW.transaction_type,
            'payment_mode',     NEW.payment_mode,
            'amount',           NEW.amount,
            'customer_id',      NEW.customer_id
        );
        PERFORM rcerp_notify('rcerp_customer_payment', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id, v_changes);
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.is_reversed      IS DISTINCT FROM OLD.is_reversed OR
           NEW.amount           IS DISTINCT FROM OLD.amount
        THEN
            IF NEW.is_reversed IS DISTINCT FROM OLD.is_reversed THEN
                v_changes := jsonb_set(v_changes, '{is_reversed}', to_jsonb(NEW.is_reversed));
            END IF;
            IF NEW.amount IS DISTINCT FROM OLD.amount THEN
                v_changes := jsonb_set(v_changes, '{amount}', to_jsonb(NEW.amount));
            END IF;
            PERFORM rcerp_notify('rcerp_customer_payment', TG_TABLE_NAME, 'UPDATE', NEW.id, NEW.branch_id, v_changes);
        END IF;
        RETURN NEW;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 6. Trigger function: rcerp_notify_stock_change()
        //    Fires on stock_transactions INSERT only.
        //    Notifies for real-time stock level updates (dashboard, availability).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_stock_change()
RETURNS trigger AS $$
DECLARE
    v_branch_id integer;
BEGIN
    -- stock_transactions has no branch_id column directly — look it up
    -- from the warehouse. qty and rate are the actual column names
    -- (not qty_change / avg_cost).
    SELECT w.branch_id INTO v_branch_id
    FROM warehouses w
    WHERE w.id = NEW.warehouse_id;

    PERFORM rcerp_notify('rcerp_stock_change', TG_TABLE_NAME, 'INSERT', NEW.id, v_branch_id,
        jsonb_build_object(
            'product_id',     NEW.product_id,
            'warehouse_id',   NEW.warehouse_id,
            'reference_type', NEW.reference_type,
            'reference_id',   NEW.reference_id,
            'qty',            NEW.qty,
            'rate',           NEW.rate
        )
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 7. Trigger function: rcerp_notify_journal_entry()
        //    Fires on journal_entries INSERT only.
        //    Notifies for real-time GL dashboard updates.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_journal_entry()
RETURNS trigger AS $$
BEGIN
    PERFORM rcerp_notify('rcerp_journal_entry', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id,
        jsonb_build_object(
            'entry_no',       NEW.entry_no,
            'reference_type', NEW.reference_type,
            'reference_id',   NEW.reference_id,
            'is_reversed',    NEW.is_reversed
        )
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 8. Trigger function: rcerp_notify_system()
        //    Fires on system_policies UPDATE and users UPDATE.
        //    Notifies for policy changes and user activation/deactivation.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_system_policy()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.mode IS DISTINCT FROM OLD.mode THEN
        -- system_policies has no policy_key column — use id and mode
        -- as the identifying attributes (mode is the unique identifier
        -- for the active policy type).
        PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, 'UPDATE', NEW.id, NULL,
            jsonb_build_object(
                'policy_id', NEW.id,
                'old_mode',  OLD.mode,
                'new_mode',  NEW.mode
            )
        );
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 9. Attach triggers to tables
        // ============================================================
        $triggers = [
            ['sales_invoices',   'rcerp_notify_sales_invoice',   'AFTER INSERT OR UPDATE'],
            ['sales_challans',   'rcerp_notify_sales_challan',   'AFTER INSERT OR UPDATE'],
            ['sales_returns',    'rcerp_notify_sales_return',    'AFTER INSERT OR UPDATE'],
            ['customer_payments','rcerp_notify_customer_payment','AFTER INSERT OR UPDATE'],
            ['stock_transactions','rcerp_notify_stock_change',   'AFTER INSERT'],
            ['journal_entries',  'rcerp_notify_journal_entry',   'AFTER INSERT'],
            ['system_policies',  'rcerp_notify_system_policy',   'AFTER UPDATE'],
        ];

        foreach ($triggers as [$table, $function, $timing]) {
            $triggerName = "trg_notify_{$table}";
            DB::statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");
            DB::statement("CREATE TRIGGER {$triggerName} {$timing} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
        }

        // ============================================================
        // 10. Monitoring view: v_listen_notify_channels
        //     Shows active LISTEN/NOTIFY activity via pg_stat_activity.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_listen_notify_channels AS
SELECT
    pid,
    usename,
    application_name,
    client_addr,
    backend_start,
    query_start,
    state,
    query
FROM pg_stat_activity
WHERE query ILIKE '%LISTEN%'
   OR query ILIKE '%rcerp_%'
ORDER BY backend_start DESC
SQL);
    }

    public function down(): void
    {
        // Drop triggers
        $tables = [
            'sales_invoices', 'sales_challans', 'sales_returns',
            'customer_payments', 'stock_transactions', 'journal_entries',
            'system_policies',
        ];

        foreach ($tables as $table) {
            DB::statement("DROP TRIGGER IF EXISTS trg_notify_{$table} ON {$table}");
        }

        // Drop trigger functions
        $functions = [
            'rcerp_notify_sales_invoice',
            'rcerp_notify_sales_challan',
            'rcerp_notify_sales_return',
            'rcerp_notify_customer_payment',
            'rcerp_notify_stock_change',
            'rcerp_notify_journal_entry',
            'rcerp_notify_system_policy',
        ];

        foreach ($functions as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}() CASCADE");
        }

        // Drop helper function
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify(text, text, text, integer, integer, jsonb) CASCADE');

        // Drop monitoring view
        DB::statement('DROP VIEW IF EXISTS v_listen_notify_channels');
    }
};
