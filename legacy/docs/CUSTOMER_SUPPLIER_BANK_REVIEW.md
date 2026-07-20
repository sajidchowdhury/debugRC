# Customer, Supplier & Bank Setup — Review

Date: 2026-06-19  
Scope: Master data (`customer/`, `supplier/`, `bank/`) and accounting transactions (`Accounting/customer/`, `Accounting/supplier/`).

---

## How it is organized

| Area | Master data (directory) | Money flow (payments) |
|------|-------------------------|------------------------|
| Customer | `CustomerController` → `app/views/customer/` | `CustomerTransactionController` → `app/views/Accounting/customer/` |
| Supplier | `SupplierController` → `app/views/supplier/` | `SupplierTransactionController` → `app/views/Accounting/supplier/` |
| Bank | `BankController` → `app/views/bank/` | Used by customer/supplier payments, money transfer, other income/expense |

Master data and payments are separate modules. That split is fine, but it means a full picture of one party (e.g. customer AR + payment history) is spread across two screens.

---

## Customer

### What works

- **UI** — Uses the same `branch-hub` layout as branch/warehouse: hero, stats, quick links, DataTables list, mobile cards.
- **Master fields** — Shop name, contact, mobile, address, sales person, credit limit. Credit limit is enforced in sales (with override flow).
- **Mobile uniqueness** — Checked on create/update.
- **Deactivation guard (AJAX paths)** — `toggle` and `delete` block deactivation when there is outstanding AR or any sales invoice history.
- **Index stats** — Active/inactive count, customers with due, total receivable (from latest `customer_ledger` row per customer).
- **Edit sidebar** — Shows balance, sales count, and whether deactivation is allowed.
- **Payments** — Receive, refund, discount, write-off. Posts to `customer_ledger`, GL via `JournalPostingService`, and `banks.balance` when mode is bank. Receive also runs branch intercompany settlement.
- **Reversal** — Reverses ledger, GL, and bank book; audit logged. Branch-scoped access for non-admin users.
- **Roles** — Salesman can maintain customers and record payments; only admin/manager/accountant can reverse payments or deactivate.

### Gaps / risks

- **No detail/show page** — Unlike branch/warehouse, there is no read-only hub with ledger summary, recent invoices, and payment links.
- **Deactivation bypass on form update** — `customer/update` writes `is_active` directly. Safety checks exist on `toggle`/`delete` but not on the edit form. A user with edit access can set inactive while balance or sales history exists.
- **Auto code generation** — `C-0001` style codes use `COUNT(*) + 1`. Deleted/inactive rows still count; concurrent creates can collide. No DB unique constraint mentioned in model.
- **Audit is shallow** — Uses `UserAudit` with action names only. Branch/warehouse use `MasterDataAuditHelper` for field-level before/after on updates. Customer updates log name/mobile but not credit limit or sales person changes in a structured way.
- **Payment create UX** — Customer dropdown loads all active customers in HTML. Fine for small lists; slow and awkward at scale (no search/typeahead like sales module).
- **Global master list** — Customers are not branch-scoped (probably correct for AR), but payment list is branch-scoped. Worth documenting for users.
- **Credit limit** — Enforced on sales, not re-checked when posting a write-off/discount beyond business rules (only “amount ≤ due” is checked).

---

## Supplier

### What works

- **Same hub pattern** as customer — stats, inactive list, audit link, DataTables.
- **Deactivation guard (AJAX)** — Blocks when payable balance exists or there is purchase receive history.
- **Edit sidebar** — Outstanding payable and purchase count.
- **Payments** — Payment, advance, receive (refund/credit from supplier). Ledger + GL + bank book in one DB transaction.
- **Reversal** — Same pattern as customer payments with audit.
- **Tighter roles** — Only admin/manager create/edit suppliers. Accountant can view and post payments. Salesman has no supplier access (unlike customer).

### Gaps / risks

- **Thinner master record** — Name, mobile, address only. No contact person, payment terms, tax ID, or default purchase terms.
- **Same code generation issue** as customer (`S-0001` from count).
- **Same deactivation bypass** on `supplier/update` via `is_active` radio on edit form.
- **No show/detail page** for supplier + payable snapshot + recent purchases/payments.
- **No intercompany/settlement hook** on supplier payment (customer receive has `BranchIntercompanyService`; supplier does not).
- **Helper typo** — `Get_ALl_Active_Supplier()` (inconsistent casing vs other helpers); works but signals copy-paste drift.
- **Audit** — Same shallow `UserAudit` as customer, not `MasterDataAuditHelper`.

---

## Bank

### What works

- **Hub UI** — Active/inactive lists, total balance stat, links to money transfer and customer payments.
- **Fields** — Bank name, account number, branch name. Balance is system-maintained, not user-edited (correct).
- **GL mapping (Phase 5)** — On edit, optional per-bank ledger from `cash_bank` nature via `bank_ledger_mappings`. Falls back to bank control account if unset.
- **Balance updates** — `BankModel::updateBalance()` called from payment/transfer flows for bank mode.
- **Edit sidebar** — Current balance, GL account picker, link to money transfer.
- **Roles** — Admin, manager, accountant manage banks; toggle restricted to admin/manager.

### Gaps / risks

- **No deactivation safety** — Customer/supplier block deactivate when money/history exists. Bank `toggle`/`delete`/`update is_active` have **no** check for non-zero balance or linked payments/transfers. An inactive bank can still be referenced historically but disappears from dropdowns while balance remains.
- **No account number uniqueness** — Duplicate account numbers allowed.
- **GL mapping only on edit** — Create form has no ledger picker; new banks always use default control until someone edits.
- **Denormalized balance** — `banks.balance` is updated by app code, not derived from GL. Drift between cash book and chart of accounts is possible if posting paths diverge or manual DB fixes happen.
- **No show page** — No transaction history or reconciliation view per bank account.
- **Weaker create feedback** — `createBank()` returns boolean; customer/supplier return structured `{status, message}`.
- **Audit** — Basic action log only; no field-level diff.

---

## Accounting transaction views (shared)

### Strengths

- Consistent money-flow UI (`accounting-money-flow.css`, transaction themes).
- Default date filter = today (good for daily cashier work).
- Details page shows ledger lines, journal entry, settlements (customer), due snapshot, printable slip.
- CSRF on store/reverse; JSON API-style responses on write actions.
- `CustomerTransaction.js` / `SupplierTransaction.js` document transaction types in UI hints.

### Weaknesses

- **Index is not server-side DataTables** — Full result set loaded in PHP for the filtered date range. Large date ranges will slow down.
- **Duplicated logic** — `syncBankBookBalance`, branch filter helpers, and controller structure are near copies between customer and supplier transaction models.
- **Customer index `can_reverse`** — Sets `can_reverse = empty(is_reversed)` in controller loop instead of calling `canUserReversePayment()` like supplier index (minor inconsistency; role check may still happen on reverse action).

---

## Comparison to newer master-data modules

Branch and warehouse recently gained:

- `show` read-only pages  
- `MasterDataAuditHelper` for enriched audit  
- Stronger integrity rules on edit  

Customer, supplier, and bank have the hub **look** but not the full **depth** (show page, structured audit, consistent safety on all deactivate paths).

---

## Suggested priorities (if improving later)

1. **High** — Apply deactivation safety on `update()` for customer and supplier when `is_active = 0`. Add similar checks for bank (balance = 0 and no recent payment references, or warn-only).
2. **High** — Replace count-based codes with a sequence table or `MAX(code)+1` with unique index on `customer_code` / `supplier_code`.
3. **Medium** — Bank account number uniqueness; optional GL mapping on create.
4. **Medium** — Customer/supplier show pages (balance, last 10 ledger rows, links to payments/purchases).
5. **Medium** — Adopt `MasterDataAuditHelper` for customer/supplier/bank updates.
6. **Low** — Searchable customer/supplier picker on payment create (reuse sales customer search pattern).
7. **Low** — Extract shared payment transaction trait/service to cut duplication.

---

## File reference

| Module | Controller | Model | Views |
|--------|------------|-------|-------|
| Customer master | `app/controllers/CustomerController.php` | `app/models/CustomerModel.php` | `app/views/customer/` |
| Customer payments | `app/controllers/CustomerTransactionController.php` | `app/models/CustomerTransactionModel.php` | `app/views/Accounting/customer/` |
| Supplier master | `app/controllers/SupplierController.php` | `app/models/SupplierModel.php` | `app/views/supplier/` |
| Supplier payments | `app/controllers/SupplierTransactionController.php` | `app/models/SupplierTransactionModel.php` | `app/views/Accounting/supplier/` |
| Bank | `app/controllers/BankController.php` | `app/models/BankModel.php`, `app/models/BankLedgerMappingModel.php` | `app/views/bank/` |

Roles: `app/config/route_roles.php` (`CustomerController`, `SupplierController`, `BankController`, `CustomerTransactionController`, `SupplierTransactionController`).
