-- 为 records 增加整改图“最后上传时间”字段（可重复执行）
-- 同一记录（同一个 key）允许员工重新上传整改图，每次上传都会刷新该时间。
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

-- 回填历史数据：已有整改图但缺少上传时间的，沿用记录创建时间
UPDATE `records`
SET `fix_uploaded_at` = `created_at`
WHERE `fix_image` IS NOT NULL
  AND `fix_image` <> ''
  AND `fix_uploaded_at` IS NULL;
