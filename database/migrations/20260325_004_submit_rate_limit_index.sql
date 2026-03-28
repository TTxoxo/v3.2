-- 20260325_004_submit_rate_limit_index.sql
-- Purpose: Add hot-path composite index for submit rate limiting query.
-- Query target: inquiries(site_id, user_ip, created_at) in api/submit.php.
-- Safety: additive only.
-- Rollback note:
--   - ALTER TABLE inquiries DROP INDEX idx_inquiries_site_ip_created;

SET NAMES utf8mb4;

DELIMITER $$
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

CALL sp_add_index_if_missing(
    'inquiries',
    'idx_inquiries_site_ip_created',
    'INDEX `idx_inquiries_site_ip_created` (`site_id`, `user_ip`, `created_at`)'
);

DROP PROCEDURE IF EXISTS sp_add_index_if_missing;
