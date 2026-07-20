-- Damage invoice GL link (Dr shrinkage / Cr inventory on post)

ALTER TABLE `damage_invoices`
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL AFTER `total_value`,
    ADD INDEX `idx_dmg_journal` (`journal_entry_id`);