<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Damage Phase 3 — Photo / Evidence Attachments.
 *
 * Closes the second-biggest accountability gap surfaced in the GAP analysis:
 * damage declarations had NO photographic evidence. A "real damage" or "theft"
 * write-off could be posted with zero proof — making insurance claims
 * impossible and leaving the door wide open for fake write-offs (an employee
 * declares stock as damaged, walks out with it, no photo to contradict them).
 *
 * This migration introduces the `damage_attachments` table for storing
 * photographic / documentary evidence against a damage invoice, plus:
 *
 *   1. Row-Level Security mirroring `damage_invoices` — a non-admin user can
 *      only SELECT/INSERT/UPDATE/DELETE attachments whose parent damage
 *      belongs to their session branch. The policy JOINs through
 *      damage_invoices (attachments have no branch_id of their own).
 *
 *   2. A LISTEN/NOTIFY trigger on `damage_attachments` firing on the dedicated
 *      `rcerp_damage_attachment_change` channel. This lets the damage detail
 *      page auto-refresh its Evidence gallery when another user (or the same
 *      user in another tab) uploads or removes a photo — ports the Phase 2
 *      real-time strength to child rows without noising up the index page's
 *      `rcerp_damage_change` banner (an attachment upload is not a header
 *      change, so the index refresh banner should NOT fire).
 *
 * Storage strategy (enforced in the controller, NOT the schema):
 *   Evidence files are stored on the `local` disk (storage/app/private) and
 *   streamed through an authorized controller route — NOT on the `public`
 *   disk. Damage evidence is sensitive (theft scenes, damaged inventory,
 *   potentially identifying employees) and must NOT be web-accessible via a
 *   guessable /storage/... URL. RLS is meaningless if the file is publicly
 *   served. The `file_path` column stores the disk-relative path.
 *
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md  Phase 3
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // Table: damage_attachments
        //   One damage → many evidence files (photos / PDFs).
        //   FK ON DELETE CASCADE so a hard-deleted damage takes its
        //   attachment rows with it (the controller deletes the physical
        //   files first via Storage::delete before the damage is removed).
        //   A soft-delete of the damage (cancelled) KEEPS the attachments —
        //   audit trail must survive a cancel/reverse (Phase 3 risk note).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE TABLE damage_attachments (
    id                 integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    damage_invoice_id  integer NOT NULL REFERENCES damage_invoices(id) ON DELETE CASCADE,
    file_path          varchar(500) NOT NULL,
    file_name          varchar(255) NOT NULL,
    mime_type          varchar(100) NOT NULL,
    file_size          bigint NOT NULL,
    caption            varchar(255),
    uploaded_by        integer NOT NULL REFERENCES users(id),
    created_at         timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        DB::statement('CREATE INDEX idx_dmg_att_damage ON damage_attachments(damage_invoice_id)');
        DB::statement('CREATE INDEX idx_dmg_att_uploader ON damage_attachments(uploaded_by)');

        // ============================================================
        // Row-Level Security — mirror damage_invoices.
        //
        // damage_attachments has NO branch_id column (the branch is implied
        // by the parent damage_invoices row). Every policy JOINs through
        // damage_invoices to check the parent's branch_id against the
        // session's app.branch_id GUC (set by SetAppBranchId middleware).
        //
        // The admin override (app.is_admin = 'true') lets superadmins /
        // cross-branch admins through — matching every other RLS policy in
        // the system (see 07_views_triggers_constraints.sql).
        // ============================================================
        DB::statement('ALTER TABLE damage_attachments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE damage_attachments FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
CREATE POLICY rls_damage_attachments_select ON damage_attachments FOR SELECT
    USING (
        current_setting('app.is_admin', true) = 'true'
        OR EXISTS (
            SELECT 1 FROM damage_invoices di
            WHERE di.id = damage_attachments.damage_invoice_id
              AND di.branch_id = current_setting('app.branch_id')::int
        )
    )
SQL);

        DB::statement(<<<'SQL'
CREATE POLICY rls_damage_attachments_insert ON damage_attachments FOR INSERT
    WITH CHECK (
        current_setting('app.is_admin', true) = 'true'
        OR EXISTS (
            SELECT 1 FROM damage_invoices di
            WHERE di.id = damage_attachments.damage_invoice_id
              AND di.branch_id = current_setting('app.branch_id')::int
        )
    )
SQL);

        DB::statement(<<<'SQL'
CREATE POLICY rls_damage_attachments_update ON damage_attachments FOR UPDATE
    USING (
        current_setting('app.is_admin', true) = 'true'
        OR EXISTS (
            SELECT 1 FROM damage_invoices di
            WHERE di.id = damage_attachments.damage_invoice_id
              AND di.branch_id = current_setting('app.branch_id')::int
        )
    )
    WITH CHECK (
        current_setting('app.is_admin', true) = 'true'
        OR EXISTS (
            SELECT 1 FROM damage_invoices di
            WHERE di.id = damage_attachments.damage_invoice_id
              AND di.branch_id = current_setting('app.branch_id')::int
        )
    )
SQL);

        DB::statement(<<<'SQL'
CREATE POLICY rls_damage_attachments_delete ON damage_attachments FOR DELETE
    USING (
        current_setting('app.is_admin', true) = 'true'
        OR EXISTS (
            SELECT 1 FROM damage_invoices di
            WHERE di.id = damage_attachments.damage_invoice_id
              AND di.branch_id = current_setting('app.branch_id')::int
        )
    )
SQL);

        DB::statement(<<<'SQL'
CREATE POLICY rls_damage_attachments_admin ON damage_attachments FOR ALL
    USING (current_setting('app.is_admin', true) = 'true')
    WITH CHECK (current_setting('app.is_admin', true) = 'true')
SQL);

        // ============================================================
        // LISTEN/NOTIFY trigger — rcerp_damage_attachment_change channel.
        //
        // Fires AFTER INSERT / DELETE on damage_attachments. UPDATE is NOT
        // wired (only `caption` is user-editable and that doesn't justify a
        // NOTIFY; if caption-edit is added later, wire UPDATE here too).
        //
        // Payload shape (matches rcerp_notify() convention):
        //   { table, action, id, branch_id, changes }
        // where:
        //   id        = the damage_attachments.id (NOT the damage id)
        //   branch_id = the PARENT damage's branch_id (resolved via a join
        //               so branch-scoped SSE clients only get their own)
        //   changes   = { damage_invoice_id, mime_type, file_name }
        //
        // The detail page subscribes to this channel and reloads its Evidence
        // gallery when the payload's damage_invoice_id matches the open
        // damage. The index page does NOT subscribe (an attachment upload is
        // not a header change — the index refresh banner must stay quiet).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_damage_attachment()
RETURNS trigger AS $$
DECLARE
    v_branch integer;
BEGIN
    -- Resolve the parent damage's branch_id once. NULL (e.g. if the parent
    -- was hard-deleted in the same tx) falls back to 0 so the event is still
    -- published to the global SSE queue.
    SELECT di.branch_id INTO v_branch
      FROM damage_invoices di
     WHERE di.id = NEW.damage_invoice_id;

    IF v_branch IS NULL THEN
        v_branch := 0;
    END IF;

    PERFORM rcerp_notify(
        'rcerp_damage_attachment_change',
        TG_TABLE_NAME,
        TG_OP,
        NEW.id,
        v_branch,
        jsonb_build_object(
            'damage_invoice_id', NEW.damage_invoice_id,
            'mime_type',          NEW.mime_type,
            'file_name',          NEW.file_name
        )
    );

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_damage_attachment_delete()
RETURNS trigger AS $$
DECLARE
    v_branch integer;
BEGIN
    SELECT di.branch_id INTO v_branch
      FROM damage_invoices di
     WHERE di.id = OLD.damage_invoice_id;

    IF v_branch IS NULL THEN
        v_branch := 0;
    END IF;

    PERFORM rcerp_notify(
        'rcerp_damage_attachment_change',
        TG_TABLE_NAME,
        'DELETE',
        OLD.id,
        v_branch,
        jsonb_build_object(
            'damage_invoice_id', OLD.damage_invoice_id,
            'mime_type',         OLD.mime_type,
            'file_name',         OLD.file_name
        )
    );

    RETURN OLD;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_attachments_insert ON damage_attachments");
        DB::statement(<<<'SQL'
CREATE TRIGGER trg_notify_damage_attachments_insert
    AFTER INSERT ON damage_attachments
    FOR EACH ROW EXECUTE FUNCTION rcerp_notify_damage_attachment()
SQL);

        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_attachments_delete ON damage_attachments");
        DB::statement(<<<'SQL'
CREATE TRIGGER trg_notify_damage_attachments_delete
    AFTER DELETE ON damage_attachments
    FOR EACH ROW EXECUTE FUNCTION rcerp_notify_damage_attachment_delete()
SQL);
    }

    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_attachments_delete ON damage_attachments");
        DB::statement("DROP TRIGGER IF EXISTS trg_notify_damage_attachments_insert ON damage_attachments");
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify_damage_attachment_delete() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify_damage_attachment() CASCADE');

        DB::statement('DROP POLICY IF EXISTS rls_damage_attachments_admin ON damage_attachments');
        DB::statement('DROP POLICY IF EXISTS rls_damage_attachments_delete ON damage_attachments');
        DB::statement('DROP POLICY IF EXISTS rls_damage_attachments_update ON damage_attachments');
        DB::statement('DROP POLICY IF EXISTS rls_damage_attachments_insert ON damage_attachments');
        DB::statement('DROP POLICY IF EXISTS rls_damage_attachments_select ON damage_attachments');

        DB::statement('ALTER TABLE damage_attachments NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP TABLE IF EXISTS damage_attachments');
    }
};
