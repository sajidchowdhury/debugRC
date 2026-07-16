-- ============================================================================
-- MIGRATION: 006_fix_received_qty_defaults.sql
-- Purpose: Ensure purchase_order_items.received_qty is never NULL (fixes "No items found" in Receive even when PO has items)
-- Run this to backfill existing data.
-- ============================================================================

-- Set default 0 for future inserts (if column allows)
ALTER TABLE `purchase_order_items`
    MODIFY COLUMN `received_qty` DECIMAL(12,4) NOT NULL DEFAULT 0.0000;

-- Backfill any existing NULLs to 0 (critical for old POs created before the column was properly defaulted)
UPDATE `purchase_order_items`
SET `received_qty` = 0
WHERE `received_qty` IS NULL;

-- Also ensure the column exists with proper default on fresh installs (safe if already done)
-- The ALTER MODIFY is idempotent-ish.

-- Optional: you can also ensure the status is recalculated, but running a receive or the updatePOStatus will handle.
-- ============================================================================