<?php
require_once 'config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tin không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Kiểm tra quyền sở hữu tin
    $stmt = $pdo->prepare('SELECT id, user_id, title FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Tin không tồn tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$post['user_id'] !== (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Bạn không có quyền xem.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $applicants = [];

    // 1. Lấy từ bảng messages (có post_id)
    try {
        $check = $pdo->query("SHOW COLUMNS FROM messages LIKE 'post_id'");
        if ($check->rowCount() > 0) {
            $sql = "SELECT DISTINCT u.id, u.name, u.email, u.phone, u.avatar, MIN(m.created_at) AS first_contact
                    FROM messages m
                    JOIN users u ON u.id = m.sender_id
                    WHERE m.post_id = ? AND m.receiver_id = ? AND m.sender_id != ?
                    GROUP BY u.id, u.name, u.email, u.phone, u.avatar";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$postId, $userId, $userId]);
            
            while ($row = $stmt->fetch()) {
                $applicants[$row['id']] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'] ?: 'Người dùng',
                    'email' => $row['email'] ?: '',
                    'phone' => $row['phone'] ?: '',
                    'avatar' => $row['avatar'] ?: '',
                    'contact_time' => $row['first_contact'] ? date('d/m/Y H:i', strtotime($row['first_contact'])) : null
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('get_applicants messages error: ' . $e->getMessage());
    }

    // 2. Lấy từ bảng direct_messages (qua conversations)
    try {
        $sql = "SELECT DISTINCT u.id, u.name, u.email, u.phone, u.avatar, MIN(dm.created_at) AS first_contact
                FROM conversations c
                JOIN direct_messages dm ON dm.conversation_id = c.id
                JOIN users u ON (
                    (c.user1_id = ? AND u.id = c.user2_id) OR 
                    (c.user2_id = ? AND u.id = c.user1_id)
                )
                WHERE (c.user1_id = ? OR c.user2_id = ?) AND u.id != ?
                GROUP BY u.id, u.name, u.email, u.phone, u.avatar";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
        
        while ($row = $stmt->fetch()) {
            if (!isset($applicants[$row['id']])) {
                $applicants[$row['id']] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'] ?: 'Người dùng',
                    'email' => $row['email'] ?: '',
                    'phone' => $row['phone'] ?: '',
                    'avatar' => $row['avatar'] ?: '',
                    'contact_time' => $row['first_contact'] ? date('d/m/Y H:i', strtotime($row['first_contact'])) : null
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('get_applicants direct_messages error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'post_id' => $postId,
        'post_title' => $post['title'],
        'applicants' => array_values($applicants)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi: ' . $e->getMessage(),
        'applicants' => []
    ], JSON_UNESCAPED_UNICODE);
}
