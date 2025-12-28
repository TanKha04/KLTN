<?php
require_once 'config.php';
require_login();

$userId = $_SESSION['user_id'];
// Detect nếu đang trong iframe hoặc có embed=1
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1') || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe');
$onlyAdmin = !empty($_GET['from_admin']);

// Đảm bảo cột is_read tồn tại
try {
    $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'is_read'");
    $checkCol->execute();
    if ((int)$checkCol->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    }
} catch (Throwable $e) {
    error_log('Add is_read column failed: ' . $e->getMessage());
}

// Đánh dấu tất cả tin nhắn gửi đến user hiện tại là đã đọc
try {
    $markRead = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND is_read = 0');
    $markRead->execute([$userId]);
} catch (Throwable $e) {
    error_log('Mark messages as read failed: ' . $e->getMessage());
}

if ($onlyAdmin) {
    $sql = 'SELECT m.*, ufrom.name AS from_name, uto.name AS to_name, p.title AS post_title
            FROM messages m
            JOIN users ufrom ON m.sender_id = ufrom.id
            JOIN users uto ON m.receiver_id = uto.id
            LEFT JOIN posts p ON m.post_id = p.id
            WHERE m.receiver_id = ? AND ufrom.is_admin = 1
            ORDER BY m.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
} else {
    $stmt = $pdo->prepare('SELECT m.*, ufrom.name AS from_name, uto.name AS to_name, p.title AS post_title FROM messages m JOIN users ufrom ON m.sender_id = ufrom.id JOIN users uto ON m.receiver_id = uto.id LEFT JOIN posts p ON m.post_id = p.id WHERE m.sender_id = ? OR m.receiver_id = ? ORDER BY m.created_at DESC');
    $stmt->execute([$userId, $userId]);
}
$messages = $stmt->fetchAll();

if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Tin nhắn</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}.premium-navbar,.navbar,.site-header{display:none!important;}html,body{overflow-x:hidden;}</style>';
    echo '</head><body>';
}
?>

<script>
// Ẩn navbar nếu đang trong iframe
if (window.self !== window.top) {
    document.addEventListener('DOMContentLoaded', function() {
        var navbar = document.querySelector('.premium-navbar');
        if (navbar) navbar.style.display = 'none';
        document.body.style.paddingTop = '0';
    });
}
</script>

<style>
/* Messages Page Premium Styles - Match conversations.php */
.messages-page-wrapper {
    padding: 1rem 0;
    min-height: 70vh;
}
.messages-sidebar {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 24px;
    padding: 2rem;
    color: #fff;
    position: sticky;
    top: 100px;
    box-shadow: 0 20px 50px rgba(11, 63, 145, 0.35);
    overflow: hidden;
}
.messages-sidebar::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -30%;
    width: 80%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}
.messages-sidebar .sidebar-icon {
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
    position: relative;
    z-index: 1;
}
.messages-sidebar h5 {
    font-size: 1.35rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
}
.messages-sidebar p {
    font-size: 0.9rem;
    opacity: 0.85;
    margin-bottom: 1.5rem;
    line-height: 1.5;
    position: relative;
    z-index: 1;
}
.messages-sidebar .sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    position: relative;
    z-index: 1;
}
.messages-sidebar .sidebar-link {
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
.messages-sidebar .sidebar-link:hover {
    background: rgba(255,255,255,0.25);
    transform: translateX(8px);
    color: #fff;
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
}
.messages-sidebar .sidebar-link i {
    width: 22px;
    text-align: center;
    font-size: 1.1rem;
}

/* Main Content */
.messages-main {
    background: #fff;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 15px 50px rgba(0,0,0,0.08);
}
.messages-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem 2rem;
    border-bottom: 2px solid #e2e8f0;
}
.messages-header h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.messages-header h3 i, .messages-header h3 .header-icon {
    color: #3b82f6;
    font-size: 1.35rem;
}
.messages-header .header-subtitle {
    color: #64748b;
    font-size: 0.95rem;
    margin: 0 0 1rem 0;
}
.messages-filter-btns {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.filter-btn-primary {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}
.filter-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    color: #fff;
}
.filter-btn-outline {
    background: #fff;
    color: #475569;
    border-color: #e2e8f0;
}
.filter-btn-outline:hover {
    background: #f8fafc;
    border-color: #3b82f6;
    color: #3b82f6;
}

/* Messages List */
.messages-body {
    padding: 1.5rem 2rem;
}
.messages-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.message-card {
    background: linear-gradient(135deg, #f8fafc 0%, #fff 100%);
    border-radius: 16px;
    padding: 1.25rem;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    animation: messageSlideIn 0.4s ease-out backwards;
}
.message-card:nth-child(1) { animation-delay: 0.05s; }
.message-card:nth-child(2) { animation-delay: 0.1s; }
.message-card:nth-child(3) { animation-delay: 0.15s; }
.message-card:nth-child(4) { animation-delay: 0.2s; }
.message-card:nth-child(5) { animation-delay: 0.25s; }
@keyframes messageSlideIn {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}
.message-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.08);
    border-color: #c7d2fe;
}
.message-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.75rem;
}
.message-avatar {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.message-meta {
    flex: 1;
    min-width: 0;
}
.message-users {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    font-size: 0.95rem;
}
.message-users a {
    font-weight: 600;
    color: #1e293b;
    text-decoration: none;
}
.message-users a:hover {
    color: #3b82f6;
}
.message-arrow {
    color: #94a3b8;
}
.message-post-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: #dbeafe;
    color: #1d4ed8;
    padding: 0.3rem 0.75rem;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 500;
    margin-left: 0.5rem;
}
.message-time {
    font-size: 0.85rem;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.message-content {
    background: #fff;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-left: 60px;
    border-left: 3px solid #3b82f6;
    color: #475569;
    line-height: 1.6;
    font-size: 0.95rem;
}

/* Empty State */
.messages-empty {
    text-align: center;
    padding: 4rem 2rem;
}
.messages-empty .empty-icon {
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
.messages-empty h5 {
    color: #1e293b;
    font-weight: 700;
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
}
.messages-empty p {
    color: #64748b;
    font-size: 0.95rem;
}

@media (max-width: 991px) {
    .messages-sidebar {
        position: static;
        margin-bottom: 1.5rem;
    }
}
@media (max-width: 768px) {
    .messages-header, .messages-body {
        padding: 1.25rem;
    }
    .message-content {
        margin-left: 0;
        margin-top: 0.5rem;
    }
}
</style>

<div class="messages-page-wrapper">
    <div class="row g-4">
        <div class="col-lg-3">
            <div class="messages-sidebar">
                <div class="sidebar-icon">💬</div>
                <h5>Tin nhắn</h5>
                <p>Quản lý tin nhắn và thông báo của bạn</p>
                <div class="sidebar-nav">
                    <a href="index.php" class="sidebar-link">
                        <i class="fas fa-arrow-left"></i> Quay lại trang chủ
                    </a>
                    <a href="conversations.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="sidebar-link" <?php echo $isEmbed ? 'target="_self"' : ''; ?>>
                        <i class="fas fa-comments"></i> Hội thoại
                    </a>
                    <a href="logout.php" class="sidebar-link">
                        <i class="fas fa-sign-out-alt"></i> Đăng xuất
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="messages-main">
                <div class="messages-header">
                    <h3>
                        <i class="fas <?php echo $onlyAdmin ? 'fa-bell' : 'fa-envelope'; ?>"></i>
                        <?php echo $onlyAdmin ? 'Thông báo từ quản trị viên' : 'Tin nhắn của tôi'; ?>
                    </h3>
                    <div class="messages-filter-btns">
                        <?php if ($onlyAdmin): ?>
                            <a class="filter-btn filter-btn-primary" href="view_messages.php<?php echo $isEmbed ? '?embed=1' : ''; ?>">
                                <i class="fas fa-inbox"></i> Tất cả tin nhắn
                            </a>
                            <span class="filter-btn filter-btn-outline" style="cursor: default;">
                                <i class="fas fa-bell"></i> Đang xem thông báo
                            </span>
                        <?php else: ?>
                            <span class="filter-btn filter-btn-outline" style="cursor: default;">
                                <i class="fas fa-inbox"></i> Đang xem tất cả
                            </span>
                            <a class="filter-btn filter-btn-primary" href="view_messages.php?from_admin=1<?php echo $isEmbed ? '&embed=1' : ''; ?>">
                                <i class="fas fa-bell"></i> Thông báo hệ thống
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="messages-body">
                    <?php if (!$messages): ?>
                        <div class="messages-empty">
                            <div class="empty-icon">📭</div>
                            <h5>Chưa có tin nhắn</h5>
                            <p>Hộp thư của bạn đang trống. Tin nhắn mới sẽ xuất hiện ở đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="messages-list">
                            <?php foreach ($messages as $m): ?>
                                <div class="message-card">
                                    <div class="message-card-header">
                                        <div class="message-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="message-meta">
                                            <div class="message-users">
                                                <a href="view_profile.php?id=<?php echo $m['sender_id']; ?>"><?php echo htmlspecialchars($m['from_name']); ?></a>
                                                <span class="message-arrow"><i class="fas fa-long-arrow-alt-right"></i></span>
                                                <a href="view_profile.php?id=<?php echo $m['receiver_id']; ?>"><?php echo htmlspecialchars($m['to_name']); ?></a>
                                                <?php if ($m['post_title']): ?>
                                                    <span class="message-post-tag">
                                                        <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($m['post_title']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="message-time">
                                                <i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($m['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="message-content">
                                        <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                                    </div>
                                </div>
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
