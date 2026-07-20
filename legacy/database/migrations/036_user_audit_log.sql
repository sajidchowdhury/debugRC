-- D4: DB-backed audit trail (dual-write with logs/user_audit.log during transition)

CREATE TABLE IF NOT EXISTS `user_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `logged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `performed_by` int(11) NOT NULL DEFAULT 0,
  `action` varchar(64) NOT NULL,
  `target_id` int(11) UNSIGNED DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `branch_id` int(11) UNSIGNED DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logged_at` (`logged_at`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_performed_by` (`performed_by`),
  KEY `idx_audit_target_id` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
