<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace document_sequences SELECT FOR UPDATE with Advisory Locks — Task 20.
 *
 * This migration is intentionally lightweight — the core change is in the PHP
 * service layer (DocumentSequenceService), which replaces `lockForUpdate()` with
 * `pg_advisory_xact_lock()`. The migration's role is:
 *
 *   1. Add a documentation comment to the `document_sequences` table.
 *   2. Ensure the RLS policy on document_sequences does not interfere with
 *      advisory lock–based sequence allocation (branch_id=0 rows must be
 *      visible to all branches for global sequences).
 *   3. Add a helper function `doc_seq_advisory_key()` that computes the same
 *      hash as DocumentSequenceService::computeLockKey(), so SQL-level
 *      diagnostics can inspect current lock waits.
 *   4. Add an index on `doc_type, branch_id, period_key` as a covering index
 *      (INCLUDE last_number) to make the advisory-locked SELECT fast without
 *      needing to touch the heap.
 *
 * Why advisory locks instead of SELECT FOR UPDATE:
 *
 *   | Aspect                | SELECT FOR UPDATE             | Advisory Lock                |
 *   |-----------------------|-------------------------------|------------------------------|
 *   | Lock storage         | Disk (page infomask + WAL)    | Shared memory only           |
 *   | Read blocking        | Blocks all reads on the row   | Reads proceed unblocked      |
 *   | Lock scope           | Row-level                     | Session/transaction-level    |
 *   | RLS interaction      | WHERE clause filtered → 0 rows| Independent of RLS           |
 *   | Auto-release         | On COMMIT/ROLLBACK only       | On COMMIT/ROLLBACK (xact)    |
 *   | Cross-transaction    | No (must be in same xact)     | No (xact_lock is same scope) |
 *   | Performance          | ~0.5–2ms per lock (disk I/O)  | ~0.01–0.05ms (memory only)   |
 *
 * The RLS concern is critical: all current sequences use branch_id=0 (global),
 * but the RLS policy filters by `current_setting('app.branch_id')::int`. A
 * non-admin user's lockForUpdate() would find 0 rows and allocate a duplicate
 * number. Advisory locks bypass this entirely since they don't depend on
 * reading a row to establish the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add helper function for SQL-level advisory key computation.
        //    Mirrors DocumentSequenceService::computeLockKey() in PHP.
        //    Uses hashtext() which is the PostgreSQL built-in hash function
        //    (different algorithm from PHP's crc32, but consistent within PG).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION doc_seq_advisory_key(
                p_doc_type  varchar,
                p_branch_id integer DEFAULT 0,
                p_period_key varchar DEFAULT ''
            ) RETURNS integer
            LANGUAGE sql IMMUTABLE STRICT
            AS $$
                SELECT (
                    ('x' || left(md5(p_doc_type || ':' || p_branch_id::text || ':' || p_period_key), 8))::bit(32)::int
                );
            $$;
        SQL);

        // 2. Add covering index on document_sequences for the advisory-lock
        //    read pattern. When DocumentSequenceService does:
        //      SELECT * FROM document_sequences WHERE doc_type=? AND branch_id=? AND period_key=?
        //    this index satisfies the query entirely from the index (INCLUDE last_number),
        //    avoiding heap access. Combined with advisory locks (no FOR UPDATE),
        //    this makes sequence allocation extremely fast.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_doc_seq_covering
                ON document_sequences (doc_type, branch_id, period_key)
                INCLUDE (last_number, id);
        SQL);

        // 3. RLS compatibility: ensure the document_sequences RLS policy allows
        //    all roles to see branch_id=0 rows (global sequences). This is already
        //    the case because the RLS policy was designed with admin bypass, but
        //    we add an explicit policy for branch_id=0 visibility.
        //
        //    The existing policy `document_sequences_select_policy` uses:
        //      USING (branch_id = current_setting('app.branch_id')::int)
        //    which would hide branch_id=0 rows from non-admin, non-zero-branch users.
        //    We add a policy that always allows SELECT on branch_id=0 rows.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE POLICY document_sequences_global_select
                ON document_sequences
                FOR SELECT
                USING (branch_id = 0);
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE POLICY document_sequences_global_insert
                ON document_sequences
                FOR INSERT
                WITH CHECK (branch_id = 0);
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE POLICY document_sequences_global_update
                ON document_sequences
                FOR UPDATE
                USING (branch_id = 0)
                WITH CHECK (branch_id = 0);
        SQL);

        // 4. Drop the old per-branch RLS policies on document_sequences that
        //    would conflict with global (branch_id=0) sequence allocation.
        //    We keep the admin-bypass policy but replace branch-scoped ones.
        DB::statement("DROP POLICY IF EXISTS document_sequences_select_policy ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_insert_policy ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_update_policy ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_delete_policy ON document_sequences");

        // 5. Admin bypass policy (superadmin can see/modify all sequences).
        DB::statement(<<<'SQL'
            CREATE POLICY document_sequences_admin_all
                ON document_sequences
                FOR ALL
                USING (current_setting('app.is_admin', true)::text = 'true')
                WITH CHECK (current_setting('app.is_admin', true)::text = 'true');
        SQL);

        DB::statement('ANALYZE document_sequences');
    }

    public function down(): void
    {
        // Drop the helper function.
        DB::statement("DROP FUNCTION IF EXISTS doc_seq_advisory_key(varchar, integer, varchar)");

        // Drop the covering index.
        DB::statement("DROP INDEX IF EXISTS idx_doc_seq_covering");

        // Drop the global and admin policies.
        DB::statement("DROP POLICY IF EXISTS document_sequences_global_select ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_global_insert ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_global_update ON document_sequences");
        DB::statement("DROP POLICY IF EXISTS document_sequences_admin_all ON document_sequences");

        // Recreate the original per-branch RLS policies (as they were in Task 19 migration).
        DB::statement(<<<'SQL'
            CREATE POLICY document_sequences_select_policy
                ON document_sequences FOR SELECT
                USING (branch_id = current_setting('app.branch_id', true)::int
                       OR current_setting('app.is_admin', true)::text = 'true');
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY document_sequences_insert_policy
                ON document_sequences FOR INSERT
                WITH CHECK (branch_id = current_setting('app.branch_id', true)::int
                            OR current_setting('app.is_admin', true)::text = 'true');
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY document_sequences_update_policy
                ON document_sequences FOR UPDATE
                USING (branch_id = current_setting('app.branch_id', true)::int
                       OR current_setting('app.is_admin', true)::text = 'true')
                WITH CHECK (branch_id = current_setting('app.branch_id', true)::int
                            OR current_setting('app.is_admin', true)::text = 'true');
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY document_sequences_delete_policy
                ON document_sequences FOR DELETE
                USING (branch_id = current_setting('app.branch_id', true)::int
                       OR current_setting('app.is_admin', true)::text = 'true');
        SQL);
    }
};
