# RC_ERP_v2 — Docker Development Environment

> **Quick start:** `cp .env.docker .env && docker-compose up -d`
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
              └──┬──────┬────┬──┘
                 │      │    │
    ┌────────────▼┐  ┌──▼──┐ │
    │rcerp_postgres│ │rcerp│ │
    │ PostgreSQL 16│ │redis│ │
    │  (port 5432) │ │  7  │ │
    └──────────────┘ └─────┘ │
                       ┌─────▼──────────┐
                       │rcerp_mysql_archive│
                       │ MySQL 8 (read-only)│
                       │   (port 3306)     │
                       └──────────────────┘
```

## Containers

| Container | Image | Port | Purpose |
|-----------|-------|------|---------|
| `rcerp_app` | PHP 8.4 FPM (custom) | 9000 (internal) | Laravel application |
| `rcerp_nginx` | nginx:1.25-alpine | **8080** → 80 | Reverse proxy + static files |
| `rcerp_postgres` | postgres:16-alpine | 5432 | Primary database (66+ tables) |
| `rcerp_redis` | redis:7-alpine | 6379 | Sessions, cache, queue |
| `rcerp_mysql_archive` | mysql:8.0 | 3306 | Read-only legacy archive |

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/sajidchowdhury/RC_ERP_v2.git
cd RC_ERP_v2

# 2. Copy environment file
cp .env.docker .env

# 3. Build + start all containers
docker-compose up -d --build

# 4. Wait for setup to complete (check logs)
docker-compose logs -f app
# Look for: "Docker Setup Complete!"

# 5. Open in browser
open http://localhost:8080

# Login: admin / password123
```

## Common Commands

```bash
# Start all containers
docker-compose up -d

# Stop all containers
docker-compose down

# Rebuild the app container (after Dockerfile changes)
docker-compose up -d --build app

# View logs
docker-compose logs -f app       # App (PHP-FPM)
docker-compose logs -f nginx     # Nginx
docker-compose logs -f postgres  # PostgreSQL

# Shell into app container
docker-compose exec app bash

# Run Artisan commands
docker-compose exec app php artisan migrate
docker-compose exec app php artisan tinker
docker-compose exec app php artisan test

# Run tests
docker-compose exec app vendor/bin/phpunit

# Connect to PostgreSQL
docker-compose exec postgres psql -U rcerp_app -d rcerp

# Connect to Redis
docker-compose exec redis redis-cli

# Connect to MySQL Archive
docker-compose exec mysql_archive mysql -u archive_reader -p rcerp_legacy
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
└── legacy/                     # Legacy PHP app (volume-mounted)
```

## Environment Variables

The `.env.docker` file controls database credentials:

```env
# PostgreSQL
POSTGRES_DB=rcerp
POSTGRES_USER=rcerp_app
POSTGRES_PASSWORD=rcerp_secret

# MySQL Archive
MYSQL_ROOT_PASSWORD=archive_root_secret
MYSQL_DATABASE=rcerp_legacy
MYSQL_USER=archive_reader
MYSQL_PASSWORD=archive_reader_secret
```

## What Happens on First Start

The `docker/entrypoint.sh` script runs automatically:

1. **Fixes storage permissions** — `chmod 775 storage bootstrap/cache`
2. **Installs Composer dependencies** — `composer install` (if vendor/ is empty)
3. **Waits for PostgreSQL** — polls until the DB is accepting connections
4. **Loads SQL schema** — runs `database/sql/01-07_*.sql` files (66 tables)
5. **Runs migrations** — `php artisan migrate --force` (adds missing columns)
6. **Creates admin user** — username: `admin`, password: `password123`
7. **Clears caches** — config, cache, view, route
8. **Starts PHP-FPM** — the main process

## Database Access

### pgAdmin

1. Open pgAdmin
2. Add server:
   - Host: `localhost`
   - Port: `5432`
   - Username: `rcerp_app`
   - Password: `rcerp_secret`
   - Database: `rcerp`

### Command Line

```bash
docker-compose exec postgres psql -U rcerp_app -d rcerp
```

## Troubleshooting

### 500 Server Error

```bash
# Check the Laravel log
docker-compose exec app tail -50 storage/logs/laravel.log

# Run migrations manually
docker-compose exec app php artisan migrate --force
```

### Page Loading Too Slow

```bash
# Ensure Redis is running
docker-compose exec redis ping

# Check OPcache is enabled
docker-compose exec app php -i | grep opcache.enable
```

### Can't See Tables in Database

```bash
# The schema didn't load. Run it manually:
docker-compose exec app bash -c '
  for f in database/sql/*.sql; do
    PGPASSWORD=rcerp_secret psql -h rcerp_postgres -U rcerp_app -d rcerp -f "$f"
  done
'
```

### Reset Everything

```bash
# Stop + remove containers + volumes (DESTROYS ALL DATA)
docker-compose down -v

# Start fresh
docker-compose up -d --build
```
