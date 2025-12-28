<?php
/**
 * API xử lý các chức năng bình luận
 * - Thêm bình luận (với reply)
 * - Sửa bình luận
 * - Xóa bình luận
 * - Like/Unlike bình luận
 * - Báo cáo bình luận
 * - Admin: Ẩn/Hiện bình luận
 */

require_once '../config.php';
header('Content-Type: application/json');

// Xử lý JSON input
$jsonInput = file_get_contents('php://input');
$jsonData = json_decode($jsonInput, true);
if ($jsonData) {
    $_POST = array_merge($_POST, $jsonData);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

try {
    switch ($action) {
        case 'add':
            $response = addComment($pdo);
            break;
        case 'edit':
            $response = editComment($pdo);
            break;
        case 'delete':
            $response = deleteComment($pdo);
            break;
        case 'like':
            $response = likeComment($pdo);
            break;
        case 'report':
            $response = reportComment($pdo);
            break;
        case 'toggle_hide':
            $response = toggleHideComment($pdo);
            break;
        case 'get':
            $response = getComments($pdo);
            break;
        default:
            $response = ['success' => false, 'message' => 'Action không hợp lệ'];
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
}

echo json_encode($response);
exit;


// Thêm bình luận mới (hỗ trợ reply)
function addComment($pdo) {
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'Vui lòng đăng nhập'];
    }
    
    $post_id = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $user_id = $_SESSION['user_id'];
    
    if ($post_id <= 0 || empty($content)) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    // Kiểm tra post tồn tại
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Bài viết không tồn tại'];
    }
    
    // Nếu là reply, kiểm tra comment cha tồn tại
    if ($parent_id) {
        $stmt = $pdo->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ?");
        $stmt->execute([$parent_id, $post_id]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Bình luận gốc không tồn tại'];
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)");
    $stmt->execute([$post_id, $user_id, $parent_id, $content]);
    $comment_id = $pdo->lastInsertId();
    
    // Lấy thông tin comment vừa tạo
    $stmt = $pdo->prepare("SELECT c.*, u.name AS author_name, u.avatar AS author_avatar 
                           FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'success' => true, 
        'message' => 'Đã thêm bình luận',
        'comment' => $comment
    ];
}

// Sửa bình luận
function editComment($pdo) {
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'Vui lòng đăng nhập'];
    }
    
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    if ($comment_id <= 0 || empty($content)) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    // Kiểm tra quyền sửa (chỉ chủ comment hoặc admin)
    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comment) {
        return ['success' => false, 'message' => 'Bình luận không tồn tại'];
    }
    
    if ($comment['user_id'] != $user_id && !is_admin_user()) {
        return ['success' => false, 'message' => 'Bạn không có quyền sửa bình luận này'];
    }
    
    $stmt = $pdo->prepare("UPDATE comments SET content = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$content, $comment_id]);
    
    return ['success' => true, 'message' => 'Đã cập nhật bình luận', 'content' => $content];
}

// Xóa bình luận
function deleteComment($pdo) {
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'Vui lòng đăng nhập'];
    }
    
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $user_id = $_SESSION['user_id'];
    
    if ($comment_id <= 0) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    // Kiểm tra quyền xóa
    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comment) {
        return ['success' => false, 'message' => 'Bình luận không tồn tại'];
    }
    
    if ($comment['user_id'] != $user_id && !is_admin_user()) {
        return ['success' => false, 'message' => 'Bạn không có quyền xóa bình luận này'];
    }
    
    // Xóa comment và các reply
    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? OR parent_id = ?");
    $stmt->execute([$comment_id, $comment_id]);
    
    return ['success' => true, 'message' => 'Đã xóa bình luận'];
}


// Like/Unlike bình luận
function likeComment($pdo) {
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'Vui lòng đăng nhập'];
    }
    
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $reaction = $_POST['reaction'] ?? 'like';
    $user_id = $_SESSION['user_id'];
    
    if ($comment_id <= 0) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    // Kiểm tra comment tồn tại
    $stmt = $pdo->prepare("SELECT id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    if (!$stmt->fetch()) {
        return ['success' => false, 'message' => 'Bình luận không tồn tại'];
    }
    
    // Kiểm tra đã like chưa
    $stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
    $stmt->execute([$comment_id, $user_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Unlike
        $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
        $stmt->execute([$comment_id, $user_id]);
        $liked = false;
    } else {
        // Like
        $stmt = $pdo->prepare("INSERT INTO comment_likes (comment_id, user_id, reaction_type) VALUES (?, ?, ?)");
        $stmt->execute([$comment_id, $user_id, $reaction]);
        $liked = true;
    }
    
    // Đếm số like
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?");
    $stmt->execute([$comment_id]);
    $like_count = (int)$stmt->fetchColumn();
    
    return [
        'success' => true, 
        'liked' => $liked,
        'like_count' => $like_count,
        'message' => $liked ? 'Đã thích' : 'Đã bỏ thích'
    ];
}

// Báo cáo bình luận
function reportComment($pdo) {
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'Vui lòng đăng nhập'];
    }
    
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $user_id = $_SESSION['user_id'];
    
    if ($comment_id <= 0 || empty($reason)) {
        return ['success' => false, 'message' => 'Vui lòng chọn lý do báo cáo'];
    }
    
    // Kiểm tra comment tồn tại
    $stmt = $pdo->prepare("SELECT id, user_id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comment) {
        return ['success' => false, 'message' => 'Bình luận không tồn tại'];
    }
    
    // Không cho tự báo cáo
    if ($comment['user_id'] == $user_id) {
        return ['success' => false, 'message' => 'Bạn không thể báo cáo bình luận của mình'];
    }
    
    // Kiểm tra đã báo cáo chưa
    $stmt = $pdo->prepare("SELECT id FROM comment_reports WHERE comment_id = ? AND reporter_id = ? AND status = 'pending'");
    $stmt->execute([$comment_id, $user_id]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Bạn đã báo cáo bình luận này rồi'];
    }
    
    $stmt = $pdo->prepare("INSERT INTO comment_reports (comment_id, reporter_id, reason, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$comment_id, $user_id, $reason, $description]);
    
    return ['success' => true, 'message' => 'Đã gửi báo cáo. Cảm ơn bạn!'];
}

// Admin: Ẩn/Hiện bình luận
function toggleHideComment($pdo) {
    if (!is_admin_user()) {
        return ['success' => false, 'message' => 'Bạn không có quyền thực hiện'];
    }
    
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $hide = isset($_POST['hide']) ? (int)$_POST['hide'] : null;
    
    if ($comment_id <= 0) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    $stmt = $pdo->prepare("SELECT is_hidden FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comment) {
        return ['success' => false, 'message' => 'Bình luận không tồn tại'];
    }
    
    // Nếu có tham số hide, sử dụng nó; nếu không, toggle
    $new_status = ($hide !== null) ? $hide : ($comment['is_hidden'] ? 0 : 1);
    $stmt = $pdo->prepare("UPDATE comments SET is_hidden = ? WHERE id = ?");
    $stmt->execute([$new_status, $comment_id]);
    
    return [
        'success' => true, 
        'is_hidden' => (bool)$new_status,
        'message' => $new_status ? 'Đã ẩn bình luận' : 'Đã hiện bình luận'
    ];
}

// Lấy danh sách bình luận
function getComments($pdo) {
    $post_id = (int)($_GET['post_id'] ?? 0);
    $isAdmin = is_admin_user();
    $currentUserId = is_logged_in() ? $_SESSION['user_id'] : 0;
    
    if ($post_id <= 0) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ'];
    }
    
    $sql = "SELECT c.*, u.name AS author_name, u.avatar AS author_avatar,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) AS like_count,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id AND user_id = ?) AS user_liked
            FROM comments c 
            JOIN users u ON u.id = c.user_id 
            WHERE c.post_id = ?";
    
    if (!$isAdmin) {
        $sql .= " AND (c.is_hidden = 0 OR c.is_hidden IS NULL)";
    }
    
    $sql .= " ORDER BY c.parent_id IS NULL DESC, c.created_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$currentUserId, $post_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tổ chức comments theo cấu trúc cây
    $tree = buildCommentTree($comments);
    
    return ['success' => true, 'comments' => $tree];
}

// Hàm hỗ trợ xây dựng cây bình luận
function buildCommentTree($comments) {
    $indexed = [];
    $tree = [];
    
    foreach ($comments as $c) {
        $c['replies'] = [];
        $indexed[$c['id']] = $c;
    }
    
    foreach ($indexed as $id => $c) {
        if ($c['parent_id'] && isset($indexed[$c['parent_id']])) {
            $indexed[$c['parent_id']]['replies'][] = &$indexed[$id];
        } else {
            $tree[] = &$indexed[$id];
        }
    }
    
    return $tree;
}
