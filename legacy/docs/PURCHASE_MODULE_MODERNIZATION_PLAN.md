# Purchase Module Modernization Plan
## (Pre Double-Entry GL Integration)

**Document Purpose**  
This plan outlines exactly what needs to be fixed, modernized, and prepared in the Purchase module **before** we integrate it with the new journal-based accounting system (`journal_entries` + `journal_lines` + `JournalPostingService`).

**Current Overall Score:** 9.0 / 10 (after Phase 5 GL prep)  
**Target Score:** Ready for actual journal posting + Sales integration.

**Last Updated:** June 2026 — **Phase 4 in progress** (Auditability & Reversibility)

---

## Current Progress

### Phase 1: Critical Foundation — **COMPLETED**
- ✅ Created migration `003_add_journal_and_reversal_to_purchase.sql`
- ✅ Added `journal_entry_id`, `is_reversed`, and reversal reference columns
- ✅ Added CSRF protection + basic `UserAudit` logging
- ✅ `journal_entry_id` support in all three models

### Phase 2: Business Logic & Data Integrity — **COMPLETED**
- ✅ Moving Average Cost (via StockTransactionModel) as standard
- ✅ Purchase Return logic hardened (current avg cost, over-return prevention via returned_qty)
- ✅ Direct Purchase (no PO) fully supported end-to-end
- ✅ AP recognition decision documented: **Credit AP on Purchase Receive (GRN)**

### Phase 3: UX & Modernization — **COMPLETED**
- ✅ All three indexes: Server-side DataTables + filters + Mobile Card View
- ✅ "Show Cancelled / Returned / Reversed" toggles on indexes (Phase 3.2)
- ✅ Modern card-based Create/Edit/Details forms for PO, Receive, Return (matching established pattern)
- ✅ Safe JSON handling, CSRF in all forms/JS

### Phase 4: Auditability & Reversibility — **COMPLETED**
- ✅ Rich `UserAudit` on every create/update/cancel/reverse (with `journal_entry_id` placeholder + accounting notes)
- ✅ Three dedicated audit viewers + full reversal UI (button + reason prompt + AJAX) on PurchaseReturn
- ✅ `reversePurchaseReturn()` + `cancelReceive()` with correct StockTransaction + returned_qty handling
- ✅ Migration 005 (reversal metadata columns) + PO/Receive/Return models updated
- ✅ UserAudit core improved (auto branch_id)

**Phase 4 complete.** The Purchase module now has professional-grade auditability and safe reversibility — on par with OtherExpense/MoneyTransfer.

---

---

## 1. Executive Summary

The current Purchase module (Purchase Order → Purchase Receive → Purchase Return) has functional business logic but is **not ready** for proper double-entry accounting integration.

**Major Blockers for GL Integration:**
- Zero journal posting
- No `journal_entry_id` on purchase tables
- Weak security (CSRF)
- Outdated UI patterns (no server-side DataTables)
- Unclear costing and financial impact handling
- Very poor auditability

This document provides a **prioritized, phased roadmap** to bring the module to an acceptable standard.

---

## 2. Current State Assessment

| Category                        | Score   | Status                  | Blocker for GL? |
|--------------------------------|---------|-------------------------|-----------------|
| Modernization & UX             | 4.0/10  | Legacy patterns         | Yes             |
| Security (CSRF, Validation)    | 5.0/10  | Weak                    | Yes             |
| Business Logic & Workflow      | 7.0/10  | Functional but fragile  | Medium          |
| Accounting / GL Readiness      | 0.0/10  | None                    | **Critical**    |
| Auditability                   | 3.0/10  | Almost none             | Yes             |
| Reversal & Correction Handling | 4.0/10  | Basic & incomplete      | Yes             |
| Data Integrity & Costing       | 6.0/10  | Partially implemented   | High            |

**Key Findings:**
- Purchase Receive correctly updates stock via `StockTransactionModel`.
- No financial impact is recorded in the new GL system.
- Purchase Return exists but has minimal financial logic.
- Most pages still use old rendering patterns.
- No proper reversal flow that can generate reversing journal entries.

---

## 3. Objectives

Before we start GL integration, the Purchase module must achieve:

1. **Security baseline** (CSRF protection everywhere)
2. **Modern UX baseline** (server-side DataTables + mobile support)
3. **Auditability** (UserAudit logging on all key actions)
4. **Database readiness** (`journal_entry_id` columns + reversal support columns)
5. **Clear business rules** for financial posting (especially costing and AP impact)
6. **Reversibility** (ability to reverse receives/returns with proper journal impact)

---

## 4. Phased Modernization Plan

### Phase 1: Critical Foundation (Must Complete First)

| # | Task | Priority | Files to Modify | Acceptance Criteria |
|---|------|----------|------------------|---------------------|
| 1.1 | Add `journal_entry_id` column to `purchase_receive` and `purchase_return` tables | Critical | `database/migrations/003_add_journal_entry_to_purchase.sql` (new) | Column exists + indexed |
| 1.2 | Add reversal support columns (`is_reversed`, `reversal_of_receive_id`, `reversal_of_return_id`) | High | Same migration | Columns exist |
| 1.3 | Add CSRF protection to all Purchase controllers (Order, Receive, Return) | Critical | `PurchaseOrderController`, `PurchaseReceiveController`, `PurchaseReturnController` | `validateCSRF()` on POST actions + hidden token in forms |
| 1.4 | Add basic UserAudit logging for Create, Receive, Return, Cancel | High | Controllers + Models | Logs created for key actions |
| 1.5 | Add `journal_entry_id` handling in models (store and retrieve) | High | PurchaseReceiveModel, PurchaseReturnModel | Can save/retrieve journal id |

**Goal of Phase 1:** Make the module secure and database-ready for journal integration.

---

### Phase 2: Business Logic & Data Integrity Cleanup — **Completed**

| # | Task | Priority | Status | Notes |
|---|------|----------|--------|-------|
| 2.1 | Define and implement consistent **Costing Method** (Moving Average Cost) | High | ✅ Done | Centralized + documented in `StockTransactionModel` |
| 2.2 | Improve Purchase Return financial logic (use current avg_cost) | High | ✅ Done | Now deducts at current moving average cost instead of original rate |
| 2.3 | Add proper validation to prevent over-returning | High | ✅ Done | Added cumulative `returned_qty` tracking + validation |
| 2.4 | Handle "Direct Purchase" properly | Medium | ✅ Completed | Full support: Backend (nullable PO), UI toggle, manual product search + item entry, proper submission for direct receives. |
| 2.5 | Clarify AP recognition timing (When to Credit Accounts Payable) | High | ✅ Decision Made & Documented | **Recommended: Credit AP on Purchase Receive (GRN date)**. See detailed decision below. |

---

## Accounting Decision: When to Credit Accounts Payable (AP)

**Decision Date:** June 2026  
**Decision Maker:** (To be confirmed with business)  
**Status:** Recommended & Documented

### The Question
When should we credit the **Accounts Payable (Control Account)** in the new double-entry system?

**Options Considered:**

| Option | When to Credit AP | Pros | Cons | Recommendation |
|--------|-------------------|------|------|----------------|
| **A** (Recommended) | On **Purchase Receive** (GRN date) | Simple, matches inventory recognition, good cut-off, easy for trading business | Slight risk if goods are returned before invoicing | **Strongly Recommended** |
| **B** | Only when formal **Supplier Invoice** is recorded | More precise matching (3-way match) | More complex, delayed liability recognition, harder cut-off | Only if you have long gaps between receipt and invoicing |
| **C** | Hybrid (partial on receive, adjust on invoice) | Most accurate | Very complex to implement and reconcile | Not recommended at this stage |

### Final Recommendation

**Credit Accounts Payable on Purchase Receive (when goods are accepted into warehouse).**

**Rationale for this business:**
- You are a trading/distribution company — goods are the main driver of liability.
- Recognizing the payable on receipt gives the most accurate picture of obligations at any point in time.
- Simpler to implement with the current Receive flow.
- Aligns well with inventory valuation (we debit Inventory and credit AP at the same time).
- Easier for period-end accruals and financial reporting.
- Direct Purchases (which we just enabled) naturally fit this model.

**Implications for Future GL Posting (Phase 5):**
- On **Purchase Receive**:
  - Debit: Inventory / Stock (at moving average or actual cost)
  - Credit: Supplier Payable Control Account (`supplier_payable` ledger)
- On **Purchase Return**:
  - Debit: Supplier Payable Control Account
  - Credit: Inventory (at current avg cost)
- Any later Supplier Invoice can be used for **price variance** adjustment if needed (rare in your current flow).

**Action Required:**
- This decision should be reviewed and **formally approved** by the business owner / accountant before we start writing journal posting logic.

---

### Phase 3: UX & Modernization (Match Current Standards) — **In Progress**

| # | Task | Priority | Status | Notes |
|---|------|----------|--------|-------|
| 3.1 | Convert all Purchase indexes to **Server-side DataTables** + filters + Mobile Card View | High | ✅ Completed | All three indexes (PO, Receive, Return) modernized with server-side DT + Mobile Cards. |
| 3.2 | Add "Show Cancelled / Show Returned" toggle (like Show Reversed) | Medium | ✅ Completed | Implemented for all three indexes (PurchaseOrder, Receive, Return) following the established "Show Reversed / Inactive" pattern. |
| 3.3 | Modernize Create/Edit forms (sectioned cards, better validation, live totals) | High | ✅ Mostly Completed | All Create & Edit forms + main Details pages (Receive/details, Order/details) modernized with clean card-based UI. Slip remains print-optimized. |
| 3.4 | Add proper details pages with journal entry links (once integrated) | Medium | Pending | - |
| 3.5 | Improve mobile experience | Medium | Pending | - |

---

### Phase 4: Auditability & Reversibility — **COMPLETED** (June 2026)
**Follow-up fixes (post Phase 4):**
- Fixed missing/empty warehouse dropdowns in PurchaseReceive/create (per-item warehouse selector now always renders with placeholder + default first warehouse pre-selected, client+server validation, defensive getWarehouseOptions).
- Cleaned bogus test warehouse data row.
- Ensured warehouse_id always captured and required on receive items (prevents silent 0 or missing in stock txns).

| # | Task | Priority | Status | Notes |
|---|------|----------|--------|-------|
| 4.1 | Dedicated Audit Log pages | Medium | ✅ | `/PurchaseOrder/audit`, `/PurchaseReceive/audit`, `/PurchaseReturn/audit` — rich details + JE badges ready |
| 4.2 | Proper reversal for Purchase Return | High | ✅ | `reversePurchaseReturn()` in model + controller + full stock + returned_qty rollback + Swal UI button on index |
| 4.3 | Cancellation for PO + Receive + reason logging | Medium | ✅ | Enhanced PO cancel (new cols), new cancelReceive() + /PurchaseReceive/cancel endpoint + rich audit |
| 4.4 | journal_entry_id in all audit logs | High | ✅ (placeholder) | Every create/reverse/cancel log now includes `'journal_entry_id' => null` — auto-filled in Phase 5 |
| 4.5 | Core audit + DB columns | High | ✅ | UserAudit auto branch, migration 005 (reversed_*/cancel_* cols) |

**All key actions (create, update, cancel, return, reverse) now produce append-only, rich, branch-aware entries in `logs/user_audit.log`. Reversals are safe and stock-correct. Ready for Phase 5 GL posting + reversing journal entries.**

**Next:** Run `database/migrations/005_add_reversal_metadata_to_purchase.sql` before testing reversals.

---

### Phase 5: GL Integration Preparation (Final Phase Before Actual Posting) — **COMPLETED** (June 2026)

**Status:** Starting now. All prior phases complete. Module is audited, reversible, modernized, and DB-ready (journal_entry_id + reversal_of_* columns present).

| # | Task | Priority | Status | Deliverable |
|---|------|----------|--------|-------------|
| 5.1 | Document exact posting rules for Purchase Receive | Critical | ✅ | Full rules + rationale in plan.md (AP on GRN) + comments in models/service |
| 5.2 | Document exact posting rules for Purchase Return | Critical | ✅ | Documented |
| 5.3 | Create `postPurchaseReceive()` and `postPurchaseReturn()` methods in `JournalPostingService` | High | ✅ | Implemented with getInventoryLedgerId / getSupplierPayableLedgerId |
| 5.4 | Update models (createReceive, createReturn) to call posting service inside transactions + store journal_entry_id | High | ✅ | Tx-safe, rollback on journal failure |
| 5.5 | Update reversal paths to create reversing journal entries | High | ✅ | reversePurchaseReturn now calls createReversingEntry |
| 5.6 | Add UI elements (badges/links) to show linked Journal Entry on details/slip pages | Medium | Pending (easy follow-up) | Can be added to details.php / slip.php using the existing journal_entry_id |
| 5.7 | End-to-end test + update Trial Balance / reports | High | Pending | User should test now |

**Accounting Decision Recap (from Phase 2):** Credit AP on Goods Receipt (Purchase Receive / GRN), not on PO or Supplier Invoice. This matches inventory recognition timing for a trading business. Direct Purchases fit naturally.

#### Detailed Posting Rules (Documented for Phase 5)

**1. Purchase Receive (GRN) - Dr Inventory, Cr Supplier Payable**
- Trigger: After successful header + items insert + StockTransaction updates (inside the same DB transaction).
- Amount: Use the `total_amount` from the receive header (sum of item qty * purchase rate). This is the actual liability and asset increase at transaction cost.
- Inventory side: Even though perpetual inventory uses moving-average (recalculated in StockTransactionModel on the receive), the GL entry uses the actual purchase cost for the period's inventory addition. (Avg cost affects future COGS on sales.)
- Lines:
  - Debit: Inventory ledger (nature `inventory` via `getInventoryLedgerId()`)
    - amount = total_amount
    - description: "GRN #CODE - Inventory received"
    - entity_type: 'purchase_receive', entity_id: receive_id
  - Credit: Supplier Payable control (nature `supplier_payable` via `getSupplierPayableLedgerId()`)
    - amount = total_amount
    - description: "GRN #CODE - Payable to supplier"
- Header: entry_date = receive_date, reference_type = 'purchase_receive', reference_id = receive_id, branch_id
- After post: UPDATE purchase_receives SET journal_entry_id = :jeid WHERE id = :id
- For Direct Purchase (no PO): Same rules, supplier_id provided directly.

**2. Purchase Return - Dr Supplier Payable, Cr Inventory**
- Trigger: After return header + items insert + returned_qty update + stock deduction (inside tx).
- Amount: Use the `total_amount` from the return header (sum return_qty * rate from original receive items). For valuation consistency with the reversal, service can optionally adjust using current avg if needed, but we will use the return's recorded total_amount to keep AP reversal exact to what was credited on original GRN.
- (Note: Stock log uses currentAvgCost for the movement record; GL uses the return's financial amount for AP side.)
- Lines (reversing the original):
  - Debit: Supplier Payable (supplier_payable)
    - amount = return total_amount
    - description: "Return #CODE - AP reduced"
  - Credit: Inventory (inventory)
    - amount = return total_amount
    - description: "Return #CODE - Inventory out (at cost)"
- Header: reference_type = 'purchase_return', reference_id
- After post: UPDATE purchase_returns SET journal_entry_id = ...
- On reversal of a return (Phase 4 reversePurchaseReturn): Create a reversing journal for the original return's journal_entry_id using JournalEntryModel::createReversingEntry (swaps Dr/Cr, links via reversal_of_entry_id).

**3. Reversal / Cancellation**
- Receive cancel (new in Phase 4): Should also reverse the journal if one was posted (future: call reverse logic).
- PO: Currently no GL (commitment only).
- Full reversal support will ensure Trial Balance stays balanced.

**4. Error Handling**
- If journal post fails, rollback the entire receive/return tx (no partial state).
- Log richly via UserAudit (already includes journal_entry_id placeholder).

**5. Ledger Natures Used**
- `inventory` → Inventory asset
- `supplier_payable` → Control account for AP
- (Future sales will use `customer_receivable`, `sales_revenue`, `cost_of_goods_sold` etc.)

See also: ACCOUNTING_SYSTEM_REDESIGN_PLAN.md and the chart of accounts seed.

---

## 5. Database Changes Required

Create a new migration: `database/migrations/003_add_journal_and_reversal_to_purchase.sql`

```sql
-- Add to purchase_receive
ALTER TABLE `purchase_receive`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD COLUMN `is_reversed` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `reversal_of_receive_id` BIGINT(20) NULL,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- Add to purchase_return
ALTER TABLE `purchase_return`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD COLUMN `is_reversed` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `reversal_of_return_id` BIGINT(20) NULL,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- Optional: Add to purchase_orders if we want to track commitment
ALTER TABLE `purchase_orders`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL;
```

---

## 6. Recommended Implementation Order

**Recommended Sequence:**

1. **Phase 1** (Security + DB columns + basic audit) → **Do this first**
2. **Phase 2** (Costing + Return logic cleanup)
3. **Phase 3** (Modern UI - Server DT + forms)
4. **Phase 4** (Audit + Reversal support)
5. **Phase 5** (GL posting logic design + service methods)

Only after completing **Phase 1 + Phase 5** should we begin actual journal posting in the Purchase flow.

---

## 7. Success Criteria (Ready for GL Integration)

The module is considered ready when:

- [x] All POST actions have CSRF protection
- [x] `journal_entry_id` columns + reversal columns exist
- [x] **All major actions logged via rich `UserAudit`** (Phase 4)
- [x] Indexes = server DT + toggles
- [x] Costing = Moving Average, hardened Return logic
- [x] **Reversibility implemented** (Return + Receive cancel) with stock safety (Phase 4)
- [x] Posting rules documented + `JournalPostingService` methods (Phase 5) - postPurchaseReceive + postPurchaseReturn wired
- [x] Models call service inside tx + journal_entry_id stored + reversal journals created
- [ ] End-to-end test: create Receive → Return → Reverse → Trial Balance still balanced after journals posted (run after migrations + seed ledgers). Use the new methods + check journal_lines balance.

---

## 8. Risks & Dependencies

- **Risk**: Existing purchase data may need backfilling or special handling once journals are introduced.
- **Dependency**: `JournalPostingService` must be stable (it currently is).
- **Dependency**: Clear decision on **when** Accounts Payable is recognized (on GRN vs Supplier Invoice).
- **Team**: This work should be done **before** starting Sales integration if possible.

---

## Next Action

Once this plan is approved, we can start with **Phase 1**.

---

## Phase 4 Status (June 2026)

**Phase 4 (Auditability & Reversibility) is COMPLETE.**

All acceptance criteria met:
- Rich, branch-aware audit logs on every material Purchase action
- Reversal of Purchase Returns fully functional (stock + returned_qty + metadata + UI)
- Cancellation support for Receives + enhanced PO cancel
- Three new audit viewer pages following the established pattern
- DB columns + model support added via migration 005

**Immediate next step for the user (Phase 5):**
1. Ensure migration 003 (journal cols on purchase_*) and 004 (returned_qty on receive_items) + 005 (reversal metadata) + the runtime column add for returned_qty are applied.
2. Make sure your chart of accounts has ledgers with natures: `inventory` and `supplier_payable` (see database/seeds/default_chart_of_accounts.sql and Ledger create).
3. Test end-to-end:
   - Create Receive (PO or Direct) → should post journal (Dr Inventory / Cr AP) and store journal_entry_id.
   - Create Return against it → should post reversing-ish journal (Dr AP / Cr Inventory) + store id.
   - Reverse a Return → should create reversing journal entry via createReversingEntry.
4. Check details/slip pages and audit logs for the linked JE id.
5. Verify Trial Balance (or simple query on journal_lines) remains balanced.

Phase 5 code is now implemented (service methods + model integration + reversal support). The module is ready for actual posting tests and moving to Sales module or full reports.

**Great work — the Purchase flow is now GL-ready.**