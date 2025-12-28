<?php
require_once 'config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$post_id = (int)($_POST['post_id'] ?? 0);
$selected_user = (int)($_POST['selected_user'] ?? $_POST['user_id'] ?? $_POST['applicant_id'] ?? 0);
$note = trim($_POST['note'] ?? '');
$customMessage = trim($_POST['message'] ?? '');

// Helper function for JSON response
function jsonError($message) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$post_id || !$selected_user) {
    jsonError('Thiếu thông tin bài đăng hoặc người dùng.');
}

// fetch post and verify ownership
$stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    jsonError('Tin không tồn tại.');
}
if ($post['user_id'] != $_SESSION['user_id']) {
    jsonError('Bạn không có quyền thực hiện thao tác này.');
}

try {
    // mark post as taken
    $u = $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?');
    $u->execute(['taken', $post_id]);
    if ($u->rowCount() === 0) {
        // No rows updated — check current status
        $check = $pdo->prepare('SELECT status FROM posts WHERE id = ?');
        $check->execute([$post_id]);
        $current = $check->fetchColumn();
        if ($current === 'taken') {
            // already taken
            $_SESSION['flash_error'] = 'Tin này đã được nhận trước đó.';
            throw new Exception('Post already taken');
        }

        // Try to ensure enum includes 'taken' (best-effort)
        try {
            $col = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'status'");
            $col->execute();
            $columnType = $col->fetchColumn();
            if ($columnType !== false && strpos($columnType, 'taken') === false) {
                // alter enum to include 'taken'
                $pdo->exec("ALTER TABLE posts CHANGE status status ENUM('open','taken','inactive','closed') NOT NULL DEFAULT 'open'");
            }
        } catch (Throwable $e) {
            error_log('accept applicant enum alter failed: ' . $e->getMessage());
        }

        // retry update once
        $u->execute(['taken', $post_id]);
        if ($u->rowCount() === 0) {
            throw new Exception('Cập nhật trạng thái thất bại (không thể cập nhật trạng thái sang taken).');
        }
    }

    // insert a message to notify the selected user
    if (!empty($customMessage)) {
        $msg = $customMessage;
    } else {
        $msg = 'Bạn đã được chọn nhận việc cho tin: ' . ($post['title'] ?? '') . '. ' . ($note ? "\nGhi chú: " . $note : '');
    }
    $ins = $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,post_id,message) VALUES (?,?,?,?)');
    $ins->execute([$_SESSION['user_id'], $selected_user, $post_id, $msg]);

    // Try to persist the assigned user on the post (add column if missing)
    try {
        // Check if column exists
        $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
        $colChk->execute();
        $hasAssigned = (int)$colChk->fetchColumn() > 0;
        if (!$hasAssigned) {
            // add column (nullable)
            $pdo->exec("ALTER TABLE posts ADD COLUMN assigned_to INT NULL AFTER user_id");
        }
        // update the post with assigned_to
        $a = $pdo->prepare('UPDATE posts SET assigned_to = ? WHERE id = ?');
        $a->execute([$selected_user, $post_id]);
    } catch (Throwable $e) {
        error_log('accept applicant assigned_to update failed: ' . $e->getMessage());
        // not fatal for user flow
    }

    $_SESSION['flash_success'] = 'Đã chọn người nhận việc và thông báo đã được gửi.';
} catch (Throwable $e) {
    error_log('accept applicant error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Không thể hoàn tất thao tác. Vui lòng thử lại.';
}

// Always return JSON response
$success = empty($_SESSION['flash_error']);
$message = $success ? ($_SESSION['flash_success'] ?? 'Đã chọn người nhận việc thành công.') : ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => $success, 'message' => $message, 'post_id' => $post_id, 'status' => $success ? 'taken' : 'error'], JSON_UNESCAPED_UNICODE);
exit;

?>
