# Sales Module Modernization Plan
## (Fix → Align → GL Integration → Mobile-First UX)

**Document Purpose**  
This is the master roadmap for the Sales module (`SalesModel`, `ChallanModel`, `SalesReturnModel`, controllers, and views). It:

1. Fixes all known bugs and integrity issues from the June 2026 audit  
2. Closes gaps vs the Purchase module and accounting foundation  
3. Integrates proper double-entry posting via `JournalPostingService`  
4. Delivers a **lightweight, mobile-friendly, user-friendly** sales experience  

**Related documents**

- [ACCOUNTING_SYSTEM_REDESIGN_PLAN.md](./ACCOUNTING_SYSTEM_REDESIGN_PLAN.md) — GL engine, Chart of Accounts, Trial Balance  
- [PURCHASE_MODULE_MODERNIZATION_PLAN.md](./PURCHASE_MODULE_MODERNIZATION_PLAN.md) — Reference implementation (Phases 1–5 complete)  
- [PROPOSED_ACCOUNTING_SCHEMA.md](./PROPOSED_ACCOUNTING_SCHEMA.md) — Table/column conventions  

**Current Overall Score:** ~5.5 / 10  
**Target Score:** 9.0 / 10 — secure, reversible, GL-integrated, mobile-ready (parity with modernized Purchase)

**Last Updated:** June 2026 — **Planning** (implementation not started)

---

## 1. Executive Summary

Today the sales flow works operationally but is **financially and technically fragile**:

| Area | Today | Target |
|------|--------|--------|
| Customer sub-ledger | Posted at invoice create | Kept, synced with GL |
| General Ledger | **None** for sales/challan/returns | Full double-entry via `JournalPostingService` |
| Stock issue | Challan only; separate DB connection | Same transaction as challan header |
| Sales return | Credit at create, stock at confirm | Aligned timing + validation |
| UX | Desktop forms, session cart | Mobile-first POS-style flow |
| Security / audit | Partial CSRF, weak audit | Full CSRF + `UserAudit` like Purchase |

**End state:** A salesman can create an invoice on a phone, warehouse completes godown/challan, returns are safe, and accountants see correct AR, revenue, COGS, and inventory in Trial Balance.

---

## 2. Current Architecture (Baseline)

```
Session cart → finalizeSales (draft invoice + dispatch reserve + customer_ledger DEBIT)
            → prepareGodown (godown_issued + warehouse dispatches)
            → finalizeChallan (challan_completed + stock OUT)
Sales return → createReturn (pending + customer_ledger CREDIT)
            → confirmReturn (completed + stock IN)
```

**Key files**

| Role | Path |
|------|------|
| Sales model | `app/models/SalesModel.php` |
| Challan / godown | `app/models/ChallanModel.php` |
| Sales return | `app/models/SalesReturnModel.php` |
| Controllers | `app/controllers/SalesController.php`, `ChallanController.php`, `SalesReturnController.php` |
| Stock engine | `app/models/StockTransactionModel.php` |
| GL engine | `app/services/Accounting/JournalPostingService.php` |
| Stock availability | `app/helpers/Helper.php` (`Get_Product_Total_Available_Stock`, etc.) |

---

## 3. Known Issues → Phase Mapping

All items from the sales audit are assigned to a phase below (nothing left orphaned).

### Critical (must fix early)

| ID | Issue | Phase |
|----|--------|-------|
| C1 | `StockTransactionModel` uses separate `Database` — stock not in same transaction as invoice/challan/return | **1** |
| C2 | `updateExistingInvoice` returns inside open transaction without `rollback` | **1** |
| C3 | `deleteInvoice` updates dispatches **after** `commit` | **1** |
| C4 | Challan status filters wrong (`godown_copy` = `challan_generated`) | **2** |
| C5 | No server-side over-return validation | **2** |
| C6 | Credit note at return create, stock at confirm (timing risk) | **2** |
| C7 | `deleteTabCart` missing on model (controller calls it) | **1** |
| C8 | Race-prone invoice/challan/return codes | **2** |
| C9 | `confirmReturn` not idempotent (double confirm → double stock IN) | **2** |
| C10 | Stock OUT can go negative | **2** |

### Medium

| ID | Issue | Phase |
|----|--------|-------|
| M1 | AR at invoice, stock at challan (document policy + optional GL split) | **4–5** |
| M2 | `prepareGodown` no status guard | **2** |
| M3 | `finalizeChallan` changes `transport_cost` without ledger/total sync | **4** |
| M4 | `MAX(running_balance)` vs `ORDER BY id DESC` inconsistency | **2** |
| M5 | Return stock IN uses sales rate not `avg_cost` | **2** |
| M6 | Multi-branch: POST `branch_id`, unscoped return search | **3** |
| M7 | `getInvoiceForEdit` INNER JOIN breaks NULL `sales_person` | **2** |
| M8 | Payment `bank_id` = `payment_mode` | **4** |
| M9 | `getInvoiceForReceive` payment join wrong | **4** |
| M10 | No stock check on cart finalize | **2** |
| M11 | Godown stock check ignores pending dispatches | **2** |
| M12 | Pending return reversal edge cases | **4** |

### Gaps vs Purchase module

| Gap | Purchase (done) | Sales (today) | Phase |
|-----|-----------------|---------------|-------|
| `journal_entry_id` on documents | Yes | No | **1, 5** |
| Reversal metadata + safe reverse | Yes | Partial (invoice soft-delete, return reverse) | **4** |
| Server-side return qty cap | Yes | Client only | **2** |
| CSRF on all POST | Yes | Challan only | **1** |
| UserAudit on key actions | Yes | Credit override only | **4** |
| Server-side DataTables + mobile cards | Yes | Legacy lists | **6** |
| Single-phase vs two-phase return | One transaction | Ledger then stock | **2** (policy) |

---

## 4. Objectives (Definition of Done)

When this plan is complete:

1. **Correctness** — No negative stock (unless explicit config), no duplicate challan/return processing, atomic stock + header + ledger/GL.  
2. **Consistency** — Same patterns as Purchase: transactions, audit, reversals, `journal_entry_id`.  
3. **Accounting** — Documented posting rules; Trial Balance includes sales, COGS, and returns.  
4. **Multi-branch** — Session branch enforced; admin override explicit.  
5. **UX** — Fast mobile sales screen: customer → products → cart → save; warehouse godown/challan optimized for touch.  
6. **Maintainability** — Shared connection injection for `StockTransactionModel`; posting only through `JournalPostingService`.

---

## 5. Accounting Policy Decisions (Lock Before Phase 5)

These must be agreed once and coded consistently (recommendation in **bold**):

| Event | Sub-ledger (customer_ledger) | GL (journal) | Recommendation |
|-------|------------------------------|--------------|----------------|
| **Invoice finalized** (draft) | Debit customer (AR) | Dr AR / Cr Sales Revenue (+ tax lines later) | **Post GL at invoice** — matches current sub-ledger timing |
| **Challan completed** (stock OUT) | No change | Dr COGS / Cr Inventory (at moving avg) | **Post GL at challan** — matches stock movement |
| **Customer payment** | Credit customer | Dr Bank/Cash / Cr AR | Post with payment |
| **Return confirmed** (stock IN) | Credit customer | Dr Sales Returns & Allowances / Cr AR; Dr Inventory / Cr COGS (recover cost) | **Move sub-ledger credit to confirm** (align with stock) |
| **Return pending cancelled** | No net effect | N/A | Reversal entry only if provisional credit was posted |

**Transport cost on challan:** Either block edit after invoice, or recalculate `total_amount` + sub-ledger + GL adjustment in one transaction (Phase 4).

**COGS rate:** Always `warehouse_stock.avg_cost` at issue; returns use same cost layer, not selling price (fixes M5).

---

## 6. Phased Implementation Plan

### Phase 1: Critical Foundation — **COMPLETED** (June 2026)

**Goal:** Stop data corruption and runtime errors; prepare DB for journals.

**Completed:**
- Shared `Database` connection injected into `StockTransactionModel` from `ChallanModel` and `SalesReturnModel`
- `updateExistingInvoice` validates credit limit before `beginTransaction()`
- `deleteInvoice` removes dispatches inside the transaction (DELETE, not invalid `is_reversed` column)
- `deleteTabCart()` alias added (fixes controller fatal)
- Migration `007_add_journal_entry_to_sales.sql` + `set*JournalEntryId()` helpers on models
- CSRF on all Sales / SalesReturn mutating POST actions + frontend tokens

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 1.1 | **Shared DB connection** for stock ops | Critical | `StockTransactionModel`, `ChallanModel`, `SalesReturnModel`, optionally `Database` factory | Challan/return rollback also rolls back `warehouse_stock` + `stock_transactions` |
| 1.2 | Fix `updateExistingInvoice` early return | Critical | `SalesModel.php` | Credit-limit failure calls `rollback()` or validates before `beginTransaction()` |
| 1.3 | Fix `deleteInvoice` dispatch update | Critical | `SalesModel.php` | `sales_invoice_dispatches.is_reversed` updated **inside** transaction before commit |
| 1.4 | Fix `deleteTabCart` | High | `SalesController.php` and/or `SalesModel.php` | Endpoint works (alias to `clearTabCart` or implement method) |
| 1.5 | Migration: sales GL + reversal columns | Critical | `database/migrations/00X_sales_journal_and_reversal.sql` (new) | See schema below |
| 1.6 | CSRF on Sales + SalesReturn POST | Critical | `SalesController`, `SalesReturnController` | All mutating actions call `validateCSRF()` |
| 1.7 | Models store `journal_entry_id` (nullable) | High | `SalesModel`, `ChallanModel`, `SalesReturnModel` | Columns read/written on create/post |

**Migration 00X (proposed columns)**

```sql
-- sales_invoices
ALTER TABLE sales_invoices ADD COLUMN journal_entry_id INT NULL AFTER total_amount;
ALTER TABLE sales_invoices ADD INDEX idx_si_journal (journal_entry_id);

-- sales_challans (COGS posting)
ALTER TABLE sales_challans ADD COLUMN journal_entry_id INT NULL;

-- sales_returns
ALTER TABLE sales_returns ADD COLUMN journal_entry_id INT NULL;
-- customer_payments (when GL added)
ALTER TABLE customer_payments ADD COLUMN journal_entry_id INT NULL;
```

**Phase 1 exit:** No fatal cart bug; invoice delete atomic; stock in same transaction; schema ready.

---

### Phase 2: Business Logic & Data Integrity — **COMPLETED** (June 2026)

**Goal:** Sales/return/challan rules match Purchase rigor.

**Completed:**
- `document_sequences` table + atomic codes for invoice, challan, return
- Server-side returnable qty validation; total recomputed on create
- Customer credit note posts on **warehouse confirm** (not on pending create)
- `confirmReturn` uses `FOR UPDATE`, pending-only guard, structured errors
- Negative stock blocked on OUT; returns stock IN at moving `avg_cost`
- Running balance via `ORDER BY id DESC`
- Challan filters use `si.status`; godown status guard + net warehouse availability
- Cart stock validation on finalize/edit; `LEFT JOIN` sales_person on edit

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 2.1 | **Atomic document numbers** | High | `SalesModel`, `ChallanModel`, `SalesReturnModel` | DB sequence table or `FOR UPDATE` counter; unique constraints on codes |
| 2.2 | Server-side **returnable qty** validation | Critical | `SalesReturnModel::createReturn` | Cannot exceed `qty - SUM(previous returns)` per line |
| 2.3 | **Credit note timing** | High | `SalesReturnModel` | Sub-ledger credit on `confirmReturn` (or provisional + auto-reverse on cancel) — per policy §5 |
| 2.4 | `confirmReturn` idempotency | Critical | `SalesReturnModel` | `status = 'pending'` + row lock; second submit fails cleanly |
| 2.5 | Negative stock prevention | High | `StockTransactionModel`, `ChallanModel` | OUT rejected if `qty + delta < 0`; clear error message |
| 2.6 | Return stock IN at **avg_cost** | High | `SalesReturnModel::confirmReturn` | Uses warehouse avg, not sales rate |
| 2.7 | Running balance: `ORDER BY id DESC` only | Medium | `SalesReturnModel` | Remove `MAX(running_balance)` |
| 2.8 | Fix challan **filters** | Medium | `SalesModel::getFilteredTodayInvoices` | godown = `godown_issued` or pending dispatch; challan = `challan_completed` |
| 2.9 | `prepareGodown` status guard | Medium | `ChallanModel` | Only `draft` or `godown_issued` |
| 2.10 | Stock check on **finalizeSales** | High | `SalesModel` + Helper | Warn/block if cart qty > available (branch-aware) |
| 2.11 | Godown validation uses **available** qty | High | `ChallanModel::prepareGodown` | Same formula as `Get_Product_Total_Available_Stock` |
| 2.12 | `getInvoiceForEdit` LEFT JOIN sales_person | Low | `SalesModel` | Editable when `sales_person` is NULL |

**Phase 2 exit:** Returns cannot over-return; double confirm impossible; stock math trustworthy; filters accurate.

---

### Phase 3: Multi-Branch & Security Hardening — **COMPLETED** (2026-06-02)

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 3.1 | Enforce `branch_id` from session on create | High | `SalesModel`, controllers | POST cannot override branch unless `role === admin` |
| 3.2 | Branch scope on return invoice search | High | `SalesReturnModel::searchInvoices` | Only invoices for session branch |
| 3.3 | Branch on `getPendingReturns` | Medium | `SalesReturnModel` | Warehouse sees own branch pending list |
| 3.4 | Align “Today invoices” filter | Medium | `SalesModel` / controller | Branch always scoped; admin/manager see all branch invoices, others own only |
| 3.5 | Warehouse confirm: warehouse belongs to branch | High | `SalesReturnModel::confirmReturn` | Invalid warehouse rejected server-side |

**Implemented:** `Helper` branch-scope helpers (`sessionBranchId`, `resolveBranchIdForWrite/Read`, `assertInvoiceAccessible`, `warehouseBelongsToBranch`); sales finalize/edit/delete/payment/print/receive; challan godown/finalize/lists; returns search/create/confirm/reverse/slip; `get_branch` all-branches only for admin; stock/search APIs use `resolveBranchIdForRead`; `sales.js` branch picker locked for non-admin.

**Phase 3 exit:** Cross-branch data leaks closed.

---

### Phase 4: Auditability, Reversals & Payment Fixes — **COMPLETED** (2026-06-02)

**Goal:** Match Purchase Phase 4 — every action logged; reversals safe.

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 4.1 | **UserAudit** on all key actions | High | Controllers | `sale_*`, `godown_prepared`, `challan_*`, `payment_received`, `return_*` |
| 4.2 | Reverse **challan** (new) | High | `ChallanModel` + migration 009 | Stock IN + status → `godown_issued` + JE reversal if linked |
| 4.3 | Invoice delete only if no godown/challan | High | `SalesModel::deleteInvoice` | Draft only; blocks challan/dispatched stock |
| 4.4 | Transport cost change policy | Medium | `ChallanModel::finalizeChallan` | Recalc `total_amount` + customer ledger delta at challan |
| 4.5 | Fix `recordCustomerPayment` bank mapping | Medium | `SalesModel` | `cash` vs `bank` + separate `bank_id`; `transaction_type=receive` |
| 4.6 | Fix `getInvoiceForReceive` allocations | Medium | `SalesModel` | Sums `invoice_payment_allocations` |
| 4.7 | Dedicated audit pages (optional) | Low | `sales/audit`, `SalesReturn/audit` | Filtered log viewers |

**Implemented:** Migration `009_sales_phase4_reversal_audit.sql`; challan reverse UI (admin/manager); receive modal Cash option; audit links on Today/Returns.

**Phase 4 exit:** Full traceability; dangerous deletes blocked; payments display correctly.

---

### Phase 5: General Ledger Integration — **COMPLETED** (2026-06-02)

**Goal:** Sales appears in Trial Balance; aligned with [ACCOUNTING_SYSTEM_REDESIGN_PLAN.md](./ACCOUNTING_SYSTEM_REDESIGN_PLAN.md).

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 5.1 | Document posting rules in code comments | Critical | `JournalPostingService`, this doc §5 | Rules match implementation |
| 5.2 | `postSalesInvoice()` | Critical | `JournalPostingService` | Dr AR (control) / Cr Sales Revenue; `journal_entry_id` on invoice |
| 5.3 | `postSalesChallanCOGS()` | Critical | `JournalPostingService`, `ChallanModel` | Dr COGS / Cr Inventory at avg cost |
| 5.4 | `postCustomerPayment()` | High | `JournalPostingService`, `SalesModel` | Dr Bank/Cash / Cr AR |
| 5.5 | `postSalesReturn()` | High | `JournalPostingService`, `SalesReturnModel` | Return + inventory restoration lines |
| 5.6 | Reversing entries | High | `JournalEntryModel::createReversingEntry` | Invoice delete, return reverse, challan reverse |
| 5.7 | Control accounts | High | Seed / config | AR → customer control; tie to sub-ledger |
| 5.8 | Trial Balance verification checklist | Medium | `docs/ACCOUNTING_PROGRESS.md` | Test script: 1 sale → 1 challan → 1 payment → 1 return |

**Implemented:** `postSalesInvoice`, `postSalesInvoiceTotalAdjustment`, `postSalesChallanCOGS`, `postCustomerPayment`, `postSalesReturn`, `reverseLinkedJournal`; wired in `SalesModel`, `ChallanModel`, `SalesReturnModel` (fail transaction if JE fails).

**Example posting (invoice)**

```
Dr  Accounts Receivable (control)     total_amount
    Cr  Sales Revenue                  subtotal - discount
    Cr  Sales Revenue (transport)      transport_cost   [same ledger unless split later]
```

**Example posting (challan)**

```
Dr  Cost of Goods Sold               Σ(qty × avg_cost)
    Cr  Inventory                      Σ(qty × avg_cost)
```

**Phase 5 exit:** GL balances; sub-ledger reconciles to AR control; `journal_entry_id` populated.

---

### Phase 6: Mobile-First, Lightweight Sales UX — **DONE**

**Goal:** Fast, touch-friendly experience for field sales and warehouse — minimal taps, works on 360px screens.

| # | Task | Priority | Files | Acceptance criteria |
|---|------|----------|-------|---------------------|
| 6.1 | **Mobile sales shell** (new layout) | Critical | `app/views/sales/mobile/` or refactor `create.php` | Bottom nav, large tap targets, sticky cart bar |
| 6.2 | Customer quick search + recents | High | JS + optional API | Select customer in ≤2 taps |
| 6.3 | Product search with live stock badge | High | Reuse `search_product` | Shows available qty; out-of-stock disabled |
| 6.4 | Cart: swipe remove, +/- qty steppers | High | JS | No tiny inputs; haptic-friendly |
| 6.5 | Offline-friendly draft (optional) | Medium | localStorage backup of cart | Refresh does not lose cart |
| 6.6 | Server-side DataTables + **mobile cards** on Today / Challan / Returns | High | `sales/today.php`, `challan/index.php`, `SalesReturn/index.php` | Same pattern as Purchase indexes |
| 6.7 | Godown/challan: barcode-ready product rows | Medium | Challan views | Large rows, warehouse dropdown per line |
| 6.8 | PWA meta / install prompt (optional) | Low | `public/` layout | Add to home screen on Android |
| 6.9 | Performance: reduce full page reloads | High | AJAX finalize, toast feedback | Save invoice without leaving screen |

**UX principles**

- Max **3 taps** from open app to adding first product (returning user).  
- Primary actions fixed at bottom (thumb zone).  
- Amounts and qty in **16px+** font; contrast WCAG AA.  
- Error messages plain Urdu/English mix as per existing app tone.

**Phase 6 exit:** Usable on phone without horizontal scroll; sales staff acceptance test passed.

---

### Phase 7: Polish, Reports & Performance — **NOT STARTED**

| # | Task | Priority | Notes |
|---|------|----------|-------|
| 7.1 | Sales GL detail on invoice print | Medium | Show JE number for accountants |
| 7.2 | Customer statement from ledger + GL | Medium | Reconcile sub-ledger to control |
| 7.3 | Replace session cart with DB draft (multi-device) | Low | `sales_drafts` table per user/customer |
| 7.4 | Index/query optimization on dispatches | Low | Composite indexes for pending qty |
| 7.5 | Export columns include challan status correctly | Low | Fix CSV challan column in `SalesController::export` |

---

## 7. Implementation Order (Recommended)

```
Phase 1 ──► Phase 2 ──► Phase 3
                │
                ▼
         Phase 4 ──► Phase 5 ──► Phase 6 ──► Phase 7
```

- **Do not start Phase 5** until Phase 1 (shared transaction) and Phase 2 (return/challan integrity) are done.  
- **Phase 6** can start in parallel after Phase 2 for UI-only work (mock APIs), but go live after Phase 5 if amounts must match GL.  
- Purchase module Phase 5 patterns are the **template** for journal posting code style.

---

## 8. File Change Checklist (Quick Reference)

| File | Phases |
|------|--------|
| `app/models/StockTransactionModel.php` | 1, 2 |
| `app/models/SalesModel.php` | 1, 2, 4, 5 |
| `app/models/ChallanModel.php` | 1, 2, 4, 5 |
| `app/models/SalesReturnModel.php` | 1, 2, 3, 5 |
| `app/controllers/SalesController.php` | 1, 4, 6 |
| `app/controllers/ChallanController.php` | 1, 4, 6 |
| `app/controllers/SalesReturnController.php` | 1, 3, 4, 6 |
| `app/services/Accounting/JournalPostingService.php` | 5 |
| `app/helpers/Helper.php` | 2 |
| `database/migrations/00X_sales_*.sql` | 1 |
| `public/assets/js/` (sales, challan, return) | 6 |
| `app/views/sales/*`, `challan/*`, `SalesReturn/*` | 6, 7 |

---

## 9. Testing Checklist (Per Phase)

### Phase 1–2

- [ ] Concurrent two invoices → unique codes  
- [ ] Challan failure mid-loop → no partial stock deduction  
- [ ] Delete draft invoice → dispatches reversed in same TX  
- [ ] Return qty > sold → rejected  
- [ ] Double-click confirm return → second fails  

### Phase 5

- [ ] Trial Balance balanced after: invoice + challan + payment + return  
- [ ] Reversing invoice creates reversing JE; AR net zero  

### Phase 6

- [ ] iPhone/Android Chrome: create 5-line invoice without zoom  
- [ ] Warehouse godown on tablet portrait mode  

---

## 10. Progress Tracker

| Phase | Status | Completed | Notes |
|-------|--------|-----------|-------|
| 1 — Critical foundation | ✅ Completed | June 2026 | Migration 007; CSRF; shared DB for stock |
| 2 — Data integrity | ✅ Completed | June 2026 | See Phase 2 notes above |
| 3 — Multi-branch | ✅ Completed | 2026-06-02 | Helper branch scope; admin-only cross-branch create |
| 4 — Audit & reversals | ✅ Completed | 2026-06-02 | UserAudit; challan reverse; payment fixes |
| 5 — GL integration | ✅ Completed | 2026-06-02 | JournalPostingService + model hooks |
| 6 — Mobile UX | ⬜ Not started | | |
| 7 — Polish | ⬜ Not started | | |

**Update this table as tasks complete** (same style as `PURCHASE_MODULE_MODERNIZATION_PLAN.md`).

---

## 11. Success Metrics

| Metric | Before | Target |
|--------|--------|--------|
| Critical bugs open | 10 | 0 |
| Sales in Trial Balance | No | Yes |
| Mobile usability (subjective 1–5) | ~2 | ≥4 |
| Return over-qty incidents | Possible | Zero |
| Audit log coverage on sales actions | ~10% | 100% |
| Transaction atomicity (stock + header) | No | Yes |

---

*This document is the single source of truth for Sales module work. When implementation starts, update §10 and link PRs/commits to task IDs (e.g. `S2.4`).*