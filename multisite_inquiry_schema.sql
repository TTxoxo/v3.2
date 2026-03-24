-- 多站点外贸询盘管理系统数据库结构
-- MySQL 5.7+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `multisite_inquiry`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `multisite_inquiry`;

-- 1) 管理员用户
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT '存储 bcrypt 哈希值',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_users_username` (`username`),
  KEY `idx_admin_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 站点
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

-- 3) 表单配置
CREATE TABLE IF NOT EXISTS `forms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `form_name` VARCHAR(150) NOT NULL,
  `fields_json` JSON NOT NULL COMMENT '表单字段 JSON 结构定义',
  `enable_ga4` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_ads` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_enhanced_conversion` TINYINT(1) NOT NULL DEFAULT 0,
  `require_gclid` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forms_site_id` (`site_id`),
  KEY `idx_forms_created_at` (`created_at`),
  CONSTRAINT `fk_forms_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) 询盘数据
CREATE TABLE IF NOT EXISTS `inquiries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `form_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `message` TEXT,
  `user_ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(1024) DEFAULT NULL,
  `gclid` VARCHAR(255) DEFAULT NULL,
  `wbraid` VARCHAR(255) DEFAULT NULL,
  `gbraid` VARCHAR(255) DEFAULT NULL,
  `client_id` VARCHAR(255) DEFAULT NULL COMMENT 'GA client id (_ga cookie parsed)',
  `source_channel` VARCHAR(100) DEFAULT NULL COMMENT '来源渠道：paid_search/organic_search/referral/direct 等',
  `source_platform` VARCHAR(255) DEFAULT NULL COMMENT '来源平台：google/facebook/bing 或 referrer host',
  `source_medium` VARCHAR(100) DEFAULT NULL COMMENT '来源媒介：cpc/organic/referral/none 等',
  `referrer_url` TEXT COMMENT '提交时 document.referrer',
  `landing_page` TEXT COMMENT '提交页 URL',
  `utm_source` VARCHAR(150) DEFAULT NULL,
  `utm_medium` VARCHAR(150) DEFAULT NULL,
  `utm_campaign` VARCHAR(255) DEFAULT NULL,
  `utm_term` VARCHAR(255) DEFAULT NULL,
  `utm_content` VARCHAR(255) DEFAULT NULL,
  `fbclid` VARCHAR(255) DEFAULT NULL,
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
  CONSTRAINT `fk_inquiries_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_inquiries_form_id`
    FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) SMTP 配置
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

-- 6) Google 配置
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

-- 7) 表单处理日志
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


-- 8) 站点级跟踪配置
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id` BIGINT UNSIGNED NOT NULL,
  `ga4_measurement_id` VARCHAR(50) DEFAULT NULL,
  `ga4_api_secret` VARCHAR(255) DEFAULT NULL,
  `ads_conversion_id` VARCHAR(50) DEFAULT NULL,
  `ads_conversion_label` VARCHAR(255) DEFAULT NULL,
  `smtp_to_email` VARCHAR(1000) DEFAULT NULL COMMENT '多个收件邮箱，支持逗号/分号/换行分隔',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_settings_site_id` (`site_id`),
  KEY `idx_site_settings_created_at` (`created_at`),
  CONSTRAINT `fk_site_settings_site_id`
    FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 9) 系统显示配置（后台名称/登录页文案）
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_name` VARCHAR(150) NOT NULL DEFAULT '外贸询盘系统',
  `admin_login_name` VARCHAR(150) NOT NULL DEFAULT '询盘系统后台',
  `admin_login_title` VARCHAR(255) DEFAULT 'H5 扁平化管理界面',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_settings_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 已部署系统升级（如果 inquiries 表已存在，请按需执行）
-- ALTER TABLE `inquiries`
--   ADD COLUMN `wbraid` VARCHAR(255) NULL,
--   ADD COLUMN `gbraid` VARCHAR(255) NULL,
--   ADD COLUMN `client_id` VARCHAR(255) NULL,
--   ADD COLUMN `source_channel` VARCHAR(100) NULL,
--   ADD COLUMN `source_platform` VARCHAR(255) NULL,
--   ADD COLUMN `source_medium` VARCHAR(100) NULL,
--   ADD COLUMN `referrer_url` TEXT NULL,
--   ADD COLUMN `landing_page` TEXT NULL,
--   ADD COLUMN `utm_source` VARCHAR(150) NULL,
--   ADD COLUMN `utm_medium` VARCHAR(150) NULL,
--   ADD COLUMN `utm_campaign` VARCHAR(255) NULL,
--   ADD COLUMN `utm_term` VARCHAR(255) NULL,
--   ADD COLUMN `utm_content` VARCHAR(255) NULL,
--   ADD COLUMN `fbclid` VARCHAR(255) NULL,
--   ADD KEY `idx_inquiries_source_channel` (`source_channel`),
--   ADD KEY `idx_inquiries_utm_source` (`utm_source`);
--
-- ALTER TABLE `site_settings`
--   MODIFY COLUMN `smtp_to_email` VARCHAR(1000) NULL COMMENT '多个收件邮箱，支持逗号/分号/换行分隔';
--
-- CREATE TABLE IF NOT EXISTS `system_settings` (
--   `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
--   `system_name` VARCHAR(150) NOT NULL DEFAULT '外贸询盘系统',
--   `admin_login_name` VARCHAR(150) NOT NULL DEFAULT '询盘系统后台',
--   `admin_login_title` VARCHAR(255) DEFAULT 'H5 扁平化管理界面',
--   `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   PRIMARY KEY (`id`),
--   KEY `idx_system_settings_created_at` (`created_at`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
