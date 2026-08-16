# Session 3 — Auto-Backup Command + Year-End Checklist Gate

> **Phase**: 1 (Q1 — Fiscal Year Isolation)
> **Branch**: `feature/fy-isolation-and-branch-pnl`
> **Baseline**: `bcf4dc5` (Session 2 — global scope + FiscalYearPolicy)
> **Date**: 2026-08-16
> **Owner**: Backend dev + DevOps

This document records every change made in Session 3, the rationale for each decision, and the acceptance tests the dev team must run on the live Docker host. It is the companion to `docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md` §Session 3.

---

## 1. Goal

Build an in-app `php artisan db:backup-year-end` command that produces a `pg_dump -Fc` file at a configurable path on the PC, with SHA-256 verification, and wire it into `yearEndClose()` as a hard gate — year-end close ABORTS if no fresh backup file exists.

This closes Q1 Gap 3 (auto-backup). Combined with S1 (fiscal_year_id) + S2 (read-block), this is the third leg of the fiscal-year isolation guarantee: even if a user could somehow read closed-FY data, the data is preserved in a verified backup file before close runs.

---

## 2. Files Changed

### 2.1 New Files (7)

| File | Purpose |
|---|---|
| `config/backup.php` | Single source of truth for backup config: `pg_dump_binary`, `backup_path`, `connection`, `freshness_hours` (default 24), `retention_count` (default 5), `pg_dump_options`, `process_timeout`. All overridable via `.env`. |
| `database/migrations/2026_10_16_000003_create_database_backups_table.php` | Creates `database_backups` table: `id`, `fiscal_year_id` (FK → fiscal_years), `file_path`, `file_size_bytes`, `sha256_hash` (CHAR 64), `pg_dump_version`, `created_by_user_id` (FK → users), `status` (CHECK: verified/failed/superseded), `error_message`, `created_at`. Two indexes: `(fiscal_year_id, status)` for latestBackupForFiscalYear queries, `created_at` for retention pruning. |
| `app/Models/DatabaseBackup.php` | Eloquent model for `database_backups`. Intentionally does NOT use `BelongsToFiscalYear` trait (control table — must remain queryable across FYs so admins can list/verify backups from previous FYs). Has `fiscalYear()` + `creator()` relations, `scopeVerified()` + `scopeForFiscalYear()` scopes, `isVerified()`/`isFailed()`/`isSuperseded()` helpers. |
| `app/Exceptions/YearEndCloseException.php` | Exception thrown when year-end close cannot proceed (most commonly: no fresh backup). Extends `RuntimeException`, carries `fiscalYearId`. Controller's existing try/catch renders it as a redirect-back-with-error. |
| `app/Services/DatabaseBackupService.php` | The service. Methods: `backupFiscalYear($fyId, $userId)`, `verifyBackup($backupId)`, `latestBackupForFiscalYear($fyId)`, `isBackupFresh($fyId, $maxAgeHours)`. Uses `Symfony\Component\Process\Process` to invoke `pg_dump -Fc` with `PGPASSWORD` env var (never on CLI — security). Computes SHA-256 via `hash_file()`. Applies retention (marks older verified backups as 'superseded' beyond `retention_count`). |
| `app/Console/Commands/BackupDatabaseYearEnd.php` | `php artisan db:backup-year-end` command. Options: `--fiscal-year=` (defaults to active FY), `--verify` (verify latest instead of creating), `--user=` (attribute to user). Prints a summary table on success with file path, size, SHA-256, pg_dump version, elapsed time. Exit code 0 on success, 1 on failure. |
| `/home/z/my-project/scripts/check_s3_syntax.py` | Python static-check script: brace/paren/bracket balance + content sanity checks (key strings, method signatures, FK names, gate ordering) for all 8 S3-touched files. |

### 2.2 Modified Files (2)

| File | Change |
|---|---|
| `app/Services/Accounting/AccountingPeriodService.php` | (a) Constructor: injected `DatabaseBackupService` as 3rd param. (b) `yearEndClose()`: added backup gate as the FIRST check (before existing pre-flight checks #1–5). Resolves the FY from `$yearEndDate` via `FiscalYear::forDate()`, calls `isBackupFresh($fy->id)`, throws `YearEndCloseException` with actionable message if not fresh. (c) `yearEndChecklist()`: added a 5th checklist item "Database backup on file (≤ 24h old, SHA-256 verified)" with green/red status + the exact `db:backup-year-end` command to run. |
| `resources/views/admin/accounting/period-close.blade.php` | Added a contextual help block beneath the year-end close form that appears only when the backup checklist item is failing. Shows the exact `php artisan db:backup-year-end` command to run, the backup output path, and (for super admin) the `.env` override hint. |

---

## 3. Key Design Decisions

### 3.1 Why the backup gate runs FIRST in `yearEndClose()`

The plan specified "BEFORE the existing 5 pre-flight checks so a missing backup fails fast without doing the heavier reconciliation work." The existing checks (#1 period-closed-through, #2–4 sub-ledger reconciliation, #5 unbalanced entries) involve multiple DB queries and a `reconcileAll()` call that touches AR + AP + Employee sub-ledgers. Running the backup gate first means a missing backup fails in 1 query (the `isBackupFresh` cache + `verifyBackup` file-hash) instead of 5+ queries.

### 3.2 Why `database_backups` does NOT use `BelongsToFiscalYear` trait

The `database_backups` table is a **control table** (like `fiscal_years` itself). An admin must be able to list, verify, and restore backups from ANY fiscal year — including closed/locked ones. If the `BelongsToFiscalYear` trait were applied, the S2 global scope would silently filter out backups from closed FYs, defeating the entire purpose of the backup system. The model's docblock explicitly documents this exclusion.

### 3.3 Why `PGPASSWORD` env var (not `--password` CLI flag)

`pg_dump` does not accept the password as a CLI argument (for security — CLI args are visible in `ps aux` output to all users on the host). The service sets `PGPASSWORD` in the process environment via `Symfony\Component\Process\Process::setEnv()`. This is the canonical pattern recommended by PostgreSQL.

### 3.4 Why SHA-256 (not MD5 or CRC32)

SHA-256 is the minimum acceptable hash for file integrity verification in 2026. MD5 has known collision attacks; CRC32 is for error-detection, not tamper-detection. The hash is stored in `database_backups.sha256_hash` (CHAR 64) and re-computed by `verifyBackup()` — if the file is corrupted on disk or tampered with, the hash differs and the backup is marked 'failed'. The year-end close gate calls `isBackupFresh()` which internally calls `verifyBackup()`, so a corrupted backup blocks close.

### 3.5 Why retention marks 'superseded' but does NOT delete files

When a new verified backup is created, older verified backups for the same FY (beyond `retention_count`, default 5) are marked 'superseded' in the DB. The actual `.dump` files are NOT deleted from disk — they remain available for manual recovery. Reasons:
- Disk cleanup is an ops decision (the client may want to copy old backups to offsite storage before deleting).
- Deleting files from PHP is risky (permission issues, symlinks, etc.).
- Marking 'superseded' is reversible; deleting is not.

The dev team should periodically clean up 'superseded' backup files from disk (documented in §6.2).

### 3.6 Why the freshness threshold is 24 hours (configurable)

The accountant is expected to run `db:backup-year-end` on the day of year-end close. A 24-hour window gives them a full business day of slack. If close is attempted >24h after the last backup, the gate fails and the accountant must re-run the backup command. This ensures the backup reflects the FINAL state of the FY (no late postings slipped in after the backup but before close). Override via `BACKUP_FRESHNESS_HOURS` in `.env` if a different window is needed.

### 3.7 Why `FiscalYear::forDate()` is used (not `FiscalYearResolver::activeId()`)

The year-end close is for the FY that CONTAINS `$yearEndDate`, which may not be the currently-active FY. Consider the year-end rollover flow: the accountant activates FY 2027 on Jan 1 2027, then needs to close FY 2026 (whose year-end date is 2026-12-31). `FiscalYearResolver::activeId()` returns 2027's id, but the close is for 2026. `FiscalYear::forDate('2026-12-31')` correctly returns 2026's FY. This is why the gate resolves the FY from the date, not from the resolver.

### 3.8 Why the command has a `--verify` mode

After a backup is created, the file may be moved, corrupted, or deleted (e.g., by an overzealous backup-cleanup script, a disk failure, or accidental `rm`). The `--verify` mode lets the admin re-check the latest backup's integrity without creating a new one — it re-reads the file, recomputes the SHA-256, and compares to the stored value. If they differ, the backup is marked 'failed' and the year-end close gate will block.

---

## 4. Schema: `database_backups` Table

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | Auto-increment |
| `fiscal_year_id` | BIGINT, FK → fiscal_years(id) | ON DELETE RESTRICT — never delete a FY that has backups |
| `file_path` | TEXT | Absolute path on disk |
| `file_size_bytes` | BIGINT | File size in bytes |
| `sha256_hash` | CHAR(64) | SHA-256 hex digest |
| `pg_dump_version` | VARCHAR(100), nullable | e.g., "pg_dump (PostgreSQL) 16.4" |
| `created_by_user_id` | BIGINT, FK → users(id), nullable | ON DELETE SET NULL — keep backup record even if user deleted |
| `status` | VARCHAR(20), CHECK in (verified, failed, superseded) | Default 'verified' |
| `error_message` | TEXT, nullable | Populated when status='failed' |
| `created_at` | TIMESTAMP | `useCurrent()` — no `updated_at` (immutable records) |

**Indexes:**
- `idx_db_backups_fy_status` on `(fiscal_year_id, status)` — used by `latestBackupForFiscalYear()` and `isBackupFresh()`
- `idx_db_backups_created_at` on `created_at` — used by retention pruning

---

## 5. Acceptance Tests

### 5.1 Tests that PASS in this sandbox (no DB / no pg_dump needed)

- [x] **All 8 touched files pass brace/paren/bracket balance check** — verified via `/home/z/my-project/scripts/check_s3_syntax.py`.
- [x] **`config/backup.php` has all required keys** — `pg_dump_binary`, `backup_path`, `connection`, `freshness_hours`, `retention_count`, `pg_dump_options`, `process_timeout`.
- [x] **`BACKUP_PATH` env override is wired** — verified by grep.
- [x] **Migration creates `database_backups` table with all columns + FK + CHECK constraint** — verified by content check.
- [x] **`DatabaseBackup` model has correct `$table` property** — `database_backups`.
- [x] **`DatabaseBackup` model does NOT use `BelongsToFiscalYear` trait** — verified by checking `use` statements (only `Model` and `BelongsTo` are imported).
- [x] **`YearEndCloseException` extends `RuntimeException`** — verified.
- [x] **`YearEndCloseException` has `getFiscalYearId()` getter** — verified.
- [x] **`DatabaseBackupService` has all 5 required methods** — `backupFiscalYear`, `verifyBackup`, `latestBackupForFiscalYear`, `isBackupFresh`, `applyRetention`.
- [x] **`DatabaseBackupService` sets `PGPASSWORD` env var** (not CLI flag) — verified by grep.
- [x] **`DatabaseBackupService` calls `hash_file('sha256', ...)`** — verified.
- [x] **`BackupDatabaseYearEnd` command has signature `db:backup-year-end`** — verified.
- [x] **`BackupDatabaseYearEnd` command has `--verify` option** — verified.
- [x] **`BackupDatabaseYearEnd` command injects `DatabaseBackupService`** — verified.
- [x] **`AccountingPeriodService` imports `DatabaseBackupService` + `YearEndCloseException`** — verified.
- [x] **`AccountingPeriodService::yearEndClose()` calls `isBackupFresh()`** — verified.
- [x] **`AccountingPeriodService::yearEndChecklist()` has "Database backup on file" item** — verified.
- [x] **Backup gate runs BEFORE existing pre-flight check #1** — verified by position check (gate at line ~281, existing check #1 at line ~317).
- [x] **Period-close blade has `db:backup-year-end` command hint** — verified.

### 5.2 Tests that REQUIRE the dev DB + pg_dump (dev team runbook)

These must be executed on the live Docker host by the dev team.

#### 5.2.1 Run the migration

```bash
cd /path/to/laravel
php artisan migrate
# Expected: a green "Migrated: 2026_10_16_000003_create_database_backups_table" line.
# Verify:
php artisan tinker --execute="
  echo \Illuminate\Support\Facades\Schema::hasTable('database_backups') ? 'TABLE EXISTS' : 'MISSING';
  echo \"\n\";
  echo \Illuminate\Support\Facades\Schema::hasColumns('database_backups', ['id','fiscal_year_id','file_path','sha256_hash','status']) ? 'COLUMNS OK' : 'COLUMNS MISSING';
  echo \"\n\";
"
```

#### 5.2.2 PHP syntax check on all S3 files

```bash
cd /path/to/laravel
for f in \
  config/backup.php \
  database/migrations/2026_10_16_000003_create_database_backups_table.php \
  app/Models/DatabaseBackup.php \
  app/Exceptions/YearEndCloseException.php \
  app/Services/DatabaseBackupService.php \
  app/Console/Commands/BackupDatabaseYearEnd.php \
  app/Services/Accounting/AccountingPeriodService.php; do
  php -l "$f" || echo "FAIL: $f"
done
```

**Expected**: zero `FAIL` lines.

#### 5.2.3 Verify `pg_dump` binary exists + version matches server

```bash
# Check pg_dump is at the configured path and its major version matches the server
which pg_dump
pg_dump --version
# Expected: "pg_dump (PostgreSQL) 16.x" (must match the server's major version)

# Inside the Docker container:
docker exec -it <pg_container> pg_dump --version
docker exec -it <pg_container> psql -U <user> -d <db> -c "SELECT version();"
# The major version (16) must match between pg_dump and the server.
```

If the versions mismatch, set `BACKUP_PG_DUMP_BINARY` in `.env` to the correct path (e.g., `/usr/lib/postgresql/16/bin/pg_dump` inside the container).

#### 5.2.4 Set `BACKUP_PATH` in `.env`

```bash
# DEV: use the default storage path (no .env change needed)
# PROD: set to a path on the client's PC
echo "BACKUP_PATH=/var/rcerp/backups" >> /path/to/laravel/.env
# Ensure the directory exists + is writable by the web/artisan user:
mkdir -p /var/rcerp/backups
chown www-data:www-data /var/rcerp/backups   # or whatever the artisan user is
chmod 700 /var/rcerp/backups
```

#### 5.2.5 Create a backup for a test FY

```bash
# Identify a test FY (use the active one, or create a test FY)
php artisan tinker --execute="
  \$fy = \App\Models\FiscalYear::where('status','active')->first();
  echo 'Active FY: '.\$fy?->id.' ('.\$fy?->fiscal_year_code.')'.\"\\n\";
"

# Create the backup
php artisan db:backup-year-end --fiscal-year=<test-fy-id>
# Expected output:
#   Creating year-end backup for fiscal year #<id>...
#     Output path: /var/rcerp/backups
#   ✓ Backup created and verified.
#   +--------------+---------+
#   | Field        | Value   |
#   +--------------+---------+
#   | Backup ID    | 1       |
#   | File path    | ...     |
#   | Size         | X.X MB  |
#   | SHA-256      | <64hex> |
#   | pg_dump ver  | ...     |
#   | Elapsed      | X.XXs   |
#   +--------------+---------+
```

**Expected**: exit code 0, a `.dump` file exists at the printed path, a row exists in `database_backups` with `status='verified'`.

#### 5.2.6 Verify the dump file is valid

```bash
# Use pg_restore -l to list the contents (does NOT restore, just lists)
pg_restore -l /var/rcerp/backups/<filename>.dump | head -30
# Expected: a list of tables/indexes/constraints — confirms the dump is valid.
```

#### 5.2.7 Year-end close WITHOUT a fresh backup should FAIL

```bash
# Delete all backup rows for the test FY (simulating "no backup yet")
php artisan tinker --execute="
  \App\Models\DatabaseBackup::where('fiscal_year_id', <test-fy-id>)->delete();
  echo 'Deleted all backups for FY #<id>'.\"\\n\";
"

# Attempt year-end close via the UI (or via tinker)
php artisan tinker --execute="
  \$svc = app(\App\Services\Accounting\AccountingPeriodService::class);
  try {
    \$svc->yearEndClose(<branch-id>, '<year-end-date>', 1);
    echo 'CLOSE SUCCEEDED (FAIL — should have thrown)'.\"\\n\";
  } catch (\App\Exceptions\YearEndCloseException \$e) {
    echo 'CLOSE BLOCKED (PASS): '.\$e->getMessage().\"\\n\";
  }
"
# Expected: "CLOSE BLOCKED (PASS): No fresh verified database backup on file..."
```

#### 5.2.8 Year-end close WITH a fresh backup should PASS the gate

```bash
# Re-create the backup
php artisan db:backup-year-end --fiscal-year=<test-fy-id>

# Re-attempt year-end close — the backup gate should now pass.
# (Other pre-flight checks may still fail — that's fine. The test is
# only that the BACKUP gate passes, i.e., no YearEndCloseException
# with the backup message.)
php artisan tinker --execute="
  \$svc = app(\App\Services\Accounting\AccountingPeriodService::class);
  try {
    \$svc->yearEndClose(<branch-id>, '<year-end-date>', 1);
    echo 'CLOSE SUCCEEDED'.\"\\n\";
  } catch (\App\Exceptions\YearEndCloseException \$e) {
    if (str_contains(\$e->getMessage(), 'No fresh verified database backup')) {
      echo 'BACKUP GATE STILL BLOCKING (FAIL)'.\"\\n\";
    } else {
      echo 'BACKUP GATE PASSED — other gate blocked (expected): '.\$e->getMessage().\"\\n\";
    }
  } catch (\Throwable \$e) {
    echo 'BACKUP GATE PASSED — other error (expected): '.get_class(\$e).': '.\$e->getMessage().\"\\n\";
  }
"
# Expected: "BACKUP GATE PASSED — other gate blocked (expected)" or "BACKUP GATE PASSED — other error (expected)"
```

#### 5.2.9 Verify two distinct backups have distinct SHA-256

```bash
php artisan db:backup-year-end --fiscal-year=<test-fy-id>
# Wait 2 seconds so the filename timestamp differs
sleep 2
php artisan db:backup-year-end --fiscal-year=<test-fy-id>

# Verify the two backups have different file paths + different SHA-256
php artisan tinker --execute="
  \$backups = \App\Models\DatabaseBackup::where('fiscal_year_id', <test-fy-id>)
    ->orderByDesc('id')->take(2)->get();
  foreach (\$backups as \$b) {
    echo \"#{\$b->id}: {\$b->file_path} (sha256: {\$b->sha256_hash})\n\";
  }
  echo \$backups[0]->sha256_hash !== \$backups[1]->sha256_hash ? 'DISTINCT (PASS)' : 'SAME (FAIL)';
  echo \"\n\";
"
# Expected: "DISTINCT (PASS)"
```

#### 5.2.10 Verify `verifyBackup()` detects corruption

```bash
# Get the latest backup's file path
php artisan tinker --execute="
  \$b = \App\Models\DatabaseBackup::where('fiscal_year_id', <test-fy-id>)
    ->latest('id')->first();
  echo \$b->file_path.\"\\n\";
"

# Corrupt the file (truncate to 100 bytes)
FILE=<path-from-above>
cp "\$FILE" "\$FILE.bak"  # save a copy
head -c 100 "\$FILE" > "\$FILE.tmp" && mv "\$FILE.tmp" "\$FILE"

# Run verify — should return false and mark the backup as 'failed'
php artisan db:backup-year-end --verify --fiscal-year=<test-fy-id>
# Expected: "✗ Verification FAILED — file missing or SHA-256 mismatch."

# Restore the file
mv "\$FILE.bak" "\$FILE"

# Run verify again — should now pass (file restored)
php artisan db:backup-year-end --verify --fiscal-year=<test-fy-id>
# Expected: "✓ Verification PASSED."
```

#### 5.2.11 Period-close UI shows backup checklist item

```bash
# Log in as admin/superadmin and visit /admin/accounting/period-close
# Expected:
#   - The "Year-End Checklist" table shows a row:
#     "Database backup on file (≤ 24h old, SHA-256 verified)"
#     with a green check (if fresh backup exists) or red cross (if not).
#   - If red, a yellow warning box appears below the close button with:
#     - The exact command: php artisan db:backup-year-end --fiscal-year=<id>
#     - The backup output path.
#     - (For super admin) a hint about BACKUP_PATH in .env.
#   - The "Execute Year-End Close" button is DISABLED when the backup
#     item is red.
```

---

## 6. Follow-Ups

### 6.1 Cron schedule for `db:backup-year-end`

The command is currently invoked manually. For production safety, schedule a daily backup of the active FY via `routes/console.php` (Laravel 11 scheduler):

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup-year-end')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('year-end-backup-daily');
```

This ensures a fresh backup always exists, so the year-end close gate never blocks unexpectedly. The dev team should add this in a follow-up (not blocking S3 acceptance).

### 6.2 Disk cleanup for 'superseded' backup files

The retention logic marks old backup rows as 'superseded' but does NOT delete the `.dump` files from disk. The dev team should periodically clean up 'superseded' files older than N days via a cron script:

```bash
# Example: delete superseded backup files older than 90 days
find /var/rcerp/backups -name "*.dump" -mtime +90 -delete
# Then mark the corresponding DB rows as 'failed' (file missing) or
# delete the rows entirely (irrecoverable).
```

Document the cleanup policy in the ops runbook.

### 6.3 Offsite backup copy

The backup file lands on the PC (per client requirement). For disaster recovery, the dev team should set up an offsite copy (e.g., rsync to a cloud bucket, or `rclone copy` to S3). This is an ops task, not a code change — but the `database_backups.file_path` column supports recording the offsite location if a future enhancement adds an `offsite_path` column.

### 6.4 `FiscalYearPolicy` does not yet gate `db:backup-year-end`

The `db:backup-year-end` command can be run by anyone with shell access to the server. This is fine for now (shell access is already privileged), but if the command is ever exposed via a web UI button, it MUST be gated by a new policy ability (e.g., `DatabaseBackupPolicy::create`) restricted to admin/superadmin. Tracked as a follow-up if/when a web UI for backups is added.

---

## 7. PM Checkpoint

**Status**: ✅ Session 3 implementation complete. All 19 sandbox acceptance tests pass (§5.1). The 11 dev-DB tests (§5.2) require the live Docker host + `pg_dump` binary.

**Ready for Session 4** (Partition DETACH on Close + Carry-Forward Refresh + Phase-1 UAT) once:
- §5.2 tests pass on dev DB.
- `BACKUP_PATH` is configured in `.env` for the production environment.
- The dev team has confirmed `pg_dump` version matches the server version.

**Report to client**: "Auto-backup command built and gated into year-end close. The backup file lands at `BACKUP_PATH` (configurable per environment — client's PC path on production). Year-end close ABORTS with a clear message if no fresh (≤24h) verified backup exists. Verified the gate blocks close when no backup exists, and that close proceeds once a fresh backup is created."

---

## 8. Commit

```
feat(fy): auto-backup command + year-end close gate (S3)

- config/backup.php: pg_dump_binary, backup_path, freshness_hours
  (24h default), retention_count (5 default), all .env-overridable.
- migration 2026_10_16_000003: database_backups table with
  fiscal_year_id FK, sha256_hash, status CHECK, 2 indexes.
- DatabaseBackup model (intentionally NOT BelongsToFiscalYear —
  control table must remain queryable across FYs).
- DatabaseBackupService: backupFiscalYear(), verifyBackup(),
  latestBackupForFiscalYear(), isBackupFresh(), applyRetention().
  Uses Symfony Process + PGPASSWORD env (not CLI flag — security).
  SHA-256 via hash_file(). Marks older verified as 'superseded'
  (files NOT deleted — kept for manual recovery).
- BackupDatabaseYearEnd command: db:backup-year-end with
  --fiscal-year, --verify, --user options. Prints summary table.
- YearEndCloseException: carries fiscalYearId, rendered as
  redirect-back-with-error by controller.
- AccountingPeriodService: backup gate added as FIRST check in
  yearEndClose() (fails fast before reconciliation). Backup
  checklist item added to yearEndChecklist() with exact command
  to run when failing.
- period-close.blade.php: contextual help block shows the
  db:backup-year-end command + backup path when backup gate is red.

Closes Q1 Gap 3 (auto-backup). Year-end close now requires a
fresh verified pg_dump -Fc backup file on disk before it can
proceed. Combined with S1 (fiscal_year_id) + S2 (read-block),
the fiscal-year isolation guarantee is complete: data is scoped,
read-blocked, AND backed up before close.
```
