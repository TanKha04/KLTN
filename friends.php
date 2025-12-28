<?php
// Friends management page
declare(strict_types=1);
require_once 'config.php';
require_login();

$userId = $_SESSION['user_id'];
$tab = $_GET['tab'] ?? 'friends';

// Get friends list
$friendsStmt = $pdo->prepare('
    SELECT u.id, u.name, u.email, u.avatar, u.role, u.verified, u.last_activity, f.accepted_at
    FROM friendships f
    JOIN users u ON (u.id = CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END)
    WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = "accepted"
    ORDER BY f.accepted_at DESC
');
$friendsStmt->execute([$userId, $userId, $userId]);
$friends = $friendsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending requests (received)
$pendingStmt = $pdo->prepare('
    SELECT u.id, u.name, u.email, u.avatar, u.role, u.verified, u.last_activity, f.created_at
    FROM friendships f
    JOIN users u ON u.id = f.user_id
    WHERE f.friend_id = ? AND f.status = "pending"
    ORDER BY f.created_at DESC
');
$pendingStmt->execute([$userId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get sent requests
$sentStmt = $pdo->prepare('
    SELECT u.id, u.name, u.email, u.avatar, u.role, u.verified, f.created_at
    FROM friendships f
    JOIN users u ON u.id = f.friend_id
    WHERE f.user_id = ? AND f.status = "pending"
    ORDER BY f.created_at DESC
');
$sentStmt->execute([$userId]);
$sentRequests = $sentStmt->fetchAll(PDO::FETCH_ASSOC);

// Search users - tìm kiếm chính xác hơn
$searchQuery = trim($_GET['q'] ?? '');
$searchResults = [];
if ($searchQuery !== '') {
    // Tìm kiếm: tên bắt đầu bằng từ khóa, hoặc có từ bắt đầu bằng từ khóa (sau khoảng trắng)
    $searchStmt = $pdo->prepare('
        SELECT id, name, email, avatar, role, verified, last_activity
        FROM users 
        WHERE id != ? AND (
            name LIKE ? OR 
            name LIKE ? OR
            email LIKE ?
        )
        ORDER BY 
            CASE 
                WHEN LOWER(name) = LOWER(?) THEN 1
                WHEN LOWER(name) LIKE LOWER(?) THEN 2
                WHEN LOWER(name) LIKE LOWER(?) THEN 3
                ELSE 4
            END,
            name ASC
        LIMIT 20
    ');
    $exactMatch = $searchQuery;
    $startsWith = $searchQuery . '%';
    $wordStartsWith = '% ' . $searchQuery . '%';
    $searchStmt->execute([$userId, $startsWith, $wordStartsWith, $startsWith . '%', $exactMatch, $startsWith, $wordStartsWith]);
    $searchResults = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
}

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
    // Ẩn navbar trên trang bạn bè
    echo '<style>.premium-navbar { display: none !important; } body { padding-top: 0 !important; }</style>';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Bạn bè</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}</style>';
    echo '</head><body>';
}
?>

<style>
.friends-page { max-width: 1000px; margin: 0 auto; padding: 1.5rem; }
</style>

<style>
.friends-header { background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%); border-radius: 20px; padding: 2rem; margin-bottom: 1.5rem; color: #fff; }
.friends-header h1 { font-size: 1.75rem; font-weight: 800; margin: 0 0 0.5rem; }
.friends-header p { margin: 0; opacity: 0.9; }
.friends-search { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); border-radius: 12px; padding: 0.75rem 1rem 0.75rem 2.75rem; color: #fff; width: 100%; max-width: 400px; margin-top: 1rem; }
.friends-search::placeholder { color: rgba(255,255,255,0.7); }
.friends-search-wrap { position: relative; }
.friends-search-wrap i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.7); }
.friends-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.friends-tab { padding: 0.75rem 1.5rem; border-radius: 12px; background: #f1f5f9; color: #64748b; text-decoration: none; font-weight: 600; transition: all 0.3s; display: flex; align-items: center; gap: 0.5rem; }
.friends-tab:hover { background: #d1fae5; color: #059669; }
.friends-tab.active { background: linear-gradient(135deg, #059669, #10b981); color: #fff; }
.friends-tab .badge { background: #ef4444; color: #fff; padding: 0.15rem 0.5rem; border-radius: 10px; font-size: 0.75rem; }
.friends-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.friend-card { background: #fff; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; transition: all 0.3s; }
.friend-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.1); transform: translateY(-2px); }
.friend-info { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.friend-avatar { width: 56px; height: 56px; border-radius: 14px; object-fit: cover; background: linear-gradient(135deg, #059669, #10b981); }
.friend-avatar-placeholder { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #059669, #10b981); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 700; }
.friend-details { flex: 1; min-width: 0; }
.friend-name { font-weight: 700; color: #1e293b; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; }
.friend-name .verified { color: #10b981; font-size: 0.9rem; }
.friend-meta { font-size: 0.85rem; color: #64748b; }
.friend-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.friend-btn { padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.35rem; text-decoration: none; }
.friend-btn.primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; }
.friend-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.4); }
.friend-btn.success { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
.friend-btn.success:hover { box-shadow: 0 4px 12px rgba(16,185,129,0.4); }
.friend-btn.danger { background: rgba(239,68,68,0.1); color: #dc2626; }
.friend-btn.danger:hover { background: #dc2626; color: #fff; }
.friend-btn.outline { background: #f1f5f9; color: #64748b; }
.friend-btn.outline:hover { background: #e2e8f0; color: #1e293b; }
.empty-state { text-align: center; padding: 3rem; color: #94a3b8; }
.empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
.empty-state h5 { color: #64748b; margin-bottom: 0.5rem; }
.online-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.online-dot.online { background: #10b981; }
.online-dot.offline { background: #94a3b8; }
</style>

<div class="friends-page">
    <div class="friends-header">
        <h1><i class="bi bi-people-fill me-2"></i>Bạn bè</h1>
        <p>Quản lý danh sách bạn bè và lời mời kết bạn</p>
        <form method="get" class="friends-search-wrap">
            <i class="bi bi-search"></i>
            <input type="hidden" name="tab" value="search">
            <input type="text" name="q" class="friends-search" placeholder="Tìm kiếm người dùng..." value="<?php echo htmlspecialchars($searchQuery); ?>">
        </form>
    </div>

    <div class="friends-tabs">
        <a href="friends.php?tab=friends" class="friends-tab <?php echo $tab === 'friends' ? 'active' : ''; ?>">
            <i class="bi bi-people"></i> Bạn bè (<?php echo count($friends); ?>)
        </a>
        <a href="friends.php?tab=requests" class="friends-tab <?php echo $tab === 'requests' ? 'active' : ''; ?>">
            <i class="bi bi-person-plus"></i> Lời mời
            <?php if (count($pendingRequests) > 0): ?>
                <span class="badge"><?php echo count($pendingRequests); ?></span>
            <?php endif; ?>
        </a>
        <a href="friends.php?tab=sent" class="friends-tab <?php echo $tab === 'sent' ? 'active' : ''; ?>">
            <i class="bi bi-send"></i> Đã gửi (<?php echo count($sentRequests); ?>)
        </a>
        <?php if ($searchQuery): ?>
        <a href="friends.php?tab=search&q=<?php echo urlencode($searchQuery); ?>" class="friends-tab <?php echo $tab === 'search' ? 'active' : ''; ?>">
            <i class="bi bi-search"></i> Kết quả tìm kiếm
        </a>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'friends'): ?>
    <!-- Friends List -->
    <div class="friends-grid">
        <?php if (empty($friends)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <i class="bi bi-people"></i>
                <h5>Chưa có bạn bè</h5>
                <p>Tìm kiếm và kết bạn với người dùng khác</p>
            </div>
        <?php else: ?>
            <?php foreach ($friends as $friend): ?>
            <div class="friend-card" id="friend-<?php echo $friend['id']; ?>">
                <div class="friend-info">
                    <?php if (!empty($friend['avatar']) && function_exists('upload_exists') && upload_exists($friend['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($friend['avatar'])); ?>" class="friend-avatar" alt="">
                    <?php else: ?>
                        <div class="friend-avatar-placeholder"><?php echo strtoupper(substr($friend['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="friend-details">
                        <div class="friend-name">
                            <?php echo htmlspecialchars($friend['name']); ?>
                            <?php if ($friend['verified']): ?><i class="bi bi-patch-check-fill verified"></i><?php endif; ?>
                        </div>
                        <div class="friend-meta">
                            <span class="online-dot <?php echo is_user_online($friend['last_activity'] ?? null) ? 'online' : 'offline'; ?>"></span>
                            <?php echo is_user_online($friend['last_activity'] ?? null) ? 'Đang online' : 'Offline'; ?>
                        </div>
                    </div>
                </div>
                <div class="friend-actions">
                    <a href="chat.php?with=<?php echo $friend['id']; ?>" class="friend-btn primary"><i class="bi bi-chat-dots"></i> Nhắn tin</a>
                    <a href="view_profile.php?id=<?php echo $friend['id']; ?>" class="friend-btn outline"><i class="bi bi-person"></i> Xem</a>
                    <button class="friend-btn danger" onclick="friendAction('unfriend', <?php echo $friend['id']; ?>)"><i class="bi bi-person-dash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'requests'): ?>
    <!-- Pending Requests -->
    <div class="friends-grid">
        <?php if (empty($pendingRequests)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <i class="bi bi-inbox"></i>
                <h5>Không có lời mời</h5>
                <p>Các lời mời kết bạn sẽ hiển thị tại đây</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingRequests as $req): ?>
            <div class="friend-card" id="request-<?php echo $req['id']; ?>">
                <div class="friend-info">
                    <?php if (!empty($req['avatar']) && function_exists('upload_exists') && upload_exists($req['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($req['avatar'])); ?>" class="friend-avatar" alt="">
                    <?php else: ?>
                        <div class="friend-avatar-placeholder"><?php echo strtoupper(substr($req['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="friend-details">
                        <div class="friend-name">
                            <?php echo htmlspecialchars($req['name']); ?>
                            <?php if ($req['verified']): ?><i class="bi bi-patch-check-fill verified"></i><?php endif; ?>
                        </div>
                        <div class="friend-meta">Gửi lúc <?php echo date('d/m/Y H:i', strtotime($req['created_at'])); ?></div>
                    </div>
                </div>
                <div class="friend-actions">
                    <button class="friend-btn success" onclick="friendAction('accept_request', <?php echo $req['id']; ?>)"><i class="bi bi-check-lg"></i> Chấp nhận</button>
                    <button class="friend-btn danger" onclick="friendAction('reject_request', <?php echo $req['id']; ?>)"><i class="bi bi-x-lg"></i> Từ chối</button>
                    <a href="view_profile.php?id=<?php echo $req['id']; ?>" class="friend-btn outline"><i class="bi bi-person"></i></a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'sent'): ?>
    <!-- Sent Requests -->
    <div class="friends-grid">
        <?php if (empty($sentRequests)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <i class="bi bi-send"></i>
                <h5>Chưa gửi lời mời nào</h5>
                <p>Các lời mời kết bạn bạn đã gửi sẽ hiển thị tại đây</p>
            </div>
        <?php else: ?>
            <?php foreach ($sentRequests as $sent): ?>
            <div class="friend-card" id="sent-<?php echo $sent['id']; ?>">
                <div class="friend-info">
                    <?php if (!empty($sent['avatar']) && function_exists('upload_exists') && upload_exists($sent['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($sent['avatar'])); ?>" class="friend-avatar" alt="">
                    <?php else: ?>
                        <div class="friend-avatar-placeholder"><?php echo strtoupper(substr($sent['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="friend-details">
                        <div class="friend-name">
                            <?php echo htmlspecialchars($sent['name']); ?>
                            <?php if ($sent['verified']): ?><i class="bi bi-patch-check-fill verified"></i><?php endif; ?>
                        </div>
                        <div class="friend-meta">Đã gửi lúc <?php echo date('d/m/Y H:i', strtotime($sent['created_at'])); ?></div>
                    </div>
                </div>
                <div class="friend-actions">
                    <button class="friend-btn danger" onclick="friendAction('cancel_request', <?php echo $sent['id']; ?>)"><i class="bi bi-x-lg"></i> Hủy lời mời</button>
                    <a href="view_profile.php?id=<?php echo $sent['id']; ?>" class="friend-btn outline"><i class="bi bi-person"></i> Xem</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'search' && $searchQuery): ?>
    <!-- Search Results -->
    <div class="friends-grid">
        <?php if (empty($searchResults)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <i class="bi bi-search"></i>
                <h5>Không tìm thấy kết quả</h5>
                <p>Thử tìm kiếm với từ khóa khác</p>
            </div>
        <?php else: ?>
            <?php 
            // Get friendship status for each result
            $friendIds = array_column($searchResults, 'id');
            $statusMap = [];
            if ($friendIds) {
                $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
                $statusStmt = $pdo->prepare("
                    SELECT 
                        CASE WHEN user_id = ? THEN friend_id ELSE user_id END AS other_id,
                        status,
                        user_id
                    FROM friendships 
                    WHERE (user_id = ? AND friend_id IN ($placeholders)) 
                       OR (friend_id = ? AND user_id IN ($placeholders))
                ");
                $params = array_merge([$userId, $userId], $friendIds, [$userId], $friendIds);
                $statusStmt->execute($params);
                while ($row = $statusStmt->fetch()) {
                    $statusMap[$row['other_id']] = [
                        'status' => $row['status'],
                        'is_sender' => $row['user_id'] == $userId
                    ];
                }
            }
            ?>
            <?php foreach ($searchResults as $result): 
                $fs = $statusMap[$result['id']] ?? null;
            ?>
            <div class="friend-card" id="user-<?php echo $result['id']; ?>">
                <div class="friend-info">
                    <?php if (!empty($result['avatar']) && function_exists('upload_exists') && upload_exists($result['avatar'])): ?>
                        <img src="<?php echo htmlspecialchars(public_url_for($result['avatar'])); ?>" class="friend-avatar" alt="">
                    <?php else: ?>
                        <div class="friend-avatar-placeholder"><?php echo strtoupper(substr($result['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="friend-details">
                        <div class="friend-name">
                            <?php echo htmlspecialchars($result['name']); ?>
                            <?php if ($result['verified']): ?><i class="bi bi-patch-check-fill verified"></i><?php endif; ?>
                        </div>
                        <div class="friend-meta"><?php echo $result['role'] === 'student' ? 'Sinh viên' : 'Bệnh nhân'; ?></div>
                    </div>
                </div>
                <div class="friend-actions">
                    <?php if ($fs && $fs['status'] === 'accepted'): ?>
                        <a href="chat.php?with=<?php echo $result['id']; ?>" class="friend-btn primary"><i class="bi bi-chat-dots"></i> Nhắn tin</a>
                        <button class="friend-btn danger" onclick="friendAction('unfriend', <?php echo $result['id']; ?>)"><i class="bi bi-person-dash"></i></button>
                    <?php elseif ($fs && $fs['status'] === 'pending' && $fs['is_sender']): ?>
                        <button class="friend-btn outline" disabled><i class="bi bi-clock"></i> Đã gửi lời mời</button>
                        <button class="friend-btn danger" onclick="friendAction('cancel_request', <?php echo $result['id']; ?>)"><i class="bi bi-x-lg"></i></button>
                    <?php elseif ($fs && $fs['status'] === 'pending' && !$fs['is_sender']): ?>
                        <button class="friend-btn success" onclick="friendAction('accept_request', <?php echo $result['id']; ?>)"><i class="bi bi-check-lg"></i> Chấp nhận</button>
                        <button class="friend-btn danger" onclick="friendAction('reject_request', <?php echo $result['id']; ?>)"><i class="bi bi-x-lg"></i></button>
                    <?php elseif ($fs && $fs['status'] === 'blocked'): ?>
                        <button class="friend-btn outline" disabled>Đã chặn</button>
                    <?php else: ?>
                        <button class="friend-btn primary" onclick="friendAction('send_request', <?php echo $result['id']; ?>)"><i class="bi bi-person-plus"></i> Kết bạn</button>
                    <?php endif; ?>
                    <a href="view_profile.php?id=<?php echo $result['id']; ?>" class="friend-btn outline"><i class="bi bi-person"></i> Xem</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function friendAction(action, friendId) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('friend_id', friendId);
    
    fetch('friend_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Có lỗi xảy ra');
        }
    })
    .catch(() => alert('Có lỗi xảy ra'));
}
</script>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
