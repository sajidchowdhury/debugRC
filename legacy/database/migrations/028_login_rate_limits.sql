-- Phase 2 (Auth): persistent login brute-force rate limits (IP + username).

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
    `bucket_key` VARCHAR(128) NOT NULL,
    `attempt_count` INT(11) NOT NULL DEFAULT 0,
    `reset_at` DATETIME NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bucket_key`),
    KEY `idx_login_rate_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
