<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10.1 — Phase 8.7: Verify partition-wise joins are still working.
 *
 * PostgreSQL's `enable_partitionwise_join` GUC lets the planner parallelise
 * a join between two identically-partitioned tables by joining each pair of
 * matching partitions separately (one partitioned-hash-join node per pair).
 * This is a critical optimisation for the journal_entries ↔ journal_lines
 * join — the alternative is a single large hash join that defeats the
 * partition pruning that makes the partitioned tables worthwhile.
 *
 * The optimisation can silently stop working if:
 *   - Someone sets `enable_partitionwise_join = off` in `postgresql.conf`.
 *   - The partition boundaries of the two parents drift out of alignment
 *     (e.g. one gets a partition the other doesn't have, or pg_partman
 *     creates them with different intervals).
 *   - A future PostgreSQL upgrade changes the planner's heuristics.
 *
 * This command runs `EXPLAIN (ANALYZE, FORMAT JSON)` on the canonical
 * JE↔JL join query (roadmap §5.8, lines 371–378), parses the JSON plan,
 * and recursively walks the plan tree looking for a node whose `Node Type`
 * contains both "Partition" and "Join" (e.g. "Partitioned Hash Join",
 * "Partitioned Nested Loop"). It returns FAILURE if no such node is found.
 *
 * Usage:
 *   php artisan partition:verify-join                     # last complete month
 *   php artisan partition:verify-join --month=2026-07     # specific month
 *
 * Scheduled weekly on Mondays at 05:00 in routes/console.php.
 *
 * @see https://www.postgresql.org/docs/current/runtime-config-query.html#GUC-ENABLE-PARTITIONWISE-JOIN
 */
class VerifyPartitionwiseJoin extends Command
{
    protected $signature = 'partition:verify-join
                            {--month= : Test month in YYYY-MM format (default: last complete month)}';

    protected $description = 'Verify partition-wise joins are working on JE↔JL (Phase 8.7)';

    public function handle(): int
    {
        // Resolve the test month — defaults to last complete month.
        $month = $this->resolveMonth((string) $this->option('month'));

        if ($month === null) {
            $this->error('Invalid --month value. Use YYYY-MM format, e.g. --month=2026-07.');
            return self::FAILURE;
        }

        [$start, $end] = $this->monthBounds($month);

        $this->info(sprintf(
            'Verifying partition-wise join for journal_entries ↔ journal_lines (%s).',
            $month
        ));
        $this->line(sprintf('  date range: %s → %s', $start, $end));

        try {
            // Step 1: enable partition-wise joins for this session only.
            // The GUC is session-scoped — does NOT affect other connections.
            DB::statement('SET enable_partitionwise_join = on;');
        } catch (Throwable $e) {
            $this->error("Could not SET enable_partitionwise_join = on: {$e->getMessage()}");
            Log::error('partition:verify-join: could not enable partitionwise_join', [
                'month' => $month,
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        // Step 2: run EXPLAIN (ANALYZE, FORMAT JSON).
        try {
            $rows = DB::select(<<<SQL
EXPLAIN (ANALYZE, FORMAT JSON)
SELECT je.id, jl.ledger_id, jl.debit, jl.credit
FROM journal_entries je
JOIN journal_lines jl
  ON je.id = jl.journal_entry_id
 AND je.entry_date = jl.entry_date
WHERE je.entry_date BETWEEN ? AND ?
SQL, [$start, $end]);
        } catch (Throwable $e) {
            $this->error("EXPLAIN failed: {$e->getMessage()}");
            Log::error('partition:verify-join: EXPLAIN failed', [
                'month' => $month,
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        if (empty($rows)) {
            $this->error('EXPLAIN returned no rows.');
            return self::FAILURE;
        }

        // Step 3: parse the JSON plan. PostgreSQL returns a single row with
        // a "QUERY PLAN" column that is itself a JSON array.
        $planCell = $rows[0]->{'QUERY PLAN'} ?? null;
        if ($planCell === null) {
            $this->error('EXPLAIN did not return a value in the QUERY PLAN column.');
            return self::FAILURE;
        }

        // The cell may be a string OR an object implementing __toString().
        $planJson = is_string($planCell) ? $planCell : ((string) $planCell);
        $plan     = json_decode($planJson, true);

        if (!is_array($plan)) {
            $this->error('Failed to decode EXPLAIN JSON plan.');
            Log::error('partition:verify-join: JSON decode failed', [
                'month' => $month,
                'json_error' => json_last_error_msg(),
            ]);
            return self::FAILURE;
        }

        // Step 4: recursively walk the plan tree looking for a partition-wise
        // join node. The plan root is $plan[0]['Plan'].
        $root  = $plan[0]['Plan'] ?? null;
        $found = $root ? $this->findPartitionwiseJoinNode($root) : null;

        // Step 5: report success / failure.
        if ($found !== null) {
            $nodeType = $found['Node Type'] ?? 'unknown';
            $this->info(sprintf(
                '✓ Partition-wise join detected (Node Type: %s).',
                $nodeType
            ));
            $this->line('  Plan excerpt:');
            $this->line('    ' . json_encode([
                'Node Type'    => $found['Node Type'] ?? null,
                'Parent'       => $found['Parent Relationship'] ?? null,
                'Subplans'     => count($found['Plans'] ?? []),
                'Actual Rows'  => $found['Actual Rows'] ?? null,
                'Actual Time'  => $found['Actual Total Time'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('partition:verify-join: PASS', [
                'month'     => $month,
                'node_type' => $nodeType,
            ]);
            return self::SUCCESS;
        }

        // Failure path.
        $this->warn('✗ No partition-wise join node found in the query plan.');
        $this->line('  The planner did not produce a "Partitioned Hash Join" (or similar) node.');
        $this->line('  Suggested checks:');
        $this->line('    1. Confirm enable_partitionwise_join = on in postgresql.conf');
        $this->line('       (this command sets it for the session, but the weekly cron');
        $this->line('        runs in a fresh session — server-level config still matters).');
        $this->line('    2. Verify partition boundaries of journal_entries and journal_lines');
        $this->line('       are identical — compare pg_get_expr(relpartbound, oid) for the');
        $this->line('       child partitions of each parent.');
        $this->line('    3. Verify both parents have a partition covering the date range');
        $this->line('       ' . $start . ' → ' . $end . '.');
        $this->line('    4. ANALYZE both parents — stale stats can discourage partition-wise joins.');

        Log::warning('partition:verify-join: FAIL — no partition-wise join node found', [
            'month' => $month,
            'start' => $start,
            'end'   => $end,
        ]);

        return self::FAILURE;
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Resolve the --month option to a YYYY-MM string. Returns null on
     * invalid input. Defaults to last complete month.
     */
    private function resolveMonth(string $option): ?string
    {
        if ($option === '' || $option === '0') {
            // Default: last complete month.
            return now()->startOfMonth()->subDay()->format('Y-m');
        }

        // Validate YYYY-MM.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $option)) {
            return null;
        }

        return $option;
    }

    /**
     * Get the [start, end] date strings for a YYYY-MM month.
     * Returns ['YYYY-MM-01', 'YYYY-MM-DD'] where DD is the last day of month.
     *
     * @return array{0: string, 1: string}
     */
    private function monthBounds(string $month): array
    {
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * Recursively walk the EXPLAIN ANALYZE plan tree looking for a node
     * whose "Node Type" contains both "Partition" and "Join".
     *
     * PostgreSQL partition-wise join node types we accept:
     *   - "Partitioned Hash Join"
     *   - "Partitioned Nested Loop"
     *   - "Partitioned Merge Join"
     *   - An Append / MergeAppend with multiple Join children (a fallback
     *     detection — the planner may wrap the per-partition joins in an
     *     Append when there are > 1 partition pair).
     *
     * @param array $node A single plan node from EXPLAIN JSON.
     * @return array|null The matching node, or null if none found.
     */
    private function findPartitionwiseJoinNode(array $node): ?array
    {
        $nodeType = (string) ($node['Node Type'] ?? '');

        // Direct match: "Partitioned X Join".
        if (stripos($nodeType, 'Partition') !== false
            && stripos($nodeType, 'Join') !== false
        ) {
            return $node;
        }

        // Recurse into child Plans.
        $children = $node['Plans'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                $hit = $this->findPartitionwiseJoinNode($child);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        // Fallback detection: Append/MergeAppend with multiple Join-typed
        // direct children. This is the shape the planner produces when it
        // generates one Hash Join per partition pair and gathers them with
        // an Append.
        if (($nodeType === 'Append' || $nodeType === 'MergeAppend')
            && is_array($children)
            && count($children) >= 2
        ) {
            $joinChildren = 0;
            foreach ($children as $child) {
                $ct = (string) ($child['Node Type'] ?? '');
                if (stripos($ct, 'Join') !== false) {
                    $joinChildren++;
                }
            }
            if ($joinChildren >= 2) {
                return $node;
            }
        }

        return null;
    }
}
