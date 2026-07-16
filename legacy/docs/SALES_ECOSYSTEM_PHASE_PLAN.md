# Sales Ecosystem — Deep Dive & Phase-by-Phase Improvement Plan

**Project:** Remote Center ERP  
**Scope:** Sales, Challan/Godown, Sales Returns, Payments, Stock, Customer Ledger, GL  
**Goal:** Secure, lightweight, error-free stock and accounting  
**Last updated:** 2026-06-03

---

## Part A — Deep dives (three slices)

### A1. Challan reversal (`ChallanModel::reverseChallan`)

**Route:** `POST /challan/reverse_challan` → `ChallanController::reverse_challan` → `ChallanModel::reverseChallan($invoiceId, $reason)`

#### Preconditions (guards)

| Check | Behavior |
|--------|----------|
| `invoiceId > 0`, reason ≥ 5 chars | Else error |
| Invoice exists, `is_reversed = 0` | `FOR UPDATE` lock |
| `assertInvoiceAccessible(branch_id)` | Branch scope |
| `status === 'challan_completed'` | Only completed challans |
| No completed sales returns on invoice | Blocks if `sales_returns.status = completed` |
| Active `sales_challans` row (`is_reversed = 0`) | Latest challan used |

#### What runs inside the transaction

1. **Stock restore** — For each dispatch with `dispatched_qty > 0`:
   - `updateWarehouseStock(+qty)` at current `avg_cost` (moving average; may differ from issue cost if purchases happened since challan).
   - `stock_transactions` log with `reference_type = sales_challan_reversal`.
   - Reset dispatch: `dispatched_qty = 0`, `dispatched_ctn = 0`, `dispatched_at = NULL`.

2. **Challan record** — `sales_challans.is_reversed = 1`, `reversed_at`, `reversed_by`, `reverse_reason`.

3. **Invoice status** — `status = 'godown_issued'`, `challan_completed_at = NULL` (invoice **stays**; AR/revenue journal from invoice **not** reversed).

4. **GL** — Only reverses `sales_challans.journal_entry_id` (COGS entry) via `JournalPostingService::reverseLinkedJournal` (swap debits/credits on original lines).

#### What is **not** reversed (gaps to fix in Phase 3)

| Item | Risk |
|------|------|
| Original invoice journal (Dr AR / Cr Revenue) | Customer still owes full invoice amount — **by design** if you only undo delivery |
| `postSalesInvoiceTotalAdjustment` journal (transport change at challan) | If transport was changed at challan, AR/revenue adjustment JE may stay posted while invoice total on header is unchanged on reversal |
| `customer_ledger` rows for transport delta (`applyCustomerLedgerDelta`) | Sub-ledger may not match invoice `total_amount` after reversal |
| `sales_invoice.total_amount` / `transport_cost` | Not rolled back to pre-challan values |
| Pipeline reservation | `ordered_qty` still set; `warehouse_id` on dispatches kept — OK for re-challan |

#### COGS reversal nuance

- Issue used `avg_cost` at challan time; restore uses **current** `getWarehouseAvgCost`. Small valuation drift is possible under active purchasing.

---

### A2. Sales return confirm — GL & ledger (`SalesReturnModel::confirmReturn`)

**Routes:**

- `GET /salesreturn/confirm/{id}` — UI
- `POST /salesreturn/confirm_store` — `SalesReturnController::confirm_store` → `confirmReturn`

#### Phase 1 — Create return (no GL, no stock, no customer ledger)

- `POST /salesreturn/store` → `createReturn`
- Inserts `sales_returns` (`status = pending`), `sales_return_items`
- Validates `return_qty ≤ getMaxReturnableQty` per invoice line

#### Phase 2 — Warehouse confirm (economic + stock impact)

For each line (`confirm_store` payload):

| Step | Good condition | Damage condition |
|------|----------------|------------------|
| Update line | `warehouse_id`, qty, rate, amount, `confirmed_at` | Same |
| Stock IN | `updateWarehouseStock(+qty, avgCost)`; COGS accumulates `qty × avgCost` | No stock IN; movement logged with `qty = 0` |
| `stock_transactions` | `reference_type = sales_return` | Remark: Damaged |

Then:

1. Recompute `sales_returns.total_amount` from confirmed lines.
2. **`customer_ledger` CREDIT** — `updateCustomerLedgerForReturn` → `reference_type = 'sales_return'`, reduces customer balance.
3. **`sales_returns.status = 'completed'`**, `confirmed_by`, `confirmed_at`.
4. **GL** — `JournalPostingService::postSalesReturn`:

```
Revenue reversal (revenue_amount = confirmed total):
  Dr  Sales Return & Allowances (nature: sales_return)
  Cr  Accounts Receivable (nature: customer_receivable)  [entity: customer]

COGS reversal (cogs_amount = sum of Good stock at avg cost):
  Dr  Inventory (nature: inventory)
  Cr  COGS (nature: cogs)
```

5. Store `sales_returns.journal_entry_id`.

#### Return reversal (`reverseReturn`)

- Reverses GL via `reverseLinkedJournal` if `journal_entry_id` set.
- If was `completed`: customer ledger **DEBIT** with `reference_type = 'sales_return_reversal'` ⚠️ (see Phase 2 — enum bug).
- Stock OUT via `reverseConfirmedReturnStock` (reads `stock_transactions` for `sales_return`).
- Sets `status = 'reversed'`, `is_reversed = 1`.

#### Confirm GL example (invoice line 10 @ rate 2, qty 2 Good, avg cost 1.50)

| Account | Debit | Credit |
|---------|------:|-------:|
| Sales Return | 4.00 | |
| AR (control) | | 4.00 |
| Inventory | 3.00 | |
| COGS | | 3.00 |

Customer sub-ledger: **credit 4.00**. Physical stock: **+2 units**.

---

### A3. Route map — every `sales*` URL

Routing: `public/index.php` → `{controller}/{method}/{params}` (Auth required except `AuthController`).

**Aliases:** `save-fcm-token` / `save_fcm_token` → `sales/save_fcm_token`

Base URL pattern: `{BASE_URL}{controller}/{method}/...`

#### `SalesController` — prefix `/sales/`

| URL | Method | HTTP | CSRF | Purpose |
|-----|--------|------|------|---------|
| `/sales/create` | `create` | GET | — | New invoice POS page |
| `/sales/search_customer` | `search_customer` | GET | — | Autocomplete customers |
| `/sales/search_product` | `search_product` | GET | — | Products + branch available qty |
| `/sales/get_branch` | `get_branch` | GET | — | Branch list (session or all) |
| `/sales/product_stock_at_branch` | `product_stock_at_branch` | GET | — | Available qty one product |
| `/sales/get_warehouse_stock` | `get_warehouse_stock` | GET | — | Warehouse-wise stock modal |
| `/sales/get_employees` | `get_employees` | GET | — | Salesmen dropdown |
| `/sales/customer_details` | `customer_details` | GET | — | Credit limit, due |
| `/sales/add_to_cart` | `add_to_cart` | POST | ✓ | Session cart add |
| `/sales/load_cart` | `load_cart` | POST | ✓ | Load cart for customer |
| `/sales/list_draft_carts` | `list_draft_carts` | GET | — | Multi-customer draft tabs |
| `/sales/clear_tab_cart` | `clear_tab_cart` | POST | ✓ | Clear one customer cart |
| `/sales/delete_tab_cart` | `delete_tab_cart` | POST | ✓ | Alias clear cart |
| `/sales/delete_from_cart` | `delete_from_cart` | POST | ✓ | Remove line |
| `/sales/update_cart_item` | `update_cart_item` | POST | ✓ | Edit qty/rate |
| `/sales/save_fcm_token` | `save_fcm_token` | POST | — | Push token (JSON body) |
| `/sales/final_sales` | `final_sales` | POST | ✓ | **Finalize invoice** (ledger + GL) |
| `/sales/update/{id}` | `update` | POST | ✓ | Edit draft invoice |
| `/sales/today` | `today` | GET | — | Today’s invoices UI |
| `/sales/today_filter_summary` | `today_filter_summary` | GET | — | Status chip counts |
| `/sales/datatable_invoices` | `datatable_invoices` | GET | — | DataTables JSON |
| `/sales/call_it_a_day` | `call_it_a_day` | POST | ✓ | Hide from today list |
| `/sales/delete_invoice` | `delete_invoice` | POST | ✓ | Soft-delete draft |
| `/sales/edit/{id}` | `edit` | GET | — | Edit invoice page |
| `/sales/get_invoice_for_edit/{id}` | `get_invoice_for_edit` | GET | — | JSON for edit loader |
| `/sales/receive_modal/{id}` | `receive_modal` | GET | — | Payment modal HTML |
| `/sales/save_payment` | `save_payment` | POST | ✓ | Record payment + GL |
| `/sales/reverse_payment` | `reverse_payment` | POST | ✓ | Undo payment |
| `/sales/audit` | `audit` | GET | — | User audit log filter |
| `/sales/print_receipt/{id}` | `print_receipt` | GET | — | Payment receipt print |
| `/sales/invoice_copy/{id}` | `invoice_copy` | GET | — | Printable invoice |
| `/sales/export` | `export` | GET | — | CSV today list ⚠️ SQL bug |

#### `ChallanController` — prefix `/challan/`

| URL | Method | HTTP | CSRF | Purpose |
|-----|--------|------|------|---------|
| `/challan/index` | `index` | GET | — | Godown/challan queue |
| `/challan/filter_summary` | `filter_summary` | GET | — | Filter counts |
| `/challan/datatable_challans` | `datatable_challans` | GET | — | DataTables |
| `/challan/create/{id}` | `create` | GET | — | Godown/challan workspace |
| `/challan/challan_copy/{id}` | `challan_copy` | GET | — | Print challan |
| `/challan/godown_copy/{id}` | `godown_copy` | GET | — | Print godown |
| `/challan/print_blank_godown_copy/{id}` | `print_blank_godown_copy` | GET | — | Blank godown form |
| `/challan/prepare_godown` | `prepare_godown` | POST | ✓ | Assign warehouses (reservation) |
| `/challan/create_final_challan` | `create_final_challan` | POST | ✓ | Issue stock + COGS GL |
| `/challan/reverse_challan` | `reverse_challan` | POST | ✓ | Undo challan (stock + COGS) |
| `/challan/get_warehouses_for_product` | `get_warehouses_for_product` | GET | — | Warehouse picker API |
| `/challan/get_dispatchers` | `get_dispatchers` | GET | — | Dispatcher employees |
| `/challan/export` | `export` | GET | — | CSV export |

#### `SalesReturnController` — prefix `/salesreturn/`

| URL | Method | HTTP | CSRF | Purpose |
|-----|--------|------|------|---------|
| `/salesreturn/index` | `index` | GET | — | Returns list |
| `/salesreturn/return_filter_summary` | `return_filter_summary` | GET | — | Filter counts |
| `/salesreturn/datatable_returns` | `datatable_returns` | GET | — | DataTables |
| `/salesreturn/create` | `create` | GET | — | New return form |
| `/salesreturn/search_invoice` | `search_invoice` | GET | — | Find invoice |
| `/salesreturn/get_invoice_for_return` | `get_invoice_for_return` | GET | — | Lines + returnable qty |
| `/salesreturn/store` | `store` | POST | ✓ | Create pending return |
| `/salesreturn/confirm/{id}` | `confirm` | GET | — | Warehouse confirm UI |
| `/salesreturn/confirm_store` | `confirm_store` | POST | ✓ | **Confirm** (ledger + GL + stock) |
| `/salesreturn/slip/{id}` | `slip` | GET | — | Print return slip |
| `/salesreturn/reverse/{id}` | `reverse` | GET/POST | ✓ | Reverse return UI/action |
| `/salesreturn/audit` | `audit` | GET | — | Return audit logs |
| `/salesreturn/export` | `export` | GET | — | CSV export |

#### `SalesAuditController` — prefix `/salesaudit/`

| URL | Method | HTTP | Purpose |
|-----|--------|------|---------|
| `/salesaudit/checklist` | `checklist` | GET | Health-check UI |
| `/salesaudit/run_checks` | `run_checks` | GET/POST | Run repairs + report |

---

## Part B — Known defects (fix in phases below)

| ID | Severity | Area | Issue |
|----|----------|------|--------|
| D1 | **Critical** | Security | FCM server key hardcoded in `SalesController::sendFCMPush` |
| D2 | **Critical** | Security | `CURLOPT_SSL_VERIFYPEER => false` on FCM curl |
| D3 | **High** | SQL | `SalesModel::getFilteredTodayInvoices()` broken WHERE (export fails) |
| D4 | **High** | DB | ~~`customer_ledger.reference_type` enum drift~~ — fixed Phase 2 (011 + VARCHAR 012) |
| D5 | **High** | Accounting | Challan reversal does not undo transport/total adjustment (GL + customer_ledger + `total_amount`) |
| D6 | **Medium** | Stock | No row lock at invoice finalize; race on available qty |
| D7 | **Medium** | Stock | Product search JOIN can mis-count pending without warehouse alignment |
| D8 | **Medium** | Accounting | Single AR control account; no automated reconciliation vs `customer_ledger` |
| D9 | **Medium** | Ops | Session cart lost on timeout; no DB draft |
| D10 | **Low** | Naming | ~~Payment codes `PAY-Ymd-time`~~ — fixed Phase 2 (`document_sequences`) |
| D11 | **Low** | Maintainability | `SalesModel` + `Helper` god object; notification logic in controller |

---

## Part C — Phase-by-phase execution plan

Work phases in order. Each phase has **exit criteria** — do not start the next until met.

---

### Phase 0 — Safety & quick wins (1–2 days) ✅ *Implemented 2026-06-03*

**Objective:** Stop data corruption and credential leaks without redesign.

| # | Task | Files / action | Exit criteria | Status |
|---|------|----------------|---------------|--------|
| 0.1 | Move FCM key to env | `config/config.php`, `config/local.php.example`, `SalesController` | No secrets in repo; key from `FCM_SERVER_KEY` | ✅ |
| 0.2 | Enable SSL verify on FCM | `SalesController::sendFCMPush` | `CURLOPT_SSL_VERIFYPEER true`; handle curl errors | ✅ |
| 0.3 | Fix export SQL | `SalesModel::getFilteredTodayInvoices` | Remove duplicate `si.branch_id = '...'` fragment; use bindings only | ✅ |
| 0.4 | Extend `customer_ledger.reference_type` | `database/migrations/011_extend_customer_ledger_reference_type.sql` | New enum values for challan/payment/return reversals | ✅ (run migration on each DB) |
| 0.5 | Smoke test | Manual | Export CSV works; transport at challan posts ledger; return reverse posts ledger | ⏳ manual |

---

### Phase 1 — Security hardening (3–5 days) ✅ *Implemented 2026-06-03*

**Objective:** Production-safe perimeter and secrets.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 1.1 | Extract `SalesNotificationService` from controller | `app/services/Notification/*` | ✅ |
| 1.2 | CSRF on `save_fcm_token` if session-based | JSON body + `X-CSRF-Token` | ✅ |
| 1.3 | Rate-limit or auth-check JSON endpoints (`search_*`, `datatable_*`) | `guardJsonApi()` + `docs/SALES_API_ACCESS.md` | ✅ |
| 1.4 | Audit all `sendJson` / `die()` paths for info leakage | `APP_ENV` / `safeClientMessage`; sales `die()` → `abortPage` | ✅ (sales controller) |
| 1.5 | `.env` example without real keys | `docs/ENV.example` | ✅ (Phase 0) |
| 1.6 | Rotate exposed FCM key | New key in Firebase console | ⏳ manual (you) |

---

### Phase 2 — Ledger & enum integrity (3–5 days) ✅ *Implemented 2026-06-03*

**Objective:** Customer sub-ledger always consistent and valid.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 2.1 | `reference_type` → `VARCHAR(30)` | Migration `012`; challan/return/payment types post without enum errors | ✅ (run migrations) |
| 2.2 | Return/payment reversals use `reference_type = 'reversal'` | `SalesReturnModel`, `SalesModel`, `CustomerTransactionModel` | ✅ |
| 2.3 | `customer_ledger.branch_id` on every insert | `Helper::insertCustomerLedgerEntry`; migration `013` backfill | ✅ |
| 2.4 | Integrity: last `running_balance` vs SUM(debit−credit) | `Helper::getCustomerLedgerBalanceMismatches`; UI `sales/reconcile` | ✅ |
| 2.5 | Payment codes via `document_sequences` | `PAY-YYYYMMDD-####`; migration `014` seeds from legacy codes | ✅ |
| 2.6 | AR sub-ledger vs GL control | `ReconciliationService`; audit checklist + `sales/reconcile` | ✅ |

**Run on each DB:** `php database/run_migrations.php` (applies 011–014 including enum, backfill, sequence seed).

---

### Phase 3 — Challan reversal & transport correctness (5–7 days) ✅ *Implemented 2026-06-03*

**Objective:** Undo delivery without leaving AR/transport inconsistent.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 3.1 | Snapshot `pre_challan_total` / `pre_challan_transport` on invoice at challan | Migration `017`; set in `finalizeChallan` | ✅ |
| 3.2 | Reverse `sales_invoice_adjustment` JE on challan reverse | `adjustment_journal_entry_id` or find by reference | ✅ |
| 3.3 | Offset `customer_ledger` `invoice_adjustment`; mark originals reversed | `reverseChallanTransportLedger` | ✅ |
| 3.4 | Restore `transport_cost` and `total_amount` from pre-challan snapshot | `reverseChallan` UPDATE invoice | ✅ |
| 3.5 | Stock restore at **issue rate** from `stock_transactions` | `restoreStockFromChallanIssue` | ✅ |
| 3.6 | Smoke script | `database/tests/challan_reversal_smoke.php` | ✅ |

**Run:** `php database/run_migrations.php` (applies `017`). Manual: challan with transport change → reverse → verify totals/AR → re-challan.

---

### Phase 4 — Stock accuracy & concurrency (5–8 days) ✅ *Implemented 2026-06-03*

**Objective:** Error-free available qty and physical stock.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 4.1 | `FOR UPDATE` on branch `warehouse_stock` at finalize + re-check in TX | `StockService::lockBranchProductsForUpdate` | ✅ |
| 4.2 | Default `warehouse_id` on dispatches at finalize | First active warehouse per branch | ✅ |
| 4.3 | Fix product search pending join | `Search_Product_With_Stock` subquery; branch filter on pending | ✅ |
| 4.4 | Stale draft cleanup | `SALES_STALE_DRAFT_DAYS`; CLI + `SalesAudit/cancel_stale_drafts` | ✅ |
| 4.5 | DB trigger: no negative `warehouse_stock.qty` | Migration `018` | ✅ |
| 4.6 | `StockService` SSOT | Sales, challan, return use shared service in TX | ✅ |

**Ops:** `php database/scripts/cancel_stale_sales_drafts.php` (cron weekly). Run migration `018`.

---

### Phase 5 — GL control & reconciliation (7–10 days) — **COMPLETED** (2026-06-03)

**Objective:** Accounts match operations.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 5.1 | Scheduled reconciliation job | AR GL balance ≈ sum(customer_ledger net) by branch | ✅ `database/scripts/run_gl_reconciliation.php` |
| 5.2 | Inventory GL vs `sum(qty * avg_cost)` by branch | Report with tolerance | ✅ `ReconciliationService::runFullReport` + `sales/reconcile` |
| 5.3 | COGS tie-out: challan COGS JE vs `stock_transactions` for period | Document timing (invoice date vs challan date) | ✅ period filter on reconcile UI |
| 5.4 | Extend `SalesAuditModel::runHealthChecks` to email/admin on `fail > 0` | Ops visibility | ✅ `notifyAuditFailures` + `logs/reconciliation_alerts.log` |
| 5.5 | Per-bank ledger mapping table (replace single Bank Control) | `postCustomerPayment` uses mapped ledger | ✅ migration `019`, `bank/edit` GL dropdown |
| 5.6 | Discount line: optional separate ledger on invoice post | TB shows gross discount if required | ✅ `sales_discount` ledger + `postSalesInvoice` |

**Ops:** `php database/run_migrations.php` (through `019`). Cron: `php database/scripts/run_gl_reconciliation.php`. Optional `RECON_ALERT_EMAIL` in `config/local.php`.

---

### Phase 6 — Lightweight architecture (10–15 days) — **COMPLETED** (2026-06-03)

**Objective:** Smaller files, faster loads, easier testing.

| # | Task | Exit criteria | Status |
|---|------|---------------|--------|
| 6.1 | Split `SalesModel` → services | Controller calls `SalesCartService`, `SalesInvoiceService`, `SalesPaymentService` | ✅ traits + thin `SalesModel` |
| 6.2 | `StockAvailabilityService` | SSOT for branch/warehouse available qty | ✅ `Helper` + `StockService` delegate |
| 6.3 | DB-backed draft carts | Optional `SALES_DB_DRAFT_CARTS=1` + migration `020` | ✅ |
| 6.4 | Defer FCM on non-warehouse pages | `main.php` loads `notification.js` only on warehouse/challan/godown/sales/today | ✅ |
| 6.5 | Script tests | `database/tests/sales_core_smoke.php` | ✅ |
| 6.6 | `Logger` with levels | `core/Logger.php`; sales/FCM use structured logs | ✅ |

**Ops:** `php database/run_migrations.php` (through `020`). Optional: `SALES_DB_DRAFT_CARTS=1` in env/local.php.

---

### Phase 7 — Polish & compliance — **COMPLETED** (2026-06-03)

| # | Task | Status |
|---|------|--------|
| 7.1 | Role matrix document | ✅ `docs/SALES_ROLE_MATRIX.md` + `app/config/route_roles.php` + `RouteAccess` |
| 7.2 | Standardize JSON errors | ✅ `core/ApiResponse.php`; `sendJson` / CSRF / auth normalized |
| 7.3 | Align invoice codes | ✅ `database/scripts/migrate_legacy_invoice_codes.php` (dry-run default) |
| 7.4 | API versioning | ✅ `X-API-Version` + `docs/API_VERSIONING.md` |
| 7.5 | Backup + restore drill | ✅ `database/scripts/backup_accounting_core.php` + `docs/ACCOUNTING_BACKUP_RESTORE.md` |

**Ops:** Review role matrix before go-live. Run invoice migration dry-run, then `--apply` on staging. Schedule weekly accounting backup.

---

## Part D — Verification checklist (run after each phase)

### Stock

- [ ] Create invoice for qty Q; available decreases by Q before challan
- [ ] Complete challan; physical `warehouse_stock` decreases by Q
- [ ] Reverse challan; physical stock restored; available correct
- [ ] Return Good qty; stock increases; Damaged does not
- [ ] Two users cannot finalize same product over available (Phase 4+)

### Customer ledger

- [ ] Invoice → debit; payment → credit; return confirm → credit; reversals offset
- [ ] `running_balance` equals manual sum for test customer
- [ ] Transport change at challan updates balance once, reversed cleanly (Phase 3+)

### GL

- [ ] Every `sales_invoices.journal_entry_id` (posted invoice) balances debits=credits
- [ ] Every completed challan has COGS JE or explicit zero-COGS reason
- [ ] Payment, return, reversal create reversing JEs; originals marked `is_reversed`
- [ ] TB: AR, Inventory, Sales, COGS move correctly on full cycle

### Security

- [ ] No API keys in git
- [ ] CSRF on all POST mutators
- [ ] Branch isolation: user cannot open other branch invoice by ID

---

## Part E — Suggested file layout (after Phase 6)

```
app/
  services/
    Sales/
      SalesInvoiceService.php
      SalesCartService.php
      SalesPaymentService.php
      ChallanFulfillmentService.php
      SalesReturnService.php
    Stock/
      StockAvailabilityService.php
      StockMovementService.php
    Accounting/
      JournalPostingService.php   (existing)
    Notification/
      SalesNotificationService.php
  models/                         (thin PDO repositories only)
```

---

## Part F — Priority order (if time is limited)

1. **Phase 0** — secrets, export SQL, ledger enum (blocks correctness)  
2. **Phase 3** — challan reversal + transport (prevents AR drift)  
3. **Phase 4** — stock concurrency (prevents overselling)  
4. **Phase 2** — ledger hygiene  
5. **Phase 5** — reconciliation  
6. **Phase 6** — refactor for maintainability  

---

## References in codebase

| Topic | Location |
|-------|----------|
| Challan finalize / reverse | `app/models/ChallanModel.php` |
| Return confirm GL | `app/models/SalesReturnModel.php` → `confirmReturn` |
| GL posting policy | `app/services/Accounting/JournalPostingService.php` |
| Stock SSOT | `app/helpers/Helper.php` (`Get_Product_Total_Available_Stock`, etc.) |
| Health checks | `app/models/SalesAuditModel.php` |
| Prior modernization notes | `docs/SALES_MODULE_MODERNIZATION_PLAN.md`, `docs/ACCOUNTING_PROGRESS.md` |

---

*This document is the working backlog for sales ecosystem hardening. Update checkboxes and phase status as tasks complete.*