-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 19: Company & Group-Wide Public Holidays
-- ============================================================
USE aaza_hrms;

-- 1. Create company_holidays table (idempotent)
CREATE TABLE IF NOT EXISTS company_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NULL COMMENT 'NULL for group-wide holidays applying to all subsidiaries',
    holiday_date DATE NOT NULL,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_holiday_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_holiday_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_company_holiday_date (company_id, holiday_date),
    INDEX idx_holiday_date_active (holiday_date, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Register leave.manage permission (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('leave.manage', 'leave', 'Configure and manage company and group public holidays');

-- 3. Grant leave.manage to administrators and HR managers
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id
    FROM roles r
    CROSS JOIN permissions p
   WHERE p.code = 'leave.manage'
     AND r.name IN ('group_admin', 'group_hr', 'company_admin', 'hr_manager');

