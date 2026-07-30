# Database Reset & Restore — Basic Data Workflow

This document explains how to wipe the database and restore it to the "basic data" state (employees, users, customers, suppliers, products, branches, warehouses, banks, ledgers, menus, etc.) using two Artisan commands.

---

## Quick Start

```bash
# 1. Wipe all data (preserves schema + migrations table)
docker exec rcerp_app php artisan db:make-empty --force

# 2. Restore all basic data by re-running the 12 seed migrations
docker exec rcerp_app php artisan db:reseed-basic --force
```

After both commands complete, you should be able to log in normally.

---

## What Each Command Does

### `db:make-empty`

**File:** `laravel/app/Console/Commands/DbMakeEmpty.php`

- Discovers all 124 base tables in the `public` schema via `information_schema.tables`.
- **Preserves the `migrations` table** so Laravel still knows which migrations have run.
- Executes `TRUNCATE "t1", "t2", ... RESTART IDENTITY CASCADE` in a single statement — wipes all data and resets identity sequences.
- Verifies all tables are empty afterward.

**Flags:**
- `--force` — skip the interactive confirmation prompt.

### `db:reseed-basic`

**File:** `laravel/app/Console/Commands/DbReseedBasic.php`

Re-runs the 12 data-seeding migrations that originally populated the database. Since `db:make-empty` preserves the `migrations` table, `php artisan migrate` would normally skip these (they're marked as "ran"). This command works around that by:

1. **Verifying** the legacy SQL dump files are present at `/var/www/legacy/`.
2. **Deleting** the `migrations` table rows for the 12 data-only seed migrations.
3. **Calling** `php artisan migrate` — Laravel sees those 12 as "pending" and re-runs them in timestamp order.
4. **Reporting** before → after row counts for 13 key tables so you can verify success.

**Flags:**
- `--force` — skip the interactive confirmation prompt.

---

## The 12 Seed Migrations (re-run in order)

| # | Migration | Data Restored |
|---|-----------|---------------|
| 1 | `2025_01_05_000001_seed_default_chart_of_accounts` | 33 default ledger heads (Chart of Accounts) |
| 2 | `2025_01_09_000003_seed_return_notification_rules` | 4 sales-return notification rules |
| 3 | `2025_01_10_000001_seed_menus_from_legacy` | 52 menu entries |
| 4 | `2026_07_30_000005_migrate_legacy_admin_and_employee_data` | 146 employees + 136 users (from `admin_employee.sql`) |
| 5 | `2026_07_30_000006_make_e0001_superadmin_with_all_menus` | E0001 superadmin menu permissions |
| 6 | `2026_07_30_000007_make_emp0001_superadmin_with_all_menus` | EMP0001 superadmin menu permissions |
| 7 | `2026_07_30_000008_migrate_legacy_product_and_category_data` | 1,230 products + 25 categories + 2 groups + 6 UOM |
| 8 | `2026_07_30_000009_migrate_legacy_bank_data` | 31 banks |
| 9 | `2026_07_30_000010_migrate_legacy_supplier_data` | 107 suppliers |
| 10 | `2026_07_30_000011_migrate_legacy_customer_data` | 2,448 customers |
| 11 | `2026_07_30_000012_migrate_legacy_branch_and_warehouse_data` | 5 branches + 22 warehouses |
| 12 | `2026_07_30_000013_add_missing_legacy_ledger_heads` | 5 additional legacy ledger heads |

All 12 migrations are:
- **Pure INSERT/UPSERT** — none of them call `Schema::create` or `Schema::table` (they only insert data).
- **Idempotent** — use `ON CONFLICT DO UPDATE`, `insertOrIgnore`, or `updateOrInsert`, so re-running on a partially-populated DB is safe.
- **Schema-aware** — the notification_rules seeder detects whether the `recipient_type` column still exists or has been moved to the pivot table, and inserts accordingly.

---

## Required Legacy SQL Files

The migrations read from legacy MySQL dumps that must be present inside the Docker container at `/var/www/legacy/`. On the host, this maps to `./legacy/` in the project root.

| File | Size | Used By |
|------|------|---------|
| `osudlagb_remotecenter.sql` | ~7 MB | Migrations 7, 8, 9, 10, 11 (products, banks, suppliers, customers, branches/warehouses) |
| `admin_employee.sql` | ~100 KB | Migration 4 (employees + users) |

If either file is missing, `db:reseed-basic` will abort with a clear error listing all searched paths before making any changes.

---

## Why Not Snapshot/Restore?

An earlier approach used `db:snapshot-basic` to dump all 21 master tables into a single SQL file (`basic_data_snapshot.sql`) and `db:restore-basic` to replay it. **This approach was abandoned** because:

1. The snapshot used `INSERT INTO ... OVERRIDING SYSTEM VALUE` with inline column lists, but PostgreSQL rejected the syntax in certain configurations — all 4,318 INSERT statements failed.
2. Replaying raw SQL is fragile: string escaping, FK ordering, RLS policies, and identity column handling all need to be perfect.
3. The existing seed migrations already solve all of this using **parameterized queries** (`DB::statement` with `?` placeholders) and proper FK-safe insertion order.

The `db:reseed-basic` command reuses the original working migrations instead of replaying raw SQL — no escaping issues, no syntax problems.

---

## Verification

After `db:reseed-basic` completes, it prints a before → after row count table:

```
Row counts (before → after):
  ✓ branches                    0 →     5
  ✓ warehouses                  0 →    22
  ✓ employees                   0 →   146
  ✓ users                       0 →   136
  ✓ customers                   0 →  2448
  ✓ suppliers                   0 →   107
  ✓ products                    0 →  1230
  ✓ product_categories          0 →    25
  ✓ banks                       0 →    31
  ✓ ledgers                     0 →    38
  ✓ menus                       0 →    52
  ✓ user_menu_permissions       0 →   104
  ✓ notification_rules          0 →     4

✓ Restore complete. You should now be able to log in.
```

- `✓` = table has rows after restore.
- `⚠` = table is still empty (check migration output for errors).

---

## Troubleshooting

### "column recipient_type does not exist"

This was a schema-drift bug in the notification_rules seed migration, **fixed in commit `7235424`**. The seed migration is now schema-aware. If you see this error, `git pull` to get the latest version.

### "Cannot find osudlagb_remotecenter.sql"

The legacy SQL dumps are not in the Git repo (they're too large). They must be present at:
- **Host:** `./legacy/osudlagb_remotecenter.sql` and `./legacy/admin_employee.sql`
- **Container:** `/var/www/legacy/osudlagb_remotecenter.sql` and `/var/www/legacy/admin_employee.sql`

The `docker-compose.yml` mounts `./legacy` → `/var/www/legacy`, so just copy the files into the host `legacy/` directory. No container restart needed.

### Some tables still empty after reseed

Re-run with the migration output visible to see which migration failed:

```bash
docker exec rcerp_app php artisan db:reseed-basic --force
```

The output of `php artisan migrate` is printed inline. Look for the first error — that's the root cause. All 12 migrations are designed to be independent (each catches its own row-level errors), so a failure in one won't block the others.

### Want a truly fresh start

```bash
docker exec rcerp_app php artisan db:make-empty --force
docker exec rcerp_app php artisan db:reseed-basic --force
```

This is safe to run any number of times — both commands are idempotent.

---

## Command Reference

| Command | Purpose |
|---------|---------|
| `db:make-empty [--force]` | Wipe all data from all tables (preserves schema + migrations table). |
| `db:reseed-basic [--force]` | Re-run the 12 data-seeding migrations to restore basic data. |
| `db:snapshot-basic [--dry-run] [--table=]` | *(Deprecated)* Dump master tables to `basic_data_snapshot.sql`. |
| `db:restore-basic [--force] [--stop-on-error] [--show-statements]` | *(Deprecated)* Replay `basic_data_snapshot.sql`. Use `db:reseed-basic` instead. |

---

## File Locations

```
laravel/
├── app/Console/Commands/
│   ├── DbMakeEmpty.php          # db:make-empty command
│   ├── DbReseedBasic.php        # db:reseed-basic command (preferred restore method)
│   ├── DbSnapshotBasic.php      # db:snapshot-basic (deprecated)
│   └── DbRestoreBasic.php       # db:restore-basic (deprecated)
├── database/
│   ├── migrations/
│   │   ├── 2025_01_05_000001_seed_default_chart_of_accounts.php
│   │   ├── 2025_01_09_000003_seed_return_notification_rules.php    # schema-aware
│   │   ├── 2025_01_10_000001_seed_menus_from_legacy.php
│   │   ├── 2026_07_30_000005_migrate_legacy_admin_and_employee_data.php
│   │   ├── 2026_07_30_000006_make_e0001_superadmin_with_all_menus.php
│   │   ├── 2026_07_30_000007_make_emp0001_superadmin_with_all_menus.php
│   │   ├── 2026_07_30_000008_migrate_legacy_product_and_category_data.php
│   │   ├── 2026_07_30_000009_migrate_legacy_bank_data.php
│   │   ├── 2026_07_30_000010_migrate_legacy_supplier_data.php
│   │   ├── 2026_07_30_000011_migrate_legacy_customer_data.php
│   │   ├── 2026_07_30_000012_migrate_legacy_branch_and_warehouse_data.php
│   │   └── 2026_07_30_000013_add_missing_legacy_ledger_heads.php
│   └── sql/
│       └── basic_data_snapshot.sql  # deprecated snapshot (do not use)
└── ...

legacy/  (host) → /var/www/legacy/ (container)
├── osudlagb_remotecenter.sql   # ~7 MB legacy MySQL dump
└── admin_employee.sql          # ~100 KB admin + employee extract
```
