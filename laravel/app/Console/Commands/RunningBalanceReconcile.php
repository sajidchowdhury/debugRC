<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Running Balance Reconciliation — Task 18.
 *
 * Verifies that the denormalized `balance` column in each sub-ledger matches
 * the mathematically correct running balance computed by PostgreSQL window
 * functions. Uses materialized views that compute SUM() OVER (PARTITION BY
 * entity ORDER BY id) and compares against the stored `balance` column.
 *
 * Running balance formulas (same as SubLedgerService):
 *   customer_ledger: balance = prev + debit - credit
 *   supplier_ledger: balance = prev + credit - debit
 *   employee_ledger: balance = prev + credit - debit
 *   cash_ledger:     balance = prev + amount
 *
 * Usage:
 *   php artisan reconcile:running-balance           # Check all 4 ledgers
 *   php artisan reconcile:running-balance --fix      # Fix drift rows
 *   php artisan reconcile:running-balance --ledger=customer  # Single ledger
 *   php artisan reconcile:running-balance --as-of=2025-01-15 # Historical
 *
 * Exit 0 = all match, 1 = drift detected.
 */
class RunningBalanceReconcile extends Command
{
    protected $signature = 'reconcile:running-balance
        {--fix : Fix drift by updating stored balance to computed balance}
        {--ledger= : Check a single ledger (customer|supplier|employee|cash)}
        {--as-of= : Check entries up to this date (Y-m-d)}
        {--top=10 : Number of top-drift entities to show}';

    protected $description = 'Verify running balances in sub-ledgers using window functions';

    private float $tolerance;

    /** @var array<string, array{view: string, entity_col: string, entity_label: string}> */
    private array $ledgers;

    public function __construct()
    {
        parent::__construct();
        $this->tolerance = (float) config('app.gl_reconciliation_tolerance', 0.02);

        $this->ledgers = [
            'customer' => [
                'view' => 'mv_customer_ledger_balance_check',
                'entity_col' => 'customer_id',
                'entity_label' => 'Customer',
                'entity_table' => 'customers',
                'entity_name_cols' => 'customer_code, customer_name',
                'formula' => 'SUM(debit - credit) OVER (PARTITION BY customer_id ORDER BY id)',
            ],
            'supplier' => [
                'view' => 'mv_supplier_ledger_balance_check',
                'entity_col' => 'supplier_id',
                'entity_label' => 'Supplier',
                'entity_table' => 'suppliers',
                'entity_name_cols' => 'supplier_code, supplier_name',
                'formula' => 'SUM(credit - debit) OVER (PARTITION BY supplier_id ORDER BY id)',
            ],
            'employee' => [
                'view' => 'mv_employee_ledger_balance_check',
                'entity_col' => 'employee_id',
                'entity_label' => 'Employee',
                'entity_table' => 'employees',
                'entity_name_cols' => 'employee_code, name',
                'formula' => 'SUM(credit - debit) OVER (PARTITION BY employee_id ORDER BY id)',
            ],
            'cash' => [
                'view' => 'mv_cash_ledger_balance_check',
                'entity_col' => 'branch_id',
                'entity_label' => 'Branch (Cash)',
                'entity_table' => 'branches',
                'entity_name_cols' => 'id, branch_name',
                'formula' => 'SUM(amount) OVER (PARTITION BY branch_id ORDER BY id)',
            ],
        ];
    }

    public function handle(): int
    {
        $this->info('=== Running Balance Reconciliation (Task 18) ===');
        $this->info('Verifies stored balance = window-function computed balance in sub-ledgers.');
        $this->newLine();

        $fix = (bool) $this->option('fix');
        $ledger = $this->option('ledger');
        $asOf = $this->option('as-of');
        $topN = (int) $this->option('top');

        if ($fix) {
            $this->warn('⚠  FIX MODE: Will update stored balances where drift is detected.');
            $this->newLine();
        }

        // Validate --as-of date.
        if ($asOf && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $this->error("Invalid --as-of date format: {$asOf}. Expected Y-m-d.");
            return self::FAILURE;
        }

        // Determine which ledgers to check.
        $ledgersToCheck = $this->ledgers;
        if ($ledger) {
            $ledger = strtolower($ledger);
            if (!isset($this->ledgers[$ledger])) {
                $this->error("Unknown ledger: {$ledger}. Valid: customer, supplier, employee, cash.");
                return self::FAILURE;
            }
            $ledgersToCheck = [$ledger => $this->ledgers[$ledger]];
        }

        $totalDriftRows = 0;
        $allGreen = true;

        foreach ($ledgersToCheck as $key => $config) {
            $result = $this->checkLedger($key, $config, $fix, $asOf, $topN);
            if (!$result['green']) {
                $allGreen = false;
                $totalDriftRows += $result['drift_rows'];
            }

            // Store structured snapshot in reconciliation_snapshots.
            $this->storeSnapshot($key, $result, $asOf);
        }

        // Summary.
        $this->newLine();
        $this->info('=== Reconciliation Summary ===');
        if ($allGreen) {
            $this->info('✓ ALL running balances match — no drift detected.');
            $this->info('  Denormalized balance columns are consistent with window-function computation.');
            return self::SUCCESS;
        } else {
            $this->error("✗ Drift detected: {$totalDriftRows} rows with stored_balance ≠ computed_balance.");
            if (!$fix) {
                $this->warn('  Run with --fix to update stored balances, or investigate manually.');
            }
            return self::FAILURE;
        }
    }

    /**
     * Check a single ledger's running balance integrity.
     *
     * @param string $key Ledger key (customer/supplier/employee/cash)
     * @param array $config Ledger configuration
     * @param bool $fix Whether to fix drift
     * @param string|null $asOf As-of date filter
     * @param int $topN Number of top-drift entities to show
     * @return array{green: bool, total_rows: int, matched_rows: int, drift_rows: int, max_drift: float, details: array}
     */
    private function checkLedger(string $key, array $config, bool $fix, ?string $asOf, int $topN): array
    {
        $view = $config['view'];
        $label = $config['entity_label'];
        $entityCol = $config['entity_col'];

        $this->info("▶ Checking: {$label} Ledger ({$view})");

        // Step 1: Refresh the materialized view.
        $this->line("  Refreshing materialized view...");
        DB::statement("REFRESH MATERIALIZED VIEW {$view}");
        $this->line("  ✓ View refreshed.");

        // Step 2: Count total and drifted rows.
        $dateFilter = $asOf ? " AND transaction_date <= ?" : '';
        $params = $asOf ? [$asOf] : [];

        $stats = DB::selectOne("
            SELECT
                COUNT(*) AS total_rows,
                COUNT(*) FILTER (WHERE ABS(drift) <= ?) AS matched_rows,
                COUNT(*) FILTER (WHERE ABS(drift) > ?) AS drift_rows,
                COALESCE(MAX(ABS(drift)), 0) AS max_drift
            FROM {$view}
            WHERE 1=1{$dateFilter}
        ", array_merge([$this->tolerance, $this->tolerance], $params));

        $totalRows = (int) $stats->total_rows;
        $matchedRows = (int) $stats->matched_rows;
        $driftRows = (int) $stats->drift_rows;
        $maxDrift = (float) $stats->max_drift;
        $green = $driftRows === 0;

        $this->line("  Total rows:     " . number_format($totalRows));
        $this->line("  Matched:        " . number_format($matchedRows));
        $this->line("  Drift rows:     " . number_format($driftRows));
        $this->line("  Max drift:      " . number_format($maxDrift, 4));

        if ($green) {
            $this->info("  ✓ ALL RUNNING BALANCES MATCH");
        } else {
            $this->error("  ✗ DRIFT DETECTED — {$driftRows} rows out of sync");

            // Show top-N entities with worst drift.
            $this->showTopDriftEntities($view, $config, $asOf, $topN);

            // Fix mode: update stored balances.
            if ($fix) {
                $fixed = $this->fixDriftRows($key, $view, $config, $asOf);
                $this->info("  ✓ Fixed {$fixed} rows (updated stored_balance = computed_balance).");
            }
        }

        $this->newLine();

        return [
            'green' => $green,
            'total_rows' => $totalRows,
            'matched_rows' => $matchedRows,
            'drift_rows' => $driftRows,
            'max_drift' => $maxDrift,
            'details' => [
                'view' => $view,
                'formula' => $config['formula'],
            ],
        ];
    }

    /**
     * Show top-N entities with the worst running balance drift.
     */
    private function showTopDriftEntities(string $view, array $config, ?string $asOf, int $topN): void
    {
        $entityCol = $config['entity_col'];
        $entityTable = $config['entity_table'];
        $nameCols = $config['entity_name_cols'];
        $dateFilter = $asOf ? " AND mv.transaction_date <= ?" : '';
        $params = $asOf ? array_merge([$this->tolerance, $topN], [$asOf]) : [$this->tolerance, $topN];

        // Per-entity drift summary.
        $entityDrift = DB::select("
            SELECT
                mv.{$entityCol} AS entity_id,
                COUNT(*) AS drift_count,
                ROUND(MAX(ABS(mv.drift)), 2) AS max_entity_drift,
                ROUND(SUM(mv.drift), 2) AS cumulative_drift
            FROM {$view} mv
            WHERE ABS(mv.drift) > ?{$dateFilter}
            GROUP BY mv.{$entityCol}
            ORDER BY MAX(ABS(mv.drift)) DESC
            LIMIT ?
        ", $params);

        if (empty($entityDrift)) {
            return;
        }

        $this->warn("  Top " . count($entityDrift) . " entities by drift:");

        // Build a lookup of entity names.
        $entityIds = collect($entityDrift)->pluck('entity_id')->toArray();
        $entityNames = DB::table($entityTable)
            ->whereIn('id', $entityIds)
            ->selectRaw("id, {$nameCols}")
            ->get()
            ->keyBy('id');

        foreach ($entityDrift as $row) {
            $name = $entityNames[$row->entity_id] ?? null;
            $label = $name
                ? trim(($name->customer_code ?? $name->supplier_code ?? $name->employee_code ?? $name->id) . ' — ' . ($name->customer_name ?? $name->supplier_name ?? $name->name ?? $name->branch_name ?? 'Unknown'))
                : "ID {$row->entity_id}";
            $this->line(sprintf(
                "    %-40s  drift_rows=%d  max_drift=%s  cumulative=%s",
                $label,
                $row->drift_count,
                number_format($row->max_entity_drift, 2),
                number_format($row->cumulative_drift, 2)
            ));
        }

        // Show the actual worst rows.
        $worstRows = DB::select("
            SELECT id, {$entityCol}, transaction_date, transaction_type,
                   stored_balance, computed_balance, drift
            FROM {$view}
            WHERE ABS(drift) > ?{$dateFilter}
            ORDER BY ABS(drift) DESC
            LIMIT 5
        ", $asOf ? [$this->tolerance, $asOf] : [$this->tolerance]);

        if (!empty($worstRows)) {
            $this->warn("  Worst individual rows (top 5):");
            foreach ($worstRows as $row) {
                $this->line(sprintf(
                    "    id=%d  %s=%d  date=%s  stored=%s  computed=%s  drift=%s",
                    $row->id,
                    $entityCol,
                    $row->{$entityCol},
                    $row->transaction_date,
                    number_format($row->stored_balance, 2),
                    number_format($row->computed_balance, 2),
                    number_format($row->drift, 2)
                ));
            }
        }
    }

    /**
     * Fix drift rows by updating stored_balance to computed_balance.
     *
     * Instead of updating the materialized view (which is read-only), we
     * update the source table (customer_ledger, supplier_ledger, etc.)
     * where the drift is detected, then refresh the materialized view
     * to verify the fix.
     *
     * @return int Number of rows fixed
     */
    private function fixDriftRows(string $key, string $view, array $config, ?string $asOf): int
    {
        $sourceTable = $this->getSourceTable($key);
        $dateFilter = $asOf ? " AND transaction_date <= ?" : '';
        $params = $asOf ? [$this->tolerance, $asOf] : [$this->tolerance];

        // Get all drifted row IDs and their correct balances.
        $driftedRows = DB::select("
            SELECT id, computed_balance
            FROM {$view}
            WHERE ABS(drift) > ?{$dateFilter}
        ", $params);

        $fixed = 0;
        foreach ($driftedRows as $row) {
            DB::table($sourceTable)
                ->where('id', $row->id)
                ->update(['balance' => $row->computed_balance]);
            $fixed++;
        }

        // Refresh the materialized view to verify fix.
        if ($fixed > 0) {
            DB::statement("REFRESH MATERIALIZED VIEW {$view}");

            // Verify: count remaining drift rows.
            $remaining = DB::selectOne("
                SELECT COUNT(*) AS cnt FROM {$view} WHERE ABS(drift) > ?
            ", [$this->tolerance]);

            if ((int) $remaining->cnt > 0) {
                $this->warn("  ⚠  After fix, {$remaining->cnt} rows still have drift (may need cascading recalculation).");
            }
        }

        return $fixed;
    }

    /**
     * Map ledger key to source table name.
     */
    private function getSourceTable(string $key): string
    {
        return match ($key) {
            'customer' => 'customer_ledger',
            'supplier' => 'supplier_ledger',
            'employee' => 'employee_ledger',
            'cash' => 'cash_ledger',
            default => throw new \InvalidArgumentException("Unknown ledger key: {$key}"),
        };
    }

    /**
     * Store a structured reconciliation snapshot for audit trail.
     */
    private function storeSnapshot(string $ledgerType, array $result, ?string $asOf): void
    {
        try {
            DB::table('reconciliation_snapshots')->insert([
                'run_type' => 'running_balance',
                'ledger_type' => $ledgerType,
                'total_rows' => $result['total_rows'],
                'matched_rows' => $result['matched_rows'],
                'drift_rows' => $result['drift_rows'],
                'max_drift' => $result['max_drift'],
                'status' => $result['green'] ? 'green' : 'red',
                'tolerance' => $this->tolerance,
                'as_of_date' => $asOf,
                'details' => json_encode($result['details']),
                'ran_at' => now(),
                'ran_by' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            // Don't fail the reconciliation if snapshot storage fails.
            Log::warning("Reconciliation snapshot storage failed: {$e->getMessage()}");
        }
    }
}
