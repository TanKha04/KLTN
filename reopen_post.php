<?php
require_once 'config.php';
require_login();

function jsonError($message) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức không hợp lệ.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { jsonError('ID không hợp lệ.'); }

$stmt = $pdo->prepare('SELECT id, user_id, status FROM posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) { jsonError('Tin không tồn tại.'); }
if ((int)$post['user_id'] !== (int)$_SESSION['user_id']) { jsonError('Bạn không có quyền mở lại tin này.'); }

try {
    // Only include assigned_to in the UPDATE if the column actually exists in the table
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
    $colChk->execute();
    $hasAssigned = (int)$colChk->fetchColumn() > 0;
    if ($hasAssigned) {
        $u = $pdo->prepare('UPDATE posts SET status = ?, assigned_to = NULL WHERE id = ?');
        $u->execute(['open', $id]);
    } else {
        $u = $pdo->prepare('UPDATE posts SET status = ? WHERE id = ?');
        $u->execute(['open', $id]);
    }
    $_SESSION['flash_success'] = 'Tin đã được mở lại.';
} catch (Throwable $e) {
    error_log('reopen post error: '.$e->getMessage());
    $_SESSION['flash_error'] = 'Không thể mở tin. Vui lòng thử lại.';
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false);

// Always return JSON for POST requests from fetch API
$success = empty($_SESSION['flash_error']);
$message = $success ? ($_SESSION['flash_success'] ?? 'Tin đã được mở lại.') : ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => $success, 'message' => $message, 'post_id' => $id, 'status' => $success ? 'open' : 'error'], JSON_UNESCAPED_UNICODE);
exit;

?>
