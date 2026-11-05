-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 9: Group Dashboard, Reports, Analytics & Permissions
-- ============================================================
USE aaza_hrms;

-- 1. Ensure permissions exist
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('report.view', 'reports', 'View dashboard and analytical reports'),
  ('report.export', 'reports', 'Export report data to CSV and print formats'),
  ('dashboard.group', 'reports', 'View group-level multi-company analytics and KPIs');

-- 2. Role Assignments
-- Group Admin (all three)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('report.view', 'report.export', 'dashboard.group');

-- Group HR Manager (all three)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('report.view', 'report.export', 'dashboard.group');

-- Company Administrator (report.view + report.export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('report.view', 'report.export');

-- HR Manager (report.view + report.export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('report.view', 'report.export');

-- HR Officer (report.view only)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('report.view');

-- 3. Idempotent Performance Indexes for High-Traffic Dashboard & Report Queries
-- Index on employees(company_id, joining_date)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_emp_comp_joining');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_emp_comp_joining ON employees(company_id, joining_date)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on candidate_applications(company_id, status, application_date)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'candidate_applications' AND INDEX_NAME = 'idx_app_comp_stat_date');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_app_comp_stat_date ON candidate_applications(company_id, status, application_date)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on leave_requests(company_id, status, start_date)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND INDEX_NAME = 'idx_lr_comp_stat_start');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_lr_comp_stat_start ON leave_requests(company_id, status, start_date)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on job_offers(company_id, status) if missing
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_offers' AND INDEX_NAME = 'idx_jo_comp_stat');
SET @exist_existing := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_offers' AND INDEX_NAME = 'idx_jo_company_status');
SET @sql := IF(@exist = 0 AND @exist_existing = 0, 'CREATE INDEX idx_jo_comp_stat ON job_offers(company_id, status)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on interviews(company_id, scheduled_at) if missing
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND INDEX_NAME = 'idx_int_comp_sched');
SET @exist_existing := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interviews' AND INDEX_NAME = 'idx_int_company_date');
SET @sql := IF(@exist = 0 AND @exist_existing = 0, 'CREATE INDEX idx_int_comp_sched ON interviews(company_id, scheduled_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on attendance(company_id, att_date) if missing
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_att_comp_date');
SET @exist_existing := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_att_company_date');
SET @sql := IF(@exist = 0 AND @exist_existing = 0, 'CREATE INDEX idx_att_comp_date ON attendance(company_id, att_date)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
-- 9. database/phase9_reports.sql (this file)
-- ============================================================

