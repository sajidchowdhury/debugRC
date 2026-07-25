# Today Invoice — Business Implementation Plan (Migration Roadmap)

> **Goal:** Close every verified business-logic gap between the Legacy `sales/today` module and the Laravel `admin/sales-invoices` (Today Invoice) module, achieving feature parity — or exceeding it — while preserving the Laravel project's modern architecture (Eloquent, FormRequests, Services, Policies, Tailwind v4).
>
> **Scope:** Only the Today Invoice menu. No other module.
> **Source analysis:** `today-invoice-business-analysis.md` (§7 Comparison Table, §8 Missing Features).
> **Rule:** Every task below references a verified gap (F-#) from the analysis. No guesses.

---

## How to read this plan

- Phases are ordered by **impact on the daily-collection workflow** (Critical → High → Medium → Low).
- Each phase is independently shippable but may depend on earlier phases (noted in `Dependencies`).
- Each phase ends with a `Completion checklist` — every box must be ticked before the phase is marked done.
- **No code is written in this document.** This is a roadmap. Implementation happens in code review-batched PRs per phase.

---

## Phase 1 — Call-It-A-Day Parity (Critical) ✅ Complete

> Closes gaps **F-2, F-33, F-42, F-43**. This is the single biggest behavioral gap: the "Call It A Day" feature exists in Laravel code but is invisible to users because (a) the list doesn't filter by it, (b) no button invokes it, and (c) the auto-prompt after full payment never fires.

### Goal
Make "Call It A Day" behave exactly like Legacy: invoices marked `call_a_day=true` disappear from the Today list, the user can trigger it per-row and in bulk, and the system auto-prompts after a fully-paid payment.

### Tasks
1. **F-2 — Filter the list by `call_a_day=false`.**
   - In `SalesInvoiceController::buildInvoiceFilterQuery()`, add `->where('call_a_day', false)` to the base query used by `index()`, `datatable()`, and `summary()`.
   - Verify the partial index `idx_si_call_a_day_active WHERE call_a_day = false` (migration `2025_01_19_000001`) is present so the filter is index-backed.
   - Update the misleading docstring in `SalesInvoiceService::callItADay()` and the migration comment to match reality (they currently claim the view already filters — it does not, until this task lands).
   - **Exclude the filter when the user explicitly asks to see called-it-a-day invoices** — add an optional `?include_called=1` query param (admin/manager only) for auditing. This is a Laravel improvement over Legacy (Legacy has no way to see called invoices once removed).

2. **F-42 — Per-row "Call It A Day" button on the index table.**
   - Add a 12th column action button (warning-colored, fa-check-circle) to the DataTables `columns` render in `index.blade.php`, shown only when `row.call_a_day === false`.
   - Wire the click handler to POST `{{ route('admin.sales-invoices.call-it-a-day') }}` with `invoice_ids: [row.id]` + CSRF token, then redraw the table + refresh summary on success.
   - Use SweetAlert2 confirmation ("Remove this invoice from your collection list?") matching the Legacy UX.

3. **F-43 — Bulk "Call It A Day" with checkbox column.**
   - Add a checkbox column (col 0) to the DataTables `columns`. Render a checkbox only when `row.call_a_day === false`.
   - Add a "Select all on this page" checkbox in the header.
   - Add a bulk-action bar above the table: "Call It A Day (N selected)" button + count.
   - Wire the bulk button to POST the same endpoint with `invoice_ids: [array of checked ids]`. Batch cap = 200 (service already enforces).

4. **F-33 — Auto call-it-a-day after full payment.**
   - This depends on the payment submit no longer doing a full-page redirect. **Two options:**
     - **Option A (preferred):** Switch the receive-modal form submit from native POST (redirect) to AJAX (`fetch`/`$.ajax`) POST to `admin.customer-payments.store` with the same payload. On success JSON response, fire a `salesToday:paymentRecorded` custom event with `{ is_fully_paid, invoice_id }`. The index page listens and, if `is_fully_paid`, shows a SweetAlert2 "Fully paid. Remove from collection list?" → silent POST to call-it-a-day for that invoice.
     - **Option B (fallback):** Keep the native POST but pass a `return_to=index` flag; the `CustomerPaymentController::store` redirect target becomes `admin.sales-invoices.index?scope=today&just_paid={invoiceId}` and the index page detects the query param and fires the prompt.
   - **Recommendation:** Option A — it also unblocks F-40 (print-receipt success prompt) in Phase 2.

### Dependencies
- None. Phase 1 is self-contained.
- Phase 2's F-40 (print-receipt prompt) benefits from the AJAX submit introduced in task 4.

### Expected result
- Invoices marked "called it a day" vanish from the Today list immediately.
- Users can call-it-a-day per-row or in bulk with one click + confirmation.
- After recording a payment that fully settles an invoice, the user is auto-prompted to remove it from the list.
- The daily-collection workflow matches Legacy.

### Completion checklist
- [x] `buildInvoiceFilterQuery()` applies `where('call_a_day', false)` to index/datatable/summary.
- [x] Partial index `idx_si_call_a_day_active` verified present.
- [x] Misleading docstring + migration comment corrected.
- [x] Optional `?include_called=1` audit param works for admin/manager.
- [x] Per-row "Call It A Day" button renders (hidden when already called).
- [x] SweetAlert2 confirmation fires before the POST.
- [x] Bulk checkbox column + select-all + bulk-action bar functional.
- [x] Bulk POST caps at 200 and reports `updated_count`.
- [x] Receive-modal submit switched to AJAX (Option A) OR return-URL flow (Option B).
- [x] Auto call-it-a-day prompt fires on `is_fully_paid=true`.
- [x] Table redraws + summary refreshes after every call-it-a-day action.
- [x] Audit log entry `sale_call_a_day` written for every action (verify in `user_audit_log`).

### Phase 1 Verification

**F-2 — Filter the list by `call_a_day=false`** (landed in this phase):

| Criterion | Evidence |
|---|---|
| `buildInvoiceFilterQuery()` applies `where('call_a_day', false)` to the base query | `app/Http/Controllers/Admin/SalesInvoiceController.php` — `buildInvoiceFilterQuery()` now applies the filter at the base (before any scope branches), so `datatable()` + `summary()` inherit it across ALL scopes/chips. |
| `index()` inline query also filtered | `index()` applies `->when(! $includeCalled, fn($q) => $q->where('call_a_day', false))` to its own inline query + the `$statsBase` used for chip counts. |
| `?include_called=1` audit param (admin/manager only) | New private helper `shouldIncludeCalledInvoices(Request)` checks `$request->boolean('include_called')` AND `$user->hasRole('admin', 'manager')`. Used by both `index()` and `buildInvoiceFilterQuery()`. |
| Partial index `idx_si_call_a_day_active WHERE call_a_day = false` verified present | `database/migrations/2025_01_19_000001_add_call_a_day_to_sales_invoices.php` line 47 — `CREATE INDEX IF NOT EXISTS idx_si_call_a_day_active ON sales_invoices (call_a_day) WHERE call_a_day = false`. |
| Misleading docstring corrected | `app/Services/Sales/SalesInvoiceService.php` `callItADay()` docstring rewritten — removed false "DataTable filters by COALESCE(call_a_day, false) = false" claim; now accurately describes the `buildInvoiceFilterQuery()` base filter + `?include_called=1` opt-out + the backing partial index. |
| Misleading migration comment corrected | `database/migrations/2025_01_19_000001_add_call_a_day_to_sales_invoices.php` header rewritten — removed false "omitted in the PG schema redesign" claim; now accurately explains the migration is a no-op for the column on fresh installs (04_sales.sql declares it) and exists for backfill + the partial index. |
| Redundant `call_a_day` filter removed from today-branch | `buildInvoiceFilterQuery()` no longer repeats `where('call_a_day', false)` inside the `scope === 'today'` branch (now at base). `summary()` `$countToday` no longer repeats it either — base clone inherits it, keeping the count consistent across audit modes. |

**F-42 — Per-row "Call It A Day" button** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| Per-row button renders, hidden when `call_a_day=true` | `resources/views/admin/sales-invoices/index.blade.php` — DataTables `columns[11]` actions column renders `.btn-call-it-a-day` inside the overflow dropdown only when `row.show_call_a_day` is true. The flag is computed server-side in `datatable()`: `show_call_a_day => $due <= 0.01 && !$isCancelled && !$isReversed && !$calledItADay`. |
| SweetAlert2 confirmation before POST | `confirmCallItADay(ids, title, text)` (index.blade.php L1170-1183) — `Swal.fire({ icon: 'question', showCancelButton: true, confirmButtonColor: '#ea580c' })` then `callItADay(ids)` on confirm. |
| Click handler wired | Delegated `$(document).on('click', '.btn-call-it-a-day', …)` (L1152-1159) — survives DataTables redraws. |

**F-43 — Bulk "Call It A Day" with checkbox column** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| Checkbox column (col 0) | DataTables `columns[0]` renders `<input type="checkbox" class="row-invoice-checkbox" value="{row.id}">` (index.blade.php L706-718). |
| Select-all header checkbox | `#selectAllInvoices` in the `<thead>` (L202-206) + `change` handler (L1129-1134). |
| Bulk-action bar | `#invoiceBulkBar` (L162-188) — sticky amber bar with `#bulkSelectedCount` + `#bulkCallItADay` + `#bulkClear` buttons, hidden by default, shown via `updateBulkBar()` when ≥1 row selected. |
| Bulk POST caps at 200 + reports `updated_count` | `SalesInvoiceService::callItADay()` L782 — `array_slice(array_map('intval', array_unique($invoiceIds)), 0, 200)`. Returns `['status', 'message', 'updated_count']`. JS `callItADay()` (L1186-1213) reads `data.updated_count` + toasts + redraws. |

**F-33 — Auto call-it-a-day after full payment (Option A — AJAX submit)** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| Receive-modal submit is AJAX (Option A) | `doSubmit()` (index.blade.php L1587-1661) — `$.ajax({ url: $form.attr('action'), method: 'POST', data: $form.serialize(), dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' } })`. No full-page redirect. |
| Controller returns JSON with `is_fully_paid` + `payment_id` + `print_receipt_url` | `CustomerPaymentController::store()` — when `$request->expectsJson() || $request->ajax()`, returns JSON with `payment_id`, `payment_code`, `invoice_id`, `is_fully_paid`, `balance_after`, `message`, `print_receipt_url`. `is_fully_paid` computed by re-fetching the invoice after `confirmPayment()` commits. |
| Auto call-it-a-day prompt on `is_fully_paid=true` | `doSubmit().done()` (L1633-1638) — after the success Swal closes, if `isFullyPaid && invoiceId > 0`, fires `confirmCallItADay([invoiceId], 'Call it a day?', 'This invoice is now fully paid. Remove it from your daily collection list?')`. |
| Table redraws + summary refreshes | `doSubmit().done()` L1611-1612 — `dt.ajax.reload(); scheduleSummary();` immediately on payment success (before the Swal), so due/paid columns update. Same pattern in `callItADay()` L1203-1204. |

**Audit log verification:**

| Action | Logger method | Action string | Call site |
|---|---|---|---|
| Per-row / bulk Call-It-A-Day | `SalesAuditLogger::callItADay(userId, branchId, invoiceIds, updatedCount)` | `sale_call_a_day` | `SalesInvoiceService::callItADay()` L800 (inside DB transaction, after the batch UPDATE) |
| Payment received (F-33 trigger context) | `SalesAuditLogger::paymentReceived(...)` | `payment_received` | `CustomerPaymentService::confirmPayment()` via `auditPaymentConfirmed()` |

Both write to `user_audit_log` (PG) + `logs/user_audit.log` (file) via `UserAuditLogger::log()`.

---

## Phase 2 — Filter UX Parity (High)

> Closes gaps **F-31, F-32, F-41, F-40**. These are efficiency features that salesmen rely on for high-volume daily collection.

### Goal
Restore the filter-navigation speed of the Legacy page: persistent filters, one-click date presets, a visible active-filter bar, and an in-modal print-receipt prompt after payment.

### Tasks
1. **F-31 — Filter persistence via `localStorage`.**
   - Define a `STORAGE_KEY = 'rcerp_sales_invoices_filters_v1'` (new version namespace to avoid clashing with Legacy's `sales_today_filters_v1`).
   - On every filter change (status chip, scope chip, from_date, to_date, customer_id, branch_id, search, smart_sort), debounce-save a JSON blob to `localStorage`.
   - On page load: if the URL has explicit filter params, URL wins (set `forceUrlParams=true`); otherwise hydrate from `localStorage`.
   - "Clear" button wipes `localStorage` + DOM + redraws with defaults (`scope=today`, smart_sort on).

2. **F-32 — Date preset buttons.**
   - Add a row of 5 pill buttons above the date inputs: **Today / Yesterday / Last 7 days / This month / Custom**.
   - "Today" → from=today, to=today. "Yesterday" → from=yesterday, to=yesterday. "Last 7 days" → from=today−6, to=today (default active). "This month" → from=first-of-month, to=today. "Custom" → clears preset active state, leaves date inputs editable.
   - Active preset = amber gradient pill (match the project's design system, not Legacy's indigo).
   - Clicking a preset sets the date inputs + clears `scope` (presets are date-based, not scope-based) + redraws.

3. **F-41 — Active filter bar.**
   - Add a `#activeFilterBar` row below the filter form. Render pill-shaped tags for each active filter: period label + date range, status/scope chip label, search term, "Unpaid first" (if smart sort on).
   - Each tag has an inline `×` button that clears just that filter and redraws.
   - An inline "Clear all" button on the right wipes everything.

4. **F-40 — Receive-modal print-receipt success prompt.**
   - Depends on Phase 1 task 4 (AJAX submit). On successful payment POST, the JSON response includes `payment_id` + `is_fully_paid`.
   - Show a SweetAlert2 success toast with a "Print receipt" button → opens `{{ route('admin.customer-payments.print-receipt', ['payment' => ':paymentId']) }}` in a new tab.
   - After the toast, fire the auto call-it-a-day prompt (Phase 1 task 4) if `is_fully_paid`.

### Dependencies
- Phase 1 task 4 (AJAX submit) for F-40.

### Expected result
- Filters survive page reload.
- One-click date navigation.
- Users always see what's currently filtered.
- Print-receipt is one click away after payment, no navigation.

### Completion checklist
- [ ] `localStorage` key `rcerp_sales_invoices_filters_v1` hydrates on load (URL params override).
- [ ] 5 date preset buttons functional; active state styled per design system.
- [ ] Active filter bar renders all active filters as removable tags.
- [ ] "Clear all" resets to defaults.
- [ ] Receive-modal submit is AJAX; success JSON includes `payment_id` + `is_fully_paid`.
- [ ] SweetAlert2 success toast with "Print receipt" button opens the receipt in a new tab.
- [ ] No full-page redirect on payment submit.

---

## Phase 3 — Receive-Modal Inline Reverse & Per-Row Actions (High)

> Closes gaps **F-39, F-42 (remaining actions: edit/cancel on index), F-17 UX**. Brings the index page's per-row action set to parity with Legacy (view / edit / cancel / receive / call-it-a-day) and lets accountants reverse a payment without leaving the modal.

### Goal
The index page becomes the single hub for daily collection: every action is reachable from the table row, and payment reversal happens inside the receive modal.

### Tasks
1. **F-39 — Receive-modal inline reverse-payment.**
   - In `_receive_modal_body.blade.php`, the "Payments on this invoice" list already shows each payment with a print-receipt link. Add a "Reverse" button per payment row, visible only when `auth()->user()->can('reverse-payment')` (accountant/manager/admin — use `@can` or a role check).
   - Clicking "Reverse" opens a SweetAlert2 with a `textarea` for `cancel_reason` (min 5 chars, validated in `preConfirm`).
   - On confirm, AJAX POST to `{{ route('admin.customer-payments.cancel', ':paymentId') }}` with `cancel_reason` + CSRF.
   - On success: reload the modal body (re-fetch `/receive-modal/{id}`) + fire `salesToday:paymentRecorded` event with `{ reversedPaymentId }` so the index table + summary refresh.

2. **F-42 — Per-row Edit + Cancel buttons on the index table.**
   - Add an "Edit" button (outline-primary, fa-edit) shown only when `row.status === 'draft'`. Links to `{{ route('admin.sales-invoices.edit', ':id') }}`.
   - Add a "Cancel" button (outline-danger, fa-ban) shown only when `row.status === 'draft'`. Clicking opens SweetAlert2 with `cancel_reason` textarea → AJAX POST to `{{ route('admin.sales-invoices.cancel', ':id') }}` → redraw table + summary.
   - Group all per-row actions (View / Edit / Cancel / Receive / Call-it-a-day) into a compact button group. On mobile, collapse into a "⋯" dropdown to save horizontal space.

3. **F-17 UX — Reversal reason min-length enforcement (client + server).**
   - Already enforced server-side (`cancel_reason: min:5`). Add client-side `preConfirm` validation in the SweetAlert2 to match Legacy's UX (don't let the user submit a <5-char reason).

### Dependencies
- Phase 1 task 4 (AJAX submit pattern) for the reverse-payment AJAX call structure.

### Expected result
- Accountants reverse a payment without leaving the index page.
- Draft invoices can be edited or cancelled directly from the index table.
- The index table's action column matches Legacy's action set.

### Completion checklist
- [ ] "Reverse" button per payment in the receive modal (role-gated to accountant/manager/admin).
- [ ] SweetAlert2 textarea with min:5 client validation.
- [ ] AJAX POST to `admin.customer-payments.cancel` succeeds; modal reloads; table + summary refresh.
- [ ] Per-row "Edit" button renders only for `status==='draft'`.
- [ ] Per-row "Cancel" button renders only for `status==='draft'`; SweetAlert2 reason prompt; AJAX POST.
- [ ] Per-row action group is responsive (collapses to dropdown on mobile).
- [ ] Audit log entries `payment_reversed` + `sale_cancelled` written.

---

## Phase 4 — Notifications, Rate Limits & Search Coverage (Medium)

> Closes gaps **F-18, F-12, F-6**. Improves observability and robustness.

### Goal
Real-time Telegram alerts on today-invoice payments, rate-limited AJAX endpoints, and a smart search that covers every field Legacy covered.

### Tasks
1. **F-18 — Telegram notification on today payment.**
   - Port `SalesTelegramNotifier::notifyTodayInvoicePayment` to a Laravel `Notification` class (`app/Notifications/TodayInvoicePaymentReceived.php`).
   - Trigger: inside `CustomerPaymentService::confirmPayment()`, after the DB transaction commits, dispatch the notification to users where `role IN (admin, accountant)` AND `telegram_user_id IS NOT NULL` AND `invoice.invoice_date === today`.
   - Use Laravel's `ShouldQueue` + a queue worker so notification failures don't block the payment response (mirrors Legacy's `SalesTelegramNotifier::safe()` wrapper).
   - Gate via `config('services.telegram.enabled', true)`.

2. **F-12 — Rate-limit datatable + summary routes.**
   - Add `->middleware('throttle:180,1')` to `admin.sales-invoices.datatable`.
   - Add `->middleware('throttle:120,1')` to `admin.sales-invoices.summary`.
   - These match Legacy's per-user limits. Laravel's `throttle` middleware uses the user ID (or IP for guests) as the key — appropriate for authenticated routes.

3. **F-6 — Expand smart search.**
   - In `buildInvoiceFilterQuery()`, extend the search `orWhere` closure to also match:
     - `employees.name` (salesman) via a JOIN or `whereHas('salesman', ...)`.
     - `users.username` (creator) via `whereHas('creator', ...)`.
     - `products.product_name` / `products.product_code` via `whereHas('items.product', ...)`.
   - Verify the ILIKE indexes (migration `2025_01_20_000005` added GIN full-text on products/customers — confirm the search uses them or add a composite index if needed).

### Dependencies
- Phase 1 task 4 (AJAX submit) for the notification trigger point (after commit).
- A configured Telegram bot token in `config/services.php` + `users.telegram_user_id` column (migration `2025_01_24_000003` may already exist — verify).

### Expected result
- Admin/accountant receive a Telegram message within seconds of a today-invoice payment.
- AJAX endpoints reject abusive clients with HTTP 429.
- Smart search finds invoices by salesman, creator, or product — matching Legacy.

### Completion checklist
- [ ] `TodayInvoicePaymentReceived` notification class created; queued.
- [ ] Notification fires only when `invoice.invoice_date === today`.
- [ ] Notification recipients: admin + accountant with `telegram_user_id`.
- [ ] `throttle:180,1` on datatable route; `throttle:120,1` on summary route.
- [ ] 429 response test confirms rate limiting works.
- [ ] Smart search returns matches for salesman name, creator username, product name/code.
- [ ] Query performance acceptable (EXPLAIN ANALYZE; add index if needed).

---

## Phase 5 — Stale-Draft Surface & Polish (Medium)

> Closes gaps **F-20, F-37, F-38**. Surfaces stale drafts and polishes DataTables behavior.

### Goal
Users see stale drafts that need attention; nested SweetAlert2 focus works; DataTables length menu is intentionally hidden or surfaced.

### Tasks
1. **F-20 — Stale-draft banner on the today page.**
   - In `SalesInvoiceController::index()`, compute `$staleCount = SalesInvoice::where('status','draft')->where('is_reversed',false)->where('invoice_date', '<', now()->subDays(config('sales.stale_draft_days', 14)))->count()`.
   - Pass `$staleCount` to the view.
   - If `$staleCount > 0`, render a dismissible amber warning banner above the stat cards: "⚠️ N draft invoice(s) older than 14 days. [Cancel stale drafts] [Dismiss]".
   - "Cancel stale drafts" links to `admin.sales.cancel-stale-drafts` (manager/admin) or shows a disabled tooltip for salesman/accountant.

2. **F-37 — Nested SweetAlert2 focus-trap fix.**
   - Port Legacy's `sales-receive-payment.js` L12-26 focus-trap fix: on `focusin` events inside `.swal2-container` when a Bootstrap modal is open, call `e.stopImmediatePropagation()`.
   - Add this as a one-time document-level listener in the index page's `@push('scripts')` block (or a shared `resources/js/swal-focus-trap.js` loaded globally).
   - Verify the reverse-payment SweetAlert2 (Phase 3 task 1) works correctly when the receive modal is still open.

3. **F-38 — DataTables length menu decision.**
   - Decide: hide the length menu (match Legacy's fixed 25) OR keep it visible (Laravel's current [10,25,50,100,250]).
   - **Recommendation:** Keep it visible but default to 25 — power users benefit from larger pages. If hiding, set `dom: 'rtip'` and `pageLength: 25` to match Legacy exactly.

### Dependencies
- None (independent of other phases).

### Expected result
- Stale drafts are visible and actionable from the today page.
- SweetAlert2 prompts inside the receive modal don't lose focus to Bootstrap.
- DataTables pagination length is intentionally decided (not accidental).

### Completion checklist
- [ ] `$staleCount` computed and passed to the view.
- [ ] Stale-draft banner renders when `$staleCount > 0`, dismissible.
- [ ] "Cancel stale drafts" link role-gated (manager/admin).
- [ ] Focus-trap fix applied; verified with a nested SweetAlert2 inside the open modal.
- [ ] DataTables length menu decision documented + implemented.

---

## Phase 6 — Dead Code Cleanup & Architectural Polish (Low)

> Closes housekeeping items. No user-facing feature changes.

### Goal
Remove confusion-causing dead code and centralize authorization.

### Tasks
1. **Delete dead JS files.**
   - `public/assets/js/sales-today-index.js` (550 lines) — not loaded by any Laravel view.
   - `public/assets/js/sales-receive-payment.js` (385 lines) — not loaded (only referenced by the dead file above).
   - **Before deleting:** grep the entire `resources/views/` and `public/` trees to confirm zero references. If any Blade view loads them via `<script src>`, remove that tag too.
   - **Alternative (if the team prefers the external files over inline `@push`):** refactor the inline `@push('scripts')` block into these external files and load them via `<script src="{{ asset('assets/js/sales-today-index.js') }}"></script>`. Pick one approach; do not keep both.

2. **Add `SalesInvoicePolicy` + `CustomerPaymentPolicy`.**
   - Centralize the role + branch rules currently spread across `role:` middleware + `branch.isolation` + `SalesAccess`.
   - Methods: `view`, `create`, `update`, `delete` (cancel), `callItADay`, `receivePayment`, `reversePayment`, `exportCsv`.
   - Register in `AuthServiceProvider` and use `$this->authorize()` in controllers + `@can` in Blade.
   - This does not change behavior — it makes the rules testable and discoverable.

3. **Resolve the salesman-visibility question (F-3) + CSV-export permission (F-30).**
   - Document the business decision in `docs/today-invoice-business-analysis.md` §9.4 (update the recommendation to "confirmed" or "changed").
   - If matching Legacy: add `->where('created_by', auth()->id())` for salesman role in `buildInvoiceFilterQuery()`; add `salesman` to the `export-csv` route middleware.
   - If keeping Laravel behavior: no code change; just close the doc item.

### Dependencies
- Phases 1-5 should land first (so the dead JS files aren't accidentally revived).

### Expected result
- Codebase has one source of truth for the today-invoice JS (either inline or external — not both).
- Authorization rules are in Policy classes, testable via unit tests.
- The salesman-visibility + CSV-export-permission questions are resolved and documented.

### Completion checklist
- [ ] Dead JS files deleted OR refactored (one approach chosen).
- [ ] Zero dangling `<script src>` references to deleted files.
- [ ] `SalesInvoicePolicy` + `CustomerPaymentPolicy` created and registered.
- [ ] Controllers use `$this->authorize()`; Blade uses `@can`.
- [ ] Behavior unchanged (verified by existing tests + manual smoke).
- [ ] F-3 + F-30 business decision documented in the analysis doc.

---

## Cross-Phase: Testing & Verification Strategy

> Applies to every phase. No phase is "done" until these pass.

1. **Unit tests** — add / update tests in `tests/Feature/Sales/` for every new service method or changed query. Minimum: one happy-path + one guard-failure test per task.
2. **Feature tests** — add HTTP-level tests for every new AJAX endpoint or changed response shape. Cover: status code, JSON structure, audit log entry, branch isolation.
3. **Browser smoke** — after each phase, manually verify:
   - Page loads without errors (check `storage/logs/laravel.log` + browser console).
   - DataTables renders + paginates.
   - Each new button fires its action + the table redraws.
   - Filters persist across reload (Phase 2).
   - Mobile card rendering still works (< 768px).
4. **Audit log verification** — after every write action, confirm a row in `user_audit_log` with the correct `action`, `user_id`, `branch_id`, and `details` JSONB.
5. **Branch isolation verification** — switch to a non-admin role + non-session branch via the topbar switcher; confirm 403/redirect on every write route.

---

## Phase Summary (one-page view)

| Phase | Closes gaps | Impact | Estimated effort | Status |
|---|---|---|---|---|
| 1 — Call-It-A-Day Parity | F-2, F-33, F-42, F-43 | Critical | Medium (controller + view + JS) | ✅ Complete |
| 2 — Filter UX Parity | F-31, F-32, F-41, F-40 | High | Medium (mostly JS + view) | ⬜ Pending |
| 3 — Inline Reverse & Per-Row Actions | F-39, F-42 (remaining), F-17 UX | High | Medium (view + AJAX) | ⬜ Pending |
| 4 — Notifications, Rate Limits, Search | F-18, F-12, F-6 | Medium | Medium (notification + middleware + query) | ⬜ Pending |
| 5 — Stale-Draft Surface & Polish | F-20, F-37, F-38 | Medium | Small (view + JS) | ⬜ Pending |
| 6 — Dead Code & Architectural Polish | housekeeping | Low | Small (cleanup + Policy classes) | ⬜ Pending |

**Total: 6 phases, ~16 tasks, closing 16 verified gaps + 4 housekeeping items.**

---

**End of business implementation plan.** Cross-reference: `today-invoice-business-analysis.md` (§7-9) for the gap list this plan closes; `today-invoice-uiux-implementation-plan.md` for the UI-layer roadmap (which overlaps with Phases 1-3 on the view/JS side).
