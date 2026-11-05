-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 7: Job Offers & Salary Permissions Seed
-- ============================================================
USE aaza_hrms;

-- 1. Ensure permissions exist
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('offer.view', 'recruitment', 'View job offers'),
  ('salary.view', 'employee', 'View salary information');

-- 2. Assign to Group Admin (all permissions)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 3. Assign to Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 4. Assign to Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 5. Assign to HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 6. Assign to HR Officer (offer.view only)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('offer.view');
