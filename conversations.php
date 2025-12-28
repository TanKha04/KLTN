<?php
require_once 'config.php';
require_login();

$current_user_id = $_SESSION['user_id'];

// Self-migration: Ensure tables and columns exist
try {
    // Check if conversations table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'conversations'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $pdo->exec("CREATE TABLE `conversations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user1_id` INT NOT NULL,
            `user2_id` INT NOT NULL,
            `last_message_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_conversation (user1_id, user2_id)
        );");
    } else {
        // Thêm cột last_message_at nếu chưa có
        $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'last_message_at'");
        $checkCol->execute();
        if ((int)$checkCol->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE conversations ADD COLUMN last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }
    
    // Check if direct_messages table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'direct_messages'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $pdo->exec("CREATE TABLE `direct_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `conversation_id` INT NOT NULL,
            `sender_id` INT NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        );");
    } else {
        // Thêm cột conversation_id nếu chưa có
        $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'direct_messages' AND COLUMN_NAME = 'conversation_id'");
        $checkCol->execute();
        if ((int)$checkCol->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE direct_messages ADD COLUMN conversation_id INT NULL AFTER id");
        }
    }
} catch (PDOException $e) {
    error_log("Failed to create/update chat tables: " . $e->getMessage());
}

// Fetch conversations for current user
$stmt = $pdo->prepare("
    SELECT c.*, 
           u.name as other_user_name, 
           u.id as other_user_id,
           u.role as other_user_role,
           u.avatar as other_user_avatar,
           u.verified as other_user_verified,
           u.last_activity as other_user_last_activity,
           dm.message as last_message,
           dm.created_at as last_message_time
    FROM conversations c
    JOIN users u ON (u.id = CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END)
    LEFT JOIN direct_messages dm ON dm.conversation_id = c.id 
        AND dm.id = (SELECT MAX(id) FROM direct_messages WHERE conversation_id = c.id)
    WHERE c.user1_id = ? OR c.user2_id = ?
    ORDER BY c.last_message_at DESC
");
$stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch friends list for quick access
$friends = [];
try {
    $friendsStmt = $pdo->prepare('
        SELECT u.id, u.name, u.avatar, u.role, u.verified, u.last_activity
        FROM friendships f
        JOIN users u ON (u.id = CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END)
        WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = "accepted"
        ORDER BY u.last_activity DESC
        LIMIT 10
    ');
    $friendsStmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    $friends = $friendsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Table might not exist
}

// Get pending friend requests count
$pendingCount = 0;
try {
    $pendingStmt = $pdo->prepare('SELECT COUNT(*) FROM friendships WHERE friend_id = ? AND status = "pending"');
    $pendingStmt->execute([$current_user_id]);
    $pendingCount = (int)$pendingStmt->fetchColumn();
} catch (Throwable $e) {}

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Chat</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}.premium-navbar,.navbar,.site-header{display:none!important;}</style>';
    echo '</head><body>';
}
?>

<style>
/* Conversations Page Premium Styles */
.conversations-wrapper {
    padding: 1rem 0;
    min-height: 70vh;
}

/* Sidebar Premium */
.conversations-sidebar {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 24px;
    padding: 2rem;
    color: #fff;
    position: sticky;
    top: 100px;
    box-shadow: 0 20px 50px rgba(11, 63, 145, 0.35);
    overflow: hidden;
}
.conversations-sidebar::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -30%;
    width: 80%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}
.sidebar-header {
    position: relative;
    z-index: 1;
    margin-bottom: 1.5rem;
}
.sidebar-icon-box {
    width: 70px;
    height: 70px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin-bottom: 1.25rem;
    border: 1px solid rgba(255,255,255,0.2);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.sidebar-header h5 {
    font-size: 1.35rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.sidebar-header p {
    font-size: 0.9rem;
    opacity: 0.85;
    margin: 0;
    line-height: 1.5;
}
.sidebar-nav-premium {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    position: relative;
    z-index: 1;
}
.sidebar-nav-link {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1rem 1.25rem;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    border-radius: 14px;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(255,255,255,0.15);
}
.sidebar-nav-link:hover {
    background: rgba(255,255,255,0.25);
    transform: translateX(8px);
    color: #fff;
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
}
.sidebar-nav-link i {
    width: 22px;
    text-align: center;
    font-size: 1.1rem;
}

/* Friends Quick List */
.friends-quick-list {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(255,255,255,0.2);
    position: relative;
    z-index: 1;
}
.friends-quick-title {
    font-size: 0.85rem;
    font-weight: 600;
    opacity: 0.8;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.friends-quick-items {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.friend-quick-item {
    position: relative;
    display: block;
}
.friend-quick-avatar, .friend-quick-avatar-placeholder {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.3);
    transition: all 0.3s;
}
.friend-quick-avatar-placeholder {
    background: rgba(255,255,255,0.2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
}
.friend-quick-item:hover .friend-quick-avatar,
.friend-quick-item:hover .friend-quick-avatar-placeholder {
    border-color: #fff;
    transform: scale(1.1);
}
.friend-quick-status {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #1e40af;
}
.friend-quick-status.online { background: #22c55e; }
.friend-quick-status.offline { background: #94a3b8; }

/* Main Content Premium */
.conversations-main {
    background: #ffffff;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 15px 50px rgba(0,0,0,0.1);
    border: 1px solid rgba(226, 232, 240, 0.8);
}
.conversations-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 2rem 2.5rem;
    border-bottom: 1px solid #e2e8f0;
    position: relative;
}
.conversations-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 2.5rem;
    right: 2.5rem;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899);
    border-radius: 3px 3px 0 0;
}
.conversations-header h3 {
    font-size: 1.75rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.85rem;
}
.conversations-header h3 .header-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.35);
}
.conversations-header .header-subtitle {
    color: #64748b;
    font-size: 0.95rem;
    margin-top: 0.5rem;
    font-weight: 500;
}

/* Conversations Body */
.conversations-body {
    padding: 2rem 2.5rem;
    background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
}

/* Empty State Premium */
.conversations-empty {
    text-align: center;
    padding: 4rem 2rem;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #dbeafe 100%);
    border-radius: 24px;
    border: 2px dashed #93c5fd;
    position: relative;
    overflow: hidden;
}
.conversations-empty::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 30%, rgba(59, 130, 246, 0.08) 0%, transparent 50%);
    pointer-events: none;
}
.empty-illustration {
    width: 140px;
    height: 140px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 50%, #1e40af 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 2rem;
    font-size: 4rem;
    box-shadow: 0 20px 50px rgba(59, 130, 246, 0.4);
    position: relative;
    z-index: 1;
    animation: floatBounce 3s ease-in-out infinite;
}
@keyframes floatBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.conversations-empty h4 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e40af;
    margin-bottom: 1rem;
    position: relative;
    z-index: 1;
}
.conversations-empty p {
    color: #475569;
    font-size: 1rem;
    line-height: 1.7;
    max-width: 450px;
    margin: 0 auto 2rem;
    position: relative;
    z-index: 1;
}
.empty-actions {
    display: flex;
    justify-content: center;
    gap: 1rem;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.empty-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    padding: 1rem 1.75rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.empty-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #fff;
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
}
.empty-btn-primary:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 35px rgba(59, 130, 246, 0.5);
    color: #fff;
}
.empty-btn-outline {
    background: #fff;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}
.empty-btn-outline:hover {
    background: #3b82f6;
    color: #fff;
    transform: translateY(-4px);
}

/* Conversations List Premium */
.conversations-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.conversation-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 1.5rem;
    background: #fff;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    text-decoration: none;
    color: inherit;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}
.conversation-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}
.conversation-card:hover {
    transform: translateY(-5px) scale(1.01);
    box-shadow: 0 15px 40px rgba(59, 130, 246, 0.15);
    border-color: #93c5fd;
}
.conversation-card:hover::before {
    opacity: 1;
}

/* Animation for list items */
.conversation-card:nth-child(1) { animation: convSlideIn 0.4s ease-out 0.05s backwards; }
.conversation-card:nth-child(2) { animation: convSlideIn 0.4s ease-out 0.1s backwards; }
.conversation-card:nth-child(3) { animation: convSlideIn 0.4s ease-out 0.15s backwards; }
.conversation-card:nth-child(4) { animation: convSlideIn 0.4s ease-out 0.2s backwards; }
.conversation-card:nth-child(5) { animation: convSlideIn 0.4s ease-out 0.25s backwards; }
.conversation-card:nth-child(6) { animation: convSlideIn 0.4s ease-out 0.3s backwards; }
@keyframes convSlideIn {
    from { opacity: 0; transform: translateX(-20px); }
    to { opacity: 1; transform: translateX(0); }
}

/* Avatar Premium */
.conv-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}
.conv-avatar, .conv-avatar-img {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    font-weight: 700;
    color: #fff;
    box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    object-fit: cover;
}
.conv-avatar.patient, .conv-avatar-img.patient {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border: 2px solid rgba(59,130,246,0.3);
}
.conv-avatar.student, .conv-avatar-img.student {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: 2px solid rgba(16,185,129,0.3);
}
.conv-online-dot {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid #fff;
}
.conv-online-dot.online { background: #22c55e; }
.conv-online-dot.offline { background: #94a3b8; }
.friend-badge-small {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #059669;
    padding: 0.15rem 0.4rem;
    border-radius: 6px;
    font-size: 0.7rem;
}

/* Conversation Info */
.conv-info {
    flex: 1;
    min-width: 0;
}
.conv-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.conv-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.75rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
}
.conv-role-badge.patient {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.conv-role-badge.student {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #047857;
}
.conv-last-message {
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-top: 0.5rem;
}

/* Conversation Meta */
.conv-meta {
    text-align: right;
    flex-shrink: 0;
}
.conv-time {
    font-size: 0.85rem;
    color: #94a3b8;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    justify-content: flex-end;
    margin-bottom: 0.5rem;
}
.conv-arrow {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    transition: all 0.3s ease;
}
.conversation-card:hover .conv-arrow {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    transform: translateX(5px);
}

/* Responsive */
@media (max-width: 991px) {
    .conversations-sidebar {
        position: static;
        margin-bottom: 1.5rem;
    }
}
@media (max-width: 768px) {
    .conversations-header, .conversations-body {
        padding: 1.5rem;
    }
    .conversation-card {
        padding: 1.25rem;
        flex-wrap: wrap;
    }
    .conv-avatar {
        width: 55px;
        height: 55px;
        font-size: 1.25rem;
    }
    .conv-meta {
        width: 100%;
        text-align: left;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .conv-arrow {
        display: none;
    }
}
</style>

<div class="conversations-wrapper">
    <div class="row g-4">
        <div class="col-lg-3">
            <div class="conversations-sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-icon-box">💬</div>
                    <h5>Trò chuyện</h5>
                    <p>Kết nối và trao đổi trực tiếp với người dùng khác</p>
                </div>
                <div class="sidebar-nav-premium">
                    <a href="friends.php" class="sidebar-nav-link">
                        <i class="bi bi-people-fill"></i> Bạn bè
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-danger ms-auto"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="view_messages.php?from_admin=1" class="sidebar-nav-link">
                        <i class="bi bi-bell-fill"></i> Tin nhắn hệ thống
                    </a>
                    <a href="index.php" class="sidebar-nav-link">
                        <i class="bi bi-arrow-left"></i> Quay lại trang chủ
                    </a>
                </div>
                
                <?php if (!empty($friends)): ?>
                <div class="friends-quick-list">
                    <div class="friends-quick-title">
                        <i class="bi bi-people"></i> Bạn bè trực tuyến
                    </div>
                    <div class="friends-quick-items">
                        <?php foreach ($friends as $friend): ?>
                        <a href="chat.php?with=<?php echo $friend['id']; ?><?php echo $isEmbed ? '&embed=1' : ''; ?>" class="friend-quick-item" title="<?php echo htmlspecialchars($friend['name']); ?>"><?php if (!empty($friend['avatar']) && upload_exists($friend['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars(public_url_for($friend['avatar'])); ?>" class="friend-quick-avatar" alt="">
                            <?php else: ?>
                                <div class="friend-quick-avatar-placeholder"><?php echo strtoupper(substr($friend['name'], 0, 1)); ?></div>
                            <?php endif; ?>
                            <span class="friend-quick-status <?php echo is_user_online($friend['last_activity'] ?? null) ? 'online' : 'offline'; ?>"></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="conversations-main">
                <div class="conversations-header">
                    <h3>
                        <span class="header-icon">💬</span>
                        Cuộc trò chuyện của tôi
                    </h3>
                    <p class="header-subtitle">
                        <?php if (!empty($conversations)): ?>
                            Bạn có <?php echo count($conversations); ?> cuộc trò chuyện
                        <?php else: ?>
                            Bắt đầu kết nối với người dùng khác
                        <?php endif; ?>
                    </p>
                </div>

                <div class="conversations-body">
                    <?php if (empty($conversations)): ?>
                        <div class="conversations-empty">
                            <div class="empty-illustration">💭</div>
                            <h4>Chưa có cuộc trò chuyện nào</h4>
                            <p>Bạn có thể bắt đầu trò chuyện bằng cách nhấp vào "Liên hệ người đăng" trên các bài đăng hoặc tìm kiếm người dùng khác để nhắn tin.</p>
                            <div class="empty-actions">
                                <a href="index.php?type=recruitment#posts" class="empty-btn empty-btn-primary">
                                    <i class="fas fa-search"></i> Tìm tin tuyển dụng
                                </a>
                                <a href="index.php?type=application#posts" class="empty-btn empty-btn-outline">
                                    <i class="fas fa-users"></i> Xem tin ứng tuyển
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="conversations-list">
                            <?php 
                            // Get friend IDs for checking
                            $friendIds = array_column($friends, 'id');
                            foreach ($conversations as $conv): 
                                $isPatient = $conv['other_user_role'] === 'patient';
                                $roleClass = $isPatient ? 'patient' : 'student';
                                $roleLabel = $isPatient ? 'Bệnh nhân' : 'Sinh viên';
                                $roleIcon = $isPatient ? 'bi-heart-pulse' : 'bi-mortarboard';
                                $isFriend = in_array($conv['other_user_id'], $friendIds);
                            ?>
                                <a href="chat.php?user_id=<?php echo $conv['other_user_id']; ?><?php echo $isEmbed ? '&embed=1' : ''; ?>" class="conversation-card">
                                    <div class="conv-avatar-wrap">
                                        <?php if (!empty($conv['other_user_avatar']) && upload_exists($conv['other_user_avatar'])): ?>
                                            <img src="<?php echo htmlspecialchars(public_url_for($conv['other_user_avatar'])); ?>" class="conv-avatar-img <?php echo $roleClass; ?>" alt="">
                                        <?php else: ?>
                                            <div class="conv-avatar <?php echo $roleClass; ?>">
                                                <?php echo strtoupper(mb_substr($conv['other_user_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <span class="conv-online-dot <?php echo is_user_online($conv['other_user_last_activity'] ?? null) ? 'online' : 'offline'; ?>"></span>
                                    </div>
                                    <div class="conv-info">
                                        <div class="conv-name">
                                            <?php echo htmlspecialchars($conv['other_user_name']); ?>
                                            <?php if (!empty($conv['other_user_verified'])): ?>
                                                <i class="bi bi-patch-check-fill text-primary" style="font-size: 0.9rem;"></i>
                                            <?php endif; ?>
                                            <?php if ($isFriend): ?>
                                                <span class="friend-badge-small"><i class="bi bi-people-fill"></i></span>
                                            <?php endif; ?>
                                            <span class="conv-role-badge <?php echo $roleClass; ?>">
                                                <i class="bi <?php echo $roleIcon; ?>-fill"></i>
                                                <?php echo $roleLabel; ?>
                                            </span>
                                        </div>
                                        <?php if ($conv['last_message']): ?>
                                            <p class="conv-last-message">
                                                <?php echo htmlspecialchars(mb_substr($conv['last_message'], 0, 80)) . (mb_strlen($conv['last_message']) > 80 ? '...' : ''); ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="conv-last-message" style="font-style: italic; color: #94a3b8;">
                                                Chưa có tin nhắn
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="conv-meta">
                                        <div class="conv-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo $conv['last_message_time'] ? date('d/m H:i', strtotime($conv['last_message_time'])) : date('d/m H:i', strtotime($conv['created_at'])); ?>
                                        </div>
                                        <div class="conv-arrow">
                                            <i class="fas fa-chevron-right"></i>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>