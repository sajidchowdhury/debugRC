-- ============================================================================
-- MIGRATION: 009_sales_phase4_reversal_audit.sql
-- Purpose: Phase 4 — reversal metadata on sales challans (audit / GL prep)
-- ============================================================================

ALTER TABLE `sales_challans`
    ADD COLUMN `reversed_at`     DATETIME     NULL     AFTER `is_reversed`,
    ADD COLUMN `reversed_by`     INT(11)      NULL     AFTER `reversed_at`,
    ADD COLUMN `reverse_reason`  VARCHAR(500) NULL     AFTER `reversed_by`,
    ADD INDEX `idx_sc_reversed` (`is_reversed`, `reversed_at`);

-- ============================================================================
-- Run after 007. Existing challans remain valid (NULL reversal metadata).
-- ============================================================================