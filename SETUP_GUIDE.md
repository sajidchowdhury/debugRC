# RC_ERP_v2 — Complete Setup Guide

> **Last updated:** Phase 14 (User module complete)
> **8 of 9 administration modules production-ready** · 1185 tests · 2681 assertions · 87.93% coverage

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Clone the Repository](#2-clone-the-repository)
3. [Database Setup (pgAdmin)](#3-database-setup-pgadmin)
4. [Laravel Configuration](#4-laravel-configuration)
5. [Run Migrations + Seed Data](#5-run-migrations--seed-data)
6. [Create Admin User](#6-create-admin-user)
7. [Start the Server](#7-start-the-server)
8. [Test the Features](#8-test-the-features)
9. [Troubleshooting 500 Errors](#9-troubleshooting-500-errors)
10. [Performance Optimization](#10-performance-optimization)

---

## 1. Prerequisites

Install these on your machine:

| Software | Version | Download |
|----------|---------|----------|
| PHP | 8.2+ (8.4 recommended) | https://php.net |
| PostgreSQL | 14+ (17 recommended) | https://postgresql.org |
| Redis | 6+ (8 recommended) | https://redis.io |
| Composer | 2.x | https://getcomposer.org |
| pgAdmin | 4+ | https://pgadmin.org |
| Node.js | 18+ (for asset building) | https://nodejs.org |

### PHP Extensions Required

```bash
# Install these PHP extensions:
pdo, pdo_pgsql, pgsql, mbstring, dom, simplexml, xml, xmlreader, xmlwriter,
tokenizer, ctype, fileinfo, curl, bcmath, intl, phar, openssl, sodium, zip
```

**Ubuntu/Debian:**
```bash
sudo apt install php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-bcmath php8.4-intl php8.4-zip php8.4-sqlite3 php8.4-gd unzip
```

**Windows (XAMPP/WAMP):** Edit `php.ini` and uncomment:
```ini
extension=pdo_pgsql
extension=pgsql
extension=mbstring
extension=xml
extension=curl
extension=intl
extension=zip
```

---

## 2. Clone the Repository

```bash
# Clone the repo
git clone https://github.com/sajidchowdhury/RC_ERP_v2.git
cd RC_ERP_v2/laravel

# Install PHP dependencies
composer install --no-interaction --prefer-dist

# Install Node dependencies (for frontend assets, if needed)
npm install
```

---

## 3. Database Setup (pgAdmin)

### Step 3a: Create the Database

1. Open **pgAdmin** in your browser
2. Right-click **Servers** → **Register** → **Server**
3. **General tab**: Name = `RC ERP Local`
4. **Connection tab**:
   - Host: `localhost`
   - Port: `5432`
   - Username: `postgres` (or your PostgreSQL username)
   - Password: your PostgreSQL password
5. Click **Save**

6. Right-click **Databases** → **Create** → **Database**
   - Database: `rcerp`
   - Owner: `postgres` (or create a dedicated `rcerp_app` role)
   - Click **Save**

### Step 3b: Create the Database Role (optional but recommended)

```sql
-- Run this in pgAdmin's Query Tool
CREATE ROLE rcerp_app WITH LOGIN PASSWORD 'your_secure_password';
CREATE DATABASE rcerp OWNER rcerp_app;
GRANT ALL PRIVILEGES ON DATABASE rcerp TO rcerp_app;
```

### Step 3c: Load the Schema (THIS FIXES "can't see any tables")

> ⚠️ **This is the most important step.** If you skip this, you'll see no tables and get 500 errors.

**Option A: Load via pgAdmin UI**

1. In pgAdmin, right-click your `rcerp` database → **Query Tool**
2. Open each SQL file from `database/sql/` in order:
   - `01_auth_and_master.sql`
   - `02_accounting.sql`
   - `03_stock.sql`
   - `04_sales.sql`
   - `05_purchase.sql`
   - `06_payment_and_misc.sql`
   - `07_views_triggers_constraints.sql`
3. Paste each file's contents into the Query Tool and click **Execute (F5)**
4. You should now see 66 tables under your database → **Schemas** → **public** → **Tables**

**Option B: Load via command line (faster)**

```bash
cd RC_ERP_v2/laravel

# Replace 'postgres' with your PostgreSQL username
# Replace 'rcerp' with your database name

psql -U postgres -d rcerp -f database/sql/01_auth_and_master.sql
psql -U postgres -d rcerp -f database/sql/02_accounting.sql
psql -U postgres -d rcerp -f database/sql/03_stock.sql
psql -U postgres -d rcerp -f database/sql/04_sales.sql
psql -U postgres -d rcerp -f database/sql/05_purchase.sql
psql -U postgres -d rcerp -f database/sql/06_payment_and_misc.sql
psql -U postgres -d rcerp -f database/sql/07_views_triggers_constraints.sql
```

**Verify tables exist:**
```sql
-- Run in pgAdmin Query Tool
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';
-- Should return: 66+
```

---

## 4. Laravel Configuration

### Step 4a: Create .env file

```bash
cd RC_ERP_v2/laravel
cp .env.example .env
```

Edit `.env` and set these values:

```env
APP_NAME="RC ERP"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=Asia/Dhaka
APP_URL=http://localhost:8000

# Database — UPDATE THESE TO MATCH YOUR SETUP
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rcerp
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password

# Session + Cache — USE REDIS FOR PRODUCTION
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_DRIVER=sync

# Redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Step 4b: Generate the Application Key

```bash
php artisan key:generate
```

### Step 4c: Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
```

---

## 5. Run Migrations + Seed Data

> ⚠️ **This step fixes the 500 errors on Branch/Warehouse/Bank pages.**
> The migrations add missing columns (`created_by`, `deleted_at`, etc.) that the
> controllers require.

```bash
cd RC_ERP_v2/laravel

# Run all migrations
php artisan migrate --force

# Seed the default chart of accounts
php artisan db:seed --class=DefaultChartOfAccountsSeeder

# Seed the DB-driven menu system
php artisan db:seed --class=MenuSeeder

# Migrate master data from legacy (if applicable)
php artisan migrate:master-data
```

### Verify migrations ran successfully

```bash
php artisan migrate:status
```

All migrations should show **Ran = Yes**.

### What each migration does

| Migration | What it adds | Fixes |
|-----------|--------------|-------|
| `2025_01_10_000002_add_created_by_to_branches_and_warehouses` | `created_by` column + active indexes | 500 on Branch/Warehouse pages |
| `2025_01_12_000001_fix_employees_role_check` | Adds 'user' to role CHECK | Employee creation with role='user' |
| `2025_01_13_000001_add_soft_deletes_to_banks` | `deleted_at` + `deleted_by` on banks | 500 on Bank page |
| `2025_01_10_000003_drop_broken_product_price_history_trigger` | Drops trigger causing UPDATE crashes | Product price update 500 |

---

## 6. Create Admin User

You need at least one admin user to log in. Create a seeder script:

```bash
# Create a temporary seeder
php artisan make:seeder AdminUserSeeder
```

Edit `database/seeders/AdminUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create Head Office branch
        $branch = Branch::firstOrCreate(
            ['branch_code' => 'HO'],
            [
                'branch_name' => 'Head Office',
                'address' => '123 Main Street, Dhaka',
                'phone' => '02-1234567',
                'email' => 'ho@rcerp.com',
                'is_active' => true,
            ]
        );

        // Create admin employee
        $employee = Employee::firstOrCreate(
            ['employee_code' => 'EMP-0001'],
            [
                'name' => 'System Administrator',
                'role' => 'admin',
                'branch_id' => $branch->id,
                'phone' => '01711111111',
                'email' => 'admin@rcerp.com',
                'salary' => 100000,
                'joining_date' => '2024-01-01',
                'is_active' => true,
            ]
        );

        // Create admin user
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'employee_id' => $employee->id,
                'password_hash' => Hash::make('password123'),
                'is_active' => true,
                'credential_version' => 1,
            ]
        );

        echo "Admin user created: admin / password123\n";
    }
}
```

Run the seeder:

```bash
php artisan db:seed --class=AdminUserSeeder
```

---

## 7. Start the Server

```bash
cd RC_ERP_v2/laravel

# Start Laravel dev server
php artisan serve --host=0.0.0.0 --port=8000

# You should see:
# INFO  Server running on [http://0.0.0.0:8000]
```

Open your browser and go to: **http://localhost:8000/login**

Login with:
- **Username:** `admin`
- **Password:** `password123`

---

## 8. Test the Features

### 8a. Administration Modules (8 complete)

| Module | URL | What to test |
|--------|-----|-------------|
| Branch | `/admin/branches` | Create, edit, toggle, audit, export |
| Warehouse | `/admin/warehouses` | Create, edit, toggle, change branch |
| Product | `/admin/products` | Create, edit, price history, toggle |
| Customer | `/admin/customers` | Create, edit, toggle, ledger |
| Supplier | `/admin/suppliers` | Create, edit, toggle, ledger |
| Employee | `/admin/employees` | Create, edit, toggle, role assignment |
| Bank | `/admin/banks` | Create, edit, toggle, ledger mapping |
| **User** | `/admin/users` | **NEW** — Create, unlock, reset password, security audit |

### 8b. Run the Test Suite

```bash
cd RC_ERP_v2/laravel

# Run ALL tests (1185 tests, should all pass)
php artisan test
# OR
vendor/bin/phpunit

# Run tests for a specific module
vendor/bin/phpunit --filter=Branch
vendor/bin/phpunit --filter=User

# Generate coverage report
vendor/bin/phpunit -c phpunit-coverage.xml --coverage-html coverage/
```

### 8c. Run Verification Commands

```bash
# Verify chart of accounts
php artisan chart:validate

# Verify stock replay
php artisan stock:replay-verify

# Verify journal replay
php artisan journal:replay-verify

# Run reconciliation
php artisan sub-ledger:reconcile
```

---

## 9. Troubleshooting 500 Errors

### Problem: "500 Server Error" on Branch/Warehouse/Bank pages

**Cause:** Missing database columns that the controllers expect.

**Fix:**

```bash
# Step 1: Make sure you loaded the SQL schema (Section 3c)
# Step 2: Run all migrations
php artisan migrate --force

# Step 3: If migrations say "Nothing to migrate" but you still get 500,
# the migrations table is out of sync. Reset it:
php artisan migrate:fresh --force

# Step 4: Check the Laravel log for the actual error
tail -50 storage/logs/laravel.log
```

### Common 500 Error Causes + Fixes

| Error message | Cause | Fix |
|---------------|-------|-----|
| `column "created_by" does not exist` | Migration not run | `php artisan migrate --force` |
| `column "deleted_at" of relation "banks" does not exist` | Bank migration not run | `php artisan migrate --force` |
| `relation "branches" does not exist` | SQL schema not loaded | Load `database/sql/*.sql` files (Section 3c) |
| `relation "system_policies" does not exist` | Migration not run | `php artisan migrate --force` |
| `SQLSTATE[42P01]: Undefined table` | Table doesn't exist | Load SQL schema files |
| `Class "App\Http\Controllers\Controller" not found` | Missing base controller | Already fixed in latest commit |
| `Call to undefined method App\Models\Branch::active()` | Missing scope | Already fixed in latest commit |

### How to see the actual error

```bash
# Enable debug mode in .env
APP_DEBUG=true

# Check the log file
tail -100 storage/logs/laravel.log

# OR clear the log and reproduce the error
> storage/logs/laravel.log
# Now visit the page that gives 500, then:
cat storage/logs/laravel.log
```

---

## 10. Performance Optimization

### Problem: Page loading too long

**Causes + Fixes:**

#### 10a. Use Redis for sessions + cache (MUST DO)

```env
# In .env, change from:
SESSION_DRIVER=file
CACHE_DRIVER=file

# To:
SESSION_DRIVER=redis
CACHE_DRIVER=redis
```

Make sure Redis is running:
```bash
redis-cli ping
# Should return: PONG
```

#### 10b. Add database indexes (fixes slow queries)

Run this in pgAdmin Query Tool:

```sql
-- Add missing indexes for admin pages
CREATE INDEX IF NOT EXISTS idx_branches_active ON branches (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_warehouses_active ON warehouses (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_warehouses_branch ON warehouses (branch_id);
CREATE INDEX IF NOT EXISTS idx_employees_branch ON employees (branch_id);
CREATE INDEX IF NOT EXISTS idx_employees_active ON employees (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_customers_active ON customers (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_suppliers_active ON suppliers (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_banks_active ON banks (is_active) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_users_active ON users (is_active) WHERE is_active = true;

-- Analyze tables for query planner
ANALYZE;
```

#### 10c. Enable Laravel caching (production only)

```bash
# Cache config + routes + views (run after every deploy)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Clear caches when developing
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

#### 10d. Enable OPcache (PHP level)

In `php.ini`:
```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

#### 10e. Check slow queries

```bash
# Enable query logging temporarily
# In .env, add:
DB_LOG_SLOW_QUERIES=true
DB_SLOW_QUERY_THRESHOLD=200

# Check the log
tail -f storage/logs/laravel.log | grep "SLOW QUERY"
```

---

## Quick Start Summary (Copy-Paste)

```bash
# 1. Clone
git clone https://github.com/sajidchowdhury/RC_ERP_v2.git
cd RC_ERP_v2/laravel

# 2. Install dependencies
composer install --no-interaction --prefer-dist

# 3. Configure .env
cp .env.example .env
php artisan key:generate
# Edit .env: set DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 4. Load schema (MUST DO — fixes "no tables" issue)
psql -U postgres -d rcerp -f database/sql/01_auth_and_master.sql
psql -U postgres -d rcerp -f database/sql/02_accounting.sql
psql -U postgres -d rcerp -f database/sql/03_stock.sql
psql -U postgres -d rcerp -f database/sql/04_sales.sql
psql -U postgres -d rcerp -f database/sql/05_purchase.sql
psql -U postgres -d rcerp -f database/sql/06_payment_and_misc.sql
psql -U postgres -d rcerp -f database/sql/07_views_triggers_constraints.sql

# 5. Run migrations (MUST DO — fixes 500 errors)
php artisan migrate --force

# 6. Seed admin user
php artisan make:seeder AdminUserSeeder
# (Edit the seeder file as shown in Section 6)
php artisan db:seed --class=AdminUserSeeder

# 7. Start server
php artisan serve

# 8. Open http://localhost:8000/login
# Login: admin / password123

# 9. Run tests to verify everything works
vendor/bin/phpunit
```

---

## Support

If you still have issues after following this guide:

1. Check `storage/logs/laravel.log` for the actual error
2. Run `php artisan migrate:status` to verify all migrations ran
3. Run `php artisan tinker` and try:
   ```php
   \App\Models\Branch::count();
   \App\Models\Bank::count();
   ```
4. Verify Redis is running: `redis-cli ping`
5. Verify PostgreSQL is running: `psql -U postgres -c "SELECT 1"`

---

**Last updated:** Phase 14 (User module complete)
**Total tests:** 1185 passing, 2681 assertions, 87.93% line coverage
**Production-ready modules:** 8/9 (Branch, Warehouse, Product, Customer, Supplier, Employee, Bank, User)
