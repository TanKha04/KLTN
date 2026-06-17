<?php
// Create friendships table
require_once 'config.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `friendships` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `friend_id` INT NOT NULL,
            `status` ENUM('pending','accepted','blocked') DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `accepted_at` TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_friendship (user_id, friend_id),
            INDEX idx_friendships_user (user_id, status),
            INDEX idx_friendships_friend (friend_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Bảng friendships đã được tạo thành công!";
} catch (PDOException $e) {
    echo "❌ Lỗi: " . $e->getMessage();
}
