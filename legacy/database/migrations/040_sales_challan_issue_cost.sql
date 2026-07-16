-- W1: Persist per-line issue cost at challan finalize for accurate COGS/inventory restore on reversal.

CREATE TABLE IF NOT EXISTS `sales_challan_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `sales_challan_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `warehouse_id` INT(11) NOT NULL,
    `qty` DECIMAL(15,3) NOT NULL,
    `issue_rate` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `cogs_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sci_challan` (`sales_challan_id`),
    KEY `idx_sci_product` (`product_id`),
    KEY `idx_sci_wh` (`warehouse_id`),
    CONSTRAINT `fk_sci_challan` FOREIGN KEY (`sales_challan_id`)
        REFERENCES `sales_challans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from challan stock OUT rows (best available historical rate).
INSERT INTO `sales_challan_items` (`sales_challan_id`, `product_id`, `warehouse_id`, `qty`, `issue_rate`, `cogs_amount`)
SELECT
    st.reference_id,
    st.product_id,
    st.warehouse_id,
    ABS(st.qty),
    COALESCE(NULLIF(st.rate, 0), 0),
    ROUND(ABS(st.qty) * COALESCE(NULLIF(st.rate, 0), 0), 2)
FROM `stock_transactions` st
INNER JOIN `sales_challans` sc ON sc.id = st.reference_id
WHERE st.reference_type = 'sales_challan'
  AND st.qty < -0.0001
  AND NOT EXISTS (
      SELECT 1 FROM `sales_challan_items` sci
      WHERE sci.sales_challan_id = st.reference_id
        AND sci.product_id = st.product_id
        AND sci.warehouse_id = st.warehouse_id
  );
