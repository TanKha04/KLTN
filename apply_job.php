<?php
/**
 * apply_job.php - Xử lý ứng tuyển công việc
 * Sinh viên gửi ứng tuyển vào tin tuyển dụng của bệnh nhân
 */

require_once 'config.php';

header('Content-Type: application/json');

// Kiểm tra đăng nhập
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Bạn cần đăng nhập để ứng tuyển.']);
    exit;
}

// Kiểm tra phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

// Kiểm tra role - chỉ sinh viên mới được ứng tuyển
if (($_SESSION['role'] ?? '') !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Chỉ sinh viên mới có thể ứng tuyển.']);
    exit;
}

$postId = (int)($_POST['post_id'] ?? 0);
$message = trim($_POST['message'] ?? '');
$userId = $_SESSION['user_id'];

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID bài đăng không hợp lệ.']);
    exit;
}

try {
    // Lấy thông tin bài đăng
    $postStmt = $pdo->prepare('SELECT id, user_id, title, type, status FROM posts WHERE id = ?');
    $postStmt->execute([$postId]);
    $post = $postStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) {
        echo json_encode(['success' => false, 'message' => 'Bài đăng không tồn tại.']);
        exit;
    }
    
    // Kiểm tra loại bài đăng - chỉ ứng tuyển vào tin tuyển dụng
    if ($post['type'] !== 'recruitment') {
        echo json_encode(['success' => false, 'message' => 'Bạn chỉ có thể ứng tuyển vào tin tuyển dụng.']);
        exit;
    }
    
    // Kiểm tra trạng thái bài đăng
    if ($post['status'] !== 'open') {
        echo json_encode(['success' => false, 'message' => 'Tin này đã đóng hoặc không còn nhận ứng tuyển.']);
        exit;
    }
    
    // Không cho phép ứng tuyển vào tin của chính mình
    if ($post['user_id'] == $userId) {
        echo json_encode(['success' => false, 'message' => 'Bạn không thể ứng tuyển vào tin của chính mình.']);
        exit;
    }
    
    // Kiểm tra đã ứng tuyển chưa
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND post_id = ? AND message LIKE 'Sinh viên ứng tuyển:%'");
    $checkStmt->execute([$userId, $postId]);
    if ((int)$checkStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Bạn đã ứng tuyển vào tin này rồi.']);
        exit;
    }
    
    // Lấy thông tin sinh viên
    $userStmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    // Tạo nội dung tin nhắn ứng tuyển
    $applyMessage = "Sinh viên ứng tuyển: " . $user['name'];
    if (!empty($message)) {
        $applyMessage .= "\n\nLời nhắn: " . $message;
    }
    if (!empty($user['phone'])) {
        $applyMessage .= "\n\nSĐT: " . $user['phone'];
    }
    if (!empty($user['email'])) {
        $applyMessage .= "\nEmail: " . $user['email'];
    }
    
    // Lưu tin nhắn ứng tuyển
    $insertStmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, post_id, message, created_at) VALUES (?, ?, ?, ?, NOW())');
    $insertStmt->execute([$userId, $post['user_id'], $postId, $applyMessage]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Ứng tuyển thành công! Người đăng tin sẽ nhận được thông báo của bạn.'
    ]);
    
} catch (Throwable $e) {
    error_log('Apply job error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra. Vui lòng thử lại sau.']);
}
