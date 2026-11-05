-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 16: In-App Notifications & Scheduled Reminders Schema
-- ============================================================
USE aaza_hrms;

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

