# Journal Posting Rules — First Principles Document
## Phase 9.1 — Accounting Engine: Chart of Accounts + Ledger Natures

**Status:** Re-derived from double-entry bookkeeping principles. Must be reviewed + signed off by the accountant before implementation.

---

## 1. The Core Rule (Non-Negotiable)

Every journal entry MUST satisfy:
1. **Dr = Cr** (debits equal credits) — enforced by DB trigger `enforce_balanced_journal_entry()`
2. Every line references an **active ledger** (ledger_id must exist, is_active=true)
3. Posting date falls within an **open accounting period** for the branch
4. Reversals create a **new entry** with swapped Dr/Cr — originals are never mutated
5. Every entry has `reference_type` + `reference_id` linking to the source transaction

---

## 2. Ledger Natures — The Behavior Tags

Each ledger has a `ledger_nature` that tells the posting engine which ledger to use for a given operation. Natures are categorized as **critical** (must resolve to exactly one active ledger) and **extended** (used by specific posting methods).

### 2.1 Critical Natures (7 — each must resolve to exactly one active ledger)

| Nature | Account Type | Normal Balance | Used By | Description |
|---|---|---|---|---|
| `cash_bank` | Asset | Debit | Payments, transfers, money transfers | Cash and bank balances |
| `ar` | Asset | Debit | Sales invoices, customer payments, sales returns | Accounts Receivable (customers owe us) |
| `ap` | Liability | Credit | Purchase receives, supplier payments, purchase returns | Accounts Payable (we owe suppliers) |
| `inventory` | Asset | Debit | All stock movements (receive, issue, adjust, transfer, damage) | Stock valuation at moving-average cost |
| `sales_revenue` | Income | Credit | Sales invoice finalize | Sales revenue |
| `cogs` | Expense | Debit | Sales challan issue, sales return confirm | Cost of Goods Sold |
| `retained_earnings` | Equity | Credit | Year-end close | Accumulated profits/losses |

**Validation rule:** For each critical nature, exactly one active ledger must exist. Zero → error (posting will fail). More than one → error (ambiguous, which one to use?).

### 2.2 Extended Natures (used by specific posting methods)

| Nature | Account Type | Normal Balance | Used By |
|---|---|---|---|
| `sales_return` | Income (contra) | Debit | Sales return confirm (revenue reversal) |
| `sales_discount` | Expense (contra-revenue) | Debit | Sales invoice with discount |
| `transport_revenue` | Income | Credit | Sales invoice with transport cost |
| `inventory_shrinkage` | Expense | Debit | Stock adjustment decrease, stock take loss, damage |
| `inventory_surplus` | Income | Credit | Stock adjustment increase, stock take gain |
| `damage_loss` | Expense | Debit | Damage write-off (falls back to inventory_shrinkage) |
| `employee_payable` | Liability | Credit | Employee transactions (advances, salary) |
| `interbranch_receivable` | Asset | Debit | Cross-branch transfers (Due-from-Branch) |
| `interbranch_payable` | Liability | Credit | Cross-branch transfers (Due-to-Branch) |
| `other_income` | Income | Credit | Other income entries |
| `operating_expense` | Expense | Debit | Other expense entries |
| `salary_expense` | Expense | Debit | Employee salary |
| `payroll_expense` | Expense | Debit | Payroll postings |
| `depreciation` | Expense | Debit | Depreciation |
| `finance_cost` | Expense | Debit | Bank charges, interest |
| `manual_adjustment` | Expense | Debit | Manual journal adjustments |

---

## 3. The ~40 Posting Methods (Re-Derived from Double-Entry)

Each method documents: the Dr/Cr lines, the ledger natures used, and the rate/amount semantics.

### 3.1 Sales Module Postings

#### 3.1.1 postSalesInvoice (Phase 8.2)
When: Invoice finalized from cart.
```
Dr Accounts Receivable (ar)           total_amount
   Cr Sales Revenue (sales_revenue)      subTotal (or subTotal - discount if no discount ledger)
   Cr Transport Revenue (transport_revenue)  transport_cost (if > 0)
Dr Sales Discount (sales_discount)     discount (if > 0)
```
Rate: sales rate (from cart items).
Amount: total = subTotal + transport - discount.

#### 3.1.2 postSalesChallanCOGS (Phase 8.3)
When: Challan issued (stock OUT).
```
Dr COGS (cogs)                        Σ(qty × avg_cost)
   Cr Inventory (inventory)              Σ(qty × avg_cost)
```
Rate: current avg_cost at time of challan.
Amount: COGS = cumulative for all dispatch lines.

#### 3.1.3 postCustomerPayment (Phase 8.4)
When: Customer pays.
```
Dr Cash/Bank (cash_bank) OR Bank Ledger (via bank_ledger_mappings)   amount
   Cr Accounts Receivable (ar)                                          amount
```
Bank mode: Dr bank's mapped ledger. Cash mode: Dr cash_bank nature ledger.
Intercompany (if cross-branch bank): Dr Due-to-Branch / Cr Due-from-Branch.

#### 3.1.4 postSalesReturn — Revenue Reversal (Phase 8.5)
When: Sales return confirmed.
```
Dr Sales Return (sales_return)         Σ(qty × sales_rate)
   Cr Accounts Receivable (ar)           Σ(qty × sales_rate)
```
Rate: sales rate from the original invoice.
Amount: total_amount (revenue reversal).

#### 3.1.5 postSalesReturn — COGS Reversal (Phase 8.5)
When: Sales return confirmed (second journal).
```
Dr Inventory (inventory)               Σ(qty × original_cost)
   Cr COGS (cogs)                        Σ(qty × original_cost)
```
Rate: **ORIGINAL avg_cost** from the challan's stock_transaction (NOT current).
Amount: cogs_amount (COGS reversal).

### 3.2 Purchase Module Postings

#### 3.2.1 postPurchaseReceive (Phase 7.2)
When: GRN confirmed (stock IN).
```
Dr Inventory (inventory)               total_amount
   Cr Accounts Payable (ap)              total_amount
```
Rate: purchase rate (from GRN items).
Amount: total_amount of the GRN.

#### 3.2.2 postPurchaseReturn (Phase 7.3)
When: Purchase return confirmed (stock OUT).
```
Dr Accounts Payable (ap)               total_amount
   Cr Inventory (inventory)              total_amount
```
Rate: ORIGINAL receive rate from GRN item (NOT current avg_cost).
Amount: total_amount of the return.

### 3.3 Stock Module Postings

#### 3.3.1 postStockAdjustment — Increase (Phase 6.3)
When: Stock adjustment confirmed (stock IN).
```
Dr Inventory (inventory)               total_value
   Cr Inventory Surplus (inventory_surplus)  total_value
```

#### 3.3.2 postStockAdjustment — Decrease (Phase 6.3)
When: Stock adjustment confirmed (stock OUT).
```
Dr Inventory Shrinkage (inventory_shrinkage)  total_value
   Cr Inventory (inventory)                      total_value
```

#### 3.3.3 postStockTake — Net Gain (Phase 6.4)
When: Stock take posted, net physical > system.
```
Dr Inventory (inventory)               gain_value
   Cr Inventory Surplus (inventory_surplus)  gain_value
```

#### 3.3.4 postStockTake — Net Loss (Phase 6.4)
When: Stock take posted, net physical < system.
```
Dr Inventory Shrinkage (inventory_shrinkage)  loss_value
   Cr Inventory (inventory)                      loss_value
```
Rate: current avg_cost at time of stock take.

#### 3.3.5 postWarehouseTransfer — Cross-Branch (Phase 6.5)
When: Cross-branch transfer confirmed.
Two journals (intercompany):
```
From-branch (creditor):
  Dr Due-to-Branch (interbranch_payable)     amount
     Cr Inventory (inventory)                   amount

To-branch (debtor):
  Dr Inventory (inventory)                     amount
     Cr Due-from-Branch (interbranch_receivable)  amount
```
Rate: source avg_cost (transferred at source cost).
Same-branch: NO GL (inventory reallocated within branch).

#### 3.3.6 postDamage (Phase 6.6)
When: Damage confirmed (stock OUT).
```
Dr Damage Loss (damage_loss) OR Inventory Shrinkage (inventory_shrinkage)  total_value
   Cr Inventory (inventory)                                                   total_value
```
Rate: current avg_cost at time of damage.
damage_loss nature looked up first, falls back to inventory_shrinkage.

### 3.4 Accounting Module Postings

#### 3.4.1 postManualJournal
When: Manual journal entry created by accountant.
```
User-defined lines (subject to Dr=Cr + period validation)
```
No automatic nature lookup — user selects specific ledgers.

#### 3.4.2 postOtherIncome
When: Other income entry confirmed.
```
Dr Cash/Bank (cash_bank)              amount
   Cr Other Income (other_income)        amount
```

#### 3.4.3 postOtherExpense
When: Other expense entry confirmed.
```
Dr Operating Expense (operating_expense)  amount
   Cr Cash/Bank (cash_bank)                  amount
```

#### 3.4.4 postMoneyTransfer — Cash to Bank
When: Money transferred from cash to bank.
```
Dr Bank Ledger (via bank_ledger_mappings)  amount
   Cr Cash/Bank (cash_bank)                   amount
```

#### 3.4.5 postMoneyTransfer — Bank to Cash
When: Money transferred from bank to cash.
```
Dr Cash/Bank (cash_bank)               amount
   Cr Bank Ledger (via bank_ledger_mappings)  amount
```

#### 3.4.6 postMoneyTransfer — Cash to Cash
When: Same cash ledger → NO GL (just a record).

#### 3.4.7 postMoneyTransfer — Bank to Bank
When: Bank to bank → Dr dest bank / Cr source bank.

#### 3.4.8 postEmployeeTransaction — Advance/Salary
When: Employee payment confirmed.
```
Dr Salary Expense (salary_expense) OR Operating Expense  amount
   Cr Cash/Bank (cash_bank)                                 amount
+ Employee Ledger sub-ledger entry (debit = advance given)
```

#### 3.4.9 postYearEndClose
When: Year-end close executed.
```
For each Income ledger:
  Dr Income ledger (balance) → Cr Retained Earnings (retained_earnings)

For each Expense ledger:
  Dr Retained Earnings (retained_earnings) → Cr Expense ledger (balance)
```
All income/expense ledgers zeroed, net profit/loss transferred to retained_earnings.

---

## 4. Ledger Hierarchy (Chart of Accounts Structure)

```
Level 1: Main Groups (account_type)
  ├── Asset
  │   ├── Current Assets
  │   │   ├── Cash in Hand          (nature: cash_bank)
  │   │   ├── Bank Accounts          (nature: cash_bank, control_account)
  │   │   ├── Accounts Receivable    (nature: ar, control_account)
  │   │   └── Inventory              (nature: inventory)
  │   └── Fixed Assets
  │       ├── Due from Branches      (nature: interbranch_receivable)
  │       └── ...
  ├── Liability
  │   ├── Current Liabilities
  │   │   ├── Accounts Payable       (nature: ap, control_account)
  │   │   ├── Employee Payable       (nature: employee_payable)
  │   │   └── Due to Branches        (nature: interbranch_payable)
  │   └── Long Term Liabilities
  ├── Equity
  │   ├── Owner's Equity
  │   └── Retained Earnings          (nature: retained_earnings)
  ├── Income
  │   ├── Sales Revenue              (nature: sales_revenue)
  │   ├── Sales Return               (nature: sales_return, contra)
  │   ├── Other Income               (nature: other_income)
  │   └── Transport Revenue          (nature: transport_revenue)
  └── Expense
      ├── COGS                       (nature: cogs)
      ├── Inventory Shrinkage        (nature: inventory_shrinkage)
      ├── Inventory Surplus          (nature: inventory_surplus, contra-income)
      ├── Damage Loss                (nature: damage_loss)
      ├── Sales Discount             (nature: sales_discount, contra-revenue)
      ├── Salary Expense             (nature: salary_expense)
      ├── Operating Expense          (nature: operating_expense)
      └── Financial Expenses         (nature: finance_cost)
```

---

## 5. Control Accounts

Control accounts are parent ledgers that represent the aggregate of a sub-ledger:
- `ar` control account = Σ of all customer_ledger balances
- `ap` control account = Σ of all supplier_ledger balances
- `employee_payable` control account = Σ of all employee_ledger balances

The reconciliation hub (Phase 5) verifies that control account GL balance = sub-ledger total.

---

## 6. Validation Rules

### 6.1 Critical Nature Validation
For each of the 7 critical natures:
```sql
SELECT COUNT(*) FROM ledgers
WHERE ledger_nature = ? AND is_active = true AND deleted_at IS NULL;
```
Must return exactly 1. If 0 → "Nature X not configured". If >1 → "Nature X has multiple ledgers".

### 6.2 Account Type Consistency
Each nature must map to the correct account_type:
- cash_bank, ar, inventory, interbranch_receivable → Asset
- ap, employee_payable, interbranch_payable → Liability
- retained_earnings → Equity
- sales_revenue, other_income, transport_revenue, inventory_surplus → Income
- cogs, sales_return, sales_discount, inventory_shrinkage, damage_loss, operating_expense, salary_expense, finance_cost → Expense

### 6.3 Period Validation
Before posting, check:
```sql
SELECT closed_through_date FROM accounting_periods WHERE branch_id = ?;
```
If posting_date <= closed_through_date → reject (period is closed).

---

## 7. Sign-off

- [ ] Accountant: this document reviewed and approved
- [ ] Lead developer: CoA validation command passes
- [ ] All 7 critical natures resolve to exactly one active ledger
- [ ] All extended natures documented and mapped
- [ ] Period close validation tested

---

*This document is the single source of truth for GL posting in RC_ERP. Any change to posting logic requires updating this document.*
