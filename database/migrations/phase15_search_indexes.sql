-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 15: Unified Global Search Performance Indexes
-- ============================================================
USE aaza_hrms;

-- 1. Index on job_vacancies(title) for vacancy searches
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_vacancies' AND INDEX_NAME = 'idx_vac_title');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_vac_title ON job_vacancies(title)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Composite index on employees(first_name, last_name)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_emp_first_last');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_emp_first_last ON employees(first_name, last_name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Dedicated index on employees(employee_code)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND INDEX_NAME = 'idx_emp_code');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_emp_code ON employees(employee_code)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Composite index on candidates(first_name, last_name)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'candidates' AND INDEX_NAME = 'idx_cand_first_last');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_cand_first_last ON candidates(first_name, last_name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Index on users(full_name) for user directory searches
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_full_name');
SET @sql := IF(@exist = 0, 'CREATE INDEX idx_users_full_name ON users(full_name)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

