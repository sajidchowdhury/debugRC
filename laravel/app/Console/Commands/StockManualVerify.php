<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stock Manual Verification — Phase 6.2.
 *
 * Picks 10 sample products and shows the step-by-step moving-average cost
 * calculation for each, so the accountant can manually verify the logic
 * matches their understanding.
 *
 * This is the "accountant sign-off" tool required by avg_cost_rule.md §7:
 *   "Accountant: this document reviewed and approved (the rate semantics
 *    in §3 are the critical business rules)"
 *
 * The accountant reviews:
 *   1. The 10 sample products' transaction history
 *   2. The step-by-step qty + avg_cost after each transaction
 *   3. Confirms the final qty + avg_cost matches their manual calculation
 *
 * Usage:
 *   php artisan stock:manual-verify
 *   php artisan stock:manual-verify --product=42
 *   php artisan stock:manual-verify --count=20
 *
 * Output: a table per product showing each transaction + running balance.
 */
class StockManualVerify extends Command
{
    protected $signature = 'stock:manual-verify
                            {--product=0 : Verify a specific product_id (default: pick 10 samples)}
                            {--count=10 : Number of sample products to verify (default 10)}
                            {--warehouse=0 : Filter to a specific warehouse_id}';

    protected $description = 'Show step-by-step avg-cost calculation for 10 sample products (for accountant sign-off)';

    public function handle(): int
    {
        $this->info('=== Stock Manual Verification (Phase 6.2) ===');
        $this->info('Shows step-by-step moving-average cost calculation for accountant review.');
        $this->info('The accountant must confirm the final qty + avg_cost matches their manual calculation.');
        $this->newLine();

        $specificProduct = (int) $this->option('product');
        $sampleCount = (int) $this->option('count');
        $warehouseFilter = (int) $this->option('warehouse');

        // Step 1: Select products to verify.
        if ($specificProduct > 0) {
            $products = DB::table('products')
                ->where('id', $specificProduct)
                ->get();
        } else {
            // Pick products with the most transactions (most interesting to verify).
            $query = DB::table('products as p')
                ->join('stock_transactions as st', 'st.product_id', '=', 'p.id')
                ->where('st.is_reversed', false)
                ->when($warehouseFilter, fn($q) => $q->where('st.warehouse_id', $warehouseFilter))
                ->select('p.id', 'p.product_code', 'p.product_name', 'p.unit', DB::raw('COUNT(st.id) as tx_count'))
                ->groupBy('p.id', 'p.product_code', 'p.product_name', 'p.unit')
                ->orderByDesc('tx_count')
                ->limit($sampleCount);

            $products = $query->get();
        }

        if ($products->isEmpty()) {
            $this->warn('No products found to verify.');
            return self::FAILURE;
        }

        $this->info("Verifying {$products->count()} product(s):");
        $this->newLine();

        $allMatch = true;

        foreach ($products as $product) {
            $this->info(str_repeat('=', 80));
            $this->info("Product: {$product->product_code} — {$product->product_name} (unit: {$product->unit})");
            $this->info(str_repeat('=', 80));

            // Get all stock transactions for this product, ordered chronologically.
            $transactions = DB::table('stock_transactions as st')
                ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
                ->where('st.product_id', $product->id)
                ->where('st.is_reversed', false)
                ->when($warehouseFilter, fn($q) => $q->where('st.warehouse_id', $warehouseFilter))
                ->select(
                    'st.id', 'st.transaction_date', 'st.warehouse_id',
                    'w.warehouse_name', 'st.qty', 'st.rate',
                    'st.reference_type', 'st.reference_id', 'st.created_at'
                )
                ->orderBy('st.created_at')
                ->orderBy('st.id')
                ->get();

            if ($transactions->isEmpty()) {
                $this->warn("  No transactions found for this product.");
                $this->newLine();
                continue;
            }

            // Group by warehouse (each warehouse has its own avg_cost).
            $byWarehouse = $transactions->groupBy('warehouse_id');

            foreach ($byWarehouse as $warehouseId => $whTransactions) {
                $whName = $whTransactions->first()->warehouse_name;
                $this->newLine();
                $this->info("  Warehouse: {$whName} (ID: {$warehouseId})");

                // Print the step-by-step table.
                $tableRows = [];
                $runningQty = 0.0;
                $runningAvg = 0.0;

                foreach ($whTransactions as $tx) {
                    $qty = (float) $tx->qty;
                    $rate = (float) $tx->rate;
                    $oldQty = $runningQty;
                    $oldAvg = $runningAvg;

                    if ($qty > 0) {
                        // IN
                        $runningQty = $oldQty + $qty;
                        if ($runningQty <= 0) {
                            $runningAvg = $rate;
                        } else {
                            $runningAvg = ($oldQty * $oldAvg + $qty * $rate) / $runningQty;
                        }
                        $movement = 'IN';
                    } else {
                        // OUT
                        $runningQty = $oldQty - abs($qty);
                        // avg unchanged
                        $movement = 'OUT';
                    }

                    $tableRows[] = [
                        $tx->id,
                        $tx->transaction_date,
                        $movement,
                        number_format($qty, 4),
                        number_format($rate, 2),
                        number_format($oldQty, 4),
                        number_format($oldAvg, 2),
                        number_format($runningQty, 4),
                        number_format($runningAvg, 2),
                        $tx->reference_type,
                        $tx->reference_id,
                    ];
                }

                $this->table(
                    ['TX#', 'Date', 'Move', 'Qty', 'Rate', 'OldQty', 'OldAvg', 'NewQty', 'NewAvg', 'RefType', 'Ref#'],
                    $tableRows
                );

                // Compare to live warehouse_stock.
                $live = DB::table('warehouse_stock')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $product->id)
                    ->first();

                $this->newLine();
                $this->info("  Computed final: qty=" . number_format($runningQty, 4) . ", avg_cost=" . number_format($runningAvg, 2));

                if ($live) {
                    $liveQty = (float) $live->qty;
                    $liveAvg = (float) $live->avg_cost;
                    $qtyMatch = abs($liveQty - $runningQty) < 0.0001;
                    $avgMatch = abs($liveAvg - $runningAvg) < 0.01;

                    $this->info("  Live value:     qty=" . number_format($liveQty, 4) . ", avg_cost=" . number_format($liveAvg, 2));

                    if ($qtyMatch && $avgMatch) {
                        $this->info("  ✓ MATCH — computed values match live warehouse_stock.");
                    } else {
                        $this->error("  ✗ MISMATCH — qty drift: " . number_format(abs($liveQty - $runningQty), 4)
                            . ", cost drift: " . number_format(abs($liveAvg - $runningAvg), 2));
                        $allMatch = false;
                    }
                } else {
                    if (abs($runningQty) < 0.0001) {
                        $this->info("  ✓ MATCH — zero balance (no live row, as expected).");
                    } else {
                        $this->error("  ✗ MISMATCH — computed qty is " . number_format($runningQty, 4) . " but no live warehouse_stock row exists.");
                        $allMatch = false;
                    }
                }
            }

            $this->newLine();
        }

        // Final summary.
        $this->newLine();
        $this->info(str_repeat('=', 80));
        $this->info('=== Manual Verification Summary ===');
        $this->info(str_repeat('=', 80));

        if ($allMatch) {
            $this->info("✓ ALL {$products->count()} product(s) verified — computed values match live warehouse_stock.");
            $this->newLine();
            $this->info('Next steps for accountant sign-off:');
            $this->info('  1. Review the step-by-step calculations above.');
            $this->info('  2. Confirm the avg-cost logic matches your understanding (see avg_cost_rule.md).');
            $this->info('  3. Pay special attention to:');
            $this->info('     - IN movements: avg_cost recalculated as weighted average.');
            $this->info('     - OUT movements: avg_cost UNCHANGED (cost flows out at current average).');
            $this->info('     - Rate column: the unit cost snapshotted at transaction time.');
            $this->info('  4. Sign the avg_cost_rule.md document (§7 sign-off section).');

            return self::SUCCESS;
        } else {
            $this->error('✗ Some products have mismatches — investigate before sign-off.');
            $this->warn('Run `php artisan stock:replay-verify` for a full drift report.');
            return self::FAILURE;
        }
    }
}
