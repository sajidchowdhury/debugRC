# Go-Live Checklist

> **Module:** Deployment (Production go-live verification gate)
> **Audience:** Release manager, DevOps engineer, DBA, QA lead, accountant, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `environment.md`, `docker-setup.md`,
> `vps-bdix-deployment.md`, `nginx-config.md`, `artisan-commands.md`,
> `cron-scheduled-jobs.md`, `../security/system-policy-compliance.md`, and
> `docs/STOCK_TAKE_GO_LIVE_CHECKLIST.md` (the per-feature template this file generalizes).

---

## 1. What is it?

This is the **single verification gate** between "code is deployed" and "ERP is announced
to end users". Every checkbox must be `[x]` before the release manager signs off go-live.
The sign-off table at §13 captures name + date + signature for each role.

The checklist covers 12 areas:

1. Pre-flight (VPS + DNS + secrets ready)
2. Environment configuration (`.env` audit)
3. Schema + migrations
4. Master data + admin user
5. Nginx + TLS
6. PHP-FPM + workers (queue + listen-notify)
7. Scheduler (Laravel + pg_cron + supervisor)
8. Backup + disaster recovery
9. **Verification suites** (post-deploy + period-close)
10. Security audit
11. Smoke tests (manual UI walkthrough)
12. Rollback plan

This file **generalizes** the per-feature `docs/STOCK_TAKE_GO_LIVE_CHECKLIST.md` template
(which covers only the Stock Take feature) into an ERP-wide go-live gate. Per-feature
checklists may still be used as supplements — this file is the umbrella.

### 1.1 Status: pending first real VPS go-live

> ⚠️ As of Phase 19, no real VPS go-live has happened (see `vps-bdix-deployment.md` §1.3).
> This checklist is the **expected procedure**. After the first real go-live, update this
> file with the actual values observed (e.g. "Step 9.1.1 took 4 minutes on a 4-core VPS")
> and flip Status to "Canonical — verified".

---

## 2. Why does it exist?

- **Single gate.** Without a checklist, "is the ERP ready for users?" is a subjective
  question. With this checklist, it's objective: all 12 sections checked + 5 sign-offs
  collected = ready.
- **Safety.** The ERP is safety-critical (accounting integrity, audit trails, branch
  isolation). §9 verification suites are the safety net — they catch GL drift, stock
  drift, RBAC bypass, partition regression before users touch the system.
- **Auditability.** The sign-off table (§13) is stored alongside the release tag in
  `docs/releases/`. An auditor can verify "was the checklist run before go-live on date
  X?" by checking the table.
- **Rollback readiness.** §12 documents the rollback procedure BEFORE go-live, so it's
  ready if anything goes wrong in the first 24 hours.
- **Cross-role alignment.** The checklist forces all 5 roles (release manager, DevOps,
  DBA, QA, accountant) to verify their area before sign-off. No single role can declare
  go-live alone.
- **Repeatable.** Every go-live (initial + every major version) follows the same
  checklist. No "we forgot to check X last time" regressions.

---

## 3. When is it used?

- **Initial VPS go-live** — the full §4–§13 sequence, run once.
- **Major version upgrade** (e.g. Phase 20 cross-cutting workflows ship) — the same
  checklist, focused on the changed areas.
- **Disaster recovery** — after restoring from backup on a fresh VPS, run the §9
  verification suites to confirm data integrity.
- **NOT for routine code deploys.** Routine deploys use the §9.1 post-deploy verification
  suite only (5 commands). The full checklist is for go-live-class events.

---

## 4. Who uses it?

| Role | Responsibility | Sign-off area |
|---|---|---|
| Release manager | Drives the checklist; collects sign-offs | §1, §13 |
| DevOps engineer | VPS provisioning, env config, Nginx, supervisor, backups | §1, §2, §5, §6, §7, §8 |
| DBA | Schema, migrations, master data, pg_cron, partitioning | §3, §4, §7.2, §9.3, §9.4 |
| QA lead | Smoke tests, RBAC pen-test, verification suites | §9, §11 |
| Accountant | GL verification, sub-ledger reconciliation, manual sample review | §9.2, §11.4 |

---

## 5. Related modules

- `environment.md` §10 — the env-var hygiene audit (used in §2 below).
- `docker-setup.md` §12 — the Docker verification commands (used in dev go-live).
- `vps-bdix-deployment.md` §8 — the VPS provisioning sequence (gates §1 below).
- `nginx-config.md` §12 — the Nginx verification commands (used in §5 below).
- `artisan-commands.md` §9 — the verification suites (used in §9 below).
- `cron-scheduled-jobs.md` §14 — the scheduler verification commands (used in §7 below).
- `../security/system-policy-compliance.md` — the system policy framework (used in §10).
- `../security/rbac-roles-permissions.md` — the RBAC matrix (used in §10.3).
- `../security/audit-trails.md` — the audit trail verification (used in §10.4).

---

## 6. Business rules

- **R-1 — All checkboxes must be `[x]` before sign-off.** No partial sign-offs. If a
  checkbox can't be checked, the go-live is blocked.
- **R-2 — All 5 sign-offs (§13) must be collected.** Release manager + DevOps + DBA + QA
  + accountant. Missing any one = blocked.
- **R-3 — The §9 verification suites are MANDATORY.** §9.1 (post-deploy) + §9.2
  (period-close) + §9.3 (partitioning) + §9.4 (archival). All must pass with exit code 0.
- **R-4 — Rollback plan (§12) must be documented BEFORE go-live.** Not "we'll figure it
  out if something breaks". The procedure + the backup-to-restore-from must be specified.
- **R-5 — The 24-hour rollback window.** If a severe issue is found within 24 hours of
  go-live, rollback is allowed (and expected). After 24 hours, rollback requires explicit
  business-owner approval (because users have entered data).
- **R-6 — The accountant sign-off requires manual sample review.** §9.2 commands 6 + 7
  (`journal:manual-verify` + `stock:manual-verify`) print 10 sample entries/products for
  the accountant to visually review. The accountant MUST review + sign off, not just run
  the commands.
- **R-7 — The security audit (§10) is non-negotiable.** Even if the verification suites
  pass, the security audit must pass. A passing verification suite doesn't catch a
  misconfigured firewall or a committed secret.
- **R-8 — Smoke tests (§11) require a human.** Automated tests cover code paths; smoke
  tests cover "does the UI actually work for a real user?". A QA engineer MUST walk
  through §11 manually.
- **R-9 — The checklist is versioned with the release.** Each release tag in
  `docs/releases/` includes a snapshot of this checklist with the sign-offs filled in.
- **R-10 — Failures block go-live.** If any checkbox can't be checked, the release
  manager MUST either (a) fix the issue, (b) document an accepted risk with business-
  owner approval, or (c) delay go-live. Silent "we'll fix it later" is not acceptable.
- **R-11 — The checklist is run on the PRODUCTION VPS, not staging.** Staging
  verification is encouraged but doesn't count — the production VPS may have different
  data, different env vars, different PG config.
- **R-12 — The checklist is run AFTER all code is deployed, not before.** Deploy first,
  then verify. Verifying staging + deploying after is a common mistake (the deploy could
  break something the staging verification didn't catch).

---

## 7. Section format

Each section below has:

- A **header** with the area name + responsible role.
- A **checkbox list** of verification steps.
- **Verification commands** (where applicable) — copy-paste ready.
- **Expected output** — what success looks like.
- **Failure handling** — what to do if it fails.

---

## 8. §1 — Pre-flight (Release manager + DevOps)

> Run BEFORE touching the production VPS.

### 8.1 Checklist

- [ ] **1.1** VPS provisioned (Ubuntu 22.04 LTS, root SSH access, BDIX-connected).
- [ ] **1.2** Domain name purchased + DNS A record pointed at the VPS IP.
- [ ] **1.3** BDIX latency verified <50ms from each office ISP.
- [ ] **1.4** Backup destination ready (second VPS / S3-compatible storage / local backup
  server with SSH access).
- [ ] **1.5** Legacy MySQL dump available (if migrating from the legacy system).
- [ ] **1.6** Production `.env` drafted (use `environment.md` §8 cheatsheet).
- [ ] **1.7** Strong secrets generated (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`,
  `ARCHIVE_DB_PASSWORD`).
- [ ] **1.8** Go-live date + time announced to users (recommend Friday evening Dhaka, low
  traffic).
- [ ] **1.9** Rollback plan documented (§12 below).
- [ ] **1.10** Sign-off table (§13) prepared with all 5 roles notified.

### 8.2 Verification commands

```bash
# 1.2 — DNS resolution
dig +short erp.example.com
# Expected: the VPS IP

# 1.3 — BDIX latency (from a Dhaka office)
ping -c 10 erp.example.com
# Expected: avg <50ms

# 1.7 — Secret strength
echo -n "STRONG_DB_PASSWORD" | wc -c
# Expected: >= 16
```

### 8.3 Failure handling

- 1.2 fails → DNS not propagated. Wait (TTL × 2) + retry.
- 1.3 fails → ISP routing issue. Contact the BDIX provider or use a secondary VPS.
- 1.7 fails → Generate stronger secrets: `openssl rand -base64 32`.

---

## 9. §2 — Environment configuration (DevOps)

### 9.1 Checklist

- [ ] **2.1** `.env` file written per `environment.md` §8 (production cheatsheet).
- [ ] **2.2** `APP_ENV=production`.
- [ ] **2.3** `APP_DEBUG=false`.
- [ ] **2.4** `APP_KEY` is NOT the dev default (`base64:2cn8GO0r6OSab790IzGrvPj+...`).
- [ ] **2.5** `APP_TIMEZONE=Asia/Dhaka`.
- [ ] **2.6** `APP_URL` matches the Nginx `server_name` (e.g. `https://erp.example.com`).
- [ ] **2.7** `DB_CONNECTION=pgsql` + strong `DB_PASSWORD`.
- [ ] **2.8** `SESSION_DRIVER=redis` + `SESSION_CONNECTION=legacy`.
- [ ] **2.9** `QUEUE_CONNECTION=redis` + `CACHE_STORE=redis`.
- [ ] **2.10** `ARCHIVE_ENABLED=true` (unless intentional decommission).
- [ ] **2.11** `MAIL_MAILER=smtp` (NOT `log`).
- [ ] **2.12** `.env` file owned by `rcerp:rcerp` with mode `640`.
- [ ] **2.13** `.env` is NOT in git (`git ls-files laravel/.env` returns empty).
- [ ] **2.14** No dev passwords (`rcerp_secret`, `archive_reader_secret`,
  `archive_root_secret`) in production `.env`.
- [ ] **2.15** `php artisan config:cache` run after `.env` written.

### 9.2 Verification commands

```bash
# Run the full env hygiene audit (environment.md §10)
sudo grep '^APP_DEBUG=' /var/www/rcerp_v2/laravel/.env
# Expected: APP_DEBUG=false

sudo grep '^APP_KEY=' /var/www/rcerp_v2/laravel/.env
# Expected: NOT base64:2cn8GO0r6OSab790IzGrvPj+siQVQDNsjsWbkzNxRC4=

sudo grep -E '(rcerp_secret|archive_reader_secret|archive_root_secret)' /var/www/rcerp_v2/laravel/.env
# Expected: empty output

cd /var/www/rcerp_v2 && git ls-files laravel/.env
# Expected: empty (not in git)

sudo stat -c '%U:%G %a' /var/www/rcerp_v2/laravel/.env
# Expected: rcerp:rcerp 640

# Confirm Laravel can read the .env
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan tinker --execute="echo config('app.url');"
# Expected: the APP_URL value
```

### 9.3 Failure handling

- 2.4 fails → Run `php artisan key:generate` (see `environment.md` §11.1 for rotation
  procedure if data already exists).
- 2.13 fails → `.env` was accidentally committed. Remove from git history (use `git
  filter-repo` or BFG Repo-Cleaner), rotate all secrets, force-push.

---

## 10. §3 — Schema + migrations (DBA)

### 10.1 Checklist

- [ ] **3.1** PostgreSQL 16 installed + running.
- [ ] **3.2** `pg_cron` extension installed (requires `shared_preload_libraries`).
- [ ] **3.3** `pg_partman` extension installed.
- [ ] **3.4** `rcerp_app` role + `rcerp` database created.
- [ ] **3.5** `postgresql.conf` tuned per `vps-bdix-deployment.md` §7.3.
- [ ] **3.6** `pg_hba.conf` allows `rcerp_app` from `127.0.0.1` only (md5 auth).
- [ ] **3.7** All migrations ran (`php artisan migrate:status` shows 0 Pending).
- [ ] **3.8** The `archive` schema exists (created by migration
  `2026_08_15_000004`).
- [ ] **3.9** All 7 pg_cron jobs scheduled (use `v_pg_cron_jobs` view).
- [ ] **3.10** `partition_performance_measurements` table exists (created by Phase 8.8
  migration).

### 10.2 Verification commands

```bash
# 3.1 + 3.2 + 3.3
psql -U rcerp_app -d rcerp -c "SELECT extname, extversion FROM pg_extension WHERE extname IN ('pg_cron', 'pg_partman');"
# Expected: 2 rows

# 3.4
psql -U rcerp_app -d rcerp -c "SELECT current_database(), current_user;"
# Expected: rcerp, rcerp_app

# 3.7
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan migrate:status | grep -c Pending
# Expected: 0

# 3.7 (full status)
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan migrate:status | tail -5
# Expected: all rows show "Ran"

# 3.8
psql -U rcerp_app -d rcerp -c "SELECT schema_name FROM information_schema.schemata WHERE schema_name = 'archive';"
# Expected: 1 row

# 3.9
psql -U rcerp_app -d rcerp -c "SELECT jobname, schedule, active FROM cron.job ORDER BY jobname;"
# Expected: 7 rows (cancel-stale-drafts, refresh-report-views, refresh-rb-checks,
#           purge-old-notifications, analyze-high-write-tables, pg_partman-maintenance,
#           partition-consolidation)

# 3.10
psql -U rcerp_app -d rcerp -c "SELECT COUNT(*) FROM partition_performance_measurements;"
# Expected: 0 (or >0 if perf has been measured)
```

### 10.3 Failure handling

- 3.2 fails → Add `pg_cron` to `shared_preload_libraries` in `postgresql.conf`, restart
  PG, retry `CREATE EXTENSION pg_cron`.
- 3.7 fails → Check the failing migration's error. Common causes: missing extension,
  conflicting object, partial previous run. See `../database/migrations-conventions.md`
  §11 for rollback/fix procedures.
- 3.9 fails → Some pg_cron jobs are scheduled by specific migrations. Check which
  migration scheduled which job (see `cron-scheduled-jobs.md` §9) and re-run the
  migration.

---

## 11. §4 — Master data + admin user (DBA)

### 11.1 Checklist

- [ ] **4.1** Chart of accounts seeded (`php artisan chart:validate` passes).
- [ ] **4.2** Branches + warehouses created.
- [ ] **4.3** Master data migrated from legacy (if applicable): `migrate:master-data
  --execute` ran successfully.
- [ ] **4.4** Employees + users migrated (if applicable): `migrate:legacy-employees
  --execute` ran successfully.
- [ ] **4.5** Admin user exists + password changed from `password123`.
- [ ] **4.6** Admin user's `credential_version = 1` (or higher if rotated).
- [ ] **4.7** Default `NORMAL` system policy exists in `system_policies`.
- [ ] **4.8** RBAC roles configured per `config/roles.php`.
- [ ] **4.9** At least one user per role (admin, manager, salesman, accountant) for smoke
  tests.

### 11.2 Verification commands

```bash
# 4.1
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan chart:validate
# Expected: exit code 0, "All checks passed"

# 4.2
psql -U rcerp_app -d rcerp -c "SELECT COUNT(*) FROM branches; SELECT COUNT(*) FROM warehouses;"
# Expected: >= 1 each

# 4.5
psql -U rcerp_app -d rcerp -c "SELECT username, is_active, credential_version FROM users WHERE username = 'admin';"
# Expected: 1 row, is_active = true, credential_version >= 1

# Confirm admin password is NOT 'password123' (try logging in via tinker)
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan tinker --execute="
  \$u = \App\Models\User::where('username', 'admin')->first();
  echo password_verify('password123', \$u->password_hash) ? 'INSECURE: still password123' : 'OK: password changed';
"
# Expected: OK: password changed

# 4.7
psql -U rcerp_app -d rcerp -c "SELECT mode, is_active FROM system_policies LIMIT 1;"
# Expected: 1 row, mode = NORMAL

# 4.9
psql -U rcerp_app -d rcerp -c "
  SELECT u.username, e.role
  FROM users u JOIN employees e ON u.employee_id = e.id
  WHERE u.is_active = true
  ORDER BY e.role;
"
# Expected: users across multiple roles
```

### 11.3 Failure handling

- 4.5 fails → Change the admin password:
  ```bash
  sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan tinker --execute="
    \$u = \App\Models\User::where('username', 'admin')->first();
    \$u->password_hash = password_hash('NEW_STRONG_PASSWORD', PASSWORD_BCRYPT);
    \$u->credential_version++;
    \$u->save();
  "
  ```

---

## 12. §5 — Nginx + TLS (DevOps)

### 12.1 Checklist

- [ ] **5.1** Nginx installed + running.
- [ ] **5.2** RC_ERP config at `/etc/nginx/sites-available/rcerp` (from
  `docs/migration/nginx.conf.example`).
- [ ] **5.3** Symlinked to `/etc/nginx/sites-enabled/rcerp`.
- [ ] **5.4** Default site disabled (`/etc/nginx/sites-enabled/default` removed).
- [ ] **5.5** `server_name` matches `APP_URL`.
- [ ] **5.6** `nginx -t` passes.
- [ ] **5.7** TLS certificate obtained via certbot.
- [ ] **5.8** HTTP → HTTPS redirect active.
- [ ] **5.9** `/sse/` location has `fastcgi_buffering off` + `X-Accel-Buffering no`.
- [ ] **5.10** Sensitive files (`.env`, `.git/`) return 403.
- [ ] **5.11** Static assets served with `Cache-Control: public, immutable`.
- [ ] **5.12** `client_max_body_size 50M` (or matches PHP's `upload_max_filesize`).

### 12.2 Verification commands

```bash
# 5.1 + 5.6
sudo systemctl status nginx
sudo nginx -t
# Expected: active (running) + "syntax is ok" + "test is successful"

# 5.7 + 5.8
curl -sI http://erp.example.com
# Expected: HTTP/1.1 301 Moved Permanently, Location: https://erp.example.com/

curl -sI https://erp.example.com
# Expected: HTTP/2 200 (or 302 redirect to /login)

# 5.9
sudo grep -A 5 'location /sse/' /etc/nginx/sites-enabled/rcerp | grep -E '(fastcgi_buffering|X-Accel-Buffering)'
# Expected: fastcgi_buffering off; + X-Accel-Buffering no

# 5.10
curl -sI https://erp.example.com/.env
# Expected: HTTP/2 403 Forbidden

curl -sI https://erp.example.com/.git/config
# Expected: HTTP/2 403 Forbidden

# 5.11
curl -sI https://erp.example.com/build/assets/app-abc123.js | grep -i cache-control
# Expected: Cache-Control: public, immutable

# 5.12
sudo grep client_max_body_size /etc/nginx/sites-enabled/rcerp
# Expected: client_max_body_size 50M;
```

### 12.3 Failure handling

- 5.6 fails → `nginx -t` prints the syntax error. Fix the config + retry.
- 5.7 fails → DNS not propagated, or port 80 not open. Check `ufw status` + DNS.
- 5.8 fails → certbot didn't add the redirect. Run `certbot --nginx --redirect` again.

---

## 13. §6 — PHP-FPM + workers (DevOps)

### 13.1 Checklist

- [ ] **6.1** PHP 8.4-FPM installed + running.
- [ ] **6.2** PHP 8.4 extensions installed (pdo_pgsql, redis, gd, bcmath, etc.).
- [ ] **6.3** `php.ini` configured per `docker/php/php.ini` (memory_limit 512M,
  max_execution_time 120, opcache enabled).
- [ ] **6.4** OPcache `validate_timestamps = 0` (production — no per-request file stat).
- [ ] **6.5** PHP-FPM runs as `rcerp` user (not `www-data`).
- [ ] **6.6** Supervisor installed + running.
- [ ] **6.7** Queue worker config at `/etc/supervisor/conf.d/rcerp-queue-worker.conf`.
- [ ] **6.8** Listen-notify worker config at `/etc/supervisor/conf.d/rcerp-listen-notify.conf`.
- [ ] **6.9** Both workers RUNNING (`sudo supervisorctl status`).
- [ ] **6.10** Queue worker processed at least one test job.

### 13.2 Verification commands

```bash
# 6.1
sudo systemctl status php8.4-fpm
# Expected: active (running)

# 6.2
php -m | grep -E '^(pdo_pgsql|redis|gd|bcmath|opcache)$'
# Expected: all 5 extensions listed

# 6.5
sudo grep '^user = ' /etc/php/8.4/fpm/pool.d/www.conf
# Expected: user = rcerp

# 6.6
sudo systemctl status supervisor
# Expected: active (running)

# 6.9
sudo supervisorctl status
# Expected: rcerp-queue-worker:rcerp-queue-worker_00  RUNNING
#           rcerp-queue-worker:rcerp-queue-worker_01  RUNNING
#           rcerp-listen-notify                       RUNNING

# 6.10 — dispatch a test job
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan tinker --execute="
  \Illuminate\Support\Facades\Queue::push(new class implements \Illuminate\Contracts\Queue\ShouldQueue {
    public function handle() { \Log::info('Go-live test job processed'); }
  });
"
sleep 2
sudo tail -5 /var/www/rcerp_v2/laravel/storage/logs/queue-worker.log /var/www/rcerp_v2/laravel/storage/logs/laravel.log | grep 'Go-live test job'
# Expected: "Go-live test job processed"
```

### 13.3 Failure handling

- 6.9 fails → `sudo supervisorctl reread && sudo supervisorctl update` to pick up config
  changes. Then `sudo supervisorctl start rcerp-*:*`.
- 6.10 fails → Check `storage/logs/queue-worker.log` for errors. Common: Redis
  unreachable, `.env` wrong, code error in the job.

---

## 14. §7 — Scheduler (DevOps + DBA)

### 14.1 Checklist

- [ ] **7.1** Cron entry installed for `rcerp` user (`* * * * * cd /var/www/rcerp_v2/laravel
  && php artisan schedule:run`).
- [ ] **7.2** `php artisan schedule:list` shows all 6 Laravel jobs.
- [ ] **7.3** pg_cron has all 7 jobs scheduled (use `v_pg_cron_jobs` view).
- [ ] **7.4** pg_cron BGW is running (check `pg_stat_activity`).
- [ ] **7.5** At least one job has run successfully (check `cron.job_run_details`).
- [ ] **7.6** Both supervisor workers running (re-verify from §6.9).
- [ ] **7.7** The Laravel scheduler is firing every minute (check
  `storage/logs/laravel.log` for schedule entries).

### 14.2 Verification commands

```bash
# 7.1
sudo crontab -u rcerp -l | grep schedule:run
# Expected: * * * * * cd /var/www/rcerp_v2/laravel && php artisan schedule:run >> /dev/null 2>&1

# 7.2
sudo -u rcerp php /var/www/rcerp_v2/laravel/artisan schedule:list
# Expected: 6 jobs listed with next run times

# 7.3 + 7.4 + 7.5
psql -U rcerp_app -d rcerp -c "SELECT jobname, schedule, active, last_status, last_start FROM v_pg_cron_jobs ORDER BY jobname;"
# Expected: 7 rows, all active = true, last_status = 'succeeded' (for at least the
#           every-5-min jobs)

# 7.6 (re-verify)
sudo supervisorctl status
# Expected: all RUNNING

# 7.7
sudo tail -100 /var/www/rcerp_v2/laravel/storage/logs/laravel.log | grep -i schedule
# Expected: schedule entries every minute
```

### 14.3 Failure handling

- 7.1 fails → `sudo crontab -u rcerp -e` + add the cron entry.
- 7.3 fails → Some pg_cron jobs are scheduled by migrations. Check which migration
  scheduled which job (see `cron-scheduled-jobs.md` §9) and re-run the migration.
- 7.5 fails (all jobs show `failed`) → Check `cron.job_run_details.return_message` for
  the error. Common: function doesn't exist (migration didn't run), permission denied
  (archive_reader grants).

---

## 15. §8 — Backup + disaster recovery (DevOps)

### 15.1 Checklist

- [ ] **8.1** Backup script at `/usr/local/bin/rcerp-backup.sh`.
- [ ] **8.2** Script executable (`chmod +x`).
- [ ] **8.3** Cron entry installed (`0 1 * * * /usr/local/bin/rcerp-backup.sh`).
- [ ] **8.4** Backup directory exists (`/var/backups/rcerp/`).
- [ ] **8.5** At least one backup has run successfully (`.dump` file exists).
- [ ] **8.6** Off-site rsync configured (backup server reachable via SSH).
- [ ] **8.7** Off-site copy verified (file exists on backup server).
- [ ] **8.8** Restore procedure documented (see §12 rollback plan).
- [ ] **8.9** Restore procedure tested (on a staging VPS or local Docker).

### 15.2 Verification commands

```bash
# 8.1 + 8.2
ls -la /usr/local/bin/rcerp-backup.sh
# Expected: -rwxr-xr-x (executable)

# 8.3
sudo crontab -l | grep rcerp-backup
# Expected: 0 1 * * * /usr/local/bin/rcerp-backup.sh

# 8.4 + 8.5
ls -la /var/backups/rcerp/
# Expected: at least one rcerp_YYYYMMDD_HHMMSS.dump file

# 8.6 + 8.7
ssh backup@example.com "ls -la /backups/rcerp/"
# Expected: matching .dump file

# 8.9 (run on staging only — destructive)
# pg_restore -d rcerp_staging -c -C /var/backups/rcerp/rcerp_LATEST.dump
```

### 15.3 Failure handling

- 8.5 fails → Run the backup script manually + check the log:
  ```bash
  sudo /usr/local/bin/rcerp-backup.sh
  sudo tail /var/backups/rcerp/backup.log
  ```
- 8.7 fails → SSH key not set up. Generate a keypair + add the public key to the backup
  server's `authorized_keys`.

---

## 16. §9 — Verification suites (QA + DBA + Accountant)

> **MANDATORY.** All must pass with exit code 0 before go-live.

### 16.1 §9.1 — Post-deploy verification suite (QA)

- [ ] **9.1.1** `journal:replay-verify` passes (GL integrity, Dr=Cr, no orphans).
- [ ] **9.1.2** `stock:replay-verify` passes (stock ledger integrity).
- [ ] **9.1.3** `chart:validate` passes (CoA valid).
- [ ] **9.1.4** `sales:pen-test` passes (RBAC + branch isolation enforced).
- [ ] **9.1.5** `migrate:status` shows 0 Pending.

### 16.2 Verification commands

```bash
cd /var/www/rcerp_v2/laravel

# 9.1.1
sudo -u rcerp php artisan journal:replay-verify
# Expected: exit code 0, "All checks passed"

# 9.1.2
sudo -u rcerp php artisan stock:replay-verify
# Expected: exit code 0, "No drift detected"

# 9.1.3
sudo -u rcerp php artisan chart:validate
# Expected: exit code 0, "All checks passed"

# 9.1.4
sudo -u rcerp php artisan sales:pen-test
# Expected: exit code 0, all role tests pass

# 9.1.5
sudo -u rcerp php artisan migrate:status | grep -c Pending
# Expected: 0
```

### 16.3 §9.2 — Period-close verification suite (Accountant)

- [ ] **9.2.1** `journal:replay-verify` passes (re-run from §9.1.1).
- [ ] **9.2.2** `reversal:verify` passes (all reversals net to zero).
- [ ] **9.2.3** `subledger:reconcile` passes (AR/AP/Employee vs GL control).
- [ ] **9.2.4** `reconcile:running-balance --top=20` shows no drift.
- [ ] **9.2.5** `stock:replay-verify` passes (re-run from §9.1.2).
- [ ] **9.2.6** `journal:manual-verify --count=10` — accountant reviewed 10 sample
  entries + signed off.
- [ ] **9.2.7** `stock:manual-verify --count=10` — accountant reviewed 10 sample
  products' avg-cost + signed off.

### 16.4 Verification commands

```bash
cd /var/www/rcerp_v2/laravel

# 9.2.2
sudo -u rcerp php artisan reversal:verify
# Expected: exit code 0, "All reversals net to zero"

# 9.2.3
sudo -u rcerp php artisan subledger:reconcile
# Expected: exit code 0, "All sub-ledgers reconciled"

# 9.2.4
sudo -u rcerp php artisan reconcile:running-balance --top=20
# Expected: 0 drift items (or documented acceptable drift)

# 9.2.6 — print 10 sample entries for accountant review
sudo -u rcerp php artisan journal:manual-verify --count=10 | tee /tmp/journal-manual-verify.log
# Expected: 10 entries printed; accountant reviews + initials the log

# 9.2.7 — print 10 sample products for avg-cost review
sudo -u rcerp php artisan stock:manual-verify --count=10 | tee /tmp/stock-manual-verify.log
# Expected: 10 products printed; accountant reviews + initials the log
```

### 16.5 §9.3 — Partitioning ops suite (DBA)

- [ ] **9.3.1** `partition:verify-join` passes (partition-wise joins working).
- [ ] **9.3.2** `partition:measure-perf` runs + all 10 queries meet targets.
- [ ] **9.3.3** `pg_partman` maintenance job ran successfully (check `v_pg_cron_jobs`).

### 16.6 Verification commands

```bash
cd /var/www/rcerp_v2/laravel

# 9.3.1
sudo -u rcerp php artisan partition:verify-join
# Expected: exit code 0, "Partition-wise join node found"

# 9.3.2
sudo -u rcerp php artisan partition:measure-perf
# Expected: all 10 queries under target, results persisted

# 9.3.3
psql -U rcerp_app -d rcerp -c "SELECT jobname, last_status, last_start FROM v_pg_cron_jobs WHERE jobname = 'pg_partman-maintenance';"
# Expected: last_status = 'succeeded', last_start within 24 hours
```

### 16.7 §9.4 — Archival suite (DBA)

- [ ] **9.4.1** `partition:export-parquet --dry-run` lists expected exports.
- [ ] **9.4.2** The `archive` schema exists + has expected tables.
- [ ] **9.4.3** `partition-consolidation` pg_cron job ran successfully (or is scheduled
  for the next quarter).

### 16.8 Verification commands

```bash
cd /var/www/rcerp_v2/laravel

# 9.4.1
sudo -u rcerp php artisan partition:export-parquet --dry-run
# Expected: list of partitions that would be exported (may be empty if archive is empty)

# 9.4.2
psql -U rcerp_app -d rcerp -c "SELECT table_name FROM information_schema.tables WHERE table_schema = 'archive' ORDER BY table_name;"
# Expected: list of archived partition tables (may be empty on a fresh install)

# 9.4.3
psql -U rcerp_app -d rcerp -c "SELECT jobname, schedule, active FROM v_pg_cron_jobs WHERE jobname = 'partition-consolidation';"
# Expected: 1 row, active = true
```

### 16.9 Failure handling

- 9.1.1 fails → Investigate the GL drift. Common causes: partial rollback, missing
  journal_line, FK trigger bug. Use `journal:replay-verify --fix-orphans` ONLY after
  root-causing.
- 9.1.2 fails → Investigate stock drift. Use `stock:reconcile-drift --dry-run` to see
  the drift per (warehouse, product). Fix via a `stock:adjustment` (NOT by editing
  `warehouse_stock` directly).
- 9.2.4 fails → `reconcile:running-balance --fix` overwrites stored balances. Use ONLY
  after root-causing the drift.
- 9.3.2 fails (query over target) → Check the PG query plan (`EXPLAIN ANALYZE`).
  Common: partition pruning not working (missing constraint), stale stats (run
  `analyze-high-write-tables` manually), missing index.

---

## 17. §10 — Security audit (DevOps + Release manager)

### 17.1 Checklist

- [ ] **10.1** UFW firewall enabled (only 22, 80, 443 open).
- [ ] **10.2** Root SSH login disabled.
- [ ] **10.3** Password SSH auth disabled (key-only).
- [ ] **10.4** fail2ban installed + protecting SSH.
- [ ] **10.5** All default passwords changed (admin user, DB, Redis, archive).
- [ ] **10.6** No secrets in git (re-verify from §2.13).
- [ ] **10.7** `APP_DEBUG=false` (re-verify from §2.3).
- [ ] **10.8** `ARCHIVE_DB_USERNAME` has only SELECT grants on the legacy MySQL.
- [ ] **10.9** Audit trail enabled (`Auditable` trait on all business models).
- [ ] **10.10** RBAC matrix verified (per `../security/rbac-roles-permissions.md`).

### 17.2 Verification commands

```bash
# 10.1
sudo ufw status
# Expected: 22, 80, 443 ALLOW; everything else DENY

# 10.2 + 10.3
sudo grep -E '^(PermitRootLogin|PasswordAuthentication)' /etc/ssh/sshd_config
# Expected: PermitRootLogin no, PasswordAuthentication no

# 10.4
sudo fail2ban-client status sshd
# Expected: jail list includes sshd

# 10.8 (if MySQL archive is running)
mysql -h 127.0.0.1 -u archive_reader -p rcerp_legacy -e "SHOW GRANTS;"
# Expected: GRANT SELECT only (no INSERT/UPDATE/DELETE)

# 10.9 — confirm at least one audited model has an audit log entry
psql -U rcerp_app -d rcerp -c "SELECT COUNT(*) FROM user_audit_log;"
# Expected: >= 1 (the admin user creation should have logged)
```

### 17.3 Failure handling

- 10.8 fails → `REVOKE ALL ON rcerp_legacy.* FROM archive_reader; GRANT SELECT ON
  rcerp_legacy.* TO archive_reader;` (see `../archive/legacy-read-only.md` §12 E-1).

---

## 18. §11 — Smoke tests (QA)

> A human MUST walk through these. Automated tests don't count.

### 18.1 Checklist

- [ ] **11.1** Login as admin → dashboard loads, all menu items visible.
- [ ] **11.2** Login as salesman → only sales menu visible, no admin routes accessible.
- [ ] **11.3** Create a sales invoice (draft) → save → finalize → verify GL entry posted.
- [ ] **11.4** Reverse the invoice → verify reversal GL entry posted.
- [ ] **11.5** View the customer ledger → balance updated correctly.
- [ ] **11.6** Receive a purchase order → receive goods → verify stock + AP updated.
- [ ] **11.7** Run a stock take → verify warehouse_stock matches counted qty.
- [ ] **11.8** View the Trial Balance report → loads + balances.
- [ ] **11.9** View the Balance Sheet report → loads + balances.
- [ ] **11.10** Open the realtime notifications panel → SSE connects + receives a test
  notification.
- [ ] **11.11** Test the legacy session bridge (if applicable): log into the legacy app,
  refresh Laravel → still logged in.
- [ ] **11.12** Upload a product image → file saved to `storage/app/public/`.
- [ ] **11.13** Trigger a 404 + 500 → custom error pages render (not stack traces).
- [ ] **11.14** Test on mobile (responsive layout) → all key flows work.

### 18.2 Failure handling

- 11.3 fails → Check `storage/logs/laravel.log` for the error. Common: RBAC
  misconfiguration, missing service, FK constraint.
- 11.10 fails → Check the listen-notify worker log + the SSE Nginx config (§5.9).
- 11.11 fails → Check Redis DB 1 has sessions + the `LEGACY_SESSION_COOKIE` matches.
- 11.13 fails → `APP_DEBUG=true` (stack traces leak) OR the error template is missing.

---

## 19. §12 — Rollback plan (Release manager)

### 19.1 Checklist

- [ ] **12.1** The previous stable version's git SHA documented.
- [ ] **12.2** The pre-deploy DB backup location + filename documented.
- [ ] **12.3** The rollback procedure documented (see §12.2 below).
- [ ] **12.4** The 24-hour rollback window announced to the release team.
- [ ] **12.5** A "rollback decision tree" documented (who decides + when).

### 19.2 Rollback procedure

```bash
# 1. SSH in as rcerp
ssh rcerp@erp.example.com
cd /var/www/rcerp_v2

# 2. Roll back the code
git checkout <PREVIOUS_STABLE_SHA>

# 3. Reinstall dependencies
cd laravel
composer install --no-dev --optimize-autoloader
npm install
npm run build:css
npm run build

# 4. Roll back the DB (ONLY if migrations ran + can't be reversed via migrate:rollback)
#    WARNING: this loses all data entered since the backup.
sudo -u postgres pg_restore -d rcerp -c -C /var/backups/rcerp/rcerp_PRE_DEPLOY.dump

# 5. If migrations are reversible, use migrate:rollback instead of pg_restore:
#    php artisan migrate:rollback --step=N  # where N = number of batches to roll back

# 6. Clear + rebuild caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Restart PHP-FPM + workers
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart rcerp-queue-worker:*
sudo supervisorctl restart rcerp-listen-notify

# 8. Verify
curl -sI https://erp.example.com/up
php artisan journal:replay-verify
php artisan stock:replay-verify

# 9. Announce rollback to users
```

### 19.3 Rollback decision tree

| Symptom | Action |
|---|---|
| Single user reports a UI bug | Investigate; do NOT rollback. Hotfix forward. |
| Multiple users report the same bug | Investigate; if blocking, rollback within 24h. |
| GL drift detected (`journal:replay-verify` fails) | **Rollback immediately.** Data integrity > uptime. |
| Stock drift detected (`stock:replay-verify` fails) | **Rollback immediately.** |
| Security incident (suspected breach) | **Rollback immediately** + rotate all secrets. |
| Performance regression (>5s page loads) | Investigate; rollback if users can't work. |
| 500 errors on critical paths (login, sales) | **Rollback immediately.** |

### 19.4 After rollback

- [ ] Post-mortem written (what broke, why, how to prevent).
- [ ] Fix developed + tested on staging.
- [ ] Re-deployed with this checklist re-run.

---

## 20. §13 — Sign-off table

> All 5 sign-offs MUST be collected before announcing go-live to users.

| Role | Name | Date | Signature | Area verified |
|---|---|---|---|---|
| Release manager | _______________ | _____ | _____ | §1, §13 |
| DevOps engineer | _______________ | _____ | _____ | §2, §5, §6, §7, §8, §10 |
| DBA | _______________ | _____ | _____ | §3, §4, §9.3, §9.4 |
| QA lead | _______________ | _____ | _____ | §9.1, §11 |
| Accountant | _______________ | _____ | _____ | §9.2, §11.4–11.5 |

### 20.1 Storage

The completed sign-off table is stored alongside the release tag at
`docs/releases/RCERP_v<MAJOR>.<MINOR>.<PATCH>.md`. The release tag is `git tag -a
v<MAJOR>.<MINOR>.<PATCH> -m "Go-live sign-off: <date>"`.

### 20.2 Acceptable risk documentation

If any checkbox can't be checked but the business owner approves proceeding:

| Checkbox | Risk accepted | Business owner approval | Date |
|---|---|---|---|
| _____ | _____ | _____ | _____ |

> This MUST be filled in for every unchecked box. Silent "we'll fix it later" is not
> acceptable (R-10).

---

## 21. Known edge cases

- **E-1 — The checklist takes 4–8 hours.** Don't underestimate. Schedule the go-live
  for Friday evening Dhaka so the team has the weekend to monitor.
- **E-2 — `journal:replay-verify` is slow on large DBs.** On a 5-year DB with 1M
  transactions, it can take 30+ minutes. Plan accordingly.
- **E-3 — The accountant sign-off (§9.2) is the bottleneck.** The accountant must
  visually review 10 sample entries + 10 sample products. This takes 1–2 hours of
  focused accountant time. Schedule it in advance.
- **E-4 — pg_cron jobs may not have run yet on a fresh VPS.** If the VPS was provisioned
  the same day, the daily jobs (02:00, 03:00, 04:00) may not have fired yet. Either
  wait, or trigger them manually (`SELECT cancel_stale_sales_drafts(14, 200, NULL);`).
- **E-5 — The legacy session bridge may drift.** If the legacy PHP app + Laravel have
  been running side-by-side, Redis DB 1 may have stale sessions. Clear it before
  go-live: `redis-cli -n 1 FLUSHDB` (warning: logs out all legacy users).
- **E-6 — The BDIX latency may vary during the day.** Test §1.3 at peak hours (11:00 +
  16:00 Dhaka) not just off-peak.
- **E-7 — Certbot rate limits.** If the go-live is delayed + re-attempted multiple
  times, certbot may refuse to issue certificates. Use `--staging` for testing.
- **E-8 — The 24-hour rollback window assumes no data entry.** If users enter data
  during the first 24 hours, rollback loses it. Mitigation: announce "read-only for the
  first 24 hours" or schedule go-live for a low-traffic weekend.
- **E-9 — `php artisan config:cache` caches env values.** If you edit `.env` after
  `config:cache` (e.g. to fix a typo), you MUST re-run `config:clear` + `config:cache`.
  Forgetting this is the #1 cause of "I changed .env but the app still uses the old
  value" during go-live.
- **E-10 — The smoke tests (§11) are only as good as the tester.** A junior QA may miss
  subtle bugs. Have a senior engineer + an accountant walk through §11 together.

---

## 22. Future improvements

- **F-1 — Automate §9 verification suites.** A `php artisan verify:all` command that
  runs §9.1 + §9.2 + §9.3 + §9.4 in sequence + prints a summary. Currently each command
  is run separately.
- **F-2 — Add a `php artisan go-live:check` command.** Would programmatically check
  §1–§8 + §10 (the non-suite, non-smoke sections) + print a pass/fail report.
- **F-3 — Add automated smoke tests (Selenium / Playwright).** Currently §11 is manual.
  Automated browser tests would catch UI regressions without human time.
- **F-4 — Add a "go-live dashboard" in the admin UI.** Would show the checklist
  progress + sign-off status. Currently it's a markdown file.
- **F-5 — Add a pre-go-live staging environment.** Currently the checklist runs on
  production. A staging VPS would allow running the checklist without production risk.
- **F-6 — Add a canary deploy mechanism.** Route 10% of traffic to the new version
  before full go-live. Currently it's all-or-nothing.
- **F-7 — Add a "go-live observability" dashboard.** Track 5xx rate, response time,
  queue depth, PG connections for the first 24 hours. Currently monitoring is manual.
- **F-8 — Document the per-feature go-live checklists.** `docs/STOCK_TAKE_GO_LIVE_CHECKLIST.md`
  is the template. Each major feature should have its own supplement.
- **F-9 — Add a "rollback drill" procedure.** Quarterly practice rollback on staging to
  verify the procedure works + the team knows it. Currently rollback is untested.
- **F-10 — Add a "post-go-live review" template.** 1 week after go-live, the team
  reviews what went well + what didn't. Currently ad-hoc.

---

## 23. Verification commands (meta)

> These verify the checklist itself was run correctly.

```bash
# 1. Confirm all 5 sign-offs are present in the release tag
git tag -l --format='%(refname:short): %(contents)' v*.*.* | head -5
# Expected: each tag message includes "Go-live sign-off: <date>"

# 2. Confirm the release notes file exists
ls docs/releases/RCERP_v*.*.*.md
# Expected: at least one file per release

# 3. Confirm the sign-off table is filled in
grep -c '___' docs/releases/RCERP_v*.*.*.md
# Expected: 0 (no blank signatures)

# 4. Confirm acceptable-risk documentation (if any)
grep -A 1 'Risk accepted' docs/releases/RCERP_v*.*.*.md
# Expected: every unchecked box has a documented risk + business owner approval

# 5. Confirm the post-deploy verification suite was run
grep -l 'journal:replay-verify' /tmp/journal-verify-*.log
# Expected: a log file per release

# 6. Confirm the accountant's manual review logs exist
ls /tmp/journal-manual-verify.log /tmp/stock-manual-verify.log
# Expected: both files exist + are initialed by the accountant

# 7. Confirm the rollback plan is documented
grep -A 3 'Rollback procedure' docs/releases/RCERP_v*.*.*.md
# Expected: the procedure + the backup filename
```

---

## 24. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Pre-flight | §8 | `vps-bdix-deployment.md` §8.1 |
| Env config audit | §9 | `environment.md` §10 |
| Schema + migrations | §10 | `../database/migrations-conventions.md` |
| Master data + admin user | §11 | `../database/etl-legacy-migration.md` |
| Nginx + TLS | §12 | `nginx-config.md` §12 |
| PHP-FPM + workers | §13 | `cron-scheduled-jobs.md` §10 (supervisor workers) |
| Scheduler | §14 | `cron-scheduled-jobs.md` §14 |
| Backup + DR | §15 | `vps-bdix-deployment.md` §11 |
| Post-deploy verification suite | §16.1 | `artisan-commands.md` §9.1 |
| Period-close verification suite | §16.3 | `artisan-commands.md` §9.2 |
| Partitioning ops suite | §16.5 | `artisan-commands.md` §9.3 |
| Archival suite | §16.7 | `artisan-commands.md` §9.4 |
| Security audit | §17 | `../security/system-policy-compliance.md` |
| Smoke tests | §18 | (none — manual) |
| Rollback plan | §19 | `vps-bdix-deployment.md` §11.2 (DR restore) |
| Sign-off table | §20 | (none — this is the canonical ref) |

---

*End of `go-live-checklist.md`. This is the verification gate for production go-live. For
the deployment procedures it verifies, see `vps-bdix-deployment.md` (VPS), `docker-setup.md`
(Docker dev), and `environment.md` (env config). For the command catalogues it runs, see
`artisan-commands.md` and `cron-scheduled-jobs.md`.*
