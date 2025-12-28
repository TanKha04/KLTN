<?php
require_once '../config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$showPhone = isset($input['show_phone']) ? (int)$input['show_phone'] : 1;
$showEmail = isset($input['show_email']) ? (int)$input['show_email'] : 1;
$allowMessages = isset($input['allow_messages']) ? (int)$input['allow_messages'] : 1;

try {
    // Kiểm tra và thêm các cột nếu chưa có
    $columns = ['show_phone', 'show_email', 'allow_messages'];
    foreach ($columns as $col) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
        $check->execute([$col]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col TINYINT(1) DEFAULT 1");
        }
    }
    
    $stmt = $pdo->prepare('UPDATE users SET show_phone = ?, show_email = ?, allow_messages = ? WHERE id = ?');
    $result = $stmt->execute([$showPhone, $showEmail, $allowMessages, $userId]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Đã lưu cài đặt quyền riêng tư']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra']);
    }
} catch (Exception $e) {
    error_log('Update privacy error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
