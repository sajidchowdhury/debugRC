<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: fn_financial_audit_trigger() — branch_id access on tables without the column.
 *
 * The trigger was using NEW.branch_id / OLD.branch_id directly, but some
 * audited tables (e.g., journal_lines) don't have a branch_id column.
 * This caused "record NEW has no field branch_id" at runtime.
 *
 * Fix: extract branch_id from the JSONB representation (_before / _after)
 * instead of direct field access. JSONB ->> returns NULL for missing keys,
 * so this works for all tables regardless of schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_financial_audit_trigger()
RETURNS TRIGGER AS $$
DECLARE
    _prev_hash VARCHAR(64);
    _row_hash  VARCHAR(64);
    _before    JSONB;
    _after     JSONB;
    _changed   TEXT[];
    _col       TEXT;
    _op        VARCHAR(6);
    _record_id BIGINT;
    _branch_id INTEGER;
    _performed_by VARCHAR(100);
    _session_user VARCHAR(100);
    _request_path VARCHAR(500);
    _request_ip   VARCHAR(45);
    _request_id   VARCHAR(100);
    _xmin      XID;
BEGIN
    _op := TG_OP;

    -- Determine record_id from the NEW or OLD row
    IF _op = 'DELETE' THEN
        _record_id := OLD.id;
        _before := to_jsonb(OLD);
        _after := NULL;
        _changed := ARRAY[]::TEXT[];
        _xmin := OLD.xmin;
    ELSIF _op = 'INSERT' THEN
        _record_id := NEW.id;
        _before := NULL;
        _after := to_jsonb(NEW);
        _changed := ARRAY[]::TEXT[];
        _xmin := NEW.xmin;
    ELSE -- UPDATE
        _record_id := NEW.id;
        _before := to_jsonb(OLD);
        _after := to_jsonb(NEW);
        -- Detect changed columns
        _changed := ARRAY[]::TEXT[];
        FOR _col IN
            SELECT key FROM jsonb_object_keys(_before) AS key
            WHERE (_before->>key) IS DISTINCT FROM (_after->>key)
        LOOP
            _changed := array_append(_changed, _col);
        END LOOP;
        _xmin := NEW.xmin;
    END IF;

    -- Get branch_id from the JSONB representation (works for tables without branch_id column)
    _branch_id := COALESCE(
        (_after ->> 'branch_id')::INTEGER,
        (_before ->> 'branch_id')::INTEGER
    );

    -- Get user identity
    _session_user := session_user;
    _performed_by := current_user;

    -- Get request context (if available from app settings)
    BEGIN
        _request_path := current_setting('app.request_path', true);
    EXCEPTION WHEN OTHERS THEN
        _request_path := NULL;
    END;
    BEGIN
        _request_ip := current_setting('app.request_ip', true);
    EXCEPTION WHEN OTHERS THEN
        _request_ip := NULL;
    END;
    BEGIN
        _request_id := current_setting('app.request_id', true);
    EXCEPTION WHEN OTHERS THEN
        _request_id := NULL;
    END;

    -- Get previous hash for cryptographic chaining
    SELECT row_hash INTO _prev_hash
    FROM financial_audit_log
    ORDER BY id DESC
    LIMIT 1;

    IF _prev_hash IS NULL THEN
        _prev_hash := '0000000000000000000000000000000000000000000000000000000000000000';
    END IF;

    -- Compute this row's hash: SHA-256 of (prev_hash + table_name + operation + record_id + COALESCE(after_data, before_data))
    _row_hash := encode(
        digest(
            _prev_hash || TG_TABLE_NAME || _op || _record_id::TEXT || COALESCE(_after::TEXT, _before::TEXT),
            'sha256'
        ),
        'hex'
    );

    -- Insert the audit record
    INSERT INTO financial_audit_log (
        table_name, operation, record_id,
        before_data, after_data, changed_columns,
        performed_by, db_session_user, branch_id,
        transaction_id,
        request_path, request_ip, request_id,
        prev_hash, row_hash
    ) VALUES (
        TG_TABLE_NAME, _op, _record_id,
        _before, _after, _changed,
        _performed_by, _session_user, _branch_id,
        _xmin,
        _request_path, _request_ip, _request_id,
        _prev_hash, _row_hash
    );

    IF _op = 'DELETE' THEN
        RETURN OLD;
    ELSE
        RETURN NEW;
    END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER
SQL);
    }

    public function down(): void
    {
        // No down — keeping the fixed version
    }
};
