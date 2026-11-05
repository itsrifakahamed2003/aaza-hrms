-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 6: Interview Permissions Seed
-- ============================================================
USE aaza_hrms;

-- 1. Insert interview permissions
INSERT IGNORE INTO permissions (code, module, description) VALUES
 ('interview.view', 'recruitment', 'View interviews'),
 ('interview.manage', 'recruitment', 'Schedule, reschedule, cancel, and manage interviews'),
 ('interview.view_evaluations', 'recruitment', 'View confidential interview evaluations');

-- 2. Assign to Group Admin (all permissions)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 3. Assign to Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 4. Assign to Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 5. Assign to HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 6. Assign to HR Officer
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');
