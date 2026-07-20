# Report development guide

Read this **before adding or changing any report** in Remote Center ERP.

Related: [INVESTIGATION_MODE.md](./INVESTIGATION_MODE.md) (QR workflow and business rules).

---

## Investigation mode — the extra rule

When **investigation mode is ON** (global flag in `investigation_windows`):

| Area | Behavior |
|------|----------|
| **Reports** | Date filters are **capped to the current fiscal year** (default **Jul 1 → Jun 30**, end date capped at today) |
| **All users** | Same rule — including superadmin |
| **Everything else** | Unchanged (sales, users, permissions, writes) |

When investigation mode is **OFF**, reports use whatever dates the user selects.

**Do not** re-introduce old behavior: no read-only POST blocking, no branch scoping, no hiding superadmin during investigation.

---

## Where logic lives

| File | Role |
|------|------|
| `core/InvestigationMode.php` | `getReportPeriod()`, `clampReportDates()`, `clampAsOfDate()`, `isGloballyActive()` |
| `app/controllers/ReportController.php` | `resolveReportDates()`, `resolveAsOfDate()`, `attachReportPeriodMeta()` |
| `config/local.php` | Optional `INVESTIGATION_FISCAL_START_MONTH` (default `7` = July) |

Fiscal window is computed only when `InvestigationMode::isGloballyActive()` is true.

---

## Adding a new report in `ReportController`

### 1. Date range reports (`from_date` / `to_date`)

Always resolve dates through the controller helper **before** querying or passing to the view:

```php
$input = array_merge($_GET, $_POST); // or $_GET only

$range = $this->resolveReportDates(
    $input['from_date'] ?? null,
    $input['to_date'] ?? null,
    date('Y-m-d', strtotime('-30 days')), // your normal default FROM
    date('Y-m-d')                          // your normal default TO
);
$from_date = $range['from'];
$to_date   = $range['to'];

$data['from_date'] = $from_date;
$data['to_date']   = $to_date;
$this->attachReportPeriodMeta($data, $range);
```

Use `$from_date` / `$to_date` for:

- Model/report class queries
- AJAX JSON responses
- CSV/PDF exports (same clamped values)

**Never** read `from_date` / `to_date` directly from `$_GET` / `$_POST` without `resolveReportDates()`.

### 2. “As of” reports (`as_of_date`)

```php
$as_of_date = $this->resolveAsOfDate($_GET['as_of_date'] ?? null, date('Y-m-d'));
$data['as_of_date'] = $as_of_date;

if ($period = InvestigationMode::getReportPeriod()) {
    $data['investigation_report_period'] = $period['label'];
}
```

### 3. AJAX / secondary endpoints

Any report sub-action that accepts dates (e.g. `ProductMovementExplanation`) must call `resolveReportDates()` the same way as the main action.

### 4. Views

- Pass clamped dates back into filter inputs so the UI matches query results.
- Optionally show a notice when `$investigation_report_period` is set (see `app/views/Report/index.php`).

Example in view:

```php
<?php if (!empty($investigation_report_period)): ?>
<div class="alert alert-warning small py-2">
    Investigation mode — data limited to <?= htmlspecialchars($investigation_report_period, ENT_QUOTES) ?>.
</div>
<?php endif; ?>
```

---

## Reports outside `ReportController`

If a report lives in another controller (e.g. `StockTakeController::exportVarianceReport`):

1. `require_once` `core/InvestigationMode.php`
2. Call `InvestigationMode::clampReportDates($from, $to)` or `clampAsOfDate($date)` before running queries
3. When investigation is active, default missing dates to `getReportPeriod()['from']` / `['to']`

Do **not** duplicate fiscal-year math in controllers — use `InvestigationMode` only.

---

## Report model classes (`app/models/Reports/`)

- Models should receive **already-clamped** dates from the controller.
- Models must **not** check investigation mode themselves (keeps one enforcement point).

---

## Catalog / routing

When registering a new report in `app/helpers/ReportsCatalog.php`:

- Route through `ReportController` when possible.
- Ensure the controller action follows the patterns above.

---

## Checklist for every new report PR

- [ ] `from_date` / `to_date` go through `resolveReportDates()` (or `clampReportDates()` if not in `ReportController`)
- [ ] `as_of_date` goes through `resolveAsOfDate()` / `clampAsOfDate()`
- [ ] Export and AJAX paths use the same clamped dates as the main page
- [ ] View shows `investigation_report_period` notice when set (recommended)
- [ ] No investigation-specific branch or role restrictions added to reports
- [ ] Tested with investigation mode **OFF** (any dates) and **ON** (Jul–Jun window only)

---

## Quick test

1. Turn investigation mode ON (scan QR as activator).
2. Open the report — dates should fall within the fiscal window shown on Reports index.
3. Try a wider date range in filters — results should still be clamped.
4. Turn investigation mode OFF — full date range works again.

---

## Config reference

```php
// config/local.php
define('INVESTIGATION_FISCAL_START_MONTH', 7); // July; fiscal year Jul–Jun
```

See `config/config.php` for defaults and env overrides.
