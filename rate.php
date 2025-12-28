<?php
require_once 'config.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

function rating_json_response(array $payload, int $status = 200): void {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        rating_json_response(['success' => false, 'message' => 'Phương thức không hợp lệ.'], 405);
    }
    header('Location: index.php'); exit;
}
if (!is_logged_in()) {
    if ($isAjax) {
        rating_json_response(['success' => false, 'message' => 'Bạn cần đăng nhập để đánh giá.'], 401);
    }
    header('Location: login.php'); exit;
}
$rated_id = (int)($_POST['rated_id'] ?? 0);
$score = (int)($_POST['score'] ?? 0);
$comment = trim($_POST['comment'] ?? '');
// Basic validation
if (!$rated_id || $score < 1 || $score > 5) {
    if ($isAjax) {
        rating_json_response(['success' => false, 'message' => 'Vui lòng chọn số sao hợp lệ.'], 422);
    }
    header('Location: profile.php?id='.$rated_id); exit;
}

// Optional title from modal
$title = trim($_POST['title'] ?? '');

// Permission: only allow rating the owner if the current user was assigned to one of their posts
// Ensure assigned_to column exists before querying
$allowed = false;
try {
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
    $colChk->execute();
    $hasAssigned = (int)$colChk->fetchColumn() > 0;
    if ($hasAssigned) {
        $perm = $pdo->prepare('SELECT id FROM posts WHERE user_id = ? AND assigned_to = ? AND status = ? LIMIT 1');
        $perm->execute([$rated_id, $_SESSION['user_id'], 'taken']);
        $allowed = (bool)$perm->fetchColumn();
    } else {
        $allowed = false;
    }
} catch (Throwable $e) {
    error_log('rate permission check failed: ' . $e->getMessage());
    $allowed = false;
}
if (!$allowed) {
    // not permitted to rate — redirect back with a message
    $_SESSION['flash_error'] = 'Bạn không có quyền đánh giá người này.';
    if ($isAjax) {
        rating_json_response(['success' => false, 'message' => 'Bạn không có quyền đánh giá người này.'], 403);
    }
    header('Location: profile.php?id='.$rated_id);
    exit;
}

// Limit comment length and include title if provided
$comment = mb_substr($comment, 0, 1000);
if ($title !== '') {
    $comment = trim($title) . "\n\n" . $comment;
}

// If the user already rated this person, update the rating instead of inserting duplicate
$stmt = $pdo->prepare('SELECT id FROM ratings WHERE user_id = ? AND rated_user_id = ?');
$stmt->execute([$_SESSION['user_id'], $rated_id]);
$existing = $stmt->fetchColumn();
if ($existing) {
    $up = $pdo->prepare('UPDATE ratings SET rating = ?, comment = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?');
    $up->execute([$score, $comment, $existing]);
} else {
    $ins = $pdo->prepare('INSERT INTO ratings (user_id,rated_user_id,rating,comment,created_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP)');
    $ins->execute([$_SESSION['user_id'], $rated_id, $score, $comment]);
}

// After save, compute new average and count
$avgStmt = $pdo->prepare('SELECT AVG(rating) AS avg_score, COUNT(*) AS cnt FROM ratings WHERE rated_user_id = ?');
$avgStmt->execute([$rated_id]);
$avgRow = $avgStmt->fetch();
$newAvg = $avgRow['avg_score'] ? round($avgRow['avg_score'],1) : null;
$newCount = (int)($avgRow['cnt'] ?? 0);

// Compute satisfaction percent (ratings >= 4)
$positiveStmt = $pdo->prepare('SELECT COUNT(*) FROM ratings WHERE rated_user_id = ? AND rating >= 4');
$positiveStmt->execute([$rated_id]);
$positiveCount = (int)$positiveStmt->fetchColumn();
$satisfactionPercent = $newCount > 0 ? round(($positiveCount / $newCount) * 100) : null;

// If AJAX request, return JSON with updated info and a snippet
if ($isAjax) {

    $raterStmt = $pdo->prepare('SELECT name, role, avatar, verified FROM users WHERE id = ? LIMIT 1');
    $raterStmt->execute([$_SESSION['user_id']]);
    $raterInfo = $raterStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => $_SESSION['name'], 'role' => $_SESSION['role'] ?? '', 'avatar' => null, 'verified' => 0];

    $roleLabel = '';
    switch ($raterInfo['role'] ?? '') {
        case 'student':
            $roleLabel = 'Sinh viên';
            break;
        case 'patient':
            $roleLabel = 'Bệnh nhân';
            break;
        default:
            $roleLabel = '';
    }

    $avatarHtml = '';
    if (!empty($raterInfo['avatar']) && ($url = public_url_for($raterInfo['avatar']))) {
        $avatarHtml = '<img src="'.htmlspecialchars($url).'" class="rounded-circle" width="32" height="32" alt="'.htmlspecialchars($raterInfo['name']).'">';
    } else {
        $avatarHtml = '<div class="rounded-circle bg-light text-secondary d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:0.85rem;">'.strtoupper(substr($raterInfo['name'],0,1)).'</div>';
    }

    $starsHtml = '';
    for ($i=1;$i<=5;$i++) {
        $starsHtml .= '<i class="'.($i <= $score ? 'fas' : 'far').' fa-star"></i>';
    }

    $snippet = '<div class="review-item mb-3" data-score="'.$score.'" data-role="'.htmlspecialchars($raterInfo['role'] ?? '').'">'
        .'<div class="d-flex align-items-center gap-2 mb-1">'
        .$avatarHtml
        .'<div><strong>'.htmlspecialchars($raterInfo['name']).'</strong>'
        .($roleLabel ? '<small class="text-muted ms-1">'.$roleLabel.'</small>' : '')
        .'</div>'
        .'<small class="text-muted ms-auto">'.date('d/m H:i').'</small>'
        .'</div>'
        .'<div class="text-warning mb-1">'.$starsHtml.'</div>'
        .'<div class="small">'.nl2br(htmlspecialchars($comment)).'</div>'
        .'</div>';

    rating_json_response([
        'success' => true,
        'avg' => $newAvg,
        'count' => $newCount,
        'satisfactionPercent' => $satisfactionPercent,
        'snippet' => $snippet
    ]);
}

header('Location: profile.php?id='.$rated_id);
exit;
