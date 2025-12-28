<?php
require_once 'config.php';
require_login();

$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
// Support both 'user_id' and 'with' parameters
$other_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_GET['with']) ? (int)$_GET['with'] : 0);

if (($_SESSION['role'] ?? '') === 'student' && !is_student_verified()) {
    header('Location: request_verification.php');
    exit;
}

if ($current_user_id <= 0 || $other_user_id <= 0 || $other_user_id === $current_user_id) {
    header('Location: conversations.php');
    exit;
}

// Check if users are friends
$areFriends = false;
$friendshipStatus = null;
try {
    $fsStmt = $pdo->prepare('SELECT status FROM friendships WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))');
    $fsStmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
    $fsRow = $fsStmt->fetch();
    if ($fsRow) {
        $friendshipStatus = $fsRow['status'];
        $areFriends = ($fsRow['status'] === 'accepted');
    }
} catch (Throwable $e) {
    // Table might not exist
}

// Ensure chat tables exist (self-migration safeguard)
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'conversations'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE `conversations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user1_id` INT NOT NULL,
            `user2_id` INT NOT NULL,
            `last_message_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_conversation (user1_id, user2_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'direct_messages'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE `direct_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `conversation_id` INT NOT NULL,
            `sender_id` INT NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
} catch (PDOException $e) {
    error_log('Chat self-migration failed: ' . $e->getMessage());
}

// Fetch recipient info
$stmt = $pdo->prepare('SELECT name, role, last_activity FROM users WHERE id = ?');
$stmt->execute([$other_user_id]);
$other_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$other_user) {
    header('Location: conversations.php');
    exit;
}

// Handle sending a message
$send_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);

    if ($message !== '') {
        $user1 = min($current_user_id, $other_user_id);
        $user2 = max($current_user_id, $other_user_id);

        try {
            // Kiểm tra conversation đã tồn tại chưa
            $stmt = $pdo->prepare('SELECT id FROM conversations WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)');
            $stmt->execute([$user1, $user2, $user2, $user1]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conversation) {
                $conversation_id = (int)$conversation['id'];
            } else {
                // Tạo conversation mới
                $stmt = $pdo->prepare('INSERT INTO conversations (user1_id, user2_id, last_message_at, created_at) VALUES (?, ?, NOW(), NOW())');
                $stmt->execute([$user1, $user2]);
                $conversation_id = (int)$pdo->lastInsertId();
            }

            // Gửi tin nhắn
            $stmt = $pdo->prepare('INSERT INTO direct_messages (conversation_id, sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$conversation_id, $current_user_id, $other_user_id, $message]);

            // Cập nhật thời gian tin nhắn cuối
            $stmt = $pdo->prepare('UPDATE conversations SET last_message_at = NOW() WHERE id = ?');
            $stmt->execute([$conversation_id]);

            // Redirect về trang chat với user_id
            $embedParam = isset($_GET['embed']) && $_GET['embed'] == '1' ? '&embed=1' : '';
            header('Location: chat.php?user_id=' . $other_user_id . $embedParam);
            exit;
        } catch (PDOException $e) {
            error_log('Chat send failed: ' . $e->getMessage());
            $send_error = 'Lỗi: ' . $e->getMessage();
        } catch (Exception $e) {
            error_log('Chat send failed: ' . $e->getMessage());
            $send_error = 'Lỗi: ' . $e->getMessage();
        }
    }
}

// Load conversation history
$user1 = min($current_user_id, $other_user_id);
$user2 = max($current_user_id, $other_user_id);
$messages = [];

try {
    $stmt = $pdo->prepare('
        SELECT dm.*, u.name AS sender_name
        FROM conversations c
        LEFT JOIN direct_messages dm ON dm.conversation_id = c.id
        LEFT JOIN users u ON dm.sender_id = u.id
        WHERE (c.user1_id = ? AND c.user2_id = ?) OR (c.user1_id = ? AND c.user2_id = ?)
        ORDER BY dm.created_at ASC
    ');
    $stmt->execute([$user1, $user2, $user2, $user1]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (!empty($row['id'])) {
            $messages[] = $row;
        }
    }
} catch (PDOException $e) {
    error_log('Chat history load failed: ' . $e->getMessage());
}

// Get avatar
$avatarStmt = $pdo->prepare('SELECT avatar, verified FROM users WHERE id = ?');
$avatarStmt->execute([$other_user_id]);
$otherUserExtra = $avatarStmt->fetch(PDO::FETCH_ASSOC);

// Xử lý embed mode (khi mở trong iframe)
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

<div class="chat-page">
    <div class="chat-container">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="sidebar-header">
                <i class="bi bi-chat-heart-fill"></i>
                <span>Tin nhắn</span>
            </div>
            <div class="sidebar-nav">
                <a href="conversations.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="sidebar-link">
                    <i class="bi bi-arrow-left"></i> Tất cả cuộc trò chuyện
                </a>
                <a href="friends.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="sidebar-link">
                    <i class="bi bi-people"></i> Danh sách bạn bè
                </a>
                <a href="view_profile.php?id=<?php echo $other_user_id; ?><?php echo $isEmbed ? '&embed=1' : ''; ?>" class="sidebar-link">
                    <i class="bi bi-person"></i> Xem hồ sơ
                </a>
            </div>
            
            <?php if ($areFriends): ?>
            <div class="friend-status-box success">
                <i class="bi bi-person-check-fill"></i>
                <span>Đã là bạn bè</span>
            </div>
            <?php elseif ($friendshipStatus === 'pending'): ?>
            <div class="friend-status-box warning">
                <i class="bi bi-clock"></i>
                <span>Đang chờ kết bạn</span>
            </div>
            <?php else: ?>
            <div class="friend-status-box info">
                <i class="bi bi-person-plus"></i>
                <a href="view_profile.php?id=<?php echo $other_user_id; ?>">Gửi lời mời kết bạn</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <!-- Chat Header -->
            <div class="chat-header">
                <div class="chat-user-info">
                    <div class="chat-avatar-wrap">
                        <?php if (!empty($otherUserExtra['avatar']) && upload_exists($otherUserExtra['avatar'])): ?>
                            <img src="<?php echo htmlspecialchars(public_url_for($otherUserExtra['avatar'])); ?>" class="chat-avatar" alt="">
                        <?php else: ?>
                            <div class="chat-avatar-placeholder"><?php echo strtoupper(substr($other_user['name'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <span class="chat-status-dot <?php echo is_user_online($other_user['last_activity'] ?? null) ? 'online' : 'offline'; ?>"></span>
                    </div>
                    <div class="chat-user-details">
                        <h4 class="chat-user-name">
                            <?php echo htmlspecialchars($other_user['name']); ?>
                            <?php if (!empty($otherUserExtra['verified'])): ?>
                                <i class="bi bi-patch-check-fill verified-badge"></i>
                            <?php endif; ?>
                            <?php if ($areFriends): ?>
                                <span class="friend-badge"><i class="bi bi-people-fill"></i> Bạn bè</span>
                            <?php endif; ?>
                        </h4>
                        <div class="chat-user-status">
                            <span class="role-badge <?php echo $other_user['role']; ?>">
                                <i class="bi bi-<?php echo $other_user['role'] === 'patient' ? 'heart-pulse' : 'mortarboard'; ?>-fill"></i>
                                <?php echo $other_user['role'] === 'patient' ? 'Bệnh nhân' : 'Sinh viên Y khoa'; ?>
                            </span>
                            <span class="online-badge <?php echo is_user_online($other_user['last_activity'] ?? null) ? 'online' : 'offline'; ?>">
                                <i class="bi bi-circle-fill"></i>
                                <?php echo is_user_online($other_user['last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <a href="video_call.php?with=<?php echo $other_user_id; ?>" class="header-action-btn video-call-btn" title="Gọi video">
                        <i class="bi bi-camera-video-fill"></i>
                    </a>
                    <a href="view_profile.php?id=<?php echo $other_user_id; ?>" class="header-action-btn" title="Xem hồ sơ">
                        <i class="bi bi-person"></i>
                    </a>
                </div>
            </div>
            <!-- Messages Container -->
            <div class="chat-messages" id="messageContainer">
                <?php if (empty($messages)): ?>
                    <div class="chat-empty-state">
                        <div class="empty-icon">
                            <i class="bi bi-chat-heart"></i>
                        </div>
                        <h5>Bắt đầu cuộc trò chuyện</h5>
                        <p>Gửi tin nhắn đầu tiên đến <?php echo htmlspecialchars($other_user['name']); ?></p>
                    </div>
                <?php else: ?>
                    <?php 
                    $lastDate = '';
                    foreach ($messages as $msg): 
                        $msgDate = date('d/m/Y', strtotime($msg['created_at']));
                        if ($msgDate !== $lastDate):
                            $lastDate = $msgDate;
                    ?>
                        <div class="chat-date-divider">
                            <span><?php echo $msgDate === date('d/m/Y') ? 'Hôm nay' : $msgDate; ?></span>
                        </div>
                    <?php endif; ?>
                        <div class="message-wrapper <?php echo $msg['sender_id'] == $current_user_id ? 'sent' : 'received'; ?>">
                            <div class="message-bubble">
                                <div class="message-content">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                                <div class="message-time">
                                    <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                    <?php if ($msg['sender_id'] == $current_user_id): ?>
                                        <i class="bi bi-check2-all"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Chat Input -->
            <div class="chat-input-area">
                <?php if (!empty($send_error)): ?>
                <div class="alert alert-danger py-2 mb-2" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-exclamation-circle me-1"></i> <?php echo htmlspecialchars($send_error); ?>
                </div>
                <?php endif; ?>
                <form method="post" action="chat.php?user_id=<?php echo $other_user_id; ?><?php echo $isEmbed ? '&embed=1' : ''; ?>" class="chat-form" id="chatForm">
                    <div class="input-wrapper">
                        <textarea name="message" class="chat-input" rows="1" placeholder="Nhập tin nhắn..." required id="messageInput"></textarea>
                    </div>
                    <button type="submit" class="send-btn">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Chat Page Layout */
.chat-page { margin: -1.5rem -0.75rem; min-height: calc(100vh - 80px); background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%); }
.chat-container { display: flex; max-width: 1200px; margin: 0 auto; height: calc(100vh - 80px); padding: 1.5rem; gap: 1.5rem; }

/* Sidebar */
.chat-sidebar { width: 280px; flex-shrink: 0; display: flex; flex-direction: column; gap: 1rem; }
.sidebar-header { background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); border-radius: 16px; padding: 1.25rem; color: #fff; display: flex; align-items: center; gap: 0.75rem; font-size: 1.1rem; font-weight: 700; }
.sidebar-nav { background: #fff; border-radius: 16px; padding: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
.sidebar-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 10px; color: #475569; text-decoration: none; font-weight: 500; transition: all 0.3s; }
.sidebar-link:hover { background: #f1f5f9; color: #3b82f6; transform: translateX(4px); }
.sidebar-link i { font-size: 1.1rem; }
.friend-status-box { background: #fff; border-radius: 12px; padding: 1rem; display: flex; align-items: center; gap: 0.75rem; font-weight: 600; font-size: 0.9rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
.friend-status-box.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.friend-status-box.warning { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
.friend-status-box.info { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
.friend-status-box a { color: inherit; text-decoration: underline; }

/* Main Chat Area */
.chat-main { flex: 1; background: #fff; border-radius: 20px; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.08); overflow: hidden; }

/* Chat Header */
.chat-header { padding: 1rem 1.5rem; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.chat-user-info { display: flex; align-items: center; gap: 1rem; }
.chat-avatar-wrap { position: relative; }
.chat-avatar, .chat-avatar-placeholder { width: 52px; height: 52px; border-radius: 14px; object-fit: cover; }
.chat-avatar-placeholder { background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 700; }
.chat-status-dot { position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; }
.chat-status-dot.online { background: #22c55e; }
.chat-status-dot.offline { background: #94a3b8; }
.chat-user-name { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.verified-badge { color: #3b82f6; font-size: 1rem; }
.friend-badge { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; }
.chat-user-status { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem; flex-wrap: wrap; }
.role-badge { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem; }
.role-badge.patient { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
.role-badge.student { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.online-badge { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.7rem; font-weight: 500; display: inline-flex; align-items: center; gap: 0.3rem; }
.online-badge.online { background: #d1fae5; color: #059669; }
.online-badge.offline { background: #f1f5f9; color: #64748b; }
.online-badge i { font-size: 0.5rem; }
.header-action-btn { width: 40px; height: 40px; border-radius: 10px; background: #f1f5f9; color: #64748b; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: all 0.3s; }
.header-action-btn:hover { background: #3b82f6; color: #fff; }
.header-action-btn.video-call-btn { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
.header-action-btn.video-call-btn:hover { background: linear-gradient(135deg, #059669, #047857); transform: scale(1.05); }
.chat-header-actions { display: flex; gap: 0.5rem; }

/* Messages Area */
.chat-messages { flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 0.75rem; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); }
.chat-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: #94a3b8; }
.chat-empty-state .empty-icon { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #dbeafe, #c7d2fe); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
.chat-empty-state .empty-icon i { font-size: 2rem; color: #3b82f6; }
.chat-empty-state h5 { color: #475569; margin-bottom: 0.25rem; }
.chat-date-divider { text-align: center; margin: 1rem 0; }
.chat-date-divider span { background: #e2e8f0; color: #64748b; padding: 0.35rem 1rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }

/* Message Bubbles */
.message-wrapper { display: flex; }
.message-wrapper.sent { justify-content: flex-end; }
.message-wrapper.received { justify-content: flex-start; }
.message-bubble { max-width: 70%; }
.message-wrapper.sent .message-bubble { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; border-radius: 18px 18px 4px 18px; }
.message-wrapper.received .message-bubble { background: #f1f5f9; color: #1e293b; border-radius: 18px 18px 18px 4px; }
.message-content { padding: 0.75rem 1rem; line-height: 1.5; word-wrap: break-word; }
.message-time { padding: 0 1rem 0.5rem; font-size: 0.7rem; opacity: 0.7; display: flex; align-items: center; gap: 0.25rem; }
.message-wrapper.sent .message-time { justify-content: flex-end; }

/* Chat Input */
.chat-input-area { padding: 1rem 1.5rem; background: #fff; border-top: 1px solid #e2e8f0; }
.chat-form { display: flex; gap: 0.75rem; align-items: flex-end; }
.input-wrapper { flex: 1; }
.chat-input { width: 100%; border: 2px solid #e2e8f0; border-radius: 14px; padding: 0.75rem 1rem; font-size: 0.95rem; resize: none; transition: all 0.3s; max-height: 120px; }
.chat-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,0.1); }
.send-btn { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: all 0.3s; box-shadow: 0 4px 15px rgba(59,130,246,0.3); }
.send-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59,130,246,0.4); }

/* Responsive */
@media (max-width: 991px) {
    .chat-sidebar { display: none; }
    .chat-container { padding: 0; }
    .chat-main { border-radius: 0; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto scroll to bottom
    const container = document.getElementById('messageContainer');
    container.scrollTop = container.scrollHeight;
    
    // Auto-resize textarea
    const textarea = document.getElementById('messageInput');
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    
    // Submit on Enter (Shift+Enter for new line)
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (this.value.trim()) {
                document.getElementById('chatForm').submit();
            }
        }
    });
});
</script>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>