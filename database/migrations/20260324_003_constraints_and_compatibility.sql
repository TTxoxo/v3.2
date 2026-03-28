-- 20260324_003_constraints_and_compatibility.sql
-- Purpose: Enforce one-form-per-site and add compatibility sync between form_logs and inquiry_logs.
-- Prerequisite: run 20260324_001 and 20260324_002 first.
-- Rollback note:
--   - Drop uk_forms_site_id to allow multiple forms per site again.
--   - Drop compatibility triggers trg_form_logs_ai / trg_form_logs_au.

SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_add_unique_if_missing $$
CREATE PROCEDURE sp_add_unique_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD UNIQUE KEY `', p_index, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

-- 1) Enforce one-form-per-site on active forms table.
CALL sp_add_unique_if_missing('forms', 'uk_forms_site_id', '`site_id`');

DROP PROCEDURE IF EXISTS sp_add_unique_if_missing;

-- 2) Compatibility triggers: current application writes form_logs.
-- Keep inquiry_logs synchronized until application switches to inquiry_logs writes directly.

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_create_trigger_if_missing $$
CREATE PROCEDURE sp_create_trigger_if_missing(
    IN p_trigger_name VARCHAR(128),
    IN p_trigger_sql LONGTEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME = p_trigger_name
    ) THEN
        SET @sql = p_trigger_sql;
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$
DELIMITER ;

CALL sp_create_trigger_if_missing(
    'trg_form_logs_ai',
    'CREATE TRIGGER trg_form_logs_ai AFTER INSERT ON form_logs
     FOR EACH ROW
     INSERT INTO inquiry_logs (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
     VALUES (NEW.inquiry_id, NEW.ga4_status, NEW.ads_status, NEW.mail_status, NEW.error_message, NEW.created_at)
     ON DUPLICATE KEY UPDATE
         ga4_status = VALUES(ga4_status),
         ads_status = VALUES(ads_status),
         mail_status = VALUES(mail_status),
         error_message = VALUES(error_message),
         created_at = VALUES(created_at)'
);

CALL sp_create_trigger_if_missing(
    'trg_form_logs_au',
    'CREATE TRIGGER trg_form_logs_au AFTER UPDATE ON form_logs
     FOR EACH ROW
     INSERT INTO inquiry_logs (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
     VALUES (NEW.inquiry_id, NEW.ga4_status, NEW.ads_status, NEW.mail_status, NEW.error_message, NEW.created_at)
     ON DUPLICATE KEY UPDATE
         ga4_status = VALUES(ga4_status),
         ads_status = VALUES(ads_status),
         mail_status = VALUES(mail_status),
         error_message = VALUES(error_message),
         created_at = VALUES(created_at)'
);

DROP PROCEDURE IF EXISTS sp_create_trigger_if_missing;

-- 3) Safety checks (non-blocking, for operator visibility).
-- Sites with no form after convergence should be reviewed manually.
SELECT s.id AS site_id, s.site_name
FROM sites s
LEFT JOIN forms f ON f.site_id = s.id
WHERE f.id IS NULL;
