-- Migration: Thêm các chức năng cho bình luận
-- Chạy file này để cập nhật database

-- Thêm cột parent_id cho bảng comments (nếu chưa có)
SET @dbname = DATABASE();
SET @tablename = 'comments';
SET @columnname = 'parent_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE comments ADD COLUMN parent_id INT(11) DEFAULT NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Thêm cột is_hidden cho bảng comments (nếu chưa có)
SET @columnname = 'is_hidden';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE comments ADD COLUMN is_hidden TINYINT(1) DEFAULT 0'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Thêm cột updated_at cho bảng comments (nếu chưa có)
SET @columnname = 'updated_at';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE comments ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Tạo bảng comment_likes (Like/React bình luận)
CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `comment_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `reaction_type` VARCHAR(20) DEFAULT 'like',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_comment` (`comment_id`, `user_id`),
  KEY `idx_comment_likes_comment` (`comment_id`),
  KEY `idx_comment_likes_user` (`user_id`),
  FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tạo bảng comment_reports (Báo cáo bình luận)
CREATE TABLE IF NOT EXISTS `comment_reports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `comment_id` INT(11) NOT NULL,
  `reporter_id` INT(11) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `status` ENUM('pending', 'reviewed', 'resolved', 'dismissed') DEFAULT 'pending',
  `admin_note` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comment_reports_comment` (`comment_id`),
  KEY `idx_comment_reports_reporter` (`reporter_id`),
  KEY `idx_comment_reports_status` (`status`),
  FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
