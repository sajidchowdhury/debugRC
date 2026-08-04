# VPS BDIX Deployment

> **Module:** Deployment (Production bare-metal on Bangladesh BDIX VPS)
> **Audience:** DevOps engineers, release managers, system administrators
> **Status:** Draft (pending first real VPS go-live — see §1.3)
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `README.md` (§"What's still needed"),
> `docs/migration/nginx.conf.example`, `docs/SETUP_GUIDE.md`, `environment.md`,
> `nginx-config.md`, `cron-scheduled-jobs.md`, and `../archive/legacy-read-only.md`.

---

## 1. What is it?

RC_ERP_v2 is **targeted for production deployment on a BDIX VPS** — a Virtual Private
Server hosted in Bangladesh and connected to the BDIX (Bangladesh Internet Exchange)
backbone. BDIX hosting gives Bangladesh-local users low-latency access (typically <20ms
vs 200ms+ for Singapore/US hosts) and avoids international bandwidth costs for a
Bangladesh-domestic business.

The production deployment is **bare-metal Ubuntu 22.04 LTS** running PHP-FPM 8.4 +
PostgreSQL 16 + Redis 7 + Nginx directly on the host (NOT Docker — see `docker-setup.md`
R-10 for why the Docker stack is dev-only). The legacy PHP/MySQL application runs
alongside the new Laravel app during the transition window, sharing sessions via Redis
(the legacy session bridge — see `../security/auth-and-sessions.md` §7.4).

This file is the **end-to-end runbook** for provisioning a fresh VPS from an empty Ubuntu
22.04 install to a production-ready RC_ERP_v2 deployment. It covers VPS sizing, OS
hardening, package installation, directory layout, `.env` writing, schema load, master-data
migration, Nginx+PHP-FPM wiring, supervisor-managed workers, TLS via Let's Encrypt, backup
strategy, monitoring, and the cutover sequence.

### 1.1 Why BDIX specifically?

| Concern | BDIX VPS | AWS Singapore | DigitalOcean NYC |
|---|---|---|---|
| Latency from Dhaka | <20ms | 50–80ms | 250–300ms |
| Bandwidth cost (Bangladesh→world) | BDT 2–5/GB (BDIX free) | BDT 8–15/GB | BDT 10–20/GB |
| Compliance (data residency) | Data stays in Bangladesh | Data leaves Bangladesh | Data leaves Bangladesh |
| Payment | bKash/local bank | USD credit card | USD credit card |
| Support timezone | BST (UTC+6) | SGT (UTC+8) | EST (UTC-5) |

For a Bangladesh-domestic ERP serving 50–200 in-country users, BDIX is the clear winner.

### 1.2 What this file is NOT

- Not a Docker deployment guide — see `docker-setup.md` for the dev Docker stack.
- Not a Kubernetes/cloud-native guide — the ERP is a single-instance monolith; K8s is
  overkill.
- Not a multi-region or HA guide — the ERP has no HA setup (single VPS, single PG, single
  Redis). HA is a future roadmap item (see §13 F-1).
- Not a legacy-app decommission guide — see `../archive/legacy-read-only.md` §11.3 for the
  9-step decommission runbook.

### 1.3 Status: pending first real VPS go-live

> ⚠️ **This file is a DRAFT.** As of Phase 19, no real VPS has been provisioned yet. The
> README states "Phase 1 — VPS BDIX Provisioning: ⬜ Pending (manual — needs VPS)". The
> commands below are the **expected procedure** based on the codebase, the
> `docs/migration/nginx.conf.example` reference, and standard Ubuntu 22.04 + Laravel
> deployment practice. They MUST be validated against a real VPS before being marked
> Canonical. The `go-live-checklist.md` file is the verification gate.

---

## 2. Why does it exist?

- **Low latency for Bangladesh users.** The ERP is used by 50–200 in-country staff doing
  high-frequency data entry (sales invoices, stock receipts). 200ms+ latency makes the UI
  feel sluggish; <20ms makes it feel native.
- **Data residency.** Bangladesh financial and accounting data should stay in
  Bangladesh. Hosting on BDIX keeps the data in-country, simplifying compliance.
- **Cost.** BDIX VPS hosting is BDT 1,500–5,000/month for a 4-core/8GB/100GB VPS, vs
  USD 40–80/month for equivalent AWS Singapore. For a Bangladesh SME, this is a 5–10×
  cost saving.
- **Bandwidth.** BDIX-to-BDIX traffic is often free or near-free; international bandwidth
  is metered. A BDIX-hosted ERP serving BDIX-connected offices pays nothing for user
  traffic.
- **Operational simplicity.** A single bare-metal VPS is easier to operate for a small
  team than a Docker/K8s cluster. SSH + apt + systemd is the entire ops surface.
- **Compliance.** Bangladesh Bank (the central bank) has data-residency guidance for
  financial data. BDIX hosting satisfies this; international hosting requires a
  data-export agreement.

---

## 3. When is it used?

- **Initial VPS provisioning** — the full §5 sequence, run once per VPS lifetime.
- **Re-provisioning after a VPS migration** — the same sequence, on a new VPS, after
  restoring the DB backup from the old VPS.
- **Disaster recovery** — the same sequence, on a fresh VPS, after a catastrophic failure
  (see §11 for the backup-restore procedure).
- **NOT for routine deploys.** Routine code deploys use `git pull && php artisan
  migrate --force && php artisan config:cache && sudo systemctl reload php8.4-fpm`. See
  §8.2.

---

## 4. Who uses it?

- **DevOps engineer** — primary audience. Runs the §5 provisioning sequence.
- **System administrator** — manages the VPS (user accounts, SSH keys, firewall).
- **Release manager** — drives the cutover (§9) and signs off the go-live-checklist.
- **DBA** — handles PostgreSQL backup/restore, partition maintenance.
- **AI assistants** — MUST consult this file before suggesting any VPS command. Never
  suggest Docker commands for the VPS (the VPS is bare-metal).

---

## 5. Related modules

- `environment.md` — the `.env` file written in §5.5 below.
- `docker-setup.md` — the dev environment (NOT for production).
- `nginx-config.md` — the Nginx config installed in §5.7 below.
- `artisan-commands.md` — the commands run in §5.6 below.
- `cron-scheduled-jobs.md` — the supervisor + cron config in §5.8 below.
- `go-live-checklist.md` — the verification gate after §5 completes.
- `../security/auth-and-sessions.md` §7.4 — the legacy session bridge (Redis DB 1).
- `../archive/legacy-read-only.md` — the legacy MySQL archive (§5.9 below).

---

## 6. Business rules

- **R-1 — Ubuntu 22.04 LTS only.** Not 20.04 (PHP 8.4 not in default repos), not 24.04
  (untested with the ERP's PHP extensions). 22.04 has PHP 8.3 in ondrej/php PPA; 8.4 is
  available via the same PPA. LTS = 5 years of security updates.
- **R-2 — PHP 8.4 minimum.** The codebase uses PHP 8.4 features (typed constants,
  `#[Override]` attribute, asymmetric visibility in some vendors). PHP 8.3 will work for
  most code but is not tested.
- **R-3 — PostgreSQL 16 minimum.** The schema uses PG 16 features (improved partition
  pruning, `MERGE ... RETURNING`, `ANY_VALUE` aggregate). PG 15 may work for most queries
  but is not tested.
- **R-4 — Redis 7 minimum.** The ACL system (used for the legacy session bridge DB
  isolation) requires Redis 6+; Redis 7 adds `FUNCTION` support (not yet used).
- **R-5 — The VPS MUST have a non-root sudo user.** Never run PHP-FPM or Nginx as root.
  The §5.1 sequence creates a `rcerp` user with sudo.
- **R-6 — UFW firewall: only ports 22, 80, 443 open.** PostgreSQL (5432), Redis (6379),
  and MySQL archive (3306) bind to `127.0.0.1` only — never exposed to the internet.
- **R-7 — TLS is mandatory in production.** Let's Encrypt via certbot. The Nginx config
  redirects HTTP → HTTPS. `SESSION_SECURE_COOKIE=true` in `.env`.
- **R-8 — The legacy PHP app runs alongside Laravel during the transition window.** Both
  apps share the same Nginx server block (Laravel at `/admin/*` + `/api/*`, legacy at
  `/`). See `nginx-config.md` §7 for the dual-root config.
- **R-9 — Backups run daily at 01:00 Dhaka.** `pg_dump` to a local file + off-site
  rsync to a backup VPS or S3-compatible storage. See §11.
- **R-10 — The VPS is single-instance.** No HA, no read replicas, no load balancer. If
  the VPS goes down, the ERP is down. Mitigation: nightly off-site backups + a documented
  disaster-recovery runbook (§11).
- **R-11 — Production `.env` is owned by `rcerp:rcerp` with mode 640.** Not 644 (world-
  readable exposes secrets). The `rcerp` user reads it; PHP-FPM runs as `rcerp` (or
  `www-data` if configured — see §5.4).
- **R-12 — `APP_DEBUG=false` in production. NON-NEGOTIABLE. See `environment.md` R-3.

---

## 7. VPS sizing

### 7.1 Minimum specs (small business, <50 users, <5 years of data)

| Resource | Minimum | Recommended | Notes |
|---|---|---|---|
| CPU | 2 cores | 4 cores | PHP-FPM + PG both CPU-bound on heavy reports |
| RAM | 4 GB | 8 GB | PG `shared_buffers` 1 GB + Redis 256 MB + PHP-FPM 1 GB + OS 1 GB |
| Disk | 50 GB SSD | 100 GB NVMe | PG data + Redis AOF + Nginx logs + 30-day backups |
| Bandwidth | 100 Mbps | 1 Gbps | BDIX peering is the bottleneck, not the VPS NIC |
| OS | Ubuntu 22.04 LTS | same | See R-1 |

### 7.2 Recommended specs (medium business, 50–200 users, 5–10 years of data)

| Resource | Value | Notes |
|---|---|---|
| CPU | 8 cores | Partition pruning + materialized view refreshes are CPU-heavy |
| RAM | 16 GB | PG `shared_buffers` 4 GB + `effective_cache_size` 12 GB |
| Disk | 200 GB NVMe | 10 years of partitioned data + Parquet cold storage |
| Bandwidth | 1 Gbps BDIX | |

### 7.3 PostgreSQL memory tuning (recommended for 16 GB VPS)

```ini
# /etc/postgresql/16/main/postgresql.conf
shared_buffers = 4GB
effective_cache_size = 12GB
maintenance_work_mem = 1GB
work_mem = 64MB
max_connections = 100
shared_preload_libraries = 'pg_cron,pg_partman_bgw'
cron.database_name = 'rcerp'
track_io_timing = on
log_min_duration_statement = 1000  # log queries slower than 1s
autovacuum_max_workers = 6
```

---

## 8. The provisioning sequence

### 8.1 Pre-flight (run BEFORE touching the VPS)

- [ ] VPS provisioned by hosting provider (Ubuntu 22.04 LTS, root access via SSH key).
- [ ] Domain name purchased + DNS A record pointed at the VPS IP.
- [ ] BDIX connectivity verified (ping the VPS from a Dhaka office — should be <20ms).
- [ ] Backup destination ready (a second VPS, S3-compatible storage, or local backup
  server with SSH access).
- [ ] Legacy MySQL dump available (if migrating from the legacy system) — see §9.2.

### 8.2 Step 1 — OS hardening

```bash
# Run as root over SSH

# 1. Update + upgrade all packages
apt update && apt upgrade -y

# 2. Create the rcerp sudo user
adduser rcerp --gecos "" --disabled-password
echo "rcerp:STRONG_PASSWORD" | chpasswd
usermod -aG sudo rcerp

# 3. Copy your SSH public key to the rcerp user
mkdir -p /home/rcerp/.ssh
cp /root/.ssh/authorized_keys /home/rcerp/.ssh/
chown -R rcerp:rcerp /home/rcerp/.ssh
chmod 700 /home/rcerp/.ssh
chmod 600 /home/rcerp/.ssh/authorized_keys

# 4. Disable root SSH login + password auth
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

# 5. Configure UFW firewall
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# 6. Install fail2ban (SSH brute-force protection)
apt install -y fail2ban
systemctl enable fail2ban
systemctl start fail2ban

# 7. Set the timezone
timedatectl set-timezone Asia/Dhaka

# 8. Enable automatic security updates
apt install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
```

### 8.3 Step 2 — Install PHP 8.4 + extensions

```bash
# Add the ondrej/php PPA (PHP 8.4 not in default Ubuntu 22.04)
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update

# Install PHP 8.4 + all extensions the ERP needs
apt install -y \
  php8.4-fpm \
  php8.4-pgsql \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-bcmath \
  php8.4-intl \
  php8.4-zip \
  php8.4-gd \
  php8.4-redis \
  php8.4-mysql \
  php8.4-opcache \
  php8.4-cli

# Configure PHP-FPM
cat > /etc/php/8.4/fpm/conf.d/99-rcerp.ini <<'INI'
memory_limit = 512M
max_execution_time = 120
max_input_time = 120
upload_max_filesize = 50M
post_max_size = 50M
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
log_errors = On
date.timezone = Asia/Dhaka
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
INI

# Configure the www pool to run as rcerp
sed -i 's/^user = www-data/user = rcerp/' /etc/php/8.4/fpm/pool.d/www.conf
sed -i 's/^group = www-data/group = rcerp/' /etc/php/8.4/fpm/pool.d/www.conf

systemctl enable php8.4-fpm
systemctl restart php8.4-fpm
```

### 8.4 Step 3 — Install PostgreSQL 16 + Redis 7

```bash
# PostgreSQL 16 (from PGDG repo)
sh -c 'echo "deb https://apt.postgresql.org/pub/repos/apt jammy-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /etc/apt/trusted.gpg.d/pgdg.gpg
apt update
apt install -y postgresql-16 postgresql-16-pg-partman postgresql-16-pg-cron

# Configure PG
systemctl enable postgresql
systemctl start postgresql

# Create the rcerp_app role + database
sudo -u postgres psql <<'SQL'
CREATE ROLE rcerp_app WITH LOGIN PASSWORD 'STRONG_DB_PASSWORD';
CREATE DATABASE rcerp OWNER rcerp_app;
\c rcerp
CREATE EXTENSION IF NOT EXISTS pg_cron;
CREATE EXTENSION IF NOT EXISTS pg_partman;
SQL

# Edit postgresql.conf (see §7.3 for recommended values)
nano /etc/postgresql/16/main/postgresql.conf

# Edit pg_hba.conf to allow rcerp_app from localhost only
cat >> /etc/postgresql/16/main/pg_hba.conf <<'HBA'
# RC_ERP — local app connection
local   rcerp   rcerp_app                       md5
host    rcerp   rcerp_app   127.0.0.1/32        md5
host    rcerp   rcerp_app   ::1/128             md5
HBA

systemctl restart postgresql

# Redis 7 (from PPA)
add-apt-repository -y ppa:redislabs/redis
apt update
apt install -y redis

# Configure Redis
sed -i 's/^# requirepass .*/requirepass STRONG_REDIS_PASSWORD/' /etc/redis/redis.conf
sed -i 's/^# maxmemory .*/maxmemory 512mb/' /etc/redis/redis.conf
sed -i 's/^# maxmemory-policy .*/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
sed -i 's/^bind 127.0.0.1 -::1/bind 127.0.0.1/' /etc/redis/redis.conf  # IPv4 only

systemctl enable redis-server
systemctl restart redis-server
```

### 8.5 Step 4 — Install Nginx + certbot

```bash
apt install -y nginx certbot python3-certbot-nginx

# Disable the default site
rm -f /etc/nginx/sites-enabled/default

# Create the RC_ERP config (see nginx-config.md §7 for the full file)
nano /etc/nginx/sites-available/rcerp
ln -s /etc/nginx/sites-available/rcerp /etc/nginx/sites-enabled/rcerp

nginx -t && systemctl reload nginx

# Obtain the TLS certificate (after DNS is pointed at the VPS)
certbot --nginx -d erp.example.com -d www.erp.example.com \
  --non-interactive --agree-tos --email admin@example.com --redirect
```

### 8.6 Step 5 — Clone the repo + write `.env`

```bash
# As the rcerp user
sudo -u rcerp -i

# Clone
cd /var/www
git clone https://github.com/sajidchowdhury/debugRC.git rcerp_v2
cd rcerp_v2/laravel

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Install npm dependencies + build assets
npm install
npm run build:css
npm run build

# Write .env (use the production cheatsheet from environment.md §8)
nano .env
chmod 640 .env

# Generate the APP_KEY (if not already set in .env)
php artisan key:generate

# Clear + cache config
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set ownership + permissions
sudo chown -R rcerp:rcerp /var/www/rcerp_v2
sudo chgrp -R www-data /var/www/rcerp_v2/laravel/storage /var/www/rcerp_v2/laravel/bootstrap/cache
sudo chmod -R 775 /var/www/rcerp_v2/laravel/storage /var/www/rcerp_v2/laravel/bootstrap/cache
```

### 8.7 Step 6 — Load schema + run migrations

```bash
cd /var/www/rcerp_v2/laravel

# Run all migrations (creates schema from scratch via the first migration)
php artisan migrate --force

# Verify migration status
php artisan migrate:status | grep -c Ran   # Expected: all migrations
```

### 8.8 Step 7 — Migrate master data (one-time, from legacy MySQL)

> Only run this if migrating from the legacy system. For a fresh install, skip to §8.9.

```bash
# 1. Ensure the legacy MySQL is reachable from the VPS
#    (either start a local MySQL on the VPS, or tunnel to the legacy server)
mysql -h 127.0.0.1 -u archive_reader -p rcerp_legacy -e "SELECT 1;"

# 2. Run the master-data migration (one-time)
php artisan migrate:master-data

# 3. Run the employee migration (one-time)
php artisan migrate:legacy-employees --execute

# 4. Load the chart of accounts seed (if not already loaded)
php artisan chart:seed
```

### 8.9 Step 8 — Create the admin user

```bash
# Use the rcerp:setup command (interactive) or create manually
php artisan rcerp:setup --force --skip-schema --skip-migrate

# Verify
php artisan tinker --execute="echo \App\Models\User::where('username', 'admin')->first()?->username;"
# Expected: admin

# IMPORTANT: change the admin password from the default 'password123'
php artisan tinker --execute="
  \$u = \App\Models\User::where('username', 'admin')->first();
  \$u->password_hash = password_hash('NEW_STRONG_PASSWORD', PASSWORD_BCRYPT);
  \$u->credential_version++;
  \$u->save();
  echo 'admin password updated';
"
```

### 8.10 Step 9 — Configure supervisor for the queue + listen-notify workers

```bash
sudo apt install -y supervisor

# Queue worker
sudo cat > /etc/supervisor/conf.d/rcerp-queue-worker.conf <<'CONF'
[program:rcerp-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/rcerp_v2/laravel/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=rcerp
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/rcerp_v2/laravel/storage/logs/queue-worker.log
stopwaitsecs=3600
CONF

# Listen-notify worker
sudo cat > /etc/supervisor/conf.d/rcerp-listen-notify.conf <<'CONF'
[program:rcerp-listen-notify]
process_name=%(program_name)s
command=php /var/www/rcerp_v2/laravel/artisan listen-notify:worker
autostart=true
autorestart=true
user=rcerp
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/rcerp_v2/laravel/storage/logs/listen-notify.log
stopwaitsecs=10
CONF

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start rcerp-queue-worker:*
sudo supervisorctl start rcerp-listen-notify:*
```

### 8.11 Step 10 — Configure the Laravel scheduler (cron)

```bash
# Add the Laravel scheduler cron entry (runs every minute)
sudo crontab -u rcerp -e
# Add this line:
* * * * * cd /var/www/rcerp_v2/laravel && php artisan schedule:run >> /dev/null 2>&1

# Verify
sudo crontab -u rcerp -l
```

### 8.12 Step 11 — Set up daily backups

```bash
# Create the backup script
sudo cat > /usr/local/bin/rcerp-backup.sh <<'BASH'
#!/bin/bash
set -e
BACKUP_DIR=/var/backups/rcerp
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# 1. PostgreSQL dump (custom format, compressed)
pg_dump -Fc -U rcerp_app -h 127.0.0.1 rcerp > $BACKUP_DIR/rcerp_$DATE.dump

# 2. Keep only the last 7 days locally
find $BACKUP_DIR -name "rcerp_*.dump" -mtime +7 -delete

# 3. Off-site copy (rsync to backup server)
rsync -az $BACKUP_DIR/rcerp_$DATE.dump backup@example.com:/backups/rcerp/

# 4. Log
echo "$(date) - backup OK: rcerp_$DATE.dump" >> $BACKUP_DIR/backup.log
BASH

sudo chmod +x /usr/local/bin/rcerp-backup.sh

# Schedule daily at 01:00 Dhaka
sudo crontab -e
# Add: 0 1 * * * /usr/local/bin/rcerp-backup.sh
```

### 8.13 Step 12 — Verify

Run the `go-live-checklist.md` §3 verification commands. All must pass before declaring
the VPS production-ready.

---

## 9. Routine deploy workflow (after initial provisioning)

```bash
# SSH in as rcerp
ssh rcerp@erp.example.com
cd /var/www/rcerp_v2

# 1. Pull the latest code
git pull origin main

# 2. Install/update dependencies
cd laravel
composer install --no-dev --optimize-autoloader
npm install
npm run build:css
npm run build

# 3. Run migrations
php artisan migrate --force

# 4. Clear + rebuild caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Restart PHP-FPM (picks up new code + opcache reset)
sudo systemctl reload php8.4-fpm

# 6. Restart the queue + listen-notify workers (they cache code in memory)
sudo supervisorctl restart rcerp-queue-worker:*
sudo supervisorctl restart rcerp-listen-notify

# 7. Verify
curl -sI https://erp.example.com/up
# Expected: HTTP/2 200
```

---

## 10. Cutover sequence (legacy → new ERP)

> Only for the initial go-live. Routine deploys don't need this.

1. **T-7 days:** Freeze legacy system changes. Take a final MySQL dump.
2. **T-3 days:** Provision the VPS per §5. Load schema + master data per §8.7 + §8.8.
3. **T-2 days:** Run verification commands (`journal:replay-verify`, `stock:replay-verify`,
   `chart:validate`, `subledger:reconcile`). All must pass.
4. **T-1 day:** Cut over DNS to point at the new VPS. Both apps run side-by-side (legacy
   at `/`, Laravel at `/admin/*`) sharing sessions via Redis.
5. **T-0 (go-live):** Sign off the `go-live-checklist.md`. Announce to users.
6. **T+7 days:** Verify no issues. Schedule the legacy decommission per
   `../archive/legacy-read-only.md` §11.3.
7. **T+30 days:** Decommission the legacy PHP app. Set `ARCHIVE_ENABLED=false` only when
   the PG-only data is sufficient (see `../archive/legacy-read-only.md` R-5).

---

## 11. Backup + disaster recovery

### 11.1 Backup strategy

| What | When | Where | Retention |
|---|---|---|---|
| PostgreSQL dump | Daily 01:00 Dhaka | `/var/backups/rcerp/` + off-site rsync | 7 days local, 30 days off-site |
| `.env` file | On change | Off-site only (encrypted) | Indefinite |
| Nginx + PHP-FPM configs | On change | Off-site (git) | Indefinite (in repo) |
| Code | On every deploy | GitHub + off-site mirror | Indefinite |

### 11.2 Restore procedure (disaster recovery)

```bash
# On a fresh VPS (after §8.1–§8.5):
# 1. Provision the VPS per the normal sequence
# 2. Clone the repo + write .env per §8.6
# 3. DO NOT run migrations (we'll restore from backup)
# 4. Restore the latest backup
sudo -u postgres pg_restore -d rcerp -c -C /var/backups/rcerp/rcerp_LATEST.dump

# 5. Verify
php artisan migrate:status | grep -c Ran
psql -U rcerp_app -d rcerp -c "SELECT COUNT(*) FROM users;"

# 6. Clear + cache config
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart all
```

---

## 12. Known edge cases

- **E-1 — BDIX latency varies by ISP.** Some Bangladesh ISPs route BDIX traffic
  suboptimally. Test latency from each office's ISP before declaring the VPS production-
  ready. If one ISP has >100ms, consider a secondary BDIX-connected VPS or a CDN.
- **E-2 — Power outages in Bangladesh.** Dhaka experiences frequent power cuts. The VPS
  should be on a UPS + generator (most BDIX hosting providers offer this). Local office
  clients should have UPS too.
- **E-3 — `pg_cron` requires `shared_preload_libraries`.** Forgetting this in
  `postgresql.conf` causes the `CREATE EXTENSION pg_cron` to fail with "pg_cron must be
  loaded via shared_preload_libraries". Fix: add `pg_cron` to
  `shared_preload_libraries` + restart PG.
- **E-4 — `pg_partman_bgw` requires `shared_preload_libraries` too.** Same as E-3. The
  Background Worker auto-runs `run_maintenance_proc()` if scheduled — but the scheduler
  still needs the Laravel cron entry as fallback.
- **E-5 — Let's Encrypt rate limits.** 50 certificates per registered domain per week. If
  you reprovision the VPS multiple times, you'll hit the limit. Use the `--staging` flag
  for testing.
- **E-6 — The legacy PHP app needs its own PHP version.** If the VPS runs the legacy app
  alongside Laravel, the legacy PHP (5.6 or 7.x) conflicts with PHP 8.4. Solution: run
  legacy PHP-FPM on a different port (e.g. 9000 for 8.4, 9001 for legacy) and have Nginx
  route accordingly. Or use PHP-FPM pools with different PHP versions (complex).
- **E-7 — `composer install --no-dev` skips PHPUnit.** If you later need to run tests on
  the VPS, run `composer install` (without `--no-dev`) to install dev dependencies.
- **E-8 — The supervisor `numprocs=2` for queue workers is a guess.** Tune based on job
  volume. Too many workers consume RAM; too few cause job backlog. Monitor with `sudo
  supervisorctl status` + `php artisan queue:monitor`.
- **E-9 — `php artisan config:cache` caches env values.** After editing `.env`, you MUST
  run `config:clear` + `config:cache` + restart PHP-FPM. Forgetting this is the #1 cause
  of "I changed .env but the app still uses the old value" tickets.
- **E-10 — The MySQL archive is not started on the VPS by default.** If the
  `/admin/archive` UI is used, the legacy MySQL must be running (either locally on the VPS
  or tunneled from the legacy server). Set `ARCHIVE_ENABLED=false` to gracefully disable
  the archive UI instead of showing connection errors.

---

## 13. Future improvements

- **F-1 — Add a hot-standby PostgreSQL replica.** Currently single-instance (R-10). A
  streaming replica on a second VPS would allow read-scaling + fast failover.
- **F-2 — Add a Prometheus + Grafana monitoring stack.** Track PG connections, Redis
  memory, PHP-FPM workers, queue depth, Nginx 5xx rate. Currently monitoring is manual
  (`htop`, `tail -f laravel.log`).
- **F-3 — Add a Sentry integration for error tracking.** Would catch production errors
  with stack traces + env context. Currently relies on `laravel.log` which is hard to
  triage.
- **F-4 — Add a blue-green deploy mechanism.** Currently deploys cause ~5s downtime during
  PHP-FPM reload. A blue-green setup (two app directories + Nginx upstream switch) would
  eliminate downtime.
- **F-5 — Add WAL archiving for point-in-time recovery.** Currently `pg_dump` gives
  daily snapshots only. WAL archiving + `pg_basebackup` would allow recovery to any
  second.
- **F-6 — Add a CDN for static assets.** `/build/*` and `/assets/*` could be served from
  a BDIX-connected CDN, reducing VPS bandwidth. Currently served by Nginx directly.
- **F-7 — Document the BDIX-specific bandwidth monitoring.** Most BDIX providers meter
  international traffic but not BDIX traffic. A monitoring script that distinguishes the
  two would help with capacity planning.
- **F-8 — Add an automated DR test.** A monthly cron that restores the latest backup to
  a staging VPS and runs `php artisan migrate:status` to verify recoverability.
  Currently DR is untested.
- **F-9 — Add a `php artisan deploy` command.** Would wrap the §9 routine deploy sequence
  into a single command with rollback-on-failure.
- **F-10 — Migrate from supervisord to systemd.** Supervisor is fine but systemd is the
  Ubuntu-native process manager. Would eliminate one package dependency.

---

## 14. Verification commands

```bash
# 1. Confirm the VPS is reachable + TLS works
curl -sI https://erp.example.com/up
# Expected: HTTP/2 200

# 2. Confirm PHP-FPM is running
sudo systemctl status php8.4-fpm
# Expected: active (running)

# 3. Confirm PostgreSQL is running + accepting connections
sudo systemctl status postgresql
psql -U rcerp_app -d rcerp -h 127.0.0.1 -c "SELECT version();"
# Expected: PostgreSQL 16.x

# 4. Confirm Redis is running + password works
sudo systemctl status redis-server
redis-cli -a STRONG_REDIS_PASSWORD ping
# Expected: PONG

# 5. Confirm Nginx is running + serving
sudo systemctl status nginx
curl -sI https://erp.example.com
# Expected: HTTP/2 200 (or 302 redirect to /login)

# 6. Confirm supervisor is running both workers
sudo supervisorctl status
# Expected: rcerp-queue-worker:rcerp-queue-worker_00  RUNNING
#           rcerp-queue-worker:rcerp-queue-worker_01  RUNNING
#           rcerp-listen-notify                       RUNNING

# 7. Confirm the cron scheduler is firing
sudo crontab -u rcerp -l
tail -20 /var/www/rcerp_v2/laravel/storage/logs/laravel.log | grep -i schedule
# Expected: schedule entries every minute

# 8. Confirm the daily backup is configured
sudo crontab -l | grep rcerp-backup
ls -la /var/backups/rcerp/
# Expected: backup script scheduled + at least one .dump file

# 9. Confirm the firewall is configured
sudo ufw status
# Expected: 22, 80, 443 ALLOW, everything else DENY

# 10. Confirm fail2ban is protecting SSH
sudo fail2ban-client status sshd
# Expected: jail list includes sshd
```

---

## 15. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| VPS sizing | §7 | (none — this is the canonical ref) |
| OS hardening | §8.2 | `../security/system-policy-compliance.md` |
| PHP 8.4 extensions | §8.3 | `docker-setup.md` §8.3 (same extension list) |
| PostgreSQL tuning | §7.3 | `../database/partitioning.md` (PG config affects partitioning) |
| `.env` writing | §8.6 | `environment.md` §8 (production cheatsheet) |
| Nginx config | §8.5 | `nginx-config.md` §7 (the full config) |
| Supervisor workers | §8.10 | `cron-scheduled-jobs.md` §5 (scheduler + workers) |
| Cron entry | §8.11 | `cron-scheduled-jobs.md` §4 (Laravel scheduler) |
| Backup strategy | §11 | `../database/migrations-conventions.md` §10 (DB-level backup) |
| Cutover sequence | §10 | `go-live-checklist.md` (verification gate) |
| Legacy app coexistence | R-8, E-6 | `../archive/legacy-overview.md` (transition window) |
| Disaster recovery | §11.2 | `go-live-checklist.md` §12 (rollback plan) |

---

*End of `vps-bdix-deployment.md`. For the Docker dev stack, see `docker-setup.md`. For the
go-live verification gate, see `go-live-checklist.md`.*
