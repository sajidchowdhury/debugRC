-- Phase 3: Trace demand line items on stock movements

ALTER TABLE `stock_transactions`
    ADD COLUMN `branch_demand_item_id` INT(11) DEFAULT NULL AFTER `reference_id`,
    ADD KEY `idx_st_branch_demand_item` (`branch_demand_item_id`);

-- Menu: weekly inter-branch control report
INSERT INTO `menus` (`menu_name`, `menu_link`, `controller`, `action`, `icon`, `parent_id`, `sort_order`, `section`, `is_active`)
SELECT 'Inter-branch Weekly', NULL, 'BranchDemand', 'weekly', 'fa fa-chart-line', 72, 7, 'Inventory Report', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `menus` WHERE `controller` = 'BranchDemand' AND `action` = 'weekly' LIMIT 1
);