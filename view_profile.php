<?php
require_once 'config.php';

$success = '';
$error = '';

// Handle admin verify/unverify actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin_user()) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId > 0 && ($action === 'toggle_verify_user')) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET verified = 1 - verified WHERE id = ?');
            $stmt->execute([$targetId]);
            $success = 'Đã cập nhật trạng thái xác minh.';
        } catch (Throwable $e) {
            error_log('verify toggle error: ' . $e->getMessage());
            $error = 'Không thể cập nhật trạng thái. Vui lòng thử lại.';
        }
    }
}

require_once 'header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div class='alert alert-danger'>ID người dùng không hợp lệ.</div>";
    require_once 'footer.php';
    exit;
}

$profile_user_id = (int)$_GET['id'];
$stmt_user = $pdo->prepare("SELECT id, name, email, role, bio, location, phone, created_at, avatar, verified, last_activity, is_admin FROM users WHERE id = ?");
$stmt_user->execute([$profile_user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "<div class='alert alert-danger'>Không tìm thấy người dùng.</div>";
    require_once 'footer.php';
    exit;
}

$stmtR = $pdo->prepare('SELECT AVG(rating) AS avg_score, COUNT(*) AS cnt FROM ratings WHERE rated_user_id = ?');
$stmtR->execute([$profile_user_id]);
$rR = $stmtR->fetch();
$avg = $rR['avg_score'] ? round($rR['avg_score'], 1) : null;
$totalCount = (int)($rR['cnt'] ?? 0);

function get_role_name($role, $is_admin = false) {
    if ($is_admin) return 'Quản trị viên';
    switch ($role) {
        case 'student': return 'Sinh viên Y khoa';
        case 'patient': return 'Bệnh nhân';
        default: return 'Người dùng';
    }
}

$canRate = false;
if (is_logged_in() && $_SESSION['user_id'] != $profile_user_id) {
    try {
        $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'assigned_to'");
        $colChk->execute();
        $hasAssigned = (int)$colChk->fetchColumn() > 0;
        if ($hasAssigned) {
            $perm = $pdo->prepare('SELECT id FROM posts WHERE user_id = ? AND assigned_to = ? AND status = ? LIMIT 1');
            $perm->execute([$profile_user_id, $_SESSION['user_id'], 'taken']);
            $canRate = (bool)$perm->fetchColumn();
        }
    } catch (Throwable $e) {
        $canRate = false;
    }
}

$existingScore = null;
$existingComment = '';
if (is_logged_in() && $_SESSION['user_id'] != $profile_user_id) {
    $existingStmt = $pdo->prepare('SELECT rating AS score, comment FROM ratings WHERE user_id = ? AND rated_user_id = ? LIMIT 1');
    $existingStmt->execute([$_SESSION['user_id'], $profile_user_id]);
    if ($row = $existingStmt->fetch()) {
        $existingScore = (int)$row['score'];
        $existingComment = (string)$row['comment'];
    }
}

try {
    $recentStmt = $pdo->prepare('SELECT r.id, r.rating AS score, r.comment, r.created_at, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role, u.verified AS rater_verified FROM ratings r JOIN users u ON u.id = r.user_id WHERE r.rated_user_id = ? ORDER BY r.created_at DESC LIMIT 5');
    $recentStmt->execute([$profile_user_id]);
    $recentReviews = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentReviews = [];
}

$positiveStmt = $pdo->prepare('SELECT COUNT(*) FROM ratings WHERE rated_user_id = ? AND rating >= 4');
$positiveStmt->execute([$profile_user_id]);
$positiveCount = (int)$positiveStmt->fetchColumn();
$satisfactionPercent = $totalCount > 0 ? round(($positiveCount / $totalCount) * 100) : null;

// Count posts by user
$postCountStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
$postCountStmt->execute([$profile_user_id]);
$postCount = (int)$postCountStmt->fetchColumn();

// Check friendship status
$friendshipStatus = null;
$friendshipSender = null;
if (is_logged_in() && $_SESSION['user_id'] != $profile_user_id) {
    try {
        $fsStmt = $pdo->prepare('SELECT status, user_id FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
        $fsStmt->execute([$_SESSION['user_id'], $profile_user_id, $profile_user_id, $_SESSION['user_id']]);
        if ($fsRow = $fsStmt->fetch()) {
            $friendshipStatus = $fsRow['status'];
            $friendshipSender = $fsRow['user_id'];
        }
    } catch (Throwable $e) {
        // Table might not exist yet
    }
}
?>

<style>
.profile-page { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); min-height: 100vh; margin: -1.5rem -0.75rem; padding: 2rem; }
.profile-container { max-width: 900px; margin: 0 auto; }
.profile-back { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.25rem; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.3); border-radius: 10px; color: #fff; text-decoration: none; font-weight: 600; transition: all 0.3s ease; margin-bottom: 1.5rem; }
.profile-back:hover { background: #fff; color: #3b82f6; }
.profile-card { background: #fff; border-radius: 24px; box-shadow: 0 25px 80px rgba(0,0,0,0.15); overflow: hidden; }
.profile-header { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); padding: 2.5rem; text-align: center; position: relative; }
.profile-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 120px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
.profile-avatar-wrap { position: relative; z-index: 1; margin-bottom: 1rem; }
.profile-avatar { width: 140px; height: 140px; border-radius: 50%; border: 5px solid #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.2); object-fit: cover; background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.profile-avatar-placeholder { width: 140px; height: 140px; border-radius: 50%; border: 5px solid #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.2); background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 3.5rem; font-weight: 700; margin: 0 auto; }
.profile-name { font-size: 1.75rem; font-weight: 800; color: #1e293b; margin: 0.5rem 0 0.25rem; display: flex; align-items: center; justify-content: center; gap: 0.75rem; flex-wrap: wrap; }
.profile-verified { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.85rem; background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.profile-role { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4f46e5; border-radius: 25px; font-weight: 600; font-size: 0.95rem; margin-top: 0.75rem; }
.profile-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; padding: 1.5rem 2rem; background: linear-gradient(135deg, #fafbff, #f5f7ff); border-bottom: 1px solid #e2e8f0; }
.profile-stat { text-align: center; padding: 1rem; background: #fff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: all 0.3s ease; }
.profile-stat:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
.profile-stat-value { font-size: 1.75rem; font-weight: 800; color: #1e293b; }
.profile-stat-label { font-size: 0.85rem; color: #64748b; font-weight: 500; }
.profile-stat.rating .profile-stat-value { color: #f59e0b; }
.profile-body { padding: 2rem; }
.profile-section { margin-bottom: 1.5rem; }
.profile-section:last-child { margin-bottom: 0; }
.profile-section-title { font-size: 0.85rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
.profile-info-grid { display: grid; gap: 1rem; }
.profile-info-item { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 14px; transition: all 0.3s ease; }
.profile-info-item:hover { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); }
.profile-info-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.profile-info-content { flex: 1; }
.profile-info-label { font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem; }
.profile-info-value { font-size: 1rem; color: #1e293b; font-weight: 500; }
.profile-bio { background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 16px; padding: 1.25rem; border-left: 4px solid #f59e0b; }
.profile-bio p { margin: 0; color: #78350f; line-height: 1.7; }
.profile-actions { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
.profile-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; text-decoration: none; transition: all 0.3s ease; border: none; cursor: pointer; font-size: 0.95rem; }
.profile-btn.primary { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
.profile-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4); }
.profile-btn.success { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
.profile-btn.success:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); }
.profile-btn.danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
.profile-btn.danger:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4); }
.profile-btn.outline { background: #fff; color: #3b82f6; border: 2px solid #3b82f6; }
.profile-btn.outline:hover { background: #3b82f6; color: #fff; }
.profile-btn.call { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
.profile-btn.call:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); }
.phone-link { color: #3b82f6; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; transition: all 0.3s ease; }
.phone-link:hover { color: #1d4ed8; }
.profile-reviews { margin-top: 1.5rem; }
.profile-review-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; margin-bottom: 1rem; transition: all 0.3s ease; }
.profile-review-item:hover { box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
.profile-review-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.profile-review-avatar { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; }
.profile-review-info { flex: 1; }
.profile-review-name { font-weight: 600; color: #1e293b; }
.profile-review-date { font-size: 0.8rem; color: #94a3b8; }
.profile-review-stars { color: #f59e0b; }
.profile-review-content { color: #475569; line-height: 1.6; font-size: 0.95rem; }
.profile-alert { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.5rem; border-radius: 14px; margin-bottom: 1.5rem; }
.profile-alert.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; }
.profile-alert.error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; }
.profile-alert.info { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; }
@media (max-width: 767px) {
    .profile-page { padding: 1rem; }
    .profile-stats { grid-template-columns: 1fr; }
    .profile-header { padding: 2rem 1.5rem; }
    .profile-body { padding: 1.5rem; }
    .profile-name { font-size: 1.5rem; }
}
</style>

<div class="profile-page">
    <div class="profile-container">
        <a href="javascript:history.back()" class="profile-back"><i class="bi bi-arrow-left"></i> Quay lại</a>

        <?php if ($success): ?>
            <div class="profile-alert success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="profile-alert error"><i class="bi bi-exclamation-circle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <!-- Header -->
            <div class="profile-header">
                <div class="profile-avatar-wrap avatar-wrapper">
                    <?php if (!empty($user['avatar']) && upload_exists($user['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($user['avatar'])); ?>" class="profile-avatar" alt="<?php echo htmlspecialchars($user['name']); ?>">
                    <?php else: ?>
                        <div class="profile-avatar-placeholder"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <span class="online-status-dot lg <?php echo is_user_online($user['last_activity'] ?? null) ? 'online' : 'offline'; ?>" title="<?php echo is_user_online($user['last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>"></span>
                </div>
                <h1 class="profile-name">
                    <?php echo htmlspecialchars($user['name']); ?>
                    <?php if (!empty($user['verified'])): ?>
                        <span class="profile-verified"><i class="bi bi-patch-check-fill"></i> Đã xác minh</span>
                    <?php endif; ?>
                </h1>
                <div class="profile-role">
                    <i class="bi bi-<?php echo !empty($user['is_admin']) ? 'shield-fill' : ($user['role'] === 'student' ? 'mortarboard-fill' : 'heart-pulse-fill'); ?>"></i>
                    <?php echo htmlspecialchars(get_role_name($user['role'] ?? '', !empty($user['is_admin']))); ?>
                </div>
                <!-- Online Status Badge -->
                <div class="online-status-badge <?php echo is_user_online($user['last_activity'] ?? null) ? 'online' : 'offline'; ?>" style="margin-top: 0.75rem;">
                    <i class="bi bi-circle-fill"></i>
                    <?php echo is_user_online($user['last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="profile-stats">
                <div class="profile-stat rating">
                    <div class="profile-stat-value">
                        <?php if ($avg !== null): ?>
                            <i class="bi bi-star-fill"></i> <?php echo $avg; ?>
                        <?php else: ?>
                            <i class="bi bi-star"></i> --
                        <?php endif; ?>
                    </div>
                    <div class="profile-stat-label">Đánh giá</div>
                </div>
                <div class="profile-stat">
                    <div class="profile-stat-value"><?php echo $totalCount; ?></div>
                    <div class="profile-stat-label">Lượt đánh giá</div>
                </div>
                <div class="profile-stat">
                    <div class="profile-stat-value"><?php echo $postCount; ?></div>
                    <div class="profile-stat-label">Bài đăng</div>
                </div>
            </div>

            <!-- Body -->
            <div class="profile-body">
                <!-- Contact Info -->
                <div class="profile-section">
                    <div class="profile-section-title"><i class="bi bi-person-lines-fill"></i> Thông tin liên hệ</div>
                    <div class="profile-info-grid">
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-envelope-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Email</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($user['phone'])): ?>
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-telephone-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Điện thoại</div>
                                <div class="profile-info-value">
                                    <a href="tel:<?php echo htmlspecialchars($user['phone']); ?>" class="phone-link">
                                        <?php echo htmlspecialchars($user['phone']); ?>
                                        <i class="bi bi-telephone-outbound-fill ms-2"></i>
                                    </a>
                                </div>
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
                        <div class="profile-info-item">
                            <div class="profile-info-icon"><i class="bi bi-calendar-event-fill"></i></div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Ngày tham gia</div>
                                <div class="profile-info-value"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($user['bio'])): ?>
                <div class="profile-section">
                    <div class="profile-section-title"><i class="bi bi-info-circle-fill"></i> Giới thiệu</div>
                    <div class="profile-bio">
                        <p><?php echo nl2br(htmlspecialchars($user['bio'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($satisfactionPercent !== null): ?>
                <div class="profile-section">
                    <div class="profile-section-title"><i class="bi bi-graph-up"></i> Mức độ hài lòng</div>
                    <div style="background: #f1f5f9; border-radius: 10px; height: 12px; overflow: hidden;">
                        <div style="background: linear-gradient(90deg, #10b981, #34d399); height: 100%; width: <?php echo $satisfactionPercent; ?>%; transition: width 0.5s ease;"></div>
                    </div>
                    <div style="text-align: center; margin-top: 0.5rem; font-weight: 600; color: #10b981;"><?php echo $satisfactionPercent; ?>% hài lòng</div>
                </div>
                <?php endif; ?>

                <?php if (!empty($recentReviews)): ?>
                <div class="profile-section">
                    <div class="profile-section-title"><i class="bi bi-chat-quote-fill"></i> Đánh giá gần đây</div>
                    <div class="profile-reviews">
                        <?php foreach ($recentReviews as $rv): ?>
                        <div class="profile-review-item">
                            <div class="profile-review-header">
                                <?php if (!empty($rv['rater_avatar']) && upload_exists($rv['rater_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars(public_url_for($rv['rater_avatar'])); ?>" class="profile-review-avatar" style="object-fit:cover;" alt="">
                                <?php else: ?>
                                    <div class="profile-review-avatar"><?php echo strtoupper(substr($rv['rater_name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div class="profile-review-info">
                                    <div class="profile-review-name"><?php echo htmlspecialchars($rv['rater_name']); ?></div>
                                    <div class="profile-review-date"><?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?></div>
                                </div>
                                <div class="profile-review-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?php echo $i <= $rv['score'] ? '-fill' : ''; ?>"></i>
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

                <!-- Actions -->
                <div class="profile-actions" id="profile-actions">
                    <?php if (is_logged_in() && $_SESSION['user_id'] != $user['id']): ?>
                        <!-- Friend Button -->
                        <?php if ($friendshipStatus === 'accepted'): ?>
                            <button class="profile-btn success" id="friend-btn" onclick="friendAction('unfriend')">
                                <i class="bi bi-person-check-fill"></i> Bạn bè
                            </button>
                        <?php elseif ($friendshipStatus === 'pending' && $friendshipSender == $_SESSION['user_id']): ?>
                            <button class="profile-btn outline" id="friend-btn" onclick="friendAction('cancel_request')">
                                <i class="bi bi-clock"></i> Đã gửi lời mời
                            </button>
                        <?php elseif ($friendshipStatus === 'pending' && $friendshipSender == $profile_user_id): ?>
                            <button class="profile-btn success" id="friend-btn" onclick="friendAction('accept_request')">
                                <i class="bi bi-check-lg"></i> Chấp nhận kết bạn
                            </button>
                        <?php elseif ($friendshipStatus === 'blocked'): ?>
                            <button class="profile-btn outline" disabled>
                                <i class="bi bi-slash-circle"></i> Đã chặn
                            </button>
                        <?php else: ?>
                            <button class="profile-btn primary" id="friend-btn" onclick="friendAction('send_request')">
                                <i class="bi bi-person-plus-fill"></i> Kết bạn
                            </button>
                        <?php endif; ?>
                        
                        <a href="chat.php?with=<?php echo $user['id']; ?>" class="profile-btn primary">
                            <i class="bi bi-chat-dots-fill"></i> Nhắn tin
                        </a>
                        <a href="video_call.php?with=<?php echo $user['id']; ?>" class="profile-btn call">
                            <i class="bi bi-camera-video-fill"></i> Gọi video
                        </a>
                        <?php if ($canRate): ?>
                            <button class="profile-btn outline" data-bs-toggle="modal" data-bs-target="#rateModal">
                                <i class="bi bi-star-fill"></i> Đánh giá
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if (is_admin_user()): ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggle_verify_user">
                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                            <button class="profile-btn <?php echo !empty($user['verified']) ? 'danger' : 'success'; ?>">
                                <i class="bi bi-<?php echo !empty($user['verified']) ? 'x-circle' : 'patch-check'; ?>-fill"></i>
                                <?php echo !empty($user['verified']) ? 'Bỏ xác minh' : 'Xác minh'; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<?php if (is_logged_in() && $_SESSION['user_id'] != $user['id'] && $canRate): ?>
<!-- Rating Modal -->
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border: none; padding: 1.5rem;">
                <h5 class="modal-title"><i class="bi bi-star-fill me-2"></i>Đánh giá <?php echo htmlspecialchars($user['name']); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <form method="post" action="rate.php" id="rating-form">
                    <input type="hidden" name="rated_id" value="<?php echo $user['id']; ?>">
                    <input type="hidden" name="score" id="rating-score" value="<?php echo $existingScore ?? ''; ?>">
                    
                    <div class="text-center mb-4">
                        <div class="star-rating-input" style="font-size: 2.5rem; color: #e2e8f0; cursor: pointer;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="bi bi-star<?php echo ($existingScore && $s <= $existingScore) ? '-fill' : ''; ?> star-btn" 
                                   data-value="<?php echo $s; ?>" 
                                   style="transition: all 0.2s; <?php echo ($existingScore && $s <= $existingScore) ? 'color: #f59e0b;' : ''; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="mt-2 text-muted" id="rating-text">Chọn số sao</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tiêu đề (tùy chọn)</label>
                        <input type="text" class="form-control" name="title" placeholder="Ví dụ: Rất hài lòng" style="border-radius: 10px; padding: 0.75rem 1rem;">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Nhận xét của bạn</label>
                        <textarea class="form-control" name="comment" rows="4" placeholder="Chia sẻ trải nghiệm của bạn..." style="border-radius: 10px; padding: 0.75rem 1rem;"><?php echo htmlspecialchars($existingComment); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn w-100" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; padding: 0.85rem; border-radius: 12px; font-weight: 600;">
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
    const texts = ['', 'Rất tệ', 'Tệ', 'Bình thường', 'Tốt', 'Xuất sắc'];
    
    function updateStars(value) {
        stars.forEach((star, index) => {
            if (index < value) {
                star.classList.remove('bi-star');
                star.classList.add('bi-star-fill');
                star.style.color = '#f59e0b';
            } else {
                star.classList.remove('bi-star-fill');
                star.classList.add('bi-star');
                star.style.color = '#e2e8f0';
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
    
    document.querySelector('.star-rating-input').addEventListener('mouseleave', function() {
        updateStars(parseInt(scoreInput.value) || 0);
    });
    
    // Initialize
    if (scoreInput.value) {
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

<?php if (is_logged_in() && $_SESSION['user_id'] != $user['id']): ?>
<script>
function friendAction(action) {
    const btn = document.getElementById('friend-btn');
    if (!btn) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Đang xử lý...';
    
    const formData = new FormData();
    formData.append('action', action);
    formData.append('friend_id', <?php echo $profile_user_id; ?>);
    
    fetch('friend_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update button based on new status
            updateFriendButton(data.status);
        } else {
            alert(data.message || 'Có lỗi xảy ra');
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('Có lỗi xảy ra');
        btn.disabled = false;
    });
}

function updateFriendButton(status) {
    const btn = document.getElementById('friend-btn');
    if (!btn) return;
    
    btn.disabled = false;
    
    switch(status) {
        case 'friends':
            btn.className = 'profile-btn success';
            btn.innerHTML = '<i class="bi bi-person-check-fill"></i> Bạn bè';
            btn.onclick = () => friendAction('unfriend');
            break;
        case 'pending_sent':
            btn.className = 'profile-btn outline';
            btn.innerHTML = '<i class="bi bi-clock"></i> Đã gửi lời mời';
            btn.onclick = () => friendAction('cancel_request');
            break;
        case 'pending_received':
            btn.className = 'profile-btn success';
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Chấp nhận kết bạn';
            btn.onclick = () => friendAction('accept_request');
            break;
        case 'blocked':
            btn.className = 'profile-btn outline';
            btn.innerHTML = '<i class="bi bi-slash-circle"></i> Đã chặn';
            btn.disabled = true;
            break;
        default:
            btn.className = 'profile-btn primary';
            btn.innerHTML = '<i class="bi bi-person-plus-fill"></i> Kết bạn';
            btn.onclick = () => friendAction('send_request');
    }
}
</script>
<?php endif; ?>

<!-- Call Phone Modal -->
<div class="modal fade" id="callModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; padding: 1.25rem;">
                <h5 class="modal-title"><i class="bi bi-telephone-fill me-2"></i>Gọi điện</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" style="padding: 2rem;">
                <div style="font-size: 3rem; color: #10b981; margin-bottom: 1rem;">
                    <i class="bi bi-telephone-fill"></i>
                </div>
                <p style="color: #64748b; margin-bottom: 0.5rem;">Số điện thoại:</p>
                <p id="phoneNumber" style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem;"></p>
                <div class="d-grid gap-2">
                    <a id="callLink" href="#" class="btn btn-success btn-lg" style="border-radius: 12px;">
                        <i class="bi bi-telephone-outbound-fill me-2"></i>Gọi ngay
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="copyPhone()" style="border-radius: 12px;">
                        <i class="bi bi-clipboard me-2"></i>Sao chép số
                    </button>
                </div>
                <p id="copySuccess" style="color: #10b981; margin-top: 1rem; display: none;">
                    <i class="bi bi-check-circle-fill"></i> Đã sao chép!
                </p>
            </div>
        </div>
    </div>
</div>

<script>
let currentPhone = '';

function handleCall(event, phone) {
    // Kiểm tra nếu là thiết bị di động thì cho phép gọi trực tiếp
    if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
        return true; // Cho phép link tel: hoạt động bình thường
    }
    
    // Trên máy tính, hiển thị modal
    event.preventDefault();
    currentPhone = phone;
    document.getElementById('phoneNumber').textContent = phone;
    document.getElementById('callLink').href = 'tel:' + phone;
    new bootstrap.Modal(document.getElementById('callModal')).show();
}

function copyPhone() {
    navigator.clipboard.writeText(currentPhone).then(function() {
        document.getElementById('copySuccess').style.display = 'block';
        setTimeout(function() {
            document.getElementById('copySuccess').style.display = 'none';
        }, 2000);
    });
}
</script>

<?php require_once 'footer.php'; ?>
