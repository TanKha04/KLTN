<?php
/**
 * rate_user.php - Lưu đánh giá thành viên
 * Xử lý lưu số sao và lời nhận xét đánh giá giữa sinh viên và bệnh nhân
 */

require_once 'config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rated_user_id = (int)($_POST['rated_user_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if (!$rated_user_id || $rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu đánh giá không hợp lệ (số sao từ 1 đến 5).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($rated_user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Bạn không thể tự đánh giá chính mình.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Kiểm tra xem đối phương có tồn tại không
    $userStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $userStmt->execute([$rated_user_id]);
    $ratedUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ratedUser) {
        echo json_encode(['success' => false, 'message' => 'Người dùng được đánh giá không tồn tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Bảo mật: Chỉ cho phép đánh giá nếu hai bên có ít nhất một ca trực đã HOÀN THÀNH (status = 'completed')
    $checkAppt = $pdo->prepare('
        SELECT COUNT(*) FROM appointments 
        WHERE status = "completed" 
          AND ((patient_id = ? AND student_id = ?) OR (patient_id = ? AND student_id = ?))
    ');
    $checkAppt->execute([$_SESSION['user_id'], $rated_user_id, $rated_user_id, $_SESSION['user_id']]);
    $hasCompletedCa = (int)$checkAppt->fetchColumn() > 0;

    if (!$hasCompletedCa) {
        echo json_encode(['success' => false, 'message' => 'Bạn chỉ có thể đánh giá người dùng sau khi đã hoàn thành ít nhất một lịch hẹn chăm sóc cùng nhau.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lưu hoặc cập nhật đánh giá (dựa trên UNIQUE KEY user_rated)
    $checkRate = $pdo->prepare('SELECT id FROM ratings WHERE user_id = ? AND rated_user_id = ?');
    $checkRate->execute([$_SESSION['user_id'], $rated_user_id]);
    $existing = $checkRate->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $update = $pdo->prepare('UPDATE ratings SET rating = ?, comment = ?, created_at = NOW() WHERE id = ?');
        $update->execute([$rating, $comment, $existing['id']]);
        $message = "Cập nhật đánh giá của bạn thành công!";
    } else {
        $insert = $pdo->prepare('INSERT INTO ratings (user_id, rated_user_id, rating, comment) VALUES (?, ?, ?, ?)');
        $insert->execute([$_SESSION['user_id'], $rated_user_id, $rating, $comment]);
        $message = "Gửi đánh giá thành công! Cảm ơn phản hồi của bạn.";
    }

    // Lấy tên người gửi đánh giá để tạo thông báo
    $senderStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $senderStmt->execute([$_SESSION['user_id']]);
    $sender = $senderStmt->fetch(PDO::FETCH_ASSOC);

    // Tạo thông báo cho đối phương
    $notifTitle = "Bạn nhận được đánh giá mới!";
    $notifMsg = ($sender['name'] ?? 'Thành viên') . " đã gửi cho bạn đánh giá " . $rating . " sao kèm nhận xét: \"" . mb_strimwidth($comment, 0, 50, '...') . "\"";
    $notifLink = "profile.php"; // Xem tại hồ sơ cá nhân

    $insertNotifStmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, \'rating\', ?, ?, ?, 0, NOW())');
    $insertNotifStmt->execute([$rated_user_id, $notifTitle, $notifMsg, $notifLink]);

    echo json_encode([
        'success' => true,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Rate user error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi hệ thống xảy ra: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
