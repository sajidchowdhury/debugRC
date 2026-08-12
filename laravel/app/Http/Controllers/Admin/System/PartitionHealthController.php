<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Partition Health Monitoring Dashboard — Phase 10.1, Phase 8.4.
 *
 * Aggregates the operational health of the PostgreSQL partitioned tables
 * into a single admin dashboard. Designed to be the "ops console" for the
 * 30 partitioned tables created by Phases 1–7 of the 10.1 partitioning
 * plan, surfacing the same metrics that the daily `partition-health-check`
 * pg_cron job (Phase 8.3) writes to `partition_health_alerts`.
 *
 * Metrics surfaced (per roadmap §9.1 + §8.4):
 *
 *   - Recent unresolved alerts (CRITICAL/WARNING/INFO) from
 *     `partition_health_alerts`, with severity counts.
 *   - Per-parent partition counts from `pg_inherits` + `pg_class`
 *     (alert if > 80 partitions).
 *   - pg_partman config: retention, premake, last_maintenance for every
 *     registered parent (alert if retention IS NULL or last_maintenance
 *     is stale > 24h).
 *   - Largest partitions (top 20) from `v_partition_sizes`.
 *   - Stale VACUUM stats from `v_partition_vacuum_stats` (last vacuum
 *     > 7 days OR n_dead_tup > 100k).
 *   - Default partitions with > 0 rows from `v_default_partition_check`
 *     (always a red flag — data is landing in the catch-all).
 *   - Tables missing future partitions from `v_missing_future_partitions`
 *     (< 3 months ahead = CRITICAL).
 *   - BRIN indexes with idx_scan = 0 from `pg_stat_user_indexes`
 *     (planner isn't using them — possible stats issue).
 *
 * The controller is *defensive*: every query that depends on a Phase 8
 * SQL object (the `v_*` views, the `partition_health_alerts` table, the
 * `partman.part_config` table) is wrapped in try/catch and falls back to
 * an empty collection. This means the page renders cleanly on a fresh
 * install where the Phase 8 migrations (Task 8A) have not run yet — the
 * dependent sections simply show "view not available" rather than 500.
 *
 * SQL objects assumed (created by parallel Task 8A):
 *   - Table:  partition_health_alerts (id, check_name, table_name, details,
 *             severity, created_at, resolved_at, resolved_by)
 *   - View:   v_partition_sizes (parent, child, size_bytes, size_pretty,
 *             seq_scans, seq_tuples_read)
 *   - View:   v_partition_vacuum_stats (parent, child, last_vacuum,
 *             last_autovacuum, last_analyze, last_autoanalyze, n_dead_tup,
 *             n_live_tup, stale_days)
 *   - View:   v_default_partition_check (parent, default_partition,
 *             row_count, size_bytes)
 *   - View:   v_missing_future_partitions (parent, last_partition_date,
 *             months_ahead, missing_count)
 *   - Schema: partman.part_config (parent_table, retention, premake,
 *             last_maintenance, automatic_maintenance)
 */
class PartitionHealthController extends Controller
{
    /**
     * Render the partition-health dashboard.
     */
    public function index()
    {
        // ----------------------------------------------------------------
        // 1. Alerts (from partition_health_alerts — created by Phase 8.3).
        // ----------------------------------------------------------------
        $alerts         = $this->collectAlerts();
        $alertCounts    = $this->collectAlertCounts();

        // ----------------------------------------------------------------
        // 2. pg_partman config (retention, premake, last_maintenance).
        // ----------------------------------------------------------------
        $partmanConfigs = $this->collectPartmanConfig();

        // ----------------------------------------------------------------
        // 3. Per-parent partition counts via pg_inherits + pg_class.
        // ----------------------------------------------------------------
        $partitionCounts = $this->collectPartitionCounts();

        // ----------------------------------------------------------------
        // 4–7. Statistics views (created by Phase 8.5 migration).
        // ----------------------------------------------------------------
        $largestPartitions      = $this->collectLargestPartitions();
        $staleVacuumStats       = $this->collectStaleVacuumStats();
        $defaultPartitionIssues = $this->collectDefaultPartitionIssues();
        $missingFuturePartitions = $this->collectMissingFuturePartitions();

        // ----------------------------------------------------------------
        // 8. BRIN index usage (idx_scan = 0 → planner not using them).
        // ----------------------------------------------------------------
        $unusedBrinIndexes = $this->collectUnusedBrinIndexes();

        // ----------------------------------------------------------------
        // Top-level status pills.
        // ----------------------------------------------------------------
        $totalAlerts        = $alertCounts['total']      ?? 0;
        $criticalCount      = $alertCounts['CRITICAL']   ?? 0;
        $warningCount       = $alertCounts['WARNING']    ?? 0;
        $partitionedTables  = $partitionCounts->count();
        $totalPartitions    = $partitionCounts->sum('partition_count');

        // Overall health: CRITICAL if any critical alert, WARNING if any
        // warning or stale vacuum / default-partition issue, else HEALTHY.
        $status = 'healthy';
        if ($criticalCount > 0 || $defaultPartitionIssues->isNotEmpty() || $missingFuturePartitions->isNotEmpty()) {
            $status = 'critical';
        } elseif ($warningCount > 0 || $staleVacuumStats->isNotEmpty()) {
            $status = 'degraded';
        }

        return view('admin.system.partition-health', [
            'title'                    => 'Partition health',
            'generatedAt'              => now(),

            // Alerts.
            'alerts'                   => $alerts,
            'alertCounts'              => $alertCounts,
            'totalAlerts'              => $totalAlerts,
            'criticalCount'            => $criticalCount,
            'warningCount'             => $warningCount,

            // pg_partman config.
            'partmanConfigs'           => $partmanConfigs,

            // Per-parent partition counts.
            'partitionCounts'          => $partitionCounts,
            'partitionedTables'        => $partitionedTables,
            'totalPartitions'          => $totalPartitions,

            // Statistics views.
            'largestPartitions'        => $largestPartitions,
            'staleVacuumStats'         => $staleVacuumStats,
            'defaultPartitionIssues'   => $defaultPartitionIssues,
            'missingFuturePartitions'  => $missingFuturePartitions,

            // BRIN.
            'unusedBrinIndexes'        => $unusedBrinIndexes,

            // Overall.
            'status'                   => $status,
        ]);
    }

    // ====================================================================
    // ALERTS  (partition_health_alerts)
    // ====================================================================

    /**
     * Recent unresolved alerts, ordered by severity (CRITICAL first) then
     * created_at desc. Limited to the last 100 to keep the page snappy.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectAlerts()
    {
        try {
            if (!Schema::hasTable('partition_health_alerts')) {
                return collect([]);
            }

            return DB::table('partition_health_alerts')
                ->whereNull('resolved_at')
                ->orderByRaw("CASE severity
                    WHEN 'CRITICAL' THEN 0
                    WHEN 'WARNING'  THEN 1
                    WHEN 'INFO'     THEN 2
                    ELSE 3
                END")
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();
        } catch (Throwable) {
            return collect([]);
        }
    }

    /**
     * Count of unresolved alerts grouped by severity.
     *
     * @return array{total: int, CRITICAL: int, WARNING: int, INFO: int}
     */
    private function collectAlertCounts(): array
    {
        $counts = ['total' => 0, 'CRITICAL' => 0, 'WARNING' => 0, 'INFO' => 0];

        try {
            if (!Schema::hasTable('partition_health_alerts')) {
                return $counts;
            }

            $rows = DB::table('partition_health_alerts')
                ->select('severity', DB::raw('COUNT(*) AS cnt'))
                ->whereNull('resolved_at')
                ->groupBy('severity')
                ->get();

            foreach ($rows as $row) {
                $sev = strtoupper((string) $row->severity);
                if (isset($counts[$sev])) {
                    $counts[$sev] = (int) $row->cnt;
                }
                $counts['total'] += (int) $row->cnt;
            }
        } catch (Throwable) {
            // Keep the zeroed-out defaults.
        }

        return $counts;
    }

    // ====================================================================
    // pg_partman CONFIG
    // ====================================================================

    /**
     * All registered partitioned parents with their retention, premake,
     * and last_maintenance timestamp.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectPartmanConfig()
    {
        try {
            // Check the partman schema exists. Use a direct catalog lookup
            // rather than Schema::hasTable (which only knows about public).
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = 'partman' AND table_name = 'part_config'"
            );
            if (!$exists) {
                return collect([]);
            }

            return DB::table('partman.part_config')
                ->select([
                    'parent_table',
                    'retention',
                    'premake',
                    'last_maintenance',
                    'automatic_maintenance',
                    'template_table',
                    'partition_interval',
                    'partition_type',
                ])
                ->orderBy('parent_table')
                ->get();
        } catch (Throwable) {
            return collect([]);
        }
    }

    // ====================================================================
    // PER-PARENT PARTITION COUNTS  (pg_inherits + pg_class)
    // ====================================================================

    /**
     * For every partitioned parent in the public schema, count direct
     * child partitions. Highlights parents with > 80 partitions (the
     * roadmap §9.1 alert threshold).
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectPartitionCounts()
    {
        try {
            $rows = DB::select(<<<SQL
SELECT
    parent.relname                          AS parent_table,
    COUNT(child.oid)                        AS partition_count,
    MAX(COALESCE(pg_get_expr(child.relpartbound, child.oid), '')) AS last_bound
FROM pg_inherits inh
JOIN pg_class      parent ON parent.oid = inh.inhparent
JOIN pg_class      child  ON child.oid  = inh.inhrelid
JOIN pg_namespace  pn     ON pn.oid     = parent.relnamespace
WHERE pn.nspname = 'public'
  AND parent.relkind = 'p'              -- partitioned parent
GROUP BY parent.relname
ORDER BY parent.relname
SQL);

            return collect($rows);
        } catch (Throwable) {
            return collect([]);
        }
    }

    // ====================================================================
    // STATISTICS VIEWS  (created by Phase 8.5 migration)
    // ====================================================================

    /**
     * Top 20 largest partitions by size, with seq_scan counts.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectLargestPartitions()
    {
        try {
            if (!$this->viewExists('v_partition_sizes')) {
                return collect([]);
            }

            return collect(DB::select(<<<SQL
SELECT parent, child, size_bytes, size_pretty, seq_scans, seq_tuples_read
FROM v_partition_sizes
ORDER BY size_bytes DESC NULLS LAST
LIMIT 20
SQL));
        } catch (Throwable) {
            return collect([]);
        }
    }

    /**
     * Partitions with stale VACUUM (> 7 days) or high dead tuples (> 100k).
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectStaleVacuumStats()
    {
        try {
            if (!$this->viewExists('v_partition_vacuum_stats')) {
                return collect([]);
            }

            return collect(DB::select(<<<SQL
SELECT parent, child, last_vacuum, last_autovacuum,
       last_analyze, last_autoanalyze,
       n_dead_tup, n_live_tup, stale_days
FROM v_partition_vacuum_stats
WHERE (stale_days IS NOT NULL AND stale_days > 7)
   OR (n_dead_tup IS NOT NULL AND n_dead_tup > 100000)
ORDER BY
    CASE WHEN n_dead_tup IS NULL THEN 0 ELSE n_dead_tup END DESC,
    CASE WHEN stale_days  IS NULL THEN 0 ELSE stale_days  END DESC
LIMIT 100
SQL));
        } catch (Throwable) {
            return collect([]);
        }
    }

    /**
     * Default partitions with > 0 rows — always a red flag (data is
     * landing in the catch-all because the partition key didn't match
     * any existing partition range).
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectDefaultPartitionIssues()
    {
        try {
            if (!$this->viewExists('v_default_partition_check')) {
                return collect([]);
            }

            return collect(DB::select(<<<SQL
SELECT parent, default_partition, row_count, size_bytes
FROM v_default_partition_check
WHERE row_count > 0
ORDER BY row_count DESC
SQL));
        } catch (Throwable) {
            return collect([]);
        }
    }

    /**
     * Tables with < 3 months of future partitions — pg_partman should be
     * premaking these. A row here means run_maintenance hasn't run or
     * premake is misconfigured.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectMissingFuturePartitions()
    {
        try {
            if (!$this->viewExists('v_missing_future_partitions')) {
                return collect([]);
            }

            return collect(DB::select(<<<SQL
SELECT parent, last_partition_date, months_ahead, missing_count
FROM v_missing_future_partitions
WHERE months_ahead IS NULL OR months_ahead < 3
ORDER BY months_ahead ASC NULLS FIRST
SQL));
        } catch (Throwable) {
            return collect([]);
        }
    }

    // ====================================================================
    // BRIN INDEX USAGE  (pg_stat_user_indexes)
    // ====================================================================

    /**
     * BRIN indexes that have never been scanned by the planner (idx_scan = 0).
     * Per roadmap §8.6: the planner is likely not using them — could be a
     * stats issue, or the queries don't actually match the BRIN column.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function collectUnusedBrinIndexes()
    {
        try {
            return collect(DB::select(<<<SQL
SELECT
    schemaname,
    relname       AS table_name,
    indexrelname  AS index_name,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
JOIN pg_class cls ON cls.relname = pg_stat_user_indexes.indexrelname
JOIN pg_index pi  ON pi.indexrelid = cls.oid
WHERE idx_scan = 0
  AND EXISTS (
      SELECT 1
      FROM pg_opclass op
      JOIN pg_am am ON am.oid = op.opcmethod
      WHERE op.oid = ANY (pi.indclass)
        AND am.amname = 'brin'
  )
ORDER BY table_name, index_name
LIMIT 100
SQL));
        } catch (Throwable) {
            return collect([]);
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Check if a view exists in the public schema (information_schema
     * lookup — doesn't require the view to be queryable).
     */
    private function viewExists(string $viewName): bool
    {
        try {
            $row = DB::selectOne(
                "SELECT 1 FROM information_schema.views
                  WHERE table_schema = 'public' AND table_name = ?",
                [$viewName]
            );
            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
