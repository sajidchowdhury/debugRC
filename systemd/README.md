# RC_ERP_v2 — systemd unit templates

> **Audience:** DevOps engineers provisioning a non-Docker deployment on
> Ubuntu 22.04 LTS who prefer the systemd-native process manager over
> supervisord.
>
> **Cross-ref:** `AI_CONTEXT/deployment/vps-bdix-deployment.md` §8.10 documents
> the supervisord path; F-10 in that same file tracks "migrate from supervisord
> to systemd" as a future improvement. This directory is the cutover target.

## What's here

| File | systemd unit name | Purpose |
|---|---|---|
| `rcerp-listen-notify.service` | `rcerp-listen-notify.service` | The PostgreSQL `LISTEN/NOTIFY` worker (`php artisan listen-notify:worker`). Long-running event loop, exactly 1 instance. |
| `rcerp-queue-worker.service` | `rcerp-queue-worker@.service` (template) | The Laravel queue worker. Run 2 instances via `rcerp-queue-worker@1` + `rcerp-queue-worker@2`. |

## Why these are NOT in `routes/console.php` (Laravel cron)

Both workers are **long-running event loops** that never exit. Laravel's
`Schedule::command(...)` runs a command, waits for it to finish, then moves to
the next scheduled job — every minute, by cron. Scheduling a non-exiting
command would fork a new instance every minute (process leak) and block the
scheduler. The correct pattern is a process manager (systemd OR supervisor).
See `../supervisor/README.md` for the full rationale.

## Install

### rcerp-listen-notify (single instance)

```bash
# 1. Copy the unit file
sudo cp systemd/rcerp-listen-notify.service /etc/systemd/system/

# 2. Reload systemd + enable + start
sudo systemctl daemon-reload
sudo systemctl enable --now rcerp-listen-notify

# 3. Verify
sudo systemctl status rcerp-listen-notify
# Expected: active (running)

sudo journalctl -u rcerp-listen-notify -f
# Tail the worker log (stdout/stderr captured by journald).
```

### rcerp-queue-worker (2 instances)

```bash
# 1. Copy the template unit (NOTE the @ in the destination name)
sudo cp systemd/rcerp-queue-worker.service /etc/systemd/system/rcerp-queue-worker@.service

# 2. Reload systemd
sudo systemctl daemon-reload

# 3. Enable + start 2 instances
sudo systemctl enable --now rcerp-queue-worker@1
sudo systemctl enable --now rcerp-queue-worker@2

# 4. Verify
sudo systemctl status rcerp-queue-worker@1 rcerp-queue-worker@2
```

## Operational notes

### Logs

systemd captures stdout/stderr into journald (per-unit). Tail during incidents:

```bash
sudo journalctl -u rcerp-listen-notify -f
sudo journalctl -u rcerp-queue-worker@1 -f
```

Laravel also writes to `laravel/storage/logs/laravel.log` — that file is the
cross-worker application log; journald is the per-process stdout/stderr stream.

### Deploys

Restart both workers after a code deploy (they cache PHP in memory):

```bash
sudo systemctl restart rcerp-listen-notify
sudo systemctl restart rcerp-queue-worker@1 rcerp-queue-worker@2
```

### Worker health

The LISTEN/NOTIFY worker writes a JSON heartbeat to Redis key
`rcerp:listen_notify:heartbeat` every 60s (TTL 120s). It is surfaced at the
`/sse/status` HTTP endpoint. If `worker_running` is `false` or the heartbeat
is >120s stale, systemd has failed to restart the process — investigate via
`sudo systemctl status rcerp-listen-notify` + `sudo journalctl -u rcerp-listen-notify`.

## Hardening

Both units ship with these systemd security directives:

- `NoNewPrivileges=true` — no setuid escalation.
- `PrivateTmp=true` — private `/tmp`.
- `ProtectSystem=full` — `/usr`, `/boot`, `/etc` read-only.
- `ProtectHome=true` — `/home`, `/root`, `/run/user` inaccessible.
- `ReadWritePaths=/var/www/rcerp_v2/laravel/storage` — only `storage/` is
  writable (logs, cache, framework-generated files).
- `ProtectKernelTunables=true`, `ProtectKernelModules=true`,
  `ProtectControlGroups=true` — kernel surface locked down.
- `RestrictSUIDSGID=true` — no setuid binaries.
- `LockPersonality=true` — no personality(2) changes.

These are conservative — if you encounter `EPERM` errors, drop the offending
directive. The worker only needs: PG socket/TCP, Redis TCP, file read of
`laravel/`, file write to `laravel/storage/`.

## Choosing supervisor vs systemd

See `../supervisor/README.md` § "Choosing supervisor vs systemd" for the
side-by-side comparison. Pick ONE — do not run both for the same worker.
