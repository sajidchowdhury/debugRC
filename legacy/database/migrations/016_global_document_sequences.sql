-- Document codes are system-wide unique (invoice_code, payment_code, etc.).
-- Resync counters from existing documents and drop per-branch sequence rows.

INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
SELECT
    'sales_invoice',
    0,
    SUBSTRING(si.invoice_code, 4, 8),
    MAX(CAST(SUBSTRING_INDEX(si.invoice_code, '-', -1) AS UNSIGNED))
FROM sales_invoices si
WHERE si.invoice_code REGEXP '^SI-[0-9]{8}-[0-9]+$'
GROUP BY SUBSTRING(si.invoice_code, 4, 8)
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(document_sequences.last_number, VALUES(last_number));

INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
SELECT
    'customer_payment',
    0,
    SUBSTRING(cp.payment_code, 5, 8),
    MAX(CAST(SUBSTRING_INDEX(cp.payment_code, '-', -1) AS UNSIGNED))
FROM customer_payments cp
WHERE cp.payment_code REGEXP '^PAY-[0-9]{8}-[0-9]+$'
GROUP BY SUBSTRING(cp.payment_code, 5, 8)
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(document_sequences.last_number, VALUES(last_number));

INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
SELECT
    'sales_return',
    0,
    SUBSTRING(sr.return_code, 4, 8),
    MAX(CAST(SUBSTRING_INDEX(sr.return_code, '-', -1) AS UNSIGNED))
FROM sales_returns sr
WHERE sr.return_code REGEXP '^SR-[0-9]{8}-[0-9]+$'
GROUP BY SUBSTRING(sr.return_code, 4, 8)
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(document_sequences.last_number, VALUES(last_number));

INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
SELECT
    'sales_challan',
    0,
    SUBSTRING(sc.challan_code, 4, 4),
    MAX(CAST(SUBSTRING_INDEX(sc.challan_code, '-', -1) AS UNSIGNED))
FROM sales_challans sc
WHERE sc.challan_code REGEXP '^CH-[0-9]{4}-[0-9]+$'
GROUP BY SUBSTRING(sc.challan_code, 4, 4)
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(document_sequences.last_number, VALUES(last_number));

DELETE FROM document_sequences WHERE branch_id <> 0;