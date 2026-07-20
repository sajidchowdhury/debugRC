-- Reference schema: auth / user-management core tables (no data).
-- Apply structural changes via database/migrations/*.sql

CREATE TABLE IF NOT EXISTS `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_branches_code` (`branch_code`),
  KEY `idx_branches_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_code` varchar(20) NOT NULL,
  `warehouse_name` varchar(100) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_warehouses_code` (`warehouse_code`),
  KEY `idx_warehouses_active` (`is_active`),
  KEY `idx_warehouses_branch` (`branch_id`),
  CONSTRAINT `fk_warehouses_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `nid` varchar(30) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `branch_id` int(11) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `joining_date` date DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT 0.00,
  `bank_account` varchar(50) DEFAULT NULL,
  `blood_group` varchar(10) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_code` (`employee_code`),
  KEY `idx_employees_branch` (`branch_id`),
  CONSTRAINT `fk_employees_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `last_login_user_agent` varchar(255) DEFAULT NULL,
  `failed_login_count` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `credential_version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `telegram_user_id` bigint(20) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `employee_id` (`employee_id`),
  CONSTRAINT `fk_users_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_name` varchar(100) NOT NULL,
  `controller` varchar(50) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_menu_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `menu_id` int(11) NOT NULL,
  `can_view` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_menu_unique` (`user_id`,`menu_id`),
  KEY `menu_id` (`menu_id`),
  CONSTRAINT `fk_ump_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ump_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
  `bucket_key` varchar(128) NOT NULL,
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `reset_at` datetime NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`bucket_key`),
  KEY `idx_login_rate_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prt_user` (`user_id`),
  KEY `idx_prt_hash` (`token_hash`),
  KEY `idx_prt_expires` (`expires_at`),
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(24) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  KEY `idx_remember_expires` (`expires_at`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
