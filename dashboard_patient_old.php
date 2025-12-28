<?php
require_once 'config.php';
require_login();
// If admin, do not allow patient dashboard access
if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}
if ($_SESSION['role'] !== 'patient') {
        die('Truy cập bị từ chối.');
}

$userId = $_SESSION['user_id'];

// check can_post flag to conditionally show create button
$stmtCan = $pdo->prepare('SELECT can_post FROM users WHERE id = ?');
$stmtCan->execute([$userId]);
$canPostRow = $stmtCan->fetch();
$userCanPost = !empty($canPostRow['can_post']);

$stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ? AND type = "recruitment" ORDER BY created_at DESC');
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();
$postsCount = count($posts);

$msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE to_user = ?');
$msgStmt->execute([$userId]);
$messageCount = (int)$msgStmt->fetchColumn();

$applicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE post_id IN (SELECT id FROM posts WHERE user_id = ? AND type = "recruitment")');
$applicationsStmt->execute([$userId]);
$applicationsCount = (int)$applicationsStmt->fetchColumn();

$favoritePosts = [];
try {
    $favStmt = $pdo->prepare('SELECT p.* FROM favorites f JOIN posts p ON p.id = f.post_id WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 10');
    $favStmt->execute([$userId]);
    $favoritePosts = $favStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Fetch favorites failed: ' . $e->getMessage());
}

$recentAssignments = [];
$recentAssignCount = 0;
try {
    $assignmentSql = "SELECT m.created_at AS assigned_at, p.id AS post_id, p.title, u.name AS student_name, u.email AS student_email "
        . "FROM messages m "
        . "JOIN posts p ON p.id = m.post_id "
        . "JOIN users u ON u.id = m.to_user "
        . "WHERE m.from_user = ? "
        . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
        . "AND m.message LIKE 'Bạn đã được chọn nhận việc%' "
        . "ORDER BY m.created_at DESC";
    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute([$userId]);
    $recentAssignments = $assignmentStmt->fetchAll();
    $recentAssignCount = count($recentAssignments);
} catch (Throwable $e) {
    error_log('Fetch patient assignment history failed: ' . $e->getMessage());
}

$ratingStmt = $pdo->prepare('SELECT AVG(score) AS avg_score, COUNT(*) AS total FROM ratings WHERE rated_id = ?');
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData && $ratingData['avg_score'] ? round($ratingData['avg_score'], 1) : null;
$ratingTotal = $ratingData ? (int)$ratingData['total'] : 0;

$myReviewStmt = $pdo->prepare('SELECT r.score, r.comment, r.created_at, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role FROM ratings r JOIN users u ON u.id = r.rater_id WHERE r.rated_id = ? ORDER BY r.created_at DESC LIMIT 20');
$myReviewStmt->execute([$userId]);
$myReviews = $myReviewStmt->fetchAll();

require_once 'header.php';
?>

<style>
.dashboard-hero-new {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 28px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(11, 63, 145, 0.3);
}
.dashboard-hero-new::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    pointer-events: none;
}
.dashboard-hero-new::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(59,130,246,0.3) 0%, transparent 70%);
    pointer-events: none;
}
.hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
}
.hero-welcome {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}
.hero-avatar {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    border: 2px solid rgba(255,255,255,0.3);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.hero-text h2 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.hero-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    color: #fff;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid rgba(255,255,255,0.2);
}
.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.hero-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: 14px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}
.hero-btn-primary {
    background: #fff;
    color: #1e40af;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.hero-btn-primary:hover {
    background: #f0f9ff;
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    color: #1e40af;
    text-decoration: none;
}
.hero-btn-secondary {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
    backdrop-filter: blur(10px);
}
.hero-btn-secondary:hover {
    background: rgba(255,255,255,0.25);
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
}
.hero-btn-outline {
    background: transparent;
    color: #fff;
    border: 2px solid rgba(255,255,255,0.4);
}
.hero-btn-outline:hover {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.6);
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
}
@media (max-width: 768px) {
    .dashboard-hero-new { padding: 1.75rem; }
    .hero-content { flex-direction: column; align-items: flex-start; }
    .hero-actions { width: 100%; }
    .hero-btn { flex: 1; justify-content: center; min-width: 140px; }
    .hero-text h2 { font-size: 1.4rem; }
}
</style>

<div class="dashboard-hero-new">
    <div class="hero-content">
        <div class="hero-welcome">
            <div class="hero-avatar">👋</div>
            <div class="hero-text">
                <h2>Xin chào, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
                <span class="hero-role-badge">🏥 Bệnh nhân</span>
            </div>
        </div>
        <div class="hero-actions">
            <?php if ($userCanPost): ?>
                <a href="create_recruitment.php" class="hero-btn hero-btn-primary">📢 Tạo tin tuyển dụng</a>
            <?php else: ?>
                <button class="hero-btn hero-btn-secondary" disabled style="opacity:0.6;">📢 Tạo tin (bị chặn)</button>
            <?php endif; ?>
            <a href="view_messages.php?from_admin=1" class="hero-btn hero-btn-secondary">🔔 Thông báo</a>
            <a href="view_messages.php" class="hero-btn hero-btn-outline">📬 Tin nhắn</a>
        </div>
    </div>
</div>

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

/* Card colors */
.card-blue { border-color: #3b82f6; background: linear-gradient(135deg, #fff 0%, #dbeafe 100%); }
.card-blue .icon-box { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 20px rgba(59,130,246,0.4); }
.card-blue .stat-value { color: #1e40af; }
.card-blue:hover { box-shadow: 0 20px 50px rgba(59,130,246,0.25); }

.card-green { border-color: #10b981; background: linear-gradient(135deg, #fff 0%, #d1fae5 100%); }
.card-green .icon-box { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 20px rgba(16,185,129,0.4); }
.card-green .stat-value { color: #047857; }
.card-green:hover { box-shadow: 0 20px 50px rgba(16,185,129,0.25); }

.card-cyan { border-color: #06b6d4; background: linear-gradient(135deg, #fff 0%, #cffafe 100%); }
.card-cyan .icon-box { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 20px rgba(6,182,212,0.4); }
.card-cyan .stat-value { color: #0e7490; }
.card-cyan:hover { box-shadow: 0 20px 50px rgba(6,182,212,0.25); }

.card-pink { border-color: #ec4899; background: linear-gradient(135deg, #fff 0%, #fce7f3 100%); }
.card-pink .icon-box { background: linear-gradient(135deg, #ec4899, #db2777); box-shadow: 0 8px 20px rgba(236,72,153,0.4); }
.card-pink .stat-value { color: #be185d; }
.card-pink:hover { box-shadow: 0 20px 50px rgba(236,72,153,0.25); }

.card-purple { border-color: #8b5cf6; background: linear-gradient(135deg, #fff 0%, #ede9fe 100%); }
.card-purple .icon-box { background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 8px 20px rgba(139,92,246,0.4); }
.card-purple .stat-value { color: #6d28d9; }
.card-purple:hover { box-shadow: 0 20px 50px rgba(139,92,246,0.25); }

.card-amber { border-color: #f59e0b; background: linear-gradient(135deg, #fff 0%, #fef3c7 100%); }
.card-amber .icon-box { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 20px rgba(245,158,11,0.4); }
.card-amber .stat-value { color: #b45309; }
.card-amber:hover { box-shadow: 0 20px 50px rgba(245,158,11,0.25); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
.stats-enhanced .stat-card-new:nth-child(1) { animation: fadeInUp 0.5s ease-out 0.1s backwards; }
.stats-enhanced .stat-card-new:nth-child(2) { animation: fadeInUp 0.5s ease-out 0.15s backwards; }
.stats-enhanced .stat-card-new:nth-child(3) { animation: fadeInUp 0.5s ease-out 0.2s backwards; }
.stats-enhanced .stat-card-new:nth-child(4) { animation: fadeInUp 0.5s ease-out 0.25s backwards; }
.stats-enhanced .stat-card-new:nth-child(5) { animation: fadeInUp 0.5s ease-out 0.3s backwards; }
.stats-enhanced .stat-card-new:nth-child(6) { animation: fadeInUp 0.5s ease-out 0.35s backwards; }
</style>

<div class="stats-enhanced" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin: 1.75rem 0 2.25rem;">
    <div class="stat-card-new card-blue">
        <div class="icon-box">💼</div>
        <div>
            <p class="stat-label mb-1">Tin tuyển dụng</p>
            <p class="stat-value mb-0"><?php echo $postsCount; ?></p>
            <span class="stat-desc">Đã đăng</span>
        </div>
    </div>
    <div class="stat-card-new card-green">
        <div class="icon-box">🤝</div>
        <div>
            <p class="stat-label mb-1">Lượt liên hệ</p>
            <p class="stat-value mb-0"><?php echo $applicationsCount; ?></p>
            <span class="stat-desc">Từ sinh viên</span>
        </div>
    </div>
    <a href="favorites.php" class="stat-card-new card-cyan">
        <div class="icon-box">❤️</div>
        <div>
            <p class="stat-label mb-1">Yêu thích</p>
            <p class="stat-value mb-0"><?php echo count($favoritePosts); ?></p>
            <span class="stat-desc">Bài đăng đã lưu</span>
        </div>
    </a>
    <a href="assignment_history.php" class="stat-card-new card-pink">
        <div class="icon-box">📅</div>
        <div>
            <p class="stat-label mb-1">Lịch sử nhận việc</p>
            <p class="stat-value mb-0"><?php echo $recentAssignCount; ?></p>
            <span class="stat-desc">Trong 30 ngày gần nhất</span>
        </div>
    </a>
    <div class="stat-card-new card-amber" role="button" tabindex="0" data-bs-toggle="offcanvas" data-bs-target="#patientReviewsCanvas" aria-controls="patientReviewsCanvas" style="cursor: pointer;">
        <div class="icon-box">⭐</div>
        <div>
            <p class="stat-label mb-1">Đánh giá</p>
            <p class="stat-value mb-0"><?php echo $avgRating !== null ? $avgRating : '—'; ?></p>
            <span class="stat-desc"><?php echo $ratingTotal; ?> lượt đánh giá</span>
        </div>
    </div>
</div>
<div class="offcanvas offcanvas-end reviews-offcanvas-premium" tabindex="-1" id="patientReviewsCanvas" aria-labelledby="patientReviewsCanvasLabel">
        <div class="reviews-offcanvas-header">
            <div class="reviews-header-content">
                <div class="reviews-header-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div>
                    <h5 class="reviews-title" id="patientReviewsCanvasLabel">Đánh giá về bạn</h5>
                    <p class="reviews-subtitle">Hiển thị tối đa 20 phản hồi gần nhất</p>
                </div>
            </div>
            <button type="button" class="reviews-close-btn" data-bs-dismiss="offcanvas" aria-label="Đóng">
                <i class="fas fa-times"></i>
            </button>
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
                    <div class="reviews-summary-count">
                        <i class="fas fa-users"></i> <?php echo $ratingTotal; ?> lượt đánh giá
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$myReviews): ?>
                <div class="reviews-empty-state">
                    <div class="empty-icon">
                        <i class="far fa-comment-dots"></i>
                    </div>
                    <h6>Chưa có đánh giá</h6>
                    <p>Bạn chưa nhận được đánh giá nào. Hãy tiếp tục hoạt động để nhận phản hồi từ người dùng!</p>
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
                                        <div class="avatar-placeholder">
                                            <?php echo strtoupper(substr($rv['rater_name'],0,1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="review-meta">
                                    <h6 class="reviewer-name"><?php echo htmlspecialchars($rv['rater_name']); ?></h6>
                                    <span class="review-date">
                                        <i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?>
                                    </span>
                                </div>
                                <div class="review-rating">
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?php echo $i <= (int)$rv['score'] ? 'fas' : 'far'; ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="rating-score"><?php echo (int)$rv['score']; ?>/5</span>
                                </div>
                            </div>
                            <?php if (!empty($rv['comment'])): ?>
                            <div class="review-content">
                                <p><?php echo nl2br(htmlspecialchars($rv['comment'])); ?></p>
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
.reviews-offcanvas-premium {
    width: 420px !important;
    max-width: 90vw;
    border: none !important;
    box-shadow: -20px 0 60px rgba(0, 0, 0, 0.15);
}

.reviews-offcanvas-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.reviews-offcanvas-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}

.reviews-header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
    z-index: 1;
}

.reviews-header-icon {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #fff;
    backdrop-filter: blur(10px);
}

.reviews-title {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
}

.reviews-subtitle {
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.85rem;
    margin: 0.25rem 0 0;
}

.reviews-close-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.reviews-close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.reviews-offcanvas-body {
    padding: 1.5rem;
    background: linear-gradient(180deg, #f8fafc 0%, #fff 100%);
    height: calc(100% - 90px);
    overflow-y: auto;
}

/* Summary Card */
.reviews-summary-card {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 20px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 30px rgba(245, 158, 11, 0.2);
    position: relative;
    overflow: hidden;
}

.reviews-summary-card::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -30%;
    width: 60%;
    height: 60%;
    background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%);
    pointer-events: none;
}

.reviews-summary-score {
    position: relative;
    z-index: 1;
}

.reviews-summary-score .score-number {
    font-size: 3rem;
    font-weight: 800;
    color: #92400e;
    line-height: 1;
}

.reviews-summary-score .score-max {
    font-size: 1.25rem;
    color: #b45309;
    font-weight: 600;
}

.reviews-summary-stars {
    font-size: 1.5rem;
    color: #f59e0b;
    margin: 0.75rem 0;
    letter-spacing: 0.15rem;
    position: relative;
    z-index: 1;
}

.reviews-summary-count {
    font-size: 0.9rem;
    color: #92400e;
    font-weight: 600;
    position: relative;
    z-index: 1;
}

.reviews-summary-count i {
    margin-right: 0.35rem;
}

/* Empty State */
.reviews-empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border-radius: 20px;
    border: 2px dashed #cbd5e1;
}

.reviews-empty-state .empty-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.25rem;
    font-size: 2rem;
    color: #fff;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.reviews-empty-state h6 {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.reviews-empty-state p {
    color: #64748b;
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.6;
}

/* Review Cards */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.review-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    animation: reviewSlideIn 0.4s ease-out backwards;
}

.review-card:nth-child(1) { animation-delay: 0.1s; }
.review-card:nth-child(2) { animation-delay: 0.15s; }
.review-card:nth-child(3) { animation-delay: 0.2s; }
.review-card:nth-child(4) { animation-delay: 0.25s; }
.review-card:nth-child(5) { animation-delay: 0.3s; }

@keyframes reviewSlideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border-color: #c7d2fe;
}

.review-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.review-avatar {
    flex-shrink: 0;
}

.review-avatar img {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    object-fit: cover;
    border: 2px solid #e2e8f0;
}

.review-avatar .avatar-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
}

.review-meta {
    flex: 1;
    min-width: 0;
}

.reviewer-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.review-date {
    font-size: 0.8rem;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.review-rating {
    text-align: right;
    flex-shrink: 0;
}

.review-rating .rating-stars {
    color: #f59e0b;
    font-size: 0.85rem;
    letter-spacing: 0.05rem;
}

.review-rating .rating-score {
    display: block;
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 0.25rem;
}

.review-content {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    padding: 1rem;
    margin-top: 0.5rem;
    border-left: 3px solid #667eea;
}

.review-content p {
    margin: 0;
    color: #475569;
    font-size: 0.9rem;
    line-height: 1.6;
}

/* Scrollbar Styling */
.reviews-offcanvas-body::-webkit-scrollbar {
    width: 6px;
}

.reviews-offcanvas-body::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.reviews-offcanvas-body::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 3px;
}

.reviews-offcanvas-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
}

@media (max-width: 576px) {
    .reviews-offcanvas-premium {
        width: 100% !important;
    }
    
    .review-header {
        flex-wrap: wrap;
    }
    
    .review-rating {
        width: 100%;
        text-align: left;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #e2e8f0;
    }
}
</style>

<style>
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}
@media (max-width: 992px) {
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .quick-actions-grid { grid-template-columns: 1fr; }
}
.action-card {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    padding: 1.75rem;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    color: inherit;
    display: block;
}
.action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.6s ease;
}
.action-card:hover::before {
    left: 100%;
}
.action-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 25px 60px rgba(59, 130, 246, 0.15), 0 0 0 1px rgba(59, 130, 246, 0.1);
    text-decoration: none;
    color: inherit;
}
.action-card .action-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    margin-bottom: 1rem;
    transition: all 0.4s ease;
}
.action-card:hover .action-icon {
    transform: scale(1.15) rotate(5deg);
}
.action-card .action-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.action-card .action-desc {
    font-size: 0.9rem;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 0.75rem;
}
.action-card .action-link {
    font-weight: 600;
    font-size: 0.9rem;
    color: #3b82f6;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: gap 0.3s ease;
}
.action-card:hover .action-link {
    gap: 0.6rem;
    color: #1d4ed8;
}

/* Card color themes */
.action-card.card-history { border-left: 4px solid #3b82f6; }
.action-card.card-history .action-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.action-card.card-history:hover { box-shadow: 0 25px 60px rgba(59, 130, 246, 0.2); }

.action-card.card-rating { border-left: 4px solid #f59e0b; }
.action-card.card-rating .action-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.action-card.card-rating:hover { box-shadow: 0 25px 60px rgba(245, 158, 11, 0.2); }

.action-card.card-search { border-left: 4px solid #10b981; }
.action-card.card-search .action-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.action-card.card-search:hover { box-shadow: 0 25px 60px rgba(16, 185, 129, 0.2); }

.action-card.card-update { border-left: 4px solid #8b5cf6; }
.action-card.card-update .action-icon { background: linear-gradient(135deg, #ede9fe, #ddd6fe); }
.action-card.card-update:hover { box-shadow: 0 25px 60px rgba(139, 92, 246, 0.2); }

.action-card.card-schedule { border-left: 4px solid #06b6d4; }
.action-card.card-schedule .action-icon { background: linear-gradient(135deg, #cffafe, #a5f3fc); }
.action-card.card-schedule:hover { box-shadow: 0 25px 60px rgba(6, 182, 212, 0.2); }

.action-card.card-support { border-left: 4px solid #ec4899; }
.action-card.card-support .action-icon { background: linear-gradient(135deg, #fce7f3, #fbcfe8); }
.action-card.card-support:hover { box-shadow: 0 25px 60px rgba(236, 72, 153, 0.2); }

/* Animation */
@keyframes cardFadeIn {
    from { opacity: 0; transform: translateY(25px); }
    to { opacity: 1; transform: translateY(0); }
}
.action-card:nth-child(1) { animation: cardFadeIn 0.5s ease-out 0.05s backwards; }
.action-card:nth-child(2) { animation: cardFadeIn 0.5s ease-out 0.1s backwards; }
.action-card:nth-child(3) { animation: cardFadeIn 0.5s ease-out 0.15s backwards; }
.action-card:nth-child(4) { animation: cardFadeIn 0.5s ease-out 0.2s backwards; }
.action-card:nth-child(5) { animation: cardFadeIn 0.5s ease-out 0.25s backwards; }
.action-card:nth-child(6) { animation: cardFadeIn 0.5s ease-out 0.3s backwards; }
</style>

<div class="quick-actions-grid">
    <a href="assignment_history.php" class="action-card card-history">
        <div class="action-icon">🧾</div>
        <h5 class="action-title">Lịch sử nhận việc</h5>
        <p class="action-desc">Theo dõi các lần chọn sinh viên trong 30 ngày gần nhất.</p>
        <span class="action-link">Xem chi tiết →</span>
    </a>
    <a href="#patientReviewsCanvas" class="action-card card-rating" data-bs-toggle="offcanvas" data-bs-target="#patientReviewsCanvas">
        <div class="action-icon">⭐</div>
        <h5 class="action-title">Đánh giá đã nhận</h5>
        <p class="action-desc">Theo dõi phản hồi từ sinh viên và cải thiện trải nghiệm hỗ trợ.</p>
        <span class="action-link">Xem đánh giá →</span>
    </a>
    <a href="index.php?type=application#posts" class="action-card card-search">
        <div class="action-icon">👩‍⚕️</div>
        <h5 class="action-title">Tìm sinh viên phù hợp</h5>
        <p class="action-desc">Duyệt tin ứng tuyển để chọn người hỗ trợ đáng tin cậy.</p>
        <span class="action-link">Xem danh sách →</span>
    </a>
    <a href="#my-posts" class="action-card card-update">
        <div class="action-icon">📝</div>
        <h5 class="action-title">Cập nhật tin tuyển</h5>
        <p class="action-desc">Chỉnh sửa hoặc bổ sung thông tin để thu hút sinh viên.</p>
        <span class="action-link">Quản lý tin →</span>
    </a>
    <a href="view_messages.php" class="action-card card-schedule">
        <div class="action-icon">📅</div>
        <h5 class="action-title">Hẹn lịch chăm sóc</h5>
        <p class="action-desc">Thống nhất lịch làm việc và trao đổi qua tin nhắn.</p>
        <span class="action-link">Mở hộp thư →</span>
    </a>
    <a href="account_request.php" class="action-card card-support">
        <div class="action-icon">🛠</div>
        <h5 class="action-title">Hỗ trợ tài khoản</h5>
        <p class="action-desc">Viết nhầm tên, cần ẩn hồ sơ hoặc muốn xóa tài khoản? Gửi yêu cầu cho quản trị viên.</p>
        <span class="action-link">Gửi yêu cầu →</span>
    </a>
</div>

<style>
.posts-section {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
}
.posts-section-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem 2rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.posts-section-header h4 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.posts-section-header .header-icon {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}
.posts-table {
    width: 100%;
    border-collapse: collapse;
}
.posts-table thead th {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1rem 1.25rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
}
.posts-table tbody tr {
    transition: all 0.3s ease;
}
.posts-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.04) 0%, rgba(139, 92, 246, 0.04) 100%);
}
.posts-table tbody td {
    padding: 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.posts-table .post-title {
    font-weight: 600;
    color: #1e293b;
    max-width: 280px;
}
.posts-table .post-category {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.85rem;
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #6d28d9;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.posts-table .post-area {
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table .post-area a {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}
.posts-table .post-area a:hover {
    text-decoration: underline;
}
.posts-table .post-date {
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table .status-badge {
    padding: 0.45rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.posts-table .status-badge.status-open {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #047857;
}
.posts-table .status-badge.status-closed {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    color: #475569;
}
.posts-table .status-badge.status-inactive {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #b45309;
}
.posts-table .status-badge.status-taken {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.posts-table .action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: nowrap;
    justify-content: flex-end;
    align-items: center;
}
.posts-table .action-btn {
    padding: 0.45rem 0.9rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.posts-table .action-btn.btn-view {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.posts-table .action-btn.btn-view:hover {
    background: linear-gradient(135deg, #bfdbfe, #93c5fd);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-edit {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #475569;
}
.posts-table .action-btn.btn-edit:hover {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-delete {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #dc2626;
}
.posts-table .action-btn.btn-delete:hover {
    background: linear-gradient(135deg, #fecaca, #fca5a5);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-accept {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.posts-table .action-btn.btn-accept:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-reopen {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.posts-table .action-btn.btn-reopen:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-2px);
}
.posts-empty {
    padding: 4rem 2rem;
    text-align: center;
}
.posts-empty-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 1.5rem;
}
.posts-empty p {
    color: #64748b;
    font-size: 1rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 992px) {
    .posts-table thead { display: none; }
    .posts-table tbody tr {
        display: block;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
    }
    .posts-table tbody td {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .posts-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        font-size: 0.85rem;
    }
    .posts-table tbody td:last-child {
        border-bottom: none;
    }
    .posts-table .action-buttons {
        justify-content: flex-start;
    }
}

/* Accept Modal Styles */
.accept-modal .modal-dialog {
    max-width: 500px;
}
.accept-modal-content {
    border: none;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
}
.accept-modal-header {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    padding: 24px 28px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    position: relative;
}
.accept-modal-icon {
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
.accept-modal-title-wrap {
    flex: 1;
}
.accept-modal-title {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 6px 0;
}
.accept-modal-subtitle {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.accept-modal-close {
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
.accept-modal-close:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: rotate(90deg);
}
.accept-modal-body {
    padding: 28px;
    background: #f8fafc;
}
.accept-empty-state {
    text-align: center;
    padding: 40px 20px;
}
.accept-empty-icon {
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
.accept-empty-state p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #475569;
    margin: 0 0 8px 0;
}
.accept-empty-state span {
    font-size: 0.9rem;
    color: #94a3b8;
}
.accept-section {
    margin-bottom: 24px;
}
.accept-section:last-of-type {
    margin-bottom: 0;
}
.accept-section-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 12px;
}
.accept-section-label i {
    color: #10b981;
    font-size: 0.85rem;
}
.accept-candidates-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.accept-candidate-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}
.accept-candidate-item:hover {
    border-color: #10b981;
    background: #f0fdf4;
}
.accept-candidate-item:has(.accept-radio:checked) {
    border-color: #10b981;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.15);
}
.accept-radio {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.accept-candidate-avatar {
    width: 46px;
    height: 46px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    font-weight: 700;
    flex-shrink: 0;
}
.accept-candidate-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.accept-candidate-name {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
}
.accept-candidate-badge {
    font-size: 0.75rem;
    color: #10b981;
    background: #d1fae5;
    padding: 3px 10px;
    border-radius: 20px;
    width: fit-content;
    font-weight: 500;
}
.accept-check-icon {
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
.accept-candidate-item:has(.accept-radio:checked) .accept-check-icon {
    background: #10b981;
    border-color: #10b981;
    color: #fff;
}
.accept-textarea {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 0.95rem;
    resize: vertical;
    min-height: 90px;
    transition: all 0.3s ease;
    background: #fff;
}
.accept-textarea:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.accept-textarea::placeholder {
    color: #94a3b8;
}
.accept-actions {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}
.accept-btn-cancel {
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
.accept-btn-cancel:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.accept-btn-confirm {
    flex: 1.5;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 24px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
}
.accept-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(16, 185, 129, 0.45);
}
@media (max-width: 576px) {
    .accept-modal-header {
        padding: 20px;
    }
    .accept-modal-body {
        padding: 20px;
    }
    .accept-modal-icon {
        width: 48px;
        height: 48px;
        font-size: 1.2rem;
    }
    .accept-actions {
        flex-direction: column;
    }
    .accept-btn-confirm {
        flex: 1;
    }
}
</style>

<section id="my-posts" class="posts-section">
    <div class="posts-section-header">
        <h4><span class="header-icon">📋</span> Tin tuyển dụng của bạn</h4>
        <?php if ($userCanPost): ?>
            <a href="create_recruitment.php" class="btn btn-primary btn-sm" style="border-radius: 10px;">+ Đăng tin mới</a>
        <?php endif; ?>
    </div>
    <?php if (!$posts): ?>
        <div class="posts-empty">
            <div class="posts-empty-icon">📝</div>
            <p>Chưa có tin nào. Hãy đăng tin để tìm sinh viên Y hỗ trợ.</p>
            <a class="btn btn-primary" href="create_recruitment.php" style="border-radius: 12px; padding: 0.75rem 1.5rem;">Đăng tin mới</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="posts-table">
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Chuyên khoa</th>
                        <th>Khu vực</th>
                        <th>Trạng thái</th>
                        <th>Ngày đăng</th>
                        <th style="text-align: right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td data-label="Tiêu đề"><span class="post-title"><?php echo htmlspecialchars($p['title']); ?></span></td>
                        <td data-label="Chuyên khoa"><span class="post-category">🏥 <?php echo htmlspecialchars($p['category'] ?? '—'); ?></span></td>
                        <td data-label="Khu vực" class="post-area">
                            <?php $area = $p['area'] ?? '—'; ?>
                            <?php if ($area && filter_var($area, FILTER_VALIDATE_URL)): ?>
                                <a href="<?php echo htmlspecialchars($area); ?>" target="_blank" rel="noopener">📍 Mở bản đồ</a>
                            <?php else: ?>
                                📍 <?php echo htmlspecialchars($area ?: '—'); ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Trạng thái">
                            <?php $pst = $p['status'] ?? 'open'; ?>
                            <?php if ($pst === 'inactive'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-inactive">⏸ Chưa hoạt động</span>
                            <?php elseif ($pst === 'closed'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-closed">🔒 Đã đóng</span>
                            <?php elseif ($pst === 'taken'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-taken">✅ Đã nhận việc</span>
                            <?php else: ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-open">🟢 Đang mở</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ngày đăng" class="post-date">🗓 <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></td>
                        <td data-label="Thao tác">
                            <div class="action-buttons">
                                <a class="action-btn btn-view" href="view_post.php?id=<?php echo $p['id']; ?>">👁 Xem</a>
                                <a class="action-btn btn-edit" href="edit_post.php?id=<?php echo $p['id']; ?>">✏️ Sửa</a>
                                <form method="post" action="delete_post.php" class="d-inline" onsubmit="return confirm('Xóa tin này?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="action-btn btn-delete">🗑 Xóa</button>
                                </form>
                                <?php $pst = ($p['status'] ?? 'open'); ?>
                                <?php if ($pst === 'inactive'): ?>
                                    <button type="button" class="action-btn btn-reopen" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>">🔄 Mở lại</button>
                                <?php elseif ($pst === 'taken'): ?>
                                    <form method="post" action="reopen_post.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="action-btn btn-reopen">🔄 Mở</button>
                                    </form>
                                <?php elseif ($pst === 'closed'): ?>
                                    <button type="button" class="action-btn" disabled style="opacity:0.5;">🔒 Đã đóng</button>
                                <?php else: ?>
                                    <button type="button" class="action-btn btn-accept" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>" id="accept-trigger-<?php echo (int)$p['id']; ?>">✅ Nhận việc</button>
                                <?php endif; ?>

                                                        <div class="modal fade accept-modal" id="acceptModal-<?php echo (int)$p['id']; ?>" tabindex="-1" aria-labelledby="acceptModalLabel-<?php echo (int)$p['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered">
                                                                <div class="modal-content accept-modal-content">
                                                                    <div class="accept-modal-header">
                                                                        <div class="accept-modal-icon">
                                                                            <i class="fas fa-user-check"></i>
                                                                        </div>
                                                                        <div class="accept-modal-title-wrap">
                                                                            <h5 class="accept-modal-title">Chọn người nhận việc</h5>
                                                                            <p class="accept-modal-subtitle"><?php echo htmlspecialchars($p['title']); ?></p>
                                                                        </div>
                                                                        <button type="button" class="accept-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="accept-modal-body">
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
                                                                            <div class="accept-empty-state">
                                                                                <div class="accept-empty-icon">
                                                                                    <i class="fas fa-inbox"></i>
                                                                                </div>
                                                                                <p>Chưa có ai liên hệ về tin này</p>
                                                                                <span>Hãy chờ sinh viên liên hệ với bạn</span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <form method="post" action="accept_applicant.php" class="accept-form">
                                                                                <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                                                                
                                                                                <div class="accept-section">
                                                                                    <label class="accept-section-label">
                                                                                        <i class="fas fa-users"></i>
                                                                                        Danh sách ứng viên
                                                                                    </label>
                                                                                    <div class="accept-candidates-list">
                                                                                        <?php foreach ($contacts as $idx => $c): ?>
                                                                                            <label class="accept-candidate-item" for="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>">
                                                                                                <input class="accept-radio" type="radio" name="selected_user" id="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>" value="<?php echo $c['id']; ?>" required>
                                                                                                <div class="accept-candidate-avatar">
                                                                                                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                                                                                </div>
                                                                                                <div class="accept-candidate-info">
                                                                                                    <span class="accept-candidate-name"><?php echo htmlspecialchars($c['name']); ?></span>
                                                                                                    <span class="accept-candidate-badge">Đã liên hệ</span>
                                                                                                </div>
                                                                                                <div class="accept-check-icon">
                                                                                                    <i class="fas fa-check-circle"></i>
                                                                                                </div>
                                                                                            </label>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                <div class="accept-section">
                                                                                    <label class="accept-section-label">
                                                                                        <i class="fas fa-comment-alt"></i>
                                                                                        Ghi chú cho ứng viên (tuỳ chọn)
                                                                                    </label>
                                                                                    <textarea name="note" class="accept-textarea" rows="3" placeholder="Nhập lời nhắn hoặc hướng dẫn cho người được chọn..."></textarea>
                                                                                </div>
                                                                                
                                                                                <div class="accept-actions">
                                                                                    <button type="button" class="accept-btn-cancel" data-bs-dismiss="modal">
                                                                                        <i class="fas fa-times"></i>
                                                                                        Hủy bỏ
                                                                                    </button>
                                                                                    <button type="submit" class="accept-btn-confirm">
                                                                                        <i class="fas fa-check"></i>
                                                                                        Xác nhận chọn
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
                        var showBtn = document.getElementById('show-accept-' + postId);
                        if (showBtn) showBtn.classList.add('d-none');
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
                        var badge = document.getElementById('post-status-badge-' + postId);
                        if (badge) { badge.textContent = 'Đã nhận việc này'; badge.className = 'badge bg-success'; }
                        else {
                            var triggerRow = trigger ? (trigger.closest('tr') || null) : null;
                            if (triggerRow) {
                                var statusCell = triggerRow.querySelector('td:nth-child(4)');
                                if (statusCell) statusCell.innerHTML = '<span class="badge bg-success">Đã nhận việc này</span>';
                            }
                        }
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

// No additional script needed for favorites card because it now navigates directly to favorites.php
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

<?php include_once 'chatbot_widget.php'; ?>
