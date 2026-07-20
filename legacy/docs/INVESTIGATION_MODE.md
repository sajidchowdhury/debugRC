# Investigation Mode — Operational Guide



When an external party reviews the business, **Investigation Mode** limits **report date ranges only**. Everything else (sales, users, permissions) works exactly as normal.



## Normal vs investigation



| | Normal | Investigation ON |

|---|--------|------------------|

| **Reports** | Any dates you choose | **Jul–Jun fiscal year only** (1 year window) |

| **Superadmin / admin** | Full access | **Same full access** |

| **Sales, challan, users** | Normal | **Normal** |



The footer shows a small investigation icon while mode is ON.



## Workflow (scan only)



1. Trained employee logs in and **scans the QR** (`/investigation/scan?t=…`).

2. Mode **OFF** → turns **ON** automatically.

3. All users (including superadmin) see reports capped to the current **Jul 1 – Jun 30** fiscal year.

4. Scan again → OTP → enter code → mode **OFF** → reports use any date again.



## Setup



```php

// config/local.php

define('INVESTIGATION_QR_SECRET', 'use-a-long-random-string-here');

define('INVESTIGATION_COMPANY_EMAIL', 'compliance@yourcompany.com');

// optional — default is July (7)

// define('INVESTIGATION_FISCAL_START_MONTH', 7);

```



Run `php database/run_migrations.php`, then superadmin → **`/investigation/settings`** → add activators → print QR.



## Audit



Events in `logs/user_audit.log`: `investigation_mode_activated`, `investigation_mode_deactivated`, activator add/remove.

## For developers

When adding or changing reports, follow **[REPORT_DEVELOPMENT.md](./REPORT_DEVELOPMENT.md)** (investigation date clamping checklist).
