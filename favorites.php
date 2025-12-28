<?php
require_once 'config.php';
require_login();

if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'patient';
$dashboardLink = $userRole === 'student' ? 'dashboard_student.php' : 'dashboard_patient.php';
$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

$favorites = [];
$errorMessage = null;
try {
    $stmt = $pdo->prepare('SELECT p.*, f.created_at AS favorited_at, u.name AS author_name, u.email AS author_email, u.role AS author_role '
        . 'FROM favorites f '
        . 'JOIN posts p ON p.id = f.post_id '
        . 'JOIN users u ON u.id = p.user_id '
        . 'WHERE f.user_id = ? '
        . 'ORDER BY f.created_at DESC');
    $stmt->execute([$userId]);
    $favorites = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Không thể tải danh sách yêu thích. Vui lòng thử lại sau.';
    error_log('favorites.php load error: ' . $e->getMessage());
}

if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Yêu thích</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:1rem;}</style>';
    echo '</head><body>';
}
?>
<style>
.favorites-hero {
    background: linear-gradient(135deg, #ec4899 0%, #f43f5e 50%, #fb7185 100%);
    border-radius: 28px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(236, 72, 153, 0.3);
}
.favorites-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
    pointer-events: none;
}
.favorites-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
}
.favorites-hero-left {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}
.favorites-hero-icon {
    width: 72px;
    height: 72px;
    background: rgba(255,255,255,0.2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    border: 2px solid rgba(255,255,255,0.3);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.favorites-hero-text h2 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
}
.favorites-hero-text p {
    color: rgba(255,255,255,0.9);
    margin: 0;
    font-size: 0.95rem;
}
.favorites-hero-count {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.2);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    color: #fff;
    font-weight: 600;
    font-size: 0.9rem;
    margin-top: 0.75rem;
}
.favorites-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.fav-btn {
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
}
.fav-btn-primary {
    background: #fff;
    color: #db2777;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.fav-btn-primary:hover {
    background: #fdf2f8;
    transform: translateY(-3px);
    color: #db2777;
    text-decoration: none;
}
.fav-btn-outline {
    background: transparent;
    color: #fff;
    border: 2px solid rgba(255,255,255,0.4);
}
.fav-btn-outline:hover {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.6);
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
}

/* Empty State */
.favorites-empty {
    background: linear-gradient(145deg, #ffffff 0%, #fdf2f8 100%);
    border-radius: 28px;
    padding: 4rem 2rem;
    text-align: center;
    box-shadow: 0 10px 40px rgba(236, 72, 153, 0.1);
    border: 1px solid rgba(236, 72, 153, 0.1);
}
.favorites-empty-icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, #fce7f3, #fbcfe8);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    margin: 0 auto 2rem;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
.favorites-empty h4 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.75rem;
}
.favorites-empty p {
    color: #64748b;
    font-size: 1rem;
    max-width: 400px;
    margin: 0 auto 2rem;
    line-height: 1.7;
}
.favorites-empty .btn-explore {
    background: linear-gradient(135deg, #ec4899, #db2777);
    color: #fff;
    padding: 0.9rem 2rem;
    border-radius: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: 0 8px 24px rgba(236, 72, 153, 0.3);
}
.favorites-empty .btn-explore:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(236, 72, 153, 0.4);
    color: #fff;
}

/* Favorites Table */
.favorites-card {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
}
.favorites-table {
    width: 100%;
    border-collapse: collapse;
}
.favorites-table thead th {
    background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);
    padding: 1rem 1.25rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: #be185d;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #fbcfe8;
}
.favorites-table tbody tr {
    transition: all 0.3s ease;
}
.favorites-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.04) 0%, rgba(244, 63, 94, 0.04) 100%);
}
.favorites-table tbody td {
    padding: 1.25rem;
    border-bottom: 1px solid #fce7f3;
    vertical-align: middle;
}
.favorites-table .post-title {
    font-weight: 600;
    color: #1e293b;
    max-width: 250px;
}
.favorites-table .author-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.favorites-table .author-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #fce7f3, #fbcfe8);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.favorites-table .author-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.9rem;
}
.favorites-table .author-email {
    color: #64748b;
    font-size: 0.8rem;
}
.favorites-table .category-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.85rem;
    background: linear-gradient(135deg, #fce7f3, #fbcfe8);
    color: #be185d;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.favorites-table .status-badge {
    padding: 0.45rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.favorites-table .status-open {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #047857;
}
.favorites-table .status-closed {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    color: #475569;
}
.favorites-table .status-taken {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.favorites-table .status-inactive {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #b45309;
}
.favorites-table .btn-detail {
    background: linear-gradient(135deg, #ec4899, #db2777);
    color: #fff;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.favorites-table .btn-detail:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(236, 72, 153, 0.3);
    color: #fff;
}
@media (max-width: 992px) {
    .favorites-hero { padding: 1.75rem; }
    .favorites-hero-content { flex-direction: column; align-items: flex-start; }
    .favorites-table thead { display: none; }
    .favorites-table tbody tr {
        display: block;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid #fce7f3;
        border-radius: 16px;
        background: #fff;
    }
    .favorites-table tbody td {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid #fdf2f8;
    }
    .favorites-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #be185d;
        font-size: 0.85rem;
    }
    .favorites-table tbody td:last-child { border-bottom: none; }
}
</style>

<div class="favorites-hero">
    <div class="favorites-hero-content">
        <div class="favorites-hero-left">
            <div class="favorites-hero-icon">💖</div>
            <div class="favorites-hero-text">
                <h2>Bài tuyển yêu thích</h2>
                <p>Danh sách các bài đăng bạn đã lưu để xem lại</p>
                <div class="favorites-hero-count">📚 <?php echo count($favorites); ?> bài viết đã lưu</div>
            </div>
        </div>
        <div class="favorites-hero-actions">
            <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="fav-btn fav-btn-outline">← Bảng điều khiển</a>
            <a href="index.php?type=recruitment#posts" class="fav-btn fav-btn-primary">🔍 Tìm thêm bài tuyển</a>
        </div>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger" style="border-radius: 16px;"><?php echo htmlspecialchars($errorMessage); ?></div>
<?php elseif (!$favorites): ?>
    <div class="favorites-empty">
        <div class="favorites-empty-icon">💝</div>
        <h4>Bạn chưa lưu bài tuyển dụng nào</h4>
        <p>Khi xem bài tuyển, hãy nhấn nút "Yêu thích" để lưu lại và truy cập nhanh tại đây.</p>
        <a href="index.php?type=recruitment#posts" class="btn-explore">🔍 Khám phá bài tuyển</a>
    </div>
<?php else: ?>
    <div class="favorites-card">
        <div class="table-responsive">
            <table class="favorites-table">
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Người đăng</th>
                        <th>Khu vực</th>
                        <th>Chuyên ngành</th>
                        <th>Ngày lưu</th>
                        <th>Trạng thái</th>
                        <th style="text-align: right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($favorites as $fav): ?>
                        <tr>
                            <td data-label="Tiêu đề"><span class="post-title"><?php echo htmlspecialchars($fav['title']); ?></span></td>
                            <td data-label="Người đăng">
                                <div class="author-info">
                                    <div class="author-avatar">👤</div>
                                    <div>
                                        <div class="author-name"><?php echo htmlspecialchars($fav['author_name'] ?? ''); ?></div>
                                        <div class="author-email"><?php echo htmlspecialchars($fav['author_email'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Khu vực">📍 <?php echo htmlspecialchars($fav['area'] ?? '—'); ?></td>
                            <td data-label="Chuyên ngành"><span class="category-badge">🏥 <?php echo htmlspecialchars($fav['category'] ?? '—'); ?></span></td>
                            <td data-label="Ngày lưu">🗓 <?php echo !empty($fav['favorited_at']) ? date('d/m/Y H:i', strtotime($fav['favorited_at'])) : '—'; ?></td>
                            <td data-label="Trạng thái">
                                <?php $status = $fav['status'] ?? 'open'; ?>
                                <?php if ($status === 'closed'): ?>
                                    <span class="status-badge status-closed">🔒 Đã đóng</span>
                                <?php elseif ($status === 'taken'): ?>
                                    <span class="status-badge status-taken">✅ Đã nhận việc</span>
                                <?php elseif ($status === 'inactive'): ?>
                                    <span class="status-badge status-inactive">⏸ Tạm ẩn</span>
                                <?php else: ?>
                                    <span class="status-badge status-open">🟢 Đang mở</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Thao tác" style="text-align: right;">
                                <a class="btn-detail" href="view_post.php?id=<?php echo (int)($fav['id']); ?>">👁 Xem chi tiết</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
