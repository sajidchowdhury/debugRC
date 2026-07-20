-- Phase 6A: Manual journal entry metadata (GL lines live in journal_entries / journal_lines)

CREATE TABLE IF NOT EXISTS `manual_journals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `internal_note` TEXT NULL COMMENT 'Optional internal note (in addition to JE description)',
    `attachment_filename` VARCHAR(255) NULL,
    `attachment_path` VARCHAR(500) NULL,
    `branch_id` INT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_manual_journal_entry` (`journal_entry_id`),
    KEY `idx_manual_branch` (`branch_id`),
    KEY `idx_manual_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
