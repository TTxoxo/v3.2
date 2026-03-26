-- multisite_inquiry_full_install.sql
-- Purpose: one-shot fresh-install schema (final converged state) for environments
--          where step-by-step migration scripts may fail due client/protocol limits.
-- Notes:
--   1) For existing production databases, prefer migration route in DB_MIGRATION_NOTES.md.
--   2) For brand-new empty databases, this file can be imported directly.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `multisite_inquiry`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `multisite_inquiry`;

-- Core admin/auth tables
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_users_username` (`username`),
  KEY `idx_admin_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_name` VARCHAR(150) NOT NULL,
  `domain` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sites_domain` (`domain`),
  UNIQUE KEY `uk_sites_api_key` (`api_key`),
  KEY `idx_sites_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one-site-one-user target table
CREATE TABLE IF NOT EXISTS `site_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
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

-- forms: final target keeps compatibility fields_json + one-form-per-site
CREATE TABLE IF NOT EXISTS `forms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `form_name` VARCHAR(150) NOT NULL,
  `fields_json` JSON NOT NULL,
  `enable_ga4` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_ads` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_enhanced_conversion` TINYINT(1) NOT NULL DEFAULT 0,
  `require_gclid` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forms_site_id` (`site_id`),
  KEY `idx_forms_created_at` (`created_at`),
  CONSTRAINT `fk_forms_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_fields` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` BIGINT UNSIGNED NOT NULL,
  `field_key` VARCHAR(80) NOT NULL,
  `field_label` VARCHAR(150) NOT NULL,
  `field_type` VARCHAR(30) NOT NULL,
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

CREATE TABLE IF NOT EXISTS `inquiries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `form_id` BIGINT UNSIGNED NOT NULL,
  `legacy_form_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `tel` VARCHAR(50) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `message` TEXT,
  `payload_json` JSON NULL,
  `user_ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(1024) DEFAULT NULL,
  `gclid` VARCHAR(255) DEFAULT NULL,
  `wbraid` VARCHAR(255) DEFAULT NULL,
  `gbraid` VARCHAR(255) DEFAULT NULL,
  `client_id` VARCHAR(255) DEFAULT NULL,
  `source_channel` VARCHAR(100) DEFAULT NULL,
  `source_platform` VARCHAR(255) DEFAULT NULL,
  `source_medium` VARCHAR(100) DEFAULT NULL,
  `referrer_url` TEXT,
  `landing_page` TEXT,
  `utm_source` VARCHAR(150) DEFAULT NULL,
  `utm_medium` VARCHAR(150) DEFAULT NULL,
  `utm_campaign` VARCHAR(255) DEFAULT NULL,
  `utm_term` VARCHAR(255) DEFAULT NULL,
  `utm_content` VARCHAR(255) DEFAULT NULL,
  `fbclid` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'new',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inquiries_site_id` (`site_id`),
  KEY `idx_inquiries_form_id` (`form_id`),
  KEY `idx_inquiries_created_at` (`created_at`),
  KEY `idx_inquiries_source_channel` (`source_channel`),
  KEY `idx_inquiries_utm_source` (`utm_source`),
  KEY `idx_inquiries_gclid` (`gclid`),
  KEY `idx_inquiries_wbraid` (`wbraid`),
  KEY `idx_inquiries_site_form_created` (`site_id`, `form_id`, `created_at`),
  KEY `idx_inquiries_tel` (`tel`),
  KEY `idx_inquiries_status` (`status`),
  KEY `idx_inquiries_site_ip_created` (`site_id`, `user_ip`, `created_at`),
  CONSTRAINT `fk_inquiries_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_inquiries_form_id`
    FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility log table still used by runtime
CREATE TABLE IF NOT EXISTS `form_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inquiry_id` BIGINT UNSIGNED NOT NULL,
  `ga4_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `ads_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `mail_status` ENUM('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
  `error_message` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form_logs_inquiry_id` (`inquiry_id`),
  KEY `idx_form_logs_created_at` (`created_at`),
  CONSTRAINT `fk_form_logs_inquiry_id`
    FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_type` VARCHAR(30) NOT NULL,
  `account_identifier` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(1024) DEFAULT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_success` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_account_time` (`account_type`, `account_identifier`, `attempted_at`),
  KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_login_attempts_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Existing config tables used by current runtime/admin
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `ga4_measurement_id` VARCHAR(50) DEFAULT NULL,
  `ga4_api_secret` VARCHAR(255) DEFAULT NULL,
  `ads_conversion_id` VARCHAR(50) DEFAULT NULL,
  `ads_conversion_label` VARCHAR(255) DEFAULT NULL,
  `smtp_to_email` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_settings_site_id` (`site_id`),
  KEY `idx_site_settings_created_at` (`created_at`),
  CONSTRAINT `fk_site_settings_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_name` VARCHAR(150) NOT NULL DEFAULT '外贸询盘系统',
  `admin_login_name` VARCHAR(150) NOT NULL DEFAULT '询盘系统后台',
  `admin_login_title` VARCHAR(255) DEFAULT 'H5 扁平化管理界面',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_settings_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `smtp_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `host` VARCHAR(255) NOT NULL,
  `port` SMALLINT UNSIGNED NOT NULL,
  `username` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `encryption` ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
  `from_email` VARCHAR(190) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_smtp_settings_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ga4_measurement_id` VARCHAR(50) DEFAULT NULL,
  `ga4_api_secret` VARCHAR(255) DEFAULT NULL,
  `ads_conversion_id` VARCHAR(50) DEFAULT NULL,
  `ads_conversion_label` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_google_settings_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility triggers (direct create; avoids prepared-statement CREATE TRIGGER protocol issue)
DROP TRIGGER IF EXISTS trg_form_logs_ai;
DROP TRIGGER IF EXISTS trg_form_logs_au;

DELIMITER $$

CREATE TRIGGER trg_form_logs_ai
AFTER INSERT ON form_logs
FOR EACH ROW
BEGIN
    INSERT INTO inquiry_logs (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
    VALUES (NEW.inquiry_id, NEW.ga4_status, NEW.ads_status, NEW.mail_status, NEW.error_message, NEW.created_at)
    ON DUPLICATE KEY UPDATE
        ga4_status = VALUES(ga4_status),
        ads_status = VALUES(ads_status),
        mail_status = VALUES(mail_status),
        error_message = VALUES(error_message),
        created_at = VALUES(created_at);
END$$

CREATE TRIGGER trg_form_logs_au
AFTER UPDATE ON form_logs
FOR EACH ROW
BEGIN
    INSERT INTO inquiry_logs (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
    VALUES (NEW.inquiry_id, NEW.ga4_status, NEW.ads_status, NEW.mail_status, NEW.error_message, NEW.created_at)
    ON DUPLICATE KEY UPDATE
        ga4_status = VALUES(ga4_status),
        ads_status = VALUES(ads_status),
        mail_status = VALUES(mail_status),
        error_message = VALUES(error_message),
        created_at = VALUES(created_at);
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
