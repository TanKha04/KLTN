<?php
require_once 'config.php';
require_admin();

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

// Thống kê người dùng
$userStats = [];
try {
    $userStats['total'] = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $userStats['students'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $userStats['patients'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'patient'")->fetchColumn();
    $userStats['verified'] = $pdo->query('SELECT COUNT(*) FROM users WHERE verified = 1')->fetchColumn();
    $userStats['new_today'] = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $userStats['new_week'] = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    $userStats['new_month'] = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
} catch (Throwable $e) {}

// Thống kê bài đăng
$postStats = [];
try {
    $postStats['total'] = $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    $postStats['recruitment'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE type = 'recruitment'")->fetchColumn();
    $postStats['application'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE type = 'application'")->fetchColumn();
    $postStats['open'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'open'")->fetchColumn();
    $postStats['taken'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'taken'")->fetchColumn();
    $postStats['closed'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'closed'")->fetchColumn();
    $postStats['new_today'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $postStats['new_week'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Throwable $e) {}

// Thống kê tin nhắn
$messageStats = [];
try {
    $messageStats['total'] = $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $messageStats['today'] = $pdo->query("SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $messageStats['week'] = $pdo->query("SELECT COUNT(*) FROM messages WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Throwable $e) {}

// Thống kê đánh giá
$ratingStats = [];
try {
    $ratingStats['total'] = $pdo->query('SELECT COUNT(*) FROM ratings')->fetchColumn();
    $ratingStats['avg'] = $pdo->query('SELECT ROUND(AVG(rating), 1) FROM ratings')->fetchColumn();
    $ratingStats['five_star'] = $pdo->query('SELECT COUNT(*) FROM ratings WHERE rating = 5')->fetchColumn();
} catch (Throwable $e) {}

// Thống kê yêu thích
$favoriteStats = [];
try {
    $favoriteStats['total'] = $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
} catch (Throwable $e) {}

// Thống kê bình luận
$commentStats = [];
try {
    $commentStats['total'] = $pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn();
    $commentStats['today'] = $pdo->query("SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Throwable $e) {}

// Thống kê theo tháng (6 tháng gần nhất)
$monthlyStats = [];
try {
    $monthlyStats = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM users 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$monthlyPosts = [];
try {
    $monthlyPosts = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM posts 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once 'header.php';
?>

<style>
.stats-page { background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%); min-height: 100vh; margin: -1.5rem -0.75rem; padding: 2rem; }
.stats-container { max-width: 1400px; margin: 0 auto; }
.stats-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2rem; }
.stats-header-left { display: flex; align-items: center; gap: 1rem; }
.stats-icon { width: 70px; height: 70px; background: linear-gradient(135deg, #e0ecff 0%, #c7d2fe 100%); border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #0b3f91; box-shadow: 0 10px 30px rgba(11, 63, 145, 0.25); }
.stats-title { color: #fff; }
.stats-title h1 { font-size: 2rem; font-weight: 700; margin: 0 0 0.25rem 0; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
.stats-title p { margin: 0; opacity: 0.9; font-size: 1rem; }
.stats-back { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: rgba(255,255,255,0.18); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.35); border-radius: 14px; color: #fff; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.stats-back:hover { background: #fff; color: #0b3f91; }

.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { background: #fff; border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 40px rgba(0,0,0,0.1); transition: all 0.3s ease; position: relative; overflow: hidden; cursor: pointer; text-decoration: none; display: block; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
.stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 50px rgba(0,0,0,0.15); }
.stat-card.blue::before { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }
.stat-card.green::before { background: linear-gradient(180deg, #10b981, #059669); }
.stat-card.purple::before { background: linear-gradient(180deg, #8b5cf6, #7c3aed); }
.stat-card.orange::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
.stat-card.pink::before { background: linear-gradient(180deg, #ec4899, #db2777); }
.stat-card.cyan::before { background: linear-gradient(180deg, #06b6d4, #0891b2); }

.stat-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.stat-card-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
.stat-card.blue .stat-card-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.stat-card.green .stat-card-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.stat-card.purple .stat-card-icon { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
.stat-card.orange .stat-card-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
.stat-card.pink .stat-card-icon { background: linear-gradient(135deg, #fce7f3, #fbcfe8); color: #db2777; }
.stat-card.cyan .stat-card-icon { background: linear-gradient(135deg, #cffafe, #a5f3fc); color: #0891b2; }

.stat-card-value { font-size: 2.5rem; font-weight: 800; color: #1e293b; line-height: 1; }
.stat-card-label { font-size: 0.95rem; color: #64748b; font-weight: 500; margin-top: 0.25rem; }
.stat-card-change { font-size: 0.8rem; padding: 0.25rem 0.5rem; border-radius: 6px; font-weight: 600; }
.stat-card-change.up { background: #d1fae5; color: #059669; }
.stat-card-change.down { background: #fee2e2; color: #dc2626; }

.stats-section { background: #fff; border-radius: 24px; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
.stats-section-title { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
.stats-section-title i { color: #3b82f6; }

.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
.detail-item { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 14px; padding: 1.25rem; text-align: center; transition: all 0.3s; cursor: pointer; text-decoration: none; display: block; }
.detail-item:hover { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); transform: translateY(-3px); }
.detail-value { font-size: 1.75rem; font-weight: 800; color: #1e293b; }
.detail-label { font-size: 0.85rem; color: #64748b; margin-top: 0.25rem; }

.chart-container { height: 300px; position: relative; }
.chart-bars { display: flex; align-items: flex-end; justify-content: space-around; height: 250px; padding: 0 1rem; border-bottom: 2px solid #e2e8f0; }
.chart-bar-wrapper { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
.chart-bar { width: 40px; background: linear-gradient(180deg, #3b82f6, #1d4ed8); border-radius: 8px 8px 0 0; transition: all 0.3s; min-height: 10px; }
.chart-bar:hover { background: linear-gradient(180deg, #60a5fa, #3b82f6); }
.chart-bar.secondary { background: linear-gradient(180deg, #10b981, #059669); }
.chart-bar.secondary:hover { background: linear-gradient(180deg, #34d399, #10b981); }
.chart-label { font-size: 0.75rem; color: #64748b; font-weight: 500; }
.chart-value { font-size: 0.8rem; color: #1e293b; font-weight: 700; }

.stats-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; }

@media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 767px) { 
    .stats-page { padding: 1rem; } 
    .stats-header { flex-direction: column; align-items: flex-start; } 
    .stats-grid { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: 1fr; }
}
</style>

<div class="stats-page">
    <div class="stats-container">
        <div class="stats-header">
            <div class="stats-header-left">
                <div class="stats-icon"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="stats-title">
                    <h1>Thống kê hệ thống</h1>
                    <p>Tổng quan hoạt động của hệ thống Kết nối Y tế</p>
                </div>
            </div>
            <?php if ($isEmbed): ?>
            <a href="#" class="stats-back" onclick="window.parent.showSection('welcome', 'Bảng điều khiển'); return false;"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <?php else: ?>
            <a href="admin.php" class="stats-back"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <?php endif; ?>
        </div>

        <!-- Main Stats Cards -->
        <div class="stats-grid">
            <a href="admin_users.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="stat-card blue">
                <div class="stat-card-header">
                    <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
                    <span class="stat-card-change up">+<?php echo (int)($userStats['new_week'] ?? 0); ?> tuần này</span>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)($userStats['total'] ?? 0)); ?></div>
                <div class="stat-card-label">Tổng người dùng</div>
            </a>
            <a href="admin_posts.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="stat-card green">
                <div class="stat-card-header">
                    <div class="stat-card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                    <span class="stat-card-change up">+<?php echo (int)($postStats['new_week'] ?? 0); ?> tuần này</span>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)($postStats['total'] ?? 0)); ?></div>
                <div class="stat-card-label">Tổng bài đăng</div>
            </a>
            <a href="conversations.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="stat-card purple">
                <div class="stat-card-header">
                    <div class="stat-card-icon"><i class="bi bi-chat-dots-fill"></i></div>
                    <span class="stat-card-change up">+<?php echo (int)($messageStats['week'] ?? 0); ?> tuần này</span>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)($messageStats['total'] ?? 0)); ?></div>
                <div class="stat-card-label">Tin nhắn</div>
            </a>
            <div class="stat-card orange">
                <div class="stat-card-header">
                    <div class="stat-card-icon"><i class="bi bi-star-fill"></i></div>
                </div>
                <div class="stat-card-value"><?php echo $ratingStats['avg'] ?? '0'; ?> <small style="font-size: 1rem; color: #f59e0b;">★</small></div>
                <div class="stat-card-label">Đánh giá trung bình</div>
            </div>
        </div>

        <div class="stats-row">
            <!-- User Stats Detail -->
            <div class="stats-section">
                <div class="stats-section-title"><i class="bi bi-people-fill"></i> Chi tiết người dùng</div>
                <div class="detail-grid">
                    <a href="admin_users.php?role=student<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #3b82f6;"><?php echo (int)($userStats['students'] ?? 0); ?></div>
                        <div class="detail-label">Sinh viên Y khoa</div>
                    </a>
                    <a href="admin_users.php?role=patient<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #10b981;"><?php echo (int)($userStats['patients'] ?? 0); ?></div>
                        <div class="detail-label">Bệnh nhân</div>
                    </a>
                    <a href="admin_verifications.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #8b5cf6;"><?php echo (int)($userStats['verified'] ?? 0); ?></div>
                        <div class="detail-label">Đã xác minh</div>
                    </a>
                    <a href="admin_users.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #f59e0b;"><?php echo (int)($userStats['new_today'] ?? 0); ?></div>
                        <div class="detail-label">Mới hôm nay</div>
                    </a>
                    <a href="admin_users.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #ec4899;"><?php echo (int)($userStats['new_month'] ?? 0); ?></div>
                        <div class="detail-label">Mới tháng này</div>
                    </a>
                </div>
            </div>

            <!-- Post Stats Detail -->
            <div class="stats-section">
                <div class="stats-section-title"><i class="bi bi-file-earmark-text-fill"></i> Chi tiết bài đăng</div>
                <div class="detail-grid">
                    <a href="admin_posts.php?type=recruitment<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #3b82f6;"><?php echo (int)($postStats['recruitment'] ?? 0); ?></div>
                        <div class="detail-label">Tin tuyển dụng</div>
                    </a>
                    <a href="admin_posts.php?type=application<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #10b981;"><?php echo (int)($postStats['application'] ?? 0); ?></div>
                        <div class="detail-label">Tin ứng tuyển</div>
                    </a>
                    <a href="admin_posts.php?status=open<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #22c55e;"><?php echo (int)($postStats['open'] ?? 0); ?></div>
                        <div class="detail-label">Đang mở</div>
                    </a>
                    <a href="admin_posts.php?status=taken<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #f59e0b;"><?php echo (int)($postStats['taken'] ?? 0); ?></div>
                        <div class="detail-label">Đã nhận</div>
                    </a>
                    <a href="admin_posts.php?status=closed<?php echo $isEmbed ? '&embed=1' : ''; ?>" class="detail-item">
                        <div class="detail-value" style="color: #64748b;"><?php echo (int)($postStats['closed'] ?? 0); ?></div>
                        <div class="detail-label">Đã đóng</div>
                    </a>
                </div>
            </div>
        </div>

        <!-- Activity Stats -->
        <div class="stats-section">
            <div class="stats-section-title"><i class="bi bi-activity"></i> Hoạt động khác</div>
            <div class="detail-grid">
                <a href="admin_favorites.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="detail-item">
                    <div class="detail-value" style="color: #ec4899;"><?php echo (int)($favoriteStats['total'] ?? 0); ?></div>
                    <div class="detail-label">Lượt yêu thích</div>
                </a>
                <div class="detail-item">
                    <div class="detail-value" style="color: #f59e0b;"><?php echo (int)($ratingStats['total'] ?? 0); ?></div>
                    <div class="detail-label">Lượt đánh giá</div>
                </div>
                <div class="detail-item">
                    <div class="detail-value" style="color: #10b981;"><?php echo (int)($ratingStats['five_star'] ?? 0); ?></div>
                    <div class="detail-label">Đánh giá 5 sao</div>
                </div>
                <div class="detail-item">
                    <div class="detail-value" style="color: #8b5cf6;"><?php echo (int)($commentStats['total'] ?? 0); ?></div>
                    <div class="detail-label">Bình luận</div>
                </div>
                <a href="conversations.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="detail-item">
                    <div class="detail-value" style="color: #06b6d4;"><?php echo (int)($messageStats['today'] ?? 0); ?></div>
                    <div class="detail-label">Tin nhắn hôm nay</div>
                </a>
                <div class="detail-item">
                    <div class="detail-value" style="color: #3b82f6;"><?php echo (int)($commentStats['today'] ?? 0); ?></div>
                    <div class="detail-label">Bình luận hôm nay</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="stats-row">
            <div class="stats-section">
                <div class="stats-section-title"><i class="bi bi-graph-up"></i> Người dùng mới (6 tháng)</div>
                <div class="chart-container">
                    <div class="chart-bars">
                        <?php 
                        $maxVal = max(array_column($monthlyStats, 'count') ?: [1]);
                        foreach ($monthlyStats as $m): 
                            $height = ($m['count'] / $maxVal) * 200;
                        ?>
                        <div class="chart-bar-wrapper">
                            <div class="chart-value"><?php echo $m['count']; ?></div>
                            <div class="chart-bar" style="height: <?php echo max($height, 10); ?>px;"></div>
                            <div class="chart-label"><?php echo date('m/Y', strtotime($m['month'] . '-01')); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($monthlyStats)): ?>
                        <div style="text-align: center; color: #94a3b8; padding: 2rem;">Chưa có dữ liệu</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="stats-section">
                <div class="stats-section-title"><i class="bi bi-graph-up"></i> Bài đăng mới (6 tháng)</div>
                <div class="chart-container">
                    <div class="chart-bars">
                        <?php 
                        $maxVal = max(array_column($monthlyPosts, 'count') ?: [1]);
                        foreach ($monthlyPosts as $m): 
                            $height = ($m['count'] / $maxVal) * 200;
                        ?>
                        <div class="chart-bar-wrapper">
                            <div class="chart-value"><?php echo $m['count']; ?></div>
                            <div class="chart-bar secondary" style="height: <?php echo max($height, 10); ?>px;"></div>
                            <div class="chart-label"><?php echo date('m/Y', strtotime($m['month'] . '-01')); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($monthlyPosts)): ?>
                        <div style="text-align: center; color: #94a3b8; padding: 2rem;">Chưa có dữ liệu</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
