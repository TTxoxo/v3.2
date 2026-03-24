-- 20260324_002_data_backfill_and_form_convergence.sql
-- Purpose: Backfill new columns/tables and converge to one-form-per-site with deterministic rule.
-- Deterministic rule:
--   - For each site, keep the latest form (MAX(id)) as canonical form.
--   - Preserve older forms in forms_archive.
--   - Preserve inquiry history by storing previous form_id in inquiries.legacy_form_id.
-- Rollback note:
--   - forms_archive keeps source records for recovery.
--   - inquiries.legacy_form_id keeps original reference before remap.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) Backfill inquiries.tel from historical inquiries.phone (dedicated builtin column alias).
UPDATE inquiries
SET tel = phone
WHERE (tel IS NULL OR tel = '')
  AND phone IS NOT NULL
  AND phone <> '';

-- 2) Initialize payload_json for existing rows (empty object for null values).
UPDATE inquiries
SET payload_json = JSON_OBJECT()
WHERE payload_json IS NULL;

-- 3) Backfill inquiry_logs from legacy form_logs (if any).
INSERT INTO inquiry_logs (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
SELECT fl.inquiry_id, fl.ga4_status, fl.ads_status, fl.mail_status, fl.error_message, fl.created_at
FROM form_logs fl
LEFT JOIN inquiry_logs il ON il.inquiry_id = fl.inquiry_id
WHERE il.inquiry_id IS NULL;

-- 4) Build temporary canonical-form map by site.
DROP TEMPORARY TABLE IF EXISTS tmp_site_primary_form;
CREATE TEMPORARY TABLE tmp_site_primary_form AS
SELECT site_id, MAX(id) AS primary_form_id
FROM forms
GROUP BY site_id;

DROP TEMPORARY TABLE IF EXISTS tmp_form_migration_map;
CREATE TEMPORARY TABLE tmp_form_migration_map AS
SELECT f.id AS old_form_id, f.site_id, p.primary_form_id
FROM forms f
JOIN tmp_site_primary_form p ON p.site_id = f.site_id
WHERE f.id <> p.primary_form_id;

-- 5) Preserve duplicate forms in archive before any delete.
INSERT INTO forms_archive
(
    original_form_id,
    site_id,
    form_name,
    fields_json,
    enable_ga4,
    enable_ads,
    enable_enhanced_conversion,
    require_gclid,
    is_active,
    original_created_at,
    archived_at
)
SELECT
    f.id,
    f.site_id,
    f.form_name,
    f.fields_json,
    f.enable_ga4,
    f.enable_ads,
    f.enable_enhanced_conversion,
    f.require_gclid,
    0,
    f.created_at,
    NOW()
FROM forms f
JOIN tmp_form_migration_map m ON m.old_form_id = f.id
LEFT JOIN forms_archive fa ON fa.original_form_id = f.id
WHERE fa.original_form_id IS NULL;

-- 6) Repoint inquiries to canonical form_id, preserving previous id in legacy_form_id.
UPDATE inquiries i
JOIN tmp_form_migration_map m ON m.old_form_id = i.form_id
SET i.legacy_form_id = i.form_id,
    i.form_id = m.primary_form_id;

-- 7) Delete duplicated forms from active forms after inquiries repoint.
DELETE f
FROM forms f
JOIN tmp_form_migration_map m ON m.old_form_id = f.id;

DROP TEMPORARY TABLE IF EXISTS tmp_form_migration_map;
DROP TEMPORARY TABLE IF EXISTS tmp_site_primary_form;

-- 8) Populate form_fields from forms.fields_json for compatibility migration.
-- MySQL 5.7 compatible JSON loop (no JSON_TABLE required).

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_backfill_form_fields $$
CREATE PROCEDURE sp_backfill_form_fields()
BEGIN
    DECLARE done_forms INT DEFAULT 0;
    DECLARE v_form_id BIGINT UNSIGNED;
    DECLARE v_fields JSON;
    DECLARE v_len INT;
    DECLARE i INT;
    DECLARE v_name VARCHAR(80);
    DECLARE v_label VARCHAR(150);
    DECLARE v_type VARCHAR(30);
    DECLARE v_required TINYINT;
    DECLARE v_sort INT;

    DECLARE cur_forms CURSOR FOR
        SELECT id, fields_json
        FROM forms
        ORDER BY id ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done_forms = 1;

    OPEN cur_forms;
    read_forms: LOOP
        FETCH cur_forms INTO v_form_id, v_fields;
        IF done_forms = 1 THEN
            LEAVE read_forms;
        END IF;

        SET v_len = COALESCE(JSON_LENGTH(v_fields), 0);
        SET i = 0;

        WHILE i < v_len DO
            SET v_name = JSON_UNQUOTE(JSON_EXTRACT(v_fields, CONCAT('$[', i, '].name')));
            SET v_label = JSON_UNQUOTE(JSON_EXTRACT(v_fields, CONCAT('$[', i, '].label')));
            SET v_type = LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v_fields, CONCAT('$[', i, '].type'))), 'text'));
            SET v_required = IF(JSON_EXTRACT(v_fields, CONCAT('$[', i, '].required')) = TRUE, 1, 0);
            SET v_sort = COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(v_fields, CONCAT('$[', i, '].sort'))) AS UNSIGNED), i + 1);

            IF v_name IS NULL OR v_name = '' THEN
                SET v_name = CONCAT('custom_', i + 1);
            END IF;
            IF v_label IS NULL OR v_label = '' THEN
                SET v_label = v_name;
            END IF;

            -- Normalize builtin aliases.
            IF LOWER(v_name) IN ('phone', 'mobile') THEN
                SET v_name = 'tel';
            END IF;

            IF LOWER(v_name) IN ('name','tel','email','message') THEN
                INSERT INTO form_fields (form_id, field_key, field_label, field_type, is_builtin, is_required, sort_order, is_active, settings_json, created_at, updated_at)
                VALUES (v_form_id, LOWER(v_name), v_label, v_type, 1, v_required, v_sort, 1, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_label = VALUES(field_label),
                    field_type = VALUES(field_type),
                    is_builtin = 1,
                    is_required = VALUES(is_required),
                    sort_order = VALUES(sort_order),
                    is_active = 1,
                    updated_at = NOW();
            ELSE
                INSERT INTO form_fields (form_id, field_key, field_label, field_type, is_builtin, is_required, sort_order, is_active, settings_json, created_at, updated_at)
                VALUES (v_form_id, v_name, v_label, v_type, 0, v_required, v_sort, 1, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    field_label = VALUES(field_label),
                    field_type = VALUES(field_type),
                    is_builtin = 0,
                    is_required = VALUES(is_required),
                    sort_order = VALUES(sort_order),
                    is_active = 1,
                    updated_at = NOW();
            END IF;

            SET i = i + 1;
        END WHILE;

        -- Ensure all builtin fixed fields always exist.
        INSERT INTO form_fields (form_id, field_key, field_label, field_type, is_builtin, is_required, sort_order, is_active, settings_json, created_at, updated_at)
        VALUES
            (v_form_id, 'name', 'Name', 'text', 1, 1, 1, 1, NULL, NOW(), NOW()),
            (v_form_id, 'tel', 'Tel', 'phone', 1, 0, 2, 1, NULL, NOW(), NOW()),
            (v_form_id, 'email', 'Email', 'email', 1, 1, 3, 1, NULL, NOW(), NOW()),
            (v_form_id, 'message', 'Message', 'textarea', 1, 0, 4, 1, NULL, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            is_builtin = 1,
            is_active = 1,
            updated_at = NOW();
    END LOOP;

    CLOSE cur_forms;
END $$
DELIMITER ;

CALL sp_backfill_form_fields();
DROP PROCEDURE IF EXISTS sp_backfill_form_fields;

SET FOREIGN_KEY_CHECKS = 1;
