-- Phase 2: Seed document_sequences from existing PAY-YYYYMMDD-#### codes (safe to re-run).
INSERT INTO document_sequences (doc_type, branch_id, period_key, last_number)
SELECT
    'customer_payment' AS doc_type,
    0 AS branch_id,
    SUBSTRING(cp.payment_code, 5, 8) AS period_key,
    MAX(CAST(SUBSTRING_INDEX(cp.payment_code, '-', -1) AS UNSIGNED)) AS last_number
FROM customer_payments cp
WHERE cp.payment_code REGEXP '^PAY-[0-9]{8}-[0-9]+$'
GROUP BY SUBSTRING(cp.payment_code, 5, 8)
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(document_sequences.last_number, VALUES(last_number));