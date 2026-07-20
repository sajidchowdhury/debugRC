-- ============================================================================
-- MIGRATION: 004_add_returned_qty_to_receive_items.sql
-- Purpose: Support cumulative tracking of returned quantities (needed for Phase 2 validation)
-- ============================================================================

ALTER TABLE `purchase_receive_items`
    ADD COLUMN `returned_qty` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER `received_qty`,
    ADD INDEX `idx_returned_qty` (`returned_qty`);

-- Note: This column will be updated whenever a Purchase Return is created against this receive item.
-- It helps prevent over-returning across multiple return transactions.