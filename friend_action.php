<?php
// Handle friend actions (add, accept, reject, remove, block)
declare(strict_types=1);
require_once 'config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$friendId = (int)($_POST['friend_id'] ?? 0);

if ($friendId <= 0 || $friendId === $userId) {
    echo json_encode(['success' => false, 'message' => 'ID không hợp lệ']);
    exit;
}

try {
    switch ($action) {
        case 'send_request':
            // Check if friendship already exists
            $check = $pdo->prepare('SELECT id, status FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
            $check->execute([$userId, $friendId, $friendId, $userId]);
            $existing = $check->fetch();
            
            if ($existing) {
                if ($existing['status'] === 'blocked') {
                    echo json_encode(['success' => false, 'message' => 'Không thể gửi lời mời']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Đã có yêu cầu kết bạn']);
                }
                exit;
            }
            
            $stmt = $pdo->prepare('INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, "pending")');
            $stmt->execute([$userId, $friendId]);
            echo json_encode(['success' => true, 'message' => 'Đã gửi lời mời kết bạn', 'status' => 'pending_sent']);
            break;
            
        case 'accept_request':
            $stmt = $pdo->prepare('UPDATE friendships SET status = "accepted", accepted_at = NOW() WHERE user_id = ? AND friend_id = ? AND status = "pending"');
            $stmt->execute([$friendId, $userId]);
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Đã chấp nhận lời mời kết bạn', 'status' => 'friends']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Không tìm thấy lời mời']);
            }
            break;
            
        case 'reject_request':
            $stmt = $pdo->prepare('DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = "pending"');
            $stmt->execute([$friendId, $userId]);
            echo json_encode(['success' => true, 'message' => 'Đã từ chối lời mời', 'status' => 'none']);
            break;
            
        case 'cancel_request':
            $stmt = $pdo->prepare('DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = "pending"');
            $stmt->execute([$userId, $friendId]);
            echo json_encode(['success' => true, 'message' => 'Đã hủy lời mời kết bạn', 'status' => 'none']);
            break;
            
        case 'unfriend':
            $stmt = $pdo->prepare('DELETE FROM friendships WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = "accepted"');
            $stmt->execute([$userId, $friendId, $friendId, $userId]);
            echo json_encode(['success' => true, 'message' => 'Đã hủy kết bạn', 'status' => 'none']);
            break;
            
        case 'block':
            // Remove existing friendship first
            $pdo->prepare('DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)')->execute([$userId, $friendId, $friendId, $userId]);
            // Add block
            $stmt = $pdo->prepare('INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, "blocked")');
            $stmt->execute([$userId, $friendId]);
            echo json_encode(['success' => true, 'message' => 'Đã chặn người dùng', 'status' => 'blocked']);
            break;
            
        case 'unblock':
            $stmt = $pdo->prepare('DELETE FROM friendships WHERE user_id = ? AND friend_id = ? AND status = "blocked"');
            $stmt->execute([$userId, $friendId]);
            echo json_encode(['success' => true, 'message' => 'Đã bỏ chặn', 'status' => 'none']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ']);
    }
} catch (Throwable $e) {
    error_log('Friend action error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra']);
}
