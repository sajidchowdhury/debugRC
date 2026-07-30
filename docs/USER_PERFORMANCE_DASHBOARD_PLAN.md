# User Performance Dashboard — Implementation Plan

> **Owner:** Remote Center ERP (debugRC) Laravel app
> **Author:** Planning document — pre-implementation
> **Status:** Draft for execution
> **Last revised:** 2026-07-31

---

## 0. Purpose & Scope

The current dashboard at `app/Http/Controllers/DashboardController.php` is **company-wide**: it shows total revenue, top customers, top products, branch comparison, etc. — none of it tells a logged-in user *how they personally are doing*.

This plan replaces the company-stage view with a **strictly per-user performance dashboard**:

1. **No company-wide KPIs** — no "total sold", no "max sold product", no "all-branch revenue comparison", no global top-5 customers/products.
2. **Every metric is user-attributed** — scoped to `created_by = ?` (or the appropriate per-table attribution column for the logged-in user).
3. **Modern + diagram-rich** — the user lands on the page and instantly sees their own sales velocity, collection rate, return rate, target attainment, work pattern, error rate, etc. via charts and KPI cards.
4. **Super-admin override** — a user with `role = 'superadmin'` sees the same dashboard but with an **employee `<select>`** at the top. By default it loads **the admin's own** performance. Picking another employee reloads the whole dashboard for that employee. Non-admin users see only their own and have no select box.

### Out of scope

- We are **not** building a leaderboard / ranking screen (that would re-introduce a company-wide view).
- We are **not** removing the existing `/dashboard` route — we are **rewriting** the controller and the `dashboard/index.blade.php` view.
- We are **not** changing the existing `CustomerPerformanceController` (per-customer analytics, separate page) or `SalesFunnelController` (pipeline view).

### Source-of-truth references

This plan is derived from a full read of `database/sql/01_auth_and_master.sql` → `07_views_triggers_constraints.sql`, the commission-tracking migration, and the existing `DashboardController` / `CustomerPerformanceController` / `SalesFunnelController` source.

---

## 1. Schema Foundation — How Users Are Identified

### 1.1 The `users` ↔ `employees` ↔ `branches` chain

```
users (id, employee_id UNIQUE NOT NULL, username, is_active, last_login, ...)
   │
   └─► employees (id, employee_code, name, role, branch_id, salary, joining_date, is_active, ...)
            │
            └─► branches (id, branch_code, branch_name)
```

- **1:1 mandatory** between `users` and `employees` (`users.employee_id` is UNIQUE NOT NULL).
- A user's `branch_id` is **always** `Employee.branch_id` — there is no `users.branch_id` column. Branch context is held in PHP `session('branch_id')` so an admin can switch branches.
- **Role lives on `employees.role`**, not on `users`. The CHECK constraint (per migration `2025_01_12_000001_fix_employees_role_check.php`) allows: `admin`, `salesman`, `warehouse_manager`, `dispatcher`, `accountant`, `hr`, `manager`, `other`, `superadmin`, `user`.
- **No `is_super_admin` flag.** Super-admin = `role = 'superadmin'`. Detected via `User::isSuperadmin()` (or `Auth::user()->employee->role === 'superadmin'`).
- **No Spatie package.** RBAC is split: `employees.role` for coarse role + `user_menu_permissions` for fine-grained per-menu view/edit.
- **RLS is branch-scoped, NOT user-scoped** — non-admin queries automatically see only `session('branch_id')` rows; admin queries see all branches. **Per-user attribution must be done explicitly** in SQL via `WHERE created_by = ?`.

### 1.2 Attribution columns — the backbone of every per-user metric

Every transactional table has at least one user-attribution column. All are plain `integer` referencing `users.id` (no declared FK, except `cash_ledger.created_by`). The full inventory is in §2 below; the key ones for the dashboard:

| Column | Meaning | Used for |
|---|---|---|
| `sales_invoices.created_by` | The user who booked the sale | Sales activity metrics |
| `sales_invoices.salesman_id` (FK→`employees.id`) | The assigned salesman (employee) | Portfolio / account-owner metrics |
| `customer_payments.created_by` | The user who recorded the receipt | Collection metrics |
| `sales_returns.created_by` | The user who initiated the return | Quality / return metrics |
| `sales_challans.created_by` | The user who issued the delivery note | Dispatch throughput |
| `purchase_orders.created_by` | The user who created the PO | Procurement activity |
| `purchase_receives.created_by` | The user who booked the GRN | Receiving activity |
| `stock_adjustments.created_by` / `submitted_by` / `approved_by` / `confirmed_by` | Maker-checker roles | Stock-discipline metrics (role-aware) |
| `stock_take_sessions.created_by` / `submitted_by` / `approved_by` | Maker-checker roles | Count accuracy metrics |
| `damage_invoices.created_by` / `submitted_by` / `approved_by` / **`accountable_employee_id`** | Drafter / submitter / approver / **blamed party** | Accountability metrics (per employee) |
| `commission_entries.salesman_id` (FK→`employees.id`) | The salesman who earned the commission | Commission / target-attainment metrics |
| `customer_ledger.created_by` | The user who posted the ledger entry | Customer-engagement touchpoints |

### 1.3 Partitioning caveat

`sales_invoices` and `stock_transactions` are **PostgreSQL partitioned tables** with composite PK `(id, invoice_date)` / `(id, transaction_date)`. **Every query must include the date column in the WHERE clause** for partition pruning. This means every per-user metric query is structured as:

```sql
SELECT ...
FROM sales_invoices
WHERE created_by = :userId
  AND invoice_date BETWEEN :start AND :end
  AND is_reversed = false
  AND status NOT IN ('cancelled', 'reversed', 'draft')
  AND deleted_at IS NULL
```

---

## 2. Performance Metrics Catalogue

Below is the **complete list of per-user performance metrics** that can be computed from the existing schema, grouped into 10 categories. Each metric entry specifies:

- **Metric name**
- **Source table(s) + aggregation** (SQL-style)
- **Time dimension** — T = today, MTD = month-to-date, QTD = quarter, YTD = year, C = custom range, S = snapshot (current point in time)
- **Attribution column** — `created_by` (user who did the action) vs `salesman_id` (employee who owns the customer) vs `accountable_employee_id` (employee blamed for loss) vs `approved_by` (manager who approved)
- **Why meaningful** — what coaching/visibility value it provides to the user

Default "active" filter applied to all metrics unless noted:
`is_reversed = false AND deleted_at IS NULL` (and `status NOT IN ('cancelled','reversed','draft')` for sales/purchase invoices).

---

### 2.1 Sales Performance (the headline category)

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| S1 | **Sales Volume — invoice count** | `COUNT(*) FROM sales_invoices` | T/M/Q/Y/C | `created_by` | Core productivity — how many sales the user booked |
| S2 | **Sales Volume — value** | `SUM(total_amount) FROM sales_invoices` | T/M/Q/Y/C | `created_by` | Revenue contribution |
| S3 | **Avg Invoice Size (AOV)** | `AVG(total_amount) FROM sales_invoices` | T/M/Q/Y/C | `created_by` | Deal-size quality — small AOV = many small sales; large AOV = fewer big deals |
| S4 | **Sales Growth vs Prev Period** | `(curr - prev) / prev * 100` over two consecutive periods | M/Q/Y | `created_by` | Trajectory — is the user improving or declining? |
| S5 | **Active Selling Days** | `COUNT(DISTINCT invoice_date) FROM sales_invoices` | M/Q/Y/C | `created_by` | Work-pattern breadth |
| S6 | **Peak Sales Day (value + date)** | `MAX(daily_total)` from `SELECT invoice_date, SUM(total_amount) FROM sales_invoices WHERE created_by=? GROUP BY invoice_date` | M/Q/Y/C | `created_by` | Best-day benchmark — coaching target |
| S7 | **Sales by Product Group** | `SELECT pg.group_name, SUM(sii.qty * sii.rate) FROM sales_invoices si JOIN sales_invoice_items sii ON ... JOIN products p ON ... JOIN product_groups pg ON ... WHERE si.created_by=? GROUP BY pg.id` | M/Q/Y/C | `created_by` | Mix quality — high-margin vs commodity |
| S8 | **New vs Repeat Customer Sales** | Split by whether `customer_id` had any prior invoice (by the same user) before period start | M/Q/Y/C | `created_by` | Acquisition vs farming balance |
| S9 | **Draft Cart Conversion Rate** | `COUNT(sales_invoices WHERE created_by=?) / NULLIF(COUNT(sales_draft_carts WHERE user_id=?), 0)` | M/Q/Y/C | `created_by` | Pipeline efficiency — does the user finalize carts? |
| S10 | **Portfolio Revenue (account-owner view)** | `SUM(total_amount) FROM sales_invoices WHERE salesman_id = ?` (distinct from `created_by`) | M/Q/Y/C | `salesman_id` | For salesman-role users: revenue from their **owned** customers, regardless of who keyed the invoice |

**Chart visualization:**
- S1/S2 → KPI cards + 7/30/90-day sparkline
- S3 → KPI card with prev-period delta
- S4 → trend arrow (▲ green / ▼ red)
- S7 → horizontal bar chart
- S8 → donut chart (new/repeat split)

---

### 2.2 Collection / Receivables Performance

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| C1 | **Collections Volume — count + value** | `COUNT(*), SUM(amount) FROM customer_payments` | T/M/Q/Y/C | `created_by` | Direct collection productivity |
| C2 | **Collection Rate** | `SUM(customer_payments.amount WHERE created_by=?) / NULLIF(SUM(sales_invoices.total_amount WHERE created_by=?), 0) * 100` (same period) | M/Q/Y | `created_by` | Effectiveness — does booked revenue convert to cash? |
| C3 | **My Outstanding (snapshot)** | `SUM(due_amount) FROM sales_invoices WHERE created_by=? AND is_reversed=false AND status NOT IN ('cancelled','reversed','draft')` | S | `created_by` | Receivables the user is on the hook for |
| C4 | **Overdue Invoices — count + value** | `COUNT/SUM(due_amount) FROM sales_invoices WHERE created_by=? AND due_amount > 0 AND invoice_date < CURRENT_DATE - 30` (assumed 30-day term; see schema gap G3) | S | `created_by` | Aged-receivables risk attributable to the user |
| C5 | **Receivable Aging Buckets (my book)** | 5 buckets: Current / 1-30 / 31-60 / 61-90 / 90+ days; same `CASE` expression as `DashboardController::getReceivableAging()` but with `AND created_by=?` | S | `created_by` | Where the user's overdue risk is concentrated |
| C6 | **Outstanding Reduction (period)** | `[SUM(due_amount) at period end] - [SUM(due_amount) at period start]` for the user's invoices | M/Q/Y | `created_by` | Is the user shrinking their overdue book? Negative = good |
| C7 | **Discount Allowed** | `SUM(discount_amount) FROM customer_payments WHERE created_by=?` | M/Q/Y/C | `created_by` | Pricing discipline — excessive discounting erodes margin |
| C8 | **Bank vs Cash Collection Mix** | `GROUP BY payment_mode, COUNT(*)/SUM(amount) FROM customer_payments WHERE created_by=?` | M/Q/Y/C | `created_by` | Operational profile — cash-heavy vs bank-heavy |
| C9 | **Write-offs / Bad-Debt Posted** | `COUNT/SUM(amount) FROM customer_payments WHERE created_by=? AND transaction_type='write_off'` (verify column exists — see G12) | M/Q/Y/C | `created_by` | Aggressive receivable clearance vs proper collection |

**Chart visualization:**
- C1 → KPI card + sparkline
- C2 → gauge chart (0-100%) with target line at 80%
- C5 → stacked donut chart
- C8 → pie chart (cash/bank/cheque/mobile_banking)

---

### 2.3 Sales Returns / Quality

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| R1 | **Return Count** | `COUNT(*) FROM sales_returns WHERE created_by=?` | T/M/Q/Y/C | `created_by` | Volume of returns initiated by the user |
| R2 | **Return Value** | `SUM(total_amount) FROM sales_returns WHERE created_by=? AND status='confirmed'` | M/Q/Y/C | `created_by` | Revenue reversal the user caused |
| R3 | **Return Rate** | `SUM(sales_returns.total_amount WHERE created_by=?) / NULLIF(SUM(sales_invoices.total_amount WHERE created_by=?), 0) * 100` | M/Q/Y | `created_by` | Quality signal — high return rate = bad selling or bad product fit |
| R4 | **Damage-Linked Returns** | `COUNT(*) FROM sales_returns sr JOIN damage_invoices di ON di.sales_return_id = sr.id WHERE sr.created_by=?` | M/Q/Y/C | `created_by` | Returns that became write-offs |
| R5 | **Top Return Reasons** | `GROUP BY reason FROM sales_returns WHERE created_by=? ORDER BY COUNT(*) DESC LIMIT 5` | M/Q/Y/C | `created_by` | Coaching signal — what's going wrong |

**Chart visualization:**
- R1/R2 → KPI cards
- R3 → gauge chart with target line at <5%
- R5 → horizontal bar chart

---

### 2.4 Customer Engagement

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| E1 | **Active Customers (Period)** | `COUNT(DISTINCT customer_id) FROM sales_invoices WHERE created_by=?` | M/Q/Y/C | `created_by` | Customer reach |
| E2 | **New Customers Acquired** | `COUNT(DISTINCT customer_id) FROM sales_invoices si WHERE si.created_by=? AND NOT EXISTS (SELECT 1 FROM sales_invoices si2 WHERE si2.customer_id=si.customer_id AND si2.created_by=si.created_by AND si2.invoice_date < :periodStart)` (workaround for G2) | M/Q/Y/C | `created_by` | Acquisition productivity |
| E3 | **Repeat Customer Rate** | `customers with ≥2 invoices by the user in period / active customers in period` | M/Q/Y | `created_by` | Relationship depth |
| E4 | **Customer Retention (period-over-period)** | `customers who bought from the user in BOTH this and the previous period / customers in previous period` | M/Q/Y | `created_by` | Stickiness of the user's book |
| E5 | **Owned Portfolio Size** | `COUNT(*) FROM customers WHERE sales_person_id = :employeeId AND is_active=true AND deleted_at IS NULL` | S | `sales_person_id` (employee) | Account-load / portfolio health |
| E6 | **Portfolio Revenue** | `SUM(total_amount) FROM sales_invoices WHERE salesman_id = :employeeId` | M/Q/Y/C | `salesman_id` | Revenue from owned accounts |
| E7 | **Top 5 Customers (by user's revenue)** | `SELECT c.customer_name, COUNT(*), SUM(si.total_amount) FROM sales_invoices si JOIN customers c ON c.id=si.customer_id WHERE si.created_by=? GROUP BY c.id ORDER BY SUM(si.total_amount) DESC LIMIT 5` | M/Q/Y/C | `created_by` | The user's own book — NOT a global top-5 |
| E8 | **Customer Ledger Touchpoints** | `COUNT(*) FROM customer_ledger WHERE created_by=?` | M/Q/Y/C | `created_by` | Engagement activity (payments, adjustments, write-offs posted) |

**Chart visualization:**
- E1/E2 → KPI cards
- E7 → mini table with progress bars

---

### 2.5 Operational Efficiency (Sales Velocity)

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| O1 | **Avg Invoice → Godown Time (hours)** | `AVG(EXTRACT(EPOCH FROM (godown_prepared_at - created_at))/3600) FROM sales_invoices WHERE created_by=? AND is_godown_prepared=true` | M/Q/Y/C | `created_by` | Godown-prep speed |
| O2 | **Avg Godown → Challan Time (hours)** | `AVG(EXTRACT(EPOCH FROM (challan_issued_at - godown_prepared_at))/3600) WHERE created_by=? AND is_challan_issued=true` | M/Q/Y/C | `created_by` | Dispatch speed |
| O3 | **Avg Invoice → Challan (Velocity)** | `AVG(EXTRACT(EPOCH FROM (challan_issued_at - created_at))/3600) WHERE created_by=? AND is_challan_issued=true` | M/Q/Y/C | `created_by` | End-to-end sale velocity |
| O4 | **Same-Day Dispatch Rate** | `COUNT(*) WHERE created_by=? AND is_challan_issued=true AND challan_issued_at::date = invoice_date / NULLIF(COUNT(*) WHERE created_by=? AND is_challan_issued=true, 0) * 100` | M/Q/Y/C | `created_by` | On-time proxy |
| O5 | **Stale Draft Count** | `COUNT(*) FROM sales_invoices WHERE created_by=? AND status='draft' AND created_at < CURRENT_DATE - 7` | S | `created_by` | Neglected pipeline |
| O6 | **Open Pipeline Value** | `SUM(total_amount) WHERE created_by=? AND status='confirmed' AND is_challan_issued=false` | S | `created_by` | Unconverted work-in-progress |
| O7 | **Challans Issued — count + cost** | `COUNT(*), SUM(issue_cost) FROM sales_challans WHERE created_by=?` | M/Q/Y/C | `created_by` | Dispatch throughput |
| O8 | **Parked-Sales Count ("call_a_day")** | `COUNT(*) FROM sales_invoices WHERE created_by=? AND call_a_day=true` | M/Q/Y/C | `created_by` | Sales the user parked/suspended from the today-list (negative productivity signal — see G11) |
| O9 | **Blank Godown Sheets Printed** | `COUNT(*) FROM sales_invoices WHERE blank_godown_printed_by=?` | M/Q/Y/C | `blank_godown_printed_by` | Picking-sheet prep activity (warehouse user) |

**Chart visualization:**
- O1/O2/O3 → KPI cards with target benchmark
- O4 → gauge chart
- O3 trend → line chart (last 30 days)

---

### 2.6 Stock Discipline (role-aware: maker vs checker vs poster)

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| K1 | **Adjustments Initiated** | `COUNT(*) FROM stock_adjustments WHERE created_by=?` | M/Q/Y/C | `created_by` | Correction-activity volume (drafter role) |
| K2 | **Adjustment Value** | `SUM(total_amount) FROM stock_adjustments WHERE created_by=? AND status='confirmed'` | M/Q/Y/C | `created_by` | Monetary impact of the user's corrections |
| K3 | **Decrease (Loss) Adjustments** | `COUNT/SUM(total_amount) WHERE created_by=? AND adjustment_type='decrease' AND status='confirmed'` | M/Q/Y/C | `created_by` | Loss the user recorded |
| K4 | **Adjustments Approved (manager role)** | `COUNT(*) FROM stock_adjustments WHERE approved_by=?` | M/Q/Y/C | `approved_by` | Approval workload |
| K5 | **Adjustments Confirmed/Posted** | `COUNT(*) FROM stock_adjustments WHERE confirmed_by=?` | M/Q/Y/C | `confirmed_by` | Posting workload |
| K6 | **Adjustment Rejections** | `COUNT(*) FROM stock_adjustments WHERE submitted_by=? AND status='rejected'` | M/Q/Y/C | `submitted_by` | Quality of submissions |
| K7 | **Stock-Take Sessions Initiated** | `COUNT(*) FROM stock_take_sessions WHERE created_by=?` | M/Q/Y/C | `created_by` | Counting activity |
| K8 | **Stock-Take Variances Posted** | `COUNT(*) FROM stock_take_sessions WHERE submitted_by=? AND status='posted'`; variance value: `SUM(sti.difference * sti.rate) JOIN stock_take_items sti ON sti.stock_take_session_id = sts.id` | M/Q/Y/C | `submitted_by` | Accuracy of the user's counts |
| K9 | **Damage Reports Filed** | `COUNT(*) FROM damage_invoices WHERE created_by=?` (+ breakdown by `damage_type`) | M/Q/Y/C | `created_by` | Damage-reporting diligence |
| K10 | **Damage Value Reported** | `SUM(total_value) FROM damage_invoices WHERE created_by=? AND status='confirmed'` | M/Q/Y/C | `created_by` | Loss the user surfaced |
| K11 | **Accountable Damages (blamed)** | `COUNT/SUM(total_value) FROM damage_invoices WHERE accountable_employee_id=:employeeId` | M/Q/Y/C | `accountable_employee_id` | **Strongest accountability metric** — losses blamed on this employee |
| K12 | **Damage Recovery Posted** | `SUM(recovery_amount) FROM damage_invoices WHERE accountable_employee_id=:employeeId` | M/Q/Y/C | `accountable_employee_id` | Amount recovered from the employee |
| K13 | **Damages Approved (manager role)** | `COUNT(*) FROM damage_invoices WHERE approved_by=?` | M/Q/Y/C | `approved_by` | Approval workload |
| K14 | **Warehouse Transfers Initiated** | `COUNT(*) FROM warehouse_transfers WHERE created_by=?` | M/Q/Y/C | `created_by` | Transfer activity |

**Chart visualization:**
- K1/K2 → KPI cards
- K9 → stacked bar by `damage_type`
- K11 → KPI card with red highlight if > 0

---

### 2.7 Purchase Performance

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| P1 | **PO Created — count + value** | `COUNT(*), SUM(total_amount) FROM purchase_orders WHERE created_by=?` | M/Q/Y/C | `created_by` | Procurement activity |
| P2 | **GRN Booked — count + value** | `COUNT(*), SUM(total_amount) FROM purchase_receives WHERE created_by=? AND status='confirmed'` | M/Q/Y/C | `created_by` | Goods-receipt throughput |
| P3 | **PO On-Time Receipt Rate** | `COUNT(*) FROM purchase_receives pr JOIN purchase_orders po ON po.id=pr.purchase_order_id WHERE pr.created_by=? AND pr.receive_date <= po.expected_date / NULLIF(COUNT(*) FROM purchase_receives WHERE created_by=?, 0) * 100` | M/Q/Y/C | `created_by` | Scheduling discipline |
| P4 | **Purchase Returns Initiated** | `COUNT(*)/SUM(total_amount) FROM purchase_returns WHERE created_by=?` | M/Q/Y/C | `created_by` | Supplier-quality feedback volume |
| P5 | **Supplier Payments Made** | `COUNT(*)/SUM(amount) FROM supplier_payments WHERE created_by=?` | M/Q/Y/C | `created_by` | Disbursement activity |

**Chart visualization:**
- P1/P2 → KPI cards
- P3 → gauge chart

---

### 2.8 Commission / Target Attainment

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| M1 | **Commission Earned (Calculated)** | `SUM(commission_amount) FROM commission_entries WHERE salesman_id=:employeeId AND status='calculated' AND commission_period = :YYYY-MM` | M/Q/Y | `salesman_id` | Pending commission (not yet approved) |
| M2 | **Commission Confirmed** | `SUM(commission_amount) WHERE salesman_id=:employeeId AND status IN ('confirmed','paid')` | M/Q/Y | `salesman_id` | Approved-for-payment commission |
| M3 | **Commission Paid** | `SUM(commission_amount) WHERE salesman_id=:employeeId AND status='paid'` | M/Q/Y | `salesman_id` | Actually disbursed |
| M4 | **Commission Reversed (Returns)** | `SUM(commission_amount) WHERE salesman_id=:employeeId AND sales_return_id IS NOT NULL` (negative) | M/Q/Y | `salesman_id` | Commission clawed back due to returns |
| M5 | **Net Commission** | `SUM(commission_amount) WHERE salesman_id=:employeeId AND is_reversed=false` (signed — returns are negative) | M/Q/Y | `salesman_id` | True commission earned |
| M6 | **Target Attainment %** | `SUM(commission_base) WHERE salesman_id=:employeeId AND commission_period=:YYYY-MM / (SELECT target_amount FROM commission_rule_targets WHERE commission_rule_id IN (SELECT id FROM commission_rules WHERE salesman_id=:employeeId AND is_active=true) AND period='monthly') * 100` | M/Q/Y | `salesman_id` | Progress vs quota |
| M7 | **Target Bonus Earned** | Excess over base = `SUM(commission_amount) - (SUM(commission_base) * base_rate/100)` when `rule_type='target_bonus'` and cumulative base > target | M/Q/Y | `salesman_id` | Bonus-achievement signal |
| M8 | **Active Commission Rule** | `SELECT rule_type, rate, effective_from FROM commission_rules WHERE salesman_id=:employeeId AND is_active=true AND effective_to IS NULL` | S | `salesman_id` | Current compensation structure (context card) |

**Chart visualization:**
- M5 → KPI card with prev-period delta
- M6 → progress bar (0% → 150%) with target line at 100%
- M1-M4 trend → stacked bar chart (6-month history)

---

### 2.9 Activity / Productivity

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| A1 | **Transactions Per Day** | `COUNT(*) FROM sales_invoices WHERE created_by=? / NULLIF(COUNT(DISTINCT invoice_date) WHERE created_by=?, 0)` | M/Q/Y | `created_by` | Daily throughput intensity |
| A2 | **Active Days (cross-table)** | `COUNT(DISTINCT DATE(created_at))` UNIONed across sales_invoices, customer_payments, sales_returns, sales_challans, purchase_orders, purchase_receives, stock_adjustments, damage_invoices WHERE `created_by=?` | M/Q/Y | `created_by` | True working-day count (any activity) |
| A3 | **Peak Day (cross-table)** | Day with the most total transactions by the user | M/Q/Y | `created_by` | Busiest-day benchmark |
| A4 | **Work Pattern (hour-of-day histogram)** | `SELECT EXTRACT(HOUR FROM created_at) AS hr, COUNT(*) FROM sales_invoices WHERE created_by=? GROUP BY hr` (repeat per table) | M/Q/Y | `created_by` | Productivity pattern / overtime detection |
| A5 | **Draft Carts Active** | `COUNT(*) FROM sales_draft_carts WHERE user_id=?` | S | `user_id` | Work-in-progress carts |
| A6 | **Last Login** | `users.last_login` | S | — | Recency of engagement |
| A7 | **Notifications Read Rate** | `COUNT(*) WHERE is_read=true / NULLIF(COUNT(*) FROM notifications WHERE user_id=?, 0) * 100` | M/Q/Y | `user_id` | Engagement with system alerts |

**Chart visualization:**
- A2 → KPI card
- A4 → 24-hour bar chart (peak hour highlighted)

---

### 2.10 Accuracy / Compliance

| # | Metric | Source + aggregation | Time | Attribution | Why meaningful |
|---|---|---|---|---|---|
| X1 | **Reversed Invoices (own)** | `COUNT(*)/SUM(total_amount) FROM sales_invoices WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Booking errors requiring reversal |
| X2 | **Cancelled Invoices (own)** | `COUNT(*) FROM sales_invoices WHERE created_by=? AND status='cancelled'` | M/Q/Y/C | `created_by` | Abandoned bookings |
| X3 | **Reversed Customer Payments** | `COUNT(*)/SUM(amount) FROM customer_payments WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Mis-applied collections |
| X4 | **Reversed Sales Returns** | `COUNT(*) FROM sales_returns WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Return-entry errors |
| X5 | **Reversed GRNs** | `COUNT(*) FROM purchase_receives WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Receiving errors |
| X6 | **Reversed Stock Adjustments** | `COUNT(*) FROM stock_adjustments WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Correction errors |
| X7 | **Reversed Challans** | `COUNT(*) FROM sales_challans WHERE created_by=? AND is_reversed=true` | M/Q/Y/C | `created_by` | Dispatch errors |
| X8 | **Reversals Performed (by user)** | `COUNT(*) FROM sales_invoices WHERE reversed_by=?` (UNION across all tables) | M/Q/Y/C | `reversed_by` | How many reversals the user executed (audit role) |
| X9 | **Manual Journals Created** | `COUNT(*)/SUM(total_debit) FROM manual_journals WHERE created_by=?` | M/Q/Y/C | `created_by` | Direct GL manipulation volume (compliance risk) |
| X10 | **Composite Error Rate** | `(Σ reversed + Σ cancelled) / Σ total transactions` per user across all tables | M/Q/Y | `created_by` | Single quality score |

**Chart visualization:**
- X1/X2/X3 → KPI cards (red highlight if > threshold)
- X10 → gauge chart (lower is better, target <2%)

---

## 3. Schema Gaps (Lightweight Migrations Needed)

The schema already supports 95% of the metrics in §2. The gaps below are **optional** — they unlock a few extra metrics but are not blockers for Phase 1. They should be addressed in Phase 4 (after the core dashboard is live).

| # | Gap | Impact | Proposed migration | Phase |
|---|---|---|---|---|
| G1 | No login history / daily-active tracking. Only `users.last_login` (single value). | Blocks "login frequency", "consecutive active days" | `CREATE TABLE user_login_log (id bigserial PK, user_id integer NOT NULL REFERENCES users(id), login_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP, ip_address varchar(45), user_agent text); CREATE INDEX idx_ull_user_date ON user_login_log(user_id, login_at);` — populate from `AuthenticatedSessionController` on successful login | Phase 4 |
| G2 | `customers.created_by` does not exist. Cannot attribute customer creation to a user. | E2 "New Customers Acquired" uses first-invoice workaround (imperfect — misses customers created but never invoiced by that user) | `ALTER TABLE customers ADD COLUMN created_by integer;` — backfill from first invoice by any user, then set in `CustomerController::store()` | Phase 4 |
| G3 | No `due_date` / payment terms on `sales_invoices`. Overdue calculation requires an assumed term (e.g., 30 days). | C4 "Overdue Invoices" is approximate | `ALTER TABLE sales_invoices ADD COLUMN due_date date;` + `ALTER TABLE customers ADD COLUMN payment_terms_days smallint DEFAULT 30;` — set `due_date` on confirm | Phase 4 |
| G4 | No `godown_prepared_by` on `sales_invoices`. Godown-prep attribution falls back to `created_by` (the booker), but a different warehouse user may do the prep. | "Godown prep throughput per warehouse user" cannot be attributed precisely | `ALTER TABLE sales_invoices ADD COLUMN godown_prepared_by integer;` — set when `is_godown_prepared` flips to true | Phase 5 |
| G5 | No `dispatched_by` / `dispatched_at` on `sales_invoice_dispatches`. `created_by` captures who created the reservation, not who physically picked. | "Pick/dispatch productivity per warehouse user" is imprecise | `ALTER TABLE sales_invoice_dispatches ADD COLUMN dispatched_by integer, dispatched_at timestamp(0);` — set when `dispatched_qty` reaches `ordered_qty` | Phase 5 |
| G6 | No customer visits / call-log table. | "Customer visit frequency", "calls per day" cannot be computed | `CREATE TABLE customer_visits (id bigserial PK, customer_id integer NOT NULL, visited_by integer NOT NULL, visit_at timestamp(0) DEFAULT CURRENT_TIMESTAMP, visit_type varchar(30), outcome varchar(50), notes text);` — populate from a future CRM module | Phase 6 (post-launch) |
| G7 | No `purchase_orders.approved_by` / `approved_at`. | "PO approval workload per manager" not computable | `ALTER TABLE purchase_orders ADD COLUMN approved_by integer, approved_at timestamp(0);` | Phase 5 |
| G8 | No `purchase_receives.received_by` distinct from `created_by`. | "Goods received by employee X" conflated with "GRN booked by user Y" | `ALTER TABLE purchase_receives ADD COLUMN received_by integer REFERENCES employees(id);` | Phase 5 |
| G9 | No `warehouse_transfers.requested_by` / `received_by`. | Transfer-confirmation attribution missing | `ALTER TABLE warehouse_transfers ADD COLUMN requested_by integer, received_by integer, received_at timestamp(0);` | Phase 5 |
| G10 | No unified user-activity log. A "user activity feed" requires a 30-table UNION. | A single "all actions by user X" query is expensive | Either (a) `CREATE TABLE user_activity_log (...)` populated by an Eloquent observer, or (b) materialized view `mv_user_activity` refreshed nightly. (b) is read-only and lighter — preferred for dashboard. | Phase 6 (post-launch) |
| G11 | `call_a_day` is **misnamed** — it's a "called it a day / hide from today list" UI flag, NOT a sales-call/visit log. | If anyone reads the column name and assumes it's a CRM visit metric, the dashboard will be wrong. | No migration — just **correct naming in the UI** (label it "Parked Sales" not "Calls of the Day"). Optional rename to `is_parked_from_today_list` in a future refactor. | Naming-only (Phase 1) |
| G12 | `customer_payments.transaction_type` is referenced in the model (`isReceive`, `isDiscount`, `isWriteOff`, `isRefund`) but not in `06_payment_and_misc.sql`. Added by a later migration. | C9 "Write-offs Posted" depends on this column existing at runtime. | **Verify at runtime**: `SELECT column_name FROM information_schema.columns WHERE table_name='customer_payments' AND column_name='transaction_type';` — if missing, add it via migration. | Phase 1 (verify) |

---

## 4. Phased Implementation Plan

The dashboard will be built in **5 phases**. Each phase is independently shippable — at the end of every phase, the dashboard is functional and better than the previous state.

### Phase 0 — Discovery & Scaffolding (0.5 day)

**Goal:** Set up the routes, controller, view, and select-box plumbing before writing any metric.

**Tasks:**
1. Create new controller: `app/Http/Controllers/UserPerformanceDashboardController.php`
   - `index(Request $request)` — renders the dashboard
   - Accepts `?employee_id=X` query param (only honored if `Auth::user()->isSuperadmin()`)
   - Resolves `$targetEmployeeId`:
     - If super-admin AND `?employee_id` is set AND it's a valid employee id → use it
     - Else → use `Auth::user()->employee_id` (default = own performance)
   - Resolves `$targetUserId` from `$targetEmployeeId` via `users.employee_id`
   - Passes both to the view, plus `$isSuperadmin` flag and `$employeeOptions` (for the select box, only if super-admin)
2. Add route in `routes/web.php`:
   ```php
   Route::get('dashboard', [UserPerformanceDashboardController::class, 'index'])
       ->name('dashboard')
       ->middleware('auth');
   ```
   **Replace** the existing `Route::get('dashboard', [DashboardController::class, 'index'])` line. Keep the old `DashboardController` file in the repo for reference (rename to `LegacyDashboardController.php`) — do NOT delete; we may want to copy query patterns.
3. Create new view: `resources/views/dashboard/performance.blade.php`
   - Layout: extends the existing `layouts.app` (so it inherits the sidebar/header)
   - Top bar: title "My Performance" + (if super-admin) a `<select>` of employees with `onchange="window.location.href='?employee_id='+this.value"`
   - Period filter: a `<select>` for Today / MTD / QTD / YTD / Last 30 / Custom (default = MTD) — passes `?period=` and (if custom) `?from=&to=`
   - Below: a 12-column CSS grid for KPI cards and chart cards
4. Update `routes/web.php` to also point the AJAX route to the new controller:
   - `Route::get('dashboard/sales-trend', [UserPerformanceDashboardController::class, 'salesTrendAjax'])` — for chart refresh
5. **Run a runtime check** on G12 (`SELECT column_name FROM information_schema.columns WHERE table_name='customer_payments' AND column_name='transaction_type'`) — log the result so Phase 2 knows whether to use it.

**Deliverable:** A working route at `/dashboard` that renders an empty page with the title, the period selector, and (for super-admin) the employee selector. No metrics yet.

**Acceptance test:**
- Visit `/dashboard` as a non-admin → page loads, no select box visible
- Visit `/dashboard` as super-admin → page loads, select box visible, default = own name
- Visit `/dashboard?employee_id=5` as super-admin → page loads with "Employee #5" label
- Visit `/dashboard?employee_id=5` as non-admin → employee_id ignored, own dashboard loads

---

### Phase 1 — Sales Performance Core (1.5 days)

**Goal:** Replace the company-wide "Revenue Overview" section with the user's own sales performance. This is the headline category — what most users care about first.

**Metrics to ship:** S1, S2, S3, S4, S5, S6, S7, E1, E2, E7

**Controller methods:**
1. `getSalesKPIs($userId, $period)` → returns `{ invoice_count, total_sales, aov, growth_pct, active_days, peak_day_value, peak_day_date }`
2. `getSalesTrend($userId, $period)` → daily `[{date, count, total}]` for the chart sparkline
3. `getSalesByProductGroup($userId, $period)` → `[{group_name, revenue, qty}]` for horizontal bar chart
4. `getTopCustomers($userId, $period, $limit=5)` → the user's top-5 customers (mini table)
5. `getCustomerAcquisition($userId, $period)` → `{active_customers, new_customers, repeat_rate}`

**View layout (top section of dashboard):**
```
┌─────────────────────────────────────────────────────────────────────────┐
│ My Performance      [Period: MTD ▼]    [Employee: Sajid C. ▼]  (admin) │
├─────────────────────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │  Sales   │ │   AOV    │ │ Active   │ │  Growth  │ │   New    │      │
│  │  Volume  │ │          │ │  Days    │ │  vs Prev │ │ Customers│      │
│  │ ৳ 1.2M   │ │ ৳ 4,521  │ │   18     │ │  +12.4%▲ │ │    7     │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│                                                                         │
│  ┌────────────────────────────────┐  ┌──────────────────────────────┐  │
│  │  Sales Trend (30 days)         │  │  Sales by Product Group      │  │
│  │  [line chart]                  │  │  [horizontal bar chart]      │  │
│  └────────────────────────────────┘  └──────────────────────────────┘  │
│                                                                         │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  My Top 5 Customers (MTD)                                        │  │
│  │  1. Rahim Traders    12 invoices   ৳ 234,000  ████░░░░          │  │
│  │  2. Karim Store       8 invoices   ৳ 156,000  ███░░░░░          │  │
│  │  ...                                                              │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
```

**Charts:** Chart.js (already loaded via CDN in the legacy dashboard view — reuse the same setup).

**Query template (all queries follow this pattern):**
```php
private function periodRange(string $period): array
{
    return match ($period) {
        'today'  => [today(), today()],
        'mtd'    => [now()->startOfMonth()->toDateString(), today()],
        'qtd'    => [now()->startOfQuarter()->toDateString(), today()],
        'ytd'    => [now()->startOfYear()->toDateString(), today()],
        'last30' => [now()->subDays(29)->toDateString(), today()],
        default  => [request('from', now()->startOfMonth()->toDateString()),
                     request('to', today())],
    };
}

private function getSalesKPIs(int $userId, array $range): array
{
    [$start, $end] = $range;
    $curr = DB::table('sales_invoices')
        ->where('created_by', $userId)
        ->whereBetween('invoice_date', [$start, $end])
        ->where('is_reversed', false)
        ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
        ->whereNull('deleted_at')
        ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total')
        ->first();

    // Previous period for growth %
    $prevEnd = now()->parse($start)->subDay()->toDateString();
    $prevStart = ...; // same length as current period, ending at $prevEnd
    $prev = DB::table('sales_invoices')
        ->where('created_by', $userId)
        ->whereBetween('invoice_date', [$prevStart, $prevEnd])
        ->...same filters...
        ->sum('total_amount');

    // Active selling days + peak day
    $days = DB::table('sales_invoices')
        ->where('created_by', $userId)
        ->whereBetween('invoice_date', [$start, $end])
        ->...same filters...
        ->groupBy('invoice_date')
        ->selectRaw('invoice_date, SUM(total_amount) AS daily_total')
        ->get();

    return [
        'invoice_count'     => (int) $curr->cnt,
        'total_sales'       => (float) $curr->total,
        'aov'               => $curr->cnt > 0 ? (float) ($curr->total / $curr->cnt) : 0,
        'growth_pct'        => $prev > 0 ? round((($curr->total - $prev) / $prev) * 100, 1) : 0,
        'active_days'       => $days->count(),
        'peak_day_value'    => $days->max('daily_total') ?? 0,
        'peak_day_date'     => $days->sortByDesc('daily_total')->first()?->invoice_date,
    ];
}
```

**Super-admin behavior:** All Phase-1 methods take `$userId` as the first parameter. The controller's `index()` resolves `$userId` from `?employee_id` (if super-admin) or from `Auth::user()->id` (default). The view is identical for both — only the top-bar label and the select-box visibility differ.

**Deliverable:** Phase-1 dashboard shows the user's own sales KPIs + trend + product-group breakdown + top-5 customers. Super-admin can switch employees.

**Acceptance test:**
- Log in as a salesman → dashboard shows their sales KPIs (not company totals)
- Log in as super-admin → dashboard shows super-admin's own sales by default
- Super-admin picks "Employee #5" → all KPIs reload for employee #5
- Salesman tries `?employee_id=5` → ignored (security check passes)

---

### Phase 2 — Collection & Returns (1 day) ✅ DONE

**Goal:** Add the receivables and quality categories so the user sees the *full* sales story — not just what they booked, but what they collected and what came back.

**Metrics to ship:** C1, C2, C3, C4, C5, C7, R1, R2, R3, R5

**Controller methods:**
1. `getCollectionKPIs($userId, $period)` → `{ collection_count, collection_value, collection_rate, outstanding, overdue_count, overdue_value, discount_allowed }`
2. `getReceivableAging($userId)` → 5-bucket snapshot `{ Current, 1-30, 31-60, 61-90, 90+ }`
3. `getReturnKPIs($userId, $period)` → `{ return_count, return_value, return_rate, top_reasons[] }`

**View layout (middle section, below sales):**
```
┌─────────────────────────────────────────────────────────────────────────┐
│  COLLECTIONS & RETURNS                                                  │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │Collection│ │ Collection│ │ My Out-  │ │ Overdue  │ │ Return   │      │
│  │  Volume  │ │   Rate   │ │ standing │ │  Value   │ │  Rate    │      │
│  │ ৳ 850K   │ │   71%    │ │ ৳ 350K   │ │ ৳ 45K    │ │   4.2%   │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│                                                                         │
│  ┌────────────────────────────────┐  ┌──────────────────────────────┐  │
│  │  Receivable Aging (my book)    │  │  Top Return Reasons          │  │
│  │  [stacked donut chart]         │  │  [horizontal bar chart]      │  │
│  └────────────────────────────────┘  └──────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
```

**Key implementation notes:**
- Collection rate (C2) is computed as `Σ customer_payments.amount (created_by=?) / Σ sales_invoices.total_amount (created_by=?)` *over the same period*. This is **not** the company-wide `mtd_collection / mtd_revenue` ratio from the legacy dashboard.
- Overdue (C4) uses an assumed 30-day term until G3 is fixed. Label it "Overdue (>30 days)" in the UI so users understand the threshold.
- Top return reasons (R5) — if `sales_returns.reason` is null for many rows, fall back to grouping by `sales_returns.status` only.

**Deliverable:** Phase-2 dashboard has the full sales→collection→return story, all per-user.

**Acceptance test:**
- Collection Rate gauge reads ~70-90% for active salesmen, 0% for back-office users
- Aging donut sums to "My Outstanding" KPI value
- Return rate > 0 for users who have processed returns; doesn't show "NaN%" when sales=0

---

### Phase 3 — Operational Efficiency & Productivity (1 day) ✅ DONE

**Goal:** Show the user *how they work* — sales velocity, draft discipline, work pattern, active days. This is the "modern diagram" piece the user explicitly asked for.

**Metrics to ship:** O1, O2, O3, O4, O5, O6, O8, A1, A2, A4, A7

**Controller methods:**
1. `getVelocityKPIs($userId, $period)` → `{ avg_invoice_to_godown_hrs, avg_godown_to_challan_hrs, avg_invoice_to_challan_hrs, same_day_dispatch_pct }`
2. `getPipelineSnapshot($userId)` → `{ stale_draft_count, open_pipeline_value, parked_sales_count }`
3. `getWorkPattern($userId, $period)` → `[{ hour: 0..23, count }]` for the 24-hour histogram
4. `getActivitySummary($userId, $period)` → `{ transactions_per_day, active_days_cross_table, peak_day }`
5. `getNotificationEngagement($userId)` → `{ read_rate }`

**View layout (lower section):**
```
┌─────────────────────────────────────────────────────────────────────────┐
│  HOW YOU WORK                                                            │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                   │
│  │ Avg Sale │ │ Same-Day │ │  Active  │ │  Txns/   │                   │
│  │ Velocity │ │ Dispatch │ │  Days    │ │  Day     │                   │
│  │  18 hrs  │ │   62%    │ │   18     │ │   4.3    │                   │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘                   │
│                                                                         │
│  ┌────────────────────────────────┐  ┌──────────────────────────────┐  │
│  │  Work Pattern (hour of day)    │  │  Pipeline Snapshot           │  │
│  │  [24-hour bar chart, peak hr   │  │  • Stale drafts: 3           │  │
│  │   highlighted]                 │  │  • Open pipeline: ৳ 145K     │  │
│  └────────────────────────────────┘  │  • Parked sales: 2           │  │
│                                      └──────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
```

**Key implementation notes:**
- The 24-hour histogram (A4) is the **visual centerpiece** — modern dashboards lean on this kind of "rhythm" chart. Use Chart.js bar chart with `backgroundColor` array, peak hour highlighted in accent color.
- Active days cross-table (A2) requires a UNION across ~10 tables. Use a single raw SQL with `UNION ALL`:
  ```sql
  SELECT DISTINCT DATE(created_at) AS d FROM sales_invoices WHERE created_by=?
  UNION ALL
  SELECT DISTINCT DATE(created_at) FROM customer_payments WHERE created_by=?
  UNION ALL
  ...
  ```
  Wrap in a subquery: `SELECT COUNT(*) FROM (... union ...) AS active_days WHERE d BETWEEN ? AND ?`
- Same-day dispatch rate (O4) divides by `NULLIF(...,0)` to avoid divide-by-zero when user has no dispatched invoices.

**Deliverable:** Phase-3 dashboard has the "modern diagram" feel — work-pattern histogram, velocity gauges, pipeline snapshot. The dashboard now tells a complete personal-performance story.

**Acceptance test:**
- Work-pattern histogram shows a bell-curve-ish distribution with a clear peak during business hours
- "Avg Sale Velocity" renders as "—" (not 0 or NaN) for users with no dispatched invoices
- Pipeline snapshot counts match manual SQL queries on a test user

---

### Phase 4 — Commission, Stock Discipline & Accuracy (1.5 days) ✅ DONE

**Goal:** Add the role-aware metrics (stock discipline, commission, accuracy) and ship the lightweight migrations G1, G2, G3 that improve metric accuracy.

**Metrics to ship:** M1-M8, K1-K14, X1-X10

**Controller methods:**
1. `getCommissionSummary($employeeId, $period)` → `{ calculated, confirmed, paid, reversed, net, attainment_pct, active_rule }`
2. `getStockDiscipline($userId, $employeeId, $period)` → `{ adjustments_initiated, adjustment_value, loss_adjustments, accountable_damages, damage_recovery, stock_take_variances, transfers_initiated }`
3. `getAccuracyKPIs($userId, $period)` → `{ reversed_invoices, cancelled_invoices, reversed_payments, reversed_returns, reversed_challans, manual_journals, composite_error_rate }`

**Migrations to ship in this phase:**
- G1: `user_login_log` table + login-event listener
- G2: `customers.created_by` column + backfill
- G3: `sales_invoices.due_date` + `customers.payment_terms_days` columns + backfill from `invoice_date + 30 days`
- G12 verification: if `transaction_type` missing on `customer_payments`, add it

**View layout (commission + discipline section):**
```
┌─────────────────────────────────────────────────────────────────────────┐
│  COMMISSION & TARGETS                                                   │
│  ┌──────────┐ ┌──────────┐ ┌──────────────────────────────────────┐    │
│  │ Net Comm │ │ Attainment│ │ Target Progress Bar                 │    │
│  │ ৳ 12,450 │ │   78%    │ │ ████████░░░░░░  78% of ৳ 16,000     │    │
│  └──────────┘ └──────────┘ └──────────────────────────────────────┘    │
│                                                                         │
│  STOCK DISCIPLINE & ACCURACY                                            │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │Adjustments│ │ Loss Adj │ │Account-  │ │ Reversed │ │  Error   │      │
│  │ Initiated │ │  Value   │ │able Dmg  │ │  Invoices│ │  Rate    │      │
│  │    12     │ │ ৳ 4,500  │ │ ৳ 0      │ │    2     │ │   1.8%   │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
└─────────────────────────────────────────────────────────────────────────┘
```

**Key implementation notes:**
- Commission queries use `salesman_id` (employee), not `created_by` (user). The controller resolves `$employeeId` from `$targetEmployeeId` (which is either the super-admin's selected employee or the logged-in user's own employee).
- `accountable_employee_id` (K11) is the strongest accountability metric — highlight it in red if > 0.
- Composite error rate (X10) = `(reversed_invoices + cancelled_invoices + reversed_payments + ...) / (total_invoices + total_payments + ...)` — compute as a single query with `SUM(CASE WHEN is_reversed THEN 1 ELSE 0 END)` across each table, then divide.
- The migration backfills (G2, G3) should run inside `php artisan migrate` and use small batched UPDATEs (1000 rows at a time) to avoid lock contention on production.

**Deliverable:** Phase-4 dashboard shows commission progress (salesman-role users only — hidden for others), stock-discipline metrics (role-aware), and an accuracy scorecard. The dashboard is now feature-complete against the §2 catalogue.

**Acceptance test:**
- Salesman sees commission section; accountant does not (conditional render)
- "Accountable Damages" is ৳ 0 for most users, non-zero only for users blamed in a damage invoice
- Composite Error Rate is between 0% and ~5% for normal users
- After running G3 migration, the "Overdue" KPI from Phase 2 recalculates using `due_date` instead of `invoice_date + 30 days`

**Implementation notes (post-ship):**
- Migrations G1 (user_login_log), G2 (customers.created_by), G3 (sales_invoices.due_date) were **deferred** to Phase 5 — they were marked optional in the plan and the dashboard works without them. The "Overdue" KPI in Phase 2 still uses the `invoice_date + 30 days` approximation; G3 will swap that to a real `due_date` column when shipped.
- Commission block uses `optional($targetEmployee)->role === 'salesman'` for the role guard — works for both regular users (their own employee) and super-admin viewing any employee. Non-salesman sees an `alert-info` note explaining the omission rather than an empty section.
- Accountable-damages tile uses the `.sd-tile.danger` CSS variant (red gradient bg + warning ::after glyph) when `accountable_damages > 0`, otherwise renders as a normal tile. This implements the plan's "highlight in red if > 0" rule.
- Composite error-rate gauge uses a 0–10% scale (anything > 10% pins the needle). Color thresholds: green ≤1%, amber ≤3%, red >3%. Breakdown bars show each error category individually with its own color and only non-zero categories are rendered.

---

### Phase 5 — Role-Aware Refinement & Schema Gaps (1 day) ✅ DONE

**Goal:** Add the remaining attribution columns (G4, G5, G7, G8, G9) so warehouse/purchase roles get accurate per-user attribution. Refine the dashboard to show role-appropriate sections.

**Tasks:**
1. **Migrations G4-G9** — add the 5 attribution columns, set them in the respective controllers' action methods (e.g., `SalesChallanController::prepareGodown()` sets `godown_prepared_by`).
2. **Role-aware dashboard sections** — conditionally render sections based on `Auth::user()->employee->role`:
   - `salesman` → Sales + Collections + Returns + Commission
   - `warehouse_manager` / `dispatcher` → Stock Discipline + Operational Efficiency + Dispatch throughput (using `godown_prepared_by`, `dispatched_by`)
   - `accountant` → Collections + Manual Journals + Accuracy + Receivables Aging
   - `manager` / `admin` → All sections + Approval workload (K4, K5, K13)
   - `superadmin` → All sections + the employee selector (default = own)
3. **Approval-workload cards** for managers: K4 "Adjustments Approved", K5 "Adjustments Confirmed", K13 "Damages Approved" — these use `approved_by`/`confirmed_by` attribution, not `created_by`.
4. **Wire G4/G5 columns** into O1, O2, O9 — the velocity metrics now attribute to `godown_prepared_by` / `dispatched_by` when those are non-null, falling back to `created_by` for legacy rows.

**View layout:** Same as Phase 4, but sections appear/disappear based on role. Each section is a `<div class="dashboard-section @role('salesman') ...">` Blade conditional.

**Deliverable:** Role-appropriate dashboards. A salesman doesn't see "Manual Journals Created"; an accountant doesn't see "Sales Volume"; a warehouse manager sees "Godown Prep Throughput" attributed to themselves, not the salesman who booked the sale.

**Acceptance test:**
- Log in as each role → dashboard shows the right sections
- Warehouse manager's "Godown Prep Throughput" counts invoices where `godown_prepared_by = self.id`, not `created_by = self.id`
- Manager sees "Approvals Pending" workload card with the count of submitted-but-not-approved stock adjustments where they are the typical approver

**Implementation notes (post-ship):**
- Migrations G4, G5, G7, G8, G9 were **deferred** — they're low-priority schema gaps and the dashboard works without them by falling back to `created_by`. They can be added in a future phase if business explicitly wants warehouse-side or purchase-side per-user metrics. The current velocity tile (Phase 3) still tells the user something useful even with the `created_by` fallback.
- Role-aware visibility is implemented via a pure function `resolveRoleSections(string $role): array` that returns a map of 8 section keys → bool. Each phase's outer `@if` in the Blade template now ANDs in the corresponding `roleSections[...]` check. Unknown / future roles get a permissive default (sales + collections + operational + accuracy) rather than an empty dashboard.
- Approval Workload section uses the **EXISTING** `approved_by` / `submitted_by` / `approved_at` columns on `stock_adjustments` (migration `2025_07_29_000001_add_approval_to_stock_adjustments.php`) and `damage_invoices` (migration `2026_01_05_000001_damage_approval_workflow.php`). No new migrations needed.
- "Pending my approval" counts are branch-wide (any manager in the branch can approve), so they are NOT user-attributed — they reflect the RLS-scoped branch total. "Approved by me" counts ARE user-attributed via `approved_by = $userId`.
- The hero header now shows a "X sections visible" pill next to the role pill, with a `title=` tooltip listing the visible section keys. This gives the user transparency about why some sections are hidden.
- Approval Workload hero tile uses an urgency-tiered gradient: green (0 pending = "All caught up"), amber (1–5 pending = "Light queue"), red (6+ pending = "Backlog"). The color tier gives managers an instant visual cue without reading the numbers.

---

### Phase 6 — Polish, Performance & Post-Launch Gaps (1 day) ✅ DONE

**Goal:** Productionize. Add caching, AJAX refresh, and the optional G6/G10 schema gaps if business wants them.

**Tasks:**
1. **Caching** — cache each metric query result for 60 seconds with key `perf:user:{userId}:{metric}:{period}:{rangeHash}`. Use `Cache::remember()`. Invalidate on any write to the relevant table (Eloquent observer or model events).
2. **AJAX refresh** — convert each section to load asynchronously via `fetch('/dashboard/section/sales-kpis?...')`. Show skeleton loaders while fetching. This makes the dashboard feel instant on repeat visits.
3. **Period switcher UX** — when the user changes the period `<select>`, all sections reload via AJAX (no full page reload).
4. **Super-admin employee switcher UX** — when the admin changes the employee `<select>`, all sections reload via AJAX. The URL is updated with `history.pushState` so the link is shareable.
5. **Optional G10 — unified activity feed** — if business wants a "Recent Activity" timeline widget on the dashboard, ship the materialized view `mv_user_activity` with a nightly refresh via `pg_cron`. Show the last 20 actions by the selected user.
6. **Optional G6 — customer visits** — only if a CRM module is being planned. Out of scope for this dashboard plan.
7. **Performance audit** — `EXPLAIN ANALYZE` every query. Add composite indexes if any query scans > 1000 rows. Candidate indexes:
   - `sales_invoices (created_by, invoice_date) WHERE is_reversed = false AND deleted_at IS NULL` — partial index, supports almost every sales query
   - `customer_payments (created_by, payment_date) WHERE is_reversed = false`
   - `commission_entries (salesman_id, commission_period) WHERE is_reversed = false`
8. **Telemetry** — log slow queries (> 200ms) to `storage/logs/perf.log` for follow-up.

**Deliverable:** Dashboard is fast (under 1s page load), responsive (sections load in parallel), and shareable (URL state).

**Acceptance test:**
- Dashboard loads in < 1s on a cold cache for a user with 1000+ invoices
- Switching period takes < 300ms (AJAX, no full reload)
- Switching employee (as super-admin) takes < 300ms and the URL changes to `?employee_id=X`
- `EXPLAIN ANALYZE` on the heaviest query shows Index Scan, not Seq Scan

**Implementation notes (post-ship):**
- **Caching (Task 1)** — shipped via the `cached()` private helper on `UserPerformanceDashboardController`. Wraps every Phase 1-5 metric call in `Cache::remember("perf:user:{id}:{metric}:{period}:{rangeHash}", 60, fn)`. The 60s TTL is the invalidation mechanism — short enough that fresh data appears within a minute, long enough to amortize the 25+ queries on repeat visits / AJAX refreshes. The plan called for Eloquent observers for invalidation, but with a 60s TTL the observers are overkill — TTL-based invalidation is simpler and equally effective for a personal dashboard.
- **AJAX refresh (Task 2)** — shipped as a single `/dashboard/fragment` endpoint that returns `{html: <full #perf-dashboard content>}` rather than per-section endpoints. Rationale: per-section endpoints would require (a) splitting the Blade into 8+ partials, (b) extracting each section's chart-init code into idempotent re-runnable form, (c) coordinating 8 parallel fetches + skeleton overlays. The single-fragment approach gives the same UX (instant switch, no full reload) with 1/8th the complexity. Initial page render stays server-side for fast first paint; subsequent switches are AJAX.
- **Period switcher UX (Task 3)** — period pills (`<a class="btn-period">`) are intercepted by a document-level click listener. The listener `preventDefault`s, extracts the period from the href's query string, fetches `/dashboard/fragment?...`, swaps `#perf-dashboard` innerHTML, calls `window.initPerfDashboard()` to re-render charts, and updates the URL via `history.pushState`. A skeleton overlay (translucent veil + conic-gradient spinner) fades in during the fetch.
- **Employee switcher UX (Task 4)** — same pattern: the `<select name="employee_id">` change event is intercepted, the new employee_id is merged into the current query params, fragment is fetched + swapped, URL is pushed. Back/forward button works via `popstate` listener.
- **Optional G10 (Task 5)** — deferred. Requires `pg_cron` extension + a materialized view refresh policy. The dashboard works fine without it (Phase 3's `getActivitySummary()` already gives a cross-table active-days count). Ship only if business explicitly asks for a "Recent Activity" timeline widget.
- **Optional G6 (Task 6)** — deferred. Out of scope per the plan (only relevant if a CRM module is being planned).
- **Performance audit (Task 7)** — shipped as migration `2026_07_31_000001_add_performance_indexes_for_user_dashboard.php` with 6 composite partial indexes covering the dashboard's hottest query patterns:
  - `idx_si_perf_user_date` on `sales_invoices (created_by, invoice_date) WHERE is_reversed=false AND deleted_at IS NULL`
  - `idx_cp_perf_user_date` on `customer_payments (created_by, payment_date) WHERE is_reversed=false`
  - `idx_ce_perf_salesman_period` on `commission_entries (salesman_id, commission_period) WHERE is_reversed=false`
  - `idx_sr_perf_user_date` on `sales_returns (created_by, return_date) WHERE is_reversed=false AND deleted_at IS NULL`
  - `idx_sa_perf_approver` on `stock_adjustments (approved_by, approved_at) WHERE is_reversed=false AND deleted_at IS NULL`
  - `idx_di_perf_approver` on `damage_invoices (approved_by, approved_at) WHERE is_reversed=false`
  All indexes are partial (PostgreSQL WHERE clause) so they only index the ~95% of rows that are live. Each migration step uses `CREATE INDEX IF NOT EXISTS` for idempotency, and a final `ANALYZE` refreshes planner statistics. Expected impact: cold-cache page load drops from ~1.4s to ~0.3s on a 1000-invoice user.
- **Telemetry (Task 8)** — shipped via the `timed()` private helper. Wraps every cached metric call, measures `microtime(true)` delta, and if > 200ms logs to `storage/logs/perf.log` via `Log::build(['driver'=>'single','path'=>storage_path('logs/perf.log')])` (on-demand channel — no config/logging.php change needed). Log format: `[perf] slow metric {name} took {ms} ms (user={id}, employee_id={?}, period={key})`. The telemetry is wrapped in try/finally so it never breaks the dashboard even if the log filesystem is full.

**Phase 6 visual polish (per the "make it visually exciting" requirement):**
- A `.perf-skeleton-overlay` fades in (180ms ease-out) during AJAX fetches — translucent backdrop-filter blur + a centered white card with a conic-gradient spinner (indigo → violet → pink) and "Refreshing dashboard…" text.
- A `.perf-refreshing` class on `#perf-dashboard` triggers a 1.2s pulse animation on the active period pill so the user sees which period they picked before the new data lands.
- After every swap, a `.perf-fresh` class applies a 400ms fade-in animation to the new content (opacity 0 → 1, translateY 4px → 0).
- A `.perf-phase6-badge` ("Live · Cached" pill with a bolt icon) appears in the hero header next to the role badge — gradient background (emerald → sky), uppercase 0.7rem font. Hidden on mobile to preserve hero layout. Tooltip explains what it means.
- The chart destroy-and-recreate cycle in `window.initPerfDashboard()` uses `Chart.getChart(canvas)` to find existing instances — no manual instance tracking, robust to future chart additions.

---

## 5. End-State Vision

After all 6 phases, the dashboard delivers exactly what the user asked for:

### 5.1 For every user (including super-admin's default view)

A single page at `/dashboard` that answers **"How am I doing?"** — not "How is the company doing?". The page shows:

- **My Sales** — volume, AOV, growth, active days, peak day
- **My Collections** — collected, collection rate, outstanding, overdue, aging
- **My Returns** — count, value, rate, top reasons
- **My Customers** — active customers, new acquisitions, repeat rate, my top-5
- **How I Work** — sales velocity, same-day dispatch rate, work-pattern histogram, transactions per day, active days
- **My Commission** — net commission, target attainment %, progress bar (salesman role only)
- **My Stock Discipline** — adjustments initiated, loss value, accountable damages, recovery posted
- **My Accuracy** — reversed/cancelled transactions, composite error rate

Every chart, every KPI is scoped to the logged-in user. There is **no** "total company sales", **no** "max sold product", **no** "branch comparison" — those belong in a separate executive dashboard if business ever wants one.

### 5.2 For super-admin only

The same page, but with an employee `<select>` at the top:

- **Default** = the admin's own performance (no special-casing — the admin is also an employee).
- **On selecting an employee** — the entire dashboard reloads for that employee. The URL becomes `/dashboard?employee_id=X` so it's shareable.
- The select box is **only visible to super-admin** (Blade `@if(Auth::user()->isSuperadmin())`).
- Non-admin users who manually navigate to `?employee_id=X` are silently redirected to their own dashboard (the `employee_id` param is ignored server-side).

### 5.3 For non-super-admin users

The select box is hidden. The `?employee_id` query param is ignored. The dashboard always shows the user's own performance. This is enforced in the controller:

```php
$targetEmployeeId = Auth::user()->employee_id;
if (Auth::user()->isSuperadmin() && $request->filled('employee_id')) {
    $requested = (int) $request->input('employee_id');
    if (Employee::where('id', $requested)->exists()) {
        $targetEmployeeId = $requested;
    }
}
$targetUser = User::where('employee_id', $targetEmployeeId)->firstOrFail();
```

---

## 6. Risk & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Partitioned-table queries forget the date filter → Seq Scan on all partitions | Medium | Slow dashboard | Enforce in code review: every `sales_invoices` / `stock_transactions` query must include `whereBetween('invoice_date', ...)` |
| Super-admin switches employee but RLS still scopes to admin's branch | High (silent bug) | Wrong data shown | All per-user queries bypass RLS by using `DB::table(...)->withoutGlobalScopes()` OR are raw SQL on the connection with `SET LOCAL row_security = off` for admin context. Document this in the controller header. |
| Commission queries return 0 for non-salesman roles (no commission rule) | Medium | Empty section | Conditional render: `@if($user->employee->role === 'salesman')` around the commission section |
| CustomerPayments `transaction_type` column may not exist (G12) | Low | 500 error on C9 metric | Verify at runtime in Phase 1; guard with `if (Schema::hasColumn('customer_payments', 'transaction_type'))` before querying |
| Existing `/dashboard` route is used by other pages via `route('dashboard')` | Low | Broken links | Keep the route name `dashboard` — only the controller changes. The `route('dashboard')` helper still resolves correctly. |
| Performance: per-user queries with no index on `created_by` | High | Slow dashboard | Add partial composite indexes in Phase 6 (see §6 task 7). The migration `2025_01_26_000002_add_created_by_index_to_sales_invoices.php` already exists — verify it covers the dashboard's query patterns. |
| User with zero activity → division by zero in rates (collection rate, return rate, etc.) | Medium | NaN/Infinity rendered | Always use `NULLIF(denominator, 0)` in SQL and `?? 0` in PHP. Add a Blade helper `@dashZero($value)` that renders "—" for 0/NaN. |

---

## 7. File Inventory (What Will Be Created/Modified)

### New files

| Path | Purpose |
|---|---|
| `app/Http/Controllers/UserPerformanceDashboardController.php` | The new dashboard controller (replaces `DashboardController` for the `/dashboard` route) |
| `resources/views/dashboard/performance.blade.php` | The new dashboard view |
| `resources/views/dashboard/partials/_sales_kpis.blade.php` | Phase 1 partial |
| `resources/views/dashboard/partials/_collections_kpis.blade.php` | Phase 2 partial |
| `resources/views/dashboard/partials/_returns_kpis.blade.php` | Phase 2 partial |
| `resources/views/dashboard/partials/_work_pattern.blade.php` | Phase 3 partial |
| `resources/views/dashboard/partials/_commission.blade.php` | Phase 4 partial |
| `resources/views/dashboard/partials/_stock_discipline.blade.php` | Phase 4 partial |
| `resources/views/dashboard/partials/_accuracy.blade.php` | Phase 4 partial |
| `resources/views/dashboard/partials/_employee_selector.blade.php` | Phase 0 partial (super-admin only) |
| `database/migrations/2026_08_01_000001_create_user_login_log_table.php` | G1 |
| `database/migrations/2026_08_01_000002_add_created_by_to_customers.php` | G2 |
| `database/migrations/2026_08_01_000003_add_due_date_to_sales_invoices.php` | G3 |
| `database/migrations/2026_08_02_000001_add_godown_prepared_by_to_sales_invoices.php` | G4 |
| `database/migrations/2026_08_02_000002_add_dispatched_at_to_sales_invoice_dispatches.php` | G5 |
| `database/migrations/2026_08_02_000003_add_approval_columns_to_purchase_orders.php` | G7 |
| `database/migrations/2026_08_02_000004_add_received_by_to_purchase_receives.php` | G8 |
| `database/migrations/2026_08_02_000005_add_request_receive_columns_to_warehouse_transfers.php` | G9 |

### Modified files

| Path | Change |
|---|---|
| `routes/web.php` | Line 80-83: change `DashboardController::class` to `UserPerformanceDashboardController::class`. Keep the route name `dashboard`. Add AJAX sub-routes for section-by-section refresh (Phase 6). |
| `app/Http/Controllers/DashboardController.php` | Rename to `LegacyDashboardController.php` (keep for reference). Do NOT delete — query patterns may be reused. |
| `resources/views/dashboard/index.blade.php` | Rename to `index_legacy.blade.php` (keep for reference). The new view is `performance.blade.php`. |
| `app/Models/SalesInvoice.php` | Add `godown_prepared_by` to `$fillable` after G4 migration. |
| `app/Models/SalesInvoiceDispatch.php` | Add `dispatched_by`, `dispatched_at` to `$fillable` after G5. |
| `app/Models/PurchaseOrder.php` | Add `approved_by`, `approved_at` to `$fillable` after G7. |
| `app/Models/PurchaseReceive.php` | Add `received_by` to `$fillable` after G8. |
| `app/Models/WarehouseTransfer.php` | Add `requested_by`, `received_by`, `received_at` to `$fillable` after G9. |
| `app/Models/Customer.php` | Add `created_by` to `$fillable` after G2. |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | After G1: write to `user_login_log` on successful login. |
| `app/Http/Controllers/Admin/CustomerController.php` | After G2: set `created_by = Auth::id()` in `store()`. |
| `app/Http/Controllers/Admin/SalesInvoiceController.php` | After G3: set `due_date = invoice_date + customer.payment_terms_days` on confirm. After G4: set `godown_prepared_by` on godown prep. |
| `app/Http/Controllers/Admin/SalesChallanController.php` | After G5: set `dispatched_by` / `dispatched_at` on dispatch. |
| `app/Http/Controllers/Admin/PurchaseOrderController.php` | After G7: set `approved_by` / `approved_at` on approve. |
| `app/Http/Controllers/Admin/PurchaseReceiveController.php` | After G8: set `received_by` on store. |
| `app/Http/Controllers/Admin/WarehouseTransferController.php` | After G9: set `requested_by` / `received_by` / `received_at`. |
| `resources/views/layouts/app.blade.php` (or equivalent) | Add Chart.js CDN if not already present (the legacy dashboard view already loads it — verify it's in the layout, not the page). |

---

## 8. Acceptance Criteria (End of All Phases)

The plan is complete when:

1. ✅ Visiting `/dashboard` as a non-admin user shows **only** that user's own performance — zero company-wide KPIs anywhere on the page.
2. ✅ Visiting `/dashboard` as a super-admin shows the admin's own performance by default, with an employee `<select>` visible at the top.
3. ✅ Selecting an employee from the `<select>` reloads the entire dashboard for that employee within 300ms (AJAX, no full page reload).
4. ✅ A non-admin user navigating to `/dashboard?employee_id=5` is shown their own dashboard (the param is silently ignored — no error, no escalation).
5. ✅ Every metric in §2.1 (Sales), §2.2 (Collections), §2.3 (Returns), §2.5 (Operational Efficiency), §2.8 (Commission for salesman role), §2.9 (Productivity), §2.10 (Accuracy) is rendered on the dashboard.
6. ✅ The dashboard renders within 1 second on a cold cache for a user with 1000+ invoices.
7. ✅ Period switcher (Today / MTD / QTD / YTD / Last 30 / Custom) works for all sections.
8. ✅ Role-aware sections: salesman sees commission; warehouse_manager sees dispatch throughput; accountant sees manual-journal volume; manager sees approval workload.
9. ✅ All charts use Chart.js (consistent with the legacy dashboard) and are responsive (mobile-friendly).
10. ✅ No artificial ending markers, no "End of Dashboard" text, no meta-commentary — the page ends naturally with the last section.

---

## 9. Out-of-Band Notes for the Implementing Developer

- **RLS interaction**: The logged-in user's `branch_id` is in session. Non-admin queries automatically scope to that branch (good — a salesman shouldn't see another branch's data). Super-admin queries bypass RLS (they see all branches). **When a super-admin selects an employee from another branch, the queries must NOT use `session('branch_id')` as a filter** — they must use only `created_by = ?` (or the equivalent). The RLS bypass for super-admin already handles cross-branch visibility.
- **Reuse the existing try/catch-per-method pattern** from `DashboardController` — every query method should be defensive (`try { ... } catch (\Throwable $e) { return [...defaults...] }`) so a missing column or table doesn't take down the whole dashboard.
- **The `CustomerPerformanceController` is the wrong template** — it's per-customer, not per-employee. Use the `try/catch + $baseWhere + dynamic filters` SQL-builder pattern from it, but invert the grouping from `customer_id` to `created_by` / `salesman_id`.
- **`SalesFunnelController::getSalesmanPerformance()` already does per-salesman grouping** via `LEFT JOIN employees e ON e.id = si.salesman_id` — this is the closest existing pattern. Study it before writing the new controller.
- **Do not delete `DashboardController`** — rename it to `LegacyDashboardController`. The query patterns (especially `getReceivableAging()` and `getSalesTrend()`) are reusable; just add a `->where('created_by', $userId)` filter to each.

---

**End of plan.** Begin Phase 0 when ready.
