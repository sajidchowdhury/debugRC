# Stock Verification

> **Module:** Inventory (Phase 8)
> **Audience:** Engineers + AI assistants + **accountants** (must review before Canonical)
> **Status:** Draft — pending accountant sign-off
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Stock/StockAdjustmentReconcileService.php` (drift detection + rebuild) + `laravel/app/Services/Accounting/ReconciliationService.php:364-415` (Inventory + COGS recon) + `laravel/app/Console/Commands/ReconcileStockDrift.php` + `laravel/app/Console/Commands/StockReplayVerify.php`

## 1. What is it?

**Stock verification** in RC_ERP_v2 is **NOT a single discrete feature** — there is no
`StockVerification*` service, model, controller, or route. Instead, the concept is distributed
across THREE distinct mechanisms that together provide end-to-end stock integrity assurance:

1. **Stock Take** — the physical count + variance verification flow (see `stock-take.md`).
2. **Reconciliation reports** — GL vs sub-ledger + snapshot vs ledger drift detection.
3. **Periodic verification jobs / artisan commands** — nightly drift detection + full replay
   verification.

This file documents the **distributed** nature of stock verification and cross-references the
specific mechanisms.

## 2. Why does it exist?

- Stock integrity is a three-layer concern: (a) physical reality vs system record (Stock Take),
  (b) GL inventory balance vs stock ledger valuation (Reconciliation), (c) stock ledger vs
  warehouse_stock snapshot (drift detection). Each layer catches a different class of error.
- A single "Stock Verification" feature would conflate these concerns. The distributed design
  lets each mechanism run independently at its own cadence (stock take = monthly, recon =
  daily, drift = nightly).
- The `stock:replay-verify` command is the **ultimate** verification — it replays the entire
  `stock_transactions` history through the avg-cost logic and compares against `warehouse_stock`.
  Zero drift is the acceptance criterion for the moving-average costing implementation (per
  `docs/migration/avg_cost_rule.md §6`).

## 3. When is it used?

- **Stock Take** — monthly full count, ABC-cycle count, negative-only/zero-only count, ad-hoc
  count (see `stock-take.md` §3).
- **Reconciliation reports** — daily/monthly close, audit response (see
  `../accounting/subledger-reconciliation.md`).
- **`stock:reconcile-drift`** — nightly drift-detection job, scheduled at 03:00 in
  `routes/console.php`.
- **`stock:replay-verify`** — on-demand full replay verification (run before/after major data
  migrations, or when drift is suspected).
- **`StockAdjustmentReconcileService::rebuildSnapshot()`** — admin-only repair path when drift
  is detected.

## 4. Who uses it?

- **Accountants** run the reconciliation reports (Inventory + COGS sections).
- **Admins** receive drift alerts (configurable via `stock_adjustment.reconcile_alert_roles`) and
  run `rebuildSnapshot` when needed.
- **The system** runs `stock:reconcile-drift` nightly via the scheduler.
- **Engineers / AI assistants** run `stock:replay-verify` during development + data migrations.

## 5. Related modules

- `stock-take.md` — the physical count + variance verification flow (Mechanism 1).
- `stock-costing.md` — the moving-average formula that `stock:replay-verify` exercises.
- `stock-ledger.md` — the `stock_transactions` SSOT that all verification compares against.
- `warehouse-stock.md` — the derived snapshot that drift detection compares against the ledger.
- `stock-adjustment.md` — the `reconciliation_variance` category for booking residual drift.
- `../accounting/subledger-reconciliation.md` §5 (Inventory) + §6 (COGS) — the GL vs sub-ledger
  recon formulas (Mechanism 2).
- `../accounting/financial-audit-log.md` §12 — scope gap (inventory tables NOT in
  `fn_financial_audit_trigger`).

## 6. Business rules (the Core Rule)

- **MUST** verify stock integrity at three layers: physical vs system (Stock Take), GL vs
  sub-ledger (Reconciliation), snapshot vs ledger (drift detection).
- **MUST** run `stock:reconcile-drift` nightly (scheduled at 03:00) to detect drift between
  `warehouse_stock` and `stock_transactions`.
- **MUST** alert admins (configurable via `stock_adjustment.reconcile_alert_roles`) when drift is
  detected.
- **MUST** support `StockAdjustmentReconcileService::rebuildSnapshot()` as the admin-only repair
  path — DELETEs + re-INSERTs `warehouse_stock` from the `stock_transactions` ledger (the SSOT),
  wrapped in a single DB transaction.
- **MUST** support `stock:replay-verify` as the ultimate verification — replays every
  `stock_transactions` row through the avg-cost logic, compares against `warehouse_stock`, logs
  drift to `avg_cost_drift` table. Exit code 0 = PASS (zero drift), 1 = FAIL.
- **MUST NOT** auto-write-off drift — admin must run `rebuildSnapshot` manually after
  investigation.
- **MUST** treat `warehouse_stock` as a **derived** snapshot — the SSOT is `stock_transactions`.
  If the snapshot drifts, it can always be rebuilt from the ledger.

## 7. Technical implementation

### 7.1 Mechanism 1 — Stock Take (physical verification)

See `stock-take.md` for full details. The stock take module IS the physical verification flow:
setup → physical count → variance computation → approval → posting (with GL impact) → reversal
(if needed).

`StockTakeService::reconcileSnapshotWithLiveStock()` at L2777 — within `postSession`, reconciles
the setup-time snapshot (`system_qty` on `stock_take_items`) against the LIVE `warehouse_stock.qty`
for every counted product. If stock moved between setup and post, the drift is recorded in the
audit payload (warning, not a block).

### 7.2 Mechanism 2 — Reconciliation reports (GL vs sub-ledger)

#### 7.2.1 `ReconciliationService::reconcileInventory()` — `ReconciliationService.php:364-415`

Already documented in `../accounting/subledger-reconciliation.md` §5. Compares
`warehouse_stock.stock_value` (qty × avg_cost, qty > 0) against the GL `inventory` ledger
balance. Drill-down: top 10 products by stock value when variance > tolerance.

#### 7.2.2 `ReconciliationService::reconcileCOGS()` — `ReconciliationService.php:430`

Already documented in `../accounting/subledger-reconciliation.md` §6. Compares GL `cogs` ledger
balance against `stock_transactions` (reference_type=sales_challan MINUS sales_return).

#### 7.2.3 `StockAdjustmentReconcileService::computeDrift()` — `StockAdjustmentReconcileService.php:87-165`

The fundamental stock ledger invariant:

```
warehouse_stock.qty == SUM(stock_transactions.qty) FILTER (WHERE NOT is_reversed
                                                            AND warehouse_id = ws.warehouse_id
                                                            AND product_id = ws.product_id)
```

SQL uses `LEFT JOIN` on a pre-aggregated ledger subquery + `FILTER (WHERE NOT is_reversed)`
(idiomatic PG). Branch-scoped when `$branchId` is passed; all-tenant when null.

#### 7.2.4 `StockAdjustmentReconcileService::rebuildSnapshot()` — `StockAdjustmentReconcileService.php:191`

The REPAIR path. Admin-only. DELETE + INSERT `warehouse_stock` from the `stock_transactions`
ledger (the SSOT). Wrapped in a single DB transaction so the table is never empty to concurrent
readers.

### 7.3 Mechanism 3 — Periodic verification jobs / artisan commands

#### 7.3.1 `stock:reconcile-drift` — `laravel/app/Console/Commands/ReconcileStockDrift.php`

Nightly drift-detection job. Scheduled at 03:00 in `routes/console.php`. Calls
`StockAdjustmentReconcileService::computeDrift()` and fires `ERPNotification` to admins
(configurable via `stock_adjustment.reconcile_alert_roles`) when drift is detected.

Options:
- `--dry-run` — reports without notifying.
- `--branch=N` — scopes to one branch.

#### 7.3.2 `stock:replay-verify` — `laravel/app/Console/Commands/StockReplayVerify.php`

The ULTIMATE verification. Replays ALL `stock_transactions` through the avg-cost logic, compares
to live `warehouse_stock`, logs drift to `avg_cost_drift` table. Zero drift required for sign-off
(acceptance criteria in `docs/migration/avg_cost_rule.md §6`).

Options:
- `--limit=N` — process only N rows.
- `--from-id=N` — start from a specific transaction id.
- `--product=N` — filter to one product.
- `--keep-drift` — persist drift rows to `avg_cost_drift` table.

Exit code: 0 = PASS (zero drift), 1 = FAIL (drift detected).

#### 7.3.3 `stock:manual-verify` — `laravel/app/Console/Commands/StockManualVerify.php`

Manual verification command (referenced from `StockReplayVerify` output). Interactive prompt to
investigate specific drift rows.

### 7.4 Materialized views — the "verification" snapshot

#### 7.4.1 `mv_stock_valuation` — migration `2025_01_03_000001_create_report_materialized_views.php:127`

Per-warehouse `qty × avg_cost` with product + branch enrichment. `WHERE qty > 0`. UNIQUE index on
`(warehouse_id, product_id)` for `REFRESH CONCURRENTLY`. See `stock-costing.md` §7.8 for the
full DDL.

#### 7.4.2 `mv_product_abc_classification` — `03_stock.sql:587-638`

Per-warehouse ABC ranking. Refreshed nightly by pg_cron job `refresh-abc-classification` via
`REFRESH MATERIALIZED VIEW CONCURRENTLY`. ABC thresholds (default A=80%, B=95%) + lookback days
(default 365) are policy-driven.

These MVs are the verification snapshot — they expose the current stock valuation for
reconciliation against the GL.

### 7.5 Drift detection + investigation flow

#### 7.5.1 `avg_cost_drift` table — migration `2025_01_04_000002_create_avg_cost_drift_tables.php:31`

Logs every `(warehouse_id, product_id)` where `stock:replay-verify` found drift. Columns include
`qty_drift`, `cost_drift`, `status` (open/investigated/resolved), `investigation_notes`,
`resolved_at`.

#### 7.5.2 `warehouse_stock_shadow` table — same migration

Persistent copy of the replay result. Used for diff against the live `warehouse_stock` to detect
new drift since the last replay.

#### 7.5.3 Drift viewer UI

`admin/stock/drift` (GET) lists drift rows; `admin/stock/drift/{id}` (POST updateDrift) updates
status + notes. (`StockTransactionController:215-276`).

### 7.6 Report format

- `StockTakeVarianceReport` (`laravel/app/Services/Stock/StockTakeVarianceReport.php`, 229 lines)
  — per-session variance report.
- `StockTakeWeeklyReport` (`laravel/app/Services/Stock/StockTakeWeeklyReport.php`, 195 lines) —
  weekly stock take activity.
- `WarehouseTransferSummaryReport` (287 lines) — transfer summary.
- `DamageReportService` (`laravel/app/Services/Reports/DamageReportService.php`).
- Routes: `admin/reports/stocktake-variance`, `admin/reports/stocktake-weekly`,
  `admin/reports/product-stock-analysis`, `admin/warehouse-transfers/summary`.

## 8. Intercompany / cross-branch

Stock verification is branch-scoped (the `computeDrift` method accepts a `$branchId` parameter).
The `stock:reconcile-drift --branch=N` option scopes to one branch. All-tenant verification (null
`$branchId`) is supported for admin/superadmin.

## 9. Workflow / state machine

Stock verification has no single state machine — each mechanism has its own:

- **Stock Take** — 7-state machine (see `stock-take.md` §9).
- **Drift detection** — `open` → `investigated` → `resolved` (on `avg_cost_drift.status`).
- **Reconciliation** — point-in-time report (no state machine; the recon is recomputed on each
  run).

## 10. Validation & input rules

- `stock:reconcile-drift` options: `--dry-run` (boolean), `--branch=N` (integer).
- `stock:replay-verify` options: `--limit=N` (integer), `--from-id=N` (integer), `--product=N`
  (integer), `--keep-drift` (boolean).
- `StockAdjustmentReconcileService::rebuildSnapshot()` — admin-only, no input validation (operates
  on the whole table or a scoped branch).
- Drift tolerance: `config('stock_adjustment.reconcile_tolerance', 0.0001)` — matches the DB
  CHECK constraint. Any `|drift| <= tolerance` is treated as zero (rounding noise).

## 11. Reversal & correction flow

Stock verification itself is read-only (it detects + reports drift). The **correction** paths are:

1. **`StockAdjustmentReconcileService::rebuildSnapshot()`** — admin-only. Rebuilds
   `warehouse_stock` from the `stock_transactions` ledger. Use when the snapshot is wrong but the
   ledger is right.
2. **Stock Adjustment (`reconciliation_variance` category)** — books the residual variance as a
   GL-posted adjustment. Use when the ledger is wrong (e.g. a missed movement) and the snapshot
   is right.
3. **Stock Take** — the full physical count + variance posting. Use when both the snapshot AND
   the ledger are suspected wrong (physical reality is the arbiter).

The choice of correction path depends on which layer is wrong:
- Snapshot wrong, ledger right → `rebuildSnapshot`.
- Ledger wrong, snapshot right → Stock Adjustment (`reconciliation_variance`).
- Both wrong → Stock Take (physical count).

## 12. Open questions / known gaps

1. **NO `StockVerification*` feature** (§1) — the concept is distributed across Stock Take +
   Reconciliation + console commands. **Recommended:** either document this distribution clearly
   (this file does that), or create a unifying `StockVerificationService` facade that aggregates
   the three mechanisms into a single "verification status" dashboard.
2. **NO `stock:verify` command** (§7.3) — the closest is `stock:reconcile-drift` (drift
   detection) and `stock:replay-verify` (full replay). **Recommended:** add a `stock:verify`
   umbrella command that runs all three mechanisms and produces a consolidated report.
3. **`mv_stock_valuation` excludes `qty <= 0` rows** (§7.4.1) — zero-qty + negative-qty
   warehouses are invisible in the MV. The underlying `warehouse_stock` rows exist; the MV is a
   reporting convenience that hides them. Cosmetic gap (carried from `stock-costing.md` §12 #2).
4. **NO automated drift write-off** (§11) — admin must run `rebuildSnapshot` manually when
   `stock:reconcile-drift` detects drift. **Recommended:** consider an auto-rebuild option for
   drift below a threshold (with audit log).
5. **`avg_cost_drift` table not in `financial_audit_log` hash chain** — drift investigation notes
   are mutable (admin can update `status` + `investigation_notes`). The `user_audit_log` covers
   these changes via the `AuditableMasterData` trait (if applied). **Recommended:** confirm the
   trait is applied to the `AvgCostDrift` model.
6. **`warehouse_stock_shadow` table refresh cadence** — the shadow table is a persistent copy of
   the replay result. It is NOT automatically refreshed — admin must run `stock:replay-verify
   --keep-drift` to update it. **Recommended:** document the refresh cadence (e.g. weekly) or
   automate via scheduler.
7. **No `inventory:reconcile` command** — the reconciliation reports are web-only
   (`admin/stock-adjustments/reconcile`). **Recommended:** add an artisan command for headless
   recon (useful for CI/CD + monitoring).
8. **Drift tolerance `0.0001`** (§10) — matches the DB CHECK constraint. Any `|drift| <= tolerance`
   is treated as zero (rounding noise). **Accountant must confirm this tolerance is appropriate**
   for the business's materiality threshold.

## 13. Accountant review checklist

> **This is a SAFETY-CRITICAL document.** Before marking it Canonical, an accountant with
> production credentials MUST review and sign off on each item below.

- [ ] The distributed nature of stock verification (§1) — Stock Take + Reconciliation + console
      commands — is clearly documented and understood. No single "Stock Verification" feature
      exists.
- [ ] The three-layer verification model (§2) — physical vs system, GL vs sub-ledger, snapshot vs
      ledger — covers all stock integrity concerns.
- [ ] The nightly `stock:reconcile-drift` job (§7.3.1) is scheduled and alerts are received by
      the right roles.
- [ ] The `stock:replay-verify` command (§7.3.2) — zero drift required for sign-off — is the
      acceptance criterion for the moving-average costing implementation.
- [ ] The `rebuildSnapshot` repair path (§11) — admin-only, rebuilds from the ledger SSOT — is
      the correct correction when the snapshot is wrong but the ledger is right.
- [ ] The Stock Adjustment `reconciliation_variance` category (§11) — books the residual variance
      as a GL-posted adjustment — is the correct correction when the ledger is wrong.
- [ ] The Stock Take (§11) — full physical count — is the correct correction when both snapshot
      and ledger are suspected wrong.
- [ ] The drift tolerance `0.0001` (§12 #8) — is this appropriate for the business's materiality
      threshold?
- [ ] The lack of automated drift write-off (§12 #4) — is manual `rebuildSnapshot` acceptable, or
      should auto-rebuild be added for drift below a threshold?
- [ ] The `mv_stock_valuation` exclusion of `qty <= 0` rows (§12 #3) — is this acceptable for
      reporting?
- [ ] The lack of `stock:verify` umbrella command (§12 #2) — should a consolidated verification
      command be added?
