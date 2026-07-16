-- ============================================================================
-- MIGRATION: 010_add_journal_entry_id_to_supplier_payments.sql
-- Purpose: Add journal_entry_id to supplier_payments for integration with
--          the new accounting system (JournalPostingService) -- Phase for
--          supplier due/ledger/accounting parity with customer_payments.
-- Run this after pulling changes for supplier payment GL support.
-- ============================================================================

ALTER TABLE `supplier_payments` 
    ADD COLUMN `journal_entry_id` BIGINT(20) NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_sp_journal_entry` (`journal_entry_id`);

-- Optional FK (uncomment if desired for integrity):
-- ALTER TABLE `supplier_payments` 
--     ADD CONSTRAINT `fk_supplier_payments_journal` 
--     FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) 
--     ON DELETE SET NULL;

-- Also expand supplier_ledger ref_type enum to support all trans types used in code/audit (was missing advance/receive causing potential insert issues)
ALTER TABLE `supplier_ledger` 
    MODIFY `reference_type` enum('purchase','payment','return','adjustment','reversal','advance','receive') NOT NULL;

COMMIT;