# RC-ERP v2 — Pre-Handover Setup Guide

> **Project:** RC-ERP (Laravel + PostgreSQL)  
> **Migrating From:** Lagachy Legacy Software  
> **Handover Date:** 2026-08-13  
> **Purpose:** Complete checklist of everything that must be configured before the client goes live, split into **Developer Setup** (technical/system) and **Client Setup** (business data the client must provide/fill).  

---

## Table of Contents

1. [Already Migrated Data](#1-already-migrated-data)
2. [Developer Setup — System & Technical](#2-developer-setup--system--technical)
3. [Client Setup — Business Data to Fill](#3-client-setup--business-data-to-fill)
4. [Excel Import Sheets Specification](#4-excel-import-sheets-specification)
5. [Import Workflow (Excel → PostgreSQL)](#5-import-workflow-excel--postgresql)
6. [Dependency Order — What Must Be Done First](#6-dependency-order--what-must-be-done-first)
7. [Post-Import Verification Checklist](#7-post-import-verification-checklist)
8. [Risk Items & Warnings](#8-risk-items--warnings)

---

## 1. Already Migrated Data

The following master data has **already been transferred** from Lagachy:

| Data | Table(s) | Status |
|------|----------|--------|
| Branches | `branches` | ✅ HO, PAT, NOW, TAR |
| Employees | `employees` | ✅ All staff |
| Users | `users` | ✅ Login accounts |
| Customers | `customers` | ✅ Customer master |
| Suppliers | `suppliers` | ✅ Supplier master |
| Banks | `banks` | ✅ Bank accounts |
| Products | `products` + `product_categories` + `product_groups` | ✅ Product master |
| Product Price History | `product_price_history` | ✅ Historical pricing |

---

## 2. Developer Setup — System & Technical

These items **you (the developer) must handle** before handover. They are system-level configurations, seeded data, or technical setup that the client cannot do themselves.

### 2.1 — Database Schema & Baseline

| # | Item | Table(s) | How | Priority |
|---|------|----------|-----|----------|
| 1 | Run all migrations | All | `php artisan migrate` | 🔴 Critical |
| 2 | Seed Chart of Accounts | `ledgers` | `php artisan chart:seed` (migration `2025_01_05_000001`) | 🔴 Critical |
| 3 | Seed Menu Structure | `menus` | Migration `2025_01_10_000001` | 🔴 Critical |
| 4 | Seed User Menu Permissions | `user_menu_permissions` | Migration `2026_07_30_000006/7` — superadmin gets all menus | 🔴 Critical |
| 5 | Seed Units of Measure | `units_of_measure` | Migration `2025_08_06_000001` — Pcs, Carton, KG, Bag, Dobe, Set | 🔴 Critical |
| 6 | Seed Damage Reasons | `damage_reasons` | Migration `2026_01_01_000001` — ~15 standard reasons | 🟡 Medium |
| 7 | Seed Notification Rules | `notification_rules` + `notification_rule_recipients` | `NotificationRuleSeeder` — 18 default rules | 🟡 Medium |
| 8 | Seed Default Approval Workflow | `approval_workflows` + `approval_steps` | Migration `2026_08_10_000001` — Manual Journal approval | 🟡 Medium |
| 9 | Seed System Policy | `system_policies` | Migration `2025_01_07_000001` — NORMAL mode | 🟡 Medium |
| 10 | Validate Chart of Accounts | `ledgers` | `php artisan chart:validate` | 🔴 Critical |

### 2.2 — Fiscal Year & Accounting Period Initialization

| # | Item | Table(s) | Details | Priority |
|---|------|----------|---------|----------|
| 1 | Create Fiscal Year | `fiscal_years` | At minimum one active fiscal year (e.g., FY 2026-07 to 2027-06). Must set `is_current = true`. | 🔴 Critical |
| 2 | Create Fiscal Periods | `fiscal_periods` | Generate monthly periods within the fiscal year. Status = `open` for current/future, `closed` for past. | 🔴 Critical |
| 3 | Initialize Accounting Periods | `accounting_periods` | One row per branch with `closed_through_date` set to the day before go-live (so transactions from go-live onward are allowed). | 🔴 Critical |

### 2.3 — Document Sequence Initialization

Every document type needs a starting sequence per branch. These auto-increment as documents are created.

| # | Document Type | `doc_type` Code | Initial `last_number` | Priority |
|---|--------------|-----------------|----------------------|----------|
| 1 | Sales Invoice | `SI` | Set to last Lagachy invoice number per branch | 🔴 Critical |
| 2 | Sales Challan | `SC` | 0 or last Lagachy challan number | 🔴 Critical |
| 3 | Sales Return | `SR` | 0 | 🟡 Medium |
| 4 | Purchase Order | `PO` | 0 | 🟡 Medium |
| 5 | Purchase Receive | `PR` | 0 | 🟡 Medium |
| 6 | Purchase Return | `PT` | 0 | 🟡 Medium |
| 7 | Customer Payment | `CP` | 0 | 🔴 Critical |
| 8 | Supplier Payment | `SP` | 0 | 🔴 Critical |
| 9 | Money Transfer | `MT` | 0 | 🟡 Medium |
| 10 | Stock Adjustment | `SA` | 0 | 🟡 Medium |
| 11 | Stock Take Session | `ST` | 0 | 🟡 Medium |
| 12 | Warehouse Transfer | `WT` | 0 | 🟡 Medium |
| 13 | Damage Invoice | `DM` | 0 | 🟡 Medium |
| 14 | Branch Demand | `BD` | 0 | 🟡 Medium |
| 15 | Manual Journal | `MJ` | 0 | 🟡 Medium |
| 16 | Other Income | `OI` | 0 | Low |
| 17 | Other Expense | `OE` | 0 | Low |
| 18 | Employee Transaction | `ET` | 0 | Low |
| 19 | Journal Entry | `JE` | 0 | 🔴 Critical |

> **Insert format:** `INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number) VALUES ('SI', 1, '202608', 0);`  
> The `period_key` is typically `YYYYMM` format.

### 2.4 — Bank → Ledger Mapping

Each bank account in the `banks` table must be linked to a General Ledger account.

| # | Task | Table | Details |
|---|------|-------|---------|
| 1 | Map each bank to its GL ledger | `bank_ledger_mappings` | `bank_id` → `ledger_id` (typically "Bank — [Account Name]" ledger under Assets > Current Assets) |
| 2 | Update `banks.ledger_id` | `banks` | Set the primary ledger_id on each bank row |

> **Important:** The Chart of Accounts must already be seeded before this step. Identify which ledger corresponds to each bank account and create the mapping.

### 2.5 — Warehouse Setup Verification

| # | Task | Details |
|---|------|---------|
| 1 | Verify warehouses exist for each branch | At minimum 1 warehouse per branch in `warehouses` table |
| 2 | Set `is_active = true` | All active warehouses |
| 3 | Set `is_frozen_for_count = false` | Unless a stock take is in progress at go-live |

### 2.6 — Company Setup (If Using Consolidation)

| # | Task | Table | Details |
|---|------|-------|---------|
| 1 | Create company record | `companies` | The parent company (e.g., "RC Trading") |
| 2 | Link branches to company | `branches.company_id` | Set `company_id` on all branch records |

### 2.7 — Environment & Security

| # | Task | Details |
|---|------|---------|
| 1 | `.env` configuration | `APP_URL`, `DB_*`, `APP_TIMEZONE=Asia/Dhaka`, `APP_LOCALE=en` |
| 2 | Storage link | `php artisan storage:link` |
| 3 | Passport/Sanctum tokens | If API auth is used, run `php artisan passport:install` |
| 4 | Queue workers | Configure supervisor for `php artisan queue:work` |
| 5 | Schedule cron | `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1` |
| 6 | Login rate limiting | Verify `login_rate_limits` table exists |
| 7 | Backup strategy | pg_dump cron job for daily backups |
| 8 | Partition maintenance | Verify monthly partition creation for partitioned tables |

---

## 3. Client Setup — Business Data to Fill

These are **business data items** that the client must provide. Some come from Lagachy (opening balances), some are new configuration for the Laravel system.

### 3.1 — 🔴 CRITICAL: Opening Balances (From Lagachy)

These represent the financial position as of the cutover date (the last day of Lagachy operation). **If these are wrong, the entire accounting will be wrong from day one.**

#### 3.1.1 — Customer Opening Due

> The total outstanding amount each customer owes as of the cutover date. This is the AR balance from Lagachy.

| Field | Description | Example |
|-------|-------------|---------|
| `customer_code` | Existing customer code from master | `C-001` |
| `opening_due_amount` | Total outstanding balance (BDT) | `15,500.00` |
| `as_of_date` | Cutover date | `2026-08-12` |
| `invoice_references` | (Optional) Breakdown by Lagachy invoice number | `INV-1001: 5500, INV-1002: 10000` |

**Target tables:** `customer_ledger` (insert opening debit entry), `customers.opening_balance` + `customers.balance_type = 'debit'`

#### 3.1.2 — Supplier Opening Due

> The total amount the company owes each supplier as of the cutover date. This is the AP balance from Lagachy.

| Field | Description | Example |
|-------|-------------|---------|
| `supplier_code` | Existing supplier code from master | `S-001` |
| `opening_due_amount` | Total outstanding balance (BDT) | `42,000.00` |
| `as_of_date` | Cutover date | `2026-08-12` |
| `invoice_references` | (Optional) Breakdown by Lagachy purchase invoice | `PO-2001: 22000, PO-2003: 20000` |

**Target tables:** `supplier_ledger` (insert opening credit entry), `suppliers.opening_balance` + `suppliers.balance_type = 'credit'`

#### 3.1.3 — Bank Opening Balance

> The bank account balance as of the cutover date. This is the actual balance per the bank statement/ Lagachy bank register.

| Field | Description | Example |
|-------|-------------|---------|
| `bank_id` / `bank_name` | Existing bank account | `City Bank - Main` |
| `opening_balance` | Balance as of cutover (BDT) | `5,50,000.00` |
| `as_of_date` | Cutover date | `2026-08-12` |

**Target table:** `banks.balance` (update), plus a journal entry to record the opening balance in the GL.

#### 3.1.4 — Cash in Hand (Branch Cash Opening)

> Physical cash held at each branch as of cutover.

| Field | Description | Example |
|-------|-------------|---------|
| `branch_code` | Branch identifier | `HO` |
| `cash_point` | Cash register/point name | `Counter-1` |
| `opening_balance` | Cash in hand (BDT) | `25,000.00` |

**Target table:** `branch_cash` (insert with initial balance)

#### 3.1.5 — Warehouse Opening Stock

> Current physical stock quantity and average cost per product per warehouse as of cutover. This is **THE most important opening data** — wrong stock means wrong COGS, wrong P&L.

| Field | Description | Example |
|-------|-------------|---------|
| `warehouse_code` | Warehouse identifier | `WH-HO-01` |
| `product_code` | Product identifier | `P-001` |
| `qty_on_hand` | Current quantity in base unit | `150` |
| `avg_cost` | Average/weighted cost per unit (BDT) | `85.50` |

**Target tables:** `warehouse_stock` (upsert), `stock_transactions` (insert opening transaction with `reference_type = 'opening_balance'`), `branch_product_cost` (per-branch avg cost)

#### 3.1.6 — Employee Opening Balance (Loans/Advances)

> If any employee has outstanding loan or advance balance from Lagachy.

| Field | Description | Example |
|-------|-------------|---------|
| `employee_code` | Employee identifier | `E-001` |
| `transaction_type` | `loan` or `advance` | `loan` |
| `outstanding_amount` | Remaining balance (BDT) | `10,000.00` |
| `as_of_date` | Cutover date | `2026-08-12` |

**Target table:** `employee_ledger` (insert opening entry)

---

### 3.2 — 🟡 IMPORTANT: Business Configuration

#### 3.2.1 — Customer Credit Limits

> Each customer should have a credit limit set. Without this, sales may exceed acceptable risk.

| Field | Description | Example |
|-------|-------------|---------|
| `customer_code` | Customer identifier | `C-001` |
| `credit_limit` | Maximum outstanding allowed (BDT) | `50,000.00` |

**Target table:** `customers.credit_limit` (update)

#### 3.2.2 — Product UOM Conversions

> Unit of Measure conversion factors for products sold in multiple units (e.g., 1 Carton = 12 Pcs).

| Field | Description | Example |
|-------|-------------|---------|
| `product_code` | Product identifier | `P-001` |
| `from_uom` | Larger unit | `Carton` |
| `to_uom` | Smaller unit | `Pcs` |
| `factor` | Conversion factor | `12` |

**Target table:** `product_uom_conversions`

#### 3.2.3 — Salesman Assignment to Customers

> Each customer should have a default sales person assigned for commission and routing.

| Field | Description | Example |
|-------|-------------|---------|
| `customer_code` | Customer identifier | `C-001` |
| `sales_person_code` | Employee code of salesman | `E-010` |

**Target table:** `customers.sales_person_id` (update)

#### 3.2.4 — Commission Rules

> If salesmen earn commission, rules must be defined before first sale.

| Field | Description | Example |
|-------|-------------|---------|
| `salesman_code` | Employee code | `E-010` |
| `rule_type` | `flat`, `tiered`, `product_group`, or `target_bonus` | `flat` |
| `rate` | Commission percentage | `2.5` |
| `effective_from` | Start date | `2026-08-13` |
| `effective_to` | End date (nullable = ongoing) | `NULL` |

**Target tables:** `commission_rules`, `commission_rule_tiers`, `commission_rule_product_groups`, `commission_rule_targets`

---

### 3.3 — 🟢 OPTIONAL: Advanced Configuration

#### 3.3.1 — Dimensions & Cost Centers

> For segment reporting, department tracking, or cost center allocation.

| Field | Description | Example |
|-------|-------------|---------|
| `dimension_name` | Dimension type | `Department` |
| `dimension_code` | Code | `DEPT` |
| `value_name` | Specific value | `Sales` |
| `value_code` | Value code | `SALES` |
| `branch_code` | Branch scope (or `ALL` for company-wide) | `HO` |

**Target tables:** `dimensions`, `dimension_values`

#### 3.3.2 — Fixed Asset Register

> If tracking fixed assets with depreciation.

| Field | Description | Example |
|-------|-------------|---------|
| `asset_code` | Asset identifier | `FA-001` |
| `asset_name` | Description | `Delivery Van - 1` |
| `acquisition_cost` | Purchase price (BDT) | `8,00,000` |
| `acquisition_date` | Date acquired | `2024-01-15` |
| `depreciation_method` | `straight_line` or `declining_balance` | `straight_line` |
| `useful_life_months` | Total useful life | `60` |
| `branch_code` | Owning branch | `HO` |

**Target tables:** `fixed_assets`, `asset_depreciation_schedules`

#### 3.3.3 — Budget Allocation

> If the client wants budget vs. actual tracking.

| Field | Description | Example |
|-------|-------------|---------|
| `budget_name` | Budget name | `FY2027 Annual Budget` |
| `fiscal_year` | Fiscal year ID | `1` |
| `ledger_code` | GL account | `4100` |
| `period` | Month/Quarter | `2026-08` |
| `amount` | Budget amount (BDT) | `5,00,000` |

**Target tables:** `budgets`, `budget_lines`

#### 3.3.4 — Elimination Rules (Consolidation)

> Only needed if the client uses multi-company consolidation.

**Target tables:** `elimination_rules`

---

## 4. Excel Import Sheets Specification

The Excel workbook should have the following sheets, one per data category. Each sheet maps directly to PostgreSQL table(s) and can be imported via a script.

### Sheet 1: `customer_opening_due`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| customer_code | Text | ✅ | `customers.customer_code` (lookup) | Must exist in customers table |
| customer_name | Text | ❌ | — | For reference only |
| opening_due | Decimal | ✅ | `customer_ledger.debit` | Amount owed BY customer TO us |
| as_of_date | Date | ✅ | `customer_ledger.transaction_date` | Cutover date |
| lagachy_invoice_ref | Text | ❌ | — | Original Lagachy invoice number(s) for audit trail |

### Sheet 2: `supplier_opening_due`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| supplier_code | Text | ✅ | `suppliers.supplier_code` (lookup) | Must exist in suppliers table |
| supplier_name | Text | ❌ | — | For reference only |
| opening_due | Decimal | ✅ | `supplier_ledger.credit` | Amount we owe TO supplier |
| as_of_date | Date | ✅ | `supplier_ledger.transaction_date` | Cutover date |
| lagachy_invoice_ref | Text | ❌ | — | Original Lagachy purchase ref |

### Sheet 3: `bank_opening_balance`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| bank_name | Text | ✅ | `banks.bank_name` (lookup) | Must exist in banks table |
| account_number | Text | ❌ | — | For identification |
| opening_balance | Decimal | ✅ | `banks.balance` | Actual balance per bank statement |
| as_of_date | Date | ✅ | — | Cutover date |

### Sheet 4: `cash_opening_balance`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| branch_code | Text | ✅ | `branches.branch_code` (lookup) | Must exist |
| cash_point | Text | ✅ | `branch_cash.cash_point` | e.g., "Counter-1", "Main" |
| opening_balance | Decimal | ✅ | `branch_cash.balance` | Cash in hand |

### Sheet 5: `warehouse_opening_stock`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| warehouse_code | Text | ✅ | `warehouses.warehouse_code` (lookup) | Must exist |
| product_code | Text | ✅ | `products.product_code` (lookup) | Must exist |
| qty_on_hand | Decimal | ✅ | `warehouse_stock.qty` | In base UOM (usually Pcs) |
| avg_cost | Decimal | ✅ | `warehouse_stock.avg_cost` | Weighted average cost per unit |
| total_value | Decimal | ❌ | `warehouse_stock.total_value` | Auto-calculated: qty × avg_cost if blank |

### Sheet 6: `employee_opening_balance`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| employee_code | Text | ✅ | `employees.employee_code` (lookup) | Must exist |
| employee_name | Text | ❌ | — | For reference |
| transaction_type | Text | ✅ | `employee_ledger.transaction_type` | `loan` or `advance` |
| outstanding_amount | Decimal | ✅ | `employee_ledger.debit` | Remaining balance |
| as_of_date | Date | ✅ | `employee_ledger.transaction_date` | Cutover date |

### Sheet 7: `customer_credit_limits`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| customer_code | Text | ✅ | `customers.customer_code` (lookup) | Must exist |
| credit_limit | Decimal | ✅ | `customers.credit_limit` | Max outstanding allowed |

### Sheet 8: `product_uom_conversions`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| product_code | Text | ✅ | `products.product_code` (lookup) | Must exist |
| from_uom | Text | ✅ | `units_of_measure.code` (lookup) | e.g., "Carton" |
| to_uom | Text | ✅ | `units_of_measure.code` (lookup) | e.g., "Pcs" |
| factor | Decimal | ✅ | `product_uom_conversions.factor` | e.g., 12 |

### Sheet 9: `salesman_customer_assignment`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| customer_code | Text | ✅ | `customers.customer_code` (lookup) | Must exist |
| sales_person_code | Text | ✅ | `employees.employee_code` (lookup) | Must exist, role = salesman |

### Sheet 10: `commission_rules`

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| salesman_code | Text | ✅ | `employees.employee_code` (lookup) | Must exist, role = salesman |
| rule_type | Text | ✅ | `commission_rules.rule_type` | flat / tiered / product_group / target_bonus |
| rate | Decimal | ✅ | `commission_rules.rate` | Percentage |
| effective_from | Date | ✅ | `commission_rules.effective_from` | Start date |
| effective_to | Date | ❌ | `commission_rules.effective_to` | NULL = ongoing |

### Sheet 11: `fixed_assets` (Optional)

| Column | Type | Required | DB Target | Notes |
|--------|------|----------|-----------|-------|
| asset_code | Text | ✅ | `fixed_assets.asset_code` | |
| asset_name | Text | ✅ | `fixed_assets.asset_name` | |
| acquisition_cost | Decimal | ✅ | `fixed_assets.acquisition_cost` | |
| acquisition_date | Date | ✅ | `fixed_assets.acquisition_date` | |
| depreciation_method | Text | ✅ | `fixed_assets.depreciation_method` | straight_line / declining_balance |
| useful_life_months | Integer | ✅ | `fixed_assets.useful_life_months` | |
| branch_code | Text | ✅ | `branches.branch_code` (lookup) | |
| asset_ledger_code | Text | ✅ | `ledgers.ledger_code` (lookup) | Fixed Asset GL account |
| dep_ledger_code | Text | ✅ | `ledgers.ledger_code` (lookup) | Accumulated Depreciation GL |

---

## 5. Import Workflow (Excel → PostgreSQL)

### Step-by-Step Process

```
1. Developer prepares Excel template (empty, with headers + validation)
2. Client fills Excel with business data
3. Client returns Excel to developer
4. Developer validates data (referential integrity, no orphans, no negatives)
5. Developer runs import script → PostgreSQL
6. Developer runs post-import verification
7. Developer creates opening journal entries (to balance the GL)
8. Sign-off and go-live
```

### Import Script Logic (Pseudocode)

```python
# For each sheet in Excel:
#   1. Read sheet into DataFrame
#   2. Validate all lookup columns exist in their target tables
#   3. Validate numeric columns are non-negative
#   4. Insert/Update target PostgreSQL tables
#   5. Log inserted/updated row counts
#   6. Flag any errors for manual review
```

### Opening Journal Entry (Critical!)

After all opening balances are imported, a **single compound journal entry** must be created to record the opening position in the General Ledger. This ensures the accounting equation holds:

```
DEBITS                          CREDITS
────────                        ───────
Bank Opening Balances    →      Retained Earnings (or Opening Balance Equity)
Customer Opening Due     →
Fixed Assets             →
Cash in Hand             →

                        ←      Supplier Opening Due
                        ←      Employee Loans/Advances
                        ←      Accumulated Depreciation
```

The difference (Debits − Credits) must equal zero. If it doesn't, the imbalance goes to an "Opening Balance Equity" or "Retained Earnings" ledger account.

---

## 6. Dependency Order — What Must Be Done First

This is the **correct sequence** of setup. Each step depends on previous steps being complete.

```
PHASE 1: System Foundation (Developer — Day 1)
─────────────────────────────────────────────
  1.1  Run migrations (schema + seeded data)
  1.2  Seed Chart of Accounts (ledgers)
  1.3  Seed Menus + Permissions
  1.4  Seed Units of Measure
  1.5  Seed Damage Reasons
  1.6  Seed Notification Rules
  1.7  Seed Approval Workflows
  1.8  Seed System Policy
  1.9  Validate Chart of Accounts

PHASE 2: Master Data Verification (Developer — Day 1-2)
──────────────────────────────────────────────────────
  2.1  Verify branches, employees, users migrated correctly
  2.2  Verify customers, suppliers migrated correctly
  2.3  Verify products, categories, groups migrated correctly
  2.4  Verify banks migrated correctly
  2.5  Set up warehouses per branch
  2.6  Map banks to GL ledgers (bank_ledger_mappings)
  2.7  Create company record + link branches (if consolidation)

PHASE 3: Fiscal Setup (Developer — Day 2)
──────────────────────────────────────────
  3.1  Create fiscal year (FY 2026-07 to 2027-06)
  3.2  Generate fiscal periods (monthly)
  3.3  Initialize accounting_periods per branch
  3.4  Initialize document_sequences per (doc_type, branch)

PHASE 4: Client Data Collection (Client — Day 2-3)
─────────────────────────────────────────────────
  4.1  Send Excel template to client
  4.2  Client fills: Customer Opening Due
  4.3  Client fills: Supplier Opening Due
  4.4  Client fills: Bank Opening Balance
  4.5  Client fills: Cash Opening Balance
  4.6  Client fills: Warehouse Opening Stock
  4.7  Client fills: Employee Opening Balance (if any)
  4.8  Client fills: Customer Credit Limits
  4.9  Client fills: Product UOM Conversions
  4.10 Client fills: Salesman Assignments
  4.11 Client fills: Commission Rules (if applicable)
  4.12 Client fills: Fixed Assets (if applicable)

PHASE 5: Data Import (Developer — Day 3-4)
───────────────────────────────────────────
  5.1  Validate all Excel data
  5.2  Import customer opening dues → customer_ledger + update customers.opening_balance
  5.3  Import supplier opening dues → supplier_ledger + update suppliers.opening_balance
  5.4  Import bank opening balances → update banks.balance
  5.5  Import cash opening balances → branch_cash
  5.6  Import warehouse stock → warehouse_stock + stock_transactions (opening)
  5.7  Import employee opening balances → employee_ledger
  5.8  Import credit limits → customers.credit_limit
  5.9  Import UOM conversions → product_uom_conversions
  5.10 Import salesman assignments → customers.sales_person_id
  5.11 Import commission rules → commission_rules + child tables
  5.12 Import fixed assets → fixed_assets

PHASE 6: Opening Journal Entry (Developer — Day 4)
─────────────────────────────────────────────────
  6.1  Calculate total debits and credits from all opening balances
  6.2  Create compound journal entry to record opening position in GL
  6.3  Verify: SUM(debits) = SUM(credits) — if not, post difference to Opening Balance Equity
  6.4  Post the journal entry (status = posted)

PHASE 7: Verification & Go-Live (Developer + Client — Day 4-5)
──────────────────────────────────────────────────────────────
  7.1  Verify customer AR aging matches Lagachy
  7.2  Verify supplier AP aging matches Lagachy
  7.3  Verify bank balances match Lagachy
  7.4  Verify stock quantities match Lagachy
  7.5  Verify trial balance balances
  7.6  Test one complete sales cycle (create → confirm → dispatch → challan → payment)
  7.7  Test one complete purchase cycle (PO → GRN → payment)
  7.8  Sign-off from client
  7.9  GO LIVE 🚀
```

---

## 7. Post-Import Verification Checklist

After all data is imported, run these checks:

| # | Check | SQL / Method | Expected Result |
|---|-------|--------------|-----------------|
| 1 | All customers have opening balance entry | `SELECT COUNT(*) FROM customers c LEFT JOIN customer_ledger cl ON ... WHERE cl.id IS NULL AND c.is_active = true` | 0 (no active customers without ledger) |
| 2 | All suppliers have opening balance entry | Same pattern for `suppliers` / `supplier_ledger` | 0 |
| 3 | All banks have GL mapping | `SELECT COUNT(*) FROM banks b LEFT JOIN bank_ledger_mappings blm ON ... WHERE blm.id IS NULL` | 0 |
| 4 | All warehouses have stock for expected products | Check `warehouse_stock` completeness | All active products have entries |
| 5 | Opening journal entry balances | `SELECT SUM(debit) - SUM(credit) FROM journal_lines WHERE journal_entry_id = <opening_je_id>` | 0.00 |
| 6 | Document sequences exist for all doc types | Check `document_sequences` per branch | All 19 doc types × 4 branches = 76 rows |
| 7 | Fiscal year is active | `SELECT COUNT(*) FROM fiscal_years WHERE is_current = true` | 1 |
| 8 | Accounting periods initialized | `SELECT COUNT(*) FROM accounting_periods` | ≥ 1 per branch |
| 9 | Trial balance (post-opening) | Run trial balance report | Debits = Credits |
| 10 | User can login and see menus | Manual test | All menus visible for superadmin |

---

## 8. Risk Items & Warnings

### 🔴 High Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Opening stock wrong** | COGS, P&L, Balance Sheet all wrong from day 1 | Physical stock count on cutover day, compare with Lagachy |
| **Customer/Supplier opening due wrong** | AR/AP aging mismatch, wrong payment collection targets | Get aged trial balance from Lagachy and reconcile |
| **Missing bank→ledger mapping** | Payments won't post to GL, journal entries will fail | Pre-create all mappings before any transaction |
| **Fiscal year not created** | No transactions can be posted | Create before any business operation |
| **Document sequences overlap** | Duplicate document numbers with Lagachy | Set starting number higher than last Lagachy number |

### 🟡 Medium Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| **UOM conversions missing** | Wrong quantity in sales/purchase (e.g., selling cartons but system thinks pcs) | Client must fill product_uom_conversions sheet |
| **No credit limits set** | Unlimited sales on credit → bad debt risk | Set reasonable defaults if client doesn't provide |
| **Commission rules not configured** | No commission calculated for salesmen | Not blocking for go-live, but set up within first week |
| **Employee opening balances missed** | Outstanding loans/advances lost | Check Lagachy HR/loan module |

### 🟢 Low Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Fixed assets not imported** | Depreciation not tracked from day 1 | Can be imported later |
| **Budgets not set** | No budget vs. actual reporting | Can be set up later |
| **Dimensions not configured** | No cost center tracking | Can be set up later |

---

## Quick Summary — Who Does What

| Category | Developer | Client |
|----------|-----------|--------|
| Database schema & migrations | ✅ | |
| Chart of Accounts seeding | ✅ | |
| Menu & permissions | ✅ | |
| System seeded data (UOM, damage reasons, etc.) | ✅ | |
| Fiscal year & periods | �' | |
| Document sequences | ✅ | |
0 | Bank → Ledger mapping | ✅ | |
| Warehouse setup | ✅ | |
| Company setup | ✅ | |
| Environment & security | ✅ | |
| **Customer opening due** | | ✅ (fill Excel) |
| **Supplier opening due** | | ✅ (fill Excel) |
| **Bank opening balance** | | ✅ (fill Excel) |
| **Cash opening balance** | | ✅ (fill Excel) |
| **Warehouse opening stock** | | ✅ (fill Excel) |
| **Employee opening balance** | | ✅ (fill Excel) |
| **Customer credit limits** | | ✅ (fill Excel) |
| **Product UOM conversions** | | ✅ (fill Excel) |
| **Salesman assignments** | | ✅ (fill Excel) |
| **Commission rules** | | ✅ (fill Excel) |
| **Fixed assets** | | ✅ (fill Excel, optional) |
| Data import & validation | ✅ | |
| Opening journal entry | ✅ | |
| Go-live verification | ✅ | ✅ (sign-off) |

---

> **Last Updated:** 2026-08-12  
> **Document Version:** 1.0  
> **Author:** Developer Setup Guide for RC-ERP v2 Migration
