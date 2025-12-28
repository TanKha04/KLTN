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
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập mật khẩu']);
    exit;
}

// Verify password
$stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Mật khẩu không đúng']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Delete related data
    $pdo->prepare('DELETE FROM messages WHERE from_user = ? OR to_user = ?')->execute([$userId, $userId]);
    $pdo->prepare('DELETE FROM favorites WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM ratings WHERE rater_id = ? OR rated_id = ?')->execute([$userId, $userId]);
    $pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$userId]);
    
    // Delete friendships if table exists
    try {
        $pdo->prepare('DELETE FROM friendships WHERE user_id = ? OR friend_id = ?')->execute([$userId, $userId]);
    } catch (Exception $e) {}
    
    // Delete user
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    
    $pdo->commit();
    
    // Destroy session
    session_destroy();
    
    echo json_encode(['success' => true, 'message' => 'Tài khoản đã được xóa']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
}
