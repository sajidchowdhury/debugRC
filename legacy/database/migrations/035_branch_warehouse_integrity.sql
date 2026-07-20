-- Phase 2: Branch & warehouse master data integrity (unique codes, indexes, FK)

-- Resolve duplicate branch codes before unique constraint
UPDATE branches b1
INNER JOIN branches b2
    ON b1.branch_code = b2.branch_code AND b1.id > b2.id
SET b1.branch_code = CONCAT(LEFT(b1.branch_code, 12), '-', b1.id);

-- Resolve duplicate warehouse codes before unique constraint
UPDATE warehouses w1
INNER JOIN warehouses w2
    ON w1.warehouse_code = w2.warehouse_code AND w1.id > w2.id
SET w1.warehouse_code = CONCAT(LEFT(w1.warehouse_code, 12), '-', w1.id);

-- Orphan warehouse branch_id → first branch (if any)
UPDATE warehouses w
LEFT JOIN branches b ON b.id = w.branch_id
SET w.branch_id = (SELECT MIN(b2.id) FROM branches b2)
WHERE w.branch_id IS NOT NULL
  AND b.id IS NULL
  AND EXISTS (SELECT 1 FROM branches b3 LIMIT 1);

ALTER TABLE branches
    ADD UNIQUE KEY uk_branches_code (branch_code),
    ADD KEY idx_branches_active (is_active);

ALTER TABLE warehouses
    ADD UNIQUE KEY uk_warehouses_code (warehouse_code),
    ADD KEY idx_warehouses_active (is_active),
    ADD KEY idx_warehouses_branch (branch_id);

ALTER TABLE warehouses
    ADD CONSTRAINT fk_warehouses_branch
    FOREIGN KEY (branch_id) REFERENCES branches (id);
