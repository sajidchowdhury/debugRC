# Cron & Scheduled Jobs

> **Module:** Deployment (Scheduled jobs: Laravel scheduler + pg_cron + supervisor workers)
> **Audience:** DevOps engineers, DBAs, release managers, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `laravel/routes/console.php` (6 Laravel
> scheduler entries), `laravel/database/migrations/2025_01_20_000009_add_pg_cron_scheduled_jobs.php`
> (5 pg_cron jobs), `laravel/database/migrations/2026_08_15_000004_schedule_partman_maintenance_and_create_archive_schema.php`
> (1 pg_partman job), `laravel/database/migrations/2026_08_25_000003_create_partition_consolidation.php`
> (1 consolidation job), `docker-compose.yml` (queue worker + listen-notify containers),
> and `../database/partitioning.md`.

---

## 1. What is it?

RC_ERP_v2 has **three independent scheduling systems** running concurrently in production:

1. **The Laravel scheduler** (`routes/console.php`) — 6 PHP-level jobs, driven by a
   per-minute cron entry, running inside the PHP-FPM process. Used for business-logic
   jobs (cancel stale drafts, refresh MVs, reconcile stock drift, partition ops).
2. **PostgreSQL `pg_cron`** — 7 DB-level jobs, driven by the `pg_cron` extension's
   background worker, running inside PostgreSQL. Used for pure-SQL maintenance jobs
   (refresh MVs, purge old notifications, VACUUM ANALYZE, pg_partman maintenance, partition
   consolidation).
3. **Supervisor** (long-running workers, not cron) — 2 persistent processes: the Redis
   queue worker (`queue:work`) + the PG LISTEN/NOTIFY worker (`listen-notify:worker`).
   These are not scheduled — they run continuously.

This file documents **all three systems**, their job inventories, the timezone
reconciliation between Laravel (Asia/Dhaka) and pg_cron (UTC), the overlap-avoidance
strategy, and the operational commands to inspect + restart + debug each.

### 1.1 Why three systems?

- **Laravel scheduler** is for jobs that need PHP context (calling services, dispatching
  notifications, reading config). Can't run inside PG.
- **pg_cron** is for jobs that are pure SQL (refresh MVs, VACUUM, partition DDL). Running
  them in PG is faster (no PHP round-trip) + more reliable (runs even if PHP-FPM is
  down). The Laravel scheduler is the **fallback** for the 2 overlapping jobs (stale-draft
  cleanup + MV refresh) — if pg_cron is unavailable, Laravel handles them.
- **Supervisor workers** are not scheduled — they're long-running processes that should
  never exit. They're grouped here because they're part of the "background work" story.

---

## 2. Why does it exist?

- **Periodic maintenance without manual intervention.** Cancel stale drafts, refresh MVs,
  purge old notifications, VACUUM ANALYZE — all happen automatically on schedule.
- **Defense-in-depth scheduling.** The 2 most critical jobs (stale-draft cleanup + MV
  refresh) are scheduled in BOTH pg_cron AND Laravel. If pg_cron is unavailable (extension
  not installed, BGW crashed), Laravel handles them. If Laravel is unavailable (cron entry
  missing, FPM down), pg_cron handles them.
- **Partition lifecycle automation.** pg_partman's `run_maintenance_proc()` auto-creates
  future partitions + detaches expired ones. The pg_cron job runs it daily. Without this,
  future partitions stop being created at month 6 (the `p_premake` value) and inserts
  fail.
- **Partition consolidation.** The quarterly pg_cron job consolidates old monthly
  partitions into quarterly/yearly ones, keeping the catalog small. Without this, after
  3–7 years, the catalog bloats to ~360 partitions/year and query planning slows.
- **Timezone correctness.** Laravel scheduler runs in `APP_TIMEZONE` (Asia/Dhaka, UTC+6).
  pg_cron runs in UTC. A job scheduled `dailyAt('02:00')` in Laravel runs at 02:00 Dhaka
  (20:00 UTC previous day). A pg_cron job `'0 2 * * *'` runs at 02:00 UTC (08:00 Dhaka).
  This file's §6 reconciliation table is the only place these are aligned.
- **Overlap avoidance.** Heavy jobs (partition consolidation, Parquet export, perf
  measurement) are scheduled at distinct times to avoid concurrent execution. See §7 for
  the timeline diagram.
- **Auditability.** Every scheduled job logs its start/end/duration. The `v_pg_cron_jobs`
  view + Laravel's `schedule:run` log + supervisor's log provide a complete audit trail.

---

## 3. When is it used?

- **Continuous** — the 3 systems run constantly in production.
- **After VPS provisioning** — install the cron entry (§5.1), verify pg_cron extension
  (§5.2), start supervisor workers (§5.3).
- **After deploy** — `php artisan schedule:clear-cache` if schedule definitions changed.
- **Debugging "why didn't job X run"** — §9 inspection commands.
- **Debugging "job X is slow"** — §10 perf analysis.
- **Capacity planning** — §7 timeline shows the job density per hour.

---

## 4. Who uses it?

- **DevOps engineer** — installs + monitors the 3 systems.
- **DBA** — owns pg_cron + partitioning jobs.
- **Backend engineers** — consult when adding a new scheduled job (which system? what
  timezone? what overlap?).
- **Release manager** — verifies all jobs are running before period close.
- **AI assistants** — MUST consult this file before suggesting "schedule job X". Never
  suggest a cron entry without checking §7 for overlap.

---

## 5. Related modules

- `artisan-commands.md` §7 — the 6 Laravel-scheduled commands + the 3 partitioning
  commands.
- `environment.md` §7.1 — `APP_TIMEZONE` drives the Laravel scheduler.
- `vps-bdix-deployment.md` §8.10–§8.11 — supervisor + cron installation.
- `docker-setup.md` §7 — the `rcerp_queue_worker` + `rcerp_listen_notify` containers.
- `../database/partitioning.md` — the partitioning design that pg_partman + the
  consolidation job operate.
- `../architecture/partitioning-archival.md` — the archival lifecycle.
- `../architecture/realtime-events.md` §5 — the LISTEN/NOTIFY worker architecture.
- `../reports/materialized-views.md` — the 7 MVs that `reports:refresh` + the
  `refresh-report-views` pg_cron job refresh.

---

## 6. Business rules

- **R-1 — The Laravel scheduler runs every minute via cron.** The crontab entry is:
  ```
  * * * * * cd /var/www/rcerp_v2/laravel && php artisan schedule:run >> /dev/null 2>&1
  ```
  Without this, NO Laravel-scheduled job runs.
- **R-2 — pg_cron requires `shared_preload_libraries = 'pg_cron'` in `postgresql.conf`.**
  The extension must be loaded at PG startup, not just `CREATE EXTENSION`. Forgetting this
  causes `CREATE EXTENSION pg_cron` to fail.
- **R-3 — pg_cron runs in UTC, Laravel scheduler runs in `APP_TIMEZONE`.** A job
  scheduled `dailyAt('02:00')` in Laravel runs at 02:00 Asia/Dhaka = 20:00 UTC previous
  day. A pg_cron job `'0 2 * * *'` runs at 02:00 UTC = 08:00 Asia/Dhaka. NEVER assume
  they're aligned. See §6.1 reconciliation table.
- **R-4 — Defense-in-depth: critical jobs run in BOTH systems.** The 2 most critical
  jobs (stale-draft cleanup + MV refresh) are scheduled in pg_cron AND Laravel. Both are
  idempotent, so concurrent execution is safe (the second run is a no-op).
- **R-5 — `withoutOverlapping()` on all Laravel jobs.** Prevents concurrent execution of
  the same job. A slow `reports:refresh` run won't stack with the next 5-minute tick.
  Default lock TTL is 24 hours — override with `->withoutOverlapping(60)` for 60-min lock.
- **R-6 — `runInBackground()` on all Laravel jobs.** The scheduler worker doesn't block
  on the job. The next scheduled job can fire even if the previous is still running
  (subject to R-5's overlap guard per-job).
- **R-7 — pg_cron jobs are scheduled with `cron.schedule(job_name, schedule, command)`.**
  The `job_name` is the audit key — `cron.job_run_details` records every run with the
  job name + status + return message.
- **R-8 — pg_cron jobs are idempotent.** `refresh_all_report_views()` uses `REFRESH
  MATERIALIZED VIEW CONCURRENTLY` (no-op if no data changed). `cancel_stale_sales_drafts()`
  re-checks status before cancelling. `purge_old_notifications()` uses `DELETE WHERE
  created_at < ...` (no-op if no rows match). `vacuum_analyze_high_write_tables()` is
  inherently idempotent.
- **R-9 — The partition consolidation job runs quarterly.** At 04:00 on Jan/Apr/Jul/Oct
  1st, before the 04:30 Parquet export (so exports operate on already-consolidated
  partitions).
- **R-10 — The Parquet export runs quarterly.** At 04:30 on Jan/Apr/Jul/Oct 1st, 30
  minutes after the consolidation job.
- **R-11 — Supervisor workers restart on failure.** `autorestart=true` + `startsecs=3`
  ensures the queue worker + listen-notify worker are always running. If they crash,
  supervisor restarts them within 3 seconds.
- **R-12 — The cron entry runs as the `rcerp` user (VPS) or root (Docker).** NOT as
  `www-data` — the scheduler needs write access to `storage/logs/laravel.log` + the
  `schedule-cache` file.

### 6.1 Timezone reconciliation table

| Job | System | Schedule expression | Runs at (UTC) | Runs at (Asia/Dhaka) |
|---|---|---|---|---|
| `reports:refresh` (Laravel) | Laravel | `everyFiveMinutes` | every 5 min | every 5 min |
| `refresh-report-views` (pg_cron) | pg_cron | `*/5 * * * *` | every 5 min | every 5 min |
| `refresh-rb-checks` (pg_cron) | pg_cron | `0 * * * *` | every hour :00 | every hour :00 |
| `sales:cancel-stale-drafts` (Laravel) | Laravel | `dailyAt('02:00')` | 20:00 prev day | 02:00 |
| `cancel-stale-drafts` (pg_cron) | pg_cron | `0 2 * * *` | 02:00 | 08:00 |
| `stock:reconcile-drift` (Laravel) | Laravel | `dailyAt('03:00')` | 21:00 prev day | 03:00 |
| `purge-old-notifications` (pg_cron) | pg_cron | `0 3 * * *` | 03:00 | 09:00 |
| `pg_partman run_maintenance` (pg_cron) | pg_cron | `0 2 * * *` (daily 02:00 UTC) | 02:00 | 08:00 |
| `analyze-high-write-tables` (pg_cron) | pg_cron | `0 4 * * *` | 04:00 | 10:00 |
| `partition-consolidation` (pg_cron) | pg_cron | `0 4 1 1,4,7,10 *` | 04:00 quarter-start | 10:00 quarter-start |
| `partition:export-parquet` (Laravel) | Laravel | `30 4 1 1,4,7,10 *` (cron expr) | 04:30 quarter-start | 10:30 quarter-start |
| `partition:verify-join` (Laravel) | Laravel | `weeklyOn(1, '05:00')` | Sun 23:00 prev | Mon 05:00 |
| `partition:measure-perf` (Laravel) | Laravel | `weeklyOn(1, '05:30')` | Sun 23:30 prev | Mon 05:30 |

> ⚠️ Note: the Laravel `dailyAt('02:00')` for `sales:cancel-stale-drafts` runs at 02:00
> Dhaka = 20:00 UTC previous day. The pg_cron `'0 2 * * *'` for `cancel-stale-drafts`
> runs at 02:00 UTC = 08:00 Dhaka. These are NOT the same time — they're 6 hours apart.
> This is intentional (defense-in-depth, R-4) — if one fails, the other catches it 6
> hours later.

---

## 7. The 24-hour job timeline

```
Time (Asia/Dhaka)  Job                                          System
─────────────────────────────────────────────────────────────────────
00:00              ─ (nothing) ─
01:00              DB backup (cron, not Laravel/pg_cron)
02:00              sales:cancel-stale-drafts                    Laravel
02:00              pg_partman run_maintenance                   pg_cron (08:00 Dhaka)
                   cancel-stale-drafts                          pg_cron (08:00 Dhaka)
03:00              stock:reconcile-drift                        Laravel
03:00              purge-old-notifications                      pg_cron (09:00 Dhaka)
04:00              analyze-high-write-tables                    pg_cron (10:00 Dhaka)
04:00 (quarterly)  partition-consolidation                      pg_cron (10:00 Dhaka)
04:30 (quarterly)  partition:export-parquet                     Laravel (10:30 Dhaka)
05:00 (weekly Mon) partition:verify-join                        Laravel
05:30 (weekly Mon) partition:measure-perf                       Laravel
06:00–23:59        ─ reports:refresh every 5 min (Laravel) ─
                   ─ refresh-report-views every 5 min (pg_cron) ─
                   ─ refresh-rb-checks hourly (pg_cron) ─
                   ─ queue:work (continuous, supervisor) ─
                   ─ listen-notify:worker (continuous, supervisor) ─
```

> The 02:00–05:00 window is the "maintenance window" — most heavy jobs run here to
> minimize user impact. The 04:00–05:00 sub-window is the "partition window" — partition
> DDL jobs run here to avoid concurrent user writes.

---

## 8. Laravel scheduler jobs (6)

> Source: `laravel/routes/console.php`. All chained with `->withoutOverlapping()->runInBackground()`.

### 8.1 `reports:refresh`

```php
Schedule::command('reports:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('reports-refresh')
    ->description('Refresh financial report materialized views');
```

| Field | Value |
|---|---|
| **Schedule** | Every 5 minutes |
| **Purpose** | Refresh all 7 financial report MVs (Trial Balance, Balance Sheet, Income Statement, etc.) |
| **What it does** | Runs `REFRESH MATERIALIZED VIEW CONCURRENTLY` on all 7 MVs |
| **Overlap with pg_cron** | `refresh-report-views` pg_cron job runs the same SQL every 5 min (R-4) |
| **Cross-ref** | `artisan-commands.md` §7.5.2, `../reports/materialized-views.md` |

### 8.2 `sales:cancel-stale-drafts`

```php
Schedule::command('sales:cancel-stale-drafts')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('sales-cancel-stale-drafts')
    ->description('Cancel stale draft sales invoices (>14 days, no godown)');
```

| Field | Value |
|---|---|
| **Schedule** | Daily at 02:00 Asia/Dhaka (20:00 UTC previous day) |
| **Purpose** | Cancel draft sales invoices older than 14 days with no godown/challan activity |
| **What it does** | Updates `sales_invoices.status` → cancelled, posts reversal GL entries, updates `customer_ledger` |
| **Overlap with pg_cron** | `cancel-stale-drafts` pg_cron job runs at 02:00 UTC = 08:00 Dhaka (R-4) |
| **Cross-ref** | `artisan-commands.md` §7.5.1, `../sales/sales-invoice.md` §11 |

### 8.3 `stock:reconcile-drift`

```php
Schedule::command('stock:reconcile-drift')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('stock-reconcile-drift')
    ->description('Detect warehouse_stock ↔ stock_transactions drift and alert admins');
```

| Field | Value |
|---|---|
| **Schedule** | Daily at 03:00 Asia/Dhaka (21:00 UTC previous day) |
| **Purpose** | Detect stock drift (warehouse_stock.qty vs SUM(stock_transactions.qty)) + alert admins |
| **What it does** | Computes drift for every (warehouse, product), fires `ERPNotification` to admins |
| **Overlap with pg_cron** | None (Laravel-only) |
| **Cross-ref** | `artisan-commands.md` §7.5.3, `../inventory/stock-adjustment.md` |

### 8.4 `partition:export-parquet`

```php
Schedule::command('partition:export-parquet')
    ->cron('30 4 1 1,4,7,10 *')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-export-parquet')
    ->description('Export archived partitions to Parquet cold storage (quarterly)');
```

| Field | Value |
|---|---|
| **Schedule** | At 04:30 on Jan/Apr/Jul/Oct 1st (cron expression, runs in `APP_TIMEZONE`) |
| **Purpose** | Export archived partitions to Parquet cold storage + DROP source tables |
| **What it does** | For each partition in `archive` schema, exports to `.parquet` file, drops the table |
| **Overlap with pg_cron** | Runs 30 min AFTER `partition-consolidation` pg_cron job (R-9) |
| **Cross-ref** | `artisan-commands.md` §7.2.1, `../database/partitioning.md` |

> Note: `->quarterly()` in Laravel runs at 00:00 on quarter-start. We use an explicit
> cron expression `'30 4 1 1,4,7,10 *'` to control the 04:30 timing (after the 04:00
> consolidation job).

### 8.5 `partition:verify-join`

```php
Schedule::command('partition:verify-join')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-verify-join')
    ->description('Verify partition-wise joins are working (weekly)');
```

| Field | Value |
|---|---|
| **Schedule** | Weekly on Monday at 05:00 Asia/Dhaka (Sunday 23:00 UTC) |
| **Purpose** | Verify partition-wise joins are working (JE↔JL join plan has "Partition-wise join" node) |
| **What it does** | Runs `EXPLAIN ANALYZE`, asserts the join node, alerts on regression |
| **Overlap with pg_cron** | None (Laravel-only) |
| **Cross-ref** | `artisan-commands.md` §7.1.5, `../database/partitioning.md` |

### 8.6 `partition:measure-perf`

```php
Schedule::command('partition:measure-perf')
    ->weeklyOn(1, '05:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-measure-perf')
    ->description('Measure partition query performance vs targets (weekly)');
```

| Field | Value |
|---|---|
| **Schedule** | Weekly on Monday at 05:30 Asia/Dhaka (Sunday 23:30 UTC) |
| **Purpose** | Measure 10 partition query runtimes vs targets + persist results |
| **What it does** | Runs 10 benchmark queries, persists to `partition_performance_measurements`, alerts on target breach |
| **Overlap with pg_cron** | None (Laravel-only). Offset 30 min after `partition:verify-join` (R-9) |
| **Cross-ref** | `artisan-commands.md` §7.1.6, `../database/partitioning.md` |

---

## 9. pg_cron jobs (7)

> Source: `laravel/database/migrations/2025_01_20_000009_add_pg_cron_scheduled_jobs.php` (5
> jobs) + `2026_08_15_000004_schedule_partman_maintenance_and_create_archive_schema.php` (1
> job) + `2026_08_25_000003_create_partition_consolidation.php` (1 job). All scheduled
> via `SELECT cron.schedule(job_name, schedule, command)`.

### 9.1 `cancel-stale-drafts`

```sql
SELECT cron.schedule(
    'cancel-stale-drafts',
    '0 2 * * *',
    $$SELECT cancel_stale_sales_drafts(14, 200, NULL)$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 2 * * *'` = daily at 02:00 UTC (08:00 Asia/Dhaka) |
| **Purpose** | Cancel stale draft sales invoices (pure-SQL version of the Laravel command) |
| **What it calls** | `cancel_stale_sales_drafts(14, 200, NULL)` — PL/pgSQL function |
| **Overlap with Laravel** | `sales:cancel-stale-drafts` Laravel job runs at 02:00 Dhaka = 20:00 UTC (R-4) |
| **Cross-ref** | `../sales/sales-invoice.md` §11 |

### 9.2 `refresh-report-views`

```sql
SELECT cron.schedule(
    'refresh-report-views',
    '*/5 * * * *',
    $$SELECT refresh_all_report_views()$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'*/5 * * * *'` = every 5 minutes |
| **Purpose** | Refresh all 7 financial report MVs (pure-SQL version of `reports:refresh`) |
| **What it calls** | `refresh_all_report_views()` — PL/pgSQL function |
| **Overlap with Laravel** | `reports:refresh` Laravel job runs every 5 min (R-4) |
| **Cross-ref** | `../reports/materialized-views.md` |

### 9.3 `refresh-rb-checks`

```sql
SELECT cron.schedule(
    'refresh-rb-checks',
    '0 * * * *',
    $$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_customer_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_supplier_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_employee_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_cash_ledger_balance_check$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 * * * *'` = hourly at :00 |
| **Purpose** | Refresh the 4 running-balance check MVs (lighter than the financial report MVs) |
| **What it calls** | Inline `REFRESH MATERIALIZED VIEW CONCURRENTLY` statements |
| **Overlap with Laravel** | None (pg_cron-only) |
| **Cross-ref** | `../accounting/running-balance.md`, `../reports/materialized-views.md` |

### 9.4 `purge-old-notifications`

```sql
SELECT cron.schedule(
    'purge-old-notifications',
    '0 3 * * *',
    $$SELECT purge_old_notifications(90)$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 3 * * *'` = daily at 03:00 UTC (09:00 Asia/Dhaka) |
| **Purpose** | Delete read notifications older than 90 days |
| **What it calls** | `purge_old_notifications(90)` — PL/pgSQL function |
| **Overlap with Laravel** | None (pg_cron-only) |
| **Cross-ref** | `../workflows/notification-workflow.md` |

### 9.5 `analyze-high-write-tables`

```sql
SELECT cron.schedule(
    'analyze-high-write-tables',
    '0 4 * * *',
    $$SELECT vacuum_analyze_high_write_tables()$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 4 * * *'` = daily at 04:00 UTC (10:00 Asia/Dhaka) |
| **Purpose** | `ANALYZE` on high-write tables (autovacuum handles VACUUM; this updates planner stats) |
| **What it calls** | `vacuum_analyze_high_write_tables()` — PL/pgSQL function (despite the name, only runs ANALYZE) |
| **Overlap with Laravel** | None (pg_cron-only) |
| **Cross-ref** | `../database/partitioning.md` (per-partition VACUUM tuning) |

### 9.6 `pg_partman-maintenance` (daily partition lifecycle)

```sql
SELECT cron.schedule(
    'pg_partman-maintenance',
    '0 2 * * *',
    $$CALL partman.run_maintenance_proc()$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 2 * * *'` = daily at 02:00 UTC (08:00 Asia/Dhaka) |
| **Purpose** | Run pg_partman maintenance: create future partitions + detach expired ones per `partman.part_config` retention |
| **What it calls** | `partman.run_maintenance_proc()` — pg_partman 5.x procedure |
| **Overlap with Laravel** | None (pg_cron-only). Laravel has no pg_partman wrapper. |
| **Cross-ref** | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |

> Scheduled by migration `2026_08_15_000004`. If pg_cron is unavailable, this job is
> NOT scheduled — future partitions stop being created at month 6 (the `p_premake`
> value). Mitigation: install pg_cron OR run `CALL partman.run_maintenance_proc()`
> manually daily.

### 9.7 `partition-consolidation` (quarterly)

```sql
SELECT cron.schedule(
    'partition-consolidation',
    '0 4 1 1,4,7,10 *',
    $$SELECT * FROM run_quarterly_consolidation()$$
);
```

| Field | Value |
|---|---|
| **Schedule** | `'0 4 1 1,4,7,10 *'` = at 04:00 UTC (10:00 Asia/Dhaka) on Jan/Apr/Jul/Oct 1st |
| **Purpose** | Consolidate old monthly partitions into quarterly ones (3–7 years old) or yearly (7+ years) |
| **What it calls** | `run_quarterly_consolidation()` — PL/pgSQL function |
| **Overlap with Laravel** | `partition:export-parquet` Laravel job runs 30 min AFTER this (R-9) |
| **Cross-ref** | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |

> Scheduled by migration `2026_08_25_000003`. Consolidates the 4 highest-volume parents:
> `journal_entries`, `journal_lines`, `stock_transactions`, `sales_invoices`. Yearly
> consolidation is NOT scheduled automatically (manual trigger when the first parents
> reach 7+ years).

---

## 10. Supervisor workers (2 — long-running, not cron)

> Source: `vps-bdix-deployment.md` §8.10 (supervisor config) + `docker-compose.yml` (Docker
> containers `rcerp_queue_worker` + `rcerp_listen_notify`).

### 10.1 Queue worker (`queue:work`)

```ini
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
```

| Field | Value |
|---|---|
| **Command** | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600` |
| **Purpose** | Process queued jobs (notifications, CSV exports, etc.) from the Redis queue |
| **`--sleep=3`** | Sleep 3 seconds when no jobs available (reduces Redis polling) |
| **`--tries=3`** | Retry failed jobs up to 3 times |
| **`--max-time=3600`** | Restart the worker after 1 hour (releases memory + picks up code changes) |
| **`numprocs=2`** | Run 2 worker processes (tune based on job volume) |
| **`stopwaitsecs=3600`** | Wait up to 1 hour for the current job to finish before killing |
| **Docker equivalent** | `rcerp_queue_worker` container (`docker-compose.yml`) |
| **Cross-ref** | `../architecture/realtime-events.md` §4 |

### 10.2 LISTEN/NOTIFY worker (`listen-notify:worker`)

```ini
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
```

| Field | Value |
|---|---|
| **Command** | `php artisan listen-notify:worker` |
| **Purpose** | Long-running PG LISTEN → Redis pub/sub bridge for SSE |
| **`numprocs=1`** | Only 1 process (multiple would duplicate events) |
| **`stopwaitsecs=10`** | Quick shutdown (the worker exits cleanly on SIGTERM) |
| **Docker equivalent** | `rcerp_listen_notify` container (`docker-compose.yml`) |
| **Cross-ref** | `artisan-commands.md` §7.5.4, `../architecture/realtime-events.md` §5 |

---

## 11. Operational commands

### 11.1 Laravel scheduler

```bash
# List all scheduled jobs + next run time
php artisan schedule:list

# Run the scheduler in the foreground (dev only)
php artisan schedule:work

# Run the scheduler once (for testing)
php artisan schedule:run

# Test a specific scheduled job
php artisan schedule:test

# Clear the schedule cache (after editing routes/console.php)
php artisan schedule:clear-cache

# View the cron entry
sudo crontab -u rcerp -l
```

### 11.2 pg_cron

```sql
-- List all pg_cron jobs
SELECT * FROM cron.job;

-- List recent job runs
SELECT * FROM cron.job_run_details ORDER BY start_time DESC LIMIT 20;

-- Use the monitoring view (created by the migration)
SELECT * FROM v_pg_cron_jobs;

-- Unschedule a job
SELECT cron.unschedule('cancel-stale-drafts');
SELECT cron.unschedule(job_id => 1);

-- Reschedule a job (unschedule + schedule)
SELECT cron.unschedule('cancel-stale-drafts');
SELECT cron.schedule('cancel-stale-drafts', '0 2 * * *', $$SELECT cancel_stale_sales_drafts(14, 200, NULL)$$);

-- Manually trigger a job's command
SELECT cancel_stale_sales_drafts(14, 200, NULL);
SELECT refresh_all_report_views();
SELECT * FROM run_quarterly_consolidation();

-- Check pg_cron extension is loaded
SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_cron';

-- Check the BGW is running
SELECT * FROM pg_stat_activity WHERE backend_type = 'background worker' AND query LIKE '%pg_cron%';
```

### 11.3 Supervisor workers

```bash
# List all supervisor programs + status
sudo supervisorctl status

# Restart a specific worker
sudo supervisorctl restart rcerp-queue-worker:*
sudo supervisorctl restart rcerp-listen-notify

# Stop / start
sudo supervisorctl stop rcerp-queue-worker:*
sudo supervisorctl start rcerp-queue-worker:*

# Tail the worker log
sudo tail -f /var/www/rcerp_v2/laravel/storage/logs/queue-worker.log
sudo tail -f /var/www/rcerp_v2/laravel/storage/logs/listen-notify.log

# Reload supervisor config (after editing /etc/supervisor/conf.d/*.conf)
sudo supervisorctl reread
sudo supervisorctl update
```

### 11.4 Docker (dev)

```bash
# The Docker stack runs the scheduler + workers as containers, not cron/supervisor.
# The scheduler is NOT running by default in Docker (no cron entry).
# To run a scheduled job manually:
docker compose exec rcerp_app php artisan schedule:run

# The queue worker + listen-notify worker ARE running as containers:
docker compose ps rcerp_queue_worker rcerp_listen_notify

# Tail their logs:
docker compose logs -f rcerp_queue_worker
docker compose logs -f rcerp_listen_notify
```

---

## 12. Known edge cases

- **E-1 — pg_cron requires `shared_preload_libraries`.** Forgetting this in
  `postgresql.conf` causes `CREATE EXTENSION pg_cron` to fail with "pg_cron must be
  loaded via shared_preload_libraries". The migration catches this + logs a warning +
  falls back to the Laravel scheduler. But the partition-consolidation + pg_partman
  jobs have NO Laravel fallback — they only run via pg_cron.
- **E-2 — pg_cron runs in UTC.** A job `'0 2 * * *'` runs at 02:00 UTC = 08:00 Dhaka,
  NOT 02:00 Dhaka. The reconciliation table in §6.1 is the only source of truth. Mixing
  up timezones causes "the job ran 6 hours late" tickets.
- **E-3 — `withoutOverlapping()` default lock is 24 hours.** If a job hangs (e.g.
  `reports:refresh` is stuck on a slow MV refresh), the next 5-minute tick is skipped
  for up to 24 hours. Override with `->withoutOverlapping(60)` for a 60-min lock.
- **E-4 — `runInBackground()` doesn't propagate errors.** A background job that fails
  silently doesn't surface in `schedule:run` output. Check `storage/logs/laravel.log`
  for the job's errors.
- **E-5 — pg_cron's `cron.job_run_details` table grows unbounded.** Every job run
  inserts a row. After a year at 5-min intervals, that's 105K rows. Purge periodically:
  `DELETE FROM cron.job_run_details WHERE start_time < NOW() - INTERVAL '90 days';`.
- **E-6 — The partition-consolidation job is destructive.** It DETACHes + CREATEs +
  INSERTs + DROPs partitions. If it fails mid-way (e.g. power loss), partitions may be
  detached but not re-attached. The function is idempotent (re-running picks up where it
  left off), but manual inspection is required after a failure.
- **E-7 — `listen-notify:worker` doesn't reconnect on PG restart.** The worker caches
  the PG connection. If PG restarts, the worker exits with a connection error.
  Supervisor's `autorestart=true` restarts it within 3 seconds. During that window, SSE
  events are missed (browsers reconnect + get the latest state, so no data loss).
- **E-8 — `queue:work --max-time=3600` restarts hourly.** This is intentional (releases
  memory + picks up code changes without a manual restart). But it means a long-running
  job (>1 hour) is killed mid-execution. Use `--stop-when-empty` + a longer `--max-time`
  for long jobs, or dispatch them as separate processes.
- **E-9 — The Docker stack has no cron entry.** The Laravel scheduler is NOT running by
  default in Docker. Scheduled jobs don't fire unless you manually `docker compose exec
  rcerp_app php artisan schedule:run` or add a cron sidecar. The queue worker +
  listen-notify worker DO run as containers.
- **E-10 — pg_partman's `p_premake=6` means 6 months of future partitions.** If the
  pg_partman maintenance job stops running (pg_cron down, extension uninstalled),
  inserts fail at month 7 (no partition exists). Mitigation: monitor the
  `partition:verify-join` weekly job + alert if it fails.

---

## 13. Future improvements

- **F-1 — Add a Laravel fallback for the partition-consolidation job.** Currently if
  pg_cron is down, consolidation doesn't run. A Laravel `partition:consolidate` command
  (with `--dry-run`) would provide defense-in-depth.
- **F-2 — Add a Laravel fallback for the pg_partman maintenance job.** Same as F-1. A
  Laravel `partition:run-maintenance` command would call `partman.run_maintenance_proc()`.
- **F-3 — Add alerting for failed pg_cron jobs.** Currently `cron.job_run_details`
  records failures but doesn't alert. A daily Laravel job that queries for failed
  pg_cron runs + dispatches an `ERPNotification` would close the gap.
- **F-4 — Add a `schedule:monitor` command.** Would list all scheduled jobs (Laravel +
  pg_cron + supervisor) in a single table with last-run + next-run + status. Currently
  you have to query 3 systems separately.
- **F-5 — Migrate the queue worker to Laravel Horizon.** Horizon provides a dashboard
  + auto-scaling + failed-job retention. Currently we use bare `queue:work` + manual
  log inspection.
- **F-6 — Add a cron sidecar to the Docker stack.** Currently Docker dev doesn't run
  the scheduler. A `rcerp_scheduler` container running `cron` would close the dev/prod
  parity gap.
- **F-7 — Purge `cron.job_run_details` automatically.** Add a pg_cron job that purges
  rows older than 90 days. Currently manual (E-5).
- **F-8 — Document the `pg_partman.part_config` retention values.** Currently the
  retention months per parent are in the DB table, not documented. A §9.x table here
  would make them visible.
- **F-9 — Add a "dry-run" mode for the partition-consolidation job.** Currently the
  function supports `p_dry_run = true`, but the pg_cron job calls it with
  `dry_run=false`. A second pg_cron job with `dry_run=true` running weekly would
  preview upcoming consolidations.
- **F-10 — Add timezone-aware pg_cron scheduling.** Currently all pg_cron jobs run in
  UTC. A wrapper function that converts Asia/Dhaka times to UTC before scheduling would
  reduce the cognitive load (E-2).

---

## 14. Verification commands

```bash
# 1. Confirm the cron entry exists (VPS)
sudo crontab -u rcerp -l | grep schedule:run
# Expected: * * * * * cd /var/www/rcerp_v2/laravel && php artisan schedule:run >> /dev/null 2>&1

# 2. List all Laravel-scheduled jobs
php artisan schedule:list
# Expected: 6 jobs listed with their next run time

# 3. Confirm pg_cron is loaded
psql -U rcerp_app -d rcerp -c "SELECT extname, extversion FROM pg_extension WHERE extname = 'pg_cron';"
# Expected: 1 row, extname = pg_cron

# 4. List all pg_cron jobs
psql -U rcerp_app -d rcerp -c "SELECT jobname, schedule, active FROM cron.job ORDER BY jobname;"
# Expected: 7 jobs listed

# 5. Check recent pg_cron job runs
psql -U rcerp_app -d rcerp -c "SELECT jobname, status, start_time, return_message FROM v_pg_cron_jobs ORDER BY start_time DESC LIMIT 10;"
# Expected: recent runs with status 'succeeded'

# 6. Confirm supervisor workers are running
sudo supervisorctl status
# Expected: rcerp-queue-worker:* RUNNING, rcerp-listen-notify RUNNING

# 7. Confirm the queue worker is processing jobs
sudo tail -20 /var/www/rcerp_v2/laravel/storage/logs/queue-worker.log
# Expected: recent job processing entries

# 8. Confirm the listen-notify worker is connected
sudo tail -20 /var/www/rcerp_v2/laravel/storage/logs/listen-notify.log
# Expected: "Listening on channels: rcerp_*"

# 9. Manually trigger the Laravel scheduler (for testing)
php artisan schedule:run
# Expected: runs any jobs due now

# 10. Manually trigger a pg_cron job's command
psql -U rcerp_app -d rcerp -c "SELECT refresh_all_report_views();"
# Expected: function returns successfully
```

---

## 15. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Laravel scheduler jobs | §8 | `artisan-commands.md` §7 (the commands) |
| pg_cron jobs | §9 | `../database/partitioning.md`, `../reports/materialized-views.md` |
| Supervisor workers | §10 | `../architecture/realtime-events.md` §4–5 |
| Timezone reconciliation | §6.1 | `environment.md` §7.1 (APP_TIMEZONE) |
| Job timeline | §7 | `go-live-checklist.md` §3 (verify all jobs running) |
| Defense-in-depth scheduling | R-4 | `../coding/error-handling.md` (defense-in-depth pattern) |
| `pg_partman` maintenance | §9.6 | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |
| Partition consolidation | §9.7 | `../database/partitioning.md` (consolidation strategy) |
| Parquet export | §8.4 | `artisan-commands.md` §7.2.1 |
| Docker stack scheduler gap | E-9 | `docker-setup.md` §10 E-9 |

---

*End of `cron-scheduled-jobs.md`. For the command catalogues, see `artisan-commands.md`.
For the go-live verification that all jobs are running, see `go-live-checklist.md` §3.*
