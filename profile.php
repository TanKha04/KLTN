<?php
require_once 'config.php';

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Hồ sơ</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:1rem;}</style>';
    echo '</head><body>';
}

if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="alert alert-danger">ID người dùng không hợp lệ.</div>';
    if ($isEmbed) { echo '</body></html>'; } else { require_once 'footer.php'; }
    exit;
}
$uid = (int)$_GET['id'];
$stmt = $pdo->prepare('SELECT id,name,username,email,role,bio,location,phone,school,class_code,student_id,verified,created_at,avatar,last_activity,is_admin FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();
if (!$user) {
    echo '<div class="alert alert-danger">Người dùng không tồn tại.</div>';
    if ($isEmbed) { echo '</body></html>'; } else { require_once 'footer.php'; }
    exit;
}

// Calculate average rating
$stmt = $pdo->prepare('SELECT AVG(rating) AS avg_score, COUNT(*) AS cnt FROM ratings WHERE rated_user_id = ?');
$stmt->execute([$uid]);
$r = $stmt->fetch();
$avg = $r['avg_score'] ? round($r['avg_score'],1) : null;
$totalCount = (int)($r['cnt'] ?? 0);

// Count posts
$postStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
$postStmt->execute([$uid]);
$postCount = (int)$postStmt->fetchColumn();

// If logged in and viewing someone else, get existing rating by current user
$existingScore = null;
$existingComment = '';
if (is_logged_in() && $_SESSION['user_id'] != $uid) {
  $stEx = $pdo->prepare('SELECT rating AS score, comment FROM ratings WHERE user_id = ? AND rated_user_id = ?');
  $stEx->execute([$_SESSION['user_id'], $uid]);
  $er = $stEx->fetch();
  if ($er) {
    $existingScore = (int)$er['score'];
    $existingComment = $er['comment'];
  }
}

// Fetch recent reviews
try {
    $recentStmt = $pdo->prepare('SELECT r.*, r.rating AS score, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role FROM ratings r JOIN users u ON u.id = r.user_id WHERE r.rated_user_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $recentStmt->execute([$uid]);
    $recentReviews = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentReviews = [];
}

// Determine rating permission
$canRate = false;
if (is_logged_in() && $_SESSION['user_id'] != $uid) {
  try {
    $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
    $colChk->execute();
    $hasAssigned = (int)$colChk->fetchColumn() > 0;
    if ($hasAssigned) {
      $perm = $pdo->prepare('SELECT id FROM posts WHERE user_id = ? AND assigned_to = ? AND status = ? LIMIT 1');
      $perm->execute([$uid, $_SESSION['user_id'], 'taken']);
      $canRate = (bool)$perm->fetchColumn();
    }
  } catch (Throwable $e) {
    $canRate = false;
  }
}

function get_role_label($role, $is_admin = false) {
    if ($is_admin) return 'Quản trị viên';
    return $role === 'student' ? 'Sinh viên Y khoa' : ($role === 'patient' ? 'Bệnh nhân' : 'Người dùng');
}
?>

<style>
/* Profile Page Premium Styles */
.profile-premium-page {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    margin: -1.5rem -0.75rem;
    padding: 2rem;
}
.profile-premium-container {
    max-width: 1000px;
    margin: 0 auto;
}
.profile-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.25rem;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 12px;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-bottom: 1.5rem;
}
.profile-back-btn:hover {
    background: #fff;
    color: #667eea;
}
</style>

<style>
/* Profile Card */
.profile-premium-card {
    background: #fff;
    border-radius: 28px;
    box-shadow: 0 25px 80px rgba(0,0,0,0.15);
    overflow: hidden;
}
.profile-premium-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2.5rem 2rem 4rem;
    text-align: center;
    position: relative;
}
.profile-premium-header::after {
    content: '';
    position: absolute;
    bottom: -30px;
    left: 50%;
    transform: translateX(-50%);
    width: 120%;
    height: 60px;
    background: #fff;
    border-radius: 50% 50% 0 0;
}
.profile-avatar-premium {
    position: relative;
    z-index: 2;
    display: inline-block;
}
.profile-avatar-premium img,
.profile-avatar-premium .avatar-placeholder {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    border: 5px solid #fff;
    box-shadow: 0 15px 40px rgba(0,0,0,0.2);
    object-fit: cover;
}
.profile-avatar-premium .avatar-placeholder {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 700;
}
.profile-avatar-premium .online-dot {
    position: absolute;
    bottom: 8px;
    right: 8px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 4px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.profile-avatar-premium .online-dot.online {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    animation: pulse-online 2s infinite;
}
.profile-avatar-premium .online-dot.offline {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}
@keyframes pulse-online {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5), 0 2px 8px rgba(0,0,0,0.2); }
    50% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0), 0 2px 8px rgba(0,0,0,0.2); }
}
</style>

<style>
/* Profile Info Section */
.profile-info-section {
    padding: 1rem 2rem 2rem;
    text-align: center;
    position: relative;
    z-index: 1;
}
.profile-name-premium {
    font-size: 1.75rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.profile-verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.85rem;
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #047857;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.profile-role-premium {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1.25rem;
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    color: #4f46e5;
    border-radius: 25px;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 1rem;
}
.profile-online-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.profile-online-badge.online {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #059669;
}
.profile-online-badge.offline {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #dc2626;
}
.profile-online-badge i {
    font-size: 0.5rem;
}

/* Stats Grid */
.profile-stats-premium {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #fafbff, #f5f7ff);
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}
.profile-stat-item {
    text-align: center;
    padding: 1rem;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}
.profile-stat-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.profile-stat-value {
    font-size: 1.75rem;
    font-weight: 800;
    margin-bottom: 0.25rem;
}
.profile-stat-value.rating {
    color: #f59e0b;
}
.profile-stat-value.posts {
    color: #3b82f6;
}
.profile-stat-value.reviews {
    color: #8b5cf6;
}
.profile-stat-label {
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 500;
}
</style>

<style>
/* Profile Body */
.profile-body-premium {
    padding: 2rem;
}
.profile-section-premium {
    margin-bottom: 2rem;
}
.profile-section-premium:last-child {
    margin-bottom: 0;
}
.profile-section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e2e8f0;
}
.profile-section-title i {
    color: #667eea;
    font-size: 1.1rem;
}

/* Bio Card */
.profile-bio-card {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 16px;
    padding: 1.5rem;
    border-left: 4px solid #f59e0b;
}
.profile-bio-card p {
    margin: 0;
    color: #78350f;
    line-height: 1.7;
    font-size: 1rem;
}
.profile-bio-empty {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    border-radius: 16px;
    padding: 2rem;
    text-align: center;
    color: #64748b;
}

/* Info Grid */
.profile-info-grid {
    display: grid;
    gap: 1rem;
}
.profile-info-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 14px;
    transition: all 0.3s ease;
}
.profile-info-item:hover {
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    transform: translateX(5px);
}
.profile-info-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.profile-info-content {
    flex: 1;
}
.profile-info-label {
    font-size: 0.8rem;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}
.profile-info-value {
    font-size: 1rem;
    color: #1e293b;
    font-weight: 600;
}
</style>

<style>
/* Reviews Section */
.profile-reviews-section {
    background: linear-gradient(135deg, #fafbff, #f5f7ff);
    border-radius: 20px;
    padding: 1.5rem;
    border: 1px solid #e2e8f0;
}
.profile-review-card {
    background: #fff;
    border-radius: 14px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
}
.profile-review-card:last-child {
    margin-bottom: 0;
}
.profile-review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.profile-review-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}
.profile-review-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}
.profile-review-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 12px;
}
.profile-review-meta {
    flex: 1;
}
.profile-review-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 0.95rem;
}
.profile-review-date {
    font-size: 0.8rem;
    color: #94a3b8;
}
.profile-review-stars {
    color: #f59e0b;
    font-size: 0.9rem;
}
.profile-review-content {
    color: #475569;
    line-height: 1.6;
    font-size: 0.95rem;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 1rem;
    border-radius: 10px;
    margin-top: 0.5rem;
}
.profile-no-reviews {
    text-align: center;
    padding: 2rem;
    color: #94a3b8;
}
.profile-no-reviews i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    display: block;
    color: #cbd5e1;
}
</style>

<style>
/* Rating Section */
.profile-rating-section {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 20px;
    padding: 2rem;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.profile-rating-section::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -30%;
    width: 80%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}
.profile-rating-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
}
.profile-rating-header i {
    font-size: 1.5rem;
}
.profile-rating-header h5 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
}
.profile-rating-info {
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    border-radius: 16px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
}
.profile-rating-info p {
    margin: 0;
    opacity: 0.9;
    line-height: 1.6;
}
.profile-rating-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.85rem 1.75rem;
    background: #fff;
    color: #667eea;
    border: none;
    border-radius: 14px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    position: relative;
    z-index: 1;
}
.profile-rating-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(0,0,0,0.2);
}
.profile-rating-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Actions */
.profile-actions-premium {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-top: 1px solid #e2e8f0;
}
.profile-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.85rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    font-size: 0.95rem;
}
.profile-action-btn.primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}
.profile-action-btn.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    color: #fff;
}
.profile-action-btn.secondary {
    background: #fff;
    color: #667eea;
    border: 2px solid #667eea;
}
.profile-action-btn.secondary:hover {
    background: #667eea;
    color: #fff;
}

@media (max-width: 767px) {
    .profile-premium-page { padding: 1rem; }
    .profile-stats-premium { grid-template-columns: 1fr; }
    .profile-premium-header { padding: 2rem 1.5rem 3.5rem; }
    .profile-body-premium { padding: 1.5rem; }
    .profile-name-premium { font-size: 1.4rem; }
}
</style>

<div class="profile-premium-page">
    <div class="profile-premium-container">
        <a href="javascript:history.back()" class="profile-back-btn">
            <i class="bi bi-arrow-left"></i> Quay lại
        </a>

        <div class="profile-premium-card">
            <!-- Header with Avatar -->
            <div class="profile-premium-header">
                <div class="profile-avatar-premium">
                    <?php if (!empty($user['avatar']) && upload_exists($user['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($user['avatar'])); ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                    <?php else: ?>
                        <div class="avatar-placeholder"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <span class="online-dot <?php echo is_user_online($user['last_activity'] ?? null) ? 'online' : 'offline'; ?>"></span>
                </div>
            </div>

            <!-- Profile Info -->
            <div class="profile-info-section">
                <h1 class="profile-name-premium">
                    <?php echo htmlspecialchars($user['name']); ?>
                    <?php if (!empty($user['verified'])): ?>
                        <span class="profile-verified-badge"><i class="bi bi-patch-check-fill"></i> Đã xác minh</span>
                    <?php endif; ?>
                </h1>
                <div class="profile-role-premium">
                    <i class="bi bi-<?php echo !empty($user['is_admin']) ? 'shield-fill' : ($user['role'] === 'student' ? 'mortarboard-fill' : 'heart-pulse-fill'); ?>"></i>
                    <?php echo get_role_label($user['role'], !empty($user['is_admin'])); ?>
                </div>
                <div class="profile-online-badge <?php echo is_user_online($user['last_activity'] ?? null) ? 'online' : 'offline'; ?>">
                    <i class="bi bi-circle-fill"></i>
                    <?php echo is_user_online($user['last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="profile-stats-premium">
                <div class="profile-stat-item">
                    <div class="profile-stat-value rating">
                        <?php if ($avg !== null): ?>
                            <i class="bi bi-star-fill"></i> <?php echo $avg; ?>
                        <?php else: ?>
                            <i class="bi bi-star"></i> --
                        <?php endif; ?>
                    </div>
                    <div class="profile-stat-label">Đánh giá</div>
                </div>
                <div class="profile-stat-item">
                    <div class="profile-stat-value reviews"><?php echo $totalCount; ?></div>
                    <div class="profile-stat-label">Lượt đánh giá</div>
                </div>
                <div class="profile-stat-item">
                    <div class="profile-stat-value posts"><?php echo $postCount; ?></div>
                    <div class="profile-stat-label">Bài đăng</div>
                </div>
            </div>

            <!-- Body -->
            <div class="profile-body-premium">
                <!-- Bio Section -->
                <div class="profile-section-premium">
                    <div class="profile-section-title">
                        <i class="bi bi-info-circle-fill"></i> Giới thiệu
                    </div>
                    <?php if (!empty($user['bio'])): ?>
                        <div class="profile-bio-card">
                            <p><?php echo nl2br(htmlspecialchars($user['bio'])); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="profile-bio-empty">
                            <i class="bi bi-chat-square-text"></i>
                            <p>Chưa có thông tin giới thiệu</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Contact Info -->
                <div class="profile-section-premium">
                    <div class="profile-section-title">
                        <i class="bi bi-person-lines-fill"></i> Thông tin liên hệ
                    </div>
                    <div class="profile-info-grid">
                        <?php if (!empty($user['email'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-envelope-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Email</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['location'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-geo-alt-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Khu vực</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['location']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['phone'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-telephone-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Điện thoại</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['phone']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['username'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-at"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Tên tài khoản</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['school'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-building"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Trường</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['school']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['class_code'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Mã lớp</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['class_code']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['student_id'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-person-badge-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Mã sinh viên</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['student_id']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-calendar-event-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Ngày tham gia</div>
                                <div class="profile-info-value"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Reviews -->
                <?php if (!empty($recentReviews)): ?>
                <div class="profile-section-premium">
                    <div class="profile-section-title">
                        <i class="bi bi-chat-quote-fill"></i> Đánh giá gần đây
                    </div>
                    <div class="profile-reviews-section">
                        <?php foreach ($recentReviews as $rv): ?>
                        <div class="profile-review-card">
                            <div class="profile-review-header">
                                <?php if (!empty($rv['rater_avatar']) && upload_exists($rv['rater_avatar'])): ?>
                                    <div class="profile-review-avatar">
                                        <img src="<?php echo htmlspecialchars(public_url_for($rv['rater_avatar'])); ?>" alt="">
                                    </div>
                                <?php else: ?>
                                    <div class="profile-review-avatar"><?php echo strtoupper(substr($rv['rater_name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div class="profile-review-meta">
                                    <div class="profile-review-name"><?php echo htmlspecialchars($rv['rater_name']); ?></div>
                                    <div class="profile-review-date"><i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?></div>
                                </div>
                                <div class="profile-review-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?php echo $i <= (int)$rv['score'] ? '-fill' : ''; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php if (!empty($rv['comment'])): ?>
                            <div class="profile-review-content"><?php echo nl2br(htmlspecialchars($rv['comment'])); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rating Section -->
                <?php if (is_logged_in() && $_SESSION['user_id'] != $user['id']): ?>
                <div class="profile-section-premium">
                    <div class="profile-rating-section">
                        <div class="profile-rating-header">
                            <i class="bi bi-star-fill"></i>
                            <h5>Đánh giá người dùng</h5>
                        </div>
                        <?php if (!$canRate): ?>
                            <div class="profile-rating-info">
                                <p><i class="bi bi-info-circle me-2"></i>Bạn chỉ có thể gửi đánh giá nếu người đăng đã chọn bạn nhận việc cho một tin.</p>
                            </div>
                            <button class="profile-rating-btn" disabled>
                                <i class="bi bi-star"></i> Đánh giá
                            </button>
                        <?php else: ?>
                            <div class="profile-rating-info">
                                <p><i class="bi bi-check-circle me-2"></i>Bạn có thể đánh giá người dùng này. Hãy chia sẻ trải nghiệm của bạn!</p>
                            </div>
                            <button class="profile-rating-btn" data-bs-toggle="modal" data-bs-target="#rateModal">
                                <i class="bi bi-star-fill"></i> Gửi đánh giá
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="profile-actions-premium">
                <?php if (is_logged_in() && $_SESSION['user_id'] == $user['id']): ?>
                    <a href="edit_profile.php" class="profile-action-btn primary">
                        <i class="bi bi-pencil-fill"></i> Chỉnh sửa hồ sơ
                    </a>
                <?php elseif (is_logged_in()): ?>
                    <a href="chat.php?user_id=<?php echo $user['id']; ?>" class="profile-action-btn primary">
                        <i class="bi bi-chat-dots-fill"></i> Nhắn tin
                    </a>
                <?php else: ?>
                    <a href="login.php" class="profile-action-btn primary">
                        <i class="bi bi-box-arrow-in-right"></i> Đăng nhập để liên hệ
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (is_logged_in() && $_SESSION['user_id'] != $user['id'] && $canRate): ?>
<!-- Rating Modal -->
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 24px; overflow: hidden; border: none;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border: none; padding: 1.5rem 2rem;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 14px; display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-star-fill" style="font-size: 1.5rem;"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0" style="font-weight: 700;">Đánh giá <?php echo htmlspecialchars($user['name']); ?></h5>
                        <small style="opacity: 0.85;">Chia sẻ trải nghiệm của bạn</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <form method="post" action="rate.php" id="rating-form">
                    <input type="hidden" name="rated_id" value="<?php echo $user['id']; ?>">
                    <input type="hidden" name="score" id="rating-score" value="<?php echo $existingScore ?? ''; ?>">
                    
                    <div class="text-center mb-4">
                        <p class="text-muted mb-3">Bạn đánh giá như thế nào?</p>
                        <div class="star-rating-input" style="font-size: 3rem; color: #e2e8f0; cursor: pointer;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="bi bi-star<?php echo ($existingScore && $s <= $existingScore) ? '-fill' : ''; ?> star-btn" 
                                   data-value="<?php echo $s; ?>" 
                                   style="transition: all 0.2s; margin: 0 0.15rem; <?php echo ($existingScore && $s <= $existingScore) ? 'color: #f59e0b;' : ''; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="mt-2 fw-semibold" id="rating-text" style="color: #667eea;">Chọn số sao</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><i class="bi bi-chat-left-text me-2"></i>Nhận xét của bạn</label>
                        <textarea class="form-control" name="comment" rows="4" placeholder="Chia sẻ trải nghiệm của bạn với người dùng này..." style="border-radius: 14px; padding: 1rem; border: 2px solid #e2e8f0;"><?php echo htmlspecialchars($existingComment); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn w-100" style="background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; padding: 1rem; border-radius: 14px; font-weight: 700; font-size: 1rem; border: none;">
                        <i class="bi bi-send-fill me-2"></i>Gửi đánh giá
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stars = document.querySelectorAll('.star-btn');
    const scoreInput = document.getElementById('rating-score');
    const ratingText = document.getElementById('rating-text');
    const texts = ['', 'Rất tệ 😞', 'Tệ 😕', 'Bình thường 😐', 'Tốt 😊', 'Xuất sắc 🌟'];
    
    function updateStars(value) {
        stars.forEach((star, index) => {
            if (index < value) {
                star.classList.remove('bi-star');
                star.classList.add('bi-star-fill');
                star.style.color = '#f59e0b';
                star.style.transform = 'scale(1.1)';
            } else {
                star.classList.remove('bi-star-fill');
                star.classList.add('bi-star');
                star.style.color = '#e2e8f0';
                star.style.transform = 'scale(1)';
            }
        });
        ratingText.textContent = texts[value] || 'Chọn số sao';
    }
    
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const value = parseInt(this.dataset.value);
            scoreInput.value = value;
            updateStars(value);
        });
        
        star.addEventListener('mouseenter', function() {
            const value = parseInt(this.dataset.value);
            updateStars(value);
        });
    });
    
    document.querySelector('.star-rating-input')?.addEventListener('mouseleave', function() {
        updateStars(parseInt(scoreInput.value) || 0);
    });
    
    if (scoreInput && scoreInput.value) {
        updateStars(parseInt(scoreInput.value));
    }
    
    // Handle form submit via AJAX
    const ratingForm = document.getElementById('rating-form');
    if (ratingForm) {
        ratingForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Đang gửi...';
            
            fetch('rate.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('rateModal'));
                    if (modal) modal.hide();
                    
                    // Show success message
                    alert('Đánh giá đã được gửi thành công!');
                    
                    // Reload page to update ratings display
                    window.location.reload();
                } else {
                    alert(data.message || 'Có lỗi xảy ra khi gửi đánh giá.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Có lỗi xảy ra khi gửi đánh giá.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    }
});
</script>
<?php endif; ?>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
