-- Phase 6B: Soft period close per branch (closed_through_date locks posting on or before that date)

CREATE TABLE IF NOT EXISTS `accounting_periods` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `branch_id` INT NOT NULL,
    `closed_through_date` DATE NOT NULL COMMENT 'No GL posting on or before this date (unless override)',
    `closed_by` INT NULL,
    `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_accounting_period_branch` (`branch_id`),
    KEY `idx_closed_through` (`closed_through_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
