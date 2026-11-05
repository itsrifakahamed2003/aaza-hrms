-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Unified Single-File Database Schema & Migration Master
-- Suitable for fresh installation on cPanel, Plesk, VPS & Local Dev
--
-- Note: This file contains NO 'CREATE DATABASE' or 'USE' commands.
-- It is safe for host-prefixed database names (e.g. user_aazahrms).
-- Target database must be selected/connected before importing.
-- Generated: 2026-11-05 07:06:06 UTC
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Step 1: schema.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 1: Database Schema (MySQL 5.7+/MariaDB 10.4+, InnoDB, utf8mb4)
-- Import via phpMyAdmin or: mysql -u root < schema.sql
-- ============================================================




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

-- ------------------------------------------------------------
-- Step 2: phase2_roles_seed.sql
-- ------------------------------------------------------------
-- Phase 2 add-on: give the group_hr and company_admin roles their permissions.
-- (schema.sql created these two roles with no permissions.)
-- Safe to run more than once. Run after schema.sql.


-- Group HR Manager: HR work across companies, plus group dashboard and cross-company candidates.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id FROM permissions
 WHERE code NOT IN ('company.manage', 'role.manage', 'user.manage', 'audit.view');

-- Company Administrator: everything inside one company, but nothing group-wide.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id FROM permissions
 WHERE code NOT IN ('company.manage', 'role.manage', 'dashboard.group', 'candidate.cross_company');

-- ------------------------------------------------------------
-- Step 3: phase6_permissions.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 6: Interview Permissions Seed
-- ============================================================


-- 1. Insert interview permissions
INSERT IGNORE INTO permissions (code, module, description) VALUES
 ('interview.view', 'recruitment', 'View interviews'),
 ('interview.manage', 'recruitment', 'Schedule, reschedule, cancel, and manage interviews'),
 ('interview.view_evaluations', 'recruitment', 'View confidential interview evaluations');

-- 2. Assign to Group Admin (all permissions)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 3. Assign to Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 4. Assign to Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 5. Assign to HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- 6. Assign to HR Officer
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('interview.view', 'interview.manage', 'interview.view_evaluations');

-- ------------------------------------------------------------
-- Step 4: phase6_fix.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 6 Fix: Schema alignment & missing interview_panel table
-- ============================================================


-- 1. Ensure interview_panel table exists
CREATE TABLE IF NOT EXISTS interview_panel (
  interview_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (interview_id, user_id),
  CONSTRAINT fk_ip_interview FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_ip_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Align interviews table
ALTER TABLE interviews
  MODIFY interview_type VARCHAR(30) NOT NULL DEFAULT 'video',
  ADD COLUMN location_or_link VARCHAR(255) NULL AFTER location,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 3. Align interview_evaluations table
ALTER TABLE interview_evaluations
  MODIFY recommendation VARCHAR(30) NOT NULL,
  MODIFY overall_score DECIMAL(3,2) UNSIGNED NULL,
  ADD COLUMN submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ------------------------------------------------------------
-- Step 5: phase7_permissions.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 7: Job Offers & Salary Permissions Seed
-- ============================================================


-- 1. Ensure permissions exist
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('offer.view', 'recruitment', 'View job offers'),
  ('salary.view', 'employee', 'View salary information');

-- 2. Assign to Group Admin (all permissions)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 3. Assign to Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 4. Assign to Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 5. Assign to HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('offer.view', 'offer.manage', 'salary.view');

-- 6. Assign to HR Officer (offer.view only)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('offer.view');

-- ------------------------------------------------------------
-- Step 6: phase8_leave_attendance.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 8: Attendance and Leave Management Schema & Permissions
-- NOTE: ADD COLUMN IF NOT EXISTS requires MariaDB 10.0+/XAMPP and will not run on standard MySQL.
-- ============================================================


-- 1. ALTER existing tables (Idempotent via IF NOT EXISTS in MariaDB 10.4+)
-- leave_types: add description
ALTER TABLE leave_types
  ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER name;

-- leave_requests: add half_day, decided_by, decided_at, decision_comment, updated_at
ALTER TABLE leave_requests
  ADD COLUMN IF NOT EXISTS half_day TINYINT(1) NOT NULL DEFAULT 0 AFTER total_days,
  ADD COLUMN IF NOT EXISTS decided_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN IF NOT EXISTS decided_at DATETIME NULL AFTER decided_by,
  ADD COLUMN IF NOT EXISTS decision_comment VARCHAR(255) NULL AFTER decided_at,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER decision_comment;

-- Add foreign key constraint for decided_by if not present
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND CONSTRAINT_NAME = 'fk_lr_decided_by');
SET @sql_stmt = IF(@fk_exists = 0,
  'ALTER TABLE leave_requests ADD CONSTRAINT fk_lr_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Seed Permissions
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('leave.view', 'leave', 'View leave requests, balances and reports'),
  ('leave.manage_types', 'leave', 'Manage group and company leave types');

-- 3. Assign Permissions to Roles
-- Group Admin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Group HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_hr'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- HR Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_manager'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.manage_types', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- HR Officer
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'hr_officer'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.request', 'leave.approve', 'attendance.view', 'attendance.manage');

-- Department Manager
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'manager'), id
   FROM permissions
  WHERE code IN ('leave.view', 'leave.request', 'leave.approve', 'attendance.view');

-- Employee
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'employee'), id
   FROM permissions
  WHERE code IN ('leave.request');

-- 4. Seed Default Group-wide Leave Types (company_id IS NULL)
-- Insert only when no row with that name and company_id IS NULL exists
INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Annual Leave', 'Standard statutory annual leave allowance', 14.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Annual Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Casual Leave', 'Short-term personal and casual leave', 7.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Casual Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Medical Leave', 'Certified sick and medical leave (requires medical certificate)', 7.0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Medical Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Unpaid Leave', 'Leave without pay (unlimited balance)', 0.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Unpaid Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Maternity Leave', 'Maternity leave for female employees (requires medical report)', 84.0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Maternity Leave' AND company_id IS NULL);

INSERT INTO leave_types (company_id, name, description, days_per_year, requires_document, is_active)
SELECT NULL, 'Paternity Leave', 'Paternity leave for male employees', 5.0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Paternity Leave' AND company_id IS NULL);

-- ------------------------------------------------------------
-- Step 7: phase9_reports.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 9: Group Dashboard, Reports, Analytics & Permissions
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 8: phase10_final.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 10: Audit Log Viewer, Security Hardening & Rate Limiting
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 9: phase11_link_user.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 11: Link Login Account to Employee Unique Index & Permissions
-- ============================================================


-- 1. Ensure employee.update permission exists and is granted to roles that have employee.edit
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('employee.update', 'employee', 'Update and link employee records');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT rp.role_id, (SELECT id FROM permissions WHERE code = 'employee.update')
   FROM role_permissions rp
   JOIN permissions p ON rp.permission_id = p.id
  WHERE p.code = 'employee.edit';

-- 2. Check for and resolve any duplicate user_id assignments before creating unique index.
-- If duplicate non-null user_id exists, retain the lowest employee id and reset duplicates to NULL.
UPDATE employees e
JOIN (
    SELECT user_id, MIN(id) AS keep_id
      FROM employees
     WHERE user_id IS NOT NULL
     GROUP BY user_id
    HAVING COUNT(*) > 1
) dup ON e.user_id = dup.user_id AND e.id != dup.keep_id
SET e.user_id = NULL;

-- 3. Idempotently add UNIQUE index on employees(user_id) (allows NULL)
SET @exist := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'employees'
       AND INDEX_NAME = 'uq_employees_user_id'
);
SET @sql := IF(@exist = 0, 'CREATE UNIQUE INDEX uq_employees_user_id ON employees(user_id)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Step 10: phase12_user_overrides.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: User Permission Overrides & Granular Permissions
-- ============================================================


-- 1. Table for per-user, per-company permission overrides (allow/deny)
CREATE TABLE IF NOT EXISTS user_permission_overrides (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  company_id    INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  effect        ENUM('allow','deny') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_company_permission (user_id, company_id, permission_id),
  KEY idx_upo_user (user_id),
  KEY idx_upo_company (company_id),
  KEY idx_upo_perm (permission_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Ensure granular user management permissions exist
INSERT IGNORE INTO permissions (code, module, description) VALUES
 ('user.create', 'user', 'Create new system users'),
 ('user.edit',   'user', 'Edit system users and credentials');

-- 3. Assign new permissions to Group Admin (all permissions)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
   FROM permissions
  WHERE code IN ('user.create', 'user.edit');

-- 4. Assign new permissions to Company Administrator
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT (SELECT id FROM roles WHERE name = 'company_admin'), id
   FROM permissions
  WHERE code IN ('user.create', 'user.edit');

-- ------------------------------------------------------------
-- Step 11: phase12_company_logo.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: Company Logo Management & Granular Permissions
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 12: phase12_login_logo.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: Login Page Branding & Custom Logo Settings
-- ============================================================


-- 1. Ensure system_settings table exists
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Seed default keys for login page branding (idempotent)
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('branding.login_logo', NULL),
  ('branding.login_use_sidebar', '0');

-- ------------------------------------------------------------
-- Step 13: phase13_user_delete.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 13: User Deletion & Granular Permissions
-- ============================================================


-- 1. Insert user.delete permission (idempotent)
INSERT IGNORE INTO permissions (code, module, description) VALUES
  ('user.delete', 'user', 'Permanently delete inactive users with no dependent records');

-- 2. Grant permission ONLY to the group_admin role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM roles WHERE name = 'group_admin'), id
    FROM permissions
   WHERE code = 'user.delete';

-- ------------------------------------------------------------
-- Step 14: phase14_delete_permissions.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 14: Master Organizational & Document Deletion Permissions
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 15: phase15_search_indexes.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 15: Unified Global Search Performance Indexes
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 16: phase16_notifications.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 16: In-App Notifications & Scheduled Reminders Schema
-- ============================================================


-- 1. Ensure table exists if not already present
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  company_id  INT UNSIGNED NULL,
  type        VARCHAR(50) NOT NULL,
  title       VARCHAR(150) NOT NULL DEFAULT '',
  message     VARCHAR(255) NOT NULL,
  link_url    VARCHAR(255) NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  read_at     TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dedupe_key  VARCHAR(100) NULL DEFAULT NULL,
  KEY idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Ensure title column exists
SET @exist_title := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'title');
SET @sql_title := IF(@exist_title = 0, 'ALTER TABLE notifications ADD COLUMN title VARCHAR(150) NOT NULL DEFAULT \'\' AFTER type', 'DO 0');
PREPARE stmt FROM @sql_title;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Ensure read_at column exists
SET @exist_read := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'read_at');
SET @sql_read := IF(@exist_read = 0, 'ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER is_read', 'DO 0');
PREPARE stmt FROM @sql_read;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Ensure dedupe_key column exists
SET @exist_dedupe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'dedupe_key');
SET @sql_dedupe := IF(@exist_dedupe = 0, 'ALTER TABLE notifications ADD COLUMN dedupe_key VARCHAR(100) NULL DEFAULT NULL AFTER created_at', 'DO 0');
PREPARE stmt FROM @sql_dedupe;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Ensure UNIQUE index on (user_id, dedupe_key) exists
SET @exist_uq := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'notifications'
       AND INDEX_NAME = 'uq_notif_user_dedupe'
);
SET @sql_uq := IF(@exist_uq = 0, 'CREATE UNIQUE INDEX uq_notif_user_dedupe ON notifications(user_id, dedupe_key)', 'DO 0');
PREPARE stmt FROM @sql_uq;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Ensure composite index on (user_id, is_read, created_at) exists
SET @exist_idx := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'notifications'
       AND INDEX_NAME = 'idx_notif_user_read_created'
);
SET @sql_idx := IF(@exist_idx = 0, 'CREATE INDEX idx_notif_user_read_created ON notifications(user_id, is_read, created_at)', 'DO 0');
PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Step 17: phase17_company_settings.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 17: Granular Company Settings Management
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 18: phase18_performance.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 18: Employee Performance Management & Goals Schema
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 19: phase19_holidays.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 19: Company & Group-Wide Public Holidays
-- ============================================================


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

-- ------------------------------------------------------------
-- Step 20: phase20_cleanup_permissions.sql
-- ------------------------------------------------------------
-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 20: Clean up dead / deprecated permission codes
-- ============================================================


-- Remove role bindings for deprecated permissions
DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('evaluation.view', 'interview.evaluate', 'interview.schedule');

-- Remove deprecated permission definitions
DELETE FROM permissions
WHERE code IN ('evaluation.view', 'interview.evaluate', 'interview.schedule');

-- ------------------------------------------------------------
-- Step 21: phase21_demo_seed_log.sql
-- ------------------------------------------------------------
-- Phase 21: Demo Seed Audit & Cleanup Log Table
-- Tracks seeded records for clean idempotent removal during demo hosting & testing.

CREATE TABLE IF NOT EXISTS `demo_seed_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int(10) unsigned NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `row_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_demo_seed_company_table` (`company_id`, `table_name`),
  KEY `idx_demo_seed_row` (`table_name`, `row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
