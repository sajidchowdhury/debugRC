# Sales Module — Implementation Progress Tracker

> **Companion to:** `SALES_MODULE_ROADMAP.md` (full roadmap with detailed tasks, acceptance criteria, and sign-off tables — see worklog for the original).
>
> **Purpose:** Track completion status of each roadmap task. Update as work progresses.
>
> **Audit Verdict:** 🔴 NOT READY FOR PRODUCTION
>
> **Last Updated:** 2025-01-08 (P0-1 through P0-4 complete)

---

## Phase 0 — Critical Blockers

| Task | Description | Status | Notes |
|------|-------------|--------|-------|
| **P0-1** | Fix `sales_invoices.transport_cost` mismatch | ✅ Done | Migration `2025_01_08_000001` — added `transport_cost numeric(12,2) DEFAULT 0` |
| **P0-2** | Fix `sales_invoice_dispatches` (ordered_qty/dispatched_qty/created_by) | ✅ Done | Migration `2025_01_08_000002` + 3 service code fixes (qty now populated) |
| **P0-3** | Fix `sales_returns` (cogs_amount/reason) + `sales_return_items` (sales_invoice_item_id) | ✅ Done | Migration `2025_01_08_000003` — added 3 columns + FK + partial index |
| **P0-4** | Fix `customer_payments.reference_no` | ✅ Done | Migration `2025_01_08_000004` — added column + partial index |
| **P0-5** | Add missing `sales_challan_items` table | ⬜ Pending | Per-line issue cost SSOT (migration 040 equivalent) |
| **P0-6** | Wire cart finalize button to backend | ⬜ Pending | UI stub shows "Coming in Phase 8.2" |
| **P0-7** | Add RBAC to all sales routes | ⬜ Pending | Currently only `auth` middleware |
| **P0-8** | Add branch isolation (middleware + policy + scope) | ⬜ Pending | No `assertInvoiceAccessible` equivalent |

### P0-1 Through P0-4 — Detailed Completion Notes

**4 migrations created** in `laravel/database/migrations/`:

1. **`2025_01_08_000001_add_transport_cost_to_sales_invoices.php`**
   - Adds `transport_cost numeric(12,2) DEFAULT 0` after `tax_amount`
   - Idempotent (guarded by `Schema::hasColumn`)
   - Decision: Option B (add column back) — lower risk than rewriting service + views

2. **`2025_01_08_000002_restore_dispatch_quantity_columns.php`**
   - Adds `ordered_qty numeric(14,4) DEFAULT 0`, `dispatched_qty numeric(14,4) DEFAULT 0`, `created_by integer`
   - Backfills existing rows: `ordered_qty = qty WHERE ordered_qty = 0 AND qty > 0`
   - Adds partial index `idx_sdis_pipeline ON (sales_invoice_id, product_id) WHERE dispatched_qty < ordered_qty`
   - **3 service code fixes** to also populate the existing `qty` column (feeds the `GENERATED amount` column):
     - `SalesInvoiceService::finalizeFromCart:184` — adds `'qty' => item.qty` alongside `ordered_qty`
     - `SalesChallanService::issueChallan:192` — adds `'qty' => DB::raw('ordered_qty')` alongside `dispatched_qty`
     - `SalesChallanService::cancelChallan:273` — adds `'qty' => 0` alongside `dispatched_qty => 0`

3. **`2025_01_08_000003_fix_sales_return_schema_mismatches.php`**
   - Adds `cogs_amount numeric(14,2) DEFAULT 0` + `reason text` to `sales_returns`
   - Adds `sales_invoice_item_id integer` FK to `sales_return_items` (ON DELETE SET NULL)
   - Adds partial index `idx_sri_invoice_item WHERE sales_invoice_item_id IS NOT NULL`
   - Note: `reason` and `notes` coexist (reason = user rationale, notes = internal)

4. **`2025_01_08_000004_add_reference_no_to_customer_payments.php`**
   - Adds `reference_no varchar(100)` after `payment_mode`
   - Adds partial index `idx_cp_reference_no WHERE reference_no IS NOT NULL`
   - Captures cheque no, bank txn ID, mobile banking txn ID

### Verification Status
- [x] All 4 migration files created with correct syntax (anonymous class, up/down, idempotent guards)
- [x] All 5 missing columns now have matching schema
- [x] Service code updated to populate `qty` alongside `ordered_qty`/`dispatched_qty`
- [x] Models already had these columns in `fillable` (no model changes needed)
- [ ] `php artisan migrate` — **PENDING** (no PHP runtime in current sandbox; run in VPS/dev environment)
- [ ] End-to-end test (finalize invoice → issue challan → receive payment → create return) — **PENDING**

### Files Modified
| File | Change |
|------|--------|
| `laravel/database/migrations/2025_01_08_000001_add_transport_cost_to_sales_invoices.php` | NEW |
| `laravel/database/migrations/2025_01_08_000002_restore_dispatch_quantity_columns.php` | NEW |
| `laravel/database/migrations/2025_01_08_000003_fix_sales_return_schema_mismatches.php` | NEW |
| `laravel/database/migrations/2025_01_08_000004_add_reference_no_to_customer_payments.php` | NEW |
| `laravel/app/Services/Sales/SalesInvoiceService.php` | Line 184: added `'qty'` to dispatch insert |
| `laravel/app/Services/Sales/SalesChallanService.php` | Line 192: added `'qty'` to issueChallan update; Line 273: added `'qty'` to cancelChallan update |

---

## Phase 1 — Operational Completeness

| Task | Description | Status |
|------|-------------|--------|
| P1-1 | Invoice edit/update flow | ⬜ Pending |
| P1-2 | Stale draft cancellation (Artisan + cron) | ⬜ Pending |
| P1-3 | Fix audit logging (9 business events) | ⬜ Pending |
| P1-4 | Fix double-bookkeeping (allocations tables) | ⬜ Pending |
| P1-5 | Linked damage write-off for Damage returns | ⬜ Pending |
| P1-6 | Print views (invoice/challan/receipt/slip) | ⬜ Pending |
| P1-7 | Sales notifications (return events) | ⬜ Pending |

---

## Phase 2 — Refinements & Edge Cases

| Task | Description | Status |
|------|-------------|--------|
| P2-1 | Period-close admin bypass | ⬜ Pending |
| P2-2 | Invoice state machine (path back to draft) | ⬜ Pending |
| P2-3 | Transport snapshot workflow | ⬜ Pending |
| P2-4 | ETL data conversion plan | ⬜ Pending |
| P2-5 | Restore transaction_type or document alternative | ⬜ Pending |
| P2-6 | Idempotency token on finalize | ⬜ Pending |
| P2-7 | Cache branch pipeline qty | ⬜ Pending |

---

## Phase 3 — Verification & QA

| Task | Description | Status |
|------|-------------|--------|
| P3-1 | Stock replay verification | ⬜ Pending |
| P3-2 | Journal replay verification | ⬜ Pending |
| P3-3 | Reconciliation (6 sections) | ⬜ Pending |
| P3-4 | Shadow mode (7 days) | ⬜ Pending |
| P3-5 | Reversal verification | ⬜ Pending |
| P3-6 | Penetration test (RBAC + branch) | ⬜ Pending |
| P3-7 | Final cutover sign-off | ⬜ Pending |

---

## Next Steps

1. **Immediate:** Run `php artisan migrate` in a PHP/PostgreSQL environment to apply the 4 new migrations
2. **Immediate:** Test the full sales workflow end-to-end (cart → finalize → godown → challan → payment → return)
3. **Then:** Proceed to P0-5 (add `sales_challan_items` table) and P0-6 (wire finalize button)
4. **Then:** P0-7 (RBAC) and P0-8 (branch isolation) — the two critical security fixes
