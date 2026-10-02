-- 为 records 表增加「整改图最后上传时间」字段（可重复执行）
-- 同一 key 重新上传整改图时，该字段会更新为最后一次上传时间
-- 执行示例：
-- docker compose exec -T db mysql -uroot -proot hygiene_audit < backend/database/migrate_add_fix_uploaded_at.sql

SET NAMES utf8mb4;
USE hygiene_audit;

SET @db = DATABASE();

-- records.fix_uploaded_at
SET @sql = (
  SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'records'
       AND COLUMN_NAME = 'fix_uploaded_at') = 0,
    'ALTER TABLE `records` ADD COLUMN `fix_uploaded_at` datetime DEFAULT NULL AFTER `fix_image`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 历史已完成的记录：用记录创建时间回填一个初始值，便于页面展示（仍代表最后一次上传时间）
UPDATE `records`
SET `fix_uploaded_at` = `created_at`
WHERE `status` = 'completed'
  AND `fix_image` IS NOT NULL
  AND `fix_image` <> ''
  AND `fix_uploaded_at` IS NULL;
