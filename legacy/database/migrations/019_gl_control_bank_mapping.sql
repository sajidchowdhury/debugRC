-- Phase 5: Per-bank GL mapping + optional sales discount ledger for invoice posting.

CREATE TABLE IF NOT EXISTS `bank_ledger_mappings` (
    `bank_id` INT(11) NOT NULL,
    `ledger_id` INT(11) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bank_id`),
    KEY `idx_blm_ledger` (`ledger_id`),
    CONSTRAINT `fk_blm_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_blm_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `ledgers` (
    `ledger_code`, `ledger_name`, `description`, `parent_id`, `account_type`,
    `ledger_nature`, `normal_balance`, `sort_order`, `is_active`, `is_system`
)
SELECT 'L-1010', 'Sales Discount Allowed', 'Invoice line discounts (contra revenue)', 16, 'Expense',
       'sales_discount', 'debit', 6230, 1, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `ledgers` WHERE `ledger_nature` = 'sales_discount' LIMIT 1
);