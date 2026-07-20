-- Link employee_transactions to journal_entries (run once)
ALTER TABLE `employee_transactions`
  ADD COLUMN `journal_entry_id` INT(11) NULL DEFAULT NULL AFTER `branch_id`,
  ADD KEY `idx_employee_transactions_journal` (`journal_entry_id`);