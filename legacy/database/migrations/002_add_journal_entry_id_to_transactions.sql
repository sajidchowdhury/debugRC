-- ============================================================================
-- MIGRATION: 002_add_journal_entry_id_to_transactions.sql
-- Purpose: Link operational transactions to the new Journal Entry system
-- ============================================================================

-- Add journal_entry_id to money_transfers
ALTER TABLE `money_transfers` 
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- Add journal_entry_id to other_incomes
ALTER TABLE `other_incomes` 
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- Add journal_entry_id to other_expenses
ALTER TABLE `other_expenses` 
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- Optional: Add foreign key constraints (recommended for data integrity)
-- Uncomment these if you want strict referential integrity:

-- ALTER TABLE `money_transfers` 
--     ADD CONSTRAINT `fk_money_transfers_journal` 
--     FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) 
--     ON DELETE SET NULL;

-- ALTER TABLE `other_incomes` 
--     ADD CONSTRAINT `fk_other_incomes_journal` 
--     FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) 
--     ON DELETE SET NULL;

-- ALTER TABLE `other_expenses` 
--     ADD CONSTRAINT `fk_other_expenses_journal` 
--     FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) 
--     ON DELETE SET NULL;

COMMIT;