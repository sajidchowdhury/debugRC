<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — Damage LISTEN/NOTIFY trigger (port legacy strength).
 *
 * Adds a PostgreSQL LISTEN/NOTIFY trigger on `damage_invoices`, mirroring
 * the pattern established in `2025_01_21_000001_add_listen_notify_triggers`.
 * Previously damage_invoices was the ONLY transactional table without a
 * NOTIFY trigger — so the damage index/detail pages never auto-refreshed
 * when another user created, confirmed, or cancelled a damage, while every
 * other module (sales, returns, payments, stock, journal) did. This closed
 * that real-time parity gap.
 *
 * Channel: `rcerp_damage_change` (registered in ListenNotifyService::PG_CHANNELS).
 *
 * Flow:
 *   damage_invoices INSERT/UPDATE/DELETE
 *     → rcerp_notify_damage() trigger
 *       → rcerp_notify('rcerp_damage_change', ...)
 *         → pg_notify('rcerp_damage_change', payload)
 *           → ListenNotifyWorker (LISTEN) → Redis → SSE → browser
 *
 * The trigger only fires on meaningful column changes (status, is_reversed,
 * total_value, damage_type, journal_entry_id) to avoid NOTIFY noise from
 * `updated_at` bumps. DELETE always notifies (so the index can drop the row).
 *
 * NOTE: This migration does NOT add a `damage_audit_log` table. Integrity
 * checks are LIVE-COMPUTED by DamageIntegrityService (like legacy
 * DamageAuditModel) to avoid a second source of truth — see Phase 2 spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // Trigger function: rcerp_notify_damage()
        //   Fires on damage_invoices INSERT / UPDATE / DELETE.
        //   On INSERT: notifies with damage_type + status + total_value.
        //   On UPDATE: only notifies if a meaningful column changed
        //     (status, is_reversed, total_value, damage_type,
        //      journal_entry_id, branch_id, warehouse_id).
        //   On DELETE: always notifies (row removed → index must refresh).
        //
        // Uses the shared rcerp_notify() helper created by migration
        // 2025_01_21_000001 so the payload shape matches every other
        // channel (table, action, id, branch_id, changes, triggered_at).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_damage()
RETURNS trigger AS $$
DECLARE
    v_changes jsonb := '{}'::jsonb;
    v_id      integer;
    v_branch  integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        -- DELETE has no NEW row; use OLD for id + branch_id so branch-
        -- scoped SSE clients still receive the removal event.
        v_id     := OLD.id;
        v_branch := OLD.branch_id;
        v_changes := jsonb_build_object('status', OLD.status, 'is_reversed', OLD.is_reversed);
        PERFORM rcerp_notify('rcerp_damage_change', TG_TABLE_NAME, 'DELETE', v_id, v_branch, v_changes);
        RETURN OLD;
    END IF;

    -- INSERT
    IF TG_OP = 'INSERT' THEN
        v_changes := jsonb_build_object(
            'status',           NEW.status,
            'damage_type',      NEW.damage_type,
            'total_value',      NEW.total_value,
            'is_reversed',      NEW.is_reversed,
            'journal_entry_id', NEW.journal_entry_id
        );
        PERFORM rcerp_notify('rcerp_damage_change', TG_TABLE_NAME, 'INSERT', NEW.id, NEW.branch_id, v_changes);
        RETURN NEW;
    END IF;

    -- UPDATE: only notify on meaningful changes (skip updated_at noise).
    IF NEW.status           IS DISTINCT FROM OLD.status OR
       NEW.is_reversed      IS DISTINCT FROM OLD.is_reversed OR
       NEW.total_value      IS DISTINCT FROM OLD.total_value OR
       NEW.damage_type      IS DISTINCT FROM OLD.damage_type OR
       NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id OR
       NEW.branch_id        IS DISTINCT FROM OLD.branch_id OR
       NEW.warehouse_id     IS DISTINCT FROM OLD.warehouse_id
    THEN
        IF NEW.status IS DISTINCT FROM OLD.status THEN
            v_changes := jsonb_set(v_changes, '{status}', to_jsonb(NEW.status));
        END IF;
        IF NEW.is_reversed IS DISTINCT FROM OLD.is_reversed THEN
            v_changes := jsonb_set(v_changes, '{is_reversed}', to_jsonb(NEW.is_reversed));
        END IF;
        IF NEW.total_value IS DISTINCT FROM OLD.total_value THEN
            v_changes := jsonb_set(v_changes, '{total_value}', to_jsonb(NEW.total_value));
        END IF;
        IF NEW.damage_type IS DISTINCT FROM OLD.damage_type THEN
            v_changes := jsonb_set(v_changes, '{damage_type}', to_jsonb(NEW.damage_type));
        END IF;
        IF NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id THEN
            -- journal_entry_id is nullable: to_jsonb(NULL) returns SQL NULL,
            -- which would nullify the whole v_changes object via jsonb_set.
            -- COALESCE to JSON 'null' so the key is recorded as a JSON null
            -- instead (matches the INSERT branch's jsonb_build_object behaviour).
            v_changes := jsonb_set(v_changes, '{journal_entry_id}', COALESCE(to_jsonb(NEW.journal_entry_id), 'null'::jsonb));
        END IF;
        IF NEW.branch_id IS DISTINCT FROM OLD.branch_id THEN
            v_changes := jsonb_set(v_changes, '{branch_id}', to_jsonb(NEW.branch_id));
        END IF;
        IF NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id THEN
            v_changes := jsonb_set(v_changes, '{warehouse_id}', to_jsonb(NEW.warehouse_id));
        END IF;

        PERFORM rcerp_notify('rcerp_damage_change', TG_TABLE_NAME, 'UPDATE', NEW.id, NEW.branch_id, v_changes);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        // Attach the trigger (AFTER INSERT OR UPDATE OR DELETE, per-row).
        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_invoices ON damage_invoices");
        DB::statement(<<<'SQL'
CREATE TRIGGER trg_notify_damage_invoices
    AFTER INSERT OR UPDATE OR DELETE ON damage_invoices
    FOR EACH ROW EXECUTE FUNCTION rcerp_notify_damage()
SQL);
    }

    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_invoices ON damage_invoices");
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify_damage() CASCADE');
    }
};
