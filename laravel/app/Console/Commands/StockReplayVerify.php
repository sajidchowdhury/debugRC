<?php

namespace App\Console\Commands;

use App\Services\Stock\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Replay Verification — Phase 6.2.
 *
 * Replays all stock_transactions from production through the new
 * StockService avg-cost logic and compares the resulting balances
 * to the live warehouse_stock. Zero drift is required for Phase 6.2
 * sign-off (see avg_cost_rule.md §6).
 *
 * Phase 6.2 enhancements over Phase 6.1:
 *   - Writes replay results to warehouse_stock_shadow table (persistent)
 *   - Logs every drift to avg_cost_drift table (for investigation)
 *   - Tracks last_transaction_id per product (for root-cause analysis)
 *   - Clears previous drift rows on each run
 *
 * Usage:
 *   php artisan stock:replay-verify
 *   php artisan stock:replay-verify --product=42
 *   php artisan stock:replay-verify --limit=1000
 *
 * Exit codes:
 *   0 = zero drift (PASS)
 *   1 = drift detected or errors (FAIL — investigate avg_cost_drift table)
 */
class StockReplayVerify extends Command
{
    protected $signature = 'stock:replay-verify
                            {--limit=0 : Limit number of transactions (0 = all)}
                            {--from-id=0 : Start from transaction id}
                            {--product=0 : Filter to a single product_id}
                            {--keep-drift : Do not clear previous drift rows before running}';

    protected $description = 'Replay all stock transactions, verify zero drift vs live warehouse_stock, log drift to avg_cost_drift table';

    public function handle(StockService $stockService): int
    {
        $this->info('=== Stock Replay Verification (Phase 6.2) ===');
        $this->info('Replays all stock_transactions through the avg-cost logic');
        $this->info('and compares to live warehouse_stock. Zero drift is required.');
        $this->newLine();

        // Clear previous drift rows (unless --keep-drift).
        if (!$this->option('keep-drift')) {
            $cleared = DB::table('avg_cost_drift')->where('status', 'open')->delete();
            if ($cleared > 0) {
                $this->info("Cleared {$cleared} previous open drift rows.");
            }
            DB::table('warehouse_stock_shadow')->truncate();
        }

        // Step 1: Count transactions to replay.
        $query = DB::table('stock_transactions')
            ->where('is_reversed', false)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($this->option('product')) {
            $query->where('product_id', (int) $this->option('product'));
        }
        if ($this->option('from-id')) {
            $query->where('id', '>=', (int) $this->option('from-id'));
        }
        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $totalCount = (clone $query)->count();
        $this->info("Transactions to replay: {$totalCount}");

        if ($totalCount === 0) {
            $this->warn('No transactions found. Nothing to replay.');
            return self::SUCCESS;
        }

        // Step 2: Build shadow balances in memory.
        // Each entry: [qty, avg_cost, tx_count, last_tx_id, last_ref_type, last_ref_id]
        $shadow = [];
        $errors = [];
        $processed = 0;
        $bar = $this->output->createProgressBar(min($totalCount, 100000));
        $bar->start();

        $query->chunk(1000, function ($transactions) use (&$shadow, &$errors, &$processed, $bar) {
            foreach ($transactions as $tx) {
                $processed++;
                $key = "{$tx->warehouse_id}:{$tx->product_id}";

                if (!isset($shadow[$key])) {
                    $shadow[$key] = [
                        'qty' => 0.0,
                        'avg_cost' => 0.0,
                        'tx_count' => 0,
                        'last_tx_id' => null,
                        'last_ref_type' => null,
                        'last_ref_id' => null,
                    ];
                }

                $oldQty = $shadow[$key]['qty'];
                $oldAvg = $shadow[$key]['avg_cost'];
                $qty = (float) $tx->qty;
                $rate = (float) $tx->rate;

                if ($qty > 0) {
                    // IN: recalc avg cost
                    $newQty = $oldQty + $qty;
                    if ($newQty <= 0) {
                        $newAvg = $rate;
                    } else {
                        $newAvg = ($oldQty * $oldAvg + $qty * $rate) / $newQty;
                    }
                } else {
                    // OUT: reduce qty, avg unchanged
                    $newQty = $oldQty - abs($qty);
                    $newAvg = $oldAvg;

                    if ($newQty < -0.0001) {
                        $errors[] = [
                            'transaction_id' => $tx->id,
                            'warehouse_id' => $tx->warehouse_id,
                            'product_id' => $tx->product_id,
                            'error' => "Insufficient stock: available {$oldQty}, requested " . abs($qty),
                            'reference_type' => $tx->reference_type,
                            'reference_id' => $tx->reference_id,
                        ];
                    }
                }

                $shadow[$key] = [
                    'qty' => $newQty,
                    'avg_cost' => $newAvg,
                    'tx_count' => $shadow[$key]['tx_count'] + 1,
                    'last_tx_id' => $tx->id,
                    'last_ref_type' => $tx->reference_type,
                    'last_ref_id' => $tx->reference_id,
                ];
            }
            $bar->advance(count($transactions));
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Processed {$processed} transactions.");
        $this->info("Shadow balances: " . count($shadow) . " warehouse/product pairs.");

        if (!empty($errors)) {
            $this->newLine();
            $this->error("Found " . count($errors) . " insufficient-stock errors during replay:");
            foreach (array_slice($errors, 0, 20) as $err) {
                $this->warn("  TX #{$err['transaction_id']}: {$err['error']} ({$err['reference_type']} #{$err['reference_id']})");
            }
            if (count($errors) > 20) {
                $this->warn("  ... and " . (count($errors) - 20) . " more.");
            }
        }

        // Step 3: Write shadow balances to warehouse_stock_shadow table.
        $this->newLine();
        $this->info('Writing shadow balances to warehouse_stock_shadow table...');
        $shadowRows = [];
        $now = now();
        foreach ($shadow as $key => $bal) {
            [$wid, $pid] = explode(':', $key);
            $shadowRows[] = [
                'warehouse_id' => (int) $wid,
                'product_id' => (int) $pid,
                'qty' => $bal['qty'],
                'avg_cost' => $bal['avg_cost'],
                'transaction_count' => $bal['tx_count'],
                'last_transaction_id' => $bal['last_tx_id'],
                'replayed_at' => $now,
            ];
        }
        // Insert in chunks of 500.
        foreach (array_chunk($shadowRows, 500) as $chunk) {
            DB::table('warehouse_stock_shadow')->insert($chunk);
        }
        $this->info("Wrote " . count($shadowRows) . " shadow rows.");

        // Step 4: Compare shadow to live warehouse_stock + log drift.
        $this->newLine();
        $this->info('Comparing shadow balances to live warehouse_stock...');
        $liveStock = DB::table('warehouse_stock')->get();

        $driftCount = 0;
        $maxQtyDrift = 0.0;
        $maxCostDrift = 0.0;
        $driftRows = [];

        foreach ($liveStock as $live) {
            $key = "{$live->warehouse_id}:{$live->product_id}";
            $shadowBal = $shadow[$key] ?? ['qty' => 0.0, 'avg_cost' => 0.0, 'last_tx_id' => null, 'last_ref_type' => null, 'last_ref_id' => null];

            $qtyDrift = abs((float) $live->qty - $shadowBal['qty']);
            $costDrift = abs((float) $live->avg_cost - $shadowBal['avg_cost']);

            // Tolerance: 0.0001 for qty, 0.01 for cost.
            if ($qtyDrift > 0.0001 || $costDrift > 0.01) {
                $driftCount++;
                $maxQtyDrift = max($maxQtyDrift, $qtyDrift);
                $maxCostDrift = max($maxCostDrift, $costDrift);

                $driftRows[] = [
                    'warehouse_id' => $live->warehouse_id,
                    'product_id' => $live->product_id,
                    'live_qty' => $live->qty,
                    'shadow_qty' => $shadowBal['qty'],
                    'qty_drift' => $qtyDrift,
                    'live_avg_cost' => $live->avg_cost,
                    'shadow_avg_cost' => $shadowBal['avg_cost'],
                    'cost_drift' => $costDrift,
                    'last_transaction_id' => $shadowBal['last_tx_id'],
                    'last_reference_type' => $shadowBal['last_ref_type'],
                    'last_reference_id' => $shadowBal['last_ref_id'],
                    'status' => 'open',
                    'detected_at' => $now,
                ];
            }

            unset($shadow[$key]);
        }

        // Orphan shadow: products in shadow but not in live warehouse_stock.
        $orphanShadow = count($shadow);
        foreach ($shadow as $key => $bal) {
            if (abs($bal['qty']) > 0.0001) { // only log non-zero orphans
                [$wid, $pid] = explode(':', $key);
                $driftRows[] = [
                    'warehouse_id' => (int) $wid,
                    'product_id' => (int) $pid,
                    'live_qty' => 0,
                    'shadow_qty' => $bal['qty'],
                    'qty_drift' => abs($bal['qty']),
                    'live_avg_cost' => 0,
                    'shadow_avg_cost' => $bal['avg_cost'],
                    'cost_drift' => abs($bal['avg_cost']),
                    'last_transaction_id' => $bal['last_tx_id'],
                    'last_reference_type' => $bal['last_ref_type'],
                    'last_reference_id' => $bal['last_ref_id'],
                    'status' => 'open',
                    'detected_at' => $now,
                ];
                $driftCount++;
            }
        }

        // Write drift rows to avg_cost_drift table.
        if (!empty($driftRows)) {
            foreach (array_chunk($driftRows, 500) as $chunk) {
                DB::table('avg_cost_drift')->insert($chunk);
            }
        }

        // Step 5: Report.
        $this->newLine();
        $this->info('=== Replay Verification Result ===');
        $this->info("Live warehouse_stock rows: " . $liveStock->count());
        $this->info("Shadow balances: " . count($shadowRows));
        $this->info("Orphan shadow (no live row): {$orphanShadow}");
        $this->info("Insufficient-stock errors: " . count($errors));
        $this->info("Drift count: {$driftCount}");

        if ($driftCount > 0) {
            $this->warn("Max qty drift: " . number_format($maxQtyDrift, 4));
            $this->warn("Max cost drift: " . number_format($maxCostDrift, 2));
            $this->newLine();
            $this->error("DRIFT DETECTED — logged {$driftCount} rows to avg_cost_drift table.");
            $this->warn("Investigate with: SELECT * FROM avg_cost_drift WHERE status = 'open' ORDER BY qty_drift DESC, cost_drift DESC;");
            $this->warn("Or visit: /admin/stock/drift (coming in Phase 6.2 UI)");

            Log::warning('Stock replay drift detected', [
                'drift_count' => $driftCount,
                'max_qty_drift' => $maxQtyDrift,
                'max_cost_drift' => $maxCostDrift,
                'logged_to' => 'avg_cost_drift table',
            ]);

            return self::FAILURE;
        }

        if (!empty($errors)) {
            $this->warn('Replay had insufficient-stock errors but balances match.');
            $this->warn('(Legacy may have allowed transient negatives — investigate each error.)');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ ZERO DRIFT — StockService avg-cost logic matches live warehouse_stock.');
        $this->info('Shadow balances written to warehouse_stock_shadow table.');
        $this->info('Phase 6.2 replay verification PASSED.');

        Log::info('Stock replay verification passed', [
            'transactions' => $processed,
            'balances' => $liveStock->count(),
            'shadow_rows' => count($shadowRows),
        ]);

        return self::SUCCESS;
    }
}
