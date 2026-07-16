-- Link auto damage write-offs from confirmed damaged sales returns (C1 fix)

ALTER TABLE `damage_invoices`
    ADD COLUMN `sales_return_id` INT UNSIGNED NULL DEFAULT NULL AFTER `remarks`,
    ADD INDEX `idx_damage_sales_return` (`sales_return_id`);

ALTER TABLE `sales_return_items`
    ADD COLUMN `damage_invoice_id` INT UNSIGNED NULL DEFAULT NULL AFTER `warehouse_id`,
    ADD INDEX `idx_sri_damage_invoice` (`damage_invoice_id`);
