-- ============================================================================
-- MIGRATION: 003_add_journal_and_reversal_to_purchase.sql
-- Purpose: Prepare Purchase module for double-entry accounting integration
-- This is Phase 1 of Purchase Module Modernization Plan
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Add journal_entry_id and reversal columns to purchase_receive
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_receive`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD COLUMN `is_reversed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_amount`,
    ADD COLUMN `reversal_of_receive_id` BIGINT(20) NULL AFTER `is_reversed`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`),
    ADD INDEX `idx_is_reversed` (`is_reversed`);

-- ----------------------------------------------------------------------------
-- 2. Add journal_entry_id and reversal columns to purchase_return
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_return`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD COLUMN `is_reversed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_amount`,
    ADD COLUMN `reversal_of_return_id` BIGINT(20) NULL AFTER `is_reversed`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`),
    ADD INDEX `idx_is_reversed` (`is_reversed`);

-- ----------------------------------------------------------------------------
-- 3. (Optional but recommended) Add journal_entry_id to purchase_orders
--    This can be used later if we want to record purchase commitments
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_orders`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL AFTER `id`,
    ADD INDEX `idx_journal_entry` (`journal_entry_id`);

-- ----------------------------------------------------------------------------
-- Notes:
-- - These columns allow us to link Purchase transactions to the new journal system.
-- - `is_reversed` + `reversal_of_*_id` will support proper reversal entries in future.
-- - Run this migration before starting any GL posting logic for Purchase.
-- ============================================================================