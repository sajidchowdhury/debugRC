# RC_ERP_v2 — Docker Development Environment

> **Quick start:** `cp .env.docker .env && docker compose up -d`
> **Access:** http://localhost:8080 · **Login:** admin / password123

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Host Machine                          │
│                   localhost:8080                         │
└──────────────────────┬──────────────────────────────────┘
                       │
              ┌────────▼────────┐
              │  rcerp_nginx    │  Nginx 1.25 (port 80→8080)
              │  Reverse Proxy  │
              └────────┬────────┘
                       │ fastcgi_pass:9000
              ┌────────▼────────┐
              │   rcerp_app     │  PHP 8.4 FPM + Laravel 12
              │   Application   │  (entrypoint: migrations + seed)
              └──┬──────┬───────┘
                 │      │
    ┌────────────▼┐  ┌──▼──────┐
    │rcerp_postgres│ │rcerp_redis│
    │ PostgreSQL 16│ │  Redis 7 │
    │  (port 5432) │ │(port 6379)│
    └──────────────┘ └──────────┘

  OPTIONAL (does not start by default):

         ┌─────────────────────┐
         │ rcerp_mysql_archive │  MySQL 8 (read-only legacy)
         │   (port 3307→3306)  │  Start with: --profile archive
         └─────────────────────┘
```

## Containers

| Container | Image | Port | Required | Purpose |
|-----------|-------|------|:--------:|---------|
| `rcerp_app` | PHP 8.4 FPM (custom) | 9000 (internal) | ✅ | Laravel application |
| `rcerp_nginx` | nginx:1.25-alpine | **8080** → 80 | ✅ | Reverse proxy + static files |
| `rcerp_postgres` | postgres:16-alpine | 5432 | ✅ | Primary database (66+ tables) |
| `rcerp_redis` | redis:7-alpine | 6379 | ✅ | Sessions, cache, queue |
| `rcerp_mysql_archive` | mysql:8.0 | **3307** → 3306 | ❌ Optional | Read-only legacy archive |

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/sajidchowdhury/debugRC.git
cd debugRC

# 2. Copy environment file
cp .env.docker .env

# 3. Build + start core stack (PostgreSQL + Redis + Laravel + Nginx)
docker compose up -d --build

# 4. Wait for setup to complete (check logs)
docker compose logs -f app
# Look for: "Docker Setup Complete!"

# 5. Open in browser
open http://localhost:8080

# Login: admin / password123
```

## Starting the MySQL Archive (Optional)

The MySQL archive container is **not required** for the application to run.
It only provides read-only access to legacy data for the Anti-Corruption Layer.

```bash
# Start MySQL archive in addition to the core stack
docker compose --profile archive up -d rcerp_mysql_archive

# Or start everything (core + archive)
docker compose --profile archive up -d

# Stop only the archive
docker compose --profile archive stop rcerp_mysql_archive
```

**Why port 3307?** The MySQL archive maps host port 3307 → container port 3306.
This avoids conflicts with XAMPP, WAMP, or a local MySQL installation that
typically uses port 3306. Internal Docker networking is unaffected — the
Laravel container connects to `rcerp_mysql_archive:3306` via the bridge network.

## Common Commands

```bash
# Start core stack (4 containers)
docker compose up -d

# Start core + MySQL archive (5 containers)
docker compose --profile archive up -d

# Stop all containers
docker compose down

# Stop + remove volumes (DESTROYS ALL DATA)
docker compose down -v

# Rebuild the app container (after Dockerfile changes)
docker compose up -d --build app

# View logs
docker compose logs -f app       # App (PHP-FPM)
docker compose logs -f nginx     # Nginx
docker compose logs -f postgres  # PostgreSQL

# Shell into app container
docker compose exec app bash

# Run Artisan commands
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app php artisan test

# Run tests
docker compose exec app vendor/bin/phpunit

# Connect to PostgreSQL
docker compose exec postgres psql -U rcerp_app -d rcerp

# Connect to Redis
docker compose exec redis redis-cli

# Connect to MySQL Archive (when running)
docker compose --profile archive exec mysql_archive mysql -u archive_reader -p rcerp_legacy
```

## File Structure

```
RC_ERP_v2/
├── docker-compose.yml          # Container orchestration
├── Dockerfile                  # PHP-FPM app container build
├── .env.docker                 # Docker environment variables
├── .dockerignore               # Build context exclusions
├── docker/
│   ├── entrypoint.sh           # App container startup script
│   ├── php/
│   │   └── php.ini            # Custom PHP config (Redis sessions, OPcache)
│   └── nginx/
│       └── default.conf        # Nginx server config
├── postgres/
│   └── init/
│       └── 01-init-database.sql # PostgreSQL init (extensions + test DB)
├── mysql_archive/
│   └── init/
│       └── 01-init-archive.sql  # MySQL archive init (sample legacy data)
├── laravel/                    # Laravel application (volume-mounted)
│   ├── public/
│   │   └── build/              # Vite-compiled frontend assets
│   └── ...
└── legacy/                     # Legacy PHP app (volume-mounted)
```

## What Happens on First Start

The `docker/entrypoint.sh` script runs automatically:

1. **Fixes storage permissions** — `chmod 775 storage bootstrap/cache`
2. **Installs Composer dependencies** — `composer install` (if vendor/ is empty)
3. **Waits for PostgreSQL** — polls until the DB is accepting connections (max 30 retries)
4. **Loads SQL schema** — runs `database/sql/01-07_*.sql` files (66 tables)
5. **Runs migrations** — `php artisan migrate --force` (adds missing columns)
6. **Creates admin user** — username: `admin`, password: `password123`
7. **Clears caches** — config, cache, view, route
8. **Starts PHP-FPM** — the main process

## Troubleshooting

### 500 Server Error

```bash
# Check the Laravel log
docker compose exec app tail -50 storage/logs/laravel.log

# Run migrations manually
docker compose exec app php artisan migrate --force
```

### Page Loading Too Slow

```bash
# Ensure Redis is running
docker compose exec redis ping

# Check OPcache is enabled
docker compose exec app php -i | grep opcache.enable
```

### Can't See Tables in Database

```bash
# The schema didn't load. Run it manually:
docker compose exec app bash -c '
  for f in database/sql/*.sql; do
    PGPASSWORD=rcerp_secret psql -h rcerp_postgres -U rcerp_app -d rcerp -f "$f"
  done
'
```

### Port Conflict (3306 already in use)

If you see `bind: address already in use` for port 3306, it means XAMPP or
another MySQL is running on your host. The MySQL archive already maps to
**port 3307** to avoid this. If 3307 is also in use, change it in
`docker-compose.yml`:

```yaml
rcerp_mysql_archive:
    ports:
      - "3308:3306"   # Change 3308 to any free port
```

### Reset Everything

```bash
# Stop + remove containers + volumes (DESTROYS ALL DATA)
docker compose down -v

# Start fresh
docker compose up -d --build
```
