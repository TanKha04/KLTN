<?php
require_once 'config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method.');
}

$post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$comment_text = isset($_POST['comment']) ? trim($_POST['comment']) : '';
$parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
$user_id = $_SESSION['user_id'];

if ($post_id <= 0 || empty($comment_text)) {
    // Redirect back with an error message (or handle more gracefully)
    header('Location: view_post.php?id=' . $post_id . '&error=empty_comment');
    exit;
}

// Ensure the post exists
$stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
$stmt->execute([$post_id]);
if ($stmt->rowCount() == 0) {
    die('Post does not exist.');
}

// Insert comment
$sql = "INSERT INTO comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);

try {
    $stmt->execute([$post_id, $user_id, $parent_id, $comment_text]);
    header('Location: view_post.php?id=' . $post_id . '#comments-section');
    exit;
} catch (PDOException $e) {
    // Log error and redirect
    error_log('Comment submission failed: ' . $e->getMessage());
    header('Location: view_post.php?id=' . $post_id . '&error=db_error');
    exit;
}
