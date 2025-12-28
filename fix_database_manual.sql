-- Fix Database Manual - Từng lệnh một
-- Copy từng đoạn và chạy riêng biệt, bỏ qua lỗi "Duplicate column"

USE dacn1_db;

-- Thêm cột name (bỏ qua nếu lỗi)
ALTER TABLE `users` ADD COLUMN `name` VARCHAR(150) DEFAULT NULL AFTER `id`;

-- Thêm cột last_activity (bỏ qua nếu lỗi)
ALTER TABLE `users` ADD COLUMN `last_activity` DATETIME DEFAULT NULL AFTER `last_login`;

-- Thêm cột avatar (bỏ qua nếu lỗi)
ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) DEFAULT NULL AFTER `phone`;

-- Thêm cột facebook_id (bỏ qua nếu lỗi)
ALTER TABLE `users` ADD COLUMN `facebook_id` VARCHAR(64) DEFAULT NULL AFTER `email_verified`;

-- Thêm cột can_post (bỏ qua nếu lỗi)
ALTER TABLE `users` ADD COLUMN `can_post` TINYINT(1) DEFAULT 0 AFTER `is_admin`;

-- Cập nhật dữ liệu
UPDATE `users` SET `name` = `username` WHERE `name` IS NULL OR `name` = '';
UPDATE `users` SET `last_activity` = NOW() WHERE `last_activity` IS NULL;
UPDATE `users` SET `can_post` = 1 WHERE `is_admin` = 1;

-- Tạo admin user
INSERT IGNORE INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`) 
VALUES ('Administrator', 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1, 1, 1, 1);