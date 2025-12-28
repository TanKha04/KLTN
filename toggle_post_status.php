<?php
require_once 'config.php';
require_login();

function jsonError($message) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonError('Phương thức không hợp lệ.');
}

$id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$requestedStatus = isset($_POST['status']) ? $_POST['status'] : null;

if ($id <= 0) {
    jsonError('ID không hợp lệ.');
}

// Ensure 'status' column exists and has the correct enum values
try {
    $check = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'status'");
    $check->execute();
    $columnType = $check->fetchColumn();

    if ($columnType === false) {
        // Column doesn't exist, create it including 'taken'
        $pdo->exec("ALTER TABLE posts ADD COLUMN status ENUM('open','taken','inactive','closed') NOT NULL DEFAULT 'open'");
    } else {
        // Ensure the enum includes 'taken' so other code can set it
        if (strpos($columnType, 'taken') === false) {
            // Update enum to include 'taken' without dropping existing values
            $pdo->exec("ALTER TABLE posts CHANGE status status ENUM('open','taken','inactive','closed') NOT NULL DEFAULT 'open'");
        }
    }
} catch (Exception $e) {
    // If INFORMATION_SCHEMA not accessible, ignore; the next query may still work if column exists
}

$stmt = $pdo->prepare('SELECT id, user_id, status FROM posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    jsonError('Tin không tồn tại.');
}
if ((int)$post['user_id'] !== (int)$_SESSION['user_id']) {
    jsonError('Bạn không có quyền cập nhật trạng thái tin này.');
}

$newStatus = $requestedStatus ? $requestedStatus : (($post['status'] === 'open') ? 'closed' : 'open');
// Validate status
if (!in_array($newStatus, ['open', 'closed', 'taken', 'inactive'])) {
    $newStatus = ($post['status'] === 'open') ? 'closed' : 'open';
}
$upd = $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?');
$upd->execute([$newStatus, $id]);

// Trả về JSON cho fetch API requests
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'newStatus' => $newStatus], JSON_UNESCAPED_UNICODE);
exit;
