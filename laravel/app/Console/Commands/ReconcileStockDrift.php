<?php

namespace App\Console\Commands;

use App\Services\Stock\StockAdjustmentReconcileService;
use Illuminate\Console\Command;

/**
 * Reconcile Stock Drift — Phase 7.
 *
 * Nightly drift-detection job. Computes the divergence between the
 * warehouse_stock snapshot cache and the stock_transactions immutable ledger
 * (the SSOT) for every (warehouse, product) pair. When drift is found,
 * fires an ERPNotification to every user whose employee role is in the
 * `stock_adjustment.reconcile_alert_roles` config (default: admin).
 *
 * The invariant being checked:
 *
 *   warehouse_stock.qty
 *     == SUM(stock_transactions.qty)
 *          FILTER (WHERE NOT is_reversed
 *                  AND warehouse_id = ws.warehouse_id
 *                  AND product_id   = ws.product_id)
 *
 * No drift = no notification (we don't spam "all clear" nightly). The
 * command logs a quiet info line either way so schedule health is observable.
 *
 * Usage:
 *   php artisan stock:reconcile-drift              # run + alert (default)
 *   php artisan stock:reconcile-drift --dry-run    # report only, no notify
 *   php artisan stock:reconcile-drift --branch=2   # scope to one branch
 *
 * Scheduled nightly at 03:00 in routes/console.php (offset from the 02:00
 * stale-draft cancel so the two heavy queries don't overlap).
 */
class ReconcileStockDrift extends Command
{
    protected $signature = 'stock:reconcile-drift
                            {--dry-run : Report drift only; do not fire notifications}
                            {--branch= : Scope to a single branch_id (default: all branches)}';

    protected $description = 'Detect warehouse_stock ↔ stock_transactions drift and alert admins (Phase 7)';

    public function handle(StockAdjustmentReconcileService $reconcile): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;

        $this->info('Running stock drift reconciliation…');

        // When a branch is scoped we just compute + report (no nightly alert
        // path — the alert is all-tenant by design). --dry-run also skips
        // the alert.
        if ($branchId !== null || $dryRun) {
            $result = $reconcile->computeDrift($branchId);
            $this->info(sprintf(
                'Checked %d row(s); found %d mismatch(es); total |drift| = %.4f.',
                $result['checked'],
                $result['mismatched'],
                $result['total_drift_qty']
            ));
            if ($result['mismatched'] > 0) {
                $this->warn('Drift rows (top 20):');
                foreach (array_slice($result['mismatches'], 0, 20) as $m) {
                    $this->line(sprintf(
                        '  WH %s (%s) — %s — snapshot %s / ledger %s / drift %s',
                        $m->warehouse_id,
                        $m->warehouse_name ?? '?',
                        $m->product_name ?? ('#' . $m->product_id),
                        $m->snapshot_qty,
                        $m->ledger_qty,
                        $m->drift_qty
                    ));
                }
            }
            return self::SUCCESS;
        }

        // All-tenant nightly path — compute + alert admins.
        $result = $reconcile->runNightlyDriftAlert();

        if ($result['mismatched'] === 0) {
            $this->info('No stock drift detected.');
        } else {
            $this->warn(sprintf(
                'STOCK DRIFT DETECTED: %d mismatch(es); total |drift| = %.4f. Notified %d admin user(s).',
                $result['mismatched'],
                $result['total_drift_qty'],
                $result['notified']
            ));
        }

        return self::SUCCESS;
    }
}
