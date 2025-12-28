-- Migration: Thêm các trường mới cho bảng users
-- Chạy file này nếu database đã tồn tại

-- Thêm cột username
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(100) DEFAULT NULL UNIQUE AFTER `name`;

-- Thêm cột school (trường)
ALTER TABLE `users` ADD COLUMN `school` VARCHAR(255) DEFAULT NULL AFTER `phone`;

-- Thêm cột class_code (mã lớp)
ALTER TABLE `users` ADD COLUMN `class_code` VARCHAR(100) DEFAULT NULL AFTER `school`;
