-- Phase: Stock adjustment GL link (reuse shrinkage/surplus ledgers from 024)

ALTER TABLE `stock_adjustments`
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL AFTER `total_amount`,
    ADD INDEX `idx_sa_journal` (`journal_entry_id`);