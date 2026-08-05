# RC_ERP_v2 — supervisor configuration templates

> **Audience:** DevOps engineers provisioning a non-Docker deployment
> (bare-metal VPS / BDIX). Docker deployments do NOT need these —
> `docker-compose.yml`'s `rcerp_listen_notify` + `rcerp_queue` services use
> Docker's built-in `restart: unless-stopped` policy.
>
> **Cross-ref:** `AI_CONTEXT/deployment/vps-bdix-deployment.md` §8.10 is the
> full provisioning runbook. This directory is the canonical source for the
> `.conf` files referenced there.

## What's here

| File | Purpose | Equivalent docker-compose service |
|---|---|---|
| `rcerp-listen-notify.conf` | Supervises the PostgreSQL `LISTEN/NOTIFY` worker (`php artisan listen-notify:worker`). Long-running event loop, **must be exactly 1 process**. | `rcerp_listen_notify` |
| `rcerp-queue-worker.conf` | Supervises the Laravel queue worker (`php artisan queue:work`). Async jobs (report generation, partition export, batch notification fanout). 2 processes is the default. | `rcerp_queue` |

## Why these are NOT in `routes/console.php` (Laravel cron)

Both workers are **long-running event loops** that never exit. Laravel's
`Schedule::command(...)` runs a command, waits for it to finish, then moves to
the next scheduled job — every minute, by cron. Scheduling a non-exiting
command would:

1. Fork a new instance every minute (process leak).
2. Block the scheduler's single-worker pipeline so no other scheduled job runs.

The correct pattern for long-running PHP workers is a **process manager**
(supervisor OR systemd) that auto-restarts on transient failure. Periodic
`schedulable` commands (the 5 in `routes/console.php`: report refresh, stale
draft cancel, stock drift reconcile, partition export, depreciation post) ARE
cron-managed — see `AI_CONTEXT/deployment/cron-scheduled-jobs.md` for that
half of the async story.

## Install

```bash
# 1. Install supervisor (Ubuntu 22.04 LTS)
sudo apt install -y supervisor

# 2. Copy both .conf files into supervisor's conf.d
sudo cp supervisor/rcerp-listen-notify.conf /etc/supervisor/conf.d/
sudo cp supervisor/rcerp-queue-worker.conf   /etc/supervisor/conf.d/

# 3. Reload supervisor + start the new programs
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start rcerp-listen-notify
sudo supervisorctl start rcerp-queue-worker:*

# 4. Verify
sudo supervisorctl status
# Expected:
#   rcerp-listen-notify              RUNNING   pid 12345, uptime 0:01:23
#   rcerp-queue-worker:rcerp-queue-worker_00   RUNNING   pid 12346, uptime 0:01:23
#   rcerp-queue-worker:rcerp-queue-worker_01   RUNNING   pid 12347, uptime 0:01:23
```

## Operational notes

### Logs

Both programs write to `laravel/storage/logs/{listen-notify,queue-worker}.log`
with 20MB rotation × 10 backups. Tail during incidents:

```bash
sudo tail -f /var/www/rcerp_v2/laravel/storage/logs/listen-notify.log
```

### Deploys

Restart both workers after a code deploy (they cache PHP in memory):

```bash
sudo supervisorctl restart rcerp-listen-notify
sudo supervisorctl restart rcerp-queue-worker:*
```

(See `AI_CONTEXT/deployment/vps-bdix-deployment.md` §9 for the full deploy
sequence.)

### Worker health

The LISTEN/NOTIFY worker writes a JSON heartbeat to Redis key
`rcerp:listen_notify:heartbeat` every 60s (TTL 120s). It is surfaced at the
`/sse/status` HTTP endpoint:

```json
{
  "worker_running": true,
  "worker_heartbeat": {
    "timestamp": "2026-09-06T12:34:56+06:00",
    "processed_count": 1234,
    "last_notification_at": "2026-09-06T12:34:00+06:00",
    "pid": 12345
  }
}
```

If `worker_running` is `false` or the heartbeat timestamp is >120s stale,
supervisor has failed to restart the process — investigate via
`sudo supervisorctl status` + `sudo journalctl -u supervisor`.

## Choosing supervisor vs systemd

Both are supported in-repo. Pick **one** — do not run both for the same worker.

| Concern | supervisor | systemd |
|---|---|---|
| Already installed on Ubuntu 22.04 | ❌ (needs `apt install supervisor`) | ✅ (pid 1) |
| Config format | INI-like, simple | Unit-file, more verbose |
| Mature in Laravel ecosystem | ✅ (Laravel docs default) | ✅ |
| One less package dependency | ❌ | ✅ |
| Template in this repo | `supervisor/*.conf` | `systemd/*.service` |

`AI_CONTEXT/deployment/vps-bdix-deployment.md` F-10 tracks "migrate from
supervisord to systemd" as a future improvement. The systemd templates in
`../systemd/` exist so that migration is a one-step cutover, not a
from-scratch rewrite.
