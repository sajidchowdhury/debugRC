-- ============================================================
-- Migration 046: Drop TOTP / 2FA columns from users table
-- Phase 0 — Pre-Migration Security Cleanup
--
-- Per migration decision, TOTP 2FA on login is removed completely.
-- This migration drops the columns added by migration 030_auth_phase5_features.sql:
--   - users.totp_secret   (VARCHAR, encrypted TOTP secret)
--   - users.totp_enabled  (TINYINT(1), 2FA on/off flag)
--
-- Run AFTER deploying the Phase 0 code changes (which remove all
-- references to these columns). Safe to run even if columns already
-- absent (idempotent via INFORMATION_SCHEMA check).
--
-- NOTE: This is a MySQL migration (legacy app still on MySQL until Phase 2).
-- The Laravel/PostgreSQL baseline migration in Phase 2.2 will simply
-- not include these columns.
-- ============================================================

-- Drop totp_secret if it exists
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'totp_secret'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `users` DROP COLUMN `totp_secret`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop totp_enabled if it exists
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'totp_enabled'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `users` DROP COLUMN `totp_enabled`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
