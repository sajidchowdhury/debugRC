-- Phase 3: Snapshot invoice totals before challan transport change; link adjustment journal on challan.
-- Split per table so partial installs can complete on re-run.

ALTER TABLE `sales_invoices`
    ADD COLUMN `pre_challan_transport` DECIMAL(12,2) NULL DEFAULT NULL AFTER `transport_cost`;

ALTER TABLE `sales_invoices`
    ADD COLUMN `pre_challan_total` DECIMAL(12,2) NULL DEFAULT NULL AFTER `total_amount`;

ALTER TABLE `sales_challans`
    ADD COLUMN `transport_adjustment` DECIMAL(12,2) NULL DEFAULT NULL AFTER `total_amount`;

ALTER TABLE `sales_challans`
    ADD COLUMN `adjustment_journal_entry_id` INT(11) NULL DEFAULT NULL AFTER `journal_entry_id`;

ALTER TABLE `sales_challans`
    ADD INDEX `idx_sc_adj_journal` (`adjustment_journal_entry_id`);