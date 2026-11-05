-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 6 Fix: Schema alignment & missing interview_panel table
-- ============================================================
USE aaza_hrms;

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
