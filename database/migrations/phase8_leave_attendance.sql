-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 8: Attendance and Leave Management Schema & Permissions
-- NOTE: ADD COLUMN IF NOT EXISTS requires MariaDB 10.0+/XAMPP and will not run on standard MySQL.
-- ============================================================
USE aaza_hrms;

-- 1. ALTER existing tables (Idempotent via IF NOT EXISTS in MariaDB 10.4+)
-- leave_types: add description
ALTER TABLE leave_types
  ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER name;

-- leave_requests: add half_day, decided_by, decided_at, decision_comment, updated_at
ALTER TABLE leave_requests
  ADD COLUMN IF NOT EXISTS half_day TINYINT(1) NOT NULL DEFAULT 0 AFTER total_days,
  ADD COLUMN IF NOT EXISTS decided_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN IF NOT EXISTS decided_at DATETIME NULL AFTER decided_by,
  ADD COLUMN IF NOT EXISTS decision_comment VARCHAR(255) NULL AFTER decided_at,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER decision_comment;

-- Add foreign key constraint for decided_by if not present
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND CONSTRAINT_NAME = 'fk_lr_decided_by');
SET @sql_stmt = IF(@fk_exists = 0,
  'ALTER TABLE leave_requests ADD CONSTRAINT fk_lr_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Seed Permissions
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('leave.view', 'leave', 'View leave requests, balances and reports'),
  ('leave.manage_types', 'leave', 'Manage group and company leave types');

-- 3. Assign Permissions to Roles
-- Group Admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- HR Officer
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Department Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'manager'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.request', 'leave.approve', 'attendance.view');

-- Employee
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'employee'), id
   FROM permissions
  WHERE code IN ('leave.request');

-- 4. Seed Default Group-wide Leave Types (company_id IS NULL)
-- Insert only when no row with that name and company_id IS NULL exists
INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Annual Leave', 'Standard statutory annual leave allowance', 14.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Annual Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Casual Leave', 'Short-term personal and casual leave', 7.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Casual Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Medical Leave', 'Certified sick and medical leave (requires medical certificate)', 7.0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Medical Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Unpaid Leave', 'Leave without pay (unlimited balance)', 0.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Unpaid Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Maternity Leave', 'Maternity leave for female employees (requires medical report)', 84.0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Maternity Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Paternity Leave', 'Paternity leave for male employees', 5.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Paternity Leave' AND company_id IS NULL);
