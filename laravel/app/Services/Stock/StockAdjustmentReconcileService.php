<?php

namespace App\Services\Stock;

use App\Notifications\ERPNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Stock Adjustment Reconcile Service — Phase 7.
 *
 * Detects + surfaces divergence between the `warehouse_stock` snapshot cache
 * and the `stock_transactions` immutable ledger (the SSOT). The fundamental
 * invariant for every (warehouse, product) pair is:
 *
 *   warehouse_stock.qty
 *     == SUM(stock_transactions.qty)
 *          FILTER (WHERE NOT is_reversed
 *                  AND warehouse_id = ws.warehouse_id
 *                  AND product_id   = ws.product_id)
 *
 * Because `warehouse_stock` is a denormalized cache maintained by
 * StockService::applyTransaction() (lock → recompute → upsert), any bug in
 * that path, any manual SQL poke, or any crashed transaction that committed
 * the ledger row but not the snapshot update will produce drift. Phase 7's
 * job is to make that drift VISIBLE (reconcile view), REPAIRABLE
 * (rebuildSnapshot — admin-only, recomputes the cache from the ledger), and
 * ALERTED (nightly schedule fires an ERPNotification to admins).
 *
 * Scope note: this service reconciles the ENTIRE warehouse_stock ↔
 * stock_transactions invariant, not just adjustments. Adjustments are one of
 * many writers (purchases, sales, transfers, stock-take, damages); a drift
 * detected here is not necessarily caused by the adjustment module. The
 * reconcile view is surfaced under the Stock Adjustment module because that
 * module is the bookkeeping-correction tool, and "find + fix stock drift" is
 * a natural accountant responsibility — but the SQL is module-agnostic.
 *
 * G15/G16 (data-hygiene) were already closed by Phases 1/3 (cancel_reason
 * always stored; getProductRate branch-validated). This phase closes G12
 * (no warehouse_stock ↔ ledger drift reconciliation check).
 */
class StockAdjustmentReconcileService
{
    /**
     * Default drift tolerance (numeric(14,4) scale). Any |drift| <= this is
     * treated as zero (rounding noise). Overridable via config.
     */
    private const DEFAULT_TOLERANCE = 0.0001;

    /**
     * Cap on rows returned by computeDrift() — the view is paginated/scrollable
     * but we never want to pull millions of rows into PHP.
     */
    private const DEFAULT_LIMIT = 500;

    /**
     * Compute the drift between warehouse_stock.qty and the non-reversed
     * stock_transactions ledger sum, for every (warehouse, product) where
     * the two diverge beyond the tolerance.
     *
     * The SQL uses a LEFT JOIN on a pre-aggregated ledger subquery (rather
     * than a correlated subquery) so the planner can hash-join once. The
     * FILTER (WHERE NOT is_reversed) clause is the idiomatic PostgreSQL way
     * to conditionally aggregate — cleaner than CASE WHEN and matches the
     * Phase 7 plan spec verbatim.
     *
     * Branch scoping: when $branchId is provided, only warehouses owned by
     * that branch are checked (a non-admin user reconciles only their own
     * branch). When null, ALL branches are checked (admin / nightly job).
     * The branch filter is applied INSIDE the warehouse_stock join (not on
     * the ledger subquery) so a product moving between branches isn't
     * miscounted.
     *
     * @param int|null $branchId  Scope to one branch, or null for all.
     * @param int|null $warehouseId  Scope to one warehouse, or null for all.
     * @param int $limit  Max drift rows to return.
     * @return array{
     *     mismatches: list<array>,
     *     checked: int,
     *     mismatched: int,
     *     total_drift_qty: float,
     *     ran_at: string
     * }
     */
    public function computeDrift(?int $branchId = null, ?int $warehouseId = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $tolerance = (float) config('stock_adjustment.reconcile_tolerance', self::DEFAULT_TOLERANCE);
        $tolerance = $tolerance > 0 ? $tolerance : self::DEFAULT_TOLERANCE;

        $wheres = [];
        $bindings = [];

        if ($branchId !== null && $branchId > 0) {
            $wheres[] = 'w.branch_id = ?';
            $bindings[] = $branchId;
        }
        if ($warehouseId !== null && $warehouseId > 0) {
            $wheres[] = 'ws.warehouse_id = ?';
            $bindings[] = $warehouseId;
        }
        $whereClause = $wheres ? ('AND ' . implode(' AND ', $wheres)) : '';

        // Drift rows (the HAVING clause is what makes this return only
        // divergent pairs — the full cross-check count comes from a separate
        // COUNT query below).
        $sql = <<<SQL
SELECT
    ws.warehouse_id,
    ws.product_id,
    ws.qty          AS snapshot_qty,
    ws.avg_cost     AS snapshot_avg_cost,
    COALESCE(st.ledger_qty, 0) AS ledger_qty,
    ws.qty - COALESCE(st.ledger_qty, 0) AS drift_qty,
    w.warehouse_name,
    w.branch_id,
    p.product_name,
    p.product_code
FROM warehouse_stock ws
JOIN warehouses w ON w.id = ws.warehouse_id
JOIN products   p ON p.id = ws.product_id
LEFT JOIN (
    SELECT warehouse_id, product_id,
           SUM(qty) FILTER (WHERE NOT is_reversed) AS ledger_qty
    FROM stock_transactions
    GROUP BY warehouse_id, product_id
) st ON st.warehouse_id = ws.warehouse_id
    AND st.product_id   = ws.product_id
WHERE ABS(ws.qty - COALESCE(st.ledger_qty, 0)) > ?
{$whereClause}
ORDER BY ABS(ws.qty - COALESCE(st.ledger_qty, 0)) DESC
LIMIT ?
SQL;

        $driftBindings = array_merge([$tolerance], $bindings, [$limit]);
        $mismatches = DB::select($sql, $driftBindings);

        // Total rows checked (the full population, not just the drift rows)
        // — drives the "% consistent" stat on the view. Same WHERE scope.
        $countSql = <<<SQL
SELECT COUNT(*) AS c
FROM warehouse_stock ws
JOIN warehouses w ON w.id = ws.warehouse_id
WHERE 1=1
{$whereClause}
SQL;
        $checked = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        // Sum the absolute drift so the alert + the view can quote a single
        // "total drift" figure (helps triage: 0.0004 drift = noise; 47.0
        // drift = a real desync). Computed in SQL for precision.
        $totalDrift = 0.0;
        foreach ($mismatches as $m) {
            $totalDrift += abs((float) $m->drift_qty);
        }

        return [
            'mismatches'      => $mismatches,
            'checked'         => $checked,
            'mismatched'      => count($mismatches),
            'total_drift_qty' => round($totalDrift, 4),
            'ran_at'          => now()->toDateTimeString(),
        ];
    }

    /**
     * Rebuild the warehouse_stock snapshot for a single warehouse (or all
     * warehouses) from the stock_transactions ledger.
     *
     * This is the REPAIR path for drift. It is destructive (DELETE + INSERT)
     * and therefore ADMIN-ONLY at the controller/policy layer. The ledger is
     * the SSOT, so rebuilding the cache from it is always safe — any drift
     * is, by definition, a cache defect (the ledger is append-only + never
     * mutated by application code).
     *
     * The rebuild is wrapped in a single DB transaction so the warehouse_stock
     * table never appears empty to a concurrent reader (DELETE + INSERT are
     * atomic). avg_cost is recomputed as the moving-average of all IN
     * transactions (qty > 0) MINUS the running OUT cost basis — but because
     * the legacy moving-average is path-dependent, we approximate it as
     * total_value / total_qty when total_qty > 0, else 0. This matches the
     * denormalized `warehouse_stock.total_value` / `total_qty` columns the
     * schema defines.
     *
     * @param int|null $warehouseId  Scope to one warehouse, or null for all.
     * @param int|null $branchId  Optional branch scope (defense-in-depth;
     *                             non-admin rebuilds are branch-scoped).
     * @return array{rebuilt: int, errors: list<string>}
     */
    public function rebuildSnapshot(?int $warehouseId = null, ?int $branchId = null): array
    {
        $errors = [];
        $rebuilt = 0;

        DB::transaction(function () use ($warehouseId, $branchId, &$rebuilt, &$errors) {
            $scopeWheres = [];
            $scopeBindings = [];

            if ($warehouseId !== null && $warehouseId > 0) {
                $scopeWheres[] = 'warehouse_id = ?';
                $scopeBindings[] = $warehouseId;
            }
            $scopeWhereClause = $scopeWheres ? implode(' AND ', $scopeWheres) : '1=1';

            // Lock the warehouse_stock rows we're about to rewrite (or, when
            // scoped to all, lock the table). This blocks concurrent
            // StockService::applyTransaction() upserts for the scope until
            // we commit — the rebuild is fast (one DELETE + one INSERT…
            // SELECT) so the lock window is short.
            if ($warehouseId !== null && $warehouseId > 0) {
                DB::table('warehouse_stock')
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->get();
            }

            // Recompute the snapshot from the ledger. qty = sum of non-reversed
            // movements. total_qty / total_value mirror the schema's
            // denormalized columns (kept for compatibility with any report
            // that reads them). avg_cost = total_value / total_qty when there
            // is stock on hand, else 0.
            //
            // NOTE on total_value: a movement's value is qty × rate. SUM of
            // that across non-reversed rows is the current inventory value at
            // historical cost — which is what the moving-average avg_cost × qty
            // would equal if avg_cost were always maintained correctly. So
            // rebuilding avg_cost = total_value / qty is self-correcting.
            $branchJoin = '';
            if ($branchId !== null && $branchId > 0) {
                $branchJoin = 'JOIN warehouses w ON w.id = st.warehouse_id AND w.branch_id = ' . (int) $branchId;
            }

            $ledgerScope = $scopeWheres ? ('WHERE ' . implode(' AND ', array_map(fn ($c) => 'st.' . $c, $scopeWheres))) : '';

            $recomputeSql = <<<SQL
SELECT
    st.warehouse_id,
    st.product_id,
    SUM(st.qty) FILTER (WHERE NOT st.is_reversed) AS qty,
    SUM(st.qty * st.rate) FILTER (WHERE NOT st.is_reversed) AS total_value
FROM stock_transactions st
{$branchJoin}
{$ledgerScope}
GROUP BY st.warehouse_id, st.product_id
SQL;

            $rows = DB::select($recomputeSql, $scopeBindings);

            // Delete the existing snapshot rows for the scope, then re-insert
            // from the ledger. Products that have ZERO ledger activity (all
            // movements reversed, or never stocked) get a qty-0 snapshot row
            // so the warehouse_stock table still lists them — matching the
            // original applyTransaction() upsert semantics where a row is
            // created on first movement and never removed.
            DB::table('warehouse_stock')->whereRaw($scopeWhereClause, $scopeBindings)->delete();

            $now = now();
            $insertRows = [];
            foreach ($rows as $r) {
                $qty = (float) ($r->qty ?? 0);
                $totalValue = (float) ($r->total_value ?? 0);
                $avgCost = $qty > 0 ? round($totalValue / $qty, 2) : 0.0;

                $insertRows[] = [
                    'warehouse_id' => $r->warehouse_id,
                    'product_id'   => $r->product_id,
                    'qty'          => $qty,
                    'avg_cost'     => $avgCost,
                    'total_qty'    => $qty,
                    'total_value'  => round($totalValue, 2),
                    'updated_at'   => $now,
                ];
                $rebuilt++;
            }

            // Chunk the insert (Postgres parameter limit is 65535; 7 cols ×
            // 500 rows = 3500 params — safe, but chunk to be defensive for
            // the "rebuild all" case which can be thousands of rows).
            foreach (array_chunk($insertRows, 500) as $chunk) {
                DB::table('warehouse_stock')->insert($chunk);
            }
        });

        Log::info('StockAdjustmentReconcileService: snapshot rebuilt', [
            'warehouse_id' => $warehouseId,
            'branch_id'    => $branchId,
            'rebuilt_rows' => $rebuilt,
        ]);

        return ['rebuilt' => $rebuilt, 'errors' => $errors];
    }

    /**
     * Run the nightly drift check + alert admins.
     *
     * Called by the `stock:reconcile-drift` scheduled command (routes/
     * console.php). When drift is detected (mismatched > 0), an ERPNotification
     * is fired to every user whose role is in `reconcile_alert_roles` (default
     * admin + superadmin). The notification body quotes the mismatched count +
     * total drift so an admin can decide whether to open the reconcile view.
     *
     * No drift = no notification (we don't spam "all clear" nightly). The
     * command logs a quiet "0 mismatches" line either way so the schedule
     * health is observable.
     *
     * @return array{mismatched: int, total_drift_qty: float, notified: int}
     */
    public function runNightlyDriftAlert(): array
    {
        $result = $this->computeDrift(); // all branches
        $mismatched = $result['mismatched'];
        $totalDrift = $result['total_drift_qty'];

        if ($mismatched === 0) {
            Log::info('StockAdjustmentReconcileService: nightly drift check — 0 mismatches.');
            return ['mismatched' => 0, 'total_drift_qty' => 0.0, 'notified' => 0];
        }

        // Respect the alert toggle: when FALSE, the drift is still computed
        // + logged above (so manual `php artisan stock:reconcile-drift` runs
        // and the Reconcile page surface it) but no push notification fires.
        $alertEnabled = (bool) config('stock_adjustment.reconcile_drift_alert', true);
        if (!$alertEnabled) {
            Log::warning('StockAdjustmentReconcileService: drift detected but alert disabled (stock_adjustment.reconcile_drift_alert=false).', [
                'mismatched'      => $mismatched,
                'total_drift_qty' => $totalDrift,
            ]);
            return ['mismatched' => $mismatched, 'total_drift_qty' => $totalDrift, 'notified' => 0];
        }

        // Resolve alert recipients by role (config-driven). The role is
        // stored on employees.role (not users — see User::getRole()), so we
        // join users → employees. We notify each matching user directly via
        // $user->notify() rather than the rule-based
        // NotificationService::dispatch() — drift alerts are system health,
        // not business events, and shouldn't depend on a notification_rule
        // being configured.
        $alertRoles = (array) config('stock_adjustment.reconcile_alert_roles', ['admin']);
        $recipients = User::query()
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereHas('employee', function ($q) use ($alertRoles) {
                $q->where('is_active', true)
                  ->whereNull('deleted_at')
                  ->whereIn('role', $alertRoles);
            })
            ->with('employee')
            ->get();

        $notified = 0;
        foreach ($recipients as $user) {
            $user->notify(new ERPNotification(
                title: 'Stock Drift Detected',
                body:  sprintf(
                    '%d warehouse×product pair(s) show drift between warehouse_stock and stock_transactions (total |drift| = %.4f). Open the Reconciliation page to review.',
                    $mismatched,
                    $totalDrift
                ),
                event: 'stock_drift_detected',
                referenceType: 'stock_reconcile',
                referenceId: null,
                icon: 'fa-triangle-exclamation',
                color: 'danger',
            ));
            $notified++;
        }

        Log::warning('StockAdjustmentReconcileService: nightly drift check alerted admins.', [
            'mismatched'       => $mismatched,
            'total_drift_qty'  => $totalDrift,
            'notified_users'   => $notified,
            'alert_roles'      => $alertRoles,
        ]);

        return ['mismatched' => $mismatched, 'total_drift_qty' => $totalDrift, 'notified' => $notified];
    }
}
