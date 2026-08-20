# Remote Center ERP — Performance, Migration & Go-Live Guide

**Target:** remotecenter.com.bd (4-core / 4GB RAM / Ubuntu 22.04 / PHP 8.3 FPM / PostgreSQL 16 / Redis 8 / Nginx)
**Date:** July 2026
**Prepared by:** AI Assistant

---

# ═══════════════════════════════════════════════════════════════
# TASK 1: PERFORMANCE OPTIMIZATION — IMMEDIATE FIXES
# ═══════════════════════════════════════════════════════════════

## 1A. PHP OPcache Installation & Configuration

```bash
# Check current state
php -m | grep -i opcache
php -i | grep opcache.enable

# If not enabled, install
sudo apt install -y php8.3-opcache

# Create config file
sudo tee /etc/php/8.3/mods-available/opcache.ini > /dev/null <<'EOF'
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.revalidate_freq=2
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
opcache.enable_cli=0
opcache.jit=1255
opcache.jit_buffer_size=64M
EOF

# Enable and restart
sudo phpenmod opcache
sudo systemctl restart php8.3-fpm

# Verify
php -i | grep -E 'opcache\.(enable|memory_consumption|max_accelerated)'
```

**Expected result:** Pages that took 800ms+ should drop to 80-150ms after OPcache warms up.

---

## 1B. PHP-FPM Tuning for 4GB RAM

```bash
# Backup current config
sudo cp /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.bak

# Apply optimized config
sudo tee /etc/php/8.3/fpm/pool.d/www.conf.d/optimization.conf > /dev/null <<'EOF'
; Process Manager
pm = dynamic
pm.max_children = 40
pm.start_servers = 6
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 500
pm.process_idle_timeout = 10

; Connection handling
pm.max_spawn_rate = 10

; Child process limits (prevent memory leaks)
php_admin_value[memory_limit] = 128M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 25M
php_admin_value[max_execution_time] = 60

; Status page (optional - for monitoring)
pm.status_path = /fpm-status
EOF

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm

# Verify
sudo systemctl status php8.3-fpm
```

**Memory math:** 40 children × 128MB = 5.12GB theoretical max, but in practice PHP workers use 30-50MB each, so ~40 × 50MB = 2GB peak. The `pm.max_requests = 500` prevents memory leak accumulation.

---

## 1C. Redis Connection Diagnosis & Fix

```bash
# Step 1: Check if Redis is actually running
sudo systemctl status redis

# Step 2: Test Redis AUTH from CLI
redis-cli -a YOUR_REDIS_PASSWORD ping
# Expected: PONG

# Step 3: Check what the app actually uses for cache/session/queue
cd /var/www/remotecenter.com.bd/current/laravel

# Check .env for Redis config
grep -E 'REDIS_|CACHE_|SESSION_|QUEUE_' .env

# Step 4: Test Redis connection through Laravel
cd /var/www/remotecenter.com.bd/current/laravel
php artisan tinker --execute="echo Redis::connection()->ping();"

# Step 5: Verify cache driver
php artisan tinker --execute="echo config('cache.default');"

# Step 6: Verify session driver
php artisan tinker --execute="echo config('session.driver');"
```

### If Redis AUTH fails, fix the config:

```bash
# Check database.php redis config
grep -A 20 "'redis' =>" config/database.php
```

The Predis client in `config/database.php` must pass the password correctly:

```php
// config/database.php → 'redis' → 'default' context
'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],
    'cache' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_CACHE_DB', 1),
    ],
],
```

**IMPORTANT:** If `.env` has `REDIS_PASSWORD=null` but Redis actually requires a password (or vice versa), every request silently falls back to file cache → PostgreSQL for sessions. This is likely the #1 performance issue.

### After fixing Redis, ensure .env has:

```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=your_actual_password
REDIS_PORT=6379
```

Then:
```bash
sudo systemctl restart php8.3-fpm
php artisan config:cache
```

---

## 1D. Nginx Gzip Compression

```bash
# Find your Nginx site config
sudo nginx -T 2>/dev/null | grep -B5 'server_name.*remotecenter'

# Add gzip to the HTTPS server block
sudo tee /etc/nginx/conf.d/gzip.conf > /dev/null <<'EOF'
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 4;
gzip_min_length 256;
gzip_types
    text/plain
    text/css
    text/xml
    text/javascript
    application/json
    application/javascript
    application/xml
    application/xml+rss
    application/x-javascript
    image/svg+xml
    font/woff2
    font/woff
    application/wasm;
EOF

# Test and reload
sudo nginx -t && sudo systemctl reload nginx

# Verify
curl -sI -H 'Accept-Encoding: gzip' https://remotecenter.com.bd/ | grep -i 'content-encoding'
```

---

## 1E. Materialized Views Refresh

```bash
# Check if MVs are empty
sudo -u postgres psql -d rcerp -c "SELECT relname, n_live_tup FROM pg_stat_user_tables WHERE relname LIKE 'mv_%';"

# If counts show 0, refresh them
sudo -u postgres psql -d rcerp -c "SELECT refresh_all_report_views();"

# If the function doesn't exist, refresh manually
sudo -u postgres psql -d rcerp <<'SQL'
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ledger_balances;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_journal_entry_summary;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ar_aging;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ap_aging;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_stock_valuation;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_daily_sales;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_monthly_pnl;
SQL

# Verify
sudo -u postgres psql -d rcerp -c "SELECT relname, n_live_tup FROM pg_stat_user_tables WHERE relname LIKE 'mv_%';"
```

### Schedule auto-refresh (cron):
```bash
# Add to crontab (every 15 minutes during business hours)
(crontab -l 2>/dev/null; echo "*/15 8-22 * * * sudo -u postgres psql -d rcerp -c 'SELECT refresh_all_report_views();' > /dev/null 2>&1") | crontab -
```

---

## 1F. Slow Query Identification

```bash
# Enable temporary slow query logging (DO NOT leave on permanently)
sudo -u postgres psql -d rcerp <<'SQL'
ALTER SYSTEM SET log_min_duration_statement = 500;  -- log queries > 500ms
SELECT pg_reload_conf();
SQL

# After hitting a few pages, check the log
tail -100 /var/log/postgresql/postgresql-16-main.log | grep 'duration'

# When done, turn it OFF	sudo -u postgres psql -d rcerp <<'SQL'
ALTER SYSTEM RESET log_min_duration_statement;
SELECT pg_reload_conf();
SQL
```

### Quick PostgreSQL tuning for 4GB RAM:
```bash
sudo tee /etc/postgresql/16/main/conf.d/tuning.conf > /dev/null <<'EOF'
# Memory (4GB server, ~2.5GB for PostgreSQL)
shared_buffers = 1GB
effective_cache_size = 2GB
work_mem = 16MB
maintenance_work_mem = 256MB

# WAL
wal_buffers = 64MB
checkpoint_completion_target = 0.9
max_wal_size = 1GB
min_wal_size = 256MB

# Connections
max_connections = 100

# Planner
random_page_cost = 1.1
effective_io_concurrency = 200

# Logging
log_min_duration_statement = 500
log_checkpoints = on
EOF

sudo systemctl restart postgresql
```

---

## 1G. Laravel-Level Performance

```bash
cd /var/www/remotecenter.com.bd/current/laravel

# Cache all config, routes, views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev

# Check compiled views exist
ls -la bootstrap/cache/
```

---

# ═══════════════════════════════════════════════════════════════
# TASK 2: LEGACY DATA MIGRATION PLAN
# ═══════════════════════════════════════════════════════════════

## 2A. Migration Priority & Data Volume

| Priority | Table | Rows to Migrate | Dependency | Notes |
|----------|-------|-----------------|------------|-------|
| **P0** | branches | 4 | None | Referenced by everything |
| **P0** | employees | ~149 | branches | From admin_employee.sql (149 rows + 4 from osudlagb) |
| **P0** | users | ~50 | employees | From admin_employee.sql (link to employee_id) |
| **P0** | user_menu_permissions | ~50×? | users, menus | From admin_employee.sql |
| **P1** | product_categories | 27 | None | |
| **P1** | product_groups | 1 | None | Default group only |
| **P1** | products | 1,189 | categories, groups | |
| **P1** | warehouses | 22 | branches | |
| **P1** | customers | 2,448 | branches | |
| **P1** | suppliers | 107 | branches | **CLEAN FIRST** — rows 17-107 are test data |
| **P1** | banks | 31 | None | |
| **P1** | ledgers (chart of accounts) | 37 | None | |
| **P2** | warehouse_stock | 1,529 | products, warehouses | Current stock on hand |
| **P2** | document_sequences | 6 | None | Reset to current numbers |
| **P3** | journal_entries | 193 | ledgers, branches | Historical GL |
| **P3** | journal_lines | 434 | journal_entries, ledgers | |
| **P3** | customer_ledger | 1,090 | customers | |
| **P3** | supplier_ledger | 481 | suppliers | |
| **P3** | employee_ledger | 0 | employees | Empty in legacy |
| **P3** | sales_invoices | 526 | customers, branches | |
| **P3** | sales_invoice_items | 1,044 | invoices, products | |
| **P3** | customer_payments | ? | customers | |
| **P3** | supplier_payments | ? | suppliers | |
| **P3** | purchase_orders | ? | suppliers | |
| **P3** | purchase_receives | ? | suppliers | |
| **P3** | purchase_order_items | ? | |
| **P3** | purchase_receive_items | ? | |
| **P3** | stock_transactions | ? | products, warehouses | |
| **P3** | money_transfers | ? | banks, branches | |
| **P3** | other_incomes | ? | |
| **P3** | other_expenses | ? | |

---

## 2B. Column Mapping — Old MySQL → New PostgreSQL

### BRANCHES
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| branch_code | branch_code | varchar(20) → varchar(20) | Direct |
| branch_name | branch_name | varchar(100) → varchar(100) | Direct |
| address | address | text → text | Direct |
| phone | phone | varchar(20) → varchar(30) | Wider in new |
| email | email | varchar(100) → varchar(100) | Direct |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_by | — | — | **DROP** (not in new schema) |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |
| — | company_id | — | **DEFAULT NULL** (set after company creation) |

### EMPLOYEES
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| employee_code | employee_code | varchar(20) → varchar(30) | Wider in new |
| name | name | varchar(100) → varchar(100) | Direct |
| father_name | — | — | **DROP** (not in new schema) |
| mother_name | — | — | **DROP** |
| date_of_birth | — | — | **DROP** |
| nid | — | — | **DROP** |
| mobile | phone | varchar(15) → varchar(30) | **RENAMED** |
| email | email | varchar(100) → varchar(100) | Direct |
| address | address | text → text | Direct |
| branch_id | branch_id | int → int FK | Direct |
| department | — | — | **DROP** |
| designation | — | — | **DROP** |
| role | role | enum → varchar(30) | Direct (same values) |
| joining_date | joining_date | date → date | Direct |
| salary | salary | decimal → numeric(12,2) | Direct |
| bank_account | — | — | **DROP** |
| blood_group | — | — | **DROP** |
| photo | photo | varchar(255) → varchar(255) | Direct |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_by | — | — | **DROP** |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| deleted_at | deleted_at | datetime → timestamp(0) | Direct |
| deleted_by | deleted_by | int → int | Direct |

### USERS (admin_employee.sql → new users table)
| Old Column (admin table) | New Column | Transform | Notes |
|----------------------|------------|----------|-------|
| id | — | — | **SKIP** (new table uses own IDENTITY) |
| employee_id | employee_id | int → int FK | **LINK to employees table** |
| username | username | varchar(50) → varchar(50) | Direct |
| password | password_hash | varchar(200) → varchar(255) | **RENAMED** — bcrypt hashes are compatible |
| — | is_active | — | Set based on `hr_status = 'Active'` |
| — | credential_version | — | **DEFAULT 1** |
| — | last_login | — | **DEFAULT NULL** |
| — | last_login_ip | — | **DEFAULT NULL** |
| — | last_login_user_agent | — | **DROP** (not in new schema) |
| — | failed_login_count | — | **DEFAULT 0** |
| — | locked_until | — | **DEFAULT NULL** |
| — | created_at | — | **DEFAULT NOW()** |
| — | updated_at | — | **DEFAULT NOW()** |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |

**CRITICAL:** The old `admin` table has 149 user rows. Many have `hr_status = 'Block'` — these should be migrated as `is_active = false` but with their data preserved.

**CRITICAL:** Old `dypricpt_pass` column contains PLAIN TEXT passwords. **NEVER** expose or use these. Only use `password` column (bcrypt hash).

**CRITICAL:** User ID 149 (username 'E0001', employee_id=1, superadmin) must be the first user created. Their `employee_id=1` must match `employees.id=1`.

### CUSTOMERS
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| customer_code | customer_code | varchar(50) → varchar(30) | Narrower — check for long codes |
| shop_name | shop_name | varchar(150) → varchar(200) | Direct |
| customer_name | customer_name | varchar(100) → varchar(200) | Wider in new |
| mobile | mobile | varchar(15) → varchar(30) | Wider in new |
| — | phone | — | **DEFAULT NULL** |
| — | email | — | **DEFAULT NULL** |
| address | address | text → text | Direct |
| — | branch_id | — | **DEFAULT NULL** (old system had no branch per customer) |
| sales_person_id | sales_person_id | int → int | Direct (no FK in new) |
| credit_limit | credit_limit | decimal → numeric(14,2) | Direct |
| — | opening_balance | — | **DEFAULT 0** (handled via ledger) |
| — | balance_type | — | **DEFAULT 'debit'** |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_by | — | — | **DROP** |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |

### SUPPLIERS
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Preserve IDs 1-16 (real data). **SKIP 17-107** (test data) |
| supplier_code | supplier_code | varchar(50) → varchar(30) | Narrower — check codes |
| supplier_name | supplier_name | varchar(150) → varchar(200) | Wider in new |
| — | phone | — | **DEFAULT NULL** |
| mobile | mobile | varchar(15) → varchar(30) | Wider |
| — | email | — | **DEFAULT NULL** |
| address | address | text → text | Direct |
| — | branch_id | — | **DEFAULT NULL** |
| — | contact_person | — | **DEFAULT NULL** |
| — | opening_balance | — | **DEFAULT 0** |
| — | balance_type | — | **DEFAULT 'credit'** |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_at | created_at | datetime → timestamp(0) | Direct |
| created_by | — | — | **DROP** |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |

### PRODUCTS
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| product_code | product_code | varchar(50) → varchar(50) | Direct |
| product_name | product_name | varchar(200) → varchar(200) | Direct |
| category_id | category_id | int → int FK | Direct |
| group_id | group_id | int → int FK | Direct (DEFAULT 1 if null) |
| unit | unit | enum → varchar(20) | Direct (same values) |
| pcs_per_carton | — | — | **DROP** (not in new schema) |
| safety_stock | min_stock | decimal → numeric(12,4) | **RENAMED** |
| — | max_stock | — | **DEFAULT 0** |
| — | reorder_level | — | **DEFAULT 0** |
| — | purchase_rate | — | **DEFAULT 0** |
| — | sales_rate | — | **DEFAULT 0** |
| image | product_image | varchar(225) → varchar(255) | **RENAMED** |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_at | created_at | datetime → timestamp(0) | Direct |
| created_by | — | — | **DROP** |
| — | updated_at | — | **DEFAULT NOW()** |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |
| — | condition_state | — | **DEFAULT 'Good'** |

### WAREHOUSES
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| warehouse_code | warehouse_code | varchar(20) → varchar(30) | Wider in new |
| warehouse_name | warehouse_name | varchar(100) → varchar(100) | Direct |
| branch_id | branch_id | int → int FK | Direct |
| address | location | text → text | **RENAMED** |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_by | — | — | **DROP** |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |
| — | is_frozen_for_count | — | **DEFAULT false** |

### BANKS
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| bank_name | bank_name | varchar(100) → varchar(100) | Direct |
| account_number | account_number | varchar(50) → varchar(50) | Direct |
| account_name | account_holder | text → varchar(100) | **RENAMED** |
| branch_name | branch_name | varchar(100) → varchar(100) | Direct |
| balance | balance | float → numeric(18,2) | **Type fix** |
| updated_at | updated_at | int → date | **Type change** — old stores as UNIX timestamp! Convert: `to_date('1970-01-01','YYYY-MM-DD') + (updated_at \|\| ' seconds')::interval` |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| created_by | created_by | int → int | Direct |
| created_at | created_at | datetime → timestamp(0) | Direct |
| — | ledger_id | — | **DEFAULT NULL** (set up later via bank_ledger_mappings) |

### LEDGERS (Chart of Accounts)
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | int → int (IDENTITY) | Must preserve IDs |
| ledger_code | ledger_code | varchar(20) → varchar(20) | Direct |
| ledger_name | ledger_name | varchar(150) → varchar(100) | **Narrower** — check for long names |
| description | description | text → text | Direct |
| parent_id | parent_id | int → int | Direct (0 = root) |
| account_type | account_type | enum → varchar(20) | Direct (same 5 values) |
| ledger_nature | ledger_nature | varchar(60) → varchar(50) | Direct |
| — | — | — | normal_balance: derive from account_type (Asset/Expense=debit, Liability/Equity/Income=credit) |
| sort_order | sort_order | int → int | Direct |
| is_active | is_active | tinyint → boolean | **0/1 → false/true** |
| is_system | is_system | tinyint → boolean | **0/1 → false/true** |
| is_control_account | is_control_account | tinyint → boolean | **0/1 → false/true** |
| control_account_type | control_account_type | varchar(50) → varchar(30) | Direct |
| created_by | created_by | int → int | Direct |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | opening_balance | — | **DEFAULT 0** |
| — | deleted_at | — | **DEFAULT NULL** |
| — | deleted_by | — | **DEFAULT NULL** |
| — | is_elimination | — | **DEFAULT false** |

### CUSTOMER_LEDGER
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | bigint → int (IDENTITY) | Must preserve IDs |
| transaction_date | transaction_date | date → date | Direct |
| customer_id | customer_id | int → int | Direct |
| branch_id | branch_id | int → int | Direct |
| reference_type | reference_type | enum → varchar(30) | Direct |
| reference_id | reference_id | bigint → int | Direct |
| debit | debit | decimal → numeric(14,2) | Direct |
| credit | credit | decimal → numeric(14,2) | Direct |
| running_balance | balance | decimal → numeric(14,2) | **RENAMED** |
| remarks | description | text → text | **RENAMED** |
| created_by | created_by | int → int | Direct |
| is_reversed | — | — | **DEFAULT false** (new column) |
| — | transaction_type | — | **SET from reference_type** (invoice→'invoice', payment→'payment', etc.) |
| — | journal_entry_id | — | **DEFAULT NULL** (backfill from journal_entries later) |
| created_at | created_at | datetime → timestamp(0) | **DEFAULT NOW()** |

### SUPPLIER_LEDGER
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | bigint → int (IDENTITY) | Must preserve IDs |
| transaction_date | transaction_date | date → date | Direct |
| supplier_id | supplier_id | int → int | Direct |
| branch_id | branch_id | int → int | Direct |
| reference_type | reference_type | enum → varchar(30) | Direct |
| reference_id | reference_id | bigint → int | Direct |
| debit | debit | decimal → numeric(14,2) | Direct |
| credit | credit | decimal → numeric(14,2) | Direct |
| running_balance | balance | decimal → numeric(14,2) | **RENAMED** |
| remarks | description | text → text | **RENAMED** |
| created_by | created_by | int → int | Direct |
| is_reversed | — | — | **DEFAULT false** (new column) |
| — | transaction_type | — | **SET from reference_type** |
| — | journal_entry_id | — | **DEFAULT NULL** |
| created_at | created_at | datetime → timestamp(0) | **DEFAULT NOW()** |

### JOURNAL_ENTRIES
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | bigint → int (IDENTITY) | Must preserve IDs |
| entry_no | entry_no | varchar(30) → varchar(30) | Direct |
| entry_date | entry_date | date → date | Direct |
| description | description | text → text | Direct |
| reference_type | reference_type | varchar(50) → varchar(30) | Narrower — check for long values |
| reference_id | reference_id | bigint → int | Direct |
| branch_id | branch_id | int → int | Direct |
| total_debit | — | — | **DROP** (denormalized — can recompute) |
| total_credit | — | — | **DROP** |
| is_posted | — | — | **DROP** (always posted in legacy) |
| posted_at | — | — | **DROP** |
| is_reversed | is_reversed | tinyint → boolean | **0/1 → false/true** |
| reversal_of_entry_id | reversal_of_entry_id | bigint → int | Direct |
| created_by | created_by | int → int | Direct |
| created_at | created_at | datetime → timestamp(0) | Direct |
| updated_at | updated_at | datetime → timestamp(0) | Direct |
| — | source | — | **DEFAULT 'manual'** |
| — | reversed_at | — | **DEFAULT NULL** |
| — | reversed_by | — | **DEFAULT NULL** |
| — | reverse_reason | — | **DEFAULT NULL** |

### JOURNAL_LINES
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| id | id | bigint → int (IDENTITY) | Must preserve IDs |
| journal_entry_id | journal_entry_id | bigint → int FK | Direct |
| ledger_id | ledger_id | int → int | Direct |
| debit | debit | decimal → numeric(15,2) | Direct |
| credit | credit | decimal → numeric(15,2) | Direct |
| description | memo | varchar(255) → text | **RENAMED** |
| entity_type | entity_type | varchar(30) → varchar(30) | Direct |
| entity_id | entity_id | bigint → int | Direct |
| created_at | created_at | datetime → timestamp(0) | Direct |
| — | dimension_value_id | — | **DEFAULT NULL** |

### WAREHOUSE_STOCK
| Old Column | New Column | Type Transform | Notes |
|------------|------------|---------------|-------|
| warehouse_id | warehouse_id | int → int (PK) | Direct |
| product_id | product_id | int → int (PK) | Direct |
| qty | qty | decimal → numeric(14,4) | Direct |
| avg_cost | avg_cost | decimal → numeric(12,2) | Direct |
| — | total_qty | — | **SET = qty** (denormalized copy) |
| — | total_value | — | **SET = qty * avg_cost** |
| — | updated_at | — | **DEFAULT NOW()** |

---

## 2C. Migration Execution Script

Create this file on the VPS: `/var/www/remotecenter.com.bd/current/laravel/database/etl/migrate_legacy_data.php`

This is an Artisan command that reads from the MySQL dump and inserts into PostgreSQL.

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateLegacyData extends Command
{
    protected $signature = 'legacy:migrate
        {--step=1 : Migration step (1=master, 2=stock, 3=transactions, 4=sequences, 5=verify)}
        {--source= : Path to osudlagb_remotecenter.sql}
        {--admin-source= : Path to admin_employee.sql}
        {--dry-run : Show what would be migrated without inserting}';

    protected $description = 'Migrate data from legacy MySQL to new PostgreSQL system';

    // ... (full implementation would be 2000+ lines)
    // Key patterns:
    // 1. Parse MySQL INSERT statements with regex
    // 2. Transform data types (tinyint→boolean, enum→varchar, datetime→timestamp)
    // 3. Use INSERT ... ON CONFLICT DO NOTHING for idempotency
    // 4. Wrap each table in a transaction
    // 5. Log row counts and errors
}
```

### Recommended approach — Use SQL directly:

Since the legacy dump is in MySQL syntax, the easiest approach is:

1. **Load the MySQL dump into a temporary MySQL database** (or use the existing one)
2. **Use pgloader or a PHP ETL script** to transfer data
3. **Run transformation SQL** after the initial load

```bash
# Option A: pgloader (fastest)
# Install pgloader
sudo apt install -y pgloader

# Create a pgloader config
cat > /tmp/migrate.load <<'EOF'
LOAD DATABASE
     FROM mysql://root:password@legacy-host/osudlagb_remotecenter
     INTO postgresql://rcerp_user:password@localhost/rcerp

WITH include only
     TABLE NAMES MATCHING 'branches',
                         'warehouses', 
                         'product_categories',
                         'products',
                         'customers',
                         'suppliers',
                         'banks',
                         'ledgers',
                         'journal_entries',
                         'journal_lines',
                         'customer_ledger',
                         'supplier_ledger',
                         'warehouse_stock',
                         'document_sequences'

SET MAINTENANCE MODE OFF

BEFORE LOAD DO
    $$ ALTER TABLE branches DISABLE TRIGGER ALL; $$,
    $$ ALTER TABLE warehouses DISABLE TRIGGER ALL; $$,
    $$ ALTER TABLE products DISABLE TRIGGER ALL; $$

AFTER LOAD DO
    $$ ALTER TABLE branches ENABLE TRIGGER ALL; $$,
    $$ ALTER TABLE warehouses ENABLE TRIGGER ALL; $$,
    $$ ALTER TABLE products ENABLE TRIGGER ALL; $$

CAST
    type tinyint to boolean,
    type enum to varchar,
    type datetime to timestamp,
    type float to numeric

ON CONFLICT DO NOTHING;
EOF

pgloader /tmp/migrate.load
```

### Option B: PHP Artisan Command (more control)

For the employee/admin data, create a dedicated migration since it comes from a separate database:

```php
// database/migrations/2026_07_30_000005_migrate_legacy_admin_and_employee_data.php
// FIX: Make idempotent — skip if no legacy file found

public function up(): void
{
    $candidates = [
        database_path('sql/admin_employee.sql'),
        database_path('legacy/admin_employee.sql'),
        base_path('legacy/admin_employee.sql'),
        '/var/www/legacy/admin_employee.sql',
    ];

    $sqlPath = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $sqlPath = $c;
            break;
        }
    }

    if ($sqlPath === null) {
        $this->command->warn('admin_employee.sql not found — skipping legacy data migration (fresh deploy).');
        return;
    }

    // Parse the SQL file and migrate data
    // ... implementation
}
```

### Data cleanup needed BEFORE migration:

```sql
-- 1. Remove test supplier data (IDs 17-107)
-- These were bulk-generated on 2026-06-04 06:16:36
-- Keep only IDs 1-16 (real suppliers)

-- 2. Fix MySQL zero-dates ('0000-00-00 00:00:00') in customers
-- These should be NULL in PostgreSQL

-- 3. Fix bank.updated_at (stored as UNIX timestamp int, not datetime!)
-- Convert: to_timestamp(updated_at) where updated_at > 10000000

-- 4. supplier_codes for test data are auto-generated (S489130, S749220, etc.)
-- Only real suppliers (1-16) have proper codes (0001-0016)
```

---

## 2D. Supplier Data Cleanup

The legacy supplier table has **91 test/duplicate entries** (IDs 17-107) created on `2026-06-04 06:16:36`. These must be excluded.

```sql
-- Real suppliers to migrate (IDs 1-16):
-- 1: Khadiza Plastic
-- 2: Ma Ayesha Plastic
-- 3: M/S Foyaz Plastic
-- 4: M/S Monia Plastic
-- 5: Mirdha Printing & Packing
-- 6: M/S Sayma Plastic
-- 7: Shat Rang Traders
-- 8: Faruk Traders
-- 9: Giyas Uddin
-- 10: Rc Star Industry
-- 11: M/S Mizan Plastic Products
-- 12: Tanha Plastic
-- 13: CHINA
-- 14: SOUDIA ELECTRONICS
-- 15: Alif Electronics
-- 16: Ahad Elecltronics

-- ID 17 (S-0017) is clearly a test entry (name='1', address='1', mobile='1')
```

---

# ═══════════════════════════════════════════════════════════════
# TASK 3: GO-LIVE PREPARATION CHECKLIST
# ═══════════════════════════════════════════════════════════════

## 3A. Fiscal Year Setup

**How it works in the new system:**
- `fiscal_years` table is created by migration `2026_08_10_000004`
- The old system has **NO fiscal_years table** — it only has `accounting_periods` (per-branch period close)
- The new system has both: `fiscal_years` (company-wide) + `fiscal_periods` (monthly within each FY) + `accounting_periods` (per-branch close date)

**Action required:**
```sql
-- Create fiscal year 2026-2027 (Bangladesh FY: Jul-Jun)
INSERT INTO fiscal_years (name, fiscal_year_code, start_date, end_date, period_type, status, is_current)
VALUES ('FY 2026-2027', 'FY2026', '2026-07-01', '2027-06-30', 'monthly', 'active', true);

-- The migration should auto-create 12 fiscal_periods for monthly type
-- Verify:
SELECT * FROM fiscal_periods WHERE fiscal_year_id = (SELECT id FROM fiscal_years WHERE is_current = true);
```

**Or via Artisan (if available):**
```bash
php artisan fiscal-year:create --name="FY 2026-2027" --start="2026-07-01" --end="2027-06-30" --period-type=monthly
```

---

## 3B. Opening Balances

### Customer Ledger Opening Balances

The customer_ledger has 1,090 rows of historical data. The `running_balance` column shows the cumulative balance. The **final running_balance per customer** becomes the opening balance.

```sql
-- Get opening balances per customer (latest balance from legacy)
SELECT customer_id, running_balance
FROM customer_ledger cl1
WHERE cl1.id = (
    SELECT MAX(id) FROM customer_ledger cl2 WHERE cl2.customer_id = cl1.customer_id
)
ORDER BY customer_id;

-- Alternatively, use the MAX running_balance approach (handles reversals)
SELECT customer_id, 
       MAX(running_balance) FILTER (WHERE is_reversed = 0) as opening_balance
FROM customer_ledger
GROUP BY customer_id;
```

**How to enter:** After migrating customer_ledger data, the opening balances are implicit in the ledger. If starting fresh (no historical data), use the `customers.opening_balance` column.

### Supplier Ledger Opening Balances

```sql
-- Same approach for suppliers
SELECT supplier_id, running_balance
FROM supplier_ledger sl1
WHERE sl1.id = (
    SELECT MAX(id) FROM supplier_ledger sl2 WHERE sl2.supplier_id = sl1.supplier_id
)
ORDER BY supplier_id;
```

### Warehouse Stock Opening Balances

```sql
-- warehouse_stock has 1,529 rows with current qty and avg_cost
-- This IS the opening balance — just migrate it directly
SELECT warehouse_id, product_id, qty, avg_cost
FROM warehouse_stock
WHERE qty > 0
ORDER BY warehouse_id, product_id;
```

### Ledger (GL) Opening Balances

```sql
-- The 37 ledgers in the old system have no opening_balance column
-- Opening balances must be derived from journal_lines
SELECT jl.ledger_id, l.ledger_name,
       SUM(jl.debit) - SUM(jl.credit) as balance
FROM journal_lines jl
JOIN ledgers l ON l.id = jl.ledger_id
JOIN journal_entries je ON je.id = jl.journal_entry_id
WHERE je.is_reversed = 0
GROUP BY jl.ledger_id, l.ledger_name
ORDER BY jl.ledger_id;

-- Then update: UPDATE ledgers SET opening_balance = <derived_balance> WHERE id = <id>;
```

---

## 3C. Document Sequences

**Current state:** 6 rows in legacy `document_sequences` table with `branch_id = 0` (global).

```sql
-- Legacy sequences (need to update last_number to actual max)
-- sales_invoice:     period 20260619 → last_number 3
-- sales_return:     period 20260618 → last_number 1
-- sales_challan:    period 2026 → last_number 2
-- customer_payment: period 20260619 → last_number 5
```

**Action required:**
```sql
-- After migrating transactions, update sequences to actual max

-- Sales invoices
SELECT MAX(CAST(SPLIT_PART(invoice_code, '-', 2) AS INTEGER)) 
FROM sales_invoices;
-- → Update document_sequences last_number accordingly

-- Purchase receives
SELECT MAX(CAST(SPLIT_PART(receive_code, '-', 2) AS INTEGER)) 
FROM purchase_receives;

-- Customer payments
SELECT MAX(CAST(SPLIT_PART(payment_code, '-', 2) AS INTEGER)) 
FROM customer_payments;

-- Supplier payments
SELECT MAX(CAST(SPLIT_PART(payment_code, '-', 2) AS INTEGER)) 
FROM supplier_payments;

-- Journal entries
SELECT MAX(CAST(SPLIT_PART(entry_no, '-', 3) AS INTEGER)) 
FROM journal_entries;
```

**How sequences work:** The new system uses `document_sequences` with `(doc_type, branch_id, period_key)` as unique key. For global sequences, `branch_id = 0`. The `period_key` format is typically `YYYYMM` or `YYYYMMDD`.

---

## 3D. Company Settings

**The new system has a `companies` table** (from `08_consolidation.sql`), but no generic `settings` table.

```sql
-- Create the company
INSERT INTO companies (company_code, company_name, legal_name, address, phone, email, currency, is_consolidation_parent, ownership_pct, status)
VALUES ('RC', 'Remote Center', 'M/S Remote Center', 'Nurul Haque Tower, Patuatuli, Dhaka', NULL, 'sajidchowdhury35@gmail.com', 'BDT', true, 100.00, 'active');

-- Link branches to company
UPDATE branches SET company_id = (SELECT id FROM companies WHERE company_code = 'RC');
```

**Company name/address/logo:** These are likely stored in the Laravel `config/` files or Blade templates, not in the database. Check:
```bash
grep -r 'company_name\|company_address\|logo' config/ resources/views/
```

---

## 3E. Notification Rules

```sql
-- Check existing notification rules
SELECT id, name, event, channel, is_active, times_fired 
FROM notification_rules;

-- Check recipients
SELECT nr.name, nrr.recipient_type, nrr.recipient_user_id
FROM notification_rules nr
JOIN notification_rule_recipients nrr ON nrr.notification_rule_id = nr.id;

-- If empty, seed basic notification rules
INSERT INTO notification_rules (name, event, channel, is_active, description) VALUES
('Low Stock Alert', 'stock.low', 'database', true, 'Alert when product stock falls below safety level'),
('Sales Invoice Created', 'sales.invoice.created', 'database', true, 'Notify when new sales invoice is created'),
('Purchase Order Pending', 'purchase.order.pending', 'database', true, 'Notify when PO needs approval'),
('Stock Take Completed', 'stock_take.completed', 'database', true, 'Notify when stock take session is posted');
```

---

## 3F. System Policies

```sql
-- Check system policies
SELECT * FROM system_policies WHERE is_active = true;

-- Should be empty or have one NORMAL mode entry
-- If empty, ensure default exists:
INSERT INTO system_policies (mode, is_active, reason, activation_source)
VALUES ('NORMAL', true, 'Initial setup', 'system')
ON CONFLICT DO NOTHING;
```

---

## 3G. Pre-Go-Live Checklist

### Infrastructure
- [ ] PHP 8.3 OPcache enabled and verified
- [ ] PHP-FPM tuned (pm=dynamic, max_children=40)
- [ ] Redis running and accessible from Laravel (ping test passed)
- [ ] CACHE_DRIVER=redis in .env
- [ ] SESSION_DRIVER=redis in .env
- [ ] Nginx gzip compression enabled
- [ ] PostgreSQL shared_buffers = 1GB
- [ ] SSL certificate valid and auto-renewing (certbot)
- [ ] Laravel config:cache, route:cache, view:cache executed
- [ ] Composer --optimize-autoloader --no-dev executed
- [ ] File permissions correct (storage/logs writable by www-data)
- [ ] Cron jobs running: `php artisan schedule:run` every minute

### Data Migration
- [ ] Branches migrated (4 rows)
- [ ] Warehouses migrated (22 rows)
- [ ] Product categories migrated (27 rows)
- [ ] Product groups migrated (1 default row)
- [ ] Products migrated (1,189 rows) — verify codes preserved
- [ ] Customers migrated (2,448 rows) — fix '0000-00-00' dates to NULL
- [ ] Suppliers migrated (16 real rows, NOT 91 test rows 17-107)
- [ ] Banks migrated (31 rows) — fix updated_at UNIX timestamp conversion
- [ ] Chart of Accounts / Ledgers migrated (37 rows)
- [ ] Employees migrated from admin_employee.sql (~149 rows)
- [ ] Users migrated from admin_employee.sql (~149 rows, link to employees)
- [ ] User menu permissions migrated
- [ ] Warehouse stock migrated (1,529 rows) — current stock on hand
- [ ] Customer ledger migrated (1,090 rows)
- [ ] Supplier ledger migrated (481 rows)
- [ ] Journal entries migrated (193 rows)
- [ ] Journal lines migrated (434 rows)
- [ ] All historical transactions migrated (sales invoices, purchase receives, payments, etc.)

### Accounting Setup
- [ ] Fiscal year 2026-2027 created (Jul 2026 - Jun 2027)
- [ ] 12 fiscal periods auto-generated
- [ ] Ledger opening balances set (derived from journal_lines)
- [ ] Customer opening balances verified (from customer_ledger)
- [ ] Supplier opening balances verified (from supplier_ledger)
- [ ] Materialized views refreshed (mv_ledger_balances, etc.)
- [ ] Bank-ledger mappings created (bank_ledger_mappings table)
- [ ] Accounting periods not closed for current period

### Document Sequences
- [ ] sales_invoice sequence set to max + 1
- [ ] sales_return sequence set to max + 1
- [ ] sales_challan sequence set to max + 1
- [ ] customer_payment sequence set to max + 1
- [ ] supplier_payment sequence set to max + 1
- [ ] purchase_receive sequence set to max + 1
- [ ] purchase_return sequence set to max + 1
- [ ] journal_entry sequence set to max + 1
- [ ] stock_adjustment sequence set to max + 1
- [ ] warehouse_transfer sequence set to max + 1
- [ ] money_transfer sequence set to max + 1
- [ ] employee_transaction sequence set to max + 1

### System Configuration
- [ ] Company record created in `companies` table
- [ ] Branches linked to company (company_id set)
- [ ] Notification rules configured
- [ ] System policy set to NORMAL mode
- [ ] Branch cash initialized (branch_cash table)
- [ ] Menus seeded and permissions assigned to admin user

### Security
- [ ] Admin user can log in with old password (bcrypt compatible)
- [ ] All blocked users have is_active = false
- [ ] TOTP disabled for all users (was optional in old, not in new)
- [ ] RLS policies verified (test with non-admin user)
- [ ] .env APP_KEY set (not default)
- [ ] .env DB_PASSWORD is strong
- [ ] .env REDIS_PASSWORD is set and matches Redis config

### Verification Tests
- [ ] **Login test:** Admin (E0001) can log in
- [ ] **Dashboard loads:** No 500 errors, shows data
- [ ] **Product list:** 1,189 products visible
- [ ] **Customer list:** 2,448 customers visible
- [ ] **Create sales invoice:** Full flow works (select customer → add products → save)
- [ ] **Create purchase receive:** Full flow works
- [ ] **Stock check:** Warehouse stock matches expected quantities
- [ ] **Ledger report:** Customer/supplier ledger shows historical data
- [ ] **Trial balance:** GL trial balance ties out
- [ ] **Journal entry report:** Historical entries visible
- [ ] **Materialized views:** Report pages load with data (not empty)
- [ ] **Branch demand:** Inter-branch flow works
- [ ] **Mobile responsive:** Test on mobile viewport

### Post-Go-Live Monitoring
- [ ] Monitor PHP slow logs for 1 week
- [ ] Monitor PostgreSQL slow queries (log_min_duration_statement = 500)
- [ ] Check Redis memory usage daily
- [ ] Monitor disk space (partitions grow fast)
- [ ] Set up log rotation for Laravel logs
- [ ] Set up database backup (pg_dump daily, keep 30 days)
- [ ] Set up application backup (code + uploads)

### Backup Script
```bash
#!/bin/bash
# /usr/local/bin/backup-rcerp.sh
BACKUP_DIR="/var/backups/rcerp"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Database backup
sudo -u postgres pg_dump -Fc rcerp > $BACKUP_DIR/rcerp_$DATE.dump

# Keep only last 30 days
find $BACKUP_DIR -name "*.dump" -mtime +30 -delete

echo "Backup completed: rcerp_$DATE.dump"
```

```bash
# Add to crontab (daily at 2 AM)
0 2 * * * /usr/local/bin/backup-rcerp.sh >> /var/log/rcerp-backup.log 2>&1
```

---

## Summary of Immediate Actions

**Run on VPS right now (in order):**

1. `sudo apt install -y php8.3-opcache` → configure OPcache → restart PHP-FPM
2. Check Redis connection → fix .env if needed → restart PHP-FPM
3. Add Nginx gzip config → reload Nginx
4. Tune PostgreSQL (shared_buffers=1GB) → restart PostgreSQL
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
6. Refresh materialized views
7. Fix migration `2026_07_30_000005` (skip if no legacy file)
8. Fix remaining 10 broken migrations from the list
9. Run `php artisan migrate` to completion
10. Begin data migration (legacy MySQL → PostgreSQL)
11. Complete go-live checklist above
