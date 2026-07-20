-- Phase 1: Stock take integrity, workflow B (count then post), reversal support

ALTER TABLE `stock_take_sessions`
    MODIFY COLUMN `status` ENUM('draft','counting','adjusted','reversed') NOT NULL DEFAULT 'draft',
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL AFTER `adjusted_at`,
    ADD COLUMN `posted_at` DATETIME DEFAULT NULL AFTER `journal_entry_id`;

UPDATE `stock_take_sessions`
SET `status` = 'adjusted'
WHERE `status` = '' OR `status` IS NULL OR `status` = 'completed' OR `status` = 'in_progress';

UPDATE `stock_take_sessions`
SET `status` = 'reversed'
WHERE `is_reversed` = 1 AND `status` <> 'reversed';

ALTER TABLE `stock_take_warehouses`
    MODIFY COLUMN `status` ENUM('pending','counted','posted') NOT NULL DEFAULT 'pending';

UPDATE `stock_take_warehouses`
SET `status` = 'posted'
WHERE `status` = 'adjusted';

ALTER TABLE `stock_take_items`
    ADD COLUMN `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Avg cost at count/post' AFTER `physical_qty`,
    ADD COLUMN `is_applied` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reason`;

DELETE t1 FROM `stock_take_items` t1
INNER JOIN `stock_take_items` t2
    ON t1.stock_take_session_id = t2.stock_take_session_id
   AND t1.warehouse_id = t2.warehouse_id
   AND t1.product_id = t2.product_id
   AND t1.id > t2.id;

ALTER TABLE `stock_take_items`
    ADD UNIQUE KEY `uk_sti_session_wh_product` (`stock_take_session_id`, `warehouse_id`, `product_id`);