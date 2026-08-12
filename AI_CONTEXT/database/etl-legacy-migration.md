# ETL & Legacy Migration

> **Module:** Database Design (ETL pipeline)
> **Audience:** Engineers + AI assistants + DBAs + migration leads
> **Status:** Draft
> **Last reviewed:** 2026-08-03
> **Source of truth:** this file, grounded in `laravel/database/etl/{pgloader.load,sync_sequences.sql,post_load_fixes.sql,etl_verify.sql}`, `docs/migration/schema_mapping.md`, `docs/MIGRATION_PLAN.md`, the legacy-data migration files in `laravel/database/migrations/2026_07_30_*`, and the Anti-Corruption Layer in `laravel/app/Archive/`.

---

## 1. What is it?

The end-to-end pipeline that moved RC_ERP's data from the legacy **MySQL** database
(`osudlagb_remotecenter`) into the new **PostgreSQL 16** schema, plus the **Anti-Corruption
Layer (ACL)** that preserves read-only access to the legacy MySQL for historical queries beyond
the operational window. The pipeline has four stages: **pgloader bulk load → post-load fixes →
sequence sync → verification**, followed by **replay testing** (38,775 stock transactions, 521
invoices, 311 GRNs, 550 payments) to prove zero drift before cutover.

This file documents the one-time ETL and the ongoing legacy read-only archive. It does NOT
document the PostgreSQL partition-archival (that is `partitioning.md#archive-schema-lifecycle`).

## 2. Why does it exist?

The migration was governed by the four non-negotiable principles (see
`../PROJECT_OVERVIEW.md`): (1) DB conversion done (MySQL → PG), (2) app conversion done (PHP →
Laravel), (3) keep the existing UI, (4) **re-derive business logic — never copy-paste**. The
ETL had to move ~7 years of financial data without losing inventory value or breaking
double-entry integrity, while the ACL preserves historical read access so the business can
query old transactions without re-migrating them.

The migration was validated by **replaying production data** through the new service layer and
requiring zero drift vs the legacy computed balances. Cutover required 3-way sign-off (lead
developer + accountant + business owner).

## 3. When is it used?

- **One-time ETL** — executed during the migration phases (Phase 2 database migration, Phase 6.2
  stock replay, Phase 9 production replay). The pipeline files remain in the repo for
  re-running on fresh environments or for forensic re-derivation.
- **Legacy data migrations** (`2026_07_30_000005-000013_*`) — run as part of `php artisan
  migrate` to populate master data (employees, products, customers, suppliers, banks, branches,
  warehouses, ledgers) from a legacy SQL dump.
- **Anti-Corruption Layer** — used at runtime by `ArchiveService` for queries that span beyond
  the operational window (default 24 months). PG-first; archive fallback with 1-hour cache.

## 4. Who uses it?

- **Migration lead / DBA** — runs the ETL pipeline during environment setup or forensic
  re-derivation.
- **`php artisan migrate`** — runs the legacy-data migration files automatically.
- **`ArchiveService`** — runtime PG-first search with archive fallback.
- **Accountant** — signs off on reconciliation (P3-3 checklist) before cutover.
- **AI assistants** — MUST understand the ETL to interpret schema differences (e.g. why
  `ledgers.parent_id` is NULL not 0, why `banks.balance` is numeric not float).

## 5. Related modules

- `schema-overview.md` — the target schema.
- `migrations-conventions.md#data-migrations` — the legacy-data migration files.
- `partitioning.md` — the operational retention (separate from the legacy MySQL archive).
- `../architecture/module-map.md` — the Archive module (Phase 18 will document the ACL in
  depth).

## 6. Business rules (ETL-level)

- **Float → numeric conversion is lossy.** `banks.balance` was FLOAT in MySQL, converted to
  `numeric(18,2)`. A post-load fix logs deltas > 0.01 BDT for accountant review.
- **MySQL `0` sentinel → PG `NULL`.** `ledgers.parent_id = 0` (MySQL "no parent") became
  `NULL`. Code MUST NOT assume 0 means root.
- **ENUM → VARCHAR + CHECK.** All 38 MySQL ENUM columns became `varchar(50) CHECK (col IN (...))`
  (see `schema-overview.md#enum-vs-check-pattern`).
- **Generated columns are EXCLUDED from pgloader.** PG computes them (8 total: `*.amount`,
  `*.difference`, `*.total_value`, `*.total_qty`).
- **Zero-dates → NULL.** MySQL `'0000-00-00'` and dates before `1900-01-01` are set to NULL.
- **Status enum values are mapped.** `sales_invoices.status='godown_issued'` →
  `'confirmed' + is_godown_prepared=true`; `'challan_completed'` → `'confirmed' + both flags`.
  `sales_returns.status='pending'` → `'created'`; `'completed'` → `'confirmed'`.
- **`sales_return_items.original_cost` backfilled** from `stock_transactions.rate` where
  `reference_type='sales_challan' AND qty < 0` (the original avg_cost snapshot).
- **Replay must produce zero drift.** `stock:replay-verify` replays all stock_transactions and
  requires zero drift on `warehouse_stock` rows (or accountant sign-off on investigated rows).
- **Sub-ledger reconciliation must pass.** `subledger:reconcile` runs 7 sections; all must be
  within `0.02` BDT tolerance before cutover.
- **The legacy MySQL archive is READ-ONLY.** No writes ever go to `mysql_archive`; the ACL
  translates reads into DTOs with a `source` flag.

## 7. Technical implementation

### 7.1 ETL files (`laravel/database/etl/`)

| File | Lines | Purpose |
|---|---|---|
| `pgloader.load` | 71 | pgloader config: MySQL → PostgreSQL bulk load |
| `sync_sequences.sql` | 45 | Safety net: `setval` every IDENTITY sequence to `MAX(id)` |
| `post_load_fixes.sql` | 359 | 14 data-quality fixes after pgloader |
| `etl_verify.sql` | 161 | 4-part verification report |

### 7.2 pgloader configuration (`pgloader.load`)

- **Source:** `mysql://rcerp_user:***@mysql-source-host:3306/osudlagb_remotecenter`
- **Target:** `postgresql://rcerp_app:***@localhost:5432/rcerp`
- **WITH options:** `include drop, create no tables, truncate, data only, batch rows = 5000,
  prefetch rows = 5000, create no indexes, reset sequences, foreign keys, downcase identifiers`

**CAST rules (critical):**

| MySQL type | PG type | Notes |
|---|---|---|
| `datetime` | `timestamptz` | drop default, drop not null, using zero-dates-to-null |
| `date` | `date` | drop not null, drop default, using zero-dates-to-null |
| `tinyint` | `boolean` | using tinyint-to-boolean |
| `float` | `numeric(18,2)` | CRITICAL: `banks.balance` was FLOAT |
| `int` | `integer` | — |
| `bigint` | `bigint` | — |
| `decimal` | `numeric` | — |
| `double` | `numeric` | — |
| `longtext`/`mediumtext` | `text` | — |
| `enum` | `text` | (CHECK constraint added separately) |
| `json` | `jsonb` | — |

**EXCLUDING COLUMNS** (GENERATED ALWAYS AS STORED in PG — PG computes them):
- `purchase_order_items.amount`, `purchase_receive_items.amount`, `purchase_return_items.amount`
- `sales_invoice_dispatches.amount`, `sales_invoice_items.amount`, `sales_return_items.amount`
- `stock_take_items.difference`, `stock_transactions.total_value`
- `warehouse_stock.total_qty`, `warehouse_stock.total_value`
- `users.totp_secret`, `users.totp_enabled` (Phase 0 dropped)

**EXCLUDING TABLES:** `schema_migrations`, `~v_.*` (views)

**SET:** `work_mem = '128MB'`, `maintenance_work_mem = '512MB'`

### 7.3 sync_sequences.sql

Safety net after pgloader (pgloader's `reset sequences` should handle this, but this script
ensures correctness):

```sql
DO $$
DECLARE
    r record;
    max_id bigint;
    seq_name text;
BEGIN
    FOR r IN
        SELECT c.table_name, c.column_name,
               pg_get_serial_sequence(format('%I', c.table_name), c.column_name) AS seq
        FROM information_schema.columns c
        WHERE c.table_schema = 'public'
          AND c.column_default LIKE 'nextval%'
          AND c.table_name NOT IN ('schema_migrations')
    LOOP
        IF r.seq IS NOT NULL THEN
            EXECUTE format('SELECT COALESCE(MAX(%I), 0) FROM %I', r.column_name, r.table_name) INTO max_id;
            IF max_id > 0 THEN
                EXECUTE format('SELECT setval(%L, %s)', r.seq, max_id);
            END IF;
        END IF;
    END LOOP;
END;
$$;
```

### 7.4 post_load_fixes.sql (14 fixes)

| # | Fix | What it does |
|---|---|---|
| 1 | banks.balance recompute | Logs deltas > 0.01 between stored balance and computed (from transactions) for accountant review |
| 2 | banks.updated_at INT→date | Converts `INT(11)` storing YYYYMMDD to `date` via `to_date(updated_at::text, 'YYYYMMDD')` |
| 3 | sales_invoices.updated_at date→timestamp | PG schema is `timestamp(0)`; pgloader may have kept it as date |
| 4 | Zero-date cleanup | Loop over all date/timestamp columns: `UPDATE ... SET col = NULL WHERE col = '0000-00-00' OR col < '1900-01-01'` |
| 5 | warehouse_stock.avg_cost NULL check | Logs NULL/0 avg_cost rows (recomputed in Phase 6.2 replay) |
| 6 | ledgers.parent_id 0→NULL | MySQL used 0 as "no parent" sentinel; PG uses NULL |
| 7 | schema_migrations population | Inserts baseline migration row |
| 8 | Row count verification | (Manual comparison to MySQL source) |
| 9 | sales_invoices.status enum→CHECK | `godown_issued` → `confirmed + is_godown_prepared=true`; `challan_completed` → `confirmed + both flags` |
| 10 | sales_returns.status enum→CHECK | `pending` → `created`; `completed` → `confirmed` |
| 11 | sales_returns.branch_id backfill | From linked invoice's branch_id |
| 12 | customers.shop_name add+backfill | Legacy had shop_name; PG schema removed it; add back from customer_name |
| 13 | customer_payments.transaction_type backfill | Set to 'receive' for existing rows |
| 14 | sales_return_items.original_cost backfill | From `stock_transactions.rate` where `reference_type='sales_challan' AND qty < 0` |
| P2-4 | warehouses.address→location rename | Legacy `address` → PG `location`; copy data then drop legacy column |

### 7.5 etl_verify.sql (4-part verification)

1. **Row counts per table** — `SELECT schemaname||'.'||relname, n_live_tup FROM pg_stat_user_tables WHERE schemaname='public'`
2. **Financial checksums** — `SUM(debit) = SUM(credit)` on journal_lines; AR/AP sub-ledger totals; stock valuation
3. **Integrity checks** — orphan journal_lines, unbalanced JEs, negative stock, NULL customer/product codes
4. **Table-by-table comparison** vs MySQL `information_schema.tables.table_rows`

### 7.6 Legacy-data migration files (`2026_07_30_*`)

These run as part of `php artisan migrate` and populate master data from a legacy SQL dump
(the dump is placed in a known location and parsed by the migration):

| Migration | What it migrates |
|---|---|
| `2026_07_30_000005_migrate_legacy_admin_and_employee_data.php` | Parses legacy SQL dump → employees + users |
| `2026_07_30_000006_make_e0001_superadmin_with_all_menus.php` | Grants superadmin + all menus to legacy user `e0001` |
| `2026_07_30_000007_make_emp0001_superadmin_with_all_menus.php` | Grants superadmin + all menus to legacy user `emp0001` |
| `2026_07_30_000008_migrate_legacy_product_and_category_data.php` | Products + categories |
| `2026_07_30_000009_migrate_legacy_bank_data.php` | Banks |
| `2026_07_30_000010_migrate_legacy_supplier_data.php` | Suppliers |
| `2026_07_30_000011_migrate_legacy_customer_data.php` | Customers |
| `2026_07_30_000012_migrate_legacy_branch_and_warehouse_data.php` | Branches + warehouses |
| `2026_07_30_000013_add_missing_legacy_ledger_heads.php` | Ledger heads not in default CoA |

### 7.7 Replay/verification methodology

**P3-1 Stock Replay (`php artisan stock:replay-verify`):**
- Replays all 38,775 `stock_transactions` in chronological order.
- Recomputes `warehouse_stock.qty + avg_cost` from scratch into `warehouse_stock_shadow`.
- Logs drift to `avg_cost_drift` table (`live_qty` vs `shadow_qty`, `live_avg_cost` vs `shadow_avg_cost`).
- Acceptance: zero drift OR accountant sign-off on investigated rows.

**P3-2 Journal Replay (`php artisan journal:replay-verify`):**
- 8 core GL checks + 5 sales-specific checks.
- Verifies: every non-draft invoice has exactly 1 GL JE with Dr=Cr; AR sub-ledger == GL AR control; COGS + Inventory reversal nets to zero per invoice.
- Acceptance: 0 unbalanced JEs, 0 orphan journal_lines, sub-ledger totals match GL control accounts.

**P3-3 Reconciliation (`php artisan subledger:reconcile`):**
- 7 sections (originally 6, expanded to 7):
  1. AR: `customer_ledger` total (debit-credit) vs GL `ar` control account (tolerance 0.02 BDT)
  2. AP: `supplier_ledger` total (credit-debit) vs GL `ap` control
  3. Employee Payable: `employee_ledger` total vs GL `employee_payable` control
  4. Orphan Sub-Ledger Entries: sub-ledger rows without `journal_entry_id` (must be 0)
  5. Cash/Bank: GL `cash_bank` ledger vs `SUM(banks.balance) + SUM(cash_ledger.balance)`
  6. Inventory: GL `inventory` ledger vs `SUM(warehouse_stock.qty × avg_cost)`
  7. COGS: GL `cogs` ledger vs `SUM(sales_challan_items.cogs_amount) + damage_loss GL`

**Verified replay volumes (golden dataset + production):**

| Metric | Value | Source |
|---|---|---|
| Stock transactions | 38,775 | `phase6_2_complete.md` |
| warehouse_stock rows | 1,529 (zero drift) | `phase6_2_complete.md` |
| Sales invoices | 521 | `MIGRATION_PLAN.md` Phase 9 |
| GRNs (purchase_receives) | 311 | `MIGRATION_PLAN.md` Phase 9 |
| Payments | 550 | `MIGRATION_PLAN.md` Phase 9 |
| Golden dataset | 50 products, 5 categories, 20 customers, 10 suppliers, 4 branches, 6 warehouses | `MIGRATION_PLAN.md` §3.3 |

### 7.8 mysql_archive read-only connection (`config/database.php:24-45`)

```php
'mysql_archive' => [
    'driver' => 'mysql',
    'host' => env('ARCHIVE_MYSQL_HOST', 'rcerp_mysql_archive'),
    'port' => env('ARCHIVE_MYSQL_PORT', '3306'),
    'database' => env('ARCHIVE_MYSQL_DATABASE', 'rcerp_legacy'),
    'username' => env('ARCHIVE_MYSQL_USERNAME', 'archive_reader'),
    'password' => env('ARCHIVE_MYSQL_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_general_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
],
```

### 7.9 Anti-Corruption Layer (`app/Archive/`)

**3-layer architecture (from `phase12_complete.md`):**

```mermaid
flowchart TD
    APP[Application request] --> AS[ArchiveService]
    AS -->|PG-first| PG[(PostgreSQL<br/>operational, writable)]
    PG -->|found?| YES[return PG result]
    PG -->|not found + within archive window| ACL[Anti-Corruption Layer]
    ACL --> REPO[LegacyMySQLRepository<br/>PDO read-only]
    REPO --> MYSQL[(Legacy MySQL<br/>READ-ONLY)]
    REPO --> DTO[translate → DTO<br/>source=archive_mysql]
    DTO --> CACHE[(1hr cache)]
    CACHE --> AS
```

1. **Layer 1 — Operational (PostgreSQL):** Laravel + PostgreSQL = the ONLY writable system.
   Active master data, current balances, current stock, current fiscal year, recent operational
   history (24 months default).
2. **Layer 2 — Archive (Legacy MySQL, READ-ONLY):** Legacy PHP + MySQL. Permanently read-only.
   Historical transactions (>24 months), historical journal entries, historical stock transactions.
3. **Layer 3 — Anti-Corruption Layer (Archive Module):** Laravel module isolating all
   legacy-specific logic.

**Files:**
- `app/Archive/Services/ArchiveService.php` — PG-first search; archive fallback (1hr cache, immutable data)
- `app/Archive/Repositories/ArchiveRepositoryInterface.php` — contract (`searchInvoices`, `findInvoice`, `searchCustomers`, `getCustomerLedger`, `getSupplierLedger`, `isAvailable`)
- `app/Archive/Repositories/LegacyMySQLRepository.php` — PDO read-only implementation; translates raw MySQL records → DTOs
- `app/Archive/DTOs/InvoiceArchiveDTO.php`, `CustomerArchiveDTO.php`, `LedgerArchiveDTO.php` — each has `source` field (`postgresql` | `archive_mysql`) + `is_archived` flag

**`config/archive.php`** — controls only the legacy MySQL connection (NOT the PG partition
archival). Defaults: `cache_ttl=3600s`, `migration_history_months=24`, `enabled=true`. The
config comment explicitly warns:

> ⚠️ NOT related to Phase 10.1 Phase 7 (PostgreSQL Partition Archival). This config is for the
> LEGACY MySQL read-only archive.

### 7.10 basic_data_snapshot.sql (NOT ETL)

`laravel/database/sql/basic_data_snapshot.sql` (4398 lines) is a **Laravel-generated snapshot**
produced by `php artisan db:snapshot-basic` and consumed by `php artisan db:restore-basic`. It
contains INSERT statements for master + config tables only (no transactional/audit/log tables).
Uses `SET session_replication_role = 'replica'` to bypass FK checks during restore. **This is
NOT an ETL file** — it is a dev-environment seeding tool.

### 7.11 Migration phase completion docs

| Phase | Doc | ETL relevance |
|---|---|---|
| Phase 0 | `phase0_complete.md` | Pre-migration security cleanup (drop totp/telegram fields) |
| Phase 2 | `phase2_complete.md` | Database migration to PostgreSQL (pgloader + post_load_fixes + sync_sequences + etl_verify) |
| Phase 3 | `phase3_complete.md` | Laravel foundation + shared session bridge |
| Phase 4 | `phase4_complete.md` | Master data modules (CRUD) |
| Phase 5 | `phase5_complete.md` | Reporting layer (7 materialized views) |
| Phase 6.1 | `phase6_1_complete.md` | Stock transactions SSOT |
| Phase 6.2 | `phase6_2_complete.md` | Warehouse stock + moving-avg cost replay verification (38,775 transactions, 1,529 warehouse_stock rows) |
| Phase 6.3-6.6 | `phase6_3_complete.md` … `phase6_6_complete.md` | Stock adjustments, stock take, warehouse transfers, damages |
| Phase 7.1 | `phase7_1_complete.md` | Purchase orders |
| Phase 12 | `phase12_complete.md` | Enterprise cutover + 3-layer architecture (PG operational + MySQL archive + Anti-Corruption Layer) |

## 8. Important database tables (ETL-specific)

| Table | Purpose |
|---|---|
| `avg_cost_drift` | Replay drift log (live vs shadow qty/avg_cost) — created by `2025_01_04_000002` |
| `warehouse_stock_shadow` | Shadow table for replay verification — same schema as `warehouse_stock` |
| `reconciliation_snapshots` | Sub-ledger reconciliation snapshots — created by `2025_01_20_000006` |
| `schema_migrations` | Legacy migration tracking (unused by Laravel; kept for reference) |
| `migrations` | Laravel-standard migration tracking |

## 9. Related services

- `laravel/app/Archive/Services/ArchiveService.php` — PG-first search with archive fallback.
- `laravel/app/Archive/Repositories/LegacyMySQLRepository.php` — PDO read-only legacy access.
- Console commands: `stock:replay-verify`, `journal:replay-verify`, `subledger:reconcile`
  (verification), `db:snapshot-basic`, `db:restore-basic` (dev seeding).

## 10. Related models

- `laravel/app/Models/AvgCostDrift.php`, `WarehouseStockShadow.php`,
  `ReconciliationSnapshot.php` — ETL/replay helper models.
- `laravel/app/Archive/DTOs/*` — DTOs (not Eloquent models) carrying `source` + `is_archived`.

## 11. Important workflows

### 11.1 One-time ETL pipeline

```mermaid
flowchart TD
    SRC[Legacy MySQL<br/>osudlagb_remotecenter] --> PG[pgloader.load<br/>bulk load]
    PG --> PGDB[(PostgreSQL rcerp)]
    PGDB --> PLF[post_load_fixes.sql<br/>14 data-quality fixes]
    PLF --> SEQ[sync_sequences.sql<br/>setval to MAX id]
    SEQ --> VRF[etl_verify.sql<br/>4-part verification]
    VRF --> RP1[stock:replay-verify<br/>38,775 txns, zero drift]
    VRF --> RP2[journal:replay-verify<br/>8+5 GL checks]
    VRF --> RP3[subledger:reconcile<br/>7 sections, 0.02 BDT tol]
    RP1 & RP2 & RP3 --> SIGN[3-way sign-off<br/>dev + accountant + owner]
    SIGN --> CUT[Cutover]
```

### 11.2 Runtime archive fallback

```mermaid
sequenceDiagram
    participant U as User
    participant AS as ArchiveService
    participant PG as PostgreSQL
    participant C as Cache
    participant ACL as LegacyMySQLRepository
    participant MY as Legacy MySQL
    U->>AS: searchInvoices(query)
    AS->>PG: SELECT ... (PG-first)
    alt found in PG
        PG-->>AS: rows
        AS-->>U: DTOs (source=postgresql)
    else not found + within archive window
        AS->>C: check cache
        alt cache hit
            C-->>AS: rows
        else cache miss
            AS->>ACL: searchInvoices(query)
            ACL->>MY: SELECT ... (read-only)
            MY-->>ACL: raw rows
            ACL->>ACL: translate → DTOs (source=archive_mysql, is_archived=true)
            ACL-->>AS: DTOs
            AS->>C: put (TTL 3600s)
        end
        AS-->>U: DTOs
    end
```

## 12. Known edge cases

- **`banks.balance` FLOAT→numeric is lossy.** A post-load fix logs deltas > 0.01 BDT for
  accountant review; the accountant must reconcile and post a manual journal if needed.
- **`ledgers.parent_id = 0` (MySQL sentinel) became `NULL`.** Code MUST NOT assume 0 means root.
- **`customers.shop_name` was removed in PG** then re-added from `customer_name` in
  post_load_fixes #12 — verify the column exists before assuming the original PG schema.
- **`warehouses.address` was renamed to `location`** (post_load_fixes P2-4) — code referencing
  `address` will fail.
- **`sales_invoices.status` enum values were mapped** (`godown_issued`/`challan_completed` →
  `confirmed` + workflow flags) — historical invoices carry the new status + flags.
- **`sales_returns.status` enum values were mapped** (`pending` → `created`, `completed` →
  `confirmed`).
- **`sales_return_items.original_cost` was backfilled** from `stock_transactions.rate` — if a
  return's original challan was reversed before backfill, the snapshot may be wrong. Accountant
  review required.
- **`customer_payment_settlements` table was dropped** in favor of `invoice_payment_allocations`
  (migration `2025_01_09_000001`) — legacy data referencing it was migrated to the new table.
- **`notifications` table was overwritten** by Laravel-standard UUID PK schema in Phase 2 —
  legacy notification data is in the MySQL archive, not PG.
- **The ACL `isAvailable()` check** — if the legacy MySQL is down, the ACL returns empty results
  (not an error) so the application degrades gracefully.
- **`mysql_archive` is READ-ONLY by design** — the `archive_reader` user has only SELECT. Any
  write attempt will fail at the DB level.

## 13. Future improvements

- **Retire the legacy MySQL archive** once all historical data is >7 years old and has been
  exported to Parquet (per `partitioning.md`). The ACL would then read from Parquet, not MySQL.
- **Automated drift detection** — a nightly job that re-runs `stock:replay-verify` on the past
  month and alerts on any drift (currently manual).
- **ACL write-through** — currently the ACL is read-only; a write-through mode (write to PG +
  archive to MySQL) is a candidate if the business wants the legacy app to stay writable.
- **Schema-mapping automation** — `docs/migration/schema_mapping.md` is a manual table; a
  generated mapping from `information_schema` would keep it in sync.

---

## Appendix A — The 22-row MySQL→PG conversion rules (from `schema_mapping.md`)

| # | Rule | Detail |
|---|---|---|
| 1 | ENUM → VARCHAR + CHECK | 38 MySQL ENUMs converted |
| 2 | FLOAT → numeric(18,2) | banks.balance |
| 3 | `0` sentinel → NULL | ledgers.parent_id |
| 4 | Zero-date → NULL | `'0000-00-00'`, dates < 1900-01-01 |
| 5 | Generated columns EXCLUDED | 8 STORED columns (PG computes them) |
| 6 | ON DUPLICATE KEY → ON CONFLICT | 7 upserts converted |
| 7 | tinyint → boolean | via tinyint-to-boolean cast |
| 8 | datetime → timestamptz | with zero-dates-to-null |
| 9 | json → jsonb | — |
| 10 | longtext → text | — |
| 11 | enum → text | (CHECK added separately) |
| 12 | double → numeric | — |
| 13 | INT(11) date → date | banks.updated_at YYYYMMDD |
| 14 | address → location | warehouses |
| 15 | shop_name backfill | customers (from customer_name) |
| 16 | status godown_issued → confirmed+flag | sales_invoices |
| 17 | status challan_completed → confirmed+flags | sales_invoices |
| 18 | status pending → created | sales_returns |
| 19 | status completed → confirmed | sales_returns |
| 20 | branch_id backfill | sales_returns (from invoice) |
| 21 | transaction_type backfill | customer_payments (→ 'receive') |
| 22 | original_cost backfill | sales_return_items (from stock_transactions.rate) |

## Appendix B — The 6 critical design decisions (from `schema_mapping.md` §3)

1. **Double-entry integrity at DB level** — `enforce_balanced_journal_entry` trigger (was
   application-enforced in MySQL, routinely violated).
2. **`warehouse_stock` composite PK** `(warehouse_id, product_id)` — no `id` column (MySQL had
   an auto-increment id).
3. **`banks.balance` FLOAT → numeric(18,2)** — eliminates float rounding errors.
4. **ENUMs as VARCHAR + CHECK** — enables schema evolution without `ALTER TYPE` locking.
5. **Generated columns** — 8 STORED columns computed by PG (amount, difference, total_value).
6. **ON DUPLICATE KEY → ON CONFLICT** — 7 upserts converted to PG idiomatic syntax.
