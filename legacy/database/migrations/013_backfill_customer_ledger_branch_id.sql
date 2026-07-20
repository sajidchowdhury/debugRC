-- Phase 2: Backfill branch_id on historical customer_ledger rows (safe to re-run).

UPDATE customer_ledger cl
INNER JOIN sales_invoices si ON cl.reference_type = 'invoice' AND cl.reference_id = si.id
SET cl.branch_id = si.branch_id
WHERE cl.branch_id IS NULL AND si.branch_id IS NOT NULL;

UPDATE customer_ledger cl
INNER JOIN customer_payments cp ON cl.reference_type = 'payment' AND cl.reference_id = cp.id
SET cl.branch_id = cp.branch_id
WHERE cl.branch_id IS NULL AND cp.branch_id IS NOT NULL;

UPDATE customer_ledger cl
INNER JOIN sales_returns sr ON cl.reference_type = 'sales_return' AND cl.reference_id = sr.id
INNER JOIN sales_invoices si ON sr.sales_invoice_id = si.id
SET cl.branch_id = si.branch_id
WHERE cl.branch_id IS NULL AND si.branch_id IS NOT NULL;

UPDATE customer_ledger cl
INNER JOIN sales_invoices si ON cl.reference_type IN ('reversal', 'invoice_adjustment')
    AND cl.reference_id = si.id
SET cl.branch_id = si.branch_id
WHERE cl.branch_id IS NULL AND si.branch_id IS NOT NULL;

UPDATE customer_ledger cl
INNER JOIN customer_payments cp ON cl.reference_type IN ('reversal', 'payment_reversal')
    AND cl.reference_id = cp.id
SET cl.branch_id = cp.branch_id
WHERE cl.branch_id IS NULL AND cp.branch_id IS NOT NULL;

UPDATE customer_ledger cl
INNER JOIN sales_returns sr ON cl.reference_type IN ('reversal', 'sales_return_reversal')
    AND cl.reference_id = sr.id
INNER JOIN sales_invoices si ON sr.sales_invoice_id = si.id
SET cl.branch_id = si.branch_id
WHERE cl.branch_id IS NULL AND si.branch_id IS NOT NULL;