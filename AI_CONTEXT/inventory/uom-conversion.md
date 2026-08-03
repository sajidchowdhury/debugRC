# UoM Conversion

> **Module:** Inventory (Phase 8)
> **Audience:** Engineers + AI assistants + **accountants** (must review before Canonical)
> **Status:** Draft — pending accountant sign-off
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Stock/UomConversionService.php` (207 lines) + `laravel/database/sql/01_auth_and_master.sql:139-184` (`products.unit` CHECK + `units_of_measure` + `product_uom_conversions` DDL)

## 1. What is it?

**Unit of Measure (UoM) conversion** lets the ERP translate quantities between different units
for the same product: 1 Carton = 12 Pcs, 1 Bag = 50 KG, etc. The system maintains a per-product
conversion table (`product_uom_conversions`) mapping any `from_uom` to the product's `base_uom`
with a `factor`.

The **base unit** is the unit in which stock is valued and tracked in the ledger. The
`products.unit` column (CHECK enum: `Pcs`, `Carton`, `KG`, `Bag`, `Dobe`, `Set`) defines the base
unit per product.

## 2. Why does it exist?

- Purchase orders may be in Cartons (bulk), sales in Pcs (retail), but the stock ledger MUST be
  in a single base unit to compute moving-average cost correctly. UoM conversion translates at
  the service layer before posting to `stock_transactions`.
- The `product_uom_conversions` table lets each product have its own conversion factors (1 Carton
  of Product A might be 12 Pcs; 1 Carton of Product B might be 24 Pcs).
- The base unit is the unit whose code matches `products.unit` — implicitly, the conversion to
  base is factor 1.0 (self-conversion).

## 3. When is it used?

- **Stock Adjustment** (Phase 5) — the only module with explicit UOM support. The user picks a
  UoM from a dropdown, enters `qty_entered`, and the service computes `qty_base = qty_entered ×
  factor` for the stock ledger.
- **Sales / Purchase / Damage / Warehouse Transfer** — these modules do **NOT** have UOM
  dropdowns (§12 #1 gap). They assume `qty` is in the base unit. If a salesman enters "2 Cartons"
  in a sales invoice, the system records `qty=2` in the base unit (Pcs), which is wrong — the
  salesman should have entered `24` (2 × 12).

## 4. Who uses it?

- **Accountants** use the UoM dropdown in Stock Adjustment.
- **The system** resolves conversions via `UomConversionService` (5-min Redis cache).
- **Admins** manage UoM master data via DB seeders / admin SQL (NO UI for
  `product_uom_conversions` — §12 #2 gap).

## 5. Related modules

- `stock-adjustment.md` — the only module with Phase 5 UOM columns (`uom_id`, `qty_entered`,
  `qty_base`, `uom_factor`).
- `stock-ledger.md` — the `stock_transactions.qty` is ALWAYS in the base unit.
- `stock-costing.md` — the `rate` is per-base-unit cost.
- `warehouse-stock.md` — the `warehouse_stock.qty` is in the base unit.

## 6. Business rules (the Core Rule)

- **MUST** define a base unit per product via `products.unit` (CHECK enum: `Pcs`, `Carton`, `KG`,
  `Bag`, `Dobe`, `Set`).
- **MUST** store `stock_transactions.qty` + `warehouse_stock.qty` in the BASE unit. NO `uom_id`
  column on `stock_transactions`.
- **MUST** resolve conversions via `UomConversionService::resolveFactor(productId, fromUomId)`,
  which returns the factor to convert `from_uom` → `base_uom`.
- **MUST** treat self-conversion (from_uom IS base_uom) as factor 1.0 — implicit, no DB row
  required.
- **MUST** snapshot the `uom_factor` on `stock_adjustment_items` at creation time (audit
  immutability — if the factor changes later, old adjustment rows retain the snapshot).
- **MUST NOT** allow a stock movement without a resolvable conversion (throws
  `InvalidArgumentException` with a clear message).
- **MUST NOT** support historical factor tracking — the CURRENT factor is used at creation time.
  There is no `effective_from / effective_to` on `product_uom_conversions`.
- **MUST** cache conversion lookups in Redis (5-min TTL, key prefix `uom:`). `clearCacheForProduct()`
  is best-effort (no-op for non-Redis stores).

## 7. Technical implementation

### 7.1 The `products.unit` base unit — `01_auth_and_master.sql:139`

```sql
unit varchar(20) NOT NULL CHECK (unit IN ('Pcs','Carton','KG','Bag','Dobe','Set')),
```

This is the BASE UNIT for each product. The UOM whose `code` matches this column is the product's
base unit.

### 7.2 The `units_of_measure` table — `01_auth_and_master.sql:163-173`

```sql
CREATE TABLE units_of_measure (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code varchar(20) NOT NULL UNIQUE,   -- Pcs, Carton, KG, Bag, Dobe, Set
    name varchar(60) NOT NULL,
    type varchar(20) NOT NULL,          -- count, weight, volume
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
```

NO RLS (global reference data). Seeded by migration `2025_08_06_000001_create_uom_tables.php`
from the `products.unit` enum values.

### 7.3 The `product_uom_conversions` table — `01_auth_and_master.sql:175-184`

```sql
CREATE TABLE product_uom_conversions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id integer NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    from_uom_id integer NOT NULL REFERENCES units_of_measure(id),
    to_uom_id integer NOT NULL REFERENCES units_of_measure(id),  -- usually the base
    factor numeric(14,6) NOT NULL,   -- 1 from_uom = factor to_uom
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT puc_product_from_to_unique UNIQUE (product_id, from_uom_id, to_uom_id)
);
```

### 7.4 The conversion formula — `UomConversionService.php:75-98` (verbatim)

```php
public function resolveFactor(int $productId, int $fromUomId): ?float
{
    $base = $this->resolveBaseUnit($productId);
    if (!$base) {
        return null;
    }
    // Self-conversion: the from-unit IS the base unit → factor 1.
    if ($fromUomId === $base->id) {
        return 1.0;
    }
    return Cache::remember(
        self::CACHE_PREFIX . "factor:{$productId}:{$fromUomId}:{$base->id}",
        self::CACHE_TTL,
        function () use ($productId, $fromUomId, $base) {
            $row = ProductUomConversion::where('product_id', $productId)
                ->where('from_uom_id', $fromUomId)
                ->where('to_uom_id', $base->id)
                ->first();
            return $row ? (float) $row->factor : null;
        }
    );
}
```

Base unit self-conversion is IMPLICIT (factor = 1.0). Non-base conversions require a
`product_uom_conversions` row with `to_uom_id = base unit id`.

### 7.5 Stock ledger handling of UoM

- `stock_transactions.qty` is ALWAYS in the BASE unit (`numeric(14,4)`). No `uom_id` column on
  `stock_transactions`.
- Sales invoices / challans / purchase receives / damages / warehouse transfers all post BASE qty
  to the stock ledger. The conversion (if any) happens at the service layer before calling
  `StockService::applyTransaction`.
- ONLY `stock_adjustment_items` carries the UOM snapshot (Phase 5): `uom_id`, `qty_entered`,
  `qty_base`, `uom_factor`. The legacy `qty` column stays authoritative (= `qty_base` for new
  rows).

### 7.6 Stock Adjustment UOM columns — `03_stock.sql:181-193`

Phase 5 added UOM columns to `stock_adjustment_items`:

| Column | Type | Notes |
|---|---|---|
| `uom_id` | FK units_of_measure (nullable) | the UoM the user entered |
| `qty_entered` | `numeric(14,4)` | the qty in the entered UoM |
| `qty_base` | `numeric(14,4)` | `qty_entered × uom_factor` — what posts to stock |
| `uom_factor` | `numeric(14,6)` | snapshotted at creation time (audit immutability) |

All nullable for back-compat (pre-Phase-5 rows have null UOM columns and use the legacy `qty`
column directly).

## 8. Intercompany / cross-branch

UoM conversion is a global reference-data operation. There is no intercompany or cross-branch
aspect — the conversion factors are per-product, not per-branch.

## 9. Workflow / state machine

UoM conversion has no state machine — it is a pure lookup function. The `product_uom_conversions`
table has no `status` or `is_active` column (the `units_of_measure` table has no `is_active`
column either — all units are considered active).

## 10. Validation & input rules

- `products.unit` is `varchar(20) CHECK IN ('Pcs','Carton','KG','Bag','Dobe','Set')`.
- `units_of_measure.code` is `varchar(20) UNIQUE`.
- `product_uom_conversions.factor` is `numeric(14,6) NOT NULL` — fractional factors allowed
  (e.g. 1 Pcs = 0.0833 Carton).
- `product_uom_conversions` has a UNIQUE constraint on `(product_id, from_uom_id, to_uom_id)`.
- Stock Adjustment item validation: `items.*.uom_id => nullable|integer|exists:units_of_measure,id`.

## 11. Reversal & correction flow

UoM conversion has no reversal — it is a pure lookup. The `uom_factor` snapshotted on
`stock_adjustment_items` at creation time is **immutable** — if the factor changes later, old
adjustment rows retain the snapshot. The reversal of a stock adjustment uses the snapshotted
factor (via the `qty_base` column) to compute the reversal qty.

## 12. Open questions / known gaps

1. **NO UOM support in Sales / Purchase / Damage / Warehouse Transfer** (§3) — only Stock
   Adjustment has Phase 5 UOM columns. Salesmen entering "2 Cartons" in a sales invoice record
   `qty=2` in the base unit (Pcs), which is wrong. **Recommended:** add UOM dropdowns to all
   stock-movement modules, mirroring the Stock Adjustment Phase 5 pattern.
2. **NO UI for `product_uom_conversions`** — UOM master data is seeded by migration;
   `product_uom_conversions` rows are added via DB seeders / admin SQL (no UI). **Recommended:**
   add an admin UI for managing per-product conversion factors.
3. **NO historical factor tracking** (§6) — the CURRENT factor is used at creation time, then
   snapshotted on `stock_adjustment_items.uom_factor`. If the factor changes later, old
   adjustment rows retain the snapshot (audit immutability), but NEW adjustments use the new
   factor — there is no `effective_from / effective_to` on `product_uom_conversions`.
   **Recommended:** add `effective_from / effective_to` columns for time-based factor lookup,
   if retroactive reporting is needed.
4. **Circular conversions** — `product_uom_conversions` has a UNIQUE constraint on
   `(product_id, from_uom_id, to_uom_id)` — both directions (A→B and B→A) can coexist. The
   service only resolves `from_uom → base`, so circular conversions are not a concern. But if a
   future feature needs A→B→C (chained), the service would need to be extended.
5. **No conversion defined** — `resolveFactor` returns `null`. The StockAdjustmentService throws
   `InvalidArgumentException` with a clear message ("No UOM conversion found for product X from
   UOM Y to base unit 'Z'"). **Recommended:** add a UI warning at product creation if no
   conversions are defined.
6. **Base unit change** — If `products.unit` changes (e.g. from 'Pcs' to 'KG'), the old
   `product_uom_conversions` rows with `to_uom_id = Pcs.id` become orphaned. The new base unit
   (KG) would have no conversions. This is NOT handled automatically — admin must clean up.
   **Recommended:** block base unit change if conversions exist, or auto-migrate.
7. **Fractional quantities** — `qty` is `numeric(14,4)` everywhere — fractional quantities ARE
   allowed (e.g. `1.5 Carton` = `18 Pcs` if factor=12). NO rounding rules beyond the column
   precision. **Accountant must confirm fractional quantities are acceptable** (some businesses
   require integer Pcs).
8. **Cache invalidation** — `clearCacheForProduct()` is best-effort (no-op for non-Redis stores).
   If the file/array cache driver is used, stale factors may persist for up to 5 min. **Recommended:**
   document the Redis requirement for UoM conversion cache.

## 13. Accountant review checklist

> **This is a SAFETY-CRITICAL document.** Before marking it Canonical, an accountant with
> production credentials MUST review and sign off on each item below.

- [ ] The base unit per product (§7.1) — `products.unit` CHECK enum — is the correct set of units
      for this business. Add/remove values as needed.
- [ ] The implicit self-conversion (§7.4) — from_uom IS base_uom → factor 1.0 — is correct.
- [ ] The `qty_base = qty_entered × uom_factor` formula (§7.6) — what posts to stock — is correct.
- [ ] The `uom_factor` snapshot at creation time (§7.6) — audit immutability — is the desired
      behaviour. Old adjustment rows retain the old factor even if it changes later.
- [ ] The lack of UOM support in Sales / Purchase / Damage / Warehouse Transfer (§12 #1) — is
      this a feature gap that should be addressed? Salesmen entering "2 Cartons" instead of "24
      Pcs" is a real-world risk.
- [ ] The lack of UI for `product_uom_conversions` (§12 #2) — is admin SQL acceptable, or should
      a UI be added?
- [ ] The lack of historical factor tracking (§12 #3) — is the snapshot-at-creation approach
      sufficient, or should `effective_from / effective_to` be added?
- [ ] The fractional quantities allowance (§12 #7) — is this acceptable, or should integer Pcs
      be enforced?
- [ ] The cache invalidation best-effort behaviour (§12 #8) — confirm Redis is the production
      cache driver.
