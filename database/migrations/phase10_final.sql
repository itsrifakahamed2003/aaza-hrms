-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 10: Audit Log Viewer, Security Hardening & Rate Limiting
-- ============================================================
USE aaza_hrms;

-- 1. Ensure permissions exist
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('audit.view', 'audit', 'View activity audit logs'),
  ('audit.export', 'audit', 'Export activity audit logs to CSV');

-- 2. Role Assignments
-- Group Admin (audit.view + audit.export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('audit.view', 'audit.export');

-- Group HR Manager (audit.view + audit.export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('audit.view', 'audit.export');

-- Company Administrator (audit.view only for their own company)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('audit.view');

-- Ensure hr_manager and other roles do NOT have audit.view or audit.export
DELETE rp FROM role_permissions rp
JOIN roles r ON rp.role_id = r.id
JOIN permissions p ON rp.permission_id = p.id
WHERE p.code IN ('audit.view', 'audit.export')
  AND r.name NOT IN ('group_admin', 'group_hr', 'company_admin');

-- 3. Idempotent Performance Indexes for activity_logs
-- Index on activity_logs(company_id, created_at)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_log_comp_created');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_log_comp_created ON activity_logs(company_id, created_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on activity_logs(user_id, created_at)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_log_user_created');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_log_user_created ON activity_logs(user_id, created_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on activity_logs(action)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_log_action');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_log_action ON activity_logs(action)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Idempotent column must_change_password on users table
SET @exist_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password');
SET @sql_col := IF(@exist_col = 0, 'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active', 'DO 0');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- 5. Rate limiting table for IP requests (login, public form)
CREATE TABLE IF NOT EXISTS rate_limits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  action VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rl_ip_action_created (ip_address, action, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- SQL IMPORT ORDER:
-- 1. database/schema.sql
-- 2. database/phase2_departments.sql
-- 3. database/phase3_documents.sql
-- 4. database/phase4_recruitment.sql
-- 5. database/phase5_candidates.sql
-- 6. database/phase6_permissions.sql
-- 7. database/phase7_permissions.sql
-- 8. database/phase8_leave_attendance.sql
-- 9. database/phase9_reports.sql
-- 10. database/phase10_final.sql (this file)
-- ============================================================
