<?php
/**
 * Script để thêm các cột mới vào bảng users
 * Chạy file này một lần để cập nhật database
 */
require_once 'config.php';

$messages = [];

try {
    // Kiểm tra và thêm cột username
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'username'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `username` VARCHAR(100) DEFAULT NULL AFTER `name`");
        $messages[] = "✓ Đã thêm cột 'username'";
        
        // Thêm unique index cho username
        try {
            $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `idx_username` (`username`)");
            $messages[] = "✓ Đã thêm unique index cho 'username'";
        } catch (Exception $e) {
            $messages[] = "⚠ Index username có thể đã tồn tại";
        }
    } else {
        $messages[] = "• Cột 'username' đã tồn tại";
    }

    // Kiểm tra và thêm cột school
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'school'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `school` VARCHAR(255) DEFAULT NULL AFTER `phone`");
        $messages[] = "✓ Đã thêm cột 'school'";
    } else {
        $messages[] = "• Cột 'school' đã tồn tại";
    }

    // Kiểm tra và thêm cột class_code
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'class_code'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `class_code` VARCHAR(100) DEFAULT NULL AFTER `school`");
        $messages[] = "✓ Đã thêm cột 'class_code'";
    } else {
        $messages[] = "• Cột 'class_code' đã tồn tại";
    }

    echo "<h2>Cập nhật Database thành công!</h2>";
    echo "<ul>";
    foreach ($messages as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul>";
    echo "<p><strong>Bạn có thể xóa file này sau khi chạy xong.</strong></p>";
    echo "<p><a href='register.php'>Quay lại trang đăng ký</a></p>";

} catch (PDOException $e) {
    echo "<h2>Lỗi!</h2>";
    echo "<p>Không thể cập nhật database: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
