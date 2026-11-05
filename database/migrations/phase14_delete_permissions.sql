-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 14: Master Organizational & Document Deletion Permissions
-- ============================================================
USE aaza_hrms;

-- 1. Insert granular deletion permissions (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('department.delete', 'org',         'Permanently delete inactive departments with no dependent records'),
  ('designation.delete','org',         'Permanently delete inactive designations with no dependent records'),
  ('leave_type.delete', 'leave',       'Permanently delete inactive leave types with no dependent records'),
  ('role.delete',       'role',        'Permanently delete custom non-system roles with no assigned users'),
  ('document.delete',   'documents',   'Permanently delete uploaded employee or candidate documents'),
  ('candidate.delete',  'recruitment', 'Permanently delete candidate profile with no active applications'),
  ('vacancy.delete',    'recruitment', 'Permanently delete closed/cancelled vacancies with no active candidates'),
  ('interview.delete',  'recruitment', 'Permanently delete cancelled or completed interviews'),
  ('offer.delete',      'recruitment', 'Permanently delete declined or withdrawn offers'),
  ('employee.delete',   'employees',   'Permanently delete terminated employee records and personal data');

-- 2. Grant permissions ONLY to the group_admin role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
    FROM permissions
   WHERE code IN (
     'department.delete', 'designation.delete', 'leave_type.delete', 'role.delete', 'document.delete',
     'candidate.delete', 'vacancy.delete', 'interview.delete', 'offer.delete', 'employee.delete'
   );
