-- ============================================================================
-- MIGRATION: 008_document_sequences.sql
-- Purpose: Atomic document numbering for Sales module (Phase 2)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `document_sequences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `doc_type` VARCHAR(50) NOT NULL,
    `branch_id` INT(11) NOT NULL DEFAULT 0,
    `period_key` VARCHAR(20) NOT NULL,
    `last_number` INT(11) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_sequence` (`doc_type`, `branch_id`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Unique document codes (skip if you have duplicate legacy codes — clean data first)
-- ALTER TABLE `sales_invoices` ADD UNIQUE KEY `uk_si_invoice_code` (`invoice_code`);
-- ALTER TABLE `sales_challans` ADD UNIQUE KEY `uk_sc_challan_code` (`challan_code`);
-- ALTER TABLE `sales_returns` ADD UNIQUE KEY `uk_sr_return_code` (`return_code`);