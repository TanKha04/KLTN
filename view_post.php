<?php
require_once 'config.php';

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.email AS author_email, u.verified AS author_verified, u.avatar AS author_avatar, u.role AS author_role FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? LIMIT 1');
$stmt->execute([$postId]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    require_once 'header.php';
    echo '<div class="alert alert-warning">Bài đăng không tồn tại hoặc đã bị xóa.</div>';
    require_once 'footer.php';
    exit;
}

$loggedIn = is_logged_in();
$isAdmin = is_admin_user();
$currentUserId = $loggedIn ? (int)$_SESSION['user_id'] : null;
$isOwner = $loggedIn && (int)($post['user_id'] ?? 0) === $currentUserId;
$isFavorite = false;
if ($loggedIn && !$isOwner) {
    try {
        $favStmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND post_id = ? LIMIT 1');
        $favStmt->execute([$currentUserId, $postId]);
        $isFavorite = (bool)$favStmt->fetchColumn();
    } catch (Throwable $e) {}
}

$favoriteStatus = $_GET['fav'] ?? null;
$favoriteError = $_GET['fav_error'] ?? null;
$favoriteLoginUrl = 'login.php?redirect=' . urlencode('view_post.php?id=' . $postId);
$areaValue = trim((string)($post['area'] ?? ''));
$areaIsLink = filter_var($areaValue, FILTER_VALIDATE_URL) !== false;

// Tách kỹ năng từ content nếu có
$content = $post['content'];
$skills = [];
if (preg_match('/Kỹ năng nổi bật:\s*(.+?)(?:\n|$)/i', $content, $matches)) {
    $skillsText = trim($matches[1]);
    $skills = array_map('trim', preg_split('/[,;]/', $skillsText));
    $content = preg_replace('/Kỹ năng nổi bật:\s*.+?(?:\n|$)/i', '', $content);
}

// Load comments
$comments = [];
try {
    $showAll = $isAdmin;
    $sql = 'SELECT c.id, c.content AS comment, c.parent_id, c.is_hidden, c.created_at, c.updated_at, c.user_id, 
            u.name AS author_name, u.avatar AS author_avatar,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) AS like_count
            FROM comments c JOIN users u ON u.id=c.user_id WHERE c.post_id = ?';
    if (!$showAll) { $sql .= ' AND (c.is_hidden IS NULL OR c.is_hidden = 0)'; }
    $sql .= ' ORDER BY c.parent_id IS NULL DESC, c.created_at ASC';
    $cstmt = $pdo->prepare($sql);
    $cstmt->execute([$postId]);
    $comments = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Lấy danh sách comment đã like của user hiện tại
    $userLikedComments = [];
    if ($loggedIn) {
        $likeStmt = $pdo->prepare('SELECT comment_id FROM comment_likes WHERE user_id = ?');
        $likeStmt->execute([$currentUserId]);
        $userLikedComments = $likeStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) { $comments = []; $userLikedComments = []; }

require_once 'header.php';

// Hàm render comment
function renderComment($c, $repliesMap, $currentUserId, $isAdmin, $loggedIn, $userLikedComments) {
    $isOwner = $currentUserId && $c['user_id'] == $currentUserId;
    $isHidden = !empty($c['is_hidden']);
    $isLiked = in_array($c['id'], $userLikedComments);
    $likeCount = (int)($c['like_count'] ?? 0);
    $replies = $repliesMap[$c['id']] ?? [];
    
    ob_start();
    ?>
    <div class="vp-comment <?php echo $isHidden ? 'vp-comment-hidden' : ''; ?>" data-comment-id="<?php echo $c['id']; ?>">
        <div class="vp-comment-avatar">
            <?php if (!empty($c['author_avatar'])): ?>
                <img src="<?php echo htmlspecialchars($c['author_avatar']); ?>" alt="">
            <?php else: ?>
                <?php echo strtoupper(substr($c['author_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <div class="vp-comment-content">
            <div class="vp-comment-header">
                <span class="vp-comment-author">
                    <?php echo htmlspecialchars($c['author_name']); ?>
                    <?php if ($isHidden && $isAdmin): ?>
                        <span class="vp-comment-hidden-badge"><i class="bi bi-eye-slash"></i> Đã ẩn</span>
                    <?php endif; ?>
                </span>
                <span class="vp-comment-date">
                    <i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?>
                    <?php if (!empty($c['updated_at'])): ?>
                        <span class="vp-comment-edited">(đã sửa)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="vp-comment-text" id="comment-text-<?php echo $c['id']; ?>"><?php echo nl2br(htmlspecialchars($c['comment'])); ?></div>
            
            <!-- Comment Actions -->
            <div class="vp-comment-actions">
                <?php if ($loggedIn): ?>
                    <button class="vp-comment-action <?php echo $isLiked ? 'liked' : ''; ?>" onclick="likeComment(<?php echo $c['id']; ?>, this)">
                        <i class="bi bi-heart<?php echo $isLiked ? '-fill' : ''; ?>"></i>
                        <span class="like-count"><?php echo $likeCount > 0 ? $likeCount : ''; ?></span>
                    </button>
                    <button class="vp-comment-action" onclick="showReplyForm(<?php echo $c['id']; ?>)">
                        <i class="bi bi-reply"></i> Trả lời
                    </button>
                <?php else: ?>
                    <span class="vp-comment-action" style="cursor:default;">
                        <i class="bi bi-heart"></i> <?php echo $likeCount > 0 ? $likeCount : ''; ?>
                    </span>
                <?php endif; ?>
                
                <?php if ($isOwner): ?>
                    <button class="vp-comment-action" onclick="editComment(<?php echo $c['id']; ?>)">
                        <i class="bi bi-pencil"></i> Sửa
                    </button>
                    <button class="vp-comment-action" onclick="deleteComment(<?php echo $c['id']; ?>)">
                        <i class="bi bi-trash"></i> Xóa
                    </button>
                <?php endif; ?>
                
                <?php if ($loggedIn && !$isOwner): ?>
                    <button class="vp-comment-action" onclick="showReportModal(<?php echo $c['id']; ?>)">
                        <i class="bi bi-flag"></i> Báo cáo
                    </button>
                <?php endif; ?>
                
                <?php if ($isAdmin): ?>
                    <button class="vp-comment-action" onclick="toggleHideComment(<?php echo $c['id']; ?>, this)">
                        <i class="bi bi-<?php echo $isHidden ? 'eye' : 'eye-slash'; ?>"></i>
                        <?php echo $isHidden ? 'Hiện' : 'Ẩn'; ?>
                    </button>
                    <?php if (!$isOwner): ?>
                    <button class="vp-comment-action" onclick="deleteComment(<?php echo $c['id']; ?>)" style="color:#ef4444;">
                        <i class="bi bi-trash"></i> Xóa (Admin)
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Reply Form -->
            <div class="vp-reply-form" id="reply-form-<?php echo $c['id']; ?>">
                <textarea placeholder="Viết trả lời..." id="reply-text-<?php echo $c['id']; ?>"></textarea>
                <div class="vp-reply-form-actions">
                    <button class="vp-reply-btn secondary" onclick="hideReplyForm(<?php echo $c['id']; ?>)">Hủy</button>
                    <button class="vp-reply-btn primary" onclick="submitReply(<?php echo $c['id']; ?>)">
                        <i class="bi bi-send"></i> Gửi
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($replies)): ?>
    <div class="vp-comment-replies">
        <?php foreach ($replies as $reply): ?>
            <?php echo renderComment($reply, $repliesMap, $currentUserId, $isAdmin, $loggedIn, $userLikedComments); ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}
?>

<style>
/* Page Layout */
.vp-page {
    background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
    min-height: 100vh;
    margin: -1.5rem -0.75rem;
    padding: 2rem 1rem;
}
.vp-container {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 2rem;
}

/* Hero Section */
.vp-hero {
    grid-column: 1 / -1;
    background: #3b82f6;
    border-radius: 28px;
    padding: 2.5rem;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(59, 130, 246, 0.3);
    min-height: 200px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 2rem;
}
.vp-hero::before {
    display: none;
}
.vp-logo-frame {
    width: 180px;
    height: 180px;
    background: white;
    border-radius: 20px;
    padding: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    flex-shrink: 0;
}
.vp-logo-frame img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.vp-date-corner {
    display: none;
}
.vp-date-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    opacity: 0.9;
}
.vp-date-item i {
    color: rgba(255,255,255,0.8);
}
}
.vp-hero-content {
    flex: 1;
    position: relative;
    z-index: 1;
}
.vp-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    opacity: 0.9;
    margin-bottom: 1.5rem;
}
.vp-breadcrumb a {
    color: white;
    text-decoration: none;
    opacity: 0.8;
    transition: opacity 0.3s;
}
.vp-breadcrumb a:hover { opacity: 1; }
.vp-breadcrumb i { font-size: 0.7rem; }
.vp-title {
    font-size: 2.25rem;
    font-weight: 800;
    margin: 0 0 1.5rem;
    line-height: 1.3;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.vp-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    align-items: center;
}
.vp-author-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    padding: 0.75rem 1.25rem;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.2);
}
.vp-author-avatar {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, #fff 0%, #f0f0f0 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.25rem;
    color: #3b82f6;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    overflow: hidden;
}
.vp-author-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vp-author-name {
    font-weight: 700;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.vp-verified { color: #10b981; }
.vp-author-role {
    font-size: 0.85rem;
    opacity: 0.85;
    margin-top: 0.25rem;
}
.vp-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
    background: rgba(255, 255, 255, 0.9);
    color: #1e293b;
    padding: 0.5rem 1rem;
    border-radius: 12px;
    font-weight: 600;
}
.vp-status-badge {
    padding: 0.5rem 1.25rem;
    border-radius: 25px;
    font-weight: 700;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.vp-status-badge.open { background: #10b981; color: white; }
.vp-status-badge.closed { background: rgba(255,255,255,0.2); color: white; }
.vp-status-badge.taken { background: #3b82f6; color: white; }

/* Main Content */
.vp-main { display: flex; flex-direction: column; gap: 1.5rem; }

/* Card Base */
.vp-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.06);
    overflow: hidden;
}
.vp-card-header {
    padding: 1.25rem 1.75rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.vp-card-header i {
    font-size: 1.25rem;
    color: #3b82f6;
}
.vp-card-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
}
.vp-card-body { padding: 1.75rem; }

/* Tags Section */
.vp-tags-section .vp-card-body {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.vp-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1.25rem;
    border-radius: 25px;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s;
}
.vp-tag.type {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}
.vp-tag.category {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}
.vp-tag.area {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #047857;
    cursor: pointer;
}
.vp-tag.area:hover { transform: translateY(-2px); }
.vp-tag.area a { color: inherit; text-decoration: none; }

/* Skills Section */
.vp-skills-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.vp-skill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    color: #1d4ed8;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.9rem;
    border: 1px solid #bfdbfe;
    transition: all 0.3s;
}
.vp-skill:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(59, 130, 246, 0.2);
}
.vp-skill i { color: #3b82f6; }

/* Content Section */
.vp-content {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #334155;
}

/* Image */
.vp-image-wrapper {
    margin-bottom: 1.5rem;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.vp-image {
    width: 100%;
    max-height: 400px;
    object-fit: contain;
    background: #f8fafc;
    display: block;
}

/* Video Section */
.vp-video-section {
    margin-bottom: 1.5rem;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border-radius: 20px;
    padding: 1.5rem;
    border: 2px solid #a7f3d0;
}
.vp-video-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    font-weight: 700;
    color: #047857;
    font-size: 1.1rem;
}
.vp-video-header i { font-size: 1.5rem; color: #10b981; }
.vp-video-wrapper {
    border-radius: 16px;
    overflow: hidden;
    background: #000;
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}
.vp-video {
    width: 100%;
    max-height: 400px;
    display: block;
}

/* Sidebar */
.vp-sidebar { display: flex; flex-direction: column; gap: 1.5rem; }

/* Action Card */
.vp-action-card {
    background: white;
    border-radius: 24px;
    padding: 1.75rem;
    box-shadow: 0 10px 40px rgba(0,0,0,0.06);
}
.vp-action-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.vp-action-title i { color: #3b82f6; }
.vp-action-buttons {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.vp-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 1rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.3s;
    border: none;
    cursor: pointer;
}
.vp-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.35);
}
.vp-btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(59, 130, 246, 0.45);
    color: white;
}
.vp-btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.35);
}
.vp-btn-success:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(16, 185, 129, 0.45);
    color: white;
}
.vp-btn-outline {
    background: white;
    color: #64748b;
    border: 2px solid #e2e8f0;
}
.vp-btn-outline:hover {
    background: #f8fafc;
    border-color: #3b82f6;
    color: #3b82f6;
}
.vp-btn-fav {
    background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
    color: #991b1b;
}
.vp-btn-fav:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25);
}
.vp-btn-fav.active {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}
.vp-btn-disabled {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
}

/* Contact Card */
.vp-contact-card {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-radius: 24px;
    padding: 1.75rem;
    border: 2px solid #a7f3d0;
}
.vp-contact-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.vp-contact-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
}
.vp-contact-label {
    font-size: 0.8rem;
    color: #047857;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}
.vp-contact-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #065f46;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.vp-copy-btn {
    background: white;
    border: none;
    padding: 0.5rem;
    border-radius: 8px;
    cursor: pointer;
    color: #047857;
    transition: all 0.2s;
}
.vp-copy-btn:hover {
    background: #d1fae5;
}

/* Author Card */
.vp-author-sidebar {
    background: white;
    border-radius: 24px;
    padding: 1.75rem;
    box-shadow: 0 10px 40px rgba(0,0,0,0.06);
    text-align: center;
}
.vp-author-avatar-lg {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 2rem;
    color: white;
    margin: 0 auto 1rem;
    box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
    overflow: hidden;
}
.vp-author-avatar-lg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vp-author-name-lg {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.vp-author-email-lg {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 1rem;
}
.vp-author-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #047857;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

/* Comments */
.vp-comments { grid-column: 1 / -1; }
.vp-comments-body { padding: 1.5rem 1.75rem; }
.vp-comment {
    display: flex;
    gap: 1rem;
    padding: 1.25rem;
    background: linear-gradient(135deg, #fafbff 0%, #f5f7ff 100%);
    border-radius: 16px;
    margin-bottom: 1rem;
    transition: all 0.3s;
}
.vp-comment:hover {
    transform: translateX(5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}
.vp-comment:last-child { margin-bottom: 0; }
.vp-comment-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
    overflow: hidden;
}
.vp-comment-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vp-comment-content { flex: 1; }
.vp-comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.vp-comment-author { font-weight: 700; color: #1e293b; }
.vp-comment-date { font-size: 0.8rem; color: #94a3b8; }
.vp-comment-text { color: #475569; line-height: 1.6; }
.vp-comment-actions {
    display: flex;
    gap: 1rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
}
.vp-comment-action {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 8px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    background: #f1f5f9;
    color: #64748b;
}
.vp-comment-action:hover { background: #e2e8f0; color: #475569; }
.vp-comment-action.liked { background: #fee2e2; color: #ef4444; }
.vp-comment-action.liked:hover { background: #fecaca; }
.vp-comment-action i { font-size: 0.9rem; }
.vp-comment-replies {
    margin-left: 3.5rem;
    margin-top: 0.75rem;
    padding-left: 1rem;
    border-left: 2px solid #e2e8f0;
}
.vp-comment-replies .vp-comment {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    margin-bottom: 0.75rem;
    padding: 1rem;
}
.vp-comment-hidden {
    opacity: 0.5;
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%) !important;
}
.vp-comment-hidden-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.5rem;
    background: #ef4444;
    color: white;
    border-radius: 4px;
    font-size: 0.7rem;
    margin-left: 0.5rem;
}
.vp-comment-edited {
    font-size: 0.75rem;
    color: #94a3b8;
    font-style: italic;
}
.vp-reply-form {
    margin-top: 0.75rem;
    padding: 1rem;
    background: #f8fafc;
    border-radius: 12px;
    display: none;
}
.vp-reply-form.show { display: block; }
.vp-reply-form textarea {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.9rem;
    resize: none;
    min-height: 60px;
}
.vp-reply-form textarea:focus {
    outline: none;
    border-color: #3b82f6;
}
.vp-reply-form-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
    justify-content: flex-end;
}
.vp-reply-btn {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}
.vp-reply-btn.primary {
    background: #3b82f6;
    color: white;
}
.vp-reply-btn.primary:hover { background: #2563eb; }
.vp-reply-btn.secondary {
    background: #e2e8f0;
    color: #64748b;
}
.vp-reply-btn.secondary:hover { background: #cbd5e1; }
.vp-no-comments {
    text-align: center;
    padding: 2rem;
    color: #94a3b8;
}
.vp-no-comments i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    display: block;
}
.vp-comment-form {
    padding: 1.5rem 1.75rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-top: 1px solid #e2e8f0;
}
.vp-comment-form label {
    font-weight: 600;
    color: #475569;
    margin-bottom: 0.75rem;
    display: block;
}
.vp-comment-form textarea {
    width: 100%;
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    resize: vertical;
    min-height: 100px;
    transition: all 0.3s;
}
.vp-comment-form textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}
.vp-comment-form .btn-submit {
    margin-top: 1rem;
    padding: 0.85rem 2rem;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}
.vp-comment-form .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
}
.vp-login-prompt {
    text-align: center;
    padding: 1.5rem;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 14px;
    color: #1e40af;
}
.vp-login-prompt a { color: #1d4ed8; font-weight: 600; }

/* Alert */
.vp-alert {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-radius: 14px;
    font-weight: 500;
    border-left: 4px solid;
}
.vp-alert.success { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; border-left-color: #10b981; }
.vp-alert.info { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border-left-color: #3b82f6; }
.vp-alert.warning { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; border-left-color: #f59e0b; }
.vp-alert.danger { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; border-left-color: #ef4444; }

/* Responsive */
@media (max-width: 900px) {
    .vp-container {
        grid-template-columns: 1fr;
    }
    .vp-hero { grid-column: 1; }
    .vp-comments { grid-column: 1; }
    .vp-sidebar { order: -1; }
}
@media (max-width: 600px) {
    .vp-page { padding: 1rem 0.5rem; }
    .vp-hero { padding: 1.5rem; border-radius: 20px; }
    .vp-title { font-size: 1.5rem; }
    .vp-hero-meta { flex-direction: column; align-items: flex-start; gap: 1rem; }
    .vp-card-body { padding: 1.25rem; }
}

/* Apply Button Style */
.vp-btn-apply {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35);
    animation: pulse-apply 2s infinite;
}
.vp-btn-apply:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(245, 158, 11, 0.45);
    color: white;
}
@keyframes pulse-apply {
    0%, 100% { box-shadow: 0 8px 25px rgba(245, 158, 11, 0.35); }
    50% { box-shadow: 0 8px 35px rgba(245, 158, 11, 0.55); }
}

/* Apply Modal */
.apply-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(5px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.apply-modal-overlay.show {
    display: flex;
}
.apply-modal {
    background: white;
    border-radius: 24px;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 25px 80px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease-out;
    overflow: hidden;
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-30px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.apply-modal-header {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    padding: 1.5rem;
    color: white;
    position: relative;
}
.apply-modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.apply-modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: rgba(255,255,255,0.2);
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: white;
    font-size: 1.25rem;
    cursor: pointer;
    transition: all 0.2s;
}
.apply-modal-close:hover {
    background: rgba(255,255,255,0.3);
}
.apply-modal-body {
    padding: 1.5rem;
}
.apply-modal-post-title {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    padding: 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    font-weight: 600;
    color: #92400e;
}
.apply-modal-body label {
    display: block;
    font-weight: 600;
    color: #475569;
    margin-bottom: 0.5rem;
}
.apply-modal-body textarea {
    width: 100%;
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    resize: vertical;
    min-height: 100px;
    transition: all 0.3s;
}
.apply-modal-body textarea:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
}
.apply-modal-footer {
    padding: 1rem 1.5rem 1.5rem;
    display: flex;
    gap: 1rem;
}
.apply-modal-btn {
    flex: 1;
    padding: 1rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.apply-modal-btn.primary {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
}
.apply-modal-btn.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
}
.apply-modal-btn.primary:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}
.apply-modal-btn.secondary {
    background: #f1f5f9;
    color: #64748b;
    border: 2px solid #e2e8f0;
}
.apply-modal-btn.secondary:hover {
    background: #e2e8f0;
    color: #475569;
}
</style>


<div class="vp-page">
    <div class="vp-container">
        <!-- Hero Section -->
        <div class="vp-hero">
            <div class="vp-hero-content">
                <div class="vp-breadcrumb">
                    <a href="index.php"><i class="bi bi-house-fill"></i> Trang chủ</a>
                    <i class="bi bi-chevron-right"></i>
                    <span><?php echo $post['type'] === 'recruitment' ? 'Tin tuyển dụng' : 'Tin ứng tuyển'; ?></span>
                </div>
                <h1 class="vp-title"><?php echo htmlspecialchars($post['title']); ?></h1>
                <div class="vp-hero-meta">
                    <div class="vp-author-card">
                        <div class="vp-author-avatar">
                            <?php if (!empty($post['author_avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($post['author_avatar']); ?>" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='<?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="vp-author-name">
                                <?php echo htmlspecialchars($post['author_name']); ?>
                                <?php if (!empty($post['author_verified'])): ?>
                                    <i class="bi bi-patch-check-fill vp-verified"></i>
                                <?php endif; ?>
                            </div>
                            <div class="vp-author-role">
                                <?php echo $post['author_role'] === 'patient' ? 'Bệnh nhân' : 'Sinh viên Y khoa'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="vp-date-item">
                        <i class="bi bi-calendar3"></i>
                        <?php echo date('d/m/Y H:i', strtotime($post['created_at'])); ?>
                    </div>
                    <?php 
                    $pst = $post['status'] ?? 'open';
                    $statusText = ['open' => 'Đang mở', 'closed' => 'Đã đóng', 'taken' => 'Đã nhận'];
                    ?>
                    <span class="vp-status-badge <?php echo $pst; ?>"><?php echo $statusText[$pst] ?? $pst; ?></span>
                </div>
            </div>
            <div class="vp-logo-frame">
                <img src="ảnh/logo web.jpg" alt="Logo Kết Nối Y Tế">
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($favoriteStatus === 'added'): ?>
            <div class="vp-alert success"><i class="bi bi-check-circle-fill"></i> Đã lưu bài viết vào danh sách yêu thích.</div>
        <?php elseif ($favoriteStatus === 'removed'): ?>
            <div class="vp-alert info"><i class="bi bi-info-circle-fill"></i> Đã xóa bài viết khỏi danh sách yêu thích.</div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="vp-main">
            <!-- Tags -->
            <div class="vp-card vp-tags-section">
                <div class="vp-card-body">
                    <?php $typeLabel = $post['type'] === 'recruitment' ? 'Tuyển dụng' : 'Ứng tuyển'; ?>
                    <span class="vp-tag type"><i class="bi bi-briefcase-fill"></i> <?php echo $typeLabel; ?></span>
                    <?php if (!empty($post['category'])): ?>
                        <span class="vp-tag category"><i class="bi bi-bookmark-fill"></i> <?php echo htmlspecialchars($post['category']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($areaValue)): ?>
                        <span class="vp-tag area">
                            <i class="bi bi-geo-alt-fill"></i>
                            <?php if ($areaIsLink): ?>
                                <a href="<?php echo htmlspecialchars($areaValue); ?>" target="_blank">Xem bản đồ</a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($areaValue); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Skills Section -->
            <?php if (!empty($skills)): ?>
            <div class="vp-card">
                <div class="vp-card-header">
                    <i class="bi bi-star-fill"></i>
                    <h3>Kỹ năng nổi bật</h3>
                </div>
                <div class="vp-card-body">
                    <div class="vp-skills-grid">
                        <?php foreach ($skills as $skill): ?>
                            <?php if (trim($skill)): ?>
                            <span class="vp-skill"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars(trim($skill)); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Content -->
            <div class="vp-card">
                <div class="vp-card-header">
                    <i class="bi bi-file-text-fill"></i>
                    <h3>Mô tả chi tiết</h3>
                </div>
                <div class="vp-card-body">
                    <?php if (!empty($post['card_image'])): ?>
                        <div class="vp-image-wrapper">
                            <img src="<?php echo htmlspecialchars($post['card_image']); ?>" alt="Hình ảnh" class="vp-image">
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($post['video_path']) && file_exists($post['video_path'])): ?>
                        <div class="vp-video-section">
                            <div class="vp-video-header">
                                <i class="bi bi-play-circle-fill"></i>
                                <span>Video giới thiệu</span>
                            </div>
                            <div class="vp-video-wrapper">
                                <video class="vp-video" controls preload="metadata">
                                    <source src="<?php echo htmlspecialchars($post['video_path']); ?>" type="video/mp4">
                                </video>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="vp-content"><?php echo nl2br(htmlspecialchars(trim($content))); ?></div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="vp-sidebar">
            <!-- Actions -->
            <div class="vp-action-card">
                <div class="vp-action-title"><i class="bi bi-lightning-fill"></i> Hành động</div>
                <div class="vp-action-buttons">
                    <?php 
                    $needsVerification = $loggedIn && !$isAdmin && (($_SESSION['role'] ?? '') === 'student') && !is_student_verified();
                    $currentUserRole = $_SESSION['role'] ?? '';
                    $authorRole = $post['author_role'] ?? '';
                    $isPatientViewingPatient = ($currentUserRole === 'patient' && $authorRole === 'patient');
                    ?>
                    
                    <?php 
                    // Kiểm tra sinh viên đã ứng tuyển chưa
                    $hasApplied = false;
                    if ($loggedIn && $currentUserRole === 'student' && $post['type'] === 'recruitment') {
                        try {
                            $checkApply = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND post_id = ? AND message LIKE 'Sinh viên ứng tuyển:%'");
                            $checkApply->execute([$currentUserId, $postId]);
                            $hasApplied = (int)$checkApply->fetchColumn() > 0;
                        } catch (Throwable $e) {}
                    }
                    ?>
                    <?php if ($loggedIn && !$isOwner): ?>
                        <?php if ($pst === 'taken'): ?>
                            <button class="vp-btn vp-btn-disabled" disabled><i class="bi bi-check-circle"></i> Đã có người nhận</button>
                        <?php elseif ($isPatientViewingPatient): ?>
                            <span class="vp-btn vp-btn-disabled"><i class="bi bi-eye"></i> Chỉ xem</span>
                        <?php elseif ($needsVerification): ?>
                            <a class="vp-btn vp-btn-primary" href="request_verification.php"><i class="bi bi-shield-check"></i> Xin xác thực để liên hệ</a>
                        <?php else: ?>
                            <?php // Nút Ứng tuyển cho sinh viên xem tin tuyển dụng ?>
                            <?php if ($currentUserRole === 'student' && $post['type'] === 'recruitment'): ?>
                                <?php if ($hasApplied): ?>
                                    <button class="vp-btn vp-btn-disabled" disabled>
                                        <i class="bi bi-check2-circle"></i> Đã ứng tuyển
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="vp-btn vp-btn-apply" onclick="showApplyModal(<?php echo $postId; ?>, '<?php echo htmlspecialchars(addslashes($post['title'])); ?>')">
                                        <i class="bi bi-hand-index-thumb-fill"></i> Ứng tuyển ngay
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <a class="vp-btn vp-btn-primary" href="chat.php?user_id=<?php echo (int)$post['user_id']; ?>">
                                <i class="bi bi-chat-dots-fill"></i> Liên hệ
                            </a>
                        <?php endif; ?>
                        
                        <form method="post" action="toggle_favorite.php" style="margin:0;">
                            <input type="hidden" name="post_id" value="<?php echo (int)$postId; ?>">
                            <input type="hidden" name="redirect" value="view_post.php?id=<?php echo $postId; ?>">
                            <input type="hidden" name="action" value="<?php echo $isFavorite ? 'remove' : 'add'; ?>">
                            <button type="submit" class="vp-btn vp-btn-fav <?php echo $isFavorite ? 'active' : ''; ?>" style="width:100%;">
                                <i class="bi bi-heart<?php echo $isFavorite ? '-fill' : ''; ?>"></i>
                                <?php echo $isFavorite ? 'Đã lưu yêu thích' : 'Lưu yêu thích'; ?>
                            </button>
                        </form>
                    <?php elseif (!$loggedIn): ?>
                        <a class="vp-btn vp-btn-primary" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Đăng nhập để liên hệ</a>
                        <a class="vp-btn vp-btn-fav" href="<?php echo htmlspecialchars($favoriteLoginUrl); ?>">
                            <i class="bi bi-heart"></i> Lưu yêu thích
                        </a>
                    <?php else: ?>
                        <a class="vp-btn vp-btn-outline" href="edit_post.php?id=<?php echo $postId; ?>">
                            <i class="bi bi-pencil"></i> Chỉnh sửa tin
                        </a>
                    <?php endif; ?>
                    
                    <a class="vp-btn vp-btn-outline" href="index.php#posts">
                        <i class="bi bi-arrow-left"></i> Quay lại danh sách
                    </a>
                </div>
            </div>

            <!-- Contact Info -->
            <?php if (!empty($post['contact_info'])): ?>
            <div class="vp-contact-card">
                <div class="vp-contact-header">
                    <div class="vp-contact-icon"><i class="bi bi-telephone-fill"></i></div>
                    <div>
                        <div class="vp-contact-label">Thông tin liên hệ</div>
                        <div class="vp-contact-value">
                            <span id="contactInfo"><?php echo htmlspecialchars($post['contact_info']); ?></span>
                            <button type="button" class="vp-copy-btn" onclick="copyContact()" title="Sao chép">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Author Info -->
            <div class="vp-author-sidebar">
                <div class="vp-author-avatar-lg">
                    <?php if (!empty($post['author_avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($post['author_avatar']); ?>" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='<?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>';">
                    <?php else: ?>
                        <?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="vp-author-name-lg">
                    <?php echo htmlspecialchars($post['author_name']); ?>
                    <?php if (!empty($post['author_verified'])): ?>
                        <i class="bi bi-patch-check-fill vp-verified"></i>
                    <?php endif; ?>
                </div>
                <div class="vp-author-email-lg"><?php echo htmlspecialchars($post['author_email']); ?></div>
                <?php if (!empty($post['author_verified'])): ?>
                    <span class="vp-author-badge"><i class="bi bi-shield-check"></i> Đã xác minh</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Comments Section -->
        <div class="vp-card vp-comments">
            <div class="vp-card-header">
                <i class="bi bi-chat-square-text-fill"></i>
                <h3>Bình luận (<?php echo count($comments); ?>)</h3>
            </div>
            
            <div class="vp-comments-body">
                <?php if (empty($comments)): ?>
                    <div class="vp-no-comments">
                        <i class="bi bi-chat-dots"></i>
                        <p>Chưa có bình luận nào. Hãy là người đầu tiên!</p>
                    </div>
                <?php else: ?>
                    <?php 
                    // Tổ chức comments theo cấu trúc cây
                    $parentComments = array_filter($comments, fn($c) => empty($c['parent_id']));
                    $childComments = array_filter($comments, fn($c) => !empty($c['parent_id']));
                    $repliesMap = [];
                    foreach ($childComments as $child) {
                        $repliesMap[$child['parent_id']][] = $child;
                    }
                    ?>
                    <?php foreach ($parentComments as $c): ?>
                        <?php echo renderComment($c, $repliesMap, $currentUserId, $isAdmin, $loggedIn, $userLikedComments); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="vp-comment-form">
                <?php if ($loggedIn): ?>
                    <form method="post" action="add_comment.php">
                        <input type="hidden" name="post_id" value="<?php echo (int)$postId; ?>">
                        <label><i class="bi bi-pencil-square"></i> Viết bình luận</label>
                        <textarea name="comment" placeholder="Nhập nội dung bình luận..."></textarea>
                        <button type="submit" class="btn-submit"><i class="bi bi-send"></i> Gửi bình luận</button>
                    </form>
                <?php else: ?>
                    <div class="vp-login-prompt">
                        <i class="bi bi-box-arrow-in-right"></i> Hãy <a href="login.php">đăng nhập</a> để bình luận.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const postId = <?php echo $postId; ?>;

function copyContact() {
    const text = document.getElementById('contactInfo').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.querySelector('.vp-copy-btn');
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-clipboard"></i>';
        }, 2000);
    });
}

// ========== COMMENT FUNCTIONS ==========

// Like/Unlike comment
function likeComment(commentId, btn) {
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=like&comment_id=' + commentId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const icon = btn.querySelector('i');
            const countSpan = btn.querySelector('.like-count');
            if (data.liked) {
                btn.classList.add('liked');
                icon.className = 'bi bi-heart-fill';
            } else {
                btn.classList.remove('liked');
                icon.className = 'bi bi-heart';
            }
            countSpan.textContent = data.like_count > 0 ? data.like_count : '';
        } else {
            alert(data.message);
        }
    });
}

// Show/Hide reply form
function showReplyForm(commentId) {
    document.getElementById('reply-form-' + commentId).classList.add('show');
    document.getElementById('reply-text-' + commentId).focus();
}

function hideReplyForm(commentId) {
    document.getElementById('reply-form-' + commentId).classList.remove('show');
    document.getElementById('reply-text-' + commentId).value = '';
}

// Submit reply
function submitReply(parentId) {
    const content = document.getElementById('reply-text-' + parentId).value.trim();
    if (!content) {
        alert('Vui lòng nhập nội dung trả lời');
        return;
    }
    
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=add&post_id=' + postId + '&parent_id=' + parentId + '&content=' + encodeURIComponent(content)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

// Edit comment
function editComment(commentId) {
    const textEl = document.getElementById('comment-text-' + commentId);
    const currentText = textEl.innerText;
    document.getElementById('editCommentId').value = commentId;
    document.getElementById('editCommentText').value = currentText;
    document.getElementById('editModalOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModalOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

function submitEdit() {
    const commentId = document.getElementById('editCommentId').value;
    const content = document.getElementById('editCommentText').value.trim();
    if (!content) {
        alert('Vui lòng nhập nội dung');
        return;
    }
    
    const btn = document.getElementById('editSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Đang lưu...';
    
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=edit&comment_id=' + commentId + '&content=' + encodeURIComponent(content)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('comment-text-' + commentId).innerHTML = data.content.replace(/\n/g, '<br>');
            closeEditModal();
        } else {
            alert(data.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Lưu thay đổi';
    });
}

// Delete comment
function deleteComment(commentId) {
    if (!confirm('Bạn có chắc muốn xóa bình luận này?')) return;
    
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&comment_id=' + commentId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

// Report comment
function showReportModal(commentId) {
    document.getElementById('reportCommentId').value = commentId;
    document.getElementById('reportReason').value = '';
    document.getElementById('reportDescription').value = '';
    document.getElementById('reportModalOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeReportModal() {
    document.getElementById('reportModalOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

function submitReport() {
    const commentId = document.getElementById('reportCommentId').value;
    const reason = document.getElementById('reportReason').value;
    const description = document.getElementById('reportDescription').value.trim();
    
    if (!reason) {
        alert('Vui lòng chọn lý do báo cáo');
        return;
    }
    
    const btn = document.getElementById('reportSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Đang gửi...';
    
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=report&comment_id=' + commentId + '&reason=' + encodeURIComponent(reason) + '&description=' + encodeURIComponent(description)
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            closeReportModal();
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-flag-fill"></i> Gửi báo cáo';
    });
}

// Admin: Toggle hide comment
function toggleHideComment(commentId, btn) {
    fetch('api/comments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle_hide&comment_id=' + commentId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    });
}

// Apply Job Modal Functions
function showApplyModal(postId, postTitle) {
    document.getElementById('applyPostId').value = postId;
    document.getElementById('applyPostTitle').textContent = postTitle;
    document.getElementById('applyMessage').value = '';
    document.getElementById('applyModalOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeApplyModal() {
    document.getElementById('applyModalOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

function submitApply() {
    const postId = document.getElementById('applyPostId').value;
    const message = document.getElementById('applyMessage').value.trim();
    const btn = document.getElementById('applySubmitBtn');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Đang gửi...';
    
    fetch('apply_job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'post_id=' + postId + '&message=' + encodeURIComponent(message)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeApplyModal();
            // Show success message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'vp-alert success';
            alertDiv.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.message;
            document.querySelector('.vp-container').insertBefore(alertDiv, document.querySelector('.vp-main'));
            
            // Reload page after 2 seconds to update button state
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            alert(data.message || 'Có lỗi xảy ra. Vui lòng thử lại.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill"></i> Gửi ứng tuyển';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Có lỗi xảy ra. Vui lòng thử lại.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill"></i> Gửi ứng tuyển';
    });
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('applyModalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeApplyModal();
            }
        });
    }
});
</script>

<!-- Apply Job Modal -->
<div id="applyModalOverlay" class="apply-modal-overlay">
    <div class="apply-modal">
        <div class="apply-modal-header">
            <h3><i class="bi bi-hand-index-thumb-fill"></i> Ứng tuyển công việc</h3>
            <button type="button" class="apply-modal-close" onclick="closeApplyModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="apply-modal-body">
            <input type="hidden" id="applyPostId" value="">
            <div class="apply-modal-post-title">
                <i class="bi bi-file-earmark-text"></i> <span id="applyPostTitle"></span>
            </div>
            <label for="applyMessage">
                <i class="bi bi-chat-left-text"></i> Lời nhắn gửi người đăng tin (không bắt buộc)
            </label>
            <textarea id="applyMessage" placeholder="Giới thiệu ngắn về bản thân, kinh nghiệm hoặc lý do bạn muốn nhận công việc này..."></textarea>
        </div>
        <div class="apply-modal-footer">
            <button type="button" class="apply-modal-btn secondary" onclick="closeApplyModal()">
                <i class="bi bi-x-circle"></i> Hủy
            </button>
            <button type="button" id="applySubmitBtn" class="apply-modal-btn primary" onclick="submitApply()">
                <i class="bi bi-send-fill"></i> Gửi ứng tuyển
            </button>
        </div>
    </div>
</div>

<!-- Report Comment Modal -->
<div id="reportModalOverlay" class="apply-modal-overlay">
    <div class="apply-modal">
        <div class="apply-modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
            <h3><i class="bi bi-flag-fill"></i> Báo cáo bình luận</h3>
            <button type="button" class="apply-modal-close" onclick="closeReportModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="apply-modal-body">
            <input type="hidden" id="reportCommentId" value="">
            <label><i class="bi bi-exclamation-triangle"></i> Lý do báo cáo</label>
            <select id="reportReason" style="width:100%;padding:0.75rem;border:2px solid #e2e8f0;border-radius:10px;font-size:1rem;margin-bottom:1rem;">
                <option value="">-- Chọn lý do --</option>
                <option value="spam">Spam / Quảng cáo</option>
                <option value="offensive">Nội dung xúc phạm</option>
                <option value="harassment">Quấy rối / Bắt nạt</option>
                <option value="misinformation">Thông tin sai lệch</option>
                <option value="inappropriate">Nội dung không phù hợp</option>
                <option value="other">Lý do khác</option>
            </select>
            <label><i class="bi bi-chat-left-text"></i> Mô tả thêm (không bắt buộc)</label>
            <textarea id="reportDescription" placeholder="Mô tả chi tiết vấn đề..."></textarea>
        </div>
        <div class="apply-modal-footer">
            <button type="button" class="apply-modal-btn secondary" onclick="closeReportModal()">
                <i class="bi bi-x-circle"></i> Hủy
            </button>
            <button type="button" id="reportSubmitBtn" class="apply-modal-btn primary" style="background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%);" onclick="submitReport()">
                <i class="bi bi-flag-fill"></i> Gửi báo cáo
            </button>
        </div>
    </div>
</div>

<!-- Edit Comment Modal -->
<div id="editModalOverlay" class="apply-modal-overlay">
    <div class="apply-modal">
        <div class="apply-modal-header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
            <h3><i class="bi bi-pencil-fill"></i> Sửa bình luận</h3>
            <button type="button" class="apply-modal-close" onclick="closeEditModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="apply-modal-body">
            <input type="hidden" id="editCommentId" value="">
            <label><i class="bi bi-chat-left-text"></i> Nội dung bình luận</label>
            <textarea id="editCommentText" placeholder="Nhập nội dung..."></textarea>
        </div>
        <div class="apply-modal-footer">
            <button type="button" class="apply-modal-btn secondary" onclick="closeEditModal()">
                <i class="bi bi-x-circle"></i> Hủy
            </button>
            <button type="button" id="editSubmitBtn" class="apply-modal-btn primary" onclick="submitEdit()">
                <i class="bi bi-check-lg"></i> Lưu thay đổi
            </button>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
