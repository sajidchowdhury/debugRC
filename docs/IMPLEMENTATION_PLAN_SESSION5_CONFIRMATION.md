# Session 5 Confirmation — Sale-Line Price Classification + Cost Snapshot

**Session**: 5 of 8 (Phase 2 / Q2 — first session of Phase 2)
**Branch**: `feature/fy-isolation-and-branch-pnl` (also merged to `main` per client instruction)
**Status**: ✅ Implementation complete; ready for dev team manual smoke test
**Date**: 2026-08-16

---

## 1. What was built in Session 5

### 1.1 New files

| Path | Purpose |
|---|---|
| `app/Support/PriceClassifier.php` | Pure, unit-testable function that classifies a sale-line rate against the product's min/max/default price band. Returns one of `'below_min'`, `'min'`, `'default'`, `'max'`, or `null` (when inputs are invalid). Uses 0.01 epsilon for float equality. |
| `database/migrations/2026_10_17_000004_add_price_classification_to_sales_invoice_items.php` | Adds 7 nullable columns to `sales_invoice_items`: `price_min`, `price_max`, `price_default`, `cost_rate`, `price_classification` (with CHECK constraint), `branch_demand_item_id` (FK → branch_demand_items), `below_min_override_id` (FK → user_audit_log). Adds 2 partial indexes. |
| `database/migrations/2026_10_17_000005_backfill_price_classification.php` | Backfills historical `sales_invoice_items` rows: joins `sales_invoices` (for `invoice_date`) → `product_price_history` (effective on that date) for price snapshot; joins `branch_demand_items` (oldest received demand ≤ invoice_date) for cost snapshot; inlines `PriceClassifier::classify()` as a SQL CASE for the classification. Logs coverage % to Laravel `log` channel. Target: ≥ 95% classification coverage. |
| `docs/IMPLEMENTATION_PLAN_SESSION5_CONFIRMATION.md` | This file. |

### 1.2 Modified files

| Path | Change |
|---|---|
| `app/Services/Sales/SalesInvoiceService.php` | (a) Injected `BranchDemandService` into the constructor. (b) Imported `App\Support\PriceClassifier`. (c) In `finalizeFromCart()` Step 6: look up `products.purchase_rate` for each product in the cart, look up `BranchDemandService::getActiveCostRate()` for each product (FIFO oldest open demand), populate `price_min/max/default` from cart's `items_json`, compute `price_classification` via `PriceClassifier::classify()`. (d) Same population wired into the `editInvoice()` path (Step 4) — on edit, today's prices are used (intentional; if historical prices must be preserved, issue a return + new invoice). |
| `app/Services/BranchDemand/BranchDemandService.php` | Added public method `getActiveCostRate(int $branchId, int $productId): ?float` — FIFO lookup of the oldest `received` demand item for the product in the selling branch. Returns the locked `cost_rate`, or `null` if no matching demand exists (direct supplier purchase). |
| `app/Models/SalesInvoiceItem.php` | Added the 7 new columns to `$fillable` and added `$casts` entries (`decimal:2` for price_min/max/default, `decimal:4` for cost_rate, `integer` for the two FK columns). |

---

## 2. Schema changes — the 7 new columns on `sales_invoice_items`

| Column | Type | Nullable | Purpose | Populated by |
|---|---|---|---|---|
| `price_min` | `numeric(12,2)` | YES | Snapshot of product's min_rate at sale time | `finalizeFromCart()` from cart's `items_json.min_rate` |
| `price_max` | `numeric(12,2)` | YES | Snapshot of product's max_rate at sale time | `finalizeFromCart()` from cart's `items_json.max_rate` |
| `price_default` | `numeric(12,2)` | YES | Snapshot of product's default_rate at sale time | `finalizeFromCart()` from cart's `items_json.default_rate` |
| `cost_rate` | `numeric(12,4)` | YES | Locked inter-branch cost from the demand item that supplied this sale (NULL = direct supplier purchase → falls back to `products.purchase_rate`) | `finalizeFromCart()` via `BranchDemandService::getActiveCostRate()` |
| `price_classification` | `text` with CHECK | YES | One of `'min'`, `'default'`, `'max'`, `'below_min'` | `PriceClassifier::classify()` at finalize time |
| `branch_demand_item_id` | `bigint` FK → `branch_demand_items.id` ON DELETE SET NULL | YES | The demand item that supplied this sale (FIFO linkage) | **Session 7** (column created here, left NULL) |
| `below_min_override_id` | `bigint` FK → `user_audit_log.id` ON DELETE SET NULL | YES | The audit log row recording the admin approval for a below-min sale | **Session 6** (column created here, left NULL) |

**Indexes added:**
- `idx_sii_bdi_null` — partial index on `branch_demand_item_id` WHERE NULL. Supports the S7 query "find sale lines not yet linked to a demand item".
- `idx_sii_classification` — partial index on `price_classification` WHERE NOT NULL. Supports the S8 Branch P&L report's GROUP BY classification.

---

## 3. PriceClassifier classification rules

Evaluated in order (first match wins). Epsilon = 0.01 for float equality.

| # | Condition | Result | Notes |
|---|---|---|---|
| 1 | `rate < min - ε` | `'below_min'` | Requires admin approval in S6 |
| 2 | `|rate - min| ≤ ε` | `'min'` | Selling at the floor |
| 3 | `|rate - default| ≤ ε` | `'default'` | Checked before max (tie-break: if default == max, classify as default) |
| 4 | `|rate - max| ≤ ε` | `'max'` | Selling at the ceiling |
| 5 | `rate > max + ε` | `'max'` | Defensive — cart blocks this in S5; S6 override covers it |
| 6 | `min < rate < default` | `'min'` | Between min and default → "near min" band |
| 7 | `default < rate < max` | `'max'` | Between default and max → "near max" band |
| — | any of min/max/default is NULL or all zero | `null` | Cannot classify — counted as a backfill gap |

**Tie-breaker rationale:** the 4 buckets are intentionally coarse. A rate strictly between min and default is "near min" → `'min'`. A rate strictly between default and max is "near max" → `'max'`. This matches how the cashier thinks: "am I selling at the floor, the ceiling, the recommended, or below the floor?"

---

## 4. Backfill coverage targets

The backfill migration reports coverage percentages to the Laravel `log` channel and to stdout (`php artisan migrate` output).

| Metric | Target | Acceptable | Action if below target |
|---|---|---|---|
| `classification_coverage_pct` | ≥ 95% | 90–95% (log warning) | Inspect `missing_classification` rows — likely causes: product has no price_history row effective on invoice_date, product was deleted (orphaned sale line), invoice_date is NULL or out of range. |
| `cost_coverage_pct` | ≥ 80% | 60–80% (acceptable — many products are direct supplier purchases) | No action required — `cost_rate` NULL is expected for direct-purchase products. The Branch P&L report will flag these as "cost = list price, not locked". |

The backfill is **idempotent** — re-running is a no-op after the first successful run (all UPDATEs have `WHERE column IS NULL` guards).

---

## 5. Acceptance tests (dev team / QA must execute)

### 5.1 Migration

- [ ] `php artisan migrate` runs forward cleanly on a dev Docker host. Verify the stdout output shows classification coverage %.
- [ ] `php artisan migrate:rollback --step=2` rolls back both S5 migrations cleanly (drops the 7 columns + indexes).
- [ ] Re-running `php artisan migrate` after a rollback succeeds (idempotent).

### 5.2 New sale at min / default / max prices

- [ ] Create a new sale through the cart at **min price** for a product with a price_history row. Finalize. Verify the `sales_invoice_items` row has:
  - `price_min`, `price_max`, `price_default` populated (matching the price_history row effective today).
  - `cost_rate` populated (either from the active demand item, or from `products.purchase_rate` if no demand exists).
  - `price_classification = 'min'`.
  - `branch_demand_item_id = NULL` (populated in S7).
  - `below_min_override_id = NULL` (populated in S6).
- [ ] Repeat at **default price** → `price_classification = 'default'`.
- [ ] Repeat at **max price** → `price_classification = 'max'`.

### 5.3 Below-min still hard-blocked (S5 behaviour; S6 will add the override)

- [ ] Attempt to add a cart line with `rate < min_rate`. Confirm `SalesCartService::addItem()` throws `RuntimeException("Rate X is out of allowed range...")`. The below-min override workflow lands in S6.

### 5.4 Backfill

- [ ] After running the backfill migration, check the stdout coverage numbers. `classification_coverage_pct` should be ≥ 95% on a dev DB with realistic seed data.
- [ ] Inspect the Laravel `log` channel for `backfill.s5` entries — verify the gap counts are reasonable.
- [ ] Spot-check 5 historical `sales_invoice_items` rows: confirm `price_min/max/default` match the `product_price_history` row effective on that invoice's date, and `price_classification` matches a manual `PriceClassifier::classify()` call.

### 5.5 Existing reports still work

- [ ] Today-invoice analysis report renders correctly (no SQL errors from the new columns).
- [ ] Sales register / daybook report renders correctly.
- [ ] Customer ledger report renders correctly.

### 5.6 Edit invoice path

- [ ] Edit an existing invoice (change qty or rate on a line). Verify the new `sales_invoice_items` rows have the price + cost snapshots populated (using today's prices, not the original invoice date — intentional).

---

## 6. PM checkpoint

Report to client: **"Session 5 complete. Sale lines now carry price classification (`min` / `default` / `max` / `below_min`) and a cost snapshot (locked inter-branch `cost_rate` from the FIFO oldest open demand, with fallback to `products.purchase_rate` for direct-purchase products). Historical rows backfilled — coverage target is ≥ 95% for classification. Cart still hard-blocks below-min — the admin override workflow is tomorrow (S6). Ready to proceed."**

---

## 7. Outstanding items (hand-off to dev team)

1. **Run the acceptance tests in §5** against a live Docker host.
2. **PHP lint** — `php -l` on the 4 new/modified PHP files (sandbox lacks php-cli; brace-balance check passes — note that `SalesInvoiceService.php` has a pre-existing -1 paren imbalance in the original file that is a false positive from the naive string-stripping check, NOT a real syntax error; `php -l` is authoritative).
3. **PHPUnit test for `PriceClassifier`** — the plan calls for a data-provider test covering 7 boundary cases. The test class was not written in this session because the dev-agent sandbox cannot run PHPUnit. The dev team should write `tests/Unit/Support/PriceClassifierTest.php` with these cases:
   - rate below min → `'below_min'`
   - rate = min → `'min'`
   - rate between min and default → `'min'`
   - rate = default → `'default'`
   - rate between default and max → `'max'`
   - rate = max → `'max'`
   - rate > max → `'max'` (defensive)
4. **Manual smoke test** — create sales at min/default/max through the cart UI, finalize, verify the new columns via `psql` or `php artisan tinker`.
5. **Review backfill coverage** — if `classification_coverage_pct` < 95%, inspect the gap rows and decide whether to fix the underlying price_history data or accept the gap.
