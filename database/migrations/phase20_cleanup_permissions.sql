-- ============================================================
-- AaZa Group - Multi-Company HR & Recruitment Management System
-- Phase 20: Clean up dead / deprecated permission codes
-- ============================================================
USE aaza_hrms;

-- Remove role bindings for deprecated permissions
DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('evaluation.view', 'interview.evaluate', 'interview.schedule');

-- Remove deprecated permission definitions
DELETE FROM permissions
WHERE code IN ('evaluation.view', 'interview.evaluate', 'interview.schedule');

