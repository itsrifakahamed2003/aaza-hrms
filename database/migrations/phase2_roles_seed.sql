-- Phase 2 add-on: give the group_hr and company_admin roles their permissions.
-- (schema.sql created these two roles with no permissions.)
-- Safe to run more than once. Run after schema.sql.
USE aaza_hrms;

-- Group HR Manager: HR work across companies, plus group dashboard and cross-company candidates.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id FROM permissions
 WHERE code NOT IN ('company.manage', 'role.manage', 'user.manage', 'audit.view');

-- Company Administrator: everything inside one company, but nothing group-wide.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id FROM permissions
 WHERE code NOT IN ('company.manage', 'role.manage', 'dashboard.group', 'candidate.cross_company');
