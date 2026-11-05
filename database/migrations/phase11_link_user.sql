-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 11: Link Login Account to Employee Unique Index & Permissions
-- ============================================================
USE aaza_hrms;

-- 1. Ensure employee.update permission exists and is granted to roles that have employee.edit
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('employee.update', 'employee', 'Update and link employee records');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT rp.role_id, (SELECT id FROM permissions WHERE code = 'employee.update')
   FROM role_permissions rp
   JOIN permissions p ON rp.permission_id = p.id
  WHERE p.code = 'employee.edit';

-- 2. Check for and resolve any duplicate user_id assignments before creating unique index.
-- If duplicate non-null user_id exists, retain the lowest employee id and reset duplicates to NULL.
UPDATE employees e
JOIN (
    SELECT user_id, MIN(id) AS keep_id
      FROM employees
     WHERE user_id IS NOT NULL
     GROUP BY user_id
    HAVING COUNT(*) > 1
) dup ON e.user_id = dup.user_id AND e.id != dup.keep_id
SET e.user_id = NULL;

-- 3. Idempotently add UNIQUE index on employees(user_id) (allows NULL)
SET @exist := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'employees'
       AND INDEX_NAME = 'uq_employees_user_id'
);
SET @sql := IF(@exist = 0, 'CREATE UNIQUE INDEX uq_employees_user_id ON employees(user_id)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
