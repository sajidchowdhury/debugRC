-- Inter-branch warehouse transfer GL (dual branch journals)

ALTER TABLE `warehouse_transfers`
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL COMMENT 'From-branch (sender) journal' AFTER `total_amount`,
    ADD COLUMN `journal_entry_id_debtor` BIGINT(20) DEFAULT NULL COMMENT 'To-branch (receiver) journal' AFTER `journal_entry_id`,
    ADD INDEX `idx_wt_journal_from` (`journal_entry_id`),
    ADD INDEX `idx_wt_journal_to` (`journal_entry_id_debtor`);