<?php
require_once 'config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
}

$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$message = trim($_POST['message'] ?? '');

if ($postId <= 0 || $userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
}

// Kiểm tra quyền sở hữu tin
$stmt = $pdo->prepare('SELECT id, user_id, title FROM posts WHERE id = ?');
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) {
    jsonResponse(['success' => false, 'message' => 'Tin không tồn tại.']);
}

if ((int)$post['user_id'] !== (int)$_SESSION['user_id']) {
    jsonResponse(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.']);
}

// Lấy thông tin người bị từ chối
$userStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$targetUser = $userStmt->fetch();

if (!$targetUser) {
    jsonResponse(['success' => false, 'message' => 'Người dùng không tồn tại.']);
}

// Gửi tin nhắn thông báo từ chối
if (empty($message)) {
    $message = 'Cảm ơn bạn đã quan tâm đến tin "' . $post['title'] . '". Rất tiếc vị trí này đã có người phù hợp hơn. Chúc bạn may mắn!';
}

try {
    $insertMsg = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, post_id, message, created_at) VALUES (?, ?, ?, ?, NOW())');
    $insertMsg->execute([$_SESSION['user_id'], $userId, $postId, $message]);
    
    jsonResponse(['success' => true, 'message' => 'Đã gửi thông báo từ chối.']);
} catch (Throwable $e) {
    error_log('reject_applicant error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Không thể gửi tin nhắn.']);
}
