# Sales Entry Audit — Legacy vs Laravel

> **Scope:** Comparison of the Legacy Sales Entry (`legacy/app/controllers/SalesController.php` + `legacy/app/services/Sales/*` + `legacy/app/views/sales/*` + `legacy/public/assets/js/sales*.js`) against the Laravel Sales Cart Blade (`laravel/app/Http/Controllers/Admin/SalesCartController.php` + `laravel/app/Services/Sales/*` + `laravel/resources/views/admin/sales/cart.blade.php`).
>
> **Mode:** Analysis only. No code was modified.
>
> **Audit Date:** 2026-07-21
> **Auditor:** Super Z (automated code analysis)
> **Repository:** `sajidchowdhury/debugRC` @ `main`

> **REMEDIATION PROGRESS (post-audit):** This audit drives a remediation
> backlog tracked in `docs/REMEDIATION_LOG.md`. Each gap closed is marked
> inline in §6 (Missing Features) and §9 (Recommendations) with
> `✅ Fixed by R#`. See also `docs/SESSION_CONTEXT.md` for the
> persistent session memory.
>
> | ID  | Title                                                       | Status    |
> |-----|-------------------------------------------------------------|-----------|
> | R1  | Replace select2 500-row dropdowns with live search endpoints | ✅ Done   |
> | R2  | (next item — user to assign)                                | ⏳ Pending |

---

## 1. Executive Summary

The Legacy Sales Entry is a **mature, feature-rich POS module** built on a custom PHP 8 MVC framework (no framework, hand-rolled router + base controller, MySQL via PDO, jQuery 3.6 + Bootstrap 5 + SweetAlert2). It has been hardened over many iterations: multi-customer cart tabs, barcode scanning, credit-limit override, branch-level soft holds, soft-delete invoices, full GL/customer-ledger reversal on edit/delete, Telegram + FCM notifications, multi-page bilingual invoice prints, server-side DataTables, CSV export, sales audit checklist, stale-draft cleanup, and an explicit reconciliation hub.

The Laravel Sales Cart Blade is the **Phase 8 re-implementation** of the same module on Laravel 11 + PostgreSQL. It is **API-first** — every cart operation has parallel Admin (Blade) and API (JSON) controllers sharing common services. It introduces strong guarantees that the Legacy system lacks: PostgreSQL Row-Level Security (RLS) on 28 tables, `pg_advisory_xact_lock` for atomic document-sequence generation, idempotency tokens on finalize (5–10 min cache), DB-level EXCLUDE constraints on payment allocations, generated columns for derived totals, table partitioning by month for `sales_invoices` and `stock_transactions`, full-text search indexes on products/customers, GIN JSONB indexes on cart items, and CTE-based materialized-view reports refreshed every 5 minutes.

**Net assessment:**

| Dimension | Verdict |
|---|---|
| Architectural cleanliness | Laravel is **clearly better** (Form Requests, Resources, services, RLS, partitioning). |
| POS UX richness | Legacy is **clearly better** (barcode, multi-tab cart, price-range slider, walk-in flows). |
| Concurrency safety | Laravel is **better** (idempotency tokens, advisory locks, EXCLUDE constraint) — but the Legacy system's pre-/post-lock double-check pattern is also safe in practice. |
| Business-rule parity | Laravel is **mostly on par**, with notable gaps in barcode, multi-customer tabs, walk-in customer, live credit display, and notification fan-out. |
| Performance characteristics | Laravel is **better on read paths** (CTE MVs, partitioning, full-text search) and **comparable on write paths**. |
| Production maturity | Legacy is **more battle-tested**; Laravel is **newer and partially incomplete** (orphaned `sales-create.js`, dropdown capped at 500 [**fixed by R1**], ~~no cart-level audit log~~ [**fixed by R4**], ~~missing payment idempotency~~ [**fixed by R2**]). |

**Top risks identified:**
1. **Legacy:** Race condition in `customer_ledger.running_balance` (no row-level lock on prior balance read).
2. **Legacy:** SQL-dialect mismatch — `persistDraftCartToDb` uses Postgres `ON CONFLICT` syntax on a MySQL-declared table; silently fails when enabled.
3. **Laravel:** Cart page customer/product dropdowns are hard-capped at **500 records** with no live search, breaking large catalogs.
4. ~~**Laravel:** No idempotency token on **payment create** — double-submit can create duplicate payments.~~ **Fixed by R2.**
5. ~~**Laravel:** Cart mutations (add/update/remove/clear) are **not audit logged**.~~ **Fixed by R4.**
6. **Both:** Credit-limit check is performed **before** the customer row is locked — a small race window exists in both systems.

**Top recommendations** (details in §9):
- Port legacy POS UX (barcode + multi-tab cart + live credit) into the Laravel cart blade.
- Add `customer_ledger` row-level lock or DB trigger to maintain running_balance atomically (carry forward to Laravel).
- Add idempotency tokens to payment, challan, and cart-mutation endpoints. **Payment done in R2. Challan done in R3.** Cart mutations follow a different pattern (audit logging, not idempotency) — **done in R4**.
- ~~Add cart mutation audit logging.~~ **Done in R4.**
- Replace select2 dump-500 dropdowns with live search endpoints (legacy already had this — `sales/search_customer`, `sales/search_product`). **Done in R1.**

---

## 2. Legacy Workflow

The Legacy Sales Entry is a single-page POS UI (`sales/create.php`) supported by 30+ AJAX endpoints in `SalesController.php` (806 lines). Routing is segment-based (`public/index.php`); auth is session-cookie + per-route role gate (`app/services/Security/RouteAccess.php`).

### 2.1 Customer Selection

- **UI:** Free-text `#customerSearch` input + hand-rolled typeahead (`sales-create.js::initCustomerTypeahead`, 250 ms debounce).
- **Search endpoint:** `GET sales/search_customer?term=` → `Helper::Search_Customers`:
  ```sql
  SELECT id, customer_code, customer_name, shop_name, mobile, credit_limit
  FROM customers
  WHERE (customer_name LIKE :term OR shop_name LIKE :term
         OR mobile LIKE :term OR customer_code LIKE :term)
    AND is_active = 1
  ORDER BY shop_name ASC LIMIT 20
  ```
- **Walk-in customer:** Not supported — a customer record is mandatory.
- **Credit panel:** On selection, `sales/customer_details?customer_id=` returns `credit_limit`, `recent_due` (latest `customer_ledger.running_balance`), and `due_left = credit_limit − recent_due`. Displayed inline.
- **Branch scoping:** Customer search is **NOT** branch-scoped (any active customer is searchable from any branch). Branch scoping applies only at the stock layer.
- **Recents:** Last 5 customers cached in `localStorage["sales_customer_recents"]` as quick-pick chips.

### 2.2 Product Selection

- **UI:** Free-text `#productSearch`. Two paths:
  1. **Typeahead** (200 ms debounce) → `sales/search_product?term=&branch_id=` → `StockAvailabilityService::searchProductsWithStock`. Joins `products` + `warehouse_stock` + `sales_invoice_dispatches`, plus **three correlated subqueries** on `product_price_history` for `default_rate`, `min_rate`, `max_rate`. Returns up to 30 rows.
  2. **Barcode scan** — Enter key triggers `fetchSalesProductByExactCode` → `sales/product_by_code?code=&branch_id=` → `StockAvailabilityService::findProductByExactCode` (UPPER/TRIM exact match on `product_code`). If found, immediately selected.
- **Stock display:** Suggestion list shows `available_qty` (physical − pipeline, branch-scoped). Items ≤ 0 are disabled; selecting them triggers a SweetAlert "Out of stock".
- **Price source:** Latest row in `product_price_history` by `(effective_from DESC, created_at DESC, id DESC)`. UI defaults `#sales_rate` to `default_rate`; cashier may override within `[min_rate, max_rate]` — enforced client-side (`salesValidateRateClient`) and server-side (`SalesServiceSupportTrait::validateRateInRange`).

### 2.3 Cart Workflow

- **Server-side cart** stored in `$_SESSION['sales_draft_carts'][$customer_id]`.
- Optional DB persistence to `sales_draft_carts` table, gated by `SALES_DB_DRAFT_CARTS` config flag. Unique key: `(user_id, customer_id)` — supports multi-customer-per-cashier.
- Mutations: `add_to_cart`, `update_cart_item`, `delete_from_cart`, `clear_tab_cart`, `hydrate_edit_cart` — every mutation writes through to `$_SESSION` (immediate) and the DB table (optional).
- **Client-side backup:** `localStorage["sales_cart_draft_backup"]` mirrored on every `loadCart`. Restore prompt on next visit if backup < 24 h old.
- **Multi-tab safety:** Same `(user_id, customer_id)` row is overwritten by concurrent tabs — last write wins, no optimistic concurrency.
- **Hydration on page load:** `restoreSessionCarts` calls `sales/list_draft_carts` to restore from `$_SESSION`; if empty, falls back to `hydrateSessionCartFromDb` (DB → session).

### 2.4 Multiple Invoices in One Session

- **Yes** — `#draft-tabs` dock in `sales/create.php` (L144–163). Each customer gets its own tab + cart pane (`createOrSwitchTab`).
- Tabs have per-tab item-count badges and × close buttons.
- Closing a tab calls `sales/clear_tab_cart` to wipe both `$_SESSION` and DB row.
- After finalize, the active customer's session cart is unset server-side (`SalesInvoiceOperationsTrait::finalizeSales` L182). UI shows a success SweetAlert with two buttons: "New invoice" (reload `sales/create`) or "Today's list" (`sales/today`).

### 2.5 Draft Invoices

- **Statuses observed:**
  - `draft` — just created, not yet sent to godown.
  - `godown_issued` — godown team notified (`godown_issued_at` set; `warehouse_id` assigned on dispatches).
  - `challan_completed` — delivery challan finalized, stock OUT, COGS posted.
  - `reversed`/`cancelled` — soft-deleted via `is_reversed = 1`.
- No distinct "held" status beyond `draft`.
- **Storage:** `sales_invoices` with `status`, `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason`, `godown_issued_at`, `journal_entry_id`, `call_a_day` columns.
- **Stale-draft cleanup:**
  - `countStaleDraftInvoices` / `listStaleDraftInvoices` — `WHERE status='draft' AND is_reversed=0 AND godown_issued_at IS NULL AND created_at < (NOW() - :days * INTERVAL '1 day')`. Default threshold 14 days.
  - `cancelStaleDraftInvoices` loops over up to 200 stale rows and calls `deleteInvoice` per row.
  - `runStaleDraftCleanupIfDue` throttled to once per 6 hours per branch via `$_SESSION['sales_stale_cleanup_at_{branch}']` timestamp.
  - Standalone cron: `database/scripts/cancel_stale_sales_drafts.php`.

### 2.6 Invoice Finalization

- **Trigger:** User clicks `#posStickyFinalize` or `.finalSubmitBtn` → `submitFinalInvoice` (`sales.js` L1047–1199).
- **Client pre-validation:** customer, branch, sales_by, sales_person, invoice_date all required; transport & discount numeric.
- **Hard server gate:** `POST sales/validate_cart` → `SalesCartOperationsTrait::validateCartForSubmit` returns `{valid, rate_errors[], stock_errors[]}`. Failures surfaced via `showCartValidationError`.
- **Finalize endpoint:** `POST sales/final_sales` → `SalesInvoiceService::finalizeSales` (`SalesInvoiceOperationsTrait.php` L6–200). Sequence:
  1. Re-fetch cart from `$_SESSION['sales_draft_carts'][$customer_id]`. Empty cart → error.
  2. Compute `total = subtotal + transport − discount`.
  3. **Credit-limit check** via `CustomerModel::wouldExceedCreditLimit`: `current_due + new_invoice_amount > credit_limit`. `current_due` is the latest `customer_ledger.running_balance`. If exceeded and no `credit_limit_override` flag → returns `status: credit_limit_exceeded, requires_override: true`. Override requires a reason ≥ 10 chars.
  4. Pre-transaction stock + rate validation.
  5. `db->beginTransaction()`. Inside the transaction:
     - `StockService::lockBranchProductsForUpdate` — `SELECT … FROM warehouse_stock ws INNER JOIN warehouses w ON w.id=ws.warehouse_id AND w.branch_id=:bid WHERE ws.product_id IN (...) FOR UPDATE`.
     - `assertBranchProductsAvailable` re-checks.
     - `generateSalesInvoiceCode($branch_id)` → `allocateDocumentSequence('sales_invoice', date('Ymd'))` — `SELECT last_number FROM document_sequences WHERE doc_type=:type AND period_key=:period AND branch_id=0 FOR UPDATE`, then `UPDATE … SET last_number = next`. Code format: `SI-YYYYMMDD-NNNN`. Globally unique, not per-branch.
     - INSERT `sales_invoices` (status='draft').
     - INSERT each line into `sales_invoice_items` (warehouse_id=NULL).
     - INSERT a row per line into `sales_invoice_dispatches` (ordered_qty, dispatched_qty=0, warehouse_id=NULL) — branch-level soft hold.
     - Insert a **debit** entry into `customer_ledger` (`reference_type='invoice'`, `running_balance = prev + total`).
     - **GL posting** via `JournalPostingService::postSalesInvoice`:
       - Dr AR (customer_receivable) — `total`
       - Dr Sales Discount (if discount > 0 and configured) — `discount`
       - Cr Sales Revenue — `revenueBase` (gross subtotal when discount ledger exists; otherwise `subtotal − discount`)
       - Cr Sales Revenue — `transport` (transport credited to same revenue ledger)
     - `setInvoiceJournalEntryId` links the JE back.
  6. `commit()`, then `unset($_SESSION['sales_draft_carts'][$customer_id])`.
  7. Controller writes a `sale_created` user-audit log, fires `SalesNotificationService::notifyNewSalesInvoice`, and asynchronously triggers `SalesTelegramNotifier::notifyInvoiceCreated`.

### 2.7 Payment Handling

- **Single payment per modal submission** — no split-payment UI in `sales/receive_modal.php`.
- **Modal:** loaded via AJAX into `#receiveModalContent`. Shows invoice total, paid-so-far, balance due, list of existing allocations with reverse buttons, fresh "Amount to receive" input defaulting to balance-due.
- **Methods:** Cash or Bank (radio cards). Bank mode reveals a `<select>` of active banks and a reference/cheque-no field.
- **Quick-amount chips:** 50 % / Full due / Clear.
- **Submit:** `POST sales/save_payment` → `SalesPaymentService::recordCustomerPayment`. Transaction:
  1. `SELECT … FROM sales_invoices WHERE id=:id FOR UPDATE` — locks the invoice row. Checks `is_reversed=0` and branch accessibility.
  2. Recomputes `paidSoFar` from `invoice_payment_allocations` joined to non-reversed `customer_payments`. Rejects if `paidSoFar + amount > total_amount + 0.01`.
  3. `generateCustomerPaymentCode($branch_id)` → `PAY-YYYYMMDD-NNNN`.
  4. INSERT `customer_payments` (transaction_type='receive').
  5. Insert **credit** entry into `customer_ledger` (`reference_type='payment'`, `running_balance = prev − amount`).
  6. INSERT `invoice_payment_allocations` (invoice_id, payment_id, allocated_amount).
  7. `JournalPostingService::postCustomerPayment` — Dr Cash or Bank / Cr AR.
  8. `BranchIntercompanyService::settleFromCustomerPayment` — allocate payment against outstanding branch-demand orders.
  9. commit.
- **Partial payment:** fully supported. `balance_due = total − SUM(allocations)`; `is_fully_paid = balance_due < 0.01`.

### 2.8 Stock Update

- **NOT decremented at finalize.** Finalize inserts a `sales_invoice_dispatches` row with `dispatched_qty=0` — a pipeline reservation that reduces `available_qty` (`StockAvailabilityService::getBranchAvailableQty`).
- **Physical decrement** happens later at **challan completion** in the godown/challan workflow (`StockTransactionModel::updateWarehouseStock` with negative qty). That step produces the COGS journal via `JournalPostingService::postSalesChallanCOGS`.
- **Lock:** `StockService::lockBranchProductsForUpdate` SELECT-FOR-UPDATE on `warehouse_stock` rows for the branch + product set, inside the finalize transaction.
- **Per-branch vs per-warehouse:** Soft hold is branch-level (`warehouse_id=NULL`) while the invoice is `draft`. Once `godown_issued_at` is set, `warehouse_id` is assigned on each dispatch row, and the hold becomes warehouse-specific (migration `041_draft_dispatch_soft_hold.sql`).
- **Negative stock prevention:** `assertBranchProductsAvailable` raises an error if `requested > available + 0.0001`. `StockTransactionModel::updateWarehouseStock` also throws `RuntimeException("Insufficient stock in warehouse...")`. DB-level CHECK enforced by migration `018_warehouse_stock_non_negative.sql`.

### 2.9 Customer Due Update

- **Reference type:** `customer_ledger.reference_type` is VARCHAR. Values: `'invoice'`, `'payment'`, `'reversal'`, `'invoice_adjustment'`, `'opening'`.
- **Running balance:** Each insert computes `running_balance = prev_balance ± amount` from `SELECT running_balance FROM customer_ledger WHERE customer_id=:cid ORDER BY id DESC LIMIT 1` — **without FOR UPDATE**.
- **Reversal handling:**
  - On invoice delete — a **credit** reversal row is inserted (`reference_type='reversal'`, `is_reversed=1`, `running_balance = current − old_total`).
  - On invoice edit — the old total is reversed (credit row) and a new debit row is inserted.
  - On payment reversal — a **debit** reversal row is inserted.

### 2.10 Ledger / Accounting Update

- **Tables:** `journal_entries` (header) + `journal_lines` (double-entry). Strict DR=CR check at `JournalEntryModel::createEntry` L37.
- **Invoice posting** (`postSalesInvoice`):
  - Dr AR — `total_amount`
  - Dr Sales Discount (if configured) — `discount`
  - Cr Sales Revenue — `revenueBase`
  - Cr Sales Revenue — `transport_cost` (treated as additional revenue)
- **VAT:** none. No VAT/sales-tax ledger is referenced anywhere.
- **Payment posting** (`postCustomerPayment`):
  - Dr Cash (if `payment_mode='cash'`) OR Dr Bank-specific ledger (via `bank_ledger_mappings`)
  - Cr AR — `amount`
- **Reversal:** `JournalEntryModel::createReversingEntry` copies all lines with debits and credits swapped, marks original `is_reversed=1`, sets `reversal_of_entry_id` on the new entry.
- **Branch intercompany:** On customer payment, `BranchIntercompanyService::settleFromCustomerPayment` settles outstanding branch-demand obligations. Reversal calls `reverseCustomerPaymentSettlements`.
- **Accounting-period gate:** `AccountingPeriodService::validatePostingDate` refuses posting to closed periods.

### 2.11 Printing

- **Payment receipt:** `sales/print_receipt/{id}?payment_id=N` → `views/sales/print_receipt.php` (Bangla/English bilingual). Triggered from `sales-receive-payment.js` after successful save.
- **Invoice copy:** `sales/invoice_copy/{id}` → `views/sales/invoice_copy.php`. Multi-page A4 (17 items/page via `array_chunk`). Includes "এই চালানের পেমেন্ট", "পূর্বের বকেয়া", "মোট বকেয়া" — uses `Customer_Due_Break_Down`.
- **Godown copy / challan copy:** lives in `app/views/challan/` (outside sales scope).
- **Auto-print on finalize:** No. Finalize shows a success SweetAlert with two buttons; the user must click the print icon on Today's Sales to open `invoice_copy`.
- **Paged.js:** `public/assets/js/print_invoice.js` is the Paged.js v0.4.3 polyfill (33 K lines, MIT). **Not currently included** by any sales view — invoice_copy relies on native browser print pagination.

### 2.12 Editing Invoices

- **Edit allowed only when:**
  - `status='draft'`
  - `godown_issued_at IS NULL`
  - `is_reversed=0`
  - No payments exist (`invoiceHasPayments` returns false)
- **Customer cannot be changed** — edit view (`sales/edit.php` L50) renders `customer_id` as a hidden input with a "Locked" customer card.
- **`updateExistingInvoice` flow:**
  1. `SELECT … FOR UPDATE` on the invoice row.
  2. Lock branch warehouse_stock rows.
  3. Insert a `reversal` credit row in `customer_ledger` for the **old** total.
  4. Insert a new `invoice` debit row for the **new** total.
  5. UPDATE `sales_invoices` (subtotal, discount, transport, total, narration, sales_person, salesman_id, invoice_date, branch_id).
  6. DELETE old `sales_invoice_items`; INSERT new ones.
  7. DELETE old dispatchers and dispatches; re-insert soft-hold dispatch rows.
  8. `JournalPostingService::reverseLinkedJournal($oldJournalId, 'Invoice edited: …')` — creates a reversing entry that flips Dr/Cr.
  9. `JournalPostingService::postSalesInvoice` — posts a fresh journal for the new total.
  10. `setInvoiceJournalEntryId($invoice_id, $newJournalId)`.
  11. commit.
- **Audit trail:** `SalesController::update` logs `sale_updated` via `UserAudit`. If the credit limit was overridden, a separate `sale_credit_limit_override` entry is written.

### 2.13 Deleting / Voiding Invoices

- **Soft delete only** — `is_reversed=1`, `reversed_at=NOW()`, `reversed_by`, `reverse_reason`. The invoice row remains in `sales_invoices`; queries filter `is_reversed=0`.
- **Hard blocks** (`deleteInvoice`):
  - Already reversed → error.
  - `status ≠ 'draft'` → error ("Only draft invoices (before godown/challan) can be deleted").
  - `godown_issued_at` set → error.
  - A non-reversed `sales_challans` row exists → error.
  - Any `sales_invoice_dispatches` row with `dispatched_qty > 0` → error.
  - `invoiceHasPayments` true → error.
- **Reversal journal:** if `journal_entry_id` is set, `JournalPostingService::reverseLinkedJournal` is called.
- **Customer ledger reversal:** a `reversal` credit row is inserted with `running_balance = current − total_amount`.
- **Stock restore:** since draft invoices never decremented physical stock, only `DELETE FROM sales_invoice_dispatches WHERE sales_invoice_id=:id` is needed to restore availability.

---

## 3. Laravel Workflow

The Laravel Sales Cart is part of **Phase 8** of the RCErp migration. It is structured as an API-first sales workflow with five distinct sub-phases:

- **8.1** Sales Cart — per-user-per-customer draft cart in `sales_draft_carts`
- **8.2** Sales Invoice Finalize — cart → draft invoice + GL Dr AR / Cr Revenue + customer_ledger
- **8.3** Sales Challan — godown prep → stock OUT + COGS GL
- **8.4** Customer Payment — Dr Bank/Cash / Cr AR + intercompany settlement
- **8.5** Sales Return — stock IN at ORIGINAL avg_cost + COGS reversal

Each phase has **parallel Admin (Blade) and API (JSON) controllers** sharing common services. Branch isolation is enforced at **four layers**: BranchScope Eloquent global scope, `EnforceBranchIsolation` middleware, `SalesAccess::assertBranchAccessible` service-level check, and PostgreSQL RLS policies.

### 3.1 Page Load — `SalesCartController@index`

Preloaded data passed to the blade:
- `$customers` = `Customer::active()->orderBy('customer_name')->limit(500)->get()` — capped at 500.
- `$products` = `Product::active()->orderBy('product_name')->limit(500)->get()` — capped at 500.
- `$selectedCustomerId` from query string `?customer_id=X`.
- `$cartData` = full cart array (if `customer_id` is passed) via `SalesCartService::getCart(auth()->id(), $customerId, $branchId)`.
- `$branchId` = `session('branch_id', 0)`.

The blade re-serializes `$cartData` into a JSON `INITIAL_CART` and pushes it as `@json($initialCart)` — avoids an extra `/cart/load` round-trip when the page is opened with `?customer_id=X`.

**No payment methods, branches, or warehouses are preloaded on the cart page** — only customer + product + branch context. Branches/warehouses/payment methods are loaded on the customer-payment create page instead.

### 3.2 Customer Selection

- **Live search:** None. The customer dropdown is a server-rendered select2 populated with the first 500 active customers.
- **Walk-in customer:** Not supported.
- **Credit-limit check:** Lazy — only at finalize time via `GET /admin/sales/credit-check?customer_id=X&amount=Y`. **No live credit balance display on the cart page itself.**
- **Branch scoping:** Customer dropdown is NOT branch-scoped (Customer model doesn't `use BranchScope`). Intentional — customers can be served from any branch. The invoice is branch-scoped at finalize time.

### 3.3 Product Selection

- **Barcode:** No barcode scanning. Product picker is select2 server-rendered with first 500 active products.
- **Stock availability API:** `GET /admin/sales/cart/availability?product_id=X` → `SalesCartController@checkAvailability` → `StockAvailabilityService::getBranchAvailableQty` + `getBranchWarehouseBreakdown`. The blade calls this on product change and renders a per-warehouse breakdown table on the right rail.
- **Price source** (SalesCartService.php L379–408 `getProductPriceRange`):
  1. **Product price history** — `effective_from <= today AND (effective_to IS NULL OR effective_to >= today)`, ordered by `effective_from DESC`. Returns `{min_rate, max_rate, default_rate}`.
  2. **Fallback** — `products.sales_rate` (single value, used as min=max=default).
  3. **Manual entry** — user may type any rate ≥ 0, but validation rejects rates outside `[min_rate, max_rate]` (±0.01 tolerance).

The product option carries `data-default-rate`; on product change, the rate input is auto-filled with `parseFloat(defaultRate).toFixed(2)`.

### 3.4 Cart Workflow

The cart is **server-side persisted** in `sales_draft_carts` (DB-backed, not session). Per-user-per-customer; unique key `(user_id, customer_id)`. `SalesDraftCart::getOrCreate()` uses `firstOrCreate` on this composite key.

**Sync events:**
- On customer change → POST `/cart/load` (full cart re-read).
- On "Add to Cart" → POST `/cart/add`. Same product + same rate merges qty; same product + different rate → `RuntimeException` ("already in cart at rate X, remove it first").
- On inline qty/rate edit (debounced 300 ms) → POST `/cart/update`.
- On remove → POST `/cart/remove` (with SweetAlert confirm).
- On clear → POST `/cart/clear`.

Each mutation re-runs `validateCartItems()` and re-saves `items_json` before returning the full cart payload, which the JS uses to re-render the whole table.

**Scope:** Per-user (`auth()->id()`), per-customer (`customer_id`), per-branch (`session('branch_id')`). The branch_id is stored on the cart row but the unique key is only `(user_id, customer_id)` — so a salesman switching branches with the same customer would share the same cart.

### 3.5 Draft State

- **Cart statuses:** None beyond `is_soft_hold` (boolean). Soft-hold is a UI flag ("reserved for later"); it does not block finalize.
- **Invoice statuses** (SalesInvoice.php L163–167):
  - `draft` — cart finalized, awaiting godown prep (GL posted, stock NOT yet moved)
  - `confirmed` — godown prepared (`is_godown_prepared=true`), awaiting challan issue
  - `cancelled` — cancelled (GL + customer_ledger reversed, `is_reversed=true`)
  - No explicit `challan_issued` status — flag `is_challan_issued=true` on `confirmed` status
  - No explicit `paid` status — `paid_amount` / `due_amount` are GENERATED columns

**Stale draft cleanup:** `sales:cancel-stale-drafts` Artisan command (CancelStaleSalesDrafts.php), scheduled `dailyAt('02:00')`. Threshold = `config('sales.stale_draft_days')` = 14 days default. Selects drafts with `status='draft' AND is_reversed=false AND is_godown_prepared=false AND is_challan_issued=false AND created_at < NOW() - INTERVAL 'N days'`, limit 200 per run. Each cancel calls `SalesInvoiceService::cancelInvoice($id, $systemUserId, $reason)` which reverses GL + customer_ledger atomically. Audit logged as `stale_drafts_cancelled`. Gated by `config('sales.stale_draft_auto_cancel', true)`.

There is also a manual endpoint `POST /admin/sales/cancel-stale-drafts` for manager/admin use with optional `dry_run=true` for preview.

### 3.6 Invoice Finalization

- **Endpoint:** `POST /admin/sales/finalize` (web) or `POST /api/v1/sales/invoices` (API).
- **Validation:** `FinalizeInvoiceRequest::rules()` — see §5.4 for full quote. Key fields: `customer_id`, `branch_id`, `invoice_date`, `discount_amount`, `transport_cost`, `notes`, `is_soft_hold`, `credit_limit_override`, `override_reason`, `idempotency_token` (UUID required), `dispatcher_ids[]`.
- **Idempotency token** (P2-6):
  - Client must send a UUID `idempotency_token` (cart.blade.php L983–993 generates UUID v4 via `window.crypto.randomUUID` with fallback).
  - Server checks `Cache::get('finalize:' . $token)` (web) or `'api:finalize:' . $token` (API).
  - If cached, returns the original response with `idempotent_replay: true` and "Duplicate submission detected".
  - Cache TTL: **10 minutes** (web) / **5 minutes** (API).
- **Transaction wrapping:** `SalesInvoiceService::finalizeFromCart` wraps everything in `DB::transaction()`. Inside:
  1. `assertBranchAccessible($branchId)` — P0-8 defense-in-depth.
  2. Load cart via `SalesCartService::getCart($userId, $customerId, $branchId)`.
  3. Validate cart (`$cartData['validation']['valid']` must be true).
  4. Compute totals: `total = subTotal + transport - discount`.
  5. Credit limit check: `new_balance = current_AR_balance + total`. If exceeds and no override → throw. If override + reason < 10 chars → throw.
  6. **Lock branch products FOR UPDATE** — `StockService::lockBranchProductsForUpdate`.
  7. **Re-check availability after locking** — protects against race conditions.
  8. Generate invoice code via `DocumentSequenceService::nextCode(docType: 'sales_invoice', prefix: 'INV', datePart: 'Ymd', padLength: 4)` → `INV-YYYYMMDD-NNNN`.
  9. INSERT `sales_invoices` (status='draft', `paid_amount=0`, `due_amount` is GENERATED).
  10. INSERT `sales_invoice_items` (warehouse_id=NULL — assigned at godown).
  11. INSERT `sales_invoice_dispatches` (ordered_qty=qty, dispatched_qty=0, warehouse_id=NULL).
  12. **Post GL FIRST** — `postInvoiceGL()`:
      - Dr Accounts Receivable (nature: `ar`) — full `total`
      - Cr Sales Revenue (nature: `sales_revenue`) — `subTotal` (if discount ledger configured) or `subTotal - discount`
      - Dr Sales Discount (only if discount > 0.01 AND discount ledger configured) — `discount`
      - Cr Transport Revenue (only if transport > 0.01 AND ledger configured) — `transport`
  13. Post `customer_ledger` debit via `SubLedgerService::postCustomerLedgerEntry`.
  14. UPDATE `sales_invoices.journal_entry_id`.
  15. **Clear the cart** — `SalesCartService::clearCart($userId, $customerId)`.
  16. Assign dispatchers (if `dispatcher_ids[]` provided) — validates each dispatcher has `role='dispatcher'`, `is_active=true`, `branch_id=$branchId`.
  17. Audit log: `sale_created` (via `SalesAuditLogger::saleCreated`).
  18. Audit log: `credit_limit_override` if override used.
  19. Invalidate pipeline cache (`StockAvailabilityService::invalidatePipelineForInvoice`).

**Invoice number format:** `INV-YYYYMMDD-NNNN`. Sequence per-day-per-doc_type, allocated atomically via `pg_advisory_xact_lock`.

### 3.7 Challan / Godown Copy

The Laravel flow splits dispatch from invoicing into a **two-step godown → challan process** (SalesChallanService.php):

**Step 1: Prepare Godown** (`prepareGodown`, SalesChallanService.php L60–115)
- Endpoint: `POST /admin/sales-challans/godown/{invoiceId}` or `POST /api/v1/sales/challans/godown`.
- Form Request: `PrepareGodownRequest`.
- Inside DB transaction:
  - Lock invoice FOR UPDATE.
  - Invoice must be `draft` (else throw "Only draft invoices can be godown-prepared").
  - For each invoice item: assign `warehouse_id` from `$warehouseAssignments[product_id]`.
  - For each item: check `StockService::getWarehouseQty` ≥ `item.qty` (per-warehouse physical check).
  - UPDATE `sales_invoice_items.warehouse_id` + `sales_invoice_dispatches.warehouse_id`.
  - UPDATE `sales_invoices` SET `status='confirmed', is_godown_prepared=true, godown_prepared_at=now()`.
  - Audit: `godown_prepared`.
  - **No stock movement, no GL** — purely warehouse assignment.

**Step 2: Issue Challan** (`issueChallan`, SalesChallanService.php L132–326)
- Endpoint: `POST /admin/sales-challans/issue/{invoiceId}` or `POST /api/v1/sales/challans/issue`.
- Form Request: `IssueChallanRequest`.
- **R3: Idempotency** — client must send a UUID v4 `idempotency_token`. Server caches the response keyed by `challan:` (web, 10-min TTL) or `api:challan:` (API, 5-min TTL). Replays redirect to / return the originally-issued challan instead of throwing "Challan already issued for this invoice."
- Inside DB transaction:
  - Lock invoice + items + dispatches FOR UPDATE.
  - `assertBranchAccessible`.
  - Must be godown-prepared, not already challan-issued.
  - Generate challan code `CH-YYYYMMDD-NNNN`.
  - INSERT `sales_challans` header (transport details, transport_cost).
  - For each item:
    - `getWarehouseAvgCost($wid, $pid)` — if ≤ 0 → throw "zero avg_cost, receive stock first".
    - **Stock OUT** via `StockService::applyTransaction` with `qty=-$item.qty, rate=$avgCost` (moving-average rule).
    - INSERT `sales_challan_items` snapshot: `qty, issue_rate=$avgCost, cogs_amount` (GENERATED qty×issue_rate).
    - UPDATE `sales_invoice_dispatches` SET `dispatched_qty=ordered_qty, qty=ordered_qty`.
  - Post COGS GL: **Dr COGS / Cr Inventory** (single journal for sum of all lines).
  - **P2-3 Transport adjustment**: if challan form's `transport_cost` differs from invoice's original, snapshot `pre_challan_transport` + `pre_challan_total`, update invoice's `transport_cost + total_amount`, post adjustment GL (Dr/Cr AR + Transport Revenue swapped by sign), post `customer_ledger` `invoice_adjustment` entry for delta.
  - UPDATE `sales_invoices` SET `is_challan_issued=true, challan_issued_at=now(), cogs_journal_entry_id=$journalId`.
  - Audit: `challan_issued`.
  - Invalidate pipeline cache.

This split ensures salesmen can finalize invoices without touching warehouse, and warehouse staff can prep godown + issue challan independently. **Stock moves at challan issue, NOT at invoice finalize.**

### 3.8 Payment Handling

- **Separate CustomerPayment flow** — yes, fully separate from invoice finalize. Two-phase: create draft → confirm.
- **Endpoints** (web.php L572–590, api.php L171–186):
  - Web: `POST /admin/customer-payments` (auto-confirms in same request). **R2:** Now requires `idempotency_token` (UUID v4). Replays within 10 min redirect to the originally-created payment instead of creating a duplicate.
  - API: `POST /api/v1/sales/payments` with optional `auto_confirm=true` to skip the draft state. **R2:** Now requires `idempotency_token` (UUID v4). Replays within 5 min return the cached JSON response with `idempotent_replay: true`.
- **Split payment across cash/bank/cheque?** Partially. A single payment row has ONE `payment_mode` (cash | bank | mobile_banking | cheque | adjustment). For split payment, the user creates multiple payment records (one per mode). The customer_payments table doesn't support multi-mode split in one row.
- **Multi-invoice allocation** — yes, via `invoice_payment_allocations` (P1-4). The customer-payments create form sends parallel arrays `alloc_invoice_id[]` + `alloc_amount[]`. The API uses a nested `allocations[]` array with `{invoice_id, allocated_amount}` objects.
- **InvoicePaymentAllocation table** guarantees:
  - One row per (invoice_id, payment_id) — enforced by EXCLUDE constraint (migration `2025_01_21_000003`).
  - `CHECK (allocated_amount > 0)` — DB-level.
  - Trigger `trg_ipa_no_overallocation` — DB-level guard: `SUM(allocated_amount) > total_amount` → RAISE EXCEPTION.
  - FK `payment_id → customer_payments(id) ON DELETE CASCADE`.
- **On confirm** (CustomerPaymentService.php L136–208):
  1. Post type-specific GL (`postPaymentGL`).
  2. Post `customer_ledger` (credit for receive/discount/write_off, debit for refund).
  3. Multi-invoice allocation loop (one row per invoice).
  4. Validate total allocations ≤ payment amount (throw if exceeded).
  5. Intercompany settlement (if bank-mode + cross-branch bank): posts Dr Due-to-Branch / Cr Due-from-Branch + inserts `branch_ledger` row.
- **GL patterns by `transaction_type`:**
  - `receive`: Dr Bank/Cash / Cr AR (+ Dr Sales Discount / Cr AR for `discount_amount`)
  - `discount`: Dr Sales Discount / Cr AR (entire amount is discount)
  - `write_off`: Dr Bad Debt Expense (nature: write_off, falls back to finance_cost / operating_expense) / Cr AR
  - `payment` (refund): Dr AR / Cr Bank/Cash
- **Payment code prefixes by type:** PAY, DISC, WOFF, RFND (CustomerPaymentService.php L806–820).

### 3.9 Stock Update

- **NOT on cart-add** — cart is just metadata; validation checks availability but doesn't reserve stock.
- **NOT on invoice finalize** — finalize creates `sales_invoice_dispatches` with `dispatched_qty=0` and `warehouse_id=NULL`. This adds to the "sales pipeline" (soft reservation), reducing `available_qty` via `StockAvailabilityService::getBranchPipelineQty`.
- **NOT on godown prep** — only assigns warehouse_id, no stock movement.
- **On challan issue** — `StockService::applyTransaction(qty=-X, rate=avgCost)` decrements `warehouse_stock.qty` and creates an immutable `stock_transactions` row.
- **Per-branch / per-warehouse lock?** Yes — `StockService::lockBranchProductsForUpdate` inside the finalize transaction. Held until COMMIT/ROLLBACK.
- **Negative stock prevention?** Three layers:
  1. **Service-level** — `StockService::applyTransaction` checks `if (newQty < -QTY_TOLERANCE) throw "Insufficient stock"`.
  2. **DB-level CHECK** — `warehouse_stock CHECK (qty >= -0.0001)`.
  3. **DB trigger** — `prevent_negative_stock()` raises a business-friendly error.
- **Advisory locks** used for document sequence generation (`pg_advisory_xact_lock`). Not used for stock locking (which uses row-level `FOR UPDATE`).

### 3.10 Customer Due Update

- **On finalize** — `SubLedgerService::postCustomerLedgerEntry` writes a row to `customer_ledger`:
  - `customer_id`, `branch_id`, `transaction_date`, `transaction_type='sales_invoice'`
  - `reference_type='sales_invoice'`, `reference_id=$invoiceId`
  - `debit=$totalAmount`, `credit=0` (customer owes more)
  - `journal_entry_id` links back to the GL JE
  - `is_reversed=false`
- **On payment confirm** — for `receive/discount/write_off`: `credit=$amount+$discountAmount` (customer owes less). For `payment` (refund): `debit=$amount` (customer owes more).
- **Running balance?** Yes — `customer_ledger` has a `balance` column. The model has a `getBalance($customerId)` static helper that computes `SUM(debit) - SUM(credit)` for non-reversed entries — recomputed on demand, not maintained as a true running balance.
- **Reversal on return?** Yes — on `SalesReturnService::confirmReturn`, the customer_ledger gets a credit entry for the return total, and the GL gets a Dr Sales Return / Cr AR reversal.

### 3.11 Ledger / Accounting Update

- **On finalize** (already detailed in §3.6): single journal entry with 2–4 lines, balanced. `journal_posting_logs` row inserted (action='posted').
- **On challan issue** — Dr COGS / Cr Inventory (single journal for all lines, `cogs_amount = Σ(qty × avg_cost)`).
- **On payment confirm** — type-specific. All create a `journal_entries` row + N `journal_lines` rows + `journal_posting_logs` row.
- **On return confirm** — two GL entries:
  - Dr Sales Return / Cr AR (revenue reversal, at sales rate)
  - Dr Inventory / Cr COGS (cost reversal, at ORIGINAL avg_cost snapshotted from the challan)
- **Period validation** — `JournalPostingService::validatePeriod` checks `accounting_periods.closed_through_date` for the branch. Reversals bypass this check via `skip_period_check: true`. Admin override is configurable.

### 3.12 Printing

- **Three invoice print layouts:**
  1. `GET /admin/sales-invoices/{id}/print-invoice` → `print_invoice.blade.php` — A4 multi-page (17 items/page), paginated, watermarked "CANCELLED" if reversed. Customer copy.
  2. `GET /admin/sales-invoices/{id}/print-godown` → `print_godown.blade.php` — picking list for warehouse staff.
  3. `GET /admin/sales-invoices/{id}/print-blank-godown` → `print_blank_godown.blade.php` — Bengali/English bilingual blank write-in template for manual picking.
- **Challan print:** `GET /admin/sales-challans/{id}/print-challan` → `print_challan.blade.php` — delivery note.
- **Payment receipt:** `GET /admin/customer-payments/{id}/print-receipt` → `print_receipt.blade.php`.
- **Auto-print?** No — each print route opens in a new tab. The user invokes `window.print()` from the print layout.
- **Print layout:** Uses `@extends('layouts.print')` — a separate minimal layout. Print CSS files: `invoice-print.css`, `godown-print.css`, `challan-print.css`, `payment-receipt-print.css`.

### 3.13 Editing Invoices

- **Edit route:** `GET /admin/sales-invoices/{id}/edit` → `edit.blade.php` → `PUT /admin/sales-invoices/{id}` → `SalesInvoiceController@update`.
- **What can be edited:**
  - Items (qty, rate, condition_state) — full re-write of items array.
  - Invoice date, sales person (free text), discount amount, transport cost, notes, is_soft_hold toggle, credit limit override + reason, dispatchers (multi-select).
- **What cannot be edited** — guarded by `SalesInvoiceService::assertEditable`:
  - Status must be `draft` (not `confirmed`/`cancelled`)
  - `is_godown_prepared` must be false
  - `is_challan_issued` must be false
  - `is_reversed` must be false
  - No active payments against the invoice
- **On update** (`SalesInvoiceService::updateInvoice` L399–655):
  1. Reverse old GL JE + linked customer_ledger via `JournalReversalService::reverseByJournalEntry` (cascade).
  2. DELETE old items + dispatches + dispatchers.
  3. INSERT new items + dispatches (warehouse_id=NULL — godown must re-assign).
  4. Re-assign dispatchers.
  5. Post NEW GL JE (Dr AR / Cr Revenue + Discount + Transport).
  6. Post NEW customer_ledger debit (new total).
  7. UPDATE invoice header (`is_godown_prepared=false, godown_prepared_at=null` — edit invalidates prior godown prep).
  8. Credit limit re-check (NET increase = `max(0, newTotal - oldTotal)`, not full new total).
  9. Audit log: `sale_updated` (+ `credit_limit_override` if override used).

### 3.14 Deleting / Voiding Invoices

- **Soft delete?** Yes — `SalesInvoice` uses `SoftDeletes` trait. But cancellation is NOT a soft delete — it's a status change + reversal.
- **Cancel/void flow** (`SalesInvoiceController@cancel` → `SalesInvoiceService::cancelInvoice`):
  1. Lock invoice FOR UPDATE.
  2. `assertBranchAccessible`.
  3. Status must be `draft` (confirmed invoices must be cancelled via reversing challan first).
  4. Guard: `hasActiveChallan` → throw "Cannot cancel: a delivery challan exists. Reverse the challan first."
  5. Guard: `invoiceHasPayments` → throw "Cannot cancel: payments have been received. Reverse the payments first."
  6. Reverse GL JE + linked customer_ledger via `JournalReversalService::reverseByJournalEntry` (cascade reversal — swap Dr/Cr).
  7. UPDATE invoice: `status='cancelled', is_reversed=true, reversed_at=now(), reversed_by=$userId, reverse_reason=$reason`.
  8. Audit: `sale_cancelled`.
  9. Invalidate pipeline cache.
- **Reversal journal?** Yes — `JournalPostingService::reverseJournalEntry` creates a new JE with swapped Dr/Cr, marks the original `is_reversed=true`. Originals are never mutated (append-only ledger). Reversal bypasses period close check.
- **Stock restore?** NOT on invoice cancel — because stock never moved at finalize. On **challan cancel**, each stock_transaction is reversed via `StockService::reverseTransaction` (opposite-sign qty at original rate), GL COGS journal is reversed, dispatch rows reset to `dispatched_qty=0`, and the invoice is reset to `status='draft', is_godown_prepared=false, is_challan_issued=false` so it can be re-edited.

---

## 4. UI/UX Comparison

### 4.1 Overall Layout

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| Framework stack | Custom PHP 8 MVC + Bootstrap 5 + jQuery 3.6 + SweetAlert2 + Font Awesome 6 + DataTables | Laravel 11 Blade + Bootstrap 5 + jQuery 3.6 + SweetAlert2 + Select2 + Bootstrap Icons + Font Awesome 6 | **Same** |
| Page architecture | Single POS page (`sales/create.php`) with multi-customer tab dock + sticky bottom bar | Two-column cart page (`admin/sales/cart.blade.php`) with summary + validation + availability right rail | Legacy better for high-volume POS; Laravel better for audit clarity |
| Customer picker | Live typeahead (`initCustomerTypeahead`, 250 ms debounce) on ALL active customers | Select2 dropdown capped at 500 customers, no live search | **Legacy better** |
| Product picker | Live typeahead + barcode scan (Enter triggers exact-code lookup) | Select2 dropdown capped at 500 products, no barcode | **Legacy better** |
| Price display | Price-range slider band with min/default/max thumb + red/amber/green status | Plain rate input with min/max hint text below | **Legacy better** |
| Stock display | Inline "Available (branch)" badge per cart row + per-warehouse breakdown modal | Per-warehouse breakdown table on right rail + color-coded availability per row | **Laravel better** (always-visible breakdown vs modal) |
| Cart tabs | Multi-customer tab dock with per-tab item-count badges and × close buttons | Single cart per page — switching customers requires URL change or "Load Cart" button | **Legacy better** |
| Cart persistence indicator | None — silently syncs | "Not checked / Valid / Invalid" validation status card | **Laravel better** |
| Sticky bottom bar | Yes — item count + grand total + Finalize button always visible | No — Finalize button is in the cart actions card | **Legacy better** for long carts |
| Modals | Bootstrap modals + SweetAlert2 | SweetAlert2 only (Bootstrap modals not used) | **Same** |
| Toast notifications | SweetAlert2 toasts (top-end, 2.5 s) | SweetAlert2 toasts (top-end, 2.5 s) | **Same** |
| Mobile responsiveness | `col-xl-*` grids + mobile-cart cards with swipe-to-delete + qty steppers + 44 px input height + theme-color meta | `col-12 col-lg-*` grids + `table-responsive` + SweetAlert auto-resize | **Legacy better** (more deliberate mobile UX) |
| Bangla support | Mixed Bangla/English labels (ইনভয়েস নং, মোট বকেয়া) + Noto Sans Bengali web font | Hind Siliguri font loaded for guide.blade.php only; cart UI is English-only | **Legacy better** |
| Keyboard shortcuts | Enter selects first suggestion, Enter in product search → barcode lookup, Enter in rate → add-to-cart, ArrowUp/ArrowDown in suggestions | None | **Legacy better** |
| localStorage recents | Last 5 customers as quick-pick chips | None | **Legacy better** |
| localStorage cart backup | 24-h TTL restore prompt | Not needed (DB-backed) | Legacy better UX for offline; Laravel safer architecturally |

### 4.2 Customer Panel

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Live search | Yes (LIKE %term% on 4 columns, LIMIT 20) | No (capped 500 dropdown) | **Legacy better** |
| Walk-in customer | Not supported | Not supported | **Same (missing)** |
| Live credit display | Yes — `customer_details` returns `credit_limit`, `recent_due`, `due_left`; displayed inline | No — only checked at finalize via `credit-check` endpoint | **Legacy better** |
| Customer recents chips | Yes (last 5 in localStorage) | No | **Legacy better** |
| Branch scoping | Not scoped (any customer searchable) | Not scoped (same as legacy) | **Same** |

### 4.3 Product Panel

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Live search | Yes (LIKE %term%, joins stock + price history, LIMIT 30) | No (capped 500 dropdown) | **Legacy better** |
| Barcode scan | Yes (`product_by_code` exact-match endpoint) | No | **Legacy better** |
| Stock availability check | Inline badge + modal breakdown | Inline badge + always-visible right-rail table | **Laravel better** for visibility |
| Price source | `product_price_history` latest row + manual override within `[min, max]` | `product_price_history` latest row + manual override within `[min, max]` | **Same** |
| Price-range slider UI | Yes (visual band with thumb) | No (plain text hint) | **Legacy better** |
| Add-to-cart merge rule | Same product + same rate → merge qty | Same product + same rate → merge qty; different rate → throw "remove first" | **Legacy better** (more lenient) |

### 4.4 Cart Table

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Inline qty edit | Yes (stepper −/qty/+) | Yes (number input, 300 ms debounce) | **Same** |
| Inline rate edit | Yes | Yes | **Same** |
| Inline total recalculation | Yes (immediate) | Yes (immediate) | **Same** |
| Swipe-to-delete (mobile) | Yes | No | **Legacy better** |
| Multi-customer tabs | Yes (one cart per customer, switchable) | No (single cart per page) | **Legacy better** |
| Per-tab item count badge | Yes | N/A | **Legacy better** |
| Cart total display | Per-tab footer + sticky bottom bar | Summary card on right rail | **Same** |
| Finalize button location | Sticky bottom bar + per-tab footer | Cart actions card (no sticky) | **Legacy better** |

### 4.5 Finalize Modal

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Modal type | SweetAlert2 with custom HTML | SweetAlert2 with custom HTML | **Same** |
| Fields collected | invoice_date, discount, transport, sales_person, narration, override + reason | invoice_date, discount, transport, sales_person, dispatchers multi-select, notes, soft-hold checkbox, override + reason | **Laravel better** (dispatchers + soft-hold) |
| Pre-finalize validation | Yes (`sales/validate_cart` round-trip) | Yes (`/cart/validate` round-trip) | **Same** |
| Credit-limit pre-check | Yes (inline via `customer_details`) | Yes (inline via `credit-check` endpoint) | **Same** |
| Idempotency token | No | Yes (UUID v4, 5–10 min cache) | **Laravel better** |
| Live total recalculation in modal | Yes | Yes (`didOpen` callback) | **Same** |
| Success screen | SweetAlert with "New invoice" / "Today's list" buttons | SweetAlert with redirect to invoice show page | **Legacy better** (clearer next-action choice) |

### 4.6 Payment Flow

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Trigger location | Inline modal on Today's Sales (`sales/receive_modal/{id}`) | Separate page (`/admin/customer-payments/create`) | **Legacy better** (less context switching) |
| Payment methods | Cash, Bank | Cash, Bank, Mobile Banking, Cheque, Adjustment | **Laravel better** (more methods) |
| Transaction types | receive only | receive, discount, write_off, payment (refund) | **Laravel better** (richer types) |
| Split payment | No (single mode per submission) | No (single mode per row, multiple rows for split) | **Same (limited)** |
| Multi-invoice allocation | No (one invoice per modal) | Yes (parallel arrays `alloc_invoice_id[]` + `alloc_amount[]`) | **Laravel better** |
| Quick-amount chips | 50% / Full due / Clear | None | **Legacy better** |
| Auto-confirm | No (always confirmed) | Yes (web auto-confirms; API supports draft state with `auto_confirm=false`) | **Laravel better** (more flexible) |
| Reversal reason min length | 5 chars | Not specified | **Legacy better** (explicit rule) |

### 4.7 Today's Sales / Invoice List

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Listing | Server-side DataTables with smart sort + search | Standard Laravel pagination with filters | **Legacy better** (DataTables UX is richer) |
| Status chips | Yes (All / Awaiting payment / In progress / Draft / Godown issued / Challan done) with live counts | Basic status filter | **Legacy better** |
| Mobile cards variant | Yes (switches below 768 px) | No | **Legacy better** |
| CSV export | Yes (`sales/export`) | Yes (`CsvExportController@exportInvoices`) | **Same** |
| Call-It-A-Day | Yes (multi-select checkbox to hide from daily list) | Yes (batch endpoint) | **Same** |
| Receive payment inline | Yes (modal on the same page) | No (redirects to create page) | **Legacy better** |

### 4.8 Print

| Feature | Legacy | Laravel | Verdict |
|---|---|---|---|
| Invoice copy | `sales/invoice_copy/{id}` — multi-page A4, 17 items/page, bilingual | `print-invoice` — A4 multi-page, 17 items/page, watermarked "CANCELLED" if reversed | **Same** |
| Godown copy | `challan/godown_copy.php` (challan module) | `print-godown.blade.php` + `print-blank-godown.blade.php` (bilingual blank template) | **Laravel better** (separate routes for filled + blank) |
| Payment receipt | `sales/print_receipt/{id}?payment_id=N` — bilingual | `print-receipt` route | **Same** |
| Challan print | In challan module | `print-challan` route | **Same** |
| Auto-print on finalize | No | No | **Same (missing)** |
| Paged.js polyfill | Present but unused | Not present | **Same** |

---

## 5. Business Logic Comparison

### 5.1 Cart Persistence

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| Storage | `$_SESSION` (primary) + `sales_draft_carts` table (optional, gated by config flag) + `localStorage` backup (24-h TTL) | `sales_draft_carts` table only (DB-backed) | **Laravel better** (single source of truth) |
| Unique key | `(user_id, customer_id)` | `(user_id, customer_id)` | **Same** |
| Branch stored | Yes (cart row has branch_id, but unique key ignores it) | Yes (same — branch_id stored but not in unique key) | **Same (race risk in both)** |
| Cart recovery on browser crash | localStorage 24-h restore prompt | DB-backed — survives browser close, session timeout | **Laravel better** |
| Cart mutation audit log | No | No | **Same (missing in both)** |
| Multi-tab safety | No optimistic concurrency — last write wins | No optimistic concurrency — last write wins | **Same (risk)** |

### 5.2 Stock Reservation Model

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| Reservation trigger | At finalize (insert `sales_invoice_dispatches` with dispatched_qty=0) | At finalize (same — insert dispatches with dispatched_qty=0) | **Same** |
| Reservation granularity | Branch-level (`warehouse_id=NULL`) while draft; warehouse-level once `godown_issued_at` set | Branch-level (`warehouse_id=NULL`) while draft; warehouse-level once `is_godown_prepared=true` | **Same** |
| Physical stock decrement | At challan completion (`StockTransactionModel::updateWarehouseStock`) | At challan issue (`StockService::applyTransaction`) | **Same** |
| Pipeline calculation | `physical − SUM(ordered_qty − dispatched_qty)` on open invoices | Same — `getBranchPipelineQty` | **Same** |
| Pipeline cache | No (computed on each query) | Yes — Redis 5-min TTL with explicit invalidation on finalize/edit/cancel/challan | **Laravel better** |
| Stock locking | `SELECT FOR UPDATE` on `warehouse_stock` rows joined to `warehouses WHERE branch_id` | Same — `lockForUpdate()` on `warehouse_stock` | **Same** |
| Negative stock prevention | Service check + DB CHECK constraint + DB trigger | Service check + DB CHECK constraint + DB trigger | **Same** |
| Pre/post lock re-check | Yes (validate before, assert after lock) | Yes (validate before, assert after lock) | **Same** |

### 5.3 Invoice Code Generation

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| Format | `SI-YYYYMMDD-NNNN` | `INV-YYYYMMDD-NNNN` | **Same** (different prefix only) |
| Scope | Global (`branch_id=0` row in `document_sequences`) | Per-branch (`branch_id` in key) | **Different design choice** — neither is wrong |
| Allocation mechanism | `SELECT last_number FROM document_sequences ... FOR UPDATE` then `UPDATE` | `pg_advisory_xact_lock(int4_hash)` then `UPDATE` | **Laravel better** (no disk I/O, transaction-scoped, no RLS conflict) |
| Race protection | Row lock — blocks concurrent transactions on same row | Advisory lock — blocks concurrent transactions on same hash | **Laravel better** (less contention) |
| First-of-period INSERT race | Two concurrent INSERTs race on UNIQUE constraint — caller does not catch | Same — first INSERT wins, second fails | **Same (risk in both)** |

### 5.4 Validation Rules

| Rule | Legacy | Laravel | Verdict |
|---|---|---|---|
| Customer required | `finalizeSales`, `addToCart`, `validateCartForSubmit` | `StoreCartRequest`, `FinalizeInvoiceRequest` (Form Request) | **Laravel better** (centralized in Form Request) |
| Product required | `addToCart` | `StoreCartRequest` | **Laravel better** (centralized) |
| Qty > 0 | `addToCart`, `updateCartItem` | `StoreCartRequest` (`min:0.001`) | **Same** |
| Rate > 0 | `addToCart`, `updateCartItem` | `StoreCartRequest` (`min:0`) | **Same** |
| Rate ∈ [min, max] | `SalesServiceSupportTrait::validateRateInRange` (server) + `salesValidateRateClient` (client) | `SalesCartService::validateCartItems` (server, ±0.01 tolerance) — **no client-side validation** | **Legacy better** (defense in depth) |
| Stock available | `validateCartStockAvailability` + `assertBranchProductsAvailable` | `SalesCartService::validateCartItems` + post-lock re-check | **Same** |
| Branch required | `validateCartForSubmit` | `FinalizeInvoiceRequest` (`exists:branches,id`) | **Laravel better** (FK existence check) |
| Invoice total > 0 | `JournalPostingService::postSalesInvoice` | Not explicit — relies on items > 0 | **Legacy better** |
| Journal Dr=Cr | `JournalEntryModel::createEntry` (PHP check) | `JournalPostingService::createJournalEntry` (PHP) + DB trigger | **Laravel better** (DB-level too) |
| Accounting period open | `AccountingPeriodService::validatePostingDate` | `JournalPostingService::validatePeriod` (reversals bypass) | **Laravel better** (reversal bypass is sensible) |
| Credit limit | `CustomerModel::wouldExceedCreditLimit` | `SalesInvoiceService::checkCreditLimit` | **Same** |
| Override reason ≥ 10 chars | `finalizeSales`, `updateExistingInvoice` | `FinalizeInvoiceRequest` (string|max:500 only — no min length) | **Legacy better** (explicit min) |
| Payment amount > 0 | `recordCustomerPayment` | `StorePaymentRequest` (`min:0.01`) | **Same** |
| Payment ≤ invoice balance | `recordCustomerPayment` | `CustomerPaymentService::allocateToInvoice` + DB trigger `trg_ipa_no_overallocation` | **Laravel better** (DB-level guard) |
| Reversal reason ≥ 5 chars | `reverseCustomerPayment` | Not specified | **Legacy better** |
| Invoice status='draft' for edit | `assertInvoiceEditableBySales` | `SalesInvoiceService::assertEditable` | **Same** |
| No payments on invoice being edited | `invoiceHasPayments` | `invoiceHasPayments` | **Same** |
| No godown issued for edit | `assertInvoiceEditableBySales` | `assertEditable` (`is_godown_prepared=false`) | **Same** |
| Branch accessibility | `Helper::assertInvoiceAccessible` | `SalesAccess::assertBranchAccessible` + BranchScope + middleware + RLS | **Laravel better** (defense in depth) |
| Salesman / sales_person / invoice_date required | Client-side only — server trusts `$_POST` | `FinalizeInvoiceRequest` (`required|date` for invoice_date) | **Laravel better** (server-enforced) |
| Idempotency token | None | `FinalizeInvoiceRequest` (`required|string|uuid`) | **Laravel better** |

### 5.5 Workflow Differences

| Step | Legacy | Laravel | Verdict |
|---|---|---|---|
| Cart → Invoice | Single finalize POST creates `sales_invoices` row + items + dispatches + GL + customer_ledger in one transaction | Same — single finalize POST in one transaction | **Same** |
| Godown assignment | Implicit — `godown_issued_at` set, dispatches updated with `warehouse_id` | Explicit step — `prepareGodown` endpoint, status changes to `confirmed` | **Laravel better** (explicit state) |
| Challan issue | Implicit — `challan_completed` status | Explicit step — `issueChallan` endpoint with `IssueChallanRequest` | **Laravel better** (explicit state) |
| Transport cost adjustment | Fixed at finalize | Adjusted at challan issue — snapshot `pre_challan_transport` + `pre_challan_total`, post adjustment GL + customer_ledger `invoice_adjustment` entry | **Laravel better** (captures real cost at dispatch) |
| Payment create | Single-step — always confirmed | Two-phase — draft then confirm (web auto-confirms; API supports `auto_confirm=false`) | **Laravel better** (more flexible) |
| Payment allocation | One invoice per payment | Multi-invoice allocation via `invoice_payment_allocations` with EXCLUDE constraint + DB trigger | **Laravel better** |
| Sales return | Separate module (`SalesReturnController`) — same pattern | Separate module (`SalesReturnApiController`) — same pattern | **Same** |
| COGS posting | At challan completion (`postSalesChallanCOGS`) | At challan issue (`postChallanGL` — Dr COGS / Cr Inventory) | **Same** |
| Cost basis for return | At sales rate (revenue reversal) — cost reversal unclear | At ORIGINAL avg_cost snapshotted from challan — `Dr Inventory / Cr COGS` at original cost | **Laravel better** (correct accounting) |

### 5.6 Reversal Model

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| GL reversal | `createReversingEntry` — copies lines with Dr/Cr swapped, marks original `is_reversed=1`, sets `reversal_of_entry_id` | `reverseJournalEntry` — same pattern + cascade reverses linked sub-ledger entries | **Laravel better** (cascade) |
| Customer ledger reversal | Manual — insert reversal row with `reference_type='reversal'`, `is_reversed=1` | Cascade via `JournalReversalService::reverseByJournalEntry` (reverses GL + customer_ledger atomically) | **Laravel better** (atomic) |
| Stock reversal | Manual — `reverseTransaction` with opposite-sign qty at original rate | Same — `StockService::reverseTransaction` | **Same** |
| Append-only ledger | Yes — originals never mutated | Yes — originals never mutated | **Same** |
| Period close bypass for reversal | No (period check applies) | Yes (`skip_period_check: true`) | **Laravel better** (sensible for corrections) |

### 5.7 Branch Isolation

| Layer | Legacy | Laravel | Verdict |
|---|---|---|---|
| Eloquent global scope | N/A (custom model layer) | `BranchScope` on `SalesInvoice`, `SalesChallan`, `CustomerPayment`, `SalesReturn` | **Laravel better** |
| Middleware | Per-route role gate (`RouteAccess`) | `EnforceBranchIsolation` middleware (validates `branch_id` in input + resolves URL params) | **Laravel better** |
| Service-level check | `Helper::assertInvoiceAccessible` | `SalesAccess::assertBranchAccessible` (called in every Sales service method) | **Same** |
| DB-level RLS | N/A (MySQL has no RLS) | PostgreSQL RLS on 28 branch-scoped tables (FORCE ROW LEVEL SECURITY — even table owner subject to it) | **Laravel better** (defense in depth) |
| Admin bypass | `canOverrideBranch()` returns true for admins | `app.is_admin = 'true'` GUC parameter | **Same** |

### 5.8 Performance

| Aspect | Legacy | Laravel | Verdict |
|---|---|---|---|
| DB | MySQL via PDO | PostgreSQL 16+ | **Laravel better** (RLS, partitioning, advisory locks, EXCLUDE) |
| Read paths | Procedural PHP loops with correlated subqueries | CTE-based materialized views refreshed every 5 min | **Laravel better** |
| Write paths | Single transaction per finalize/update/payment/reverse | Same | **Same** |
| Customer search | `LIKE %term%` on 4 columns, no FTS index | GIN full-text search index (`plainto_tsquery('simple', ?)`) — but **cart blade doesn't use it** (uses 500-row dropdown) | **Laravel better** architecturally, **Same** in practice |
| Product search | 3 correlated subqueries per row on `product_price_history` | Same pattern in `SalesCartService::enrichItems` — N queries for N items | **Same (N+1 risk in both)** |
| Stock availability | Computed on each query (no cache) | Redis cache (5-min TTL) with explicit invalidation | **Laravel better** |
| Document sequence lock | `SELECT FOR UPDATE` (disk-based row lock) | `pg_advisory_xact_lock` (shared memory, transaction-scoped) | **Laravel better** |
| Rate limiting | `RateLimiter::attempt` per `user_id` + endpoint (custom) | Per-route `api.rate:N` middleware (Redis INCR + EXPIRE with cache fallback) | **Laravel better** (Redis atomic) |
| Table partitioning | None — monolithic tables | `sales_invoices` and `stock_transactions` partitioned by RANGE(date) monthly | **Laravel better** |
| Indexes | Basic FK indexes + a few covering indexes | Partial indexes, BRIN on time-series, GIN on JSONB + FTS, covering indexes | **Laravel better** |
| N+1 risks | Documented in `assertBranchProductsAvailable` (loops per product) | Documented in `SalesCartService::enrichItems` (loops per item) | **Same (risk in both)** |
| Cart persistence cost | One DB write per cart mutation + one `loadCart` round-trip | One DB write per cart mutation + returns full cart payload (no extra loadCart) | **Laravel better** |

---

## 6. Missing Features

Features that exist in the **Legacy** system but are **missing or weakened** in the **Laravel** system:

### 6.1 POS UX

| # | Feature | Legacy Location | Laravel Status |
|---|---|---|---|
| 1 | **Barcode scanning** (`product_by_code` exact-match endpoint + Enter-key handler) | `sales.js::fetchSalesProductByExactCode` L67–82; `SalesController::product_by_code` L97–114 | **Missing** — Laravel cart uses select2 dropdown only |
| 2 | **Multi-customer cart tabs** (one POS page, N customer carts, switchable) | `sales-create.js::createOrSwitchTab` L657–693; `#draft-tabs` in `create.php` | **Missing** — Laravel cart supports one customer per page |
| 3 | **Live customer typeahead** (LIKE %term% on name/shop/mobile/code) | `initCustomerTypeahead` + `sales/search_customer` endpoint | **Missing** — Laravel uses capped 500-row select2 dropdown |
| 4 | **Live product typeahead** (LIKE %term% with stock + price join) | `initProductTypeahead` + `sales/search_product` endpoint | **Missing** — Laravel uses capped 500-row select2 dropdown |
| 5 | **Price-range slider band** (visual min/default/max with thumb + red/amber/green status) | `#priceRangePanel` in `create.php`; `sales.js::renderPriceRangeBand` | **Missing** — Laravel shows plain text hint |
| 6 | **Live credit-limit display** on cart page | `customer_details` endpoint + inline panel | **Missing** — Laravel checks credit only at finalize |
| 7 | **Customer recents chips** (last 5 in localStorage) | `localStorage["sales_customer_recents"]` | **Missing** |
| 8 | **localStorage cart backup** with 24-h restore prompt | `sales.js::saveCartDraftBackup` + `restoreCartIfNeeded` | **Not needed** — Laravel is DB-backed, but no offline fallback |
| 9 | **Sticky bottom bar** (item count + grand total + Finalize always visible) | `#posStickyBar` in `create.php` | **Missing** — Laravel Finalize is in cart actions card |
| 10 | **Mobile-cart cards with swipe-to-delete** | `sales-cart-mobile` + `initCartSwipeRemove` | **Missing** — Laravel uses `table-responsive` only |
| 11 | **Keyboard shortcuts** (Enter to select, ArrowUp/ArrowDown in suggestions) | `sales-create.js` various handlers | **Missing** |
| 12 | **Inline receive-payment modal** on Today's Sales | `sales/receive_modal/{id}` loaded via AJAX | **Missing** — Laravel redirects to separate create page |
| 13 | **Quick-amount chips** (50% / Full due / Clear) | `receive_modal.php` L110–114 | **Missing** |
| 14 | **Server-side DataTables** with smart sort (unpaid first, then oldest) + smart search across 8 columns | `sales/datatable_invoices` + `SalesInvoiceOperationsTrait::buildTodayInvoiceWhere` | **Missing** — Laravel uses standard pagination |
| 15 | **Status chips with live counts** (All / Awaiting payment / In progress / Draft / Godown issued / Challan done) | `sales/today_filter_summary` + `sales-today-index.js` | **Missing** — Laravel has basic status filter |
| 16 | **Mobile cards variant** for Today's Sales (switches below 768 px) | `sales-today-index.js::renderInvoiceCards` | **Missing** |

### 6.2 Notifications

| # | Feature | Legacy Location | Laravel Status |
|---|---|---|---|
| 17 | **Telegram notifications** on invoice created / payment received | `SalesTelegramNotifier::notifyInvoiceCreated` + `notifyTodayInvoicePayment` | **Missing** — Laravel has `ListenNotifyService` (PostgreSQL NOTIFY) but no Telegram integration |
| 18 | **FCM push notifications** (web-push registration + `SalesNotificationService`) | `sales/save_fcm_token` endpoint + `SalesNotificationService` | **Missing** — migration `2025_01_20_000007_drop_fcm_and_telegram_fields.php` explicitly drops these columns |

### 6.3 Business Rules

| # | Feature | Legacy Location | Laravel Status |
|---|---|---|---|
| 19 | **Cart add merge for same-product-different-rate** (allows multiple lines for same product at different rates) | `SalesCartOperationsTrait::addToCart` merges only on same rate; different rate creates new line | **Weakened** — Laravel throws "already in cart at rate X, remove it first" |
| 20 | **Override reason minimum length** (≥ 10 chars enforced server-side) | `finalizeSales` L42–49; `updateExistingInvoice` L312–317 | **Missing** — Laravel `FinalizeInvoiceRequest` has `string|max:500` only, no `min:10` |
| 21 | **Reversal reason minimum length** for payment reverse (≥ 5 chars) | `reverseCustomerPayment` L200 | **Missing** — Laravel has no explicit minimum |
| 22 | **Stale-draft cleanup throttle** (once per 6 h per branch via `$_SESSION` timestamp) | `runStaleDraftCleanupIfDue` L1157–1187 | **Different** — Laravel uses daily 02:00 cron only (no per-branch throttle) |
| 23 | **Credit limit override audit log** as separate event | `SalesController::final_sales` L297–304 logs `sale_credit_limit_override` | **Same** — Laravel also logs `credit_limit_override` via `SalesAuditLogger` |
| 24 | **Branch intercompany settlement** on customer payment | `BranchIntercompanyService::settleFromCustomerPayment` | **Same** — Laravel has equivalent in `CustomerPaymentService` |

### 6.4 Audit / Reporting

| # | Feature | Legacy Location | Laravel Status |
|---|---|---|---|
| 25 | **Sales audit checklist** (`SalesAudit/checklist` with health dashboard) | `SalesAuditController::checklist` + `SalesAuditModel::runHealthChecks` | **Partial** — Laravel has `admin/sales-audit/index.blade.php` (audit log viewer) but no health-check runner |
| 26 | **GL reconciliation hub** (`sales/reconcile` redirect to Reconciliation hub comparing AR subledger vs GL AR control) | `views/sales/reconcile.php` + `ReconciliationService` | **Same** — Laravel has `ReconciliationController` + `reconciliation.blade.php` |
| 27 | **Bangla end-user guide** (`sales/guide` page) | `views/sales/guide.php` + `sales-guide.js` | **Same** — Laravel has `admin/sales/guide.blade.php` |
| 28 | **Go-live checklist** (multi-role ops checklist) | `views/sales/go_live_checklist.php` | **Same** — Laravel has `admin/sales/checklist.blade.php` |

### 6.5 Misc

| # | Feature | Legacy Location | Laravel Status |
|---|---|---|---|
| 29 | **Walk-in customer** (anonymous POS sale) | Not supported in Legacy either | **Same (missing in both)** |
| 30 | **VAT / sales tax** ledger posting | Not supported in Legacy | **Same (missing in both)** |
| 31 | **Auto-print on finalize** | Not supported in Legacy | **Same (missing in both)** |
| 32 | **Split payment** (cash + bank + cheque in one submission) | Not supported in Legacy (single mode per modal) | **Same (missing in both — Laravel allows multiple rows but no split UI)** |
| 33 | **PWA installability** (theme-color meta, mobile-web-app-capable) | `sales/create.php` has `<meta name="theme-color">` | **Missing** in Laravel cart blade |

---

## 7. Laravel Improvements

Features where Laravel is **clearly better** than Legacy, with explanation:

### 7.1 API-First Architecture
Parallel Admin (Blade) and API (JSON) controllers share common services. The API layer (`SalesCartApiController`, `SalesInvoiceApiController`, etc.) supports:
- Bearer token auth (`ApiAuth` middleware, SHA-256 hashed tokens in `users.api_token`)
- Per-route rate limiting (30/60/120 req/min)
- JSON:API-style responses with `data` + `meta` envelope
- Form Request classes for input validation (separate from controllers)
- API Resources for output transformation (`CartResource`, `SalesInvoiceResource`, etc.)
- Interactive API docs at `/api/docs`

**Why better:** Enables mobile/AI sidecar integration without duplicating business logic. Legacy had no public API — every endpoint was coupled to session auth.

### 7.2 Form Request Validation
7 dedicated Form Request classes in `app/Http/Requests/Api/V1/Sales/`:
- `StoreCartRequest`, `UpdateCartRequest`, `FinalizeInvoiceRequest`, `StorePaymentRequest`, `IssueChallanRequest`, `PrepareGodownRequest`, `StoreReturnRequest`

**Why better:** Centralizes validation rules, auto-generates API docs, separates validation from controller logic. Legacy used inline `$request->validate` or manual `if/else` chains scattered across controllers and service traits.

### 7.3 PostgreSQL Row-Level Security (RLS)
- 28 branch-scoped tables protected (incl. `sales_invoices`, `sales_challans`, `sales_draft_carts`, `sales_returns`, `customer_payments`, `customer_ledger`)
- `FORCE ROW LEVEL SECURITY` — even table owner subject to RLS
- Custom GUC parameters: `app.branch_id`, `app.is_admin` (set by `SetAppBranchId` middleware)
- Admin bypass via `current_setting('app.is_admin', true) = 'true'`
- Safe default: `app.branch_id = 0` and `app.is_admin = false` → direct psql sees NO branch data

**Why better:** Defense-in-depth at the DB layer. Even if the application has a bug that forgets to filter by branch, the DB refuses to return rows from other branches. Legacy (MySQL) has no RLS equivalent — a single bug could leak cross-branch data.

### 7.4 Advisory Locks for Document Sequences
`DocumentSequenceService::nextCode` uses `pg_advisory_xact_lock($int4_hash)` instead of `SELECT FOR UPDATE` on `document_sequences`.

**Why better:**
- No disk I/O (shared memory only) — faster
- Transaction-scoped auto-release — no orphaned locks
- Non-blocking reads — sequence table reads don't wait for writes
- No RLS conflict — advisory locks don't depend on reading a row (Legacy's `SELECT FOR UPDATE` on `branch_id=0` row could fail for non-admin users due to RLS)

### 7.5 Idempotency Tokens on Finalize
- Client must send UUID `idempotency_token` (enforced by Form Request)
- Server caches response for 5–10 min (web: 10 min, API: 5 min)
- Duplicate submission returns cached response with `idempotent_replay: true`

**Why better:** Prevents duplicate invoices from double-clicks, network retries, or browser refresh. Legacy had no idempotency protection — a network hiccup during finalize could create two invoices.

### 7.6 EXCLUDE Constraint for Payment Allocations
`EXCLUDE USING gist (invoice_id WITH =, payment_id WITH =)` on `invoice_payment_allocations` + trigger `trg_ipa_no_overallocation` (prevents `SUM > total_amount`).

**Why better:** Three layers of protection against over-allocation:
1. Service-level check in `CustomerPaymentService::allocateToInvoice`
2. EXCLUDE constraint (DB-level uniqueness per invoice-payment pair)
3. Trigger (DB-level sum guard)

Legacy relied on service-level check only — a bug or race condition could over-allocate.

### 7.7 Generated Columns
- `sales_invoices.due_amount = total_amount - paid_amount` (GENERATED ALWAYS)
- `sales_invoice_items.amount = qty * rate` (GENERATED)
- `sales_challan_items.cogs_amount = qty * issue_rate` (GENERATED)
- `warehouse_stock.stock_value = qty * avg_cost` (GENERATED)
- `stock_transactions.total_value = qty * rate` (GENERATED)

**Why better:** Eliminates the risk of stale computed columns — no application code can write a wrong value. Legacy computed these in PHP and could drift on edge cases (e.g., partial updates).

### 7.8 Table Partitioning
`sales_invoices` and `stock_transactions` are RANGE-partitioned by date (monthly). PRIMARY KEY is `(id, invoice_date)` (composite, both columns needed for partition routing).

**Why better:**
- Automatic partition pruning for date-range queries
- Smaller indexes per partition
- Easier archival (drop old partitions)

Legacy had monolithic tables — performance degraded as data grew.

### 7.9 CTE Reports + Materialized Views
`CteReportService` with complex Common Table Expressions for gross margin, AR aging, general ledger, today summary, payable aging, trial balance. Materialized views refreshed every 5 min via `reports:refresh` scheduled command.

**Why better:** Single SQL query computes complex reports — much faster than procedural PHP loops. Legacy used correlated subqueries + PHP loops, which became slow at scale.

### 7.10 Two-Phase Payment (Draft → Confirm)
- Web: `POST /admin/customer-payments` auto-confirms in same request
- API: `POST /api/v1/sales/payments` with optional `auto_confirm=true` to skip draft state

**Why better:** Supports a "review then post" workflow for accountants. Legacy was always immediate-confirm — no chance to review before GL posting.

### 7.11 Type-Specific Payment GL
4 transaction types with distinct GL patterns:
- `receive`: Dr Bank/Cash / Cr AR
- `discount`: Dr Sales Discount / Cr AR (entire amount is discount)
- `write_off`: Dr Bad Debt Expense / Cr AR
- `payment` (refund): Dr AR / Cr Bank/Cash

Plus 4 distinct payment code prefixes: PAY, DISC, WOFF, RFND.

**Why better:** Proper accounting for each business event. Legacy conflated these — only `receive` was supported; discounts and write-offs had to be done via separate manual journal entries.

### 7.12 Transport Cost Adjustment at Challan Issue
P2-3: When the challan form's `transport_cost` differs from the invoice's original:
1. Snapshots original values (`pre_challan_transport`, `pre_challan_total`) on the invoice
2. Updates invoice `transport_cost + total_amount`
3. Posts a transport adjustment GL JE (Dr/Cr AR + Transport Revenue, swapped by sign)
4. Posts a `customer_ledger` `invoice_adjustment` entry for the delta

**Why better:** Captures the actual transport cost at the moment of dispatch (when the driver reports the real cost) while preserving audit trail of the original estimate. Legacy had no transport adjustment mechanism.

### 7.13 Sales Return at Original Avg Cost
On `SalesReturnService::confirmReturn`:
- Dr Sales Return / Cr AR (revenue reversal, at sales rate)
- **Dr Inventory / Cr COGS (cost reversal, at ORIGINAL avg_cost snapshotted from the challan)**

**Why better:** Correct accounting — returned inventory is valued at the cost it was sold at, not the current average cost. Legacy's cost reversal was unclear (likely used current avg_cost, which is wrong if prices changed between sale and return).

### 7.14 Multi-Invoice Payment Allocation
The customer-payments create form sends parallel arrays `alloc_invoice_id[]` + `alloc_amount[]` (web) or nested `allocations[]` array (API). One payment can settle multiple invoices.

**Why better:** Customer pays lump sum → allocate across multiple outstanding invoices in one operation. Legacy required one modal per invoice — slow for customers paying multiple invoices at once.

### 7.15 Explicit Guards on Cancel/Edit
Hard guards with clear error messages:
- "Cannot cancel: a delivery challan exists for this invoice. Reverse the challan first."
- "Cannot cancel: payments have been received against this invoice. Reverse the payments first."
- "Cannot edit: godown has already been prepared for this invoice."
- "Cannot edit: a delivery challan has already been issued for this invoice."

**Why better:** Prevents users from getting into inconsistent states (e.g., cancelling an invoice after stock has been moved). Legacy had similar guards but with less explicit messaging.

### 7.16 Commission Tracking (Task 37)
Full commission engine:
- 4 rule types: `flat`, `tiered`, `product_group`, `target_bonus`
- Auto-calculated on payment allocation
- Negative entries on sales return confirmation
- Month-end batch confirmation (GL: Dr Commission Expense / Cr Employee Payable)

**Why better:** Automated commission accounting. Legacy had no commission module.

### 7.17 Listen/Notify (PostgreSQL NOTIFY)
Migration `2025_01_21_000001_add_listen_notify_triggers.php` + `ListenNotifyService` + `ListenNotifyWorker` Artisan command. Real-time notifications via PostgreSQL LISTEN/NOTIFY (no external WebSocket server needed).

**Why better:** Real-time UI updates without external infrastructure. Legacy relied on polling + FCM push.

### 7.18 Dual-Write Audit Logger
`UserAuditLogger::log()` writes to BOTH:
- `user_audit_log` table (PostgreSQL, jsonb details)
- `logs/user_audit.log` file (JSON lines, defense in depth)

If the DB write fails (e.g., transaction rollback), the file log still captures the event.

**Why better:** Defense in depth — audit events survive DB failures. Legacy wrote only to DB.

### 7.19 Comprehensive Sales Audit Logger
`SalesAuditLogger` provides 16+ typed methods:
`saleCreated`, `saleUpdated`, `saleCancelled`, `saleCallADay`, `creditLimitOverride`, `paymentReceived`, `paymentReversed`, `paymentDiscount`, `paymentWriteOff`, `paymentRefund`, `returnCreated`, `returnConfirmed`, `returnReversed`, `godownPrepared`, `challanIssued`, `challanReversed`, `staleDraftsCancelled`.

**Why better:** Typed, structured audit events (vs Legacy's free-text `action` strings). Easier to query and report.

### 7.20 Pipeline Cache
`StockAvailabilityService` caches pipeline qty in Redis (5-min TTL):
- Branch-level: `pipeline:branch:{branchId}:{productId}`
- Warehouse-level: `pipeline:wh:{warehouseId}:{productId}`
- Cache invalidated by `invalidatePipelineForInvoice($invoiceId)` after finalize/edit/cancel/challan-operations

**Why better:** Reduces DB load on hot path (cart validation, availability display). Legacy recomputed on every query.

### 7.21 Credit Limit Re-check on Edit Uses Net Increase
On `updateExistingInvoice`, credit limit re-check uses `NET increase = max(0, newTotal - oldTotal)` instead of full new total.

**Why better:** Allows editing an invoice (e.g., adding items) without re-triggering credit limit if the total decreased or stayed the same. Legacy re-checked against full new total — overly conservative.

---

## 8. Risks

### 8.1 Risks in Legacy System

| # | Risk | Description | Severity |
|---|---|---|---|
| L1 | **Race condition in `customer_ledger.running_balance`** | `insertCustomerLedgerEntry` reads previous `running_balance` via `SELECT … ORDER BY id DESC LIMIT 1` **without FOR UPDATE**, then inserts a new row with `prev ± amount`. Two concurrent transactions for the same customer can both read the same `prev` and produce two rows with the same `running_balance` — leading to a wrong final balance. The reconciliation tooling `Helper::getCustomerLedgerBalanceMismatches` exists to detect this drift after the fact. | **High** |
| L2 | **SQL-dialect mismatch in cart persistence** | `SalesCartOperationsTrait::persistDraftCartToDb` uses `INSERT … ON CONFLICT (user_id, customer_id) DO UPDATE SET …` which is **PostgreSQL syntax**. The migration `020_sales_draft_carts.sql` declares the table with `ENGINE=InnoDB` (MySQL). On MySQL 8 this query will fail. The `usesDbDraftCarts` flag and `dbDraftCartTableExists` guard mean the feature is opt-in; if enabled on MySQL without translation, **cart persistence to DB silently fails**. | **High** (when DB persistence is enabled) |
| L3 | **Lost carts on browser crash** | If `SALES_DB_DRAFT_CARTS` is off (default), the cart lives only in `$_SESSION` + `localStorage`. A browser crash + session expiry loses the server-side cart; the localStorage backup prompts restore on next visit but only for the most recent customer and only within 24 h. | **Medium** |
| L4 | **Multi-tab cart overwrite** | Same user, two tabs, same customer → both upsert the same `(user_id, customer_id)` row in `sales_draft_carts` and the same `$_SESSION` slot. Last write wins; the other tab's changes are silently lost. No ETag/optimistic lock. | **Medium** |
| L5 | **Stale-draft cleanup loops `deleteInvoice` per row** | `cancelStaleDraftInvoices` calls `deleteInvoice($id, $reason)` for each of up to 200 stale drafts. Each call opens its own transaction, posts a reversing journal, inserts a ledger reversal row, etc. On a backlog this could take minutes. The 6-hour throttle limits frequency but not per-run cost. | **Medium** |
| L6 | **`PaymentController` is dead code** | `PaymentController::receive` and `::store` reference `views/sales/receive_payment` (which doesn't exist). The standalone `PaymentController` is a leftover shell; calling `payment/store` would crash with a missing-view error. | **Low** (cleanup issue) |
| L7 | **Missing reversal link when SalesReturn exists** | `deleteInvoice` blocks if a `sales_challans` row exists but does **not** check for `sales_returns`. A sales return linked to an invoice reverses AR via its own journal, but if someone then deletes the invoice (which shouldn't be possible because the invoice would no longer be `draft` once a return is filed — but the check is not explicit), the reversal chain could break. | **Low** |
| L8 | **`allocateDocumentSequence` first-of-period INSERT race** | Both paths rely on the row being locked; if the row does not exist yet, the code does an INSERT and returns 1 — but two concurrent INSERTs would race on the UNIQUE constraint. The DB will reject one with a duplicate-key error, which the caller does not catch. | **Low** (first invoice of the period only) |
| L9 | **Pre-finalize stock check outside the lock** | The pre-transaction stock check reads availability outside the lock. A second cashier could pass the same check between this read and the `lockBranchProductsForUpdate` call. **However**, the in-transaction re-check via `assertBranchProductsAvailable` catches the race and rolls back. Net: safe, but a cashier may see "valid" then hit a "stock no longer available" error at finalize. | **Low** (mitigated) |
| L10 | **Server trusts `$_POST` for required fields** | Salesman / sales_person / invoice_date required is enforced client-side only — server trusts `$_POST`. An attacker with session access could POST without these fields. | **Low** (auth-gated) |
| L11 | **No idempotency on finalize** | Network hiccup during finalize could create two invoices. No idempotency token mechanism. | **Medium** |
| L12 | **No idempotency on payment save** | Double-click on "Save Payment" could create duplicate payments. | **Medium** |
| L13 | **Cart mutations not audit logged** | No trail of who added/updated/removed what from carts. | **Low** |

### 8.2 Risks in Laravel System

| # | Risk | Description | Severity |
|---|---|---|---|
| V1 | **Cart page dropdown capped at 500 records** | `Customer::active()->limit(500)` and `Product::active()->limit(500)`. If a branch has more than 500 active customers/products, the dropdown won't show them all. The customer model has a `scopeSearch` with full-text search (GIN + tsvector), but the cart page doesn't use it. | **High** (production-blocking for large catalogs) |
| V2 | **No idempotency token on payment create** | `POST /admin/customer-payments` and `POST /api/v1/sales/payments` have no idempotency token. Double-submit can create duplicate payments. The auto-confirm path exacerbates this (no draft state to deduplicate). **Fixed by R2** — both endpoints now require a UUID v4 `idempotency_token`; replays within 10 min (web) / 5 min (API) return the cached result instead of creating a second payment. | **High** → ✅ Fixed |
| V3 | **No idempotency token on challan issue** | `POST /admin/sales-challans/issue/{invoiceId}` has no idempotency token. Mitigated by `if ($invoice->is_challan_issued) throw "Challan already issued"` — but this is checked AFTER the invoice is locked FOR UPDATE, so concurrent calls would serialize (one succeeds, one fails with the error). **Fixed by R3** — both `POST /admin/sales-challans/issue/{invoiceId}` (web, 10-min cache) and `POST /api/v1/sales/challans/issue` (API, 5-min cache) now require a UUID v4 `idempotency_token`. Replays now return the original challan instead of throwing the error, which is friendlier UX than a stack trace. | **Medium** → ✅ Fixed |
| V4 | **Cart mutations not audit logged** | Cart add/update/remove/clear are NOT audit logged. If a salesman tampers with another salesman's cart (unlikely but possible if user_ids were shared), there's no trail. **Fixed by R4** — `SalesAuditLogger` now exposes `cartItemAdded`, `cartItemUpdated`, `cartItemRemoved`, `cartCleared`. `SalesCartService` writes one audit row per successful mutation, capturing user_id, customer_id, branch_id, product_id, qty/rate (and before/after values for updates), and resulting cart subtotal. Both web (`SalesCartController`) and API (`SalesCartApiController`) paths flow through the same service, so both are covered. | **Medium** → ✅ Fixed |
| V5 | **Credit-limit check race** | `checkCreditLimit` is called BEFORE the transaction lock. Two concurrent finalizes for the same customer could both pass the check, then both post debits. The `customer_ledger` SUM would then exceed the limit. Mitigation: the lock is on products, not on the customer row — so this race is theoretically possible. | **Medium** |
| V6 | **Auto-confirm payments + network timeout** | If the GL posting fails mid-transaction, the entire request rolls back. But if the network times out before the client sees the response, the client may retry, creating a duplicate payment. **Mitigated by R2** — the idempotency token now makes the retry safe (returns the cached payment instead of duplicating). | **Medium** → ✅ Mitigated |
| V7 | **Pipeline cache staleness** | 5-min TTL on `pipeline:branch:*` and `pipeline:wh:*` cache keys. If a finalize/edit/cancel/challan operation fails to call `invalidatePipelineForInvoice` (e.g., due to an exception path), the cache could be stale for up to 5 minutes, allowing overselling. | **Medium** |
| V8 | **Stale draft auto-cancel at 02:00 vs late-night finalize** | Runs in the background (`->runInBackground()`). If a draft is being finalized exactly at 02:00 by a salesman working late, the cron could cancel it mid-finalize. Mitigated by `withoutOverlapping()` and the `lockForUpdate` inside finalize. | **Low** (mitigated) |
| V9 | **Walk-in customer not supported** | All customers must be active records. Quick POS transactions to anonymous customers require a "Walk-in Customer" record to be set up first. | **Medium** (UX gap) |
| V10 | **Orphaned `sales-create.js`** | The Laravel `public/assets/js/sales-create.js` is **byte-identical** to the Legacy version (confirmed via md5sum: `d1a786c07fd6e851fb67bdec3094002a`). It expects a `#sales-create-app` element, but **no Blade view references it**. Confusion risk — a developer might think it's wired up. | **Low** (cleanup issue) |
| V11 | **Cart branch_id not in unique key** | The unique key on `sales_draft_carts` is `(user_id, customer_id)` — branch_id is stored but not part of the key. A salesman switching branches with the same customer would share the same cart. Could lead to cross-branch stock reservations. | **Medium** |
| V12 | **Concurrent finalize of overlapping products by different salesmen** | Mitigated by `StockService::lockBranchProductsForUpdate` inside the transaction. After locking, availability is re-checked. **However**, this is a pessimistic lock on `warehouse_stock` — it doesn't prevent two salesmen from simultaneously reserving the same stock in their carts (which would surface only at finalize time). | **Low** (mitigated) |
| V13 | **Cart mutations have no idempotency** | A double-click on "Add to Cart" would fire two POSTs, but the second would merge qty into the first (since same product + same rate merges). For "Update", the second is idempotent (sets qty to the same value). For "Remove", the second would no-op (product not in cart). | **Low** (idempotent by accident) |
| V14 | **No split payment UI** | The `customer_payments` table supports only one `payment_mode` per row. Split payment (cash + bank + cheque in one submission) requires multiple payment records — no UI guidance. | **Medium** (UX gap) |
| V15 | **No live credit-limit display on cart** | Credit limit is checked only at finalize. A salesman could fill a large cart only to be blocked at finalize. | **Medium** (UX gap) |
| V16 | **`SalesCartService::enrichItems` N+1** | Calls `getProductPriceRange` and `getBranchAvailableQty` per item — N queries for N items (no batching). For carts with many items this could be slow. | **Low** (acceptable for typical cart sizes) |
| V17 | **`StockAvailabilityService::getBranchWarehouseBreakdown` N+1** | Calls `getWarehousePipelineQty` per warehouse in a loop — N queries for N warehouses per product. | **Low** (acceptable for typical warehouse counts) |
| V18 | **`SalesCartController@index` eager-loads 500 customers + 500 products** | No pagination — fine for small catalogs, problematic for >500 products/customers. | **Medium** (same root cause as V1) |
| V19 | **Customer dropdown not branch-scoped** | Customer model doesn't `use BranchScope`. Intentional (customers can be served from any branch), but means a salesman can see all customers across branches in the dropdown. Could be a privacy concern in multi-tenant deployments. | **Low** (intentional design choice) |
| V20 | **Reversal bypasses period close check** | `skip_period_check: true` on reversals — sensible for corrections, but could be abused to post reversals to closed periods. Configurable admin override exists. | **Low** (intentional design choice) |

### 8.3 Risks Common to Both Systems

| # | Risk | Description | Severity |
|---|---|---|---|
| C1 | **Credit-limit check before customer row lock** | Both systems check credit limit before locking the customer row. A small race window exists where two concurrent finalizes for the same customer could both pass the check. | **Medium** |
| C2 | **No cart mutation audit log** | Neither system logs cart add/update/remove/clear events. Tampering with carts leaves no trail. **Mitigated on Laravel by R4** — Laravel now writes `cart_item_added`/`updated`/`removed`/`cleared` events to `user_audit_log`. Legacy still has no equivalent. | **Medium** → ✅ Mitigated (Laravel only) |
| C3 | **No split payment UI** | Neither system supports cash + bank + cheque in one submission. | **Medium** (UX gap) |
| C4 | **No walk-in customer** | Neither system supports anonymous POS sales. | **Medium** (UX gap) |
| C5 | **No VAT / sales tax** | Neither system posts VAT/sales-tax to a separate ledger. | **Medium** (regulatory gap, depending on jurisdiction) |
| C6 | **No auto-print on finalize** | Neither system auto-prints the invoice after finalize. | **Low** (UX gap) |
| C7 | **Cart branch_id not in unique key** | Both systems store branch_id on the cart row but don't include it in the unique key. A salesman switching branches with the same customer would share the same cart. | **Medium** |

---

## 9. Recommendations

> **Note:** This is an audit only — no implementation yet. The recommendations below outline what should be done next, in priority order.

### 9.1 Priority 1 — Critical Fixes (Laravel)

| # | Recommendation | Rationale |
|---|---|---|
| R1 | **Replace select2 500-row dropdowns with live search endpoints** (port `sales/search_customer` and `sales/search_product` from Legacy) | V1 — production-blocking for large catalogs. The Customer model already has a `scopeSearch` with GIN full-text search — just needs to be wired to a controller endpoint and the blade updated to use AJAX typeahead. |
| R2 | ~~**Add idempotency token to payment create** (mirror the finalize pattern — UUID v4 + 5–10 min cache)~~ **✅ DONE (2026-07-21)** — both `POST /admin/customer-payments` (web, 10-min cache) and `POST /api/v1/sales/payments` (API, 5-min cache) now require a UUID v4 `idempotency_token`. Web replays redirect to the cached payment show page with a warning flash; API replays return the cached JSON with `idempotent_replay: true`. Fixes V2, mitigates V6. |
| R3 | ~~**Add idempotency token to challan issue**~~ **✅ DONE (2026-07-21)** — both `POST /admin/sales-challans/issue/{invoiceId}` (web, 10-min cache) and `POST /api/v1/sales/challans/issue` (API, 5-min cache) now require a UUID v4 `idempotency_token`. Web replays redirect to the cached challan show page with a warning flash; API replays return the cached JSON with `idempotent_replay: true`. Fixes V3. Pattern matches finalize (R-finalize) and payment (R2). |
| R4 | ~~**Add cart mutation audit logging** (extend `SalesAuditLogger` with `cartItemAdded`, `cartItemUpdated`, `cartItemRemoved`, `cartCleared` methods)~~ **✅ DONE (2026-07-21)** — 4 new methods added to `SalesAuditLogger`. `SalesCartService` constructor now takes `SalesAuditLogger` via DI and fires one event per successful cart mutation (add / update / remove / clear). Each event captures `user_id`, `customer_id`, `branch_id`, `product_id`, qty/rate (before+after for updates, removed-line qty/rate for removes, count+value for clears), and resulting `cart_subtotal`. Audit rows are written via `UserAuditLogger::log()` (dual-write: `user_audit_log` table + `logs/user_audit.log` file). Both Blade (`SalesCartController`) and API (`SalesCartApiController`) paths flow through the same service, so both are covered with no controller changes. Fixes V4, mitigates C2 (Laravel side). |
| R5 | **Lock the customer row before credit-limit check** (add `Customer::lockForUpdate()->find($customerId)` inside the finalize transaction, before `checkCreditLimit`) | V5, C1 — eliminates the credit-limit race window. |
| R6 | **Add `branch_id` to `sales_draft_carts` unique key** (migration to drop `uq_sales_draft_user_customer` and create `uq_sales_draft_user_customer_branch`) | V11, C7 — prevents cross-branch cart contamination. |

### 9.2 Priority 1 — Critical Fixes (Legacy)

| # | Recommendation | Rationale |
|---|---|---|
| R7 | **Fix `customer_ledger.running_balance` race** — either (a) add `SELECT … FOR UPDATE` on the prior row before insert, or (b) add a DB trigger that maintains `running_balance` atomically, or (c) deprecate `running_balance` in favor of `SUM(debit) - SUM(credit)` computed on demand (Laravel approach) | L1 — silent data corruption. |
| R8 | **Fix SQL-dialect mismatch in `persistDraftCartToDb`** — either (a) translate to MySQL `INSERT … ON DUPLICATE KEY UPDATE`, or (b) document that DB cart persistence requires PostgreSQL | L2 — silent failure when feature is enabled. |
| R9 | **Add idempotency token to `final_sales` and `save_payment`** | L11, L12 — prevents duplicate invoices/payments. |

### 9.3 Priority 2 — Feature Parity (port Legacy → Laravel)

| # | Recommendation | Rationale |
|---|---|---|
| R10 | **Port barcode scanning** (`product_by_code` endpoint + Enter-key handler in cart blade) | Missing feature #1 — high-value for retail POS. |
| R11 | **Port multi-customer cart tabs** (`#draft-tabs` dock with per-tab item-count badges) | Missing feature #2 — high-volume POS workflows. |
| R12 | **Port live customer/product typeahead** (replace select2 dropdowns with debounced AJAX typeahead) | Missing features #3, #4 — same root cause as R1. |
| R13 | **Port price-range slider band UI** | Missing feature #5 — improves cashier UX. |
| R14 | **Port live credit-limit display on cart page** | Missing feature #6 — prevents wasted cart-building. |
| R15 | **Port customer recents chips** (localStorage) | Missing feature #7 — speeds up repeat-customer workflows. |
| R16 | **Port sticky bottom bar** (item count + grand total + Finalize always visible) | Missing feature #9 — improves long-cart UX. |
| R17 | **Port mobile-cart cards with swipe-to-delete** | Missing feature #10 — improves mobile UX. |
| R18 | **Port keyboard shortcuts** (Enter to select, ArrowUp/ArrowDown) | Missing feature #11 — speeds up cashier input. |
| R19 | **Port inline receive-payment modal** on Today's Sales | Missing feature #12 — reduces context switching. |
| R20 | **Port quick-amount chips** (50% / Full due / Clear) | Missing feature #13 — speeds up payment entry. |
| R21 | **Port server-side DataTables** with smart sort + smart search | Missing feature #14 — better list UX. |
| R22 | **Port status chips with live counts** | Missing feature #15 — better list UX. |
| R23 | **Port mobile cards variant** for Today's Sales | Missing feature #16 — better mobile UX. |
| R24 | **Port Telegram notifications** on invoice created / payment received | Missing feature #17 — operational visibility. |
| R25 | **Port FCM push notifications** (or replace with SSE via Listen/Notify) | Missing feature #18 — real-time alerts. |
| R26 | **Add `min:10` to `override_reason`** in `FinalizeInvoiceRequest` | Missing feature #20 — parity with Legacy. |
| R27 | **Add `min:5` to reversal reason** in payment cancel | Missing feature #21 — parity with Legacy. |
| R28 | **Add PWA installability meta tags** to cart blade | Missing feature #33 — supports POS kiosk deployment. |

### 9.4 Priority 3 — Architectural Improvements (Laravel)

| # | Recommendation | Rationale |
|---|---|---|
| R29 | **Delete orphaned `sales-create.js`, `sales.js`, `sales-edit.js`, `sales-receive-payment.js`** from `laravel/public/assets/js/` | V10 — cleanup, prevents confusion. |
| R30 | **Batch `SalesCartService::enrichItems`** — fetch price ranges and stock availability in bulk queries instead of per-item loops | V16 — performance. |
| R31 | **Batch `StockAvailabilityService::getBranchWarehouseBreakdown`** — fetch pipeline qty for all warehouses in one query | V17 — performance. |
| R32 | **Add walk-in customer support** (special customer record flagged `is_walk_in=true`, auto-created per branch) | C4 — UX gap. |
| R33 | **Add split payment UI** (multi-row payment form with running total) | C3 — UX gap. |
| R34 | **Add VAT / sales tax ledger posting** (configurable tax rate, separate tax ledger, tax-inclusive vs tax-exclusive option) | C5 — regulatory compliance. |
| R35 | **Add auto-print on finalize** (open print route in new tab automatically after successful finalize) | C6 — UX convenience. |
| R36 | **Add walk-in customer payment flow** (one-click sale with no customer record — useful for retail) | Related to R32. |
| R37 | **Add `SalesAudit::run_checks` health dashboard** (port from Legacy `SalesAuditModel::runHealthChecks`) | Missing feature #25 — operational visibility. |

### 9.5 Priority 4 — Architectural Improvements (Legacy)

| # | Recommendation | Rationale |
|---|---|---|
| R38 | **Add Form Request-style validation** (or at least centralize validation rules in a helper) | Legacy validation is scattered across controllers and service traits. |
| R39 | **Add API layer** (decouple business logic from session auth) | Legacy has no public API — every endpoint is coupled to session auth. |
| R40 | **Add PostgreSQL RLS** (if migrating to PostgreSQL) | Defense in depth at DB layer. |
| R41 | **Add idempotency tokens** (mirror Laravel pattern) | L11, L12 — prevents duplicate submissions. |
| R42 | **Add generated columns** for `due_amount`, `amount`, `cogs_amount`, `stock_value`, `total_value` | Eliminates stale computed column risk. |

### 9.6 Priority 5 — Documentation & Testing

| # | Recommendation | Rationale |
|---|---|---|
| R43 | **Add integration tests for the credit-limit race** (R5/R7) — two concurrent finalizes for the same customer | Verifies the fix. |
| R44 | **Add integration tests for the idempotency token** (R2/R3/R9) — duplicate submission with same token | Verifies the fix. |
| R45 | **Add integration tests for the EXCLUDE constraint on payment allocations** — duplicate (invoice_id, payment_id) row | Verifies the constraint works. |
| R46 | **Add integration tests for the pipeline cache invalidation** — finalize → cache miss → correct availability | Verifies V7 mitigation. |
| R47 | **Document the Legacy → Laravel migration path** for sales drafts (how `$_SESSION` carts map to `sales_draft_carts` rows) | Eases cutover. |
| R48 | **Document the orphaned `sales-create.js` situation** in `MIGRATION_PLAN.md` | V10 — prevents future confusion. |

### 9.7 Summary Recommendation

The Laravel Sales Cart is **architecturally superior** but **UX-inferior** to the Legacy Sales Entry. The recommended path forward is:

1. **Immediate (1–2 weeks):** Fix Priority 1 critical issues (R1–R9). These are production-blocking or data-corruption risks.
2. **Short-term (1–2 months):** Port Priority 2 POS UX features (R10–R28). These close the UX gap and make the Laravel cart usable for high-volume POS workflows.
3. **Medium-term (2–3 months):** Implement Priority 3 architectural improvements (R29–R37). These round out the feature set.
4. **Long-term (3–6 months):** Migrate Legacy users to Laravel once Priority 1 + 2 are complete. Priority 4 Legacy improvements are optional if migration is imminent.
5. **Ongoing:** Priority 5 documentation and testing throughout.

The biggest single risk is **V1 (dropdown capped at 500)** — this will break production for any branch with more than 500 customers or products. This should be fixed before any user is migrated to the Laravel cart.

The biggest single opportunity is **R10 (barcode scanning)** — this is the most-used Legacy feature and the most-missed in Laravel. Porting it would close the biggest UX gap with relatively little effort (the Legacy endpoint and JS handler can be reused almost verbatim).

---

*End of audit report.*
