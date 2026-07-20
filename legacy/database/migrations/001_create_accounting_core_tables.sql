-- ============================================================================
-- MIGRATION: 001_create_accounting_core_tables.sql
-- Purpose: Establish proper double-entry accounting foundation
-- Note: This is safe to run because there is no critical production data yet.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Improve existing `ledgers` table (Chart of Accounts)
-- ----------------------------------------------------------------------------
-- Make ledger_nature flexible (was restrictive ENUM). This is safe with no production data.
ALTER TABLE `ledgers` 
    MODIFY COLUMN `ledger_nature` VARCHAR(60) NULL 
        COMMENT 'System behavior tag used by posting engine (cash_bank, sales_revenue, cogs, customer_receivable, etc.)';

ALTER TABLE `ledgers` 
    ADD COLUMN `normal_balance` ENUM('debit', 'credit') NOT NULL DEFAULT 'debit' 
        COMMENT 'Normal balance side for this account' AFTER `ledger_nature`,

    ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 
        COMMENT 'System ledger. Cannot be deleted by user' AFTER `is_active`,

    ADD COLUMN `is_control_account` TINYINT(1) NOT NULL DEFAULT 0 
        COMMENT 'This ledger acts as a control account (e.g. Accounts Receivable)' AFTER `is_system`,

    ADD COLUMN `control_account_type` VARCHAR(50) NULL 
        COMMENT 'customer, supplier, employee, bank, fixed_asset, etc.' AFTER `is_control_account`,

    ADD COLUMN `description` TEXT NULL AFTER `ledger_name`;

-- Add useful indexes
ALTER TABLE `ledgers` 
    ADD INDEX `idx_ledger_nature` (`ledger_nature`),
    ADD INDEX `idx_parent_id` (`parent_id`),
    ADD INDEX `idx_account_type` (`account_type`);

-- ----------------------------------------------------------------------------
-- 2. Create Journal Entries (Master Table)
-- ----------------------------------------------------------------------------
CREATE TABLE `journal_entries` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `entry_no` VARCHAR(30) NOT NULL COMMENT 'e.g. JE-2026-000145',
    `entry_date` DATE NOT NULL,
    `description` TEXT DEFAULT NULL,
    
    -- Reference to source document
    `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'sales_invoice, purchase_receive, money_transfer, other_income, manual, etc.',
    `reference_id` BIGINT(20) DEFAULT NULL,
    
    `branch_id` INT(11) DEFAULT NULL,
    
    `total_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    `is_posted` TINYINT(1) NOT NULL DEFAULT 1,
    `posted_at` DATETIME DEFAULT NULL,
    
    `is_reversed` TINYINT(1) NOT NULL DEFAULT 0,
    `reversal_of_entry_id` BIGINT(20) DEFAULT NULL COMMENT 'If this is a reversal, points to original entry',
    
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_entry_no` (`entry_no`),
    KEY `idx_entry_date` (`entry_date`),
    KEY `idx_reference` (`reference_type`, `reference_id`),
    KEY `idx_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Create Journal Lines (Detail Table)
-- ----------------------------------------------------------------------------
CREATE TABLE `journal_lines` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `journal_entry_id` BIGINT(20) NOT NULL,
    
    `ledger_id` INT(11) NOT NULL COMMENT 'FK to ledgers table',
    
    `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    `description` VARCHAR(255) DEFAULT NULL,
    
    -- Optional: Link to sub-ledger entity for easier reporting
    `entity_type` VARCHAR(30) DEFAULT NULL COMMENT 'customer, supplier, employee, bank, asset, etc.',
    `entity_id` BIGINT(20) DEFAULT NULL,
    
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    
    PRIMARY KEY (`id`),
    KEY `idx_journal_entry` (`journal_entry_id`),
    KEY `idx_ledger` (`ledger_id`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    
    CONSTRAINT `fk_journal_lines_entry` 
        FOREIGN KEY (`journal_entry_id`) 
        REFERENCES `journal_entries` (`id`) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Optional: Add a posting log for traceability (recommended)
-- ----------------------------------------------------------------------------
CREATE TABLE `journal_posting_logs` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `journal_entry_id` BIGINT(20) NOT NULL,
    `action` ENUM('posted', 'reversed', 'edited') NOT NULL,
    `performed_by` INT(11) DEFAULT NULL,
    `performed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    `remarks` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_journal_entry` (`journal_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. Helpful Views (Optional but recommended)
-- ----------------------------------------------------------------------------

-- View to easily see journal entries with their lines
CREATE OR REPLACE VIEW `v_journal_entries_with_lines` AS
SELECT 
    je.id AS journal_entry_id,
    je.entry_no,
    je.entry_date,
    je.description,
    je.reference_type,
    je.reference_id,
    je.branch_id,
    jl.id AS line_id,
    jl.ledger_id,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    jl.debit,
    jl.credit,
    jl.description AS line_description,
    jl.entity_type,
    jl.entity_id,
    je.created_by,
    je.created_at
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
JOIN ledgers l ON l.id = jl.ledger_id;

COMMIT;