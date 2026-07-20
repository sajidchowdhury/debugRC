# Phase 0 — Pre-Migration Security Cleanup (Complete)

**Date:** Phase 0 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (new private repo — clean history, no leaked secrets)

## What was done in code

### 1. Fixed `core/Database.php` hard-coded credentials bug
- **Before:** `Database.php` ignored the `DB_HOST/DB_USER/DB_PASS/DB_NAME` constants from `config.php` and hard-coded `localhost/root/''/osudlagb_remotecenter` as private properties.
- **After:** Constructor reads from the defined constants (with safe fallbacks). Environment variables and `config/local.php` overrides now take effect.

### 2. Removed TOTP 2FA + PendingLogin + Telegram login (completely)
Per project decision, login is now **username + password only** (with rate-limiting, account lockout, credential-version, remember-me).

**Files deleted (5):**
- `core/Totp.php`
- `core/TwoFactorAuth.php`
- `core/PendingLogin.php`
- `app/views/auth/verify_2fa.php`
- `app/views/user/two_factor.php`

**Files edited (12):**
- `app/controllers/AuthController.php` — removed `require_once` for TwoFactorAuth/PendingLogin, removed `PendingLogin::isActive()` guard in `login()`, removed `totp_enabled` from SELECT, removed the TOTP branch, removed entire `verify_2fa()` method, removed `PendingLogin::clear()` in `logout()`, removed `showVerify2faForm()` helper.
- `app/controllers/UserController.php` — removed 6 methods: `two_factor()`, `two_factor_setup()`, `two_factor_confirm()`, `two_factor_disable()`, `two_factor_qr()`, `admin_disable_two_factor()`. Kept `update_telegram()` (business-alert recipient management) with redirects repointed to `dashboard`.
- `public/index.php` — removed `require_once PendingLogin`, removed PendingLogin home-route check, removed the "Pending 2FA must complete" redirect block.
- `core/RememberMe.php` — removed the `totp_enabled`/`PendingLogin::start()` branch in `attemptRestore()`; remember-me now logs in directly. Removed `totp_enabled` from the `fetchUserRow()` SELECT.
- `app/services/Security/MenuAccess.php` — removed 5 two_factor actions from the `$exempt` list; removed `admin_disable_two_factor` from `$editActions`.
- `app/config/route_roles.php` — removed `admin_disable_two_factor` route entry.
- `app/views/layouts/header.php` — removed "Two-Factor Authentication" dropdown link.
- `app/views/employee/account.php` — removed `$twoFaEnabled` variable; removed the "Disable 2FA" admin form.
- `app/views/user/index.php` — removed "Two-factor auth" quick link.
- `app/models/UserModel.php` — removed `totp_enabled` from `getUserAccountSummaryByEmployeeId()` SELECT.
- `config/config.php` — removed `AUTH_2FA_ISSUER` define block.
- `config/local.php.example` — removed `AUTH_2FA_ISSUER` example line.

**New migration:**
- `database/migrations/046_drop_totp_columns.sql` — idempotently drops `users.totp_secret` and `users.totp_enabled` columns (run after deploying code changes).

**Verification:** `grep -rnE "totp_enabled|totp_secret|TwoFactorAuth|PendingLogin|verify_2fa|core/Totp" --include="*.php"` returns **zero** code references (only Phase 0 removal comments remain).

### 3. Enforced username policy
- `app/models/UserModel.php` — added `validateUsername()`: min 4 chars, max 50, alphanumeric + underscore only. Called in both `createUser()` and `updateUser()`.
- Prevents trivially-weak usernames like `123`, `222`, `333` that were in the leaked SQL dump.

### 4. Added CSRF validation to 16 controllers
- 25 new `$this->validateCSRF()` calls added across 9 controllers that had POST handlers without protection:
  - `StockTakeController` (6), `PurchaseOrderController` (2), `PurchaseReturnController` (3), `PurchaseReceiveController` (3), `DamageController` (2), `StockAdjustmentController` (2), `WarehouseTransferController` (2), `BranchDemandController` (4), `PaymentController` (1).
- 7 controllers had no POST branches (GET-only) — no change needed.
- `AccountingPeriodController` already had CSRF — no change needed.

### 5. Kept Telegram business alerts
- `core/Telegram.php`, `app/services/Notification/*TelegramNotifier.php` — **kept** (these are sales/reconciliation/accounting alerts, not login).
- `users.telegram_user_id` column — **kept** (recipient resolution for business alerts).

## What still requires MANUAL action (cannot be done in code)

These items are tracked in the README and must be completed by the project owner:

- [ ] **Rotate Telegram bot token** — the old token was committed to the public `RC_ERP` repo. Revoke in @BotFather, create new token, set in production `config/local.php`.
- [ ] **Rotate FCM server key** — revoke legacy server key, replace with Firebase Admin SDK service-account JSON.
- [ ] **Rotate FCM VAPID key pair** — regenerate in Firebase console.
- [ ] **Reset all production user passwords** — bcrypt hashes for users `123`, `222`, `333` were in the public SQL dump. Force password reset on next login.
- [ ] **Run migration 046** on production MySQL: `php database/run_migrations.php` (drops `totp_secret` + `totp_enabled` columns).
- [ ] **Delete or make-private the old public repo** `sajidchowdhury/RC_ERP` (contains leaked secrets in history).
- [ ] **Delete or make-private the public repo** `sajidchowdhury/RC_ERP_Laravel` (created 2026-07-15, may contain sensitive content).
- [ ] **Set production `config/local.php`** with new credentials (chmod 600, never committed — gitignored).
- [ ] **Set `INVESTIGATION_SHOW_OTP_ON_FAIL=false`** in production (Investigation Mode OTP will be fully removed in Phase 11).

## Verification summary

| Check | Result |
|---|---|
| 2FA/TOTP/PendingLogin code references | 0 (clean) |
| CSRF coverage on 16 target controllers | All POST branches protected |
| Leaked Telegram/FCM/bcrypt secrets in repo | 0 (clean) |
| Firebase web API keys in client JS | Present (public by design — not a secret) |
| `config/local.php` committed | No (gitignored) |
| `osudlagb_remotecenter.sql` data dump committed | No (gitignored) |
| Username policy enforced | Yes (min 4, alphanumeric+underscore) |
| Database.php credentials bug | Fixed (reads from config constants) |

## Next phase

**Phase 1 — VPS BDIX Provisioning.** Stand up the target VPS (Ubuntu 22.04 + PHP 8.3 + PostgreSQL 16 + Redis + Nginx), ready to receive the PostgreSQL migration in Phase 2.
