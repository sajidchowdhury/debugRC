-- =============================================================================
-- RC_ERP_v2 — MySQL Archive Initialization Script
-- =============================================================================
-- Runs automatically when the MySQL container starts for the first time.
-- Creates:
--   1. A read-only user (archive_reader) with SELECT-only privileges
--   2. A sample legacy table structure (empty — for testing the archive connection)
--
-- This database is READ-ONLY for the Laravel Anti-Corruption Layer (Phase 12).
-- The Laravel ArchiveService connects here to search historical data.
-- =============================================================================

-- Create a sample legacy table (mirrors the legacy MySQL schema structure)
-- This is empty — in production, this would be populated by pgloader ETL.

CREATE TABLE IF NOT EXISTS legacy_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(50) NOT NULL UNIQUE,
    customer_name VARCHAR(200) NOT NULL,
    shop_name VARCHAR(200),
    phone VARCHAR(30),
    mobile VARCHAR(30),
    email VARCHAR(100),
    address TEXT,
    branch_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    branch_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    total_amount DECIMAL(14,2) DEFAULT 0,
    paid_amount DECIMAL(14,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(200) NOT NULL,
    unit VARCHAR(20) DEFAULT 'Pcs',
    sales_rate DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grant SELECT-only to the archive_reader user
GRANT SELECT ON rcerp_legacy.* TO 'archive_reader'@'%';

-- Insert sample data for testing (optional)
INSERT INTO legacy_customers (customer_code, customer_name, shop_name, phone, branch_id) VALUES
('CUS-OLD-001', 'Legacy Customer One', 'Old Shop #1', '01711111111', 1),
('CUS-OLD-002', 'Legacy Customer Two', 'Old Shop #2', '01722222222', 1),
('CUS-OLD-003', 'Legacy Customer Three', 'Old Shop #3', '01733333333', 2);

INSERT INTO legacy_products (product_code, product_name, unit, sales_rate) VALUES
('PROD-OLD-001', 'Legacy Product A', 'Pcs', 150.00),
('PROD-OLD-002', 'Legacy Product B', 'Carton', 1200.00),
('PROD-OLD-003', 'Legacy Product C', 'KG', 85.50);

INSERT INTO legacy_invoices (invoice_no, customer_id, branch_id, invoice_date, total_amount, status) VALUES
('INV-OLD-001', 1, 1, '2023-12-15', 5500.00, 'confirmed'),
('INV-OLD-002', 2, 1, '2023-12-20', 3200.00, 'confirmed'),
('INV-OLD-003', 3, 2, '2024-01-05', 8700.00, 'confirmed');
