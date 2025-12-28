-- Fix Database - Chỉ thêm những gì thiếu
-- Copy và paste vào phpMyAdmin SQL

USE dacn1_db;

-- Kiểm tra và thêm cột thiếu vào bảng users
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `name` VARCHAR(150) DEFAULT NULL AFTER `id`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_activity` DATETIME DEFAULT NULL AFTER `last_login`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `avatar` VARCHAR(255) DEFAULT NULL AFTER `phone`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `facebook_id` VARCHAR(64) DEFAULT NULL AFTER `email_verified`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `can_post` TINYINT(1) DEFAULT 0 AFTER `is_admin`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) DEFAULT 0 AFTER `email`;

-- Cập nhật cột name từ username nếu name trống
UPDATE `users` SET `name` = `username` WHERE `name` IS NULL OR `name` = '';

-- Tạo bảng thiếu nếu chưa có
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `posting_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `student_code` VARCHAR(100) NOT NULL,
  `class_name` VARCHAR(100) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `document_card` VARCHAR(255) DEFAULT NULL,
  `document_internship` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tạo admin user nếu chưa có
INSERT IGNORE INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`) 
VALUES ('Administrator', 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1, 1, 1, 1);

-- Cập nhật last_activity cho tất cả users
UPDATE `users` SET `last_activity` = NOW() WHERE `last_activity` IS NULL;