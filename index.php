<?php
require_once 'config.php';

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

if (!$isEmbed) {
    require_once 'header.php';
} else {
    // Embed mode - minimal HTML
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Danh sách tin</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<link rel="stylesheet" href="assets/css/style.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:1rem;}.hero-section,.site-footer{display:none !important;}</style>';
    echo '</head><body>';
}

// Build search/filter query
$where = [];
$params = [];
if (!empty($_GET['q'])) {
        $where[] = '(p.title LIKE ? OR p.content LIKE ?)';
        $params[] = '%'.$_GET['q'].'%';
        $params[] = '%'.$_GET['q'].'%';
}
if (!empty($_GET['type'])) {
        $where[] = 'p.type = ?';
        $params[] = $_GET['type'];
}
if (!empty($_GET['category'])) {
        $where[] = 'p.category = ?';
        $params[] = $_GET['category'];
}
if (!empty($_GET['area'])) {
        $where[] = 'p.area LIKE ?';
        $params[] = '%'.$_GET['area'].'%';
}

$sql = 'SELECT p.*, 
    COALESCE(u.name, u.username, u.full_name) AS author_name, 
    u.verified AS author_verified, 
    u.last_activity AS author_last_activity 
FROM posts p 
JOIN users u ON p.user_id = u.id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY p.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$isAdmin = is_admin_user();
$primaryAction = 'register.php';
$primaryText = 'Đăng ký miễn phí';
$secondaryAction = 'login.php';
$secondaryText = 'Đăng nhập';
if (is_logged_in() && !$isAdmin) {
    if ($_SESSION['role'] === 'patient') {
        $primaryAction = 'create_recruitment.php';
        $primaryText = 'Đăng tin tìm hỗ trợ';
        $secondaryAction = 'dashboard_patient.php';
        $secondaryText = 'Xem dashboard';
    } else {
        $primaryAction = 'create_application.php';
        $primaryText = 'Đăng tin ứng tuyển';
        $secondaryAction = 'dashboard_student.php';
        $secondaryText = 'Xem dashboard';
    }
}

$visiblePosts = count($posts);

if (!function_exists('encode_relative_url_path')) {
    function encode_relative_url_path(string $path): string {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);
        $encoded = array_map('rawurlencode', array_filter($segments, 'strlen'));
        return implode('/', $encoded);
    }
}

// Determine hero image (prefer curated asset, fallback to gallery folder)
$defaultHeroRelative = 'assets/img/hero-home.png';
$heroImageRelative = $defaultHeroRelative;
$defaultHeroPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $defaultHeroRelative);
if (!file_exists($defaultHeroPath)) {
    $galleryDir = __DIR__ . DIRECTORY_SEPARATOR . 'Ảnh Giao diện';
    if (is_dir($galleryDir)) {
        $glob = glob($galleryDir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
        if ($glob) {
            $heroImageRelative = str_replace('\\', '/', substr($glob[0], strlen(__DIR__) + 1));
        }
    }
}
$heroImageSrc = encode_relative_url_path($heroImageRelative);
?>

<section class="homepage-hero my-4">
    <div class="row g-4 align-items-center">
        <div class="col-lg-6">
            <span class="hero-badge">🔍 Kết nối nhanh chóng</span>
            <h1 class="hero-title">Kết Nối Y Tế Giữa Bệnh Nhân Và Sinh Viên Y Khoa</h1>
            <p class="hero-subtitle">Tạo cầu nối an toàn để tìm kiếm sự hỗ trợ chăm sóc tại nhà hoặc cơ hội thực hành lâm sàng chỉ trong vài phút.</p>

            <div class="hero-actions">
                <?php if (!$isAdmin): ?>
                    <a class="btn btn-light btn-lg" href="<?php echo $primaryAction; ?>"><?php echo htmlspecialchars($primaryText); ?></a>
                    <a class="btn btn-outline-light btn-lg" href="<?php echo $secondaryAction; ?>"><?php echo htmlspecialchars($secondaryText); ?></a>
                <?php endif; ?>
                <a class="btn btn-outline-light" href="#posts">Xem tin mới nhất</a>
            </div>

            <div class="hero-stats">
                <div class="stat-bubble">
                    <strong><?php echo number_format($visiblePosts); ?></strong>
                    <span>Tin đang hiển thị</span>
                </div>
                <div class="stat-bubble">
                    <strong>24/7</strong>
                    <span>Hỗ trợ trực tuyến</span>
                </div>
                <div class="stat-bubble">
                    <strong>3 bước</strong>
                    <span>Hoàn tất kết nối</span>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="hero-illustration">
                <img class="hero-main-image" src="<?php echo htmlspecialchars($heroImageSrc); ?>" alt="Nhân viên y tế hỗ trợ bệnh nhân" loading="lazy">
                <div class="hero-floating-stack">
                    <div class="hero-floating-card card-schedule">
                        <div class="icon">📅</div>
                        <div>
                            <strong>30+ lịch hẹn</strong>
                            <small class="meta">Đặt mỗi tuần</small>
                        </div>
                    </div>
                    <div class="hero-floating-card card-rating">
                        <div>
                            <div class="rating-stars">★★★★★</div>
                            <strong>4.9/5</strong>
                            <small class="meta">Từ cộng đồng bệnh nhân</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="steps-section pt-0">
    <div class="steps-shell">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <h2 class="fw-bold m-0">Cách Thức Hoạt Động</h2>
        </div>
        <div class="steps-grid">
            <article class="step-pill">
                <div class="step-pill__index">1</div>
                <div>
                    <h5>Đăng Ký Tài Khoản</h5>
                    <p>Tạo tài khoản miễn phí với email thường hoặc email sinh viên (@xxx.edu.vn).</p>
                </div>
            </article>
            <article class="step-pill">
                <div class="step-pill__index">2</div>
                <div>
                    <h5>Đăng Tin</h5>
                    <p>Bệnh nhân đăng tin tuyển dụng chăm sóc; sinh viên đăng tin ứng tuyển thực hành.</p>
                </div>
            </article>
            <article class="step-pill">
                <div class="step-pill__index">3</div>
                <div>
                    <h5>Kết Nối</h5>
                    <p>Xem, tìm kiếm và liên hệ với đối tác phù hợp qua tin nhắn an toàn.</p>
                </div>
            </article>
            <article class="step-pill">
                <div class="step-pill__index">4</div>
                <div>
                    <h5>Bắt Đầu Hợp Tác</h5>
                    <p>Thỏa thuận chi tiết và bắt đầu dịch vụ chăm sóc hoặc thực hành lâm sàng.</p>
                </div>
            </article>
        </div>
    </div>
</section>

<style>
/* Posts Section Styles */
.posts-section { margin-top: 3rem; }
.posts-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.posts-header h2 { font-size: 2rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 0.75rem; }
.posts-header h2 i { color: #3b82f6; }
.posts-count { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; padding: 0.5rem 1.25rem; border-radius: 25px; font-weight: 600; font-size: 0.9rem; }

/* Search Box */
.posts-search { background: #fff; border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 40px rgba(0,0,0,0.08); margin-bottom: 2rem; border: 1px solid #e2e8f0; }
.posts-search-grid { display: grid; grid-template-columns: 2fr 1fr 1.5fr 1.5fr auto; gap: 1rem; align-items: end; }
.posts-search-group { position: relative; }
.posts-search-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
.posts-search-group .search-icon { position: absolute; left: 1rem; bottom: 0.85rem; color: #94a3b8; }
.posts-search-group input, .posts-search-group select { width: 100%; padding: 0.85rem 1rem 0.85rem 2.75rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8fafc; }
.posts-search-group input:focus, .posts-search-group select:focus { outline: none; border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
.posts-search-btn { padding: 0.85rem 2rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.35); display: flex; align-items: center; gap: 0.5rem; white-space: nowrap; }
.posts-search-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(59, 130, 246, 0.45); }

/* Posts Grid */
.posts-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 1.5rem; }
.post-card-new { background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; }
.post-card-new::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: linear-gradient(90deg, #3b82f6, #1d4ed8); opacity: 0; transition: opacity 0.3s ease; }
.post-card-new:hover { transform: translateY(-8px); box-shadow: 0 25px 60px rgba(59, 130, 246, 0.15); }
.post-card-new:hover::before { opacity: 1; }
.post-card-body { padding: 1.5rem; }
.post-card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
.post-card-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.post-card-title a { color: inherit; text-decoration: none; transition: color 0.3s ease; }
.post-card-title a:hover { color: #3b82f6; }
.post-card-status { padding: 0.35rem 0.85rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; white-space: nowrap; }
.post-card-status.open { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
.post-card-status.inactive { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309; }
.post-card-status.closed { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); color: #64748b; }
.post-card-meta { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; font-size: 0.85rem; color: #64748b; }
.post-card-meta-item { display: flex; align-items: center; gap: 0.35rem; }
.post-card-meta-item i { color: #94a3b8; }
.post-card-meta .verified { color: #10b981; }
.post-card-meta-item.online-indicator { font-size: 0.8rem; font-weight: 500; padding: 0.2rem 0.6rem; border-radius: 20px; }
.post-card-meta-item.online-indicator.online { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.post-card-meta-item.online-indicator.online i { color: #22c55e; font-size: 0.5rem; animation: pulse-dot 2s infinite; }
.post-card-meta-item.online-indicator.offline { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
.post-card-meta-item.online-indicator.offline i { color: #ef4444; font-size: 0.5rem; }
@keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.post-card-tags { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
.post-card-tag { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.post-card-tag.type-recruitment { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.post-card-tag.type-application { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
.post-card-tag.area { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; }
.post-card-tag.category { background: linear-gradient(135deg, #f3e8ff, #e9d5ff); color: #7c3aed; }
.post-card-content { color: #475569; font-size: 0.95rem; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 1.25rem; }
.post-card-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
.post-card-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.25rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border-radius: 10px; font-weight: 600; font-size: 0.85rem; text-decoration: none; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.25); }
.post-card-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35); color: #fff; }
.post-card-date { font-size: 0.8rem; color: #94a3b8; display: flex; align-items: center; gap: 0.35rem; }

/* Empty State */
.posts-empty { text-align: center; padding: 4rem 2rem; background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.06); }
.posts-empty-icon { width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, #dbeafe, #bfdbfe); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2.5rem; color: #3b82f6; }
.posts-empty h4 { font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; }
.posts-empty p { color: #64748b; margin: 0; }

@media (max-width: 991px) { .posts-search-grid { grid-template-columns: 1fr 1fr; } .posts-search-btn { width: 100%; justify-content: center; } }
@media (max-width: 767px) { .posts-search-grid { grid-template-columns: 1fr; } .posts-grid { grid-template-columns: 1fr; } .posts-header { flex-direction: column; align-items: flex-start; gap: 1rem; } }
</style>

<section id="posts" class="posts-section">
    <div class="posts-header">
        <h2><i class="bi bi-newspaper"></i> Tin mới nhất</h2>
        <span class="posts-count"><?php echo count($posts); ?> bài đăng</span>
    </div>

    <div class="posts-search">
        <form method="get">
            <div class="posts-search-grid">
                <div class="posts-search-group">
                    <label>Tìm kiếm</label>
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" placeholder="Tìm theo tiêu đề hoặc nội dung...">
                </div>
                <div class="posts-search-group">
                    <label>Loại tin</label>
                    <i class="bi bi-filter search-icon"></i>
                    <select name="type">
                        <option value="">Tất cả loại</option>
                        <option value="recruitment" <?php if(($_GET['type'] ?? '')=='recruitment') echo 'selected'; ?>>Tuyển dụng</option>
                        <option value="application" <?php if(($_GET['type'] ?? '')=='application') echo 'selected'; ?>>Ứng tuyển</option>
                    </select>
                </div>
                <div class="posts-search-group">
                    <label>Khu vực</label>
                    <i class="bi bi-geo-alt search-icon"></i>
                    <input type="text" name="area" placeholder="Nhập khu vực..." value="<?php echo htmlspecialchars($_GET['area'] ?? ''); ?>">
                </div>
                <div class="posts-search-group">
                    <label>Chuyên khoa</label>
                    <i class="bi bi-tag search-icon"></i>
                    <input type="text" name="category" placeholder="Chuyên khoa / Loại" value="<?php echo htmlspecialchars($_GET['category'] ?? ''); ?>">
                </div>
                <button type="submit" class="posts-search-btn"><i class="bi bi-search"></i> Tìm kiếm</button>
            </div>
        </form>
    </div>

    <?php if (!$posts): ?>
        <div class="posts-empty">
            <div class="posts-empty-icon"><i class="bi bi-inbox"></i></div>
            <h4>Chưa có tin nào</h4>
            <p>Hãy là người đầu tiên đăng tin trên hệ thống!</p>
        </div>
    <?php else: ?>
        <div class="posts-grid">
            <?php foreach ($posts as $p): ?>
                <article class="post-card-new">
                    <div class="post-card-body">
                        <div class="post-card-header">
                            <h3 class="post-card-title">
                                <a href="view_post.php?id=<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></a>
                            </h3>
                            <?php 
                            $status = $p['status'] ?? 'open';
                            $statusText = ['open' => 'Đang mở', 'inactive' => 'Chưa hoạt động', 'closed' => 'Đã đóng', 'taken' => 'Đã nhận'];
                            ?>
                            <span class="post-card-status <?php echo $status; ?>"><?php echo $statusText[$status] ?? $status; ?></span>
                        </div>
                        
                        <div class="post-card-tags">
                            <?php $typeClass = $p['type'] === 'recruitment' ? 'type-recruitment' : 'type-application'; ?>
                            <span class="post-card-tag <?php echo $typeClass; ?>">
                                <i class="bi bi-<?php echo $p['type'] === 'recruitment' ? 'briefcase' : 'person-badge'; ?>"></i>
                                <?php echo $p['type'] === 'recruitment' ? 'Tuyển dụng' : 'Ứng tuyển'; ?>
                            </span>
                            <?php if (!empty($p['category'])): ?>
                                <span class="post-card-tag category"><?php echo htmlspecialchars($p['category']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($p['area'])): ?>
                                <span class="post-card-tag area"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($p['area']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="post-card-meta">
                            <span class="post-card-meta-item">
                                <i class="bi bi-person"></i>
                                <?php echo htmlspecialchars($p['author_name']); ?>
                                <?php if (!empty($p['author_verified'])): ?>
                                    <i class="bi bi-patch-check-fill verified" title="Đã xác minh"></i>
                                <?php endif; ?>
                            </span>
                            <span class="post-card-meta-item online-indicator <?php echo is_user_online($p['author_last_activity'] ?? null) ? 'online' : 'offline'; ?>">
                                <i class="bi bi-circle-fill"></i>
                                <?php echo is_user_online($p['author_last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>
                            </span>
                        </div>
                        
                        <p class="post-card-content"><?php echo htmlspecialchars(strip_tags($p['content'])); ?></p>
                        
                        <div class="post-card-footer">
                            <a href="view_post.php?id=<?php echo $p['id']; ?>" class="post-card-btn">
                                <i class="bi bi-eye"></i> Xem chi tiết
                            </a>
                            <span class="post-card-date">
                                <i class="bi bi-clock"></i>
                                <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
