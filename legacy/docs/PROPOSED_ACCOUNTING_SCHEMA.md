# Proposed Accounting Schema for Professional Double-Entry System

## 1. Improved `ledgers` Table (Chart of Accounts)

```sql
ALTER TABLE `ledgers` ADD COLUMN `is_system` TINYINT(1) DEFAULT 0 COMMENT 'System generated, cannot be deleted';
ALTER TABLE `ledgers` ADD COLUMN `normal_balance` ENUM('debit','credit') DEFAULT 'debit';
ALTER TABLE `ledgers` ADD COLUMN `is_control_account` TINYINT(1) DEFAULT 0;
ALTER TABLE `ledgers` ADD COLUMN `control_account_type` VARCHAR(50) NULL COMMENT 'customer, supplier, employee, bank, etc.';
```

**Recommended Default Structure** (we can seed this):

- Assets
  - Current Assets
    - Cash & Bank
    - Accounts Receivable (Control)
    - Inventory
  - Fixed Assets
- Liabilities
  - Current Liabilities
    - Accounts Payable (Control)
    - Accrued Expenses
- Equity
- Income
- Cost of Goods Sold
- Expenses

## 2. New Core Accounting Tables

### `journal_entries`
```sql
CREATE TABLE `journal_entries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `entry_no` varchar(30) NOT NULL,
  `entry_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,        -- sales_invoice, purchase_receive, money_transfer, manual, etc.
  `reference_id` bigint(20) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `total_debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_posted` tinyint(1) DEFAULT 1,
  `is_reversed` tinyint(1) DEFAULT 0,
  `reversal_of_entry_id` bigint(20) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `posted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_no` (`entry_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `journal_lines`
```sql
CREATE TABLE `journal_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) NOT NULL,
  `ledger_id` int(11) NOT NULL,                    -- FK to ledgers table
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `entity_type` varchar(30) DEFAULT NULL,          -- customer, supplier, employee, bank, etc.
  `entity_id` bigint(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `journal_entry_id` (`journal_entry_id`),
  KEY `ledger_id` (`ledger_id`),
  CONSTRAINT `fk_journal_lines_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 3. Strategy for Existing Sub-Ledgers

**Recommended Approach:**

- Keep `customer_ledger`, `supplier_ledger`, `employee_ledger` for now (they are useful for operational reporting).
- Create a background/sync process or triggers so that when a journal line is posted with `entity_type = 'customer'`, it also creates/updates the corresponding sub-ledger row.
- Over time, we can make sub-ledgers **read-only views** generated from journal_lines.

## 4. Integration Strategy

**Option Recommended:** Real-time posting using a central service.

Example flow for a Sale:
1. Sale is finalized
2. `JournalPostingService::postSalesInvoice($invoiceId)` is called
3. It creates one `journal_entries` record
4. It creates multiple `journal_lines`:
   - Debit: Accounts Receivable (or Customer Control)
   - Credit: Sales Revenue
   - Debit/Credit: VAT if applicable
   - Credit: Inventory / Debit: Cost of Goods Sold (if perpetual inventory)

This service can be called from existing models in a controlled way.

---

## Next Action

Please tell me which of the following you want to tackle **first**:

1. **Improve the Chart of Accounts** (`ledgers` table + seed a proper structure)
2. **Create the `journal_entries` + `journal_lines` tables** + basic model
3. **Design the `JournalPostingService`** architecture (how transactions will post)
4. **Start with a low-risk area** (e.g., Other Income/Expense posting)

Also, would you like me to first create the database migration scripts for the new tables?