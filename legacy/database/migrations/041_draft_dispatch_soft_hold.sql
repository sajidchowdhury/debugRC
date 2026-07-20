-- W2: Draft invoices use branch-level soft hold (warehouse_id NULL until godown save).

UPDATE sales_invoice_dispatches sid
INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
SET sid.warehouse_id = NULL
WHERE si.is_reversed = 0
  AND si.status = 'draft'
  AND si.godown_issued_at IS NULL
  AND COALESCE(sid.dispatched_qty, 0) = 0
  AND sid.warehouse_id IS NOT NULL;

UPDATE sales_invoice_items sii
INNER JOIN sales_invoices si ON si.id = sii.sales_invoice_id
SET sii.warehouse_id = NULL
WHERE si.is_reversed = 0
  AND si.status = 'draft'
  AND si.godown_issued_at IS NULL
  AND sii.warehouse_id IS NOT NULL;
