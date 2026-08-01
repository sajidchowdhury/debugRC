# Accounts / Sub-Ledger Implementation Plan for Laravel

> **Purpose:** Phase-by-phase documentation to activate the Accounts / Sub-Ledger menus (Customer, Supplier, Employee Transactions) in the Laravel RC-ERP project with PostgreSQL, based on a thorough analysis of the legacy PHP/MySQL system.
>
> **Last Updated:** March 2026
>
> **Related Docs:**
> - [Legacy ACCOUNTING_MASTER_PLAN.md](../../legacy/docs/ACCOUNTING_MASTER_PLAN.md) — Single source of truth for the full accounting system
> - [Journal Posting Rules](../../docs/migration/journal_posting_rules.md) — Dr/Cr rules for all ~40 posting methods
> - [Schema Mapping MySQL→PostgreSQL](../../docs/migration/schema_mapping.md) — Database migration reference
> - [Laravel 02_accounting.sql](../database/sql/02_accounting.sql) — PostgreSQL schema already in place
> - [Laravel 06_payment_and_misc.sql](../database/sql/06_payment_and_misc.sql) — Payment tables already in place

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Legacy System Analysis](#2-legacy-system-analysis)
3. [Laravel Current State](#3-laravel-current-state)
4. [Gap Analysis](#4-gap-analysis)
5. [Architecture Decisions](#5-architecture-decisions)
6. [Phase 1: Supplier Transaction Module](#6-phase-1-supplier-transaction-module)
7. [Phase 2: Employee Transaction Module](#7-phase-2-employee-transaction-module)
8. [Phase 3: Customer Transaction Enhancement](#8-phase-3-customer-transaction-enhancement)
9. [Phase 4: Money Transfer Module](#9-phase-4-money-transfer-module)
10. [Phase 5: Other Income & Expense Modules](#10-phase-5-other-income--expense-modules)
11. [Phase 6: Manual Journal Module](#11-phase-6-manual-journal-module)
12. [Phase 7: Accounting Dashboard & Navigation](#12-phase-7-accounting-dashboard--navigation)
13. [Phase 8: Reconciliation & Reporting Enhancements](#13-phase-8-reconciliation--reporting-enhancements)
14. [Phase 9: Advanced Features & Polish](#14-phase-9-advanced-features--polish)
15. [Verification Gates](#15-verification-gates)
16. [Appendix A: Legacy vs Laravel Schema Comparison](#appendix-a-legacy-vs-laravel-schema-comparison)
17. [Appendix B: Debit/Credit Rules by Entity Type](#appendix-b-debitcredit-rules-by-entity-type)
18. [Appendix C: GL Posting Rules Reference](#appendix-c-gl-posting-rules-reference)

---

## 1. Executive Summary

The legacy RC-ERP system has a **complete, working accounting module** with sub-ledgers for Customer, Supplier, and Employee — each with its own controller, model, views (index/create/details/audit/slip), and JavaScript frontend. The Laravel port has **partially migrated** the accounting system:

- ✅ **Database schema** is fully in place (PostgreSQL) — all tables exist
- ✅ **Core services** are ported (JournalPostingService, SubLedgerService, ReconciliationService, etc.)
- ✅ **Eloquent models** exist for sub-ledgers (CustomerLedger, SupplierLedger, EmployeeLedger)
- ✅ **Customer Payment** module is fully implemented (controller, service, views, routes)
- ❌ **Supplier Transaction** module has NO controller, service, views, or routes
- ❌ **Employee Transaction** module has NO controller, service, views, or routes
- ❌ **Money Transfer** module has NO controller, service, views, or routes
- ❌ **Other Income/Expense** modules have NO controller, service, views, or routes
- ❌ **Manual Journal** module has NO controller, service, views, or routes
- ❌ **Accounting Dashboard** hub page does not exist in Laravel

The three sub-ledger modules (Customer, Supplier, Employee) share **~85% identical business logic** — the main differences are:
1. Entity type (customer vs supplier vs employee)
2. Transaction types (different ENUMs)
3. Debit/credit side rules (which side increases/decreases the balance)
4. GL posting methods (which ledger natures to hit)

This document provides a **phase-by-phase implementation plan** to close these gaps, starting with the highest-priority modules and building reusable patterns.

---

## 2. Legacy System Analysis

### 2.1 Architecture Overview

The legacy system follows a **dual-write pattern**:

```
Business Event → Controller → Model → {
    1. Insert transaction record (e.g., supplier_payments)
    2. Insert sub-ledger entry (e.g., supplier_ledger with running balance)
    3. Call JournalPostingService → insert journal_entries + journal_lines (GL)
    4. Sync bank book balance (if bank mode)
    5. Commit transaction
}
```

All operations are wrapped in database transactions. If any step fails, the entire operation rolls back.

### 2.2 Customer Transaction Module (Legacy)

**Files:**
- Controller: `legacy/app/controllers/CustomerTransactionController.php`
- Model: `legacy/app/models/CustomerTransactionModel.php` (also `Accounting/CustomerTransactionModel.php`)
- Views: `legacy/app/views/Accounting/customer/{index,create,details,audit,slip}.php`
- JS: `legacy/public/assets/js/CustomerTransaction.js`

**Transaction Types:** `receive`, `payment`, `discount`, `write_off`

**Debit/Credit Rules (customer_ledger):**
- Positive running_balance = customer owes us (AR)
- `receive`: credit (customer owes less) → Dr Bank/Cash, Cr AR
- `payment`: debit (customer owes more — refund) → Dr AR, Cr Bank/Cash
- `discount`: credit (customer owes less — discount allowed) → Dr Sales Discount, Cr AR
- `write_off`: credit (customer owes less — bad debt) → Dr Bad Debt Expense, Cr AR

**Key Business Logic:**
1. Validates amount > 0, customer exists and is active
2. Validates payment mode (cash/bank) and bank_id if bank mode
3. Generates document code (CP-YYYY-NNNNN) via sequence
4. Inserts into `customer_payments` table
5. Inserts into `customer_ledger` with running balance
6. Calls `JournalPostingService::postCustomerTransactionJournal()`
7. Links `journal_entry_id` back to the payment record
8. Syncs bank book balance if bank mode
9. Records audit log via `UserAudit`

**Reversal Logic:**
1. Validates transaction exists, not already reversed, user has access
2. Reverses the linked journal entry via `JournalPostingService::reverseLinkedJournal()`
3. Posts opposite entry to `customer_ledger` (swap debit/credit)
4. Marks original `customer_ledger` entry as `is_reversed = 1`
5. Marks `customer_payments` as `is_reversed = 1`
6. Syncs bank book balance (undo)
7. Records audit log

### 2.3 Supplier Transaction Module (Legacy)

**Files:**
- Controller: `legacy/app/controllers/SupplierTransactionController.php`
- Model: `legacy/app/models/SupplierTransactionModel.php`
- Views: `legacy/app/views/Accounting/supplier/{index,create,details,audit,slip}.php`
- JS: `legacy/public/assets/js/SupplierTransaction.js`

**Transaction Types:** `payment`, `advance`, `receive`

**Debit/Credit Rules (supplier_ledger):**
- Positive running_balance = we owe supplier (AP)
- `payment`: debit (we owe less) → Dr AP, Cr Bank/Cash
- `advance`: debit (we owe less — advance given) → Dr AP, Cr Bank/Cash
- `receive`: credit (we owe more — goods received on credit) → Dr Inventory/Purchase, Cr AP

**Key Business Logic:**
1. Same validation pattern as customer
2. Generates document code (SP-YYYY-NNNNN)
3. Inserts into `supplier_payments` table
4. Inserts into `supplier_ledger` with running balance
5. Calls `JournalPostingService::postSupplierTransactionJournal()`
6. Links `journal_entry_id` back to the payment record
7. Syncs bank book balance if bank mode
8. Supports DataTables for AJAX pagination on index
9. Search supplier by name (AJAX endpoint)

**Reversal Logic:** Same pattern as customer — swap sides, mark reversed, undo bank balance.

### 2.4 Employee Transaction Module (Legacy)

**Files:**
- Controller: `legacy/app/controllers/EmployeeTransactionController.php`
- Model: `legacy/app/models/EmployeeTransactionModel.php`
- Views: `legacy/app/views/Accounting/employee/{index,create,details,audit,slip}.php`
- JS: `legacy/public/assets/js/EmployeeTransaction.js`

**Transaction Types:** `advance`, `loan`, `repayment`, `salary`, `deduction`, `adjustment`

**Debit/Credit Rules (employee_ledger):**
- Positive running_balance = employee owes company (advance/loan taken)
- `advance`: debit (employee owes more — money given out) → Dr Employee Payable, Cr Bank/Cash
- `loan`: debit (employee owes more — loan disbursed) → Dr Employee Payable, Cr Bank/Cash
- `salary`: debit (employee owes more — salary paid = advance) → Dr Salary Expense, Cr Bank/Cash
- `repayment`: credit (employee owes less — repaid) → Dr Bank/Cash, Cr Employee Payable
- `deduction`: credit (employee owes less — deducted from salary) → Dr Salary Expense, Cr Employee Payable
- `adjustment`: debit or credit depending on context

**Key Business Logic:**
1. Same validation pattern as customer/supplier
2. Generates document code (ET-YYYY-NNNNN)
3. Inserts into `employee_transactions` table
4. Inserts into `employee_ledger` with running balance
5. Calls `JournalPostingService::postEmployeeTransactionJournal()`
6. Links `journal_entry_id` back to the transaction record
7. Syncs bank book balance if bank mode
8. Has `collected_by` field (who collected the cash from the employee)
9. Checks `employee_payable` ledger status before allowing certain transactions

**Reversal Logic:** Same pattern — swap sides, mark reversed, undo bank balance.

### 2.5 Common Patterns Across All Three Modules

| Feature | Customer | Supplier | Employee |
|---|---|---|---|
| **Index page** | Filtered list with stats | DataTables + stats | Filtered list with stats |
| **Create page** | Select entity, type, mode, amount | Select entity, type, mode, amount | Select entity, type, mode, amount |
| **Details page** | Show transaction + sub-ledger + GL journal | Show transaction + sub-ledger + GL journal | Show transaction + sub-ledger + GL journal |
| **Slip/Print** | Printable voucher slip | Printable voucher slip | Printable voucher slip |
| **Audit page** | Recent audit logs | Recent audit logs | Recent audit logs |
| **Reversal** | Swap Dr/Cr, reverse JE, mark reversed | Swap Dr/Cr, reverse JE, mark reversed | Swap Dr/Cr, reverse JE, mark reversed |
| **Bank sync** | Update bank balance | Update bank balance | Update bank balance |
| **Document code** | CP-YYYY-NNNNN | SP-YYYY-NNNNN | ET-YYYY-NNNNN |
| **Branch access** | User can only see own branch | User can only see own branch | User can only see own branch |
| **GL Preview** | Live preview of Dr/Cr before save | Live preview of Dr/Cr before save | Live preview of Dr/Cr before save |
| **Due balance** | Customer's current AR balance | Supplier's current AP balance | Employee's current payable balance |

---

## 3. Laravel Current State

### 3.1 What Already Exists

| Component | Status | File Path |
|---|---|---|
| **Database Schema** | ✅ Complete | `database/sql/02_accounting.sql`, `06_payment_and_misc.sql` |
| **JournalPostingService** | ✅ Complete | `app/Services/Accounting/JournalPostingService.php` |
| **SubLedgerService** | ✅ Complete | `app/Services/Accounting/SubLedgerService.php` |
| **JournalReversalService** | ✅ Complete | `app/Services/Accounting/JournalReversalService.php` |
| **AccountingPeriodService** | ✅ Complete | `app/Services/Accounting/AccountingPeriodService.php` |
| **ReconciliationService** | ✅ Complete | `app/Services/Accounting/ReconciliationService.php` |
| **LedgerNatureService** | ✅ Complete | `app/Services/Accounting/LedgerNatureService.php` |
| **DocumentSequenceService** | ✅ Complete | `app/Services/Accounting/DocumentSequenceService.php` |
| **CustomerLedger Model** | ✅ Complete | `app/Models/CustomerLedger.php` |
| **SupplierLedger Model** | ✅ Complete | `app/Models/SupplierLedger.php` |
| **EmployeeLedger Model** | ✅ Complete | `app/Models/EmployeeLedger.php` |
| **JournalEntry Model** | ✅ Complete | `app/Models/Accounting/JournalEntry.php` |
| **JournalLine Model** | ✅ Complete | `app/Models/Accounting/JournalLine.php` |
| **BankLedgerMapping Model** | ✅ Complete | `app/Models/BankLedgerMapping.php` |
| **CustomerPaymentController** | ✅ Complete | `app/Http/Controllers/Admin/CustomerPaymentController.php` |
| **CustomerPaymentService** | ✅ Complete | `app/Services/Sales/CustomerPaymentService.php` |
| **CustomerPayment Views** | ✅ Complete | `resources/views/admin/customer-payments/` |
| **CustomerPayment Routes** | ✅ Complete | `routes/web.php` (resource + custom) |
| **Period Close Controller** | ✅ Complete | `app/Http/Controllers/Admin/AccountingPeriodController.php` |
| **LedgerController** | ✅ Complete | `app/Http/Controllers/Admin/LedgerController.php` |
| **SubLedgerReconcile Command** | ✅ Complete | `app/Console/Commands/SubLedgerReconcile.php` |
| **ValidateChartOfAccounts Command** | ✅ Complete | `app/Console/Commands/ValidateChartOfAccounts.php` |
| **Accounting Config** | ✅ Complete | `config/accounting.php` |

### 3.2 What Is Missing

| Component | Status | Priority |
|---|---|---|
| **SupplierTransactionController** | ❌ Missing | HIGH |
| **SupplierTransactionService** | ❌ Missing | HIGH |
| **SupplierTransaction Views** | ❌ Missing | HIGH |
| **SupplierTransaction Routes** | ❌ Missing | HIGH |
| **EmployeeTransactionController** | ❌ Missing | HIGH |
| **EmployeeTransactionService** | ❌ Missing | HIGH |
| **EmployeeTransaction Views** | ❌ Missing | HIGH |
| **EmployeeTransaction Routes** | ❌ Missing | HIGH |
| **MoneyTransferController** | ❌ Missing | MEDIUM |
| **MoneyTransferService** | ❌ Missing | MEDIUM |
| **MoneyTransfer Views** | ❌ Missing | MEDIUM |
| **MoneyTransfer Routes** | ❌ Missing | MEDIUM |
| **OtherIncomeController** | ❌ Missing | MEDIUM |
| **OtherIncomeService** | ❌ Missing | MEDIUM |
| **OtherIncome Views** | ❌ Missing | MEDIUM |
| **OtherExpenseController** | ❌ Missing | MEDIUM |
| **OtherExpenseService** | ❌ Missing | MEDIUM |
| **OtherExpense Views** | ❌ Missing | MEDIUM |
| **ManualJournalController** | ❌ Missing | MEDIUM |
| **ManualJournalService** | ❌ Missing | MEDIUM |
| **ManualJournal Views** | ❌ Missing | MEDIUM |
| **Accounting Dashboard** | ❌ Missing | HIGH |
| **Accounting Sidebar Menu** | ❌ Missing | HIGH |
| **Bank Balance Sync Service** | ❌ Missing | HIGH |
| **SupplierPayment Model** | ❌ Missing | HIGH |
| **EmployeeTransaction Model** | ❌ Missing | HIGH |
| **MoneyTransfer Model** | ❌ Missing | MEDIUM |
| **OtherIncome Model** | ❌ Missing | MEDIUM |
| **OtherExpense Model** | ❌ Missing | MEDIUM |
| **ManualJournal Model** | ❌ Missing | MEDIUM |

---

## 4. Gap Analysis

### 4.1 Critical Gaps (Must Fix First)

| # | Gap | Impact | Legacy Equivalent |
|---|---|---|---|
| G1 | No Supplier Transaction workflow | Cannot pay suppliers or track AP | `SupplierTransactionController` + `SupplierTransactionModel` |
| G2 | No Employee Transaction workflow | Cannot manage employee advances/loans/salary | `EmployeeTransactionController` + `EmployeeTransactionModel` |
| G3 | No Bank Balance Sync | Bank balances don't update on transactions | `syncBankBookBalance()` in all 3 models |
| G4 | No Accounting Dashboard | No entry point for the accounting module | `AccountingController::index()` |
| G5 | No Accounting Navigation | Users can't find accounting features | `accounting_sidebar_menu.php` |

### 4.2 Important Gaps (Second Priority)

| # | Gap | Impact | Legacy Equivalent |
|---|---|---|---|
| G6 | No Money Transfer workflow | Cannot transfer between cash/bank | `MoneyTransferController` |
| G7 | No Other Income workflow | Cannot record non-operational income | `OtherIncomeController` |
| G8 | No Other Expense workflow | Cannot record non-operational expenses | `OtherExpenseController` |
| G9 | No Manual Journal workflow | Accountants can't make manual adjustments | `ManualJournalController` |

### 4.3 Enhancement Opportunities (Better Than Legacy)

| # | Enhancement | Legacy Weakness | Laravel Improvement |
|---|---|---|---|
| E1 | Reusable BaseTransactionService | 3 separate models with ~85% duplicate code | Single abstract service with entity-specific strategies |
| E2 | Event-driven architecture | Direct model calls in controllers | Laravel Events + Listeners for GL posting, bank sync, audit |
| E3 | Form Request validation | Manual validation in models | Laravel FormRequest classes |
| E4 | API Resources | Direct array returns | Laravel API Resources for JSON responses |
| E5 | Policy-based authorization | Manual `canOverrideBranch()` checks | Laravel Policies with proper RBAC |
| E6 | Database-level constraints | Some constraints only in PHP | PostgreSQL CHECK constraints + triggers |
| E7 | Queue-based bank sync | Synchronous bank balance update | Queue job for bank balance sync |
| E8 | Snapshot-based running balance | Re-calculate on every insert | PostgreSQL window function for running balance |

---

## 5. Architecture Decisions

### 5.1 Service Layer Pattern

**Decision:** Use a dedicated Service class for each entity type (SupplierTransactionService, EmployeeTransactionService), following the same pattern as the existing `CustomerPaymentService`.

**Rationale:**
- Laravel already has `CustomerPaymentService` as a working reference
- Each entity type has unique GL posting rules (different ledger natures)
- Service classes encapsulate the dual-write transaction logic
- Controllers stay thin (request validation → service call → response)

**Service Method Signatures:**

```php
// SupplierTransactionService
public function createPayment(array $data): SupplierPayment
public function reversePayment(int $id, int $reversedBy, string $reason): SupplierPayment
public function getSupplierDue(int $supplierId): float
public function getFilteredPayments(array $filters, ?int $branchId = null): LengthAwarePaginator
public function getStats(?int $branchId = null): array

// EmployeeTransactionService
public function createTransaction(array $data): EmployeeTransaction
public function reverseTransaction(int $id, int $reversedBy, string $reason): EmployeeTransaction
public function getEmployeeDue(int $employeeId): float
public function getFilteredTransactions(array $filters, ?int $branchId = null): LengthAwarePaginator
public function getStats(?int $branchId = null): array
```

### 5.2 Dual-Write Transaction Flow

**Decision:** Use DB transactions wrapping: (1) transaction record insert, (2) sub-ledger insert via SubLedgerService, (3) GL posting via JournalPostingService, (4) bank balance sync, (5) journal_entry_id link-back.

**Rationale:** This is the same pattern as the legacy system and the existing CustomerPaymentService. The DB transaction ensures atomicity — if GL posting fails, the entire operation rolls back.

```
DB::transaction(function () {
    1. Insert supplier_payments / employee_transactions
    2. SubLedgerService::postSupplierLedgerEntry() / postEmployeeLedgerEntry()
    3. JournalPostingService::createJournalEntry() with correct lines
    4. Update supplier_payments.journal_entry_id = $journalEntryId
    5. BankService::syncBalance() if bank mode
});
```

### 5.3 Reusable Base Pattern

**Decision:** Create an abstract `EntityTransactionService` base class with shared logic, and concrete implementations for each entity type.

```
EntityTransactionService (abstract)
├── createTransaction()     — shared flow
├── reverseTransaction()    — shared flow
├── getFilteredRecords()    — shared query builder
├── getStats()              — shared stats pattern
├── abstract getDebitCreditSides() — entity-specific
├── abstract getJournalLines()     — entity-specific
├── abstract getDocumentPrefix()   — entity-specific
├── abstract getTransactionTypes() — entity-specific
│
├── SupplierTransactionService (extends EntityTransactionService)
├── EmployeeTransactionService (extends EntityTransactionService)
└── (CustomerPaymentService already exists — can be refactored later)
```

### 5.4 View Structure

**Decision:** Follow the existing Laravel Blade pattern with a consistent layout per entity type:

```
resources/views/admin/supplier-transactions/
├── index.blade.php        — List with filters + stats
├── create.blade.php       — Create form with GL preview
├── show.blade.php         — Details + sub-ledger + GL journal
├── slip.blade.php         — Printable voucher slip
└── audit.blade.php        — Audit log viewer

resources/views/admin/employee-transactions/
├── index.blade.php
├── create.blade.php
├── show.blade.php
├── slip.blade.php
└── audit.blade.php
```

### 5.5 Route Structure

**Decision:** Follow the existing resource route pattern with additional custom routes:

```php
// Supplier Transactions
Route::prefix('admin/supplier-transactions')->name('admin.supplier-transactions.')->group(function () {
    Route::get('/', [SupplierTransactionController::class, 'index'])->name('index');
    Route::get('create', [SupplierTransactionController::class, 'create'])->name('create');
    Route::post('/', [SupplierTransactionController::class, 'store'])->name('store');
    Route::get('{id}', [SupplierTransactionController::class, 'show'])->name('show');
    Route::post('{id}/reverse', [SupplierTransactionController::class, 'reverse'])->name('reverse');
    Route::get('{id}/slip', [SupplierTransactionController::class, 'slip'])->name('slip');
    Route::get('audit', [SupplierTransactionController::class, 'audit'])->name('audit');
    Route::post('get-due', [SupplierTransactionController::class, 'getDue'])->name('get-due');
    Route::get('search', [SupplierTransactionController::class, 'searchSupplier'])->name('search');
});

// Employee Transactions
Route::prefix('admin/employee-transactions')->name('admin.employee-transactions.')->group(function () {
    Route::get('/', [EmployeeTransactionController::class, 'index'])->name('index');
    Route::get('create', [EmployeeTransactionController::class, 'create'])->name('create');
    Route::post('/', [EmployeeTransactionController::class, 'store'])->name('store');
    Route::get('{id}', [EmployeeTransactionController::class, 'show'])->name('show');
    Route::post('{id}/reverse', [EmployeeTransactionController::class, 'reverse'])->name('reverse');
    Route::get('{id}/slip', [EmployeeTransactionController::class, 'slip'])->name('slip');
    Route::get('audit', [EmployeeTransactionController::class, 'audit'])->name('audit');
    Route::post('get-due', [EmployeeTransactionController::class, 'getDue'])->name('get-due');
});
```

---

## 6. Phase 1: Supplier Transaction Module

### 6.1 Overview

Implement the complete Supplier Transaction workflow. This is the highest-priority gap because:
- Supplier payments are a daily operation (paying suppliers for goods received)
- The `supplier_payments` table already exists in PostgreSQL
- The `supplier_ledger` model and `SubLedgerService` already exist
- The `JournalPostingService` already has `postSupplierTransactionJournal()` method
- The `supplier_payments` table already has `journal_entry_id` column

### 6.2 Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `app/Models/SupplierPayment.php` | Eloquent model for `supplier_payments` table |
| 2 | `app/Services/Accounting/SupplierTransactionService.php` | Business logic service |
| 3 | `app/Http/Controllers/Admin/SupplierTransactionController.php` | Controller |
| 4 | `app/Http/Requests/StoreSupplierTransactionRequest.php` | Form Request validation |
| 5 | `app/Policies/SupplierTransactionPolicy.php` | Authorization policy |
| 6 | `resources/views/admin/supplier-transactions/index.blade.php` | List view |
| 7 | `resources/views/admin/supplier-transactions/create.blade.php` | Create form |
| 8 | `resources/views/admin/supplier-transactions/show.blade.php` | Detail view |
| 9 | `resources/views/admin/supplier-transactions/slip.blade.php` | Printable slip |
| 10 | `resources/views/admin/supplier-transactions/audit.blade.php` | Audit log view |
| 11 | `routes/web.php` | Add route group |

### 6.3 SupplierPayment Model

```php
// app/Models/SupplierPayment.php
class SupplierPayment extends Model
{
    protected $table = 'supplier_payments';

    protected $fillable = [
        'payment_code', 'payment_date', 'supplier_id', 'branch_id',
        'bank_id', 'payment_mode', 'amount', 'discount_amount',
        'collected_by', 'journal_entry_id', 'transaction_type',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    // Relationships
    public function supplier()      → belongsTo(Supplier::class)
    public function branch()        → belongsTo(Branch::class)
    public function bank()          → belongsTo(Bank::class)
    public function collectedBy()   → belongsTo(Employee::class, 'collected_by')
    public function journalEntry()  → belongsTo(JournalEntry::class)
    public function createdBy()     → belongsTo(User::class, 'created_by')

    // Scopes
    public function scopeNotReversed($query)  → where('is_reversed', false)
    public function scopeByBranch($query, $branchId) → where('branch_id', $branchId)
}
```

### 6.4 SupplierTransactionService — Business Logic

```php
// app/Services/Accounting/SupplierTransactionService.php

class SupplierTransactionService
{
    public function __construct(
        private JournalPostingService $journalService,
        private SubLedgerService $subLedgerService,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * Create a supplier payment transaction.
     *
     * Transaction types: 'payment', 'advance', 'receive'
     *
     * Flow:
     * 1. Validate supplier is active
     * 2. Generate payment code (SP-YYYY-NNNNN)
     * 3. Insert supplier_payments record
     * 4. Insert supplier_ledger entry (via SubLedgerService)
     * 5. Post GL journal entry (via JournalPostingService)
     * 6. Link journal_entry_id back to supplier_payments
     * 7. Sync bank balance if bank mode
     *
     * @param array $data {
     *     supplier_id: int,
     *     branch_id: int,
     *     payment_date: string (Y-m-d),
     *     transaction_type: string ('payment'|'advance'|'receive'),
     *     payment_mode: string ('cash'|'bank'|...),
     *     bank_id: int|null,
     *     amount: float,
     *     discount_amount: float,
     *     collected_by: int|null,
     *     notes: string,
     *     created_by: int,
     * }
     * @return SupplierPayment
     */
    public function createPayment(array $data): SupplierPayment
    {
        return DB::transaction(function () use ($data) {
            // 1. Validate supplier
            $supplier = Supplier::findOrFail($data['supplier_id']);
            if (!$supplier->is_active) {
                throw new \RuntimeException('Supplier is inactive.');
            }

            // 2. Validate bank if bank mode
            $paymentMode = $data['payment_mode'] ?? 'cash';
            $bankId = $data['bank_id'] ?? null;
            if ($paymentMode === 'bank' && !$bankId) {
                throw new \RuntimeException('Select a bank account for bank mode.');
            }

            // 3. Generate payment code
            $paymentCode = $this->sequenceService->nextCode(
                docType: 'supplier_payment',
                prefix: 'SP',
                datePart: now()->format('Y'),
                padLength: 5,
                periodKey: now()->format('Y'),
            );

            // 4. Determine debit/credit sides for supplier_ledger
            $sides = $this->getSupplierLedgerSides(
                $data['transaction_type'],
                (float) $data['amount']
            );

            // 5. Insert supplier_payments
            $payment = SupplierPayment::create([
                'payment_code'    => $paymentCode,
                'payment_date'    => $data['payment_date'] ?? now()->format('Y-m-d'),
                'supplier_id'     => $data['supplier_id'],
                'branch_id'       => $data['branch_id'],
                'bank_id'         => $bankId,
                'payment_mode'    => $paymentMode,
                'transaction_type'=> $data['transaction_type'],
                'amount'          => $data['amount'],
                'discount_amount' => $data['discount_amount'] ?? 0,
                'collected_by'    => $data['collected_by'] ?? null,
                'notes'           => $data['notes'] ?? '',
                'created_by'      => $data['created_by'] ?? null,
                'is_reversed'     => false,
            ]);

            // 6. Insert supplier_ledger entry
            $this->subLedgerService->postSupplierLedgerEntry([
                'supplier_id'     => $data['supplier_id'],
                'branch_id'       => $data['branch_id'],
                'transaction_date'=> $payment->payment_date->format('Y-m-d'),
                'transaction_type'=> $data['transaction_type'],
                'reference_type'  => 'supplier_payment',
                'reference_id'    => $payment->id,
                'debit'           => $sides['debit'],
                'credit'          => $sides['credit'],
                'description'     => $data['notes'] ?? ucfirst($data['transaction_type']) . ' payment',
                'created_by'      => $data['created_by'] ?? null,
            ]);

            // 7. Post GL journal entry
            $journalEntryId = $this->postSupplierTransactionJournal($payment, $data);
            $payment->update(['journal_entry_id' => $journalEntryId]);

            // 8. Sync bank balance
            if ($paymentMode === 'bank' && $bankId) {
                $this->syncBankBalance($bankId, (float) $data['amount'], $data['transaction_type']);
            }

            return $payment->fresh();
        });
    }

    /**
     * Reverse a supplier payment.
     *
     * Flow:
     * 1. Validate payment exists, not already reversed
     * 2. Reverse the linked journal entry
     * 3. Reverse the supplier_ledger entry
     * 4. Mark payment as reversed
     * 5. Undo bank balance sync
     */
    public function reversePayment(int $paymentId, int $reversedBy, string $reason): SupplierPayment
    {
        return DB::transaction(function () use ($paymentId, $reversedBy, $reason) {
            $payment = SupplierPayment::findOrFail($paymentId);

            if ($payment->is_reversed) {
                throw new \RuntimeException('Payment already reversed.');
            }

            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse the GL journal entry
            if ($payment->journal_entry_id) {
                $this->journalService->reverseJournalEntry(
                    $payment->journal_entry_id,
                    $reversedBy,
                    "Supplier payment reversal: {$payment->payment_code} — {$reason}"
                );
            }

            // 2. Reverse the supplier_ledger entry
            $ledgerEntry = SupplierLedger::where('reference_type', 'supplier_payment')
                ->where('reference_id', $paymentId)
                ->where('is_reversed', false)
                ->first();

            if ($ledgerEntry) {
                $this->subLedgerService->reverseSupplierLedgerEntry(
                    $ledgerEntry->id,
                    $reversedBy,
                    $reason
                );
            }

            // 3. Mark payment as reversed
            $payment->update([
                'is_reversed'    => true,
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => $reason,
            ]);

            // 4. Undo bank balance sync
            if ($payment->payment_mode === 'bank' && $payment->bank_id) {
                $this->syncBankBalance(
                    $payment->bank_id,
                    (float) $payment->amount,
                    $payment->transaction_type,
                    undo: true
                );
            }

            return $payment->fresh();
        });
    }

    /**
     * Get supplier's current AP balance (what we owe).
     */
    public function getSupplierDue(int $supplierId): float
    {
        return SupplierLedger::getBalance($supplierId);
    }

    /**
     * Debit/credit sides for supplier_ledger.
     * Positive running_balance = payable to supplier.
     * - payment/advance: debit (reduce payable)
     * - receive: credit (increase payable)
     */
    private function getSupplierLedgerSides(string $type, float $amount): array
    {
        if (in_array($type, ['payment', 'advance'], true)) {
            return ['debit' => $amount, 'credit' => 0.0];
        }
        return ['debit' => 0.0, 'credit' => $amount];
    }

    /**
     * Post GL journal entry for supplier transaction.
     *
     * payment/advance: Dr AP, Cr Cash/Bank
     * receive:         Dr Inventory/Purchase, Cr AP
     */
    private function postSupplierTransactionJournal(SupplierPayment $payment, array $data): int
    {
        $amount = (float) $payment->amount;
        $apLedgerId = $this->journalService->lookupLedgerByNature('ap');
        $cashBankLedgerId = $this->journalService->lookupLedgerByNature('cash_bank');

        if ($payment->payment_mode === 'bank' && $payment->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $payment->bank_id)->first();
            if ($mapping) {
                $cashBankLedgerId = $mapping->ledger_id;
            }
        }

        $lines = [];

        if (in_array($payment->transaction_type, ['payment', 'advance'], true)) {
            // Dr AP (we owe less), Cr Cash/Bank (money out)
            $lines[] = ['ledger_id' => $apLedgerId, 'debit' => $amount, 'credit' => 0, 'entity_type' => 'supplier', 'entity_id' => $payment->supplier_id, 'memo' => "Supplier {$payment->transaction_type}: {$payment->payment_code}"];
            $lines[] = ['ledger_id' => $cashBankLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => "Cash/Bank out for: {$payment->payment_code}"];
        } else {
            // receive: Dr Inventory/Purchase, Cr AP (we owe more)
            $inventoryLedgerId = $this->journalService->lookupLedgerByNature('inventory');
            $lines[] = ['ledger_id' => $inventoryLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => "Supplier receive: {$payment->payment_code}"];
            $lines[] = ['ledger_id' => $apLedgerId, 'debit' => 0, 'credit' => $amount, 'entity_type' => 'supplier', 'entity_id' => $payment->supplier_id, 'memo' => "AP increase: {$payment->payment_code}"];
        }

        return $this->journalService->createJournalEntry([
            'entry_date'     => $payment->payment_date->format('Y-m-d'),
            'reference_type' => 'supplier_payment',
            'reference_id'   => $payment->id,
            'branch_id'      => $payment->branch_id,
            'description'    => "Supplier {$payment->transaction_type}: {$payment->payment_code}",
            'source'         => 'supplier_transaction',
            'created_by'     => $payment->created_by,
        ], $lines);
    }

    /**
     * Sync bank balance after a supplier transaction.
     */
    private function syncBankBalance(int $bankId, float $amount, string $transactionType, bool $undo = false): void
    {
        $bank = Bank::find($bankId);
        if (!$bank) return;

        // Supplier payment/advance = money going out (decrease bank balance)
        $decrease = in_array($transactionType, ['payment', 'advance'], true);
        if ($undo) $decrease = !$decrease;

        if ($decrease) {
            $bank->decrement('balance', $amount);
        } else {
            $bank->increment('balance', $amount);
        }
    }
}
```

### 6.5 SupplierTransactionController

```php
// app/Http/Controllers/Admin/SupplierTransactionController.php

class SupplierTransactionController extends Controller
{
    public function __construct(
        private SupplierTransactionService $service
    ) {}

    public function index(Request $request) { ... }
    public function create(Request $request) { ... }
    public function store(StoreSupplierTransactionRequest $request) { ... }
    public function show(int $id) { ... }
    public function reverse(Request $request, int $id) { ... }
    public function slip(int $id) { ... }
    public function audit() { ... }
    public function getDue(Request $request) { ... }
    public function searchSupplier(Request $request) { ... }
}
```

### 6.6 Supplier Payment Database Schema (Already Exists)

The `supplier_payments` table is already defined in `database/sql/06_payment_and_misc.sql`:

```sql
CREATE TABLE supplier_payments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_code varchar(30) NOT NULL,
    payment_date date NOT NULL,
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    payment_mode varchar(20) NOT NULL CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    collected_by integer REFERENCES employees(id),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT supplier_payments_code_unique UNIQUE (payment_code)
);
```

**Note:** The `supplier_payments` table does NOT have a `transaction_type` column. We need to add it via migration:

```php
// New migration needed
Schema::table('supplier_payments', function (Blueprint $table) {
    $table->string('transaction_type', 20)->default('payment')
        ->after('payment_mode')
        ->comment('payment, advance, receive');
});
```

Or we can use the existing `payment_mode = 'adjustment'` + `notes` field to distinguish between payment/advance/receive. However, the legacy system uses a separate `transaction_type` column, so adding it is the cleaner approach.

### 6.7 Verification Gate for Phase 1

| # | Test | Expected Result |
|---|---|---|
| 1 | Create a supplier payment (cash mode) | Payment record created, supplier_ledger entry created, GL journal entry created (Dr AP, Cr Cash), bank balance unchanged |
| 2 | Create a supplier payment (bank mode) | Same as above + bank balance decreased |
| 3 | Create a supplier advance | Same as payment — Dr AP, Cr Bank/Cash |
| 4 | Create a supplier receive | Dr Inventory, Cr AP — supplier_ledger credit increases |
| 5 | Reverse a supplier payment | GL journal reversed, supplier_ledger opposite entry, bank balance restored |
| 6 | View supplier payment details | Shows payment info + sub-ledger entries + GL journal lines |
| 7 | Print supplier payment slip | Clean printable slip with all transaction details |
| 8 | Filter supplier payments by date/type/mode | Correct filtered results |
| 9 | Check supplier due balance | Returns correct AP balance from supplier_ledger |
| 10 | Sub-ledger reconciliation | `SubLedgerService::reconcileAll()` shows AP sub-ledger matches GL control |

---

## 7. Phase 2: Employee Transaction Module

### 7.1 Overview

Implement the complete Employee Transaction workflow. The `employee_transactions` table already exists in PostgreSQL with the correct schema including the `transaction_type` CHECK constraint.

### 7.2 Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `app/Models/EmployeeTransaction.php` | Eloquent model for `employee_transactions` table |
| 2 | `app/Services/Accounting/EmployeeTransactionService.php` | Business logic service |
| 3 | `app/Http/Controllers/Admin/EmployeeTransactionController.php` | Controller |
| 4 | `app/Http/Requests/StoreEmployeeTransactionRequest.php` | Form Request validation |
| 5 | `app/Policies/EmployeeTransactionPolicy.php` | Authorization policy |
| 6 | `resources/views/admin/employee-transactions/index.blade.php` | List view |
| 7 | `resources/views/admin/employee-transactions/create.blade.php` | Create form |
| 8 | `resources/views/admin/employee-transactions/show.blade.php` | Detail view |
| 9 | `resources/views/admin/employee-transactions/slip.blade.php` | Printable slip |
| 10 | `resources/views/admin/employee-transactions/audit.blade.php` | Audit log view |
| 11 | `routes/web.php` | Add route group |

### 7.3 EmployeeTransaction Model

```php
// app/Models/EmployeeTransaction.php
class EmployeeTransaction extends Model
{
    protected $table = 'employee_transactions';

    public const TRANSACTION_TYPES = ['advance', 'loan', 'repayment', 'salary', 'deduction', 'adjustment'];

    protected $fillable = [
        'transaction_code', 'transaction_date', 'employee_id', 'branch_id',
        'transaction_type', 'amount', 'description',
        'journal_entry_id', 'is_reversed', 'reversed_at', 'reversed_by',
        'reverse_reason', 'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    // Relationships
    public function employee()     → belongsTo(Employee::class)
    public function branch()       → belongsTo(Branch::class)
    public function journalEntry() → belongsTo(JournalEntry::class)
    public function createdBy()    → belongsTo(User::class, 'created_by')

    // Scopes
    public function scopeNotReversed($query) → where('is_reversed', false)
    public function scopeByBranch($query, $bid) → where('branch_id', $bid)
}
```

### 7.4 EmployeeTransactionService — Business Logic

The key differences from Supplier:

**Transaction Types:** `advance`, `loan`, `repayment`, `salary`, `deduction`, `adjustment`

**Debit/Credit Rules (employee_ledger):**
- Positive running_balance = employee owes company (advance/loan taken)
- `advance`: debit (employee owes more) → Dr Employee Payable, Cr Bank/Cash
- `loan`: debit (employee owes more) → Dr Employee Payable, Cr Bank/Cash
- `salary`: debit (employee owes more — salary paid as advance) → Dr Salary Expense, Cr Bank/Cash
- `repayment`: credit (employee owes less) → Dr Bank/Cash, Cr Employee Payable
- `deduction`: credit (employee owes less) → Dr Salary Payable, Cr Employee Payable
- `adjustment`: debit or credit depending on context

**GL Posting Rules:**

```
advance/loan:
  Dr Employee Payable (employee_payable)     amount
     Cr Cash/Bank (cash_bank)                 amount

salary:
  Dr Salary Expense (salary_expense)          amount
     Cr Cash/Bank (cash_bank)                 amount

repayment:
  Dr Cash/Bank (cash_bank)                    amount
     Cr Employee Payable (employee_payable)    amount

deduction:
  Dr Salary Payable (salary_expense)          amount
     Cr Employee Payable (employee_payable)    amount

adjustment:
  Dr or Cr depending on context
```

**Additional Considerations:**
- The `employee_transactions` table has a `collected_by` field in the legacy system but NOT in the PostgreSQL schema. We should add it if needed.
- The `employee_ledger` has a CHECK constraint on `transaction_type` that only allows: `advance`, `loan`, `repayment`, `salary`, `deduction`, `adjustment`. This is enforced at the database level.
- The `employee_payable` ledger nature must exist in the Chart of Accounts before posting.

### 7.5 Verification Gate for Phase 2

| # | Test | Expected Result |
|---|---|---|
| 1 | Create employee advance (cash) | Transaction created, employee_ledger debit entry, GL posted (Dr Employee Payable, Cr Cash) |
| 2 | Create employee advance (bank) | Same + bank balance decreased |
| 3 | Create employee loan | Same as advance |
| 4 | Create employee salary | GL posted (Dr Salary Expense, Cr Cash/Bank) |
| 5 | Create employee repayment | GL posted (Dr Cash/Bank, Cr Employee Payable) |
| 6 | Create employee deduction | GL posted (Dr Salary Expense, Cr Employee Payable) |
| 7 | Reverse an employee transaction | GL reversed, employee_ledger opposite entry, bank balance restored |
| 8 | View employee transaction details | Shows transaction + sub-ledger + GL journal |
| 9 | Employee due balance | Returns correct employee payable balance |
| 10 | Sub-ledger reconciliation | Employee Payable sub-ledger matches GL control |

---

## 8. Phase 3: Customer Transaction Enhancement

### 8.1 Overview

The Customer Payment module is already fully implemented in Laravel. However, there are some enhancements needed to match the legacy system's full functionality:

### 8.2 Gaps in Current Customer Payment Implementation

| # | Gap | Legacy Feature | Enhancement |
|---|---|---|---|
| 1 | No `CustomerTransactionController` separate from `CustomerPaymentController` | Legacy has `CustomerTransactionController` for AR payments | Consider renaming or adding a unified controller |
| 2 | No GL preview on create page | Legacy shows live Dr/Cr preview before save | Add GL preview labels to the create form |
| 3 | No transaction slip print | Legacy has `slip.php` view | Add `slip.blade.php` view |
| 4 | No audit page | Legacy has `audit.php` view | Add `audit.blade.php` view |
| 5 | No `collected_by` field | Legacy tracks who collected the cash | Add `collected_by` to payments table |
| 6 | No bank balance sync in CustomerPaymentService | Legacy has `syncBankBookBalance()` | Add bank balance sync to service |

### 8.3 Files to Modify/Create

| # | File | Change |
|---|---|---|
| 1 | `app/Services/Sales/CustomerPaymentService.php` | Add bank balance sync |
| 2 | `resources/views/admin/customer-payments/create.blade.php` | Add GL preview section |
| 3 | `resources/views/admin/customer-payments/slip.blade.php` | Create new printable slip |
| 4 | `resources/views/admin/customer-payments/audit.blade.php` | Create new audit log view |
| 5 | `app/Http/Controllers/Admin/CustomerPaymentController.php` | Add `slip()` and `audit()` methods |

### 8.4 Verification Gate for Phase 3

| # | Test | Expected Result |
|---|---|---|
| 1 | Create customer payment with GL preview | Preview shows correct Dr/Cr lines before save |
| 2 | Bank balance syncs on bank payment | Bank balance decreased |
| 3 | Print customer payment slip | Clean printable slip |
| 4 | View customer payment audit logs | Recent audit logs displayed |

---

## 9. Phase 4: Money Transfer Module

### 9.1 Overview

Money Transfer handles transfers between cash and bank accounts. The `money_transfers` table already exists in PostgreSQL.

### 9.2 Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `app/Models/MoneyTransfer.php` | Eloquent model |
| 2 | `app/Services/Accounting/MoneyTransferService.php` | Business logic |
| 3 | `app/Http/Controllers/Admin/MoneyTransferController.php` | Controller |
| 4 | `app/Http/Requests/StoreMoneyTransferRequest.php` | Validation |
| 5 | `resources/views/admin/money-transfers/{index,create,show,slip,audit}.blade.php` | Views |
| 6 | `routes/web.php` | Routes |

### 9.3 GL Posting Rules

```
Cash to Bank:
  Dr Bank Ledger (via bank_ledger_mappings)     amount
     Cr Cash/Bank (cash_bank)                    amount

Bank to Cash:
  Dr Cash/Bank (cash_bank)                       amount
     Cr Bank Ledger (via bank_ledger_mappings)    amount

Cash to Cash:
  NO GL (just a record — same ledger)

Bank to Bank:
  Dr Destination Bank Ledger                     amount
     Cr Source Bank Ledger                        amount
```

Cross-branch transfers also generate intercompany journal entries.

### 9.4 Verification Gate for Phase 4

| # | Test | Expected Result |
|---|---|---|
| 1 | Cash to bank transfer | Bank balance increased, cash ledger updated, GL posted |
| 2 | Bank to cash transfer | Cash ledger updated, bank balance decreased, GL posted |
| 3 | Bank to bank transfer | Source bank decreased, destination bank increased, GL posted |
| 4 | Cash to cash transfer | Record created, NO GL posting |
| 5 | Cross-branch transfer | Intercompany journal entries created |
| 6 | Reverse a transfer | All balances and GL entries reversed |

---

## 10. Phase 5: Other Income & Expense Modules

### 10.1 Overview

Other Income and Other Expense handle non-operational income (interest, rent received, etc.) and non-operational expenses (bank charges, rent paid, etc.). Both tables already exist in PostgreSQL.

### 10.2 Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `app/Models/OtherIncome.php` | Eloquent model |
| 2 | `app/Models/OtherExpense.php` | Eloquent model |
| 3 | `app/Services/Accounting/OtherIncomeService.php` | Business logic |
| 4 | `app/Services/Accounting/OtherExpenseService.php` | Business logic |
| 5 | `app/Http/Controllers/Admin/OtherIncomeController.php` | Controller |
| 6 | `app/Http/Controllers/Admin/OtherExpenseController.php` | Controller |
| 7 | `resources/views/admin/other-incomes/{index,create,show,slip,audit}.blade.php` | Views |
| 8 | `resources/views/admin/other-expenses/{index,create,show,slip,audit}.blade.php` | Views |
| 9 | `routes/web.php` | Routes |

### 10.3 GL Posting Rules

```
Other Income:
  Dr Cash/Bank (cash_bank)              amount
     Cr Other Income (other_income)      amount

Other Expense:
  Dr Operating Expense (operating_expense)  amount
     Cr Cash/Bank (cash_bank)               amount
```

### 10.4 Verification Gate for Phase 5

| # | Test | Expected Result |
|---|---|---|
| 1 | Create other income (cash) | GL posted (Dr Cash, Cr Other Income) |
| 2 | Create other income (bank) | GL posted (Dr Bank, Cr Other Income) + bank balance updated |
| 3 | Create other expense (cash) | GL posted (Dr Operating Expense, Cr Cash) |
| 4 | Create other expense (bank) | GL posted (Dr Operating Expense, Cr Bank) + bank balance updated |
| 5 | Reverse other income | GL reversed, bank balance restored |
| 6 | Reverse other expense | GL reversed, bank balance restored |

---

## 11. Phase 6: Manual Journal Module

### 11.1 Overview

Manual Journals allow accountants to create custom journal entries with user-defined lines. The `manual_journals` table already exists in PostgreSQL.

### 11.2 Files to Create

| # | File | Purpose |
|---|---|---|
| 1 | `app/Services/Accounting/ManualJournalService.php` | Business logic |
| 2 | `app/Http/Controllers/Admin/ManualJournalController.php` | Controller |
| 3 | `app/Http/Requests/StoreManualJournalRequest.php` | Validation |
| 4 | `resources/views/admin/manual-journals/{index,create,show,audit}.blade.php` | Views |
| 5 | `routes/web.php` | Routes |

### 11.3 Key Features

- User selects specific ledgers (not automatic nature lookup)
- Must have Dr = Cr (enforced by DB trigger + service validation)
- Supports draft → posted → reversed lifecycle
- Period validation on posting
- No entity_type/entity_id on journal lines (accountant's choice)

### 11.4 Verification Gate for Phase 6

| # | Test | Expected Result |
|---|---|---|
| 1 | Create draft manual journal | Saved as draft, no GL posting |
| 2 | Post a manual journal | GL posted, Dr = Cr enforced |
| 3 | Post unbalanced journal | Rejected with error |
| 4 | Post to closed period | Rejected unless admin override |
| 5 | Reverse a manual journal | GL reversed, original marked |

---

## 12. Phase 7: Accounting Dashboard & Navigation

### 12.1 Overview

Create the Accounting Dashboard hub page and sidebar navigation menu. This is the entry point for the entire accounting module.

### 12.2 Files to Create/Modify

| # | File | Purpose |
|---|---|---|
| 1 | `app/Http/Controllers/Admin/AccountingDashboardController.php` | Dashboard controller |
| 2 | `resources/views/admin/accounting/index.blade.php` | Dashboard view |
| 3 | `resources/views/partials/accounting_sidebar.blade.php` | Sidebar menu partial |
| 4 | `routes/web.php` | Add accounting routes |

### 12.3 Dashboard Features

The accounting dashboard should show:
- **Quick Stats Cards:** Total AR, Total AP, Employee Payable, Cash/Bank balance
- **Reconciliation Status:** Traffic-light indicators for AR, AP, Employee sub-ledger vs GL
- **Recent Transactions:** Last 10 transactions across all modules
- **Period Status:** Open/closed period for each branch
- **Quick Actions:** Create payment, create transfer, create manual journal
- **Module Links:** Cards/links to each sub-module

### 12.4 Sidebar Navigation Structure

```
📊 Accounting
├── 🏠 Dashboard
├── 📋 Chart of Accounts
├── 🏦 Bank Accounts
├── ─────────────
├── 👤 Customer Transactions
│   ├── List Payments
│   ├── New Payment
│   └── Audit Log
├── 🏢 Supplier Transactions
│   ├── List Payments
│   ├── New Payment
│   └── Audit Log
├── 👷 Employee Transactions
│   ├── List Transactions
│   ├── New Transaction
│   └── Audit Log
├── ─────────────
├── 💰 Money Transfer
├── 📈 Other Income
├── 📉 Other Expense
├── 📝 Manual Journal
├── ─────────────
├── ✅ Reconciliation
├── 📅 Period Close
├── 📊 Reports
│   ├── Trial Balance
│   ├── General Ledger
│   ├── P&L
│   ├── Balance Sheet
│   ├── Cash Flow
│   ├── AR Aging
│   └── AP Aging
```

### 12.5 Verification Gate for Phase 7

| # | Test | Expected Result |
|---|---|---|
| 1 | Access accounting dashboard | Dashboard loads with stats and links |
| 2 | Navigate to each sub-module | All links work correctly |
| 3 | Sidebar shows on all accounting pages | Consistent navigation |
| 4 | Mobile responsive sidebar | Sidebar collapses on mobile |

---

## 13. Phase 8: Reconciliation & Reporting Enhancements

### 13.1 Overview

Enhance the reconciliation hub and add entity-level reconciliation reports.

### 13.2 Key Enhancements

| # | Enhancement | Description |
|---|---|---|
| 1 | Supplier sub-ledger reconciliation | Match supplier_ledger total to AP GL control |
| 2 | Employee sub-ledger reconciliation | Match employee_ledger total to Employee Payable GL control |
| 3 | Entity-level aging reports | AR aging per customer, AP aging per supplier |
| 4 | Entity statement reports | Customer statement, Supplier statement, Employee ledger |
| 5 | Bank book reconciliation | Match bank_ledger to GL bank control |
| 6 | Daily cash book per branch | Cash inflow/outflow summary |
| 7 | Cross-branch reconciliation | Intercompany matching |

### 13.3 Verification Gate for Phase 8

| # | Test | Expected Result |
|---|---|---|
| 1 | Full reconciliation | All 3 sub-ledgers match GL control accounts |
| 2 | Customer aging report | Correct AR aging buckets |
| 3 | Supplier aging report | Correct AP aging buckets |
| 4 | Employee statement | Correct employee payable history |
| 5 | Bank reconciliation | Bank ledger matches GL bank control |

---

## 14. Phase 9: Advanced Features & Polish

### 14.1 Overview

Advanced features that go beyond the legacy system.

### 14.2 Features

| # | Feature | Description |
|---|---|---|
| 1 | **Bulk payment processing** | Pay multiple suppliers at once |
| 2 | **Payment allocation** | Allocate supplier payments to specific GRNs |
| 3 | **Salary batch processing** | Process salary for all employees at once |
| 4 | **Email notifications** | Send payment confirmation to suppliers |
| 5 | **Telegram alerts** | Accounting event notifications (like legacy) |
| 6 | **Export to Excel** | Export transaction lists, aging reports |
| 7 | **Multi-currency support** | Handle foreign currency transactions |
| 8 | **Budget tracking** | Compare actual vs budget per ledger |
| 9 | **Tax reporting** | VAT/GST reports from journal lines |
| 10 | **Audit trail improvement** | Comprehensive audit trail for all accounting operations |

---

## 15. Verification Gates

### 15.1 Global Verification (After All Phases)

| # | Criterion | Test |
|---|---|---|
| 1 | Every financial event posts a balanced journal entry | `JournalPostingService::verifyAllEntriesBalanced()` returns 0 unbalanced |
| 2 | Sub-ledgers reconcile to GL control accounts | `SubLedgerService::reconcileAll()` returns `all_match: true` |
| 3 | Trial Balance is balanced | Total debits = total credits |
| 4 | No orphaned journal entries | Every journal entry has a reference_type + reference_id |
| 5 | No orphaned sub-ledger entries | Every sub-ledger entry links to a source transaction |
| 6 | Bank balances match | Bank book balance matches GL bank control |
| 7 | Reversals are complete | Reversed transactions have both GL reversal + sub-ledger reversal |
| 8 | Period close blocks new postings | Cannot post to closed periods |
| 9 | RBAC works | Non-admin users can only see their branch data |
| 10 | All CRUD operations logged | Audit trail covers create/reverse for all money modules |

---

## Appendix A: Legacy vs Laravel Schema Comparison

### customer_payments

| Column | Legacy (MySQL) | Laravel (PostgreSQL) | Status |
|---|---|---|---|
| `id` | `bigint AUTO_INCREMENT` | `integer GENERATED ALWAYS AS IDENTITY` | ✅ |
| `payment_code` | `varchar(30)` | `varchar(30)` | ✅ |
| `payment_date` | `date` | `date` | ✅ |
| `customer_id` | `int` | `integer NOT NULL` | ✅ |
| `branch_id` | `int` | `integer NOT NULL REFERENCES branches(id)` | ✅ (improved) |
| `bank_id` | `int` | `integer REFERENCES banks(id)` | ✅ (improved) |
| `payment_mode` | `enum('cash','bank',...)` | `varchar(20) CHECK (...)` | ✅ |
| `amount` | `decimal(14,2)` | `numeric(14,2)` | ✅ |
| `discount_amount` | `decimal(14,2)` | `numeric(14,2)` | ✅ |
| `journal_entry_id` | `int` | `integer REFERENCES journal_entries(id)` | ✅ (improved) |
| `intercompany_journal_entry_id` | ❌ Not in legacy | `integer REFERENCES journal_entries(id)` | ✅ (new) |
| `is_reversed` | `tinyint(1)` | `boolean NOT NULL DEFAULT false` | ✅ |
| `reversed_at` | `datetime` | `timestamp(0)` | ✅ |
| `reversed_by` | `int` | `integer` | ✅ |
| `reverse_reason` | `text` | `text` | ✅ |
| `notes` | `text` | `text` | ✅ |
| `created_by` | `int` | `integer` | ✅ |
| `transaction_type` | ❌ Not in legacy MySQL | ❌ Not in PostgreSQL schema | ⚠️ Need to add |
| `reference_no` | ❌ Not in legacy | ❌ Not in schema | ⚠️ Need to add |
| `collected_by` | ❌ Not in legacy | ❌ Not in schema | ⚠️ Consider adding |

### supplier_payments

| Column | Legacy (MySQL) | Laravel (PostgreSQL) | Status |
|---|---|---|---|
| `id` | `bigint AUTO_INCREMENT` | `integer GENERATED ALWAYS AS IDENTITY` | ✅ |
| `payment_code` | `varchar(30)` | `varchar(30)` | ✅ |
| `payment_date` | `date` | `date` | ✅ |
| `supplier_id` | `int` | `integer NOT NULL REFERENCES suppliers(id)` | ✅ (improved) |
| `branch_id` | `int` | `integer NOT NULL REFERENCES branches(id)` | ✅ (improved) |
| `bank_id` | `int` | `integer REFERENCES banks(id)` | ✅ |
| `payment_mode` | `enum` | `varchar(20) CHECK (...)` | ✅ |
| `amount` | `decimal(14,2)` | `numeric(14,2)` | ✅ |
| `discount_amount` | `decimal(14,2)` | `numeric(14,2)` | ✅ |
| `collected_by` | `int` | `integer REFERENCES employees(id)` | ✅ (improved) |
| `journal_entry_id` | `int` | `integer REFERENCES journal_entries(id)` | ✅ |
| `is_reversed` | `tinyint(1)` | `boolean NOT NULL DEFAULT false` | ✅ |
| `transaction_type` | ❌ Not in legacy MySQL | ❌ Not in PostgreSQL schema | ⚠️ Need to add |
| `notes` | `text` | `text` | ✅ |

### employee_transactions

| Column | Legacy (MySQL) | Laravel (PostgreSQL) | Status |
|---|---|---|---|
| `id` | `bigint AUTO_INCREMENT` | `integer GENERATED ALWAYS AS IDENTITY` | ✅ |
| `transaction_code` | `varchar(30)` | `varchar(30)` | ✅ |
| `transaction_date` | `date` | `date` | ✅ |
| `employee_id` | `int` | `integer NOT NULL REFERENCES employees(id)` | ✅ (improved) |
| `branch_id` | `int` | `integer NOT NULL REFERENCES branches(id)` | ✅ (improved) |
| `transaction_type` | `enum('advance','loan',...)` | `varchar(20) CHECK (...)` | ✅ |
| `amount` | `decimal(14,2)` | `numeric(14,2)` | ✅ |
| `description` | `text` | `text` | ✅ |
| `journal_entry_id` | `int` | `integer REFERENCES journal_entries(id)` | ✅ |
| `is_reversed` | `tinyint(1)` | `boolean NOT NULL DEFAULT false` | ✅ |
| `collected_by` | `int` | ❌ Not in PostgreSQL schema | ⚠️ Consider adding |
| `payment_mode` | `varchar(20)` | ❌ Not in PostgreSQL schema | ⚠️ Consider adding |
| `bank_id` | `int` | ❌ Not in PostgreSQL schema | ⚠️ Consider adding |

**Important:** The `employee_transactions` table in PostgreSQL is missing `payment_mode`, `bank_id`, and `collected_by` columns that exist in the legacy system. These need to be added via migration:

```php
Schema::table('employee_transactions', function (Blueprint $table) {
    $table->string('payment_mode', 20)->default('cash')
        ->after('amount')
        ->comment('cash, bank, mobile_banking, cheque, adjustment');
    $table->integer('bank_id')->nullable()
        ->after('payment_mode')
        ->comment('FK to banks table — required if payment_mode = bank');
    $table->integer('collected_by')->nullable()
        ->after('bank_id')
        ->comment('FK to employees table — who collected the cash');
});
```

---

## Appendix B: Debit/Credit Rules by Entity Type

### Customer Ledger (AR Sub-Ledger)

Running balance formula: `balance = prev + debit - credit`
Positive balance = customer owes us (AR)

| Transaction Type | Debit | Credit | Effect on Balance |
|---|---|---|---|
| `receive` (payment received) | 0 | amount | Balance decreases (customer owes less) |
| `payment` (refund) | amount | 0 | Balance increases (customer owes more) |
| `discount` | 0 | amount | Balance decreases (discount allowed) |
| `write_off` | 0 | amount | Balance decreases (bad debt written off) |

### Supplier Ledger (AP Sub-Ledger)

Running balance formula: `balance = prev + credit - debit`
Positive balance = we owe supplier (AP)

| Transaction Type | Debit | Credit | Effect on Balance |
|---|---|---|---|
| `payment` (we pay supplier) | amount | 0 | Balance decreases (we owe less) |
| `advance` (advance payment) | amount | 0 | Balance decreases (we owe less) |
| `receive` (goods received on credit) | 0 | amount | Balance increases (we owe more) |

### Employee Ledger (Employee Payable Sub-Ledger)

Running balance formula: `balance = prev + credit - debit`
Positive balance = we owe employee (payable)

| Transaction Type | Debit | Credit | Effect on Balance |
|---|---|---|---|
| `advance` (cash given to employee) | amount | 0 | Balance decreases (we owe less) |
| `loan` (loan disbursed) | amount | 0 | Balance decreases (we owe less) |
| `salary` (salary paid) | amount | 0 | Balance decreases (we owe less) |
| `repayment` (employee repays) | 0 | amount | Balance increases (we owe more) |
| `deduction` (deducted from salary) | 0 | amount | Balance increases (we owe more) |
| `adjustment` | varies | varies | Depends on context |

**Note on Employee Ledger Balance Direction:**

The legacy system uses a different convention for employee ledger:
- Legacy: `balance = prev + debit - credit` (positive = employee owes company)
- Laravel SubLedgerService: `balance = prev + credit - debit` (positive = we owe employee)

The Laravel convention is **correct** from an accounting perspective — Employee Payable is a liability, and liabilities have a normal credit balance. The legacy system's convention was inverted. The Laravel implementation should use the `SubLedgerService` convention (credit - debit = positive = liability).

However, this means the GL posting for employee transactions must be adjusted accordingly:
- When we give an advance to an employee, we DECREASE the Employee Payable (debit the payable account)
- When an employee repays, we INCREASE the Employee Payable (credit the payable account)

This is the correct accounting treatment: Employee Payable is a liability, and giving an advance reduces the liability.

---

## Appendix C: GL Posting Rules Reference

### Customer Transaction GL Postings

| Transaction Type | Dr | Cr | Ledger Nature |
|---|---|---|---|
| `receive` | Cash/Bank (`cash_bank`) | AR (`ar`) | Asset → Asset |
| `payment` (refund) | AR (`ar`) | Cash/Bank (`cash_bank`) | Asset → Asset |
| `discount` | Sales Discount (`sales_discount`) | AR (`ar`) | Expense → Asset |
| `write_off` | Bad Debt Expense (`operating_expense`) | AR (`ar`) | Expense → Asset |

### Supplier Transaction GL Postings

| Transaction Type | Dr | Cr | Ledger Nature |
|---|---|---|---|
| `payment` | AP (`ap`) | Cash/Bank (`cash_bank`) | Liability → Asset |
| `advance` | AP (`ap`) | Cash/Bank (`cash_bank`) | Liability → Asset |
| `receive` | Inventory (`inventory`) | AP (`ap`) | Asset → Liability |

### Employee Transaction GL Postings

| Transaction Type | Dr | Cr | Ledger Nature |
|---|---|---|---|
| `advance` | Employee Payable (`employee_payable`) | Cash/Bank (`cash_bank`) | Liability → Asset |
| `loan` | Employee Payable (`employee_payable`) | Cash/Bank (`cash_bank`) | Liability → Asset |
| `salary` | Salary Expense (`salary_expense`) | Cash/Bank (`cash_bank`) | Expense → Asset |
| `repayment` | Cash/Bank (`cash_bank`) | Employee Payable (`employee_payable`) | Asset → Liability |
| `deduction` | Salary Expense (`salary_expense`) | Employee Payable (`employee_payable`) | Expense → Liability |
| `adjustment` | Varies | Varies | Context-dependent |

### Bank Mode Override

When `payment_mode = 'bank'` and the bank has a `bank_ledger_mappings` entry, the `cash_bank` nature ledger is replaced with the bank's mapped ledger. This applies to ALL transaction types.

### Intercompany Entries

When a bank belongs to a DIFFERENT branch than the transaction's branch, an additional intercompany journal entry is created:
- Dr Due-to-Branch (interbranch_payable) / Cr Due-from-Branch (interbranch_receivable)

This is already implemented in the `CustomerPaymentService` and should be replicated for supplier and employee transactions.

---

## Document History

| Date | Phase | Author | Notes |
|---|---|---|---|
| March 2026 | Initial | Analysis | Complete analysis of legacy vs Laravel, gap analysis, and phase-by-phase implementation plan |
