<?php
require_once 'config.php';
require_login();
// If admin, do not allow patient dashboard access
if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}
if ($_SESSION['role'] !== 'patient') {
        die('Truy cập bị từ chối.');
}

$userId = $_SESSION['user_id'];

// Hàm lấy tên hiển thị đẹp hơn
function getDisplayName($name) {
    // Nếu name là email, lấy phần trước @
    if (strpos($name, '@') !== false) {
        return ucfirst(explode('@', $name)[0]);
    }
    return $name;
}
$displayName = getDisplayName($_SESSION['name'] ?? '');

// check can_post flag to conditionally show create button
$stmtCan = $pdo->prepare('SELECT can_post FROM users WHERE id = ?');
$stmtCan->execute([$userId]);
$canPostRow = $stmtCan->fetch();
$userCanPost = !empty($canPostRow['can_post']);

$stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ? AND type = "recruitment" ORDER BY created_at DESC');
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();
$postsCount = count($posts);

// Đếm tin nhắn chưa đọc
try {
    // Đảm bảo cột is_read tồn tại
    $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'is_read'");
    $checkCol->execute();
    if ((int)$checkCol->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER message");
    }
    $msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $msgStmt->execute([$userId]);
    $messageCount = (int)$msgStmt->fetchColumn();
} catch (Throwable $e) {
    // Fallback nếu cột chưa tồn tại
    $msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ?');
    $msgStmt->execute([$userId]);
    $messageCount = (int)$msgStmt->fetchColumn();
}

$applicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE post_id IN (SELECT id FROM posts WHERE user_id = ? AND type = "recruitment")');
$applicationsStmt->execute([$userId]);
$applicationsCount = (int)$applicationsStmt->fetchColumn();

$favoritePosts = [];
try {
    $favStmt = $pdo->prepare('SELECT p.* FROM favorites f JOIN posts p ON p.id = f.post_id WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 10');
    $favStmt->execute([$userId]);
    $favoritePosts = $favStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Fetch favorites failed: ' . $e->getMessage());
}

$recentAssignments = [];
$recentAssignCount = 0;
try {
    $assignmentSql = "SELECT m.created_at AS assigned_at, p.id AS post_id, p.title, u.name AS student_name, u.email AS student_email "
        . "FROM messages m "
        . "JOIN posts p ON p.id = m.post_id "
        . "JOIN users u ON u.id = m.receiver_id "
        . "WHERE m.sender_id = ? "
        . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
        . "AND m.message LIKE 'Bạn đã được chọn nhận việc%' "
        . "ORDER BY m.created_at DESC";
    $assignmentStmt = $pdo->prepare($assignmentSql);
    $assignmentStmt->execute([$userId]);
    $recentAssignments = $assignmentStmt->fetchAll();
    $recentAssignCount = count($recentAssignments);
} catch (Throwable $e) {
    error_log('Fetch patient assignment history failed: ' . $e->getMessage());
}

// Lấy danh sách ứng viên đã liên hệ vào tin của bệnh nhân
$applicantsList = [];
$applicantsCount = 0;
try {
    // Lấy từ bảng messages (người đã nhắn tin về tin tuyển dụng)
    $applicantsSql = "SELECT DISTINCT 
            u.id AS student_id, 
            u.name AS student_name, 
            u.email AS student_email, 
            u.avatar AS student_avatar,
            u.verified AS student_verified,
            p.id AS post_id, 
            p.title AS post_title,
            MIN(m.created_at) AS applied_at
        FROM messages m
        JOIN posts p ON p.id = m.post_id
        JOIN users u ON u.id = m.sender_id
        WHERE p.user_id = ? 
        AND p.type = 'recruitment'
        AND m.sender_id != ?
        GROUP BY u.id, u.name, u.email, u.avatar, u.verified, p.id, p.title
        ORDER BY applied_at DESC
        LIMIT 50";
    $applicantsStmt = $pdo->prepare($applicantsSql);
    $applicantsStmt->execute([$userId, $userId]);
    $applicantsList = $applicantsStmt->fetchAll();
    
    // Nếu không có từ messages, lấy từ direct_messages (chat trực tiếp)
    if (empty($applicantsList)) {
        $dmSql = "SELECT DISTINCT 
                u.id AS student_id, 
                u.name AS student_name, 
                u.email AS student_email, 
                u.avatar AS student_avatar,
                u.verified AS student_verified,
                NULL AS post_id, 
                'Liên hệ trực tiếp' AS post_title,
                MIN(dm.created_at) AS applied_at
            FROM conversations c
            JOIN direct_messages dm ON dm.conversation_id = c.id
            JOIN users u ON (
                (c.user1_id = ? AND u.id = c.user2_id) OR 
                (c.user2_id = ? AND u.id = c.user1_id)
            )
            WHERE (c.user1_id = ? OR c.user2_id = ?) 
            AND u.id != ?
            AND u.role = 'student'
            GROUP BY u.id, u.name, u.email, u.avatar, u.verified
            ORDER BY applied_at DESC
            LIMIT 50";
        $dmStmt = $pdo->prepare($dmSql);
        $dmStmt->execute([$userId, $userId, $userId, $userId, $userId]);
        $applicantsList = $dmStmt->fetchAll();
    }
    
    $applicantsCount = count($applicantsList);
} catch (Throwable $e) {
    error_log('Fetch applicants list failed: ' . $e->getMessage());
}

$ratingStmt = $pdo->prepare('SELECT AVG(rating) AS avg_score, COUNT(*) AS total FROM ratings WHERE rated_user_id = ?');
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData && $ratingData['avg_score'] ? round($ratingData['avg_score'], 1) : null;
$ratingTotal = $ratingData ? (int)$ratingData['total'] : 0;

$myReviewStmt = $pdo->prepare('SELECT r.rating AS score, r.comment, r.created_at, r.user_id, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role FROM ratings r JOIN users u ON u.id = r.user_id WHERE r.rated_user_id = ? ORDER BY r.created_at DESC LIMIT 20');
$myReviewStmt->execute([$userId]);
$myReviews = $myReviewStmt->fetchAll();

// Lấy tin mới nhất - chỉ tin của bản thân
$latestPostsStmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.avatar AS author_avatar, u.verified 
    FROM posts p 
    JOIN users u ON u.id = p.user_id 
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC 
    LIMIT 10');
$latestPostsStmt->execute([$userId]);
$latestPosts = $latestPostsStmt->fetchAll();
$latestPostsCount = count($latestPosts);

// Đảm bảo không ở chế độ embed để hiển thị topbar
$isEmbed = false;

require_once 'header.php';
?>
</div><!-- Đóng dashboard-container từ header.php -->
<link rel="stylesheet" href="assets/css/dashboard-sidebar.css?v=<?php echo time(); ?>">
<style>
/* Ẩn navbar trên trang dashboard */
.premium-navbar { display: none !important; }
body { padding-top: 0 !important; }

/* FORCE hiển thị topbar với specificity cao nhất */
html body .dashboard-layout .dashboard-main .dashboard-topbar,
.dashboard-layout > .dashboard-main > .dashboard-topbar,
main.dashboard-main > div.dashboard-topbar {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    min-height: 56px !important;
    overflow: visible !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 100 !important;
    background: linear-gradient(135deg, #0D1B36 0%, #1a3a5c 100%) !important;
    padding: 0.875rem 1.5rem !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
    align-items: center !important;
    justify-content: space-between !important;
}
</style>

<script>
// Định nghĩa hàm showSection sớm để onclick hoạt động
function toggleSidebar() {
    document.querySelector('.dashboard-sidebar').classList.toggle('show');
    document.querySelector('.sidebar-overlay').classList.toggle('show');
}

function closeSidebar() {
    document.querySelector('.dashboard-sidebar').classList.remove('show');
    document.querySelector('.sidebar-overlay').classList.remove('show');
}

function filterFavoritesPatient() {
    var searchValue = document.getElementById('favoritesSearchPatient').value.toLowerCase();
    var cards = document.querySelectorAll('#favoritesListPatient .favorite-card');
    var visibleCount = 0;
    
    cards.forEach(function(card) {
        var title = card.getAttribute('data-title') || '';
        var author = card.getAttribute('data-author') || '';
        
        if (title.includes(searchValue) || author.includes(searchValue)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Cập nhật số lượng
    var countEl = document.querySelector('.search-count-patient');
    if (countEl) {
        countEl.textContent = visibleCount + ' tin';
    }
}

function filterApplicants() {
    var searchValue = document.getElementById('applicantsSearch').value.toLowerCase();
    var cards = document.querySelectorAll('#applicantsList .applicant-card');
    var visibleCount = 0;
    
    cards.forEach(function(card) {
        var name = card.getAttribute('data-name') || '';
        var post = card.getAttribute('data-post') || '';
        
        if (name.includes(searchValue) || post.includes(searchValue)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Cập nhật số lượng
    var countEl = document.querySelector('.search-count-applicants');
    if (countEl) {
        countEl.textContent = visibleCount + ' ứng viên';
    }
}

function showSection(sectionId, title) {
    // Ẩn tất cả sections
    document.querySelectorAll('.dashboard-section, .dashboard-welcome-section').forEach(function(el) {
        el.style.display = 'none';
    });
    
    // Hiện section được chọn
    if (sectionId === 'welcome') {
        var welcomeSection = document.querySelector('.dashboard-welcome-section');
        if (welcomeSection) welcomeSection.style.display = 'block';
    } else {
        var section = document.getElementById('section-' + sectionId);
        if (section) {
            section.style.display = 'block';
            
            // Load iframe nếu có
            var iframe = section.querySelector('iframe');
            if (iframe) {
                var iframeSrc = {
                    'search': 'index.php?type=application&embed=1',
                    'create-post': 'create_recruitment.php?embed=1',
                    'request-permission': 'request_posting_permission.php?embed=1',
                    'my-posts': 'index.php?my_posts=1&embed=1',
                    'history': 'assignment_history.php?embed=1',
                    'favorites': 'favorites.php?embed=1',
                    'messages': 'view_messages.php?embed=1',
                    'notifications': 'view_messages.php?from_admin=1&embed=1',
                    'chat': 'conversations.php?embed=1',
                    'friends': 'friends.php?embed=1',
                    'profile': 'edit_profile.php?embed=1',
                    'ratings': 'profile.php?embed=1',
                    'stats': 'profile.php?tab=stats&embed=1',
                    'support': 'account_request.php?embed=1',
                    'settings': 'edit_profile.php?tab=settings&embed=1'
                };
                var currentSrc = iframe.getAttribute('src');
                if (iframeSrc[sectionId] && (!currentSrc || currentSrc === '')) {
                    iframe.src = iframeSrc[sectionId];
                }
            }
        }
    }
    
    // Cập nhật active menu
    document.querySelectorAll('.sidebar-menu-item').forEach(function(item) {
        item.classList.remove('active');
    });
    var activeItem = document.querySelector('.sidebar-menu-item[data-section="' + sectionId + '"]');
    if (activeItem) {
        activeItem.classList.add('active');
    }
    
    // Cập nhật title
    var pageTitle = document.getElementById('page-title');
    var breadcrumb = document.getElementById('breadcrumb-current');
    if (pageTitle) pageTitle.textContent = title;
    if (breadcrumb) breadcrumb.textContent = title;
    
    // Đóng sidebar trên mobile
    closeSidebar();
    
    return false;
}

// Hàm lưu cài đặt
function saveSettings() {
    var settings = {
        notifyEmail: document.getElementById('notifyEmail')?.checked || false,
        notifyMessage: document.getElementById('notifyMessage')?.checked || false,
        notifyApplication: document.getElementById('notifyApplication')?.checked || false,
        profileVisibility: document.getElementById('profileVisibility')?.value || 'public',
        showPhone: document.getElementById('showPhone')?.checked || false,
        showEmail: document.getElementById('showEmail')?.checked || false,
        twoFactor: document.getElementById('twoFactor')?.checked || false
    };
    
    fetch('api/update_privacy.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(settings)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Đã lưu cài đặt thành công!');
        } else {
            alert(data.message || 'Có lỗi xảy ra khi lưu cài đặt.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Đã lưu cài đặt thành công!');
    });
}

// Hàm xác nhận xóa tài khoản
function confirmDeleteAccount() {
    if (confirm('Bạn có chắc chắn muốn xóa tài khoản?\n\nHành động này không thể hoàn tác. Tất cả dữ liệu của bạn sẽ bị xóa vĩnh viễn.')) {
        if (confirm('Xác nhận lần cuối: Bạn thực sự muốn xóa tài khoản?')) {
            window.location.href = 'data_deletion.php';
        }
    }
}
</script>

<div class="dashboard-layout">
    <!-- Sidebar -->
    <aside class="dashboard-sidebar">
        <!-- Sidebar Header with Logo -->
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <div class="sidebar-brand-icon">
                    <img src="ảnh/logo web.jpg" alt="Logo Kết nối Y tế" class="sidebar-logo-img">
                </div>
                <span>Kết nối Y tế</span>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <!-- Tổng quan -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Tổng quan</div>
                <a href="#" class="sidebar-menu-item active" data-section="welcome" onclick="return showSection('welcome', 'Bảng điều khiển')">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Bảng điều khiển</span>
                </a>
            </div>
            
            <!-- Tin đăng -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Tin đăng của tôi</div>
                <?php if ($userCanPost): ?>
                <a href="#" class="sidebar-menu-item" data-section="create-post" onclick="return showSection('create-post', 'Tạo tin tuyển dụng')">
                    <i class="bi bi-plus-circle-fill"></i>
                    <span>Tạo tin mới</span>
                </a>
                <?php else: ?>
                <a href="#" class="sidebar-menu-item" data-section="request-permission" onclick="return showSection('request-permission', 'Yêu cầu quyền đăng tin')">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Yêu cầu quyền đăng</span>
                </a>
                <?php endif; ?>
                <a href="#" class="sidebar-menu-item" data-section="my-posts" onclick="return showSection('my-posts', 'Tin của tôi')">
                    <i class="bi bi-file-earmark-medical-fill"></i>
                    <span>Tin của tôi</span>
                    <?php if ($postsCount > 0): ?>
                    <span class="badge bg-success"><?php echo $postsCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="history" onclick="return showSection('history', 'Lịch sử nhận việc')">
                    <i class="bi bi-check2-circle"></i>
                    <span>Lịch sử nhận việc</span>
                    <?php if ($recentAssignCount > 0): ?>
                    <span class="badge bg-success"><?php echo $recentAssignCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="applicants" onclick="return showSection('applicants', 'Danh sách ứng viên')">
                    <i class="bi bi-people-fill"></i>
                    <span>Danh sách ứng viên</span>
                    <?php if ($applicantsCount > 0): ?>
                    <span class="badge bg-info"><?php echo $applicantsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <!-- Yêu thích -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Đã lưu</div>
                <a href="#" class="sidebar-menu-item" data-section="favorites" onclick="return showSection('favorites', 'Danh sách yêu thích')">
                    <i class="bi bi-heart-fill"></i>
                    <span>Yêu thích</span>
                    <?php if (count($favoritePosts) > 0): ?>
                    <span class="badge bg-danger"><?php echo count($favoritePosts); ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <!-- Liên lạc -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Liên lạc</div>
                <a href="#" class="sidebar-menu-item" data-section="messages" onclick="return showSection('messages', 'Tin nhắn')">
                    <i class="bi bi-envelope-fill"></i>
                    <span>Tin nhắn</span>
                    <?php if ($messageCount > 0): ?>
                    <span class="badge"><?php echo $messageCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <!-- Đánh giá -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Thống kê</div>
                <a href="#" class="sidebar-menu-item" data-section="ratings" onclick="return showSection('ratings', 'Đánh giá')">
                    <i class="bi bi-star-fill"></i>
                    <span>Đánh giá của tôi</span>
                    <?php if ($ratingTotal > 0): ?>
                    <span class="badge bg-warning text-dark"><?php echo $ratingTotal; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="stats" onclick="return showSection('stats', 'Lượt liên hệ')">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Lượt liên hệ</span>
                    <?php if ($applicationsCount > 0): ?>
                    <span class="badge bg-info"><?php echo $applicationsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <!-- Tài khoản -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Tài khoản</div>
                <a href="#" class="sidebar-menu-item" data-section="profile" onclick="return showSection('profile', 'Hồ sơ cá nhân')">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>Hồ sơ cá nhân</span>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="settings" onclick="return showSection('settings', 'Cài đặt tài khoản')">
                    <i class="bi bi-gear-fill"></i>
                    <span>Cài đặt</span>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="support" onclick="return showSection('support', 'Hỗ trợ')">
                    <i class="bi bi-life-preserver"></i>
                    <span>Hỗ trợ</span>
                </a>
                <a href="logout.php" class="sidebar-menu-item" style="color: #f87171;">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Đăng xuất</span>
                </a>
            </div>
        </nav>
    </aside>
    
    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>
    
    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-topbar">
            <div class="d-flex align-items-center">
                <button class="mobile-menu-btn me-2" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title" id="page-title">Bảng điều khiển</h1>
            </div>
            <div class="topbar-breadcrumb">
                <a href="index.php"><i class="bi bi-house"></i> Trang chủ</a>
                <span>/</span>
                <span id="breadcrumb-current">Bệnh nhân</span>
            </div>
        </div>
        
        <!-- Welcome Section - Hiển thị mặc định -->
        <div class="dashboard-welcome-section p-4">
            <div class="welcome-card">
                <!-- Animated Background Elements -->
                <div class="welcome-bg-elements">
                    <div class="floating-shape shape-1"></div>
                    <div class="floating-shape shape-2"></div>
                    <div class="floating-shape shape-3"></div>
                    <div class="floating-shape shape-4"></div>
                    <div class="floating-shape shape-5"></div>
                    <div class="pulse-ring"></div>
                </div>
                
                <div class="welcome-content">
                    <div class="welcome-avatar-wrapper">
                        <div class="welcome-icon-animated">
                            <span class="wave-emoji">👋</span>
                        </div>
                        <div class="avatar-glow"></div>
                    </div>
                    <div class="welcome-text">
                        <div class="welcome-greeting">
                            <span class="greeting-text"><?php echo date('H') < 12 ? 'Chào buổi sáng' : (date('H') < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'); ?></span>
                        </div>
                        <h2 class="welcome-name">
                            <span class="name-highlight"><?php echo htmlspecialchars($displayName); ?>!</span>
                        </h2>
                        <div class="welcome-badges">
                            <span class="badge-role patient">
                                <i class="bi bi-heart-pulse-fill"></i>
                                Bệnh nhân
                            </span>
                        </div>
                        <p class="welcome-desc">
                            <i class="bi bi-lightbulb"></i>
                            Chọn một chức năng từ menu bên trái để bắt đầu.
                        </p>
                    </div>
                </div>
                
                <div class="welcome-actions">
                    <?php if ($userCanPost): ?>
                    <a href="#" onclick="showSection('create-post', 'Tạo tin tuyển dụng'); return false;" class="welcome-btn primary">
                        <span class="btn-icon"><i class="bi bi-plus-circle-fill"></i></span>
                        <span class="btn-text">Tạo tin tuyển dụng</span>
                        <span class="btn-shine"></span>
                    </a>
                    <?php endif; ?>
                    <a href="#" onclick="showSection('notifications', 'Thông báo'); return false;" class="welcome-btn secondary">
                        <span class="btn-icon"><i class="bi bi-bell-fill"></i></span>
                        <span class="btn-text">Thông báo</span>
                    </a>
                    <a href="#" onclick="showSection('messages', 'Tin nhắn'); return false;" class="welcome-btn outline">
                        <span class="btn-icon"><i class="bi bi-chat-dots-fill"></i></span>
                        <span class="btn-text">Tin nhắn</span>
                    </a>
                </div>
                
                <!-- Decorative Corner Elements -->
                <div class="corner-decoration top-right">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="80" cy="20" r="40" fill="rgba(255,255,255,0.1)"/>
                    </svg>
                </div>
                <div class="corner-decoration bottom-left">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="20" cy="80" r="30" fill="rgba(255,255,255,0.08)"/>
                    </svg>
                </div>
            </div>
            
            <!-- Feature Cards Section -->
            <div class="feature-cards-section mt-4">
                <div class="row g-4">
                    <!-- Card 1: Đăng tin tuyển dụng -->
                    <div class="col-lg-6 col-xl-3">
                        <div class="feature-card feature-card-1">
                            <div class="feature-card-image">
                                <img src="Ảnh Giao diện/Sinh Viên Y Khám Bệnh Tại Nhà.jpg" alt="Tuyển sinh viên y">
                                <div class="feature-card-overlay"></div>
                            </div>
                            <div class="feature-card-content">
                                <div class="feature-icon">
                                    <i class="bi bi-megaphone-fill"></i>
                                </div>
                                <h5>Đăng tin tuyển dụng</h5>
                                <p>Tìm kiếm sinh viên y khoa để chăm sóc sức khỏe tại nhà</p>
                                <a href="#" onclick="showSection('my-posts', 'Tin của tôi')" class="feature-btn">
                                    Xem tin của tôi <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <div class="feature-badge">
                                <i class="bi bi-star-fill"></i> Phổ biến
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 2: Tìm sinh viên -->
                    <div class="col-lg-6 col-xl-3">
                        <div class="feature-card feature-card-2">
                            <div class="feature-card-image">
                                <img src="Ảnh Giao diện/Ảnh Sinh Viên Y khám bệnh.png" alt="Tìm sinh viên">
                                <div class="feature-card-overlay"></div>
                            </div>
                            <div class="feature-card-content">
                                <div class="feature-icon">
                                    <i class="bi bi-search-heart-fill"></i>
                                </div>
                                <h5>Tìm sinh viên Y</h5>
                                <p>Duyệt danh sách sinh viên y khoa đang tìm việc</p>
                                <a href="index.php#listings" class="feature-btn">
                                    Tìm kiếm ngay <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <div class="feature-badge feature-badge-success">
                                <i class="bi bi-check-circle-fill"></i> Nhanh chóng
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 3: Lịch sử giao việc -->
                    <div class="col-lg-6 col-xl-3">
                        <div class="feature-card feature-card-3">
                            <div class="feature-card-image">
                                <img src="Ảnh Giao diện/Sinh Viên Y Khám Bệnh.webp" alt="Lịch sử giao việc">
                                <div class="feature-card-overlay"></div>
                            </div>
                            <div class="feature-card-content">
                                <div class="feature-icon">
                                    <i class="bi bi-clipboard2-check-fill"></i>
                                </div>
                                <h5>Lịch sử giao việc</h5>
                                <p>Theo dõi các sinh viên đã được bạn chọn</p>
                                <a href="#" onclick="showSection('history', 'Lịch sử nhận việc')" class="feature-btn">
                                    Xem lịch sử <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <div class="feature-badge feature-badge-info">
                                <i class="bi bi-clock-history"></i> <?php echo $recentAssignCount; ?> việc
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 4: Yêu thích -->
                    <div class="col-lg-6 col-xl-3">
                        <div class="feature-card feature-card-4">
                            <div class="feature-card-image">
                                <img src="Ảnh Giao diện/Ảnh Sinh Viên Y.jpg" alt="Yêu thích">
                                <div class="feature-card-overlay"></div>
                            </div>
                            <div class="feature-card-content">
                                <div class="feature-icon">
                                    <i class="bi bi-heart-fill"></i>
                                </div>
                                <h5>Danh sách yêu thích</h5>
                                <p>Xem các tin đã lưu và sinh viên bạn quan tâm</p>
                                <a href="#" onclick="showSection('favorites', 'Danh sách yêu thích')" class="feature-btn">
                                    Xem yêu thích <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            <?php if (count($favoritePosts) > 0): ?>
                            <div class="feature-badge feature-badge-warning">
                                <i class="bi bi-heart-fill"></i> <?php echo count($favoritePosts); ?> tin
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Section -->
            <div class="stats-section mt-4">
                <div class="row g-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-card stat-posts">
                            <div class="stat-icon">
                                <i class="bi bi-file-earmark-medical-fill"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $postsCount; ?></h3>
                                <p>Tin đăng</p>
                            </div>
                            <div class="stat-trend">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card stat-contacts">
                            <div class="stat-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $applicationsCount; ?></h3>
                                <p>Lượt liên hệ</p>
                            </div>
                            <div class="stat-trend">
                                <i class="bi bi-person-plus-fill"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card stat-messages">
                            <div class="stat-icon">
                                <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $messageCount; ?></h3>
                                <p>Tin nhắn mới</p>
                            </div>
                            <div class="stat-trend">
                                <i class="bi bi-chat-dots-fill"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-card stat-rating">
                            <div class="stat-icon">
                                <i class="bi bi-star-fill"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $avgRating ? $avgRating : '0'; ?></h3>
                                <p>Đánh giá TB</p>
                            </div>
                            <div class="stat-trend">
                                <i class="bi bi-emoji-smile-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4 mb-4">
            <!-- Latest Posts Section - Tin mới nhất -->
            <div class="col-lg-12">
            <div class="latest-posts-section">
                <div class="latest-posts-card">
                    <!-- Header -->
                    <div class="latest-posts-header">
                        <div class="header-left">
                            <div class="header-icon">
                                <i class="bi bi-newspaper"></i>
                            </div>
                            <h4>Tin mới nhất</h4>
                        </div>
                        <span class="posts-count-badge"><?php echo $latestPostsCount; ?> bài đăng</span>
                    </div>
                    
                    <!-- Search & Filter -->
                    <div class="search-filter-bar">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-3 col-md-6">
                                <label class="filter-label">TÌM KIẾM</label>
                                <div class="search-input-wrapper">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="searchInput" class="form-control" placeholder="Tìm theo tiêu đề hoặc nội dung...">
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <label class="filter-label">LOẠI TIN</label>
                                <div class="select-wrapper">
                                    <i class="bi bi-funnel"></i>
                                    <select id="filterType" class="form-select">
                                        <option value="">Tất cả loại</option>
                                        <option value="application">Ứng tuyển</option>
                                        <option value="recruitment">Tuyển dụng</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="filter-label">KHU VỰC</label>
                                <div class="search-input-wrapper">
                                    <i class="bi bi-geo-alt"></i>
                                    <input type="text" id="filterLocation" class="form-control" placeholder="Nhập khu vực...">
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-6">
                                <label class="filter-label">CHUYÊN KHOA</label>
                                <div class="search-input-wrapper">
                                    <i class="bi bi-tag"></i>
                                    <input type="text" id="filterSpecialty" class="form-control" placeholder="Chuyên khoa / Loại">
                                </div>
                            </div>
                            <div class="col-lg-2 col-md-12">
                                <button type="button" class="btn-search-filter" onclick="filterPosts()">
                                    <i class="bi bi-search"></i> Tìm kiếm
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Posts List -->
                    <div class="latest-posts-list" id="latestPostsList">
                        <?php if (empty($latestPosts)): ?>
                        <div class="empty-posts-state">
                            <div class="empty-icon">
                                <i class="bi bi-inbox"></i>
                            </div>
                            <h5>Chưa có tin nào</h5>
                            <p>Hãy là người đầu tiên đăng tin trên hệ thống!</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($latestPosts as $post): ?>
                        <div class="post-item" data-title="<?php echo htmlspecialchars(strtolower($post['title'])); ?>" data-content="<?php echo htmlspecialchars(strtolower($post['content'] ?? '')); ?>" data-location="<?php echo htmlspecialchars(strtolower($post['location'] ?? '')); ?>">
                            <div class="post-item-avatar">
                                <?php if (!empty($post['author_avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($post['author_avatar']); ?>" alt="Avatar">
                                <?php else: ?>
                                <div class="avatar-placeholder">
                                    <i class="bi bi-person"></i>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($post['verified'])): ?>
                                <span class="verified-badge" title="Đã xác minh"><i class="bi bi-patch-check-fill"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="post-item-content">
                                <h5 class="post-item-title">
                                    <a href="view_post.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                </h5>
                                <div class="post-item-meta">
                                    <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($post['author_name']); ?></span>
                                    <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($post['location'] ?? 'Chưa cập nhật'); ?></span>
                                    <span><i class="bi bi-clock"></i> <?php echo date('d/m/Y', strtotime($post['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="post-item-actions">
                                <a href="view_post.php?id=<?php echo $post['id']; ?>" class="btn-view-post">
                                    <i class="bi bi-eye"></i> Xem
                                </a>
                                <button class="btn-contact-post" onclick="contactStudent(<?php echo $post['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($post['author_name'])); ?>')">
                                    <i class="bi bi-chat-dots"></i> Liên hệ
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- View More Button -->
                    <?php if ($latestPostsCount > 0): ?>
                    <div class="view-more-wrapper">
                        <a href="index.php#listings" class="btn-view-more">
                            <i class="bi bi-grid-3x3-gap"></i> Xem tất cả tin đăng
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
            </div>
            
            <!-- Quick Tips Section -->
            <div class="tips-section mt-4">
                <div class="tips-card">
                    <div class="tips-header">
                        <div class="tips-icon">
                            <i class="bi bi-lightbulb-fill"></i>
                        </div>
                        <h5>Mẹo để tìm sinh viên phù hợp</h5>
                    </div>
                    <div class="tips-list">
                        <div class="tip-item">
                            <div class="tip-number">1</div>
                            <div class="tip-content">
                                <strong>Mô tả chi tiết công việc</strong>
                                <p>Đăng tin với thông tin cụ thể về yêu cầu và mức lương để thu hút sinh viên</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">2</div>
                            <div class="tip-content">
                                <strong>Chọn sinh viên đã xác minh</strong>
                                <p>Ưu tiên sinh viên có tài khoản được xác minh để đảm bảo độ tin cậy</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">3</div>
                            <div class="tip-content">
                                <strong>Xem đánh giá trước</strong>
                                <p>Tham khảo đánh giá từ bệnh nhân khác trước khi quyết định chọn</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">4</div>
                            <div class="tip-content">
                                <strong>Đánh giá sau dịch vụ</strong>
                                <p>Đánh giá sinh viên sau khi hoàn thành để giúp cộng đồng</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Section: Tạo tin tuyển dụng -->
        <div class="dashboard-section" id="section-create-post" style="display: none;">
            <iframe id="iframe-create-post" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Yêu cầu quyền đăng tin -->
        <div class="dashboard-section" id="section-request-permission" style="display: none;">
            <iframe id="iframe-request-permission" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Tin của tôi -->
        <div class="dashboard-section" id="section-my-posts" style="display: none;">
            <div class="my-posts-container">
                <!-- Header -->
                <div class="my-posts-header">
                    <div class="header-left">
                        <div class="header-icon">
                            <i class="bi bi-file-earmark-medical"></i>
                        </div>
                        <div>
                            <h4>Tin tuyển dụng của tôi</h4>
                            <p><?php echo $postsCount; ?> tin đăng</p>
                        </div>
                    </div>
                    <?php if ($userCanPost): ?>
                    <a href="#" onclick="showSection('create-post', 'Tạo tin tuyển dụng')" class="btn-create-post">
                        <i class="bi bi-plus-lg"></i> Tạo tin mới
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($posts)): ?>
                <!-- Empty State -->
                <div class="my-posts-empty">
                    <div class="empty-illustration">
                        <i class="bi bi-file-earmark-plus"></i>
                    </div>
                    <h5>Chưa có tin tuyển dụng</h5>
                    <p>Bạn chưa đăng tin tuyển dụng nào. Hãy tạo tin để sinh viên có thể tìm thấy bạn!</p>
                    <?php if ($userCanPost): ?>
                    <a href="#" onclick="showSection('create-post', 'Tạo tin tuyển dụng')" class="btn-empty-create">
                        <i class="bi bi-plus-circle"></i> Tạo tin tuyển dụng đầu tiên
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Posts List -->
                <div class="my-posts-list">
                    <?php foreach ($posts as $index => $post): ?>
                    <div class="post-card" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="post-card-main">
                            <div class="post-status-indicator <?php echo $post['status'] === 'open' ? 'status-open' : 'status-closed'; ?>"></div>
                            <div class="post-info">
                                <h5 class="post-title"><?php echo htmlspecialchars($post['title']); ?></h5>
                                <div class="post-meta">
                                    <span class="meta-item">
                                        <i class="bi bi-calendar3"></i>
                                        <?php echo date('d/m/Y', strtotime($post['created_at'])); ?>
                                    </span>
                                    <span class="post-status-badge <?php echo $post['status'] === 'open' ? 'badge-open' : 'badge-closed'; ?>">
                                        <i class="bi bi-<?php echo $post['status'] === 'open' ? 'broadcast' : 'pause-circle'; ?>"></i>
                                        <?php echo $post['status'] === 'open' ? 'Đang mở' : 'Đã đóng'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="post-card-actions">
                            <a href="view_post.php?id=<?php echo $post['id']; ?>" class="action-btn btn-view" title="Xem chi tiết">
                                <i class="bi bi-eye"></i>
                                <span>Xem</span>
                            </a>
                            <a href="edit_post.php?id=<?php echo $post['id']; ?>" class="action-btn btn-edit" title="Chỉnh sửa">
                                <i class="bi bi-pencil"></i>
                                <span>Sửa</span>
                            </a>
                            <?php if ($post['status'] === 'open'): ?>
                            <button class="action-btn btn-accept" title="Chọn người & Đóng tin" onclick="showApplicants(<?php echo $post['id']; ?>, '<?php echo htmlspecialchars(addslashes($post['title'])); ?>')">
                                <i class="bi bi-person-check-fill"></i>
                                <span>Nhận</span>
                            </button>
                            <?php else: ?>
                            <button class="action-btn btn-reopen" title="Mở lại tin" onclick="togglePostStatus(<?php echo $post['id']; ?>, 'open')">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                <span>Mở lại</span>
                            </button>
                            <?php endif; ?>
                            <button class="action-btn btn-delete" title="Xóa tin" onclick="deletePost(<?php echo $post['id']; ?>)">
                                <i class="bi bi-trash3"></i>
                                <span>Xóa</span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* My Posts Container */
        .my-posts-container {
            padding: 1.5rem;
        }
        
        .my-posts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .my-posts-header .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .my-posts-header .header-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .my-posts-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .my-posts-header p {
            margin: 0;
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .btn-create-post {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-create-post:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
            color: white;
        }
        
        /* Empty State */
        .my-posts-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 16px;
            border: 2px dashed #e2e8f0;
        }
        
        .my-posts-empty .empty-illustration {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .my-posts-empty .empty-illustration i {
            font-size: 3rem;
            color: #3b82f6;
        }
        
        .my-posts-empty h5 {
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .my-posts-empty p {
            color: #64748b;
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        .btn-empty-create {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-empty-create:hover {
            transform: scale(1.05);
            color: white;
        }
        
        /* Posts List */
        .my-posts-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .post-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            animation: slideIn 0.4s ease forwards;
            opacity: 0;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .post-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-color: #3b82f6;
        }
        
        .post-card-main {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }
        
        .post-status-indicator {
            width: 4px;
            height: 50px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        
        .post-status-indicator.status-open {
            background: linear-gradient(180deg, #10b981 0%, #059669 100%);
        }
        
        .post-status-indicator.status-closed {
            background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
        }
        
        .post-info {
            flex: 1;
            min-width: 0;
        }
        
        .post-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .post-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .meta-item i {
            color: #94a3b8;
        }
        
        .post-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .post-status-badge.badge-open {
            background: #dcfce7;
            color: #059669;
        }
        
        .post-status-badge.badge-closed {
            background: #f1f5f9;
            color: #64748b;
        }
        
        /* Action Buttons */
        .post-card-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 55px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 1.1rem;
        }
        
        .action-btn span {
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 0.2rem;
        }
        
        .action-btn.btn-view {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .action-btn.btn-view:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-2px);
        }
        
        .action-btn.btn-edit {
            background: #fef3c7;
            color: #d97706;
        }
        
        .action-btn.btn-edit:hover {
            background: #f59e0b;
            color: white;
            transform: translateY(-2px);
        }
        
        .action-btn.btn-accept {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .action-btn.btn-accept:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .action-btn.btn-reopen {
            background: #dcfce7;
            color: #059669;
        }
        
        .action-btn.btn-reopen:hover {
            background: #10b981;
            color: white;
            transform: translateY(-2px);
        }
        
        .action-btn.btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .action-btn.btn-delete:hover {
            background: #dc2626;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .my-posts-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .post-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .post-card-actions {
                justify-content: space-between;
                margin-top: 0.5rem;
                padding-top: 1rem;
                border-top: 1px solid #f1f5f9;
            }
            
            .action-btn {
                flex: 1;
                width: auto;
            }
        }
        </style>
        
        <!-- Section: Lịch sử nhận việc -->
        <div class="dashboard-section" id="section-history" style="display: none;">
            <div class="history-container">
                <!-- Header -->
                <div class="history-header">
                    <div class="header-left">
                        <div class="header-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <div>
                            <h4>Lịch sử nhận việc</h4>
                            <p><?php echo $recentAssignCount; ?> lượt nhận việc trong 30 ngày</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($recentAssignments)): ?>
                <!-- Empty State -->
                <div class="history-empty">
                    <div class="empty-illustration" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
                        <i class="bi bi-calendar-x" style="color: #10b981;"></i>
                    </div>
                    <h5>Chưa có lịch sử nhận việc</h5>
                    <p>Khi bạn chọn sinh viên nhận việc, lịch sử sẽ được ghi lại tại đây</p>
                </div>
                <?php else: ?>
                <!-- History List -->
                <div class="history-list">
                    <?php foreach ($recentAssignments as $index => $assign): ?>
                    <div class="history-card" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="history-card-main">
                            <div class="history-icon">
                                <i class="bi bi-person-check-fill"></i>
                            </div>
                            <div class="history-info">
                                <h5 class="history-title"><?php echo htmlspecialchars($assign['title']); ?></h5>
                                <div class="history-meta">
                                    <span class="meta-item">
                                        <i class="bi bi-person"></i>
                                        <?php echo htmlspecialchars($assign['student_name']); ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="bi bi-envelope"></i>
                                        <?php echo htmlspecialchars($assign['student_email']); ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="bi bi-calendar3"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($assign['assigned_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="history-card-actions">
                            <a href="view_post.php?id=<?php echo $assign['post_id']; ?>" class="action-btn btn-view" title="Xem tin">
                                <i class="bi bi-eye"></i>
                                <span>Xem tin</span>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* History Container */
        .history-container {
            padding: 1.5rem;
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .history-header .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .history-header .header-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .history-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
        }
        
        .history-header p {
            margin: 0;
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.85);
        }
        
        /* Empty State */
        .history-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 16px;
            border: 2px dashed #e2e8f0;
        }
        
        .history-empty .empty-illustration {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .history-empty .empty-illustration i {
            font-size: 3rem;
        }
        
        .history-empty h5 {
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .history-empty p {
            color: #64748b;
            max-width: 400px;
            margin: 0 auto;
        }
        
        /* History List */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .history-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            animation: slideIn 0.4s ease forwards;
            opacity: 0;
        }
        
        .history-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-color: #10b981;
        }
        
        .history-card-main {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }
        
        .history-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .history-info {
            flex: 1;
            min-width: 0;
        }
        
        .history-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .history-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .history-card-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .history-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .history-card-actions {
                justify-content: center;
                margin-top: 0.5rem;
                padding-top: 1rem;
                border-top: 1px solid #f1f5f9;
            }
            
            .history-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
        }
        </style>
        
        <!-- Section: Danh sách ứng viên -->
        <div class="dashboard-section" id="section-applicants" style="display: none;">
            <div class="section-content p-4">
                <!-- Header -->
                <div class="applicants-header mb-4">
                    <div class="applicants-header-top">
                        <h4 class="applicants-title">
                            <i class="bi bi-people-fill"></i> Danh sách ứng viên
                        </h4>
                        <span class="applicants-count"><?php echo $applicantsCount; ?> ứng viên</span>
                    </div>
                </div>
                
                <?php if (empty($applicantsList)): ?>
                <!-- Empty State -->
                <div class="applicants-empty">
                    <div class="empty-icon">
                        <i class="bi bi-person-plus"></i>
                    </div>
                    <h5>Chưa có ứng viên nào</h5>
                    <p>Khi có sinh viên ứng tuyển vào tin của bạn, họ sẽ xuất hiện ở đây</p>
                    <a href="index.php?type=application#posts" class="btn-find-students">
                        <i class="bi bi-search"></i> Tìm sinh viên
                    </a>
                </div>
                <?php else: ?>
                <!-- Search Box -->
                <div class="applicants-search-box mb-4">
                    <div class="search-input-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="applicantsSearch" class="applicants-search-input" placeholder="Tìm kiếm ứng viên..." onkeyup="filterApplicants()">
                        <span class="search-count-applicants"><?php echo $applicantsCount; ?> ứng viên</span>
                    </div>
                </div>
                
                <!-- Applicants List -->
                <div class="applicants-list" id="applicantsList">
                    <?php foreach ($applicantsList as $index => $applicant): ?>
                    <div class="applicant-card" data-name="<?php echo htmlspecialchars(strtolower($applicant['student_name'])); ?>" data-post="<?php echo htmlspecialchars(strtolower($applicant['post_title'])); ?>" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="applicant-card-left">
                            <div class="applicant-avatar">
                                <?php if (!empty($applicant['student_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($applicant['student_avatar']); ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($applicant['student_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="applicant-info">
                                <h6 class="applicant-name">
                                    <?php echo htmlspecialchars($applicant['student_name']); ?>
                                    <?php if (!empty($applicant['student_verified'])): ?>
                                        <i class="bi bi-patch-check-fill text-primary"></i>
                                    <?php endif; ?>
                                </h6>
                                <p class="applicant-post">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <?php echo htmlspecialchars($applicant['post_title']); ?>
                                </p>
                                <p class="applicant-date">
                                    <i class="bi bi-clock"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($applicant['applied_at'])); ?>
                                </p>
                            </div>
                        </div>
                        <div class="applicant-card-actions">
                            <a href="chat.php?user_id=<?php echo $applicant['student_id']; ?>" class="btn-chat-applicant" title="Nhắn tin">
                                <i class="bi bi-chat-dots-fill"></i>
                            </a>
                            <a href="view_profile.php?id=<?php echo $applicant['student_id']; ?>" class="btn-view-applicant" title="Xem hồ sơ">
                                <i class="bi bi-person"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* Applicants Section - Premium Design */
        .applicants-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 20px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .applicants-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        
        .applicants-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .applicants-title i {
            font-size: 1.75rem;
        }
        
        .applicants-count {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .applicants-search-box {
            background: white;
            border-radius: 16px;
            padding: 0.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .applicants-search-input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 1rem;
            color: #1e293b;
            outline: none;
        }
        
        .applicants-search-input::placeholder {
            color: #94a3b8;
        }
        
        .search-count-applicants {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .applicants-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 24px;
            border: 2px dashed #93c5fd;
        }
        
        .applicants-empty .empty-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 15px 40px rgba(59, 130, 246, 0.35);
        }
        
        .applicants-empty h5 {
            margin: 0 0 0.5rem 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: #1e40af;
        }
        
        .applicants-empty p {
            margin: 0 0 1.5rem 0;
            color: #64748b;
            font-size: 1rem;
        }
        
        .btn-find-students {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 2rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.35);
        }
        
        .btn-find-students:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(59, 130, 246, 0.45);
            color: white;
        }
        
        .applicants-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .applicant-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
            border-left: 4px solid #3b82f6;
            transition: all 0.3s ease;
            animation: slideInApplicant 0.4s ease forwards;
            opacity: 0;
        }
        
        @keyframes slideInApplicant {
            from { opacity: 0; transform: translateX(-15px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .applicant-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(59, 130, 246, 0.15);
        }
        
        .applicant-card-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }
        
        .applicant-avatar {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            font-weight: 700;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .applicant-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .applicant-info {
            flex: 1;
            min-width: 0;
        }
        
        .applicant-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .applicant-post {
            font-size: 0.9rem;
            color: #64748b;
            margin: 0 0 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .applicant-post i {
            color: #3b82f6;
        }
        
        .applicant-date {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .applicant-card-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        .btn-chat-applicant, .btn-view-applicant {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 1.1rem;
        }
        
        .btn-chat-applicant {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-chat-applicant:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
            color: white;
        }
        
        .btn-view-applicant {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-view-applicant:hover {
            background: #3b82f6;
            color: white;
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .applicant-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .applicant-card-actions {
                justify-content: center;
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid #f1f5f9;
            }
        }
        </style>
        
        <!-- Section: Yêu thích -->
        <div class="dashboard-section" id="section-favorites" style="display: none;">
            <div class="section-content p-4">
                <!-- Header -->
                <div class="favorites-header mb-4">
                    <div class="favorites-header-top">
                        <h4 class="favorites-title">
                            <i class="bi bi-heart-fill"></i> Danh sách yêu thích
                        </h4>
                    </div>
                </div>
                
                <?php if (empty($favoritePosts)): ?>
                <!-- Empty State -->
                <div class="favorites-empty">
                    <div class="empty-icon">
                        <i class="bi bi-heart"></i>
                    </div>
                    <h5>Bạn chưa lưu bài đăng yêu thích nào</h5>
                    <p>Khám phá các cơ hội công việc và lưu những tin yêu thích của bạn</p>
                    <a href="index.php?type=application#posts" class="btn-find-posts">
                        <i class="bi bi-search"></i> Tìm sinh viên
                    </a>
                </div>
                <?php else: ?>
                <!-- Search Box -->
                <div class="favorites-search-box mb-4">
                    <div class="search-input-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="favoritesSearchPatient" class="favorites-search-input" placeholder="Tìm kiếm trong danh sách yêu thích..." onkeyup="filterFavoritesPatient()">
                        <span class="search-count-patient"><?php echo count($favoritePosts); ?> tin</span>
                    </div>
                </div>
                
                <!-- Favorites List -->
                <div class="favorites-list" id="favoritesListPatient">
                    <?php foreach ($favoritePosts as $index => $fav): ?>
                    <div class="favorite-card" data-title="<?php echo htmlspecialchars(strtolower($fav['title'])); ?>" data-author="<?php echo htmlspecialchars(strtolower($fav['author_name'] ?? '')); ?>" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="favorite-card-header">
                            <div class="favorite-card-title">
                                <h6><?php echo htmlspecialchars($fav['title']); ?></h6>
                                <p class="favorite-card-author">
                                    <i class="bi bi-person-circle"></i>
                                    <?php echo htmlspecialchars($fav['author_name'] ?? 'Ẩn danh'); ?>
                                </p>
                            </div>
                            <div class="favorite-card-date">
                                <small><?php echo date('d/m/Y', strtotime($fav['created_at'])); ?></small>
                            </div>
                        </div>
                        <div class="favorite-card-content">
                            <p><?php echo mb_substr(strip_tags($fav['content']), 0, 120); ?>...</p>
                        </div>
                        <div class="favorite-card-footer">
                            <a href="view_post.php?id=<?php echo $fav['id']; ?>" class="btn-view-detail">
                                Xem chi tiết <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* Favorites Section - Premium Design */
        .favorites-search-box {
            background: white;
            border-radius: 16px;
            padding: 0.5rem;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .search-input-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .search-input-wrapper:focus-within {
            border-color: #ff6b6b;
            background: white;
            box-shadow: 0 0 0 4px rgba(255, 107, 107, 0.1);
        }
        
        .search-input-wrapper .search-icon {
            color: #94a3b8;
            font-size: 1.1rem;
        }
        
        .favorites-search-input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 1rem;
            color: #1e293b;
            outline: none;
        }
        
        .favorites-search-input::placeholder {
            color: #94a3b8;
        }
        
        .search-count-patient {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%);
            color: white;
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .favorites-header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%);
            border-radius: 20px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(238, 90, 90, 0.3);
        }
        
        .favorites-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .favorites-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .favorites-title i {
            color: white;
            font-size: 1.75rem;
        }
        
        .favorites-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #fff5f5 0%, #ffe8e8 100%);
            border-radius: 24px;
            border: 2px dashed #ffb3b3;
        }
        
        .favorites-empty .empty-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 15px 40px rgba(238, 90, 90, 0.35);
            animation: heartbeat 2s ease-in-out infinite;
        }
        
        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .favorites-empty h5 {
            margin: 0 0 0.5rem 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: #333;
        }
        
        .favorites-empty p {
            margin: 0 0 1.5rem 0;
            color: #666;
            font-size: 1rem;
        }
        
        .btn-find-posts {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 2rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.35);
        }
        
        .btn-find-posts:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(59, 130, 246, 0.45);
            color: white;
        }
        
        .favorites-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.25rem;
        }
        
        .favorite-card {
            background: white;
            border: none;
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            animation: slideInFav 0.5s ease forwards;
            opacity: 0;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        
        .favorite-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #ff6b6b 0%, #ee5a5a 100%);
        }
        
        @keyframes slideInFav {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .favorite-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(238, 90, 90, 0.15);
        }
        
        .favorite-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }
        
        .favorite-card-title h6 {
            margin: 0 0 0.5rem 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.4;
        }
        
        .favorite-card-author {
            margin: 0;
            font-size: 0.9rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .favorite-card-author i {
            color: #3b82f6;
        }
        
        .favorite-card-date {
            white-space: nowrap;
            color: #94a3b8;
            font-size: 0.85rem;
            background: #f1f5f9;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
        }
        
        .favorite-card-content {
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .favorite-card-content p {
            margin: 0;
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        .favorite-card-footer {
            display: flex;
            justify-content: flex-end;
        }
        
        .btn-view-detail {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.25rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-view-detail:hover {
            transform: translateX(3px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
            color: white;
        }
        
        @media (max-width: 768px) {
            .favorites-list {
                grid-template-columns: 1fr;
            }
            
            .favorites-empty {
                padding: 2.5rem 1.5rem;
            }
            
            .favorites-empty .empty-icon {
                width: 100px;
                height: 100px;
                font-size: 3rem;
            }
        }
        </style>
        
        <!-- Section: Thông báo -->
        <div class="dashboard-section" id="section-notifications" style="display: none;">
            <iframe id="iframe-notifications" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Tin nhắn -->
        <div class="dashboard-section" id="section-messages" style="display: none;">
            <iframe id="iframe-messages" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Chat -->
        <div class="dashboard-section" id="section-chat" style="display: none;">
            <iframe id="iframe-chat" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Bạn bè -->
        <div class="dashboard-section" id="section-friends" style="display: none;">
            <iframe id="iframe-friends" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Hồ sơ cá nhân -->
        <div class="dashboard-section" id="section-profile" style="display: none;">
            <iframe id="iframe-profile" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Đánh giá của tôi -->
        <div class="dashboard-section" id="section-ratings" style="display: none;">
            <div class="ratings-container">
                <!-- Header -->
                <div class="ratings-header">
                    <div class="ratings-header-left">
                        <div class="ratings-icon">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div>
                            <h4>Đánh giá của bạn</h4>
                            <p>Xem các đánh giá từ sinh viên</p>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="ratings-stats">
                    <div class="rating-stat-card main">
                        <div class="stat-score">
                            <span class="score-value"><?php echo $avgRating ?? '0'; ?></span>
                            <span class="score-max">/ 5</span>
                        </div>
                        <div class="stat-stars">
                            <?php 
                            $avgScore = $avgRating ?? 0;
                            for($i = 1; $i <= 5; $i++): 
                                if ($i <= floor($avgScore)): ?>
                                    <i class="bi bi-star-fill"></i>
                                <?php elseif ($i - 0.5 <= $avgScore): ?>
                                    <i class="bi bi-star-half"></i>
                                <?php else: ?>
                                    <i class="bi bi-star"></i>
                                <?php endif;
                            endfor; ?>
                        </div>
                        <p class="stat-label"><?php echo $ratingTotal; ?> lượt đánh giá</p>
                    </div>
                    
                    <?php
                    // Tính phân bố số sao
                    $starCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
                    foreach ($myReviews as $rv) {
                        $score = (int)$rv['score'];
                        if (isset($starCounts[$score])) {
                            $starCounts[$score]++;
                        }
                    }
                    ?>
                    <div class="rating-stat-card distribution">
                        <h5>Phân bố đánh giá</h5>
                        <?php for($star = 5; $star >= 1; $star--): 
                            $count = $starCounts[$star];
                            $percent = $ratingTotal > 0 ? round(($count / $ratingTotal) * 100) : 0;
                        ?>
                        <div class="star-bar">
                            <span class="star-label"><?php echo $star; ?> <i class="bi bi-star-fill"></i></span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $percent; ?>%"></div>
                            </div>
                            <span class="star-count"><?php echo $count; ?></span>
                        </div>
                        <?php endfor; ?>
                    </div>
                    
                    <div class="rating-stat-card satisfaction">
                        <?php 
                        $positiveCount = $starCounts[5] + $starCounts[4];
                        $satisfactionPercent = $ratingTotal > 0 ? round(($positiveCount / $ratingTotal) * 100) : 0;
                        ?>
                        <div class="satisfaction-circle">
                            <svg viewBox="0 0 36 36">
                                <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                <path class="circle-fill" stroke-dasharray="<?php echo $satisfactionPercent; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                            </svg>
                            <span class="satisfaction-value"><?php echo $satisfactionPercent; ?>%</span>
                        </div>
                        <p class="stat-label">Hài lòng</p>
                        <small class="text-muted">(4-5 sao)</small>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="ratings-filter">
                    <button class="filter-btn active" data-filter="all">Tất cả</button>
                    <button class="filter-btn" data-filter="5">5 sao</button>
                    <button class="filter-btn" data-filter="4">4 sao</button>
                    <button class="filter-btn" data-filter="3">3 sao</button>
                    <button class="filter-btn" data-filter="2">2 sao</button>
                    <button class="filter-btn" data-filter="1">1 sao</button>
                </div>

                <!-- Reviews List -->
                <?php if (empty($myReviews)): ?>
                <div class="ratings-empty">
                    <div class="empty-icon">
                        <i class="bi bi-chat-square-heart"></i>
                    </div>
                    <h5>Chưa có đánh giá nào</h5>
                    <p>Khi sinh viên đánh giá bạn, các đánh giá sẽ hiển thị ở đây</p>
                </div>
                <?php else: ?>
                <div class="ratings-list" id="ratingsList">
                    <?php foreach ($myReviews as $index => $rv): ?>
                    <div class="rating-card" data-score="<?php echo (int)$rv['score']; ?>" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="rating-card-header">
                            <div class="rater-info">
                                <?php if (!empty($rv['rater_avatar']) && function_exists('upload_exists') && upload_exists($rv['rater_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars(public_url_for($rv['rater_avatar'])); ?>" alt="" class="rater-avatar">
                                <?php else: ?>
                                    <div class="rater-avatar-placeholder"><?php echo strtoupper(substr($rv['rater_name'], 0, 1)); ?></div>
                                <?php endif; ?>
                                <div class="rater-details">
                                    <h6><?php echo htmlspecialchars($rv['rater_name']); ?></h6>
                                    <span class="rater-role">
                                        <i class="bi bi-mortarboard"></i> Sinh viên
                                    </span>
                                </div>
                            </div>
                            <div class="rating-score">
                                <div class="score-stars">
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?php echo $i <= (int)$rv['score'] ? '-fill' : ''; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="score-date">
                                    <i class="bi bi-calendar3"></i>
                                    <?php echo date('d/m/Y', strtotime($rv['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <?php if (!empty($rv['comment'])): ?>
                        <div class="rating-card-body">
                            <p><?php echo nl2br(htmlspecialchars($rv['comment'])); ?></p>
                        </div>
                        <?php endif; ?>
                        <div class="rating-card-footer">
                            <a href="view_profile.php?id=<?php echo $rv['user_id'] ?? ''; ?>" class="btn-view-profile">
                                <i class="bi bi-person"></i> Xem hồ sơ
                            </a>
                            <a href="chat.php?with=<?php echo $rv['user_id'] ?? ''; ?>" class="btn-send-message">
                                <i class="bi bi-chat-dots"></i> Nhắn tin
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* Ratings Container */
        .ratings-container {
            padding: 1.5rem;
        }
        
        .ratings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .ratings-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .ratings-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .ratings-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .ratings-header p {
            margin: 0;
            font-size: 0.9rem;
            color: #64748b;
        }
        
        /* Stats Cards */
        .ratings-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .rating-stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        
        .rating-stat-card.main {
            text-align: center;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: none;
        }
        
        .stat-score {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 0.25rem;
        }
        
        .score-value {
            font-size: 3rem;
            font-weight: 800;
            color: #92400e;
        }
        
        .score-max {
            font-size: 1.25rem;
            color: #b45309;
        }
        
        .stat-stars {
            color: #f59e0b;
            font-size: 1.25rem;
            margin: 0.5rem 0;
        }
        
        .stat-label {
            margin: 0;
            color: #78350f;
            font-weight: 500;
        }
        
        /* Distribution Card */
        .rating-stat-card.distribution h5 {
            margin: 0 0 1rem;
            font-size: 0.9rem;
            color: #475569;
        }
        
        .star-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .star-label {
            width: 45px;
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .star-label i {
            color: #f59e0b;
            font-size: 0.7rem;
        }
        
        .bar-track {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .star-count {
            width: 25px;
            text-align: right;
            font-size: 0.8rem;
            color: #64748b;
        }
        
        /* Satisfaction Card */
        .rating-stat-card.satisfaction {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .satisfaction-circle {
            position: relative;
            width: 100px;
            height: 100px;
        }
        
        .satisfaction-circle svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .circle-bg {
            fill: none;
            stroke: #e2e8f0;
            stroke-width: 3;
        }
        
        .circle-fill {
            fill: none;
            stroke: #10b981;
            stroke-width: 3;
            stroke-linecap: round;
            transition: stroke-dasharray 0.5s ease;
        }
        
        .satisfaction-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            font-weight: 700;
            color: #10b981;
        }
        
        /* Filter Tabs */
        .ratings-filter {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover {
            border-color: #f59e0b;
            color: #f59e0b;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-color: transparent;
            color: white;
        }
        
        /* Empty State */
        .ratings-empty {
            text-align: center;
            padding: 3rem;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 20px;
        }
        
        .ratings-empty .empty-icon {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: #f59e0b;
        }
        
        .ratings-empty h5 {
            margin: 0 0 0.5rem;
            color: #92400e;
        }
        
        .ratings-empty p {
            margin: 0;
            color: #b45309;
        }
        
        /* Rating Cards */
        .ratings-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .rating-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            animation: fadeInUp 0.3s ease forwards;
            opacity: 0;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .rating-card.hidden {
            display: none;
        }
        
        .rating-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .rater-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .rater-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: cover;
        }
        
        .rater-avatar-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .rater-details h6 {
            margin: 0 0 0.25rem;
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .rater-role {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .rater-role i {
            color: #3b82f6;
        }
        
        .rating-score {
            text-align: right;
        }
        
        .score-stars {
            color: #f59e0b;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        
        .score-date {
            font-size: 0.8rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            justify-content: flex-end;
        }
        
        .rating-card-body {
            padding: 1.25rem;
        }
        
        .rating-card-body p {
            margin: 0;
            color: #475569;
            line-height: 1.6;
        }
        
        .rating-card-footer {
            display: flex;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-top: 1px solid #e2e8f0;
            background: #fafbfc;
        }
        
        .btn-view-profile,
        .btn-send-message {
            flex: 1;
            padding: 0.6rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-view-profile {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-view-profile:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        .btn-send-message {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        
        .btn-send-message:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            color: white;
        }
        
        @media (max-width: 768px) {
            .ratings-stats {
                grid-template-columns: 1fr;
            }
            
            .rating-card-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .rating-score {
                text-align: left;
            }
            
            .score-date {
                justify-content: flex-start;
            }
        }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Filter functionality
            const filterBtns = document.querySelectorAll('.ratings-filter .filter-btn');
            const ratingCards = document.querySelectorAll('.rating-card');
            
            filterBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // Update active state
                    filterBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    
                    ratingCards.forEach(card => {
                        if (filter === 'all' || card.dataset.score === filter) {
                            card.classList.remove('hidden');
                        } else {
                            card.classList.add('hidden');
                        }
                    });
                });
            });
        });
        </script>
        
        <!-- Section: Lượt liên hệ -->
        <div class="dashboard-section" id="section-stats" style="display: none;">
            <div class="section-content p-4">
                <div class="stats-header mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stats-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <h4 class="mb-0">Thống kê lượt liên hệ</h4>
                            <p class="text-muted mb-0">Xem thống kê các lượt liên hệ từ sinh viên</p>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-cards-grid mb-4">
                    <div class="stat-card-item blue">
                        <div class="stat-card-icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="stat-card-info">
                            <span class="stat-card-value"><?php echo $applicationsCount; ?></span>
                            <span class="stat-card-label">Tổng lượt liên hệ</span>
                        </div>
                    </div>
                    <div class="stat-card-item green">
                        <div class="stat-card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                        <div class="stat-card-info">
                            <span class="stat-card-value"><?php echo $postsCount; ?></span>
                            <span class="stat-card-label">Tin đã đăng</span>
                        </div>
                    </div>
                    <div class="stat-card-item pink">
                        <div class="stat-card-icon"><i class="bi bi-check2-circle"></i></div>
                        <div class="stat-card-info">
                            <span class="stat-card-value"><?php echo $recentAssignCount; ?></span>
                            <span class="stat-card-label">Đã giao việc</span>
                        </div>
                    </div>
                    <div class="stat-card-item amber">
                        <div class="stat-card-icon"><i class="bi bi-star-fill"></i></div>
                        <div class="stat-card-info">
                            <span class="stat-card-value"><?php echo $avgRating !== null ? $avgRating : '—'; ?></span>
                            <span class="stat-card-label">Điểm đánh giá</span>
                        </div>
                    </div>
                </div>
                
                <!-- Contact History -->
                <div class="contact-history-section">
                    <h5 class="section-title mb-3"><i class="bi bi-clock-history me-2"></i>Lịch sử liên hệ gần đây</h5>
                    <?php
                    // Lấy lịch sử liên hệ
                    $contactStmt = $pdo->prepare('
                        SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, p.title AS post_title
                        FROM messages m
                        JOIN users u ON u.id = m.sender_id
                        LEFT JOIN posts p ON p.id = m.post_id
                        WHERE m.receiver_id = ? AND m.sender_id != ?
                        ORDER BY m.created_at DESC
                        LIMIT 10
                    ');
                    $contactStmt->execute([$userId, $userId]);
                    $recentContacts = $contactStmt->fetchAll();
                    ?>
                    
                    <?php if (empty($recentContacts)): ?>
                    <div class="contacts-empty">
                        <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                        <h5>Chưa có liên hệ</h5>
                        <p>Bạn chưa nhận được liên hệ nào từ sinh viên</p>
                    </div>
                    <?php else: ?>
                    <div class="contacts-list">
                        <?php foreach ($recentContacts as $contact): ?>
                        <div class="contact-item">
                            <div class="contact-avatar">
                                <?php if (!empty($contact['sender_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($contact['sender_avatar']); ?>" alt="">
                                <?php else: ?>
                                    <i class="bi bi-person-fill"></i>
                                <?php endif; ?>
                            </div>
                            <div class="contact-content">
                                <div class="contact-header">
                                    <span class="contact-name"><?php echo htmlspecialchars($contact['sender_name']); ?></span>
                                    <span class="contact-time"><?php echo date('d/m/Y H:i', strtotime($contact['created_at'])); ?></span>
                                </div>
                                <?php if (!empty($contact['post_title'])): ?>
                                <p class="contact-post"><i class="bi bi-file-text me-1"></i><?php echo htmlspecialchars($contact['post_title']); ?></p>
                                <?php endif; ?>
                                <p class="contact-message"><?php echo mb_substr(htmlspecialchars($contact['message']), 0, 100); ?><?php echo mb_strlen($contact['message']) > 100 ? '...' : ''; ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Section: Hỗ trợ -->
        <div class="dashboard-section" id="section-support" style="display: none;">
            <iframe id="iframe-support" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Cài đặt tài khoản -->
        <div class="dashboard-section" id="section-settings" style="display: none;">
            <div class="settings-container">
                <!-- Header -->
                <div class="settings-header">
                    <div class="settings-icon">
                        <i class="bi bi-gear-fill"></i>
                    </div>
                    <div>
                        <h4>Cài đặt tài khoản</h4>
                        <p>Quản lý thông báo, quyền riêng tư và bảo mật tài khoản của bạn</p>
                    </div>
                </div>
                
                <div class="settings-grid">
                    <!-- Cài đặt thông báo -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon bg-primary-soft">
                                <i class="bi bi-bell-fill"></i>
                            </div>
                            <div>
                                <h5>Thông báo</h5>
                            </div>
                        </div>
                        <p class="settings-card-desc">Tùy chỉnh cách bạn nhận thông báo từ hệ thống</p>
                        <div class="settings-form">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="notifyEmail" checked>
                                <label class="form-check-label" for="notifyEmail">
                                    <i class="bi bi-envelope me-2"></i>Nhận thông báo qua email
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="notifyMessage" checked>
                                <label class="form-check-label" for="notifyMessage">
                                    <i class="bi bi-chat-dots me-2"></i>Thông báo tin nhắn mới
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="notifyApplication" checked>
                                <label class="form-check-label" for="notifyApplication">
                                    <i class="bi bi-person-check me-2"></i>Thông báo ứng tuyển mới
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quyền riêng tư -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon bg-success-soft">
                                <i class="bi bi-shield-lock-fill"></i>
                            </div>
                            <div>
                                <h5>Quyền riêng tư</h5>
                            </div>
                        </div>
                        <p class="settings-card-desc">Kiểm soát ai có thể xem thông tin cá nhân của bạn</p>
                        <div class="settings-form">
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-eye me-2"></i>Ai có thể xem hồ sơ</label>
                                <select class="form-select" id="profileVisibility">
                                    <option value="public">🌐 Tất cả mọi người</option>
                                    <option value="registered">👥 Chỉ người đã đăng ký</option>
                                    <option value="verified">✅ Chỉ tài khoản đã xác minh</option>
                                </select>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="showPhone" checked>
                                <label class="form-check-label" for="showPhone">
                                    <i class="bi bi-telephone me-2"></i>Hiển thị số điện thoại
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="showEmail">
                                <label class="form-check-label" for="showEmail">
                                    <i class="bi bi-at me-2"></i>Hiển thị địa chỉ email
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bảo mật -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon bg-warning-soft">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div>
                                <h5>Bảo mật</h5>
                            </div>
                        </div>
                        <p class="settings-card-desc">Bảo vệ tài khoản của bạn với các tùy chọn bảo mật</p>
                        <div class="settings-form">
                            <a href="forgot_password.php" class="btn btn-outline-primary w-100 mb-3">
                                <i class="bi bi-key me-2"></i>Đổi mật khẩu
                            </a>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="twoFactor">
                                <label class="form-check-label" for="twoFactor">
                                    <i class="bi bi-phone me-2"></i>Bật xác thực 2 bước (2FA)
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tài khoản -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon bg-danger-soft">
                                <i class="bi bi-person-gear"></i>
                            </div>
                            <div>
                                <h5>Quản lý tài khoản</h5>
                            </div>
                        </div>
                        <p class="settings-card-desc">Chỉnh sửa thông tin hoặc xóa tài khoản của bạn</p>
                        <div class="settings-form">
                            <a href="#" onclick="showSection('profile', 'Hồ sơ cá nhân')" class="btn btn-outline-secondary w-100 mb-3">
                                <i class="bi bi-pencil-square me-2"></i>Chỉnh sửa hồ sơ
                            </a>
                            <button class="btn btn-outline-danger w-100" onclick="confirmDeleteAccount()">
                                <i class="bi bi-trash3 me-2"></i>Xóa tài khoản vĩnh viễn
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div class="settings-actions">
                    <button class="btn btn-primary btn-lg" onclick="saveSettings()">
                        <i class="bi bi-check2-circle me-2"></i>Lưu tất cả cài đặt
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Modal xác nhận xóa tài khoản -->
        <div class="modal fade" id="deleteAccountModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Xác nhận xóa tài khoản</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-danger fw-bold">Cảnh báo: Hành động này không thể hoàn tác!</p>
                        <p>Khi xóa tài khoản, tất cả dữ liệu của bạn sẽ bị xóa vĩnh viễn bao gồm:</p>
                        <ul>
                            <li>Thông tin cá nhân</li>
                            <li>Tin đăng tuyển dụng</li>
                            <li>Tin nhắn và lịch sử chat</li>
                            <li>Đánh giá và nhận xét</li>
                        </ul>
                        <div class="mb-3">
                            <label class="form-label">Nhập mật khẩu để xác nhận:</label>
                            <input type="password" class="form-control" id="delete-account-password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="button" class="btn btn-danger" id="confirm-delete-account">
                            <i class="bi bi-trash me-1"></i> Xóa tài khoản
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .dashboard-section {
            background: #f1f5f9;
            min-height: calc(100vh - 120px);
        }
        
        /* Settings Styles */
        .settings-container {
            padding: 1.5rem;
            width: 100%;
            max-width: 100%;
        }
        .settings-header {
            background: linear-gradient(135deg, #0D1B36 0%, #1a3a5c 50%, #0f4c75 100%);
            border-radius: 20px;
            padding: 2rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(13, 27, 54, 0.4);
        }
        .settings-header h4 {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .settings-header p {
            margin: 0.25rem 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        .settings-icon {
            width: 60px;
            height: 60px;
            background: rgba(59, 130, 246, 0.3);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.4);
        }
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
        }
        @media (max-width: 1200px) {
            .settings-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        .settings-card {
            background: #fff;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(59, 130, 246, 0.15);
            border-color: #3b82f6;
        }
        .settings-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .settings-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
        .bg-primary-soft { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; }
        .bg-success-soft { background: linear-gradient(135deg, #dbeafe, #93c5fd); color: #1d4ed8; }
        .bg-warning-soft { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0369a1; }
        .bg-danger-soft { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1e40af; }
        .settings-card h5 { margin: 0; font-weight: 700; font-size: 1.1rem; color: #1e293b; }
        .settings-card-desc { color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem; line-height: 1.5; }
        .settings-form .form-label { font-weight: 600; color: #374151; font-size: 0.9rem; margin-bottom: 0.5rem; }
        .settings-form .form-control, .settings-form .form-select { 
            border-radius: 12px; 
            border: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .settings-form .form-control:focus, .settings-form .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .settings-form .form-check { 
            padding: 0.5rem 0; 
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .settings-form .form-check-input { 
            width: 2.5rem; 
            height: 1.25rem; 
            cursor: pointer;
            margin: 0;
            flex-shrink: 0;
        }
        .settings-form .form-check-input:checked { background-color: #3b82f6; border-color: #3b82f6; }
        .settings-form .form-check-label { 
            font-size: 0.85rem; 
            color: #475569; 
            cursor: pointer;
            font-weight: 500;
            margin: 0;
            line-height: 1.4;
        }
        .settings-form .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .settings-form .btn-outline-primary {
            border: 2px solid #3b82f6;
            color: #3b82f6;
        }
        .settings-form .btn-outline-primary:hover {
            background: #3b82f6;
            color: #fff;
        }
        .settings-form .btn-outline-secondary {
            border: 2px solid #64748b;
            color: #64748b;
        }
        .settings-form .btn-outline-secondary:hover {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }
        .settings-form .btn-outline-danger {
            border: 2px solid #ef4444;
            color: #ef4444;
        }
        .settings-form .btn-outline-danger:hover {
            background: #ef4444;
            color: #fff;
        }
        .settings-actions { 
            text-align: center; 
            padding: 2rem 0 1rem;
        }
        .settings-actions .btn { 
            padding: 1rem 3rem; 
            border-radius: 14px; 
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(135deg, #0D1B36 0%, #1a3a5c 50%, #0f4c75 100%);
            border: none;
            box-shadow: 0 8px 25px rgba(13, 27, 54, 0.35);
            transition: all 0.3s;
        }
        .settings-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(59, 130, 246, 0.45);
            background: linear-gradient(135deg, #1a3a5c 0%, #0f4c75 50%, #3b82f6 100%);
        }
        
        /* Notifications Styles */
        .notifications-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-radius: 16px;
            padding: 1.5rem;
            color: #fff;
        }
        .notifications-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .notifications-empty {
            text-align: center;
            padding: 3rem;
            background: #fff;
            border-radius: 16px;
        }
        .notifications-empty .empty-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .notification-item {
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .notification-item:hover {
            transform: translateX(5px);
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            background: #fef3c7;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f59e0b;
        }
        .notification-content { flex: 1; }
        .notification-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        .notification-sender { font-weight: 600; color: #1e293b; }
        .notification-time { font-size: 0.8rem; color: #94a3b8; }
        .notification-message { margin: 0; color: #475569; }
        
        /* Ratings Section Styles */
        .ratings-header {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            border-radius: 16px;
            padding: 1.5rem;
            color: #fff;
        }
        .ratings-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .rating-summary-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 24px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 10px 40px rgba(251, 191, 36, 0.2);
        }
        .rating-big-score {
            margin-bottom: 1rem;
        }
        .rating-big-score .score-number {
            font-size: 5rem;
            font-weight: 800;
            color: #b45309;
            line-height: 1;
        }
        .rating-big-score .score-max {
            font-size: 2rem;
            color: #92400e;
        }
        .rating-stars {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .rating-stars i {
            margin: 0 0.15rem;
        }
        .rating-count {
            color: #92400e;
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0;
        }
        .reviews-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: #fff;
            border-radius: 20px;
            border: 2px dashed #fde68a;
        }
        .reviews-empty .empty-icon {
            font-size: 5rem;
            color: #fbbf24;
            margin-bottom: 1.5rem;
        }
        .reviews-empty h5 {
            color: #1e293b;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        .reviews-empty p {
            color: #64748b;
            margin: 0;
        }
        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .review-item {
            background: #fff;
            border-radius: 20px;
            padding: 1.5rem;
            display: flex;
            gap: 1.25rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
        }
        .review-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.1);
            border-color: #fbbf24;
        }
        .review-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .review-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .review-avatar i {
            font-size: 1.75rem;
            color: #b45309;
        }
        .review-content { flex: 1; }
        .review-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        .review-name { font-weight: 700; color: #1e293b; font-size: 1.05rem; }
        .review-date { font-size: 0.85rem; color: #94a3b8; margin-left: auto; }
        .review-stars { margin-bottom: 0.75rem; font-size: 1.1rem; }
        .review-score { font-size: 0.9rem; color: #64748b; margin-left: 0.5rem; font-weight: 500; }
        .review-comment { margin: 0; color: #475569; line-height: 1.7; font-size: 0.95rem; }
        
        /* Stats Section Styles */
        .stats-header {
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            border-radius: 16px;
            padding: 1.5rem;
            color: #fff;
        }
        .stats-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .stats-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .stat-card-item {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 4px solid;
            transition: transform 0.3s;
        }
        .stat-card-item:hover {
            transform: translateY(-5px);
        }
        .stat-card-item.blue { border-color: #3b82f6; }
        .stat-card-item.green { border-color: #10b981; }
        .stat-card-item.pink { border-color: #ec4899; }
        .stat-card-item.amber { border-color: #f59e0b; }
        .stat-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .stat-card-item.blue .stat-card-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card-item.green .stat-card-icon { background: #d1fae5; color: #10b981; }
        .stat-card-item.pink .stat-card-icon { background: #fce7f3; color: #ec4899; }
        .stat-card-item.amber .stat-card-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card-info { display: flex; flex-direction: column; }
        .stat-card-value { font-size: 1.75rem; font-weight: 700; color: #1e293b; }
        .stat-card-label { font-size: 0.85rem; color: #64748b; }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
        }
        .contacts-empty {
            text-align: center;
            padding: 3rem;
            background: #fff;
            border-radius: 16px;
        }
        .contacts-empty .empty-icon {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        .contacts-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .contact-item {
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .contact-item:hover {
            transform: translateX(5px);
        }
        .contact-avatar {
            width: 45px;
            height: 45px;
            background: #e0e7ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .contact-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .contact-avatar i {
            font-size: 1.25rem;
            color: #6366f1;
        }
        .contact-content { flex: 1; min-width: 0; }
        .contact-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
        }
        .contact-name { font-weight: 600; color: #1e293b; }
        .contact-time { font-size: 0.75rem; color: #94a3b8; }
        .contact-post {
            font-size: 0.8rem;
            color: #3b82f6;
            margin: 0 0 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .contact-message {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        </style>
        
        <style>
        .dashboard-welcome-section {
            background: #f1f5f9;
        }
        
        /* Welcome Card Advanced Styles */
        .welcome-card {
            background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
            background-image: url('ảnh/ảnh nề.jpg');
            background-size: cover;
            background-position: center right;
            border-radius: 24px;
            padding: 6rem 5rem;
            min-height: 500px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(11, 63, 145, 0.35);
        }
        
        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(11, 63, 145, 0.85) 0%, rgba(30, 64, 175, 0.7) 50%, rgba(59, 130, 246, 0.55) 100%);
            z-index: 1;
        }
        
        /* Background Elements */
        .welcome-bg-elements {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 2;
            pointer-events: none;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 15s infinite ease-in-out;
        }
        
        .shape-1 { width: 300px; height: 300px; top: -100px; right: -50px; animation-delay: 0s; }
        .shape-2 { width: 200px; height: 200px; bottom: -50px; left: 10%; animation-delay: -3s; }
        .shape-3 { width: 150px; height: 150px; top: 20%; left: 5%; animation-delay: -6s; }
        .shape-4 { width: 100px; height: 100px; bottom: 20%; right: 20%; animation-delay: -9s; }
        .shape-5 { width: 80px; height: 80px; top: 50%; left: 30%; animation-delay: -12s; }
        
        .pulse-ring {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            height: 400px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: pulse-expand 4s infinite ease-out;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(20px, -20px) rotate(5deg); }
            50% { transform: translate(-10px, 20px) rotate(-5deg); }
            75% { transform: translate(-20px, -10px) rotate(3deg); }
        }
        
        @keyframes pulse-expand {
            0% { transform: translate(-50%, -50%) scale(0.8); opacity: 0.5; }
            100% { transform: translate(-50%, -50%) scale(1.5); opacity: 0; }
        }
        
        /* Content Positioning */
        .welcome-content {
            position: relative;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .welcome-avatar-wrapper {
            position: relative;
        }
        
        .welcome-icon-animated {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255,255,255,0.3);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            animation: icon-bounce 3s infinite ease-in-out;
        }
        
        .wave-emoji {
            font-size: 3rem;
            animation: wave 2.5s infinite;
            display: inline-block;
            transform-origin: 70% 70%;
        }
        
        @keyframes wave {
            0% { transform: rotate(0deg); }
            10% { transform: rotate(14deg); }
            20% { transform: rotate(-8deg); }
            30% { transform: rotate(14deg); }
            40% { transform: rotate(-4deg); }
            50% { transform: rotate(10deg); }
            60% { transform: rotate(0deg); }
            100% { transform: rotate(0deg); }
        }
        
        @keyframes icon-bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .avatar-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            border-radius: 50%;
            animation: glow-pulse 2s infinite;
        }
        
        @keyframes glow-pulse {
            0%, 100% { opacity: 0.5; transform: translate(-50%, -50%) scale(1); }
            50% { opacity: 0.8; transform: translate(-50%, -50%) scale(1.1); }
        }
        
        /* Text Styles */
        .welcome-text {
            flex: 1;
        }
        
        .welcome-greeting {
            margin-bottom: 0.5rem;
        }
        
        .greeting-text {
            font-size: 1rem;
            font-weight: 500;
            color: rgba(255,255,255,0.85);
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .welcome-name {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 1rem 0;
            line-height: 1.2;
        }
        
        .name-highlight {
            background: linear-gradient(135deg, #fff 0%, #e0f2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .welcome-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .badge-role {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .badge-role.patient {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.3), rgba(219, 39, 119, 0.3));
        }
        
        .welcome-desc {
            font-size: 1rem;
            color: rgba(255,255,255,0.85);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .welcome-desc i {
            color: #fbbf24;
        }
        
        /* Actions */
        .welcome-actions {
            position: relative;
            z-index: 3;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        
        .welcome-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.75rem;
            border-radius: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-btn .btn-icon {
            font-size: 1.25rem;
            transition: transform 0.3s;
        }
        
        .welcome-btn:hover .btn-icon {
            transform: scale(1.2);
        }
        
        .welcome-btn .btn-shine {
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s;
        }
        
        .welcome-btn:hover .btn-shine {
            left: 100%;
        }
        
        .welcome-btn.primary {
            background: #fff;
            color: #1e40af;
            box-shadow: 0 10px 30px rgba(255,255,255,0.3);
        }
        
        .welcome-btn.primary:hover {
            background: #f0f9ff;
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(255,255,255,0.4);
        }
        
        .welcome-btn.secondary {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .welcome-btn.secondary:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-4px);
        }
        
        .welcome-btn.outline {
            background: transparent;
            color: #fff;
            border: 2px solid rgba(255,255,255,0.4);
        }
        
        .welcome-btn.outline:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.6);
            transform: translateY(-4px);
        }
        
        /* Corner Decorations */
        .corner-decoration {
            position: absolute;
            width: 150px;
            height: 150px;
            z-index: 2;
            opacity: 0.5;
        }
        
        .corner-decoration.top-right {
            top: 0;
            right: 0;
        }
        
        .corner-decoration.bottom-left {
            bottom: 0;
            left: 0;
        }
        
        /* Feature Cards Section */
        .feature-cards-section {
            padding: 0;
        }
        
        .feature-card {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
        }
        
        .feature-card-image {
            position: relative;
            height: 180px;
            overflow: hidden;
        }
        
        .feature-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .feature-card:hover .feature-card-image img {
            transform: scale(1.1);
        }
        
        .feature-card-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.3) 100%);
        }
        
        .feature-card-content {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            margin-top: -40px;
            position: relative;
            z-index: 2;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .feature-card-1 .feature-icon {
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            color: #fff;
        }
        
        .feature-card-2 .feature-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
        }
        
        .feature-card-3 .feature-icon {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
        }
        
        .feature-card-4 .feature-icon {
            background: linear-gradient(135deg, #ec4899, #db2777);
            color: #fff;
        }
        
        .feature-card-content h5 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .feature-card-content p {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
            flex: 1;
        }
        
        .feature-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #3b82f6;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .feature-btn:hover {
            color: #1e40af;
            gap: 0.75rem;
        }
        
        .feature-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
            z-index: 3;
        }
        
        .feature-badge-success {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .feature-badge-info {
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }
        
        .feature-badge-warning {
            background: linear-gradient(135deg, #ec4899, #db2777);
            box-shadow: 0 4px 15px rgba(236, 72, 153, 0.4);
        }
        
        /* Search Posts Section */
        .search-posts-section {
            margin-bottom: 2rem;
        }
        
        .search-posts-card {
            background: linear-gradient(135deg, #e8f0ff 0%, #f0e8ff 100%);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        }
        
        .search-posts-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .search-posts-icon {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }
        
        .search-posts-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
        }
        
        .search-posts-form {
            width: 100%;
        }
        
        .search-posts-inputs {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            align-items: stretch;
        }
        
        .search-input-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .search-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .search-input,
        .search-select {
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 0.95rem;
            background: white;
            color: #333;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .search-input:focus,
        .search-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-input::placeholder {
            color: #999;
        }
        
        .search-btn-primary {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #667eea;
            color: white;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
            height: 44px;
        }
        
        .search-btn-primary:hover {
            background: #5568d3;
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.4);
            transform: translateY(-2px);
        }
        
        .search-posts-empty {
            text-align: center;
            padding: 3rem 2rem;
            background: white;
            border-radius: 15px;
            margin-top: 1.5rem;
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #e8f0ff 0%, #f0e8ff 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: #667eea;
            margin: 0 auto 1.5rem;
        }
        
        .search-posts-empty h5 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #333;
        }
        
        .search-posts-empty p {
            margin: 0;
            color: #999;
            font-size: 0.95rem;
        }
        
        @media (max-width: 768px) {
            .search-posts-card {
                padding: 1.5rem;
            }
            
            .search-posts-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .search-posts-inputs {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .search-btn-primary {
                width: 100%;
            }
        }
        
        /* Statistics Section */
        .stats-section {
            padding: 0;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .stat-posts::before { background: linear-gradient(180deg, #3b82f6, #1e40af); }
        .stat-contacts::before { background: linear-gradient(180deg, #10b981, #059669); }
        .stat-messages::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
        .stat-rating::before { background: linear-gradient(180deg, #ec4899, #db2777); }
        
        .stat-card .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-posts .stat-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(30, 64, 175, 0.15));
            color: #3b82f6;
        }
        
        .stat-contacts .stat-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(5, 150, 105, 0.15));
            color: #10b981;
        }
        
        .stat-messages .stat-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.15));
            color: #f59e0b;
        }
        
        .stat-rating .stat-icon {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(219, 39, 119, 0.15));
            color: #ec4899;
        }
        
        .stat-info h3 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
            line-height: 1;
        }
        
        .stat-info p {
            font-size: 0.85rem;
            color: #64748b;
            margin: 0.25rem 0 0;
        }
        
        .stat-trend {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1rem;
            opacity: 0.3;
        }
        
        .stat-posts .stat-trend { color: #3b82f6; }
        .stat-contacts .stat-trend { color: #10b981; }
        .stat-messages .stat-trend { color: #f59e0b; }
        .stat-rating .stat-trend { color: #ec4899; }
        
        /* Tips Section */
        .tips-section {
            padding: 0;
        }
        
        .tips-card {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: 20px;
            padding: 2rem;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        
        .tips-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .tips-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .tips-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .tips-header h5 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .tips-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .tip-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        
        .tip-number {
            width: 30px;
            height: 30px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .tip-content strong {
            display: block;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }
        
        .tip-content p {
            margin: 0;
            font-size: 0.85rem;
            opacity: 0.9;
            line-height: 1.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .welcome-card {
                padding: 2rem;
                min-height: auto;
            }
            
            .welcome-content {
                flex-direction: column;
                text-align: center;
            }
            
            .welcome-name {
                font-size: 1.75rem;
            }
            
            .welcome-badges {
                justify-content: center;
            }
            
            .welcome-desc {
                justify-content: center;
            }
            
            .welcome-actions {
                justify-content: center;
            }
            
            .feature-card-image {
                height: 140px;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-info h3 {
                font-size: 1.5rem;
            }
            
            .tips-list {
                grid-template-columns: 1fr;
            }
        }
        
        /* Latest Posts Section */
        .latest-posts-section {
            padding: 0;
        }
        
        .latest-posts-card {
            background: #fff;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }
        
        .latest-posts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .latest-posts-header .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .latest-posts-header .header-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }
        
        .latest-posts-header h4 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .posts-count-badge {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: #fff;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        /* Search & Filter Bar */
        .search-filter-bar {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
        }
        
        .search-input-wrapper input {
            padding-left: 2.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            height: 48px;
            background: #fff;
            transition: all 0.3s;
        }
        
        .search-input-wrapper input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        
        .select-wrapper {
            position: relative;
        }
        
        .select-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
            pointer-events: none;
            z-index: 1;
        }
        
        .select-wrapper select {
            padding-left: 2.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            height: 48px;
            background: #fff;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .select-wrapper select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        
        .btn-search-filter {
            width: 100%;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-search-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        /* Posts List */
        .latest-posts-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        
        .empty-posts-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-posts-state .empty-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .empty-posts-state .empty-icon i {
            font-size: 3rem;
            color: #3b82f6;
        }
        
        .empty-posts-state h5 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .empty-posts-state p {
            color: #64748b;
            font-style: italic;
        }
        
        /* Post Item */
        .post-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.5rem;
            background: #fff;
            border-radius: 16px;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
            height: 100%;
        }
        
        .post-item:hover {
            border-color: #3b82f6;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.15);
            transform: translateY(-5px);
        }
        
        .post-item-avatar {
            position: relative;
            flex-shrink: 0;
        }
        
        .post-item-avatar img,
        .post-item-avatar .avatar-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            object-fit: cover;
        }
        
        .post-item-avatar .avatar-placeholder {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6366f1;
            font-size: 1.25rem;
        }
        
        .post-item-avatar .verified-badge {
            position: absolute;
            bottom: -3px;
            right: -3px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
            font-size: 0.75rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        
        .post-item-content {
            flex: 1;
            min-width: 0;
        }
        
        .post-item-title {
            margin: 0 0 0.5rem;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .post-item-title a {
            color: #1e293b;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .post-item-title a:hover {
            color: #3b82f6;
        }
        
        .post-item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .post-item-meta span {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .post-item-meta i {
            color: #94a3b8;
        }
        
        .post-item-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        .btn-view-post,
        .btn-contact-post {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-view-post {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .btn-view-post:hover {
            background: #3b82f6;
            color: #fff;
        }
        
        .btn-contact-post {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
        }
        
        .btn-contact-post:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }
        
        /* View More */
        .view-more-wrapper {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f1f5f9;
        }
        
        .btn-view-more {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: transparent;
            color: #3b82f6;
            border: 2px solid #3b82f6;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-view-more:hover {
            background: #3b82f6;
            color: #fff;
        }
        
        /* Responsive for Latest Posts */
        @media (max-width: 768px) {
            .latest-posts-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .post-item {
                flex-direction: column;
                text-align: center;
            }
            
            .post-item-meta {
                justify-content: center;
            }
            
            .post-item-actions {
                width: 100%;
                justify-content: center;
            }
        }
        </style>
        
        <!-- Nội dung chi tiết - ẩn mặc định, hiện khi click menu -->
        <div class="dashboard-content" style="display: none;">

<style>
.dashboard-hero-new {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 28px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(11, 63, 145, 0.3);
}
.dashboard-hero-new::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    pointer-events: none;
}
.dashboard-hero-new::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(59,130,246,0.3) 0%, transparent 70%);
    pointer-events: none;
}
.hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
}
.hero-welcome {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}
.hero-avatar {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    border: 2px solid rgba(255,255,255,0.3);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.hero-text h2 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.hero-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    color: #fff;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid rgba(255,255,255,0.2);
}
.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.hero-btn {
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
    cursor: pointer;
}
.hero-btn-primary {
    background: #fff;
    color: #1e40af;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.hero-btn-primary:hover {
    background: #f0f9ff;
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    color: #1e40af;
    text-decoration: none;
}
.hero-btn-secondary {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
    backdrop-filter: blur(10px);
}
.hero-btn-secondary:hover {
    background: rgba(255,255,255,0.25);
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
}
.hero-btn-outline {
    background: transparent;
    color: #fff;
    border: 2px solid rgba(255,255,255,0.4);
}
.hero-btn-outline:hover {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.6);
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
}
@media (max-width: 768px) {
    .dashboard-hero-new { padding: 1.75rem; }
    .hero-content { flex-direction: column; align-items: flex-start; }
    .hero-actions { width: 100%; }
    .hero-btn { flex: 1; justify-content: center; min-width: 140px; }
    .hero-text h2 { font-size: 1.4rem; }
}
</style>

<div class="dashboard-hero-new">
    <div class="hero-content">
        <div class="hero-welcome">
            <div class="hero-avatar">👋</div>
            <div class="hero-text">
                <h2>Xin chào, <?php echo htmlspecialchars($displayName); ?>!</h2>
                <span class="hero-role-badge">🏥 Bệnh nhân</span>
            </div>
        </div>
        <div class="hero-actions">
            <?php if ($userCanPost): ?>
                <a href="create_recruitment.php" class="hero-btn hero-btn-primary">📢 Tạo tin tuyển dụng</a>
            <?php else: ?>
                <button class="hero-btn hero-btn-secondary" disabled style="opacity:0.6;">📢 Tạo tin (bị chặn)</button>
            <?php endif; ?>
            <a href="view_messages.php?from_admin=1" class="hero-btn hero-btn-secondary">🔔 Thông báo</a>
            <a href="view_messages.php" class="hero-btn hero-btn-outline">📬 Tin nhắn</a>
        </div>
    </div>
</div>

<style>
.stats-enhanced .stat-card-new {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    border-radius: 20px;
    background: #fff;
    border-left: 5px solid;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    color: inherit;
}
.stats-enhanced .stat-card-new:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 20px 50px rgba(0,0,0,0.15);
}
.stats-enhanced .stat-card-new .icon-box {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: #fff;
    transition: transform 0.3s ease;
}
.stats-enhanced .stat-card-new:hover .icon-box {
    transform: rotate(10deg) scale(1.1);
}
.stats-enhanced .stat-label { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.stats-enhanced .stat-value { font-size: 2.2rem; font-weight: 700; margin: 0.25rem 0; }
.stats-enhanced .stat-desc { font-size: 0.85rem; color: #94a3b8; }

/* Card colors */
.card-blue { border-color: #3b82f6; background: linear-gradient(135deg, #fff 0%, #dbeafe 100%); }
.card-blue .icon-box { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 20px rgba(59,130,246,0.4); }
.card-blue .stat-value { color: #1e40af; }
.card-blue:hover { box-shadow: 0 20px 50px rgba(59,130,246,0.25); }

.card-green { border-color: #10b981; background: linear-gradient(135deg, #fff 0%, #d1fae5 100%); }
.card-green .icon-box { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 20px rgba(16,185,129,0.4); }
.card-green .stat-value { color: #047857; }
.card-green:hover { box-shadow: 0 20px 50px rgba(16,185,129,0.25); }

.card-cyan { border-color: #06b6d4; background: linear-gradient(135deg, #fff 0%, #cffafe 100%); }
.card-cyan .icon-box { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 20px rgba(6,182,212,0.4); }
.card-cyan .stat-value { color: #0e7490; }
.card-cyan:hover { box-shadow: 0 20px 50px rgba(6,182,212,0.25); }

.card-pink { border-color: #ec4899; background: linear-gradient(135deg, #fff 0%, #fce7f3 100%); }
.card-pink .icon-box { background: linear-gradient(135deg, #ec4899, #db2777); box-shadow: 0 8px 20px rgba(236,72,153,0.4); }
.card-pink .stat-value { color: #be185d; }
.card-pink:hover { box-shadow: 0 20px 50px rgba(236,72,153,0.25); }

.card-purple { border-color: #8b5cf6; background: linear-gradient(135deg, #fff 0%, #ede9fe 100%); }
.card-purple .icon-box { background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 8px 20px rgba(139,92,246,0.4); }
.card-purple .stat-value { color: #6d28d9; }
.card-purple:hover { box-shadow: 0 20px 50px rgba(139,92,246,0.25); }

.card-amber { border-color: #f59e0b; background: linear-gradient(135deg, #fff 0%, #fef3c7 100%); }
.card-amber .icon-box { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 20px rgba(245,158,11,0.4); }
.card-amber .stat-value { color: #b45309; }
.card-amber:hover { box-shadow: 0 20px 50px rgba(245,158,11,0.25); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
.stats-enhanced .stat-card-new:nth-child(1) { animation: fadeInUp 0.5s ease-out 0.1s backwards; }
.stats-enhanced .stat-card-new:nth-child(2) { animation: fadeInUp 0.5s ease-out 0.15s backwards; }
.stats-enhanced .stat-card-new:nth-child(3) { animation: fadeInUp 0.5s ease-out 0.2s backwards; }
.stats-enhanced .stat-card-new:nth-child(4) { animation: fadeInUp 0.5s ease-out 0.25s backwards; }
.stats-enhanced .stat-card-new:nth-child(5) { animation: fadeInUp 0.5s ease-out 0.3s backwards; }
.stats-enhanced .stat-card-new:nth-child(6) { animation: fadeInUp 0.5s ease-out 0.35s backwards; }
</style>

<div class="stats-enhanced" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin: 1.75rem 0 2.25rem;">
    <div class="stat-card-new card-blue">
        <div class="icon-box">💼</div>
        <div>
            <p class="stat-label mb-1">Tin tuyển dụng</p>
            <p class="stat-value mb-0"><?php echo $postsCount; ?></p>
            <span class="stat-desc">Đã đăng</span>
        </div>
    </div>
    <div class="stat-card-new card-green">
        <div class="icon-box">🤝</div>
        <div>
            <p class="stat-label mb-1">Lượt liên hệ</p>
            <p class="stat-value mb-0"><?php echo $applicationsCount; ?></p>
            <span class="stat-desc">Từ sinh viên</span>
        </div>
    </div>
    <a href="favorites.php" class="stat-card-new card-cyan">
        <div class="icon-box">❤️</div>
        <div>
            <p class="stat-label mb-1">Yêu thích</p>
            <p class="stat-value mb-0"><?php echo count($favoritePosts); ?></p>
            <span class="stat-desc">Bài đăng đã lưu</span>
        </div>
    </a>
    <a href="assignment_history.php" class="stat-card-new card-pink">
        <div class="icon-box">📅</div>
        <div>
            <p class="stat-label mb-1">Lịch sử nhận việc</p>
            <p class="stat-value mb-0"><?php echo $recentAssignCount; ?></p>
            <span class="stat-desc">Trong 30 ngày gần nhất</span>
        </div>
    </a>
    <div class="stat-card-new card-amber" role="button" tabindex="0" data-bs-toggle="offcanvas" data-bs-target="#patientReviewsCanvas" aria-controls="patientReviewsCanvas" style="cursor: pointer;">
        <div class="icon-box">⭐</div>
        <div>
            <p class="stat-label mb-1">Đánh giá</p>
            <p class="stat-value mb-0"><?php echo $avgRating !== null ? $avgRating : '—'; ?></p>
            <span class="stat-desc"><?php echo $ratingTotal; ?> lượt đánh giá</span>
        </div>
    </div>
</div>
<div class="offcanvas offcanvas-end reviews-offcanvas-premium" tabindex="-1" id="patientReviewsCanvas" aria-labelledby="patientReviewsCanvasLabel">
        <div class="reviews-offcanvas-header">
            <div class="reviews-header-content">
                <div class="reviews-header-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div>
                    <h5 class="reviews-title" id="patientReviewsCanvasLabel">Đánh giá về bạn</h5>
                    <p class="reviews-subtitle">Hiển thị tối đa 20 phản hồi gần nhất</p>
                </div>
            </div>
            <button type="button" class="reviews-close-btn" data-bs-dismiss="offcanvas" aria-label="Đóng">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="reviews-offcanvas-body">
            <?php if ($avgRating !== null && $ratingTotal > 0): ?>
                <div class="reviews-summary-card">
                    <div class="reviews-summary-score">
                        <span class="score-number"><?php echo $avgRating; ?></span>
                        <span class="score-max">/ 5</span>
                    </div>
                    <div class="reviews-summary-stars">
                        <?php
                            $fullStars = floor($avgRating);
                            $fraction = $avgRating - $fullStars;
                            for ($i = 1; $i <= 5; $i++) {
                                if ($i <= $fullStars) {
                                    echo '<i class="fas fa-star"></i>';
                                } elseif ($i === $fullStars + 1 && $fraction >= 0.5) {
                                    echo '<i class="fas fa-star-half-alt"></i>';
                                } else {
                                    echo '<i class="far fa-star"></i>';
                                }
                            }
                        ?>
                    </div>
                    <div class="reviews-summary-count">
                        <i class="fas fa-users"></i> <?php echo $ratingTotal; ?> lượt đánh giá
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$myReviews): ?>
                <div class="reviews-empty-state">
                    <div class="empty-icon">
                        <i class="far fa-comment-dots"></i>
                    </div>
                    <h6>Chưa có đánh giá</h6>
                    <p>Bạn chưa nhận được đánh giá nào. Hãy tiếp tục hoạt động để nhận phản hồi từ người dùng!</p>
                </div>
            <?php else: ?>
                <div class="reviews-list">
                    <?php foreach ($myReviews as $rv): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="review-avatar">
                                    <?php if (!empty($rv['rater_avatar']) && upload_exists($rv['rater_avatar'])):
                                        $avatarUrl = htmlspecialchars(public_url_for($rv['rater_avatar'])); ?>
                                        <img src="<?php echo $avatarUrl; ?>" alt="<?php echo htmlspecialchars($rv['rater_name']); ?>">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?php echo strtoupper(substr($rv['rater_name'],0,1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="review-meta">
                                    <h6 class="reviewer-name"><?php echo htmlspecialchars($rv['rater_name']); ?></h6>
                                    <span class="review-date">
                                        <i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?>
                                    </span>
                                </div>
                                <div class="review-rating">
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?php echo $i <= (int)$rv['score'] ? 'fas' : 'far'; ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="rating-score"><?php echo (int)$rv['score']; ?>/5</span>
                                </div>
                            </div>
                            <?php if (!empty($rv['comment'])): ?>
                            <div class="review-content">
                                <p><?php echo nl2br(htmlspecialchars($rv['comment'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<style>
/* Reviews Offcanvas Premium Styles */
.reviews-offcanvas-premium {
    width: 420px !important;
    max-width: 90vw;
    border: none !important;
    box-shadow: -20px 0 60px rgba(0, 0, 0, 0.15);
}

.reviews-offcanvas-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

.reviews-offcanvas-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}

.reviews-header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    position: relative;
    z-index: 1;
}

.reviews-header-icon {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #fff;
    backdrop-filter: blur(10px);
}

.reviews-offcanvas-premium .reviews-title {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
}

.reviews-offcanvas-premium .reviews-subtitle {
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.85rem;
    margin: 0.25rem 0 0;
}

.reviews-close-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.reviews-close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.reviews-offcanvas-body {
    padding: 1.5rem;
    background: linear-gradient(180deg, #f8fafc 0%, #fff 100%);
    height: calc(100% - 90px);
    overflow-y: auto;
}

/* Summary Card */
.reviews-summary-card {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 20px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 30px rgba(245, 158, 11, 0.2);
    position: relative;
    overflow: hidden;
}

.reviews-summary-card::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -30%;
    width: 60%;
    height: 60%;
    background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%);
    pointer-events: none;
}

.reviews-summary-score {
    position: relative;
    z-index: 1;
}

.reviews-summary-score .score-number {
    font-size: 3rem;
    font-weight: 800;
    color: #92400e;
    line-height: 1;
}

.reviews-summary-score .score-max {
    font-size: 1.25rem;
    color: #b45309;
    font-weight: 600;
}

.reviews-summary-stars {
    font-size: 1.5rem;
    color: #f59e0b;
    margin: 0.75rem 0;
    letter-spacing: 0.15rem;
    position: relative;
    z-index: 1;
}

.reviews-summary-count {
    font-size: 0.9rem;
    color: #92400e;
    font-weight: 600;
    position: relative;
    z-index: 1;
}

.reviews-summary-count i {
    margin-right: 0.35rem;
}

/* Empty State */
.reviews-empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border-radius: 20px;
    border: 2px dashed #cbd5e1;
}

.reviews-empty-state .empty-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.25rem;
    font-size: 2rem;
    color: #fff;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.reviews-empty-state h6 {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.reviews-empty-state p {
    color: #64748b;
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.6;
}

/* Review Cards */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.review-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    animation: reviewSlideIn 0.4s ease-out backwards;
}

.review-card:nth-child(1) { animation-delay: 0.1s; }
.review-card:nth-child(2) { animation-delay: 0.15s; }
.review-card:nth-child(3) { animation-delay: 0.2s; }
.review-card:nth-child(4) { animation-delay: 0.25s; }
.review-card:nth-child(5) { animation-delay: 0.3s; }

@keyframes reviewSlideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.review-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border-color: #c7d2fe;
}

.review-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.review-avatar {
    flex-shrink: 0;
}

.review-avatar img {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    object-fit: cover;
    border: 2px solid #e2e8f0;
}

.review-avatar .avatar-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
}

.review-meta {
    flex: 1;
    min-width: 0;
}

.reviewer-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.review-date {
    font-size: 0.8rem;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.review-rating {
    text-align: right;
    flex-shrink: 0;
}

.review-rating .rating-stars {
    color: #f59e0b;
    font-size: 0.85rem;
    letter-spacing: 0.05rem;
}

.review-rating .rating-score {
    display: block;
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 0.25rem;
}

.review-content {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    padding: 1rem;
    margin-top: 0.5rem;
    border-left: 3px solid #667eea;
}

.review-content p {
    margin: 0;
    color: #475569;
    font-size: 0.9rem;
    line-height: 1.6;
}

/* Scrollbar Styling */
.reviews-offcanvas-body::-webkit-scrollbar {
    width: 6px;
}

.reviews-offcanvas-body::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.reviews-offcanvas-body::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 3px;
}

.reviews-offcanvas-body::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
}

@media (max-width: 576px) {
    .reviews-offcanvas-premium {
        width: 100% !important;
    }
    
    .review-header {
        flex-wrap: wrap;
    }
    
    .review-rating {
        width: 100%;
        text-align: left;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #e2e8f0;
    }
}
</style>

<style>
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}
@media (max-width: 992px) {
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .quick-actions-grid { grid-template-columns: 1fr; }
}
.action-card {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    padding: 1.75rem;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    color: inherit;
    display: block;
}
.action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.6s ease;
}
.action-card:hover::before {
    left: 100%;
}
.action-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 0 25px 60px rgba(59, 130, 246, 0.15), 0 0 0 1px rgba(59, 130, 246, 0.1);
    text-decoration: none;
    color: inherit;
}
.action-card .action-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    margin-bottom: 1rem;
    transition: all 0.4s ease;
}
.action-card:hover .action-icon {
    transform: scale(1.15) rotate(5deg);
}
.action-card .action-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.action-card .action-desc {
    font-size: 0.9rem;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 0.75rem;
}
.action-card .action-link {
    font-weight: 600;
    font-size: 0.9rem;
    color: #3b82f6;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: gap 0.3s ease;
}
.action-card:hover .action-link {
    gap: 0.6rem;
    color: #1d4ed8;
}

/* Card color themes */
.action-card.card-history { border-left: 4px solid #3b82f6; }
.action-card.card-history .action-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.action-card.card-history:hover { box-shadow: 0 25px 60px rgba(59, 130, 246, 0.2); }

.action-card.card-rating { border-left: 4px solid #f59e0b; }
.action-card.card-rating .action-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.action-card.card-rating:hover { box-shadow: 0 25px 60px rgba(245, 158, 11, 0.2); }

.action-card.card-search { border-left: 4px solid #10b981; }
.action-card.card-search .action-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.action-card.card-search:hover { box-shadow: 0 25px 60px rgba(16, 185, 129, 0.2); }

.action-card.card-update { border-left: 4px solid #8b5cf6; }
.action-card.card-update .action-icon { background: linear-gradient(135deg, #ede9fe, #ddd6fe); }
.action-card.card-update:hover { box-shadow: 0 25px 60px rgba(139, 92, 246, 0.2); }

.action-card.card-schedule { border-left: 4px solid #06b6d4; }
.action-card.card-schedule .action-icon { background: linear-gradient(135deg, #cffafe, #a5f3fc); }
.action-card.card-schedule:hover { box-shadow: 0 25px 60px rgba(6, 182, 212, 0.2); }

.action-card.card-support { border-left: 4px solid #ec4899; }
.action-card.card-support .action-icon { background: linear-gradient(135deg, #fce7f3, #fbcfe8); }
.action-card.card-support:hover { box-shadow: 0 25px 60px rgba(236, 72, 153, 0.2); }

/* Animation */
@keyframes cardFadeIn {
    from { opacity: 0; transform: translateY(25px); }
    to { opacity: 1; transform: translateY(0); }
}
.action-card:nth-child(1) { animation: cardFadeIn 0.5s ease-out 0.05s backwards; }
.action-card:nth-child(2) { animation: cardFadeIn 0.5s ease-out 0.1s backwards; }
.action-card:nth-child(3) { animation: cardFadeIn 0.5s ease-out 0.15s backwards; }
.action-card:nth-child(4) { animation: cardFadeIn 0.5s ease-out 0.2s backwards; }
.action-card:nth-child(5) { animation: cardFadeIn 0.5s ease-out 0.25s backwards; }
.action-card:nth-child(6) { animation: cardFadeIn 0.5s ease-out 0.3s backwards; }
</style>

<div class="quick-actions-grid">
    <a href="assignment_history.php" class="action-card card-history">
        <div class="action-icon">🧾</div>
        <h5 class="action-title">Lịch sử nhận việc</h5>
        <p class="action-desc">Theo dõi các lần chọn sinh viên trong 30 ngày gần nhất.</p>
        <span class="action-link">Xem chi tiết →</span>
    </a>
    <a href="#patientReviewsCanvas" class="action-card card-rating" data-bs-toggle="offcanvas" data-bs-target="#patientReviewsCanvas">
        <div class="action-icon">⭐</div>
        <h5 class="action-title">Đánh giá đã nhận</h5>
        <p class="action-desc">Theo dõi phản hồi từ sinh viên và cải thiện trải nghiệm hỗ trợ.</p>
        <span class="action-link">Xem đánh giá →</span>
    </a>
    <a href="index.php?type=application#posts" class="action-card card-search">
        <div class="action-icon">👩‍⚕️</div>
        <h5 class="action-title">Tìm sinh viên phù hợp</h5>
        <p class="action-desc">Duyệt tin ứng tuyển để chọn người hỗ trợ đáng tin cậy.</p>
        <span class="action-link">Xem danh sách →</span>
    </a>
    <a href="#my-posts" class="action-card card-update">
        <div class="action-icon">📝</div>
        <h5 class="action-title">Cập nhật tin tuyển</h5>
        <p class="action-desc">Chỉnh sửa hoặc bổ sung thông tin để thu hút sinh viên.</p>
        <span class="action-link">Quản lý tin →</span>
    </a>
    <a href="view_messages.php" class="action-card card-schedule">
        <div class="action-icon">📅</div>
        <h5 class="action-title">Hẹn lịch chăm sóc</h5>
        <p class="action-desc">Thống nhất lịch làm việc và trao đổi qua tin nhắn.</p>
        <span class="action-link">Mở hộp thư →</span>
    </a>
    <a href="account_request.php" class="action-card card-support">
        <div class="action-icon">🛠</div>
        <h5 class="action-title">Hỗ trợ tài khoản</h5>
        <p class="action-desc">Viết nhầm tên, cần ẩn hồ sơ hoặc muốn xóa tài khoản? Gửi yêu cầu cho quản trị viên.</p>
        <span class="action-link">Gửi yêu cầu →</span>
    </a>
</div>

<style>
.posts-section {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
}
.posts-section-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem 2rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.posts-section-header h4 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.posts-section-header .header-icon {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}
.posts-table {
    width: 100%;
    border-collapse: collapse;
}
.posts-table thead th {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1rem 1.25rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
}
.posts-table tbody tr {
    transition: all 0.3s ease;
}
.posts-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.04) 0%, rgba(139, 92, 246, 0.04) 100%);
}
.posts-table tbody td {
    padding: 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.posts-table .post-title {
    font-weight: 600;
    color: #1e293b;
    max-width: 280px;
}
.posts-table .post-category {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.85rem;
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #6d28d9;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.posts-table .post-area {
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table .post-area a {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}
.posts-table .post-area a:hover {
    text-decoration: underline;
}
.posts-table .post-date {
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table .status-badge {
    padding: 0.45rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.posts-table .status-badge.status-open {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #047857;
}
.posts-table .status-badge.status-closed {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    color: #475569;
}
.posts-table .status-badge.status-inactive {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #b45309;
}
.posts-table .status-badge.status-taken {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.posts-table .action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: nowrap;
    justify-content: flex-end;
    align-items: center;
}
.posts-table .action-btn {
    padding: 0.45rem 0.9rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.posts-table .action-btn.btn-view {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
}
.posts-table .action-btn.btn-view:hover {
    background: linear-gradient(135deg, #bfdbfe, #93c5fd);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-edit {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #475569;
}
.posts-table .action-btn.btn-edit:hover {
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-delete {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #dc2626;
}
.posts-table .action-btn.btn-delete:hover {
    background: linear-gradient(135deg, #fecaca, #fca5a5);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-accept {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.posts-table .action-btn.btn-accept:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
}
.posts-table .action-btn.btn-reopen {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.posts-table .action-btn.btn-reopen:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-2px);
}
.posts-empty {
    padding: 4rem 2rem;
    text-align: center;
}
.posts-empty-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 1.5rem;
}
.posts-empty p {
    color: #64748b;
    font-size: 1rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 992px) {
    .posts-table thead { display: none; }
    .posts-table tbody tr {
        display: block;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
    }
    .posts-table tbody td {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .posts-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        font-size: 0.85rem;
    }
    .posts-table tbody td:last-child {
        border-bottom: none;
    }
    .posts-table .action-buttons {
        justify-content: flex-start;
    }
}

/* Accept Modal Styles */
.accept-modal .modal-dialog {
    max-width: 500px;
}
.accept-modal-content {
    border: none;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
}
.accept-modal-header {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    padding: 24px 28px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    position: relative;
}
.accept-modal-icon {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.accept-modal-title-wrap {
    flex: 1;
}
.accept-modal-title {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 6px 0;
}
.accept-modal-subtitle {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9rem;
    margin: 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.accept-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.accept-modal-close:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: rotate(90deg);
}
.accept-modal-body {
    padding: 28px;
    background: #f8fafc;
}
.accept-empty-state {
    text-align: center;
    padding: 40px 20px;
}
.accept-empty-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #94a3b8;
    margin: 0 auto 20px;
}
.accept-empty-state p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #475569;
    margin: 0 0 8px 0;
}
.accept-empty-state span {
    font-size: 0.9rem;
    color: #94a3b8;
}
.accept-section {
    margin-bottom: 24px;
}
.accept-section:last-of-type {
    margin-bottom: 0;
}
.accept-section-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 12px;
}
.accept-section-label i {
    color: #10b981;
    font-size: 0.85rem;
}
.accept-candidates-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.accept-candidate-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}
.accept-candidate-item:hover {
    border-color: #10b981;
    background: #f0fdf4;
}
.accept-candidate-item:has(.accept-radio:checked) {
    border-color: #10b981;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.15);
}
.accept-radio {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.accept-candidate-avatar {
    width: 46px;
    height: 46px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    font-weight: 700;
    flex-shrink: 0;
}
.accept-candidate-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.accept-candidate-name {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
}
.accept-candidate-badge {
    font-size: 0.75rem;
    color: #10b981;
    background: #d1fae5;
    padding: 3px 10px;
    border-radius: 20px;
    width: fit-content;
    font-weight: 500;
}
.accept-check-icon {
    width: 28px;
    height: 28px;
    border: 2px solid #e2e8f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: transparent;
    transition: all 0.3s ease;
    flex-shrink: 0;
}
.accept-candidate-item:has(.accept-radio:checked) .accept-check-icon {
    background: #10b981;
    border-color: #10b981;
    color: #fff;
}
.accept-textarea {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 0.95rem;
    resize: vertical;
    min-height: 90px;
    transition: all 0.3s ease;
    background: #fff;
}
.accept-textarea:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.accept-textarea::placeholder {
    color: #94a3b8;
}
.accept-actions {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}
.accept-btn-cancel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 20px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}
.accept-btn-cancel:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.accept-btn-confirm {
    flex: 1.5;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 24px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
}
.accept-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(16, 185, 129, 0.45);
}
@media (max-width: 576px) {
    .accept-modal-header {
        padding: 20px;
    }
    .accept-modal-body {
        padding: 20px;
    }
    .accept-modal-icon {
        width: 48px;
        height: 48px;
        font-size: 1.2rem;
    }
    .accept-actions {
        flex-direction: column;
    }
    .accept-btn-confirm {
        flex: 1;
    }
}
</style>

<section id="my-posts" class="posts-section">
    <div class="posts-section-header">
        <h4><span class="header-icon">📋</span> Tin tuyển dụng của bạn</h4>
        <?php if ($userCanPost): ?>
            <a href="create_recruitment.php" class="btn btn-primary btn-sm" style="border-radius: 10px;">+ Đăng tin mới</a>
        <?php endif; ?>
    </div>
    <?php if (!$posts): ?>
        <div class="posts-empty">
            <div class="posts-empty-icon">📝</div>
            <p>Chưa có tin nào. Hãy đăng tin để tìm sinh viên Y hỗ trợ.</p>
            <a class="btn btn-primary" href="create_recruitment.php" style="border-radius: 12px; padding: 0.75rem 1.5rem;">Đăng tin mới</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="posts-table">
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Chuyên khoa</th>
                        <th>Khu vực</th>
                        <th>Trạng thái</th>
                        <th>Ngày đăng</th>
                        <th style="text-align: right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td data-label="Tiêu đề"><span class="post-title"><?php echo htmlspecialchars($p['title']); ?></span></td>
                        <td data-label="Chuyên khoa"><span class="post-category">🏥 <?php echo htmlspecialchars($p['category'] ?? '—'); ?></span></td>
                        <td data-label="Khu vực" class="post-area">
                            <?php $area = $p['area'] ?? '—'; ?>
                            <?php if ($area && filter_var($area, FILTER_VALIDATE_URL)): ?>
                                <a href="<?php echo htmlspecialchars($area); ?>" target="_blank" rel="noopener">📍 Mở bản đồ</a>
                            <?php else: ?>
                                📍 <?php echo htmlspecialchars($area ?: '—'); ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Trạng thái">
                            <?php $pst = $p['status'] ?? 'open'; ?>
                            <?php if ($pst === 'inactive'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-inactive">⏸ Chưa hoạt động</span>
                            <?php elseif ($pst === 'closed'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-closed">🔒 Đã đóng</span>
                            <?php elseif ($pst === 'taken'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-taken">✅ Đã nhận việc</span>
                            <?php else: ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-open">🟢 Đang mở</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ngày đăng" class="post-date">🗓 <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></td>
                        <td data-label="Thao tác">
                            <div class="action-buttons">
                                <a class="action-btn btn-view" href="view_post.php?id=<?php echo $p['id']; ?>">👁 Xem</a>
                                <a class="action-btn btn-edit" href="edit_post.php?id=<?php echo $p['id']; ?>">✏️ Sửa</a>
                                <button type="button" class="action-btn btn-delete" onclick="deletePost(<?php echo (int)$p['id']; ?>)">🗑 Xóa</button>
                                <?php $pst = ($p['status'] ?? 'open'); ?>
                                <?php if ($pst === 'inactive'): ?>
                                    <button type="button" class="action-btn btn-reopen" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>">🔄 Mở lại</button>
                                <?php elseif ($pst === 'taken'): ?>
                                    <form method="post" action="reopen_post.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="action-btn btn-reopen">🔄 Mở</button>
                                    </form>
                                <?php elseif ($pst === 'closed'): ?>
                                    <button type="button" class="action-btn" disabled style="opacity:0.5;">🔒 Đã đóng</button>
                                <?php else: ?>
                                    <button type="button" class="action-btn btn-accept" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>" id="accept-trigger-<?php echo (int)$p['id']; ?>">✅ Nhận việc</button>
                                <?php endif; ?>

                                                        <div class="modal fade accept-modal" id="acceptModal-<?php echo (int)$p['id']; ?>" tabindex="-1" aria-labelledby="acceptModalLabel-<?php echo (int)$p['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered">
                                                                <div class="modal-content accept-modal-content">
                                                                    <div class="accept-modal-header">
                                                                        <div class="accept-modal-icon">
                                                                            <i class="fas fa-user-check"></i>
                                                                        </div>
                                                                        <div class="accept-modal-title-wrap">
                                                                            <h5 class="accept-modal-title">Chọn người nhận việc</h5>
                                                                            <p class="accept-modal-subtitle"><?php echo htmlspecialchars($p['title']); ?></p>
                                                                        </div>
                                                                        <button type="button" class="accept-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="accept-modal-body">
                                                                        <?php
                                                                            $sql = "
                                                                                SELECT DISTINCT u.id, u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.post_id = ? AND m.sender_id != ?
                                                                                UNION
                                                                                SELECT DISTINCT u.id, u.name FROM conversations c
                                                                                    JOIN users u ON u.id = (CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END)
                                                                                    WHERE (c.user1_id = ? OR c.user2_id = ?)
                                                                                        AND EXISTS(
                                                                                             SELECT 1 FROM direct_messages dm WHERE dm.conversation_id = c.id AND dm.created_at >= ?
                                                                                        )
                                                                            ";
                                                                            $cstmt = $pdo->prepare($sql);
                                                                            $postCreated = $p['created_at'] ?? date('Y-m-d H:i:s');
                                                                            $cstmt->execute([(int)$p['id'], $userId, $userId, $userId, $userId, $postCreated]);
                                                                            $contacts = $cstmt->fetchAll();
                                                                        ?>
                                                                        <?php if (!$contacts): ?>
                                                                            <div class="accept-empty-state">
                                                                                <div class="accept-empty-icon">
                                                                                    <i class="fas fa-inbox"></i>
                                                                                </div>
                                                                                <p>Chưa có ai liên hệ về tin này</p>
                                                                                <span>Hãy chờ sinh viên liên hệ với bạn</span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <form method="post" action="accept_applicant.php" class="accept-form">
                                                                                <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                                                                
                                                                                <div class="accept-section">
                                                                                    <label class="accept-section-label">
                                                                                        <i class="fas fa-users"></i>
                                                                                        Danh sách ứng viên
                                                                                    </label>
                                                                                    <div class="accept-candidates-list">
                                                                                        <?php foreach ($contacts as $idx => $c): ?>
                                                                                            <label class="accept-candidate-item" for="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>">
                                                                                                <input class="accept-radio" type="radio" name="selected_user" id="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>" value="<?php echo $c['id']; ?>" required>
                                                                                                <div class="accept-candidate-avatar">
                                                                                                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                                                                                </div>
                                                                                                <div class="accept-candidate-info">
                                                                                                    <span class="accept-candidate-name"><?php echo htmlspecialchars($c['name']); ?></span>
                                                                                                    <span class="accept-candidate-badge">Đã liên hệ</span>
                                                                                                </div>
                                                                                                <div class="accept-check-icon">
                                                                                                    <i class="fas fa-check-circle"></i>
                                                                                                </div>
                                                                                            </label>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                <div class="accept-section">
                                                                                    <label class="accept-section-label">
                                                                                        <i class="fas fa-comment-alt"></i>
                                                                                        Ghi chú cho ứng viên (tuỳ chọn)
                                                                                    </label>
                                                                                    <textarea name="note" class="accept-textarea" rows="3" placeholder="Nhập lời nhắn hoặc hướng dẫn cho người được chọn..."></textarea>
                                                                                </div>
                                                                                
                                                                                <div class="accept-actions">
                                                                                    <button type="button" class="accept-btn-cancel" data-bs-dismiss="modal">
                                                                                        <i class="fas fa-times"></i>
                                                                                        Hủy bỏ
                                                                                    </button>
                                                                                    <button type="submit" class="accept-btn-confirm">
                                                                                        <i class="fas fa-check"></i>
                                                                                        Xác nhận chọn
                                                                                    </button>
                                                                                </div>
                                                                            </form>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<style>
/* Force topbar to show */
.dashboard-main > .dashboard-topbar {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    overflow: visible !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 100 !important;
}
</style>

<?php require_once 'footer.php'; ?>
<script>
// Filter posts function
function filterPosts() {
    var searchText = document.getElementById('searchInput').value.toLowerCase();
    var filterType = document.getElementById('filterType').value.toLowerCase();
    var filterLocation = document.getElementById('filterLocation').value.toLowerCase();
    var filterSpecialty = document.getElementById('filterSpecialty').value.toLowerCase();
    
    var postItems = document.querySelectorAll('.post-item');
    var visibleCount = 0;
    
    postItems.forEach(function(item) {
        var title = item.getAttribute('data-title') || '';
        var content = item.getAttribute('data-content') || '';
        var location = item.getAttribute('data-location') || '';
        
        var matchSearch = !searchText || title.includes(searchText) || content.includes(searchText);
        var matchLocation = !filterLocation || location.includes(filterLocation);
        
        if (matchSearch && matchLocation) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update count badge
    var countBadge = document.querySelector('.posts-count-badge');
    if (countBadge) {
        countBadge.textContent = visibleCount + ' bài đăng';
    }
    
    // Show empty state if no results
    var postsList = document.getElementById('latestPostsList');
    var emptyState = postsList.querySelector('.empty-posts-state');
    if (visibleCount === 0 && !emptyState) {
        // Add temporary empty message
        var tempEmpty = document.createElement('div');
        tempEmpty.className = 'empty-posts-state temp-empty';
        tempEmpty.innerHTML = '<div class="empty-icon"><i class="bi bi-search"></i></div><h5>Không tìm thấy kết quả</h5><p>Thử thay đổi từ khóa tìm kiếm</p>';
        postsList.appendChild(tempEmpty);
    } else {
        var tempEmpty = postsList.querySelector('.temp-empty');
        if (tempEmpty) tempEmpty.remove();
    }
}

// Contact student function
function contactStudent(userId, userName) {
    if (confirm('Bạn muốn liên hệ với ' + userName + '?')) {
        window.location.href = 'view_messages.php?to=' + userId;
    }
}

// Search on Enter key
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                filterPosts();
            }
        });
    }
});

// AJAX accept handler for all accept forms on this page
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form[action="accept_applicant.php"]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var fd = new FormData(form);
            var postId = fd.get('post_id');
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            fetch(form.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function(res){ return res.json(); })
                .then(function(json){
                    if (json && json.success) {
                        var showBtn = document.getElementById('show-accept-' + postId);
                        if (showBtn) showBtn.classList.add('d-none');
                        var trigger = document.getElementById('accept-trigger-' + postId) || document.querySelector('[data-bs-target="#acceptModal-' + postId + '"]');
                        if (trigger) {
                            // Convert the trigger into a reopen control labeled 'Mở'
                            trigger.textContent = 'Mở';
                            trigger.disabled = false;
                            trigger.classList.remove('d-none','btn-success');
                            trigger.classList.add('btn-outline-success');
                            // Remove modal attributes so it no longer opens the modal
                            trigger.removeAttribute('data-bs-toggle');
                            trigger.removeAttribute('data-bs-target');
                            // Attach a one-time click handler to reopen the post
                            trigger.addEventListener('click', function reopenHandler(ev){
                                if (!confirm('Bạn có chắc muốn mở lại tin này?')) return;
                                var body = new URLSearchParams(); body.append('id', postId);
                                fetch('reopen_post.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' } })
                                .then(function(r){ return r.json(); })
                                .then(function(j){
                                    if (j && j.success) {
                                        var showBtn2 = document.getElementById('show-accept-' + postId);
                                        if (showBtn2) { showBtn2.classList.remove('d-none'); }
                                        // hide this trigger and update badge
                                        trigger.classList.add('d-none');
                                        var badge2 = document.getElementById('post-status-badge-' + postId);
                                        if (badge2) { badge2.textContent = 'Đang mở'; badge2.className = 'badge bg-success'; }
                                        else {
                                            var triggerRow2 = trigger ? (trigger.closest('tr') || null) : null;
                                            if (triggerRow2) {
                                                var statusCell2 = triggerRow2.querySelector('td:nth-child(4)');
                                                if (statusCell2) statusCell2.innerHTML = '<span class="badge bg-success">Đang mở</span>';
                                            }
                                        }
                                    } else {
                                        alert(j && j.message ? j.message : 'Không thể mở tin.');
                                    }
                                }).catch(function(){ alert('Lỗi kết nối.'); });
                            }, { once: true });
                        }
                        var badge = document.getElementById('post-status-badge-' + postId);
                        if (badge) { badge.textContent = 'Đã nhận việc này'; badge.className = 'badge bg-success'; }
                        else {
                            var triggerRow = trigger ? (trigger.closest('tr') || null) : null;
                            if (triggerRow) {
                                var statusCell = triggerRow.querySelector('td:nth-child(4)');
                                if (statusCell) statusCell.innerHTML = '<span class="badge bg-success">Đã nhận việc này</span>';
                            }
                        }
                        var modalEl = document.getElementById('acceptModal-' + postId);
                        if (modalEl) {
                            var bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                            bsModal.hide();
                        }
                    } else {
                        alert(json && json.message ? json.message : 'Không thể hoàn tất thao tác');
                        if (submitBtn) submitBtn.disabled = false;
                    }
                }).catch(function(){
                    alert('Lỗi kết nối. Vui lòng thử lại.');
                    if (submitBtn) submitBtn.disabled = false;
                });
        });
    });
});

// No additional script needed for favorites card because it now navigates directly to favorites.php
</script>
<script>
// Intercept reopen forms and ensure the 'Nhận việc' button appears reliably
document.addEventListener('DOMContentLoaded', function(){
    function createAcceptButton(postId) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-success';
        btn.id = 'accept-trigger-' + postId;
        btn.setAttribute('data-bs-toggle', 'modal');
        btn.setAttribute('data-bs-target', '#acceptModal-' + postId);
        btn.textContent = 'Nhận việc';
        return btn;
    }

    document.querySelectorAll('form[action="reopen_post.php"]').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var idInput = form.querySelector('input[name="id"]');
            if (!idInput) return;
            var postId = idInput.value;
            var body = new URLSearchParams(); body.append('id', postId);
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            fetch(form.action, { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' } })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json && json.success) {
                    // Update badge to 'Đang mở'
                    var badge = document.getElementById('post-status-badge-' + postId);
                    if (badge) { badge.textContent = 'Đang mở'; badge.className = 'badge bg-success'; }

                    // Find action cell (closest td) to insert the accept button
                    var actionCell = form.closest('td') || form.parentNode;
                    var existing = document.getElementById('accept-trigger-' + postId);
                    if (existing) {
                        existing.className = 'btn btn-sm btn-success';
                        existing.setAttribute('data-bs-toggle', 'modal');
                        existing.setAttribute('data-bs-target', '#acceptModal-' + postId);
                    } else {
                        var btn = createAcceptButton(postId);
                        if (actionCell) actionCell.insertBefore(btn, form);
                        else form.parentNode.insertBefore(btn, form);
                    }

                    // remove the reopen form
                    form.remove();
                } else {
                    alert(json && json.message ? json.message : 'Không thể mở tin.');
                    if (submitBtn) submitBtn.disabled = false;
                }
            }).catch(function(){
                alert('Lỗi kết nối. Vui lòng thử lại.');
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    });
});
</script>

<?php include_once 'chatbot_widget.php'; ?>

        </div><!-- /.dashboard-content -->
    </main><!-- /.dashboard-main -->
</div><!-- /.dashboard-layout -->

<script>
function toggleSidebar() {
    document.querySelector('.dashboard-sidebar').classList.toggle('show');
    document.querySelector('.sidebar-overlay').classList.toggle('show');
}

function closeSidebar() {
    document.querySelector('.dashboard-sidebar').classList.remove('show');
    document.querySelector('.sidebar-overlay').classList.remove('show');
}

// Show section function
function showSection(sectionId, title) {
    // Ẩn tất cả sections
    document.querySelectorAll('.dashboard-section, .dashboard-welcome-section').forEach(function(el) {
        el.style.display = 'none';
    });
    
    // Ẩn sidebar trên mobile khi mở section
    var sidebar = document.querySelector('.dashboard-sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (sidebar && window.innerWidth <= 992) {
        sidebar.classList.remove('active');
        if (overlay) overlay.style.display = 'none';
    }
    
    // Hiện section được chọn
    if (sectionId === 'welcome') {
        document.querySelector('.dashboard-welcome-section').style.display = 'block';
    } else {
        var section = document.getElementById('section-' + sectionId);
        if (section) {
            section.style.display = 'block';
            
            // Load iframe nếu có
            var iframe = section.querySelector('iframe');
            if (iframe) {
                var iframeSrc = {
                    'search': 'index.php?type=application&embed=1',
                    'create-post': 'create_recruitment.php?embed=1',
                    'request-permission': 'request_posting_permission.php?embed=1',
                    'my-posts': 'index.php?my_posts=1&embed=1',
                    'history': 'assignment_history.php?embed=1',
                    'favorites': 'favorites.php?embed=1',
                    'messages': 'view_messages.php?embed=1',
                    'notifications': 'view_messages.php?from_admin=1&embed=1',
                    'chat': 'conversations.php?embed=1',
                    'friends': 'friends.php?embed=1',
                    'profile': 'edit_profile.php?embed=1',
                    'ratings': 'profile.php?embed=1',
                    'stats': 'profile.php?tab=stats&embed=1',
                    'support': 'account_request.php?embed=1'
                };
                // Chỉ load nếu chưa có src hoặc src rỗng
                var currentSrc = iframe.getAttribute('src');
                if (iframeSrc[sectionId] && (!currentSrc || currentSrc === '')) {
                    iframe.src = iframeSrc[sectionId];
                }
            }
            
            // Khi mở section, ẩn badge tương ứng
            if (sectionId === 'messages') {
                markMessagesAsRead();
            } else if (sectionId === 'favorites') {
                hideBadge('favorites');
            } else if (sectionId === 'ratings') {
                hideBadge('ratings');
            } else if (sectionId === 'my-posts') {
                hideBadge('my-posts');
            } else if (sectionId === 'history') {
                hideBadge('history');
            } else if (sectionId === 'stats') {
                hideBadge('stats');
            }
        }
    }
    
    // Cập nhật active menu
    document.querySelectorAll('.sidebar-menu-item').forEach(function(item) {
        item.classList.remove('active');
    });
    var activeItem = document.querySelector('.sidebar-menu-item[data-section="' + sectionId + '"]');
    if (activeItem) {
        activeItem.classList.add('active');
    }
    
    // Cập nhật title
    document.getElementById('page-title').textContent = title;
    document.getElementById('breadcrumb-current').textContent = title;
    
    // Đóng sidebar trên mobile
    closeSidebar();
    
    return false;
}

// Ẩn badge của một section
function hideBadge(sectionId) {
    var badge = document.querySelector('.sidebar-menu-item[data-section="' + sectionId + '"] .badge');
    if (badge) {
        badge.style.display = 'none';
    }
}

// Đánh dấu tin nhắn đã đọc và ẩn badge
function markMessagesAsRead() {
    fetch('api/mark_messages_read.php', { method: 'POST' })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            hideBadge('messages');
        }
    })
    .catch(function() {});
}

// Settings form handlers
document.addEventListener('DOMContentLoaded', function() {
    // Change password form
    var passwordForm = document.getElementById('change-password-form');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            
            if (formData.get('new_password') !== formData.get('confirm_password')) {
                alert('Mật khẩu xác nhận không khớp!');
                return;
            }
            
            fetch('api/change_password.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Đổi mật khẩu thành công!');
                    passwordForm.reset();
                } else {
                    alert(data.message || 'Có lỗi xảy ra!');
                }
            })
            .catch(() => alert('Lỗi kết nối!'));
        });
    }
    
    // Contact info form
    var contactForm = document.getElementById('contact-info-form');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            
            fetch('api/update_contact.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Cập nhật thông tin thành công!');
                } else {
                    alert(data.message || 'Có lỗi xảy ra!');
                }
            })
            .catch(() => alert('Lỗi kết nối!'));
        });
    }
    
    // Privacy settings
    var privacyBtn = document.getElementById('save-privacy-btn');
    if (privacyBtn) {
        privacyBtn.addEventListener('click', function() {
            var settings = {
                show_phone: document.getElementById('showPhone')?.checked ? 1 : 0,
                show_email: document.getElementById('showEmail')?.checked ? 1 : 0,
                allow_messages: document.getElementById('allowMessages')?.checked ? 1 : 0
            };
            
            fetch('api/update_privacy.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(settings)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Đã lưu cài đặt quyền riêng tư!');
                } else {
                    alert(data.message || 'Có lỗi xảy ra!');
                }
            })
            .catch(() => alert('Lỗi kết nối!'));
        });
    }
    
    // Delete account
    var deleteBtn = document.getElementById('confirm-delete-account');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            var password = document.getElementById('delete-account-password')?.value;
            if (!password) {
                alert('Vui lòng nhập mật khẩu!');
                return;
            }
            
            if (!confirm('Bạn có chắc chắn muốn xóa tài khoản? Hành động này không thể hoàn tác!')) {
                return;
            }
            
            fetch('api/delete_account.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Tài khoản đã được xóa!');
                    window.location.href = 'logout.php';
                } else {
                    alert(data.message || 'Có lỗi xảy ra!');
                }
            })
            .catch(() => alert('Lỗi kết nối!'));
        });
    }
});

// Hàm xóa bài đăng
function deletePost(postId) {
    if (confirm('Bạn có chắc chắn muốn xóa tin này?')) {
        fetch('delete_post.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + postId
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message || 'Đã xóa tin thành công!');
                // Reload trang để cập nhật danh sách
                window.location.reload();
            } else {
                alert(data.message || 'Có lỗi xảy ra!');
            }
        })
        .catch(() => {
            alert('Lỗi kết nối!');
        });
    }
}

// Hàm toggle trạng thái bài đăng
function togglePostStatus(postId, newStatus) {
    if (confirm(newStatus === 'open' ? 'Mở lại tin này?' : 'Đóng tin này?')) {
        fetch('toggle_post_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + postId + '&status=' + newStatus
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Có lỗi xảy ra!');
            }
        })
        .catch(() => alert('Lỗi kết nối!'));
    }
}

// Hàm hiển thị danh sách ứng viên
function showApplicants(postId, postTitle) {
    // Tạo modal hiển thị danh sách ứng viên
    var existingModal = document.getElementById('applicantsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    var modal = document.createElement('div');
    modal.id = 'applicantsModal';
    modal.className = 'modal fade';
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; border-radius: 16px 16px 0 0;">
                    <div>
                        <h5 class="modal-title mb-1"><i class="bi bi-person-check-fill me-2"></i>Chọn người nhận việc</h5>
                        <small style="opacity: 0.9;">${postTitle}</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Đang tải...</span>
                        </div>
                        <p class="mt-2 text-muted">Đang tải danh sách...</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    var bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // Fetch danh sách ứng viên
    fetch('get_applicants.php?post_id=' + postId)
        .then(res => res.json())
        .then(data => {
            var modalBody = modal.querySelector('.modal-body');
            if (data.success && data.applicants && data.applicants.length > 0) {
                var html = '<div class="applicants-list">';
                data.applicants.forEach(function(applicant) {
                    html += `
                        <div class="applicant-item d-flex align-items-center justify-content-between p-3 mb-2" style="background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="applicant-avatar" style="width: 45px; height: 45px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                    ${applicant.avatar ? '<img src="' + applicant.avatar + '" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">' : applicant.name.charAt(0).toUpperCase()}
                                </div>
                                <div>
                                    <div class="fw-semibold">${applicant.name}</div>
                                    <small class="text-muted">${applicant.email || ''}</small>
                                </div>
                            </div>
                            <button class="btn btn-success btn-sm" onclick="acceptApplicant(${postId}, ${applicant.id})" style="border-radius: 8px;">
                                <i class="bi bi-check-lg me-1"></i>Chọn
                            </button>
                        </div>
                    `;
                });
                html += '</div>';
                modalBody.innerHTML = html;
            } else {
                modalBody.innerHTML = `
                    <div class="text-center py-4">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #cbd5e1;"></i>
                        <p class="mt-3 text-muted">Chưa có ai liên hệ về tin này</p>
                    </div>
                `;
            }
        })
        .catch(function() {
            modal.querySelector('.modal-body').innerHTML = `
                <div class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-circle" style="font-size: 2rem;"></i>
                    <p class="mt-2">Không thể tải danh sách</p>
                </div>
            `;
        });
    
    modal.addEventListener('hidden.bs.modal', function() {
        modal.remove();
    });
}

// Hàm chấp nhận ứng viên
function acceptApplicant(postId, applicantId) {
    if (confirm('Xác nhận chọn người này nhận việc?')) {
        fetch('accept_applicant.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'post_id=' + postId + '&applicant_id=' + applicantId
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Đã chọn người nhận việc thành công!');
                location.reload();
            } else {
                alert(data.message || 'Có lỗi xảy ra!');
            }
        })
        .catch(() => alert('Lỗi kết nối!'));
    }
}

// Hàm toggle yêu thích
function toggleFavorite(postId, btn) {
    fetch('toggle_favorite.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'post_id=' + postId
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Xóa card khỏi danh sách
            var card = btn.closest('.favorite-card');
            if (card) {
                card.style.animation = 'fadeOut 0.3s ease forwards';
                setTimeout(function() {
                    card.remove();
                    // Cập nhật số lượng
                    var countEl = document.querySelector('.favorites-header p');
                    if (countEl) {
                        var remaining = document.querySelectorAll('.favorite-card').length;
                        countEl.textContent = remaining + ' tin đã lưu';
                    }
                    // Hiển thị empty state nếu hết
                    if (document.querySelectorAll('.favorite-card').length === 0) {
                        location.reload();
                    }
                }, 300);
            }
        } else {
            alert(data.message || 'Có lỗi xảy ra!');
        }
    })
    .catch(() => alert('Lỗi kết nối!'));
}
</script>

<style>
@keyframes fadeOut {
    from { opacity: 1; transform: translateX(0); }
    to { opacity: 0; transform: translateX(20px); }
}
</style>
