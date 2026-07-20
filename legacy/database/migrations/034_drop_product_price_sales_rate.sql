UPDATE product_price_history
SET default_rate = sales_rate, min_rate = sales_rate, max_rate = sales_rate
WHERE sales_rate IS NOT NULL
  AND (default_rate = 0 OR min_rate = 0 OR max_rate = 0);

ALTER TABLE product_price_history DROP COLUMN sales_rate;
