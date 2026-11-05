-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 18: Employee Performance Management & Goals Schema
-- ============================================================
USE aaza_hrms;

-- 1. Performance Reviews Table
CREATE TABLE IF NOT EXISTS performance_reviews (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id            INT UNSIGNED NOT NULL,
  employee_id           INT UNSIGNED NOT NULL,
  reviewer_user_id      INT UNSIGNED NOT NULL,
  period_label          VARCHAR(100) NOT NULL,
  period_start          DATE NOT NULL,
  period_end            DATE NOT NULL,
  review_date           DATE NOT NULL,
  overall_rating        TINYINT UNSIGNED NOT NULL,
  manager_feedback      TEXT NULL,
  improvement_notes     TEXT NULL,
  training_requirements TEXT NULL,
  status                ENUM('draft','submitted','acknowledged') NOT NULL DEFAULT 'draft',
  acknowledged_at       TIMESTAMP NULL DEFAULT NULL,
  reopened_at           TIMESTAMP NULL DEFAULT NULL,
  reopen_reason         TEXT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_perf_company (company_id),
  KEY idx_perf_employee (employee_id),
  KEY idx_perf_reviewer (reviewer_user_id),
  KEY idx_perf_period (period_start, period_end),
  KEY idx_perf_period_lbl (period_label),
  KEY idx_perf_status (status),
  CONSTRAINT fk_perf_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_perf_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_perf_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 2. Performance Goals Table
CREATE TABLE IF NOT EXISTS performance_goals (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id             INT UNSIGNED NULL,
  employee_id           INT UNSIGNED NOT NULL,
  company_id            INT UNSIGNED NOT NULL,
  title                 VARCHAR(200) NOT NULL,
  description           TEXT NULL,
  target_date           DATE NULL,
  progress              TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status                ENUM('open','achieved','missed') NOT NULL DEFAULT 'open',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pgoal_review (review_id),
  KEY idx_pgoal_employee (employee_id),
  KEY idx_pgoal_company (company_id),
  KEY idx_pgoal_status (status),
  CONSTRAINT fk_pgoal_review FOREIGN KEY (review_id) REFERENCES performance_reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgoal_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgoal_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Register Permissions (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('performance.view', 'performance', 'View company-wide employee performance reviews and goals'),
  ('performance.manage', 'performance', 'Create, evaluate, edit, submit, and reopen performance reviews and goals'),
  ('performance.view_own', 'performance', 'View own submitted performance reviews and goals');

-- 4. Assign Permissions to Roles (idempotent)
-- performance.view & performance.manage to group_admin, group_hr, company_admin, hr_manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id
   FROM roles r
   CROSS JOIN permissions p
  WHERE r.name IN ('group_admin', 'group_hr', 'company_admin', 'hr_manager')
    AND p.code IN ('performance.view', 'performance.manage');

-- performance.view_own to employee, manager, hr_manager, company_admin, group_admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id
   FROM roles r
   CROSS JOIN permissions p
  WHERE r.name IN ('employee', 'manager', 'department_head', 'hr_manager', 'company_admin', 'group_admin')
    AND p.code = 'performance.view_own';
