-- ============================================================
-- Sync PostgreSQL sequences after ETL
-- Phase 2.3 — Run AFTER pgloader + post_load_fixes.sql
-- ============================================================
-- pgloader's "reset sequences" option should handle this, but this script
-- is a safety net to ensure every IDENTITY sequence is set to MAX(id)+1
-- so the next INSERT doesn't collide with existing data.
-- ============================================================

\set ON_ERROR_STOP ON

DO $$
DECLARE
    r record;
    max_id bigint;
    seq_name text;
BEGIN
    FOR r IN
        SELECT
            c.table_name,
            c.column_name,
            pg_get_serial_sequence(format('%I', c.table_name), c.column_name) AS seq
        FROM information_schema.columns c
        WHERE c.table_schema = 'public'
          AND c.column_default LIKE 'nextval%'
          AND c.table_name NOT IN ('schema_migrations')
    LOOP
        IF r.seq IS NOT NULL THEN
            EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I', r.column_name, r.table_name) INTO max_id;
            IF max_id > 0 THEN
                EXECUTE format('SELECT setval(%L, %s)', r.seq, max_id);
                RAISE NOTICE 'Set % to % for %.%', r.seq, max_id, r.table_name, r.column_name;
            END IF;
        END IF;
    END LOOP;
END;
$$;

-- Special case: login_rate_limits has a varchar PK (bucket_key), no sequence.
-- warehouse_stock has composite PK (warehouse_id, product_id), no sequence.
-- These are correctly skipped by the loop above.

-- ============================================================
-- DONE. The database is now ready for the legacy PHP app to connect.
-- ============================================================
