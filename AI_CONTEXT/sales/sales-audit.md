# Sales Audit

> **Module:** Sales (Phase 10)
> **Audience:** Engineers + AI assistants + accountants + auditors
> **Status:** Draft
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Sales/SalesAuditLogger.php`
> + `laravel/app/Http/Controllers/Admin/SalesInvoiceController.php` (auditTrail method)
> + `laravel/app/Http/Controllers/Admin/ReportController.php` (salesAuditChecklist +
  computeSalesAuditChecks)
> + `laravel/database/sql/02_accounting.sql:329-455` (financial_audit_log +
  fn_financial_audit_trigger)
> + `laravel/app/Traits/AuditableMasterData.php`.

## 1. What is it?

The Sales module has a **three-layer audit infrastructure** plus a **three-section health-check
checklist**. Each layer captures a different kind of evidence and has different coverage gaps.

### Layer 1 — Hash-chained immutable financial audit log (DB trigger)

The `financial_audit_log` table records every INSERT/UPDATE/DELETE on a set of "crown-jewel"
financial tables via the `fn_financial_audit_trigger()` PostgreSQL function. Each row carries a
SHA-256 `row_hash` chained to the previous row's `prev_hash`, producing a tamper-evident ledger.
UPDATE and DELETE are REVOKE'd at the DB level.

**Coverage of the sales ecosystem: FULL (as of SALES-3).** The
`fn_financial_audit_trigger()` is now attached to `customer_payments` (from
`02_accounting.sql:446-455`) PLUS all 9 core sales tables + 5 commission tables
(14 tables, attached by migration `2026_09_01_000002` — SALES-3, commit
de2b6e6). The trigger fires on every INSERT/UPDATE/DELETE and writes a
hash-chained row to `financial_audit_log`. UPDATE and DELETE on
`financial_audit_log` are REVOKE'd at the DB level.

### Layer 2 — User-action log (`user_audit_log` via `SalesAuditLogger` + `UserAuditLogger`)

The `user_audit_log` table records discrete user actions with a jsonb `details` payload +
`ip_address` + `user_agent`. It is dual-written: every entry goes to the DB table AND to
`logs/user_audit.log` (defense in depth).

`SalesAuditLogger` (442 lines) wraps `UserAuditLogger::log()` and exposes **17+ sales-specific
event methods**: `saleCreated`, `saleCancelled`, `callItADay`, `paymentReceived`, `paymentReversed`,
`paymentDiscount`, `paymentWriteOff`, `paymentRefund`, `returnCreated`, `returnConfirmed`,
`returnReversed`, `godownPrepared`, `challanIssued`, `challanReversed`, `cartItemAdded`,
`cartItemUpdated`, `cartItemRemoved`, `cartCleared`.

The `AuditableMasterData` trait is `use`d on `Customer`, `SalesInvoice`, `SalesChallan`,
`SalesReturn`, `Employee`, `CommissionRule`, `CommissionEntry` — it hooks Eloquent
`created/updated/deleted/restored` events and emits `master_data_*` rows automatically.

**Coverage: FULL but with a critical gap.** `SalesAuditLogger::log()` always fires (the service
methods call it explicitly). However, the `AuditableMasterData` trait is **bypassed** because
the services use `DB::table(…)->insertGetId(…)` and `DB::table(…)->where(…)->update(…)` (raw
queries) instead of Eloquent (gap #11 in §11 — `master_data_*` rows are NEVER written through
the canonical service path). NOTE: this is a SEPARATE concern from the DB-trigger G4 (now
resolved); the trait gap is about the Eloquent-event audit trail, not the hash-chain.

### Layer 3 — Per-module audit-log views

- `admin/sales-invoices/audit` route (`SalesInvoiceController::auditTrail`) — reads
  `user_audit_log` filtered by sales action names, renders `admin.sales-audit.index`.
- `admin/sales-returns/audit` route — return-specific audit.
- Per-invoice / per-challan / per-return show pages display stock_movements + GL journals +
  customer_ledger entries.

### Health-check checklist (3 sections — gap G5 vs PurchaseAuditService's 12)

`ReportController::computeSalesAuditChecks(Carbon $from, Carbon $to)` (private, L1028-1078) is
a **3-section** inline checklist:

1. **Invoices without GL journal entry** — `SELECT COUNT(*) FROM sales_invoices WHERE
   invoice_date BETWEEN ? AND ? AND status NOT IN ('draft','cancelled') AND journal_entry_id IS
   NULL`.
2. **Unbalanced sales journal entries** — `SELECT COUNT(*) FROM (… GROUP BY je.id HAVING
   SUM(jl.debit) <> SUM(jl.credit))`.
3. **Stale draft invoices (>14 days)** — `SELECT COUNT(*) FROM sales_invoices WHERE
   invoice_date < (CURRENT_DATE - INTERVAL '14 days') AND status='draft'`.

Available at `admin/sales-audit-checklist` (HTML). It is **on-demand only** — there is no
scheduled job, no alerting on FAIL items (gap G5 MAJOR — compare to `PurchaseAuditService` with
12 sections + 3 detail tables).

## 2. Why does it exist?

- **Forensic trail.** When something goes wrong (a missing journal entry, an unbalanced JE, a
  stale draft), the audit infrastructure lets the team reconstruct what happened, when, and by
  whom.
- **Invariant detection.** The 3-section checklist catches data-drift issues early — before
  they cascade into a period-close failure or a reconciliation mismatch.
- **Period-close gate input.** The AR reconciliation (`reconcileAP`) depends on `customer_ledger`
  rows written by invoice/payment/return services. The checklist verifies those rows exist and
  are linked to GL journal entries.
- **Compliance evidence.** For SOX-style or VAT audit compliance, the audit log demonstrates
  segregation of duties (the `role:` middleware restricts who can finalize/cancel/confirm) and
  immutability of posted entries (the `is_reversed` flag + reversal-by-appending pattern).
- **Operational health.** The checklist surfaces issues like "invoice confirmed but no journal
  entry" or "stale draft invoices" that indicate a service-layer bug or a manual DB intervention.

## 3. When is it used?

- **Daily operations review.** A manager or accountant opens `admin/sales-invoices/audit` to see
  recent sales events. Any suspicious pattern (e.g. `credit_limit_override` without a clear
  reason) triggers investigation.
- **On cancellation.** `SalesAuditLogger::saleCancelled` / `challanReversed` / `returnReversed`
  / `paymentReversed` capture the cancel reason verbatim.
- **On period close.** `ReconciliationService::reconcileAR` cross-checks the `customer_ledger`
  (debit + credit rows from invoice/payment/return) against the GL `ar` control account. Any
  drift is surfaced via the `ledger` section of the checklist.
- **On forensic investigation.** The `financial_audit_log` hash-chain verification (via the
  `v_financial_audit_chain_verification` view) detects tampering of any of the 14 sales+
  commission tables + `customer_payments` (trigger attached in SALES-3, commit de2b6e6).
  `customer_ledger` is NOT yet covered (accounting sector, separate gap).
- **On auditor request.** External auditors are given read-only access to the per-module audit
  views + the checklist dashboard. There is no separate "auditor" role — auditors use the
  `accountant` role.

## 4. Who uses it?

- **`admin` / `superadmin`** — full access to all audit pages + the checklist.
- **`manager`** — full access to audit pages + the checklist.
- **`accountant`** — full access to audit pages + the checklist (routes use
  `role:accountant,manager,admin`).
- **`warehouse_manager`** — NO access to audit pages or the checklist (segregation: warehouse
  creates, accountant audits).
- **Excluded:** `salesman`, `dispatcher`, `hr`, `user` — no route access.

There is **no dedicated "auditor" role**. The `accountant` role serves as the read-only audit
consumer. There is **no per-row policy gate** on audit-log reads — branch isolation is enforced
via the `branch_id` filter in `user_audit_log` queries (non-admins see only their branch's
entries).

## 5. Related modules

- `sales-overview.md`, `sales-invoice.md`, `sales-challan.md`, `sales-cart.md`,
  `sales-return.md`, `commission.md`, `transport-cost.md` — the audited entities. Each
  documents its own state-machine and audit-log emission.
- `../accounting/financial-audit-log.md` — the hash-chain mechanism. Coverage now FULL for
  the sales ecosystem (trigger attached to 14 sales+commission tables in SALES-3, commit
  de2b6e6). `customer_ledger` (accounting) remains a separate gap.
- `../security/audit-trails.md` — `UserAuditLogger` + `AuditableMasterData` trait. NOTE the
  trait-bypass gap (#11 in §11 — `master_data_*` rows not written for `DB::table()` writes).
  This is a SEPARATE concern from the DB-trigger G4 (now resolved).
- `../accounting/subledger-reconciliation.md` §reconcileAR — depends on `customer_ledger` rows
  written by invoice/payment/return services.
- `../accounting/fiscal-year-period-close.md` — the period-close gate.
- `../security/branch-context-security.md` — branch isolation on audit-log reads.
- `../purchasing/purchase-audit.md` — the 12-section `PurchaseAuditService` template that
  sales-audit should mirror (currently sales has only 3 sections — gap G5).

## 6. Business rules

- **MUST** log every sales state transition via `SalesAuditLogger` (17+ event types, see §1).
- **MUST** capture cancel/confirm reason verbatim. The `$reason` parameter passed to the service
  methods is stored in both the `reverse_reason` column (on the entity row) and the
  `details.reason` field of the audit log entry.
- **MUST** capture `user_id`, `branch_id`, `ip_address`, `user_agent` on every audit entry.
  These are populated by `UserAuditLogger` from the authenticated session + request context.
- **MUST** dual-write `user_audit_log` to DB + `logs/user_audit.log` file (defense in depth).
- **MUST** run the sales audit checklist via the controller (`admin/sales-audit-checklist`) — it
  is NOT a scheduled job.
- **MUST** branch-scope the audit-log reads. Non-admins see only their branch's audit entries
  (filtered by `branch_id` on `user_audit_log`).
- **MUST NOT** rely on `AuditableMasterData` trait alone (gap #11 in §11 — bypassed by
  `DB::table` writes). The `master_data_*` rows are NEVER written for sales mutations through
  the canonical service path. (The DB-trigger audit via `fn_financial_audit_trigger` IS now
  attached — SALES-3 — so direct DB mutations ARE hash-chain-audited regardless of whether
  the trait fires.)
- **MUST NOT** assume `financial_audit_log` covers sales tables (gap G4 — only
  `customer_payments` is covered). [RESOLVED in SALES-3, commit de2b6e6 — trigger now
  attached to all 9 sales tables + 5 commission tables. Kept as a historical note; the
  assumption is now SAFE.]
- **MUST** keep `user_audit_log` partitioned by month. Old partitions can be archived to cold
  storage.
- **MUST NOT** allow UPDATE or DELETE on `financial_audit_log` (REVOKE'd at DB level; the
  trigger function rejects any mutation attempt).
- **MUST** preserve the hash chain — each new `financial_audit_log` row's `prev_hash` is the
  `row_hash` of the previous row. The `v_financial_audit_chain_verification` view detects any
  break.

## 7. Data model

### `user_audit_log` table (DDL: `02_accounting.sql`)

| Column | Type | Purpose |
|---|---|---|
| `id` | bigserial PK | Row identifier |
| `user_id` | integer | The actor (NULL for system-generated events) |
| `branch_id` | integer | The branch context (used for read filtering) |
| `action` | varchar(100) | e.g. `sale_created`, `challan_issued`, `return_confirmed`, `credit_limit_override`, `branch_override` |
| `target_user_id` | integer | The entity ID (misnomer — actually PO/GRN/Return/Invoice ID, not a user ID) |
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
| `table_name` | varchar(100) | The mutated table (e.g. `customer_payments`) |
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
- `REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC;` — even superusers cannot mutate.
- `v_financial_audit_chain_verification` view — recomputes the hash chain and flags any break.

### Trigger attachments

The trigger `trg_audit_<table>` is attached to these 24 tables:

| Table | Sales ecosystem? | Attached by |
|---|---|---|
| `journal_entries` | shared (covers all GL postings) | `02_accounting.sql:446` |
| `journal_lines` | shared (covers all GL postings) | `02_accounting.sql:447` |
| `manual_journals` | accounting | `02_accounting.sql:448` |
| `manual_journal_lines` | accounting | `02_accounting.sql:449` |
| `customer_payments` | **YES** — sales ecosystem | `02_accounting.sql:450` |
| `supplier_payments` | purchasing | `02_accounting.sql:451` |
| `money_transfers` | accounting | `02_accounting.sql:452` |
| `other_incomes` | accounting | `02_accounting.sql:453` |
| `other_expenses` | accounting | `02_accounting.sql:454` |
| `employee_transactions` | accounting | `02_accounting.sql:455` |
| `sales_invoices` | **YES** — sales (partitioned, auto-inherits to partitions) | SALES-3 (de2b6e6) |
| `sales_invoice_items` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_invoice_dispatchers` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_invoice_dispatches` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_challans` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_challan_items` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_draft_carts` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_returns` | **YES** — sales | SALES-3 (de2b6e6) |
| `sales_return_items` | **YES** — sales | SALES-3 (de2b6e6) |
| `commission_rules` | **YES** — sales (commission) | SALES-3 (de2b6e6) |
| `commission_rule_tiers` | **YES** — sales (commission) | SALES-3 (de2b6e6) |
| `commission_rule_product_groups` | **YES** — sales (commission) | SALES-3 (de2b6e6) |
| `commission_rule_targets` | **YES** — sales (commission) | SALES-3 (de2b6e6) |
| `commission_entries` | **YES** — sales (commission) | SALES-3 (de2b6e6) |

**Still NOT attached** (separate gaps, not sales-G4): `customer_ledger` (accounting sector).
Direct DB mutations to `customer_ledger` are NOT hash-chain-audited.

### `SalesAuditLogger` event methods (442 lines)

| Method | Action | When |
|---|---|---|
| `saleCreated` | `sale_created` | Invoice finalize |
| `saleCancelled` | `sale_cancelled` | Invoice cancel |
| `callItADay` | `sale_call_a_day` | Bulk set `call_a_day=true` |
| `paymentReceived` | `payment_received` | Customer payment confirmed |
| `paymentReversed` | `payment_reversed` | Payment cancelled |
| `paymentDiscount` | `payment_discount` | Discount allowed |
| `paymentWriteOff` | `payment_write_off` | Bad debt written off |
| `paymentRefund` | `payment_refund` | Customer refund |
| `returnCreated` | `return_created` | Sales return created |
| `returnConfirmed` | `return_confirmed` | Return confirmed (stock IN + GL) |
| `returnReversed` | `return_reversed` | Return reversed |
| `godownPrepared` | `godown_prepared` | Godown prep (warehouse assigned) |
| `challanIssued` | `challan_issued` | Challan issued (stock OUT + COGS) |
| `challanReversed` | `challan_reversed` | Challan cancelled |
| `cartItemAdded` | `cart_item_added` | Cart item added (R4) |
| `cartItemUpdated` | `cart_item_updated` | Cart item edited (R4) |
| `cartItemRemoved` | `cart_item_removed` | Cart item removed (R4) |
| `cartCleared` | `cart_cleared` | Cart cleared (R4) |

**`recentSalesEvents($limit, $branchId)`** (L405-430) — queries `user_audit_log` for
sales-related actions, branch-scoped. Includes the 4 R4 cart events.

## 8. Lifecycle / workflow

### Audit-trail write flow

```
Service method (e.g. finalizeFromCart)
  ↓
DB::transaction {
  stock movements (challan) + GL journal entry + customer_ledger row + invoice update
  ↓
SalesAuditLogger::saleCreated(userId, invoiceId, ...)
  ↓
UserAuditLogger::log(userId, 'sale_created', invoiceId, details)
  ↓
INSERT INTO user_audit_log (...)         -- DB write
  ↓
File append to logs/user_audit.log       -- file write (defense in depth)
}
```

**NOTE:** `AuditableMasterData::bootAuditableMasterData()` is registered on the model, but the
service uses `DB::table(…)` (raw query), so the Eloquent `created`/`updated` events never fire.
The `master_data_*` row is NOT written (gap G4).

### Checklist run flow

```
Accountant opens admin/sales-audit-checklist (HTML page)
  ↓
ReportController::salesAuditChecklist(from, to)
  ↓
computeSalesAuditChecks(from, to)  -- 3 SQL queries
  ↓
Render admin.reports.sales_audit_checklist with 3 pass/warn/fail badges
```

## 9. Integration points

| Integration | Direction | Purpose |
|---|---|---|
| `SalesAuditLogger::log()` (17 event methods) | outbound | Called by every Sales service method; writes to DB + file |
| `UserAuditLogger::log(userId, action, targetUserId, details)` | outbound | Dual-write to `user_audit_log` DB + `logs/user_audit.log` file |
| `AuditableMasterData` trait | outbound | Hooks Eloquent events on 7 models (BYPASSED — gap #11 in §11, NOT G4) |
| `ReportController::computeSalesAuditChecks(from, to)` | outbound | 3-section invariant checker |
| `SalesInvoiceController::auditTrail(Request)` | outbound | `admin.sales-audit.index` view (reads `user_audit_log`) |
| `ReconciliationService::reconcileAR` | inbound | Consumes `customer_ledger` rows; output feeds the checklist's `ledger` section |
| `v_financial_audit_chain_verification` view | outbound | Forensic hash-chain check (covers 14 sales+commission tables + `customer_payments` — SALES-3) |
| `EnforceBranchIsolation::logBranchOverrideIfCrossBranch` | outbound | Logs admin cross-branch operations as `branch_override` action |
| `logs/user_audit.log` file | outbound | Defense-in-depth file copy of every `user_audit_log` row |

## 10. Edge cases

- **Direct SQL mutation** (e.g. a DBA running `UPDATE sales_invoices SET total_amount = ...`).
  Bypasses `SalesAuditLogger` (no service method called) AND `AuditableMasterData` (no Eloquent
  event). BUT — as of SALES-3 (commit de2b6e6) — `fn_financial_audit_trigger` IS now attached
  to all 14 sales+commission tables, so the mutation IS captured in `financial_audit_log` with
  a hash-chained `before_data`/`after_data` snapshot. The forensic trail is intact. (Previously
  gap G4 — invisible to all three audit layers; now only invisible to layers 2 + the trait.)
- **Cancel inside a `DB::transaction` that later rolls back.** `SalesAuditLogger::log()` runs
  inside the transaction. If the transaction rolls back, the audit-log row is also rolled back
  (the mutation did not happen, so the audit entry should not exist either). This is correct.
- **`AuditableMasterData` re-throws on transaction level > 0.** The trait detects when it is
  inside a nested transaction (`DB::transactionLevel() > 0`) and defers the audit-log write
  until the outer transaction commits. This avoids writing audit rows for mutations that later
  roll back. (This is the trait's behaviour on models where it is NOT bypassed — e.g. `Customer`
  when updated via Eloquent. It does not help the Sales services because they bypass the trait
  entirely with `DB::table()` writes.)
- **Branch override by admin.** An admin user can read/write sales entities in any branch. The
  `EnforceBranchIsolation` middleware logs this as a `branch_override` action in
  `user_audit_log`. The audit team can query for `action = 'branch_override'` to review all
  cross-branch interventions.
- **Cart mutation events (R4).** `cart_item_added`, `cart_item_updated`, `cart_item_removed`,
  `cart_cleared` are emitted by `SalesAuditLogger` but NOT shown in the `auditTrail` web view
  (gap G9 — the controller inlines its own action list that omits them). Use
  `SalesAuditLogger::recentSalesEvents` (which includes them) for a complete view.
- **Commission events.** `commission_rule_created`, `commission_calculated`,
  `commission_reversed_on_return`, `commission_reversed_on_payment_reversal`,
  `commission_period_confirmed` are emitted by `CommissionService` and NOW fire (gap G2
  resolved in SALES-2, commit 2f686c0 — the auto-calc pipeline is wired).
- **Stale-draft cleanup.** `stale_drafts_cancelled` event (bulk action) — emitted by the
  `CancelStaleSalesDrafts` command nightly.
- **Call-it-a-day.** `sale_call_a_day` event captures the bulk invoice_ids + updated_count.

## 11. Gaps

1. **G4 (CRITICAL — RESOLVED)** — `fn_financial_audit_trigger` is now attached to ALL 9 sales
   tables + 5 commission tables (14 total) via migration `2026_09_01_000002` (SALES-3, commit
   de2b6e6). Only `customer_payments` of the sales ecosystem was previously hash-chain-audited;
   now all 14 sales+commission tables are. Direct DB mutations to these tables are captured in
   `financial_audit_log` with hash-chained before/after snapshots.
   > ✅ RESOLVED in SALES-3 (commit de2b6e6) — migration
   > `2026_09_01_000002_attach_financial_audit_trigger_to_sales_tables.php` attaches
   > `trg_audit_<table>` to 9 core sales tables (`sales_invoices`, `sales_invoice_items`,
   > `sales_invoice_dispatchers`, `sales_invoice_dispatches`, `sales_challans`,
   > `sales_challan_items`, `sales_draft_carts`, `sales_returns`, `sales_return_items`) + 5
   > commission tables (`commission_rules`, `commission_rule_tiers`,
   > `commission_rule_product_groups`, `commission_rule_targets`, `commission_entries`).
   > `sales_invoices` is partitioned — PG 12+ auto-inherits the trigger to all existing +
   > future monthly partitions. Idempotent (DROP TRIGGER IF EXISTS before CREATE). up()
   > verifies the function exists before attaching.
   >
   > `customer_ledger` (accounting sector) is NOT covered by SALES-3 — it's a separate gap
   > in the finance/accounting sector, not the sales cluster.
2. **G5 (MAJOR)** — Sales audit checklist has only **3 sections** vs `PurchaseAuditService`'s
   12. Missing sections: missing COGS journals on challans, missing return journals, missing
   customer_ledger links, commission reconciliation, transport adjustment integrity, RLS bypass
   detection, payment-AR drift, sales return reversal integrity, stale draft cleanup
   verification. No `SalesAuditService` class — checklist logic lives inline in
   `ReportController::computeSalesAuditChecks`.
3. **G9 (MAJOR)** — `SalesInvoiceController::auditTrail` inlines its own action list that
   OMITS the 4 R4 cart events (`cart_item_added`, `cart_item_updated`, `cart_item_removed`,
   `cart_cleared`) that `SalesAuditLogger::recentSalesEvents` properly includes. The audit-trail
   web view silently hides cart tampering events.

   > ✅ RESOLVED in SALES-AUDIT-2 — `SalesInvoiceController::auditTrail`
   > action list now mirrors `SalesAuditLogger::recentSalesEvents()` exactly
   > + adds the 5 commission events that fire since SALES-2 wired the
   > auto-calc pipeline. The inline `whereIn` array was expanded from 14
   > actions to 26:
   > - Added 3 payment allocation sub-types: `payment_discount`,
   >   `payment_write_off`, `payment_refund` (were in `recentSalesEvents`
   >   but omitted from the controller)
   > - Added 4 R4 cart events: `cart_item_added`, `cart_item_updated`,
   >   `cart_item_removed`, `cart_cleared` (the gap G9 specifically called
   >   these out — cart tampering events were silently hidden)
   > - Added 5 commission events: `commission_rule_created`,
   >   `commission_calculated`, `commission_reversed_on_return`,
   >   `commission_reversed_on_payment_reversal`,
   >   `commission_period_confirmed` (fire since SALES-2 commit 2f686c0)
   > The `$actionLabels` display map was expanded with matching labels,
   > icons, + colors for all 12 new actions. The blade view uses
   > `$actionLabels` dynamically (no blade changes needed). The audit-trail
   > web view now shows the complete sales event timeline — no more silent
   > gaps for cart tampering or commission lifecycle events.
4. **Commission events NEVER fire** (gap G2 in `commission.md`) — the 5 commission audit events
   are dead code.
5. **No scheduled job for the checklist.** The checklist runs only on-demand. A FAIL item (e.g.
   missing GL journal, unbalanced JE) can persist for days before someone opens the dashboard.
6. **No alerting on FAIL items.** Even when the checklist is run, FAIL items do not trigger an
   email, SMS, or Slack notification. The operations team must visually inspect the dashboard.
7. **No "auditor" role.** Auditors use the `accountant` role, which also has write access to
   customer payments. True segregation of duties would require a read-only `auditor` role.
8. **`user_audit_log.target_user_id` is misnamed for sales events.** The column was originally
   designed for user-management events. For sales events, it stores the invoice/challan/return
   ID — confusing for queries. The `details` jsonb also stores the ID under a meaningful key
   (`invoice_id` / `challan_id` / `return_id`).
9. **No drill-down from checklist item to affected rows.** The checklist emits
   `{level: 'fail', count: 5, message: '...'}` but does not link to the 5 affected invoices.
   The user must manually query `sales_invoices` to find them.
10. **`saleUpdated` audit event documented but NO `SalesAuditLogger::saleUpdated()` method**
    (gap G14 in `sales-invoice.md`). `updateInvoice` writes directly to `user_audit_log` via
    `DB::table()->insert()`.
11. **AuditableMasterData bypass** — the trait is `use`d on 7 sales-related models but bypassed
    by `DB::table()` raw writes in the sales services. `master_data_*` rows are NEVER written
    through the canonical service path.

## 12. Review checklist

- [ ] Every sales state transition emits a `SalesAuditLogger` entry with action prefix
      `sale_*` / `challan_*` / `return_*` / `cart_*` / `payment_*`. Verify by reading each
      service method.
- [ ] Cancel/confirm reasons are captured verbatim in the audit log `details.reason` field AND
      on the entity row (`reverse_reason` column).
- [ ] The sales audit checklist covers 3 sections (missing GL, unbalanced JE, stale drafts).
      Document the gap (G5) vs `PurchaseAuditService`'s 12 sections.
- [ ] Branch isolation is enforced on audit-log reads (non-admins see only their branch's
      entries — verify the `branch_id` filter in `SalesInvoiceController::auditTrail`).
- [x] Gap G4 (no `fn_financial_audit_trigger` on sales tables) — RESOLVED in SALES-3
      (commit de2b6e6). Trigger now attached to all 9 sales tables + 5 commission tables.
      Performance impact: same per-write hash-chain lookup as the existing 10 audited tables
      (BRIN-indexed). Monitor write-latency on highest-frequency tables
      (`sales_draft_carts`, `sales_invoice_items`); if needed, a future migration can move
      those to an async audit queue.
- [ ] Gap #11 (`AuditableMasterData` bypass) is documented. Confirm the audit team is aware
      that `master_data_*` rows are NOT written for sales mutations through the service path.
      (This is a SEPARATE concern from the DB-trigger G4, now resolved.)
- [ ] Gap G9 (cart events omitted from `auditTrail` web view) is documented.
- [ ] The `v_financial_audit_chain_verification` view is run on a schedule (DBA responsibility)
      to detect tampering of `customer_payments` rows. Confirm the schedule.
- [ ] The `logs/user_audit.log` file is rotated and archived (otherwise it grows unbounded).
      Confirm the log-rotation config in `config/logging.php`.
- [ ] Consider creating a `SalesAuditService` class with at least 8-10 sections (mirroring
      `PurchaseAuditService`) + a scheduled job to run the checklist daily and email FAIL items
      to the operations team (gaps G5 + #5 + #6).

## 13. Cross-references

- `sales-overview.md` — module map.
- `sales-invoice.md`, `sales-challan.md`, `sales-cart.md`, `sales-return.md`, `commission.md`,
  `transport-cost.md` — each documents its own audit-log emission.
- `../accounting/financial-audit-log.md` — the hash-chain mechanism; NOTE the partial-coverage
  gap (G4).
- `../security/audit-trails.md` — `UserAuditLogger` + `AuditableMasterData` trait; NOTE the
  bypass gap (G4).
- `../accounting/subledger-reconciliation.md` §reconcileAR — depends on `customer_ledger` rows.
- `../accounting/fiscal-year-period-close.md` — the period-close gate.
- `../security/branch-context-security.md` — branch isolation on audit-log reads.
- `../security/rbac-roles-permissions.md` — the role matrix for sales routes (no dedicated
  `auditor` role).
- `../purchasing/purchase-audit.md` — the 12-section `PurchaseAuditService` template that
  sales-audit should mirror (gap G5).
