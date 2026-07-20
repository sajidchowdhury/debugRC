-- ============================================================================
-- MIGRATION: 007_add_journal_entry_to_sales.sql
-- Purpose: Prepare Sales module for double-entry GL integration (Phase 1)
-- ============================================================================

-- sales_invoices
ALTER TABLE `sales_invoices`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL DEFAULT NULL AFTER `total_amount`,
    ADD INDEX `idx_si_journal_entry` (`journal_entry_id`);

-- sales_challans (COGS posting at challan complete)
ALTER TABLE `sales_challans`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_sc_journal_entry` (`journal_entry_id`);

-- sales_returns
ALTER TABLE `sales_returns`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_sr_journal_entry` (`journal_entry_id`);

-- customer_payments
ALTER TABLE `customer_payments`
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_cp_journal_entry` (`journal_entry_id`);

-- ============================================================================
-- Run before Phase 5 GL posting. Columns are nullable until journals are created.
-- ============================================================================