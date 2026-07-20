-- Employee control account for EmployeeTransaction GL posting
-- Nature must be: employee_payable (see JournalPostingService::getEmployeePayableLedgerId)
-- Debit normal balance = advances/loans increase amount employee owes the company

INSERT INTO `ledgers` (
    `ledger_code`,
    `ledger_name`,
    `description`,
    `parent_id`,
    `account_type`,
    `ledger_nature`,
    `normal_balance`,
    `sort_order`,
    `is_active`,
    `is_system`,
    `is_control_account`,
    `control_account_type`
)
SELECT
    'L-0105',
    'Employee Advances & Receivable',
    'Control account for employee transactions (advance, loan, salary paid, repayment). Used by EmployeeTransaction journal posting.',
    p.id,
    'Asset',
    'employee_payable',
    'debit',
    1135,
    1,
    1,
    1,
    'employee'
FROM `ledgers` p
WHERE p.ledger_code = 'L-0100'
  AND NOT EXISTS (
      SELECT 1 FROM `ledgers` e
      WHERE e.ledger_nature = 'employee_payable' AND e.is_active = 1
  )
LIMIT 1;