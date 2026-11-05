-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 6: Interview Scheduling & Evaluation Schema
-- ============================================================
USE aaza_hrms;

-- 1. Ensure interviews table exists with all required fields
CREATE TABLE IF NOT EXISTS interviews (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id   INT UNSIGNED NOT NULL,
  company_id       INT UNSIGNED NOT NULL,
  round_no         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  interview_type   VARCHAR(30) NOT NULL DEFAULT 'video',
  scheduled_at     DATETIME NOT NULL,
  duration_min     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  location_or_link VARCHAR(255) NULL,
  mode             ENUM('in_person','online','phone') NOT NULL DEFAULT 'online',
  location         VARCHAR(255) NULL,
  status           ENUM('scheduled','completed','cancelled','no_show','rescheduled') NOT NULL DEFAULT 'scheduled',
  notes            TEXT NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_int_company_date (company_id, scheduled_at),
  KEY idx_int_app (application_id),
  CONSTRAINT fk_int_app     FOREIGN KEY (application_id) REFERENCES candidate_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_int_company FOREIGN KEY (company_id)     REFERENCES companies(id),
  CONSTRAINT fk_int_by      FOREIGN KEY (created_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. Interview Panel table
CREATE TABLE IF NOT EXISTS interview_panel (
  interview_id INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (interview_id, user_id),
  CONSTRAINT fk_ip_interview FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_ip_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Interview Evaluations table
CREATE TABLE IF NOT EXISTS interview_evaluations (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  interview_id        INT UNSIGNED NOT NULL,
  evaluator_id        INT UNSIGNED NOT NULL,
  technical_score     TINYINT UNSIGNED NULL,
  communication_score TINYINT UNSIGNED NULL,
  attitude_score      TINYINT UNSIGNED NULL,
  overall_score       DECIMAL(3,2) UNSIGNED NULL,
  recommendation      VARCHAR(30) NOT NULL,
  strengths           TEXT NULL,
  weaknesses          TEXT NULL,
  comments            TEXT NULL,
  submitted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  evaluated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eval (interview_id, evaluator_id),
  CONSTRAINT fk_ie_interview FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_ie_user      FOREIGN KEY (evaluator_id) REFERENCES users(id)
) ENGINE=InnoDB;
