<?php
require_once 'config.php';
require_admin();

$createType = trim($_GET['create'] ?? '');
if (!in_array($createType, ['recruitment', 'application'], true)) {
    $createType = '';
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_post_admin') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $type = ($_POST['type'] ?? 'general');
            $status = ($_POST['status'] ?? 'open');
            $category = trim($_POST['category'] ?? '');
            $area = trim($_POST['area'] ?? '');
            $contact_info = trim($_POST['contact_info'] ?? '');
            $student_fullname = trim($_POST['student_fullname'] ?? '');
            $student_code = trim($_POST['student_code'] ?? '');
            $student_class = trim($_POST['student_class'] ?? '');
            $recruiter_fullname = trim($_POST['recruiter_fullname'] ?? '');
            $suggested_price = (int)($_POST['suggested_price'] ?? 0) ?: null;

            if ($title === '' || $content === '') {
                throw new RuntimeException('Tiêu đề và nội dung không được để trống.');
            }

            $cardImagePath = null;
            if (!empty($_FILES['card_image']['name'])) {
                $targetDir = __DIR__ . '/uploads/posts';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['card_image']['name'], PATHINFO_EXTENSION) ?: 'jpg');
                $fileName = 'post_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                $targetPath = $targetDir . '/' . $fileName;
                if (!move_uploaded_file($_FILES['card_image']['tmp_name'], $targetPath)) {
                    throw new RuntimeException('Không thể lưu ảnh bài viết.');
                }
                $cardImagePath = 'uploads/posts/' . $fileName;
            }

            $authorId = $_SESSION['user_id'] ?? 0;
            $stmt = $pdo->prepare('INSERT INTO posts (user_id, title, content, type, category, area, contact_info, card_image, student_fullname, student_code, student_class, recruiter_fullname, suggested_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$authorId, $title, $content, $type, $category ?: null, $area ?: null, $contact_info ?: null, $cardImagePath, $student_fullname ?: null, $student_code ?: null, $student_class ?: null, $recruiter_fullname ?: null, $suggested_price, $status]);
            $success = 'Đã tạo bài viết mới.';
        }
        if ($action === 'delete_post_admin') {
            $postId = (int)($_POST['post_id'] ?? 0);
            if ($postId > 0) {
                $stmt = $pdo->prepare('DELETE FROM posts WHERE id = ?');
                $stmt->execute([$postId]);
                $success = 'Đã xóa bài viết.';
            }
        }
        elseif ($action === 'toggle_hide_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            if ($commentId > 0) {
                $pdo->prepare('UPDATE comments SET is_hidden = 1 - is_hidden WHERE id = ?')->execute([$commentId]);
                $success = 'Đã cập nhật trạng thái bình luận.';
            }
        } elseif ($action === 'delete_comment_admin') {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            if ($commentId > 0) {
                $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
                $stmt->execute([$commentId]);
                $success = 'Đã xóa bình luận.';
            }
        }
    } catch (Throwable $e) {
        error_log('admin_posts action error: ' . $e->getMessage());
        $error = $e->getMessage() ?: 'Có lỗi khi thực hiện thao tác.';
    }
}

// Fetch posts
$posts = [];
try {
    $where = [];
    $params = [];
    $filterType = trim($_GET['filter_type'] ?? '');
    if ($filterType !== '') { $where[] = 'p.type = ?'; $params[] = $filterType; }
    $filterStatus = trim($_GET['filter_status'] ?? '');
    if ($filterStatus !== '') { $where[] = 'p.status = ?'; $params[] = $filterStatus; }
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') { $where[] = '(p.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $params[] = $like; }
    $sql = 'SELECT p.*, u.name AS author_name, u.email AS author_email FROM posts p JOIN users u ON u.id = p.user_id';
    if (!empty($where)) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY p.created_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $posts = []; }

$totalPosts = count($posts);
$recruitmentCount = count(array_filter($posts, fn($p) => $p['type'] === 'recruitment'));
$applicationCount = count(array_filter($posts, fn($p) => $p['type'] === 'application'));

$recentComments = [];
try {
    $recentComments = $pdo->query('SELECT c.id, c.comment, c.is_hidden, c.created_at, p.title AS post_title, u.name AS author_name FROM comments c JOIN posts p ON p.id = c.post_id JOIN users u ON u.id = c.user_id ORDER BY c.created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentComments = []; }

// Xử lý embed mode (khi mở trong iframe)
$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Quản lý bài viết</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}.premium-navbar,.navbar,.site-header{display:none!important;}</style>';
    echo '</head><body>';
}
?>

<div class="posts-management-wrapper">
    <!-- Header -->
    <div class="posts-header">
        <div class="header-content">
            <div class="header-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div class="header-text">
                <h2>Quản lý bài viết</h2>
                <p>Quản lý tin tuyển dụng, ứng tuyển và bình luận</p>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($isEmbed): ?>
            <a href="admin_posts.php?embed=1" class="btn-header-action btn-back-header"><i class="bi bi-arrow-left"></i><span>Quay lại</span></a>
            <a href="admin_posts.php?create=recruitment&embed=1#create-post" class="btn-header-action btn-success-header"><i class="bi bi-megaphone-fill"></i><span>Đăng tin tuyển</span></a>
            <a href="admin_posts.php?create=application&embed=1#create-post" class="btn-header-action btn-primary-header"><i class="bi bi-person-badge-fill"></i><span>Đăng tin ứng tuyển</span></a>
            <?php else: ?>
            <a href="admin.php" class="btn-header-action btn-back-header"><i class="bi bi-arrow-left"></i><span>Quay lại</span></a>
            <a href="admin_posts.php?create=recruitment#create-post" class="btn-header-action btn-success-header"><i class="bi bi-megaphone-fill"></i><span>Đăng tin tuyển</span></a>
            <a href="admin_posts.php?create=application#create-post" class="btn-header-action btn-primary-header"><i class="bi bi-person-badge-fill"></i><span>Đăng tin ứng tuyển</span></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="posts-stats">
        <div class="stat-item"><div class="stat-icon blue"><i class="bi bi-file-earmark-text"></i></div><div class="stat-info"><span class="stat-value"><?php echo $totalPosts; ?></span><span class="stat-label">Tổng bài viết</span></div></div>
        <div class="stat-item"><div class="stat-icon green"><i class="bi bi-megaphone"></i></div><div class="stat-info"><span class="stat-value"><?php echo $recruitmentCount; ?></span><span class="stat-label">Tin tuyển dụng</span></div></div>
        <div class="stat-item"><div class="stat-icon purple"><i class="bi bi-person-badge"></i></div><div class="stat-info"><span class="stat-value"><?php echo $applicationCount; ?></span><span class="stat-label">Tin ứng tuyển</span></div></div>
        <div class="stat-item"><div class="stat-icon orange"><i class="bi bi-chat-dots"></i></div><div class="stat-info"><span class="stat-value"><?php echo count($recentComments); ?></span><span class="stat-label">Bình luận gần đây</span></div></div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?><div class="alert-custom alert-success-custom"><div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div><div class="alert-content"><?php echo htmlspecialchars($success); ?></div></div><?php endif; ?>
    <?php if ($error): ?><div class="alert-custom alert-danger-custom"><div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div><div class="alert-content"><?php echo htmlspecialchars($error); ?></div></div><?php endif; ?>

    <!-- Create Post Form -->
    <?php if ($createType): ?>
    <div class="create-post-card" id="create-post">
        <div class="create-post-header">
            <div class="create-post-icon <?php echo $createType === 'recruitment' ? 'green' : 'purple'; ?>">
                <i class="bi bi-<?php echo $createType === 'recruitment' ? 'megaphone-fill' : 'person-badge-fill'; ?>"></i>
            </div>
            <div>
                <h5><?php echo $createType === 'recruitment' ? 'Đăng Tin Tuyển Dụng' : 'Đăng Tin Ứng Tuyển'; ?></h5>
                <p>Điền thông tin bên dưới để tạo bài viết mới</p>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="create-post-form" action="admin_posts.php<?php echo $isEmbed ? '?embed=1' : ''; ?>">
            <input type="hidden" name="action" value="create_post_admin">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($createType); ?>">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label><i class="bi bi-type"></i> Tiêu đề *</label>
                    <input id="post-title" name="title" class="form-input" required placeholder="Nhập tiêu đề bài viết...">
                </div>
                <div class="form-group full-width">
                    <label><i class="bi bi-text-paragraph"></i> Mô tả *</label>
                    <textarea name="content" class="form-textarea" rows="5" required placeholder="Nhập nội dung chi tiết..."></textarea>
                </div>
                <?php if ($createType === 'application'): ?>
                <div class="form-group"><label><i class="bi bi-bookmark"></i> Kỹ năng / Chuyên ngành</label><input name="category" class="form-input" placeholder="Ví dụ: Nội khoa, Chăm sóc người cao tuổi"></div>
                <div class="form-group"><label><i class="bi bi-geo-alt"></i> Khu vực ưu tiên</label><input name="area" class="form-input" placeholder="Ví dụ: Quận 1, TP.HCM"></div>
                <div class="form-group"><label><i class="bi bi-currency-dollar"></i> Gợi ý giá (VND)</label><input name="suggested_price" class="form-input" placeholder="Ví dụ: 22700"></div>
                <div class="form-group"><label><i class="bi bi-telephone"></i> Thông tin liên hệ</label><input name="contact_info" class="form-input" placeholder="Số điện thoại hoặc email"></div>
                <div class="form-group full-width"><label><i class="bi bi-image"></i> Ảnh minh chứng (tùy chọn)</label><input type="file" name="card_image" class="form-input"></div>
                <?php else: ?>
                <div class="form-group"><label><i class="bi bi-bookmark"></i> Chuyên khoa / Loại</label><input name="category" class="form-input" placeholder="Ví dụ: Chăm sóc người cao tuổi"></div>
                <div class="form-group"><label><i class="bi bi-geo-alt"></i> Địa chỉ / Khu vực</label><input name="area" class="form-input" placeholder="Địa chỉ cụ thể"></div>
                <div class="form-group"><label><i class="bi bi-telephone"></i> Liên hệ</label><input name="contact_info" class="form-input" placeholder="Số điện thoại hoặc email"></div>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <a href="admin_posts.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="btn-cancel"><i class="bi bi-x-lg"></i> Hủy</a>
                <button type="submit" class="btn-submit"><i class="bi bi-send-fill"></i> Đăng tin</button>
            </div>
        </form>
    </div>
    <script>window.addEventListener('DOMContentLoaded', function(){ var el = document.getElementById('create-post'); if (el) { el.scrollIntoView({behavior: 'smooth', block: 'center'}); var t = document.getElementById('post-title'); if (t) t.focus(); } });</script>
    <?php endif; ?>

    <!-- Filter & Search -->
    <div class="posts-toolbar">
        <form method="get" class="filter-form">
            <?php if ($isEmbed): ?>
            <input type="hidden" name="embed" value="1">
            <?php endif; ?>
            <div class="filter-group">
                <select name="filter_type" class="filter-select">
                    <option value="">Tất cả loại</option>
                    <option value="recruitment" <?php echo ($filterType === 'recruitment') ? 'selected' : ''; ?>>Tuyển dụng</option>
                    <option value="application" <?php echo ($filterType === 'application') ? 'selected' : ''; ?>>Ứng tuyển</option>
                    <option value="general" <?php echo ($filterType === 'general') ? 'selected' : ''; ?>>Chung</option>
                </select>
                <select name="filter_status" class="filter-select">
                    <option value="">Tất cả trạng thái</option>
                    <option value="open" <?php echo ($filterStatus === 'open') ? 'selected' : ''; ?>>Đang mở</option>
                    <option value="closed" <?php echo ($filterStatus === 'closed') ? 'selected' : ''; ?>>Đã đóng</option>
                    <option value="draft" <?php echo ($filterStatus === 'draft') ? 'selected' : ''; ?>>Nháp</option>
                </select>
            </div>
            <div class="search-group">
                <i class="bi bi-search"></i>
                <input type="search" name="q" placeholder="Tìm tiêu đề hoặc tác giả..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Lọc</button>
                <a href="admin_posts.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Đặt lại</a>
            </div>
        </form>
    </div>

    <!-- Posts Table -->
    <div class="posts-table-card">
        <div class="table-header">
            <h5><i class="bi bi-file-earmark-text"></i> Danh sách bài viết</h5>
            <span class="badge-count"><?php echo $totalPosts; ?> bài</span>
        </div>
        <?php if (empty($posts)): ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><p>Chưa có bài viết nào</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="posts-table">
                <thead>
                    <tr>
                        <th>Bài viết</th>
                        <th>Loại</th>
                        <th>Trạng thái</th>
                        <th>Ngày tạo</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): 
                        $typeLabel = $p['type'] === 'recruitment' ? 'Tuyển dụng' : ($p['type'] === 'application' ? 'Ứng tuyển' : 'Chung');
                        $typeClass = $p['type'] === 'recruitment' ? 'recruitment' : ($p['type'] === 'application' ? 'application' : 'general');
                        $statusLabel = $p['status'] === 'open' ? 'Đang mở' : ($p['status'] === 'closed' ? 'Đã đóng' : 'Nháp');
                        $statusClass = $p['status'] === 'open' ? 'open' : ($p['status'] === 'closed' ? 'closed' : 'draft');
                    ?>
                    <tr>
                        <td>
                            <div class="post-info">
                                <span class="post-title"><?php echo htmlspecialchars($p['title']); ?></span>
                                <span class="post-author"><i class="bi bi-person"></i> <?php echo htmlspecialchars($p['author_name']); ?> <span class="post-email">(<?php echo htmlspecialchars($p['author_email']); ?>)</span></span>
                            </div>
                        </td>
                        <td><span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                        <td><span class="date-text"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?><br><small><?php echo date('H:i', strtotime($p['created_at'])); ?></small></span></td>
                        <td>
                            <div class="action-buttons">
                                <a href="view_post.php?id=<?php echo (int)$p['id']; ?>" class="action-btn info" title="Xem"><i class="bi bi-eye"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Xác nhận xóa bài viết này?');">
                                    <input type="hidden" name="action" value="delete_post_admin">
                                    <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="action-btn danger" title="Xóa"><i class="bi bi-trash"></i></button>
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

    <!-- Comments Section -->
    <div class="comments-card">
        <div class="table-header">
            <h5><i class="bi bi-chat-dots"></i> Bình luận gần đây</h5>
            <span class="badge-count"><?php echo count($recentComments); ?> bình luận</span>
        </div>
        <?php if (empty($recentComments)): ?>
            <div class="empty-state"><i class="bi bi-chat"></i><p>Chưa có bình luận nào</p></div>
        <?php else: ?>
        <div class="comments-list">
            <?php foreach ($recentComments as $c): ?>
            <div class="comment-item <?php echo !empty($c['is_hidden']) ? 'hidden-comment' : ''; ?>">
                <div class="comment-content">
                    <div class="comment-text"><?php echo htmlspecialchars($c['comment']); ?></div>
                    <div class="comment-meta">
                        <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($c['author_name']); ?></span>
                        <span><i class="bi bi-file-text"></i> <?php echo htmlspecialchars($c['post_title']); ?></span>
                        <span><i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($c['created_at'])); ?></span>
                        <?php if (!empty($c['is_hidden'])): ?><span class="status-hidden"><i class="bi bi-eye-slash"></i> Đã ẩn</span><?php endif; ?>
                    </div>
                </div>
                <div class="comment-actions">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="toggle_hide_comment">
                        <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="action-btn <?php echo !empty($c['is_hidden']) ? 'success' : 'warning'; ?>" title="<?php echo !empty($c['is_hidden']) ? 'Hiện' : 'Ẩn'; ?>">
                            <i class="bi bi-<?php echo !empty($c['is_hidden']) ? 'eye' : 'eye-slash'; ?>"></i>
                        </button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Xóa bình luận này?');">
                        <input type="hidden" name="action" value="delete_comment_admin">
                        <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="action-btn danger" title="Xóa"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.posts-management-wrapper { max-width: 1400px; margin: 0 auto; }

/* Header */
.posts-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding: 1.5rem 2rem; background: linear-gradient(135deg, #0b3f91 0%, #062a63 100%); border-radius: 24px; box-shadow: 0 20px 50px rgba(11, 63, 145, 0.3); flex-wrap: wrap; gap: 1rem; }
.posts-header .header-content { display: flex; align-items: center; gap: 1.25rem; }
.posts-header .header-icon { width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #fff; }
.posts-header .header-text h2 { margin: 0; color: #fff; font-size: 1.75rem; font-weight: 700; }
.posts-header .header-text p { margin: 0.25rem 0 0; color: rgba(255,255,255,0.8); font-size: 0.95rem; }
.header-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.btn-header-action { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem; border-radius: 12px; text-decoration: none; font-weight: 500; transition: all 0.3s ease; font-size: 0.9rem; }
.btn-back-header { background: rgba(255,255,255,0.15); color: #fff !important; border: 1px solid rgba(255,255,255,0.2); }
.btn-back-header:hover { background: rgba(255,255,255,0.25); }
.btn-success-header { background: #10b981; color: #fff !important; }
.btn-success-header:hover { background: #059669; transform: translateY(-2px); }
.btn-primary-header { background: #fff; color: #0b3f91 !important; }
.btn-primary-header:hover { background: #f0f4ff; transform: translateY(-2px); }

/* Stats */
.posts-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-item { display: flex; align-items: center; gap: 1rem; padding: 1.25rem 1.5rem; background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
.stat-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
.stat-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.stat-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.stat-icon.purple { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
.stat-icon.orange { background: linear-gradient(135deg, #ffedd5, #fed7aa); color: #ea580c; }
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
.stat-label { font-size: 0.85rem; color: #64748b; }

/* Alerts */
.alert-custom { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; }
.alert-success-custom { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; color: #065f46; }
.alert-danger-custom { background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #fca5a5; color: #991b1b; }
.alert-icon { font-size: 1.5rem; }
.alert-success-custom .alert-icon { color: #059669; }
.alert-danger-custom .alert-icon { color: #dc2626; }

/* Create Post Card */
.create-post-card { background: #fff; border-radius: 20px; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 2px solid #e2e8f0; }
.create-post-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.create-post-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
.create-post-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.create-post-icon.purple { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
.create-post-header h5 { margin: 0; font-weight: 600; color: #1e293b; }
.create-post-header p { margin: 0; font-size: 0.85rem; color: #64748b; }
.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
.form-group { display: flex; flex-direction: column; gap: 0.5rem; }
.form-group.full-width { grid-column: span 2; }
.form-group label { font-weight: 500; color: #475569; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; }
.form-input, .form-textarea { padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s ease; }
.form-input:focus, .form-textarea:focus { outline: none; border-color: #0b3f91; box-shadow: 0 0 0 4px rgba(11,63,145,0.1); }
.form-textarea { resize: vertical; min-height: 120px; }
.form-actions { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
.btn-cancel, .btn-submit { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.875rem 1.5rem; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; border: none; }
.btn-cancel { background: #f1f5f9; color: #64748b; }
.btn-cancel:hover { background: #e2e8f0; color: #475569; }
.btn-submit { background: linear-gradient(135deg, #0b3f91, #062a63); color: #fff; box-shadow: 0 10px 30px rgba(11,63,145,0.3); }
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(11,63,145,0.4); }
</style>

<style>
/* Toolbar */
.posts-toolbar { margin-bottom: 1.5rem; }
.filter-form { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; background: #fff; padding: 1rem 1.5rem; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
.filter-group { display: flex; gap: 0.75rem; }
.filter-select { padding: 0.625rem 1rem; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; color: #475569; font-size: 0.9rem; cursor: pointer; }
.filter-select:focus { outline: none; border-color: #0b3f91; }
.search-group { flex: 1; min-width: 200px; position: relative; }
.search-group i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.search-group input { width: 100%; padding: 0.625rem 1rem 0.625rem 2.5rem; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; }
.search-group input:focus { outline: none; border-color: #0b3f91; }
.filter-actions { display: flex; gap: 0.5rem; }
.btn-filter, .btn-reset { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.625rem 1rem; border-radius: 10px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; border: none; font-size: 0.9rem; }
.btn-filter { background: #0b3f91; color: #fff; }
.btn-filter:hover { background: #062a63; }
.btn-reset { background: #f1f5f9; color: #64748b; }
.btn-reset:hover { background: #e2e8f0; color: #475569; }

/* Table Card */
.posts-table-card, .comments-card { background: #fff; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); margin-bottom: 1.5rem; }
.table-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; }
.table-header h5 { margin: 0; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 0.5rem; }
.badge-count { background: #e2e8f0; color: #475569; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
.empty-state { padding: 3rem; text-align: center; color: #94a3b8; }
.empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; }
.posts-table { width: 100%; border-collapse: collapse; }
.posts-table thead th { padding: 1rem 1.25rem; text-align: left; font-weight: 600; color: #475569; background: #f8fafc; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
.posts-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.2s ease; }
.posts-table tbody tr:hover { background: #f8fafc; }
.posts-table tbody td { padding: 1rem 1.25rem; vertical-align: middle; }
.post-info { display: flex; flex-direction: column; gap: 0.25rem; }
.post-title { font-weight: 600; color: #1e293b; }
.post-author { font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 0.375rem; }
.post-email { color: #94a3b8; }
.type-badge, .status-badge { display: inline-flex; align-items: center; padding: 0.375rem 0.75rem; border-radius: 8px; font-size: 0.8rem; font-weight: 500; }
.type-badge.recruitment { background: #d1fae5; color: #059669; }
.type-badge.application { background: #ede9fe; color: #7c3aed; }
.type-badge.general { background: #e2e8f0; color: #475569; }
.status-badge.open { background: #dbeafe; color: #1d4ed8; }
.status-badge.closed { background: #fee2e2; color: #dc2626; }
.status-badge.draft { background: #fef3c7; color: #d97706; }
.date-text { font-size: 0.85rem; color: #475569; }
.date-text small { color: #94a3b8; }

/* Action Buttons */
.action-buttons { display: flex; gap: 0.375rem; }
.action-btn { width: 34px; height: 34px; border-radius: 8px; border: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease; font-size: 0.9rem; text-decoration: none; }
.action-btn.info { background: #e0f2fe; color: #0284c7; }
.action-btn.info:hover { background: #0284c7; color: #fff; }
.action-btn.success { background: #d1fae5; color: #059669; }
.action-btn.success:hover { background: #059669; color: #fff; }
.action-btn.warning { background: #fef3c7; color: #d97706; }
.action-btn.warning:hover { background: #d97706; color: #fff; }
.action-btn.danger { background: #fee2e2; color: #dc2626; }
.action-btn.danger:hover { background: #dc2626; color: #fff; }

/* Comments */
.comments-list { padding: 0.5rem; }
.comment-item { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 0.5rem; background: #f8fafc; transition: all 0.2s ease; }
.comment-item:hover { background: #f1f5f9; }
.comment-item.hidden-comment { opacity: 0.6; background: #fef2f2; }
.comment-content { flex: 1; }
.comment-text { color: #1e293b; margin-bottom: 0.5rem; line-height: 1.5; }
.comment-meta { display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.8rem; color: #64748b; }
.comment-meta span { display: flex; align-items: center; gap: 0.25rem; }
.status-hidden { color: #dc2626; }
.comment-actions { display: flex; gap: 0.375rem; }

/* Responsive */
@media (max-width: 992px) {
    .posts-header { flex-direction: column; text-align: center; }
    .posts-header .header-content { flex-direction: column; }
    .header-actions { width: 100%; justify-content: center; }
    .filter-form { flex-direction: column; }
    .filter-group, .search-group, .filter-actions { width: 100%; }
}
@media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full-width { grid-column: span 1; }
    .posts-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
