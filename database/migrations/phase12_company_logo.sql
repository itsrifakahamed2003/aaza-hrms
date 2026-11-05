-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: Company Logo Management & Granular Permissions
-- ============================================================
USE aaza_hrms;

-- 1. Ensure logo_path column exists in companies table
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'logo_path');
SET @alter_query = IF(@col_exists = 0, 'ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER address', 'SELECT 1');
PREPARE stmt FROM @alter_query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Insert company.logo and company.delete permissions (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('company.logo',   'company', 'Upload, update or remove company logo and branding'),
  ('company.delete', 'company', 'Permanently delete deactivated companies with no records');

-- 3. Grant permissions ONLY to the group_admin role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
    FROM permissions
   WHERE code IN ('company.logo', 'company.delete');
