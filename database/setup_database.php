<?php
/**
 * setup_database.php - Tự động nâng cấp cấu trúc Database
 * Chạy file này bằng trình duyệt hoặc dòng lệnh PHP để cập nhật cấu trúc database một cách an toàn.
 */

require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== BẮT ĐẦU CẬP NHẬT CẤU TRÚC DATABASE ===\n\n";

try {
    // 1. Tạo bảng applications (Ứng tuyển công việc)
    echo "1. Đang kiểm tra / tạo bảng 'applications'...\n";
    $sqlApplications = "
        CREATE TABLE IF NOT EXISTS `applications` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `post_id` INT(11) NOT NULL,
          `student_id` INT(11) NOT NULL,
          `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
          `message` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `processed_at` TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_apps_post_id` (`post_id`),
          KEY `idx_apps_student_id` (`student_id`),
          FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlApplications);
    echo "   -> Thành công!\n\n";

    // 2. Tạo bảng ai_conversations (Lịch sử chat AI)
    echo "2. Đang kiểm tra / tạo bảng 'ai_conversations'...\n";
    $sqlAiConv = "
        CREATE TABLE IF NOT EXISTS `ai_conversations` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `title` VARCHAR(255) DEFAULT 'Cuộc trò chuyện AI',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ai_conv_user_id` (`user_id`),
          FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlAiConv);
    echo "   -> Thành công!\n\n";

    // 3. Tạo bảng ai_messages (Tin nhắn chat AI)
    echo "3. Đang kiểm tra / tạo bảng 'ai_messages'...\n";
    $sqlAiMsgs = "
        CREATE TABLE IF NOT EXISTS `ai_messages` (
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
    ";
    $pdo->exec($sqlAiMsgs);
    echo "   -> Thành công!\n\n";

    // 4. Kiểm tra cột post_id trong bảng messages
    echo "4. Đang kiểm tra cột 'post_id' trong bảng 'messages'...\n";
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'post_id'");
    $colChk->execute();
    if ((int)$colChk->fetchColumn() === 0) {
        echo "   -> Đang thêm cột 'post_id' vào bảng 'messages'...\n";
        $pdo->exec("ALTER TABLE messages ADD COLUMN post_id INT(11) DEFAULT NULL AFTER receiver_id");
        $pdo->exec("CREATE INDEX idx_messages_post_id ON messages (post_id)");
        echo "   -> Thành công!\n";
    } else {
        echo "   -> Cột 'post_id' đã tồn tại.\n";
    }
    echo "\n";

    // 5. Kiểm tra cột last_message_at trong bảng conversations
    echo "5. Đang kiểm tra cột 'last_message_at' trong bảng 'conversations'...\n";
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'last_message_at'");
    $colChk->execute();
    if ((int)$colChk->fetchColumn() === 0) {
        echo "   -> Đang thêm cột 'last_message_at' vào bảng 'conversations'...\n";
        $pdo->exec("ALTER TABLE conversations ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "   -> Thành công!\n";
    } else {
        echo "   -> Cột 'last_message_at' đã tồn tại.\n";
    }
    echo "\n";

    // 6. Kiểm tra cột assigned_to trong bảng posts
    echo "6. Đang kiểm tra cột 'assigned_to' trong bảng 'posts'...\n";
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
    $colChk->execute();
    if ((int)$colChk->fetchColumn() === 0) {
        echo "   -> Đang thêm cột 'assigned_to' vào bảng 'posts'...\n";
        $pdo->exec("ALTER TABLE posts ADD COLUMN assigned_to INT NULL AFTER user_id");
        // Thêm khóa ngoại cho assigned_to
        try {
            $pdo->exec("ALTER TABLE posts ADD CONSTRAINT fk_posts_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL");
        } catch (Exception $ex) {
            // bỏ qua nếu khóa ngoại lỗi
        }
        echo "   -> Thành công!\n";
    } else {
        echo "   -> Cột 'assigned_to' đã tồn tại.\n";
    }
    echo "\n";

    // 7. Đồng bộ enum status trong bảng posts
    echo "7. Đang đồng bộ hóa ENUM 'status' trong bảng 'posts'...\n";
    $pdo->exec("ALTER TABLE posts MODIFY COLUMN status ENUM('open', 'closed', 'completed', 'inactive', 'taken') DEFAULT 'open'");
    echo "   -> Thành công!\n\n";

    // 8. Cập nhật các cột thanh toán & chu kỳ cho bảng appointments
    echo "8. Đang kiểm tra / thêm các cột thanh toán vào bảng 'appointments'...\n";
    $cols = ['billing_cycle', 'start_date', 'end_date', 'price_per_day'];
    $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'";
    $existingCols = $pdo->query($checkSql)->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('billing_cycle', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN billing_cycle ENUM('daily', 'weekly', 'monthly') DEFAULT 'daily'");
        echo "   -> Thêm cột 'billing_cycle' thành công.\n";
    }
    if (!in_array('start_date', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN start_date DATE DEFAULT NULL");
        echo "   -> Thêm cột 'start_date' thành công.\n";
    }
    if (!in_array('end_date', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN end_date DATE DEFAULT NULL");
        echo "   -> Thêm cột 'end_date' thành công.\n";
    }
    if (!in_array('price_per_day', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN price_per_day DECIMAL(10,2) DEFAULT 150000.00");
        echo "   -> Thêm cột 'price_per_day' thành công.\n";
    }
    if (!in_array('patient_signature', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN patient_signature VARCHAR(255) DEFAULT NULL");
        echo "   -> Thêm cột 'patient_signature' thành công.\n";
    }
    if (!in_array('student_signature', $existingCols)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN student_signature VARCHAR(255) DEFAULT NULL");
        echo "   -> Thêm cột 'student_signature' thành công.\n";
    }
    
    // Đồng bộ lại enum status cho bảng appointments
    $pdo->exec("ALTER TABLE appointments MODIFY COLUMN status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending'");
    echo "   -> Đồng bộ enum trạng thái lịch hẹn thành công.\n\n";

    // 9. Tạo bảng attendance_logs
    echo "9. Đang kiểm tra / tạo bảng 'attendance_logs'...\n";
    $sqlAttendance = "
        CREATE TABLE IF NOT EXISTS `attendance_logs` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `appointment_id` INT(11) NOT NULL,
          `student_id` INT(11) NOT NULL,
          `log_date` DATE NOT NULL,
          `check_in_time` DATETIME DEFAULT NULL,
          `status` ENUM('pending', 'approved', 'rejected', 'day_off') DEFAULT 'pending',
          `daily_notes` TEXT DEFAULT NULL,
          `evidence_image` VARCHAR(255) DEFAULT NULL,
          `rejection_reason` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `idx_appt_date` (`appointment_id`, `log_date`),
          KEY `idx_att_appt_id` (`appointment_id`),
          KEY `idx_att_student_id` (`student_id`),
          FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlAttendance);
    echo "   -> Tạo bảng 'attendance_logs' thành công!\n\n";

    // 10. Tạo thư mục chứa ảnh điểm danh
    echo "10. Đang kiểm tra thư mục uploads/attendance/...\n";
    $dir = __DIR__ . '/uploads/attendance';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
        echo "   -> Tạo thư mục 'uploads/attendance/' thành công.\n\n";
    } else {
        echo "   -> Thư mục đã tồn tại.\n\n";
    }

    // 11. Tạo thư mục chứa ảnh chữ ký
    echo "11. Đang kiểm tra thư mục uploads/signatures/...\n";
    $sigDir = __DIR__ . '/uploads/signatures';
    if (!file_exists($sigDir)) {
        mkdir($sigDir, 0777, true);
        echo "   -> Tạo thư mục 'uploads/signatures/' thành công.\n\n";
    } else {
        echo "   -> Thư mục đã tồn tại.\n\n";
    }

    // 12. Kiểm tra cột start_time và end_time trong bảng appointments
    echo "12. Đang kiểm tra cột 'start_time' và 'end_time' trong bảng 'appointments'...\n";
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'start_time'");
    $colChk->execute();
    if ((int)$colChk->fetchColumn() === 0) {
        echo "   -> Đang thêm cột 'start_time' và 'end_time' vào bảng 'appointments'...\n";
        $pdo->exec("ALTER TABLE appointments ADD COLUMN start_time TIME DEFAULT '08:00:00' AFTER start_date");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN end_time TIME DEFAULT '17:00:00' AFTER end_date");
        echo "   -> Thành công!\n";
    } else {
        echo "   -> Các cột 'start_time' và 'end_time' đã tồn tại.\n";
    }
    echo "\n";

    echo "=== HOÀN TẤT NÂNG CẤP DATABASE THÀNH CÔNG ===\n";

} catch (Exception $e) {
    echo "\n🚨 LỖI TRONG QUÁ TRÌNH NÂNG CẤP:\n" . $e->getMessage() . "\n";
}
