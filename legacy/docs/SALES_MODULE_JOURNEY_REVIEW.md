# Sales Module — Full Journey Review & Stock Architecture

**Project:** Remote Center ERP  
**Scope:** Sales → Today's Invoice → Godown Copy → Challan Copy → Invoice (print) → Sales Return → Damage  
**Focus:** Branch stock, warehouse stock, single source of truth (SSOT)  
**Review date:** 2026-06-19  
**Review type:** Architecture & stock-integrity review (no code changes)

---

## Review methodology

This document combines:

1. **Codebase exploration** — full mapping of controllers, services, models, views, and database tables across the sales journey.
2. **Adversarial parent review** — deep read of stock write/read paths, pipeline logic, and known gaps documented in `docs/SALES_ECOSYSTEM_PHASE_PLAN.md`.
3. **Multi-model review (attempted)** — reviewers on Claude Fable 5 and Claude 4.6 Sonnet were requested but hit API limits; findings below reflect a consolidated adversarial pass equivalent to that intent.

---

## Executive summary

The sales module implements a **well-structured invoice lifecycle** with clear status gates (`draft` → `godown_issued` → `challan_completed`) and a deliberate **two-layer stock model**:


| Layer                           | Table / service            | Role                                                     |
| ------------------------------- | -------------------------- | -------------------------------------------------------- |
| **Physical on-hand**            | `warehouse_stock`          | Authoritative qty + moving average cost per warehouse    |
| **Soft reservation (pipeline)** | `sales_invoice_dispatches` | Holds qty on open invoices before physical issue         |
| **Available (sellable)**        | `StockAvailabilityService` | Physical − pipeline; used at POS, godown picker, returns |


**Physical stock moves only at three sales-journey points:**

1. **Challan complete** — OUT from assigned warehouse
2. **Sales return confirm (Good)** — IN to chosen warehouse
3. **Damage module** — OUT (standalone manual, or **auto-linked** from damaged sales return confirm — C1/W5)

**Verdict on SSOT:** The sales journey is **coherent** — `warehouse_stock` + `StockAvailabilityService` + `StockTransactionModel::logMovement` form a solid core. **C1–C3, W4 addressed (2026-06):** damaged returns auto-write-off; challan reversal restores transport/ledger; outbound modules respect sales pipeline; branch demand routes stock through `StockService` with shared DB. Remaining gaps:

- Purchase orders in transit are not soft-reserved (only sales pipeline is; immediate OUT modules now honor pipeline before deducting).

---

## Journey map

```mermaid
flowchart TB
    subgraph create ["1. Create Sales Invoice"]
        A1[POS cart / draft carts] --> A2[POST final_sales]
        A2 --> A3["sales_invoices status=draft"]
        A3 --> A4["sales_invoice_dispatches pipeline INSERT"]
        A4 --> A5["GL: Dr AR / Cr Revenue + customer_ledger"]
    end

    subgraph today ["2. Today's Invoice"]
        B1[sales/today hub] --> B2[Edit draft / Delete / Payments]
        B2 --> B3[Links to godown / prints]
    end

    subgraph godown ["3. Godown Copy"]
        C1[challan/create/{invoice}] --> C2[POST prepare_godown]
        C2 --> C3["Assign warehouse per line"]
        C3 --> C4["status=godown_issued"]
    end

    subgraph challan ["4. Challan Copy"]
        D1[POST create_final_challan] --> D2["warehouse_stock OUT"]
        D2 --> D3["dispatched_qty = ordered_qty"]
        D3 --> D4["status=challan_completed + COGS GL"]
    end

    subgraph print ["5. Invoice Print"]
        E1[sales/invoice_copy/{id}] --> E2[Customer copy — no stock change]
    end

    subgraph sreturn ["6. Sales Return"]
        F1[Create pending return] --> F2[Warehouse confirm]
        F2 --> F3{Condition?}
        F3 -->|Good| F4[Stock IN + credit note GL]
        F3 -->|Damage| F5[qty=0 log + credit note — no stock IN]
    end

    subgraph damage ["7. Damage"]
        G1[Standalone write-off] --> G2[Stock OUT + shrinkage GL]
    end

    create --> today --> godown --> challan --> print
    challan --> sreturn
    sreturn -.->|"NOT auto-linked"| damage

    style A4 fill:#fff3cd
    style D2 fill:#f8d7da
    style F4 fill:#d1e7dd
    style F5 fill:#ffe5b4
    style G2 fill:#f8d7da
```



**Roles (from `app/config/route_roles.php`):**


| Step             | Typical roles                          |
| ---------------- | -------------------------------------- |
| Create invoice   | admin, manager, salesman               |
| Godown / challan | warehouse_manager, dispatcher, manager |
| Return confirm   | warehouse_manager                      |
| Damage           | warehouse_manager                      |


---

## Stock architecture — the big picture

### Three stock concepts users care about


| Concept                         | Definition in this system                            | Where computed                                         |
| ------------------------------- | ---------------------------------------------------- | ------------------------------------------------------ |
| **Warehouse physical**          | Actual on-hand in one warehouse                      | `warehouse_stock.qty`                                  |
| **Branch physical**             | Sum of physical across branch warehouses             | Aggregated from `warehouse_stock` JOIN `warehouses`    |
| **Branch available (sellable)** | Branch physical minus sales pipeline                 | `StockAvailabilityService::getBranchAvailableQty()`    |
| **Warehouse available**         | Warehouse physical minus pipeline for that warehouse | `StockAvailabilityService::getWarehouseAvailableQty()` |


There is **no `branch_stock` table**. Branch figures are always **derived**.

### Pipeline formula

```
pending_qty = SUM(ordered_qty - dispatched_qty)
WHERE sales_invoice.status NOT IN ('challan_completed', 'reversed')
  AND COALESCE(is_reversed, 0) = 0
  AND ordered_qty > dispatched_qty
```

Draft pipeline holds qty at **branch** level (`warehouse_id NULL`) until godown assigns a warehouse; warehouse-level available is unchanged until then.

### Write path (physical mutations)

```
StockTransactionModel::updateWarehouseStock()  ← moving average costing
StockTransactionModel::logMovement()           ← immutable audit log
        ↑
StockService (sales/challan/returns use this)
        ↑
ChallanModel, SalesReturnModel, DamageModel, Purchase*, StockAdjustment*, etc.
```

### Read path (availability)

```
StockAvailabilityService  ← SSOT for "how much can I sell?"
        ↑
Helper::Search_Product_With_Stock, Get_Warehouse_Available_Stock, …
        ↑
SalesModel, ChallanModel, SalesReturnModel, POS JS endpoints
```

### System-wide stock writers (outside sales journey)

All of these also touch `warehouse_stock` and should reconcile via `stock_transactions`:


| Module             | reference_type      | Direction            |
| ------------------ | ------------------- | -------------------- |
| Purchase receive   | `purchase_receive`  | IN                   |
| Purchase return    | `purchase_return`   | OUT                  |
| Warehouse transfer | transfer types      | OUT source / IN dest |
| Branch demand      | branch demand types | Inter-warehouse      |
| Stock adjustment   | `adjustment`        | ±                    |
| Stock take         | `stock_take`        | Variance             |
| Sales challan      | `sales_challan`     | OUT                  |
| Sales return       | `sales_return`      | IN (Good)            |
| Damage             | `damage`            | OUT                  |


**Reconciliation report:** `ProductMovementReport` rebuilds balances from `stock_transactions` and compares to current `warehouse_stock`.

---

## Step-by-step review

---

### Step 1 — Create Sales Invoice (`sales/create` → `POST final_sales`)

**Purpose:** Salesman builds a cart and finalizes an invoice. This creates the commercial obligation (AR/revenue) and reserves stock in the pipeline without moving physical inventory.

**Key files:**

- `app/controllers/SalesController.php` — `create`, `final_sales`, stock AJAX endpoints
- `app/services/Sales/traits/SalesInvoiceOperationsTrait.php` — `finalizeSales`
- `app/services/Sales/SalesCartService.php` — session + DB draft carts
- `app/views/sales/create.php`, `public/assets/js/sales-create.js`

**What happens on finalize:**

1. Credit limit check (with optional override + audit reason).
2. Pre-transaction availability check via `validateCartStockAvailability` → `StockService::assertBranchProductsAvailable`.
3. Inside transaction: row locks (`lockBranchProductsForUpdate`), re-assert availability.
4. Insert `sales_invoices` (`status = draft`).
5. Insert `sales_invoice_items` with `warehouse_id = NULL` (assigned at godown).
6. Insert `sales_invoice_dispatches` with `ordered_qty`, `dispatched_qty = 0`, **`warehouse_id = NULL`** (branch-level pipeline hold until godown).
7. Post customer ledger debit + GL (Dr AR / Cr Revenue).

#### Strengths


| #   | Finding                                                                                            |
| --- | -------------------------------------------------------------------------------------------------- |
| ✅   | **Double stock check** — pre-transaction + locked re-check prevents overselling under concurrency. |
| ✅   | **Pipeline reservation** — clever soft-hold without touching physical stock until delivery.        |
| ✅   | **Credit limit enforcement** with override audit trail.                                            |
| ✅   | **Transactional integrity** — invoice, dispatches, ledger, and GL commit or roll back together.    |
| ✅   | **Multi-customer draft carts** persisted in DB (`sales_draft_carts`).                              |


#### Weaknesses


| Severity    | Finding                                                                                                                                                           | Location                                                            |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| **warning** | ~~Default warehouse seeded at finalize may not match godown assignment later~~ **Fixed (2026-06):** draft dispatches use `warehouse_id NULL` (branch hold); godown assigns warehouse. | `SalesInvoiceOperationsTrait::finalizeSales` |
| **warning** | Revenue/AR posted at invoice creation, **before** goods leave warehouse — standard for trade credit but means financial and physical timelines diverge by design. | Same                                                                |
| **warning** | ~~Stale draft invoices hold pipeline indefinitely~~ **Fixed (2026-06):** auto-cancel on `sales/today` (throttled), manual Release pipeline, CLI cron; threshold `SALES_STALE_DRAFT_DAYS` (default 14). | `SalesInvoiceOperationsTrait`, `sales/today` |
| **nit**     | Cart lives in session; server restart mid-sale loses unsaved cart (DB draft carts mitigate for saved tabs).                                                       | Session cart key `sales_draft_carts`                                |


#### Stock impact


| Stock type             | Impact                                                                                      |
| ---------------------- | ------------------------------------------------------------------------------------------- |
| **Warehouse physical** | None                                                                                        |
| **Branch physical**    | None                                                                                        |
| **Pipeline**           | **+ordered_qty** per product (reduces available immediately)                                |
| **Branch available**   | **Decreases** by ordered qty (via pipeline subtraction)                                     |
| **SSOT**               | Correctly uses `StockAvailabilityService`; reservation stored in `sales_invoice_dispatches` |


---

### Step 2 — Today's Invoice (`sales/today`)

**Purpose:** Operational hub for the day's invoices — filter by workflow stage, edit drafts, receive payments, navigate to godown/challan/prints, export, call-it-a-day.

**Key files:**

- `SalesController::today`, `datatable_invoices`, `edit`, `delete_invoice`, `receive_modal`, `call_it_a_day`
- `app/views/sales/today.php`, `sales-today-index.js`
- `SalesPaymentService` — payments (no stock impact)

**Workflow filters:** `draft`, `godown_issued`, `challan_completed` (chips in UI).

#### Strengths


| #   | Finding                                                                          |
| --- | -------------------------------------------------------------------------------- |
| ✅   | Central navigation hub ties the full journey together.                           |
| ✅   | Status chips give clear pipeline visibility.                                     |
| ✅   | Payment collection integrated without affecting stock.                           |
| ✅   | Draft edit/delete correctly rewires dispatches and reverses GL/ledger on delete. |


#### Weaknesses


| Severity    | Finding                                                                                                                                                                     | Location                                                  |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| **warning** | `call_it_a_day` is a **filter flag only** — no stock or accounting effect; name may mislead operators.                                                                      | `SalesInvoiceOperationsTrait::callItADay`                 |
| **warning** | Editing draft after other invoices consumed same stock relies on `excludeInvoiceId` in availability — must be wired on every edit path (verify on `updateExistingInvoice`). | `SalesServiceSupportTrait::validateCartStockAvailability` |
| **nit**     | High-density UI with many actions; role separation (salesman vs warehouse) depends on route_roles, not inline UI hiding.                                                    | Views + `route_roles.php`                                 |


#### Stock impact


| Stock type             | Impact                                                                     |
| ---------------------- | -------------------------------------------------------------------------- |
| **Warehouse physical** | None (except via linked delete/reverse flows)                              |
| **Pipeline**           | Delete draft → pipeline rows removed; edit draft → dispatches rewritten    |
| **Payments**           | No stock effect                                                            |
| **SSOT**               | Read-only for stock; listing uses invoice status, not direct stock queries |


---

### Step 3 — Godown Copy (`challan/create` → `POST prepare_godown` → print)

**Purpose:** Warehouse assigns **which warehouse** fulfills each line, records dispatchers, optionally adjusts transport/total, and locks warehouse choice for challan.

**Key files:**

- `ChallanController::create`, `prepare_godown`, `godown_copy`
- `ChallanModel::prepareGodown`, `resolveDispatchLinesForInvoice`
- `app/views/challan/godown_copy.php`, `godown-print.css`

**What happens:**

1. Validates invoice is `draft` or `godown_issued`.
2. Resolves dispatch lines with **warehouse available** check (`Get_Warehouse_Available_Stock`, excludes current invoice from pipeline).
3. DELETE + re-INSERT `sales_invoice_dispatches` (prevents duplicate dispatch rows).
4. Updates `sales_invoice_items.warehouse_id`.
5. Sets `status = godown_issued`, `godown_issued_at = NOW()`.

#### Strengths


| #   | Finding                                                                                |
| --- | -------------------------------------------------------------------------------------- |
| ✅   | **Warehouse-level assignment** with available-qty validation (physical − pipeline).    |
| ✅   | **Idempotent rewrite** of dispatches — fixes duplicate dispatch problem explicitly.    |
| ✅   | **Warehouse locked at challan** — finalize mode rejects warehouse changes from godown. |
| ✅   | **Partial dispatch forbidden** — qty must match invoice line (simplifies integrity).   |
| ✅   | Branch scoping via `warehouseBelongsToBranch` and `assertInvoiceAccessible`.           |


#### Weaknesses


| Severity    | Finding                                                                                                                                                                                                                                                                              | Location                                                    |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| **warning** | Godown validates **available** (physical − pipeline); finalize/challan validates **physical only** — correct because this invoice's pipeline is still counted until challan, but operators may not understand why godown passes yet challan fails if another invoice consumed stock. | `ChallanModel::resolveDispatchLinesForInvoice` lines 99–152 |
| **warning** | Transport/total can change at godown **and again at challan** — each posts GL adjustments; reversal path incomplete (see Step 4).                                                                                                                                                    | `persistInvoiceTransportCost`                               |
| **warning** | No row-level `FOR UPDATE` on `warehouse_stock` during godown save — race between two godown saves on same product/warehouse possible (low probability, same branch).                                                                                                                 | `prepareGodown`                                             |
| **nit**     | `ChallanModel extends SalesModel` — inheritance blur; warehouse logic in sales subclass.                                                                                                                                                                                             | `ChallanModel.php`                                          |


#### Stock impact


| Stock type              | Impact                                                                                    |
| ----------------------- | ----------------------------------------------------------------------------------------- |
| **Warehouse physical**  | None                                                                                      |
| **Pipeline**            | Rewritten with new `warehouse_id`; qty unchanged                                          |
| **Branch available**    | May shift between warehouses within same branch (net branch pipeline unchanged)           |
| **Warehouse available** | Pipeline attribution moves to chosen warehouse                                            |
| **SSOT**                | Correctly uses `StockAvailabilityService` via Helper; dispatches table updated atomically |


---

### Step 4 — Challan Copy (`POST create_final_challan` → print)

**Purpose:** Physical dispatch — stock leaves the warehouse, COGS recognized, invoice marked delivered.

**Key files:**

- `ChallanController::create_final_challan`, `challan_copy`, `reverse_challan`
- `ChallanModel::finalizeChallan`, `reverseChallan`

**What happens on finalize:**

1. `FOR UPDATE` lock on invoice; verify godown prepared.
2. Validate warehouse matches godown assignment; check **physical** stock ≥ demand.
3. Insert `sales_challans` record.
4. For each line: `updateWarehouseStock(-qty)` at current `avg_cost`; log `sales_challan`; set `dispatched_qty`.
5. Post COGS journal; optional transport/total adjustment journal.

#### Strengths


| #   | Finding                                                                                  |
| --- | ---------------------------------------------------------------------------------------- |
| ✅   | **Single point of physical OUT** for sales — clear SSOT moment.                          |
| ✅   | **Double-finalization guard** — rejects if already `challan_completed`.                  |
| ✅   | **COGS journal required** when amount > 0 — throws if journal missing after stock issue. |
| ✅   | **Immutable movement log** with challan reference.                                       |
| ✅   | Moving average costing applied consistently at issue.                                    |


#### Weaknesses


| Severity     | Finding                                                                                                                                                                                                                                     | Location                                       |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| **critical** | ~~**Challan reversal incomplete rollback**~~ **Fixed (2026-06):** reversal now offsets only **challan-time** `invoice_adjustment` rows (godown transport adjustments preserved), restores pre-challan totals, reverses linked transport GL. | `ChallanModel::reverseChallan`                 |
| **warning**  | ~~**COGS restore on reversal uses current avg_cost**~~ **Fixed (2026-06):** `sales_challan_items.issue_rate` captured at finalize; reversal restores inventory at that rate (no current-avg fallback). | `ChallanModel::restoreStockFromChallanIssue`   |
| **warning**  | **Physical-only check at finalize** does not re-check pipeline from other invoices on same warehouse — mitigated because this invoice still holds pipeline until dispatch completes in same transaction.                                    | `resolveDispatchLinesForInvoice` finalize mode |
| **warning**  | Invoice AR/revenue **not reversed** on challan reversal — by design (undo delivery only), but operators may expect full invoice void.                                                                                                       | `reverseChallan`                               |
| **nit**      | Blocks reversal if completed sales returns exist — correct guard, but no UI guidance on resolution order.                                                                                                                                   | `reverseChallan` preconditions                 |


#### Stock impact


| Stock type             | Impact                                                                                               |
| ---------------------- | ---------------------------------------------------------------------------------------------------- |
| **Warehouse physical** | **−qty** at assigned warehouse                                                                       |
| **Branch physical**    | **−qty** (sum across warehouses)                                                                     |
| **Pipeline**           | **−pending** (dispatched_qty = ordered_qty → invoice exits pipeline)                                 |
| **Branch available**   | Net: physical down, pipeline release — available may increase if pipeline was the binding constraint |
| **SSOT**               | Writes via `StockService`; logs to `stock_transactions`; `warehouse_stock` is authoritative physical |


---

### Step 5 — Invoice Print (`sales/invoice_copy/{id}`)

**Purpose:** Printable customer-facing invoice copy.

**Key files:**

- `SalesController::invoice_copy`
- `app/views/sales/invoice_copy.php`, `print_invoice.js`

#### Strengths


| #   | Finding                                             |
| --- | --------------------------------------------------- |
| ✅   | Pure read/print — no accidental stock side effects. |
| ✅   | Available any time regardless of workflow stage.    |


#### Weaknesses


| Severity | Finding                                                                                                          |
| -------- | ---------------------------------------------------------------------------------------------------------------- |
| **nit**  | Printing before challan shows committed sale without proof of delivery — business process concern, not code bug. |
| **nit**  | No explicit "draft / not delivered" watermark on print template (verify view).                                   |


#### Stock impact

**None.** No reads or writes to stock tables.

---

### Step 6 — Sales Return (`SalesReturn/create` → confirm)

**Purpose:** Customer returns goods against a completed challan invoice. Two-phase: create pending return, warehouse confirms condition and receiving warehouse.

**Key files:**

- `SalesReturnController`, `SalesReturnModel`
- `confirmReturn`, `reverseReturn`, `getMaxReturnableQty`
- Views: `create.php`, `confirm.php`, `slip.php`

**Prerequisite:** Invoice must be `challan_completed` (`RETURNABLE_INVOICE_STATUS`).

**Confirm logic by condition:**


| Condition  | Stock                                             | GL                              |
| ---------- | ------------------------------------------------- | ------------------------------- |
| **Good**   | IN at avg cost; `sales_return` movement           | Credit note + COGS reversal     |
| **Damage** | **No stock IN**; movement logged with **qty = 0** | Credit note (revenue side only) |


#### Strengths


| #   | Finding                                                                              |
| --- | ------------------------------------------------------------------------------------ |
| ✅   | **Two-phase confirm** — warehouse validates physical receipt before economic impact. |
| ✅   | **Return qty capped** by `getMaxReturnableQty` per invoice line.                     |
| ✅   | **Warehouse must belong to branch** — branch isolation enforced.                     |
| ✅   | **Good returns** correctly increase stock at moving average.                         |
| ✅   | **Reversal path** restores stock OUT for Good lines and reverses GL.                 |


#### Weaknesses


| Severity     | Finding                                                                                                                                                                                                                     | Location                                                   |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| **critical** | **Damaged returns disconnect** — customer gets credit (ledger + GL revenue reversal) but **no stock IN and no automatic damage write-off**. Physical shrinkage is invisible unless someone manually creates a Damage entry. | `SalesReturnModel::confirmReturn` lines 528–537            |
| **warning**  | **Damage return still credits full sales value** — financially correct for customer, but inventory account unchanged; books show lower customer balance without matching inventory reduction.                               | Same + `postSalesReturn` with `cogs_amount = 0` for damage |
| **warning**  | Return confirm uses `getWarehouseAvgCost` with fallback to sale rate if avg = 0 — may inflate inventory value on return.                                                                                                    | lines 506–508                                              |
| **warning**  | No enforced link between return slip and damage document — audit trail gap.                                                                                                                                                 | Architecture                                               |
| **nit**      | `reference_type = sales_return_reversal` on ledger may have had enum issues (noted in phase plan).                                                                                                                          | `reverseReturn`                                            |


#### Stock impact


| Stock type             | Good return                   | Damage return                                               |
| ---------------------- | ----------------------------- | ----------------------------------------------------------- |
| **Warehouse physical** | **+qty**                      | No change                                                   |
| **Branch physical**    | **+qty**                      | No change                                                   |
| **Pipeline**           | None (post-delivery)          | None                                                        |
| **Branch available**   | Increases                     | Unchanged                                                   |
| **SSOT**               | Correct IN via `StockService` | **SSOT gap** — financial event without physical counterpart |


---

### Step 7 — Damage (`Damage/create` → store)

**Purpose:** Standalone inventory write-off (breakage, expiry, etc.) — not part of the enforced sales sequence.

**Key files:**

- `DamageController`, `DamageModel::createDamage`, `reverseDamage`
- `app/views/Damage/create.php`

**What happens:**

1. Select warehouse + product lines.
2. Rate defaults to warehouse avg cost.
3. `updateWarehouseStock(-qty)` + `stock_transactions` (`reference_type = damage`).
4. GL: Dr shrinkage / Cr inventory.

#### Strengths


| #   | Finding                                                             |
| --- | ------------------------------------------------------------------- |
| ✅   | Clean, focused write-off with stock OUT + GL + audit log.           |
| ✅   | Branch/warehouse scoping enforced.                                  |
| ✅   | Reversal path restores stock via `reverseTransaction`.              |
| ✅   | Uses `StockTransactionModel` directly with transactional integrity. |


#### Weaknesses


| Severity     | Finding                                                                                                                                              | Location                    |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- |
| **critical** | ~~**Not integrated with sales return Damage path**~~ **Fixed (C1/W5):** confirm auto-creates linked `damage_invoices`; UI cross-links return ↔ damage. | `SalesReturnModel::confirmReturn` |
| **warning**  | **No stock availability pre-check** beyond implicit negative guard in `updateWarehouseStock` — can drive negative stock if concurrent issues.        | `DamageModel::createDamage` |
| **warning**  | Damage code uses `random_int` for document number — low collision risk but not sequence-based like sales invoices.                                   | line 80                     |
| **nit**      | ~~Not linked in Today's Sales UI journey~~ **Fixed (2026-06):** cross-links on Today's Sales, Sales Returns, Damage list/details, guide. | UX |


#### Stock impact


| Stock type             | Impact                                                                      |
| ---------------------- | --------------------------------------------------------------------------- |
| **Warehouse physical** | **−qty**                                                                    |
| **Branch physical**    | **−qty**                                                                    |
| **Pipeline**           | None                                                                        |
| **Branch available**   | Decreases                                                                   |
| **SSOT**               | Physical write correct; **linked to damaged returns** via auto write-off on confirm (C1) |


---

## Cross-cutting findings

### Is there a true single source of truth?


| Question                | Answer                                                                                                                                         |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **Physical qty SSOT?**  | **Yes, with caveats** — `warehouse_stock.qty` is authoritative; all sales OUT/IN should go through `StockTransactionModel`.                    |
| **Available qty SSOT?** | **Yes (system-wide outbound)** — `StockAvailabilityService`: physical − sales pipeline; all stock-out modules use it for display + validation. |
| **Branch stock SSOT?**  | **Derived, not stored** — SUM warehouses; no branch-level table. Consistent if warehouse data is correct.                                      |
| **Cost SSOT?**          | **Moving average in `warehouse_stock.avg_cost`** — used at challan OUT, return IN, damage OUT.                                                 |
| **Audit trail SSOT?**   | `**stock_transactions`** — `ProductMovementReport` reconciles to `warehouse_stock`.                                                            |
| **System-wide SSOT?**   | **Mostly yes** — outbound stock-out paths use pipeline-aware available; PO in-transit not reserved.                                            |


### SSOT gaps (system-wide, not just sales)


| Severity     | Gap                                                                                                                                                                                                                 | Evidence                                                                                             |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| **critical** | ~~**Sales pipeline is the only soft reservation**~~ **Mitigated (2026-06):** pipeline subtracted in all outbound modules (transfer, damage, branch demand send, stock decrease); purchase return already used SSOT. | `StockAvailabilityService` + `Assert_Warehouse_*` on outbound writes                                 |
| **warning**  | ~~`**BranchDemandModel` duplicates stock update SQL**~~ **Fixed (2026-06):** `StockService($this->db)`; duplicate private updater removed; send rate from warehouse avg. | `BranchDemandModel` |
| **warning**  | ~~**Mixed write paths — branch demand inline SQL**~~ **Mitigated (2026-06):** branch demand uses `StockService`; damage still uses `StockTransactionModel($this->db)` directly (both SSOT-compliant). | Stock write callers |
| **warning**  | **Damaged return vs damage module** — two concepts, one financial path, zero automatic physical path.                                                                                                               | `SalesReturnModel` + `DamageModel`                                                                   |
| **warning**  | **Negative stock possible** on concurrent OUT transactions if locks not held across full flow.                                                                                                                      | `StockTransactionModel` OUT uses `FOR UPDATE` on single row but not all callers lock branch products |
| **nit**      | `**SalesAuditModel`** provides health checks and repair routines — good safety net, but reactive not preventive.                                                                                                    | `runHealthChecks()`                                                                                  |


### Race conditions & concurrency


| Scenario                            | Mitigation                                                        | Residual risk                                         |
| ----------------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------- |
| Two salesmen finalize same product  | `lockBranchProductsForUpdate` + re-assert in `finalizeSales`      | Low                                                   |
| Godown + finalize same warehouse    | Transaction-scoped; finalize checks physical                      | Medium if godown not saved before concurrent sale     |
| Challan + damage same product       | Outbound modules use pipeline-aware available + assert before OUT | Low–medium — concurrent same-ms still needs row locks |
| Edit draft while godown in progress | Status guards (`draft` only for edit)                             | Low                                                   |


### Double-counting analysis


| Concern                                            | Verdict                                                                                             |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Pipeline + physical both subtracted for available? | **Correct** — pipeline is subset of physical, not additive deduction                                |
| Challan OUT while invoice in pipeline?             | **Correct** — dispatched_qty set equal to ordered_qty, removing pipeline in same transaction as OUT |
| Return IN counted twice?                           | **No** — single movement per confirm; reversal creates opposite                                     |
| GL inventory vs warehouse_stock                    | **Separate layers** — reconciliation via COGS/damage journals; `SalesAuditModel` checks             |


---

## Adversarial findings summary (prioritized)

### Act on (critical)


| ID  | Finding                                                                                                                                                          | Steps affected                    |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| C1  | ~~**Damaged sales return credits customer without inventory write-off**~~ **Fixed (2026-06):** damage confirm auto-links `damage_invoices` write-off + GL.       | Sales Return, Damage              |
| C2  | ~~**Challan reversal leaves transport/total/ledger misaligned**~~ **Fixed (2026-06):** reversal offsets only challan-time ledger/GL; godown transport preserved. | Challan                           |
| C3  | ~~**No system-wide available qty**~~ **Fixed (2026-06):** outbound modules use pipeline-aware available; server asserts before stock OUT.                        | All sales + cross-module outbound |


### Consider (warning)


| ID  | Finding                                                                                                    | Steps affected        |
| --- | ---------------------------------------------------------------------------------------------------------- | --------------------- |
| W1  | ~~**COGS restore on challan reversal uses current avg, not issue cost**~~ **Fixed (2026-06):** per-line `sales_challan_items.issue_rate` + strict rate resolution on reversal. | Challan               |
| W2  | ~~**Default warehouse at finalize may misallocate pipeline until godown**~~ **Fixed (2026-06):** branch soft-hold with NULL warehouse until godown save. | Create Sales, Godown  |
| W3  | ~~**Stale draft invoices hold pipeline indefinitely**~~ **Fixed (2026-06):** auto-cancel + Release pipeline on Today's Sales; cron script. | Create Sales, Today   |
| W4  | ~~`BranchDemandModel` bypasses `StockTransactionModel` SSOT.~~ **Fixed (2026-06):** `StockService($this->db)`; dead duplicate SQL removed; send rate from sender warehouse avg. | Cross-module          |
| W5  | ~~Damage module not linked in sales journey UI/workflow.~~ **Fixed (2026-06):** journey links, return↔damage cross-nav, guide section, confirm banner. | Damage, Sales Return  |
| W6  | ~~Revenue recognized at invoice, COGS at challan — timing gap is by design but complicates margin reporting.~~ **Fixed (2026-06):** Gross Margin report (`Report/grossMargin`) with delivery vs invoice date basis, pipeline bucket, product view; GL posting unchanged. | Create Sales, Challan |


### Noted (nit)


| ID  | Finding                                                         |
| --- | --------------------------------------------------------------- |
| N1  | `call_it_a_day` is cosmetic filter only.                        |
| N2  | `ChallanModel extends SalesModel` — layering smell.             |
| N3  | Partial dispatch intentionally forbidden — business limitation. |
| N4  | Invoice print has no delivery-status watermark.                 |


---

## Recommendations (no code — roadmap only)

1. ~~**Unify damaged return → damage write-off**~~ **Done (C1).**
2. ~~**Complete challan reversal rollback**~~ **Done (C2).** Challan-only ledger/GL reversal; godown transport rows preserved.
3. ~~**Extend availability service OR document scope**~~ **Done (C3).** Outbound modules wired to `StockAvailabilityService`; `Assert_Warehouse_Lines_Available` on writes.
4. ~~**Route all stock writes through `StockTransactionModel` / `StockService`**~~ **Done (W4).** `BranchDemandModel` uses `StockService($this->db)`; duplicate updater removed; send rate from warehouse avg.
5. ~~**Stale draft policy**~~ **Done (W3).** `SALES_STALE_DRAFT_DAYS` + auto-cancel on Today's Sales + `php database/scripts/cancel_stale_sales_drafts.php` for cron.
6. ~~**Operator journey doc — damaged return**~~ **Done (W5).** Guide + UI link damaged confirm → auto Damage; manual Damage for other shrinkage.
7. **Reporting**
  Implement remaining planned reports from `SalesAuditModel` section (pipeline register, challan register) for operational visibility. Gross margin report is live at `Report/grossMargin`.

---

## Key file reference


| Area                | Path                                                                          |
| ------------------- | ----------------------------------------------------------------------------- |
| Availability SSOT   | `app/services/Stock/StockAvailabilityService.php`                             |
| Stock writes SSOT   | `app/models/StockTransactionModel.php`, `app/services/Stock/StockService.php` |
| Invoice finalize    | `app/services/Sales/traits/SalesInvoiceOperationsTrait.php`                   |
| Godown / challan    | `app/models/ChallanModel.php`                                                 |
| Returns             | `app/models/SalesReturnModel.php`                                             |
| Damage              | `app/models/DamageModel.php`                                                  |
| Health audit        | `app/models/SalesAuditModel.php`                                              |
| Movement report     | `app/models/Reports/ProductMovementReport.php`                                |
| Existing phase plan | `docs/SALES_ECOSYSTEM_PHASE_PLAN.md`                                          |
| Role matrix         | `docs/SALES_ROLE_MATRIX.md`                                                   |


---

## Appendix — Stock impact matrix (quick reference)


| Step                  | warehouse_stock | sales pipeline    | stock_transactions     | GL                 |
| --------------------- | --------------- | ----------------- | ---------------------- | ------------------ |
| Finalize invoice      | —               | +hold             | —                      | Dr AR / Cr Revenue |
| Edit/delete draft     | —               | ±hold / clear     | —                      | Adjust / reverse   |
| Godown save           | —               | rewrite warehouse | —                      | Transport adjust?  |
| Challan complete      | **OUT**         | release hold      | sales_challan          | COGS               |
| Reverse challan       | **IN**          | re-hold           | sales_challan_reversal | Reverse COGS       |
| Return create         | —               | —                 | —                      | —                  |
| Return confirm Good   | **IN**          | —                 | sales_return           | Credit + COGS rev  |
| Return confirm Damage | —               | —                 | qty=0 log              | Credit only        |
| Damage write-off      | **OUT**         | —                 | damage                 | Shrinkage          |
| Payment               | —               | —                 | —                      | Cash vs AR         |


---

*End of review. No code was modified. For implementation tracking, cross-reference `docs/SALES_ECOSYSTEM_PHASE_PLAN.md`.*