-- Phase 4: Auth schema hardening (deleted_at cleanup + foreign keys).

-- 4.7 Normalize zero-date deleted_at sentinels to NULL
UPDATE users
SET deleted_at = NULL
WHERE deleted_at IN ('0000-00-00 00:00:00', '0000-00-00');

UPDATE employees
SET deleted_at = NULL
WHERE deleted_at IN ('0000-00-00 00:00:00', '0000-00-00');

-- 4.4–4.6 Remove orphan rows before adding constraints
DELETE ump FROM user_menu_permissions ump
LEFT JOIN users u ON u.id = ump.user_id
WHERE u.id IS NULL;

DELETE ump FROM user_menu_permissions ump
LEFT JOIN menus m ON m.id = ump.menu_id
WHERE m.id IS NULL;

DELETE u FROM users u
LEFT JOIN employees e ON e.id = u.employee_id
WHERE e.id IS NULL;

UPDATE employees e
LEFT JOIN branches b ON b.id = e.branch_id
SET e.branch_id = (SELECT MIN(b2.id) FROM branches b2)
WHERE b.id IS NULL
  AND EXISTS (SELECT 1 FROM branches b3 LIMIT 1);

ALTER TABLE employees
  ADD CONSTRAINT fk_employees_branch
  FOREIGN KEY (branch_id) REFERENCES branches (id);

ALTER TABLE users
  ADD CONSTRAINT fk_users_employee
  FOREIGN KEY (employee_id) REFERENCES employees (id);

ALTER TABLE user_menu_permissions
  ADD CONSTRAINT fk_ump_user
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE user_menu_permissions
  ADD CONSTRAINT fk_ump_menu
  FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE;
