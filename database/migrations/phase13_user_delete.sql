-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 13: User Deletion & Granular Permissions
-- ============================================================
USE aaza_hrms;

-- 1. Insert user.delete permission (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('user.delete', 'user', 'Permanently delete inactive users with no dependent records');

-- 2. Grant permission ONLY to the group_admin role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
    FROM permissions
   WHERE code = 'user.delete';
