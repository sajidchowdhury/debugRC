-- Phase 3: Stock take GL — shrinkage (shortage) and surplus (overage) ledgers

INSERT INTO `ledgers` (`ledger_code`, `ledger_name`, `parent_id`, `account_type`, `ledger_nature`, `sort_order`, `is_active`, `is_system`, `normal_balance`)
SELECT 'L-ST-SHR', 'Inventory Shrinkage (Stock Take)', COALESCE((SELECT id FROM `ledgers` WHERE `ledger_nature` = 'cogs' LIMIT 1), 0), 'Expense', 'inventory_shrinkage', 5025, 1, 1, 'debit'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `ledger_nature` = 'inventory_shrinkage' LIMIT 1);

INSERT INTO `ledgers` (`ledger_code`, `ledger_name`, `parent_id`, `account_type`, `ledger_nature`, `sort_order`, `is_active`, `is_system`, `normal_balance`)
SELECT 'L-ST-SUR', 'Inventory Surplus (Stock Take)', COALESCE((SELECT id FROM `ledgers` WHERE `ledger_nature` = 'other_income' LIMIT 1), 0), 'Income', 'inventory_surplus', 425, 1, 1, 'credit'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ledgers` WHERE `ledger_nature` = 'inventory_surplus' LIMIT 1);