<?php
require_once '../config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['user_id'];
$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Mật khẩu xác nhận không khớp']);
    exit;
}

if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự']);
    exit;
}

// Verify current password
$stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !password_verify($currentPassword, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không đúng']);
    exit;
}

// Update password
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$updateStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
$result = $updateStmt->execute([$hashedPassword, $userId]);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
} else {
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra, vui lòng thử lại']);
}
