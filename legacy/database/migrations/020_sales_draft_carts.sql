-- Phase 6: Optional DB-backed sales draft carts (multi-device recovery).

CREATE TABLE IF NOT EXISTS `sales_draft_carts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `branch_id` INT(11) NOT NULL DEFAULT 0,
    `customer_id` INT(11) NOT NULL,
    `items_json` LONGTEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sales_draft_user_customer` (`user_id`, `customer_id`),
    KEY `idx_sales_draft_branch` (`branch_id`),
    KEY `idx_sales_draft_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;