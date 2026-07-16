-- Phase 0: Allow reference types used by challan transport, payment reversal, and sales return reversal.
-- Safe to re-run: MODIFY replaces the full ENUM list.

ALTER TABLE `customer_ledger`
    MODIFY COLUMN `reference_type` ENUM(
        'invoice',
        'payment',
        'return',
        'adjustment',
        'sales_return',
        'reversal',
        'invoice_adjustment',
        'payment_reversal',
        'sales_return_reversal'
    ) NOT NULL;