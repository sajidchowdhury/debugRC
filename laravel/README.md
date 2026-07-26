# Laravel Application — RC_ERP_v2

This directory will contain the Laravel 11 application that progressively replaces the legacy PHP app.

## Frontend CSS build (Tailwind v4)

The ERP design system uses **Tailwind CSS v4**, compiled by the Tailwind
CLI (not Vite). It coexists with Bootstrap 5 — Tailwind's Preflight is
intentionally skipped so Bootstrap's Reboot stays as the global base.

| | |
|---|---|
| **Source CSS** | `resources/css/rc-erp.css` |
| **Compiled CSS** | `public/assets/css/rc-erp.css` (**committed to git**) |
| **Build command** | `bun run build:css` |
| **Watch mode** | `bun run dev:css` |
| **Package manager** | **bun** (not npm — `package-lock.json` is gitignored) |

### The workflow (for AI + developers)

**You normally never run the build manually.** A pre-commit hook
(`git-hooks/pre-commit`, auto-installed via `composer install`)
detects when a `.blade.php` file changes, runs `bun run build:css`
for you, and re-stages the compiled CSS. Just:

1. Edit Blade (add/remove Tailwind classes)
2. `git add` + `git commit`
3. `git push`

The hook handles the rebuild. The end user then just runs `git pull`
and refreshes — no build step on their side.

### Why the compiled CSS is committed

So that `git pull` → refresh browser → everything works, with **zero
build step** on the consumer machine. The compiled CSS is served
statically via a cache-busted `<link>` tag
(`?v={{ filemtime(...) }}`).

### If you need to rebuild manually

```bash
cd laravel
bun install          # first time only
bun run build:css    # rebuild
```

### CI safety net

The `.github/workflows/css-guard.yml` workflow rebuilds the CSS in CI
on every push/PR and fails if the committed file differs from a fresh
build — catching any `git commit --no-verify` bypass.

### Full pipeline documentation

See `docs/css-build-investigation.md`, `docs/css-build-recommendation.md`,
and `docs/css-build-action-plan.md` for the full architecture rationale.

---

## Current status (end of Phase 2)

- **Schema migrations:** ✅ Complete. `database/migrations/2025_01_01_000001_create_rcerp_schema.php` + `database/sql/01-07_*.sql` define the full PostgreSQL schema (66 tables + 1 view + 4 triggers + 42 FKs).
- **ETL scripts:** ✅ Complete. `database/etl/` contains pgloader config + post-load fixes + sequence sync + verification.
- **Laravel app scaffold:** ⬜ Not yet created. Run the scaffold command below on the VPS in Phase 3.

## How to scaffold the Laravel app (Phase 3, on the VPS)

```bash
# 1. Install Composer (if not already installed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 2. Create the Laravel project IN this directory
cd /var/www/rcerp_v2/laravel
composer create-project laravel/laravel temp --prefer-dist
# Move the scaffold files into place (preserving our migrations + sql + etl dirs)
cp -r temp/app temp/bootstrap temp/config temp/routes temp/storage temp/public temp/artisan temp/composer.json temp/composer.lock ./
rm -rf temp

# 3. Install dependencies
composer require laravel/sanctum laravel/horizon predis/predis
composer require --dev larastan/larastan laravel/pint barryvdh/laravel-debugbar

# 4. Configure .env
cp .env.example .env
php artisan key:generate
# Edit .env: set DB_CONNECTION=pgsql, DB_HOST, DB_PORT=5432, DB_DATABASE=rcerp, etc.

# 5. Run the baseline migration (creates the full PG schema)
php artisan migrate

# 6. (Phase 2.3) Run the ETL to load data from MySQL
pgloader database/etl/pgloader.load
psql -U rcerp_app -d rcerp -f database/etl/post_load_fixes.sql
psql -U rcerp_app -d rcerp -f database/etl/sync_sequences.sql
psql -U rcerp_app -d rcerp -f database/etl/etl_verify.sql

# 7. Verify the schema
php artisan migrate:status
```

## Directory structure

```
laravel/
├── database/
│   ├── migrations/
│   │   └── 2025_01_01_000001_create_rcerp_schema.php   # Baseline schema migration
│   ├── sql/                                             # Raw PostgreSQL DDL (loaded by the migration)
│   │   ├── 01_auth_and_master.sql                      # branches, employees, users, products, customers, etc.
│   │   ├── 02_accounting.sql                            # ledgers, journal_entries, journal_lines, sub-ledgers
│   │   ├── 03_stock.sql                                 # stock_transactions, warehouse_stock, adjustments, transfers
│   │   ├── 04_sales.sql                                 # invoices, items, dispatches, challans, returns
│   │   ├── 05_purchase.sql                              # orders, receives, returns
│   │   ├── 06_payment_and_misc.sql                      # payments, transfers, income/expense, notifications
│   │   └── 07_views_triggers_constraints.sql            # view, updated_at triggers, missing FKs
│   ├── etl/                                             # MySQL → PostgreSQL data migration
│   │   ├── pgloader.load                                # pgloader configuration
│   │   ├── post_load_fixes.sql                          # banks.balance, zero-dates, type fixes
│   │   ├── sync_sequences.sql                           # Set IDENTITY sequences to MAX(id)
│   │   └── etl_verify.sql                               # Row counts + financial checksums
│   └── seeds/                                           # (Phase 3+) Database seeds
├── app/                                                 # (Phase 3+) Laravel application code
├── config/                                              # (Phase 3+) Laravel config
├── routes/                                              # (Phase 3+) Routes
└── README.md                                            # This file
```

## Schema design highlights

1. **Double-entry integrity at DB level:** A trigger (`enforce_balanced_journal_entry`) on `journal_lines` rejects any insert/update/delete that would leave a journal entry unbalanced (debits ≠ credits). This is the crown-jewel invariant.

2. **Non-negative stock at DB level:** A trigger (`prevent_negative_stock`) on `warehouse_stock` rejects any insert/update that would make qty negative (with -0.0001 tolerance for floating-point).

3. **Generated columns:** `purchase_order_items.amount`, `stock_transactions.total_value`, `sales_invoice_items.amount`, `sales_return_items.amount`, `stock_take_items.difference` are `GENERATED ALWAYS AS (...) STORED` — the DB computes them, not the app.

4. **Money precision:** All money columns are `numeric(15,2)` (GL) or `numeric(14,2)` (transactions). The MySQL `banks.balance FLOAT(20,2)` bug is fixed → `numeric(18,2)`.

5. **ENUMs as VARCHAR + CHECK:** All 38 MySQL ENUM columns are converted to `varchar(50) CHECK (col IN (...))` for extensibility (adding a value doesn't require a type migration).

6. **Composite PK on warehouse_stock:** `(warehouse_id, product_id)` — no surrogate `id`. This is the inventory state table; the composite PK enforces uniqueness naturally.

7. **Updated_at triggers:** MySQL's `ON UPDATE CURRENT_TIMESTAMP` is replicated via a single trigger function `update_updated_at_column()` applied to all 40+ tables with an `updated_at` column.

8. **Missing FKs added:** Legacy MySQL was missing FKs on `sales_invoices.customer_id`, `customer_ledger.customer_id`, etc. These are added in `07_views_triggers_constraints.sql` (after ETL, so orphan rows don't block).

## Phase 2 → Phase 3 transition

After Phase 2 sign-off:
- The legacy PHP app is running against PostgreSQL on the VPS.
- This Laravel directory has the schema + ETL but no application code yet.
- Phase 3 scaffolds the Laravel app, implements the shared session bridge, and ports the simplified auth system.
