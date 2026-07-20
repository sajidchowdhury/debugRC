# Telegram Alerts — Setup & Implementation Guide

Remote Center ERP uses a **Telegram Bot** for instant operational alerts. This document covers setup, the five live **sales** alerts, and how to add alerts for other modules later.

---

## 1. Prerequisites

| Item | Notes |
|------|--------|
| Bot token | From [@BotFather](https://t.me/BotFather) on Telegram |
| Migration 043 | `database/migrations/043_users_telegram_user_id.sql` adds `users.telegram_user_id` |
| User setup | Each recipient saves their numeric chat id (via [@userinfobot](https://t.me/userinfobot)) and sends `/start` to your bot once |

### Configuration (`config/local.php`)

```php
define('TELEGRAM_BOT_TOKEN', '123456789:ABC...');
define('TELEGRAM_ALERTS_ENABLED', true);
```

Set `TELEGRAM_ALERTS_ENABLED` to `false` to disable all outbound Telegram messages without removing code.

### Logs

| File | Purpose |
|------|---------|
| `logs/telegram.log` | Every send attempt (sent / skipped / error) |
| `logs/reconciliation_alerts.log` | Scheduled reconciliation / audit failures (JSON lines) |
| `logs/app.log` | Summary via `Logger` (warnings on partial failure) |

---

## 2. Architecture

```
Controller (after successful DB commit)
    └── SalesTelegramNotifier::safe(fn)     ← never throws; won't break the main action
            └── builds message + recipient list
                    └── TelegramNotificationService::deliver()
                            └── Telegram::sendMessage()  ← Bot API (cURL)
```

### Core files

| File | Role |
|------|------|
| `core/Telegram.php` | Low-level Bot API client |
| `app/services/Notification/TelegramNotificationService.php` | Generic delivery + alert type constants |
| `app/services/Notification/SalesTelegramNotifier.php` | Sales-specific messages & recipient rules |
| `app/services/Notification/AccountingTelegramNotifier.php` | Scheduled GL reconciliation alerts |
| `app/models/NotificationModel.php` | Resolve users by role, branch, employee id |

### Recipient resolution helpers (`NotificationModel`)

| Method | Use when |
|--------|----------|
| `getBranchUsersForTelegram($role, $branchId)` | One role, one branch (e.g. warehouse managers) |
| `getUsersForTelegramByRoles($roles, $branchId?)` | Global or branch-scoped roles (admin, accountant) |
| `getUsersByEmployeeIds($employeeIds)` | Invoice salesman / sales-by on employee master |
| `getUsersTelegramProfilesByIds($userIds)` | Specific logged-in user (e.g. payment receiver) |
| `mergeTelegramRecipients(...$lists)` | Combine lists without duplicate user ids |

Users **without** `telegram_user_id` are skipped and logged — not an error.

---

## 3. Live sales alerts (implemented)

| # | Event | Trigger | Recipients | Controller / method |
|---|--------|---------|------------|---------------------|
| 1 | **Sales invoice created** | Invoice finalized (POS) | `warehouse_manager` of invoice branch | `SalesController::final_sales` |
| 2 | **Challan finalized** | Godown dispatch complete | Invoice `salesman_id` + `sales_person` (if linked to login) + branch `warehouse_manager` | `ChallanController::create_final_challan` |
| 3 | **Sales return created** | Return saved (pending) | All `admin` | `SalesReturnController::store` |
| 4 | **Sales return received** | Warehouse confirms return | `admin` + branch `warehouse_manager` + user who confirmed | `SalesReturnController::confirm_store` |
| 5 | **Payment on today invoice** | Payment saved from sales/today | `admin` + `accountant` | `SalesController::save_payment` |

**Note on #5:** Telegram fires only when the linked invoice’s **`invoice_date` is today** (`CURDATE()`), matching the “today invoice” workspace.

### Message contents (typical)

Each alert includes key business fields (codes, customer, amounts, branch) and a **clickable link** back into the ERP (godown, challan slip, return confirm, today list, etc.).

---

## 4. Scheduled reconciliation alert (implemented)

| Event | Trigger | Recipients | Hook |
|--------|---------|------------|------|
| **GL reconciliation issues** | Cron `run_gl_reconciliation.php` finds `has_issues` on any branch | `admin` + `accountant` | `AccountingTelegramNotifier::notifyReconciliationIssues()` |

- One consolidated Telegram message per cron run (per-branch details inside).
- File log always written via `ReconciliationService::writeAlert()` — even when Telegram is disabled.
- Review log in **sales/reconcile** UI or `php database/scripts/review_reconciliation_alerts.php`.

---

## 5. User self-service

- **Path:** User menu → Two-factor authentication page → **Telegram notifications**
- **Admin path:** User edit → Telegram User ID field
- **API:** `POST user/update_telegram` with `telegram_user_id` (empty clears)

---

## 6. How to add a new alert (other modules)

Follow this checklist when adding purchase, stock, reconciliation, HR, etc.

### Step 1 — Define the business rule

Document in one line:

- **Event name** (e.g. `purchase_grn_posted`)
- **When** (after which model method succeeds)
- **Who** (roles, branch scope, specific user ids)
- **Message fields** and **deep link** URL

### Step 2 — Add an alert constant

In `TelegramNotificationService.php`:

```php
public const ALERT_PURCHASE_GRN = 'purchase_grn_posted';
```

### Step 3 — Create a module notifier (recommended)

For non-sales modules, add e.g. `PurchaseTelegramNotifier.php` mirroring `SalesTelegramNotifier.php`:

```php
class PurchaseTelegramNotifier
{
    public static function safe(callable $callback): void { /* same pattern */ }

    public function notifyGrnPosted(int $receiveId): array
    {
        $ctx = $this->fetchGrnContext($receiveId);
        $recipients = $this->notifications->getUsersForTelegramByRoles(['admin', 'accountant']);
        $message = $this->buildGrnMessage($ctx);
        return $this->telegram->deliver(
            $recipients,
            $message,
            TelegramNotificationService::ALERT_PURCHASE_GRN,
            ['receive_id' => $receiveId]
        );
    }
}
```

Keep **SQL/context loading** and **HTML message formatting** inside the notifier (or a dedicated model `getXTelegramContext()` method).

### Step 4 — Hook the controller (after success only)

```php
require_once __DIR__ . '/../services/Notification/PurchaseTelegramNotifier.php';

// Inside controller, AFTER commit / success response data is known:
if (($result['status'] ?? '') === 'success') {
    PurchaseTelegramNotifier::safe(function () use ($result) {
        (new PurchaseTelegramNotifier())->notifyGrnPosted((int)$result['receive_id']);
    });
}
```

**Rules:**

- Call **after** DB transaction commits (same place you audit-log).
- Wrap in `::safe()` so Telegram never breaks the business action.
- Do **not** send on validation failures or rollbacks.

### Step 5 — Test

1. Set bot token in `config/local.php`.
2. Give test users `telegram_user_id` and `/start` the bot.
3. Perform the action once.
4. Check `logs/telegram.log` for `sent`, `skipped`, or `error`.
5. Confirm users without Telegram id are skipped (expected).

---

## 7. Planned alerts (not implemented yet)

Use the Step 5 pattern above when you reach these modules:

| Module | Suggested event | Suggested recipients |
|--------|-----------------|----------------------|
| Stock | Low stock below reorder | Branch `warehouse_manager` |
| Customer | Overdue payment reminder | `accountant`, assigned salesman |
| Purchase | GRN posted | Branch `warehouse_manager`, `accountant` |
| HR / Payroll | Salary sheet ready | Employee’s own Telegram id |

Add rows to this table as you implement them.

---

## 8. Security notes

- Store **bot token only** in `config/local.php` (gitignored) — never commit it.
- `telegram_user_id` is a **personal chat id**, not a secret, but treat user profiles accordingly.
- Messages use Telegram **HTML** parse mode; always escape dynamic text with `Telegram::escapeHtml()`.
- The bot can only message users who have **started the bot** at least once.

---

## 9. Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| No messages at all | Token missing, `TELEGRAM_ALERTS_ENABLED` false, or migration 043 not applied |
| Some users never get alerts | `telegram_user_id` empty, or user never `/start`ed the bot |
| `403 Forbidden` in log | User blocked the bot or wrong chat id |
| Challan alert missing salesman | Employee has no linked `users` row, or wrong `salesman_id` on invoice |
| Payment alert missing | Invoice date is not today (by design for alert #5) |
| Reconciliation Telegram missing | Cron not run, or no branch-scoped issues, or admin/accountant lack `telegram_user_id` |

---

## 10. Quick reference — hook locations

```php
// 1. Invoice
SalesTelegramNotifier::safe(fn () => (new SalesTelegramNotifier())->notifyInvoiceCreated($invoiceId));

// 2. Challan
SalesTelegramNotifier::safe(fn () => (new SalesTelegramNotifier())->notifyChallanCreated($payload));

// 3. Return created
SalesTelegramNotifier::safe(fn () => (new SalesTelegramNotifier())->notifyReturnCreated($returnId));

// 4. Return received
SalesTelegramNotifier::safe(fn () => (new SalesTelegramNotifier())->notifyReturnReceived($returnId, $userId));

// 5. Today invoice payment
SalesTelegramNotifier::safe(fn () => (new SalesTelegramNotifier())->notifyTodayInvoicePayment($paymentId, $invoiceId));

// 6. GL reconciliation (cron only)
AccountingTelegramNotifier::safe(fn () => (new AccountingTelegramNotifier())->notifyReconciliationIssues($branchReports));
```

For generic (non-sales) alerts without a module notifier:

```php
$telegram = new TelegramNotificationService();
$telegram->sendAlertToBranchRole($branchId, 'warehouse_manager', $htmlMessage, TelegramNotificationService::ALERT_LOW_STOCK);
```

---

*Last updated: Phase 4C complete — sales matrix + reconciliation cron Telegram + alert log review.*
