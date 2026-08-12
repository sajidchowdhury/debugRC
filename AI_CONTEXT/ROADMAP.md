# ROADMAP — RC_ERP_v2 (Remote Center ERP)

> **Module:** Roadmap / Future planning
> **Audience:** Engineers, AI assistants, product owners, accountants, stakeholders
> **Status:** Living document — update as priorities change.
> **Last reviewed:** Phase 21 (initial creation)
> **Source of truth:** This file is the canonical forward-looking roadmap. It complements:
  - [`./PROJECT_OVERVIEW.md`](./PROJECT_OVERVIEW.md) §11 Known Limitations + §12 Future
    Improvements (summary),
  - [`./changelog/PRODUCT_CHANGELOG.md`](./changelog/PRODUCT_CHANGELOG.md) (backward-looking
    product history),
  - [`./IMPLEMENTATION_PLAN.md`](./IMPLEMENTATION_PLAN.md) (the AI_CONTEXT documentation
    roadmap — separate from this product roadmap).

---

## 1. How to read this roadmap

This roadmap covers the **product** future of RC_ERP_v2 — what will be built next, in what
order, and why. It is organized into **horizons** (not strict calendar quarters) because
the team's capacity and the VPS cutover timing are the binding constraints, not dates.

### 1.1 Horizon definitions

| Horizon | Meaning | Indicative timeline |
|---|---|---|
| **H1 — Cutover** | Get the code-complete app into production on the BDIX VPS. | Next 2–4 weeks |
| **H2 — Stabilize** | Fix the cross-cutting gaps that surface in production. | 1–3 months post-cutover |
| **H3 — Extend** | Add the AI sidecar and operational enhancements. | 3–9 months post-cutover |
| **H4 — Scale** | Multi-currency, portals, manufacturing (if business requires). | 9–18 months post-cutover |

### 1.2 Prioritization principles

1. **Safety-critical first.** Anything that affects GL integrity, audit trails, or branch
   isolation is fixed before any new feature.
2. **Cutover-blocking second.** Anything that blocks the VPS cutover (e.g. legacy MySQL
   read-only enforcement, production credential rotation) is next.
3. **High-leverage third.** The AI sidecar (Phase 13) is the highest-leverage new feature —
   it unlocks report chatbot, demand forecasting, invoice OCR, and anomaly detection in
   one effort.
4. **Polish last.** Operational enhancements (back-order workflow, partial GRN, three-way
   match, portals) are important but not urgent.

### 1.3 Dependencies

Every roadmap item lists its dependencies. An item cannot start until its dependencies are
met. Dependencies reference either:
- Product phases (e.g. "Phase 1 — VPS BDIX Provisioning"),
- `AI_CONTEXT/` files (e.g. `workflows/notification-workflow.md` §13),
- External constraints (e.g. "BDIX VPS provisioned").

---

## 2. Horizon 1 — Cutover (H1)

The goal of H1 is to get the code-complete Laravel app running in production on the BDIX
VPS, with the legacy MySQL set to read-only.

### 2.1 H1.1 — Provision BDIX VPS (Phase 1, manual)

- **Status:** ⬜ Pending (manual — needs VPS hardware)
- **Owner:** Operations + DevOps
- **Dependencies:** None (this is the first step).
- **Tasks:**
  1. Provision Ubuntu 22.04 VPS on BDIX (Bangladesh Internet Exchange) for low-latency
     local access.
  2. Install PHP 8.3 + PostgreSQL 16 + Redis + Nginx.
  3. Configure firewall (ufw): allow 22 (SSH), 80/443 (HTTP/HTTPS) only.
  4. Create deploy user (non-root) with sudo.
  5. Set up SSH key-only auth; disable password auth.
- **Verification:** `php -v` returns 8.3; `psql --version` returns 16; `redis-cli ping`
  returns PONG; `nginx -t` returns OK.
- **Reference:** [`./deployment/vps-bdix-deployment.md`](./deployment/vps-bdix-deployment.md).

### 2.2 H1.2 — Production credential rotation (manual, security)

- **Status:** ⬜ Pending
- **Owner:** Security + Operations
- **Dependencies:** H1.1 (VPS provisioned).
- **Tasks:**
  1. Reset all production user passwords (bcrypt hashes were in a public SQL dump).
  2. Generate new `APP_KEY` (rotate).
  3. Generate new DB credentials (new password, restricted user).
  4. Generate new Redis password.
  5. Generate new API auth signing key.
  6. Set production `.env` with new credentials (chmod 600, never committed).
  7. Delete or make-private the old public repo `sajidchowdhury/RC_ERP`.
  8. Delete or make-private the public repo `sajidchowdhury/RC_ERP_Laravel`.
- **Verification:** `php artisan config:cache` succeeds; no `APP_KEY=...` in git history;
  old repos return 404.
- **Reference:** [`./deployment/environment.md`](./deployment/environment.md),
  [`./security/credential-versioning.md`](./security/credential-versioning.md).

### 2.3 H1.3 — Database migration on VPS

- **Status:** ⬜ Pending
- **Owner:** DevOps + Database admin
- **Dependencies:** H1.1, H1.2.
- **Tasks:**
  1. Restore PostgreSQL schema from `laravel/database/sql/01–07_*.sql`.
  2. Run `php artisan migrate` (applies the 160 migrations).
  3. Run `php artisan chart:seed` (seeds the chart of accounts).
  4. Run `php artisan migrate:master-data` (seeds branches, warehouses, suppliers,
     customers, products, employees, users).
  5. Run the ETL pipeline (`pgloader` config + 14 post-load fixes + sequence sync) to
     migrate legacy MySQL data. See [`./database/etl-legacy-migration.md`](./database/etl-legacy-migration.md).
- **Verification:**
  - `php artisan chart:validate` passes.
  - `php artisan stock:replay-verify` passes (zero drift).
  - `php artisan journal:replay-verify` passes (Dr=Cr globally).
  - `php artisan subledger:reconcile-ar --branch={id}` passes for all branches.
  - `php artisan subledger:reconcile-ap --branch={id}` passes for all branches.
  - `php artisan subledger:reconcile-inventory --branch={id}` passes for all branches.
- **Reference:** [`./database/etl-legacy-migration.md`](./database/etl-legacy-migration.md),
  [`./deployment/artisan-commands.md`](./deployment/artisan-commands.md).

### 2.4 H1.4 — Nginx + HTTPS configuration

- **Status:** ⬜ Pending
- **Owner:** DevOps
- **Dependencies:** H1.1, H1.3.
- **Tasks:**
  1. Configure Nginx as reverse proxy to PHP-FPM (see
     [`./deployment/nginx-config.md`](./deployment/nginx-config.md)).
  2. Obtain SSL certificate (Let's Encrypt or BDIX-issued).
  3. Configure HTTPS redirect (HTTP → HTTPS).
  4. Configure HSTS, security headers.
  5. Configure SSE endpoint (`/sse/status`) with long timeout + no buffering.
- **Verification:** `curl -I https://{domain}/` returns 200; SSL Labs grade A+.
- **Reference:** [`./deployment/nginx-config.md`](./deployment/nginx-config.md).

### 2.5 H1.5 — Cron + scheduler setup

- **Status:** ⬜ Pending
- **Owner:** DevOps
- **Dependencies:** H1.3.
- **Tasks:**
  1. Add Laravel scheduler cron entry: `* * * * * cd /var/www/rcerp && php artisan schedule:run >> /dev/null 2>&1`.
  2. Enable pg_cron extension (already in schema).
  3. Verify the 5 pg_cron jobs + 6 Laravel scheduler jobs are firing (see
     [`./deployment/cron-scheduled-jobs.md`](./deployment/cron-scheduled-jobs.md)).
- **Verification:** `SELECT * FROM cron.job;` returns 5 rows; `php artisan schedule:list`
  returns 6 entries; logs show each job firing on schedule.
- **Reference:** [`./deployment/cron-scheduled-jobs.md`](./deployment/cron-scheduled-jobs.md).

### 2.6 H1.6 — Legacy MySQL read-only enforcement

- **Status:** ⬜ Pending
- **Owner:** Database admin
- **Dependencies:** H1.3 (ETL complete).
- **Tasks:**
  1. Revoke WRITE privileges from the legacy MySQL user.
  2. Verify the Anti-Corruption Layer still works (read-only historical search). See
     [`./archive/legacy-read-only.md`](./archive/legacy-read-only.md).
  3. Set up MySQL backup (daily, read-only data doesn't change).
- **Verification:** Attempting an INSERT on legacy MySQL returns "permission denied";
  Anti-Corruption Layer queries still return historical data.
- **Reference:** [`./archive/legacy-read-only.md`](./archive/legacy-read-only.md),
  [`./archive/anti-corruption-layer.md`](./archive/anti-corruption-layer.md).

### 2.7 H1.7 — Go-live checklist

- **Status:** ⬜ Pending
- **Owner:** Operations + Accountant
- **Dependencies:** H1.1–H1.6.
- **Tasks:** Walk through the full go-live checklist in
  [`./deployment/go-live-checklist.md`](./deployment/go-live-checklist.md).
- **Verification:** All checklist items ticked; accountant signs off.
- **Reference:** [`./deployment/go-live-checklist.md`](./deployment/go-live-checklist.md).

---

## 3. Horizon 3 — AI Sidecar (H3, Phase 13)

The AI sidecar is the highest-leverage new feature. It is a **Python FastAPI** service that
runs alongside the Laravel app and provides four AI-powered capabilities. It is deferred to
H3 because it depends on the production deployment (H1) and stabilization (H2).

> **Note on the sidecar architecture:** The sidecar is a **separate service**, NOT a
> Laravel package. It connects to the same PostgreSQL database (read-only for analytics,
> read-write only for forecast/anomaly tables). It exposes a REST API consumed by the
> Laravel app (server-to-server, authenticated via a shared secret). The Laravel app's UI
> adds chatbot/forecast/OCR/anomaly views that call the sidecar.

### 3.1 Sidecar foundation

- **Status:** ⬜ Pending
- **Owner:** AI/ML engineer + Backend engineer
- **Dependencies:** H1 (production deployment).
- **Tasks:**
  1. Scaffold a Python FastAPI service (in a new `ai-sidecar/` folder at repo root, NOT
     inside `laravel/`).
  2. Set up a separate port (e.g. 8000) — the gateway will forward via `?XTransformPort=8000`.
  3. Connect to PostgreSQL (read-only user for analytics).
  4. Set up shared-secret auth (Laravel → sidecar).
  5. Set up logging + health check (`/health`).
  6. Containerize (Docker) — add to `docker-compose.yml`.
- **Verification:** `curl http://localhost:8000/health` returns 200; Laravel can call the
  sidecar via the gateway.

### 3.2 Report chatbot

- **Status:** ⬜ Pending
- **Owner:** AI/ML engineer
- **Dependencies:** §3.1 (sidecar foundation).
- **Description:** A natural-language chatbot that lets an accountant ask questions like
  "show me the top 10 customers by AR balance this month" or "what was the COGS for
  branch X in Q3?" and get a formatted report.
- **Architecture:**
  1. User types a question in a Laravel Blade view (`/admin/ai/chatbot`).
  2. Laravel calls the sidecar: `POST /api/v1/chatbot/query` with the question + branch
     context + user role.
  3. The sidecar uses an LLM (e.g. GPT-4 or a local model) to translate the question into
     a SQL query against the ERP's reporting MVs/CTE views.
  4. The sidecar executes the SQL (read-only, branch-isolated via `app.branch_id` GUC).
  5. The sidecar returns the result rows + a natural-language summary.
  6. Laravel renders the result as a table + summary in the Blade view.
- **Safety constraints:**
  - **Read-only.** The sidecar MUST NOT write to the database. The read-only user enforces
    this at the DB level.
  - **Branch-isolated.** The sidecar MUST set `app.branch_id` GUC before every query.
  - **Role-scoped.** The sidecar MUST NOT expose admin-only data to non-admin users.
  - **No raw user input in SQL.** The LLM generates SQL, but the sidecar MUST validate the
    generated SQL is a SELECT (no INSERT/UPDATE/DELETE/DROP).
- **Dependencies (data):** The reporting MVs (7 MVs) and CTE views must be fresh — see
  [`./reports/materialized-views.md`](./reports/materialized-views.md).
- **Reference:** [`./reports/reports-catalog.md`](./reports/reports-catalog.md),
  [`./reports/cte-reports.md`](./reports/cte-reports.md).

### 3.3 Demand forecasting

- **Status:** ⬜ Pending
- **Owner:** AI/ML engineer
- **Dependencies:** §3.1 (sidecar foundation); 6+ months of production sales data.
- **Description:** A per-SKU, per-branch demand forecast that predicts next-month sales
  based on historical sales. Used by the purchasing module to suggest PO quantities.
- **Architecture:**
  1. A scheduled job (sidecar cron) runs nightly: for each (SKU, branch) with >12 months
     of sales history, fit a forecasting model (e.g. Prophet, ARIMA, or a simple
     exponential smoothing baseline).
  2. Store the forecast in a new `demand_forecasts` table (per-SKU, per-branch, per-month,
     with confidence interval).
  3. The Laravel purchase module reads the forecast when suggesting PO quantities:
     `suggested_qty = forecast_qty - current_stock - pending_grn_qty`.
  4. A Laravel Blade view (`/admin/ai/forecast`) shows the forecast vs actuals chart.
- **Safety constraints:**
  - **Advisory only.** The forecast is a suggestion; the purchaser can override.
  - **No GL impact.** The forecast does not post anything; it only suggests PO quantities.
- **Dependencies (data):** 6+ months of `sales_invoice_items` history (partitioned).
- **Reference:** [`./purchasing/purchase-order.md`](./purchasing/purchase-order.md),
  [`./inventory/warehouse-stock.md`](./inventory/warehouse-stock.md).

### 3.4 Invoice OCR

- **Status:** ⬜ Pending
- **Owner:** AI/ML engineer
- **Dependencies:** §3.1 (sidecar foundation); VLM (Vision Language Model) skill.
- **Description:** A supplier-invoice OCR pipeline that takes a PDF or image of a supplier
  invoice, extracts the line items (product code, quantity, unit price), and creates a
  draft GRN in the Laravel app.
- **Architecture:**
  1. User uploads a supplier invoice PDF/image in a Laravel Blade view
     (`/admin/ai/ocr`).
  2. Laravel stores the file and calls the sidecar: `POST /api/v1/ocr/extract` with the
     file path + supplier context.
  3. The sidecar uses a VLM (e.g. GPT-4 Vision or a local model) to extract:
     supplier name, invoice date, line items (product code, quantity, unit price).
  4. The sidecar matches each product code against the Laravel `products` table (via the
     Laravel API or direct DB read).
  5. The sidecar returns a draft GRN payload (JSON).
  6. Laravel creates a draft GRN (status=draft, no GL post) and redirects the user to the
     GRN edit page for review + confirm.
- **Safety constraints:**
  - **Draft only.** The OCR creates a DRAFT GRN — the user MUST review and confirm. The
    GL post happens only on confirm (via `PurchaseReceiveService::confirm()`).
  - **Product-code matching.** The OCR must NOT auto-create products; if a product code
    doesn't match, the line is flagged for manual entry.
  - **No price override.** The OCR-extracted unit_price is a suggestion; the user can edit.
- **Dependencies (data):** The `products` table (product codes for matching).
- **Reference:** [`./purchasing/purchase-receive.md`](./purchasing/purchase-receive.md),
  VLM skill (for the vision-language model backend).

### 3.5 Anomaly detection

- **Status:** ⬜ Pending
- **Owner:** AI/ML engineer
- **Dependencies:** §3.1 (sidecar foundation); 3+ months of production data.
- **Description:** An anomaly-detection pipeline that scans GL entries, stock
  transactions, and payments for unusual patterns (e.g. a journal entry with an unusually
  large amount, a stock adjustment that zeroes out a SKU, a payment to a supplier with no
  matching GRN).
- **Architecture:**
  1. A scheduled job (sidecar cron) runs nightly: scans the day's GL entries, stock
     transactions, and payments.
  2. For each, computes an anomaly score (e.g. z-score of amount vs historical distribution
     for that ledger/warehouse/supplier).
  3. High-anomaly entries are written to a new `anomaly_flags` table (with reference_type,
     reference_id, anomaly_score, reason).
  4. A Laravel Blade view (`/admin/ai/anomalies`) shows the flagged entries; the
     accountant can dismiss or investigate.
  5. Optionally, high-severity anomalies trigger a notification via the existing
     `NotificationService::dispatch('anomaly_detected', ...)`.
- **Safety constraints:**
  - **Advisory only.** Anomaly flags do not block transactions; they are for review.
  - **No GL impact.** The anomaly detection does not post anything.
  - **Privacy.** The anomaly reason MUST NOT include PII (e.g. customer name) in the
    notification — only the reference_type + reference_id.
- **Dependencies (data):** 3+ months of `journal_entries` + `stock_transactions` +
  `customer_payments` + `supplier_payments` history.
- **Reference:** [`./accounting/financial-audit-log.md`](./accounting/financial-audit-log.md),
  [`./security/audit-trails.md`](./security/audit-trails.md),
  [`./workflows/notification-workflow.md`](./workflows/notification-workflow.md) (for the
  dispatch integration).

---

## 4. Horizon 2 — Stabilize (H2)

H2 is the post-cutover stabilization period. The goal is to fix the cross-cutting gaps
that surface in production. These are documented in
[`./PROJECT_OVERVIEW.md`](./PROJECT_OVERVIEW.md) §11.3 and are reproduced here with
remediation plans.

### 4.1 H2.1 — Notification system G1/G2/G3 fix

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** H1 (production deployment, to observe the duplicates in production).
- **Tasks:**
  1. Remove the 5 `CHANNEL_EVENT_MAP` entries in `ListenNotifyService` that cause G1
     (double-dispatch).
  2. Fix the static `CHANNEL_EVENT_MAP` mapping for `sales_returns` UPDATE to fire
     `return_confirmed` / `return_reversed` instead of `return_created` (G2).
  3. Pass the full `$context` array in
     `ListenNotifyService::forwardToNotificationService()` (G3).
- **Verification:** Admins receive exactly one toast per event; context-aware recipients
  (`salesman_of_invoice`, `warehouse_manager_of_branch`) receive the toast on both the
  direct-dispatch and worker-forwarded paths.
- **Reference:** [`./workflows/notification-workflow.md`](./workflows/notification-workflow.md)
  §13 G1–G3.

### 4.2 H2.2 — Activate dead intercompany settlement methods

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None (now that `banks.branch_id` exists).
- **Tasks:**
  1. `CustomerPaymentService::postIntercompanySettlement` (L772) — remove the `return
     null;` at L780 and implement the cross-branch AR settlement.
  2. `SupplierTransactionService::postIntercompanySettlement` (L616) — same.
  3. `WarehouseTransferService::postIntercompanyGL` (L531) — wire it into `confirm()` so
     cross-branch transfers post the two-JE intercompany entry.
- **Verification:** Cross-branch customer payment / supplier payment / warehouse transfer
  each post TWO journal entries (one per branch) that are mirror images. The
  `subledger:reconcile-ar` and `subledger:reconcile-ap` commands pass for both branches.
- **Reference:** [`./accounting/customer-payments.md`](./accounting/customer-payments.md) §8,
  [`./accounting/supplier-transactions.md`](./accounting/supplier-transactions.md) §8,
  [`./inventory/warehouse-transfer.md`](./inventory/warehouse-transfer.md) §7.3 + §8,
  [`./workflows/inventory-to-gl.md`](./workflows/inventory-to-gl.md) §12.1.

### 4.3 H2.3 — Avg-cost snapshot backfill

- **Status:** ⬜ Pending
- **Owner:** Database admin + Backend engineer
- **Dependencies:** H1.3 (ETL complete).
- **Tasks:**
  1. Write a one-time migration that backfills `stock_transactions.unit_cost` for legacy
     rows (using the `warehouse_stock.avg_cost` at the time of the original movement, which
     can be reconstructed by replaying stock_transactions in order).
  2. Backfill `sales_invoice_items.avg_cost_snapshot` from the linked
     `stock_transactions.unit_cost`.
- **Verification:** `SELECT COUNT(*) FROM stock_transactions WHERE unit_cost IS NULL;`
  returns 0; `SELECT COUNT(*) FROM sales_invoice_items WHERE avg_cost_snapshot IS NULL;`
  returns 0.
- **Reference:** [`./inventory/stock-costing.md`](./inventory/stock-costing.md) §13,
  [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §12.9.

### 4.4 H2.4 — Enforce recon before period close

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Tasks:**
  1. Modify `AccountingPeriodService::closePeriod()` to run the three `subledger:reconcile-*`
     commands + `stock:reconcile-drift` + `journal:replay-verify` before closing.
  2. If any command reports drift > `accounting.gl_reconciliation_tolerance`, reject the
     close with a 422 listing the drift.
- **Verification:** Attempting to close a period with drift fails; fixing the drift and
  retrying succeeds.
- **Reference:** [`./workflows/period-close-workflow.md`](./workflows/period-close-workflow.md)
  §12.8 + §13.1.

### 4.5 H2.5 — Reconcile legacy vs enhanced period close

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Tasks:**
  1. Add a reconciliation command `fiscal-year:reconcile-legacy` that compares
     `accounting_periods.closed_through_date` against `fiscal_periods.status` and reports
     inconsistencies.
  2. Optionally, unify the two mechanisms (deprecate one). Decision required from the
     accountant.
- **Verification:** The reconciliation command reports zero inconsistencies.
- **Reference:** [`./workflows/period-close-workflow.md`](./workflows/period-close-workflow.md)
  §12.12 + §13.7.

### 4.6 H2.6 — Unify approval engine (Pattern A vs Pattern B)

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Tasks:**
  1. Decide: (a) extend Pattern A (generic engine) to cover `stock_adjustments`,
     `stock_take_sessions`, `damage_invoices`, and deprecate Pattern B; OR (b) deprecate
     Pattern A and keep Pattern B.
  2. Fix G2 (approved-manual-journal dead-ends at post).
  3. Fix G4 (notification dispatch is dead code).
  4. Fix G7 (DDL stale).
- **Verification:** All four entity types (`manual_journal`, `stock_adjustments`,
  `stock_take_sessions`, `damage_invoices`) flow through one approval pattern; the
  `/admin/approvals` queue shows all pending approvals.
- **Reference:** [`./workflows/approval-workflow.md`](./workflows/approval-workflow.md) §1
  + §13 G1–G7.

### 4.7 H2.7 — Notification table RLS + audit triggers (G5, G6)

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Tasks:**
  1. Add RLS policies to `notifications`, `notification_rules`,
     `notification_rule_recipients` (G5).
  2. Add `fn_financial_audit_trigger` to `notification_rules` +
     `notification_rule_recipients` (G6).
- **Verification:** A user in Branch A cannot see Branch B's notifications; audit rows are
  appended on every INSERT/UPDATE/DELETE on the rule tables.
- **Reference:** [`./architecture/branch-isolation-rls.md`](./architecture/branch-isolation-rls.md),
  [`./security/audit-trails.md`](./security/audit-trails.md).

### 4.8 H2.8 — Add `system_policy_change` to EVENTS (G4)

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Tasks:** Add `system_policy_change` to `NotificationRule::EVENTS` + `EVENT_META` so
  the `rcerp_system` DB trigger event becomes consumable by the notification rule engine.
- **Verification:** A system-policy change fires a notification to admins.
- **Reference:** [`./security/system-policy-compliance.md`](./security/system-policy-compliance.md).

---

## 5. Horizon 3 — Extend (H3, operational enhancements)

These are operational enhancements that improve the user experience but are not
safety-critical. They can be done in parallel with the AI sidecar (§3).

### 5.1 H3.1 — Back-order workflow

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** When an invoice finalize fails due to negative-stock guard, split the
  cart into a fulfillable invoice + a back-order cart (instead of failing atomically).
- **Reference:** [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §13.2.

### 5.2 H3.2 — Partial GRN confirmation

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Allow per-line GRN confirmation (currently all-or-nothing). Requires a
  richer state machine and a partial-post GL pattern.
- **Reference:** [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §13.1.

### 5.3 H3.3 — Three-way match (PO ↔ GRN ↔ Supplier Invoice)

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Add a match gate before supplier payment confirm. Currently the match is
  manual (the accountant eyeballs the PO + GRN before clicking confirm).
- **Reference:** [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §13.3.

### 5.4 H3.4 — Supplier portal (read-only)

- **Status:** ⬜ Pending
- **Owner:** Full-stack engineer
- **Dependencies:** H1 (production deployment); H2.7 (RLS on notifications — to extend to
  portal tables).
- **Description:** A read-only portal where suppliers can see their GRNs and payment
  status. Out of current scope.
- **Reference:** [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §13.4.

### 5.5 H3.5 — Customer portal (read-only)

- **Status:** ⬜ Pending
- **Owner:** Full-stack engineer
- **Dependencies:** H1 (production deployment); H2.7 (RLS).
- **Description:** A read-only portal where customers can see their invoices and payment
  status. Out of current scope.
- **Reference:** [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §13.5.

### 5.6 H3.6 — Early-payment discount on supplier payments

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Wire `supplier_payments.discount_amount` into `postPaymentGL()` as a
  three-line posting: Dr AP / Cr Bank / Cr Discount-Received.
- **Reference:** [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §13.5,
  [`./accounting/supplier-transactions.md`](./accounting/supplier-transactions.md).

### 5.7 H3.7 — AP aging auto-refresh on payment confirm

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Trigger `refresh:report-views` after supplier payment confirm (currently
  only on the 5-min cron cycle).
- **Reference:** [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §13.7,
  [`./reports/materialized-views.md`](./reports/materialized-views.md).

### 5.8 H3.8 — Commission on partial payment

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Pay commission pro-rata to the paid amount (currently all-or-nothing:
  commission stays `accrued` until the invoice is fully paid).
- **Reference:** [`./sales/commission.md`](./sales/commission.md),
  [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §13.4.

### 5.9 H3.9 — Auto-allocation (FIFO)

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** `allocateToInvoice()` / `allocateToGRN()` currently requires the user to
  specify allocations. Add a FIFO auto-allocation (oldest invoice/GRN first).
- **Reference:** [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §13.6,
  [`./workflows/procure-to-pay.md`](./workflows/procure-to-pay.md) §6.9.

### 5.10 H3.10 — REST API v1 coverage extension

- **Status:** ⬜ Pending
- **Owner:** Backend engineer
- **Dependencies:** None.
- **Description:** Extend `/api/v1` to cover all modules (currently Sales/StockTake are
  best-covered). Add OAuth2 client-credentials flow for machine-to-machine integrations.
  Publish `laravel/docs/api/API_REFERENCE.md` (Scribe-generated).
- **Reference:** [`./api/api-modules.md`](./api/api-modules.md).

---

## 6. Horizon 4 — Scale (H4)

These are larger efforts that depend on business demand. They are listed here for
completeness but are not yet committed.

### 6.1 H4.1 — Multi-currency support

- **Status:** ⬜ Pending (business case required)
- **Description:** Add a `currency_code` column to monetary tables; post FX revaluation
  entries at period close. Currently single-currency (BDT).
- **Reference:** [`./business/business-model.md`](./business/business-model.md),
  [`./workflows/period-close-workflow.md`](./workflows/period-close-workflow.md) §13.4.

### 6.2 H4.2 — Manufacturing module

- **Status:** ⬜ Pending (business case required)
- **Description:** Add a manufacturing module (BOM, work orders, assembly/disassembly
  stock movements). The `manufacturing/` folder is currently a placeholder. New
  `manufacturing_assembly` / `manufacturing_disassembly` reference_types would be added
  to `stock_transactions`.
- **Reference:** [`./business/business-model.md`](./business/business-model.md),
  [`./workflows/inventory-to-gl.md`](./workflows/inventory-to-gl.md) §13.6.

### 6.3 H4.3 — Sales order document (with approval)

- **Status:** ⬜ Pending
- **Description:** A formal Sales Order document (with approval flow) that mirrors the PO
  on the purchasing side. Currently the cart is the only pre-invoice document.
- **Reference:** [`./workflows/order-to-cash.md`](./workflows/order-to-cash.md) §13.7.

### 6.4 H4.4 — Period-close dashboard

- **Status:** ⬜ Pending
- **Description:** A single screen showing per-branch close status, recon drift, and
  pending approvals — to give the accountant a single screen for close preparation.
- **Reference:** [`./workflows/period-close-workflow.md`](./workflows/period-close-workflow.md)
  §13.5.

### 6.5 H4.5 — Year-end close dry-run command

- **Status:** ⬜ Pending
- **Description:** A `fiscal-year:year-end-dry-run` command that shows the proposed
  Retained Earnings entry without posting.
- **Reference:** [`./workflows/period-close-workflow.md`](./workflows/period-close-workflow.md)
  §13.6.

### 6.6 H4.6 — Stock ledger → GL reconciliation MV

- **Status:** ⬜ Pending
- **Description:** A materialized view `stock_gl_reconciliation` that JOINs
  `stock_transactions` to `journal_entries` on `(reference_type, reference_id)` and flags
  mismatches. Currently done by the `stock:reconcile-drift` command in PHP; a SQL MV would
  be faster.
- **Reference:** [`./workflows/inventory-to-gl.md`](./workflows/inventory-to-gl.md) §13.4.

### 6.7 H4.7 — Stale-comment cleanup

- **Status:** ⬜ Pending
- **Description:** Clean up stale comments: `bootstrap/app.php` says "Laravel 11" (runtime
  is 12); `README.md` says "Laravel 11" in one place.
- **Reference:** [`./PROJECT_OVERVIEW.md`](./PROJECT_OVERVIEW.md) §11.5.

---

## 7. Roadmap summary table

| ID | Horizon | Item | Status | Dependencies |
|---|---|---|---|---|
| H1.1 | Cutover | Provision BDIX VPS | ⬜ Pending | None |
| H1.2 | Cutover | Production credential rotation | ⬜ Pending | H1.1 |
| H1.3 | Cutover | Database migration on VPS | ⬜ Pending | H1.1, H1.2 |
| H1.4 | Cutover | Nginx + HTTPS configuration | ⬜ Pending | H1.1, H1.3 |
| H1.5 | Cutover | Cron + scheduler setup | ⬜ Pending | H1.3 |
| H1.6 | Cutover | Legacy MySQL read-only enforcement | ⬜ Pending | H1.3 |
| H1.7 | Cutover | Go-live checklist | ⬜ Pending | H1.1–H1.6 |
| H2.1 | Stabilize | Notification G1/G2/G3 fix | ⬜ Pending | H1 |
| H2.2 | Stabilize | Activate dead intercompany methods | ⬜ Pending | None |
| H2.3 | Stabilize | Avg-cost snapshot backfill | ⬜ Pending | H1.3 |
| H2.4 | Stabilize | Enforce recon before period close | ⬜ Pending | None |
| H2.5 | Stabilize | Reconcile legacy vs enhanced period close | ⬜ Pending | None |
| H2.6 | Stabilize | Unify approval engine | ⬜ Pending | None |
| H2.7 | Stabilize | Notification table RLS + audit triggers | ⬜ Pending | None |
| H2.8 | Stabilize | Add `system_policy_change` to EVENTS | ⬜ Pending | None |
| H3.1 | Extend | Back-order workflow | ⬜ Pending | None |
| H3.2 | Extend | Partial GRN confirmation | ⬜ Pending | None |
| H3.3 | Extend | Three-way match | ⬜ Pending | None |
| H3.4 | Extend | Supplier portal (read-only) | ⬜ Pending | H1, H2.7 |
| H3.5 | Extend | Customer portal (read-only) | ⬜ Pending | H1, H2.7 |
| H3.6 | Extend | Early-payment discount | ⬜ Pending | None |
| H3.7 | Extend | AP aging auto-refresh | ⬜ Pending | None |
| H3.8 | Extend | Commission on partial payment | ⬜ Pending | None |
| H3.9 | Extend | Auto-allocation (FIFO) | ⬜ Pending | None |
| H3.10 | Extend | REST API v1 coverage extension | ⬜ Pending | None |
| §3.1 | Extend (AI) | Sidecar foundation | ⬜ Pending | H1 |
| §3.2 | Extend (AI) | Report chatbot | ⬜ Pending | §3.1 |
| §3.3 | Extend (AI) | Demand forecasting | ⬜ Pending | §3.1, 6+ months data |
| §3.4 | Extend (AI) | Invoice OCR | ⬜ Pending | §3.1, VLM skill |
| §3.5 | Extend (AI) | Anomaly detection | ⬜ Pending | §3.1, 3+ months data |
| H4.1 | Scale | Multi-currency support | ⬜ Pending (business case) | — |
| H4.2 | Scale | Manufacturing module | ⬜ Pending (business case) | — |
| H4.3 | Scale | Sales order document | ⬜ Pending | — |
| H4.4 | Scale | Period-close dashboard | ⬜ Pending | — |
| H4.5 | Scale | Year-end close dry-run | ⬜ Pending | — |
| H4.6 | Scale | Stock ledger → GL reconciliation MV | ⬜ Pending | — |
| H4.7 | Scale | Stale-comment cleanup | ⬜ Pending | — |

---

## 8. Decision log

Decisions that affect the roadmap are recorded here (most recent first).

- **2026-08-04** — Phase 21 documentation complete. AI sidecar deferred to H3 (post-cutover).
  The four AI capabilities (chatbot, forecast, OCR, anomaly) are scoped but not yet
  committed to a calendar date.
- **2026-07-22** — Telegram + Firebase FCM removed (R24/R25). Laravel-native notifications
  + Listen/Notify + SSE are the replacement.
- **2025-01** — Migration from legacy PHP/MySQL to Laravel 12 + PostgreSQL 16 + Redis
  commenced. Four non-negotiable principles established.

---

*This roadmap is the canonical forward-looking plan. For the backward-looking product
history, see [`./changelog/PRODUCT_CHANGELOG.md`](./changelog/PRODUCT_CHANGELOG.md). For
known limitations, see [`./PROJECT_OVERVIEW.md`](./PROJECT_OVERVIEW.md) §11.*
