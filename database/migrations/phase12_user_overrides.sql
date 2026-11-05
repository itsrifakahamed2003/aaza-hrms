-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 12: User Permission Overrides & Granular Permissions
-- ============================================================
USE aaza_hrms;

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
