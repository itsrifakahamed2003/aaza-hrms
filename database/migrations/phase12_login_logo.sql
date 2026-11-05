-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: Login Page Branding & Custom Logo Settings
-- ============================================================
USE aaza_hrms;

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
