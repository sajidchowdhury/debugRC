# Schema Mapping Document — MySQL → PostgreSQL
## Phase 2.1 — RC_ERP Database Migration

**Source:** `osudlagb_remotecenter.sql` (MySQL 5.7/8.0, InnoDB, utf8mb4)
**Target:** PostgreSQL 16
**Tables:** 66 (+ 1 view, 2 triggers → 4 PG triggers/functions)
**Status:** ✅ Complete

---

## 1. Conversion Rules Applied

| MySQL Feature | PostgreSQL Equivalent | Notes |
|---|---|---|
| `int(11)` | `integer` | display width ignored |
| `bigint(20)` | `bigint` | |
| `tinyint(1)` | `boolean` | |
| `tinyint(4)` | `smallint` | |
| `varchar(n)` | `varchar(n)` | |
| `text` / `longtext` | `text` | |
| `decimal(p,s)` | `numeric(p,s)` | identical semantics |
| `float(20,2)` | `numeric(18,2)` | **FIX: banks.balance was FLOAT — precision loss for money** |
| `date` | `date` | |
| `datetime` | `timestamp(0)` | |
| `enum(...)` (38 columns) | `varchar(50) CHECK (col IN (...))` | extensible — adding values doesn't need type migration |
| `json` / `longtext CHECK json_valid` | `jsonb` | |
| `AUTO_INCREMENT` (123 columns) | `GENERATED ALWAYS AS IDENTITY` | |
| `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | (dropped) | PG uses database-wide UTF-8 |
| backtick identifiers `` `col` `` | unquoted `col` | PG folds to lowercase |
| `ON UPDATE CURRENT_TIMESTAMP` | trigger `update_updated_at_column()` | single function applied to 40+ tables |
| `GENERATED ALWAYS AS (if(...)) STORED` | `GENERATED ALWAYS AS (CASE WHEN ... THEN ... ELSE ... END) STORED` | MySQL `IF()` → PG `CASE` |
| `0000-00-00 00:00:00` | `NULL` | pgloader `zero-dates-to-null` cast |
| `SIGNAL SQLSTATE '45000'` (triggers) | `RAISE EXCEPTION ... USING ERRCODE = 'check_violation'` | |
| `SHOW COLUMNS FROM t LIKE 'c'` | `SELECT 1 FROM information_schema.columns WHERE ...` | in PHP code |
| `ON DUPLICATE KEY UPDATE col = VALUES(col)` | `ON CONFLICT (...) DO UPDATE SET col = EXCLUDED.col` | in PHP code |

---

## 2. Table Inventory (66 tables + 1 view)

### Auth & Access (5 tables)
| Table | PK | Key Changes |
|---|---|---|
| `branches` | `id` | + FK from employees.branch_id |
| `employees` | `id` | + FK to branches; role as VARCHAR+CHECK |
| `users` | `id` | **Phase 0: totp_secret/totp_enabled DROPPED**; +username UNIQUE, +credential_version, +telegram_user_id |
| `menus` | `id` | |
| `user_menu_permissions` | `id` | +UNIQUE(user_id, menu_id) |

### Master Data (10 tables)
| Table | PK | Key Changes |
|---|---|---|
| `product_categories` | `id` | |
| `product_groups` | `id` | (migration 033 — not in dump, added) |
| `products` | `id` | +group_id FK; condition as VARCHAR+CHECK; unit as VARCHAR+CHECK |
| `product_price_history` | `id` | +UNIQUE(product_id, effective_from) |
| `customers` | `id` | **+INDEX on customer_id** (was missing in MySQL) |
| `suppliers` | `id` | |
| `banks` | `id` | **balance: FLOAT→numeric(18,2)**; **updated_at: INT(YYYYMMDD)→date** |
| `bank_ledger_mappings` | `id` | +UNIQUE(bank_id) for ON CONFLICT upsert |
| `warehouses` | `id` | |
| `fcm_tokens` | `id` | +UNIQUE(user_id, fcm_token) for ON CONFLICT upsert |

### Accounting Core (8 tables + 1 view + 1 trigger function)
| Table | PK | Key Changes |
|---|---|---|
| `ledgers` | `id` | parent_id 0→NULL (for future self-FK); ledger_nature as VARCHAR |
| `journal_entries` | `id` | +UNIQUE(entry_no); +INDEX(reference_type, reference_id) |
| `journal_lines` | `id` | **+CHECK (debit≥0, credit≥0, not both 0)**; **+balanced-entry trigger** |
| `journal_posting_logs` | `id` | action as VARCHAR+CHECK |
| `document_sequences` | `id` | +UNIQUE(doc_type, branch_id, period_key) |
| `accounting_periods` | `id` | (migration 045); +UNIQUE(branch_id) for ON CONFLICT |
| `manual_journals` | `id` | (migration 044); status as VARCHAR+CHECK |
| `schema_migrations` | `filename` | Laravel replaces this with `migrations` table in Phase 3 |
| `v_journal_entries_with_lines` | (view) | JOIN of journal_entries ⨝ journal_lines ⨝ ledgers |

### Sub-Ledgers (5 tables)
| Table | PK | Key Changes |
|---|---|---|
| `customer_ledger` | `id` | **+FK customer_id** (was missing in MySQL); +INDEX(customer_id, transaction_date) |
| `supplier_ledger` | `id` | **+FK supplier_id** (was missing); +INDEX(supplier_id, transaction_date) |
| `employee_ledger` | `id` | **+FK employee_id** (was missing) |
| `branch_ledger` | `id` | intercompany settlement tracking |
| `cash_ledger` | `id` | per-branch cash running balance |

### Branch Operations (3 tables)
| Table | PK | Key Changes |
|---|---|---|
| `branch_cash` | `id` | +UNIQUE(branch_id, cash_point) |
| `branch_expenses` | `id` | |
| `branch_product_cost` | `id` | per-branch product cost (separate from warehouse avg_cost) |

### Stock / Inventory (10 tables + 2 triggers)
| Table | PK | Key Changes |
|---|---|---|
| `stock_transactions` | `id` | **SSOT**; total_value GENERATED STORED; reference_type VARCHAR+CHECK (12 values) |
| `warehouse_stock` | **(warehouse_id, product_id)** | **COMPOSITE PK — no id column**; +CHECK(qty≥-0.0001); +2 triggers prevent_negative_stock |
| `stock_adjustments` | `id` | status VARCHAR+CHECK |
| `stock_adjustment_items` | `id` | |
| `stock_take_sessions` | `id` | status VARCHAR+CHECK |
| `stock_take_warehouses` | `id` | |
| `stock_take_items` | `id` | +UNIQUE(session, warehouse, product) for ON CONFLICT; difference GENERATED STORED |
| `warehouse_transfers` | `id` | interbranch flag; 2 journal_entry_id columns |
| `warehouse_transfer_items` | `id` | |
| `daily_warehouse_stock_summary` | **(warehouse_id, product_id, summary_date)** | COMPOSITE PK |
| `damage_invoices` | `id` | |
| `damage_invoice_items` | `id` | |

### Sales (8 tables)
| Table | PK | Key Changes |
|---|---|---|
| `sales_invoices` | `id` | **+INDEX on customer_id, invoice_date** (were missing); updated_at DATE→timestamp; status VARCHAR+CHECK |
| `sales_invoice_items` | `id` | **+amount GENERATED STORED** (was missing in MySQL); +warehouse_id FK |
| `sales_invoice_dispatchers` | `id` | |
| `sales_invoice_dispatches` | `id` | +UNIQUE(invoice_id, product_id); amount GENERATED STORED |
| `sales_challans` | `id` | transport snapshot columns; 2 journal_entry_id columns |
| `sales_draft_carts` | `id` | +UNIQUE(user_id, customer_id) for ON CONFLICT; items_json→jsonb |
| `sales_returns` | `id` | status VARCHAR+CHECK; **original_cost snapshot for return-at-original-cost** |
| `sales_return_items` | `id` | **+amount GENERATED STORED** (was missing); +original_cost column |

### Purchase (7 tables)
| Table | PK | Key Changes |
|---|---|---|
| `purchase_orders` | `id` | |
| `purchase_order_items` | `id` | amount GENERATED STORED |
| `purchase_receives` | `id` | +INDEX(is_reversed, reversed_at) |
| `purchase_receive_items` | `id` | amount GENERATED STORED; +return_qty column |
| `purchase_returns` | `id` | +INDEX(is_reversed, reversed_at) |
| `purchase_return_items` | `id` | amount GENERATED STORED |
| `invoice_payment_allocations` | `id` | invoice↔payment many-to-many |

### Payments (5 tables)
| Table | PK | Key Changes |
|---|---|---|
| `customer_payments` | `id` | payment_mode VARCHAR+CHECK; +intercompany_journal_entry_id |
| `customer_payment_settlements` | `id` | payment↔invoice allocation |
| `supplier_payments` | `id` | payment_mode VARCHAR+CHECK |
| `supplier_payment_settlements` | `id` | payment↔receive allocation |
| `money_transfers` | `id` | transfer_type VARCHAR+CHECK (4 values); **+FK from_bank_id, to_bank_id** (were missing) |

### Other (4 tables)
| Table | PK | Key Changes |
|---|---|---|
| `other_incomes` | `id` | |
| `other_expenses` | `id` | |
| `employee_transactions` | `id` | transaction_type VARCHAR+CHECK (6 values) |
| `notifications` | `id` | in-app + FCM push |

### Phase 0 / Auth Hardening (3 tables, from migrations not in dump)
| Table | PK | Key Changes |
|---|---|---|
| `investigation_activators` | `id` | +UNIQUE(user_id); **Phase 11 will simplify** |
| `login_rate_limits` | **bucket_key** | VARCHAR PK (no sequence) |
| `user_audit_log` | `id` | details→jsonb |

### Branch Demand (2 tables)
| Table | PK | Key Changes |
|---|---|---|
| `branch_demands` | `id` | status VARCHAR+CHECK |
| `branch_demand_items` | `id` | +fulfilled_qty tracking |

---

## 3. Critical Design Decisions

### 3.1 Double-Entry Integrity at DB Level
A trigger `enforce_balanced_journal_entry()` on `journal_lines` rejects any DML that would leave a journal entry with `SUM(debit) ≠ SUM(credit)`. This is **stronger than the legacy MySQL** which had no such constraint. The accountant's #1 invariant is now enforced by the database.

### 3.2 warehouse_stock Composite Primary Key
MySQL had both `PRIMARY KEY (warehouse_id, product_id)` AND `UNIQUE KEY unique_stock (warehouse_id, product_id)` — redundant. PG schema keeps only the composite PK. There is **no `id` column** — this is the inventory state table, not a transaction log.

### 3.3 banks.balance FLOAT → numeric(18,2)
The MySQL `float(20,2)` for bank balance was a precision bug (single-precision float → ~7 significant digits). PG uses `numeric(18,2)`. The post-load fix script recomputes the balance from transaction history and logs deltas > 0.01 for accountant review.

### 3.4 ENUMs as VARCHAR + CHECK
All 38 MySQL ENUM columns are converted to `varchar(50) CHECK (col IN (...))` rather than PG `CREATE TYPE` enums. Rationale: adding a new enum value requires only an `ALTER TABLE ... ALTER CHECK` (or no change if the app validates), vs `ALTER TYPE ... ADD VALUE` for PG enums which has locking implications. VARCHAR+CHECK is more flexible for a system under active development.

### 3.5 Generated Columns (8 total)
| Table | Column | Expression |
|---|---|---|
| `purchase_order_items` | `amount` | `qty * rate` |
| `purchase_receive_items` | `amount` | `qty * rate` |
| `purchase_return_items` | `amount` | `qty * rate` |
| `sales_invoice_items` | `amount` | `qty * rate` (NEW — was missing in MySQL) |
| `sales_invoice_dispatches` | `amount` | `qty * rate` |
| `sales_return_items` | `amount` | `qty * rate` (NEW — was missing in MySQL) |
| `stock_take_items` | `difference` | `physical_qty - system_qty` |
| `stock_transactions` | `total_value` | `qty * rate` |

Note: MySQL `warehouse_stock.avg_cost` was a GENERATED column using `IF(total_qty > 0, total_value/total_qty, 0)`. In PG, `avg_cost` is a **regular column** maintained by the application's `StockTransactionModel::updateWarehouseStock()` (the moving-average cost logic). This is intentional — the avg_cost is the INPUT to stock-out transactions, not a derived value.

### 3.6 ON DUPLICATE KEY → ON CONFLICT
7 MySQL upsert statements converted to PG `ON CONFLICT (...) DO UPDATE SET ... = EXCLUDED.col`:
| Table | Conflict Target | Unique Key |
|---|---|---|
| `login_rate_limits` | `bucket_key` | PRIMARY KEY |
| `investigation_activators` | `user_id` | UNIQUE |
| `fcm_tokens` | `(user_id, fcm_token)` | UNIQUE |
| `sales_draft_carts` | `(user_id, customer_id)` | UNIQUE |
| `accounting_periods` | `branch_id` | UNIQUE |
| `warehouse_stock` | `(warehouse_id, product_id)` | PRIMARY KEY |
| `stock_take_items` | `(session_id, warehouse_id, product_id)` | UNIQUE |
| `bank_ledger_mappings` | `bank_id` | UNIQUE |

### 3.7 Missing FKs Added
The MySQL dump was missing FKs on legacy tables (sales/customer/supplier/employee). These are added in `07_views_triggers_constraints.sql` **after ETL** so orphan rows don't block the load:
- `sales_invoices.customer_id` → `customers.id`
- `sales_invoices.branch_id` → `branches.id`
- `customer_ledger.customer_id` → `customers.id` (CASCADE)
- `supplier_ledger.supplier_id` → `suppliers.id` (CASCADE)
- `employee_ledger.employee_id` → `employees.id` (CASCADE)
- `money_transfers.from_bank_id` → `banks.id`
- `money_transfers.to_bank_id` → `banks.id`

Note: `ledgers.parent_id` self-FK is **deferred to Phase 3** because legacy data uses `0` as "no parent" sentinel (not a valid id). Phase 3 will migrate `parent_id=0 → NULL` and add the FK.

---

## 3.8 Partial Indexes for Business Queries (Phase 20)

PostgreSQL partial indexes (WHERE-clause indexes) only index rows matching a predicate,
producing much smaller indexes and faster scans for the "active subset" queries the ERP
runs on every page load. Migration `2025_01_20_000001_add_partial_indexes_business_queries.php`
and `07_views_triggers_constraints.sql` both define these indexes.

| Category | Table | Index Name | Columns | WHERE Predicate | Use Case |
|---|---|---|---|---|---|
| Open Invoices | `sales_invoices` | `idx_si_open_invoice` | `(customer_id, due_amount, invoice_date)` | `status = 'confirmed' AND is_reversed = false AND due_amount > 0` | AR aging, collections dashboard |
| Open Invoices | `sales_invoices` | `idx_si_open_by_branch` | `(branch_id, invoice_date)` | `status = 'confirmed' AND is_reversed = false AND due_amount > 0` | Branch dashboard, call-it-a-day |
| Unpaid Payments | `customer_payments` | `idx_cp_active` | `(customer_id, payment_date)` | `is_reversed = false` | AR payment history |
| Unpaid Payments | `supplier_payments` | `idx_sp_active` | `(supplier_id, payment_date)` | `is_reversed = false` | AP payment history |
| Unpaid Payments | `customer_payments` | `idx_cp_active_by_branch` | `(branch_id, payment_date)` | `is_reversed = false` | Daily collection report |
| Unpaid Payments | `supplier_payments` | `idx_sp_active_by_branch` | `(branch_id, payment_date)` | `is_reversed = false` | Daily payment report |
| Pending Returns | `sales_returns` | `idx_sr_pending` | `(branch_id, return_date)` | `status = 'created' AND is_reversed = false` | Returns awaiting confirmation |
| Pending Returns | `purchase_returns` | `idx_prtn_pending` | `(supplier_id, branch_id)` | `is_reversed = false` | Active purchase returns |
| Active Ledger | `customer_ledger` | `idx_cl_outstanding` | `(customer_id, transaction_date, balance)` | `balance > 0` | AR outstanding rows |
| Active Ledger | `supplier_ledger` | `idx_sl_outstanding` | `(supplier_id, transaction_date, balance)` | `balance > 0` | AP outstanding rows |
| Active Ledger | `branch_ledger` | `idx_bl_unsettled` | `(from_branch_id, to_branch_id, transaction_date)` | `is_settled = false` | Unsettled intercompany |
| Active Ledger | `journal_entries` | `idx_je_active` | `(entry_date, branch_id, reference_type)` | `is_reversed = false` | GL reports, trial balance |
| Active Ledger | `ledgers` | `idx_ledgers_active_by_type` | `(account_type, ledger_code)` | `is_active = true` | Chart of accounts filter |

**Note:** Master-data partial indexes (`idx_branches_active`, `idx_products_active`, etc.)
were added earlier in migration `2025_01_14_000001_add_performance_indexes.php` and are
documented in that migration's comments.

---

## 3.9 Covering Indexes (INCLUDE) for High-Frequency Queries (Phase 21)

PostgreSQL covering indexes use the INCLUDE clause to store additional columns in
the B-tree leaf pages, enabling **index-only scans** — PostgreSQL never needs to
visit the heap (actual table pages). Migration `2025_01_20_000002_add_covering_indexes_high_freq_queries.php`
and `07_views_triggers_constraints.sql` both define these indexes.

| Priority | Table | Index Name | Key Columns | INCLUDE Columns | Use Case |
|---|---|---|---|---|---|
| P0 | `customer_ledger` | `idx_cl_balance_covering` | `(customer_id, is_reversed)` | `debit, credit` | Balance SUM per customer |
| P0 | `sales_invoices` | `idx_si_customer_due_covering` | `(customer_id, is_reversed)` WHERE due_amount > 0 | `id, invoice_code, invoice_date, total_amount, paid_amount, due_amount` | Payment allocation AJAX |
| P1 | `journal_entries` | `idx_je_reference_covering` | `(reference_type, reference_id, is_reversed)` | `id, entry_no, entry_date, branch_id, description, source, created_by` | Reversal/cancel/show |
| P1 | `journal_lines` | `idx_jl_entry_covering` | `(journal_entry_id)` | `id, ledger_id, debit, credit, entity_type, entity_id, memo` | Journal show page |
| P1 | `journal_lines` | `idx_jl_ledger_date_covering` | `(ledger_id, journal_entry_id)` | `debit, credit` | GL report / trial balance |
| P2 | `sales_invoices` | `idx_si_listing_covering` | `(branch_id, status, invoice_date DESC, id DESC)` | `customer_id, invoice_code, total_amount, paid_amount, due_amount, is_godown_prepared, is_challan_issued, is_reversed` | Invoice listing DataTable |
| P2 | `customer_payments` | `idx_cp_listing_covering` | `(branch_id, payment_date DESC, id DESC)` | `customer_id, payment_code, payment_mode, amount, is_reversed` | Payment listing |
| P2 | `supplier_payments` | `idx_sp_listing_covering` | `(branch_id, payment_date DESC, id DESC)` | `supplier_id, payment_code, payment_mode, amount, is_reversed` | Payment listing |
| P2 | `invoice_payment_allocations` | `idx_ipa_invoice_covering` | `(invoice_id)` | `payment_id, allocated_amount` | Paid-so-far per invoice |
| P2 | `warehouse_stock` | `idx_ws_product_covering` | `(product_id, warehouse_id)` | `qty, avg_cost` | Product→warehouse lookup |
| P2 | `sales_challans` | `idx_sc_listing_covering` | `(branch_id, challan_date DESC, id DESC)` | `sales_invoice_id, challan_code, is_reversed, issue_cost, transport_cost` | Challan listing |
| P3 | `purchase_receives` | `idx_pr_listing_covering` | `(branch_id, receive_date DESC, id DESC)` | `supplier_id, receive_code, total_amount, is_reversed, purchase_order_id` | GRN listing |
| P3 | `supplier_ledger` | `idx_sl_reference_covering` | `(reference_type, reference_id)` | `id, supplier_id, branch_id, transaction_date, transaction_type, debit, credit, balance, journal_entry_id, created_by` | By-reference lookup |
| P3 | `stock_transactions` | `idx_st_reference_covering` | `(reference_type, reference_id)` | `id, warehouse_id, product_id, qty, rate, transaction_date, created_by` | By-reference lookup |
| P3 | `customer_ledger` | `idx_cl_reference_covering` | `(reference_type, reference_id)` | `id, customer_id, branch_id, transaction_date, transaction_type, debit, credit, balance, journal_entry_id, created_by` | By-reference lookup |
| P3 | `purchase_orders` | `idx_po_listing_covering` | `(branch_id, po_date DESC, id DESC)` | `supplier_id, po_code, total_amount, status` | PO listing |

### 3.10 BRIN Indexes for Time-Series Tables

PostgreSQL BRIN (Block Range Index) indexes store only min/max summaries per block range of pages,
making them tiny (~0.1% of table size vs ~10% for B-tree) and ideal for chronologically-ordered columns.
They complement B-tree indexes — B-tree handles equality/point lookups, BRIN handles date-range scans
at near-zero storage cost. Migration `2025_01_20_000003_add_brin_indexes_time_series_tables.php`
and `07_views_triggers_constraints.sql` both define these indexes.

| # | Table | Index Name | Column | pages_per_range | Use Case |
|---|---|---|---|---|---|
| 1 | `sales_invoices` | `idx_si_created_at_brin` | `created_at` | 32 | Monthly revenue, daily listing |
| 2 | `sales_invoices` | `idx_si_invoice_date_brin` | `invoice_date` | 32 | AR aging by invoice date |
| 3 | `customer_payments` | `idx_cp_payment_date_brin` | `payment_date` | 32 | Daily collection report |
| 4 | `customer_payments` | `idx_cp_created_at_brin` | `created_at` | 32 | Recent payments dashboard |
| 5 | `supplier_payments` | `idx_sp_payment_date_brin` | `payment_date` | 32 | AP aging, payment history |
| 6 | `supplier_payments` | `idx_sp_created_at_brin` | `created_at` | 32 | Recent payments dashboard |
| 7 | `sales_returns` | `idx_sr_return_date_brin` | `return_date` | 32 | Returns report by period |
| 8 | `purchase_receives` | `idx_pr_receive_date_brin` | `receive_date` | 32 | GRN listing, monthly purchase |
| 9 | `purchase_returns` | `idx_prtn_return_date_brin` | `return_date` | 32 | Purchase return by period |
| 10 | `purchase_orders` | `idx_po_po_date_brin` | `po_date` | 32 | PO listing by date |
| 11 | `customer_ledger` | `idx_cl_transaction_date_brin` | `transaction_date` | 32 | AR aging, customer 360 |
| 12 | `customer_ledger` | `idx_cl_created_at_brin` | `created_at` | 32 | Recent ledger entries |
| 13 | `supplier_ledger` | `idx_sl_transaction_date_brin` | `transaction_date` | 32 | AP aging, supplier history |
| 14 | `supplier_ledger` | `idx_sl_created_at_brin` | `created_at` | 32 | Recent ledger entries |
| 15 | `employee_ledger` | `idx_el_transaction_date_brin` | `transaction_date` | 32 | Employee statement by period |
| 16 | `branch_ledger` | `idx_bl_transaction_date_brin` | `transaction_date` | 32 | Intercompany by period |
| 17 | `cash_ledger` | `idx_cashl_transaction_date_brin` | `transaction_date` | 32 | Daily cash position |
| 18 | `branch_expenses` | `idx_be_expense_date_brin` | `expense_date` | 32 | Expense report by period |
| 19 | `stock_transactions` | `idx_st_transaction_date_brin` | `transaction_date` | 64 | Product movement report |
| 20 | `stock_transactions` | `idx_st_created_at_brin` | `created_at` | 64 | Recent stock movements |
| 21 | `user_audit_log` | `idx_ual_created_at_brin` | `created_at` | 64 | Security audit by date range |
| 22 | `notifications` | `idx_notif_created_at_brin` | `created_at` | 64 | Recent notifications |
| 23 | `journal_posting_logs` | `idx_jpl_performed_at_brin` | `performed_at` | 64 | GL posting audit trail |
| 24 | `daily_warehouse_stock_summary` | `idx_dwss_summary_date_brin` | `summary_date` | 32 | Stock summary by date range |
| 25 | `other_incomes` | `idx_oi_income_date_brin` | `income_date` | 32 | Income report by period |
| 26 | `other_expenses` | `idx_oe_expense_date_brin` | `expense_date` | 32 | Expense report by period |
| 27 | `employee_transactions` | `idx_et_transaction_date_brin` | `transaction_date` | 32 | Employee transaction report |
| 28 | `money_transfers` | `idx_mt_transfer_date_brin` | `transfer_date` | 32 | Transfer history by date |
| 29 | `sales_challans` | `idx_sc_challan_date_brin` | `challan_date` | 32 | Challan listing by date |
| 30 | `manual_journals` | `idx_mj_journal_date_brin` | `journal_date` | 32 | Manual journal listing |

### 3.11 GIN Index for JSONB Cart Items

PostgreSQL GIN (Generalized Inverted Index) on `sales_draft_carts.items_json` enables
`@>` containment queries for cart item lookups. The `jsonb_path_ops` operator class is
used — it supports only `@>` but produces an index ~30% smaller and faster than default GIN.
Migration `2025_01_20_000004_add_gin_index_draft_carts_items_json.php` and
`07_views_triggers_constraints.sql` both define this index.

| Table | Index Name | Column | Operator Class | Use Case |
|---|---|---|---|---|
| `sales_draft_carts` | `idx_sdc_items_gin` | `items_json` | `jsonb_path_ops` | Cart item containment (@> queries: product/warehouse lookup) |

### 3.12 Full-Text Search (tsvector + GIN) for Products & Customers

PostgreSQL full-text search replaces LIKE '%term%' with index-accelerated `tsvector @@ plainto_tsquery`
lookups. GENERATED tsvector columns are auto-maintained on INSERT/UPDATE. The `'simple'` dictionary
(lowercase + whitespace split, no stemming) is used because product codes, Bengali names, and phone
numbers must not be stemmed. GIN indexes enable sub-millisecond search on millions of rows.
Migration `2025_01_20_000005_add_fulltext_search_products_customers.php` and
`07_views_triggers_constraints.sql` both define these columns and indexes.

**Products search_vector:**

| Weight | Column | Rationale |
|---|---|---|
| A (highest) | `product_name` | Primary match — users search by name |
| B | `product_code` | Secondary — code lookups |

| Table | Index Name | Column | Dictionary | Use Case |
|---|---|---|---|---|
| `products` | `idx_products_search` | `search_vector` | simple + GIN | Product search (DataTables, POS, archive) |

**Customers search_vector:**

| Weight | Column | Rationale |
|---|---|---|
| A (highest) | `customer_name` | Primary match — users search by name/shop |
| B | `customer_code` | Secondary — code lookups |
| C | `phone`, `mobile` | Tertiary — phone number search |
| D (lowest) | `address` | Area-based keyword match |

| Table | Index Name | Column | Dictionary | Use Case |
|---|---|---|---|---|
| `customers` | `idx_customers_search` | `search_vector` | simple + GIN | Customer search (DataTables, POS, archive) |

**Laravel integration**: `Product::scopeSearch()` and `Customer::scopeSearch()` use `search_vector @@ plainto_tsquery('simple', ?)` with `ts_rank()` ordering. Automatic ILIKE fallback when `search_vector` column doesn't exist. `BaseMasterDataController::$useFullTextSearch = true` on both controllers. `ArchiveService::searchCustomers()` refactored.

### 3.13 Running Balance Reconciliation (Window Functions + Materialized Views)

Task 18: Verifies that the denormalized `balance` column in each sub-ledger matches the mathematically correct running balance computed by `SUM() OVER (PARTITION BY entity ORDER BY id)` window functions. Materialized views store the comparison (stored_balance, computed_balance, drift) and are refreshed by the `reconcile:running-balance` Artisan command. A `reconciliation_snapshots` table stores structured audit results.

Migration: `2025_01_20_000006_add_running_balance_reconciliation.php`. SQL: `07_views_triggers_constraints.sql` — RUNNING BALANCE RECONCILIATION section.

**Materialized Views:**

| # | View Name | Source Table | Entity Column | Running Balance Formula |
|---|---|---|---|---|
| 1 | `mv_customer_ledger_balance_check` | `customer_ledger` | `customer_id` | `SUM(debit - credit) OVER (PARTITION BY customer_id ORDER BY id)` |
| 2 | `mv_supplier_ledger_balance_check` | `supplier_ledger` | `supplier_id` | `SUM(credit - debit) OVER (PARTITION BY supplier_id ORDER BY id)` |
| 3 | `mv_employee_ledger_balance_check` | `employee_ledger` | `employee_id` | `SUM(credit - debit) OVER (PARTITION BY employee_id ORDER BY id)` |
| 4 | `mv_cash_ledger_balance_check` | `cash_ledger` | `branch_id` | `SUM(amount) OVER (PARTITION BY branch_id ORDER BY id)` |

**reconciliation_snapshots Indexes:**

| # | Index Name | Columns | Use Case |
|---|---|---|---|
| 1 | `idx_rs_run_type_ran_at` | `(run_type, ran_at DESC)` | Audit trail by run type and date |
| 2 | `idx_rs_ledger_type_status` | `(ledger_type, status, ran_at DESC)` | Find failed reconciliations by ledger type |

**Materialized View Unique Indexes** (required for `REFRESH MATERIALIZED VIEW CONCURRENTLY`):

| View | Index Name | Column |
|---|---|---|
| `mv_customer_ledger_balance_check` | `idx_mv_clbc_id` | `id` |
| `mv_supplier_ledger_balance_check` | `idx_mv_slbc_id` | `id` |
| `mv_employee_ledger_balance_check` | `idx_mv_elbc_id` | `id` |
| `mv_cash_ledger_balance_check` | `idx_mv_cashlbc_id` | `id` |

**Laravel integration**: `php artisan reconcile:running-balance` refreshes all materialized views, counts drifted rows, shows top-N entities by worst drift, optionally fixes drift with `--fix`, and stores results in `reconciliation_snapshots`. Options: `--ledger=customer|supplier|employee|cash`, `--as-of=date`, `--top=N`, `--fix`.

---

## 4. PHP Code SQL Compatibility (Phase 2.4)

All production PHP code (core/, app/models/, app/helpers/, app/services/, app/controllers/) has been audited and fixed for PostgreSQL compatibility:

| MySQL-ism | Occurrences Fixed | Conversion |
|---|---|---|
| Backticks in SQL | ~1021 | Removed (unquoted identifiers) |
| `ON DUPLICATE KEY UPDATE` | 8 | `ON CONFLICT ... DO UPDATE SET ... EXCLUDED` |
| `SHOW COLUMNS FROM ... LIKE` | 5 | `information_schema.columns` query |
| `SHOW TABLES LIKE` | 8 | `information_schema.tables` query |
| `DATE_FORMAT(d, '%Y-%m-%d')` | 6 | `to_char(d, 'YYYY-MM-DD')` |
| `DATE_SUB/DATE_ADD(col, INTERVAL n UNIT)` | 12 | `(col - INTERVAL 'n units')` |
| `CURDATE()` | 16 | `CURRENT_DATE` |
| `DATEDIFF(a, b)` | 2 | `(a::date - b::date)` |
| `IFNULL(a, b)` | 2 | `COALESCE(a, b)` |
| `GROUP_CONCAT(x SEPARATOR ',')` | 2 | `string_agg(x::text, ',')` |
| `FIELD(col, ...)` | 2 | `CASE col WHEN ... THEN n END` |
| `CAST(... AS UNSIGNED)` | 2 | `CAST(... AS BIGINT)` |
| `SUBSTRING(x, n)` | 1 | `SUBSTRING(x FROM n)` |
| `REGEXP` | 1 | PG `~` operator |
| `LIMIT offset, count` | 11 | `LIMIT count OFFSET offset` |
| Double-quoted SQL literals `"x"` | 2 | Single-quoted `'x'` (PG treats `"x"` as identifier) |
| `ON DUPLICATE KEY UPDATE ... VALUES()` (BankLedgerMapping) | 1 | `ON CONFLICT (bank_id) DO UPDATE SET ... EXCLUDED` |

**JavaScript template literals in views (`` ` ``) were correctly left unchanged** — they are not SQL.

**Deprecated scripts:** 15 MySQL-specific utility/test scripts in `database/scripts/` and `database/tests/` have deprecation headers. They will be replaced by Laravel artisan commands (Phase 3+) and PHPUnit tests.

---

## 5. Sign-off

- [x] All 66 tables mapped (this document)
- [x] PostgreSQL DDL written (`laravel/database/sql/01-07_*.sql`)
- [x] Laravel baseline migration written (`laravel/database/migrations/`)
- [x] ETL scripts written (`laravel/database/etl/`)
- [x] All production PHP code PG-compatible (Phase 2.4)
- [ ] Schema applied to VPS PostgreSQL (Phase 1 + Phase 2.2 on VPS)
- [ ] ETL run against production data (Phase 2.3 on VPS)
- [ ] Legacy PHP running against PG (Phase 2.4 on VPS)
- [ ] 7-day dual-run zero-error (Phase 2.5 on VPS)
- [ ] Accountant sign-off on financial checksums
