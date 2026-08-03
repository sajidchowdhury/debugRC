<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 7.2: Archival stored procedures.
 *
 * Audit finding (roadmap §6.2, lines 418-450): The Phase 0.5 migration created
 * the `archive` schema and pg_partman is configured to auto-detach expired
 * partitions into it (`retention_keep_table = true`, `retention_schema =
 * 'archive'`). However, there is currently NO reversible, validated way to
 * manually detach a single partition (e.g. for an emergency restore) or to
 * move a partition back into `public` for inspection.
 *
 * This migration installs two PL/pgSQL helper functions:
 *
 *   1. archive_partition(p_parent TEXT, p_partition TEXT)
 *      - Validates that `p_partition` is currently a child of `p_parent`
 *        (queries `pg_inherits`).
 *      - DETACHes the partition from its parent (CONCURRENTLY is not supported
 *        inside a function — use plain DETACH).
 *      - Moves the now-standalone table into the `archive` schema via
 *        `ALTER TABLE <partition> SET SCHEMA archive`.
 *      - The table name does NOT change; only its schema does. After this,
 *        the partition is reachable as `archive.<partition>`.
 *
 *   2. restore_partition(p_parent TEXT, p_partition TEXT, p_start DATE, p_end DATE)
 *      - Validates that `archive.<p_partition>` exists.
 *      - Moves it back to `public` via `ALTER TABLE archive.<p_partition>
 *        SET SCHEMA public`.
 *      - ATTACHes it as a partition of `p_parent` for the range
 *        `[p_start, p_end)`. Caller must supply the exact range the partition
 *        was originally created with — a mismatch will raise an exception at
 *        ATTACH time (PostgreSQL validates partition bounds against existing
 *        children and against any CHECK constraints).
 *
 * Lifecycle (roadmap §14.1):
 *   live (public.<parent>_p partition)   ─┐
 *                                          │  pg_partman retention run
 *                                          │  OR manual archive_partition()
 *                                          ▼
 *   archived (archive.<partition>)        ─┐
 *                                          │  ExportArchivedPartitionsToParquet
 *                                          │  (Phase 7.3) → Parquet + DROP
 *                                          ▼
 *   cold storage (storage/app/partition-exports/*.parquet)
 *
 *   Reversible: restore_partition() moves a Parquet-restored or
 *   archive-resident table back into `public.<parent>` for inspection or
 *   re-attachment when a historical audit requires live SQL access.
 *
 * Both functions are `LANGUAGE plpgsql`, use `EXECUTE format(...)` with `%I`
 * for identifier escaping, and raise meaningful exceptions on failure.
 *
 * Idempotency: `CREATE OR REPLACE FUNCTION` is used so the migration is
 * safe to re-run. The `down()` method `DROP FUNCTION IF EXISTS`es both.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. archive_partition(p_parent TEXT, p_partition TEXT)
        // ============================================================
        // Detaches a partition from its parent and moves it to the `archive`
        // schema. Validates the parent-child relationship first by querying
        // pg_inherits; raises a meaningful exception if the partition isn't
        // currently attached to the named parent.
        //
        // Notes:
        //   - DETACH PARTITION inside a function cannot use CONCURRENTLY
        //     (CONCURRENTLY must be the only statement in its transaction).
        //     For an online detach of a hot partition, call ALTER TABLE
        //     ... DETACH PARTITION CONCURRENTLY manually outside this function.
        //   - After SET SCHEMA archive, the table's indexes and constraints
        //     keep their original names — only the schema qualifier changes.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION archive_partition(
                p_parent    TEXT,
                p_partition TEXT
            ) RETURNS VOID AS $$
            DECLARE
                v_parent_oid    OID;
                v_partition_oid OID;
                v_is_child      BOOLEAN;
            BEGIN
                -- Resolve parent OID (must exist in public schema).
                SELECT c.oid INTO v_parent_oid
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relname = p_parent;

                IF v_parent_oid IS NULL THEN
                    RAISE EXCEPTION 'Parent table public.% does not exist.', p_parent
                        USING ERRCODE = '42P01';
                END IF;

                -- Resolve partition OID (must currently live in public — i.e.
                -- it has NOT already been archived).
                SELECT c.oid INTO v_partition_oid
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relname = p_partition;

                IF v_partition_oid IS NULL THEN
                    RAISE EXCEPTION
                        'Partition public.% does not exist (it may already be archived).',
                        p_partition
                        USING ERRCODE = '42P01';
                END IF;

                -- Confirm parent-child relationship via pg_inherits.
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_inherits
                    WHERE inhparent = v_parent_oid
                      AND inhrelid  = v_partition_oid
                ) INTO v_is_child;

                IF NOT v_is_child THEN
                    RAISE EXCEPTION
                        'public.% is not a child partition of public.%.',
                        p_partition, p_parent
                        USING ERRCODE = '55006';
                END IF;

                -- Detach. Cannot use CONCURRENTLY inside a function.
                EXECUTE format('ALTER TABLE public.%I DETACH PARTITION public.%I',
                               p_parent, p_partition);

                -- Move the now-standalone table into the `archive` schema.
                -- Table name is unchanged; only the schema qualifier changes.
                EXECUTE format('ALTER TABLE public.%I SET SCHEMA archive', p_partition);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 2. restore_partition(p_parent, p_partition, p_start, p_end)
        // ============================================================
        // Moves a table from the `archive` schema back to `public` and
        // attaches it as a partition of `p_parent` for the range [p_start, p_end).
        //
        // Caller MUST supply the exact original partition bounds. A mismatch
        // (overlapping an existing partition or violating a CHECK constraint)
        // will raise an exception at ATTACH time.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION restore_partition(
                p_parent    TEXT,
                p_partition TEXT,
                p_start     DATE,
                p_end       DATE
            ) RETURNS VOID AS $$
            DECLARE
                v_parent_oid        OID;
                v_archived_exists   BOOLEAN;
            BEGIN
                IF p_end <= p_start THEN
                    RAISE EXCEPTION
                        'Invalid partition range: p_end (%) must be after p_start (%).',
                        p_end, p_start
                        USING ERRCODE = '22023';
                END IF;

                -- Resolve parent OID (must exist in public schema).
                SELECT c.oid INTO v_parent_oid
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relname = p_parent;

                IF v_parent_oid IS NULL THEN
                    RAISE EXCEPTION 'Parent table public.% does not exist.', p_parent
                        USING ERRCODE = '42P01';
                END IF;

                -- Confirm the table exists in the archive schema.
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'archive'
                      AND c.relname = p_partition
                ) INTO v_archived_exists;

                IF NOT v_archived_exists THEN
                    RAISE EXCEPTION
                        'Archived table archive.% does not exist.', p_partition
                        USING ERRCODE = '42P01';
                END IF;

                -- Move the table back to public schema. Name unchanged.
                EXECUTE format('ALTER TABLE archive.%I SET SCHEMA public', p_partition);

                -- Attach as a partition of p_parent for the given range.
                -- PostgreSQL validates that the new range doesn't overlap any
                -- existing child partition of p_parent.
                EXECUTE format(
                    'ALTER TABLE public.%I ATTACH PARTITION public.%I FOR VALUES FROM (%L) TO (%L)',
                    p_parent, p_partition, p_start, p_end
                );
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Log::info('Phase 7.2: archival procedures created (archive_partition, restore_partition).');
    }

    public function down(): void
    {
        // Reverse: drop both helper functions. IF EXISTS makes this safe
        // even if only one was created (e.g. partial rollback).
        DB::statement('DROP FUNCTION IF EXISTS archive_partition(TEXT, TEXT)');
        DB::statement('DROP FUNCTION IF EXISTS restore_partition(TEXT, TEXT, DATE, DATE)');

        Log::info('Phase 7.2: archival procedures dropped.');
    }
};
