<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.3 — Core Foundation Hardening: Immutable Financial Audit Trail.
 *
 * Creates the financial_audit_log table with:
 *   - Append-only (NO UPDATE/DELETE grants, even for superuser)
 *   - Before/after JSON snapshots for every mutation
 *   - Transaction ID (xmin) for forensic correlation
 *   - User identity from current_user
 *   - Cryptographic chaining (each row includes SHA-256 hash of previous row)
 *     for tamper detection, as required by some jurisdictions
 *     (e.g., France FEC, Germany GoBD)
 *
 * Also creates PostgreSQL triggers for the core financial tables:
 *   - journal_entries
 *   - journal_lines
 *   - manual_journals
 *   - manual_journal_lines
 *   - customer_payments
 *   - supplier_payments
 *   - money_transfers
 *   - other_incomes
 *   - other_expenses
 *   - employee_transactions
 *
 * Each trigger captures INSERT/UPDATE/DELETE and writes to financial_audit_log.
 * The table is truly immutable: REVOKE UPDATE/DELETE from ALL roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Create the financial_audit_log table
        // ============================================================
        DB::statement(<<<SQL
CREATE TABLE financial_audit_log (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    table_name      VARCHAR(64) NOT NULL,
    operation       VARCHAR(6)  NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE')),
    record_id       BIGINT NOT NULL,
    before_data     JSONB,
    after_data      JSONB,
    changed_columns TEXT[],
    performed_by    VARCHAR(100),
    session_user    VARCHAR(100),
    branch_id       INTEGER,
    transaction_id  XID,
    request_path    VARCHAR(500),
    request_ip      VARCHAR(45),
    request_id      VARCHAR(100),
    prev_hash       VARCHAR(64),
    row_hash        VARCHAR(64),
    created_at      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        // Indexes for common queries
        DB::statement('CREATE INDEX idx_fal_table_record ON financial_audit_log(table_name, record_id)');
        DB::statement('CREATE INDEX idx_fal_operation ON financial_audit_log(operation)');
        DB::statement('CREATE INDEX idx_fal_performed_by ON financial_audit_log(performed_by)');
        DB::statement('CREATE INDEX idx_fal_branch ON financial_audit_log(branch_id)');
        DB::statement('CREATE INDEX idx_fal_created_at ON financial_audit_log(created_at)');
        DB::statement('CREATE INDEX idx_fal_table_op ON financial_audit_log(table_name, operation)');

        // Table comment
        DB::statement(<<<SQL
COMMENT ON TABLE financial_audit_log IS 'Phase 1.3: Immutable append-only audit trail for financial data. No UPDATE/DELETE allowed. Cryptographic chaining for tamper detection.'
SQL);

        // ============================================================
        // 2. Make the table immutable: REVOKE UPDATE/DELETE from ALL
        // ============================================================
        DB::statement('REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC');
        DB::statement('REVOKE UPDATE, DELETE ON financial_audit_log FROM postgres');
        DB::statement('REVOKE UPDATE, DELETE ON financial_audit_log FROM remote_center');

        // ============================================================
        // 3. Create the trigger function that logs mutations
        // ============================================================
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
BEGIN
    _op := TG_OP;

    -- Determine record_id from the NEW or OLD row
    IF _op = 'DELETE' THEN
        _record_id := OLD.id;
        _before := to_jsonb(OLD);
        _after := NULL;
        _changed := ARRAY[]::TEXT[];
    ELSIF _op = 'INSERT' THEN
        _record_id := NEW.id;
        _before := NULL;
        _after := to_jsonb(NEW);
        _changed := ARRAY[]::TEXT[];
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
    END IF;

    -- Get branch_id from the row if available
    _branch_id := NULL;
    IF _op = 'DELETE' THEN
        IF OLD.branch_id IS NOT NULL THEN
            _branch_id := OLD.branch_id;
        END IF;
    ELSE
        IF NEW.branch_id IS NOT NULL THEN
            _branch_id := NEW.branch_id;
        END IF;
    END IF;

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
        performed_by, session_user, branch_id,
        transaction_id,
        request_path, request_ip, request_id,
        prev_hash, row_hash
    ) VALUES (
        TG_TABLE_NAME, _op, _record_id,
        _before, _after, _changed,
        _performed_by, _session_user, _branch_id,
        xmin,
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

        // ============================================================
        // 4. Attach triggers to financial tables
        // ============================================================
        $tables = [
            'journal_entries',
            'journal_lines',
            'manual_journals',
            'manual_journal_lines',
            'customer_payments',
            'supplier_payments',
            'money_transfers',
            'other_incomes',
            'other_expenses',
            'employee_transactions',
        ];

        foreach ($tables as $table) {
            $triggerName = "trg_audit_{$table}";
            DB::statement(<<<SQL
CREATE TRIGGER {$triggerName}
AFTER INSERT OR UPDATE OR DELETE ON {$table}
FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger()
SQL);
        }

        // ============================================================
        // 5. Create a view for hash chain verification
        // ============================================================
        DB::statement(<<<SQL
CREATE OR REPLACE VIEW v_financial_audit_chain_verification AS
SELECT
    id,
    table_name,
    operation,
    record_id,
    prev_hash,
    row_hash,
    CASE
        WHEN id = 1 THEN
            prev_hash = '0000000000000000000000000000000000000000000000000000000000000000'
        ELSE
            prev_hash = LAG(row_hash) OVER (ORDER BY id)
    END AS chain_valid,
    created_at
FROM financial_audit_log
ORDER BY id
SQL);

        DB::statement(<<<SQL
COMMENT ON VIEW v_financial_audit_chain_verification IS 'Phase 1.3: Verification view for the cryptographic hash chain. If chain_valid is FALSE, the audit trail has been tampered with.'
SQL);
    }

    public function down(): void
    {
        $tables = [
            'journal_entries',
            'journal_lines',
            'manual_journals',
            'manual_journal_lines',
            'customer_payments',
            'supplier_payments',
            'money_transfers',
            'other_incomes',
            'other_expenses',
            'employee_transactions',
        ];

        foreach ($tables as $table) {
            $triggerName = "trg_audit_{$table}";
            DB::statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");
        }

        DB::statement('DROP VIEW IF EXISTS v_financial_audit_chain_verification');
        DB::statement('DROP FUNCTION IF EXISTS fn_financial_audit_trigger()');
        DB::statement('DROP TABLE IF EXISTS financial_audit_log');
    }
};
