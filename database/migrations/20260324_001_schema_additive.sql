-- 20260324_001_schema_additive.sql
-- Purpose: Add target-architecture tables/columns/indexes in additive mode.
-- Safety: Additive first; no destructive drop in this migration.
-- Rollback note:
--   - Drop newly created tables: site_users, form_fields, inquiry_logs, login_attempts, forms_archive.
--   - Remove newly added columns/indexes if no dependent code uses them.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_add_column_if_missing $$
CREATE PROCEDURE sp_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS sp_add_index_if_missing $$
CREATE PROCEDURE sp_add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_clause TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_clause);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

-- 1) Add/normalize columns on existing core tables.
CALL sp_add_column_if_missing('forms', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''future-compatible soft state'' AFTER `require_gclid`');

CALL sp_add_column_if_missing('inquiries', 'tel', 'VARCHAR(50) NULL AFTER `email`');
CALL sp_add_column_if_missing('inquiries', 'payload_json', 'JSON NULL COMMENT ''custom fields payload'' AFTER `message`');
CALL sp_add_column_if_missing('inquiries', 'legacy_form_id', 'BIGINT UNSIGNED NULL COMMENT ''original historical form id before one-form-per-site convergence'' AFTER `form_id`');
CALL sp_add_column_if_missing('inquiries', 'status', 'VARCHAR(30) NOT NULL DEFAULT ''new'' AFTER `fbclid`');

CALL sp_add_index_if_missing('inquiries', 'idx_inquiries_tel', 'INDEX `idx_inquiries_tel` (`tel`)');
CALL sp_add_index_if_missing('inquiries', 'idx_inquiries_status', 'INDEX `idx_inquiries_status` (`status`)');

-- 2) Create site_users (one-site-one-user enforced by unique site_id).
CREATE TABLE IF NOT EXISTS `site_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon hash',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_users_site_id` (`site_id`),
  UNIQUE KEY `uk_site_users_username` (`username`),
  KEY `idx_site_users_created_at` (`created_at`),
  CONSTRAINT `fk_site_users_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Create form_fields as future source of truth for form definitions.
CREATE TABLE IF NOT EXISTS `form_fields` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` BIGINT UNSIGNED NOT NULL,
  `field_key` VARCHAR(80) NOT NULL COMMENT 'stable key, e.g. name/tel/email/message/custom_xxx',
  `field_label` VARCHAR(150) NOT NULL,
  `field_type` VARCHAR(30) NOT NULL COMMENT 'text/email/phone/textarea/select/checkbox etc',
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `settings_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_form_fields_form_field_key` (`form_id`, `field_key`),
  KEY `idx_form_fields_form_sort` (`form_id`, `sort_order`),
  KEY `idx_form_fields_builtin` (`is_builtin`),
  CONSTRAINT `fk_form_fields_form_id`
    FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Create inquiry_logs (target name), keep form_logs for compatibility in this phase.
CREATE TABLE IF NOT EXISTS `inquiry_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inquiry_id` BIGINT UNSIGNED NOT NULL,
  `ga4_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `ads_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `mail_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `error_message` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inquiry_logs_inquiry_id` (`inquiry_id`),
  KEY `idx_inquiry_logs_created_at` (`created_at`),
  CONSTRAINT `fk_inquiry_logs_inquiry_id`
    FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Persistent login attempts table for stronger anti-abuse controls.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_type` ENUM('admin','site_user') NOT NULL DEFAULT 'admin',
  `account_identifier` VARCHAR(190) NOT NULL COMMENT 'username/email submitted',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(1024) DEFAULT NULL,
  `is_success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_account_time` (`account_type`, `account_identifier`, `attempted_at`),
  KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_login_attempts_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Archive table to preserve duplicate historical forms before one-form-per-site enforcement.
CREATE TABLE IF NOT EXISTS `forms_archive` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_form_id` BIGINT UNSIGNED NOT NULL,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `form_name` VARCHAR(150) NOT NULL,
  `fields_json` JSON NOT NULL,
  `enable_ga4` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_ads` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_enhanced_conversion` TINYINT(1) NOT NULL DEFAULT 0,
  `require_gclid` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `original_created_at` TIMESTAMP NULL DEFAULT NULL,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forms_archive_original_form_id` (`original_form_id`),
  KEY `idx_forms_archive_site_id` (`site_id`),
  KEY `idx_forms_archive_archived_at` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS sp_add_column_if_missing;
DROP PROCEDURE IF EXISTS sp_add_index_if_missing;

SET FOREIGN_KEY_CHECKS = 1;
