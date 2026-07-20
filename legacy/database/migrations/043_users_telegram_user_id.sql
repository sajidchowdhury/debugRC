-- ============================================================================
-- MIGRATION: 043_users_telegram_user_id.sql
-- Purpose: Store each user's Telegram chat ID for bot alerts (Phase 4C).
-- Run once per database (local XAMPP, staging, production, etc.).
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'telegram_user_id'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `users`
        ADD COLUMN `telegram_user_id` BIGINT(20) NULL DEFAULT NULL AFTER `credential_version`,
        ADD KEY `idx_users_telegram_user_id` (`telegram_user_id`)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
