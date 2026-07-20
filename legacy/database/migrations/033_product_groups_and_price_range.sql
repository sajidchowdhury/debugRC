CREATE TABLE IF NOT EXISTS product_groups (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_product_groups_name (group_name),
  KEY idx_product_groups_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO product_groups (id, group_name, is_active) VALUES (1, 'China', 1);

ALTER TABLE products ADD COLUMN group_id INT(11) NOT NULL DEFAULT 1 AFTER category_id;

UPDATE products SET group_id = 1 WHERE group_id IS NULL OR group_id = 0;

ALTER TABLE products ADD CONSTRAINT fk_products_group_id FOREIGN KEY (group_id) REFERENCES product_groups (id);

ALTER TABLE product_price_history
  ADD COLUMN min_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER product_id,
  ADD COLUMN max_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER min_rate,
  ADD COLUMN default_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER max_rate;

UPDATE product_price_history
SET min_rate = sales_rate, max_rate = sales_rate, default_rate = sales_rate
WHERE sales_rate IS NOT NULL AND sales_rate > 0;

ALTER TABLE product_price_history MODIFY COLUMN sales_rate DECIMAL(12,2) NULL DEFAULT NULL;

ALTER TABLE product_price_history ADD KEY idx_pph_product_effective (product_id, effective_from);
