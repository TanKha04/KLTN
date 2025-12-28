<?php
require_once '../config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['user_id'];
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

// Validate phone number (Vietnamese format)
if (!empty($phone) && !preg_match('/^(0|\+84)[0-9]{9,10}$/', preg_replace('/\s+/', '', $phone))) {
    echo json_encode(['success' => false, 'message' => 'Số điện thoại không hợp lệ']);
    exit;
}

try {
    // Kiểm tra và thêm cột phone nếu chưa có
    $checkPhone = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'");
    $checkPhone->execute();
    if ((int)$checkPhone->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL");
    }
    
    // Kiểm tra và thêm cột address nếu chưa có
    $checkAddress = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address'");
    $checkAddress->execute();
    if ((int)$checkAddress->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT NULL");
    }
    
    $stmt = $pdo->prepare('UPDATE users SET phone = ?, address = ? WHERE id = ?');
    $result = $stmt->execute([$phone, $address, $userId]);
    
    if ($result) {
        // Update session
        $_SESSION['phone'] = $phone;
        $_SESSION['address'] = $address;
        echo json_encode(['success' => true, 'message' => 'Cập nhật thông tin thành công']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra']);
    }
} catch (Exception $e) {
    error_log('Update contact error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
