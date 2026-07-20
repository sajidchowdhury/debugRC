-- ============================================================================
-- MIGRATION: 005_add_reversal_metadata_to_purchase.sql
-- Purpose: Phase 4 - Full Auditability & Reversibility for Purchase module
-- Adds standard reversal audit columns (matching other_expenses / journal_entries patterns)
-- Run this AFTER 003 and 004 if not already applied.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Enhance purchase_receive for proper reversal tracking
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_receive`
    ADD COLUMN `reversed_at`     DATETIME     NULL     AFTER `is_reversed`,
    ADD COLUMN `reversed_by`     INT(11)      NULL     AFTER `reversed_at`,
    ADD COLUMN `reverse_reason`  VARCHAR(500) NULL     AFTER `reversed_by`,
    ADD INDEX `idx_reversed` (`is_reversed`, `reversed_at`);

-- ----------------------------------------------------------------------------
-- 2. Enhance purchase_return for proper reversal tracking
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_return`
    ADD COLUMN `reversed_at`     DATETIME     NULL     AFTER `is_reversed`,
    ADD COLUMN `reversed_by`     INT(11)      NULL     AFTER `reversed_at`,
    ADD COLUMN `reverse_reason`  VARCHAR(500) NULL     AFTER `reversed_by`,
    ADD INDEX `idx_reversed` (`is_reversed`, `reversed_at`);

-- ----------------------------------------------------------------------------
-- 3. (Recommended) Add basic cancel metadata to purchase_orders for consistency
--    (PO already supports status='cancelled' + remarks append)
-- ----------------------------------------------------------------------------
ALTER TABLE `purchase_orders`
    ADD COLUMN `cancelled_at`    DATETIME     NULL     AFTER `journal_entry_id`,
    ADD COLUMN `cancelled_by`    INT(11)      NULL     AFTER `cancelled_at`,
    ADD COLUMN `cancel_reason`   VARCHAR(500) NULL     AFTER `cancelled_by`;

-- ----------------------------------------------------------------------------
-- Notes for developers:
-- - These columns + the ones from 003 (is_reversed, reversal_of_*, journal_entry_id)
--   give full traceability for future GL reversal (reversing journal entries).
-- - In reverse*() methods we will:
--     * SET is_reversed=1, reversed_at=NOW(), reversed_by=:uid, reverse_reason=:r
--     * (future) Call JournalPostingService to create reversing entry
--     * Link via reversal_of_* or journal_entries.reversal_of_entry_id
-- - After running, existing rows remain compatible (NULLs = not reversed).
-- ============================================================================