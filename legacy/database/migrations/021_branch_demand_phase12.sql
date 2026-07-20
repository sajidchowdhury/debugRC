-- Phase 1–2: Branch demand schema alignment + settlement support

-- Status: allow rejected; keep received as fulfilled state
ALTER TABLE `branch_demands`
    MODIFY COLUMN `status` ENUM('pending','received','rejected','reversed') NOT NULL DEFAULT 'pending';

-- Links to stock document and GL
ALTER TABLE `branch_demands`
    ADD COLUMN `warehouse_transfer_id` BIGINT(20) DEFAULT NULL AFTER `settlement_amount`,
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL COMMENT 'Creditor-branch fulfillment journal' AFTER `warehouse_transfer_id`,
    ADD COLUMN `journal_entry_id_debtor` BIGINT(20) DEFAULT NULL COMMENT 'Debtor-branch fulfillment journal' AFTER `journal_entry_id`;

-- branch_ledger: flexible reference types (demand_transfer, demand_settlement, etc.)
ALTER TABLE `branch_ledger`
    MODIFY COLUMN `reference_type` VARCHAR(50) NOT NULL DEFAULT 'adjustment';

ALTER TABLE `branch_ledger`
    ADD COLUMN `journal_entry_id` BIGINT(20) DEFAULT NULL AFTER `reference_id`;

-- Money transfer allocations to branch demands
CREATE TABLE IF NOT EXISTS `money_transfer_settlements` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `transfer_id` BIGINT(20) NOT NULL,
    `demand_id` INT(11) NOT NULL,
    `settled_amount` DECIMAL(12,2) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mts_transfer` (`transfer_id`),
    KEY `idx_mts_demand` (`demand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inter-branch GL control accounts (idempotent by ledger_code)
INSERT INTO `ledgers` (`ledger_code`, `ledger_name`, `parent_id`, `account_type`, `ledger_nature`, `sort_order`, `is_active`, `is_system`, `normal_balance`)
SELECT 'L-0105', 'Due from Branches', p.id, 'Asset', 'interbranch_receivable', 1145, 1, 1, 'debit'
FROM `ledgers` p
WHERE p.`ledger_code` = 'L-0100'
  AND NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `ledger_code` = 'L-0105' LIMIT 1)
LIMIT 1;

INSERT INTO `ledgers` (`ledger_code`, `ledger_name`, `parent_id`, `account_type`, `ledger_nature`, `sort_order`, `is_active`, `is_system`, `normal_balance`)
SELECT 'L-0302', 'Due to Branches', p.id, 'Liability', 'interbranch_payable', 2115, 1, 1, 'credit'
FROM `ledgers` p
WHERE p.`ledger_code` = 'L-0300'
  AND NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `ledger_code` = 'L-0302' LIMIT 1)
LIMIT 1;

-- Backfill empty legacy reference_type on demand rows
UPDATE `branch_ledger`
SET `reference_type` = 'demand_transfer'
WHERE (`reference_type` = '' OR `reference_type` IS NULL)
  AND `remarks` LIKE 'Demand #%';

UPDATE `branch_ledger`
SET `reference_type` = 'demand_settlement'
WHERE (`reference_type` = '' OR `reference_type` IS NULL)
  AND `remarks` LIKE 'Customer Payment Settlement%';

UPDATE `branch_ledger`
SET `reference_type` = 'demand_settlement'
WHERE (`reference_type` = '' OR `reference_type` IS NULL)
  AND `remarks` LIKE 'Money Transfer Settlement%';