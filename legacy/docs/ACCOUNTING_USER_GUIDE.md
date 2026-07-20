# Accountant User Guide — Remote Center ERP

> **Audience:** Branch accountants, finance managers, admins  
> **App entry:** Sidebar → **Accounting** → **Accounting home** (`/Accounting/index`)  
> **Also in app:** **Accounting → User guide** (`/Accounting/guide`)

---

## 1. Start here — Accounting dashboard

The dashboard shows **today’s GL health** for your branch:

| Widget | Meaning | Action |
|--------|---------|--------|
| **Trial balance (MTD)** | Debits = credits for the month | Green = balanced; red = investigate before close |
| **GL reconciliation** | Sub-ledgers vs control accounts | Open reconciliation hub for details |
| **Accounting period** | Soft lock status | Shows closed-through date if period is locked |
| **Traffic lights** | AR, AP, employee, cash, inventory, COGS | Green / amber / red — tap to jump to section |
| **Recent journals** | Latest posted entries | Link to source document where available |

**Tip:** Refresh the dashboard after month-end posting or large batch imports.

---

## 2. Daily workflows

### Receive customer payment (AR)

1. **Accounting → Customer payments** (or quick nav)
2. **New payment** → select customer, type (Receive / Refund / Discount / Write-off)
3. Check **GL preview** on the right before saving
4. Print slip from details if needed

### Pay supplier (AP)

1. **Supplier payments → New payment**
2. Same pattern: type, amount, cash or bank, GL preview

### Staff money (advances, salary, repayments)

1. **Employee transactions → New**
2. Requires exactly one active `employee_payable` control account in CoA

### Other income / expense / internal transfer

- **Other income** — non-sales receipts (Dr cash/bank, Cr income head)
- **Other expense** — non-purchase payments (Dr expense, Cr cash/bank)
- **Money transfer** — move cash/bank between branches or accounts

### Manual adjusting entry

1. **Manual journals → New journal**
2. Add lines until **Debits = Credits** (within 0.01 Tk)
3. Post — reference type is `manual`

---

## 3. Chart of accounts (CoA)

- **Chart of accounts** (`/ledger`) — browse, create, deactivate heads
- **System heads** are protected; use `ledger_nature` for automation (e.g. `customer_receivable`, `cash_bank`)
- Do not deactivate a head that still has journal activity

---

## 4. Reports (all financial reports live here)

Open **Reports** from the sidebar or **Financial reports** from the accounting hub.

| Report | Use when |
|--------|----------|
| **Trial balance** | Verify Dr = Cr; month-end sign-off |
| **General ledger** | Drill into one account |
| **Journal entries** | Search by type, date, user |
| **Profit & loss** | Income vs expense for a period |
| **Balance sheet** | Assets = Liabilities + Equity as-of date |
| **Cash flow** | Indirect cash movement |
| **Receivable / Payable aging** | Who owes / who we owe |
| **Audit checklists** (Operations) | Sales, purchase, stock, inter-branch GL checks |

Operational modules (payments, vouchers) do **not** duplicate report links — use Reports hub only.

---

## 5. GL reconciliation

1. Open **GL reconciliation** from dashboard or quick nav
2. Set **branch** and **date range** (COGS section uses the range)
3. Fix **red** sections first; **amber** = review mismatches within tolerance
4. Use entity links (customer/supplier modules) to fix sub-ledger drift

Scheduled CLI: `database/scripts/run_gl_reconciliation.php` (admin/ops).

---

## 6. Period close & year-end

### Soft period close

1. **Period close** — pick branch and **closed through date**
2. After close, new postings on or before that date are blocked (admin override configurable)
3. Banner on money forms shows earliest allowed date

### Year-end checklist

1. **Year-end checklist** — run checks (TB, reconciliation, backups)
2. Export TB / GL archive when green
3. Apply period close only when checklist passes

**Reopen** closed period: superadmin only.

---

## 7. Reversals & audit

- Reversed vouchers stay visible via **Show reversed** on index pages
- Reversals create **reversing journal entries** (not silent balance flips)
- **Audit** links on each module show who changed what

---

## 8. Mobile / phone use

- Index pages show **cards** instead of tables on small screens
- Tap **Filters** to expand filter panel
- Touch targets are sized for thumb use; use landscape for wide filter sets if needed

---

## 9. Roles

| Role | Typical access |
|------|----------------|
| **Accountant** | Money modules, reports, reconciliation, manual journals, year-end checks |
| **Manager** | Same as accountant + broader branch visibility where configured |
| **Admin** | Period close, CoA changes, user permissions |
| **Superadmin** | Period reopen, investigation mode overrides |

Exact routes: `app/config/route_roles.php`.

---

## 10. When something looks wrong

1. Run **Trial balance** for the period
2. Open **GL reconciliation** — note which section is red
3. Check **Journal entries** for unexpected `reference_type`
4. Escalate to admin if period lock or CoA nature is blocking posting

**Backup before month-end:** see [ACCOUNTING_BACKUP_RESTORE.md](./ACCOUNTING_BACKUP_RESTORE.md).

---

*Maintained with [ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md) — Phase 8D.*
