<?php
require_once 'config.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$action = $_POST['action'] ?? 'add';
$redirect = $_POST['redirect'] ?? '';

if ($postId <= 0) {
    redirect_with_flag('fav_error', 'invalid');
}

if (empty($redirect) || preg_match('#^(?:https?:)?//#i', $redirect)) {
    $redirect = 'view_post.php?id=' . $postId;
}

try {
    $postCheck = $pdo->prepare('SELECT id, user_id FROM posts WHERE id = ? LIMIT 1');
    $postCheck->execute([$postId]);
    $postRow = $postCheck->fetch(PDO::FETCH_ASSOC);
    if (!$postRow) {
        redirect_with_flag('fav_error', 'missing', $redirect);
    }
    if ((int)$postRow['user_id'] === $userId) {
        redirect_with_flag('fav_error', 'self', $redirect);
    }

    if ($action === 'remove') {
        $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND post_id = ?');
        $stmt->execute([$userId, $postId]);
        $redirect = append_query_params($redirect, ['fav' => 'removed']);
    } else {
        $stmt = $pdo->prepare('INSERT INTO favorites (user_id, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_at = created_at');
        $stmt->execute([$userId, $postId]);
        $redirect = append_query_params($redirect, ['fav' => 'added']);
    }
} catch (Throwable $e) {
    error_log('toggle_favorite error: ' . $e->getMessage());
    $redirect = append_query_params($redirect, ['fav_error' => 'db']);
}

header('Location: ' . $redirect);
exit;

function append_query_params(string $url, array $params): string {
    if (!$params) {
        return $url;
    }

    $separator = strpos($url, '?') === false ? '?' : '&';
    foreach ($params as $key => $value) {
        $url .= $separator . rawurlencode($key) . '=' . rawurlencode($value);
        $separator = '&';
    }

    return $url;
}

function redirect_with_flag(string $param, string $value, string $base = null): void {
    $target = $base;
    if ($target === null) {
        global $postId;
        $target = 'view_post.php?id=' . max(0, (int)$postId);
    }

    $target = append_query_params($target, [$param => $value]);
    header('Location: ' . $target);
    exit;
}
