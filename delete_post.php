<?php
require_once 'config.php';
require_login();

// Helper function to check if request expects JSON
function isAjaxRequest() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false);
}

function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    }
    http_response_code(405);
    die('Phương thức không hợp lệ.');
}

$id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
if ($id <= 0) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'ID không hợp lệ.']);
    }
    die('ID không hợp lệ.');
}

$stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
$stmt->execute([$id]);
$ownerId = $stmt->fetchColumn();
if (!$ownerId) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'Tin không tồn tại.']);
    }
    die('Tin không tồn tại.');
}
if ((int)$ownerId !== (int)$_SESSION['user_id']) {
    if (isAjaxRequest()) {
        jsonResponse(['success' => false, 'message' => 'Bạn không có quyền xóa tin này.']);
    }
    die('Bạn không có quyền xóa tin này.');
}

$del = $pdo->prepare('DELETE FROM posts WHERE id = ?');
$del->execute([$id]);

// Return JSON for AJAX requests
if (isAjaxRequest()) {
    jsonResponse(['success' => true, 'message' => 'Đã xóa tin thành công!']);
}

if ($_SESSION['role'] === 'patient') {
    header('Location: dashboard_patient.php?deleted=1');
} else {
    header('Location: dashboard_student.php?deleted=1');
}
exit;
