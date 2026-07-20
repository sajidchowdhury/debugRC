# Purchase Module — Remaining Changes (Phase-by-Phase Plan)

**Project:** Remote Center ERP  
**Scope:** Purchase Order → Purchase Receive (GRN) → Purchase Return  
**Purpose:** Actionable roadmap for all **remaining** purchase work after Phases 1–6 (modernization + GL).  
**Created:** June 2026  
**Based on:** Codebase audit, [PURCHASE_MODULE_REFERENCE.md](./PURCHASE_MODULE_REFERENCE.md), [SALES_MODULE_JOURNEY_REVIEW.md](./SALES_MODULE_JOURNEY_REVIEW.md), [ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md)

**Related docs (already complete — do not redo):**

- [PURCHASE_MODULE_MODERNIZATION_PLAN.md](./PURCHASE_MODULE_MODERNIZATION_PLAN.md) — Phases 1–5 (security, UX, GL wiring)
- [PURCHASE_MODULE_REFERENCE.md](./PURCHASE_MODULE_REFERENCE.md) — current behaviour reference

---

## How to use this document

1. Work **one phase at a time** (or one **session** within a long phase).
2. Mark checkboxes when done; add date + commit note in the **Changelog** at the bottom.
3. Run the **Verification gate** for that phase before starting the next.
4. A **session** = one focused dev pass (roughly 2–4 hours). Long phases are split so nothing requires a single marathon session.

---

## Executive summary

Phases **1–6** delivered: CSRF, modern UI, UserAudit, reversibility, `JournalPostingService` integration, supplier sub-ledger, stock SSOT, and `PurchaseAudit/checklist`.

**Remaining work** falls into nine phases (7–15), ordered by **risk first** (branch isolation, status integrity, accounting parity), then **operations** (reports, UX), then **maintainability** (tests, docs).

| Phase | Theme | Sessions | Priority |
|-------|--------|----------|----------|
| **7** | Branch isolation on list queries | 1 | Critical |
| **8** | GRN `returned` / `partial_returned` status | 1 | High |
| **9** | Damage return → auto damage write-off (Sales C1 parity) | 2 | High |
| **10** | Return cost / GL / stock rate alignment | 2 | Medium |
| **11** | AP reconciliation visibility for purchase | 2 | Medium |
| **12** | Purchase history reports | 2 | Medium |
| **13** | UX parity with Sales (reverse/cancel confirm) | 3 | Low–Medium |
| **14** | Test harness & code hygiene | 2 | Low |
| **15** | Documentation sync | 1 | Low |

**Optional future (not scheduled):** Phase 16 — two-phase purchase return (pending → confirm); Phase 17 — PO in-transit soft reservation.

---

## Part A — Completed baseline (Phases 1–6)

Do **not** re-implement these; they are the foundation.

| Phase | Status | Delivered |
|-------|--------|-----------|
| 1 | ✅ | CSRF, `journal_entry_id` columns, basic UserAudit |
| 2 | ✅ | Moving average, `returned_qty`, direct purchase, AP-on-GRN decision |
| 3 | ✅ | Server-side DataTables, mobile cards, modern forms |
| 4 | ✅ | Rich audit viewers, GRN cancel, return reverse, migration 005 |
| 5 | ✅ | `postPurchaseReceive`, `postPurchaseReturn`, reversal journals |
| 6 | ✅ | GRN cancel ledger + PO `received_qty` fix, stock pre-check, return reverse ledger |

**Open from reference §5.4:** item #4 (branch filter on lists) → **Phase 7**.

---

## Part B — Gap inventory (what this plan fixes)

| ID | Gap | Severity | Phase |
|----|-----|----------|-------|
| G1 | PO/GRN/Return DataTables show all branches; writes stamp session `branch_id` | Critical | 7 |
| G2 | GRN `status = 'returned'` never set; `?returned=1` view empty | High | 8 |
| G3 | Purchase **Damage** return reduces AP but no stock OUT / no linked Damage doc | High | 9 |
| G4 | Return GL uses header `total_amount` (receive rates); stock OUT uses current moving avg | Medium | 10 |
| G5 | No purchase-facing reconcile surface (AP exists in `ReconciliationService` but not linked from purchase UI) | Medium | 11 |
| G6 | `Report/PurchaseHistory`, `PurchaseReturnHistory` documented but missing | Medium | 12 |
| G7 | Return reverse AJAX-only; no stock-preview confirm page | Low | 13 |
| G8 | PO cancel routed through `delete()` | Low | 13 |
| G9 | Purchase uses `StockTransactionModel` directly; no smoke tests | Low | 14 |
| G10 | `PURCHASE_MODULE_MODERNIZATION_PLAN.md` header stale (“Phase 4”, “0.0/10 GL”) | Low | 15 |

---

## Part C — Phase-by-phase execution plan

---

### Phase 7 — Branch isolation on list queries

**Goal:** List views and summaries only show the **session branch**, matching Sales godown/challan scoping.

**Priority:** Critical  
**Estimated effort:** 1 session  
**Depends on:** Nothing

#### Tasks

| # | Task | Files |
|---|------|-------|
| 7.1 | Add `WHERE po.branch_id = :branch_id` to `getPurchaseOrdersForDataTable` | `app/models/PurchaseOrderModel.php` |
| 7.2 | Add branch filter to `getPurchaseReceivesForDataTable` | `app/models/PurchaseReceiveModel.php` |
| 7.3 | Add branch filter to `getPurchaseReturnsForDataTable` + `getReturnFilterSummary` | `app/models/PurchaseReturnModel.php` |
| 7.4 | Pass `branch_id` from controllers on AJAX datatable requests (use `Helper::sessionBranchId()`) | `PurchaseOrderController`, `PurchaseReceiveController`, `PurchaseReturnController` |
| 7.5 | Admin “all branches” override (optional): only if `$_SESSION['role'] === 'admin'` and explicit `?branch_id=` — defer if not needed | Controllers |

#### Verification gate

- [ ] Log in as branch A user → PO/GRN/Return indexes show **only** branch A rows.
- [ ] Create GRN in branch A → appears in branch A list; not visible when session switched to branch B (if multi-branch test env).
- [ ] `PurchaseAuditModel` branch filter unchanged (already scoped).
- [ ] Update [PURCHASE_MODULE_REFERENCE.md](./PURCHASE_MODULE_REFERENCE.md) §5.4 #4 → **Fixed**.

---

### Phase 8 — GRN return status lifecycle

**Goal:** GRN header `status` reflects returns so filters, badges, and audit are truthful.

**Priority:** High  
**Estimated effort:** 1 session  
**Depends on:** Phase 7 (recommended, not blocking)

#### Tasks

| # | Task | Files |
|---|------|-------|
| 8.1 | After `createReturn`, recompute GRN status: `received` → `partial_returned` if any `returned_qty > 0` but not all lines fully returned; `returned` if all lines `returned_qty >= qty` | `app/models/PurchaseReturnModel.php` (new `updateGrnReturnStatus($receiveId)`) |
| 8.2 | On `reversePurchaseReturn`, re-run status helper (may revert to `received` or `partial_returned`) | `PurchaseReturnModel.php` |
| 8.3 | Exclude `partial_returned` / `returned` from default GRN index; include in `?returned=1` mode | `PurchaseReceiveModel.php`, `purchase-receive-index.js` |
| 8.4 | Status badge CSS for `partial_returned` | `purchase-index.css`, `purchase-receive-index.js` |
| 8.5 | Add audit rule: GRN with `returned_qty` sum > 0 but status still `received` | `PurchaseAuditModel.php` |

#### Verification gate

- [ ] Full return on GRN → status `returned`; appears under **Returned GRNs** view.
- [ ] Partial return → status `partial_returned`; excluded from default list, visible in returned view.
- [ ] Reverse return → status recalculated correctly.
- [ ] Cancel GRN still blocked when active returns exist (unchanged guard).

---

### Phase 9 — Damage return parity (mirror Sales C1 / W5)

**Goal:** When a purchase return line is **Damage**, auto-create a linked **Damage** write-off so physical stock and shrinkage GL match the AP reduction.

**Priority:** High  
**Estimated effort:** 2 sessions  
**Depends on:** Phase 8 (status helper should run after return either way)

This phase is **too large for one session** — split as below.

#### Session 9A — Model & stock/GL logic

| # | Task | Files |
|---|------|-------|
| 9A.1 | Study `SalesReturnModel::confirmReturn` damage path + `createLinkedDamageWriteOff` | `app/models/SalesReturnModel.php`, `app/models/DamageModel.php` |
| 9A.2 | On `createReturn`, for `condition = 'damage'`: stock IN at avg (goods received back) then immediate Damage OUT OR direct Damage OUT at avg — **match sales pattern** (receive then write-off) | `PurchaseReturnModel.php` |
| 9A.3 | Auto-create `damage_invoices` linked to `purchase_return_id` (add `source_type` / `source_id` columns if missing, or reuse existing link pattern from sales migration) | `DamageModel.php`, new migration if needed |
| 9A.4 | Post shrinkage GL via `JournalPostingService::postDamage` inside same transaction | `PurchaseReturnModel.php` |
| 9A.5 | Guard: Damage return still debits AP via existing `postPurchaseReturn` — verify net GL effect is correct (AP down, inventory net correct after damage) | Manual test notes |

#### Session 9B — UI, audit & cross-links

| # | Task | Files |
|---|------|-------|
| 9B.1 | `getLinkedDamageInvoices($returnId)` on purchase return details | `PurchaseReturnModel.php`, `PurchaseReturn/details.php` |
| 9B.2 | Cross-link Damage list/details ↔ Purchase Return (mirror sales return ↔ damage) | `app/views/Damage/details.php`, `PurchaseReturn/details.php` |
| 9B.3 | Operator note on return create form: Damage lines trigger auto write-off | `PurchaseReturn/create.php`, `partials/create_workspace.php` |
| 9B.4 | `PurchaseAuditModel` rule: damage return without linked damage doc = fail | `PurchaseAuditModel.php` |
| 9B.5 | Update [PURCHASE_MODULE_REFERENCE.md](./PURCHASE_MODULE_REFERENCE.md) §4.4 Damage row | Reference doc |

#### Verification gate

- [ ] Good return: stock OUT only (unchanged).
- [ ] Damage return: linked `damage_invoices` row exists; stock net correct; shrinkage GL posted.
- [ ] Return reverse: reverses damage + stock + GL chain.
- [ ] Details page shows linked damage badge with link.

---

### Phase 10 — Return cost / GL / stock rate alignment

**Goal:** Reversal and inventory GL stay consistent when moving average drifts after GRN (mirror Sales W1 `issue_rate` fix).

**Priority:** Medium  
**Estimated effort:** 2 sessions  
**Depends on:** Phase 9 complete (damage path complicates costing)

#### Session 10A — Design & schema

| # | Task | Files |
|---|------|-------|
| 10A.1 | **Decision:** Store `movement_rate` on `purchase_return_items` (rate used for stock log) and optionally `gl_rate` — document in plan changelog | This doc + reference |
| 10A.2 | Migration: `purchase_return_items.movement_rate DECIMAL(15,4) NULL` (and `purchase_receive_items` if needed for cancel rate) | `database/migrations/` |
| 10A.3 | Decide GL rule: (A) GL uses stored movement_rate sum, or (B) keep header `total_amount` but reconcile drift in audit — **recommend (A) for Good returns** | Design note in reference |

#### Session 10B — Implementation

| # | Task | Files |
|---|------|-------|
| 10B.1 | Persist `movement_rate` at return create (current avg or receive rate fallback) | `PurchaseReturnModel.php` |
| 10B.2 | `postPurchaseReturn` amount = sum of `return_qty * movement_rate` (or pass computed total from model) | `JournalPostingService.php`, `PurchaseReturnModel.php` |
| 10B.3 | `reversePurchaseReturn` restores stock at stored `movement_rate`, not live avg | `PurchaseReturnModel.php` |
| 10B.4 | `PurchaseAuditModel`: flag returns where `SUM(qty * movement_rate)` ≠ `total_amount` beyond tolerance | `PurchaseAuditModel.php` |

#### Verification gate

- [ ] Return after price-changing sales/challan: GL inventory credit = stock movement value at return time.
- [ ] Reverse return restores stock at same rate as original OUT.
- [ ] Trial Balance still balances.

---

### Phase 11 — AP reconciliation visibility for purchase

**Goal:** Accountants can verify supplier sub-ledger ↔ GL from the purchase ecosystem (service already supports AP in `runFullReport`).

**Priority:** Medium  
**Estimated effort:** 2 sessions  
**Depends on:** Phases 7–10 stable data

#### Session 11A — Purchase reconcile surface

| # | Task | Files |
|---|------|-------|
| 11A.1 | Add `PurchaseAudit/reconcile` or link from `PurchaseAudit/checklist` to AP section of `ReconciliationService::runFullReport` | `PurchaseAuditController.php`, new view partial |
| 11A.2 | Show: supplier_ledger net, GL `supplier_payable` net, difference, top mismatches | View + `ReconciliationService.php` (read-only) |
| 11A.3 | Drill-down links: mismatch → `Report/GeneralLedger`, supplier → `Accounting/supplier/details` | View |
| 11A.4 | Route role: admin, manager, accountant | `app/config/route_roles.php` |

#### Session 11B — Purchase-specific audit checks

| # | Task | Files |
|---|------|-------|
| 11B.1 | Checklist section: GRNs with `journal_entry_id` but no matching `supplier_ledger` `purchase` row | `PurchaseAuditModel.php` |
| 11B.2 | Checklist section: returns with ledger debit but no GL | `PurchaseAuditModel.php` |
| 11B.3 | Optional: include AP reconcile snapshot in `PurchaseAudit/run_checks` JSON | `PurchaseAuditController.php` |
| 11B.4 | Link from `Accounting/index` hub if present | `app/views/Accounting/index.php` |

#### Verification gate

- [ ] Create GRN + return + supplier payment → AP reconcile shows within tolerance.
- [ ] Deliberate test mismatch (staging) → checklist flags it.
- [ ] Update `ACCOUNTING_MASTER_PLAN.md` X-02 note if AP is now surfaced for purchase.

---

### Phase 12 — Operational reports

**Goal:** Deliver reports promised in [PURCHASE_MODULE_REFERENCE.md](./PURCHASE_MODULE_REFERENCE.md) §1.

**Priority:** Medium  
**Estimated effort:** 2 sessions  
**Depends on:** Phase 7 (branch scoping)

#### Session 12A — Purchase History (GRN register)

| # | Task | Files |
|---|------|-------|
| 12A.1 | `PurchaseHistoryReport` model: GRN lines by date range, supplier, branch, product | `app/models/Reports/PurchaseHistoryReport.php` |
| 12A.2 | `ReportController::PurchaseHistory`, view + export CSV | Controller, `app/views/Report/PurchaseHistory.php` |
| 12A.3 | Register in `ReportsCatalog.php` + `route_roles.php` | Config/helpers |

#### Session 12B — Purchase Return History

| # | Task | Files |
|---|------|-------|
| 12B.1 | `PurchaseReturnHistoryReport` model | `app/models/Reports/PurchaseReturnHistoryReport.php` |
| 12B.2 | `ReportController::PurchaseReturnHistory`, view + export | Controller, view |
| 12B.3 | Filters: date, supplier, status (active/reversed), branch | View + JS |
| 12B.4 | Link rows to `PurchaseReceive/details`, `PurchaseReturn/details` | View |

#### Verification gate

- [ ] Reports respect session branch (or explicit branch filter for admin).
- [ ] Totals match sum of GRN/return headers for same filters.
- [ ] Export opens in Excel; UTF-8 OK.

---

### Phase 13 — UX parity with Sales

**Goal:** Operators see **what will happen** before destructive actions; clearer PO cancel API.

**Priority:** Low–Medium  
**Estimated effort:** 3 sessions  
**Depends on:** Phases 8–9 (status + damage banners)

This phase is **long** — three sessions.

#### Session 13A — Return reverse confirmation page

| # | Task | Files |
|---|------|-------|
| 13A.1 | New route `PurchaseReturn/reverse_confirm/{id}` — read-only preview: lines, stock IN qty/rate, GL reverse, ledger effect | Controller, `app/views/PurchaseReturn/reverse_confirm.php` |
| 13A.2 | Index AJAX reverse → navigate to confirm page (POST on confirm) | `purchase-return-index.js`, controller |
| 13A.3 | Mirror patterns from `SalesReturn/confirm.php` (stock table, reason field) | Views, CSS |

#### Session 13B — GRN cancel confirmation

| # | Task | Files |
|---|------|-------|
| 13B.1 | `PurchaseReceive/cancel_confirm/{id}` — show stock OUT impact, active returns guard, linked PO | Controller, view |
| 13B.2 | Details page Cancel → confirm page instead of immediate POST | `PurchaseReceive/details.php` |

#### Session 13C — PO cancel route & journey links

| # | Task | Files |
|---|------|-------|
| 13C.1 | Add `PurchaseOrder/cancel` POST route (reason required); keep `delete` for draft hard-delete only | `PurchaseOrderController.php`, `route_roles.php` |
| 13C.2 | Update JS/forms to call `cancel` instead of `delete` with reason | `purchase-order-index.js`, views |
| 13C.3 | Supplier `show` page: recent GRNs + returns cross-links (if not already) | `app/views/supplier/show.php` |
| 13C.4 | Purchase guide section (mirror `sales/guide.php`) | `app/views/PurchaseOrder/` or shared accounting guide |

#### Verification gate

- [ ] Reverse/cancel confirm shows accurate stock preview from current DB state.
- [ ] PO cancel with reason does not use `delete` endpoint.
- [ ] CSRF + role checks on new routes.

---

### Phase 14 — Test harness & code hygiene

**Goal:** Catch regressions; align with Sales service patterns.

**Priority:** Low  
**Estimated effort:** 2 sessions  
**Depends on:** Phases 7–11

#### Session 14A — Smoke test script

| # | Task | Files |
|---|------|-------|
| 14A.1 | `database/tests/purchase_core_smoke.php` — assert models load, `postPurchaseReceive`/`postPurchaseReturn` exist, branch filter keys present | New script |
| 14A.2 | Optional integration stub: create GRN in test DB → check journal lines balance (behind env flag) | Same script |
| 14A.3 | Document run command in script header | README in `database/tests/` |

#### Session 14B — Small code hygiene

| # | Task | Files |
|---|------|-------|
| 14B.1 | Replace `rand(1000,9999)` return codes with date sequence (match GRN pattern) | `PurchaseReturnModel.php` |
| 14B.2 | Optional: thin `StockService($this->db)` wrapper in receive/return models instead of raw `StockTransactionModel` — **only if constructor sharing is needed**; skip if diff noise high | Purchase models |
| 14B.3 | Remove stale "Future Phase 5" comments in models | `PurchaseReturnModel.php`, etc. |

#### Verification gate

- [ ] `php database/tests/purchase_core_smoke.php` exits 0 on staging.
- [ ] New return codes unique per day under concurrent creates (manual or script).

---

### Phase 15 — Documentation sync

**Goal:** All purchase docs reflect post–Phase 15 reality.

**Priority:** Low  
**Estimated effort:** 1 session  
**Depends on:** Phases 7–14 complete

#### Tasks

| # | Task | Files |
|---|------|-------|
| 15.1 | Update `PURCHASE_MODULE_MODERNIZATION_PLAN.md` header: Phases 1–6 complete, GL live, score 9+/10 | Modernization plan |
| 15.2 | Close all items in `PURCHASE_MODULE_REFERENCE.md` §5.4; add Phase 7–14 changes | Reference |
| 15.3 | Add pointer in `ACCOUNTING_MASTER_PLAN.md` Phase 5B follow-ups if reconcile UI added | Accounting plan |
| 15.4 | Mark this document phases complete in Changelog below | This file |

#### Verification gate

- [ ] No doc still says "0.0/10 GL readiness" or "Phase 4 in progress".
- [ ] Reference §1 reports list matches `ReportsCatalog`.

---

## Part D — Optional future phases (not in current scope)

### Phase 16 — Two-phase purchase return (pending → warehouse confirm)

**Why defer:** Large behavioural change; current single-phase return is functionally correct.

| Session | Scope |
|---------|--------|
| 16A | Schema: `purchase_returns.status` (`pending`, `confirmed`); create without stock/GL |
| 16B | `confirmReturn` endpoint + warehouse validation (mirror `SalesReturnModel`) |
| 16C | UI split create vs confirm; update audit + checklist |

### Phase 17 — PO in-transit soft reservation

**Why defer:** Sales journey review lists this as system-wide optional enhancement.

| Session | Scope |
|---------|--------|
| 17A | Design `purchase_order_dispatches` or equivalent pipeline table |
| 17B | Subtract expected PO qty from `StockAvailabilityService` (optional branch setting) |

---

## Part E — Recommended implementation order

```
Phase 7  → Phase 8  → Phase 9  → Phase 10
                ↓
         Phase 11 → Phase 12
                ↓
         Phase 13 → Phase 14 → Phase 15
```

**Rationale:**

1. **7 + 8** — data visibility and status truth (low risk, high ops value).
2. **9 + 10** — accounting/stock parity with Sales (fixes real book vs physical gaps).
3. **11 + 12** — accountant and manager visibility.
4. **13–15** — polish, tests, docs.

---

## Part F — Session quick reference

| Session | Phase | Focus |
|---------|-------|-------|
| **7** | 7 | Branch filters on all purchase lists |
| **8** | 8 | GRN returned / partial_returned status |
| **9A** | 9 | Damage return auto write-off (model) |
| **9B** | 9 | Damage return UI + audit |
| **10A** | 10 | Return rate schema + design |
| **10B** | 10 | Return rate implementation |
| **11A** | 11 | Purchase AP reconcile UI |
| **11B** | 11 | Purchase audit AP checks |
| **12A** | 12 | Purchase History report |
| **12B** | 12 | Purchase Return History report |
| **13A** | 13 | Return reverse confirm page |
| **13B** | 13 | GRN cancel confirm page |
| **13C** | 13 | PO cancel route + journey links |
| **14A** | 14 | Smoke tests |
| **14B** | 14 | Return codes + comment cleanup |
| **15** | 15 | Doc sync |

**Total:** 16 sessions across 9 phases (7–15).

---

## Part G — Key files by area

| Area | Path |
|------|------|
| PO | `app/models/PurchaseOrderModel.php`, `app/controllers/PurchaseOrderController.php` |
| GRN | `app/models/PurchaseReceiveModel.php`, `app/controllers/PurchaseReceiveController.php` |
| Return | `app/models/PurchaseReturnModel.php`, `app/controllers/PurchaseReturnController.php` |
| Audit | `app/models/PurchaseAuditModel.php`, `app/controllers/PurchaseAuditController.php` |
| GL | `app/services/Accounting/JournalPostingService.php` |
| Reconcile | `app/services/Accounting/ReconciliationService.php` |
| Stock SSOT | `app/models/StockTransactionModel.php`, `app/services/Stock/StockAvailabilityService.php` |
| Damage (Phase 9) | `app/models/DamageModel.php` |
| Sales reference | `app/models/SalesReturnModel.php` (C1 pattern) |
| Reports | `app/helpers/ReportsCatalog.php`, `app/controllers/ReportController.php` |

---

## Part H — Definition of done (whole purchase improvement program)

The purchase module matches Sales + Accounting maturity when:

| # | Criterion |
|---|-----------|
| 1 | All list queries scoped to session branch (unless admin override). |
| 2 | GRN status reflects return state (`received` / `partial_returned` / `returned` / `cancelled`). |
| 3 | Damage returns auto-link Damage write-off (physical + GL = financial). |
| 4 | Return/reversal rates stored and used consistently for stock + GL. |
| 5 | AP reconcile reachable from purchase/accounting UI; checklist covers ledger ↔ GL. |
| 6 | Purchase History + Purchase Return History reports live. |
| 7 | Destructive actions have confirm previews (return reverse, GRN cancel). |
| 8 | `purchase_core_smoke.php` passes; docs synced. |

---

## Changelog

| Date | Phase | Notes |
|------|-------|-------|
| 2026-06-20 | — | Initial remaining-phases plan created from audit vs Sales + Accounting docs |

---

*When a phase completes, check its boxes, run the verification gate, and add a row to the Changelog.*
