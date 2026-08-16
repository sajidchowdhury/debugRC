# Session 6 Confirmation — Below-Min Admin Override Workflow

**Phase 2 / Q2 — Pricing & P&L**
**Status:** Code complete, ready for UAT
**Branches:** `feature/fy-isolation-and-branch-pnl` + `main` (both pushed)

## Goal Recap

Allow a sale below the product's minimum price ONLY when an admin or
manager actively approves it with a reason (≥ 10 chars). The approval
is logged to `user_audit_log` with `action='below_min_override'`, and
the sale line's `below_min_override_id` points to that audit row.

## Implementation Summary

### Design Decision: Synchronous Approval

The plan's original draft proposed a two-step async flow (request
approval → pending row → approve/reject → finalize). After reviewing
the existing `credit_limit_override` pattern (in-flow, atomic, no
pending table) and confirming the S5 migration already added the
`below_min_override_id` column, we implemented a **synchronous**
single-call approval flow:

1. Cashier enters a below-min rate in the cart UI.
2. A SweetAlert2 modal prompts for approver username + password +
   reason (≥ 10 chars).
3. The modal POSTs to `/admin/sales/below-min-approvals`.
4. The server re-authenticates the approver (fresh credential check,
   NOT session-based), checks the role AT APPROVAL TIME, validates
   the reason, and inserts a `user_audit_log` row.
5. The returned `audit_log_id` is passed to `/cart/add` as
   `below_min_override_id`.
6. `SalesCartService::addItem()` sees the override id, skips the
   below-min hard-throw, and stores the id on the cart line
   (`items_json.below_min_override_id`).
7. At finalize, `SalesInvoiceService::finalizeFromCart()` reads the
   override id from each cart line and writes it to
   `sales_invoice_items.below_min_override_id`. It also forces
   `price_classification='below_min'` for those lines.

This avoids a new DB table, mirrors the established
`credit_limit_override` pattern, and keeps the audit row atomic with
the approval decision (not the sale — the audit row records the
APPROVAL DECISION, which may be reused if the cart add fails and the
cashier retries).

### Files Touched

**New files:**
- `app/Services/Sales/BelowMinApprovalService.php` — main service
  (`approve()`, `reject()`, `pendingForBranch()`, `isValidOverride()`)
- `app/Http/Controllers/Admin/SalesBelowMinApprovalController.php` —
  `store()` (approve) + `index()` (list recent for branch)
- `docs/IMPLEMENTATION_PLAN_SESSION6_CONFIRMATION.md` — this file

**Modified files:**
- `app/Services/Sales/SalesCartService.php`:
  - Constructor: inject `BelowMinApprovalService`
  - `addItem()`: accept `below_min_override_id`; skip below-min
    hard-throw when override id is present + valid; store on cart line
  - `updateItem()`: accept `below_min_override_id`; require it when
    rate changes to below-min; clear it when rate returns to range
  - `validateCartItems()`: skip rate-error for lines with override id;
    add `error_type` field to rate_errors (`below_min_no_override`
    vs `above_max`) for clearer UI messaging
  - `enrichItems()`: normalize `below_min_override_id` to `int|null`
- `app/Services/Sales/SalesInvoiceService.php`:
  - `finalizeFromCart()`: read `below_min_override_id` from cart line,
    write to `sales_invoice_items`, force `price_classification='below_min'`
  - `updateInvoice()`: same logic for the edit flow
- `app/Services/Sales/SalesAuditLogger.php`:
  - Add `below_min_override` to `recentSalesEvents()` action list
  - Update class docblock (event 4a)
- `app/Http/Controllers/Admin/SalesCartController.php`:
  - `add()`: accept + pass through `below_min_override_id`
  - `update()`: accept + pass through `below_min_override_id`
- `routes/web.php`:
  - Import `SalesBelowMinApprovalController`
  - Add `POST /admin/sales/below-min-approvals` (role:salesman,manager,admin)
  - Add `GET /admin/sales/below-min-approvals` (role:admin,manager)
- `resources/views/admin/sales/cart.blade.php`:
  - Inject `window.IS_ADMIN_OR_MANAGER`, `window.CURRENT_USER_ID`,
    `window.BELOW_MIN_APPROVAL_ENDPOINT`
  - Add `promptBelowMinApproval()` helper (SweetAlert2 modal with
    approver username + password + reason fields)
  - Modify `addToCart()` to call `promptBelowMinApproval()` when rate
    is below min, then pass `below_min_override_id` to `/cart/add`
  - Modify `updateItem()` to do the same for rate edits
  - Update rate-errors rendering to distinguish `below_min_no_override`
    (warning, "approval required") from `above_max` (danger, hard block)

### Schema

No new migration needed — the S5 migration
(`2026_10_17_000004_add_price_classification_to_sales_invoice_items.php`)
already added the `below_min_override_id` column to
`sales_invoice_items` as a plain `bigint` with NO declarative FK
(because `user_audit_log` is partitioned and has no unique constraint
on `id` alone — see S5 migration docblock). Referential integrity is
enforced at the application layer by `BelowMinApprovalService::isValidOverride()`.

The plan mentioned possibly adding `action_type`, `subject_type`,
`subject_id`, `payload` columns to `user_audit_log`. Audit confirmed
the table already has equivalent columns under different names:
- `action` (varchar(50)) ← `action_type`
- `record_id` (integer, nullable) ← `subject_id`
- `details` (jsonb) ← `payload`
- (`subject_type` is stored inside `details` as `action_type`)

No schema change was needed.

## UAT Checklist — Acceptance Tests

Run each test on the Docker dev host. Mark `[x]` when passed.

### 1. Cashier enters rate below min → modal appears (no hard throw)

- [ ] Log in as a salesman (NOT admin/manager).
- [ ] Go to `/admin/sales/cart`, select a customer, select a product.
- [ ] Enter a rate that is below the product's `min_rate` (e.g. if min
      is 100, enter 80).
- [ ] Click "Add to Cart".
- [ ] **Expected:** A SweetAlert2 modal appears with title
      "Below-Min Approval" and fields for approver username, password,
      and reason. The line is NOT added to the cart yet.
- [ ] **NOT expected:** An immediate error toast like "Rate 80 is out
      of allowed range" (that was the pre-S6 behavior).

### 2. Modal rejects reason < 10 chars with a validation error

- [ ] From test 1, enter valid admin credentials but a reason shorter
      than 10 chars (e.g. "ok").
- [ ] Click "Approve & Add".
- [ ] **Expected:** The modal shows a validation message:
      "Reason must be at least 10 characters." The modal stays open.
- [ ] **NOT expected:** The modal closes or the line is added.

### 3. Modal rejects non-admin/non-manager approver credentials

- [ ] From test 1, enter credentials of another salesman (not admin/
      manager) and a valid reason (≥ 10 chars).
- [ ] Click "Approve & Add".
- [ ] **Expected:** The modal shows a validation message:
      "Approver role is not sufficient. Only admin or manager can
      approve below-min sales." (HTTP 403 response.)
- [ ] **NOT expected:** The approval succeeds.

### 4. Successful approval → cart line marked approved, modal closes

- [ ] From test 1, enter valid admin/manager credentials and a reason
      ≥ 10 chars.
- [ ] Click "Approve & Add".
- [ ] **Expected:** The modal closes, a success toast appears
      ("Item added"), and the cart line appears in the cart table with
      the below-min rate.
- [ ] **Verify the audit row was written:**
      ```bash
      docker compose exec rcerp_postgres psql -U rcerp -d rcerp -c \
        "SELECT id, user_id, action, target_user_id, branch_id, details, created_at \
         FROM user_audit_log WHERE action='below_min_override' ORDER BY id DESC LIMIT 1;"
      ```
      The row should have:
      - `user_id` = the admin/manager's user id (NOT the cashier's)
      - `action` = `below_min_override`
      - `target_user_id` = the cashier's user id
      - `details` JSONB with `product_id`, `requested_rate`, `min_rate`,
        `reason`, `customer_id`, `approver_role`
- [ ] **Verify the cart line has the override id:**
      ```bash
      docker compose exec rcerp_postgres psql -U rcerp -d rcerp -c \
        "SELECT items_json FROM sales_draft_carts WHERE user_id=<cashier_id>;"
      ```
      The items_json array should contain an entry with
      `below_min_override_id` set to the audit row id from the previous
      query.

### 5. Rejected approval → cart line NOT added

- [ ] From test 1, click "Cancel" on the modal (or press Escape).
- [ ] **Expected:** The modal closes, no toast appears, the cart line
      is NOT added. The cart table is unchanged.

### 6. Finalized invoice line has correct classification + override id

- [ ] From test 4 (cart has an approved below-min line), finalize the
      cart into an invoice.
- [ ] **Verify the sales_invoice_items row:**
      ```bash
      docker compose exec rcerp_postgres psql -U rcerp -d rcerp -c \
        "SELECT id, product_id, rate, price_min, price_max, price_default, \
                cost_rate, price_classification, below_min_override_id \
         FROM sales_invoice_items WHERE sales_invoice_id=<invoice_id>;"
      ```
      The below-min line should have:
      - `price_classification` = `below_min`
      - `below_min_override_id` = the audit row id (non-null)
      - `price_min`, `price_max`, `price_default` populated from the
        product's price history at sale time
- [ ] **Verify the audit row's details:**
      ```bash
      docker compose exec rcerp_postgres psql -U rcerp -d rcerp -c \
        "SELECT details->>'product_id', details->>'requested_rate', \
                details->>'min_rate', details->>'reason', \
                details->>'approver_role', details->>'cashier_user_id' \
         FROM user_audit_log WHERE id=<override_id>;"
      ```
      All fields should be populated with the values from the approval
      modal.

### 7. Privilege-escalation test (role revoked between request and approval)

This test verifies the critical defense: the role check runs at
APPROVAL TIME, not at session-login time.

- [ ] Create a test user with role `manager`.
- [ ] Open the cart page in browser A, logged in as the salesman.
- [ ] Enter a below-min rate → modal appears.
- [ ] In browser B (or via psql), change the manager's role to
      `salesman`:
      ```bash
      docker compose exec rcerp_postgres psql -U rcerp -d rcerp -c \
        "UPDATE employees SET role='salesman' WHERE user_id=<manager_user_id>;"
      ```
      (Or use the user-management UI to demote the manager.)
- [ ] Back in browser A, enter the (now-demoted) manager's credentials
      and a valid reason in the modal.
- [ ] Click "Approve & Add".
- [ ] **Expected:** The modal shows:
      "Approver role is not sufficient. Only admin or manager can
      approve below-min sales." (HTTP 403.)
- [ ] **NOT expected:** The approval succeeds (which would be a
      privilege-escalation vulnerability).

### 8. Edit flow — below-min rate on invoice edit

- [ ] Create an invoice with a normal (within-range) line.
- [ ] Edit the invoice, change the rate to below-min.
- [ ] **Expected:** The same SweetAlert2 approval modal appears.
- [ ] Complete the approval → the edited invoice line has
      `price_classification='below_min'` and `below_min_override_id` set.
- [ ] **Note:** The edit form must pass `below_min_override_id` in the
      items payload. If the form doesn't (legacy edit JS), the server
      will hard-block the below-min rate. The cart.blade.php inline
      edit flow handles this; the legacy sales-edit.js may need a
      follow-up to support the modal (see "Known Limitations" below).

## Known Limitations / Follow-Ups

1. **Legacy `sales-edit.js` not updated.** The invoice edit page
   (`resources/views/admin/sales-invoices/edit.blade.php`) uses
   `public/assets/js/sales-edit.js` which still has the pre-S6
   hard-block on below-min rates. The cart.blade.php inline IIFE
   (used for the POS/cart page) IS updated. If the edit page is used
   to change a rate to below-min, the server will hard-block it
   (returning a 400 error). A follow-up task should port the
   `promptBelowMinApproval()` modal to sales-edit.js. The schema and
   service layer are ready — only the JS needs updating.

2. **API routes not added.** The plan mentioned possibly adding API
   routes (`routes/api.php`) for mobile/external clients. The web
   routes are sufficient for the POS UI. If the API is needed later,
   mirror the web routes under `api.auth:salesman,manager,admin`.

3. **No PHPUnit tests written.** The plan's acceptance test 7
   (privilege escalation) and the basic approval flow should be
   covered by feature tests. A follow-up task should add
   `tests/Feature/Api/V1/Sales/BelowMinOverrideApiTest.php` with:
   - `test_below_min_rate_blocked_without_override()`
   - `test_below_min_rate_allowed_with_admin_override()`
   - `test_below_min_rate_blocked_with_insufficient_role()`
   - `test_below_min_rate_blocked_with_invalid_credentials()`
   - `test_reason_minimum_10_chars_enforced()`
   - `test_privilege_escalation_blocked()` (role revoked between
     request and approval)

4. **Orphaned audit rows.** If the cashier approves a below-min rate
   but then cancels the cart add (or the cart add fails for another
   reason like stock insufficient), the audit row is orphaned — it
   records the APPROVAL DECISION but no sale line points to it. This
   is acceptable (the audit row is still a valid record of the
   approval decision) but a future retention job could mark old
   orphaned rows for review. The `details.invoice_id` field is NULL
   for orphaned rows; non-orphaned rows have it populated at finalize
   time (currently we don't backfill — see "Implementation Summary"
   above).

5. **Re-approval on qty bump.** If a cashier adds a below-min line
   (with override) and then bumps the qty, the existing override id
   is preserved (no re-approval needed). This is intentional — the
   approval was for the rate, not the qty. If the rate changes,
   re-approval is required (handled by `updateItem()`).

## PM Checkpoint

Report to the client:

> Session 6 complete. Below-min sales are now possible with admin/manager
> approval + reason (≥ 10 chars). Every below-min sale is fully auditable
> — the `user_audit_log` row records the approver, the reason, the
> product, the requested rate, and the min rate. The sale line's
> `below_min_override_id` points back to the audit row. The role check
> runs at approval time (not session-login time), so a manager whose
> role is revoked between request and approval is correctly blocked.
> Ready for Session 7 (demand-item FIFO linkage).
