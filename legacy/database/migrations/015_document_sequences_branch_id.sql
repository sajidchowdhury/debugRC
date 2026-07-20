-- Add branch_id to document_sequences (legacy installs created table without it).
ALTER TABLE `document_sequences`
    ADD COLUMN `branch_id` INT(11) NOT NULL DEFAULT 0 AFTER `doc_type`;

ALTER TABLE `document_sequences`
    DROP INDEX `uk_doc_sequence`;

ALTER TABLE `document_sequences`
    ADD UNIQUE KEY `uk_doc_sequence` (`doc_type`, `branch_id`, `period_key`);