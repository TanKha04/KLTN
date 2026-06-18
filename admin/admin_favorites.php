<?php
require_once 'config.php';
require_admin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'delete_favorite') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $postId = (int)($_POST['post_id'] ?? 0);
            if ($userId > 0 && $postId > 0) {
                $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND post_id = ? LIMIT 1');
                $stmt->execute([$userId, $postId]);
                if ($stmt->rowCount() > 0) {
                    $success = 'Đã xóa bài viết khỏi danh sách yêu thích của người dùng.';
                } else {
                    $error = 'Không tìm thấy dữ liệu yêu thích cần xóa.';
                }
            } else {
                $error = 'Thiếu thông tin người dùng hoặc bài viết.';
            }
        } elseif ($action === 'hide_post') {
            $postId = (int)($_POST['post_id'] ?? 0);
            if ($postId > 0) {
                $stmt = $pdo->prepare('UPDATE posts SET status = "closed" WHERE id = ?');
                $stmt->execute([$postId]);
                $success = 'Đã ẩn bài viết thành công.';
            } else {
                $error = 'Không xác định được bài viết cần ẩn.';
            }
        } elseif ($action === 'clear_all_favorites') {
            $pdo->exec('DELETE FROM favorites');
            $success = 'Đã xóa toàn bộ danh sách yêu thích.';
        }
    } catch (Throwable $e) {
        error_log('admin_favorites action error: ' . $e->getMessage());
        $error = 'Có lỗi xảy ra khi thao tác với dữ liệu yêu thích.';
    }
}

$favoriteCount = 0;
$favorites = [];
$userKeyword = trim($_GET['user_q'] ?? '');
$postKeyword = trim($_GET['post_q'] ?? '');
$postType = trim($_GET['post_type'] ?? '');

try {
    $favoriteCount = (int)$pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn();
    $where = [];
    $params = [];
    if ($userKeyword !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
        $kw = '%' . $userKeyword . '%';
        $params[] = $kw;
        $params[] = $kw;
    }
    if ($postKeyword !== '') {
        $where[] = 'p.title LIKE ?';
        $params[] = '%' . $postKeyword . '%';
    }
    if ($postType !== '' && in_array($postType, ['recruitment', 'application'], true)) {
        $where[] = 'p.type = ?';
        $params[] = $postType;
    }
    $sql = 'SELECT f.user_id, f.post_id, f.created_at, u.name AS user_name, u.email AS user_email, '
         . 'p.title AS post_title, p.type AS post_type, p.status AS post_status '
         . 'FROM favorites f '
         . 'JOIN users u ON u.id = f.user_id '
         . 'JOIN posts p ON p.id = f.post_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY f.created_at DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Fetch favorites failed: ' . $e->getMessage());
    $favorites = [];
    if (!$error) {
        $error = 'Không thể tải dữ liệu yêu thích. Vui lòng kiểm tra bảng favorites.';
    }
}

require_once 'header.php';
?>

<style>
.fav-page { background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%); min-height: 100vh; margin: -1.5rem -0.75rem; padding: 2rem; }
.fav-container { max-width: 1400px; margin: 0 auto; }
.fav-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2rem; }
.fav-header-left { display: flex; align-items: center; gap: 1rem; }
.fav-icon { width: 70px; height: 70px; background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #fff; box-shadow: 0 10px 30px rgba(238, 90, 36, 0.4); animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
.fav-title { color: #fff; }
.fav-title h1 { font-size: 2rem; font-weight: 700; margin: 0 0 0.25rem 0; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
.fav-title p { margin: 0; opacity: 0.9; font-size: 1rem; }
.fav-back { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.3); border-radius: 12px; color: #fff; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.fav-back:hover { background: #fff; color: #0b3f91; transform: translateX(-5px); }
.fav-alert { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.5rem; border-radius: 14px; margin-bottom: 1.5rem; font-weight: 500; }
.fav-alert.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border: 1px solid #6ee7b7; }
.fav-alert.error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border: 1px solid #fca5a5; }
.fav-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.fav-stat { background: #fff; border-radius: 20px; padding: 1.5rem; display: flex; align-items: center; gap: 1.25rem; box-shadow: 0 10px 40px rgba(0,0,0,0.1); transition: all 0.3s ease; position: relative; overflow: hidden; }
.fav-stat::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
.fav-stat:hover { transform: translateY(-5px); box-shadow: 0 20px 50px rgba(0,0,0,0.15); }
.fav-stat.pink::before { background: linear-gradient(180deg, #ff6b6b, #ee5a24); }
.fav-stat.blue::before { background: linear-gradient(180deg, #667eea, #764ba2); }
.fav-stat.green::before { background: linear-gradient(180deg, #11998e, #38ef7d); }
.fav-stat-icon { width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.fav-stat.pink .fav-stat-icon { background: linear-gradient(135deg, #ffe0e0, #ffb8b8); color: #e74c3c; }
.fav-stat.blue .fav-stat-icon { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #667eea; }
.fav-stat.green .fav-stat-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.fav-stat-info h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #1e293b; }
.fav-stat-info p { margin: 0; color: #64748b; font-weight: 500; }
.fav-card { background: #fff; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.1); overflow: hidden; }
.fav-card-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 2rem; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-bottom: 1px solid #e2e8f0; }
.fav-card-header h3 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 0.75rem; }
.fav-card-header h3 i { color: #667eea; }
.fav-clear-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.65rem 1.25rem; background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
.fav-clear-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4); }
.fav-filter { padding: 1.5rem 2rem; background: linear-gradient(135deg, #fafbff, #f5f7ff); border-bottom: 1px solid #e2e8f0; }
.fav-filter-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end; }
.fav-filter-group { position: relative; }
.fav-filter-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }
.fav-filter-group .icon { position: absolute; left: 1rem; bottom: 0.85rem; color: #94a3b8; }
.fav-filter-group input, .fav-filter-group select { width: 100%; padding: 0.85rem 1rem 0.85rem 2.75rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; transition: all 0.3s ease; background: #fff; }
.fav-filter-group input:focus, .fav-filter-group select:focus { outline: none; border-color: #0b3f91; box-shadow: 0 0 0 4px rgba(11, 63, 145, 0.1); }
.fav-search-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.85rem 1.5rem; background: linear-gradient(135deg, #0b3f91, #1e40af); color: #fff; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(11, 63, 145, 0.3); white-space: nowrap; }
.fav-search-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(11, 63, 145, 0.4); }
</style>

<div class="fav-page">
    <div class="fav-container">
        <div class="fav-header">
            <div class="fav-header-left">
                <div class="fav-icon"><i class="bi bi-heart-fill"></i></div>
                <div class="fav-title">
                    <h1>Quản lý bài viết yêu thích</h1>
                    <p>Theo dõi và quản lý các lượt lưu bài viết của người dùng</p>
                </div>
            </div>
            <a href="admin.php" class="fav-back"><i class="bi bi-arrow-left"></i> Quay lại</a>
        </div>

        <?php if ($success): ?>
            <div class="fav-alert success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="fav-alert error"><i class="bi bi-exclamation-circle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="fav-stats">
            <div class="fav-stat pink">
                <div class="fav-stat-icon"><i class="bi bi-heart-fill"></i></div>
                <div class="fav-stat-info"><h3><?php echo number_format($favoriteCount); ?></h3><p>Tổng lượt yêu thích</p></div>
            </div>
            <div class="fav-stat blue">
                <div class="fav-stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="fav-stat-info"><h3><?php echo count(array_unique(array_column($favorites, 'user_id'))); ?></h3><p>Người dùng</p></div>
            </div>
            <div class="fav-stat green">
                <div class="fav-stat-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                <div class="fav-stat-info"><h3><?php echo count(array_unique(array_column($favorites, 'post_id'))); ?></h3><p>Bài viết được lưu</p></div>
            </div>
        </div>

        <div class="fav-card">
            <div class="fav-card-header">
                <h3><i class="bi bi-list-ul"></i> Danh sách yêu thích</h3>
                <form method="post" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa toàn bộ?');">
                    <input type="hidden" name="action" value="clear_all_favorites">
                    <button class="fav-clear-btn" type="submit"><i class="bi bi-trash3"></i> Xóa tất cả</button>
                </form>
            </div>
            <div class="fav-filter">
                <form method="get">
                    <div class="fav-filter-grid">
                        <div class="fav-filter-group">
                            <label>Người dùng</label>
                            <i class="bi bi-person icon"></i>
                            <input type="text" name="user_q" value="<?php echo htmlspecialchars($userKeyword); ?>" placeholder="Tìm theo tên/email">
                        </div>
                        <div class="fav-filter-group">
                            <label>Bài viết</label>
                            <i class="bi bi-file-text icon"></i>
                            <input type="text" name="post_q" value="<?php echo htmlspecialchars($postKeyword); ?>" placeholder="Tìm theo tiêu đề">
                        </div>
                        <div class="fav-filter-group">
                            <label>Loại bài viết</label>
                            <i class="bi bi-funnel icon"></i>
                            <select name="post_type">
                                <option value="">Tất cả</option>
                                <option value="recruitment" <?php echo $postType === 'recruitment' ? 'selected' : ''; ?>>Tuyển dụng</option>
                                <option value="application" <?php echo $postType === 'application' ? 'selected' : ''; ?>>Ứng tuyển</option>
                            </select>
                        </div>
                        <button class="fav-search-btn" type="submit"><i class="bi bi-search"></i> Tìm kiếm</button>
                    </div>
                </form>
            </div>

            <style>
            .fav-table-wrap { padding: 0; }
            .fav-empty { text-align: center; padding: 4rem 2rem; }
            .fav-empty-icon { width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, #fce7f3, #f3e8ff); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2.5rem; color: #a855f7; }
            .fav-empty h4 { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; }
            .fav-empty p { color: #64748b; margin: 0; }
            .fav-table { width: 100%; border-collapse: collapse; }
            .fav-table thead { background: linear-gradient(135deg, #f8fafc, #f1f5f9); }
            .fav-table th { padding: 1rem 1.5rem; text-align: left; font-weight: 600; font-size: 0.85rem; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
            .fav-table th i { margin-right: 0.5rem; color: #667eea; }
            .fav-table tbody tr { transition: all 0.3s ease; animation: fadeIn 0.4s ease-out backwards; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
            .fav-table tbody tr:hover { background: linear-gradient(135deg, #fafbff, #f5f7ff); }
            .fav-table td { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
            .fav-user { display: flex; align-items: center; gap: 1rem; }
            .fav-avatar { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); }
            .fav-user-details { display: flex; flex-direction: column; }
            .fav-user-name { font-weight: 600; color: #1e293b; font-size: 0.95rem; }
            .fav-user-email { font-size: 0.85rem; color: #64748b; }
            .fav-post { display: flex; flex-direction: column; gap: 0.5rem; }
            .fav-post-title { font-weight: 600; color: #1e293b; font-size: 0.95rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .fav-post-meta { display: flex; gap: 0.5rem; flex-wrap: wrap; }
            .fav-badge { padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
            .fav-badge.recruitment { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
            .fav-badge.application { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
            .fav-badge.open { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #15803d; }
            .fav-badge.closed { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #b91c1c; }
            .fav-view-link { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; color: #667eea; text-decoration: none; font-weight: 500; transition: all 0.3s ease; }
            .fav-view-link:hover { color: #4f46e5; text-decoration: underline; }
            .fav-date { display: flex; align-items: center; gap: 0.5rem; color: #64748b; font-size: 0.9rem; }
            .fav-date i { color: #94a3b8; }
            .fav-actions { display: flex; gap: 0.5rem; }
            .fav-btn { width: 40px; height: 40px; border-radius: 10px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease; font-size: 1rem; }
            .fav-btn.delete { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
            .fav-btn.delete:hover { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; transform: scale(1.1); box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4); }
            .fav-btn.hide { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
            .fav-btn.hide:hover { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; transform: scale(1.1); box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4); }
            @media (max-width: 991px) { .fav-filter-grid { grid-template-columns: 1fr 1fr; } }
            @media (max-width: 767px) { 
                .fav-page { padding: 1rem; } 
                .fav-header { flex-direction: column; align-items: flex-start; } 
                .fav-filter-grid { grid-template-columns: 1fr; } 
                .fav-search-btn { width: 100%; justify-content: center; } 
                .fav-card-header { flex-direction: column; gap: 1rem; align-items: flex-start; }
                .fav-table { display: block; overflow-x: auto; }
            }
            </style>

            <div class="fav-table-wrap">
                <?php if (empty($favorites)): ?>
                    <div class="fav-empty">
                        <div class="fav-empty-icon"><i class="bi bi-heart"></i></div>
                        <h4>Không có dữ liệu</h4>
                        <p>Không tìm thấy lượt yêu thích nào phù hợp với bộ lọc của bạn.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="fav-table">
                            <thead>
                                <tr>
                                    <th><i class="bi bi-person-circle"></i> Người dùng</th>
                                    <th><i class="bi bi-file-earmark-text"></i> Bài viết</th>
                                    <th><i class="bi bi-calendar3"></i> Ngày lưu</th>
                                    <th><i class="bi bi-gear"></i> Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($favorites as $index => $fav): ?>
                                    <tr style="animation-delay: <?php echo $index * 0.05; ?>s">
                                        <td>
                                            <div class="fav-user">
                                                <div class="fav-avatar"><?php echo strtoupper(substr($fav['user_name'] ?? 'U', 0, 1)); ?></div>
                                                <div class="fav-user-details">
                                                    <span class="fav-user-name"><?php echo htmlspecialchars($fav['user_name'] ?? ''); ?></span>
                                                    <span class="fav-user-email"><?php echo htmlspecialchars($fav['user_email'] ?? ''); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fav-post">
                                                <span class="fav-post-title"><?php echo htmlspecialchars($fav['post_title'] ?? ''); ?></span>
                                                <div class="fav-post-meta">
                                                    <span class="fav-badge <?php echo htmlspecialchars($fav['post_type'] ?? ''); ?>">
                                                        <?php echo $fav['post_type'] === 'recruitment' ? 'Tuyển dụng' : 'Ứng tuyển'; ?>
                                                    </span>
                                                    <span class="fav-badge <?php echo htmlspecialchars($fav['post_status'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($fav['post_status'] ?? ''); ?>
                                                    </span>
                                                </div>
                                                <a href="view_post.php?id=<?php echo (int)($fav['post_id'] ?? 0); ?>" class="fav-view-link" target="_blank">
                                                    <i class="bi bi-box-arrow-up-right"></i> Xem bài viết
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fav-date">
                                                <i class="bi bi-clock"></i>
                                                <span><?php echo !empty($fav['created_at']) ? date('d/m/Y H:i', strtotime($fav['created_at'])) : '-'; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fav-actions">
                                                <form method="post" class="d-inline" onsubmit="return confirm('Xóa lượt yêu thích này?');">
                                                    <input type="hidden" name="action" value="delete_favorite">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)($fav['user_id'] ?? 0); ?>">
                                                    <input type="hidden" name="post_id" value="<?php echo (int)($fav['post_id'] ?? 0); ?>">
                                                    <button class="fav-btn delete" title="Xóa"><i class="bi bi-trash3"></i></button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Ẩn bài viết này?');">
                                                    <input type="hidden" name="action" value="hide_post">
                                                    <input type="hidden" name="post_id" value="<?php echo (int)($fav['post_id'] ?? 0); ?>">
                                                    <button class="fav-btn hide" title="Ẩn bài viết"><i class="bi bi-eye-slash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
