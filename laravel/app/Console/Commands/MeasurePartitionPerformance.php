<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 10.1 — Phase 8.8: Measure partition query performance vs targets.
 *
 * Runs the 10 representative performance-target queries from plan §12.1
 * with `EXPLAIN (ANALYZE, BUFFERS)`, persists the results to a
 * `partition_performance_measurements` table, and alerts if any query
 * exceeds its target. Returns FAILURE if any query exceeds its target
 * by more than 2× — that's a "regression worth paging for" signal.
 *
 * The 10 queries implemented here are representative; the exact query
 * text should be tuned to match production hot-paths (plan §12.1 doesn't
 * include the literal SQL, so the implementation below captures the
 * typical workload: Trial Balance, GL detail, journal lookups, customer /
 * supplier ledgers, sales invoices, stock transactions, daily warehouse
 * summary, and reversal lookups).
 *
 * For each query we capture:
 *   - execution_ms   (top-level "Execution Time" from the EXPLAIN JSON)
 *   - buffer_hits    (sum of "Shared Hit Blocks" across all plan nodes)
 *   - buffer_reads   (sum of "Shared Read Blocks" across all plan nodes)
 *   - partition_pruning (sum of "Subplans Removed" from Append/MergeAppend
 *                       nodes — measures how many partitions were pruned)
 *
 * Usage:
 *   php artisan partition:measure-perf                     # last month, save
 *   php artisan partition:measure-perf --month=2026-07     # specific month
 *   php artisan partition:measure-perf --no-save           # don't persist
 *
 * Scheduled weekly on Mondays at 05:30 in routes/console.php — offset 30
 * minutes after the partition:verify-join job so the two heavy EXPLAINs
 * don't overlap.
 *
 * TODO: extract the table creation into a proper migration once the
 * schema is stable. For now we create it inline IF NOT EXISTS so this
 * command is self-bootstrapping on first run (matches the defensive
 * pattern of the SystemHealthController).
 */
class MeasurePartitionPerformance extends Command
{
    protected $signature = 'partition:measure-perf
                            {--month= : Measurement month in YYYY-MM format (default: last complete month)}
                            {--no-save : Do not persist results to the partition_performance_measurements table}';

    protected $description = 'Measure 10 partition query runtimes vs targets and persist results (Phase 8.8)';

    /**
     * Performance targets — max acceptable execution time (ms) per query.
     *
     * These are conservative starting targets for a healthy staging DB;
     * production tuning should refine them. A query is "alerted" if its
     * measured runtime exceeds the target. A query is a "regression"
     * (return FAILURE) if it exceeds the target by more than 2×.
     *
     * @var array<string, int>
     */
    private const TARGETS = [
        'Q1_trial_balance'           => 500,
        'Q2_gl_detail'               => 400,
        'Q3_journal_by_entry_no'     => 50,
        'Q4_journal_by_reference'    => 100,
        'Q5_customer_ledger'         => 300,
        'Q6_supplier_ledger'         => 300,
        'Q7_sales_invoices_branch'   => 500,
        'Q8_stock_transactions'      => 400,
        'Q9_daily_warehouse_summary' => 300,
        'Q10_reversed_entries'       => 200,
    ];

    /**
     * A query that exceeds its target by this multiplier is a hard FAILURE.
     */
    private const REGRESSION_MULTIPLIER = 2;

    public function handle(): int
    {
        $month = $this->resolveMonth((string) $this->option('month'));
        if ($month === null) {
            $this->error('Invalid --month value. Use YYYY-MM format, e.g. --month=2026-07.');
            return self::FAILURE;
        }

        [$start, $end] = $this->monthBounds($month);
        $save = !$this->option('no-save');

        $this->info(sprintf('Measuring partition query performance for %s.', $month));
        $this->line(sprintf('  date range: %s → %s', $start, $end));
        $this->line(sprintf('  persist: %s', $save ? 'yes' : 'no (--no-save)'));
        $this->newLine();

        // Ensure the measurements table exists.
        if ($save) {
            $this->ensureMeasurementsTable();
        }

        // Build the query set.
        $queries = $this->buildQueries($start, $end);

        $results    = [];
        $regressions = 0;
        $alerts     = 0;

        foreach ($queries as $name => $sql) {
            $this->line("→ {$name}");

            try {
                $metrics = $this->explain($sql);
            } catch (Throwable $e) {
                $this->error("  EXPLAIN failed: {$e->getMessage()}");
                Log::error("partition:measure-perf: EXPLAIN failed for {$name}", [
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);
                $results[$name] = [
                    'query_name'      => $name,
                    'execution_ms'    => null,
                    'buffer_hits'     => 0,
                    'buffer_reads'    => 0,
                    'plan_summary'    => 'EXPLAIN FAILED: ' . $e->getMessage(),
                    'target_ms'       => self::TARGETS[$name] ?? null,
                    'status'          => 'error',
                ];
                $regressions++;
                continue;
            }

            $target   = self::TARGETS[$name] ?? null;
            $execMs   = $metrics['execution_ms'];
            $status   = 'ok';

            if ($target !== null && $execMs !== null) {
                if ($execMs > $target * self::REGRESSION_MULTIPLIER) {
                    $status = 'regression';
                    $regressions++;
                    $this->warn(sprintf(
                        '  REGRESSION: %.2f ms (target %d ms, %.1fx over)',
                        $execMs, $target, $execMs / $target
                    ));
                    Log::warning('partition:measure-perf: regression', [
                        'query'      => $name,
                        'exec_ms'    => $execMs,
                        'target_ms'  => $target,
                        'multiplier' => round($execMs / $target, 2),
                    ]);
                } elseif ($execMs > $target) {
                    $status = 'alert';
                    $alerts++;
                    $this->warn(sprintf(
                        '  ALERT: %.2f ms exceeds target %d ms (%.1fx).',
                        $execMs, $target, $execMs / $target
                    ));
                    Log::warning('partition:measure-perf: target breach', [
                        'query'     => $name,
                        'exec_ms'   => $execMs,
                        'target_ms' => $target,
                    ]);
                } else {
                    $this->info(sprintf(
                        '  ok: %.2f ms (target %d ms, %.0f%% of budget).',
                        $execMs, $target, ($execMs / $target) * 100
                    ));
                }
            } else {
                $this->line(sprintf('  %.2f ms (no target defined).', $execMs ?? 0.0));
            }

            $this->line(sprintf(
                '    buffers: %d hits / %d reads | pruning: %d subplans removed',
                $metrics['buffer_hits'],
                $metrics['buffer_reads'],
                $metrics['subplans_removed']
            ));

            $results[$name] = [
                'query_name'      => $name,
                'execution_ms'    => $execMs,
                'buffer_hits'     => $metrics['buffer_hits'],
                'buffer_reads'    => $metrics['buffer_reads'],
                'subplans_removed' => $metrics['subplans_removed'],
                'plan_summary'    => $metrics['plan_summary'],
                'target_ms'       => $target,
                'status'          => $status,
            ];
        }

        // Persist rows.
        if ($save) {
            $this->persistResults($results, $month);
        }

        // Summary.
        $this->newLine();
        $this->info(sprintf(
            'Done. %d queries: %d ok, %d alerts, %d regressions.',
            count($results),
            count($results) - $alerts - $regressions,
            $alerts,
            $regressions
        ));

        return $regressions > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ====================================================================
    // QUERY BUILDER
    // ====================================================================

    /**
     * Build the 10 representative queries with date-range substitution.
     *
     * @return array<string, string> query_name => SQL
     */
    private function buildQueries(string $start, string $end): array
    {
        // Q1: Trial Balance (sum debit/credit by ledger over a date range).
        // Joins journal_lines with journal_entries for entry_date filter +
        // partition pruning on both sides.
        $q1 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT jl.ledger_id,
       l.ledger_name,
       SUM(jl.debit)  AS total_debit,
       SUM(jl.credit) AS total_credit
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.entry_date = jl.entry_date
JOIN ledgers l          ON l.id  = jl.ledger_id
WHERE je.entry_date BETWEEN '{$start}' AND '{$end}'
GROUP BY jl.ledger_id, l.ledger_name
ORDER BY jl.ledger_id
SQL;

        // Q2: GL detail (journal lines for a ledger over a date range).
        $q2 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT je.entry_date, je.entry_no, jl.debit, jl.credit, je.description
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.entry_date = jl.entry_date
WHERE jl.entry_date BETWEEN '{$start}' AND '{$end}'
  AND jl.ledger_id = 1
ORDER BY je.entry_date, je.entry_no
SQL;

        // Q3: Journal entry lookup by entry_no (point lookup).
        $q3 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT id, entry_no, entry_date, description, is_reversed
FROM journal_entries
WHERE entry_no = 'JE-PERF-LOOKUP-001'
LIMIT 1
SQL;

        // Q4: Journal entry lookup by reference_type + reference_id.
        $q4 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT id, entry_no, entry_date, reference_type, reference_id
FROM journal_entries
WHERE reference_type = 'sales_invoice'
  AND reference_id = 1
  AND entry_date BETWEEN '{$start}' AND '{$end}'
ORDER BY entry_date DESC
SQL;

        // Q5: Customer ledger for a customer over a date range.
        $q5 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT transaction_date, transaction_type, debit, credit, balance, description
FROM customer_ledger
WHERE customer_id = 1
  AND transaction_date BETWEEN '{$start}' AND '{$end}'
ORDER BY transaction_date DESC
SQL;

        // Q6: Supplier ledger for a supplier over a date range.
        $q6 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT transaction_date, transaction_type, debit, credit, balance, description
FROM supplier_ledger
WHERE supplier_id = 1
  AND transaction_date BETWEEN '{$start}' AND '{$end}'
ORDER BY transaction_date DESC
SQL;

        // Q7: Sales invoices for a branch over a date range.
        $q7 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT invoice_code, invoice_date, customer_id, total_amount, status
FROM sales_invoices
WHERE branch_id = 1
  AND invoice_date BETWEEN '{$start}' AND '{$end}'
ORDER BY invoice_date DESC
LIMIT 500
SQL;

        // Q8: Stock transactions for a warehouse + product over a date range.
        $q8 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT transaction_date, reference_type, reference_id, qty, rate, total_value, is_reversed
FROM stock_transactions
WHERE warehouse_id = 1
  AND product_id = 1
  AND transaction_date BETWEEN '{$start}' AND '{$end}'
ORDER BY transaction_date DESC
SQL;

        // Q9: Daily warehouse stock summary for a date range.
        $q9 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT summary_date, warehouse_id, product_id,
       opening_qty, in_qty, out_qty, closing_qty
FROM daily_warehouse_stock_summary
WHERE summary_date BETWEEN '{$start}' AND '{$end}'
ORDER BY summary_date DESC, warehouse_id, product_id
SQL;

        // Q10: Reversed entries lookup (partial index idx_je_reversed).
        $q10 = <<<SQL
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT id, entry_no, entry_date, reversal_of_entry_id, reversed_at, reverse_reason
FROM journal_entries
WHERE is_reversed = true
  AND entry_date BETWEEN '{$start}' AND '{$end}'
ORDER BY reversed_at DESC
SQL;

        return [
            'Q1_trial_balance'           => $q1,
            'Q2_gl_detail'               => $q2,
            'Q3_journal_by_entry_no'     => $q3,
            'Q4_journal_by_reference'    => $q4,
            'Q5_customer_ledger'         => $q5,
            'Q6_supplier_ledger'         => $q6,
            'Q7_sales_invoices_branch'   => $q7,
            'Q8_stock_transactions'      => $q8,
            'Q9_daily_warehouse_summary' => $q9,
            'Q10_reversed_entries'       => $q10,
        ];
    }

    // ====================================================================
    // EXPLAIN RUNNER + METRICS EXTRACTION
    // ====================================================================

    /**
     * Run EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) and extract metrics.
     *
     * @return array{
     *   execution_ms: float|null,
     *   buffer_hits: int,
     *   buffer_reads: int,
     *   subplans_removed: int,
     *   plan_summary: string
     * }
     */
    private function explain(string $sql): array
    {
        $rows = DB::select($sql);

        if (empty($rows)) {
            return [
                'execution_ms'     => null,
                'buffer_hits'      => 0,
                'buffer_reads'     => 0,
                'subplans_removed' => 0,
                'plan_summary'     => 'EXPLAIN returned no rows.',
            ];
        }

        $planCell = $rows[0]->{'QUERY PLAN'} ?? null;
        $planJson = is_string($planCell) ? $planCell : (string) $planCell;
        $plan     = json_decode($planJson, true);

        if (!is_array($plan) || !isset($plan[0])) {
            return [
                'execution_ms'     => null,
                'buffer_hits'      => 0,
                'buffer_reads'     => 0,
                'subplans_removed' => 0,
                'plan_summary'     => 'JSON decode failed: ' . json_last_error_msg(),
            ];
        }

        $top    = $plan[0];
        $root   = $top['Plan'] ?? [];
        $execMs = isset($top['Execution Time']) ? (float) $top['Execution Time'] : null;

        $bufferHits      = 0;
        $bufferReads     = 0;
        $subplansRemoved = 0;

        $this->walkPlan($root, function (array $node) use (&$bufferHits, &$bufferReads, &$subplansRemoved): void {
            $bufferHits      += (int) ($node['Shared Hit Blocks']  ?? 0);
            $bufferReads     += (int) ($node['Shared Read Blocks'] ?? 0);
            $subplansRemoved += (int) ($node['Subplans Removed']   ?? 0);
        });

        $planSummary = $this->summarizePlan($root);

        return [
            'execution_ms'     => $execMs,
            'buffer_hits'      => $bufferHits,
            'buffer_reads'     => $bufferReads,
            'subplans_removed' => $subplansRemoved,
            'plan_summary'     => $planSummary,
        ];
    }

    /**
     * Recursively walk the plan tree, invoking $callback on each node.
     */
    private function walkPlan(array $node, callable $callback): void
    {
        $callback($node);

        $children = $node['Plans'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                $this->walkPlan($child, $callback);
            }
        }
    }

    /**
     * Build a short text summary of the plan (top-level node type + counts).
     */
    private function summarizePlan(array $root): string
    {
        $nodeType = $root['Node Type'] ?? 'unknown';
        $rows     = $root['Plan Rows']  ?? 0;
        $width    = $root['Plan Width'] ?? 0;
        $actualRows = $root['Actual Rows']  ?? 0;
        $actualLoops = $root['Actual Loops'] ?? 0;

        $childNodeTypes = [];
        foreach (($root['Plans'] ?? []) as $child) {
            $childNodeTypes[] = $child['Node Type'] ?? '?';
        }

        return sprintf(
            'root=%s rows=%d width=%d actual_rows=%s loops=%s children=[%s]',
            $nodeType,
            $rows,
            $width,
            is_numeric($actualRows) ? number_format((float) $actualRows, 2) : 'n/a',
            is_numeric($actualLoops) ? number_format((float) $actualLoops, 2) : 'n/a',
            implode(', ', $childNodeTypes)
        );
    }

    // ====================================================================
    // PERSISTENCE
    // ====================================================================

    /**
     * Ensure the partition_performance_measurements table exists.
     *
     * TODO: extract to a proper migration once the schema is stable.
     * Inline IF NOT EXISTS keeps this command self-bootstrapping on first
     * run — important because the parent agent reviews + commits code
     * before running migrations.
     */
    private function ensureMeasurementsTable(): void
    {
        try {
            if (Schema::hasTable('partition_performance_measurements')) {
                return;
            }

            Schema::create('partition_performance_measurements', function ($table) {
                $table->bigIncrements('id');
                $table->string('query_name', 80);
                $table->timestampTz('executed_at')->useCurrent();
                $table->float('execution_ms')->nullable();
                $table->bigInteger('buffer_hits')->default(0);
                $table->bigInteger('buffer_reads')->default(0);
                $table->text('plan_summary')->nullable();
                $table->index(['query_name', 'executed_at']);
            });

            $this->line('  created table partition_performance_measurements.');
        } catch (Throwable $e) {
            $this->warn("  could not create measurements table: {$e->getMessage()}");
            Log::warning('partition:measure-perf: could not create measurements table', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist the result rows to the measurements table.
     */
    private function persistResults(array $results, string $month): void
    {
        $now = now();

        try {
            foreach ($results as $r) {
                DB::table('partition_performance_measurements')->insert([
                    'query_name'   => $r['query_name'],
                    'executed_at'  => $now,
                    'execution_ms' => $r['execution_ms'],
                    'buffer_hits'  => $r['buffer_hits'],
                    'buffer_reads' => $r['buffer_reads'],
                    'plan_summary' => $r['plan_summary']
                                    . ' | target_ms=' . ($r['target_ms'] ?? 'null')
                                    . ' | status=' . $r['status']
                                    . ' | month=' . $month
                                    . ' | subplans_removed=' . ($r['subplans_removed'] ?? 0),
                ]);
            }

            $this->line(sprintf('  persisted %d row(s) to partition_performance_measurements.', count($results)));
        } catch (Throwable $e) {
            $this->warn("  could not persist results: {$e->getMessage()}");
            Log::warning('partition:measure-perf: could not persist results', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ====================================================================
    // DATE HELPERS
    // ====================================================================

    /**
     * Resolve the --month option to a YYYY-MM string. Returns null on
     * invalid input. Defaults to last complete month.
     */
    private function resolveMonth(string $option): ?string
    {
        if ($option === '' || $option === '0') {
            return now()->startOfMonth()->subDay()->format('Y-m');
        }

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $option)) {
            return null;
        }

        return $option;
    }

    /**
     * Get the [start, end] date strings for a YYYY-MM month.
     *
     * @return array{0: string, 1: string}
     */
    private function monthBounds(string $month): array
    {
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}
