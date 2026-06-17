-- =====================================================
-- DATABASE HOÀN CHỈNH - Tương thích XAMPP và Docker
-- =====================================================
-- HƯỚNG DẪN SỬ DỤNG:
-- 
-- XAMPP (phpMyAdmin):
--   1. Mở phpMyAdmin (http://localhost/phpmyadmin)
--   2. Import file này trực tiếp (không cần tạo database trước)
--   3. Hoặc: Tạo database 'dacn1_db' trước rồi import
--
-- Docker:
--   File này tự động chạy khi khởi tạo MySQL container
--   (mount vào /docker-entrypoint-initdb.d/)
-- =====================================================

-- Tạo database nếu chưa tồn tại (hoạt động trên cả XAMPP và Docker)
CREATE DATABASE IF NOT EXISTS `dacn1_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dacn1_db`;

-- =====================================================
-- XÓA BẢNG CŨ NẾU TỒN TẠI (theo thứ tự phụ thuộc)
-- =====================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ai_messages`;
DROP TABLE IF EXISTS `ai_conversations`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `user_feedback`;
DROP TABLE IF EXISTS `faqs`;
DROP TABLE IF EXISTS `knowledge_posts`;
DROP TABLE IF EXISTS `verifications`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `ratings`;
DROP TABLE IF EXISTS `favorites`;
DROP TABLE IF EXISTS `posting_requests`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `email_verifications`;
DROP TABLE IF EXISTS `account_requests`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `conversations`;
DROP TABLE IF EXISTS `direct_messages`;
DROP TABLE IF EXISTS `friendships`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `posts`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- BẢNG USERS - Người dùng
-- =====================================================
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) DEFAULT NULL,
  `full_name` VARCHAR(150) DEFAULT NULL,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `email_verified` TINYINT(1) DEFAULT 0,
  `facebook_id` VARCHAR(64) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('patient','student','admin') DEFAULT 'student',
  `bio` TEXT DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `school` VARCHAR(255) DEFAULT NULL,
  `class_code` VARCHAR(100) DEFAULT NULL,
  `student_id` VARCHAR(100) DEFAULT NULL,
  `verified` TINYINT(1) DEFAULT 0,
  `is_admin` TINYINT(1) DEFAULT 0,
  `can_post` TINYINT(1) DEFAULT 0,
  `show_phone` TINYINT(1) DEFAULT 1,
  `show_email` TINYINT(1) DEFAULT 1,
  `allow_messages` TINYINT(1) DEFAULT 1,
  `last_login` DATETIME DEFAULT NULL,
  `last_activity` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `idx_users_facebook_id` (`facebook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG POSTS - Bài đăng
-- =====================================================
CREATE TABLE `posts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `type` ENUM('recruitment','application') DEFAULT 'recruitment',
  `category` VARCHAR(100) DEFAULT NULL,
  `area` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('open','closed','completed','inactive','taken') DEFAULT 'open',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_posts_user_id` (`user_id`),
  KEY `idx_posts_status` (`status`),
  KEY `idx_posts_type` (`type`),
  KEY `idx_posts_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG COMMENTS - Bình luận
-- =====================================================
CREATE TABLE `comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `post_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_id` (`post_id`),
  KEY `idx_comments_user_id` (`user_id`),
  KEY `idx_comments_created_at` (`created_at`),
  FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- BẢNG FRIENDSHIPS - Kết bạn
-- =====================================================
CREATE TABLE `friendships` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `friend_id` INT(11) NOT NULL,
  `status` ENUM('pending','accepted','blocked') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_friendships_user_id` (`user_id`),
  KEY `idx_friendships_friend_id` (`friend_id`),
  KEY `idx_friendships_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`friend_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG DIRECT_MESSAGES - Tin nhắn trực tiếp
-- =====================================================
CREATE TABLE `direct_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sender_id` INT(11) NOT NULL,
  `receiver_id` INT(11) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dm_sender_id` (`sender_id`),
  KEY `idx_dm_receiver_id` (`receiver_id`),
  KEY `idx_dm_is_read` (`is_read`),
  FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG CONVERSATIONS - Cuộc hội thoại
-- =====================================================
CREATE TABLE `conversations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user1_id` INT(11) NOT NULL,
  `user2_id` INT(11) NOT NULL,
  `last_message_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_user1_id` (`user1_id`),
  KEY `idx_conv_user2_id` (`user2_id`),
  FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG MESSAGES - Tin nhắn chung
-- =====================================================
CREATE TABLE `messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sender_id` INT(11) NOT NULL,
  `receiver_id` INT(11) NOT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_messages_sender_id` (`sender_id`),
  KEY `idx_messages_receiver_id` (`receiver_id`),
  KEY `idx_messages_is_read` (`is_read`),
  FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG APPOINTMENTS - Lịch hẹn
-- =====================================================
CREATE TABLE `appointments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `patient_id` INT(11) NOT NULL,
  `student_id` INT(11) NOT NULL,
  `appointment_date` DATETIME DEFAULT NULL,
  `status` ENUM('pending','confirmed','completed','cancelled') DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_patient_id` (`patient_id`),
  KEY `idx_appointments_student_id` (`student_id`),
  KEY `idx_appointments_date` (`appointment_date`),
  KEY `idx_appointments_status` (`status`),
  FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG ACCOUNT_REQUESTS - Yêu cầu tài khoản
-- =====================================================
CREATE TABLE `account_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_requests_user_id` (`user_id`),
  KEY `idx_account_requests_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG EMAIL_VERIFICATIONS - Xác thực email
-- =====================================================
CREATE TABLE `email_verifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_email_verifications_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG PASSWORD_RESETS - Đặt lại mật khẩu
-- =====================================================
CREATE TABLE `password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_password_resets_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG POSTING_REQUESTS - Yêu cầu đăng bài
-- =====================================================
CREATE TABLE `posting_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
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
  PRIMARY KEY (`id`),
  KEY `idx_posting_requests_user_id` (`user_id`),
  KEY `idx_posting_requests_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG FAVORITES - Yêu thích
-- =====================================================
CREATE TABLE `favorites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `post_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_post` (`user_id`, `post_id`),
  KEY `idx_favorites_user_id` (`user_id`),
  KEY `idx_favorites_post_id` (`post_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG RATINGS - Đánh giá
-- =====================================================
CREATE TABLE `ratings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `rated_user_id` INT(11) NOT NULL,
  `rating` TINYINT(1) NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_rated` (`user_id`, `rated_user_id`),
  KEY `idx_ratings_user_id` (`user_id`),
  KEY `idx_ratings_rated_user_id` (`rated_user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`rated_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG REPORTS - Báo cáo vi phạm
-- =====================================================
CREATE TABLE `reports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reporter_id` INT(11) NOT NULL,
  `reported_user_id` INT(11) DEFAULT NULL,
  `post_id` INT(11) DEFAULT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('pending','reviewed','resolved') DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reports_reporter_id` (`reporter_id`),
  KEY `idx_reports_reported_user_id` (`reported_user_id`),
  KEY `idx_reports_post_id` (`post_id`),
  KEY `idx_reports_status` (`status`),
  FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG VERIFICATIONS - Xác minh sinh viên
-- =====================================================
CREATE TABLE `verifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `document_type` VARCHAR(50) NOT NULL,
  `document_path` VARCHAR(255) NOT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_verifications_user_id` (`user_id`),
  KEY `idx_verifications_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG KNOWLEDGE_POSTS - Bài viết kiến thức
-- =====================================================
CREATE TABLE `knowledge_posts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `tags` TEXT DEFAULT NULL,
  `is_featured` TINYINT(1) DEFAULT 0,
  `view_count` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_knowledge_posts_user_id` (`user_id`),
  KEY `idx_knowledge_posts_category` (`category`),
  KEY `idx_knowledge_posts_is_featured` (`is_featured`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG USER_FEEDBACK - Phản hồi người dùng
-- =====================================================
CREATE TABLE `user_feedback` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `feedback_type` ENUM('bug','suggestion','complaint','compliment') NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('new','in_progress','resolved','closed') DEFAULT 'new',
  `admin_response` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_feedback_user_id` (`user_id`),
  KEY `idx_user_feedback_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG FAQS - Câu hỏi thường gặp
-- =====================================================
CREATE TABLE `faqs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `question` VARCHAR(500) NOT NULL,
  `answer` TEXT NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faqs_category` (`category`),
  KEY `idx_faqs_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG NOTIFICATIONS - Thông báo
-- =====================================================
CREATE TABLE `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_type` (`type`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG ACTIVITY_LOGS - Nhật ký hoạt động
-- =====================================================
CREATE TABLE `activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_user_id` (`user_id`),
  KEY `idx_activity_logs_action` (`action`),
  KEY `idx_activity_logs_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG AI_CONVERSATIONS - Lịch sử trò chuyện AI
-- =====================================================
CREATE TABLE `ai_conversations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) DEFAULT 'Cuộc trò chuyện AI',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BẢNG AI_MESSAGES - Tin nhắn chat AI
-- =====================================================
CREATE TABLE `ai_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) NOT NULL,
  `role` ENUM('user', 'model') NOT NULL,
  `content` TEXT NOT NULL,
  `source` VARCHAR(50) DEFAULT 'gemini',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_msg_conv_id` (`conversation_id`),
  FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- DỮ LIỆU MẪU
-- =====================================================

-- Admin user (password: password)
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`, `last_activity`) VALUES
('Administrator', 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, 1, 1, 1, NOW());

-- Student user (password: password)
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`, `school`, `class_code`, `student_id`, `last_activity`) VALUES
('Nguyễn Văn A', 'student', 'student@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1, 0, 1, 1, 'Đại học Y Dược TP.HCM', 'YK2020', 'SV001', NOW());

-- Patient user (password: password)
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`, `phone`, `location`, `last_activity`) VALUES
('Trần Thị B', 'patient', 'patient@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient', 1, 0, 0, 1, '0901234567', 'Quận 1, TP.HCM', NOW());

-- Thêm một số sinh viên mẫu
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`, `school`, `class_code`, `student_id`, `bio`, `last_activity`) VALUES
('Lê Văn C', 'sinhvien1', 'sinhvien1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1, 0, 1, 1, 'Đại học Y Dược TP.HCM', 'YK2021', 'SV002', 'Sinh viên năm 4 chuyên ngành Nội khoa', NOW()),
('Phạm Thị D', 'sinhvien2', 'sinhvien2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 1, 0, 1, 1, 'Đại học Y Dược Hà Nội', 'YK2020', 'SV003', 'Sinh viên năm 5 chuyên ngành Ngoại khoa', NOW());

-- Thêm một số bệnh nhân mẫu
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `verified`, `is_admin`, `can_post`, `email_verified`, `phone`, `location`, `last_activity`) VALUES
('Hoàng Văn E', 'benhnhan1', 'benhnhan1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient', 1, 0, 0, 1, '0912345678', 'Quận 3, TP.HCM', NOW()),
('Ngô Thị F', 'benhnhan2', 'benhnhan2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient', 1, 0, 0, 1, '0923456789', 'Quận Bình Thạnh, TP.HCM', NOW());

-- Bài đăng mẫu
INSERT INTO `posts` (`user_id`, `title`, `content`, `type`, `category`, `area`, `status`) VALUES
(3, 'Cần tư vấn về sức khỏe tim mạch', 'Tôi có triệu chứng đau ngực nhẹ khi vận động mạnh, cần được tư vấn từ sinh viên y khoa có kinh nghiệm về tim mạch.', 'recruitment', 'Tim mạch', 'Quận 1, TP.HCM', 'open'),
(3, 'Tìm sinh viên y khoa hỗ trợ khám sức khỏe', 'Cần hỗ trợ kiểm tra sức khỏe định kỳ tại nhà cho người cao tuổi. Địa chỉ: Quận 1, TP.HCM.', 'recruitment', 'Khám tổng quát', 'Quận 1, TP.HCM', 'open'),
(6, 'Cần tư vấn về dinh dưỡng', 'Tôi muốn được tư vấn về chế độ ăn uống phù hợp cho người tiểu đường type 2.', 'recruitment', 'Dinh dưỡng', 'Quận 3, TP.HCM', 'open'),
(7, 'Hỗ trợ chăm sóc sau phẫu thuật', 'Cần sinh viên y khoa hỗ trợ hướng dẫn chăm sóc vết thương sau phẫu thuật ruột thừa.', 'recruitment', 'Ngoại khoa', 'Quận Bình Thạnh, TP.HCM', 'open'),
(2, 'Sinh viên Y5 tìm cơ hội thực hành Nội khoa', 'Tôi là sinh viên năm 5 Đại học Y Dược TP.HCM, chuyên ngành Nội khoa. Tìm cơ hội thực hành chăm sóc bệnh nhân tại nhà.', 'application', 'Nội khoa', 'TP.HCM', 'open'),
(4, 'Sinh viên Y4 nhận hỗ trợ tư vấn dinh dưỡng', 'Tôi có kiến thức về dinh dưỡng lâm sàng, sẵn sàng hỗ trợ tư vấn chế độ ăn cho bệnh nhân.', 'application', 'Dinh dưỡng', 'TP.HCM', 'open');

-- Bình luận mẫu
INSERT INTO `comments` (`post_id`, `user_id`, `content`) VALUES
(1, 2, 'Chào bạn, tôi là sinh viên năm 4 chuyên ngành Nội khoa. Tôi có thể hỗ trợ tư vấn cho bạn. Bạn có thể liên hệ với tôi qua tin nhắn.'),
(1, 4, 'Tôi cũng có thể hỗ trợ. Triệu chứng đau ngực khi vận động có thể do nhiều nguyên nhân, cần được khám kỹ hơn.'),
(2, 2, 'Tôi có thể hỗ trợ khám sức khỏe tại nhà vào cuối tuần. Bạn có thể cho biết thêm chi tiết về tình trạng sức khỏe của người cần khám không?'),
(3, 5, 'Chào bạn, tôi có kiến thức về dinh dưỡng lâm sàng. Tôi có thể tư vấn cho bạn về chế độ ăn phù hợp.');

-- Đánh giá mẫu
INSERT INTO `ratings` (`user_id`, `rated_user_id`, `rating`, `comment`) VALUES
(3, 2, 5, 'Sinh viên rất nhiệt tình và có kiến thức tốt. Cảm ơn bạn!'),
(6, 4, 4, 'Tư vấn rất hữu ích, cảm ơn bạn đã hỗ trợ.');

-- Yêu thích mẫu
INSERT INTO `favorites` (`user_id`, `post_id`) VALUES
(2, 1),
(2, 3),
(4, 2);

-- Kết bạn mẫu
INSERT INTO `friendships` (`user_id`, `friend_id`, `status`, `accepted_at`) VALUES
(2, 3, 'accepted', NOW()),
(4, 6, 'accepted', NOW()),
(2, 4, 'pending', NULL);

-- Tin nhắn mẫu
INSERT INTO `direct_messages` (`sender_id`, `receiver_id`, `message`, `is_read`) VALUES
(3, 2, 'Chào bạn, tôi muốn được tư vấn thêm về triệu chứng của mình.', 1),
(2, 3, 'Chào bạn, bạn có thể mô tả chi tiết hơn về triệu chứng không?', 1),
(3, 2, 'Tôi bị đau ngực khi leo cầu thang hoặc đi bộ nhanh, khoảng 5-10 phút thì hết.', 0);

-- Cuộc hội thoại mẫu
INSERT INTO `conversations` (`user1_id`, `user2_id`, `last_message_id`) VALUES
(2, 3, 3);

-- Lịch hẹn mẫu
INSERT INTO `appointments` (`patient_id`, `student_id`, `appointment_date`, `status`, `notes`) VALUES
(3, 2, DATE_ADD(NOW(), INTERVAL 3 DAY), 'confirmed', 'Khám tư vấn tim mạch tại nhà'),
(6, 4, DATE_ADD(NOW(), INTERVAL 5 DAY), 'pending', 'Tư vấn dinh dưỡng online');

-- FAQ mẫu
INSERT INTO `faqs` (`question`, `answer`, `category`, `sort_order`) VALUES
('Làm thế nào để đăng ký tài khoản?', 'Bạn có thể đăng ký tài khoản bằng cách click vào nút "Đăng ký" ở góc trên bên phải và điền đầy đủ thông tin cần thiết. Sau khi đăng ký, bạn cần xác thực email để kích hoạt tài khoản.', 'Tài khoản', 1),
('Sinh viên y khoa cần làm gì để được xác minh?', 'Sinh viên cần upload thẻ sinh viên và giấy tờ liên quan (giấy xác nhận thực tập, bảng điểm...) để được admin xác minh. Quá trình xác minh thường mất 1-2 ngày làm việc.', 'Xác minh', 2),
('Làm sao để tìm kiếm bài viết?', 'Sử dụng thanh tìm kiếm ở đầu trang để tìm kiếm theo từ khóa. Bạn cũng có thể lọc theo trạng thái bài viết (đang mở, đã đóng, hoàn thành).', 'Sử dụng', 3),
('Làm sao để liên hệ với sinh viên y khoa?', 'Bạn có thể gửi tin nhắn trực tiếp cho sinh viên thông qua nút "Nhắn tin" trên trang hồ sơ của họ, hoặc bình luận vào bài viết của bạn để sinh viên có thể phản hồi.', 'Sử dụng', 4),
('Thông tin cá nhân của tôi có được bảo mật không?', 'Chúng tôi cam kết bảo mật thông tin cá nhân của bạn. Bạn có thể điều chỉnh cài đặt quyền riêng tư trong phần "Cài đặt tài khoản" để kiểm soát ai có thể xem thông tin của bạn.', 'Bảo mật', 5);

-- Thông báo mẫu
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `link`, `is_read`) VALUES
(3, 'comment', 'Bình luận mới', 'Nguyễn Văn A đã bình luận vào bài viết của bạn', 'view_post.php?id=1', 0),
(2, 'appointment', 'Lịch hẹn mới', 'Bạn có lịch hẹn mới với Trần Thị B', 'appointments.php', 0),
(6, 'message', 'Tin nhắn mới', 'Bạn có tin nhắn mới từ Phạm Thị D', 'chat.php?user=5', 0);

-- Bài viết kiến thức mẫu
INSERT INTO `knowledge_posts` (`user_id`, `title`, `content`, `category`, `tags`, `is_featured`, `view_count`) VALUES
(2, 'Hướng dẫn đo huyết áp đúng cách', 'Đo huyết áp là một kỹ năng quan trọng để theo dõi sức khỏe tim mạch. Dưới đây là các bước đo huyết áp đúng cách:\n\n1. Nghỉ ngơi ít nhất 5 phút trước khi đo\n2. Ngồi thoải mái, lưng tựa vào ghế\n3. Đặt cánh tay ngang tim\n4. Quấn vòng bít đúng vị trí\n5. Đo 2-3 lần và lấy trung bình', 'Sức khỏe tim mạch', 'huyết áp,tim mạch,sức khỏe', 1, 150),
(4, 'Chế độ ăn cho người tiểu đường', 'Người tiểu đường cần chú ý đến chế độ ăn uống để kiểm soát đường huyết. Một số nguyên tắc cơ bản:\n\n- Hạn chế đường và tinh bột tinh chế\n- Ăn nhiều rau xanh và chất xơ\n- Chọn protein nạc\n- Chia nhỏ bữa ăn trong ngày\n- Uống đủ nước', 'Dinh dưỡng', 'tiểu đường,dinh dưỡng,chế độ ăn', 1, 200);

-- =====================================================
-- HOÀN TẤT
-- =====================================================
-- Mật khẩu mặc định cho tất cả user: password
-- 
-- Tài khoản mẫu:
--   Admin:   admin / password
--   Student: student / password  
--   Patient: patient / password
--
-- XAMPP: Import file này qua phpMyAdmin
-- Docker: File tự động chạy khi khởi tạo container
-- =====================================================
