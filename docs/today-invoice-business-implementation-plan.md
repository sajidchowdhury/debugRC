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

## Phase 2 — Filter UX Parity (High) ✅ Complete

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
- [x] `localStorage` key `rcerp_sales_invoices_filters_v1` hydrates on load (URL params override).
- [x] 5 date preset buttons functional; active state styled per design system.
- [x] Active filter bar renders all active filters as removable tags.
- [x] "Clear all" resets to defaults.
- [x] Receive-modal submit is AJAX; success JSON includes `payment_id` + `is_fully_paid`.
- [x] SweetAlert2 success toast with "Print receipt" button opens the receipt in a new tab.
- [x] No full-page redirect on payment submit.

### Phase 2 Verification

**F-31 — Filter persistence via `localStorage`** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| `STORAGE_KEY = 'rcerp_sales_invoices_filters_v1'` | `index.blade.php` L414 — `var FILTERS_KEY = 'rcerp_sales_invoices_filters_v1';` (new version namespace, avoids clashing with Legacy's `sales_today_filters_v1`). |
| Debounce-save on every filter change | `saveFilters()` (L565-570) — 400ms `setTimeout` debounce, `localStorage.setItem(FILTERS_KEY, JSON.stringify(currentFilterParams()))` wrapped in try/catch. Called from: status-chip click, apply button, search input (debounced 320ms), date/customer/branch/smart-sort change, date-preset apply, clearSingleFilter. |
| URL params win on page load | `loadFilters()` (L575-607) — checks `url.searchParams.has('from_date'/'to_date'/'customer_id'/'branch_id'/'status'/'scope')`; if any present, returns early (server-rendered values win). Otherwise hydrates from `localStorage.getItem(FILTERS_KEY)`. |
| Hydrates BEFORE first DataTable AJAX | `loadFilters()` called at L663 (before `var dt = $table.DataTable({...})` at L665) so the initial draw already reflects the saved filter state. |
| "Clear" wipes localStorage + DOM + redraws | `clearAllFilters()` (L610-626) — wipes all filter inputs, `localStorage.removeItem(FILTERS_KEY)`, `dt.ajax.reload()`, `scheduleSummary()`, `renderActiveFilterBar()`, resets to defaults (`scope=''`, `status_chip='all'`, `smart_sort=on`). |

**F-32 — Date preset buttons** (component + JS already existed; **DOM mount landed in this phase**):

| Criterion | Evidence |
|---|---|
| 5 preset pills: Today / Yesterday / Last 7 days / This month / Custom | `<x-erp.date-presets id="datePresets" />` mounted at `index.blade.php` L113 inside the visible filter card. Component (`resources/views/components/erp/date-presets.blade.php`) renders 5 `<button type="button" data-preset="{key}" class="date-preset-btn ...">` pills with English + Bengali labels. |
| Presets resolve to concrete from/to dates client-side (no round-trip) | `applyDatePreset(key)` (L474-507) — `today` → from=to=today; `yesterday` → from=to=yesterday; `last_7_days` → from=today−6, to=today; `this_month` → from=first-of-month, to=today; `custom` → leaves date inputs untouched + focuses `#from_date`. |
| Active preset = amber gradient pill (design system, not Legacy's indigo) | `PRESET_ACTIVE = 'bg-gradient-to-r from-amber-500 to-orange-500 border-transparent text-white shadow-sm'` (L421). `setActivePreset(key)` (L450-462) toggles `aria-pressed` + swaps `PRESET_INACTIVE` ↔ `PRESET_ACTIVE` classes. |
| Clicking a preset sets date inputs + clears scope + redraws | `applyDatePreset()` L487-494 — for non-custom presets: `$scopeInput.val('')`, `$chipInput.val('all')`, resets chip active state, `setActivePreset(key)`, then `dt.ajax.reload()` + `scheduleSummary()` + `saveFilters()` + `renderActiveFilterBar()`. |
| Visible date inputs for custom range | `#from_date` + `#to_date` are now `<input type="date">` (L115, L119) in the visible filter card — moved out of the hidden form. Native browser date picker fires the existing `change` handler (L1057→shifted) which reloads the table + saves + renders the active bar + refreshes preset highlight. |
| Preset highlight re-evaluates on date/scope change | `refreshPresetHighlight()` (L467-470) — if `scope` is active, no preset highlighted (scope owns the date context); otherwise `detectPresetFromDates()` matches current from/to against preset definitions. Called on: status-chip click, date change, clearSingleFilter, initial load. |
| `detectPresetFromDates()` correctly identifies matching preset | L431-445 — compares `#from_date`/`#to_date` against today/yesterday/last-7-days/this-month date windows using local `ymd()` formatter (avoids `toISOString()` UTC off-by-one). Returns `''` (custom/no-match) when dates don't align to a preset. |

**F-32 DOM-mount gap fixed in this phase:**

The `<x-erp.date-presets>` Blade component + all JS handlers (`applyDatePreset`, `setActivePreset`, `refreshPresetHighlight`, `detectPresetFromDates`, `$presets.on('click', '.date-preset-btn', ...)`) existed from the UI/UX phase but the `#datePresets` div was an empty placeholder — the component was never mounted, and `#from_date`/`#to_date` were `type="hidden"` with no visible date picker UI. Users had no way to apply presets or set custom dates.

This phase:
1. Removed the empty `<div id="datePresets"></div>` + hidden `#from_date`/`#to_date` from the hidden form.
2. Mounted `<x-erp.date-presets id="datePresets" />` in the visible filter card (new Period row above the Status row, separated by `border-bottom`).
3. Added visible `<input type="date" id="from_date">` + `<input type="date" id="to_date">` with native browser date pickers.
4. IDs unchanged → all existing JS selectors (`$('#datePresets')`, `$('#from_date')`, `$('#to_date')`) bind correctly to the new visible elements.

**F-41 — Active filter bar** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| `#activeFilterBar` row below the filter form | `<x-erp.active-filter-bar id="activeFilterBar" />` mounted at `index.blade.php` L147 (below the filter card, above the invoices table card). Component renders a hidden amber-tinted bar that shows when ≥1 filter is active. |
| Pill-shaped tags for each active filter | `renderActiveFilterBar()` (L522-561) — builds tags for: scope (today/pending_godown/pending_challan), status_chip (draft/confirmed/cancelled/reversed/awaiting_payment), from_date, to_date, customer_id (resolves name from `<option>`), branch_id, search, smart_sort. `filterTagHTML(key, label)` (L510-518) renders each tag as an amber-bordered pill. |
| Inline `×` button clears just that filter | `clearSingleFilter(key)` (L629-659) — per-key wipe logic (scope/status_chip/from_date/to_date/customer_id/branch_id/search/smart_sort), then `refreshPresetHighlight()` + `dt.ajax.reload()` + `scheduleSummary()` + `saveFilters()` + `renderActiveFilterBar()`. Delegated `[data-clear-filter]` click handler. |
| "Clear all" button on the right | `#clearAllFilters` button inside `<x-erp.active-filter-bar>` component + `$('#clearAllFilters').on('click', ...)` handler → `clearAllFilters()`. |
| Bar hides when nothing is active | `renderActiveFilterBar()` L551-554 — `$activeBar.addClass('hidden')` when `tags.length === 0`. |

**F-40 — Receive-modal print-receipt success prompt** (already implemented in Phase 1 / UI/UX phase):

| Criterion | Evidence |
|---|---|
| AJAX submit returns JSON with `payment_id` + `is_fully_paid` | `CustomerPaymentController::store()` — when `$request->expectsJson() || $request->ajax()`, returns JSON with `payment_id`, `payment_code`, `invoice_id`, `is_fully_paid`, `balance_after`, `message`, `print_receipt_url`. |
| SweetAlert2 success with "Print receipt" button | `doSubmit().done()` (L1615-1639) — `Swal.fire({ icon: 'success', title: 'Payment recorded ✓', confirmButtonText: printUrl ? '<i class="fas fa-print me-1"></i>Print receipt' : 'OK', showCancelButton: !!printUrl, cancelButtonText: 'Close', confirmButtonColor: '#059669' })`. |
| Opens receipt in a new tab | `.then(function (r) { if (r.isConfirmed && printUrl) { window.open(printUrl, '_blank', 'noopener'); } })` (L1629-1632). |
| Auto call-it-a-day prompt fires AFTER the print-receipt Swal if `is_fully_paid` | L1633-1638 — `if (isFullyPaid && invoiceId > 0) { confirmCallItADay([invoiceId], 'Call it a day?', '...'); }` inside the `.then()` callback (fires after the Swal closes). |
| No full-page redirect on payment submit | `doSubmit()` uses `$.ajax({...})` with `headers: { 'X-Requested-With': 'XMLHttpRequest' }` — the controller detects AJAX and returns JSON instead of redirecting. The receive modal is hidden via `getReceiveModalBs().hide()` (L1602). |

---

## Phase 3 — Receive-Modal Inline Reverse & Per-Row Actions (High) ✅ Complete

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
- [x] "Reverse" button per payment in the receive modal (role-gated to accountant/manager/admin).
- [x] SweetAlert2 textarea with min:5 client validation.
- [x] AJAX POST to `admin.customer-payments.cancel` succeeds; modal reloads; table + summary refresh.
- [x] Per-row "Edit" button renders only for `status==='draft'`.
- [x] Per-row "Cancel" button renders only for `status==='draft'`; SweetAlert2 reason prompt; AJAX POST.
- [x] Per-row action group is responsive (collapses to dropdown on mobile).
- [x] Audit log entries `payment_reversed` + `sale_cancelled` written.

### Phase 3 Verification

**F-39 — Receive-modal inline reverse-payment** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| "Reverse" button per payment row, role-gated | `_receive_modal_body.blade.php` L221-223 renders `<x-erp.reverse-payment-button :payment-id="$p['payment_id']" :payment-code="$p['payment_code']" />` inside the per-payment list, wrapped in `@if($canReversePayments)` (L193-194) where `$canReversePayments` is true only for accountant/manager/admin/superadmin. The component (`resources/views/components/erp/reverse-payment-button.blade.php` L32-40) renders a plain `<button type="button" class="btn-reverse-payment ..." data-payment-id data-payment-code>` — no form, no inline JS; all behavior is delegated from `index.blade.php`. |
| SweetAlert2 textarea for `cancel_reason` with min:5 client validation | `index.blade.php` L1709-1727 — `Swal.fire({ title: 'Reverse payment?', input: 'textarea', inputPlaceholder: 'Reason for reversal (min 5 chars)', inputValidator: v => (!v \|\| String(v).trim().length < 5) ? 'Reason must be at least 5 characters.' : undefined })`. The `inputValidator` is SweetAlert2's canonical preConfirm-time validation hook. |
| AJAX POST to `admin.customer-payments.cancel` with `cancel_reason` + CSRF | `index.blade.php` L1731-1737 — `$.ajax({ url: `/admin/customer-payments/${pid}/cancel`, method: 'POST', data: { _token: ROUTES.csrf, cancel_reason: reason }, headers: { 'X-Requested-With': 'XMLHttpRequest' } })`. Route registered at `routes/web.php` L800-801 (POST, role+branch.isolation middleware). |
| On success: reload modal body + fire `salesToday:paymentRecorded` | `index.blade.php` L1750-1774 — re-fetches `/admin/sales-invoices/${invoiceId}/receive-modal` via `$.ajax` GET, replaces `$modalContent.html(html)`, re-runs `initReceiveModalBody()`, then `dt.ajax.reload()` + `scheduleSummary()`, then `$(document).trigger('salesToday:paymentRecorded', [{ reversedPaymentId: pid, paymentCode: payCode }])`. Matches the F-39 spec payload exactly. |
| 422 validation errors surfaced | L1776-1789 — joins Laravel's `responseJSON.errors` object into a single string for the error toast. |

**F-42 — Per-row Edit + Cancel buttons on the index table** (already implemented in the UI/UX phase):

| Criterion | Evidence |
|---|---|
| "Edit" button (outline-primary, fa-edit), draft-only | `index.blade.php` DataTables actions column L849-933 (col 11) renders `<a class="rc-action-edit ..." href="${row.edit_url}">` only when `row.show_edit === true`. `SalesInvoiceController::datatable()` L870-876 sets `show_edit = $isDraft && !$isReversed` and `edit_url = route('admin.sales-invoices.edit', $inv)`. Route (`routes/web.php` L714-715) is gated `['role:salesman,manager,admin','branch.isolation']`. |
| "Cancel" button (outline-danger, fa-ban), draft-only | Rendered in the overflow ⋯ dropdown as `<button class="btn-cancel-invoice text-danger" data-invoice-id data-invoice-code>`, only when `row.show_cancel === true` (also `$isDraft && !$isReversed`). `dropdown-divider` above it visually separates the destructive action. Route (`routes/web.php` L711-712) is gated identically. |
| Cancel opens SweetAlert2 reason prompt → AJAX POST → redraw + summary | `index.blade.php` L1188-1193 delegated `.btn-cancel-invoice` click → `cancelInvoice(id, code)` helper (L1242-1283) → `Swal.fire({ input: 'textarea', inputValidator: min:5 })` → `$.ajax POST /admin/sales-invoices/${id}/cancel { cancel_reason }` → on success: toast + `dt.ajax.reload()` + `scheduleSummary()`. |
| Compact button group, collapses to ⋯ dropdown on mobile | Desktop actions column (L849-933) keeps View + Receive inline, collapses Edit / Call-it-a-day / Print / Cancel into a Bootstrap ⋯ dropdown. Mobile cards variant (L1389-1437) renders View + Receive inline + the same ⋯ overflow dropdown — single source of truth for the action set, responsive collapse by breakpoint. |
| Keyboard shortcut `e` for Edit (Phase 5 a11y) | `triggerRowAction('.rc-action-edit', 'Edit invoice')` bound to `e` key (L1955) — works the same as the click handler. |

**F-17 UX — Reversal reason min-length enforcement (client + server)** (client-side already done in UI/UX phase; **server-side parity gap fixed in this phase**):

| Criterion | Evidence |
|---|---|
| Client-side `inputValidator` enforces min:5 on reverse-payment SweetAlert2 | `index.blade.php` L1717-1722 — `inputValidator: v => (!v \|\| String(v).trim().length < 5) ? 'Reason must be at least 5 characters.' : undefined`. |
| Client-side `inputValidator` enforces min:5 on cancel-invoice SweetAlert2 | `index.blade.php` L1249-1254 — identical min:5 `inputValidator`. |
| Server-side `min:5` on `customer-payments.cancel` | `CustomerPaymentController::cancel()` L301 — `'cancel_reason' => 'required|string|min:5|max:500'` (with explicit R27 parity comment referencing Legacy's `SalesPaymentOperationsTrait::reverseCustomerPayment()` runtime `strlen($reason) < 5` check). |
| Server-side `min:5` on `sales-invoices.cancel` | **Fixed in this phase.** `SalesInvoiceController::cancel()` L577 — `'cancel_reason' => 'required|string|min:5|max:500'` (was `required|string|max:500`). Previously only the client-side `inputValidator` enforced min:5; a direct POST (curl) could submit a 1-char reason and have it audit-logged + committed. Now matches the customer-payments side + Legacy. |

**Audit log entries** (already implemented in the UI/UX phase services):

| Action | Logger method | Call site | Written inside DB tx? |
|---|---|---|---|
| `payment_reversed` | `SalesAuditLogger::paymentReversed()` (L106-116) | `CustomerPaymentService::cancelPayment()` L291 | ✅ Yes — inside the `DB::transaction()` that reverses GL + ledger + allocations + intercompany (L220-299). |
| `sale_cancelled` | `SalesAuditLogger::saleCancelled()` (L74-84) | `SalesInvoiceService::cancelInvoice()` L388 | ✅ Yes — inside the `DB::transaction()` that reverses GL + ledger (L343-399). |
| Both actions appear in recent-events feed | `SalesAuditLogger::recentSalesEvents()` L405-410 — action list includes both `sale_cancelled` + `payment_reversed`. | — | — |

**F-17 server-side parity gap fixed in this phase:**

The audit (`audit-3`) found that all three sub-tasks (F-39, F-42, F-17 client-side) were already fully implemented by the prior UI/UX phase. The only real defect was a server-side validation asymmetry: `CustomerPaymentController::cancel` enforced `cancel_reason: min:5` (matching Legacy) but `SalesInvoiceController::cancel` enforced only `required|string|max:500`. The client-side SweetAlert2 `inputValidator` protected honest users, but a direct POST (curl, devtools, scripted client) could bypass the UI and submit a 1-char reason to the invoice-cancel endpoint — which would then be audit-logged + committed.

This phase adds the missing `min:5` to the invoice-cancel rule (1-word change) so both endpoints match Legacy + each other. No new files, routes, Blade components, audit-logger methods, or JS handlers were needed.

---

## Phase 4 — Notifications, Rate Limits & Search Coverage (Medium) — F-18a/b/c/d ✅ / F-12, F-6 pending

> Closes gaps **F-18, F-12, F-6**. Improves observability and robustness.

### Goal
A configurable notification-rule engine (admin picks, per predefined event, who gets notified — multi-select of recipient types), real-time in-app delivery WITHOUT continuous database pressure (SSE via PostgreSQL LISTEN/NOTIFY → Redis → EventSource), rate-limited AJAX endpoints, and a smart search that covers every field Legacy covered.

### F-18 — REDEFINED (was "Telegram port"; now "configurable notification-rule engine")

**Original spec (superseded):** Port `SalesTelegramNotifier::notifyTodayInvoicePayment` to a Telegram Laravel Notification. **The user redefined this** — no Telegram; instead build a configurable admin panel where the admin chooses, per predefined business event, who gets notified (multi-select of recipient types). All events are predefined (users cannot create events). Real-time delivery must NOT put continuous pressure on the database.

**Predefined events** (the user's list): After Sales Confirm · After Create Challan copy · After Login · After Logout · After Create Damage invoice · After Receive money · After Sales return · After Branch demand · After increasing customer limit.

**Example recipient types** (the user's list, multi-select per event): All users · Only admin · Warehouse Manager of all branches · Warehouse Manager of branch selected by sales manager · Sales manager · Accountant · etc.

#### F-18 sub-phase roadmap (split because the audit found the engine is ~50% built by a prior "Phase 10" but has critical gaps)

| Sub-phase | Scope | Status |
|---|---|---|
| **F-18a** | Security + bell + JS + Gate — make the existing engine work end-to-end | ✅ Complete |
| **F-18b** | Schema: multi-select recipient types per event (pivot table) + context-aware recipient resolution (`warehouse_manager_of_branch`, `salesman_of_invoice`, etc.) + expand EVENTS to the user's 9 predefined events + expand RECIPIENTS + clean up vestigial `broadcast` channel | ✅ Complete |
| **F-18c** | Wire explicit `dispatch()` calls into all 9 event trigger points (only 3/10 are dispatched in PHP today; the rest rely on PG triggers + a worker process that may not be running) | ✅ Complete (8/9 — `branch_demand_created` deferred: no Laravel code path exists yet) |
| **F-18d** | Admin UX polish: redesign `rules.blade.php` for multi-select per event, default-rules seeder, "Reset to defaults" button | ✅ Complete |

**Architecture decision (F-18a):** Real-time delivery uses the existing **SSE pipeline** (PostgreSQL `LISTEN/NOTIFY` → Redis Pub/Sub → browser `EventSource`), NOT Laravel broadcasting. The database is only hit when a notification is created (event-driven, not polled) — satisfying the "no continuous DB pressure" requirement. The vestigial `broadcast` channel in `ERPNotification` (which silently no-ops due to missing `config/broadcasting.php`) was cleaned up in F-18b.

### F-18a Verification

The audit (`audit-4`) found that a prior "Phase 10 / Task 31" already built substantial infrastructure: `notifications` + `notification_rules` tables, `ERPNotification` class (`ShouldQueue`, database + broadcast channels), `NotificationService::dispatch()` + `resolveRecipients()` (7 recipient types, all global), `NotificationController` with 8 routes (rules CRUD + inbox + AJAX), `ListenNotifyWorker` + `SseController` (SSE real-time pipeline), and `public/assets/js/notification.js` (SSE client with toast popups + badge). But it was **broken in 5 ways** — F-18a fixes all 5:

| Gap | Fix | Evidence |
|---|---|---|
| **Security: no `role:admin` on rule-CRUD routes** — any authenticated user (including a salesman) could create / toggle / delete notification rules | Split the `admin/notifications` route group: rule-CRUD (`rules`, `storeRule`, `toggleRule`, `destroyRule`) now wrapped in `Route::middleware('role:admin')`; inbox + AJAX endpoints (`inbox`, `markRead`, `markAllRead`, `unreadCount`, `recent`) remain open to all authenticated users (they operate on `auth()->user()->notifications()` only) | `routes/web.php` L867-893 |
| **`view-notification-rules` Gate consumed but never defined** — `<components/layouts/erp.blade.php>` L150 `@can('view-notification-rules')` returned false for ALL users → the bell was hidden from everyone | Defined the Gate in `AppServiceProvider::boot()` → returns `$user->isAdmin()` (true for admin + superadmin) | `app/Providers/AppServiceProvider.php` L54-64 |
| **No notification bell in main `layouts/admin.blade.php`** — the main layout navbar had only a home button + user dropdown; no way to see notifications | Added a Bootstrap bell button with unread-count badge (`#notifBadge`) + dropdown (recent list `#notifList` + "Mark all read" + "View all" → inbox + "Settings" → rules gated by `@can('view-notification-rules')`). Bell visible to ALL auth users (everyone receives notifications); only the Settings link is admin-gated | `resources/views/layouts/admin.blade.php` L79-122 |
| **`notification.js` was dead code** — not loaded by any Blade view + wrong `BASE_URL = '/remote-center-erp/'` (routes are root-relative) + wrong unread endpoint (`notifications/unread` — 404; real route is `admin/notifications/unread-count`) + wrong response parsing (`data.notifications.length` vs `data.count`) | Fixed `BASE_URL = '/'`; fixed `lightCheckNotifications()` to fetch `admin/notifications/unread-count` + parse `data.count`; added null guard on `notificationSound`; exposed `updateNotificationBadge` + `lightCheckNotifications` globally (`window.*`) for the layout's dropdown JS; loaded the script via `<script src="/assets/js/notification.js?v={{ filemtime(...) }}">` on every authenticated page (after the bell DOM, before the inline dropdown JS) | `public/assets/js/notification.js` L1-16, 215-221, 258-268, 303-309; `resources/views/layouts/admin.blade.php` L313-319 |
| **No toast container / audio element** — `showBeautifulNotification()` + `playNotificationSound()` referenced `#notificationContainer` + `#notificationSound` which didn't exist in the main layout | Added `<div id="notificationContainer">` (fixed-position toast host, `aria-live="polite"`) + `<audio id="notificationSound">` (hidden, no src — play() rejects silently) BEFORE the `notification.js` script tag so the elements exist when the script's top-level `const notificationSound = document.getElementById(...)` runs | `resources/views/layouts/admin.blade.php` L304-311 |

**Real-time delivery flow (now working end-to-end after F-18a):**
1. A business event fires → `NotificationService::dispatch('event', body, refType, refId, extra)` (existing, `app/Services/Notification/NotificationService.php` L61).
2. Service finds active `notification_rules` for the event → resolves recipients → `$user->notify(new ERPNotification(...))` → writes ONE row per recipient to the `notifications` table (Laravel standard, `ShouldQueue` → async, doesn't block the request).
3. Service also calls `$this->listenNotify->emitNotify('rcerp_notification_dispatched', [...])` → `pg_notify()` → `ListenNotifyWorker` (long-running artisan process) → Redis Pub/Sub → `SseController` → browser `EventSource`.
4. `notification.js` receives the `rcerp_notification_dispatched` SSE event → shows a toast popup (`showBeautifulNotification`) + refreshes the badge (`lightCheckNotifications` → one COUNT query to `admin/notifications/unread-count`).
5. **No polling when SSE is connected.** Fallback to 30s polling ONLY if SSE is unavailable (worker down / browser doesn't support EventSource) — the polling does a single COUNT query, not a list fetch.

**Deployment note:** The `ListenNotifyWorker` must run as a separate long-lived process (`php artisan listen-notify:worker` under supervisor/systemd) for SSE-based real-time push to work. If the worker is down, the system degrades gracefully to 30s polling (a single COUNT query per poll — light load). The `/sse/status` endpoint reports worker health.

### F-18b Verification

F-18b reworks the **schema + recipient-resolution layer** so the engine supports the user's redefined spec: admin picks, per predefined event, a **multi-select** of recipient types (including context-aware types like "Warehouse Manager of the branch selected by the sales manager"). The trigger-point wiring (F-18c) and admin-UX polish (F-18d) are separate sub-phases. Five concrete changes:

| Change | What was done | Evidence |
|---|---|---|
| **Multi-select recipient types per event** — the Phase-10 schema stored ONE `recipient_type` string per `notification_rules` row; the user's spec requires multi-select | New migration `2025_01_26_000001` creates pivot table `notification_rule_recipients(notification_rule_id FK cascade, recipient_type, recipient_user_id nullable, timestamps)` + backfills every existing rule's single recipient_type into the pivot + drops the redundant `recipient_type`/`recipient_user_id` columns from `notification_rules`. New model `NotificationRuleRecipient` + `NotificationRule::recipientTypes()` hasMany. `NotificationController::storeRule()` validates `recipient_types[]` array (min:1, each in RECIPIENTS) + syncs to pivot in a transaction. `rules.blade.php` form now renders a `<select multiple>` and the table renders one badge per selection | `database/migrations/2025_01_26_000001_notification_rules_multi_recipients.php`; `app/Models/NotificationRuleRecipient.php`; `app/Models/NotificationRule.php` (`recipientTypes()`); `app/Http/Controllers/Admin/NotificationController.php` (`storeRule`); `resources/views/admin/notifications/rules.blade.php` |
| **Context-aware recipient resolution** — the user wants "Warehouse Manager of branch selected by sales manager" / "Salesman of the invoice" which need event context, not global roles | `NotificationService::dispatch()` gains a 6th param `array $context = []` (recognized keys: `branch_id`, `salesman_id`, `created_by`, `customer_id`). `resolveRecipients($rule, $context)` iterates every pivot selection, resolves each to a user set, and **merges + de-duplicates by user ID** (a user matching two selections on the same rule gets ONE notification). New recipient types: `warehouse_manager_of_branch` (→ employees where role=warehouse_manager AND branch_id=context), `salesman_of_invoice` (→ the user linked to employee context.salesman_id), `invoice_creator` (→ user context.created_by). Existing dispatchers (SalesReturnService's 3 calls) keep working — the new param is optional/variadic-safe | `app/Services/Notification/NotificationService.php` (`dispatch` signature L79, `resolveRecipients` L181) |
| **EVENTS expanded to the user's 9 predefined events** — Phase-10 had 10 events but missing 4 of the user's list (user_logout, damage_invoice_created, branch_demand_created, customer_limit_increased) | `NotificationRule::EVENTS` now carries 14 keys: the user's 9 canonical business events (sales_finalize="After Sales Confirm", challan_create, user_login, user_logout, damage_invoice_created, payment_receive="After Receive Money", return_created="After Sales Return", branch_demand_created, customer_limit_increased) + 5 pre-existing infrastructure/sub-flow events (godown_create, soft_delete, accounts_entry, return_confirmed, return_reversed — already dispatched by SalesReturnService). `NotificationService::EVENT_META` gains icon/color/title for the 4 new events | `app/Models/NotificationRule.php` (`EVENTS`); `app/Services/Notification/NotificationService.php` (`EVENT_META`) |
| **RECIPIENTS expanded + un-fused** — Phase-10 had 7 recipient types (all global, `sales_manager` over-broadly fused with admin/superadmin) | `NotificationRule::RECIPIENTS` now carries 10 types: `all_users`, `admin` (="Only Admin"), `superadmin`, `sales_manager` (**un-fused** → manager+salesman only, was manager+salesman+admin+superadmin), `accountant`, `warehouse_manager` (="all branches"), `warehouse_manager_of_branch`★, `salesman_of_invoice`★, `invoice_creator`★, `specific_user`. ★ = context-aware (new `CONTEXT_AWARE_RECIPIENTS` constant; UI marks them with a ★ suffix) | `app/Models/NotificationRule.php` (`RECIPIENTS`, `CONTEXT_AWARE_RECIPIENTS`); `app/Services/Notification/NotificationService.php` (`resolveRecipients`) |
| **Vestigial `broadcast` channel cleaned up** — `ERPNotification::via()` returned `database`+`broadcast` based on a `channels` array, but no `config/broadcasting.php` exists (no Reverb/Pusher/Echo installed) → `toBroadcast()` silently no-op'd | `ERPNotification` now database-only: `via()` always returns `['database']`; `toBroadcast()` method deleted; `BroadcastMessage` import dropped. `NotificationRule::CHANNELS` collapsed to `['database' => 'Database (In-App)']`. Migration normalizes any existing `broadcast`/`both` rule rows → `database`. Real-time push continues via the SSE pipeline (unchanged). The create-rule form replaces the channel dropdown with a read-only "Database (In-App) + real-time toast" badge + hidden `channel=database` input | `app/Notifications/ERPNotification.php`; `app/Models/NotificationRule.php` (`CHANNELS`); `database/migrations/2025_01_26_000001` (step 3); `resources/views/admin/notifications/rules.blade.php` |

**Backward compatibility:** the 3 existing `SalesReturnService::dispatch()` call sites (return_created/confirmed/reversed at L140/235/324) are untouched — the new `$context` param is optional and defaults to `[]`, so context-aware recipient types simply resolve to an empty set when no context is passed (they only match when the dispatcher supplies `branch_id`/`salesman_id`/`created_by`). The 4 pre-seeded return rules (from migration `2025_01_09_000003`) are backfilled into the pivot automatically by the F-18b migration, so no configured rule is lost.

**What F-18b does NOT do (deferred):** wiring explicit `dispatch()` calls into the remaining 6 event trigger points (sale_confirm, challan_create, login, logout, damage_invoice, branch_demand, customer_limit) — that is F-18c. The admin-UX polish (Select2 multi-select, default-rules seeder, "Reset to defaults" button) is F-18d. The current F-18b form is functional (native `<select multiple>`) but not yet polished.

### F-18c Verification

F-18c wires explicit `NotificationService::dispatch()` calls (with `$context`) into the business-event trigger points so notifications fire **deterministically from PHP** — no longer dependent on the `ListenNotifyWorker` artisan process being running. The audit (`audit-5`) mapped all 9 trigger points; F-18c wires 8 of 9 (the 9th, `branch_demand_created`, has no Laravel code path yet).

| Event | Trigger point | `$context` passed | Evidence |
|---|---|---|---|
| **sales_finalize** ("After Sales Confirm") | `SalesInvoiceService::finalizeFromCart` — after `auditLogger->saleCreated()` + cache invalidation, before the `return` (inside `DB::transaction`) | `branch_id`, `salesman_id` (employee id from `$data['salesman_id']`), `customer_id`, `created_by` | `app/Services/Sales/SalesInvoiceService.php` L331-354 |
| **challan_create** ("After Create Challan Copy") | `SalesChallanService::issueChallan` — after `auditLogger->challanIssued()` + cache invalidation, before the `return` (inside `DB::transaction`). `salesman_id` derived via a lightweight `DB::table('sales_invoices')->where('id',$invoiceId)->value('salesman_id')` query (sales_challans has no salesman_id column) | `branch_id`, `salesman_id` (derived), `customer_id`, `created_by` | `app/Services/Sales/SalesChallanService.php` L325-352 |
| **payment_receive** ("After Receive Money") | `CustomerPaymentService::confirmPayment` — after `auditPaymentConfirmed()`, before the `return` (inside `DB::transaction`). Fires ONLY when `$transactionType === 'receive'` (discount / write_off / payment-refund are not "receive money") | `branch_id`, `customer_id`, `created_by` (the confirmer) | `app/Services/Sales/CustomerPaymentService.php` L203-229 |
| **damage_invoice_created** ("After Create Damage Invoice") | `DamageService::createDamage` — after items insert, before the `return` (inside `DB::transaction`). Skipped when `$data['suppress_notification']` is set (the sales-return linked-damage flow sets this to avoid double-firing on top of `return_confirmed`) | `branch_id`, `created_by` | `app/Services/Stock/DamageService.php` L124-150 |
| **user_login** ("After Login") | `AuthenticatedSessionController::store` — after `UserAuditLogger::log('login_success')`, before `regenerateToken()` | `created_by` (the user), `branch_id` (from `$user->employee?->branch_id`) | `app/Http/Controllers/Auth/AuthenticatedSessionController.php` L213-232 |
| **user_logout** ("After Logout") | `AuthenticatedSessionController::destroy` — after `UserAuditLogger::log('logout')`. `branch_id` + `username` captured BEFORE `Auth::logout()` + `session()->invalidate()` clear them | `created_by`, `branch_id` (captured pre-logout) | `app/Http/Controllers/Auth/AuthenticatedSessionController.php` L268-287 |
| **customer_limit_increased** ("After Increasing Customer Limit") | `CustomerController::update` — after `$item->update($validated)` succeeds. Captures `$oldCreditLimit` BEFORE the update; fires ONLY when `$newCreditLimit > $oldCreditLimit` (decreases + no-change do not fire — the user's event is "After **INCREASING** customer limit"). Resolved via `app(NotificationService::class)` to avoid touching the parent-controller constructor | `customer_id`, `branch_id`, `created_by` | `app/Http/Controllers/Admin/CustomerController.php` L539-543 (capture) + L578-606 (dispatch) |
| **return_created / return_confirmed / return_reversed** ("After Sales Return" + sub-flows) | 3 existing `SalesReturnService::dispatch()` calls **upgraded** to pass `$context` (were 4-positional-arg, now 6-arg with context). `salesman_id` derived via `DB::table('sales_invoices')->where('id',$return->sales_invoice_id)->value('salesman_id')` for confirm/reverse (sales_returns has no salesman_id column) | `branch_id`, `customer_id`, `salesman_id` (derived for confirm/reverse), `created_by` | `app/Services/Sales/SalesReturnService.php` L139-162 / L254-282 / L362-390 |
| **branch_demand_created** ("After Branch Demand") | **NOT WIRED** — the `branch_demands` table exists (created in `database/sql/03_stock.sql`) but there is NO Laravel `BranchDemand` model, controller, or service. The legacy PHP code path is the only creator. F-18c cannot wire a dispatch call where there is no PHP call site. | N/A | Deferred — requires a new `BranchDemandService` (out of F-18c scope) |

**Safety design (applied to every dispatch site):**
- Each new dispatch is wrapped in `try { ... } catch (\Throwable $e) { Log::warning(...); }` so a notification failure (e.g. queue down, DB write error) NEVER rolls back the business transaction or blocks the user flow. The 5 dispatches inside `DB::transaction` closures (finalizeFromCart, issueChallan, confirmPayment, createDamage, + 3 SalesReturnService calls) are especially critical — without the try/catch, a `$user->notify()` failure would roll back the entire invoice/challan/payment/return.
- `NotificationService::dispatch()` itself is non-blocking for the request: `ERPNotification` implements `ShouldQueue`, so `$user->notify()` pushes a queued job (writes the `notifications` row async via the queue worker). The SSE `pg_notify` emission is also wrapped in its own try/catch inside `dispatch()`.
- The `payment_receive` dispatch is gated on `$transactionType === 'receive'` — discount / write_off / payment-refund types do not fire the "receive money" event.
- The `damage_invoice_created` dispatch honors a `suppress_notification` flag so the sales-return linked-damage flow (`SalesReturnService::createLinkedDamageWriteOffs`) does not double-fire damage_invoice_created on top of return_confirmed.

**What F-18c does NOT do (deferred):**
- `branch_demand_created` — no Laravel code path exists. Requires a new `BranchDemandService` (the table + RLS policies exist; only the Eloquent layer is missing). This is a separate feature, not a notification wiring task.
- Admin-UX polish (Select2 multi-select, default-rules seeder, "Reset to defaults" button) — that is F-18d, now complete (see below).

### F-18d Verification

F-18d polishes the **admin configuration UX** so the multi-select recipient engine from F-18b is pleasant to use and ships with a sensible default rule set out of the box. Three concrete changes:

| Change | What was done | Evidence |
|---|---|---|
| **Select2 multi-select** | The native `<select multiple size=8>` for `recipient_types[]` on `rules.blade.php` is upgraded to a Select2 widget (searchable, `closeOnSelect:false` so the admin picks several without the dropdown collapsing, `theme:bootstrap-5` matching the existing `#recipientUser` dropdown). The show/hide-Specific-User logic + the empty-recipient submit guard were rewritten to read Select2's `.val()` (the native `selectedOptions`/`required` no longer fire once Select2 hides the `<select>`). | `resources/views/admin/notifications/rules.blade.php` — `#recipientTypes` Select2 init + `syncSpecificUser()` + `#createRuleForm` submit guard |
| **Default-rules seeder** | New `database/seeders/NotificationRuleSeeder.php` (+ minimal `DatabaseSeeder.php` so `php artisan db:seed` works project-wide for the first time — the project previously had no seeders dir at all). Seeds 11 default rules (the user's 9 predefined events + the 2 sales-return sub-flows already dispatched by `SalesReturnService`), each with a multi-select of recipient types written to the `notification_rule_recipients` pivot. Idempotent by `(event, name)` — safe to re-run. Default rules use the ` — default` name suffix so admins can tell them from custom rules. | `database/seeders/NotificationRuleSeeder.php` (DEFAULTS const, 11 rows) + `database/seeders/DatabaseSeeder.php` |
| **"Reset to defaults" button** | New `POST admin/notifications/rules/reset-defaults` route (inside the existing `role:admin` middleware group) + `NotificationController::resetDefaults()`. Hard-deletes every `notification_rules` row (bypasses `SoftDeletes` via the query builder; the pivot FK `cascadeOnDelete` clears `notification_rule_recipients` automatically), then calls `app(NotificationRuleSeeder::class)->run()` for a clean default set. SweetAlert2 confirms the destructive action. | `routes/web.php` (resetDefaults route) + `NotificationController::resetDefaults()` + `rules.blade.php` (`#btnResetDefaults` + `#resetDefaultsForm`) |

**Default rule set** (11 rules — seeded by `NotificationRuleSeeder::DEFAULTS`):

| Event | Default recipients |
|---|---|
| `sales_finalize` (After Sales Confirm) | Admin · Warehouse Manager of branch ★ · Salesman of invoice ★ |
| `challan_create` (After Create Challan Copy) | Admin · Warehouse Manager of branch ★ |
| `user_login` (After Login) | Admin |
| `user_logout` (After Logout) | Admin |
| `damage_invoice_created` (After Create Damage Invoice) | Admin · Warehouse Manager of branch ★ · Accountant |
| `payment_receive` (After Receive Money) | Admin · Accountant |
| `return_created` (After Sales Return) | Admin |
| `return_confirmed` (Sales Return Confirmed) | Admin · Warehouse Manager of branch ★ · Accountant |
| `return_reversed` (Sales Return Reversed) | Admin · Accountant |
| `branch_demand_created` (After Branch Demand) | Admin · Warehouse Manager of branch ★ |
| `customer_limit_increased` (After Increasing Customer Limit) | Admin · Accountant |

★ = context-aware recipient type (resolves from the `$context` array that F-18c wired into every dispatch call). Every ★ selection above will resolve at dispatch time because F-18c passes the matching `branch_id` / `salesman_id` / `created_by` / `customer_id` key.

**Reset flow:** Admin clicks "Reset to defaults" → SweetAlert2 warns that ALL existing rules (including custom ones) will be deleted → on confirm, `resetDefaults()` runs inside a `DB::transaction`: `DB::table('notification_rules')->delete()` (hard delete, cascade clears pivot) → `app(NotificationRuleSeeder::class)->run()` (table is empty so every default inserts) → redirect back with a success flash. The `branch_demand_created` default is seeded **active** even though no Laravel creation path exists yet (F-18c deferred it) — it will start firing automatically once a `BranchDemandService` is built in a future phase.

**What F-18d does NOT do (deferred):**
- `branch_demand_created` still has no Laravel trigger path (F-18c gap). The default rule is seeded ready-to-fire; the `BranchDemandService` is a separate feature.
- The `godown_create` / `soft_delete` / `accounts_entry` infrastructure events (not in the user's 9) get NO default rules — admins can add them manually if desired.
- The legacy migration-seeded return rules (`2025_01_09_000003`, old-style names like "Sales Return Created — Notify Admins") coexist with F-18d defaults on existing installs. "Reset to defaults" wipes them for a clean slate; on a fresh `db:seed` they'd coexist (both fire — harmless duplication the admin can prune).

### Tasks (remaining Phase 4 tasks — unchanged from original spec)

2. **F-12 — Rate-limit datatable + summary routes.** ⬜ Pending
   - Add `->middleware('throttle:180,1')` to `admin.sales-invoices.datatable`.
   - Add `->middleware('throttle:120,1')` to `admin.sales-invoices.summary`.
   - These match Legacy's per-user limits. Laravel's `throttle` middleware uses the user ID (or IP for guests) as the key — appropriate for authenticated routes.

3. **F-6 — Expand smart search.** ⬜ Pending
   - In `buildInvoiceFilterQuery()`, extend the search `orWhere` closure to also match:
     - `employees.name` (salesman) via a JOIN or `whereHas('salesman', ...)`.
     - `users.username` (creator) via `whereHas('creator', ...)`.
     - `products.product_name` / `products.product_code` via `whereHas('items.product', ...)`.
   - Verify the ILIKE indexes (migration `2025_01_20_000005` added GIN full-text on products/customers — confirm the search uses them or add a composite index if needed).

### Dependencies
- Phase 1 task 4 (AJAX submit) for the notification trigger point (after commit).
- The existing "Phase 10" notification infrastructure (`notifications` + `notification_rules` tables, `ERPNotification`, `NotificationService`, `NotificationController`, `ListenNotifyWorker`, `SseController`) — F-18a fixes the 5 gaps that made it non-functional; F-18b/c/d extend it.

### Expected result
- Admins configure, per predefined event, who gets notified (multi-select of recipient types including branch-context-aware types like "Warehouse Manager of branch").
- Every authenticated user sees a notification bell with unread badge + recent dropdown; real-time toast popups arrive via SSE without database polling.
- AJAX endpoints reject abusive clients with HTTP 429.
- Smart search finds invoices by salesman, creator, or product — matching Legacy.

### Completion checklist
- [x] **F-18a:** `role:admin` middleware on notification rule-CRUD routes.
- [x] **F-18a:** `view-notification-rules` Gate defined; bell visible to all auth users; Settings link admin-gated.
- [x] **F-18a:** `notification.js` loaded on every authenticated page; BASE_URL + unread-count URL + response parsing fixed; toast container + audio element present.
- [x] **F-18a:** Real-time delivery via SSE (PostgreSQL LISTEN/NOTIFY → Redis → EventSource) — no continuous DB polling.
- [x] **F-18b:** Multi-select recipient types per event (pivot table `notification_rule_recipients`) + context-aware recipient resolution (`warehouse_manager_of_branch`, `salesman_of_invoice`, `invoice_creator`) + EVENTS expanded to the user's 9 predefined events (+ 5 pre-existing) + RECIPIENTS expanded to 10 types (3 context-aware) + vestigial `broadcast` channel removed from `ERPNotification` (`via()` now database-only, `toBroadcast()` deleted, `BroadcastMessage` import dropped).
- [x] **F-18c:** Explicit `dispatch()` calls wired into 8 of 9 event trigger points (`sales_finalize`, `challan_create`, `payment_receive`, `damage_invoice_created`, `user_login`, `user_logout`, `customer_limit_increased` + 3 existing `return_*` calls upgraded with `$context`). `branch_demand_created` deferred — no Laravel BranchDemand controller/service exists yet (table only; legacy-only code path).
- [x] **F-18d:** Admin UX polish — Select2 multi-select for `recipient_types[]` on `rules.blade.php` (searchable, `closeOnSelect:false`, show/hide-Specific-User + empty-recipient submit guard rewritten for Select2's hidden `<select>`) + `NotificationRuleSeeder` (11 default rules for the 9 predefined events + 2 return sub-flows, idempotent by `(event,name)`, ` — default` suffix) + `DatabaseSeeder` (enables `php artisan db:seed` project-wide) + `POST rules/reset-defaults` route + `NotificationController::resetDefaults()` (hard-delete all rules → cascade clears pivot → re-seed; SweetAlert2 confirms).
- [ ] **F-12:** `throttle:180,1` on datatable route; `throttle:120,1` on summary route.
- [ ] **F-12:** 429 response test confirms rate limiting works.
- [ ] **F-6:** Smart search returns matches for salesman name, creator username, product name/code.
- [ ] **F-6:** Query performance acceptable (EXPLAIN ANALYZE; add index if needed).

---

## Phase 5 — Stale-Draft Surface & Polish (Medium) — ✅ Complete

> Closes gaps **F-20, F-37, F-38**. Surfaces stale drafts and polishes DataTables behavior.

### Goal
Users see stale drafts that need attention; nested SweetAlert2 focus works; DataTables length menu is intentionally hidden or surfaced.

### Tasks
1. **F-20 — Stale-draft banner on the today page.** ✅ Complete
   - In `SalesInvoiceController::index()`, compute `$staleCount = SalesInvoice::where('status','draft')->where('is_reversed',false)->where('invoice_date', '<', now()->subDays(config('sales.stale_draft_days', 14)))->count()`.
   - Pass `$staleCount` to the view.
   - If `$staleCount > 0`, render a dismissible amber warning banner above the stat cards: "⚠️ N draft invoice(s) older than 14 days. [Cancel stale drafts] [Dismiss]".
   - "Cancel stale drafts" links to `admin.sales.cancel-stale-drafts` (manager/admin) or shows a disabled tooltip for salesman/accountant.

2. **F-37 — Nested SweetAlert2 focus-trap fix.** ✅ Complete
   - Port Legacy's `sales-receive-payment.js` L12-26 focus-trap fix: on `focusin` events inside `.swal2-container` when a Bootstrap modal is open, call `e.stopImmediatePropagation()`.
   - Add this as a one-time document-level listener in the index page's `@push('scripts')` block (or a shared `resources/js/swal-focus-trap.js` loaded globally).
   - Verify the reverse-payment SweetAlert2 (Phase 3 task 1) works correctly when the receive modal is still open.

3. **F-38 — DataTables length menu decision.** ✅ Complete (decision: keep visible, default 25)
   - Decide: hide the length menu (match Legacy's fixed 25) OR keep it visible (Laravel's current [10,25,50,100,250]).
   - **Recommendation:** Keep it visible but default to 25 — power users benefit from larger pages. If hiding, set `dom: 'rtip'` and `pageLength: 25` to match Legacy exactly.

### F-20 / F-37 / F-38 Verification

| Gap | What was done | Evidence |
|---|---|---|
| **F-20** | `SalesInvoiceController::index()` computes `$staleCount` (draft + not reversed + `invoice_date < now()-stale_draft_days`, consistent with the page's `call_a_day` filter via `clone $statsBase`) + `$staleDays` (from `config('sales.stale_draft_days', 14)`) + `$canCancelStaleDrafts` (`auth()->user()->hasRole('manager','admin')`), all passed to the view. `index.blade.php` renders a dismissible amber banner between the hero header and the filter card when `$staleCount > 0`. "Cancel stale drafts" is a POST form to `admin.sales.cancel-stale-drafts` (role-gated — managers/admins get a SweetAlert2-confirmed button; salesmen/accountants get a disabled button with a "Requires manager or admin role" tooltip). "Dismiss" persists per-count in `localStorage` (key `rc_stale_draft_banner_dismissed_<count>`) so a changed stale count resurfaces the banner. | `SalesInvoiceController::index()` L118-146 + `index.blade.php` banner block L78-130 + banner JS L378-410 |
| **F-37** | Ported Legacy `sales-receive-payment.js` L12-26: a document-level capture-phase `focusin` listener that calls `e.stopImmediatePropagation()` when `e.target.closest('.swal2-container')` matches, preventing Bootstrap 5's modal focus-trap from yanking focus back when a nested SweetAlert2 dialog is open. Guarded by `window.__rcSwalFocusFix` so it registers only once. Inserted at the very top of the index page's `@push('scripts')` block (runs before any SweetAlert2 can fire). | `index.blade.php` L360-376 (IIFE at top of `<script>`) |
| **F-38** | Decision: **keep the length menu visible, default 25** (the plan doc's recommendation). The existing DataTables config already matched (`pageLength: 25`, `lengthMenu: [10,25,50,100,250]`, `dom` includes `l`); F-38 adds a documenting comment explaining the intentional choice (Legacy hard-codes 25 with no selector; Laravel keeps the selector so power users can bump to 50/100/250 for bulk review but defaults to 25 to match Legacy's first-page density). | `index.blade.php` L800-805 (comment above `pageLength`/`lengthMenu`) |

**Stale-draft count consistency:** the count uses `clone $statsBase` (which already applies the `call_a_day` filter unless `?include_called=1`), so the banner's count matches the invoices the user sees on the page. The count is independent of the active date/scope chips — stale drafts surface even when the user is viewing "today" only (the whole point: drafts older than 14 days are invisible under "today" but still need attention).

**Banner dismiss UX:** dismissed per-count, not globally. If 5 stale drafts are dismissed and a 6th appears tomorrow, the banner resurfaces (key changes from `_5` to `_6`). This avoids the banner being permanently hidden while stale drafts keep accumulating.

**Cancel-stale-drafts safety:** the POST form passes `days={{ $staleDays }}` so the existing `cancelStaleDrafts()` controller method cancels exactly the drafts the banner counted. SweetAlert2 confirms before submit (destructive — cancellations are audit-logged and irreversible). The route's `role:manager,admin` middleware is the server-side gate; the Blade-level disabled button is defense-in-depth (a salesman who somehow sees the button cannot POST — the middleware rejects).

### Dependencies
- None (independent of other phases).

### Expected result
- Stale drafts are visible and actionable from the today page.
- SweetAlert2 prompts inside the receive modal don't lose focus to Bootstrap.
- DataTables pagination length is intentionally decided (not accidental).

### Completion checklist
- [x] `$staleCount` computed and passed to the view.
- [x] Stale-draft banner renders when `$staleCount > 0`, dismissible.
- [x] "Cancel stale drafts" link role-gated (manager/admin).
- [x] Focus-trap fix applied; verified with a nested SweetAlert2 inside the open modal.
- [x] DataTables length menu decision documented + implemented.

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
| 2 — Filter UX Parity | F-31, F-32, F-41, F-40 | High | Medium (mostly JS + view) | ✅ Complete |
| 3 — Inline Reverse & Per-Row Actions | F-39, F-42 (remaining), F-17 UX | High | Medium (view + AJAX) | ✅ Complete |
| 4 — Notifications, Rate Limits, Search | F-18, F-12, F-6 | Medium | Medium (notification + middleware + query) | 🔄 In progress (F-18 ✅ a/b/c/d; F-12, F-6 pending) |
| 5 — Stale-Draft Surface & Polish | F-20, F-37, F-38 | Medium | Small (view + JS) | ✅ Complete |
| 6 — Dead Code & Architectural Polish | housekeeping | Low | Small (cleanup + Policy classes) | ⬜ Pending |

**Total: 6 phases, ~16 tasks, closing 16 verified gaps + 4 housekeeping items.**

---

**End of business implementation plan.** Cross-reference: `today-invoice-business-analysis.md` (§7-9) for the gap list this plan closes; `today-invoice-uiux-implementation-plan.md` for the UI-layer roadmap (which overlaps with Phases 1-3 on the view/JS side).
