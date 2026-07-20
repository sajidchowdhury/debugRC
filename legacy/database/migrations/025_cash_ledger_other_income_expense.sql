-- Extend cash_ledger reference types for other income / expense (branch cash book)
ALTER TABLE `cash_ledger`
  MODIFY COLUMN `reference_type` enum(
    'money_transfer',
    'customer_payment',
    'supplier_payment',
    'sales',
    'purchase',
    'adjustment',
    'opening',
    'reversal',
    'other_income',
    'other_expense'
  ) NOT NULL;