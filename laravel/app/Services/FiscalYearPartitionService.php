<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\PeriodCloseLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FiscalYearPartitionService — Phase 1, Session 4 (Q1).
 *
 * On year-end close, every monthly partition of every RANGE-partitioned
 * operational table that belongs to the closing fiscal year is DETACHed
 * from its parent and moved to the `archive` schema. After this runs,
 * the closed-FY rows are physically invisible to every normal SQL query
 * against the parent table — even the BelongsToFiscalYear global scope's
 * `withoutGlobalScope('current_fy')` escape hatch cannot see them
 * without an explicit `restore_partition()` call.
 *
 * This is the strongest read-block guarantee in the system. The
 * BelongsToFiscalYear scope (S2) is a logical read-block (filter by
 * fiscal_year_id). FiscalYearPolicy (S2) is an authorization read-block.
 * THIS service is the PHYSICAL read-block — the rows are gone from the
 * parent table entirely.
 *
 * Reversibility
 * -------------
 * `restoreForViewing()` is the documented "upload the database / restore
 * to view" path. It moves the archived partitions back into the public
 * schema and ATTACHes them as partitions of their parent. After this
 * call, the closed-FY rows are queryable again (subject to the S2
 * logical and authorization read-blocks, which still apply).
 *
 * @internal The restore path is for MANUAL OPS ONLY. It must never be
 *           exposed via a web UI route, controller, or Livewire/Eloquent
 *           model method that a request can hit. The only entry points
 *           are this class + the `fy:detach-archived` artisan command.
 *           The client's hard requirement is that closed-FY data is
 *           invisible to EVERY user including super admin — exposing a
 *           restore button in the UI would violate this.
 *
 * Idempotency
 * -----------
 * `detachAndArchive()` is idempotent: if a partition is already in the
 * `archive` schema (because a prior run archived it), the call is a
 * no-op for that partition and is recorded in the `skipped` list. This
 * allows safe re-runs after a partial failure.
 *
 * `restoreForViewing()` is idempotent in the opposite direction: if a
 * partition is currently attached in the public schema, it is a no-op.
 *
 * @see \App\Services\Accounting\AccountingPeriodService::yearEndClose()
 * @see \App\Console\Commands\DetachFiscalYearPartitions
 * @see database/migrations/2026_08_25_000002_create_archival_procedures.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 4
 */
class FiscalYearPartitionService
{
    /**
     * For each RANGE-partitioned operational table listed in
     * config('fiscal.partitioned_tables'), for each month in the fiscal
     * year's date range, DETACH the corresponding monthly partition
     * from its parent and move it to the `archive` schema.
     *
     * Order of operations in {@see \App\Services\Accounting\AccountingPeriodService::yearEndClose()}:
     *   1. Pre-flight gates (S3 backup gate + existing 5 gates)
     *   2. Post the year-end closing journal entry
     *   3. {@see refreshOpeningBalances()} — write closing balance to
     *      ledgers.opening_balance / customers.opening_balance /
     *      suppliers.opening_balance (still using parent-table rows,
     *      which must happen BEFORE detach)
     *   4. THIS METHOD — detach + archive partitions
     *   5. Flip FY status to closed → locked
     *   6. Auto-activate the next FY
     *
     * If detach fails partway, the partial state is logged to
     * `period_close_log` and the exception propagates — the caller MUST
     * NOT flip the FY status to closed on failure. The next invocation
     * is safe to re-run: already-archived partitions are skipped.
     *
     * @param  int $fiscalYearId  The fiscal year being closed.
     * @return array{detached: array<int, string>, skipped: array<int, string>, missing: array<int, string>}
     *                           `detached` = partitions we moved this run,
     *                           `skipped`   = partitions already in archive (no-op),
     *                           `missing`   = partitions that don't exist in either
     *                                          schema (pg_partman never created them).
     *
     * @throws \RuntimeException  If the fiscal year does not exist.
     * @throws \Throwable         If a DETACH/SET SCHEMA operation fails
     *                            (after logging the partial state).
     */
    public function detachAndArchive(int $fiscalYearId): array
    {
        $fy = FiscalYear::findOrFail($fiscalYearId);

        $tables    = (array) config('fiscal.partitioned_tables', []);
        $months    = $this->monthRangesForFy($fy);
        $detached  = [];
        $skipped   = [];
        $missing   = [];

        Log::info('FiscalYearPartitionService: starting detach+archive', [
            'fiscal_year_id' => $fy->id,
            'fy_code'        => $fy->fiscal_year_code,
            'start_date'     => $fy->start_date->format('Y-m-d'),
            'end_date'       => $fy->end_date->format('Y-m-d'),
            'table_count'    => count($tables),
            'month_count'    => count($months),
        ]);

        foreach ($tables as $parentTable) {
            foreach ($months as [$partitionName, $rangeStart, $rangeEnd]) {
                $where = $this->locatePartition($parentTable, $partitionName);

                if ($where === 'archive') {
                    // Already archived by a prior run — idempotent skip.
                    $skipped[] = "{$parentTable}.{$partitionName}";
                    continue;
                }

                if ($where === null) {
                    // Partition doesn't exist in either schema. pg_partman
                    // may not have created it (e.g. FY predates the
                    // partitioning setup, or this table was added after
                    // the FY closed). Log + continue — we don't want to
                    // block close on a missing partition that has no
                    // rows to detach anyway.
                    $missing[] = "{$parentTable}.{$partitionName}";
                    $this->logPartitionAction($fy, $parentTable, $partitionName, 'missing', 'Partition not found in public or archive schema');
                    continue;
                }

                // Partition is in public — archive it.
                try {
                    DB::statement(
                        'SELECT archive_partition(?, ?)',
                        [$parentTable, $partitionName]
                    );

                    $detached[] = "{$parentTable}.{$partitionName}";
                    $this->logPartitionAction($fy, $parentTable, $partitionName, 'archived', "Detached from {$parentTable} and moved to archive schema");
                } catch (\Throwable $e) {
                    // Partial-failure handling: log everything we know to
                    // period_close_log so the next run can resume, then
                    // re-throw. The caller (yearEndClose) MUST NOT flip
                    // the FY status on failure.
                    $this->logPartitionAction($fy, $parentTable, $partitionName, 'failed', $e->getMessage());
                    Log::error('FiscalYearPartitionService: detach FAILED', [
                        'fiscal_year_id' => $fy->id,
                        'parent'         => $parentTable,
                        'partition'      => $partitionName,
                        'detached_so_far' => $detached,
                        'skipped_so_far'  => $skipped,
                        'error'          => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        Log::info('FiscalYearPartitionService: detach+archive complete', [
            'fiscal_year_id' => $fy->id,
            'detached_count' => count($detached),
            'skipped_count'  => count($skipped),
            'missing_count'  => count($missing),
        ]);

        return [
            'detached' => $detached,
            'skipped'  => $skipped,
            'missing'  => $missing,
        ];
    }

    /**
     * Move all archived partitions for a fiscal year back into the
     * public schema and re-attach them as partitions of their parent
     * table. After this call, the closed-FY rows are queryable again
     * from the parent table (subject to the S2 logical + authorization
     * read-blocks, which still apply).
     *
     * This is the documented "upload the database / restore to view"
     * path. The client may restore closed-FY data ONLY by:
     *   1. Running `php artisan fy:detach-archived --fiscal-year=<id>`
     *      on the production host (NOT through the web UI).
     *   2. OR restoring a `pg_dump -Fc` backup file to a separate
     *      PostgreSQL instance and querying it directly via psql.
     *
     * @internal  NEVER call this from a web request, controller, or
     *            Livewire component. The ONLY legitimate caller is
     *            the artisan command `fy:detach-archived`. Exposing
     *            this method via the UI would violate the client's
     *            hard requirement that closed-FY data is invisible
     *            to every user including super admin.
     *
     * @param  int $fiscalYearId
     * @return array{restored: array<int, string>, skipped: array<int, string>, missing: array<int, string>}
     *
     * @throws \Throwable
     */
    public function restoreForViewing(int $fiscalYearId): array
    {
        $fy = FiscalYear::findOrFail($fiscalYearId);

        $tables   = (array) config('fiscal.partitioned_tables', []);
        $months   = $this->monthRangesForFy($fy);
        $restored = [];
        $skipped  = [];
        $missing  = [];

        foreach ($tables as $parentTable) {
            foreach ($months as [$partitionName, $rangeStart, $rangeEnd]) {
                $where = $this->locatePartition($parentTable, $partitionName);

                if ($where === 'public') {
                    // Already attached — no-op.
                    $skipped[] = "{$parentTable}.{$partitionName}";
                    continue;
                }

                if ($where === null) {
                    $missing[] = "{$parentTable}.{$partitionName}";
                    continue;
                }

                // Partition is in archive — restore it.
                try {
                    DB::statement(
                        'SELECT restore_partition(?, ?, ?, ?)',
                        [$parentTable, $partitionName, $rangeStart, $rangeEnd]
                    );

                    $restored[] = "{$parentTable}.{$partitionName}";
                    $this->logPartitionAction($fy, $parentTable, $partitionName, 'restored', "Restored from archive schema and re-attached to {$parentTable}");
                } catch (\Throwable $e) {
                    $this->logPartitionAction($fy, $parentTable, $partitionName, 'restore_failed', $e->getMessage());
                    Log::error('FiscalYearPartitionService: restore FAILED', [
                        'fiscal_year_id' => $fy->id,
                        'parent'         => $parentTable,
                        'partition'      => $partitionName,
                        'range'          => [$rangeStart, $rangeEnd],
                        'error'          => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        return [
            'restored' => $restored,
            'skipped'  => $skipped,
            'missing'  => $missing,
        ];
    }

    /**
     * Check whether ALL expected partitions for a fiscal year are
     * currently in the `archive` schema (i.e. the FY has been fully
     * detached). Returns false if ANY expected partition is either
     * still in public or missing entirely.
     *
     * @param  int $fiscalYearId
     * @return bool
     */
    public function isFiscalYearArchived(int $fiscalYearId): bool
    {
        $fy     = FiscalYear::find($fiscalYearId);
        if (!$fy) {
            return false;
        }

        $tables = (array) config('fiscal.partitioned_tables', []);
        $months = $this->monthRangesForFy($fy);

        foreach ($tables as $parentTable) {
            foreach ($months as [$partitionName]) {
                $where = $this->locatePartition($parentTable, $partitionName);
                if ($where !== 'archive') {
                    return false;
                }
            }
        }

        return true;
    }

    // ── Internals ──────────────────────────────────────────────────

    /**
     * Build the list of (partition_name, range_start, range_end) tuples
     * for every month in the fiscal year's date range.
     *
     * Partition naming convention (verified in migration
     * `2025_01_21_000004_set_up_table_partitioning.php`):
     *   `<parent_table>_<YYYY>_<MM>`
     * e.g. `sales_invoices_2025_07` covers `2025-07-01` to `2025-08-01`.
     *
     * @param  FiscalYear $fy
     * @return array<int, array{0: string, 1: string, 2: string}>
     *         Each tuple is [partition_name, range_start_Ymd, range_end_Ymd].
     *         range_end is exclusive (start of next month).
     */
    private function monthRangesForFy(FiscalYear $fy): array
    {
        $start = Carbon::parse($fy->start_date)->startOfMonth();
        $end   = Carbon::parse($fy->end_date)->endOfMonth();

        $out   = [];
        $cur   = $start->copy();

        while ($cur->lte($end)) {
            $monthStart = $cur->copy()->startOfMonth();
            $monthEnd   = $cur->copy()->addMonth()->startOfMonth(); // exclusive

            $partitionName = sprintf(
                '%s_%s',
                // We use the placeholder `<parent>` here so the same
                // tuple list can be reused across all parent tables.
                // The caller substitutes the actual parent table name
                // when constructing the partition name.
                '<parent>',
                $cur->format('Y_m')
            );

            $out[] = [
                $partitionName,
                $monthStart->format('Y-m-d'),
                $monthEnd->format('Y-m-d'),
            ];

            $cur->addMonth();
        }

        return $out;
    }

    /**
     * Locate a partition by name across the public and archive schemas.
     *
     * @param  string $parentTable     Parent table name (e.g. 'sales_invoices').
     * @param  string $partitionTpl    Partition template from monthRangesForFy()
     *                                 (contains the literal '<parent>' placeholder).
     * @return string|null             'public' if currently attached to parent,
     *                                 'archive' if detached and moved to archive,
     *                                 null if not found in either.
     */
    private function locatePartition(string $parentTable, string $partitionTpl): ?string
    {
        // Replace the '<parent>' placeholder with the actual parent table name.
        $partitionName = str_replace('<parent>', $parentTable, $partitionTpl);

        $row = DB::selectOne(<<<SQL
SELECT n.nspname AS schema_name
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relname = ?
  AND n.nspname IN ('public', 'archive')
LIMIT 1
SQL, [$partitionName]);

        return $row?->schema_name;
    }

    /**
     * Append a row to `period_close_log` recording the partition
     * detach/restore outcome. This is the audit trail that the PM
     * acceptance test ("period_close_log table has entries for every
     * detached partition") checks.
     *
     * @param  FiscalYear $fy
     * @param  string     $parentTable
     * @param  string     $partitionTpl
     * @param  string     $action       One of: archived, restored, missing, failed, restore_failed.
     * @param  string     $detail
     * @return void
     */
    private function logPartitionAction(
        FiscalYear $fy,
        string $parentTable,
        string $partitionTpl,
        string $action,
        string $detail
    ): void {
        $partitionName = str_replace('<parent>', $parentTable, $partitionTpl);

        try {
            PeriodCloseLog::create([
                'fiscal_period_id'  => null,
                'fiscal_year_id'    => $fy->id,
                'branch_id'         => $fy->branch_id,
                'action'            => "partition_{$action}",
                'period_start_date' => $fy->start_date,
                'period_end_date'   => $fy->end_date,
                'performed_by'      => null,
                'reason'            => "Partition {$partitionName}: {$detail}",
                'previous_state'    => ['parent' => $parentTable, 'partition' => $partitionName, 'outcome' => $action],
                'ip_address'        => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            // Don't let audit-log failures mask the real operation outcome.
            Log::warning('FiscalYearPartitionService: failed to write period_close_log', [
                'fiscal_year_id' => $fy->id,
                'partition'      => $partitionName,
                'action'         => $action,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
