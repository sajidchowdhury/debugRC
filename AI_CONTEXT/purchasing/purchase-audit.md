# Purchase Audit

> **Module:** Purchasing (Phase 9)
> **Audience:** Engineers + AI assistants + accountants + auditors
> **Status:** Draft
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Purchase/PurchaseAuditService.php`
> + `laravel/app/Http/Controllers/Admin/PurchaseAuditController.php`
> + `laravel/database/sql/02_accounting.sql:329-455` (`financial_audit_log` + `fn_financial_audit_trigger`)
> + `laravel/app/Traits/AuditableMasterData.php` + `laravel/app/Services/Auth/UserAuditLogger.php`.

## 1. What is it?

The Purchase module has a **three-layer audit infrastructure** plus a **twelve-section health-check
report**. Each layer captures a different kind of evidence and has different coverage gaps.

### Layer 1 — Hash-chained immutable financial audit log (DB trigger)

The `financial_audit_log` table records every INSERT/UPDATE/DELETE on a set of "crown-jewel"
financial tables via the `fn_financial_audit_trigger()` PostgreSQL function. Each row carries a
SHA-256 `row_hash` chained to the previous row's `prev_hash`, producing a tamper-evident ledger.
UPDATE and DELETE are REVOKE'd at the DB level — even a superuser cannot silently mutate a row.

**Coverage of the purchase ecosystem: PARTIAL.** Of the 10 tables to which the trigger is
attached (`02_accounting.sql:446-455`), only **`supplier_payments`** is purchase-related. The
six core purchase tables — `purchase_orders`, `purchase_order_items`, `purchase_receives`,
`purchase_receive_items`, `purchase_returns`, `purchase_return_items` — have **NO**
`trg_audit_*` attachment. Direct `DB::table('purchase_receives')->where(…)->update(…)` calls in
the Purchase services bypass the hash chain entirely (gap G3).

### Layer 2 — User-action log (`user_audit_log` via `UserAuditLogger` + `AuditableMasterData`)

The `user_audit_log` table records discrete user actions (login, role change, master-data CRUD,
purchase_order_*, purchase_receive_*, purchase_return_*, branch_override, etc.) with a jsonb
`details` payload + `ip_address` + `user_agent`. It is dual-written: every entry goes to the DB
table AND to `logs/user_audit.log` (defense in depth — if the DB is tampered with, the file
copy survives).

`UserAuditLogger::log()` is called explicitly at the end of every Purchase service method
(`createOrder`, `cancelOrder`, `createReceive`, `confirmReceive`, `cancelReceive`,
`createReturn`, `confirmReturn`, `cancelReturn`). The `AuditableMasterData` trait is `use`d on
all 4 models (`PurchaseOrder`, `PurchaseReceive`, `PurchaseReturn`, `Supplier`) — it hooks
Eloquent `created/updated/deleted/restored` events and emits `master_data_*` rows automatically.

**Coverage: FULL but with a critical gap.** `UserAuditLogger::log()` always fires (the service
methods call it explicitly). However, the `AuditableMasterData` trait is **bypassed** because
the services use `DB::table(…)->insertGetId(…)` and `DB::table(…)->where(…)->update(…)` (raw
queries) instead of Eloquent (`PurchaseOrder::create(…)`, `$po->update(…)`). The trait's
`static::created()` listener never fires. So `master_data_*` rows are NEVER written for purchase
mutations through the canonical service path (gap G4). The audit relies entirely on the
explicit `UserAuditLogger::log()` calls.

### Layer 3 — Per-module audit-log views

Each controller (`PurchaseOrderController`, `PurchaseReceiveController`,
`PurchaseReturnController`) has an `audit()` method that reads `user_audit_log` filtered by
action prefix (`purchase_order_*` / `purchase_receive_*` / `purchase_return_*`), paginated.
Available at `admin/purchase-orders/audit`, `admin/purchase-receives/audit`,
`admin/purchase-returns/audit`.

### Health-check report (`PurchaseAuditService`)

The `PurchaseAuditService` is a 12-section invariant checker — NOT a mutation audit. It runs
SQL queries against the live database and emits pass/warn/fail/info items per section, plus
three detail tables (`negative_stocks`, `missing_grn_journals`, `missing_return_journals`).
Available at `admin/purchase-audit` (HTML checklist) and `admin/purchase-audit/run` (JSON for
AJAX refresh). It is **on-demand only** — there is no scheduled job, no alerting on FAIL items.

## 2. Why does it exist?

- **Forensic trail.** When something goes wrong (a missing journal entry, a negative stock
  balance, an over-received PO line), the audit infrastructure lets the team reconstruct what
  happened, when, and by whom.
- **Invariant detection.** The health-check report catches data-drift issues early — before
  they cascade into a period-close failure or a reconciliation mismatch.
- **Period-close gate input.** The AP reconciliation (`reconcileAP`) depends on `supplier_ledger`
  rows written by GRN and return services. The audit infrastructure verifies those rows exist
  and are linked to GL journal entries.
- **Compliance evidence.** For SOX-style or VAT audit compliance, the audit log demonstrates
  segregation of duties (the `role:` middleware restricts who can confirm/cancel) and
  immutability of posted entries (the `is_reversed` flag + reversal-by-appending pattern).
- **Operational health.** The checklist surfaces issues like "GRN confirmed but no journal
  entry" or "Damage line with stock movements" that indicate a service-layer bug or a manual
  DB intervention.

## 3. When is it used?

- **Daily operations review.** A manager or accountant opens `admin/purchase-audit` to see the
  12-section checklist. Any FAIL item triggers investigation.
- **On cancellation.** `UserAuditLogger` captures the cancel reason verbatim. The audit-log
  view at `admin/purchase-receives/audit?receive_id=X` shows the full history of a single GRN.
- **On period close.** `ReconciliationService::reconcileAP` cross-checks the `supplier_ledger`
  (debit + credit rows from GRN/return) against the GL `ap` control account. Any drift is
  surfaced via the ledger section of the checklist.
- **On forensic investigation.** The `financial_audit_log` hash-chain verification (via the
  `v_financial_audit_chain_verification` view) detects tampering of `supplier_payments` rows.
  Purchase-order/GRN/return rows are NOT covered (gap G3) — a separate `UserAuditLogger`
  query is required.
- **On auditor request.** External auditors are given read-only access to the per-module audit
  views + the checklist dashboard. There is no separate "auditor" role — auditors use the
  `accountant` role.

## 4. Who uses it?

- **`admin` / `superadmin`** — full access to all audit pages + the checklist.
- **`manager`** — full access to audit pages + the checklist.
- **`accountant`** — full access to audit pages + the checklist (the routes use
  `role:admin,manager,accountant`).
- **`warehouse_manager`** — NO access to audit pages or the checklist (the routes exclude this
  role). This enforces segregation: the warehouse team creates documents, the manager/accountant
  team audits them.
- **Excluded:** `salesman`, `dispatcher`, `hr`, `user` — no route access.

There is **no dedicated "auditor" role**. The `accountant` role serves as the read-only audit
consumer. There is **no per-row policy gate** on audit-log reads — branch isolation is enforced
via the `branch_id` filter in `user_audit_log` queries (non-admins see only their branch's
entries).

## 5. Related modules

- `purchase-order.md`, `purchase-receive.md`, `purchase-return.md` — the audited entities. Each
  documents its own state-machine and audit-log emission.
- `../accounting/financial-audit-log.md` — the hash-chain mechanism. NOTE the partial-coverage
  gap (G3): purchase tables (except `supplier_payments`) are NOT attached to the trigger.
- `../security/audit-trails.md` — `UserAuditLogger` + `AuditableMasterData` trait. NOTE the
  bypass gap (G4): the trait is bypassed by `DB::table()` writes in the Purchase services.
- `../accounting/subledger-reconciliation.md` §7.2 — `reconcileAP` depends on `supplier_ledger`
  rows written by GRN/return services; the checklist's `ledger` section verifies the linkage.
- `../accounting/fiscal-year-period-close.md` — the period-close gate consumes the
  reconciliation output.
- `../security/branch-context-security.md` — branch isolation on audit-log reads (non-admins
  see only their branch).

## 6. Business rules

- **MUST** log every PO/GRN/Return state transition via `UserAuditLogger::log()` with:
  - `userId` (the actor)
  - `action` (e.g. `purchase_receive_confirmed`)
  - `targetUserId` (the entity ID — historical naming; not actually a user ID)
  - `details` (jsonb: po_code/receive_code/return_code, branch_id, supplier_id, total,
    journal_entry_id, reason, etc.)
- **MUST** capture cancel/confirm reason verbatim. The `$reason` parameter passed to the service
  methods is stored in both the `reverse_reason` column (on the entity row) and the
  `details.reason` field of the audit log entry.
- **MUST** capture `user_id`, `branch_id`, `ip_address`, `user_agent` on every audit entry.
  These are populated by `UserAuditLogger` from the authenticated session + request context.
- **MUST** dual-write `user_audit_log` to DB + `logs/user_audit.log` file (defense in depth).
  If the DB is tampered with, the file copy survives.
- **MUST** run the `PurchaseAudit` checklist via the controller (`admin/purchase-audit`) — it is
  NOT a scheduled job. Operations must manually open the page (or trigger the JSON refresh
  endpoint) to run the checks.
- **MUST** branch-scope the audit-log reads. Non-admins see only their branch's audit entries
  (filtered by `branch_id` on `user_audit_log`).
- **MUST NOT** rely on `AuditableMasterData` trait alone (gap G4 — bypassed by `DB::table`
  writes). The `master_data_*` rows are NEVER written for purchase mutations through the
  canonical service path.
- **MUST NOT** assume `financial_audit_log` covers purchase tables (gap G3 — only
  `supplier_payments` is covered). Direct DB mutations to `purchase_orders`, `purchase_receives`,
  `purchase_returns`, and their `_items` children bypass the hash chain.
- **MUST** keep `user_audit_log` partitioned by month (it lives in `02_accounting.sql` as a
  partitioned table). Old partitions can be archived to cold storage.
- **MUST NOT** allow UPDATE or DELETE on `financial_audit_log` (REVOKE'd at DB level; the
  trigger function rejects any mutation attempt).
- **MUST** preserve the hash chain — each new `financial_audit_log` row's `prev_hash` is the
  `row_hash` of the previous row (ordered by `created_at` + `id`). The
  `v_financial_audit_chain_verification` view detects any break.

## 7. Data model

### `user_audit_log` table (DDL: `02_accounting.sql`)

| Column | Type | Purpose |
|---|---|---|
| `id` | bigserial PK | Row identifier |
| `user_id` | integer | The actor (NULL for system-generated events) |
| `branch_id` | integer | The branch context (used for read filtering) |
| `action` | varchar(100) | e.g. `purchase_receive_confirmed`, `branch_override`, `master_data_updated` |
| `target_user_id` | integer | The entity ID (historical naming; not actually a user ID for purchase events — it's the PO/GRN/Return ID) |
| `details` | jsonb | Structured payload: codes, totals, journal_entry_id, reason, etc. |
| `ip_address` | varchar(45) | IPv4 or IPv6 of the requester |
| `user_agent` | text | Browser User-Agent string |
| `request_path` | varchar(255) | The route path that triggered the action |
| `request_id` | uuid | Correlation ID (matches the `X-Request-Id` header if set) |
| `created_at` | timestamp(0) | Event time |

- Partitioned by `RANGE(created_at)` (monthly partitions).
- Indexes on `(user_id, created_at)`, `(action, created_at)`, `(branch_id, created_at)`,
  `(target_user_id, action)`.

### `financial_audit_log` table (DDL: `02_accounting.sql:329-378`)

| Column | Type | Purpose |
|---|---|---|
| `id` | bigserial PK | Row identifier |
| `table_name` | varchar(100) | The mutated table (e.g. `supplier_payments`) |
| `operation` | char(1) | I/U/D (Insert/Update/Delete) |
| `row_id` | integer | PK of the mutated row |
| `before_data` | jsonb | Full row snapshot before mutation (NULL for INSERT) |
| `after_data` | jsonb | Full row snapshot after mutation (NULL for DELETE) |
| `changed_columns` | text[] | Array of changed column names (NULL for INSERT/DELETE) |
| `performed_by` | integer | The actor (from `app.current_user_id` GUC) |
| `branch_id` | integer | The branch context (from `app.branch_id` GUC) |
| `transaction_id` | bigint | PostgreSQL `txid_current()` — groups rows in the same DB transaction |
| `request_path` | varchar(255) | The route path |
| `request_ip` | varchar(45) | The requester IP |
| `request_id` | uuid | Correlation ID |
| `prev_hash` | char(64) | SHA-256 of the previous row (chain link) |
| `row_hash` | char(64) | SHA-256 of this row's content (computed over all columns + `prev_hash`) |
| `created_at` | timestamp(0) | Event time |

- Partitioned by `RANGE(created_at)` (monthly).
- `REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC;` — even superusers cannot mutate
  (the REVOKE is at the table level; only the trigger function with SECURITY DEFINER can insert).
- `v_financial_audit_chain_verification` view — recomputes the hash chain and flags any break.

### Trigger attachments (`02_accounting.sql:446-455`)

The trigger `trg_audit_<table>` is attached to these 10 tables:

| Table | Purchase ecosystem? |
|---|---|
| `journal_entries` | shared (covers all GL postings) |
| `journal_lines` | shared (covers all GL postings) |
| `manual_journals` | accounting |
| `manual_journal_lines` | accounting |
| `customer_payments` | sales |
| `supplier_payments` | **YES** — purchase ecosystem |
| `money_transfers` | accounting |
| `other_incomes` | accounting |
| `other_expenses` | accounting |
| `employee_transactions` | accounting |

**NOT attached** (gap G3): `purchase_orders`, `purchase_order_items`, `purchase_receives`,
`purchase_receive_items`, `purchase_returns`, `purchase_return_items`, `suppliers`,
`supplier_ledger`. Direct DB mutations to these tables are NOT hash-chain-audited.

### `AuditableMasterData` trait (`app/Traits/AuditableMasterData.php`)

Hooks Eloquent events: `static::created`, `static::updated`, `static::deleted`,
`static::restored`. On each event, writes a `master_data_*` row to `user_audit_log` with the
full `old` and `new` attribute snapshots.

**Used on:** `PurchaseOrder`, `PurchaseReceive`, `PurchaseReturn`, `Supplier` (and other
master-data models).

**Bypassed (gap G4):** the Purchase services use `DB::table(…)->insertGetId(…)` and
`DB::table(…)->where(…)->update(…)` instead of Eloquent. The trait's listeners never fire.
Only the explicit `UserAuditLogger::log()` calls capture the mutation — and those record only
summary fields (po_code, total, journal_entry_id), NOT the full old/new attribute diff.

### `PurchaseAuditService` report shape

```
{
  "ran_at": "2025-08-03T12:34:56Z",
  "branch_id": 1,                  // null for admin (all branches)
  "summary": { "pass": 18, "warn": 5, "fail": 2, "info": 8, "total": 33 },
  "sections": [
    { "key": "scope",         "label": "Module Scope",   "items": [...] },
    { "key": "products",      "label": "Products",       "items": [...] },
    { "key": "suppliers",     "label": "Suppliers",      "items": [...] },
    { "key": "warehouses",    "label": "Warehouses",     "items": [...] },
    { "key": "stock",         "label": "Stock SSOT",     "items": [...] },
    { "key": "purchase_order","label": "Purchase Order", "items": [...] },
    { "key": "grn",           "label": "GRN",            "items": [...] },
    { "key": "purchase_return","label":"Purchase Return","items": [...] },
    { "key": "supplier_payments","label":"Supplier Payments","items":[...] },
    { "key": "gl_journal_links","label":"GL Journal Links","items":[...] },
    { "key": "ledger",        "label": "Ledger",         "items": [...] },
    { "key": "reports",       "label": "Reports",        "items": [...] }
  ],
  "negative_stocks": [...],          // detail table 1
  "missing_grn_journals": [...],     // detail table 2
  "missing_return_journals": [...]   // detail table 3
}
```

Each `item` in a section's `items` array has the shape:

```
{ "level": "pass"|"warn"|"fail"|"info", "message": "...", "count": N, "details": {...} }
```

## 8. Lifecycle / workflow

### Audit-trail write flow

```
Service method (e.g. confirmReceive)
  ↓
DB::transaction {
  stock movements + GL journal entry + supplier_ledger row + PO update + status update
  ↓
UserAuditLogger::log(action: 'purchase_receive_confirmed', details: {...})
  ↓
INSERT INTO user_audit_log (...)         -- DB write
  ↓
File append to logs/user_audit.log       -- file write (defense in depth)
}
```

**NOTE:** `AuditableMasterData::bootAuditableMasterData()` is registered on the model, but the
service uses `DB::table(…)` (raw query), so the Eloquent `created`/`updated` events never fire.
The `master_data_*` row is NOT written. Only the explicit `UserAuditLogger::log()` call
captures the mutation.

### Audit-log read flow (per-module audit page)

```
Controller audit() method
  ↓
SELECT * FROM user_audit_log
WHERE action LIKE 'purchase_receive_%'    -- action prefix filter
  AND (branch_id = :session_branch_id OR :is_admin)  -- branch filter
  AND (target_user_id = :receive_id OR :receive_id IS NULL)  -- optional entity filter
ORDER BY created_at DESC
LIMIT 100 OFFSET :page
  ↓
Paginated view at admin/purchase-receives/audit?receive_id=X
```

### Checklist run flow

```
Controller checklist() method (HTML page)
  ↓
Render admin/purchase-audit/checklist.blade.php with empty placeholder
  ↓
Client-side JS calls /admin/purchase-audit/run (AJAX, JSON)
  ↓
Controller runChecks() method
  ↓
PurchaseAuditService::runHealthChecks(branchId)
  ↓
12 sections execute in sequence (each runs 1-N SQL queries)
  ↓
Returns JSON report (shape above)
  ↓
Client-side JS renders the 12 sections + 3 detail tables
```

### Hash-chain verification flow (forensic)

```
DBA runs: SELECT * FROM v_financial_audit_chain_verification;
  ↓
View recomputes SHA-256(row_content || prev_hash) for every row in financial_audit_log
  ↓
If computed_hash != stored row_hash → flag as broken
  ↓
Output: { total_rows, verified_rows, broken_rows, first_break_at }
```

**NOTE:** This only covers `supplier_payments` of the purchase ecosystem (gap G3). The 6 core
purchase tables are NOT in the hash chain.

## 9. Integration points

| Integration | Direction | Purpose |
|---|---|---|
| `UserAuditLogger::log()` | outbound | Called by every Purchase service method; writes to DB + file |
| `AuditableMasterData` trait | outbound | Hooks Eloquent events on 4 models (BYPASSED — gap G4) |
| `PurchaseAuditService::runHealthChecks` | outbound | 12-section invariant checker; called by `PurchaseAuditController::runChecks` |
| `PurchaseAuditService::branchFilter` | internal | String-concatenates `AND {column} = {branchId}` into raw SQL (gap G15 — int cast prevents injection but pattern violates coding standards) |
| `ReconciliationService::reconcileAP` | inbound | Consumes `supplier_ledger` rows written by GRN/return services; output feeds the `ledger` section of the checklist |
| `v_financial_audit_chain_verification` view | outbound | Forensic hash-chain check (covers `supplier_payments` only — gap G3) |
| Per-module audit views (3 controllers' `audit()` methods) | outbound | Paginated reads of `user_audit_log` filtered by action prefix |
| `EnforceBranchIsolation` middleware | inbound | Branch scoping on audit-page reads |
| `logs/user_audit.log` file | outbound | Defense-in-depth file copy of every `user_audit_log` row |

## 10. Edge cases

- **Direct SQL mutation** (e.g. a DBA running `UPDATE purchase_receives SET status='confirmed'
  WHERE id=123`). Bypasses both `UserAuditLogger` (no service method called) AND
  `AuditableMasterData` (no Eloquent event) AND `fn_financial_audit_trigger` (no trigger
  attached — gap G3). The mutation is invisible to all three audit layers. This is the worst
  case for forensic investigation.
- **Cancel inside a `DB::transaction` that later rolls back.** `UserAuditLogger::log()` runs
  inside the transaction. If the transaction rolls back, the audit-log row is also rolled back
  (the mutation did not happen, so the audit entry should not exist either). This is correct.
- **`AuditableMasterData` re-throws on transaction level > 0.** The trait detects when it is
  inside a nested transaction (`DB::transactionLevel() > 0`) and defers the audit-log write
  until the outer transaction commits. This avoids writing audit rows for mutations that later
  roll back. (This is the trait's behaviour on models where it is NOT bypassed — e.g. `Supplier`
  when updated via Eloquent. It does not help the Purchase services because they bypass the
  trait entirely with `DB::table()` writes.)
- **Branch override by admin.** An admin user can read/write purchase entities in any branch.
  The `EnforceBranchIsolation` middleware logs this as a `branch_override` action in
  `user_audit_log`. The audit team can query for `action = 'branch_override'` to review all
  cross-branch interventions.
- **Audit-log pagination.** The per-module audit views paginate at 100 rows per page. The
  checklist dashboard paginates at 25 items per section. For high-volume branches, a single
  day's `purchase_receive_*` events can exceed 100 rows — the user must paginate.
- **Closed-period reversal.** `UserAuditLogger` fires regardless of period state — there is no
  period-close check on audit writes (the audit log must capture every event, even those that
  violate period close). The period-close guard is enforced inside `JournalPostingService`, not
  in the audit logger.
- **Soft-deleted entity.** A soft-deleted PO/GRN/Return's audit rows remain in
  `user_audit_log` (the audit log is never soft-deleted). The per-module audit view's
  `target_user_id` filter still finds them.
- **Checklist timeout.** The `PurchaseAuditService::runHealthChecks` runs 12 sections
  sequentially, each with 1-N SQL queries. On a large database, this can take 30+ seconds.
  The AJAX endpoint has a 60-second timeout; longer runs will fail and the user must retry.
- **Concurrent checklist runs.** The checklist is read-only (no DB writes), so concurrent runs
  are safe. However, the report's `ran_at` timestamp is per-run — concurrent runs produce
  different reports.

## 11. Gaps

1. **G1 — `paid_amount` column missing on `purchase_receives`.** Affects the audit of GRN
   payment allocation: `SupplierTransactionService::allocateToGRN` throws at runtime, so the
   payment-allocation audit trail is never built. CRITICAL.

   > ✅ RESOLVED (PURCHASING-1) — Migration `2026_09_03_000001_add_paid_amount_to_purchase_receives.php`
   > adds `paid_amount numeric(14,2) DEFAULT 0` to `purchase_receives` (after `total_amount`),
   > mirroring `sales_invoices.paid_amount`. A partial index `idx_pr_paid WHERE paid_amount > 0`
   > powers the "partially-paid GRNs" audit view cheaply. DDL refreshed in `05_purchase.sql`.
   > `SupplierTransactionService::allocateToGRN` (+) and `reversePayment` (GREATEST(0, … - N))
   > now succeed — the supplier-payment-against-GRN workflow is unblocked. Closes G-024 + G-025.
2. **G2 — No `Purchase*Policy` classes.** Per-row audit gating is impossible. The audit team
   cannot answer "show me all GRNs created by user X that were confirmed by user Y" without a
   policy layer. CRITICAL for compliance.

   > ✅ RESOLVED in commit 1ccc5b6 — Cluster-level gap closed by creating the 3 purchasing policies: `App\Policies\PurchaseOrderPolicy`, `App\Policies\PurchaseReceivePolicy`, `App\Policies\PurchaseReturnPolicy` (all registered in `AppServiceProvider::boot()`). Each policy includes an `audit()` method mirroring the `role:admin,manager,accountant` middleware on the per-module audit routes. The `PurchaseAuditController` checklist (admin/purchase-audit) has no underlying model — it remains route-middleware-only (`role:admin,manager,accountant`) since there's no `PurchaseAudit` model to bind a policy to. See G-027/G-028/G-029 for the per-model policies.
3. **G3 — `fn_financial_audit_trigger` NOT attached to purchase tables.** Only `supplier_payments`
   is hash-chain-audited. The 6 core purchase tables (`purchase_orders`, `purchase_order_items`,
   `purchase_receives`, `purchase_receive_items`, `purchase_returns`, `purchase_return_items`)
   have no trigger. Direct DB mutations are invisible to the forensic hash chain. CRITICAL —
   forensic audit gap.

   > ✅ RESOLVED (PURCHASING-1) — Migration `2026_09_03_000002_attach_financial_audit_trigger_to_purchase_tables.php`
   > attaches `trg_audit_<table>` AFTER INSERT OR UPDATE OR DELETE on all 6 purchase tables.
   > Pattern mirrors SALES-3 (`2026_09_01_000002`) and FINANCE-1 (`2026_09_01_000003`):
   > DROP IF EXISTS + CREATE (idempotent). DDL refreshed at the bottom of `05_purchase.sql`
   > via a `DO $$ … $$` block. `supplier_payments` is intentionally excluded — it already
   > had the trigger. Closes G-030 + G-031 + G-032.
4. **G4 — `AuditableMasterData` trait bypassed by `DB::table()` writes.** The trait is `use`d
   on all 4 models but never fires because the services use raw queries. The `master_data_*`
   rows are NEVER written through the canonical path. The audit team is likely unaware of this
   — they may believe the trait is capturing full old/new attribute diffs when it is not.
   CRITICAL — silent audit gap.

   > ✅ RESOLVED (PURCHASING-2) — Added `AuditableMasterData::logManualAudit()` public static
   > helper to the trait (mirrors the protected `logAudit()` payload + transaction-aware
   > error handling). Wired explicit `logManualAudit()` calls into all 3 purchase services:
   > `PurchaseOrderService` (createOrder, updateOrder, markAsSent, cancelOrder, updateReceivedQty),
   > `PurchaseReceiveService` (createReceive, confirmReceive, cancelReceive × 2 updates,
   > decrementPoReceivedQty), `PurchaseReturnService` (createReturn, confirmReturn,
   > cancelReturn × 2 updates). For INSERTs: log 'created' with the inserted row. For UPDATEs:
   > capture old via `DB::table(...)->first()` BEFORE the update, then log 'updated' with
   > `array_intersect_key($old, $update)` as old and `$update` as new (mirrors the trait's
   > `array_intersect_key($old, $changes)` semantics). All calls happen INSIDE the parent
   > `DB::transaction()` so atomicity is preserved. Closes G-033 + G-034 + G-035 + G-036.
5. **G15 — `PurchaseAuditService::branchFilter` uses string concatenation into raw SQL.** The
   `(int)` cast prevents SQL injection, but the pattern violates the project's coding standards
   (use prepared statements). Static analyzers will flag it. MINOR.
6. **No scheduled job for the checklist.** The checklist runs only on-demand. A FAIL item
   (e.g. negative stock, missing journal) can persist for days before someone opens the
   dashboard. MAJOR — operational.
7. **No alerting on FAIL items.** Even when the checklist is run, FAIL items do not trigger an
   email, SMS, or Slack notification. The operations team must visually inspect the dashboard.
   MAJOR — operational.
8. **No "auditor" role.** Auditors use the `accountant` role, which also has write access to
   supplier payments (via `routes/web.php` L1310–1339). True segregation of duties would
   require a read-only `auditor` role. MAJOR — compliance.
9. **`user_audit_log.target_user_id` is misnamed for purchase events.** The column was
   originally designed for user-management events (e.g. `role_assigned` targets a user). For
   purchase events, it stores the PO/GRN/Return ID — confusing for queries. The `details`
   jsonb also stores the ID under a meaningful key (`po_id` / `receive_id` / `return_id`). MINOR
   — documentation issue.
10. **`PurchaseAuditService` has no test coverage for the 12 sections.** The service has 769
    lines of SQL but no automated tests (verified by checking `tests/Feature/`). A regression
    in any section's SQL would go unnoticed until someone runs the checklist. MAJOR — testing
    gap.
11. **No drill-down from checklist item to affected rows.** The checklist emits
    `{level: 'fail', count: 5, message: '...'}` but does not link to the 5 affected GRNs. The
    user must manually query `purchase_receives` to find them. (The 3 detail tables
    `negative_stocks`, `missing_grn_journals`, `missing_return_journals` are partial — only 3
    of the 12 sections have detail tables.) MINOR — UX.

## 12. Review checklist

- [ ] Every PO/GRN/Return state transition emits a `UserAuditLogger` entry with action prefix
      `purchase_order_*` / `purchase_receive_*` / `purchase_return_*`. Verify by reading each
      service method.
- [ ] Cancel/confirm reasons are captured verbatim in the audit log `details.reason` field AND
      on the entity row (`reverse_reason` column).
- [ ] The `PurchaseAudit` checklist covers all 12 sections (scope, products, suppliers,
      warehouses, stock, purchase_order, grn, purchase_return, supplier_payments,
      gl_journal_links, ledger, reports).
- [ ] Branch isolation is enforced on audit-log reads (non-admins see only their branch's
      entries — verify the `branch_id` filter in the controller `audit()` methods).
- [ ] Gap G3 (no `fn_financial_audit_trigger` on purchase tables) is documented as a known
      limitation. Confirm whether attaching the trigger is feasible (performance impact must be
      tested — the trigger fires on every write).
- [ ] Gap G4 (`AuditableMasterData` bypass) is documented. Confirm the audit team is aware that
      `master_data_*` rows are NOT written for purchase mutations through the service path.
- [ ] Gap G6 (over-receive) and G12 (unwritten sub_total/discount/tax on returns) are surfaced
      in the checklist (over-receive is in `sectionPurchaseOrder`; the unwritten-columns gap is
      NOT currently surfaced — consider adding a check).
- [ ] Period-close gate (`reconcileAP`) depends on `supplier_ledger` rows — verify the
      dependency is documented in `../accounting/fiscal-year-period-close.md` and that the
      `ledger` section of the checklist catches missing rows.
- [ ] The `v_financial_audit_chain_verification` view is run on a schedule (DBA responsibility)
      to detect tampering of `supplier_payments` rows. Confirm the schedule.
- [ ] The `logs/user_audit.log` file is rotated and archived (otherwise it grows unbounded).
      Confirm the log-rotation config in `config/logging.php`.
- [ ] Consider adding a scheduled job (daily) to run the `PurchaseAudit` checklist and email
      FAIL items to the operations team (gap #6 + #7).
- [ ] Consider adding a read-only `auditor` role for external auditors (gap #8).

## 13. Cross-references

- `purchase-order.md` — PO state transitions audited via `UserAuditLogger`; checklist inspects
  over-receive and open PO lines.
- `purchase-receive.md` — GRN state transitions audited; checklist inspects missing journals,
  missing stock IN, cancelled-with-unreversed-journal.
- `purchase-return.md` — Return state transitions audited; checklist inspects Damage-with-stock,
  Good-missing-stock-OUT, over-returned, reversal-flag mismatches.
- `../accounting/financial-audit-log.md` — the hash-chain mechanism; NOTE the partial-coverage
  gap (G3 — purchase tables NOT attached).
- `../security/audit-trails.md` — `UserAuditLogger` + `AuditableMasterData` trait; NOTE the
  bypass gap (G4 — trait bypassed by `DB::table()` writes).
- `../accounting/subledger-reconciliation.md` §7.2 — `reconcileAP` consumes `supplier_ledger`
  rows written by GRN/return services; the checklist's `ledger` section verifies the linkage.
- `../accounting/fiscal-year-period-close.md` — the period-close gate consumes the
  reconciliation output.
- `../security/branch-context-security.md` — branch isolation on audit-log reads.
- `../security/rbac-roles-permissions.md` — the role matrix for purchase routes (no dedicated
  `auditor` role — gap #8).
