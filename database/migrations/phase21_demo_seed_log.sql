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

