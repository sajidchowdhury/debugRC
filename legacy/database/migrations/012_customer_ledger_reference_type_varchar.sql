-- Phase 2: Flexible reference_type (avoids ENUM drift when adding new document types).
ALTER TABLE `customer_ledger`
    MODIFY COLUMN `reference_type` VARCHAR(30) NOT NULL;