# Today Invoice — Business Analysis (Legacy vs Laravel)

> **Role:** Senior ERP Software Architect & Laravel Migration Engineer
> **Scope:** Only the **Today Invoice** menu. No other module is analyzed.
> **Source of truth:** Actual source code in `/home/z/my-project/debugRC/legacy` (Project A — PHP MVC + MySQL) and `/home/z/my-project/debugRC/laravel` (Project B — Laravel 12 + PostgreSQL + Tailwind).
> **Method:** Every conclusion below is verified from the code. `✅` = Fully Implemented, `🟡` = Partially Implemented, `❌` = Missing.

---

## 1. Overview

The **Today Invoice** menu is the daily collection workflow screen used by salesmen, accountants, managers and admins to:

1. See the invoices they are responsible for collecting payment on.
2. Record customer payments against those invoices (with GL + customer ledger + intercompany settlement).
3. Mark invoices as "called it a day" to remove them from the daily list once handled.
4. Reverse payments, cancel draft invoices, edit drafts, export the list to CSV, and print invoices / receipts.

### Where the menu lives in each project

| Aspect | Legacy (Project A) | Laravel (Project B) |
|---|---|---|
| Framework | Custom PHP MVC (no framework), MySQL | Laravel 12, PostgreSQL |
| Route (page) | `GET sales/today` → `SalesController::today()` | `GET admin/sales-invoices` (resource `index`) → `SalesInvoiceController::index()` (auto-defaults `scope=today`) |
| View | `app/views/sales/today.php` (184 lines) | `resources/views/admin/sales-invoices/index.blade.php` (~938 lines) |
| Page JS | `public/assets/js/sales-today-index.js` (548 lines, **loaded**) | Inline `@push('scripts')` block. `public/assets/js/sales-today-index.js` (550 lines) exists but is **dead code — not loaded**. |
| Page CSS | `public/assets/css/sales-today-index.css` (335 lines, indigo/teal theme) | `public/assets/css/sales-today-index.css` (loaded but partially overridden by inline styles; amber/orange Tailwind theme) + `rc-erp.css` (Tailwind v4) |
| AJAX endpoints | `sales/datatable_invoices`, `sales/today_filter_summary`, `sales/receive_modal/{id}`, `sales/save_payment`, `sales/reverse_payment`, `sales/call_it_a_day`, `sales/delete_invoice`, `sales/export` | `admin/sales-invoices/datatable`, `admin/sales-invoices/summary`, `admin/sales-invoices/{id}/receive-modal`, `admin/customer-payments` (store), `admin/customer-payments/{id}/cancel`, `admin/sales-invoices/call-it-a-day`, `admin/sales-invoices/{id}/cancel`, `admin/sales-invoices/export-csv` |
| Design system | Bootstrap 5.3 + indigo/teal custom CSS | Tailwind v4 (`rc-erp.css`, no preflight) coexisting with Bootstrap; amber/orange accent design system with `<x-erp.*>` components |

### Key architectural differences (summary — details in §7–9)

- **Legacy** treats "Today Invoice" as its own controller action (`sales/today`) with a 7-day default window. It is a single, focused daily-collection screen.
- **Laravel** folds the Today Invoice into the general `admin/sales-invoices` index, adding a `scope=today` filter plus two new workflow scopes (`pending_godown`, `pending_challan`) that did not exist in the legacy today page. This is an intentional improvement (BUG-52) but it means the Laravel screen is a superset, not a 1:1 port.

---

## 2. Complete Business Logic (Legacy — exhaustive)

These are the business rules verified in the Legacy code. Each is numbered (`BL-#`) for cross-reference with the comparison table in §7.

| # | Rule | Description | Implementation |
|---|---|---|---|
| BL-1 | Misnomer "Today" | Despite the menu name, the page defaults to the **last 7 days** (`date_from = today − 6`, `date_to = today`). A `CURRENT_DATE` fallback exists in SQL only if no dates are sent at all — the controller always sends defaults so the fallback never fires on the page. | `SalesController::today()` L343-347; `SalesInvoiceOperationsTrait` L855-857 |
| BL-2 | Call-a-day exclusion | Invoices with `call_a_day=1` are **excluded** from the list, summary, datatable, and CSV (`COALESCE(si.call_a_day,0)=0`). "Call It A Day" is a UX "remove from my list" toggle, **not** a financial action. | `SalesInvoiceOperationsTrait` L837, L581, L591 |
| BL-3 | Branch isolation (read) | WHERE always includes `si.branch_id = session_branch_id`. Admin/manager see all invoices in the branch; all other roles see only invoices they created (`si.created_by = user_id`). | `SalesInvoiceOperationsTrait` L834-842; `Helper::canSeeAllBranchInvoices()` |
| BL-4 | Reversed exclusion | `si.is_reversed = 0` always — soft-deleted / reversed invoices never appear. | `SalesInvoiceOperationsTrait` L884 |
| BL-5 | Default date fallback | Defensive SQL: if both `date_from` and `date_to` are empty AND `skip_default_today` is unset, SQL adds `si.invoice_date = CURRENT_DATE`. | `SalesInvoiceOperationsTrait` L855-857 |
| BL-6 | Smart search | Single search box matches `invoice_code`, `shop_name`, `customer_name`, `mobile`, `branch_name`, salesman `e.name`, creator `u.username`, and an EXISTS subquery on `sales_invoice_items` joined to `products` (`product_name`, `product_code`). | `SalesInvoiceOperationsTrait` L859-880 |
| BL-7 | Status chip filter values | `all`, `pending`→`status='draft'`, `godown_copy`→`status='godown_issued'`, `challan_generated`→`status='challan_completed'`, `open_pipeline`→`status IN ('draft','godown_issued')`, `awaiting_payment`→`(total_amount − paid_amount) > 0.009`. | `SalesInvoiceOperationsTrait` L888-913 |
| BL-8 | Summary ignores chip filter | `getTodayFilterSummary` forcibly resets `challan_status='all'` so chip counts stay stable regardless of the active filter. | `SalesInvoiceOperationsTrait` L920-923 |
| BL-9 | Smart sort | When ON (default), ORDER BY: unpaid first (`CASE WHEN balance_due > 0.009 THEN 0 ELSE 1 END`), then `invoice_date ASC`, then `created_at DESC`. Overrides DataTables column/dir entirely. | `SalesInvoiceOperationsTrait` L1032-1037 |
| BL-10 | Manual column sort map | When smart sort OFF: col 1=invoice_code, 2=invoice_date, 3=shop_name, 4=branch_name, 5=salesman name, 6=total_amount, 7=paid_amount, 8=balance_due, 9=status. Fallback `si.invoice_date`. | `SalesInvoiceOperationsTrait` L1039-1051 |
| BL-11 | Pagination | DataTables server-side. `length` clamped 1–200, default 25. `LIMIT :start, :length`. | `SalesController` L404-407; trait L1073 |
| BL-12 | Rate limits | `today_filter_summary` 120/min/user, `datatable_invoices` 180/min/user, `cancel_stale_drafts` 30/min/user. | `SalesController` L368, L401, L389 |
| BL-13 | Invoice status transitions | `draft` → `godown_issued` (warehouse prepares godown copy, sets `godown_issued_at`) → `challan_completed` (final delivery challan generated). Edit/delete only in `draft` and before godown issued. | `sales-today-index.js` L418-425; trait L218-248, L663-674 |
| BL-14 | Soft-hold on edit/delete | Edit/delete blocked when `godown_issued_at` set, `status != 'draft'`, non-reversed payments exist, non-reversed `sales_challans` row exists, or `sales_invoice_dispatches.dispatched_qty > 0`. | trait L218-227, L670-707, L272-274 |
| BL-15 | Payment cap | `recordCustomerPayment` rejects if `paidSoFar + amount > total_amount + 0.01`. Modal JS caps `max` attribute. | `SalesPaymentOperationsTrait` L78-83; `sales-receive-payment.js` L95-99 |
| BL-16 | Payment allocation | Each payment with `invoice_id>0` creates a single `invoice_payment_allocations` row (no multi-invoice split from this UI). | `SalesPaymentOperationsTrait` L124-135 |
| BL-17 | Payment reversal | Requires reason ≥5 chars; reverses journal + intercompany + customer_ledger debit-back + deletes allocations + soft-reverses `customer_payments`. Only `transaction_type='receive'` reversible from sales. | `SalesPaymentOperationsTrait` L194-325 |
| BL-18 | Telegram notification | `SalesTelegramNotifier::notifyTodayInvoicePayment` fires **only if** `si.invoice_date = CURRENT_DATE`. Recipients: admin + accountant users with Telegram enabled. Wrapped in `safe()` so failures don't break the response. | `SalesController` L589-591; `SalesTelegramNotifier` L141-160, L244 |
| BL-19 | Audit logging | `UserAudit` entries for `payment_received`, `payment_reversed`, `sale_call_a_day`, `sale_deleted`. Visible at `sales/audit`. | `SalesController` L41, L478, L617, L464 |
| BL-20 | Stale-draft auto-cancel | When `SALES_STALE_DRAFT_AUTO_CANCEL=true` (default false), runs at most once per 6 hours per branch via a `$_SESSION` timestamp. Cancels drafts older than `SALES_STALE_DRAFT_DAYS` (default 14). Batch limit 200. | trait L1157-1235; `config/config.php` L55-67 |
| BL-21 | Stale-draft manual cancel | `sales/cancel_stale_drafts` (POST, admin/manager) + `SalesAuditController::cancel_stale_drafts` + cron script `database/scripts/cancel_stale_sales_drafts.php [days] [branch_id]`. All route through `cancelStaleDraftInvoices()`. | `SalesController` L383-395; cron script L21 |
| BL-22 | Document codes | `customer_payment` codes are global (not per-branch), `PAY-YYYYMMDD-NNNN`, allocated atomically via `document_sequences`. | `Helper` L1133-1138 |
| BL-23 | Branch write-protection | On edit, `resolveBranchIdForWrite` ignores non-admin's requested branch_id; admins may reassign. | `Helper` L219-236 |
| BL-24 | Branch access guard | `assertInvoiceAccessible(invoiceBranchId)` throws if non-admin accesses an invoice whose branch ≠ session branch. | `Helper` L238-246 |
| BL-25 | Credit-limit enforcement | On finalize/update, `CustomerModel::wouldExceedCreditLimit()` checked; override requires `credit_limit_override` flag + `override_reason` ≥10 chars. | trait L28-49, L297-317 |
| BL-26 | Price-range validation | Each cart item's rate must be within `product_prices.min_rate..max_rate`. | `SalesServiceSupportTrait` L64-93; trait L61-69, L333-341 |
| BL-27 | Stock availability | `StockService::assertBranchProductsAvailable()` enforces branch stock; `lockBranchProductsForUpdate()` row-locks first. | trait L79-86, L351-363 |
| BL-28 | Intercompany auto-settle | On payment, `BranchIntercompanyService::settleFromCustomerPayment` auto-applies to open branch-demand for that customer/branch. On reversal, `reverseCustomerPaymentSettlements` undoes it. | `SalesPaymentOperationsTrait` L155-167, L270-271 |
| BL-29 | GL posting | Invoice finalization posts `Dr AR / Cr Sales Revenue`; payment posts `Dr Cash-or-Bank / Cr AR`; reversals post counter-entries. Journal IDs linked back to `sales_invoices.journal_entry_id` and `customer_payments.journal_entry_id`. | trait L160-178, L467-493, L678-688; payment trait L137-153, L258-268 |
| BL-30 | CSV column 9 misnomer | Export header reads "Challan Status" but data emitted is `"Called"` (if `call_a_day`) or `"Today"`. No actual challan_status field exported. | `SalesController` L782-798 |
| BL-31 | CSV BOM | CSV starts with UTF-8 BOM for Excel compatibility. | `SalesController` L780 |
| BL-32 | Filter persistence | Filter state persisted to `localStorage` key `sales_today_filters_v1`. URL params override localStorage when `forceUrlParams` flag is true. | `sales-today-index.js` L7, L33-51, L152-160 |
| BL-33 | Date presets | Today / Yesterday / Last 7 days (default) / This month / Custom. Active state = indigo gradient pill. | `sales-today-index.js` L112-134 |
| BL-34 | Auto call-a-day after full payment | After `salesToday:paymentRecorded` event with `is_fully_paid=true`, JS prompts "Fully paid. Remove this invoice from your collection list?" → silent POST `sales/call_it_a_day` for that single invoice. | `sales-today-index.js` L394-411, L515-534 |
| BL-35 | Mobile card rendering | On `<768px`, DataTable hidden, cards rendered from `table.rows({page:'current'}).data()`. Status gets colored left border (draft=slate, godown=amber, done=emerald). | `sales-today-index.js` L444-488; CSS L291-309, L322-330 |
| BL-36 | Investigation-mode indicator | `InvestigationMode::isGloballyActive()` toggles a footer icon. Does not alter today-list data. | `footer.php` L6-13; `InvestigationMode` L94 |
| BL-37 | Reconciliation redirect | `sales/reconcile` redirects to `Reconciliation/index` preserving query string. | `SalesController` L660-666 |
| BL-38 | Edit-redirect to today | `SalesController::edit($id=null)` redirects to `sales/today` when no id. | `SalesController` L490; `BaseController` L233 |
| BL-39 | Receive-modal CSRF | `receive_modal` is GET returning HTML; CSRF embedded as `data-csrf` and sent on the subsequent `save_payment` POST. | `receive_modal.php` L13, L21; `sales-receive-payment.js` L65, L165 |
| BL-40 | Bootstrap focus-trap fix | On `focusin` inside `.swal2-container`, `stopImmediatePropagation` prevents Bootstrap 5 modal focus-trap from stealing focus out of SweetAlert2 inputs. | `sales-receive-payment.js` L12-26 |
| BL-41 | DataTables length hidden | Page hides DataTables' built-in `dataTables_filter` and `dataTables_length`; page provides its own search input and uses fixed `pageLength:25`. | CSS L311-314; `sales-today-index.js` L247 |
| BL-42 | Header screenshot tool | `html2canvas` + annotation toolbar (draw/rect/arrow/text/blur + colors + download). Global, available on every page. | `header.php` L85-275 |
| BL-43 | "Creative Guideline" footer drop-up | Footer button opens a panel linking to `sales/guide`, `Accounting/guide`, `sales/go_live_checklist`. Global. | `footer.php` L9-79 |
| BL-44 | FCM notifications | `notification.js` loaded only if `FCM_VAPID_KEY` defined AND flag set. Today page does NOT push FCM tokens from JS. | `main.php` L99-116 |
| BL-45 | "Save FCM token" endpoint | `SalesController::save_fcm_token` exists but today JS never calls it. Out-of-scope, listed for parity. | `route_roles.php` L75 |

---

## 3. CRUD Flow

### 3.1 Create (invoice appears on Today list)
- **Legacy:** `sales/create` → SalesCartService builds a draft cart in `$_SESSION['sales_draft_carts'][$customer_id]` → `SalesController::finalize` calls `SalesInvoiceService::finalizeSales()` → INSERT `sales_invoices` (status='draft') + `sales_invoice_items` + `sales_invoice_dispatches` + `customer_ledger` debit + GL journal entry → invoice appears on `sales/today`.
- **Laravel:** `admin/sales/cart` → `SalesDraftCart` (DB row with JSONB items) → `POST admin/sales/finalize` → `SalesInvoiceService::finalizeFromCart()` → same INSERT pattern + audit → invoice appears on `admin/sales-invoices?scope=today`.

### 3.2 Read (the Today list itself)
- **Legacy:** `GET sales/today` (page) + `GET sales/datatable_invoices` (DataTables AJAX) + `GET sales/today_filter_summary` (chip counts). All filter by `is_reversed=0` AND `call_a_day=0` AND `branch_id=session` (admin/manager see all branch invoices; others see only own).
- **Laravel:** `GET admin/sales-invoices` (page) + `GET admin/sales-invoices/datatable` (DataTables AJAX) + `GET admin/sales-invoices/summary` (chip counts). Filtered by `BranchScope` global scope (branch_id=session for non-admin) + `is_reversed=0`. **Does NOT filter by `call_a_day=0`** (gap).

### 3.3 Update
- **Legacy edit:** `GET sales/edit/{id}` (draft only) → `POST sales/edit/{id}` → `SalesInvoiceService::updateExistingInvoice()` reverses old GL + customer_ledger, deletes old items/dispatches, inserts new, posts new GL + ledger, updates invoice header.
- **Laravel edit:** `GET admin/sales-invoices/{id}/edit` (draft only) → `PUT admin/sales-invoices/{id}` → `SalesInvoiceService::updateInvoice()` — same pattern plus credit-limit re-check under `Customer::lockForUpdate()` (race-safe, R5).
- **Payment update (= receive):** see §3.5.
- **Call-it-a-day (toggle):** `POST sales/call_it_a_day` / `POST admin/sales-invoices/call-it-a-day` → UPDATE `call_a_day=true` WHERE branch+is_reversed+call_a_day guards. Legacy then excludes from list; Laravel does NOT exclude (gap).

### 3.4 Delete (= cancel/soft-reverse)
- **Legacy:** `POST sales/delete_invoice` → `SalesInvoiceService::deleteInvoice()` — guards: draft only, no godown, no challan, no dispatched_qty>0, no payments. Reverses journal + customer_ledger debit, deletes items + dispatches, sets `is_reversed=1`.
- **Laravel:** `POST admin/sales-invoices/{id}/cancel` → `SalesInvoiceService::cancelInvoice()` — guards: draft only, no active challan, no payments. Reverses GL + customer_ledger, sets `status='cancelled'` + `is_reversed=true`. (SoftDeletes trait also sets `deleted_at`.)

### 3.5 Payment CRUD (the core daily-collection action)
- **Create:** `POST sales/save_payment` (legacy) / `POST admin/customer-payments` (Laravel) → INSERT `customer_payments` + `invoice_payment_allocations` + `customer_ledger` credit + GL `Dr Cash/Bank / Cr AR` + intercompany settlement if cross-branch bank + audit. Laravel adds an idempotency token (UUID v4) cached for 10 min to prevent duplicate submissions (R2).
- **Read:** Receive-payment modal (`sales/receive_modal/{id}` / `admin/sales-invoices/{id}/receive-modal`) lists existing payments on the invoice with print-receipt links.
- **Update:** No "edit payment" — only reverse. (Legacy reverse is inline in the modal; Laravel requires navigating to `admin/customer-payments/{id}`.)
- **Delete (= reverse):** `POST sales/reverse_payment` (legacy, inline in modal) / `POST admin/customer-payments/{id}/cancel` (Laravel, separate page). Reason ≥5 chars. Reverses GL + intercompany + customer_ledger + deletes allocations + soft-reverses `customer_payments`.

---

## 4. Database Flow

### 4.1 Tables touched (Legacy MySQL / Laravel PostgreSQL)

| Table | Legacy op | Laravel op | Notes |
|---|---|---|---|
| `sales_invoices` | SELECT/UPDATE | SELECT/UPDATE/SoftDeletes | Laravel adds `due_amount` GENERATED column, `is_godown_prepared`, `is_challan_issued`, `deleted_at` (SoftDeletes) |
| `sales_invoice_items` | SELECT/INSERT/DELETE | SELECT/INSERT/DELETE | Laravel adds `condition_state`, `amount` GENERATED |
| `sales_invoice_dispatches` | SELECT/INSERT/DELETE | SELECT/INSERT/DELETE | Laravel keeps `dispatched_qty` |
| `sales_invoice_dispatchers` | DELETE (edit) | SELECT/INSERT/DELETE (sync) | Laravel BelongsToMany |
| `sales_challans` | SELECT (delete guard) | SELECT (delete guard) | Parity |
| `customers` | SELECT | SELECT | Parity |
| `branches` | SELECT | SELECT | Parity |
| `employees` | SELECT | SELECT | Parity (salesman name) |
| `users` | SELECT (LEFT JOIN) | SELECT (received_by_name) | Parity |
| `products` | SELECT (search subquery) | SELECT (edit dropdown) | Parity |
| `customer_payments` | INSERT/UPDATE | INSERT/UPDATE + SoftDeletes | Laravel adds `transaction_type` (4 types), `discount_amount`, `intercompany_journal_entry_id` |
| `invoice_payment_allocations` | INSERT/DELETE | INSERT/DELETE | Laravel adds DB CHECK >0, EXCLUDE constraint, `trg_ipa_no_overallocation` trigger |
| `customer_ledger` | INSERT | INSERT | Laravel adds `journal_entry_id`, `is_reversed` per-row |
| `banks` | SELECT | SELECT | Parity |
| `bank_ledger_mappings` | — | SELECT | Laravel resolves debit ledger per bank |
| `journal_entries` + `journal_lines` | INSERT (via service) | INSERT (via service) | Parity |
| `branch_ledger` / intercompany | UPDATE/INSERT/DELETE | INSERT | Laravel adds `branch_ledger` table |
| `document_sequences` | SELECT+UPDATE (FOR UPDATE) | SELECT+UPDATE (advisory lock) | Laravel improved concurrency |
| `user_audit_log` | INSERT | INSERT (JSONB + JSONL dual-write) | Laravel adds 17 event types |
| `menus` / `user_menu_permissions` | SELECT | SELECT | Parity (DB-driven sidebar) |
| `customer_ledger` running balance | SELECT (SUM) | SELECT (SUM debit-credit) | Parity |

### 4.2 Key SQL differences (migration-relevant)
- **Date math:** Legacy uses PostgreSQL-style `CURRENT_DATE::date`, `si.invoice_date::date`, `NOW() - :days * INTERVAL '1 day'` (the legacy codebase was already half-migrated to PG syntax). Laravel uses native PostgreSQL `CURRENT_DATE`, `invoice_date`, `NOW() - INTERVAL '14 days'`.
- **`due_amount`:** Legacy computes `GREATEST(0, si.total_amount - paid_subquery)` in SELECT. Laravel stores `due_amount` as a PostgreSQL GENERATED column = `total_amount - paid_amount`, auto-updated whenever `paid_amount` changes. (Cleaner, but means `due_amount` cannot be set directly.)
- **Branch isolation:** Legacy applies `si.branch_id = :scope` + `si.created_by = :user` in raw SQL. Laravel uses Eloquent `BranchScope` global scope (auto-applies `where branch_id = session` for non-admin) + `EnforceBranchIsolation` middleware + `SalesAccess::assertBranchAccessible()` defense-in-depth.

---

## 5. Validation

| Validation | Legacy | Laravel |
|---|---|---|
| CSRF | `BaseController::validateCSRF()` on all POSTs; `hash_equals` timing-safe; token from POST body / `X-CSRF-Token` header / JSON body | `@csrf` Blade directive + `VerifyCsrfToken` middleware (Laravel default) |
| Rate limiting | `BaseController::guardJsonApi()` per-user token bucket: 120/min (summary), 180/min (datatable), 30/min (stale-draft) | Laravel `throttle` middleware (NOT applied to datatable/summary routes — gap) |
| API version | `BaseController::assertApiVersion()` checks `X-API-Version` header against `API_SUPPORTED_VERSIONS` | Not implemented (no API versioning on web routes) |
| Payment amount | `paidSoFar + amount > total_amount + 0.01` → reject (server) + `max=balance` (client) | FormRequest `amount: required\|numeric\|min:0.01` + service-level `amount ≤ outstanding + 0.01` + over-payment SweetAlert2 client confirm |
| Reverse reason | `min: 5` chars (server + client) | `cancel_reason: required\|string\|min:5\|max:500` (FormRequest R27) |
| Cancel reason | (delete_invoice has no reason) | `cancel_reason: required\|string\|max:500` (FormRequest) |
| Credit limit | `CustomerModel::wouldExceedCreditLimit()` outside transaction | Outside transaction (UX fast-fail) + inside transaction under `Customer::lockForUpdate()` (race-safe, R5) |
| Override reason | `min: 10` chars when `credit_limit_override=true` | `override_reason: required\|string\|min:10` (FormRequest) |
| Idempotency | None | `idempotency_token` UUID v4, `Cache::get('payment:'.$token)` 10-min window (R2) — **NEW in Laravel** |
| Stock availability | `StockService::assertBranchProductsAvailable()` + `lockBranchProductsForUpdate()` | Same + `invalidatePipelineForInvoice()` cache bust |
| Price range | `product_prices.min_rate..max_rate` per cart item | Same (SalesServiceSupportTrait) |

---

## 6. Permissions

### 6.1 Legacy — two-layer gate (every non-public route)

1. **`RouteAccess::require($controller, $action)`** — role matrix in `app/config/route_roles.php`. Admin/superadmin bypass. Unlisted actions default to allow.
2. **`MenuAccess::require($controller, $action)`** — DB-driven per-user menu permission (`menus` + `user_menu_permissions.can_view` / `can_edit`). Admin-tier bypass. DataTables draws (`$_GET['draw']`) relax the edit-permission gate.

**Today Invoice action matrix (Legacy):**

| Action | Roles |
|---|---|
| `today` (page) | admin, manager, salesman, accountant |
| `today_filter_summary` | admin, manager, salesman, accountant |
| `datatable_invoices` | admin, manager, salesman, accountant |
| `export` (CSV) | admin, manager, salesman, accountant |
| `call_it_a_day` | admin, manager, salesman (accountant excluded) |
| `save_payment` | admin, manager, salesman, accountant |
| `reverse_payment` | admin, manager, accountant (salesman excluded) |
| `receive_modal` | admin, manager, salesman, accountant |
| `print_receipt` | admin, manager, salesman, accountant |
| `invoice_copy` | admin, manager, salesman, accountant |
| `show` | admin, manager, accountant (salesman excluded) |
| `edit` | admin, manager, salesman |
| `delete_invoice` | admin, manager, salesman |
| `cancel_stale_drafts` | admin, manager |

### 6.2 Laravel — three-layer gate

1. **`role:...` route middleware** (`EnsureRole`) — superadmin bypass, admin-tier bypass, exact role match.
2. **`branch.isolation` middleware** (`EnforceBranchIsolation`) — request `branch_id` + URL-param-derived `branch_id` must match session for non-admin; admin override logged as `branch_override`.
3. **`MenuService::getUserMenuTree()`** — DB-driven sidebar visibility (can_view). Menu visibility ≠ route authorization (direct URL still works).

**Today Invoice route middleware (Laravel):**

| Route | Middleware |
|---|---|
| `admin.sales-invoices.index` | `role:salesman,accountant,warehouse_manager,manager,admin` |
| `admin.sales-invoices.datatable` | `role:salesman,accountant,warehouse_manager,manager,admin` |
| `admin.sales-invoices.summary` | `role:salesman,accountant,warehouse_manager,manager,admin` |
| `admin.sales-invoices.call-it-a-day` | `role:salesman,accountant,manager,admin` + `branch.isolation` |
| `admin.sales-invoices.cancel` | `role:salesman,manager,admin` + `branch.isolation` |
| `admin.sales-invoices.edit` / `update` | `role:salesman,manager,admin` + `branch.isolation` |
| `admin.sales-invoices.receive-modal` | `role:salesman,accountant,manager,admin` |
| `admin.sales-invoices.export-csv` | `role:accountant,manager,admin` (**salesman excluded** — differs from legacy) |
| `admin.customer-payments.store` | `role:salesman,accountant,manager,admin` + `branch.isolation` |
| `admin.customer-payments.cancel` | `role:accountant,manager,admin` + `branch.isolation` |
| `admin.sales.cancel-stale-drafts` | `role:manager,admin` |
| `admin.sales.audit` | `role:accountant,manager,admin` |

### 6.3 Branch visibility (separate layer, enforced in SQL/Eloquent)
- **Legacy:** `Helper::canSeeAllBranchInvoices()` = admin OR manager → see all branch invoices. Others see only own (`si.created_by = user_id`).
- **Laravel:** `BranchScope` global scope = admin/superadmin → see all branches. Others see only their session branch's invoices. **Laravel does NOT restrict to own-created invoices for salesmen** — every salesman in the branch sees every branch invoice. This is a behavioral difference (see §9 Recommendations).

---

## 7. Feature Checklist & Comparison Table

Legend: `✅` Fully Implemented · `🟡` Partially Implemented · `❌` Missing

| # | Feature (Legacy BL-#) | Legacy | Laravel | Notes |
|---|---|---|---|---|
| F-1 | Today list page (BL-1) | ✅ | ✅ | Laravel uses `scope=today` (true single-day) — cleaner than legacy's 7-day default |
| F-2 | Call-a-day exclusion from list (BL-2) | ✅ | ❌ | **GAP:** Laravel sets the flag but does NOT filter `call_a_day=false` in index/datatable/summary. Migration comment + service docstring incorrectly claim it does. |
| F-3 | Branch isolation — read (BL-3) | ✅ | ✅ | Laravel via `BranchScope`. **Difference:** Laravel salesmen see ALL branch invoices; legacy salesmen see only own. |
| F-4 | Reversed exclusion (BL-4) | ✅ | ✅ | Parity |
| F-5 | Default date fallback (BL-5) | ✅ | 🟡 | Laravel auto-defaults `scope=today` on first visit; no SQL fallback needed |
| F-6 | Smart search — multi-field (BL-6) | ✅ | ✅ | Laravel searches invoice_code + customer name/code/mobile + branch name/code. Legacy also searched salesman name, creator username, product name/code via EXISTS subquery. **Laravel searches fewer fields** — minor gap. |
| F-7 | Status chip filters (BL-7) | ✅ | ✅ | Laravel adds `pending_godown` / `pending_challan` scope chips (BUG-52 improvement) |
| F-8 | Summary ignores chip filter (BL-8) | ✅ | ✅ | Laravel `summary()` uses `excludeStatusChip:true` — parity |
| F-9 | Smart sort — unpaid first (BL-9) | ✅ | ✅ | Parity (Laravel uses `due_amount` GENERATED column) |
| F-10 | Manual column sort (BL-10) | ✅ | ✅ | Parity |
| F-11 | Pagination (BL-11) | ✅ | ✅ | Laravel caps length 1-500 (legacy 1-200) |
| F-12 | Rate limits (BL-12) | ✅ | ❌ | **GAP:** Laravel does NOT apply `throttle` middleware to datatable/summary routes |
| F-13 | Invoice status transitions (BL-13) | ✅ | ✅ | Parity (Laravel uses `confirmed` instead of `godown_issued`) |
| F-14 | Soft-hold on edit/delete (BL-14) | ✅ | ✅ | Parity |
| F-15 | Payment cap (BL-15) | ✅ | ✅ | Parity + Laravel adds over-payment SweetAlert2 confirm |
| F-16 | Payment allocation (BL-16) | ✅ | ✅ | Parity (single-invoice from this modal) + Laravel DB-level EXCLUDE constraint prevents race |
| F-17 | Payment reversal (BL-17) | ✅ | ✅ | Parity. **UX difference:** Legacy reverses inline in modal; Laravel requires navigating to `customer-payments/{id}` page. |
| F-18 | Telegram notification on today payment (BL-18) | ✅ | ❌ | **GAP:** No Telegram notifier in Laravel payment flow |
| F-19 | Audit logging (BL-19) | ✅ | ✅ | Laravel adds 17 event types + JSONL dual-write + 4 cart events (R4) |
| F-20 | Stale-draft auto-cancel (BL-20) | 🟡 | 🟡 | Legacy: session-throttled, flag-gated (default OFF). Laravel: pg_cron scheduled + manual endpoint. Neither surfaces on the today page. |
| F-21 | Stale-draft manual cancel (BL-21) | ✅ | ✅ | Parity (Laravel via Artisan + endpoint) |
| F-22 | Document codes (BL-22) | ✅ | ✅ | Laravel uses advisory locks (improved concurrency) |
| F-23 | Branch write-protection (BL-23) | ✅ | ✅ | Parity (`SalesAccess::resolveBranchIdForWrite`) |
| F-24 | Branch access guard (BL-24) | ✅ | ✅ | Parity (`SalesAccess::assertBranchAccessible`) |
| F-25 | Credit-limit enforcement (BL-25) | ✅ | ✅ | Laravel adds in-transaction re-check under lock (R5 race-safe) |
| F-26 | Price-range validation (BL-26) | ✅ | ✅ | Parity |
| F-27 | Stock availability (BL-27) | ✅ | ✅ | Parity |
| F-28 | Intercompany auto-settle (BL-28) | ✅ | ✅ | Parity |
| F-29 | GL posting (BL-29) | ✅ | ✅ | Parity (Laravel adds 4 transaction types: receive/discount/write_off/payment-refund) |
| F-30 | CSV export (BL-30, BL-31) | ✅ | ✅ | Laravel 12 columns (cleaner — no "Called/Today" misnomer). **Permission difference:** Laravel excludes salesman from export. |
| F-31 | Filter persistence — localStorage (BL-32) | ✅ | ❌ | **GAP:** Laravel resets filters on page reload |
| F-32 | Date presets (BL-33) | ✅ | ❌ | **GAP:** Laravel has only from_date/to_date inputs, no preset buttons |
| F-33 | Auto call-a-day after full payment (BL-34) | ✅ | ❌ | **GAP:** Laravel payment submit redirects away from index, so no event fires |
| F-34 | Mobile card rendering (BL-35) | ✅ | ✅ | Parity (Laravel uses different status colors) |
| F-35 | Investigation-mode indicator (BL-36) | ✅ | ❌ | Out-of-scope feature; not on Laravel |
| F-36 | Receive-modal CSRF (BL-39) | ✅ | ✅ | Parity (Laravel uses `@csrf` in the form) |
| F-37 | Bootstrap focus-trap fix (BL-40) | ✅ | 🟡 | Laravel uses inline `initReceiveModalBody()`; nested SweetAlert2 focus issue may still exist — verify |
| F-38 | DataTables length hidden (BL-41) | ✅ | 🟡 | Laravel shows DataTables length menu (lengthMenu [10,25,50,100,250]) |
| F-39 | Receive-modal reverse-payment inline | ✅ | ❌ | **GAP:** Laravel requires navigating to customer-payments.show page |
| F-40 | Receive-modal print-receipt success prompt | ✅ | ❌ | **GAP:** Laravel redirects away on submit, no SweetAlert2 "Print receipt?" prompt |
| F-41 | Active filter bar (tags) | ✅ | ❌ | **GAP:** Laravel has no `#activeFilterBar` |
| F-42 | Per-row action buttons (view/edit/delete/receive/call-it-a-day) | ✅ | 🟡 | **GAP:** Laravel index shows only View + conditionally Receive. Edit/Cancel/Call-it-a-day only on show page. |
| F-43 | Bulk call-it-a-day (checkbox + button) | ✅ | ❌ | **GAP:** Laravel has no checkbox column or bulk action |
| F-44 | Idempotency token (R2) | ❌ | ✅ | Laravel-only improvement |
| F-45 | 4 payment transaction types | ❌ | ✅ | Laravel-only improvement (receive/discount/write_off/refund) |
| F-46 | Workflow scope chips (pending_godown/pending_challan) | ❌ | ✅ | Laravel-only improvement (BUG-52) |

---

## 8. Missing Features (Laravel gaps to close)

Ranked by impact on daily-collection workflow parity:

### Critical (breaks the daily-collection UX)
1. **F-2 — Call-a-day exclusion from list.** The flag is set + audited but the invoice stays visible. The whole point of "Call It A Day" is to remove the invoice from the daily list. This is the single biggest behavioral gap.
2. **F-33 — Auto call-a-day after full payment.** Legacy nudges the user to remove a fully-paid invoice from their list. Laravel redirects away, so the nudge never fires.
3. **F-42 / F-43 — Per-row call-it-a-day + bulk call-it-a-day.** There is NO button on the Laravel index page that invokes `admin/sales-invoices/call-it-a-day`. The route + service exist but are unreachable from the UI.

### High (reduces collection efficiency)
4. **F-31 — Filter persistence.** Salesmen reload the page frequently; losing filter state is frustrating.
5. **F-32 — Date presets.** Quick "Today / Yesterday / Last 7 days / This month" buttons speed up the most common navigations.
6. **F-39 — Receive-modal inline reverse-payment.** Accountants currently must navigate away to reverse a payment; legacy did it inline.
7. **F-40 — Receive-modal print-receipt success prompt.** After recording payment, legacy immediately offered to print the receipt. Laravel redirects to `customer-payments.show`, requiring extra clicks.
8. **F-41 — Active filter bar.** Visual confirmation of what's currently filtered improves usability.

### Medium (polish & observability)
9. **F-18 — Telegram notification on today payment.** Real-time alert to admin/accountant when a today-invoice payment is recorded.
10. **F-12 — Rate limits on datatable/summary.** Defense against abusive or buggy clients hammering the AJAX endpoints.
11. **F-6 — Smart search field coverage.** Laravel doesn't search salesman name, creator username, or product name/code.
12. **F-20 — Stale-draft banner on today page.** Surface stale drafts so the user knows to cancel them.

### Low (minor differences)
13. **F-3 — Salesman sees all branch invoices (Laravel) vs only own (Legacy).** This is an intentional design choice; document and confirm with the business owner before "fixing."
14. **F-30 — CSV export permission.** Legacy allows salesman; Laravel excludes. Confirm intended.
15. **F-38 — DataTables length menu visible (Laravel) vs hidden (Legacy).** Minor.
16. **F-37 — Nested SweetAlert2 focus-trap.** Verify whether the issue reproduces in Laravel's inline modal JS.

---

## 9. Recommendations

### 9.1 Must-fix for parity (Phase 1 — see implementation plan)
- Wire `call_a_day=false` into `buildInvoiceFilterQuery()` for the index/datatable/summary endpoints (F-2). One-line `where('call_a_day', false)` per query — but verify the partial index `idx_si_call_a_day_active WHERE call_a_day=false` exists (migration `2025_01_19_000001` already added it).
- Add per-row + bulk "Call It A Day" buttons on the index page wired to `admin/sales-invoices/call-it-a-day` (F-42, F-43).
- Add the auto-call-it-a-day prompt after a fully-paid payment (F-33). This requires keeping the user on the index page after payment submit (AJAX instead of full redirect) OR opening a return-URL that re-triggers the prompt.

### 9.2 Should-fix for efficiency (Phase 2)
- Filter persistence via `localStorage` (F-31) — port `sales_today_filters_v1` logic.
- Date preset buttons (F-32) — port the 5 presets.
- Active filter bar (F-41) — port `#activeFilterBar` tag rendering.
- Receive-modal inline reverse-payment (F-39) — load the reverse form inside the modal via AJAX (accountant/manager/admin only).
- Receive-modal print-receipt success prompt (F-40) — switch payment submit to AJAX, fire SweetAlert2 on success with "Print receipt" button.

### 9.3 Nice-to-have (Phase 3)
- Telegram notifier integration (F-18) — port `SalesTelegramNotifier::notifyTodayInvoicePayment` to a Laravel Notification class.
- Rate-limit the datatable/summary routes (F-12) — add `throttle:120,1` middleware.
- Expand smart search (F-6) — add salesman name, creator username, product name/code via JOIN/EXISTS.
- Stale-draft banner (F-20) — show a dismissible warning card when stale drafts exist for the session branch.

### 9.4 Architectural recommendations
- **Decide on salesman visibility scope (F-3).** Legacy: salesman sees only own invoices. Laravel: salesman sees all branch invoices. The Laravel behavior may be intentional (branch-level collection pool) — confirm with the business owner before changing. If matching legacy is required, add a `where('created_by', auth()->id())` for salesman role in `buildInvoiceFilterQuery()`.
- **Decide on CSV export permission (F-30).** Legacy allows salesman; Laravel restricts to accountant/manager/admin. Confirm intended.
- **Remove dead code.** `public/assets/js/sales-today-index.js` (550 lines) and `public/assets/js/sales-receive-payment.js` (385 lines) are not loaded by any Laravel view. Either wire them up (replacing the inline `@push('scripts')`) or delete them to avoid confusion. Recommendation: keep the inline approach (it's cleaner and uses Laravel route names) and delete the dead files.
- **Add Laravel Policies.** Authorization is currently spread across `role:` middleware + `branch.isolation` + `SalesAccess`. A `SalesInvoicePolicy` + `CustomerPaymentPolicy` would centralize the rules and enable `@can` / `$this->authorize()` in controllers/views.

---

**End of business analysis.** Cross-reference: the implementation plan in `today-invoice-business-implementation-plan.md` breaks the gaps above into phases; the UI/UX analysis in `today-invoice-uiux-analysis.md` covers the visual/interaction layer.
