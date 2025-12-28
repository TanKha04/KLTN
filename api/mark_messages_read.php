<?php
require_once '../config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'];

try {
    // Đảm bảo cột is_read tồn tại
    $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'is_read'");
    $checkCol->execute();
    if ((int)$checkCol->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    }
    
    // Đánh dấu tất cả tin nhắn gửi đến user hiện tại là đã đọc
    $stmt = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE to_user = ? AND is_read = 0');
    $stmt->execute([$userId]);
    $updated = $stmt->rowCount();
    
    echo json_encode(['success' => true, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mark_messages_read error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống'], JSON_UNESCAPED_UNICODE);
}
