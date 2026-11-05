-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 1: Database Schema (MySQL 5.7+/MariaDB 10.4+, InnoDB, utf8mb4)
-- Import via phpMyAdmin or: mysql -u root < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS aaza_hrms
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE aaza_hrms;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. COMPANIES
-- ------------------------------------------------------------
CREATE TABLE companies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. AZT, AZL, AZM
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NULL,
  phone         VARCHAR(30)  NULL,
  address       TEXT NULL,
  logo_path     VARCHAR(255) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE company_settings (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_setting (company_id, setting_key),
  CONSTRAINT fk_cs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. USERS, ROLES, PERMISSIONS (RBAC)
-- ------------------------------------------------------------
CREATE TABLE roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(50) NOT NULL UNIQUE,           -- slug: group_admin, hr_manager ...
  label         VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NULL,
  is_system     TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(80) NOT NULL UNIQUE,           -- e.g. employee.view
  module        VARCHAR(50) NOT NULL,
  description   VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(60)  NOT NULL UNIQUE,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,               -- password_hash() output
  full_name       VARCHAR(150) NOT NULL,
  is_group_admin  TINYINT(1) NOT NULL DEFAULT 0,       -- group-level, sees all companies
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login_at   DATETIME NULL,
  last_login_ip   VARCHAR(45) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A user can be assigned to one or more companies, with a role per company.
CREATE TABLE company_user_assignments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  company_id    INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  assigned_by   INT UNSIGNED NULL,
  assigned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_company_role (user_id, company_id, role_id),
  KEY idx_cua_company (company_id),
  CONSTRAINT fk_cua_user    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
  CONSTRAINT fk_cua_company FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_cua_role    FOREIGN KEY (role_id)     REFERENCES roles(id),
  CONSTRAINT fk_cua_by      FOREIGN KEY (assigned_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. DEPARTMENTS & DESIGNATIONS (per company)
-- ------------------------------------------------------------
CREATE TABLE departments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dept (company_id, name),
  CONSTRAINT fk_dept_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE designations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  title         VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_desig (company_id, title),
  CONSTRAINT fk_desig_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. CANDIDATES (central pool, shared across the group)
-- ------------------------------------------------------------
CREATE TABLE candidates (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_code    VARCHAR(20) NOT NULL UNIQUE,       -- e.g. CAN-000123
  first_name        VARCHAR(80) NOT NULL,
  last_name         VARCHAR(80) NOT NULL,
  email             VARCHAR(150) NULL,
  phone             VARCHAR(30)  NULL,
  nic_passport      VARCHAR(30)  NULL,                 -- helps link duplicate profiles
  dob               DATE NULL,
  gender            ENUM('male','female','other') NULL,
  address           TEXT NULL,
  qualifications    TEXT NULL,
  experience_years  DECIMAL(4,1) NULL,
  skills            TEXT NULL,
  source            VARCHAR(60) NULL,                  -- referral, LinkedIn, walk-in...
  consent_given     TINYINT(1) NOT NULL DEFAULT 0,     -- privacy consent to keep data
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cand_name  (last_name, first_name),
  KEY idx_cand_email (email),
  KEY idx_cand_phone (phone),
  KEY idx_cand_nic   (nic_passport),
  CONSTRAINT fk_cand_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE candidate_documents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id  INT UNSIGNED NOT NULL,
  doc_type      ENUM('cv','certificate','id_document','other') NOT NULL DEFAULT 'cv',
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,                 -- random name, stored outside web root
  mime_type     VARCHAR(100) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cd_candidate (candidate_id),
  CONSTRAINT fk_cd_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_cd_by        FOREIGN KEY (uploaded_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. EMPLOYEES
-- ------------------------------------------------------------
CREATE TABLE employees (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code         VARCHAR(30) NOT NULL,          -- e.g. AZT-0001
  company_id            INT UNSIGNED NOT NULL,
  user_id               INT UNSIGNED NULL,             -- optional login account
  candidate_id          INT UNSIGNED NULL,             -- origin if hired via recruitment
  first_name            VARCHAR(80) NOT NULL,
  last_name             VARCHAR(80) NOT NULL,
  email                 VARCHAR(150) NULL,
  phone                 VARCHAR(30)  NULL,
  nic_passport          VARCHAR(30)  NULL,
  dob                   DATE NULL,
  gender                ENUM('male','female','other') NULL,
  address               TEXT NULL,
  department_id         INT UNSIGNED NULL,
  designation_id        INT UNSIGNED NULL,
  reporting_manager_id  INT UNSIGNED NULL,
  joining_date          DATE NOT NULL,
  employment_type       ENUM('full_time','part_time','contract','intern','probation') NOT NULL DEFAULT 'full_time',
  employment_status     ENUM('active','on_leave','transferred','resigned','terminated','retired') NOT NULL DEFAULT 'active',
  basic_salary          DECIMAL(12,2) NULL,            -- restricted by permission salary.view
  photo_path            VARCHAR(255) NULL,
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_code (company_id, employee_code),
  KEY idx_emp_company_status (company_id, employment_status),
  KEY idx_emp_name  (last_name, first_name),
  KEY idx_emp_email (email),
  KEY idx_emp_phone (phone),
  KEY idx_emp_dept  (department_id),
  KEY idx_emp_desig (designation_id),
  CONSTRAINT fk_emp_company  FOREIGN KEY (company_id)           REFERENCES companies(id),
  CONSTRAINT fk_emp_user     FOREIGN KEY (user_id)              REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_cand     FOREIGN KEY (candidate_id)         REFERENCES candidates(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dept     FOREIGN KEY (department_id)        REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_desig    FOREIGN KEY (designation_id)       REFERENCES designations(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_manager  FOREIGN KEY (reporting_manager_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_by       FOREIGN KEY (created_by)           REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employee_documents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  company_id    INT UNSIGNED NOT NULL,
  doc_type      ENUM('cv','appointment_letter','agreement','education_certificate','id_document','training_certificate','other') NOT NULL,
  title         VARCHAR(150) NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  expiry_date   DATE NULL,                             -- for document expiry reminders
  is_confidential TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ed_employee (employee_id),
  KEY idx_ed_expiry (expiry_date),
  CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_ed_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_ed_by       FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Full history of every position/company the employee has held
CREATE TABLE employment_history (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  company_id      INT UNSIGNED NOT NULL,
  department_id   INT UNSIGNED NULL,
  designation_id  INT UNSIGNED NULL,
  employment_type VARCHAR(20) NULL,
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  change_type     ENUM('joined','promotion','transfer_in','transfer_out','department_change','status_change','termination','resignation') NOT NULL,
  remarks         VARCHAR(255) NULL,
  recorded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eh_employee (employee_id, start_date),
  CONSTRAINT fk_eh_employee FOREIGN KEY (employee_id)    REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_eh_company  FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_eh_dept     FOREIGN KEY (department_id)  REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_eh_desig    FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL,
  CONSTRAINT fk_eh_by       FOREIGN KEY (recorded_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Company-to-company transfer (distinct from termination / new employment)
CREATE TABLE employee_transfers (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT UNSIGNED NOT NULL,
  from_company_id     INT UNSIGNED NOT NULL,
  to_company_id       INT UNSIGNED NOT NULL,
  from_department_id  INT UNSIGNED NULL,
  to_department_id    INT UNSIGNED NULL,
  from_designation_id INT UNSIGNED NULL,
  to_designation_id   INT UNSIGNED NULL,
  transfer_date       DATE NOT NULL,
  reason              VARCHAR(255) NULL,
  status              ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  requested_by        INT UNSIGNED NULL,
  approved_by         INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_et_employee (employee_id),
  CONSTRAINT fk_et_employee FOREIGN KEY (employee_id)     REFERENCES employees(id),
  CONSTRAINT fk_et_from     FOREIGN KEY (from_company_id) REFERENCES companies(id),
  CONSTRAINT fk_et_to       FOREIGN KEY (to_company_id)   REFERENCES companies(id),
  CONSTRAINT fk_et_req      FOREIGN KEY (requested_by)    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_et_app      FOREIGN KEY (approved_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. RECRUITMENT
-- ------------------------------------------------------------
CREATE TABLE job_vacancies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      INT UNSIGNED NOT NULL,
  vacancy_code    VARCHAR(30) NOT NULL,
  title           VARCHAR(150) NOT NULL,
  department_id   INT UNSIGNED NULL,
  designation_id  INT UNSIGNED NULL,
  positions_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  employment_type ENUM('full_time','part_time','contract','intern','probation') NOT NULL DEFAULT 'full_time',
  description     TEXT NULL,
  requirements    TEXT NULL,
  closing_date    DATE NULL,
  status          ENUM('draft','open','on_hold','closed','cancelled') NOT NULL DEFAULT 'draft',
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vac_code (company_id, vacancy_code),
  KEY idx_vac_status (company_id, status),
  KEY idx_vac_closing (closing_date),
  CONSTRAINT fk_vac_company FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_vac_dept    FOREIGN KEY (department_id)  REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_vac_desig   FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL,
  CONSTRAINT fk_vac_by      FOREIGN KEY (created_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One candidate profile -> many applications (each with own company, position, history)
CREATE TABLE candidate_applications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id    INT UNSIGNED NOT NULL,
  company_id      INT UNSIGNED NOT NULL,
  vacancy_id      INT UNSIGNED NOT NULL,
  application_date DATE NOT NULL,
  status          ENUM('applied','under_review','shortlisted','interview_scheduled','interviewed',
                       'selected','on_hold','rejected','offered','offer_accepted','offer_declined',
                       'hired','withdrawn') NOT NULL DEFAULT 'applied',
  notes           TEXT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cand_vacancy (candidate_id, vacancy_id),   -- no duplicate application to same vacancy
  KEY idx_app_company_status (company_id, status),
  KEY idx_app_vacancy (vacancy_id),
  CONSTRAINT fk_app_cand    FOREIGN KEY (candidate_id) REFERENCES candidates(id),
  CONSTRAINT fk_app_company FOREIGN KEY (company_id)   REFERENCES companies(id),
  CONSTRAINT fk_app_vacancy FOREIGN KEY (vacancy_id)   REFERENCES job_vacancies(id),
  CONSTRAINT fk_app_by      FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE candidate_status_history (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  old_status     VARCHAR(30) NULL,
  new_status     VARCHAR(30) NOT NULL,
  remarks        VARCHAR(255) NULL,
  changed_by     INT UNSIGNED NULL,
  changed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_csh_app (application_id),
  CONSTRAINT fk_csh_app FOREIGN KEY (application_id) REFERENCES candidate_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_csh_by  FOREIGN KEY (changed_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. INTERVIEWS
-- ------------------------------------------------------------
CREATE TABLE interviews (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  company_id      INT UNSIGNED NOT NULL,
  round_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  interview_type  ENUM('hr','technical','final','other') NOT NULL DEFAULT 'hr',
  scheduled_at    DATETIME NOT NULL,
  duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  mode            ENUM('in_person','online','phone') NOT NULL DEFAULT 'in_person',
  location        VARCHAR(255) NULL,                   -- room or meeting link
  status          ENUM('scheduled','completed','cancelled','no_show','rescheduled') NOT NULL DEFAULT 'scheduled',
  notes           TEXT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_int_company_date (company_id, scheduled_at),
  KEY idx_int_app (application_id),
  CONSTRAINT fk_int_app     FOREIGN KEY (application_id) REFERENCES candidate_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_int_company FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_int_by      FOREIGN KEY (created_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE interviewers (
  interview_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (interview_id, user_id),
  CONSTRAINT fk_iv_interview FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_iv_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE interview_evaluations (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  interview_id        INT UNSIGNED NOT NULL,
  evaluator_id        INT UNSIGNED NOT NULL,
  technical_score     TINYINT UNSIGNED NULL,           -- 1-10
  communication_score TINYINT UNSIGNED NULL,
  attitude_score      TINYINT UNSIGNED NULL,
  overall_score       TINYINT UNSIGNED NULL,
  strengths           TEXT NULL,
  weaknesses          TEXT NULL,
  comments            TEXT NULL,
  recommendation      ENUM('select','reject','hold','next_round') NOT NULL,
  evaluated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eval (interview_id, evaluator_id),
  CONSTRAINT fk_ie_interview FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_ie_user      FOREIGN KEY (evaluator_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. JOB OFFERS
-- ------------------------------------------------------------
CREATE TABLE job_offers (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL,
  company_id            INT UNSIGNED NOT NULL,
  designation_id        INT UNSIGNED NULL,
  department_id         INT UNSIGNED NULL,
  offered_salary        DECIMAL(12,2) NULL,            -- restricted by permission salary.view
  employment_type       ENUM('full_time','part_time','contract','intern','probation') NOT NULL DEFAULT 'full_time',
  proposed_join_date    DATE NULL,
  offer_date            DATE NOT NULL,
  expiry_date           DATE NULL,
  status                ENUM('draft','sent','accepted','declined','expired','withdrawn') NOT NULL DEFAULT 'draft',
  responded_at          DATETIME NULL,
  converted_employee_id INT UNSIGNED NULL,             -- set when candidate becomes employee
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jo_company_status (company_id, status),
  CONSTRAINT fk_jo_app      FOREIGN KEY (application_id)        REFERENCES candidate_applications(id),
  CONSTRAINT fk_jo_company  FOREIGN KEY (company_id)            REFERENCES companies(id),
  CONSTRAINT fk_jo_desig    FOREIGN KEY (designation_id)        REFERENCES designations(id) ON DELETE SET NULL,
  CONSTRAINT fk_jo_dept     FOREIGN KEY (department_id)         REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_jo_emp      FOREIGN KEY (converted_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_jo_by       FOREIGN KEY (created_by)            REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. ATTENDANCE & LEAVE
-- ------------------------------------------------------------
CREATE TABLE attendance (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  company_id    INT UNSIGNED NOT NULL,
  att_date      DATE NOT NULL,
  check_in      TIME NULL,
  check_out     TIME NULL,
  status        ENUM('present','absent','late','half_day','leave') NOT NULL DEFAULT 'present',
  remarks       VARCHAR(255) NULL,
  recorded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (employee_id, att_date),
  KEY idx_att_company_date (company_id, att_date),
  CONSTRAINT fk_att_emp     FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_company FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_att_by      FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE leave_types (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NULL,                     -- NULL = group-wide default
  name          VARCHAR(60) NOT NULL,
  days_per_year DECIMAL(5,1) NOT NULL DEFAULT 0,
  requires_document TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_lt_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  company_id      INT UNSIGNED NOT NULL,
  leave_type_id   INT UNSIGNED NOT NULL,
  start_date      DATE NOT NULL,
  end_date        DATE NOT NULL,
  total_days      DECIMAL(5,1) NOT NULL,
  reason          TEXT NULL,
  document_path   VARCHAR(255) NULL,
  status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  requested_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lr_company_status (company_id, status),
  KEY idx_lr_employee (employee_id, start_date),
  CONSTRAINT fk_lr_emp     FOREIGN KEY (employee_id)   REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_company FOREIGN KEY (company_id)    REFERENCES companies(id),
  CONSTRAINT fk_lr_type    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB;

CREATE TABLE leave_approvals (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leave_request_id INT UNSIGNED NOT NULL,
  approver_id      INT UNSIGNED NOT NULL,
  decision         ENUM('approved','rejected') NOT NULL,
  comments         VARCHAR(255) NULL,
  decided_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_la_req  FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_la_user FOREIGN KEY (approver_id)      REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. NOTIFICATIONS, SETTINGS, AUDIT
-- ------------------------------------------------------------
CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  company_id  INT UNSIGNED NULL,
  type        VARCHAR(50) NOT NULL,                    -- interview_upcoming, leave_pending ...
  message     VARCHAR(255) NOT NULL,
  link_url    VARCHAR(255) NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  company_id   INT UNSIGNED NULL,
  action       VARCHAR(80) NOT NULL,                   -- candidate.create, leave.approve ...
  entity_type  VARCHAR(50) NULL,
  entity_id    INT UNSIGNED NULL,
  details      TEXT NULL,                              -- JSON of old/new values
  ip_address   VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_user (user_id),
  KEY idx_log_company (company_id),
  KEY idx_log_entity (entity_type, entity_id),
  KEY idx_log_created (created_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO roles (name, label, description, is_system) VALUES
 ('group_admin',  'Group Administrator', 'Full access across all companies', 1),
 ('group_hr',     'Group HR Manager',    'HR access across assigned/all companies', 1),
 ('company_admin','Company Administrator','Manages one company', 1),
 ('hr_manager',   'HR Manager',          'Full HR operations in assigned company', 1),
 ('hr_officer',   'HR Officer',          'Day-to-day HR operations in assigned company', 1),
 ('interviewer',  'Interviewer',         'Views assigned interviews and submits evaluations', 1),
 ('manager',      'Department Manager',  'Approves leave and views own team', 1),
 ('employee',     'Employee',            'Self-service access only', 1);

INSERT INTO permissions (code, module, description) VALUES
 ('company.view','company','View companies'),
 ('company.manage','company','Add/edit/deactivate companies'),
 ('user.view','user','View users'),
 ('user.manage','user','Create/edit users and assignments'),
 ('role.manage','user','Manage roles and permissions'),
 ('department.manage','org','Manage departments and designations'),
 ('employee.view','employee','View employee records'),
 ('employee.create','employee','Create employees'),
 ('employee.edit','employee','Edit employees'),
 ('employee.transfer','employee','Transfer employees between companies'),
 ('salary.view','employee','View salary information'),
 ('document.view','employee','View employee documents'),
 ('document.confidential','employee','View confidential documents'),
 ('document.upload','employee','Upload documents'),
 ('vacancy.view','recruitment','View vacancies'),
 ('vacancy.manage','recruitment','Create/edit vacancies'),
 ('candidate.view','recruitment','View candidates'),
 ('candidate.manage','recruitment','Create/edit candidates and applications'),
 ('candidate.cross_company','recruitment','Browse candidate pool across companies'),
 ('interview.view','recruitment','View interviews'),
 ('offer.manage','recruitment','Create offers and convert candidates to employees'),
 ('attendance.view','attendance','View attendance'),
 ('attendance.manage','attendance','Record/edit attendance'),
 ('leave.request','leave','Submit own leave requests'),
 ('leave.approve','leave','Approve or reject leave'),
 ('report.view','report','View reports'),
 ('report.export','report','Export reports'),
 ('dashboard.group','report','View group dashboard'),
 ('audit.view','audit','View activity logs');

-- Group Admin: every permission
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='group_admin'), id FROM permissions;

-- HR Manager: all except company/role management and audit
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='hr_manager'), id FROM permissions
 WHERE code NOT IN ('company.manage','role.manage','dashboard.group','candidate.cross_company');

-- HR Officer: operational permissions, no salary / confidential docs / user admin
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='hr_officer'), id FROM permissions
 WHERE code IN ('company.view','employee.view','employee.create','employee.edit','document.view','document.upload',
                'vacancy.view','vacancy.manage','candidate.view','candidate.manage','interview.view',
                'attendance.view','attendance.manage','leave.approve','report.view');

-- Interviewer
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='interviewer'), id FROM permissions
 WHERE code IN ('interview.view','candidate.view');

-- Manager
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='manager'), id FROM permissions
 WHERE code IN ('employee.view','attendance.view','leave.approve','leave.request');

-- Employee
INSERT INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name='employee'), id FROM permissions
 WHERE code IN ('leave.request');

-- Example companies (Group Admin can edit these from the UI later)
INSERT INTO companies (code, name) VALUES
 ('AZT','AaZa Tech'),
 ('AZL','AaZa Lifestyle'),
 ('AZM','AaZa Masala');

-- Group-wide default leave types
INSERT INTO leave_types (company_id, name, days_per_year, requires_document) VALUES
 (NULL,'Annual Leave',14,0),
 (NULL,'Casual Leave',7,0),
 (NULL,'Medical Leave',7,1),
 (NULL,'Unpaid Leave',0,0);

-- NOTE: The first Group Admin user is created in Phase 2 via a one-time
-- setup script (so the password is hashed with PHP password_hash()).
