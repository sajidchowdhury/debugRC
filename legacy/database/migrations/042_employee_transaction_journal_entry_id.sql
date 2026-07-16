-- ============================================================================
-- MIGRATION: 042_employee_transaction_journal_entry_id.sql
-- Purpose: Link employee_transactions to journal_entries for GL posting.
-- Idempotent: safe if column already exists from add_employee_transaction_journal_entry_id.sql
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_transactions'
      AND COLUMN_NAME = 'journal_entry_id'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `employee_transactions`
        ADD COLUMN `journal_entry_id` INT(11) NULL DEFAULT NULL AFTER `branch_id`,
        ADD KEY `idx_employee_transactions_journal` (`journal_entry_id`)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
