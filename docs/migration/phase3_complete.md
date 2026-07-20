# Phase 3 — Laravel Foundation + Shared Session + Simplified Auth (Complete)

**Date:** Phase 3 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

### 3.1 — Laravel 11 Scaffold ✅
Complete Laravel 11 application skeleton written (no `composer create-project` needed — all files are in the repo). On the VPS, run `composer install` to pull the framework.

**Files:**
- `composer.json` — Laravel 11 + Sanctum + Predis + Larastan + Pint + Debugbar
- `artisan` — CLI entry point
- `bootstrap/app.php` — Laravel 11 bootstrap (routing + middleware registration)
- `public/index.php` — Front controller
- `.env.example` — Full environment template (PostgreSQL + Redis + session bridge config)
- `package.json` + `vite.config.js` — Frontend asset build (minimal)
- `config/` — app, auth, session, database, cache, queue, mail, roles

### 3.2 — Shared Session Bridge ✅
The critical innovation that lets legacy PHP and Laravel coexist:

**`app/Session/LegacySessionBridge.php`**
- Reads/writes the legacy PHP native session from Redis
- Key format: `PHPREDIS_SESSION:<session_id>` (phpredis default)
- Value format: PHP session-serialized data (`key|serialized_value;...`)
- Uses `session_decode()` / `session_encode()` for format compatibility
- Methods: `read()`, `write()`, `destroy()`, `getSessionIdFromRequest()`

**`app/Http/Middleware/SyncLegacySession.php`**
- Runs FIRST in the middleware stack (before Laravel's auth)
- Reads the `PHPSESSID` cookie → reads legacy session from Redis
- If `user_id` is present in legacy session:
  1. Loads the User from DB (with Employee + Branch)
  2. Checks `credential_version` (constant-time `hash_equals`)
  3. If valid: `Auth::login($user)` + populates Laravel session
  4. If invalid: destroys legacy session (password was changed)
- If no legacy session: tries remember-me cookie
- On Laravel login (AuthController): writes TO the legacy session so legacy PHP sees the login

**How it works end-to-end:**
1. User logs in via legacy PHP → legacy writes `user_id` etc. to `$_SESSION` → stored in Redis
2. User navigates to `/admin/dashboard` (Laravel) → `SyncLegacySession` reads Redis → `Auth::login()` → user is authenticated in Laravel
3. User logs in via Laravel `/admin/login` → `AuthenticatedSessionController` writes to legacy session via `LegacySessionBridge::write()` → user is authenticated in legacy PHP
4. User logs out from either side → both sessions are destroyed

**VPS requirement:** Legacy PHP must use Redis sessions:
```ini
; /etc/php/8.3/fpm/conf.d/20-redis-session.ini
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379?database=1"
session.cookie_name = PHPSESSID
session.gc_maxlifetime = 28800
```

### 3.3 — Simplified Auth System ✅
Login is **username + password only** (NO 2FA, NO OTP — per Phase 0 decision).

**Auth Services** (`app/Services/Auth/`):
| Service | File | Function |
|---|---|---|
| LoginRateLimiter | `LoginRateLimiter.php` | Per-username rate-limit (5 attempts / 15 min) via Redis |
| AccountLockout | `AccountLockout.php` | Per-account lockout (5 fails → 15min) on `users.failed_login_count` + `locked_until` |
| CredentialVersion | `CredentialVersion.php` | Monotonic counter on `users.credential_version`; `hash_equals` comparison; `bump()` invalidates other sessions |
| PasswordPolicy | `PasswordPolicy.php` | 8-128 chars, letter+number+special, **HIBP Pwned Passwords k-anon check**; also `validateUsername()` (min 4, alphanumeric+underscore) |
| RememberMeManager | `RememberMeManager.php` | Selector:validator scheme, SHA-256 hashed, token rotation on use, `revokeAllForUser()` on password change |
| UserAuditLogger | `UserAuditLogger.php` | Dual-write: `user_audit_log` table (jsonb) + `storage/logs/user_audit.log` (JSON lines) |

**Auth Controllers** (`app/Http/Controllers/Auth/`):
| Controller | Routes | Function |
|---|---|---|
| AuthenticatedSessionController | `GET/POST /login`, `POST /logout` | Login (rate-limit → lockout → bcrypt verify → credential-version check → session regenerate → Laravel login + legacy session write + remember-me + audit log), Logout (revoke remember-me + destroy legacy session + Laravel logout + session invalidate) |
| PasswordResetLinkController | `GET/POST /forgot` | Forgot password (rate-limited, generic message to prevent enumeration, SHA-256 hashed token, 1hr expiry) |
| NewPasswordController | `GET /reset/{token}`, `POST /reset` | Reset password (validate token → password policy → update hash → invalidate token → bump credential_version → revoke remember-me → audit log) |

**Middleware** (`app/Http/Middleware/`):
| Middleware | Alias | Function |
|---|---|---|
| SyncLegacySession | `legacy.session` | Reads legacy session → Laravel auth sync (runs first) |
| CheckCredentialVersion | (global) | Checks credential_version on every authenticated request; invalidates if mismatched |
| EnsureRole | `role` | RBAC: `->middleware('role:admin')` or `->middleware('role:admin,accountant')`; superadmin bypass |

**Models** (`app/Models/`):
- `User` — maps to `users` table; `getAuthPassword()` returns `password_hash` (legacy column name); role helpers (`isSuperadmin()`, `isAdmin()`, `hasRole()`)
- `Employee` — role stored here (not on User); belongsTo Branch
- `Branch` — 4 branches
- `Warehouse` — belongsTo Branch

**Routes** (`routes/web.php`):
- `GET/POST /login` → login
- `POST /logout` → logout
- `GET/POST /forgot` → password reset request
- `GET /reset/{token}`, `POST /reset` → password reset
- `GET /dashboard` → authenticated dashboard (minimal stats)

**Views** (`resources/views/`):
- `layouts/app.blade.php` — Bootstrap 5 layout (same as legacy, uses `/assets/css/custom.css`)
- `auth/login.blade.php` — login form (username + password + remember-me + forgot link)
- `auth/forgot.blade.php` — forgot password form
- `auth/reset.blade.php` — reset password form
- `dashboard/index.blade.php` — minimal dashboard with stats cards + "Back to Legacy App" link

### 3.4 — Migration for auth support tables ✅
`database/migrations/2025_01_02_000001_create_auth_support_tables.php`:
- `password_reset_tokens` (SHA-256 hashed, 1hr expiry, single-use)
- `remember_tokens` (selector:validator, token rotation)
- `users.remember_token` column (Laravel native remember-me)
- `users.last_login_user_agent` column

---

## What still needs to happen ON THE VPS

Phase 3 code is **100% written**. Deployment steps:

```bash
# 1. Install Composer on the VPS
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 2. Install Laravel dependencies
cd /var/www/rcerp_v2/laravel
composer install --no-dev --optimize-autoloader

# 3. Configure environment
cp .env.example .env
php artisan key:generate
# Edit .env: set DB_PASSWORD, REDIS_PASSWORD, APP_URL, MAIL_* etc.

# 4. Run migrations (Phase 2 baseline + Phase 3 auth tables)
php artisan migrate

# 5. Configure PHP-FPM for Redis sessions
sudo tee /etc/php/8.3/fpm/conf.d/20-redis-session.ini << 'EOF'
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379?database=1"
session.cookie_name = PHPSESSID
session.gc_maxlifetime = 28800
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
EOF
sudo systemctl restart php8.3-fpm

# 6. Configure Nginx (see nginx.conf.example)
#    /admin/* → Laravel public/index.php
#    /* → legacy public/index.php
#    /assets/*, /uploads/* → shared static files

# 7. Test the session bridge
#    a. Log in via legacy PHP (http://your-vps/auth/login)
#    b. Navigate to http://your-vps/admin/dashboard → should be authenticated
#    c. Log out → should be logged out from both
```

---

## Credentials needed from the user

To deploy Phase 3 on the VPS, I need the following. **Do NOT send these in chat** — set them in the VPS `.env` file directly.

| Credential | Where it goes | Notes |
|---|---|---|
| **VPS SSH access** | For deploying code | IP + SSH key or password |
| **VPS sudo access** | For installing PHP/PG/Redis/Nginx | Phase 1 prerequisite |
| **PostgreSQL password** for `rcerp_app` user | `.env` → `DB_PASSWORD` | Set during Phase 1 provisioning |
| **Redis password** (if any) | `.env` → `REDIS_PASSWORD` | Default: no password (VPS-local only) |
| **APP_KEY** | `.env` → `APP_KEY` | Auto-generated by `php artisan key:generate` |
| **Mail SMTP credentials** | `.env` → `MAIL_*` | For password reset emails (optional in Phase 3 — can use log driver) |
| **Telegram bot token** (new, rotated) | `.env` → `TELEGRAM_BOT_TOKEN` | For business alerts (not login). Rotate the leaked one. |
| **Legacy app URL** | `.env` → `LEGACY_APP_URL` | e.g. `https://erp.yourdomain.com` — for the "Back to Legacy App" link |
| **Domain name** for the ERP | Nginx server_name | e.g. `erp.yourcompany.com` |

**I do NOT need:**
- Your GitHub password (I use the token already provided)
- The old/leaked Telegram token (that's being rotated)
- Any user passwords (those are in the DB, bcrypt-hashed)

---

## Verification checklist (for VPS deployment)

- [ ] `composer install` succeeds
- [ ] `php artisan key:generate` sets APP_KEY
- [ ] `php artisan migrate` creates all tables (Phase 2 + Phase 3)
- [ ] `php artisan serve` (or Nginx) serves `/admin/login`
- [ ] Login with existing legacy user works
- [ ] After legacy login, `/admin/dashboard` is accessible (session bridge works)
- [ ] Logout clears both sessions
- [ ] 5 failed logins locks the account for 15 min
- [ ] Password reset flow works (token generated, email sent or logged)
- [ ] `credential_version` check invalidates session on password change
- [ ] Role middleware blocks unauthorized access (`/admin/users` → 403 for non-admin)

---

## Next phase

**Phase 4 — Master Data Modules.** Port products, customers, suppliers, employees, banks, ledgers, branches, warehouses from legacy PHP to Laravel. Each is independently shippable.
