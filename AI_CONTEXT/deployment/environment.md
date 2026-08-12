# Environment Configuration

> **Module:** Deployment (Environment & secrets)
> **Audience:** DevOps engineers, release managers, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `laravel/config/*.php`, `laravel/.env.example`
> (inferred), `docker/entrypoint.sh` (lines 56–117), `docker-compose.yml` (service env
> blocks), `.env.docker`, `laravel/config/archive.php`, `laravel/config/database.php`,
> `laravel/config/app.php`, and `../security/credential-versioning.md`.

---

## 1. What is it?

RC_ERP_v2 is a Laravel 12 + PostgreSQL 16 + Redis 7 application. Like every Laravel app it
is **environment-driven**: every runtime decision — database host, queue driver, cache TTL,
archive feature flag, auth lockout thresholds, GL reconciliation tolerance — is read from
environment variables via `env()` calls inside `laravel/config/*.php`. The `.env` file is
the **single deployment-specific input** that turns the same code image into a dev, staging,
or production instance.

This file is the canonical reference for **every environment variable** the ERP reads, where
it is consumed, what its default is, when to override it, and the production hygiene rules
that surround it. It is deliberately exhaustive: a release manager cutting a new VPS
deployment should be able to provision the entire `.env` from this single page without
reading any other source.

Three artefacts in the repo already cover overlapping ground and are consolidated here:

| Source | Scope | Why this file supersedes it for deployment reference |
|---|---|---|
| `docker/entrypoint.sh` lines 56–117 | The Docker-generated `.env` for the `rcerp_app` container | It is a **template literal** baked into the entrypoint; it does not document defaults, env-var semantics, or production overrides. |
| `.env.docker` | Only the 7 docker-compose-level vars (`POSTGRES_*`, `MYSQL_*`) | It is **not** a Laravel `.env` — it only feeds `docker-compose.yml`'s `${VAR:-default}` interpolations. |
| `laravel/config/*.php` | Per-config-file `env()` calls | Scattered across 30+ files; no single index exists. This file is that index. |

---

## 2. Why does it exist?

- **Twelve-factor compliance.** Config lives in the environment, not the codebase. The same
  Docker image runs in dev, staging, and prod with no code changes — only `.env` differs.
- **Secret hygiene.** Passwords, API tokens, and the `APP_KEY` must never be committed. The
  `.gitignore` excludes `.env`; the `.env.docker` file is the **only** env file in the repo
  and it contains only non-secret docker-compose interpolations.
- **Auditability.** Accountants and compliance officers need to know *which* thresholds are
  tunable (lockout, GL tolerance, stale-draft days) and *where* they live. A single index
  makes that answer one `Ctrl-F` away.
- **Operational safety.** The ERP is safety-critical (accounting integrity, audit trails,
  branch isolation via RLS). Several env vars directly affect safety — e.g.
  `APP_DEBUG=true` in production leaks stack traces with secrets, `APP_KEY` rotation
  invalidates all encrypted cookies, `ARCHIVE_ENABLED=false` silently disables the legacy
  read-only layer. This file flags every such var with a **SAFETY** note.
- **Onboarding.** A new DevOps engineer (or AI assistant) provisioning a fresh VPS needs a
  single page that says "here are the 60 env vars, here is what each does, here is the
  production value". Without this file they have to grep `config/*.php` and reverse-engineer.

---

## 3. When is it used?

- **Initial VPS provisioning** — copy `.env.docker` to `.env`, then edit per the §8
  production-cheatsheet below.
- **Docker local dev** — `docker/entrypoint.sh` auto-generates the `.env` on first start if
  missing (see §7.2 below). Override by mounting a custom `.env` into the container.
- **Secret rotation** — when rotating `APP_KEY`, API tokens, or DB passwords, this file
  lists every place the old value was consumed.
- **Debugging "why is X behaving like Y"** — start here. Most "the app is doing the wrong
  thing" tickets trace to a wrong env var (e.g. `SESSION_DRIVER=file` instead of `redis`
  causes the legacy session bridge to silently break).
- **Security audit** — §10 below is the checklist an auditor runs to verify no secret is
  committed, no `APP_DEBUG=true` in prod, no `ARCHIVE_ENABLED=false` without a ticket.

---

## 4. Who uses it?

- **DevOps engineer** — primary audience. Provisions `.env` for every environment.
- **Release manager** — verifies §8 production-cheatsheet before signing off go-live.
- **DBA** — cares about `DB_*`, `PG_*` GUCs, partitioning-related envs.
- **Security auditor** — runs §10 verification commands.
- **AI assistants** — MUST consult this file before suggesting "set `FOO=bar` in `.env`".
  Every recommendation must cite the env-var row from §7 below.
- **Accountants** — care about `GL_RECONCILIATION_TOLERANCE`, `AUTH_*` lockout thresholds,
  and `SALES_STALE_DRAFT_DAYS` (config-driven, not env — see §9.2).

---

## 5. Related modules

- `docker-setup.md` — the Docker-generated `.env` template + `entrypoint.sh` env handling.
- `vps-bdix-deployment.md` — the VPS provisioning sequence that ends with `.env` written.
- `nginx-config.md` — Nginx does not read `.env`; it reads `docker/nginx/default.conf`.
  But the `APP_URL` env var must match the Nginx `server_name`.
- `artisan-commands.md` — many commands consume env vars (e.g.
  `php artisan api:token` reads `APP_KEY` for hashing; `php artisan listen-notify:worker`
  reads `DB_*`).
- `cron-scheduled-jobs.md` — the scheduler respects `APP_TIMEZONE`; pg_cron uses UTC.
- `../security/credential-versioning.md` — the `APP_KEY` and `users.api_token` columns
  interplay with `users.credential_version` for session invalidation.
- `../archive/legacy-read-only.md` — the `ARCHIVE_*` env-var family.

---

## 6. Business rules

> These are the **invariants** the env-var system must obey. Violating any of them is a
> release-blocker.

- **R-1 — One `.env` per environment, never committed.** The repo contains `.env.docker`
  (docker-compose interpolations only) and **no** `.env`. `.gitignore` excludes `.env`,
  `.env.local`, `.env.*.local`. Any `.env` found in `git ls-files` is a critical incident.
- **R-2 — `APP_KEY` is mandatory and immutable per environment.** Laravel refuses to boot
  without it. Rotating it invalidates every encrypted cookie and every `Crypt::encrypt()`
  value (including session data if `SESSION_ENCRYPT=true`). Rotation requires a controlled
  migration — see §11.1.
- **R-3 — `APP_DEBUG=false` in production. NON-NEGOTIABLE. `true` leaks stack traces with
  DB credentials, env vars, and user PII to the browser. The `docker/entrypoint.sh`
  template hardcodes `APP_DEBUG=true` because it is a **dev** template — VPS deployment
  MUST override.
- **R-4 — `APP_TIMEZONE=Asia/Dhaka`.** The ERP operates in Bangladesh. All `created_at`
  columns, all `Carbon::now()` calls, and the Laravel scheduler run in this timezone.
  pg_cron runs in UTC — see `cron-scheduled-jobs.md` §6 for the timezone reconciliation.
- **R-5 — `DB_CONNECTION=pgsql`.** The ERP does NOT support MySQL as the primary DB. The
  `mysql_archive` connection is read-only and uses a separate config block.
- **R-6 — `SESSION_DRIVER=redis` + `SESSION_CONNECTION=legacy`.** The legacy session bridge
  requires both — Laravel must read sessions from Redis DB 1 (where the legacy PHP app
  wrote them). Switching to `SESSION_DRIVER=database` or `file` silently breaks the bridge.
- **R-7 — `QUEUE_CONNECTION=redis`.** The queue worker container (`rcerp_queue_worker`) is
  pointless without this. Switching to `sync` blocks every HTTP request on every queued job
  (CSV exports, notification dispatch).
- **R-8 — `CACHE_STORE=redis`.** Several services depend on Redis cache (idempotency keys,
  rate-limit buckets, archive lookups). Switching to `file` works but loses cross-container
  shared state.
- **R-9 — `ARCHIVE_ENABLED=true` is the default.** Setting it to `false` silently disables
  the legacy read-only layer — `/admin/archive` will show "archive offline" without errors.
  This is the **decommission switch**, not a debugging toggle.
- **R-10 — Secrets use strong values in production.** `rcerp_secret` (DB password),
  `archive_reader_secret`, `archive_root_secret` are **dev defaults** baked into
  `docker-compose.yml`. Production MUST override via real secrets. The §10 audit command
  greps for these literal strings.
- **R-11 — `APP_URL` matches the Nginx `server_name`.** Laravel uses `APP_URL` to generate
  absolute URLs (password-reset emails, API docs links). Mismatch causes broken links.
- **R-12 — Env vars are read at process start, not per-request.** PHP-FPM workers cache
  `env()` values for the worker lifetime. Changing `.env` requires `php artisan config:clear`
  + FPM restart. See §11.2.

---

## 7. The env-var catalogue

### 7.1 Application core (`config/app.php`)

| Var | Default (dev) | Prod value | Purpose |
|---|---|---|---|
| `APP_NAME` | `"Remote Center ERP"` | same | Display name in emails, headers |
| `APP_ENV` | `local` | `production` | Laravel environment flag (enables caching, disables verbose errors) |
| `APP_KEY` | `base64:2cn8GO0r6OSab790IzGrvPj+siQVQDNsjsWbkzNxRC4=` | **generate fresh** (`php artisan key:generate`) | AES-256-CBC key for `Crypt::encrypt()` + cookie signing. **SAFETY**: rotation invalidates sessions — see §11.1 |
| `APP_DEBUG` | `true` | **`false`** | Stack-trace visibility. **SAFETY**: `true` in prod leaks secrets |
| `APP_TIMEZONE` | `Asia/Dhaka` | same | PHP timezone for `Carbon::now()`, `created_at` casts |
| `APP_URL` | `http://localhost:8080` | `https://erp.example.com` | Absolute-URL generation. Must match Nginx `server_name` |
| `APP_LOCALE` | `en` | same | i18n locale (English-only currently) |
| `APP_FALLBACK_LOCALE` | `en` | same | Fallback when translation missing |
| `APP_FAKER_LOCALE` | `en_US` | same | Faker locale for seeders |
| `APP_CIPHER` | `AES-256-CBC` | same | Cipher for `Crypt::encrypt()` |
| `APP_PREVIOUS_KEYS` | (empty) | comma-separated prior `APP_KEY`s | Allows decrypting cookies/data encrypted with old keys during rotation |
| `APP_MAINTENANCE_DRIVER` | `file` | `database` | Where the maintenance-mode flag lives. `database` is shared across FPM workers |
| `APP_MAINTENANCE_STORE` | `database` | same | Cache store for maintenance mode |
| `LEGACY_APP_URL` | `/` | `https://old.example.com` (or removed) | Link back to legacy app (used in transition period) |
| `GL_RECONCILIATION_TOLERANCE` | `0.02` | same (BDT 0.02 = 2 paisa) | Float tolerance for Dr=Cr balance checks. **SAFETY**: raising this hides fraud |

### 7.2 Database (`config/database.php`)

| Var | Default | Prod value | Purpose |
|---|---|---|---|
| `DB_CONNECTION` | `pgsql` | same | **Always `pgsql`** — see R-5 |
| `DB_URL` | (empty) | (empty) | Composite URL (overrides individual fields if set) |
| `DB_HOST` | `127.0.0.1` (local) / `rcerp_postgres` (docker) | VPS private IP | PostgreSQL host |
| `DB_PORT` | `5432` | same | PostgreSQL port |
| `DB_DATABASE` | `rcerp` | same | Primary database name |
| `DB_USERNAME` | `rcerp_app` | same (or per-env) | Application DB user (NOT the superuser) |
| `DB_PASSWORD` | `rcerp_secret` (dev) | **strong secret** | DB password. **SAFETY**: never commit |
| `DB_SSLMODE` | `prefer` | `require` (if VPS-internal TLS) | PostgreSQL SSL mode |

### 7.3 Legacy MySQL archive (`config/database.php` → `mysql_archive` + `config/archive.php`)

> ⚠️ **Two separate env-var families.** See `../archive/legacy-read-only.md` §7.7 for the
> full disambiguation. Short version: `ARCHIVE_MYSQL_*` is for the Laravel `DB::connection('mysql_archive')`
> facade (used by `migrate:legacy-employees` + `migrate:master-data`); `ARCHIVE_DB_*` is for
> the raw-PDO `ArchiveService` ACL runtime.

| Var | Default | Purpose |
|---|---|---|
| `ARCHIVE_MYSQL_HOST` | `rcerp_mysql_archive` | Laravel facade MySQL host (one-time migration commands) |
| `ARCHIVE_MYSQL_PORT` | `3306` | Laravel facade MySQL port (container-internal; host port is 3307) |
| `ARCHIVE_MYSQL_DATABASE` | `rcerp_legacy` | Laravel facade MySQL database |
| `ARCHIVE_MYSQL_USERNAME` | `archive_reader` | Laravel facade MySQL user |
| `ARCHIVE_MYSQL_PASSWORD` | `archive_reader_secret` (dev) | Laravel facade MySQL password |
| `ARCHIVE_DB_HOST` | `127.0.0.1` | ACL raw-PDO host (read by `config/archive.php`) |
| `ARCHIVE_DB_PORT` | `3306` | ACL raw-PDO port |
| `ARCHIVE_DB_DATABASE` | `osudlagb_remotecenter` | ACL raw-PDO database (legacy real name) |
| `ARCHIVE_DB_USERNAME` | `readonly_user` | ACL raw-PDO user |
| `ARCHIVE_DB_PASSWORD` | (empty) | ACL raw-PDO password |
| `ARCHIVE_CACHE_TTL` | `3600` (1 hour) | Cache TTL for archive lookups (immutable data) |
| `ARCHIVE_MIGRATION_MONTHS` | `24` | How many months of recent history moved to PG (advisory, not enforced) |
| `ARCHIVE_ENABLED` | `true` | **Decommission switch** — see R-9 |

### 7.4 Redis (`config/database.php` → `redis`)

| Var | Default | Purpose |
|---|---|---|
| `REDIS_CLIENT` | `predis` | Redis client (`predis` or `phpredis`) |
| `REDIS_URL` | (empty) | Composite URL (overrides individual fields if set) |
| `REDIS_HOST` | `127.0.0.1` (local) / `rcerp_redis` (docker) | Redis host |
| `REDIS_USERNAME` | (empty) | Redis ACL username (Redis 6+) |
| `REDIS_PASSWORD` | `null` (dev) | Redis password. **SAFETY**: set in prod |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_DB` | `0` | Default Redis DB (cache, queue) |
| `LEGACY_SESSION_REDIS_DB` | `1` | Legacy session bridge DB (must match legacy php.ini) |
| `LEGACY_SESSION_COOKIE` | `PHPSESSID` | Cookie name shared with legacy PHP app |

### 7.5 Session + cache + queue (`config/session.php`, `config/cache.php`, `config/queue.php`)

| Var | Default | Purpose |
|---|---|---|
| `SESSION_DRIVER` | `redis` | **Must be `redis`** — see R-6 |
| `SESSION_CONNECTION` | `legacy` | **Must be `legacy`** (Redis DB 1) — see R-6 |
| `SESSION_LIFETIME` | `480` (8 hours) | Session TTL in minutes |
| `SESSION_COOKIE` | `PHPSESSID` | Must match `LEGACY_SESSION_COOKIE` for bridge |
| `SESSION_ENCRYPT` | `false` | Whether to `Crypt::encrypt()` session data. **SAFETY**: `true` couples sessions to `APP_KEY` |
| `SESSION_PATH` | `/` | Cookie path |
| `SESSION_DOMAIN` | `null` | Cookie domain (null = current host) |
| `SESSION_SECURE_COOKIE` | `false` | Prod: `true` (HTTPS-only) |
| `SESSION_SAMESITE` | `lax` | SameSite cookie attribute |
| `CACHE_STORE` | `redis` | **Must be `redis`** — see R-8 |
| `QUEUE_CONNECTION` | `redis` | **Must be `redis`** — see R-7 |

### 7.6 Authentication (`config/auth.php` and `config/services.php` via `app.gl_reconciliation_tolerance`)

> Auth thresholds are **config-driven, not env-driven**. See §9.2 for the config file
> reference. The only auth env vars are `APP_KEY` (above) and the legacy-session bridge
> vars (above).

### 7.7 Mail (`config/mail.php`)

| Var | Default | Purpose |
|---|---|---|
| `MAIL_MAILER` | `log` (dev) | Mail driver (`log`, `smtp`, `sendmail`, etc.) |
| `MAIL_HOST` | (empty) | SMTP host |
| `MAIL_PORT` | (empty) | SMTP port |
| `MAIL_USERNAME` | (empty) | SMTP user |
| `MAIL_PASSWORD` | (empty) | SMTP password |
| `MAIL_ENCRYPTION` | (empty) | `tls` or `ssl` |
| `MAIL_FROM_ADDRESS` | (empty) | From address |
| `MAIL_FROM_NAME` | `"Remote Center ERP"` | From name |

> Production: configure SMTP or a transactional provider (e.g. SendGrid). The dev `log`
> mailer writes emails to `storage/logs/laravel.log` — fine for password-reset testing.

### 7.8 Filesystem / cloud (`config/filesystem.php`)

| Var | Default | Purpose |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | Default disk (`local`, `public`, `s3`) |

> The ERP currently uses local disk only (uploads go to `storage/app/public/`). S3 support
> is config-ready but not configured.

---

## 8. Production cheatsheet

> Copy this block, fill in the secrets, save as `laravel/.env` on the VPS.

```dotenv
# === Application ===
APP_NAME="Remote Center ERP"
APP_ENV=production
APP_KEY=base64:GENERATED_BY_php_artisan_key:generate
APP_DEBUG=false
APP_TIMEZONE=Asia/Dhaka
APP_URL=https://erp.example.com
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=database
APP_MAINTENANCE_STORE=database

# === Primary DB (PostgreSQL) ===
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rcerp
DB_USERNAME=rcerp_app
DB_PASSWORD=STRONG_PROD_PASSWORD
DB_SSLMODE=prefer

# === Redis ===
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=STRONG_REDIS_PASSWORD
REDIS_PORT=6379
REDIS_DB=0
LEGACY_SESSION_REDIS_DB=1
LEGACY_SESSION_COOKIE=PHPSESSID

# === Session + Cache + Queue ===
SESSION_DRIVER=redis
SESSION_CONNECTION=legacy
SESSION_LIFETIME=480
SESSION_COOKIE=PHPSESSID
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAMESITE=lax
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# === Legacy MySQL archive (ACL runtime — raw PDO) ===
ARCHIVE_DB_HOST=127.0.0.1
ARCHIVE_DB_PORT=3306
ARCHIVE_DB_DATABASE=osudlagb_remotecenter
ARCHIVE_DB_USERNAME=readonly_user
ARCHIVE_DB_PASSWORD=ARCHIVE_READONLY_PASSWORD
ARCHIVE_CACHE_TTL=3600
ARCHIVE_MIGRATION_MONTHS=24
ARCHIVE_ENABLED=true

# === Legacy MySQL archive (Laravel facade — one-time migration commands) ===
ARCHIVE_MYSQL_HOST=127.0.0.1
ARCHIVE_MYSQL_PORT=3306
ARCHIVE_MYSQL_DATABASE=rcerp_legacy
ARCHIVE_MYSQL_USERNAME=archive_reader
ARCHIVE_MYSQL_PASSWORD=archive_reader_password

# === Mail (transactional provider) ===
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=postmaster@example.com
MAIL_PASSWORD=SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Remote Center ERP"

# === Misc ===
LEGACY_APP_URL=/
GL_RECONCILIATION_TOLERANCE=0.02
```

---

## 9. Config-driven (not env-driven) thresholds

> These are **NOT** env vars — they live in `laravel/config/*.php` and are hardcoded
> arrays. Documented here because release managers often ask "where do I tune X?" and the
> answer is usually "config file, not env".

### 9.1 Where each threshold lives

| Threshold | File | Key | Default | Notes |
|---|---|---|---|---|
| Auth lockout attempts | `config/auth.php` | `lockout.max_attempts` | 5 | See `../security/password-policy.md` |
| Auth lockout minutes | `config/auth.php` | `lockout.decay_minutes` | 15 | |
| Password reset token TTL | `config/auth.php` | `password_reset.token_lifetime_hours` | 1 | |
| Remember-me days | `config/auth.php` | `remember_me.days` | 30 | |
| Stale draft days | `config/sales.php` | `stale_draft_auto_cancel.days` | 14 | See `../sales/sales-invoice.md` §11 |
| Stock drift alert roles | `config/inventory.php` | `stock_adjustment.reconcile_alert_roles` | `['admin']` | See `../inventory/stock-adjustment.md` |
| GL reconciliation tolerance | `config/app.php` | `gl_reconciliation_tolerance` | 0.02 | Also env-overridable |
| RBAC role list | `config/roles.php` | `roles.*` | (full role map) | See `../security/rbac-roles-permissions.md` |
| API rate limits | `config/api.php` | `rate_limits.{tier}` | 30/60/120 req/min | See `../api/api-overview.md` §7.4 |
| Archive cache TTL | `config/archive.php` | `cache_ttl` | 3600 | Also env-overridable |
| Partition retention months | `partman.part_config` (DB table) | `retention` | per-parent | See `../database/partitioning.md` |

### 9.2 Why split between env and config?

- **Env** = "varies per environment" (DB host, mail server, APP_DEBUG).
- **Config** = "constant for the business rule" (lockout thresholds, role list, GL
  tolerance). These rarely change between dev and prod — and when they do, it's a business
  decision (e.g. tightening lockout from 5 to 3 attempts after a security incident), not an
  ops decision.

---

## 10. Production hygiene audit

Run these commands before every production deploy. All must pass.

```bash
# 1. No .env file is committed to git
git ls-files | grep -E '(^|/)\.env($|\.)' | grep -v '\.env\.docker$'
# Expected: empty output. Any hit = critical incident.

# 2. APP_DEBUG=false in production
grep '^APP_DEBUG=' laravel/.env
# Expected: APP_DEBUG=false

# 3. APP_KEY is not the dev default
grep '^APP_KEY=' laravel/.env
# Expected: NOT base64:2cn8GO0r6OSab790IzGrvPj+siQVQDNsjsWbkzNxRC4=

# 4. No dev passwords in production
grep -E '(rcerp_secret|archive_reader_secret|archive_root_secret)' laravel/.env
# Expected: empty output

# 5. DB password is non-empty and >= 16 chars
awk -F= '/^DB_PASSWORD=/ {print length($2)}' laravel/.env
# Expected: >= 16

# 6. SESSION_DRIVER=redis
grep '^SESSION_DRIVER=' laravel/.env
# Expected: SESSION_DRIVER=redis

# 7. QUEUE_CONNECTION=redis
grep '^QUEUE_CONNECTION=' laravel/.env
# Expected: QUEUE_CONNECTION=redis

# 8. ARCHIVE_ENABLED is not false (unless intentional decommission)
grep '^ARCHIVE_ENABLED=' laravel/.env
# Expected: ARCHIVE_ENABLED=true (or absent = defaults to true)

# 9. APP_TIMEZONE=Asia/Dhaka
grep '^APP_TIMEZONE=' laravel/.env
# Expected: APP_TIMEZONE=Asia/Dhaka

# 10. Config cache is fresh (no stale env values cached)
php artisan config:clear && php artisan cache:clear
# Then rebuild: php artisan config:cache
```

---

## 11. Operational procedures

### 11.1 `APP_KEY` rotation

> **SAFETY-CRITICAL.** Do NOT rotate `APP_KEY` without following this procedure. A naive
> rotation invalidates every encrypted cookie (every logged-in user is logged out) AND
> makes every `Crypt::encrypt()`-ed column unreadable.

1. **Inventory every encrypted artifact:**
   - Session data (only if `SESSION_ENCRYPT=true` — currently `false`, so safe).
   - Cookie data (Laravel signs all cookies with `APP_KEY`; signed cookies become invalid).
   - Encrypted columns (grep `Crypt::encrypt` in `laravel/app/` — currently zero columns).
2. **Add the old key to `APP_PREVIOUS_KEYS`:**
   ```dotenv
   APP_PREVIOUS_KEYS=base64:OLD_KEY_HERE
   ```
   Laravel will decrypt with the old key, re-encrypt with the new key on next write.
3. **Generate the new key:**
   ```bash
   php artisan key:generate
   ```
   This writes `APP_KEY=base64:NEW_KEY` to `.env`.
4. **Clear config cache + restart FPM:**
   ```bash
   php artisan config:clear
   sudo systemctl restart php8.4-fpm
   ```
5. **Verify:** log in, confirm sessions still work. Run a password-reset flow end-to-end.
6. **After 30 days** (cookie lifetime), remove `APP_PREVIOUS_KEYS`.

### 11.2 Applying env changes to a running production server

```bash
# 1. Edit .env
sudo nano /var/www/rcerp_v2/laravel/.env

# 2. Clear the config cache (env values are cached in config cache)
php artisan config:clear

# 3. Rebuild the config cache for prod performance
php artisan config:cache

# 4. Restart PHP-FPM (env values are cached in worker memory)
sudo systemctl restart php8.4-fpm

# 5. Restart the queue worker (it caches env on boot)
sudo supervisorctl restart rcerp-queue-worker

# 6. Restart the listen-notify worker (same reason)
sudo supervisorctl restart rcerp-listen-notify
```

> The Laravel scheduler (`php artisan schedule:work` / cron) reads env at process start
> too. The cron entry itself does not need restart, but a long-running `schedule:work`
> daemon does.

### 11.3 Secret rotation cadence

| Secret | Cadence | Procedure |
|---|---|---|
| `APP_KEY` | Annually, or after a breach | §11.1 above |
| `DB_PASSWORD` | Quarterly | Update PG role + `.env` + restart FPM + queue + listen-notify |
| `REDIS_PASSWORD` | Annually | Update Redis config + `.env` + restart all PHP processes |
| `ARCHIVE_DB_PASSWORD` | Annually | Update MySQL user + `.env` + restart FPM (ACL is per-request PDO) |
| API tokens (`users.api_token`) | Per-user, on demand | `php artisan api:token {username}` — see `../api/api-overview.md` §7.5 |

---

## 12. Known edge cases

- **E-1 — Docker entrypoint overwrites `.env` only on first start.** If the operator
  hand-edits `.env` inside the `rcerp_app` container and then restarts, the entrypoint
  sees the existing file and skips regeneration. Good. But if they delete `.env` and
  restart, the entrypoint regenerates with the dev defaults (`APP_DEBUG=true` etc.) —
  losing production overrides. Mitigation: never delete `.env` in a running container.
- **E-2 — `SESSION_ENCRYPT=false` is correct.** Setting it to `true` couples sessions to
  `APP_KEY`, so rotating `APP_KEY` logs every user out. The legacy session bridge
  requires `false` anyway (legacy PHP doesn't encrypt sessions).
- **E-3 — `LEGACY_SESSION_COOKIE=PHPSESSID` must match the legacy php.ini.** If the
  legacy PHP app is configured with a different `session.cookie_name`, the bridge breaks
  silently (both apps write different cookies, users appear logged-out in one).
- **E-4 — `APP_TIMEZONE=Asia/Dhaka` vs pg_cron UTC.** The Laravel scheduler runs in
  `APP_TIMEZONE`; pg_cron runs in UTC. A job scheduled `dailyAt('02:00')` in Laravel runs
  at 02:00 Dhaka = 20:00 UTC previous day. pg_cron `'0 2 * * *'` runs at 02:00 UTC = 08:00
  Dhaka. The two schedules are NOT aligned — see `cron-scheduled-jobs.md` §6 for the full
  reconciliation table.
- **E-5 — `APP_DEBUG=true` in a Docker rebuild.** The `docker/entrypoint.sh` template
  hardcodes `APP_DEBUG=true`. If a production VPS uses the same entrypoint (not recommended
  — VPS should run PHP-FPM directly without Docker), the env override must happen BEFORE
  the entrypoint runs (e.g. via a mounted `.env`).
- **E-6 — `ARCHIVE_MYSQL_HOST` is `rcerp_mysql_archive` (Docker DNS name) in the
  docker-compose env block.** On a VPS (no Docker), this must be `127.0.0.1` or the actual
  MySQL archive host. Forgetting to change it causes the migrate:legacy-* commands to fail
  with "host not found".
- **E-7 — `DB_SSLMODE=prefer` falls back to plaintext.** If the VPS PostgreSQL is
  configured to require SSL (`hostssl` in `pg_hba.conf`), `prefer` works. If the PG server
  is misconfigured to refuse SSL, `prefer` silently uses plaintext — exposing the DB
  password on the wire. Production should use `require` if SSL is configured.
- **E-8 — `REDIS_PASSWORD=null` is the literal string "null" in some env parsers.**
  Laravel's `env()` returns `null` (PHP null) for the literal string `"null"` — this is
  documented Laravel behavior. But if Redis actually requires a password and `.env` has
  `REDIS_PASSWORD=null`, the connection fails with "NOAUTH Authentication required".
- **E-9 — `APP_PREVIOUS_KEYS` is comma-separated with no spaces.** Trailing spaces or
  missing commas cause the explode to produce malformed keys, which fail silently (Laravel
  catches decryption errors and returns null).
- **E-10 — `MAIL_MAILER=log` in production silently swallows all emails.** Password-reset
  emails are written to `storage/logs/laravel.log` instead of being sent. Users report
  "I never got the reset email" and the log grows. Production MUST set `MAIL_MAILER=smtp`.

---

## 13. Future improvements

- **F-1 — Centralise all env vars in a single `.env.example` checked into the repo.**
  Currently there is no `.env.example` — the Docker entrypoint template and this doc are
  the only references. A `.env.example` would let `cp .env.example .env` work outside
  Docker.
- **F-2 — Use AWS Secrets Manager / HashiCorp Vault for secret rotation.** Currently
  secrets are manually rotated per §11.3. A secrets manager would automate rotation and
  remove secrets from `.env` entirely.
- **F-3 — Add a `php artisan env:audit` command.** Would programmatically check the §10
  hygiene rules and fail CI if any rule is violated. Currently the audit is manual.
- **F-4 — Document `config/logging.php` channels.** Currently logging config is not
  covered here. Should be added (log channels, stack channels, Slack channel for
  production errors).
- **F-5 — Add `SENTRY_DSN` env var for error monitoring.** Sentry integration is not yet
  implemented; would be a one-line env var + composer package.
- **F-6 — Move `GL_RECONCILIATION_TOLERANCE` from env to config.** It is a business
  rule, not an environment setting. Currently it straddles both (`config/app.php` reads
  `env('GL_RECONCILIATION_TOLERANCE', 0.02)`). Should be config-only.
- **F-7 — Document the `pg_partman` retention config in env terms.** Currently the
  retention months live in the `partman.part_config` DB table, not in env. A future env
  var like `PARTITION_RETENTION_MONTHS_DEFAULT` could drive a migration that updates
  `part_config`.
- **F-8 — Add a `DEPLOYMENT_ENV` label env var.** Would let the app self-report
  "I am running in staging" for display in the UI footer (currently only `APP_ENV` exists,
  which conflates Laravel env with deployment env).

---

## 14. Verification commands

```bash
# 1. List every env var the app reads (greps config/*.php)
grep -rhoE "env\(['\"][A-Z_]+['\"]" laravel/config/ | sed "s/env(['\"//" | sort -u

# 2. Confirm .env is not in git
git ls-files laravel/.env
# Expected: empty

# 3. Confirm .env.docker IS in git (the only env file in repo)
git ls-files .env.docker
# Expected: .env.docker

# 4. Validate .env syntax (no spaces around =, no inline comments)
php -r "var_dump(array_map(fn(\$l) => explode('=', \$l, 2), file('laravel/.env')));"

# 5. Confirm Laravel can read the .env
docker compose exec rcerp_app php artisan tinker --execute="echo config('app.url');"
# Expected: the APP_URL value

# 6. Confirm Redis is reachable
docker compose exec rcerp_app php artisan tinker --execute="echo \Illuminate\Support\Facades\Redis::ping();"
# Expected: +PONG

# 7. Confirm PostgreSQL is reachable
docker compose exec rcerp_app php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::select('SELECT 1')[0]->?1;"
# Expected: 1

# 8. Confirm the legacy MySQL archive is reachable (only if --profile archive)
docker compose --profile archive exec rcerp_app php artisan tinker --execute="
  try { echo \Illuminate\Support\Facades\DB::connection('mysql_archive')->getPdo()->query('SELECT 1')->fetchColumn(); }
  catch (\Throwable \$e) { echo 'archive offline: ' . \$e->getMessage(); }
"
# Expected: 1 (or "archive offline: ..." if the container is not running)

# 9. Run the §10 production hygiene audit (paste from above)

# 10. Confirm config cache is fresh
php artisan config:cache && php artisan config:show app.url
# Expected: the APP_URL value
```

---

## 15. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Env-var catalogue | §7 | `../security/credential-versioning.md` (APP_KEY + credential_version) |
| Production cheatsheet | §8 | `vps-bdix-deployment.md` §5 (VPS .env writing step) |
| Config-vs-env split | §9 | `../coding/config-driven-rules.md` |
| APP_KEY rotation | §11.1 | `../security/credential-versioning.md` §6 |
| Applying env changes | §11.2 | `cron-scheduled-jobs.md` §7 (scheduler restart) |
| ARCHIVE_* disambiguation | §7.3 | `../archive/legacy-read-only.md` §7.7 |
| Legacy session bridge vars | §7.4 + §7.5 | `../security/auth-and-sessions.md` §7.4 |
| Timezone reconciliation | §12 E-4 | `cron-scheduled-jobs.md` §6 |
| Production hygiene audit | §10 | `go-live-checklist.md` §3 (env-var sign-off) |

---

*End of `environment.md`. For the Docker-specific env generation, see `docker-setup.md`
§7.2. For the VPS provisioning sequence that writes `.env`, see `vps-bdix-deployment.md`
§5.*
