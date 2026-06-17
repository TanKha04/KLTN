<?php
require_once 'config.php';
require_login();
// If this account has admin privilege, route to admin dashboard only
if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}
if ($_SESSION['role'] !== 'student') {
        die('Truy cập bị từ chối.');
}

$userId = $_SESSION['user_id'];

$stmtStudent = $pdo->prepare('SELECT verified FROM users WHERE id = ?');
$stmtStudent->execute([$userId]);
$verifiedFlag = (int)$stmtStudent->fetchColumn();
$_SESSION['verified'] = $verifiedFlag ? 1 : 0;

// check posting permission
$stmtCan = $pdo->prepare('SELECT can_post FROM users WHERE id = ?');
$stmtCan->execute([$userId]);
$canPost = (int)$stmtCan->fetchColumn();

$latestVerification = null;
if (!$verifiedFlag) {
    $stmtPending = $pdo->prepare('SELECT * FROM verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmtPending->execute([$userId]);
    $latestVerification = $stmtPending->fetch();
}

$stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ? AND type = "application" ORDER BY created_at DESC');
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();
$postsCount = count($posts);

$msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE to_user = ?');
$msgStmt->execute([$userId]);
$messageCount = (int)$msgStmt->fetchColumn();

$ratingStmt = $pdo->prepare('SELECT AVG(score) AS avg_score, COUNT(*) AS total FROM ratings WHERE rated_id = ?');
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData && $ratingData['avg_score'] ? round($ratingData['avg_score'], 1) : null;
$ratingTotal = $ratingData ? (int)$ratingData['total'] : 0;

$myReviewStmt = $pdo->prepare('SELECT r.score, r.comment, r.created_at, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role FROM ratings r JOIN users u ON u.id = r.rater_id WHERE r.rated_id = ? ORDER BY r.created_at DESC LIMIT 20');
$myReviewStmt->execute([$userId]);
$myReviews = $myReviewStmt->fetchAll();

$favoritePosts = [];
try {
    $favStmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.email AS author_email, f.created_at AS favorited_at FROM favorites f JOIN posts p ON p.id = f.post_id JOIN users u ON u.id = p.user_id WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 10');
    $favStmt->execute([$userId]);
    $favoritePosts = $favStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Fetch student favorites failed: ' . $e->getMessage());
}

$recentAcceptances = [];
$recentAcceptCount = 0;
try {
    $historySql = "SELECT m.created_at AS accepted_at, p.id AS post_id, p.title, u.name AS owner_name, u.email AS owner_email "
        . "FROM messages m "
        . "JOIN posts p ON p.id = m.post_id "
        . "JOIN users u ON u.id = p.user_id "
        . "WHERE m.to_user = ? "
        . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
        . "AND m.message LIKE 'Bạn đã được chọn nhận việc%' "
        . "ORDER BY m.created_at DESC";
    $histStmt = $pdo->prepare($historySql);
    $histStmt->execute([$userId]);
    $recentAcceptances = $histStmt->fetchAll();
    $recentAcceptCount = count($recentAcceptances);
} catch (Throwable $e) {
    error_log('Fetch student acceptance history failed: ' . $e->getMessage());
}

require_once 'header.php';
?>

<style>
/* Dashboard Hero Premium */
.dashboard-hero-premium {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 24px;
    padding: 2rem 2.5rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(11, 63, 145, 0.35);
}
.dashboard-hero-premium::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 60%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 60%);
    pointer-events: none;
}
.dashboard-hero-premium .hero-welcome h2 {
    color: #ffffff;
    font-size: 1.85rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.15);
}
.dashboard-hero-premium .hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    color: #ffffff;
    padding: 0.5rem 1.25rem;
    border-radius: 50px;
    font-size: 0.9rem;
    font-weight: 600;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.dashboard-hero-premium .hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.dashboard-hero-premium .hero-actions .btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.85rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
}
.dashboard-hero-premium .btn-action-primary {
    background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
    color: #0b3f91;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.dashboard-hero-premium .btn-action-primary:hover {
    background: #ffffff;
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    color: #0b3f91;
}
.dashboard-hero-premium .btn-action-primary i {
    color: #3b82f6;
}
.dashboard-hero-premium .btn-action-outline {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border: 2px solid rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
}
.dashboard-hero-premium .btn-action-outline:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.8);
    transform: translateY(-3px);
    color: #ffffff;
}
@media (max-width: 768px) {
    .dashboard-hero-premium {
        padding: 1.5rem;
        border-radius: 18px;
    }
    .dashboard-hero-premium .hero-welcome h2 {
        font-size: 1.4rem;
    }
    .dashboard-hero-premium .hero-actions {
        flex-direction: column;
    }
    .dashboard-hero-premium .hero-actions .btn-action {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="dashboard-hero-premium">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
        <div class="hero-welcome">
            <h2>👋 Chào mừng, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
            <span class="hero-badge">
                <i class="fas fa-user-graduate"></i> Sinh viên
            </span>
        </div>
        <div class="hero-actions mt-3 mt-md-0">
            <a href="create_application.php" class="btn-action btn-action-primary">
                <i class="fas fa-plus-circle"></i> Tạo tin ứng tuyển
            </a>
            <a href="view_messages.php?from_admin=1" class="btn-action btn-action-primary">
                <i class="fas fa-bell"></i> Thông báo
            </a>
            <a href="view_messages.php" class="btn-action btn-action-outline">
                <i class="fas fa-envelope"></i> Tin nhắn
            </a>
        </div>
    </div>
</div>

<?php if (!$verifiedFlag && !is_admin_user()): ?>
    <div class="status-card status-warning">
        <div class="status-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Tài khoản chưa được xác thực</h6>
            <p class="status-text">
                <?php if ($latestVerification): ?>
                    <?php if ($latestVerification['status'] === 'pending'): ?>
                        Đơn xin xác thực đang được xử lý (gửi ngày <?php echo date('d/m/Y', strtotime($latestVerification['created_at'])); ?>).
                    <?php elseif ($latestVerification['status'] === 'rejected'): ?>
                        Đơn gần nhất bị từ chối. <?php if (!empty($latestVerification['admin_note'])): ?>Lý do: <?php echo nl2br(htmlspecialchars($latestVerification['admin_note'])); ?><?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    Vui lòng gửi yêu cầu xác thực để hưởng đầy đủ quyền lợi sinh viên.
                <?php endif; ?>
            </p>
        </div>
        <a href="request_verification.php" class="status-btn">
            <i class="fas fa-check-circle me-1"></i> Xin xác thực
        </a>
    </div>
<?php endif; ?>

<?php if (!$canPost): ?>
    <div class="status-card status-info">
        <div class="status-icon">
            <i class="fas fa-edit"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Quyền đăng tin</h6>
            <p class="status-text">Chưa được cấp quyền đăng tin. Hãy gửi yêu cầu để được phê duyệt.</p>
        </div>
        <a href="request_posting_permission.php" class="status-btn status-btn-info">
            <i class="fas fa-paper-plane me-1"></i> Xin cấp quyền
        </a>
    </div>
<?php else: ?>
    <div class="status-card status-success">
        <div class="status-icon">
            <i class="fas fa-check-double"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Quyền đăng tin</h6>
            <p class="status-text">Bạn đã được cấp quyền đăng tin. Hãy tạo tin ứng tuyển ngay!</p>
        </div>
    </div>
<?php endif; ?>

<style>
.stats-enhanced .stat-card-new {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    border-radius: 20px;
    background: #fff;
    border-left: 5px solid;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    color: inherit;
}
.stats-enhanced .stat-card-new:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 20px 50px rgba(0,0,0,0.15);
}
.stats-enhanced .stat-card-new .icon-box {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: #fff;
    transition: transform 0.3s ease;
}
.stats-enhanced .stat-card-new:hover .icon-box {
    transform: rotate(10deg) scale(1.1);
}
.stats-enhanced .stat-label { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.stats-enhanced .stat-value { font-size: 2.2rem; font-weight: 700; margin: 0.25rem 0; }
.stats-enhanced .stat-desc { font-size: 0.85rem; color: #94a3b8; }

/* Card 1 - Blue */
.card-blue { border-color: #3b82f6; background: linear-gradient(135deg, #fff 0%, #dbeafe 100%); }
.card-blue .icon-box { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 20px rgba(59,130,246,0.4); }
.card-blue .stat-value { color: #1e40af; }
.card-blue:hover { box-shadow: 0 20px 50px rgba(59,130,246,0.25); }

/* Card 2 - Cyan */
.card-cyan { border-color: #06b6d4; background: linear-gradient(135deg, #fff 0%, #cffafe 100%); }
.card-cyan .icon-box { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 20px rgba(6,182,212,0.4); }
.card-cyan .stat-value { color: #0e7490; }
.card-cyan:hover { box-shadow: 0 20px 50px rgba(6,182,212,0.25); }

/* Card 3 - Pink */
.card-pink { border-color: #ec4899; background: linear-gradient(135deg, #fff 0%, #fce7f3 100%); }
.card-pink .icon-box { background: linear-gradient(135deg, #ec4899, #db2777); box-shadow: 0 8px 20px rgba(236,72,153,0.4); }
.card-pink .stat-value { color: #be185d; }
.card-pink:hover { box-shadow: 0 20px 50px rgba(236,72,153,0.25); }

/* Card 4 - Amber */
.card-amber { border-color: #f59e0b; background: linear-gradient(135deg, #fff 0%, #fef3c7 100%); }
.card-amber .icon-box { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 20px rgba(245,158,11,0.4); }
.card-amber .stat-value { color: #b45309; }
.card-amber:hover { box-shadow: 0 20px 50px rgba(245,158,11,0.25); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
.stats-enhanced .stat-card-new:nth-child(1) { animation: fadeInUp 0.5s ease-out 0.1s backwards; }
.stats-enhanced .stat-card-new:nth-child(2) { animation: fadeInUp 0.5s ease-out 0.2s backwards; }
.stats-enhanced .stat-card-new:nth-child(3) { animation: fadeInUp 0.5s ease-out 0.3s backwards; }
.stats-enhanced .stat-card-new:nth-child(4) { animation: fadeInUp 0.5s ease-out 0.4s backwards; }
</style>

<div class="stats-enhanced" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.25rem; margin: 1.75rem 0 2.25rem;">
    <div class="stat-card-new card-blue">
        <div class="icon-box"><i class="fas fa-graduation-cap"></i></div>
        <div>
            <p class="stat-label mb-1">Tin ứng tuyển</p>
            <p class="stat-value mb-0"><?php echo $postsCount; ?></p>
            <span class="stat-desc">Đã đăng</span>
        </div>
    </div>
    <a href="assignment_history.php" class="stat-card-new card-cyan">
        <div class="icon-box"><i class="fas fa-calendar-check"></i></div>
        <div>
            <p class="stat-label mb-1">Lịch sử nhận việc</p>
            <p class="stat-value mb-0"><?php echo $recentAcceptCount; ?></p>
            <span class="stat-desc">Trong 30 ngày gần nhất</span>
        </div>
    </a>
    <a href="favorites.php" class="stat-card-new card-pink">
        <div class="icon-box"><i class="fas fa-heart"></i></div>
        <div>
            <p class="stat-label mb-1">Yêu thích</p>
            <p class="stat-value mb-0"><?php echo count($favoritePosts); ?></p>
            <span class="stat-desc">Bài tuyển đã lưu</span>
        </div>
    </a>
    <div class="stat-card-new card-amber" role="button" tabindex="0" data-bs-toggle="offcanvas" data-bs-target="#myReviewsCanvas" aria-controls="myReviewsCanvas" style="cursor: pointer;">
        <div class="icon-box"><i class="fas fa-star"></i></div>
        <div>
            <p class="stat-label mb-1">Đánh giá</p>
            <p class="stat-value mb-0"><?php echo $avgRating !== null ? $avgRating : '—'; ?></p>
            <span class="stat-desc"><?php echo $ratingTotal; ?> lượt đánh giá</span>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end reviews-offcanvas-premium" tabindex="-1" id="myReviewsCanvas" aria-labelledby="myReviewsCanvasLabel">
    <div class="reviews-offcanvas-header">
        <div class="reviews-header-content">
            <div class="reviews-header-icon">⭐</div>
            <div>
                <h5 class="reviews-title" id="myReviewsCanvasLabel">Đánh giá bạn nhận được</h5>
                <p class="reviews-subtitle">📊 Hiển thị tối đa 20 đánh giá gần nhất</p>
            </div>
        </div>
        <button type="button" class="reviews-close-btn" data-bs-dismiss="offcanvas" aria-label="Đóng">✕</button>
    </div>
    <div class="reviews-offcanvas-body">
        <?php if ($avgRating !== null && $ratingTotal > 0): ?>
            <div class="reviews-summary-card">
                <div class="reviews-summary-score">
                    <span class="score-number"><?php echo $avgRating; ?></span>
                    <span class="score-max">/ 5</span>
                </div>
                <div class="reviews-summary-stars">
                    <?php
                        $fullStars = floor($avgRating);
                        $fraction = $avgRating - $fullStars;
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= $fullStars) {
                                echo '<i class="fas fa-star"></i>';
                            } elseif ($i === $fullStars + 1 && $fraction >= 0.5) {
                                echo '<i class="fas fa-star-half-alt"></i>';
                            } else {
                                echo '<i class="far fa-star"></i>';
                            }
                        }
                    ?>
                </div>
                <div class="reviews-summary-count">👥 <?php echo $ratingTotal; ?> lượt đánh giá</div>
            </div>
        <?php endif; ?>

        <?php if (!$myReviews): ?>
            <div class="reviews-empty-state">
                <div class="empty-icon">💬</div>
                <h6>Chưa có đánh giá</h6>
                <p>Bạn chưa nhận được đánh giá nào. Hãy tiếp tục hoạt động để nhận phản hồi từ người dùng! 💪</p>
            </div>
        <?php else: ?>
            <div class="reviews-list">
                <?php foreach ($myReviews as $rv): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div class="review-avatar">
                                <?php if (!empty($rv['rater_avatar']) && upload_exists($rv['rater_avatar'])):
                                    $avatarUrl = htmlspecialchars(public_url_for($rv['rater_avatar'])); ?>
                                    <img src="<?php echo $avatarUrl; ?>" alt="<?php echo htmlspecialchars($rv['rater_name']); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder"><?php echo strtoupper(substr($rv['rater_name'],0,1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="review-meta">
                                <h6 class="reviewer-name">👤 <?php echo htmlspecialchars($rv['rater_name']); ?></h6>
                                <span class="review-date">🕐 <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?></span>
                            </div>
                            <div class="review-rating">
                                <div class="rating-stars">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <i class="<?php echo $i <= (int)$rv['score'] ? 'fas' : 'far'; ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="rating-score"><?php echo (int)$rv['score']; ?>/5</span>
                            </div>
                        </div>
                        <?php if (!empty($rv['comment'])): ?>
                        <div class="review-content">
                            <p>💭 <?php echo nl2br(htmlspecialchars($rv['comment'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Reviews Offcanvas Premium Styles */
.reviews-offcanvas-premium { width: 420px !important; max-width: 90vw; border: none !important; box-shadow: -20px 0 60px rgba(0, 0, 0, 0.15); }
.reviews-offcanvas-header { background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); padding: 1.5rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; }
.reviews-offcanvas-header::before { content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%); pointer-events: none; }
.reviews-header-content { display: flex; align-items: center; gap: 1rem; position: relative; z-index: 1; }
.reviews-header-icon { width: 48px; height: 48px; background: rgba(255, 255, 255, 0.2); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; backdrop-filter: blur(10px); }
.reviews-title { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }
.reviews-subtitle { color: rgba(255, 255, 255, 0.8); font-size: 0.85rem; margin: 0.25rem 0 0; }
.reviews-close-btn { width: 36px; height: 36px; border-radius: 10px; background: rgba(255, 255, 255, 0.2); border: none; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease; position: relative; z-index: 1; font-size: 1rem; }
.reviews-close-btn:hover { background: rgba(255, 255, 255, 0.3); transform: rotate(90deg); }
.reviews-offcanvas-body { padding: 1.5rem; background: linear-gradient(180deg, #f8fafc 0%, #fff 100%); height: calc(100% - 90px); overflow-y: auto; }
.reviews-summary-card { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 20px; padding: 1.5rem; text-align: center; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(245, 158, 11, 0.2); position: relative; overflow: hidden; }
.reviews-summary-card::before { content: ''; position: absolute; top: -30%; right: -30%; width: 60%; height: 60%; background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%); pointer-events: none; }
.reviews-summary-score { position: relative; z-index: 1; }
.reviews-summary-score .score-number { font-size: 3rem; font-weight: 800; color: #92400e; line-height: 1; }
.reviews-summary-score .score-max { font-size: 1.25rem; color: #b45309; font-weight: 600; }
.reviews-summary-stars { font-size: 1.5rem; color: #f59e0b; margin: 0.75rem 0; letter-spacing: 0.15rem; position: relative; z-index: 1; }
.reviews-summary-count { font-size: 0.9rem; color: #92400e; font-weight: 600; position: relative; z-index: 1; }
.reviews-empty-state { text-align: center; padding: 3rem 1.5rem; background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border-radius: 20px; border: 2px dashed #cbd5e1; }
.reviews-empty-state .empty-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 2rem; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3); }
.reviews-empty-state h6 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; }
.reviews-empty-state p { color: #64748b; font-size: 0.9rem; margin: 0; line-height: 1.6; }
.reviews-list { display: flex; flex-direction: column; gap: 1rem; }
.review-card { background: #fff; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; transition: all 0.3s ease; animation: reviewSlideIn 0.4s ease-out backwards; }
.review-card:nth-child(1) { animation-delay: 0.1s; }
.review-card:nth-child(2) { animation-delay: 0.15s; }
.review-card:nth-child(3) { animation-delay: 0.2s; }
@keyframes reviewSlideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
.review-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); border-color: #c7d2fe; }
.review-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.review-avatar { flex-shrink: 0; }
.review-avatar img { width: 48px; height: 48px; border-radius: 12px; object-fit: cover; border: 2px solid #e2e8f0; }
.review-avatar .avatar-placeholder { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; }
.review-meta { flex: 1; min-width: 0; }
.reviewer-name { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.review-date { font-size: 0.8rem; color: #94a3b8; }
.review-rating { text-align: right; flex-shrink: 0; }
.review-rating .rating-stars { color: #f59e0b; font-size: 0.85rem; letter-spacing: 0.05rem; }
.review-rating .rating-score { display: block; font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-top: 0.25rem; }
.review-content { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px; padding: 1rem; margin-top: 0.5rem; border-left: 3px solid #3b82f6; }
.review-content p { margin: 0; color: #475569; font-size: 0.9rem; line-height: 1.6; }
.reviews-offcanvas-body::-webkit-scrollbar { width: 6px; }
.reviews-offcanvas-body::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
.reviews-offcanvas-body::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); border-radius: 3px; }
@media (max-width: 576px) { .reviews-offcanvas-premium { width: 100% !important; } .review-header { flex-wrap: wrap; } .review-rating { width: 100%; text-align: left; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; } }
</style>

<style>
.quick-actions-section { margin-bottom: 2rem; }
.quick-actions-title { display: flex; align-items: center; gap: 0.75rem; font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 1.25rem; }
.quick-actions-title::before { content: '⚡'; font-size: 1.5rem; }
.quick-card-premium { background: #fff; border-radius: 20px; padding: 1.75rem; box-shadow: 0 10px 40px rgba(0,0,0,0.06); border: 1px solid rgba(226, 232, 240, 0.8); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; height: 100%; }
.quick-card-premium::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #0b3f91, #3b82f6); opacity: 0; transition: opacity 0.3s ease; }
.quick-card-premium:hover::before { opacity: 1; }
.quick-card-premium:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(59, 130, 246, 0.15); border-color: #c7d2fe; }
.quick-card-icon { width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem; transition: transform 0.3s ease; }
.quick-card-premium:hover .quick-card-icon { transform: scale(1.1) rotate(5deg); }
.quick-card-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.quick-card-icon.pink { background: linear-gradient(135deg, #fce7f3, #fbcfe8); }
.quick-card-icon.cyan { background: linear-gradient(135deg, #cffafe, #a5f3fc); }
.quick-card-icon.purple { background: linear-gradient(135deg, #e9d5ff, #d8b4fe); }
.quick-card-icon.amber { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.quick-card-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.quick-card-premium h5 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
.quick-card-premium p { color: #64748b; font-size: 0.9rem; line-height: 1.6; margin-bottom: 1rem; }
.quick-card-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #3b82f6; font-weight: 600; font-size: 0.9rem; text-decoration: none; transition: all 0.3s ease; }
.quick-card-link:hover { color: #1d4ed8; gap: 0.75rem; }
</style>

<div class="quick-actions-section">
    <h4 class="quick-actions-title">Thao tác nhanh</h4>
    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon blue">👤</div>
                <h5>📝 Cập nhật hồ sơ</h5>
                <p>Bổ sung thông tin cá nhân và kỹ năng để tăng độ tin cậy với nhà tuyển dụng.</p>
                <a href="edit_profile.php" class="quick-card-link stretched-link">Đi tới hồ sơ →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon pink">💖</div>
                <h5>❤️ Danh sách yêu thích</h5>
                <p>Mở nhanh các tin tuyển đã lưu để ứng tuyển sau khi sẵn sàng.</p>
                <a href="favorites.php" class="quick-card-link stretched-link">Xem danh sách →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon cyan">📋</div>
                <h5>🧾 Lịch sử nhận việc</h5>
                <p>Theo dõi các lần được chọn hỗ trợ trong tháng vừa qua.</p>
                <a href="assignment_history.php" class="quick-card-link stretched-link">Xem chi tiết →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon purple">🔍</div>
                <h5>🗂 Xem tin tuyển</h5>
                <p>Khám phá nhu cầu hỗ trợ mới nhất từ bệnh nhân đang cần giúp đỡ.</p>
                <a href="index.php?type=recruitment#posts" class="quick-card-link stretched-link">Khám phá ngay →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon amber">💬</div>
                <h5>📅 Quản lý lịch hẹn</h5>
                <p>Theo dõi các lịch hẹn chăm sóc và trao đổi với bệnh nhân.</p>
                <a href="view_messages.php" class="quick-card-link stretched-link">Xem lịch & tin nhắn →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon green">🛡️</div>
                <h5>🛠 Hỗ trợ tài khoản</h5>
                <p>Cần chỉnh sửa giấy tờ, muốn ẩn hồ sơ hoặc xóa tài khoản? Gửi đơn cho quản trị viên.</p>
                <a href="account_request.php" class="quick-card-link stretched-link">Gửi yêu cầu →</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Posts Table Premium */
.posts-section-premium {
    background: #ffffff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}
.posts-section-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.posts-section-header h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.posts-section-header h4::before {
    content: '📋';
    font-size: 1.5rem;
}
.posts-section-header .posts-count {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    padding: 0.35rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
}
.posts-table-premium {
    width: 100%;
    border-collapse: collapse;
}
.posts-table-premium thead {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 100%);
}
.posts-table-premium thead th {
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 1rem 1.25rem;
    border: none;
}
.posts-table-premium tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f1f5f9;
}
.posts-table-premium tbody tr:hover {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
}
.posts-table-premium tbody td {
    padding: 1.25rem;
    vertical-align: middle;
    color: #334155;
}
.posts-table-premium .post-title {
    font-weight: 600;
    color: #1e293b;
    max-width: 300px;
}
.posts-table-premium .post-category {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #f1f5f9;
    padding: 0.35rem 0.85rem;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #475569;
}
.posts-table-premium .post-area {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table-premium .post-area i {
    color: #ef4444;
}
.posts-table-premium .post-date {
    color: #64748b;
    font-size: 0.875rem;
}
.posts-table-premium .post-date i {
    color: #94a3b8;
    margin-right: 0.35rem;
}
/* Status badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 600;
}
.status-badge-open {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}
.status-badge-open::before {
    content: '';
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.status-badge-inactive {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}
.status-badge-closed {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #64748b;
}
.status-badge-taken {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}
/* Action buttons */
.action-btns {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}
.action-btn-view {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1d4ed8;
}
.action-btn-view:hover {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    transform: translateY(-2px);
    color: #1d4ed8;
}
.action-btn-edit {
    background: linear-gradient(135deg, #f5f3ff, #ede9fe);
    color: #7c3aed;
}
.action-btn-edit:hover {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    transform: translateY(-2px);
    color: #7c3aed;
}
.action-btn-delete {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #dc2626;
}
.action-btn-delete:hover {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    transform: translateY(-2px);
    color: #dc2626;
}
.action-btn-accept {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.action-btn-accept:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    color: #ffffff;
}
.action-btn-reopen {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #ffffff;
}
.action-btn-reopen:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-2px);
    color: #ffffff;
}
/* Empty state */
.posts-empty-state {
    text-align: center;
    padding: 4rem 2rem;
}
.posts-empty-state .empty-icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2.5rem;
}
.posts-empty-state h5 {
    color: #1e293b;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.posts-empty-state p {
    color: #64748b;
    margin-bottom: 1.5rem;
}
.posts-empty-state .btn-create {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    padding: 0.85rem 2rem;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}
.posts-empty-state .btn-create:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
    color: #fff;
}

/* Accept Modal Student Styles */
.accept-modal-student .modal-dialog {
    max-width: 520px;
}
.accept-modal-content-student {
    border: none;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
}
.accept-modal-header-student {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    padding: 24px 28px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    position: relative;
}
.accept-modal-icon-student {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.accept-modal-title-wrap-student {
    flex: 1;
}
.accept-modal-title-student {
    color: #fff;
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0 0 8px 0;
}
.accept-modal-subtitle-student {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.accept-modal-close-student {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.accept-modal-close-student:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: rotate(90deg);
}
.accept-modal-body-student {
    padding: 28px;
    background: #f8fafc;
}
.accept-empty-state-student {
    text-align: center;
    padding: 40px 20px;
}
.accept-empty-icon-student {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #94a3b8;
    margin: 0 auto 20px;
}
.accept-empty-state-student p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #475569;
    margin: 0 0 8px 0;
}
.accept-empty-state-student span {
    font-size: 0.9rem;
    color: #94a3b8;
}
.accept-section-student {
    margin-bottom: 24px;
}
.accept-section-label-student {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 14px;
}
.accept-section-label-student i {
    color: #2563eb;
    font-size: 0.9rem;
}
.accept-candidates-list-student {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.accept-candidate-item-student {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}
.accept-candidate-item-student:hover {
    border-color: #2563eb;
    background: #eff6ff;
}
.accept-candidate-item-student:has(.accept-radio-student:checked) {
    border-color: #2563eb;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.15);
}
.accept-radio-student {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.accept-candidate-avatar-student {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.15rem;
    font-weight: 700;
    flex-shrink: 0;
}
.accept-candidate-info-student {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.accept-candidate-name-student {
    font-size: 1.05rem;
    font-weight: 600;
    color: #1e293b;
}
.accept-candidate-badge-student {
    font-size: 0.75rem;
    color: #2563eb;
    background: #dbeafe;
    padding: 4px 12px;
    border-radius: 20px;
    width: fit-content;
    font-weight: 500;
}
.accept-check-icon-student {
    width: 28px;
    height: 28px;
    border: 2px solid #e2e8f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: transparent;
    transition: all 0.3s ease;
    flex-shrink: 0;
}
.accept-candidate-item-student:has(.accept-radio-student:checked) .accept-check-icon-student {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}
.accept-textarea-student {
    width: 100%;
    padding: 16px 18px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 0.95rem;
    resize: vertical;
    min-height: 100px;
    transition: all 0.3s ease;
    background: #fff;
}
.accept-textarea-student:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}
.accept-textarea-student::placeholder {
    color: #94a3b8;
}
.accept-actions-student {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}
.accept-btn-cancel-student {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 20px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}
.accept-btn-cancel-student:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.accept-btn-confirm-student {
    flex: 1.5;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 24px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35);
}
.accept-btn-confirm-student:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(37, 99, 235, 0.45);
}
@media (max-width: 576px) {
    .accept-modal-header-student {
        padding: 20px;
        flex-direction: column;
        text-align: center;
    }
    .accept-modal-body-student {
        padding: 20px;
    }
    .accept-modal-icon-student {
        width: 50px;
        height: 50px;
        font-size: 1.3rem;
        margin: 0 auto;
    }
    .accept-actions-student {
        flex-direction: column;
    }
}
</style>

<section id="my-posts" class="posts-section-premium">
    <div class="posts-section-header">
        <h4>Tin ứng tuyển của bạn</h4>
        <span class="posts-count"><?php echo $postsCount; ?> tin</span>
    </div>
    <?php if (!$posts): ?>
        <div class="posts-empty-state">
            <div class="empty-icon">📝</div>
            <h5>Chưa có tin ứng tuyển</h5>
            <p>Hãy bắt đầu bằng cách tạo tin ứng tuyển đầu tiên của bạn!</p>
            <a class="btn-create" href="create_application.php">
                <i class="fas fa-plus-circle"></i> Tạo tin ngay
            </a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="posts-table-premium">
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Chuyên ngành</th>
                        <th>Khu vực</th>
                        <th>Trạng thái</th>
                        <th>Ngày đăng</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td class="post-title"><?php echo htmlspecialchars($p['title']); ?></td>
                        <td><span class="post-category"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($p['category'] ?? '—'); ?></span></td>
                        <td><span class="post-area"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($p['area'] ?? '—'); ?></span></td>
                        <td>
                            <?php $pst = $p['status'] ?? 'open'; ?>
                            <?php if ($pst === 'inactive'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-inactive"><i class="fas fa-pause-circle"></i> Chưa hoạt động</span>
                            <?php elseif ($pst === 'closed'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-closed"><i class="fas fa-times-circle"></i> Đã đóng</span>
                            <?php elseif ($pst === 'taken'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-taken"><i class="fas fa-check-circle"></i> Đã nhận việc</span>
                            <?php else: ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-open">Đang mở</span>
                            <?php endif; ?>
                        </td>
                        <td class="post-date"><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></td>
                        <td>
                            <div class="action-btns">
                                <a class="action-btn action-btn-view" href="view_post.php?id=<?php echo $p['id']; ?>"><i class="fas fa-eye"></i> Xem</a>
                                <a class="action-btn action-btn-edit" href="edit_post.php?id=<?php echo $p['id']; ?>"><i class="fas fa-edit"></i> Sửa</a>
                                <form method="post" action="delete_post.php" class="d-inline" onsubmit="return confirm('Xóa tin này?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="action-btn action-btn-delete"><i class="fas fa-trash"></i> Xóa</button>
                                </form>
                                <!-- Action buttons based on status -->
                                <?php $pst = ($p['status'] ?? 'open'); ?>
                                <?php if ($pst === 'inactive'): ?>
                                    <button type="button" class="action-btn action-btn-reopen" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>"><i class="fas fa-redo"></i> Mở lại</button>
                                <?php elseif ($pst === 'taken'): ?>
                                    <form method="post" action="reopen_post.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="action-btn action-btn-accept"><i class="fas fa-unlock"></i> Mở</button>
                                    </form>
                                <?php elseif ($pst === 'closed'): ?>
                                    <button type="button" class="action-btn" style="background:#e2e8f0;color:#94a3b8;cursor:not-allowed;" disabled><i class="fas fa-lock"></i> Đã đóng</button>
                                <?php else: ?>
                                    <button type="button" class="action-btn action-btn-accept" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>" id="accept-trigger-<?php echo (int)$p['id']; ?>"><i class="fas fa-handshake"></i> Nhận việc</button>
                                <?php endif; ?>
                            </div>
                        </td>

                                                        <!-- Modal: list contacts who messaged about this post -->
                                                        <div class="modal fade accept-modal-student" id="acceptModal-<?php echo (int)$p['id']; ?>" tabindex="-1" aria-labelledby="acceptModalLabel-<?php echo (int)$p['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered">
                                                                <div class="modal-content accept-modal-content-student">
                                                                    <div class="accept-modal-header-student">
                                                                        <div class="accept-modal-icon-student">
                                                                            <i class="fas fa-handshake"></i>
                                                                        </div>
                                                                        <div class="accept-modal-title-wrap-student">
                                                                            <h5 class="accept-modal-title-student">Nhận việc</h5>
                                                                            <p class="accept-modal-subtitle-student"><?php echo htmlspecialchars($p['title']); ?></p>
                                                                        </div>
                                                                        <button type="button" class="accept-modal-close-student" data-bs-dismiss="modal" aria-label="Close">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="accept-modal-body-student">
                                                                        <?php
                                                                            $sql = "
                                                                                SELECT DISTINCT u.id, u.name FROM messages m JOIN users u ON m.from_user = u.id WHERE m.post_id = ? AND m.from_user != ?
                                                                                UNION
                                                                                SELECT DISTINCT u.id, u.name FROM conversations c
                                                                                    JOIN users u ON u.id = (CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END)
                                                                                    WHERE (c.user1_id = ? OR c.user2_id = ?)
                                                                                        AND EXISTS(
                                                                                             SELECT 1 FROM direct_messages dm WHERE dm.conversation_id = c.id AND dm.created_at >= ?
                                                                                        )
                                                                            ";
                                                                            $cstmt = $pdo->prepare($sql);
                                                                            $postCreated = $p['created_at'] ?? date('Y-m-d H:i:s');
                                                                            $cstmt->execute([(int)$p['id'], $userId, $userId, $userId, $userId, $postCreated]);
                                                                            $contacts = $cstmt->fetchAll();
                                                                        ?>
                                                                        <?php if (!$contacts): ?>
                                                                            <div class="accept-empty-state-student">
                                                                                <div class="accept-empty-icon-student">
                                                                                    <i class="fas fa-inbox"></i>
                                                                                </div>
                                                                                <p>Chưa có ai liên hệ về tin này</p>
                                                                                <span>Hãy chờ bệnh nhân liên hệ với bạn</span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <form method="post" action="accept_applicant.php" class="accept-form-student">
                                                                                <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                                                                
                                                                                <div class="accept-section-student">
                                                                                    <label class="accept-section-label-student">
                                                                                        <i class="fas fa-users"></i>
                                                                                        Chọn người nhận việc
                                                                                    </label>
                                                                                    <div class="accept-candidates-list-student">
                                                                                        <?php foreach ($contacts as $c): ?>
                                                                                            <label class="accept-candidate-item-student" for="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>">
                                                                                                <input class="accept-radio-student" type="radio" name="selected_user" id="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>" value="<?php echo $c['id']; ?>" required>
                                                                                                <div class="accept-candidate-avatar-student">
                                                                                                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                                                                                </div>
                                                                                                <div class="accept-candidate-info-student">
                                                                                                    <span class="accept-candidate-name-student"><?php echo htmlspecialchars($c['name']); ?></span>
                                                                                                    <span class="accept-candidate-badge-student">Đã liên hệ</span>
                                                                                                </div>
                                                                                                <div class="accept-check-icon-student">
                                                                                                    <i class="fas fa-check-circle"></i>
                                                                                                </div>
                                                                                            </label>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                <div class="accept-section-student">
                                                                                    <label class="accept-section-label-student">
                                                                                        <i class="fas fa-comment-alt"></i>
                                                                                        Ghi chú (tuỳ chọn)
                                                                                    </label>
                                                                                    <textarea name="note" class="accept-textarea-student" rows="3" placeholder="Nhập lời nhắn hoặc ghi chú..."></textarea>
                                                                                </div>
                                                                                
                                                                                <div class="accept-actions-student">
                                                                                    <button type="button" class="accept-btn-cancel-student" data-bs-dismiss="modal">
                                                                                        <i class="fas fa-times"></i>
                                                                                        Hủy
                                                                                    </button>
                                                                                    <button type="submit" class="accept-btn-confirm-student">
                                                                                        <i class="fas fa-check"></i>
                                                                                        Xác Nhận
                                                                                    </button>
                                                                                </div>
                                                                            </form>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once 'footer.php'; ?>
<script>
// AJAX accept handler for all accept forms on this page
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form[action="accept_applicant.php"]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var fd = new FormData(form);
            var postId = fd.get('post_id');
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
                    fetch(form.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function(res){ return res.json(); })
                .then(function(json){
                    if (json && json.success) {
                        // Hide the 'Mở' button if present
                        var showBtn = document.getElementById('show-accept-' + postId);
                        if (showBtn) showBtn.classList.add('d-none');
                        // Update accept trigger button (if exists)
                        var trigger = document.getElementById('accept-trigger-' + postId) || document.querySelector('[data-bs-target="#acceptModal-' + postId + '"]');
                        if (trigger) {
                            // Convert the trigger into a reopen control labeled 'Mở'
                            trigger.textContent = 'Mở';
                            trigger.disabled = false;
                            trigger.classList.remove('d-none','btn-success');
                            trigger.classList.add('btn-outline-success');
                            // Remove modal attributes so it no longer opens the modal
                            trigger.removeAttribute('data-bs-toggle');
                            trigger.removeAttribute('data-bs-target');
                            // Attach a one-time click handler to reopen the post
                            trigger.addEventListener('click', function reopenHandler(ev){
                                if (!confirm('Bạn có chắc muốn mở lại tin này?')) return;
                                var body = new URLSearchParams(); body.append('id', postId);
                                fetch('reopen_post.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' } })
                                .then(function(r){ return r.json(); })
                                .then(function(j){
                                    if (j && j.success) {
                                        var showBtn2 = document.getElementById('show-accept-' + postId);
                                        if (showBtn2) { showBtn2.classList.remove('d-none'); }
                                        // hide this trigger and update badge
                                        trigger.classList.add('d-none');
                                        var badge2 = document.getElementById('post-status-badge-' + postId);
                                        if (badge2) { badge2.textContent = 'Đang mở'; badge2.className = 'badge bg-success'; }
                                        else {
                                            var triggerRow2 = trigger ? (trigger.closest('tr') || null) : null;
                                            if (triggerRow2) {
                                                var statusCell2 = triggerRow2.querySelector('td:nth-child(4)');
                                                if (statusCell2) statusCell2.innerHTML = '<span class="badge bg-success">Đang mở</span>';
                                            }
                                        }
                                    } else {
                                        alert(j && j.message ? j.message : 'Không thể mở tin.');
                                    }
                                }).catch(function(){ alert('Lỗi kết nối.'); });
                            }, { once: true });
                        }
                        // Update status badge in the same table row or by id
                        var badge = document.getElementById('post-status-badge-' + postId);
                        if (badge) { badge.textContent = 'Đã nhận việc này'; badge.className = 'badge bg-success'; }
                        else {
                            var triggerRow = trigger ? (trigger.closest('tr') || null) : null;
                            if (triggerRow) {
                                var statusCell = triggerRow.querySelector('td:nth-child(4)');
                                if (statusCell) statusCell.innerHTML = '<span class="badge bg-success">Đã nhận việc này</span>';
                            }
                        }
                        // hide modal
                        var modalEl = document.getElementById('acceptModal-' + postId);
                        if (modalEl) {
                            var bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                            bsModal.hide();
                        }
                    } else {
                        alert(json && json.message ? json.message : 'Không thể hoàn tất thao tác');
                        if (submitBtn) submitBtn.disabled = false;
                    }
                }).catch(function(){
                    alert('Lỗi kết nối. Vui lòng thử lại.');
                    if (submitBtn) submitBtn.disabled = false;
                });
        });
    });
});
</script>
<script>
// Intercept reopen forms and ensure the 'Nhận việc' button appears reliably
document.addEventListener('DOMContentLoaded', function(){
    function createAcceptButton(postId) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-success';
        btn.id = 'accept-trigger-' + postId;
        btn.setAttribute('data-bs-toggle', 'modal');
        btn.setAttribute('data-bs-target', '#acceptModal-' + postId);
        btn.textContent = 'Nhận việc';
        return btn;
    }

    document.querySelectorAll('form[action="reopen_post.php"]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var idInput = form.querySelector('input[name="id"]');
            if (!idInput) return;
            var postId = idInput.value;
            var body = new URLSearchParams(); body.append('id', postId);
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            fetch(form.action, { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' } })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json && json.success) {
                    // Update badge to 'Đang mở'
                    var badge = document.getElementById('post-status-badge-' + postId);
                    if (badge) { badge.textContent = 'Đang mở'; badge.className = 'badge bg-success'; }

                    // Find action cell (closest td) to insert the accept button
                    var actionCell = form.closest('td') || form.parentNode;
                    // If accept button already exists, ensure it's visible and has modal attributes
                    var existing = document.getElementById('accept-trigger-' + postId);
                    if (existing) {
                        existing.className = 'btn btn-sm btn-success';
                        existing.setAttribute('data-bs-toggle', 'modal');
                        existing.setAttribute('data-bs-target', '#acceptModal-' + postId);
                    } else {
                        var btn = createAcceptButton(postId);
                        if (actionCell) actionCell.insertBefore(btn, form);
                        else form.parentNode.insertBefore(btn, form);
                    }

                    // remove the reopen form
                    form.remove();
                } else {
                    alert(json && json.message ? json.message : 'Không thể mở tin.');
                    if (submitBtn) submitBtn.disabled = false;
                }
            }).catch(function(){
                alert('Lỗi kết nối. Vui lòng thử lại.');
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    });
});
</script>
