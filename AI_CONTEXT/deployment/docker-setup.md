# Docker Setup

> **Module:** Deployment (Local Docker development stack)
> **Audience:** Engineers, DevOps, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `docker-compose.yml`, `Dockerfile`,
> `docker/entrypoint.sh`, `docker/nginx/default.conf`, `docker/php/php.ini`,
> `.dockerignore`, `.env.docker`, and `../architecture/module-map.md`.

---

## 1. What is it?

RC_ERP_v2 ships with a complete **Docker Compose** stack for local development. It builds a
custom PHP 8.4-FPM image (with all required extensions + Node.js + Composer), runs
PostgreSQL 16 + Redis 7 as Alpine sidecars, puts Nginx in front, and offers an opt-in MySQL
8 legacy archive container. A single `docker compose up -d --build` command brings up the
entire ERP at `http://localhost:8080`, with a seeded admin user (`admin / password123`)
ready to log in.

The stack is the **canonical local development environment** — every engineer on the
project is expected to use it. Native PHP/PostgreSQL/Redis installs are not supported
(though they work; the docs assume Docker). The same image is *not* used for VPS
production — see `vps-bdix-deployment.md` for the bare-metal Ubuntu deployment path.

This file documents the **5-container topology**, the **9-step entrypoint bootstrap
sequence**, the **bind-mount strategy** (and its Windows-NTFS workaround), the **named
volume strategy** (and the `node_modules/.package-hash` cache-bust fix), the **profile
system** for the optional MySQL archive, and the **operational commands** for logs, shell
access, rebuilds, and teardown.

---

## 2. Why does it exist?

- **Reproducibility.** Every engineer gets the same PostgreSQL 16, Redis 7, PHP 8.4, Nginx
  1.25 versions. No "works on my machine" drift from native installs.
- **Onboarding speed.** A new contributor runs `docker compose up -d --build` and is logged
  into the ERP in ~3 minutes. No `apt install php8.4-pgsql`, no `pecl install redis`, no
  `createuser rcerp_app`.
- **Environment isolation.** Multiple projects on the same machine don't conflict — RC_ERP
  uses ports 8080 (Nginx), 5432 (PG), 6379 (Redis), 3307 (MySQL archive). The MySQL archive
  uses port 3307 (not 3306) to avoid clashing with host XAMPP/MySQL installs.
- **CI parity.** The same Docker image can be used in CI to run the test suite against a
  real PostgreSQL (not SQLite), catching PG-specific bugs before merge.
- **Safe teardown.** `docker compose down -v` wipes the database — useful for testing
  migrations from scratch without polluting a native install.
- **Profile-gated optional services.** The MySQL archive is opt-in (`--profile archive`) so
  the default stack starts fast and doesn't require a 500MB MySQL image pull.

---

## 3. When is it used?

- **Local development** — primary use case. Engineers run it for day-to-day coding.
- **QA / smoke testing** — before deploying to staging/VPS, run the full test suite in
  Docker to confirm no PG-version-specific regressions.
- **Onboarding demos** — show new hires the ERP running, with seed data, in minutes.
- **Migration dry-runs** — `docker compose down -v && docker compose up -d --build` runs
  migrations from scratch, useful for testing a new migration in isolation.
- **NOT for production.** The Docker stack uses bind mounts (not code copies), `APP_DEBUG=true`
  in the entrypoint template, and no TLS. VPS production uses bare-metal PHP-FPM — see
  `vps-bdix-deployment.md`.

---

## 4. Who uses it?

- **Engineers** — primary audience. Day-to-day development.
- **QA engineers** — run the test suite in a clean container.
- **DevOps** — debug container issues, profile resource usage.
- **New contributors** — first-run experience.
- **AI assistants** — MUST use this file as the reference for any Docker command. Never
  suggest `docker run` (always `docker compose`).

---

## 5. Related modules

- `environment.md` — every env var consumed by the containers.
- `nginx-config.md` — the `docker/nginx/default.conf` file mounted into `rcerp_nginx`.
- `artisan-commands.md` — many commands are run via `docker compose exec rcerp_app php artisan ...`.
- `cron-scheduled-jobs.md` — the queue worker and listen-notify worker run as separate
  containers (`rcerp_queue_worker`, `rcerp_listen_notify`).
- `../architecture/high-level-architecture.md` — the container topology fits into the
  larger architecture diagram.
- `../archive/legacy-read-only.md` — the MySQL archive container is opt-in via the
  `archive` profile.

---

## 6. Business rules

- **R-1 — Use `docker compose`, never `docker run`.** The stack is multi-container with
  bind mounts, named volumes, and a custom network. `docker run` bypasses all of this and
  produces a broken, isolated container.
- **R-2 — The default stack has 4 containers; the MySQL archive is opt-in.** `docker
  compose up -d` starts `rcerp_postgres`, `rcerp_redis`, `rcerp_app`, `rcerp_nginx`. The
  `rcerp_queue_worker` and `rcerp_listen_notify` are also part of the default stack. The
  `rcerp_mysql_archive` requires `--profile archive`.
- **R-3 — The Laravel app container does NOT depend on the MySQL archive.**
  `rcerp_app.depends_on` lists only `rcerp_postgres` and `rcerp_redis`. The
  `ArchiveService` catches MySQL connection errors gracefully — see
  `../archive/legacy-read-only.md` §7.5.
- **R-4 — Bind mounts are for source code; named volumes are for dependencies.** The
  `laravel/` directory is bind-mounted (so edits on the host reflect instantly in the
  container). `vendor/` and `node_modules/` are named volumes (so they persist across
  container rebuilds and aren't overwritten by host OS package versions).
- **R-5 — The entrypoint auto-runs migrations on every start.** `docker/entrypoint.sh`
  Step 6 runs `php artisan migrate:fresh --force` on a fresh DB or `php artisan migrate
  --force` on an existing one. This is intentional for dev; in production (VPS) migrations
  are run manually.
- **R-6 — The entrypoint creates the admin user only if it doesn't exist.** Step 8 checks
  for `users.username = 'admin'` and creates it with `password123` if missing. Existing
  admin passwords are NOT reset on container restart.
- **R-7 — Port 3307 is the host port for the MySQL archive, NOT 3306.** This avoids
  conflicts with XAMPP/MySQL on the host. The container-internal port is still 3306.
- **R-8 — The `node_modules/.package-hash` cache-bust fix is REQUIRED.** Without it,
  adding a new package to `package.json` and restarting the container leaves the volume
  stale (the new package is missing). See §7.5 for the verbatim logic.
- **R-9 — The Windows-NTFS bind-mount UID fix is REQUIRED.** On Windows + Docker Desktop,
  bind mounts are owned by root and `chmod`/`chown` silently fail. The entrypoint detects
  the mount owner UID and reconfigures PHP-FPM to run as that UID. See §7.3.
- **R-10 — The Docker stack is NOT for production.** It uses `APP_DEBUG=true`, no TLS, no
  password on Redis, and bind mounts (which break on production file permissions). For
  production, see `vps-bdix-deployment.md`.

---

## 7. Container topology

### 7.1 The 5-container diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                       Host Machine                              │
│                                                                 │
│   localhost:8080  ────────────►  rcerp_nginx (Nginx 1.25)       │
│   localhost:5432  ────────────►  rcerp_postgres (PG 16)         │
│   localhost:6379  ────────────►  rcerp_redis (Redis 7)          │
│   localhost:3307  ─── opt-in ──►  rcerp_mysql_archive (MySQL 8) │
│                                                                 │
│   Internal only:                                                │
│   rcerp_app (PHP 8.4 FPM)  ◄──►  rcerp_nginx (fastcgi :9000)   │
│   rcerp_queue_worker        ──►  rcerp_redis (queue)            │
│   rcerp_listen_notify       ──►  rcerp_postgres (LISTEN)        │
│                                                                 │
│   Network: rcerp_network (bridge)                               │
│   Volumes: postgres_data, redis_data, mysql_archive_data,       │
│            app_vendor, app_node_modules                         │
└─────────────────────────────────────────────────────────────────┘
```

### 7.2 Container inventory

| Container | Image | Host port | Internal port | Required | Purpose |
|---|---|---|---|:---:|---|
| `rcerp_postgres` | `postgres:16-alpine` | 5432 | 5432 | ✅ | Primary DB (66 tables + 7 MVs) |
| `rcerp_redis` | `redis:7-alpine` | 6379 | 6379 | ✅ | Sessions, cache, queue, pub/sub |
| `rcerp_app` | custom (built from `Dockerfile`) | — | 9000 | ✅ | PHP 8.4 FPM + Laravel 12 |
| `rcerp_queue_worker` | same custom image | — | — | ✅ | Redis queue worker (notifications, CSV exports) |
| `rcerp_listen_notify` | same custom image | — | — | ✅ | PG LISTEN/NOTIFY → Redis pub/sub bridge |
| `rcerp_nginx` | `nginx:1.25-alpine` | 8080 | 80 | ✅ | Reverse proxy + static file server |
| `rcerp_mysql_archive` | `mysql:8.0` | 3307 | 3306 | ❌ opt-in | Read-only legacy archive |

### 7.3 Volume inventory

| Volume | Type | Mount target | Purpose |
|---|---|---|---|
| `postgres_data` | named | `rcerp_postgres:/var/lib/postgresql/data` | PostgreSQL data directory |
| `redis_data` | named | `rcerp_redis:/data` | Redis AOF persistence |
| `mysql_archive_data` | named | `rcerp_mysql_archive:/var/lib/mysql` | MySQL data (legacy archive) |
| `app_vendor` | named | `rcerp_app:/var/www/laravel/vendor` | Composer dependencies (persists across rebuilds) |
| `app_node_modules` | named | `rcerp_app:/var/www/laravel/node_modules` | npm dependencies (persists across rebuilds) |
| `./laravel` | bind | `rcerp_app:/var/www/laravel:delegated` | Source code (host edits reflect instantly) |
| `./legacy` | bind | `rcerp_app:/var/www/legacy:delegated` | Legacy PHP source (reference only) |
| `./postgres/init` | bind (ro) | `rcerp_postgres:/docker-entrypoint-initdb.d` | PG init scripts (run once on first start) |
| `./laravel/database/sql` | bind (ro) | `rcerp_postgres:/sql-schema:ro` | Raw SQL schema (loaded by migrations) |
| `./docker/nginx/default.conf` | bind (ro) | `rcerp_nginx:/etc/nginx/conf.d/default.conf:ro` | Nginx config |
| `./laravel/public` | bind (ro) | `rcerp_nginx:/var/www/laravel/public:ro` | Static assets |
| `./mysql_archive/init` | bind (ro) | `rcerp_mysql_archive:/docker-entrypoint-initdb.d:ro` | MySQL init (GRANT statements) |

### 7.4 Network

Single bridge network `rcerp_network`. All containers attach to it. Container names are
DNS-resolvable within the network (e.g. `rcerp_app` resolves to the app container's IP).

### 7.5 The 9-step entrypoint bootstrap

`docker/entrypoint.sh` runs every time `rcerp_app` (or `rcerp_queue_worker`, or
`rcerp_listen_notify`) starts. The 9 steps:

1. **Fix storage permissions** — `mkdir -p storage/{logs,framework/{cache/data,sessions,views}} bootstrap/cache`.
   Detect the bind-mount owner UID and reconfigure PHP-FPM to run as that UID (the
   Windows-NTFS fix — see R-9).
2. **Create `.env` if missing** — writes a dev-template `.env` with `APP_DEBUG=true`,
   `DB_HOST=rcerp_postgres`, etc. (see `environment.md` §7 for the full template).
3. **Install Composer dependencies** — if `vendor/autoload.php` is missing, runs
   `composer install --no-interaction --optimize-autoloader` (falls back to `--no-dev`).
4. **Install npm dependencies + build Vite assets** — checks `node_modules/.package-hash`
   against the current `package.json` MD5; reinstalls if changed (the cache-bust fix — see
   R-8). Then runs `npm run build:css` (Tailwind) and `npm run build` (Vite).
5. **Wait for PostgreSQL** — polls `pg_isready` equivalent via PDO, 30 retries × 2s.
6. **Database setup** — if `migrations` table is missing, runs `migrate:fresh --force`;
   otherwise runs `migrate --force`.
7. **Ensure `system_policies` table + default NORMAL policy** — safety net in case
   migrations didn't create it (the `CheckSystemPolicy` middleware queries this table on
   every request).
8. **Create admin user** — if `users.username = 'admin'` doesn't exist, creates the HO
   branch + EMP-0001 employee + admin user with bcrypt(`password123`).
9. **Clear caches** — `config:clear`, `cache:clear`, `view:clear`, `route:clear`. Then
   `exec php-fpm` (the container's CMD).

> The `rcerp_queue_worker` overrides the CMD to `php artisan queue:work --sleep=3 --tries=3
> --max-time=3600`. The `rcerp_listen_notify` overrides it to `php artisan
> listen-notify:worker`. Both still run the entrypoint (which sets up `.env`, installs
   deps, etc.) before exec-ing their respective commands.

---

## 8. The Dockerfile

### 8.1 Base image

`php:8.4-fpm-bookworm` (Debian 12 Bookworm). Not Alpine — Alpine's `musl libc` causes
subtle compatibility issues with some PHP extensions (GD, intl).

### 8.2 System packages installed

| Package | Why |
|---|---|
| `git` | Composer needs it to clone package repos |
| `curl` | Healthchecks, downloading Node setup script |
| `libpng-dev`, `libjpeg62-turbo-dev`, `libwebp-dev`, `libfreetype6-dev` | GD extension |
| `libonig-dev` | mbstring |
| `libxml2-dev` | xml, simplexml, dom |
| `libzip-dev` | zip |
| `libpq-dev` | pdo_pgsql, pgsql |
| `unzip` | Composer prefers unzip over PHP's ZipArchive |
| `supervisor` | For future multi-process containers (currently unused) |
| `cron` | For future cron-in-container (currently host cron) |
| `postgresql-client` | `psql` for entrypoint schema loading |

### 8.3 PHP extensions

| Extension | How | Why |
|---|---|---|
| `pdo`, `pdo_pgsql`, `pgsql` | `docker-php-ext-install` | PostgreSQL |
| `mbstring` | `docker-php-ext-install` | Multi-byte strings |
| `exif` | `docker-php-ext-install` | Image metadata (uploads) |
| `pcntl` | `docker-php-ext-install` | `listen-notify:worker` signal handling |
| `bcmath` | `docker-php-ext-install` | Arbitrary-precision math (money) |
| `zip` | `docker-php-ext-install` | Composer, zip downloads |
| `opcache` | `docker-php-ext-install` | PHP opcode cache |
| `gd` | `docker-php-ext-configure --with-freetype --with-jpeg` then `install` | Image processing |
| `redis` | `pecl install redis` + `docker-php-ext-enable` | Redis sessions, predis alternative |
| `pdo_mysql`, `mysqli` | `docker-php-ext-install` | Legacy archive MySQL connection |

### 8.4 Node.js

Node.js 20.x (via deb.nodesource setup script) + npm. Used to build Vite assets
(`npm run build`) and Tailwind CSS (`npm run build:css`). Not used at runtime — the
container serves pre-built static assets from `public/build/`.

### 8.5 Composer

Pulled from the official `composer:2` image (`COPY --from=composer:2 /usr/bin/composer
/usr/bin/composer`). Not installed via `apt` or `php composer-setup.php` — the multi-stage
copy is faster and pins the version.

### 8.6 The CRLF-stripping defense

```dockerfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh
```

Git on Windows converts LF → CRLF on checkout (unless `.gitattributes` enforces LF). Linux
then tries to find `/bin/bash\r` and fails with the infamous "no such file or directory"
error. The `sed` strips `\r` as defense-in-depth, even though `.gitattributes` enforces
LF. The same fix is applied to `docker/php/php.ini`.

---

## 9. Operational commands

### 9.1 Daily workflow

```bash
# Start the stack (first run builds the image)
docker compose up -d --build

# Tail the app container logs (watch for "Docker Setup Complete!")
docker compose logs -f app

# Open a shell in the app container
docker compose exec rcerp_app bash

# Run an artisan command
docker compose exec rcerp_app php artisan migrate:status

# Run a Composer command
docker compose exec rcerp_app composer require package/name

# Run an npm command
docker compose exec rcerp_app npm install

# Stop the stack (keeps data volumes)
docker compose down

# Stop + wipe data volumes (DESTROYS the database)
docker compose down -v
```

### 9.2 The MySQL archive (opt-in)

```bash
# Start core + MySQL archive
docker compose --profile archive up -d

# Start ONLY the MySQL archive (core already running)
docker compose --profile archive up -d rcerp_mysql_archive

# Connect to the MySQL archive from the host
mysql -h 127.0.0.1 -P 3307 -u archive_reader -parchive_reader_secret rcerp_legacy

# Connect from inside the app container
docker compose exec rcerp_app php artisan tinker --execute="
  try { echo \Illuminate\Support\Facades\DB::connection('mysql_archive')->getPdo()->query('SELECT 1')->fetchColumn(); }
  catch (\Throwable \$e) { echo 'offline: ' . \$e->getMessage(); }
"
```

### 9.3 Rebuilding after `Dockerfile` or `docker-compose.yml` changes

```bash
# Rebuild the image (no cache)
docker compose build --no-cache rcerp_app

# Apply docker-compose.yml changes (e.g. new env var, new port)
docker compose up -d

# Full clean slate (warning: destroys data)
docker compose down -v
docker compose up -d --build
```

### 9.4 Inspecting state

```bash
# List containers + status
docker compose ps

# List volumes
docker volume ls | grep rcerp

# Inspect the app container's env vars
docker compose exec rcerp_app env | grep -E '^(APP_|DB_|REDIS_|ARCHIVE_)' | sort

# Inspect the PHP-FPM pool config (after entrypoint UID fix)
docker compose exec rcerp_app cat /usr/local/etc/php-fpm.d/www.conf

# Check PostgreSQL extensions
docker compose exec rcerp_postgres psql -U rcerp_app -d rcerp -c "SELECT extname FROM pg_extension;"
```

### 9.5 Logs

```bash
# All containers, last 100 lines
docker compose logs --tail 100

# Single container, follow
docker compose logs -f rcerp_app
docker compose logs -f rcerp_nginx
docker compose logs -f rcerp_postgres
docker compose logs -f rcerp_redis
docker compose logs -f rcerp_queue_worker
docker compose logs -f rcerp_listen_notify

# Laravel application log
docker compose exec rcerp_app tail -f storage/logs/laravel.log
```

---

## 10. Known edge cases

- **E-1 — `node_modules` goes stale after `package.json` change.** Without the
  `.package-hash` check (R-8), the named volume `app_node_modules` persists across
  container restarts and never picks up new packages. Symptom: "tailwindcss: not found"
  after adding tailwind to `package.json`. Fix: the entrypoint Step 4 reinstalls when the
  hash mismatches.
- **E-2 — Windows bind-mount UID mismatch.** On Windows + Docker Desktop, bind mounts are
  owned by root (UID 0) and `chmod`/`chown` silently fail. PHP-FPM (running as www-data,
  UID 33) cannot write to `storage/`. Symptom: "Failed to open stream: Permission denied"
  on `storage/logs/laravel.log`. Fix: entrypoint Step 1 detects the mount owner UID and
  reconfigures PHP-FPM to run as that UID (R-9).
- **E-3 — Port 3306 conflict with host MySQL.** If the host has XAMPP/MySQL running on
  3306, the MySQL archive container cannot bind to 3306. Fix: `docker-compose.yml` maps
  host port 3307 → container port 3306 (R-7). Internal container communication still uses
  3306.
- **E-4 — `migrate:fresh` wipes data on every fresh start.** The entrypoint Step 6 runs
  `migrate:fresh --force` if the `migrations` table is missing. This is intentional for
  dev (so a `docker compose down -v && up -d` gives a clean slate), but if the
  `postgres_data` volume is accidentally removed, all data is lost. Mitigation: never run
  `docker compose down -v` on a stack with real data; use a separate stack for prod-like
  data.
- **E-5 — The admin user is only created if `users.username = 'admin'` is missing.** If
  the admin user exists with a different password, the entrypoint does NOT reset it. To
  reset: `docker compose exec rcerp_app php artisan tinker --execute="echo
  \App\Models\User::where('username', 'admin')->update(['password_hash' =>
  password_hash('password123', PASSWORD_BCRYPT)]);"`
- **E-6 — The `rcerp_queue_worker` and `rcerp_listen_notify` containers also run the
  entrypoint.** This means they also try to run migrations (Step 6), create the admin user
  (Step 8), etc. This is harmless (migrations are idempotent; admin user check is
  guarded), but it adds ~5 seconds to their startup. A future optimization would skip
  steps 5–9 for non-app containers via an env-var gate.
- **E-7 — `composer install` falls back to `--no-dev` on failure.** If the dev
  dependencies fail to install (e.g. network issue), the entrypoint retries with
  `--no-dev`. This means dev-only packages (PHPUnit, Faker) may be missing. Symptom:
  `php artisan test` fails with "Class PHPUnit\Framework\TestCase not found". Fix: `docker
  compose exec rcerp_app composer install` manually.
- **E-8 — The MySQL archive init script grants `ALL PRIVILEGES` to `archive_reader`.**
  This contradicts the read-only intent (see `../archive/legacy-read-only.md` §12 E-1).
  The init script is additive (`GRANT SELECT` + `GRANT ALL`), so the user has write
  privileges. Mitigation: after first start, run `REVOKE ALL ON rcerp_legacy.* FROM
  archive_reader; GRANT SELECT ON rcerp_legacy.* TO archive_reader;`.
- **E-9 — `docker compose down` does NOT remove named volumes.** `postgres_data`,
  `redis_data`, etc. persist. To wipe: `docker compose down -v` (deletes volumes) or
  `docker volume rm rcerp_postgres_data` (specific volume).
- **E-10 — The `rcerp_nginx` container does NOT have a healthcheck.** `depends_on:
  rcerp_app: condition: service_started` (not `service_healthy`) because PHP-FPM has no
  built-in healthcheck. Nginx may start before PHP-FPM is ready, causing 502s on the first
  few requests. Mitigation: retry after 5 seconds, or add a healthcheck to the Dockerfile
  (future work).

---

## 11. Future improvements

- **F-1 — Add a healthcheck to the `rcerp_app` Dockerfile.** Currently Nginx starts before
  PHP-FPM is ready (E-10). A `HEALTHCHECK CMD php-fpm-healthcheck` (or a curl to a
  `/up` endpoint) would let `rcerp_nginx.depends_on` use `condition: service_healthy`.
- **F-2 — Split the entrypoint into `app-entrypoint.sh` and `worker-entrypoint.sh`.** The
  queue worker and listen-notify worker don't need to run migrations or create the admin
  user (E-6). A separate entrypoint would skip steps 5–9 for them, saving ~5s per start.
- **F-3 — Use a multi-stage build for smaller image.** Currently the image includes
  Node.js + npm (~200MB) which is only needed at build time. A multi-stage build would
  copy the built `public/build/` from a builder stage into a final stage without Node.
- **F-4 — Add a `docker compose --profile test` for CI.** Would start a clean stack, run
  `php artisan test`, and tear down. Currently CI uses native PHP + SQLite, which doesn't
  catch PG-specific bugs.
- **F-5 — Pin image versions in `docker-compose.yml`.** `postgres:16-alpine` is fine, but
  `nginx:1.25-alpine` should be `nginx:1.25.4-alpine` for reproducibility. Same for
  `redis:7-alpine` → `redis:7.2.4-alpine`.
- **F-6 — Add a `Makefile` or `justfile` for common commands.** Wraps `docker compose up
  -d --build`, `docker compose exec rcerp_app php artisan test`, etc. behind short
  aliases like `make up`, `make test`.
- **F-7 — Document the `legacy/` bind mount.** The `legacy/` directory is bind-mounted
  into `rcerp_app:/var/www/legacy:delegated` but never used at runtime (the legacy PHP
  app is not started in the default stack). It's there for the migration commands
  (`migrate:legacy-employees`, `migrate:master-data`) which read from the legacy MySQL
  archive, not the legacy PHP source. The bind mount could be removed.
- **F-8 — Add a `docker compose --profile prod` for production-like local testing.**
  Would use `APP_DEBUG=false`, a real `APP_KEY`, Redis password, etc. Useful for testing
  the production `.env` before deploying to VPS.
- **F-9 — Use Docker BuildKit cache mounts.** `RUN --mount=type=cache,target=/root/.composer
  composer install` would speed up image rebuilds by caching Composer's download cache
  across builds.
- **F-10 — Add a `docker compose --profile monitoring` for Prometheus + Grafana.** Would
  scrape the PostgreSQL and Redis exporters and visualize them. Not currently part of the
  dev stack.

---

## 12. Verification commands

```bash
# 1. Confirm all 5 default containers are running
docker compose ps
# Expected: rcerp_postgres, rcerp_redis, rcerp_app, rcerp_nginx, rcerp_queue_worker,
#           rcerp_listen_notify all "Up"

# 2. Confirm the app responds on port 8080
curl -sI http://localhost:8080 | head -1
# Expected: HTTP/1.1 200 OK (or 302 redirect to /login)

# 3. Confirm PostgreSQL is reachable + has the schema
docker compose exec rcerp_postgres psql -U rcerp_app -d rcerp -c "
  SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';
"
# Expected: 66+ (the full schema)

# 4. Confirm Redis is reachable
docker compose exec rcerp_redis redis-cli ping
# Expected: PONG

# 5. Confirm the admin user exists
docker compose exec rcerp_postgres psql -U rcerp_app -d rcerp -c "
  SELECT username, is_active, credential_version FROM users WHERE username = 'admin';
"
# Expected: 1 row

# 6. Confirm the system_policies table has the default NORMAL policy
docker compose exec rcerp_postgres psql -U rcerp_app -d rcerp -c "
  SELECT mode, is_active FROM system_policies LIMIT 1;
"
# Expected: 1 row, mode = NORMAL

# 7. Confirm the entrypoint ran all 9 steps
docker compose logs rcerp_app | grep -E 'Step [0-9]:'
# Expected: 9 "Step N:" lines, all with ✓ (or ⚠ for non-fatal warnings)

# 8. Confirm the queue worker is processing jobs
docker compose exec rcerp_app php artisan queue:monitor
# Or check the worker log:
docker compose logs --tail 20 rcerp_queue_worker

# 9. Confirm the listen-notify worker is connected
docker compose logs --tail 20 rcerp_listen_notify
# Expected: "Listening on channels: rcerp_*"

# 10. Confirm the MySQL archive is reachable (only if --profile archive)
docker compose --profile archive exec rcerp_mysql_archive mysql -u archive_reader -parchive_reader_secret -e "SELECT 1;" rcerp_legacy
# Expected: 1
```

---

## 13. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Container topology | §7 | `../architecture/high-level-architecture.md` |
| Env vars consumed by containers | §7.5 step 2 | `environment.md` §7 |
| Entrypoint migration logic | §7.5 step 6 | `../database/migrations-conventions.md` |
| Admin user creation | §7.5 step 8 | `../security/auth-and-sessions.md` §3 |
| Queue worker container | §7.2 | `../architecture/realtime-events.md` (queue + listen-notify) |
| MySQL archive opt-in | §9.2 | `../archive/legacy-read-only.md` §7.6 |
| `system_policies` safety net | §7.5 step 7 | `../security/system-policy-compliance.md` |
| Windows-NTFS bind-mount fix | §7.5 step 1, §10 E-2 | (none — this is the canonical ref) |
| `node_modules` cache-bust fix | §7.5 step 4, §10 E-1 | (none — this is the canonical ref) |
| Docker NOT for production | §3, R-10 | `vps-bdix-deployment.md` (the prod path) |

---

*End of `docker-setup.md`. For the VPS (bare-metal Ubuntu) deployment path, see
`vps-bdix-deployment.md`. For the Nginx config (both Docker and VPS), see
`nginx-config.md`.*
