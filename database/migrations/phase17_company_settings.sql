-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 17: Granular Company Settings Management
-- ============================================================
USE aaza_hrms;

-- 1. Ensure company_settings table exists
CREATE TABLE IF NOT EXISTS company_settings (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_setting (company_id, setting_key),
  CONSTRAINT fk_cs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Insert company.settings permission (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('company.settings', 'company', 'Manage company-specific settings, attendance policies and operational preferences');

-- 3. Grant company.settings to group_admin and company_admin roles
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id
   FROM roles r
   CROSS JOIN permissions p
  WHERE r.name IN ('group_admin', 'company_admin')
    AND p.code = 'company.settings';

