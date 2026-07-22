# RC_ERP_v2

Migration of the **Remote Center ERP** system from a custom PHP/MySQL codebase to **Laravel 11 + PostgreSQL 16**, deployed on a BDIX VPS.

> **Status:** Phases 0–12 COMPLETE. Phase 13 (AI Sidecar) pending. Ready for VPS deployment.

---

## Migration Progress

| Phase | Name | Status | Commit |
|---|---|---|---|
| 0 | Pre-Migration Security Cleanup | ✅ Complete | `8f0b7ce` |
| 1 | VPS BDIX Provisioning | ⬜ Pending (manual — needs VPS) | — |
| 2 | Database Migration to PostgreSQL | ✅ Complete | `a76b194` |
| 3 | Laravel Foundation + Auth | ✅ Complete | `92a5024` |
| 4 | Master Data Modules | ✅ Complete | `845efee` |
| 5 | Reporting Layer | ✅ Complete | `787d909` |
| 6 | Inventory Module (6.1–6.6) | ✅ Complete | `4aa7ecf` |
| 7 | Purchase Module (7.1–7.3) | ✅ Complete | `91d54ca` |
| 8 | Sales Module (8.1–8.5) | ✅ Complete | `739ed4b` |
| 9 | Accounting Engine (9.1–9.6) | ✅ Complete | `76240de` |
| 10 | Notifications (Laravel native) | ✅ Complete | `c9631ff` |
| 11 | Compliance & Investigation Framework | ✅ Complete | `e4ed955` |
| 12 | Enterprise Cutover & Archive | ✅ Complete | `204aa72` |
| 13 | AI Sidecar | ⬜ Pending | — |

### What's done (code complete — ready for VPS deployment)

- **137 PHP files + 123 Blade views** in the Laravel app
- **Full ERP**: master data, inventory, purchase, sales, accounting, reports, reconciliation, notifications, compliance, archive
- **PostgreSQL schema** (66 tables + 7 materialized views + triggers)
- **Legacy PHP** made PostgreSQL-compatible (all MySQL-isms fixed)
- **ETL scripts** (pgloader config + post-load fixes + sequence sync + verification)
- **Anti-Corruption Layer** for legacy MySQL archive (read-only historical search)
- **Enterprise policy engine** (investigation mode as centralized framework)
- **Laravel native notifications** (replaces Telegram + Firebase)

### What's still needed

1. **Phase 1 — VPS BDIX Provisioning** (manual): provision Ubuntu 22.04 VPS, install PHP 8.3 + PostgreSQL 16 + Redis + Nginx
2. **Phase 13 — AI Sidecar** (Python FastAPI): report chatbot, demand forecasting, invoice OCR, anomaly detection
3. **Manual security actions** (see below)
4. **Data migration on VPS**: run `php artisan migrate` + `php artisan chart:seed` + `php artisan migrate:master-data`
5. **Verification on VPS**: `php artisan chart:validate` + `php artisan stock:replay-verify` + `php artisan journal:replay-verify`
6. **Legacy MySQL set to READ-ONLY** (revoke write privileges)
7. **Nginx configuration** (see `docs/migration/nginx.conf.example`)

---

## Repository structure

```
RC_ERP_v2/
├── legacy/              # Phase 0-patched + PG-compatible legacy PHP (runs during transition)
├── laravel/             # Laravel 11 app (full ERP — 137 PHP + 123 Blade views)
│   ├── app/
│   │   ├── Archive/         # Phase 12: Anti-Corruption Layer (DTOs + Repository + Service)
│   │   ├── Console/Commands/# Verification + migration commands
│   │   ├── Events/          # SystemPolicyChanged
│   │   ├── Http/Controllers/Admin/  # 20+ controllers
│   │   ├── Http/Middleware/ # Session bridge, credential check, system policy
│   │   ├── Models/          # 40+ Eloquent models
│   │   ├── Notifications/   # ERPNotification (Laravel native)
│   │   ├── Policies/        # SystemPolicyPolicy (Gate)
│   │   ├── Services/        # Accounting, Stock, Sales, Purchase, Notification, Compliance
│   │   ├── Traits/          # AuditableMasterData, ApplySystemPolicyScope
│   │   └── ...
│   ├── database/
│   │   ├── migrations/      # PG schema + auth tables + CoA seed + notification tables + system policies
│   │   ├── sql/             # 7 raw PG DDL files (66 tables + 7 MVs + triggers)
│   │   └── etl/             # pgloader config + post-load fixes + sequence sync + verify
│   └── ...
├── docs/migration/       # Phase reports + accounting rules + schema mapping + nginx config
├── MIGRATION_PLAN.md     # the master 13-phase plan
└── .gitignore
```

---

## Four non-negotiable principles

1. **Database conversion** (MySQL → PostgreSQL) — Phase 2 ✅
2. **Application conversion** (custom PHP MVC → Laravel 11) — Phases 3–9 ✅
3. **Keep the existing UI** — Blade views reproduce legacy markup; no SPA rewrite ✅
4. **Re-derive business logic, don't copy-paste** — stock costing, journal posting, reconciliation re-derived from first principles ✅

---

## Removed features (per project decision)

- TOTP 2FA on login (Google Authenticator) — removed
- `PendingLogin` intermediate 2FA state — removed
- Telegram login notifications — removed
- `verify_2fa` view and route — removed
- `users.totp_secret`, `users.totp_enabled` columns — dropped
- Telegram business alerts — **removed** (2026-07-22). Laravel native notifications (`ERPNotification` + `NotificationService`) cover operational visibility. See `docs/sales_entry_Lg_vs_La.md` R24.
- Firebase FCM push — **removed** (2026-07-22). `fcm_tokens` table + `users.telegram_user_id` column dropped in migration `2025_01_20_000010_drop_fcm_and_telegram_fields.php`. In-app inbox + Listen/Notify realtime fanout covers the use case. See `docs/sales_entry_Lg_vs_La.md` R25.

---

## Manual action still required (cannot be done in code)

- [x] ~~Rotate Telegram bot token~~ — N/A, Telegram integration removed entirely (2026-07-22)
- [x] ~~Rotate FCM server key + VAPID key pair~~ — N/A, FCM integration removed entirely (2026-07-22)
- [ ] Reset all production user passwords (bcrypt hashes were in the public SQL dump)
- [ ] Delete or make-private the old public repo `sajidchowdhury/RC_ERP`
- [ ] Delete or make-private the public repo `sajidchowdhury/RC_ERP_Laravel`
- [ ] Provision BDIX VPS (Phase 1)
- [ ] Set production `.env` with new credentials (chmod 600, never committed)

---

## How to Run on Localhost (Docker — Recommended)

Since you have PHP 8.2.12 (XAMPP) but the project requires PHP 8.3+, **Docker is the recommended approach**. This avoids upgrading your XAMPP.

### Prerequisites
- Docker Desktop (installed ✅)
- Git

### Step 1: Clone the repo

```bash
git clone https://github.com/sajidchowdhury/RC_ERP_v2.git
cd RC_ERP_v2
```

### Step 2: Create a `docker-compose.yml` in the project root

Create `/home/z/RC_ERP_v2/docker-compose.yml` (or wherever you cloned):

```yaml
version: '3.8'

services:
  # PostgreSQL 16
  postgres:
    image: postgres:16-alpine
    container_name: rcerp_postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB: rcerp
      POSTGRES_USER: rcerp_app
      POSTGRES_PASSWORD: rcerp_password
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - rcerp_net

  # Redis 7
  redis:
    image: redis:7-alpine
    container_name: rcerp_redis
    restart: unless-stopped
    ports:
      - "6379:6379"
    networks:
      - rcerp_net

  # Laravel app (PHP 8.3 + Nginx)
  app:
    image: php:8.3-fpm-alpine
    container_name: rcerp_app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./laravel:/var/www/html
    networks:
      - rcerp_net
    depends_on:
      - postgres
      - redis
    command: sh -c "docker-php-ext-install pdo pdo_pgsql bcmath gd && php-fpm"

  # Nginx
  nginx:
    image: nginx:alpine
    container_name: rcerp_nginx
    restart: unless-stopped
    ports:
      - "8080:80"
    volumes:
      - ./laravel:/var/www/html
      - ./docker/nginx.conf:/etc/nginx/conf.d/default.conf
    networks:
      - rcerp_net
    depends_on:
      - app

  # Legacy MySQL (for archive — read-only)
  mysql_archive:
    image: mysql:8.0
    container_name: rcerp_mysql_archive
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: archive_root_pass
      MYSQL_DATABASE: osudlagb_remotecenter
      MYSQL_USER: readonly_user
      MYSQL_PASSWORD: readonly_pass
    ports:
      - "3306:3306"
    volumes:
      - mysqldata:/var/lib/mysql
    networks:
      - rcerp_net

volumes:
  pgdata:
  mysqldata:

networks:
  rcerp_net:
    driver: bridge
```

### Step 3: Create Nginx config

Create `/home/z/RC_ERP_v2/docker/nginx.conf`:

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/html/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(git|env) {
        deny all;
    }
}
```

### Step 4: Start Docker containers

```bash
docker-compose up -d
```

Wait 30 seconds for PostgreSQL to initialize.

### Step 5: Install Composer inside the container

```bash
# Enter the app container
docker exec -it rcerp_app sh

# Inside the container:
cd /var/www/html
curl -sS https://getcomposer.org/installer | php
php composer.phar install --ignore-platform-reqs

# Generate app key
php artisan key:generate

# Configure .env
cp .env.example .env
# Edit .env (see Step 6)
```

### Step 6: Configure `.env`

Edit `/home/z/RC_ERP_v2/laravel/.env`:

```env
APP_NAME="Remote Center ERP"
APP_ENV=local
APP_KEY=  # (auto-generated by key:generate)
APP_DEBUG=true
APP_TIMEZONE=Asia/Dhaka
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=rcerp
DB_USERNAME=rcerp_app
DB_PASSWORD=rcerp_password

REDIS_HOST=redis
REDIS_PORT=6379

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# Archive (legacy MySQL) — read-only
ARCHIVE_ENABLED=false  # Set to true when you import legacy data
ARCHIVE_DB_HOST=mysql_archive
ARCHIVE_DB_PORT=3306
ARCHIVE_DB_DATABASE=osudlagb_remotecenter
ARCHIVE_DB_USERNAME=readonly_user
ARCHIVE_DB_PASSWORD=readonly_pass
```

### Step 7: Run migrations + seed

```bash
# Still inside the container:
php artisan migrate
php artisan chart:seed

# Verify
php artisan chart:validate
```

### Step 8: Open in browser

```
http://localhost:8080
```

You should see the login page. No users exist yet — create one via tinker:

```bash
php artisan tinker

# Inside tinker:
# First create a branch
DB::table('branches')->insert([
    'branch_code' => 'HO',
    'branch_name' => 'Head Office',
    'is_active' => true,
]);

# Create an employee
$empId = DB::table('employees')->insertGetId([
    'employee_code' => 'EMP-0001',
    'name' => 'Admin User',
    'role' => 'superadmin',
    'branch_id' => 1,
    'is_active' => true,
]);

# Create a user
DB::table('users')->insert([
    'employee_id' => $empId,
    'username' => 'admin',
    'password_hash' => bcrypt('password123'),
    'is_active' => true,
    'credential_version' => 1,
]);

exit;
```

Login with: `admin` / `password123`

---

## Alternative: Run WITHOUT Docker (XAMPP + PostgreSQL)

If you prefer to use your existing XAMPP (PHP 8.2.12) instead of Docker:

> ⚠️ **Warning:** The project targets PHP 8.3. PHP 8.2 *may* work but is not officially supported. Some Laravel 11 features may have issues.

### Step 1: Install PostgreSQL locally

Download PostgreSQL 16 for Windows from https://www.postgresql.org/download/windows/

Install with:
- Port: 5432
- Superuser password: `postgres`
- Create database: `rcerp`
- Create user: `rcerp_app` with password `rcerp_password`

### Step 2: Install Redis for Windows

```bash
# Using WSL or Memurai (Windows Redis alternative)
# Or skip Redis and use file/database cache instead
```

### Step 3: Install Composer

```bash
# Download from https://getcomposer.org/download/
# Install globally
```

### Step 4: Setup the Laravel project

```bash
cd C:\xampp\htdocs
git clone https://github.com/sajidchowdhury/RC_ERP_v2.git
cd RC_ERP_v2\laravel

# Install dependencies
composer install --ignore-platform-reqs

# Generate key
php artisan key:generate

# Configure .env (same as Docker Step 6, but DB_HOST=127.0.0.1)
```

Edit `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rcerp
DB_USERNAME=rcerp_app
DB_PASSWORD=rcerp_password

# If no Redis:
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

### Step 5: Run migrations

```bash
php artisan migrate
php artisan chart:seed
php artisan chart:validate
```

### Step 6: Access via XAMPP

Point your browser to:
```
http://localhost/RC_ERP_v2/laravel/public/
```

Or create a virtual host in XAMPP's `httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/RC_ERP_v2/laravel/public"
    ServerName rcerp.test
</VirtualHost>
```

Add to `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1 rcerp.test
```

Access: `http://rcerp.test`

---

## Verification commands (run after setup)

```bash
# Chart of Accounts validation
php artisan chart:validate

# Stock replay verification (Phase 6.2)
php artisan stock:replay-verify

# Journal replay verification (Phase 9.2)
php artisan journal:replay-verify

# Sub-ledger reconciliation (Phase 9.3)
php artisan subledger:reconcile

# Reversal verification (Phase 9.4)
php artisan reversal:verify

# Manual stock verification (Phase 6.2 — for accountant)
php artisan stock:manual-verify

# Manual journal verification (Phase 9.2 — for accountant)
php artisan journal:manual-verify
```

---

## Key documentation

| Document | Description |
|---|---|
| `MIGRATION_PLAN.md` | The full 13-phase migration plan |
| `docs/migration/avg_cost_rule.md` | Moving-average cost first-principles document |
| `docs/migration/journal_posting_rules.md` | GL posting rules (all ~40 methods) |
| `docs/migration/schema_mapping.md` | 66-table MySQL → PostgreSQL mapping |
| `docs/migration/phase0_complete.md` | Phase 0 security cleanup report |
| `docs/migration/phase12_complete.md` | Phase 12 archive architecture report |
| `docs/migration/nginx.conf.example` | Nginx config for VPS deployment |

---

## License

Proprietary — Remote Center ERP.
