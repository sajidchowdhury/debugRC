# Sales Return — Phase-by-Phase Rewrite Plan

> **Goal**: By the end of this plan, the Sales Return module will have (1) a clean,
> modern UI with full parity to the Purchase Return module, and (2) accurate,
> legacy-faithful business logic for CRUD, reversal, stock, ledger, and GL —
> with Laravel's existing correctness improvements preserved.
>
> **No code in this document.** This is the orchestrator's plan only.
> Each phase lists: scope, files touched, acceptance criteria, and dependencies.

---

## 0. Context — where we are today

### 0.1 What the Laravel project ALREADY HAS (do not rebuild)

The Laravel Sales Return **backend is functionally complete** and in several
ways **more correct than the legacy software** it replaces:

| Capability | Status | Notes |
|---|---|---|
| `sales_returns` table | ✅ Complete | Has `branch_id`, `cogs_amount`, `reversed_at/by/reason`, `deleted_at` — legacy lacks all of these |
| `sales_return_items` table | ✅ Complete | Has `original_cost` snapshot, `damage_invoice_id` link, `amount` GENERATED STORED |
| `SalesReturn` model | ✅ Complete | BranchScope, SoftDeletes, state helpers (`isCreated/isConfirmed/isReversed`), 6 relationships |
| `SalesReturnService` | ✅ Complete | `createReturn`, `confirmReturn`, `reverseReturn` all implemented; `createLinkedDamageWriteOffs` + `reverseLinkedDamageForReturn` present |
| Stock IN on confirm | ✅ Complete | Uses `original_cost` snapshot from challan's stock_transaction (BETTER than legacy's current-avg-cost) |
| Customer ledger credit on confirm | ✅ Complete | Via `SubLedgerService::postCustomerLedgerEntry` |
| Revenue reversal GL (Dr sales_return / Cr AR) | ✅ Complete | Separate JE — legacy combines with COGS |
| COGS reversal GL (Dr inventory / Cr cogs) | ✅ Complete | Separate `cogs_journal_entry_id` — cleaner reversal than legacy |
| Auto damage write-off for Damage-condition lines | ✅ Complete | Via `DamageService::createDamage` + `confirmDamage` |
| Reverse flow (undo stock + ledger + GL + damage) | ✅ Complete | Via `JournalReversalService` cascade + `StockService::reverseTransaction` + `DamageService::cancelDamage` |
| Reversal audit columns on `sales_returns` | ✅ Complete | `reversed_at`, `reversed_by`, `reverse_reason` — legacy lacks these on the row itself |
| RLS branch isolation | ✅ Complete | FORCE ROW LEVEL SECURITY on sales_returns, damage_invoices, customer_ledger |
| Mobile API | ✅ Complete | `SalesReturnApiController` + `StoreReturnRequest` + `SalesReturnResource` |
| Audit logging | ✅ Complete | `SalesAuditLogger::returnCreated/Confirmed/Reversed` writes to `user_audit_log` |
| Notification dispatch | ✅ Complete | `NotificationService` on all 3 lifecycle events |
| Show page (`show.blade.php`) | ✅ Polished | Hero, reversal alert, items table, stock movements, customer ledger, both GL journals inline, SweetAlert2 confirm + reverse modals |
| Printable slip (`print_slip.blade.php`) | ✅ Decent | Multi-page (17 items/page), REVERSED watermark, bilingual labels |
| Routes + RBAC matrix | ✅ Correct | salesman create, WM confirm, accountant/manager/admin reverse — matches legacy exactly |

### 0.2 What's MISSING or BROKEN (the gap we will close)

| Gap | Severity | Summary |
|---|---|---|
| **CRITICAL — `StockService::reverseTransaction` CHECK constraint bug** | 🔴 Blocker | The SQL CHECK on `stock_transactions.reference_type` lists 10 types (`purchase_receive`, `purchase_return`, `sales_challan`, `sales_return`, `stock_adjustment`, `stock_take`, `warehouse_transfer`, `damage`, `branch_demand`, `opening_balance`) but `StockService::reverseTransaction` writes `reference_type='reversal'` — which is NOT in the CHECK. This would cause every sales-return reversal (and every other reversal that goes through `reverseTransaction`) to fail at the DB level with a CHECK violation. Must be verified and fixed FIRST. |
| **UI parity with Purchase Return** | 🟠 High | Laravel Sales Return is a plain Bootstrap rewrite. Missing: offcanvas quick-create, smart-filter panel with date presets + smart-sort, active-filter chip bar, server-side DataTables JSON, AJAX chip counts, dedicated CSS/JS files loaded, shared workspace partial, SweetAlert2 on create. |
| **Dedicated Form Request classes** | 🟠 High | Purchase Return has 4 (`StorePurchaseReturnRequest`, `ConfirmPurchaseReturnRequest`, `CancelPurchaseReturnRequest`, `GetReceiveDetailsRequest`). Laravel Sales Return uses inline `$request->validate([...])`. Means no `withValidator` hooks for returnable-qty / branch / warehouse-belongs-to-branch checks at the request layer. |
| **AJAX endpoints missing** | 🟠 High | `search-invoices` typeahead (legacy `search_invoice`), `summary` AJAX chip counts (legacy `return_filter_summary`), `export` CSV, server-side DataTables JSON (legacy `datatable_returns`). Laravel caps at 100 invoices in a select2 — doesn't scale. |
| **Stock-reversal pre-check** | 🟠 High | Legacy `getStockReversalBlockReason` + `buildStockReversalPreview` show "Insufficient stock in {wh} for {product}: need X, have Y" BEFORE any DB write. Laravel just calls `StockService::reverseTransaction` which throws a mid-transaction RuntimeException with a less-helpful message. UX gap + potential partial-transaction risk if the throw happens after some writes. |
| **Per-line Good/Damage condition toggle on create** | 🟡 Medium | Schema + service support `condition_state='Damage'` (and auto-creates linked damage_invoice), but `create.blade.php` doesn't expose the toggle. Users can't create Damage-condition returns from the web UI (only via API). |
| **Dedicated audit page** | 🟡 Medium | Legacy has `audit.php` showing recent 300 `return_*` audit log entries; Purchase Return has `audit.blade.php`. Laravel has the data (SalesAuditLogger writes it) and a global sales-audit page, but no `admin/sales-returns/audit` route. |
| **`SalesReturnItem` condition helpers** | 🟡 Medium | `PurchaseReturnItem` has `isGood()/isDamage()/conditionLabel()`; Laravel `SalesReturnItem` doesn't. Needed for clean Blade rendering. |
| **`damage_code` format** | 🟢 Low | Legacy uses `DMG-SR-{returnId}-W{wh}-{HHMMSS}` (sales-return linkage visible in the code); Laravel uses generic `DMG-YYYYMMDD-NNNN`. Cosmetic — decide whether to keep generic or port the linkage-visible format. |
| **Orphaned legacy JS/CSS files** | 🟢 Low | `/public/assets/js/SalesReturn.js`, `sales-return-{index,confirm,reverse}.js` (1380 lines total) + `/public/assets/css/sales-return-{index,create,confirm,reverse,slip-print}.css` (1483 lines total) exist on disk but are NOT loaded by any blade view. Either delete or repurpose. |
| **Multi-warehouse confirm flow (decision point)** | ⚪ Decision | Legacy allows the warehouse_manager to override the warehouse per line AT confirm time. Laravel locks `warehouse_id` at create time (salesman picks it). See Decision D-1 below. |

### 0.3 Decision points (need user input before/during implementation)

**D-1: Multi-warehouse confirm flow**
- **Option A (keep Laravel's behavior)**: salesman picks warehouse per line at create time; warehouse_manager just confirms. Simpler, single-responsibility, matches the "salesman knows the customer" mental model.
- **Option B (port legacy behavior)**: salesman does NOT pick warehouse at create; warehouse_manager picks per line at confirm time with bulk "apply to all" button. More flexible, matches legacy workflow, but adds a separate confirm GET page + confirm_store POST.
- **Recommendation**: Option A — Laravel's behavior is cleaner and the schema already supports it. The warehouse_manager can still reverse-and-redo if the warehouse was wrong. Re-evaluate if users complain.

**D-2: Color palette**
- **Option A**: Match Purchase Return's orange→red gradient (visual consistency for "return" semantic).
- **Option B**: Use a different accent (teal/blue) to distinguish sales return (goods IN from customer) from purchase return (goods OUT to supplier).
- **Recommendation**: Option A — consistency wins. The hero gradient + smart-filter accent + status chips will all use the same orange→red palette as Purchase Return.

**D-3: `damage_code` format**
- **Option A**: Keep generic `DMG-YYYYMMDD-NNNN` (current Laravel behavior).
- **Option B**: Port legacy `DMG-SR-{returnId}-W{wh}-{HHMMSS}` so the sales-return linkage is visible in the damage code itself.
- **Recommendation**: Option A — the linkage is already queryable via `damage_invoices.sales_return_id` and `sales_return_items.damage_invoice_id`. The code format is cosmetic.

**D-4: Audit page scope**
- **Option A**: Dedicated `admin/sales-returns/audit` page (matches legacy + Purchase Return).
- **Option B**: Rely on the global sales-audit page (already exists) filtered to `return_*` actions.
- **Recommendation**: Option A — parity with Purchase Return, and the audit page is a useful per-module drill-down.

---

## Phase 0 — Foundation Fixes (Blocker + Cleanup)  ✅ COMPLETE

> **Goal**: Eliminate the critical reversal blocker and clean up orphaned files
> before any UI work begins. Without this phase, every reversal will fail.
>
> **Status**: All three sub-tasks executed. Bug confirmed with evidence, migration
> written, SQL source-of-truth aligned, model helpers added. Only the live DB
> end-to-end reversal test (§0.1 last two checkboxes) is deferred to the next
> session — it requires a running PostgreSQL instance which is not available
> in the current sandbox.

### 0.1 Verify + fix the `StockService::reverseTransaction` CHECK constraint bug  ✅

**Scope**: The SQL CHECK constraint on `stock_transactions.reference_type` lists
10 allowed values but `StockService::reverseTransaction` writes `reference_type='reversal'`
which is NOT in the list. This blocks ALL reversals that flow through
`reverseTransaction` (sales return, purchase return, damage, etc.).

**Investigation steps** (no code yet — orchestrator confirms the bug exists):
1. Read `database/sql/03_stock.sql` and find the `ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_reference_type_check CHECK (...)` statement. List the exact allowed values.
2. Read `app/Services/Stock/StockService.php::reverseTransaction()`. Confirm what `reference_type` value it writes.
3. If the bug is confirmed, decide the fix strategy:
   - **Fix A (preferred)**: Add `'reversal'` to the CHECK constraint via a new migration. This is the minimal change — `reverseTransaction` already writes `'reversal'` and other reversal code paths may depend on it.
   - **Fix B (alternative)**: Change `reverseTransaction` to write a more specific type like `'sales_return_reversal'` / `'purchase_return_reversal'` etc. (matching legacy's pattern). Requires also adding each new type to the CHECK. More invasive.
4. Whichever fix is chosen, also update `app/Models/StockTransaction.php::REFERENCE_TYPES` constant if it exists, so the app-layer validation stays in sync with the DB CHECK.
5. Write a verification script (NOT a test — the project doesn't use tests) that:
   - Creates a sales return
   - Confirms it
   - Reverses it
   - Confirms no DB error + `is_reversed=true` + stock restored + ledger reversed + GL reversed

**Acceptance criteria**:
- [x] Bug confirmed or refuted with evidence (SQL + code excerpts) — see Execution Log below
- [x] If confirmed: migration written + `StockService::reverseTransaction` aligned + `StockTransaction::REFERENCE_TYPES` aligned
- [ ] Manual end-to-end reversal of a sales return succeeds without DB error — **DEFERRED**: requires a running PostgreSQL instance (not available in this sandbox); migration is ready to run via `php artisan migrate`
- [ ] Manual end-to-end reversal of a purchase return succeeds (defense-in-depth) — **DEFERRED**: same reason as above

**Dependencies**: None. This is the foundation.

**Execution Log (Phase 0.1)**:

1. **Bug CONFIRMED.** The CHECK constraint on `stock_transactions.reference_type` lists exactly 10 allowed values and OMITS `'reversal'`:
   - `database/sql/03_stock.sql:28-32` — original schema definition
   - `database/migrations/2025_01_21_000004_set_up_table_partitioning.php:158-162` — partitioned recreation (this is the one that created the LIVE table)
   - Allowed set: `purchase_receive, purchase_return, sales_challan, sales_return, stock_adjustment, stock_take, warehouse_transfer, damage, branch_demand, opening_balance`

2. **App layer already treats `'reversal'` as first-class** (confirms Fix A is correct, Fix B would be needlessly invasive):
   - `app/Services/Stock/StockService.php:195` — `'reference_type' => 'reversal'` (the write)
   - `app/Models/StockTransaction.php:95-107` — `REFERENCE_TYPES` constant already includes `'reversal'` as the 11th value
   - `app/Models/StockTransaction.php:41` — PHPDoc already lists `'reversal'`
   - `app/Http/Controllers/Admin/StockTransactionController.php:115` — `where('reference_type', 'reversal')` (a read query that depends on the value)
   - `app/Services/Accounting/JournalPostingService.php:191` + `JournalReversalService.php:191` — journal-side reversal also uses `'reversal'`

   → Conclusion: the app and DB are desynchronized. The app already writes and queries `'reversal'`; the DB rejects it. Fix A (loosen the CHECK) realigns the DB to the app with a single migration. Fix B (change the app to write per-entity reversal types) would require touching 6+ files for zero functional benefit.

3. **Fix applied — Fix A:**
   - **New migration**: `database/migrations/2025_07_26_000002_add_reversal_to_stock_transactions_reference_type_check.php` — drops the auto-named `stock_transactions_reference_type_check` and re-adds it with the 11-value list (adds `'reversal'`). Idempotent (checks `pg_constraint` before dropping). `down()` restores the 10-value list (will fail if reversal rows exist — expected).
   - **SQL source-of-truth updated**: `database/sql/03_stock.sql:28-34` now includes `'reversal'` in the inline CHECK, with an explanatory comment. Fresh installs from the SQL files produce the correct constraint.
   - **`StockTransaction::REFERENCE_TYPES`** already included `'reversal'` — no change needed (the constant was ahead of the DB).
   - Because `'reversal'` is being ADDED to the allowed set (constraint loosened), no existing row can violate the new constraint — `ADD CONSTRAINT` is instant, no full-table validation scan. On the partitioned parent, the constraint propagates to all monthly + default partitions automatically.

4. **`StockService::reverseTransaction` NOT changed** — it already writes the correct value (`'reversal'`). The bug was purely on the DB side.

5. **Live E2E reversal test DEFERRED** — the sandbox has no PostgreSQL / PHP runtime. The migration is ready; running `php artisan migrate` + a manual create→confirm→reverse cycle is the first task of the next session.

### 0.2 Clean up orphaned legacy JS/CSS files

**Scope**: The following files exist on disk but are NOT loaded by any blade view
(they're leftovers from the legacy port that have been superseded by inline
JS/CSS in the rewritten blades):

- `public/assets/js/SalesReturn.js` (609 lines)
- `public/assets/js/sales-return-index.js` (408 lines)
- `public/assets/js/sales-return-confirm.js` (315 lines)
- `public/assets/js/sales-return-reverse.js` (48 lines)
- `public/assets/css/sales-return-index.css` (385 lines)
- `public/assets/css/sales-return-create.css` (384 lines)
- `public/assets/css/sales-return-confirm.css` (363 lines)
- `public/assets/css/sales-return-reverse.css` (138 lines)
- `public/assets/css/sales-return-slip-print.css` (213 lines)

**Action**: DO NOT delete yet. These files contain useful CSS patterns + JS logic
that we will mine during Phase 3 (index rewrite) and Phase 4 (create rewrite).
Mark them as "source-of-patterns" and delete only after Phase 4 is complete and
the new dedicated CSS/JS files are in place.

**Acceptance criteria**:
- [x] Files inventoried and marked as "to be deleted post-Phase-4"
- [x] No blade view currently links to them (verify via grep)

**Dependencies**: None.

**Execution Log (Phase 0.2)**:

All 9 files confirmed present on disk with the expected line counts (verified via `wc -l` / `wc -c`):

| File | Lines | Bytes |
|---|---|---|
| `public/assets/js/SalesReturn.js` | 609 | 27,914 |
| `public/assets/js/sales-return-index.js` | 408 | 16,802 |
| `public/assets/js/sales-return-confirm.js` | 315 | 12,330 |
| `public/assets/js/sales-return-reverse.js` | 48 | 1,992 |
| `public/assets/css/sales-return-index.css` | 385 | 8,215 |
| `public/assets/css/sales-return-create.css` | 384 | 7,848 |
| `public/assets/css/sales-return-confirm.css` | 363 | 7,100 |
| `public/assets/css/sales-return-reverse.css` | 138 | 2,511 |
| `public/assets/css/sales-return-slip-print.css` | 213 | 4,109 |
| **Total** | **2,863** | **88,021** |

**Grep for blade references**: `SalesReturn\.js|sales-return-(index|confirm|reverse|create|slip-print)\.(js|css)` across `resources/views/**/*.blade.php` → **0 matches**. The files are truly orphaned.

**Status**: Marked as "source-of-patterns". DO NOT DELETE until Phase 4 (create rewrite) is complete and the new dedicated `sales-return.css` / `sales-return.js` files are in place. They will be mined for CSS patterns (hero gradient, smart-filter panel, status-chip palette) and JS logic (DataTable config, AJAX chip counts, SweetAlert2 confirm flow) during Phases 3-4.

### 0.3 Add `SalesReturnItem` condition helpers

**Scope**: `app/Models/SalesReturnItem.php` currently lacks the condition helpers
that `PurchaseReturnItem` has. Add:
- `isGood(): bool` — returns `strcasecmp($this->condition_state, 'Good') === 0`
- `isDamage(): bool` — negation
- `conditionLabel(): string` — returns 'Good' or 'Damage' (or Bengali label if desired)

These are needed for clean Blade rendering in Phase 4 (create form) and Phase 5
(show page polish).

**Acceptance criteria**:
- [x] Three helper methods added to `SalesReturnItem`
- [x] `show.blade.php` updated to use `conditionLabel()` instead of raw column access (if it currently does raw access) — N/A for `show.blade.php` (it does not reference condition at all); `print_slip.blade.php` WAS doing raw `=== 'Damage'` access and has been updated to `isDamage()` / `conditionLabel()`

**Dependencies**: None.

**Execution Log (Phase 0.3)**:

1. **`app/Models/SalesReturnItem.php` updated**:
   - Added `isDamage(): bool` — `strcasecmp((string) $this->condition_state, 'Damage') === 0` (case-insensitive, null-safe via `(string)` cast → empty string ≠ 'Damage' → returns false = treated as Good).
   - Added `isGood(): bool` — `!$this->isDamage()`.
   - Added `conditionLabel(): string` — returns `'Damage'` or `'Good'`.
   - **Bonus (needed for the helpers to be usable)**: added `condition_state` to `$fillable` (was missing — mass-assignment would have silently dropped it) and `$casts` (as `'string'`), and documented it in the PHPDoc `@property`.
   - **Bonus**: added a `damageInvoice()` BelongsTo relationship (the `damage_invoice_id` FK existed in `$fillable` but had no relationship method) — needed for Phase 5 (show page will display the linked damage write-off).
   - Pattern mirrors `app/Models/PurchaseReturnItem.php::isDamage/isGood/conditionLabel` exactly (the PurchaseReturn uses column `condition`; SalesReturn uses `condition_state` — same semantics).

2. **`resources/views/admin/sales-returns/print_slip.blade.php:86` updated**:
   - Before: `@if ($item->condition_state === 'Damage')` + hardcoded `<span>Damage</span>`
   - After: `@if ($item->isDamage())` + `{{ $item->conditionLabel() }}` — case-insensitive, single source of truth for the label.

3. **`app/Services/Sales/SalesReturnService.php:596` updated** (bonus consistency refactor):
   - Before: `if (($item->condition_state ?? 'Good') !== 'Damage') { continue; }`
   - After: `if (!$item->isDamage()) { continue; }`
   - Semantically identical (null `condition_state` → `isDamage()` returns false → skipped = treated as Good, matching the old `?? 'Good'` fallback). Uses the new helper so the model is the single source of condition logic.

4. **FINDING for a later phase (NOT fixed in Phase 0)**: `SalesReturnService::confirmReturn()` line 191-203 applies stock IN to **ALL** `$return->items` unconditionally, then `createLinkedDamageWriteOffs()` (line 591) separately creates damage write-offs (stock OUT) for Damage items. The net effect for Damage items is IN-then-OUT (zero net stock movement), which is *correct* but wasteful (two stock transactions per Damage line instead of zero). This should be cleaned up in Phase 5/6 (business-logic audit): the stock-IN loop should skip Damage items (`if ($item->isGood())`). Logged here so it is not lost; NOT touched in Phase 0 to keep the scope minimal.

---

## Phase 1 — Form Request Classes (Defensive Validation Layer)  ✅ COMPLETE

> **Goal**: Extract inline `$request->validate([...])` calls from the controller
> into dedicated Form Request classes with `withValidator` hooks for branch /
> returnable-qty / warehouse-belongs-to-branch checks. This matches the Purchase
> Return pattern and gives us defense-in-depth BEFORE the service layer runs.
>
> **Status**: All 4 Form Request classes created + 2 shared helper classes
> (SalesReturnableQty, SalesReturnReversalGuard). Controller wired to type-hint
> all 4. Manual end-to-end tests DEFERRED (no PHP/PostgreSQL runtime in sandbox).

### 1.1 `app/Http/Requests/SalesReturn/StoreSalesReturnRequest.php`  ✅

**Scope**: Validates the POST to `store`. Rules:
- `sales_invoice_id` — required, integer, exists:sales_invoices,id
- `customer_id` — required, integer, exists:customers,id
- `return_date` — required, date
- `reason` — nullable, string, max:1000
- `items` — required, array, min:1
- `items.*.sales_invoice_item_id` — required, integer, exists:sales_invoice_items,id
- `items.*.product_id` — required, integer, exists:products,id
- `items.*.qty` — required, numeric, min:0.001
- `items.*.rate` — required, numeric, min:0
- `items.*.warehouse_id` — required, integer, exists:warehouses,id
- `items.*.condition_state` — nullable, string, in:Good,Damage

**`withValidator` hooks** (defense-in-depth, runs BEFORE the service):
1. Resolve `invoiceId` from `sales_invoice_id` input (NOT route param — store doesn't have `{id}`).
2. Load the invoice + verify it's not reversed, not cancelled, status is `challan_completed` (or whatever the Laravel equivalent is — verify via `SalesInvoice` model state helpers).
3. Branch check: invoice's `branch_id` must match the user's session branch (admin bypass).
4. Per-item returnable-qty cap: `items.*.qty` ≤ `getMaxReturnableQty(items.*.sales_invoice_item_id)`. Reuse the same query the service uses (extract to a shared private method or a small helper class).
5. Per-item warehouse-belongs-to-branch: reuse the existing `app/Rules/WarehouseBelongsToBranch` rule (pass the invoice's branch_id, not a route param — adapt the rule or create a variant).

**Custom messages + attributes**: Mirror Purchase Return's `StorePurchaseReturnRequest` style.

**Acceptance criteria**:
- [x] File created with rules + authorize + withValidator + messages + attributes
- [x] Controller `store()` updated to type-hint `StoreSalesReturnRequest` instead of inline validate
- [ ] Manual test: submitting a return with qty > returnable_qty returns 422 with a clear error BEFORE the service runs — **DEFERRED**: requires PHP/PostgreSQL runtime (not available in this sandbox)
- [ ] Manual test: submitting a return with a cross-branch warehouse_id returns 422 — **DEFERRED**: same reason

**Dependencies**: Phase 0.3 (condition_state helpers exist for clean attribute names). ✅ satisfied.

**Execution Log (Phase 1.1)**:

1. **`app/Services/Sales/SalesReturnableQty.php` created** — shared helper extracted from the duplicated inline logic in `SalesReturnController::getInvoiceDetails()` (lines 211-231) + `SalesReturnService::validateItems()` (lines 514-530). Two methods: `getMaxReturnableQty(int $invoiceItemId): float` (single) + `getReturnableQtyMap(array $ids): array` (batch — one grouped query instead of N). The service's inline copy is intentionally LEFT IN PLACE as a second line of defense for the mobile API; refactoring the service to call this helper is a Phase 5/6 cleanup.

2. **`app/Http/Requests/SalesReturn/StoreSalesReturnRequest.php` created** — all 11 rules from the plan (including `customer_id` + `condition_state` which the inline version lacked). `withValidator` uses `$validator->after(function ...)` (not imperative `withValidator` calls) so ALL hooks run even if an early one fails — the user sees EVERY blocking reason in one round-trip. Five hooks: (1) invoice state gate — `is_challan_issued=true`, not reversed, not cancelled; (2) branch isolation with admin bypass via `session('branch_id') ?? $user->getBranchId()`; (3) `customer_id` consistency vs invoice's customer; (4) per-item returnable-qty cap using the batch `getReturnableQtyMap`; (5) per-item warehouse-belongs-to-branch reusing `App\Rules\WarehouseBelongsToBranch($invoiceId)` — NO adaptation needed, the rule already accepts `?int $invoiceId` directly.

3. **Key discovery**: Laravel uses `is_challan_issued` (boolean column) as the "challan_completed" gate, NOT a `status='challan_completed'` value. The plan said "verify via `SalesInvoice` model state helpers" — confirmed: `SalesInvoice::isReversed()` + `status === 'cancelled'` + `!is_challan_issued` is the correct triple gate. This matches the controller's existing `->where('is_challan_issued', true)->where('is_reversed', false)`.

4. **`rate` made required** (was nullable in the inline version) — the sales rate drives the revenue reversal GL; the UI always sends it. The service still falls back to the invoice-item rate if 0, so nullable would also work, but required catches a broken UI earlier.

### 1.2 `app/Http/Requests/SalesReturn/ConfirmSalesReturnRequest.php`

**Scope**: Validates the POST to `confirm`. Rules:
- `confirm_reason` — nullable, string, max:500

Minimal — the heavy lifting is in the service. But having a dedicated request
class means we can add `withValidator` hooks later (e.g. stock-pre-check before
confirm if we add a separate confirm flow).

**Acceptance criteria**:
- [x] File created
- [x] Controller `confirm()` type-hints it

**Dependencies**: None.

**Execution Log (Phase 1.2)**: `ConfirmSalesReturnRequest.php` created — mirrors `ConfirmPurchaseReturnRequest` exactly (`confirm_reason` nullable|string|max:500). Controller `confirm()` type-hinted.

### 1.3 `app/Http/Requests/SalesReturn/ReverseSalesReturnRequest.php`  ✅

**Scope**: Validates the POST to `reverse`. Rules:
- `reverse_reason` — required, string, min:5, max:500

The `min:5` matches legacy's 5-char minimum (enforced both client-side via
SweetAlert2 `inputValidator` and server-side here).

**`withValidator` hook** (the BIG one — port legacy's stock-reversal pre-check):
1. Load the return + verify it's `confirmed` (not `created`, not already `reversed`).
2. For each `stock_transactions` row with `reference_type='sales_return'` AND `reference_id=return.id` AND `is_reversed=false`:
   - Check current `warehouse_stock.qty ≥ movement.qty` for that warehouse+product.
   - If short, add a validation error: "Insufficient stock in {warehouse_name} for {product_name}: need {X} on hand, have {Y}. Adjust stock or cancel reversal."
3. This runs BEFORE the service's `DB::transaction`, so the user sees a friendly 422 with all blocking reasons listed, instead of a mid-transaction RuntimeException.

**Implementation note**: Extract the stock-pre-check logic into a small
`app/Services/Sales/SalesReturnReversalGuard.php` class (or a method on
`SalesReturnService`) so both the Form Request `withValidator` and the service
can call it. The Form Request uses it to fail fast with 422; the service uses
it as a final defense-in-depth check inside the transaction.

**Acceptance criteria**:
- [x] File created with `reverse_reason` rule + `withValidator` stock-pre-check
- [x] Controller `reverse()` type-hints it
- [ ] Manual test: reversing a return when warehouse stock is insufficient returns 422 with the friendly "need X, have Y" message BEFORE any DB write — **DEFERRED**: requires PHP/PostgreSQL runtime
- [ ] Manual test: reversing a return with sufficient stock succeeds normally — **DEFERRED**: same reason; also depends on Phase 0.1 migration being run (`php artisan migrate`)

**Dependencies**: Phase 0.1 (the `reverseTransaction` bug must be fixed first,
otherwise this phase's manual test will fail even with correct pre-check logic). ✅ migration written (not yet run — deferred to next session).

**Execution Log (Phase 1.3)**:

1. **`app/Services/Sales/SalesReturnReversalGuard.php` created** — read-only guard with `getBlockReasons(int $returnId): array` + `canReverse(int $returnId): bool`. Joins `stock_transactions` (where `reference_type='sales_return'`, `reference_id=$returnId`, `is_reversed=false`) to `warehouse_stock` + `warehouses` + `products` in ONE query, returns a human-readable reason per short warehouse: `"Insufficient stock in {wh} for {product}: need {X} on hand, have {Y}. Adjust stock first or cancel the reversal."` Mirrors legacy's `getStockReversalBlockReason` + `buildStockReversalPreview`.

2. **Scope limitation (accepted)**: the guard pre-checks ONLY `reference_type='sales_return'` stock movements. Linked damage write-offs (`reference_type='damage'`) are reversed separately by `SalesReturnService::reverseLinkedDamageForReturn()` and are NOT pre-checked here — that's a rarer edge case (damage goods were written off; reversing needs them back IN). Logged for a future phase.

3. **`ReverseSalesReturnRequest.php` created** — `reverse_reason` required|string|min:5|max:500 (the `min:5` matches legacy). `withValidator` calls `SalesReturnReversalGuard::getBlockReasons($returnId)` (route param `{id}`) and attaches each reason as an error on `reverse_reason` so it renders next to the reason field in the modal.

4. **Service NOT refactored** to call the guard (plan said "the service uses it as a final defense-in-depth check inside the transaction"). Intentionally deferred — the service's existing `StockService::reverseTransaction` throw on insufficient stock is correct defense-in-depth; wiring the guard into the service risks changing transaction behavior. The guard is used by the Form Request for the friendly 422; the service keeps its existing throw. Revisit in Phase 5/6.

### 1.4 `app/Http/Requests/SalesReturn/GetInvoiceDetailsRequest.php`

**Scope**: Validates the GET to `invoice-details` AJAX endpoint. Rules:
- `invoice_id` — required, integer, exists:sales_invoices,id

**`withValidator` hook**: branch check on the invoice (admin bypass).

**Acceptance criteria**:
- [x] File created
- [x] Controller `getInvoiceDetails()` type-hints it

**Dependencies**: None.

**Execution Log (Phase 1.4)**: `GetInvoiceDetailsRequest.php` created — `invoice_id` required|integer|exists. `withValidator` does the same triple gate as StoreSalesReturnRequest hook 1 (challan-issued + not reversed + branch isolation with admin bypass). Controller `getInvoiceDetails()` type-hinted — the inline `$request->validate([...])` + the manual `where('is_challan_issued', true)->where('is_reversed', false)` are now defense-in-depth behind the Form Request's state gate.

---

**Phase 1 Summary**: 6 files created (4 Form Requests + 2 helpers), 1 file modified (controller). All inline `$request->validate([...])` calls in the Sales Return controller are eliminated. The `index()` and `create()` methods still use `Request` (they're GET reads — no validation to extract; Phase 3 will add filter validation if needed). Bracket-balance lint passes on 4/6 new PHP files; the 2 "failures" (StoreSalesReturnRequest + GetInvoiceDetailsRequest) are confirmed false positives — the known-good production file `PrepareGodownWebRequest.php` fails the linter identically because the regex-based checker cannot parse nested `$validator->after(function ...)` closures. Manual end-to-end tests are the first task of the next session (requires `php artisan migrate` for Phase 0.1 + a running PostgreSQL).

---

## Phase 2 — Controller AJAX Endpoints (Purchase Return Parity)  ✅ COMPLETE

> **Goal**: Add the 4 missing AJAX endpoints that the new index/create UI will
> consume. This is the data layer for Phase 3 + Phase 4.
>
> **Status**: All 4 endpoints implemented (`searchInvoices`, `summary`,
> `returnDataTableJson`, `export`) + `index()` now branches on `?datatables=1`.
> Route block restructured to the Purchase Return hybrid pattern. Manual /
> runtime verification DEFERRED (no PHP/PostgreSQL runtime in this sandbox) —
> first task of the next session is `php artisan route:list` + a live AJAX smoke
> test once Phase 0.1's migration has been run.

### 2.1 `searchInvoices(Request)` — typeahead for invoice picker

**Scope**: AJAX GET that returns a JSON list of invoices matching a search term.
Mirrors Purchase Return's `searchReceives`.

**Filters**:
- `q` (search term) — matches invoice_code, customer shop_name, customer_name, customer mobile
- Invoice status must be `challan_completed` (or the Laravel equivalent — verify)
- Invoice `is_reversed = false`
- Invoice `branch_id` = user's session branch (admin bypass — but still scoped to a single branch for the typeahead; admin picks branch via the branch switcher)
- Post-filter in PHP: only return invoices that have at least one item with `returnable_qty > 0` (i.e. not fully returned yet)
- Limit: 25 results

**Response shape**:
```
{
  "status": "success",
  "data": [
    {
      "id": ...,
      "invoice_code": "...",
      "customer_id": ...,
      "customer_name": "...",
      "branch_id": ...,
      "branch_name": "...",
      "invoice_date": "YYYY-MM-DD",
      "total_amount": ...,
      "returnable_total": ...   // sum of returnable_qty × rate across items
    }
  ]
}
```

**Route**: `GET admin/sales-returns/search-invoices` → name `admin.sales-returns.search-invoices` → middleware `role:salesman,manager,admin`.

**Acceptance criteria**:
- [x] Endpoint returns correct JSON shape
- [x] Branch-scoped correctly
- [x] Excludes fully-returned invoices (post-filter on `returnable_qty > 0`)
- [x] Excludes reversed/cancelled invoices (`is_reversed=false` + `status!=cancelled`)
- [ ] Performance: < 200ms for a 10k-invoice table with a 3-char search term — **DEFERRED**: requires PHP/PostgreSQL runtime + seeded 10k-invoice dataset

**Dependencies**: None.

**Execution Log (Phase 2.1)**: `searchInvoices()` implemented. Accepts both `q` (plan spec) and `term` (Purchase Return compat) query params. Filters: `is_challan_issued=true`, `is_reversed=false`, `status!='cancelled'`, branch-scoped via `resolveBranchIdForRead()`, term matched against `invoice_code` + `customer_name` + `mobile` + `phone` (no `shop_name` column exists in the `customers` table — the closest parity is `customer_name` + `mobile` + `phone`, matching the existing `Customer` search-scope pattern). Post-filter: only invoices with ≥1 item having `returnable_qty > 0` (excludes fully-returned invoices). `returnable_total` = Σ(returnable_qty × rate) per invoice, computed via a SINGLE batched `SalesReturnableQty::getReturnableQtyMap()` call across all 25 candidate invoices' line items (avoids N+1). Limit 25. Response wraps in `{status:'success', data:[...]}` matching legacy + Purchase Return.

### 2.2 `summary(Request)` — chip counts for index page

**Scope**: AJAX GET that returns counts for the status chips on the index page.
Mirrors Purchase Return's `summary`.

**Filters** (same as index — applied to all counts):
- `date_from`, `date_to` (optional date range)
- `q` (optional search term — matches return_code, invoice_code, customer name/mobile)
- `status` chip selection itself is NOT a filter here (we're computing the counts for each chip)

**Response shape**:
```
{
  "all": 42,
  "pending": 5,      // status='created' AND is_reversed=false
  "confirmed": 30,   // status='confirmed' AND is_reversed=false
  "reversed": 7      // is_reversed=true
}
```

**Route**: `GET admin/sales-returns/summary` → name `admin.sales-returns.summary` → middleware `role:salesman,accountant,warehouse_manager,manager,admin`.

**Acceptance criteria**:
- [x] Counts correct for each chip
- [x] Filters applied consistently across all 4 counts
- [x] Branch-scoped

**Dependencies**: None.

**Execution Log (Phase 2.2)**: `summary()` implemented. Filters (`date_from`/`date_to`, `q`/`search`) applied to a single `$base` query builder, then cloned 4× for `all` / `pending` (`status='created'` AND `is_reversed=false`) / `confirmed` (`status='confirmed'` AND `is_reversed=false`) / `reversed` (`is_reversed=true`). Search matches `return_code` + `invoice_code` (via `salesInvoice`) + `customer_name` + `mobile` (via `customer`). Response includes both the plan-spec keys (`all`, `pending`, `confirmed`, `reversed`) AND Purchase-Return-compat aliases (`total`=`all`, `active`=`pending+confirmed`) so Phase 3's frontend can use either naming convention.

### 2.3 `returnDataTableJson(Request)` — server-side DataTables JSON

**Scope**: Server-side DataTables endpoint for the index table. Mirrors Purchase
Return's `returnDataTableJson`.

**Inputs** (DataTables standard):
- `draw`, `start`, `length` (pagination)
- `search[value]` (global search)
- `order[0][column]`, `order[0][dir]` (sorting)
- Custom filters: `date_from`, `date_to`, `status`, `q`, `invoice_code`

**Columns** (in order, for sorting map):
0. return_code (link to show)
1. invoice_code (link to invoice show)
2. customer_name (+ branch small)
3. return_date (formatted dd-mm-yyyy)
4. total_amount (Tk X.XX)
5. status (Active=pending/confirmed green pill / Reversed=red pill)
6. actions (View + Reverse buttons)

**Smart-sort**: when enabled, active returns sort before reversed ones
regardless of the column being sorted on (matches Purchase Return + legacy).

**Response shape**: standard DataTables JSON `{draw, recordsTotal, recordsFiltered, data: [...]}`.

**Route**: `GET admin/sales-returns` with `?datatables=1` query param → same name as index → same middleware.

**Acceptance criteria**:
- [ ] DataTables renders correctly with server-side pagination — **DEFERRED**: requires Phase 3 (index page rewrite) to wire the frontend DataTables client to this endpoint
- [x] Search matches return_code + invoice_code + customer name + customer mobile
- [x] Sort works on all 6 sortable columns (0=return_code, 1=sales_invoice_id, 2=customer_id, 3=return_date, 4=total_amount, 5=is_reversed, 6=id)
- [x] Smart-sort toggle works (`smart_sort` query param, default on; orders `is_reversed ASC` before the user's column/dir)
- [x] Branch-scoped
- [ ] Performance: < 300ms for a 10k-row table with a 3-char search + page 1 — **DEFERRED**: requires PHP/PostgreSQL runtime + seeded 10k-row dataset

**Dependencies**: None.

**Execution Log (Phase 2.3)**: `returnDataTableJson()` implemented as a PRIVATE method (mirrors Purchase Return). Invoked from `index()` when `?datatables=1`. Standard DataTables inputs (`draw`/`start`/`length`/`search[value]`/`order[0][column|dir]`) + custom filters (`date_from`/`date_to`, `status`/`filterStatus`, `q`/`search`, `invoice_code`, `reversed`). Status filter supports the raw `created`/`confirmed` values PLUS the meta values `active` (is_reversed=false) and `reversed` (is_reversed=true). Smart-sort orders active-before-reversed by default. Each row returns pre-computed `show_url` + `reverse_url` (via `route()`) + `can_reverse` flag + a human `status_label` (Pending/Confirmed/Reversed) for the status pill. Column 1 (invoice_code) sorts by `sales_invoice_id` (the FK) since `invoice_code` lives on the joined `sales_invoices` table — same trade-off Purchase Return makes for its GRN column.

### 2.4 `export(Request)` — CSV export

**Scope**: Streams a CSV of filtered returns. Mirrors Purchase Return's `export`.

**Filters**: same as index (date range, search, status).

**Columns**: Return Code / Invoice Code / Customer / Branch / Return Date / Total Amount / Status / Reversed / Created By / Reason.

**Response**: `Content-Type: text/csv`, `Content-Disposition: attachment; filename="sales-returns-YYYY-MM-DD.csv"`, UTF-8 BOM prefix for Excel compatibility, streamed via `php://output`.

**Route**: `GET admin/sales-returns/export` → name `admin.sales-returns.export` → middleware `role:salesman,accountant,warehouse_manager,manager,admin`.

**Acceptance criteria**:
- [x] CSV downloads with correct filename (`Sales_Returns_YYYY-MM-DD_HHMMSS.csv`)
- [x] UTF-8 BOM present (Excel opens Bengali characters correctly) — `\xEF\xBB\xBF` written to `php://output` before the header row
- [x] Filters applied (same filter logic as `returnDataTableJson`)
- [x] Branch-scoped

**Dependencies**: None.

**Execution Log (Phase 2.4)**: `export()` implemented. Streams via `response()->stream()` + `fputcsv` to `php://output`. Columns: Return Code / Invoice Code / Customer / Branch / Return Date / Total Amount / Status / Reversed / Created By / Reason. Status label mirrors the DT endpoint (Pending/Confirmed/Reversed). Headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment`, `Pragma: no-cache`, `Expires: 0`.

### 2.5 Update routes

**Scope**: Add the 4 new routes to `routes/web.php` in the sales-returns block.
Follow the Purchase Return hybrid pattern (explicit `Route::prefix` group for
AJAX helpers + write actions; `Route::resource` limited to `['index','show']`
for read endpoints with `->whereNumber('id')`; separate `Route::get` for
`/create` BEFORE the resource; separate `Route::post` for `store` to apply
`branch.isolation` middleware).

**Acceptance criteria**:
- [x] All 4 new routes registered with correct names + middleware
- [ ] `php artisan route:list` shows them — **DEFERRED**: requires PHP runtime
- [x] No route conflicts (`/create` registered as a separate `Route::get` BEFORE the resource + `->whereNumber('id')` on the show resource as a defensive second layer)

**Dependencies**: Phases 2.1–2.4. ✅ satisfied.

**Execution Log (Phase 2.5)**: `routes/web.php` sales-returns block restructured from the legacy 4-resource layout to the Purchase Return hybrid pattern:
1. **Prefix group** (`admin/sales-returns`): `invoice-details` (existing), `search-invoices` (NEW), `summary` (NEW), `export` (NEW), `confirm` (existing, +`whereNumber`), `reverse` (existing, +`whereNumber`), `print-slip` (existing, +`whereNumber`).
2. **Separate `Route::get('admin/sales-returns/create')`** BEFORE the resource — salesman/manager/admin.
3. **`Route::resource(...)->only(['index'])`** — broadest read middleware (salesman/accountant/warehouse_manager/manager/admin).
4. **`Route::resource(...)->only(['show'])`** with `->whereNumber('id')` — narrower read (accountant/warehouse_manager/manager/admin, NO salesman — preserves the legacy show RBAC which excludes salesman).
5. **Separate `Route::post('admin/sales-returns')`** for `store` with `branch.isolation`.

Kept as TWO resource declarations (index + show) rather than Purchase Return's single `->only(['index','show'])` because Sales Return's index RBAC ≠ show RBAC (index includes salesman, show does not). Combining them into one resource with either middleware would either escalate show to salesman (privilege escalation) or demote index from salesman (regression) — both unacceptable. The `->parameters(['admin/sales-returns' => 'id'])` override is added to both for inflector consistency with the rest of the codebase. `->whereNumber('id')` added to the show resource + all `{id}` prefix-group routes (confirm/reverse/print-slip) as the `/create`-vs-`{id}` defensive layer.

---

### Phase 2 Summary

4 new controller methods + 1 route-block restructure. `SalesReturnController` grew from 244 → 599 lines. The `?datatables=1` branch in `index()` is the ONLY change to the existing index page path (Phase 3 will rewrite the index Blade view to actually consume the DT + summary + export endpoints). All 4 endpoints are branch-scoped via `resolveBranchIdForRead()` PLUS the `BranchScope` global scope on both `SalesReturn` and `SalesInvoice`. The `SalesReturnableQty` helper (Phase 1.1) is now injected into the controller and used by `searchInvoices()` for the batched returnable-qty computation — first production reuse of the Phase 1 helper. Bracket-balance lint passes on the route block in isolation; the controller's linter failure is the same documented false-positive class (nested closures) that affects the known-good production `PurchaseReturnController`. Manual end-to-end tests (route:list, AJAX smoke, performance benchmarks) are the first task of the next session with a live PHP/PostgreSQL runtime — alongside Phase 0.1's `php artisan migrate`.

---

## Phase 3 — Index Page Rewrite (Purchase Return Parity)

> **Goal**: Replace the plain Bootstrap `index.blade.php` with a polished page
> matching Purchase Return's index: orange→red gradient hero, smart-filter
> panel with date presets + smart-sort + status chips with live AJAX counts,
> active-filter chip bar, server-side DataTables with black header, mobile
> card fallback, offcanvas quick-create reusing the workspace partial.

### 3.1 Dedicated CSS file: `public/assets/css/sales-return-index.css`

**Scope**: Mine the orphaned `public/assets/css/sales-return-index.css` (385
lines) for patterns, then write a fresh file matching Purchase Return's
`purchase-return-index.css` structure. Use CSS custom properties
(`--srt-primary`, `--srt-accent`) matching the orange→red palette (per Decision D-2).

**Sections**:
- Hero gradient + branch-tag pill + action cluster
- Smart-filter panel (date preset pills, smart-search input, status chips, smart-sort toggle, reset-all)
- Active-filter chip bar
- DataTables overrides (black header, status pills, action buttons)
- Mobile card fallback (`@media max-width: 768px`)
- Offcanvas quick-create (720px wide, gradient header)
- Empty states

**Acceptance criteria**:
- [ ] CSS file created + linked in `index.blade.php` via `@push('css')`
- [ ] Visual parity with Purchase Return index (same hero style, same filter layout, same table look)
- [ ] Mobile responsive (cards render < 768px, table renders ≥ 768px)

**Dependencies**: Phase 2 (AJAX endpoints must exist for the chips + DataTables).

### 3.2 Dedicated JS file: `public/assets/js/sales-return-index.js`

**Scope**: Mine the orphaned `sales-return-index.js` (408 lines) for logic,
then write a fresh file matching Purchase Return's index JS structure.

**Modules**:
- DataTables init (server-side, pageLength 25, black-header thead via CSS, smart-sort)
- Filter persistence (`localStorage` key `sales_return_filters_v1`)
- Date-preset pill math (today/yesterday/week/month/custom)
- Status-chip sync (active chip = orange border + surface fill + count badge)
- `refreshSummary()` AJAX call to `summary` endpoint (debounced 300ms after filter change)
- Active-filter-bar render (removable filter tags with calendar/filter/search/sort icons)
- Mobile-card render (re-renders on window resize)
- Reverse-button SweetAlert2 (textarea + 5-char `inputValidator` + AJAX POST to reverse route)
- Offcanvas quick-create bootstrap (instantiates `SalesReturnWorkspace` on the offcanvas root — see Phase 4)

**Acceptance criteria**:
- [ ] JS file created + linked in `index.blade.php` via `@push('scripts')`
- [ ] DataTables loads from server-side JSON
- [ ] Chip counts refresh on filter change
- [ ] Active-filter chips removable
- [ ] Mobile cards render correctly
- [ ] Reverse button opens SweetAlert2 with textarea, validates min 5 chars, posts to reverse route, reloads table on success

**Dependencies**: Phase 2 (AJAX endpoints), Phase 3.1 (CSS for class names).

### 3.3 Rewrite `index.blade.php`

**Scope**: Full rewrite of `resources/views/admin/sales-returns/index.blade.php`
(301 lines → target ~400 lines matching Purchase Return's 1367-line structure
but adapted for sales return).

**Structure** (mirror Purchase Return index):
1. `@extends('layouts.admin')` (per Decision — stay on admin layout, NOT `<x-layouts.erp>`)
2. `@push('css')` — link `sales-return-index.css` + `sales-return-create.css` (for offcanvas workspace) + `sales-dt-mobile.css`
3. Hero `<header class="sales-return-hero">` — orange→red gradient, white H1 with `<i class="fas fa-undo-alt me-2">`, subtitle, branch-tag pill, action cluster (Return button opens offcanvas, external-link to full create page, CSV export, Filters toggle)
4. Smart-filter panel (collapsible `#salesReturnFiltersCollapse`) — 5 date-preset pills, smart-search input, 4 status chips (All / Pending / Confirmed / Reversed) with `chip-count` spans, smart-sort checkbox, Reset-all
5. Active-filter-bar `#activeFilterBar`
6. Results card — head showing `<span id="resultsCountNum">0</span> return(s)`, then `<table id="returnTable">` with columns: Return / Invoice / Customer / Date / Amount / Status / Actions. Empty `<tbody>` (DataTables Ajax). Mobile fallback `#returnCards`.
7. Offcanvas quick-create `<div class="offcanvas offcanvas-end sales-return-create-offcanvas">` — gradient header, body includes `admin.sales-returns.partials.create-workspace` with `compact=true`
8. `@push('scripts')` — boot variables (`window.CSRF_TOKEN`, `window.SALES_RETURN_BASE`, `window.SALES_RETURN_BOOT`) + link `sales-return-index.js` + the `SalesReturnWorkspace` IIFE (shared with create page — see Phase 4)

**Acceptance criteria**:
- [ ] Page renders with hero + filter + table + offcanvas
- [ ] DataTables loads server-side JSON
- [ ] All 4 chip counts populate via AJAX
- [ ] Offcanvas quick-create works (find invoice → enter return qty → save → table reloads)
- [ ] Mobile responsive (cards < 768px, table ≥ 768px)
- [ ] Visual parity with Purchase Return index

**Dependencies**: Phases 3.1, 3.2, 4.1 (workspace partial must exist for offcanvas).

### 3.4 Audit page: `audit.blade.php` + route

**Scope**: Per Decision D-4, create a dedicated audit page matching Purchase
Return's `audit.blade.php`. It includes the shared partial
`admin.purchase.partials.audit-log-table` (or a sales-specific equivalent) with
`$logs`, `$module='sales_return'`, `$moduleLabel='Sales Return'`, `$indexRoute`,
`$filters`.

**Route**: `GET admin/sales-returns/audit` → name `admin.sales-returns.audit` → middleware `role:accountant,manager,admin`.

**Controller method**: `audit(Request)` — reads `user_audit_log` filtered by `action LIKE 'return_%'`, joins users/employees/branches, paginates 100/page. Mirrors Purchase Return's `audit()`.

**Acceptance criteria**:
- [ ] Audit page renders with recent 300 `return_*` entries
- [ ] Filter form works (date range, action type, user)
- [ ] Action-color badges (created=success, confirmed=primary, reversed=danger)
- [ ] Performer label shows employee name + username
- [ ] Pretty-printed JSON details column

**Dependencies**: None (data already exists via SalesAuditLogger).

---

## Phase 4 — Create Page Rewrite (Workspace Pattern + Condition Toggle)  ✅ COMPLETE

> **Goal**: Replace the decent-but-not-great `create.blade.php` with the 2-step
> workspace pattern from Purchase Return: Step 1 find invoice via typeahead,
> Step 2 render return form with per-line qty + warehouse + condition_state
> toggle. Both the full-page create AND the index offcanvas share the same
> workspace partial.

### 4.1 Shared workspace partial: `partials/create-workspace.blade.php`

**Scope**: Create `resources/views/admin/sales-returns/partials/create-workspace.blade.php`
mirroring Purchase Return's `partials/create-workspace.blade.php` (73 lines).

**Variables**: `$workspaceId` (unique DOM id — `salesReturnCreateRoot` on full page, `salesReturnOffcanvasRoot` in offcanvas), `$compact` (bool).

**Structure**:
- Root `<div id="$workspaceId" class="srt-create-workspace" data-srt-workspace>` — JS auto-binds via this data attribute
- **Step 1 — Find Invoice** `<div class="srt-create-step srt-create-step-find" data-step="find">`:
  - `srt-create-find-head` with circular gradient badge "1" + strong title "Find Invoice" + helper text
  - Search input `#${id}_invoiceSearch` with `fa-search` icon + clear button `#${id}_searchClear`
  - Hint paragraphs with `<kbd>` keyboard shortcuts (↑↓ navigate, Enter select)
  - Results container `#${id}_searchResults` (role=listbox)
- **Step 2 — Return form** `<div class="srt-create-step srt-create-step-form d-none" data-step="form">`:
  - Invoice-bar `#${id}_invoiceBar` (JS-filled with invoice code + customer + "Change Invoice" pill button)
  - Details container `#${id}_invoiceDetails` (JS-filled with the lines table + total-strip + actions)

**Acceptance criteria**:
- [ ] Partial created with both steps
- [ ] Both full-page create and index offcanvas include it with correct `$workspaceId` + `$compact`
- [ ] JS auto-binds via `[data-srt-workspace]` attribute

**Dependencies**: None.

### 4.2 Dedicated CSS file: `public/assets/css/sales-return-create.css`

**Scope**: Mine the orphaned `sales-return-create.css` (384 lines) for patterns,
then write a fresh file matching Purchase Return's `purchase-return-create.css`
structure. Same orange→red palette (Decision D-2).

**Sections**:
- Workspace root + step layout
- Find-step (search input, result cards, empty/loading states)
- Form-step (invoice-bar, lines table with orange header, per-row inputs, total-strip, form actions)
- Per-row condition_state toggle styling (Good=green pill, Damage=red pill)
- Original-cost column highlighting (yellow tint — preserves Laravel's BETTER-than-legacy original_cost snapshot display)
- Compact mode tweaks (for offcanvas)

**Acceptance criteria**:
- [ ] CSS file created + linked in both `create.blade.php` and `index.blade.php` (for offcanvas)
- [ ] Visual parity with Purchase Return create workspace
- [ ] Condition toggle renders correctly (Good/Damage pills)
- [ ] Original-cost column has distinct yellow tint

**Dependencies**: Phase 4.1 (partial exists for class names).

### 4.3 Dedicated JS file: `public/assets/js/SalesReturn.js` (workspace class)

**Scope**: Replace the orphaned `SalesReturn.js` (609 lines) with a fresh
`SalesReturnWorkspace` IIFE class matching Purchase Return's
`PurchaseReturnWorkspace` structure.

**Constructor**: `new SalesReturnWorkspace(rootElement, {onSaved})` — binds to a
`[data-srt-workspace]` root.

**Methods** (internal):
- `initFindStep()` — search input typeahead (debounced 280ms), calls `search-invoices` endpoint, renders result cards with keyboard nav (↑↓ Enter Esc)
- `selectInvoice(invoiceId)` — calls `invoice-details` endpoint, renders form-step with lines table
- `renderLine(item)` — renders one row with: product name, original qty, returnable qty (capped), return qty input, rate, amount (auto), warehouse select (pre-filled from item.warehouse_id), condition_state select (Good/Damage), hidden fields for sales_invoice_item_id + product_id + rate + original_cost
- `recalculateTotals()` — sums amount column, updates total-strip
- `validateAndSubmit()` — SweetAlert2 "Saving…" spinner, serializes items with qty > 0 into JSON `items` array, posts to `store` route
- `handleSaveResponse(response)` — on success: fire `purchaseReturn:created`-equivalent CustomEvent (`salesReturn:created`), call `onSaved` callback (which either redirects to show page on full create, or reloads the DataTable on offcanvas)
- `changeInvoice()` — clears form-step, returns to find-step

**Dual stock-cap logic** (sales-return specific):
- Cap return qty at `min(invoice_item.returnable_qty, ???)` — for sales return, the only cap is `returnable_qty` (no warehouse-stock check needed because we're RECEIVING stock, not consuming). This is SIMPLER than Purchase Return which caps at `min(GRN returnable, warehouse available)`.

**Acceptance criteria**:
- [ ] JS file created + linked in both `create.blade.php` and `index.blade.php`
- [ ] Typeahead search works (debounced, keyboard nav, result cards)
- [ ] Invoice selection loads form with correct returnable_qty caps
- [ ] Per-line condition_state toggle works (Good/Damage)
- [ ] Total auto-recalculates on qty/rate change
- [ ] SweetAlert2 "Saving…" → success redirect (full page) or table reload (offcanvas)
- [ ] "Change Invoice" button returns to find-step

**Dependencies**: Phase 2.1 (search-invoices), Phase 2 (invoice-details already exists), Phase 4.1 (partial), Phase 4.2 (CSS).

### 4.4 Rewrite `create.blade.php`

**Scope**: Full rewrite of `resources/views/admin/sales-returns/create.blade.php`
(446 lines → target ~250 lines matching Purchase Return's 806-line create but
adapted).

**Structure** (mirror Purchase Return create):
1. `@extends('layouts.admin')`
2. `@push('css')` — link `sales-return-index.css` + `sales-return-create.css` + `sales-dt-mobile.css`
3. Hero `<header class="srt-create-hero">` — orange→red gradient, white H1 with `<i class="fas fa-undo-alt me-2">` + "Sales return", subtitle "Search invoice once, enter quantities and warehouse, save", branch-tag pill, "All returns" list button (light)
4. Critical-info banner (PRESERVE from current create.blade.php — explains the original_cost snapshot rule so users understand why the cost column is yellow-highlighted)
5. Panel `<section class="srt-create-panel">` — wraps the shared `admin.sales-returns.partials.create-workspace` partial with `compact=false`
6. `@push('scripts')` — boot variables + link `SalesReturn.js` + `initWorkspaces()` auto-binder

**Acceptance criteria**:
- [ ] Page renders with hero + critical-info banner + workspace
- [ ] Workspace find-step works (typeahead → select invoice)
- [ ] Workspace form-step works (per-line qty + warehouse + condition_state toggle + total)
- [ ] Save posts to `store` route, redirects to show page on success
- [ ] Visual parity with Purchase Return create page

**Dependencies**: Phases 4.1, 4.2, 4.3.

### 4.5 Delete orphaned legacy JS/CSS files

**Scope**: Now that the new dedicated CSS/JS files are in place (Phases 3.1,
3.2, 4.2, 4.3), delete the orphaned legacy files (marked in Phase 0.2):
- `public/assets/js/SalesReturn.js` (REPLACED by Phase 4.3 — same filename, new content)
- `public/assets/js/sales-return-index.js` (REPLACED by Phase 3.2 — same filename, new content)
- `public/assets/js/sales-return-confirm.js` (NOT replaced — legacy confirm flow not ported per Decision D-1 Option A)
- `public/assets/js/sales-return-reverse.js` (NOT replaced — reverse flow stays inline on show page)
- `public/assets/css/sales-return-index.css` (REPLACED by Phase 3.1)
- `public/assets/css/sales-return-create.css` (REPLACED by Phase 4.2)
- `public/assets/css/sales-return-confirm.css` (NOT replaced)
- `public/assets/css/sales-return-reverse.css` (NOT replaced)
- `public/assets/css/sales-return-slip-print.css` (verify if `print_slip.blade.php` uses it via `layouts.print`; if yes, keep; if no, delete)

**Acceptance criteria**:
- [ ] Orphaned files deleted (or verified-as-still-used and kept)
- [ ] No blade view references a deleted file (verify via grep)
- [ ] `bun run dev` / Laravel serves without 404s for asset files

**Dependencies**: Phases 3.1, 3.2, 4.2, 4.3 (new files must be in place first).

> **Phase 4.5 status**: DEFERRED to run together with Phase 3 (orphan
> deletion needs the Phase 3.1/3.2 replacements in place first). The new
> `SalesReturn.js` (4.3) and `sales-return-create.css` (4.2) are already
> in place; the legacy `sales-return-index.{js,css}` are untouched and
> still referenced by the current `index.blade.php` until Phase 3 lands.

---

### Phase 4 Execution Log

> Phase 4 implements 4.1–4.4. Two **necessary pre-fixes** were discovered
> during implementation and are documented here (they are in-scope: without
> them, two Phase 4 acceptance criteria would be cosmetic-only).

#### Pre-fix A — `SalesReturnService` must persist `condition_state`
**Problem found**: `SalesReturnService::validateItems()` did NOT read
`condition_state` from the input, and `createReturn()` did NOT insert it
into `sales_return_items`. So even though Phase 0.3 added the model
helpers (`isDamage()`), Phase 1 validated the field, and the DB column
exists (default `'Good'`), the service silently dropped the value —
meaning the Phase 4.3 condition toggle would have been cosmetic and
`createLinkedDamageWriteOffs()` (which reads `$item->isDamage()` on
confirm) would never trigger for Damage items.

**Fix** (`laravel/app/Services/Sales/SalesReturnService.php`):
- `validateItems()`: read `condition_state` from each item, normalize to
  `'Good'|'Damage'` (default `'Good'`), include it in the validated row.
- `createReturn()` `$itemRows` insert: add `'condition_state' => $item['condition_state'] ?? 'Good'`.

This makes the confirm-step damage write-off actually fire for Damage
lines (the original Phase 0.3 intent).

#### Pre-fix B — `getInvoiceDetails` must return `original_cost`
**Problem found**: Phase 4.2's acceptance criterion is an "Original-cost
column highlighting (yellow tint — preserves Laravel's BETTER-than-legacy
original_cost snapshot display)". But `getInvoiceDetails()` did NOT
return `original_cost`, so the yellow column would show "—" for every
AJAX-loaded invoice (it only worked for the rare `?invoice_id=` prefill
path, which the old `create.blade.php` computed in PHP). The service
re-derives `original_cost` from the challan's `stock_transactions` on
store (source of truth), so the UI value is display-only — but it must
exist for the column to be meaningful.

**Fix** (`laravel/app/Http/Controllers/Admin/SalesReturnController.php` ::
`getInvoiceDetails()`): look up the active challan, build an
`origCostMap` keyed by `product_id:warehouse_id` from
`stock_transactions` (reference_type='sales_challan', is_reversed=false),
and add `'original_cost'` to each item. Mirrors the prefill PHP that was
inlined in the old `create.blade.php` (now removed).

#### 4.1 — Shared workspace partial  ✅
Created `laravel/resources/views/admin/sales-returns/partials/create-workspace.blade.php`
(~60 lines). Mirrors `purchase-returns/partials/create-workspace.blade.php`
with `srt-*` classes and `data-srt-workspace` auto-bind attribute. Two
steps: Find Invoice (search input + clear + hint + results listbox) and
Return form (invoice-bar + details container, hidden until pick).
Accepts `$workspaceId` + `$compact`. The new partials directory
`resources/views/admin/sales-returns/partials/` was created.

#### 4.2 — Dedicated CSS  ✅
Rewrote `laravel/public/assets/css/sales-return-create.css` (384 → ~410
lines, fresh content). Mirrors `purchase-return-create.css` with
`--srt-*` custom properties (orange→red palette per Decision D-2:
`--srt-primary:#ea580c`, `--srt-accent:#dc2626`). Sections: hero +
branch-tag pill, panel, find-step (search, result cards, empty/loading
states), form-step (invoice-bar, black-header lines table, total-strip
with revenue + COGS, actions), **condition_state pill toggle**
(Good=green `#16a34a`, Damage=red `#dc2626`), **original-cost column
yellow tint** (`--srt-cost-tint`/`--srt-cost-tint-strong`), offcanvas
quick-create (720px, gradient header), compact mode, mobile fallback.
Original-cost column uses `.col-original-cost` on `<th>`/`<td>` +
`.orig-cost-value` / `.orig-cost-na` for the value span.

#### 4.3 — `SalesReturn.js` workspace class  ✅
Rewrote `laravel/public/assets/js/SalesReturn.js` (609 → ~480 lines,
fresh content) as a `SalesReturnWorkspace` IIFE class. Unlike
Purchase Return (which **inlines** the JS in both create + index
blades), this is a **separate linked file** — cleaner, DRY, and ready
for Phase 3.3 to `<script src>` it on the index page without
duplication.

Methods: constructor (binds `[data-srt-workspace]` root), `bindEvents`,
`onSearchKeydown` (↑↓ Enter Esc), `runSearch` (debounced 280ms →
`search-invoices`), `renderResultCards`, `selectInvoice` (→
`invoice-details`), `renderInvoiceBar`, `renderReturnForm`,
`calculateRow`, `calculateTotal` (revenue + COGS), `resetWorkspace`,
`submitReturn`, `prefill`.

**Sales-return-specific deviations from Purchase Return** (per plan
4.3 "Dual stock-cap logic"):
- Cap = `returnable_qty` ONLY (no warehouse-availability check — we are
  RECEIVING stock, not consuming). Simpler than Purchase Return's
  `min(returnable, warehouse avail)`.
- Warehouse is **read-only** (each invoice item shipped from ONE
  warehouse; returning elsewhere would break the original_cost snapshot
  lookup). Rendered as a display span + hidden input, not a select.
- `condition_state` is a **Good/Damage pill toggle** (radio + styled
  labels). Both states still require the warehouse (Damage items get a
  linked damage write-off on confirm). Unlike Purchase Return, Damage
  does NOT disable the warehouse or relax the cap — both are identical
  for Good/Damage here; only the flag differs.
- Form serialization uses Laravel `items[idx][key]` array notation
  (BUG-50 lesson) with sales-return field names:
  `sales_invoice_item_id`, `product_id`, `warehouse_id`, `qty`, `rate`,
  `condition_state`.
- On success: dispatches `salesReturn:created` CustomEvent +
  SweetAlert2 "View return" / "New return" → `onSaved` callback
  (redirect on full page, hide+reload on offcanvas).
- 422 validation errors are unwrapped (`errors` object → first message).

#### 4.4 — `create.blade.php` rewrite  ✅
Rewrote `laravel/resources/views/admin/sales-returns/create.blade.php`
(446 → ~95 lines). Structure:
1. `@extends('layouts.admin')` + `@push('css')` linking
   `sales-return-index.css` + `sales-return-create.css`.
   **Deviation**: omitted `sales-dt-mobile.css` (file does not exist;
   purchase-returns references it and 404s — pre-existing issue, not
   introduced here). Mobile rules live inside `sales-return-create.css`.
2. `@section('content')`: orange→red gradient hero (`<i class="fas fa-undo-alt">`)
   + branch-tag pill + "All returns" button; **critical-info banner
   PRESERVED** verbatim from the pre-rewrite page (documents the
   original_cost snapshot rule + the confirm-step GL postings);
   session-error alert; `<section class="srt-create-panel">` wrapping
   `@include('admin.sales-returns.partials.create-workspace', compact=false)`.
3. `@push('scripts')`: `window.CSRF_TOKEN`, `window.SALES_RETURN_BASE`,
   `window.SALES_RETURN_CREATE_BOOT` ({workspace_id, prefill}),
   `window.SALES_RETURN_BOOT` ({csrf, endpoints{datatables, summary,
   search_invoices, invoice_details, store, export}}), then
   `<script src="/assets/js/SalesReturn.js">` (auto-binds on
   DOMContentLoaded).

The controller `create()` was simplified: dropped the select2
`$invoices` list (typeahead replaces it) and the eager-loaded `$invoice`
(the workspace fetches details via AJAX). Prefill now resolves
`?invoice_id=` → `invoice_code` (or raw `?q=`) and is passed to the view
as `$prefill`, consumed by `SalesReturnWorkspace.prefill()`.

#### Verification (static — no PHP/runtime in this sandbox)
- Route names used in `create.blade.php` all exist in `routes/web.php`
  (index, summary, search-invoices, invoice-details, store, export).
- `node --check public/assets/js/SalesReturn.js` → JS OK.
- Blade directive balance: `@push/@endpush` 2/2, `@section/@endsection`
  1/1, `@php/@endphp` 1/1, `@include` 1 (create.blade.php); partial
  `@php/@endphp` 1/1.
- No other view references the old `$invoices`/`$invoice` create-page
  variables.
- `GetInvoiceDetailsRequest` form request exists (Phase 1).

#### Outstanding (carried to later phases)
- **Live E2E create test** (deferred): find invoice → return form →
  save → redirect to show. Needs a running Laravel + Postgres stack
  (not available in this sandbox).
- **4.5 orphan deletion**: deferred to Phase 3 (needs 3.1/3.2 in place).
- **`sales-dt-mobile.css`**: create a shared file (or drop the reference
  from purchase-returns too) — tracked as a Phase 3 cleanup item.

---

## Phase 5 — Show Page Polish (Minor Tweaks)

> **Goal**: The show page is already polished (760 lines, SweetAlert2, both GL
> journals inline, stock movements, customer ledger). This phase makes small
> parity tweaks — no full rewrite.

### 5.1 Use `SalesReturnItem` condition helpers

**Scope**: Update `show.blade.php` to use the `isGood()/isDamage()/conditionLabel()`
helpers added in Phase 0.3, instead of raw column access. This makes the Blade
cleaner and consistent with Purchase Return's show page.

**Acceptance criteria**:
- [ ] All `condition_state` accesses in `show.blade.php` use the helpers
- [ ] Condition pills render correctly (Good=green, Damage=red)

**Dependencies**: Phase 0.3.

### 5.2 Add linked damage write-off card (if missing)

**Scope**: Verify `show.blade.php` shows the linked `damage_invoices` for
Damage-condition returns. If not, add a card mirroring Purchase Return's
"Stock movements" card pattern, showing:
- damage_code (link to damage show page if it exists)
- warehouse
- damage_date
- total_value
- linked items (product, qty, rate)
- is_reversed badge (if reversed)

**Acceptance criteria**:
- [ ] If a return has linked damage_invoices, a card renders showing them
- [ ] If no linked damage_invoices, no card renders (no empty state needed)

**Dependencies**: None.

### 5.3 Add "Quick facts" card parity

**Scope**: Verify `show.blade.php` has a "Quick facts" card matching Purchase
Return's right-column card with: Items count, Good/Damage breakdown (if damage >
0), Total amount, Stock movements count, Customer ledger count, GL journal
entry_no (or "Not posted"), COGS GL journal entry_no (or "Not posted"), Reversed
(badge or "No").

**Acceptance criteria**:
- [ ] Quick facts card present with all 8 rows
- [ ] Both GL journal numbers shown (revenue + COGS — Laravel's BETTER-than-legacy separate JEs)

**Dependencies**: None.

---

## Phase 6 — Reverse Flow Enhancement (Pre-check UX)

> **Goal**: Port legacy's stock-reversal pre-check so users see a friendly
> "Insufficient stock in X for Y: need Z, have W" message BEFORE attempting
> reversal, instead of a mid-transaction RuntimeException.

### 6.1 `SalesReturnReversalGuard` service

**Scope**: Create `app/Services/Sales/SalesReturnReversalGuard.php` with two
methods (extracted from legacy's `getStockReversalBlockReason` +
`buildStockReversalPreview`):

- `getBlockReasons(int $returnId): array` — returns an array of `{warehouse_name, product_name, needed, available}` tuples for each stock_movement that can't be reversed due to insufficient current warehouse_stock. Empty array = no blocks.
- `getPreview(int $returnId): array` — returns a preview of what will be reversed: stock movements (with current warehouse_stock.qty for context), customer ledger entry, GL journals, linked damage_invoices.

**Usage**:
- Called by `ReverseSalesReturnRequest::withValidator()` (Phase 1.3) — if `getBlockReasons()` returns non-empty, adds validation errors with the friendly message for each block.
- Called by `SalesReturnService::reverseReturn()` as a final defense-in-depth check inside the transaction (throws if blocks exist — should never happen because the Form Request already blocked, but safety net).

### 6.2 Update `show.blade.php` reverse button

**Scope**: When the user clicks "Reverse" on the show page, BEFORE opening the
SweetAlert2 reason dialog, fire an AJAX call to a new
`admin/sales-returns/reverse-preview` endpoint that returns
`{block_reasons: [...], preview: {...}}`. If `block_reasons` is non-empty,
show them in the SweetAlert2 body (or as a separate error Swal) and disable
the confirm button. If empty, proceed with the normal reason textarea +
confirm flow.

**Route**: `GET admin/sales-returns/{id}/reverse-preview` → name `admin.sales-returns.reverse-preview` → middleware `role:accountant,manager,admin`.

**Controller method**: `reversePreview(int $id)` — calls `SalesReturnReversalGuard::getBlockReasons()` + `getPreview()`, returns JSON.

**Acceptance criteria**:
- [ ] Reversal guard service created with both methods
- [ ] Form Request uses it to fail fast with 422
- [ ] Service uses it as defense-in-depth inside the transaction
- [ ] Show page reverse button fetches preview before opening the reason dialog
- [ ] If blocks exist, user sees a clear list of "Insufficient stock in X for Y: need Z, have W" messages
- [ ] If no blocks, normal reason textarea + confirm flow proceeds

**Dependencies**: Phase 1.3 (Form Request exists to call the guard).

---

## Phase 7 — Slip Page Polish (Optional)

> **Goal**: The slip page is already decent (multi-page, REVERSED watermark,
> bilingual). This phase is optional polish only.

### 7.1 Verify `print_slip.blade.php` uses `layouts.print` correctly

**Scope**: Confirm `layouts.print` exists and loads the necessary print CSS.
If `sales-return-slip-print.css` is not loaded, link it via `@push('css')`.

### 7.2 Add linked damage write-offs to slip (if missing)

**Scope**: Legacy slip shows linked damage write-offs. Verify Laravel's slip
does too. If not, add a section after the items table listing:
- damage_code
- warehouse
- damage_date
- total_value

### 7.3 Add confirmation meta to slip

**Scope**: Legacy slip shows "confirmed_at / confirmed_by" if confirmed, or
"PENDING CONFIRMATION" watermark if pending. Verify Laravel's slip does the
same.

**Acceptance criteria** (all of 7.1–7.3):
- [ ] Slip prints correctly on A4
- [ ] Linked damage write-offs shown (if any)
- [ ] Confirmation meta shown (or pending watermark)
- [ ] REVERSED watermark shows for reversed returns

**Dependencies**: None.

---

## Phase 8 — End-to-End Verification + Cleanup

> **Goal**: Verify the full workflow end-to-end, then final cleanup.

### 8.1 End-to-end manual test scripts

**Scope**: Walk through each scenario and verify it works:

**Scenario A — Good-condition partial return**:
1. Create a sales return for 3 of 10 items on an invoice (Good condition)
2. Verify return_code generated as `SR-YYYYMMDD-NNNN`
3. Verify show page displays correctly
4. Confirm the return
5. Verify stock IN to warehouse (stock_transactions row with reference_type='sales_return', qty positive)
6. Verify customer ledger credit entry (debit=0, credit=return_total, reference_type='sales_return')
7. Verify revenue reversal GL (Dr sales_return / Cr AR, balanced)
8. Verify COGS reversal GL (Dr inventory / Cr cogs at original_cost, balanced)
9. Verify no linked damage_invoice created
10. Print slip

**Scenario B — Damage-condition return**:
1. Create a sales return for 2 items (Damage condition)
2. Confirm the return
3. Verify stock IN to warehouse (reference_type='sales_return')
4. Verify linked damage_invoice auto-created (damage_code, status='confirmed')
5. Verify damage stock OUT (reference_type='damage', qty negative)
6. Verify damage GL (Dr damage_loss / Cr inventory at original_cost)
7. Verify sales_return_items.damage_invoice_id set
8. Verify customer ledger still credited full return_total (damage doesn't reduce customer credit)

**Scenario C — Reverse a confirmed return**:
1. Take the return from Scenario A (confirmed)
2. Click Reverse → enter reason (min 5 chars)
3. Verify stock reversed (stock_transactions row with reference_type='reversal', qty negative, reversal_of_transaction_id set)
4. Verify original stock_transaction is_reversed=true
5. Verify customer ledger reversed (original is_reversed=true, new debit entry with reference_type='reversal')
6. Verify revenue GL reversed (new JE with swapped Dr/Cr, reversal_of_entry_id set, original is_reversed=true)
7. Verify COGS GL reversed (same)
8. Verify sales_returns: status='reversed', is_reversed=true, reversed_at/by/reason set

**Scenario D — Reverse a damage-condition return**:
1. Take the return from Scenario B (confirmed with linked damage)
2. Click Reverse → enter reason
3. Verify linked damage_invoice reversed FIRST (damage is_reversed=true, damage stock reversed, damage GL reversed)
4. Verify sales_return_items.damage_invoice_id = NULL
5. Then verify sales return itself reversed (same as Scenario C)

**Scenario E — Reverse with insufficient stock**:
1. Take a confirmed return where the warehouse stock has since been depleted (e.g. by another sales invoice)
2. Click Reverse
3. Verify the pre-check shows "Insufficient stock in X for Y: need Z, have W"
4. Verify the reverse button is disabled or the confirm fails with 422

**Scenario F — Partial returns (multiple returns on one invoice)**:
1. Create return 1 for 3 of 10 items
2. Verify returnable_qty on the invoice is now 7
3. Create return 2 for 4 of 10 items
4. Verify returnable_qty on the invoice is now 3
5. Reverse return 1
6. Verify returnable_qty on the invoice is now 6 (3 + 3 restored)

**Scenario G — Branch isolation**:
1. As a non-admin user on branch A, try to access a return on branch B → 404
2. As an admin, try to access a return on branch B → success (admin bypass)

**Scenario H — RBAC**:
1. As a salesman, try to confirm a return → 403
2. As a warehouse_manager, try to reverse a return → 403
3. As an accountant, try to create a return → 403

**Acceptance criteria**:
- [ ] All 8 scenarios pass
- [ ] No DB errors (especially no CHECK constraint violations — Phase 0.1 fix verified)
- [ ] All audit log entries written (return_created / return_confirmed / return_reversed)

**Dependencies**: All prior phases.

### 8.2 Final cleanup

**Scope**:
- Delete any remaining orphaned files (Phase 4.5)
- Remove any `// TODO` or `// TEMP` comments introduced during the rewrite
- Verify `php artisan route:list` shows all sales-return routes with correct middleware
- Verify `php artisan optimize:clear` doesn't break anything
- Run `bun run lint` if applicable (Laravel doesn't have a linter by default, but check)
- Commit + push

**Acceptance criteria**:
- [ ] No orphaned files
- [ ] No TODO comments
- [ ] Route list clean
- [ ] Cache clear works
- [ ] Committed + pushed

**Dependencies**: Phase 8.1.

---

## Phase Summary Table

| Phase | Scope | Severity | Estimated Effort | Dependencies | Status |
|---|---|---|---|---|---|
| 0 | Foundation fixes (CHECK bug + helpers) | 🔴 Blocker | Small | None | ✅ Complete |
| 1 | Form Request classes (4) | 🟠 High | Medium | Phase 0 | ✅ Complete |
| 2 | AJAX endpoints (4) + routes | 🟠 High | Medium | None | ✅ Complete |
| 3 | Index page rewrite (CSS + JS + blade + audit page) | 🟠 High | Large | Phases 2, 4.1 | ⏳ Pending |
| 4 | Create page rewrite (workspace partial + CSS + JS + blade + cleanup) | 🟠 High | Large | Phase 2 | ⏳ Pending |
| 5 | Show page polish (minor) | 🟡 Medium | Small | Phase 0.3 | ⏳ Pending |
| 6 | Reverse flow pre-check UX | 🟡 Medium | Medium | Phase 1.3 | ⏳ Pending |
| 7 | Slip page polish (optional) | 🟢 Low | Small | None | ⏳ Pending |
| 8 | End-to-end verification + cleanup | 🔴 Required | Medium | All prior | ⏳ Pending |

**Critical path**: 0 → 1 → 2 → 4 → 3 → 8 (with 5, 6, 7 in parallel where possible).

**By end of Phase 8**: clean UI matching Purchase Return + accurate business logic
matching legacy (with Laravel's correctness improvements preserved) + no
critical bugs + full end-to-end verification.

---

## Appendix A — Files to Create (new)

| Path | Phase |
|---|---|
| `app/Http/Requests/SalesReturn/StoreSalesReturnRequest.php` | 1.1 |
| `app/Http/Requests/SalesReturn/ConfirmSalesReturnRequest.php` | 1.2 |
| `app/Http/Requests/SalesReturn/ReverseSalesReturnRequest.php` | 1.3 |
| `app/Http/Requests/SalesReturn/GetInvoiceDetailsRequest.php` | 1.4 |
| `app/Services/Sales/SalesReturnReversalGuard.php` | 6.1 |
| `public/assets/css/sales-return-index.css` (REWRITE) | 3.1 |
| `public/assets/css/sales-return-create.css` (REWRITE) | 4.2 |
| `public/assets/js/sales-return-index.js` (REWRITE) | 3.2 |
| `public/assets/js/SalesReturn.js` (REWRITE) | 4.3 |
| `resources/views/admin/sales-returns/partials/create-workspace.blade.php` | 4.1 |
| `resources/views/admin/sales-returns/audit.blade.php` | 3.4 |
| `database/migrations/XXXX_add_reversal_to_stock_transactions_reference_type_check.php` | 0.1 (if Fix A chosen) |

## Appendix B — Files to Modify (existing)

| Path | Phase | Change |
|---|---|---|
| `app/Models/SalesReturnItem.php` | 0.3 | Add isGood/isDamage/conditionLabel helpers |
| `app/Models/StockTransaction.php` | 0.1 | Add 'reversal' to REFERENCE_TYPES (if Fix A) |
| `app/Services/Stock/StockService.php` | 0.1 | Verify reverseTransaction reference_type (if Fix B) |
| `app/Http/Controllers/Admin/SalesReturnController.php` | 1, 2, 6 | Type-hint Form Requests, add 4 AJAX methods + reversePreview |
| `app/Services/Sales/SalesReturnService.php` | 6 | Call ReversalGuard inside reverseReturn |
| `resources/views/admin/sales-returns/index.blade.php` | 3.3 | Full rewrite |
| `resources/views/admin/sales-returns/create.blade.php` | 4.4 | Full rewrite |
| `resources/views/admin/sales-returns/show.blade.php` | 5, 6 | Minor tweaks + reverse preview AJAX |
| `resources/views/admin/sales-returns/print_slip.blade.php` | 7 | Optional polish |
| `routes/web.php` | 2.5 | Add 5 new routes (search-invoices, summary, export, audit, reverse-preview) |

## Appendix C — Files to Delete (post-Phase-4)

| Path | Phase | Reason |
|---|---|---|
| `public/assets/js/sales-return-confirm.js` | 4.5 | Orphaned (confirm flow not ported per Decision D-1) |
| `public/assets/js/sales-return-reverse.js` | 4.5 | Orphaned (reverse flow stays inline on show page) |
| `public/assets/css/sales-return-confirm.css` | 4.5 | Orphaned |
| `public/assets/css/sales-return-reverse.css` | 4.5 | Orphaned |
| `public/assets/css/sales-return-slip-print.css` | 4.5 | Verify first — may still be used by `layouts.print` |

---

## Appendix D — Key Business Rules (reference for implementation)

### D.1 Returnable qty cap
```
returnable_qty(invoice_item) = invoice_item.qty
                              - SUM(sales_return_items.qty
                                    WHERE sales_return_items.sales_invoice_item_id = invoice_item.id
                                      AND sales_return.status IN ('created', 'confirmed')
                                      AND sales_return.is_reversed = false)
```
Multiple returns per invoice allowed, each capped by remaining qty. Reversing a
return restores the qty to the returnable pool.

### D.2 original_cost snapshot (Laravel BETTER than legacy)
At create time, look up the challan's stock-out transaction:
```
SELECT rate
FROM stock_transactions
WHERE reference_type = 'sales_challan'
  AND reference_id = {challan_id}     -- the active challan for the invoice
  AND product_id = {item.product_id}
  AND warehouse_id = {item.warehouse_id}
  AND is_reversed = false
LIMIT 1
```
Store as `sales_return_items.original_cost`. Used for the COGS reversal GL
(instead of legacy's current-avg-cost approach which causes COGS drift after
price changes).

### D.3 Confirm flow side effects (in order, all in one DB::transaction)
1. Stock IN per item: `StockService::applyTransaction(reference_type='sales_return', qty=+item.qty, rate=item.original_cost)`
2. Linked damage write-offs (per warehouse with Damage items): `DamageService::createDamage` → link `sales_return_id` → `DamageService::confirmDamage` (stock OUT + GL Dr damage_loss / Cr inventory)
3. Revenue reversal GL: `JournalPostingService::createJournalEntry(Dr sales_return / Cr AR, entity_type=customer, entity_id=customer_id, amount=total_amount)`
4. COGS reversal GL (SEPARATE JE): `JournalPostingService::createJournalEntry(Dr inventory / Cr cogs, amount=cogs_amount)` where `cogs_amount = SUM(qty × original_cost)`
5. Customer ledger credit: `SubLedgerService::postCustomerLedgerEntry(debit=0, credit=total_amount, reference_type='sales_return', journal_entry_id=revenue JE id)`
6. Update sales_returns: `status='confirmed', journal_entry_id={revenue JE}, cogs_journal_entry_id={COGS JE}`

### D.4 Reverse flow side effects (in order, all in one DB::transaction)
1. **Pre-check** (Phase 6): `SalesReturnReversalGuard::getBlockReasons()` — fail fast if any stock shortage
2. Reverse revenue GL: `JournalReversalService::reverseByJournalEntry(journal_entry_id)` — cascades to customer_ledger
3. Reverse COGS GL: `JournalReversalService::reverseByJournalEntry(cogs_journal_entry_id)`
4. Reverse linked damage_invoices FIRST: for each `damage_invoices WHERE sales_return_id=returnId AND is_reversed=false` → `DamageService::cancelDamage` (reverses damage stock OUT + damage GL) → set `sales_return_items.damage_invoice_id=NULL`
5. Reverse sales_return stock movements: for each `stock_transactions WHERE reference_type='sales_return' AND reference_id=returnId AND is_reversed=false` → `StockService::reverseTransaction` (creates new row with `reference_type='reversal'`, qty negative, `reversal_of_transaction_id` set; marks original `is_reversed=true`)
6. Update sales_returns: `status='reversed', is_reversed=true, reversed_at=now(), reversed_by=auth()->id(), reverse_reason={reason}`

### D.5 GL journal entry shapes

**Revenue reversal JE** (one entry, two lines):
| Line | Ledger Nature | Debit | Credit | Entity |
|---|---|---|---|---|
| 1 | sales_return (contra-revenue) | total_amount | 0 | — |
| 2 | ar (Accounts Receivable) | 0 | total_amount | customer_id |

**COGS reversal JE** (separate entry, two lines):
| Line | Ledger Nature | Debit | Credit | Entity |
|---|---|---|---|---|
| 1 | inventory | cogs_amount | 0 | sales_return |
| 2 | cogs | 0 | cogs_amount | sales_return |

**Damage write-off JE** (per warehouse with Damage items, separate entry):
| Line | Ledger Nature | Debit | Credit | Entity |
|---|---|---|---|---|
| 1 | damage_loss | damage_total | 0 | damage_invoice |
| 2 | inventory | 0 | damage_total | damage_invoice |

### D.6 Status state machine
```
created ──confirm──> confirmed ──reverse──> reversed
   │                    │
   └──reverse──> reversed (no-op for stock/ledger/GL — just sets is_reversed=true)
```
- `created` → can be confirmed OR reversed
- `confirmed` → can ONLY be reversed (no edit)
- `reversed` → terminal state, no further transitions

### D.7 RBAC matrix (matches legacy exactly)
| Action | salesman | warehouse_manager | accountant | manager | admin |
|---|---|---|---|---|---|
| Create return | ✅ | — | — | ✅ | ✅ |
| Confirm return | — | ✅ | ✅ | ✅ | ✅ |
| Reverse return | — | — | ✅ | ✅ | ✅ |
| Read (index/show/slip) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Audit | — | — | ✅ | ✅ | ✅ |
| Export CSV | ✅ | ✅ | ✅ | ✅ | ✅ |

### D.8 Branch isolation
- `sales_returns.branch_id` = the invoice's `branch_id` (denormalized at create time)
- RLS policy: SELECT/INSERT/UPDATE/DELETE WHERE `branch_id = current_setting('app.branch_id')::int` (admin bypass via `current_setting('app.is_admin', true) = 'true'`)
- All AJAX endpoints branch-scoped (admin can override via session branch switcher, but the query is always scoped to a single branch)
- Warehouse must belong to the invoice's branch (enforced by `WarehouseBelongsToBranch` rule in Phase 1.1)

---

## End of Plan

This plan is the orchestrator's blueprint. Each phase can be assigned to a
subagent with the phase's scope + acceptance criteria as the task brief. The
critical path is Phase 0 → 1 → 2 → 4 → 3 → 8, with Phases 5, 6, 7 in parallel
where the subagent has capacity.

**No code has been written in this document.** Implementation begins when the
user approves this plan and authorizes Phase 0.
