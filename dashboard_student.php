<?php
require_once 'config.php';
require_login();
// If this account has admin privilege, route to admin dashboard only
if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}
if ($_SESSION['role'] !== 'student') {
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

$stmtStudent = $pdo->prepare('SELECT verified FROM users WHERE id = ?');
$stmtStudent->execute([$userId]);
$verifiedFlag = (int)$stmtStudent->fetchColumn();
$_SESSION['verified'] = $verifiedFlag ? 1 : 0;

// check posting permission
$stmtCan = $pdo->prepare('SELECT can_post FROM users WHERE id = ?');
$stmtCan->execute([$userId]);
$canPost = (int)$stmtCan->fetchColumn();

$latestVerification = null;
if (!$verifiedFlag) {
    $stmtPending = $pdo->prepare('SELECT * FROM verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmtPending->execute([$userId]);
    $latestVerification = $stmtPending->fetch();
}

$stmt = $pdo->prepare('SELECT * FROM posts WHERE user_id = ? AND type = "application" ORDER BY created_at DESC');
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();
$postsCount = count($posts);

// Đếm tin nhắn chưa đọc
try {
    $msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $msgStmt->execute([$userId]);
    $messageCount = (int)$msgStmt->fetchColumn();
} catch (Throwable $e) {
    $msgStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ?');
    $msgStmt->execute([$userId]);
    $messageCount = (int)$msgStmt->fetchColumn();
}

$ratingStmt = $pdo->prepare('SELECT AVG(rating) AS avg_score, COUNT(*) AS total FROM ratings WHERE rated_user_id = ?');
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData && $ratingData['avg_score'] ? round($ratingData['avg_score'], 1) : null;
$ratingTotal = $ratingData ? (int)$ratingData['total'] : 0;

$myReviewStmt = $pdo->prepare('SELECT r.rating AS score, r.comment, r.created_at, r.user_id, u.name AS rater_name, u.avatar AS rater_avatar, u.role AS rater_role FROM ratings r JOIN users u ON u.id = r.user_id WHERE r.rated_user_id = ? ORDER BY r.created_at DESC LIMIT 20');
$myReviewStmt->execute([$userId]);
$myReviews = $myReviewStmt->fetchAll();

$favoritePosts = [];
try {
    $favStmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.email AS author_email, f.created_at AS favorited_at FROM favorites f JOIN posts p ON p.id = f.post_id JOIN users u ON u.id = p.user_id WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 10');
    $favStmt->execute([$userId]);
    $favoritePosts = $favStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Fetch student favorites failed: ' . $e->getMessage());
}

$recentAcceptances = [];
$recentAcceptCount = 0;
try {
    $historySql = "SELECT m.created_at AS accepted_at, p.id AS post_id, p.title, u.name AS owner_name, u.email AS owner_email "
        . "FROM messages m "
        . "JOIN posts p ON p.id = m.post_id "
        . "JOIN users u ON u.id = p.user_id "
        . "WHERE m.receiver_id = ? "
        . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
        . "AND m.message LIKE 'Bạn đã được chọn nhận việc%' "
        . "ORDER BY m.created_at DESC";
    $histStmt = $pdo->prepare($historySql);
    $histStmt->execute([$userId]);
    $recentAcceptances = $histStmt->fetchAll();
    $recentAcceptCount = count($recentAcceptances);
} catch (Throwable $e) {
    error_log('Fetch student acceptance history failed: ' . $e->getMessage());
}

// Đếm lượt liên hệ (số người đã liên hệ với sinh viên qua tin đăng)
$contactCount = 0;
$contactDetails = [];
try {
    $contactSql = "SELECT DISTINCT m.sender_id, u.name, u.avatar, u.role, MAX(m.created_at) AS last_contact, p.title AS post_title, p.id AS post_id
        FROM messages m 
        JOIN users u ON u.id = m.sender_id 
        JOIN posts p ON p.id = m.post_id
        WHERE m.receiver_id = ? AND m.sender_id != ?
        GROUP BY m.sender_id, p.id
        ORDER BY last_contact DESC";
    $contactStmt = $pdo->prepare($contactSql);
    $contactStmt->execute([$userId, $userId]);
    $contactDetails = $contactStmt->fetchAll();
    $contactCount = count($contactDetails);
} catch (Throwable $e) {
    error_log('Fetch contact count failed: ' . $e->getMessage());
}

// Lấy danh sách việc đã ứng tuyển
$myApplications = [];
$myApplicationsCount = 0;
try {
    $appSql = "SELECT DISTINCT m.created_at AS applied_at, p.id AS post_id, p.title, p.status, p.area, 
               u.name AS employer_name, u.avatar AS employer_avatar, u.email AS employer_email
        FROM messages m 
        JOIN posts p ON p.id = m.post_id 
        JOIN users u ON u.id = p.user_id
        WHERE m.sender_id = ? 
        AND m.message LIKE 'Sinh viên ứng tuyển:%'
        ORDER BY m.created_at DESC
        LIMIT 20";
    $appStmt = $pdo->prepare($appSql);
    $appStmt->execute([$userId]);
    $myApplications = $appStmt->fetchAll();
    $myApplicationsCount = count($myApplications);
} catch (Throwable $e) {
    error_log('Fetch my applications failed: ' . $e->getMessage());
}

// Lấy tin ứng tuyển của chính mình để hiển thị (bao gồm cả tin đang mở và đã đóng)
$recruitmentPosts = [];
try {
    $recruitStmt = $pdo->prepare('SELECT p.*, u.name AS author_name, u.avatar AS author_avatar, u.verified AS author_verified 
        FROM posts p 
        JOIN users u ON u.id = p.user_id 
        WHERE p.user_id = ? AND p.type = "application"
        ORDER BY p.created_at DESC 
        LIMIT 6');
    $recruitStmt->execute([$userId]);
    $recruitmentPosts = $recruitStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Fetch my posts failed: ' . $e->getMessage());
}

// Không ở chế độ embed để hiển thị topbar
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

/* ========== NEW WELCOME HERO BANNER ========== */
.welcome-hero-banner {
    position: relative;
    background: linear-gradient(135deg, #0D1B36 0%, #1a3a5c 50%, #0f4c75 100%);
    border-radius: 24px;
    padding: 2.5rem;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 20px 60px rgba(13, 27, 54, 0.4);
}

.welcome-hero-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
}

.hero-gradient-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.5;
}

.hero-gradient-orb.orb-1 {
    width: 300px;
    height: 300px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    top: -100px;
    right: -50px;
    animation: float-orb 8s ease-in-out infinite;
}

.hero-gradient-orb.orb-2 {
    width: 200px;
    height: 200px;
    background: linear-gradient(135deg, #10b981, #06b6d4);
    bottom: -50px;
    left: 20%;
    animation: float-orb 10s ease-in-out infinite reverse;
}

.hero-gradient-orb.orb-3 {
    width: 150px;
    height: 150px;
    background: linear-gradient(135deg, #f59e0b, #ec4899);
    top: 50%;
    left: -30px;
    animation: float-orb 12s ease-in-out infinite;
}

@keyframes float-orb {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(30px, -20px) scale(1.1); }
}

.welcome-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 2rem;
}

.welcome-hero-left {
    flex: 1;
    color: #fff;
}

.welcome-time-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    padding: 0.5rem 1rem;
    border-radius: 30px;
    font-size: 0.85rem;
    margin-bottom: 1rem;
    border: 1px solid rgba(255,255,255,0.2);
}

.welcome-hero-title {
    font-size: 2.5rem;
    font-weight: 800;
    margin: 0 0 1rem;
    background: linear-gradient(135deg, #fff 0%, #93c5fd 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.welcome-hero-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-size: 0.8rem;
    font-weight: 600;
}

.hero-badge.badge-student {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.3), rgba(139, 92, 246, 0.3));
    border: 1px solid rgba(147, 197, 253, 0.4);
    color: #93c5fd;
}

.hero-badge.badge-verified {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(6, 182, 212, 0.3));
    border: 1px solid rgba(110, 231, 183, 0.4);
    color: #6ee7b7;
}

.hero-badge.badge-unverified {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.3), rgba(239, 68, 68, 0.3));
    border: 1px solid rgba(252, 211, 77, 0.4);
    color: #fcd34d;
}

.welcome-hero-desc {
    color: rgba(255,255,255,0.8);
    font-size: 1rem;
    margin-bottom: 1.5rem;
    max-width: 500px;
}

.welcome-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.hero-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.85rem 1.5rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
}

.hero-btn-primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
}

.hero-btn-primary:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(16, 185, 129, 0.5);
    color: #fff;
}

.hero-btn-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #fff;
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
}

.hero-btn-warning:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(245, 158, 11, 0.5);
    color: #fff;
}

.hero-btn-outline {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    backdrop-filter: blur(10px);
}

.hero-btn-outline:hover {
    background: rgba(255,255,255,0.2);
    color: #fff;
}

.hero-btn-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #ef4444;
    color: #fff;
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    font-weight: 700;
}

.welcome-hero-right {
    flex-shrink: 0;
    width: 320px;
}

.hero-illustration-wrapper {
    position: relative;
}

.hero-illustration-img {
    width: 100%;
    height: 220px;
    object-fit: cover;
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}

.hero-floating-card {
    position: absolute;
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(10px);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    animation: float-card 4s ease-in-out infinite;
}

.hero-floating-card.card-stats {
    bottom: -15px;
    left: -20px;
    animation-delay: 0s;
}

.hero-floating-card.card-rating {
    top: -15px;
    right: -20px;
    animation-delay: 2s;
}

@keyframes float-card {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

.floating-card-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.card-stats .floating-card-icon {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #2563eb;
}

.card-rating .floating-card-icon {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #d97706;
}

.floating-card-content {
    display: flex;
    flex-direction: column;
}

.floating-card-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
}

.floating-card-label {
    font-size: 0.75rem;
    color: #64748b;
}

/* ========== QUICK STATS GRID ========== */
.quick-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 10;
}

.quick-stat-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
    position: relative;
    overflow: hidden;
    z-index: 11;
}

.quick-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    border-radius: 4px 0 0 4px;
}

.quick-stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.1);
}

.quick-stat-card:active {
    transform: translateY(-2px);
}

.quick-stat-card.stat-posts::before { background: linear-gradient(180deg, #10b981, #059669); }
.quick-stat-card.stat-contacts::before { background: linear-gradient(180deg, #3b82f6, #2563eb); }
.quick-stat-card.stat-messages::before { background: linear-gradient(180deg, #8b5cf6, #7c3aed); }
.quick-stat-card.stat-favorites::before { background: linear-gradient(180deg, #ec4899, #db2777); }
.quick-stat-card.stat-history::before { background: linear-gradient(180deg, #06b6d4, #0891b2); }
.quick-stat-card.stat-rating::before { background: linear-gradient(180deg, #f59e0b, #d97706); }

.stat-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    position: relative;
}

.stat-posts .stat-card-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.stat-contacts .stat-card-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #2563eb; }
.stat-messages .stat-card-icon { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
.stat-favorites .stat-card-icon { background: linear-gradient(135deg, #fce7f3, #fbcfe8); color: #db2777; }
.stat-history .stat-card-icon { background: linear-gradient(135deg, #cffafe, #a5f3fc); color: #0891b2; }
.stat-rating .stat-card-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }

.stat-notification-dot {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 10px;
    height: 10px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid #fff;
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.7; }
}

.stat-card-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.stat-card-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.2;
}

.stat-card-value small {
    font-size: 0.9rem;
    font-weight: 600;
    color: #64748b;
}

.stat-card-label {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 500;
}

.stat-card-arrow {
    color: #cbd5e1;
    font-size: 1.2rem;
    transition: all 0.3s;
}

.quick-stat-card:hover .stat-card-arrow {
    color: #3b82f6;
    transform: translateX(5px);
}


/* ========== RESPONSIVE ========== */
@media (max-width: 992px) {
    .welcome-hero-content {
        flex-direction: column;
        text-align: center;
    }
    
    .welcome-hero-left {
        order: 2;
    }
    
    .welcome-hero-right {
        order: 1;
        width: 100%;
        max-width: 300px;
    }
    
    .welcome-hero-title {
        font-size: 2rem;
    }
    
    .welcome-hero-badges {
        justify-content: center;
    }
    
    .welcome-hero-desc {
        margin-left: auto;
        margin-right: auto;
    }
    
    .welcome-hero-actions {
        justify-content: center;
    }
    
    .hero-floating-card.card-stats {
        left: 10px;
    }
    
    .hero-floating-card.card-rating {
        right: 10px;
    }
}

@media (max-width: 576px) {
    .welcome-hero-banner {
        padding: 1.5rem;
    }
    
    .welcome-hero-title {
        font-size: 1.5rem;
    }
    
    .welcome-hero-right {
        display: none;
    }
    
    .quick-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .quick-action-item {
        padding: 1rem 0.5rem;
    }
    
    .quick-action-item .action-icon {
        width: 45px;
        height: 45px;
        font-size: 1.2rem;
    }
    
    .quick-action-item span {
        font-size: 0.75rem;
    }
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

function filterFavorites() {
    var searchValue = document.getElementById('favoritesSearch').value.toLowerCase();
    var cards = document.querySelectorAll('#favoritesList .favorite-card');
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
    var countEl = document.querySelector('.search-count');
    if (countEl) {
        countEl.textContent = visibleCount + ' tin';
    }
}

function showSection(sectionId, title) {
    console.log('showSection called:', sectionId, title);
    
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
        console.log('Looking for section:', 'section-' + sectionId, section);
        
        if (section) {
            section.style.display = 'block';
            
            // Hiện loading nếu có
            var loading = document.getElementById('loading-' + sectionId);
            if (loading) loading.style.display = 'flex';
            
            // Load iframe nếu có
            var iframe = section.querySelector('iframe');
            if (iframe) {
                var iframeSrc = {
                    'create-post': 'create_application.php?embed=1',
                    'request-verification': 'request_verification.php?embed=1',
                    'verify': 'request_verification.php?embed=1',
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
                    'settings': 'edit_profile.php?tab=settings&embed=1',
                    'contacts': 'profile.php?tab=contacts&embed=1'
                };
                // Luôn load src nếu có trong mapping
                if (iframeSrc[sectionId]) {
                    console.log('Loading iframe:', iframeSrc[sectionId]);
                    iframe.src = iframeSrc[sectionId];
                }
            }
        } else {
            console.log('Section not found: section-' + sectionId);
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

// Hàm xóa bài đăng
function deletePost(postId) {
    if (confirm('Bạn có chắc chắn muốn xóa tin này không?')) {
        fetch('delete_post.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'post_id=' + postId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Xóa card khỏi DOM
                var postCard = document.getElementById('postCard' + postId);
                if (postCard) {
                    postCard.style.animation = 'fadeOut 0.3s ease';
                    setTimeout(() => postCard.remove(), 300);
                }
                alert('Đã xóa tin thành công!');
            } else {
                alert(data.message || 'Có lỗi xảy ra khi xóa tin.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Có lỗi xảy ra. Vui lòng thử lại.');
        });
    }
}

// Hàm hiển thị danh sách người đã liên hệ
function showApplicants(postId, postTitle) {
    // Tạo modal động
    var existingModal = document.getElementById('dynamicApplicantsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    var modalHTML = '<div id="dynamicApplicantsModal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:999999;display:flex;align-items:center;justify-content:center;padding:20px;">' +
        '<div style="background:#fff;border-radius:16px;width:100%;max-width:550px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
            '<div style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);padding:1rem 1.5rem;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center;">' +
                '<h5 style="margin:0;color:#fff;font-size:1rem;font-weight:600;"><i class="bi bi-people-fill"></i> Chọn người nhận việc</h5>' +
                '<button onclick="closeDynamicModal()" style="background:rgba(255,255,255,0.2);border:none;width:32px;height:32px;border-radius:50%;color:#fff;cursor:pointer;font-size:1.2rem;">&times;</button>' +
            '</div>' +
            '<div id="dynamicModalBody" style="padding:1.5rem;overflow-y:auto;flex:1;">' +
                '<div style="text-align:center;padding:2rem;"><div class="spinner-border text-primary"></div><p style="margin-top:1rem;color:#64748b;">Đang tải...</p></div>' +
            '</div>' +
            '<div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;text-align:right;">' +
                '<button onclick="closeDynamicModal()" style="padding:0.5rem 1.5rem;background:#f1f5f9;color:#64748b;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Đóng</button>' +
            '</div>' +
        '</div>' +
    '</div>';
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    document.body.style.overflow = 'hidden';
    
    // Fetch data
    fetch('get_applicants.php?post_id=' + postId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var bodyEl = document.getElementById('dynamicModalBody');
            if (bodyEl) {
                renderApplicants(data, postId, bodyEl);
            }
        })
        .catch(function(error) {
            var bodyEl = document.getElementById('dynamicModalBody');
            if (bodyEl) {
                bodyEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#dc2626;"><i class="bi bi-exclamation-triangle" style="font-size:2rem;"></i><p>Lỗi tải dữ liệu</p></div>';
            }
        });
}

function closeDynamicModal() {
    var modal = document.getElementById('dynamicApplicantsModal');
    if (modal) {
        modal.remove();
    }
    document.body.style.overflow = '';
}

function renderApplicants(data, postId, container) {
    if (!container) return;
    
    if (data.success && data.applicants && data.applicants.length > 0) {
        var html = '';
        for (var i = 0; i < data.applicants.length; i++) {
            var user = data.applicants[i];
            var safeName = (user.name || 'Người dùng').replace(/'/g, "\\'");
            html += '<div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:#f8fafc;border-radius:12px;margin-bottom:0.75rem;">' +
                '<div style="width:45px;height:45px;background:linear-gradient(135deg,#8b5cf6,#7c3aed);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;"><i class="bi bi-person-fill"></i></div>' +
                '<div style="flex:1;">' +
                    '<div style="font-weight:600;color:#1e293b;">' + (user.name || 'Người dùng') + '</div>' +
                    '<div style="font-size:0.85rem;color:#64748b;"><i class="bi bi-clock"></i> ' + (user.contact_time || 'Đã liên hệ') + '</div>' +
                '</div>' +
                '<div style="display:flex;gap:0.5rem;">' +
                    '<a href="chat.php?user=' + user.id + '" style="padding:0.4rem 0.75rem;background:#eff6ff;color:#3b82f6;border-radius:6px;text-decoration:none;font-size:0.85rem;"><i class="bi bi-chat-fill"></i></a>' +
                    '<button onclick="acceptApplicant(' + postId + ',' + user.id + ',\'' + safeName + '\')" style="padding:0.4rem 0.75rem;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:600;"><i class="bi bi-check-lg"></i> Chọn</button>' +
                '</div>' +
            '</div>';
        }
        container.innerHTML = html;
    } else {
        container.innerHTML = '<div style="text-align:center;padding:2rem;">' +
            '<div style="width:80px;height:80px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;"><i class="bi bi-people" style="font-size:2rem;color:#94a3b8;"></i></div>' +
            '<h6 style="color:#64748b;margin-bottom:0.5rem;">Chưa có ai liên hệ</h6>' +
            '<p style="color:#94a3b8;font-size:0.85rem;margin:0;">Khi có người quan tâm, họ sẽ xuất hiện ở đây.</p>' +
        '</div>';
    }
}

// Hàm chọn người và đóng tin
function acceptApplicant(postId, userId, userName) {
    if (confirm('Bạn muốn chọn "' + userName + '" cho tin này?\n\nTin sẽ được đóng sau khi chọn.')) {
        fetch('accept_applicant.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'post_id=' + postId + '&user_id=' + userId
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                alert('Đã chọn "' + userName + '" thành công!\nTin của bạn đã được đóng.');
                closeDynamicModal();
                location.reload();
            } else {
                alert(data.message || 'Có lỗi xảy ra.');
            }
        })
        .catch(function(error) {
            alert('Lỗi kết nối. Vui lòng thử lại.');
        });
    }
}

// Hàm thay đổi trạng thái tin
function togglePostStatus(postId, newStatus) {
    fetch('toggle_post_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'post_id=' + postId + '&status=' + newStatus
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload trang để cập nhật UI
            location.reload();
        } else {
            alert(data.message || 'Có lỗi xảy ra.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Có lỗi xảy ra. Vui lòng thử lại.');
    });
}

// Hàm cập nhật số lượng đã chọn
function updateSelectedCount() {
    var checkboxes = document.querySelectorAll('.post-select-checkbox:checked');
    var countEl = document.getElementById('selectedCount');
    var btnDelete = document.getElementById('btnBulkDelete');
    if (countEl) countEl.textContent = checkboxes.length + ' tin đã chọn';
    if (btnDelete) btnDelete.disabled = checkboxes.length === 0;
}

// Hàm xóa nhiều tin
function bulkDeletePosts() {
    var checkboxes = document.querySelectorAll('.post-select-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Vui lòng chọn ít nhất một tin để xóa.');
        return;
    }
    
    if (confirm('Bạn có chắc chắn muốn xóa ' + checkboxes.length + ' tin đã chọn?')) {
        var postIds = Array.from(checkboxes).map(cb => cb.value);
        
        // Xóa từng tin
        var deletePromises = postIds.map(id => {
            return fetch('delete_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'post_id=' + id
            });
        });
        
        Promise.all(deletePromises)
            .then(() => {
                alert('Đã xóa ' + postIds.length + ' tin thành công!');
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Có lỗi xảy ra. Vui lòng thử lại.');
            });
    }
}

// Hàm chọn tất cả
function toggleSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('.post-select-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateSelectedCount();
}

// Hàm lưu cài đặt
function saveSettings() {
    var settings = {
        notifyEmail: document.getElementById('notifyEmail')?.checked || false,
        notifyMessage: document.getElementById('notifyMessage')?.checked || false,
        notifyJob: document.getElementById('notifyJob')?.checked || false,
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

// Event delegation cho nút Nhận - đảm bảo hoạt động trong mọi trường hợp
document.addEventListener('DOMContentLoaded', function() {
    console.log('Event delegation initialized');
    
    document.body.addEventListener('click', function(e) {
        // Tìm nút btn-accept gần nhất
        var btn = e.target.closest('.btn-accept');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            
            // Lấy postId và postTitle từ data attributes
            var postId = btn.getAttribute('data-post-id');
            var postTitle = btn.getAttribute('data-post-title');
            
            console.log('Button clicked! postId:', postId, 'postTitle:', postTitle);
            
            if (postId && postTitle) {
                showApplicants(parseInt(postId), postTitle);
            } else {
                alert('Lỗi: Không tìm thấy thông tin bài đăng. postId=' + postId + ', postTitle=' + postTitle);
            }
        }
    });
});
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
                <?php if ($canPost): ?>
                <a href="#" class="sidebar-menu-item" data-section="create-post" onclick="return showSection('create-post', 'Tạo tin ứng tuyển')">
                    <i class="bi bi-plus-circle-fill"></i>
                    <span>Tạo tin mới</span>
                </a>
                <?php else: ?>
                <a href="#" class="sidebar-menu-item" data-section="verify" onclick="return showSection('verify', 'Xác minh để đăng tin')">
                    <i class="bi bi-shield-check"></i>
                    <span>Xác minh để đăng</span>
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
                    <?php if ($recentAcceptCount > 0): ?>
                    <span class="badge bg-success"><?php echo $recentAcceptCount; ?></span>
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
            
            <!-- Thống kê -->
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">Thống kê</div>
                <a href="#" class="sidebar-menu-item" data-section="contacts" onclick="return showSection('contacts', 'Lượt liên hệ')">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Lượt liên hệ</span>
                    <?php if ($contactCount > 0): ?>
                    <span class="badge bg-primary"><?php echo $contactCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="sidebar-menu-item" data-section="ratings" onclick="return showSection('ratings', 'Đánh giá')">
                    <i class="bi bi-star-fill"></i>
                    <span>Đánh giá của tôi</span>
                    <?php if ($ratingTotal > 0): ?>
                    <span class="badge bg-warning text-dark"><?php echo $ratingTotal; ?></span>
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
                <span id="breadcrumb-current">Sinh viên Y khoa</span>
            </div>
        </div>
        
        <!-- Welcome Section - Hiển thị mặc định -->
        <div class="dashboard-welcome-section p-4">
            <!-- Hero Welcome Banner -->
            <div class="welcome-hero-banner">
                <div class="welcome-hero-bg">
                    <div class="hero-gradient-orb orb-1"></div>
                    <div class="hero-gradient-orb orb-2"></div>
                    <div class="hero-gradient-orb orb-3"></div>
                </div>
                <div class="welcome-hero-content">
                    <div class="welcome-hero-left">
                        <div class="welcome-time-badge">
                            <i class="bi bi-<?php echo date('H') < 12 ? 'sunrise' : (date('H') < 18 ? 'sun' : 'moon-stars'); ?>"></i>
                            <?php echo date('H') < 12 ? 'Chào buổi sáng' : (date('H') < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'); ?>
                        </div>
                        <h1 class="welcome-hero-title"><?php echo htmlspecialchars($displayName); ?>!</h1>
                        <div class="welcome-hero-badges">
                            <span class="hero-badge badge-student">
                                <i class="bi bi-mortarboard-fill"></i> Sinh viên Y khoa
                            </span>
                            <?php if ($verifiedFlag): ?>
                            <span class="hero-badge badge-verified">
                                <i class="bi bi-patch-check-fill"></i> Đã xác minh
                            </span>
                            <?php else: ?>
                            <span class="hero-badge badge-unverified">
                                <i class="bi bi-shield-exclamation"></i> Chưa xác minh
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="welcome-hero-desc">
                            Chào mừng bạn đến với hệ thống kết nối y tế. Hãy khám phá các tính năng bên dưới!
                        </p>
                        <div class="welcome-hero-actions">
                            <?php if ($canPost): ?>
                            <a href="#" onclick="showSection('create-post', 'Tạo tin ứng tuyển'); return false;" class="hero-btn hero-btn-primary">
                                <i class="bi bi-plus-lg"></i> Tạo tin ứng tuyển
                            </a>
                            <?php else: ?>
                            <a href="#" onclick="showSection('verify', 'Xác minh để đăng tin'); return false;" class="hero-btn hero-btn-warning">
                                <i class="bi bi-shield-check"></i> Xác minh để đăng tin
                            </a>
                            <?php endif; ?>
                            <a href="#" onclick="showSection('messages', 'Tin nhắn'); return false;" class="hero-btn hero-btn-outline">
                                <i class="bi bi-chat-dots"></i> Tin nhắn
                                <?php if ($messageCount > 0): ?>
                                <span class="hero-btn-badge"><?php echo $messageCount; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                    <div class="welcome-hero-right">
                        <div class="hero-illustration-wrapper">
                            <img src="Ảnh Giao diện/Sinh Viên Y Khám Bệnh Tại Nhà.jpg" alt="Sinh viên Y khoa" class="hero-illustration-img" onerror="this.style.display='none'">
                            <div class="hero-floating-card card-stats">
                                <div class="floating-card-icon"><i class="bi bi-graph-up-arrow"></i></div>
                                <div class="floating-card-content">
                                    <span class="floating-card-value"><?php echo $contactCount; ?></span>
                                    <span class="floating-card-label">Lượt liên hệ</span>
                                </div>
                            </div>
                            <div class="hero-floating-card card-rating">
                                <div class="floating-card-icon"><i class="bi bi-star-fill"></i></div>
                                <div class="floating-card-content">
                                    <span class="floating-card-value"><?php echo $avgRating ? $avgRating : '0'; ?>/5</span>
                                    <span class="floating-card-label">Đánh giá</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats Grid -->
            <div class="quick-stats-grid">
                <div class="quick-stat-card stat-posts">
                    <div class="stat-card-icon">
                        <i class="bi bi-file-earmark-medical-fill"></i>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo $postsCount; ?></span>
                        <span class="stat-card-label">Tin đăng</span>
                    </div>
                </div>
                <div class="quick-stat-card stat-contacts">
                    <div class="stat-card-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo $contactCount; ?></span>
                        <span class="stat-card-label">Liên hệ</span>
                    </div>
                </div>
                <div class="quick-stat-card stat-messages">
                    <div class="stat-card-icon">
                        <i class="bi bi-envelope-fill"></i>
                        <?php if ($messageCount > 0): ?>
                        <span class="stat-notification-dot"></span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo $messageCount; ?></span>
                        <span class="stat-card-label">Tin nhắn mới</span>
                    </div>
                </div>
                <div class="quick-stat-card stat-favorites">
                    <div class="stat-card-icon">
                        <i class="bi bi-heart-fill"></i>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo count($favoritePosts); ?></span>
                        <span class="stat-card-label">Yêu thích</span>
                    </div>
                </div>
                <div class="quick-stat-card stat-history">
                    <div class="stat-card-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo $recentAcceptCount; ?></span>
                        <span class="stat-card-label">Việc đã nhận</span>
                    </div>
                </div>
                <div class="quick-stat-card stat-rating">
                    <div class="stat-card-icon">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <div class="stat-card-info">
                        <span class="stat-card-value"><?php echo $avgRating ? $avgRating : '0'; ?><small>/5</small></span>
                        <span class="stat-card-label"><?php echo $ratingTotal; ?> đánh giá</span>
                    </div>
                </div>
            </div>
            
            <!-- Search Posts Section -->
            <div class="search-posts-section mt-4 mb-4">
                <div class="search-posts-card">
                    <div class="search-posts-header">
                        <div class="search-posts-icon">
                            <i class="bi bi-file-earmark-medical"></i>
                        </div>
                        <h3>Tin đăng của tôi</h3>
                    </div>
                    
                    <?php if (empty($recruitmentPosts)): ?>
                    <!-- Empty State -->
                    <div class="search-posts-empty">
                        <div class="empty-icon">
                            <i class="bi bi-file-earmark-plus"></i>
                        </div>
                        <h5>Chưa có tin đăng nào</h5>
                        <p>Bạn chưa đăng tin nào. Hãy tạo tin ứng tuyển để bệnh nhân có thể tìm thấy bạn!</p>
                        <?php if ($canPost): ?>
                        <a href="#" onclick="showSection('create-post', 'Tạo tin ứng tuyển')" class="btn-view-all" style="margin-top: 1rem;">
                            <i class="bi bi-plus-circle"></i> Tạo tin ứng tuyển
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Posts Grid -->
                    <div class="recruitment-posts-grid">
                        <?php foreach ($recruitmentPosts as $rPost): ?>
                        <div class="recruitment-post-card">
                            <div class="rpost-header">
                                <div class="rpost-author">
                                    <?php if (!empty($rPost['author_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($rPost['author_avatar']); ?>" alt="Avatar" class="rpost-avatar" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="rpost-avatar-placeholder" style="display:none;"><?php echo strtoupper(mb_substr($rPost['author_name'], 0, 1)); ?></div>
                                    <?php else: ?>
                                    <div class="rpost-avatar-placeholder"><?php echo strtoupper(mb_substr($rPost['author_name'], 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div class="rpost-author-info">
                                        <span class="rpost-author-name">
                                            <?php echo htmlspecialchars($rPost['author_name']); ?>
                                            <?php if ($rPost['author_verified']): ?>
                                            <i class="bi bi-patch-check-fill rpost-verified"></i>
                                            <?php endif; ?>
                                        </span>
                                        <span class="rpost-role <?php echo $rPost['status'] === 'open' ? 'status-open' : 'status-closed'; ?>">
                                            <?php echo $rPost['status'] === 'open' ? '● Đang mở' : '○ Đã đóng'; ?>
                                        </span>
                                    </div>
                                </div>
                                <span class="rpost-date"><i class="bi bi-calendar3"></i> <?php echo date('d/m', strtotime($rPost['created_at'])); ?></span>
                            </div>
                            <h5 class="rpost-title"><?php echo htmlspecialchars($rPost['title']); ?></h5>
                            <p class="rpost-content"><?php echo htmlspecialchars(mb_substr(strip_tags($rPost['content']), 0, 100)); ?>...</p>
                            <div class="rpost-meta">
                                <?php if (!empty($rPost['area'])): ?>
                                <div class="rpost-location"><i class="bi bi-geo-alt-fill"></i> <?php echo htmlspecialchars($rPost['area']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($rPost['category'])): ?>
                                <div class="rpost-category"><i class="bi bi-bookmark-fill"></i> <?php echo htmlspecialchars($rPost['category']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="rpost-actions">
                                <a href="view_post.php?id=<?php echo $rPost['id']; ?>" class="rpost-btn rpost-btn-view">
                                    <i class="bi bi-eye"></i> Xem
                                </a>
                                <a href="edit_post.php?id=<?php echo $rPost['id']; ?>" class="rpost-btn rpost-btn-edit">
                                    <i class="bi bi-pencil"></i> Sửa
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="view-all-posts">
                        <a href="#" onclick="showSection('my-posts', 'Tin của tôi')" class="btn-view-all">
                            <i class="bi bi-grid-3x3-gap-fill"></i> Xem tất cả tin của tôi
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Tips Section -->
            <div class="tips-section mt-4">
                <div class="tips-card">
                    <div class="tips-header">
                        <div class="tips-icon">
                            <i class="bi bi-lightbulb-fill"></i>
                        </div>
                        <h5>Mẹo để được chọn nhiều hơn</h5>
                    </div>
                    <div class="tips-list">
                        <div class="tip-item">
                            <div class="tip-number">1</div>
                            <div class="tip-content">
                                <strong>Hoàn thiện hồ sơ</strong>
                                <p>Thêm ảnh đại diện chuyên nghiệp và mô tả chi tiết kinh nghiệm của bạn</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">2</div>
                            <div class="tip-content">
                                <strong>Xác minh tài khoản</strong>
                                <p>Tài khoản được xác minh sẽ được ưu tiên hiển thị và tăng độ tin cậy</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">3</div>
                            <div class="tip-content">
                                <strong>Phản hồi nhanh</strong>
                                <p>Trả lời tin nhắn từ bệnh nhân trong vòng 24 giờ để tăng cơ hội</p>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-number">4</div>
                            <div class="tip-content">
                                <strong>Thu thập đánh giá tốt</strong>
                                <p>Phục vụ tận tâm để nhận được đánh giá 5 sao từ bệnh nhân</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
                            <h4>Tin ứng tuyển của tôi</h4>
                            <p><?php echo $postsCount; ?> tin đăng</p>
                        </div>
                    </div>
                    <?php if ($canPost): ?>
                    <a href="#" onclick="showSection('create-post', 'Tạo tin ứng tuyển')" class="btn-create-post">
                        <i class="bi bi-plus-lg"></i> Tạo tin mới
                    </a>
                    <?php endif; ?>
                </div>
                
                <!-- Bulk Actions Toolbar -->
                <?php if (!empty($posts)): ?>
                <div class="bulk-actions-toolbar" id="bulkActionsToolbar">
                    <div class="bulk-select-all">
                        <label class="custom-checkbox">
                            <input type="checkbox" id="selectAllPosts" onchange="toggleSelectAll(this)">
                            <span class="checkmark"></span>
                            <span class="label-text">Chọn tất cả</span>
                        </label>
                    </div>
                    <div class="bulk-actions-right">
                        <span class="selected-count" id="selectedCount">0 tin đã chọn</span>
                        <button class="btn-bulk-delete" id="btnBulkDelete" onclick="bulkDeletePosts()" disabled>
                            <i class="bi bi-trash3"></i> Xóa đã chọn
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($posts)): ?>
                <!-- Empty State -->
                <div class="my-posts-empty">
                    <div class="empty-illustration">
                        <i class="bi bi-file-earmark-plus"></i>
                    </div>
                    <h5>Chưa có tin ứng tuyển</h5>
                    <p>Bạn chưa đăng tin ứng tuyển nào. Hãy tạo tin để bệnh nhân có thể tìm thấy bạn!</p>
                    <?php if ($canPost): ?>
                    <a href="#" onclick="showSection('create-post', 'Tạo tin ứng tuyển')" class="btn-empty-create">
                        <i class="bi bi-plus-circle"></i> Tạo tin ứng tuyển đầu tiên
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Posts List -->
                <div class="my-posts-list">
                    <?php foreach ($posts as $index => $post): ?>
                    <div class="post-card" id="postCard<?php echo $post['id']; ?>" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="post-card-main">
                            <label class="custom-checkbox post-checkbox">
                                <input type="checkbox" class="post-select-checkbox" value="<?php echo $post['id']; ?>" onchange="updateSelectedCount()">
                                <span class="checkmark"></span>
                            </label>
                            <div class="post-status-indicator <?php echo $post['status'] === 'open' ? 'status-open' : ($post['status'] === 'taken' ? 'status-taken' : 'status-closed'); ?>"></div>
                            <div class="post-info">
                                <h5 class="post-title"><?php echo htmlspecialchars($post['title']); ?></h5>
                                <div class="post-meta">
                                    <span class="meta-item">
                                        <i class="bi bi-calendar3"></i>
                                        <?php echo date('d/m/Y', strtotime($post['created_at'])); ?>
                                    </span>
                                    <span class="post-status-badge <?php echo $post['status'] === 'open' ? 'badge-open' : ($post['status'] === 'taken' ? 'badge-taken' : 'badge-closed'); ?>">
                                        <i class="bi bi-<?php echo $post['status'] === 'open' ? 'broadcast' : ($post['status'] === 'taken' ? 'check-circle' : 'pause-circle'); ?>"></i>
                                        <?php echo $post['status'] === 'open' ? 'Đang mở' : ($post['status'] === 'taken' ? 'Đã nhận việc' : 'Đã đóng'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="post-card-actions">
                            <?php if ($post['status'] === 'open'): ?>
                            <button type="button" class="action-btn btn-applicants" title="Chọn người nhận việc" onclick="showApplicants(<?php echo $post['id']; ?>, '<?php echo htmlspecialchars(addslashes($post['title'])); ?>')">
                                <i class="bi bi-check-circle-fill"></i>
                                <span>Nhận</span>
                            </button>
                            <?php endif; ?>
                            <a href="view_post.php?id=<?php echo $post['id']; ?>" class="action-btn btn-view" title="Xem chi tiết">
                                <i class="bi bi-eye"></i>
                                <span>Xem</span>
                            </a>
                            <a href="edit_post.php?id=<?php echo $post['id']; ?>" class="action-btn btn-edit" title="Chỉnh sửa">
                                <i class="bi bi-pencil"></i>
                                <span>Sửa</span>
                            </a>
                            <?php if ($post['status'] === 'closed' || $post['status'] === 'taken'): ?>
                            <a href="javascript:void(0)" class="action-btn btn-reopen" title="Mở lại tin" onclick="togglePostStatus(<?php echo $post['id']; ?>, 'open')">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                <span>Mở lại</span>
                            </a>
                            <?php endif; ?>
                            <a href="javascript:void(0)" class="action-btn btn-delete" title="Xóa tin" onclick="deletePost(<?php echo $post['id']; ?>)">
                                <i class="bi bi-trash3"></i>
                                <span>Xóa</span>
                            </a>
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-create-post:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
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
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .my-posts-empty .empty-illustration i {
            font-size: 3rem;
            color: #10b981;
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
            border-color: #10b981;
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
        
        /* Bulk Actions Toolbar */
        .bulk-actions-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .bulk-select-all {
            display: flex;
            align-items: center;
        }
        
        .bulk-actions-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .selected-count {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .btn-bulk-delete {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-bulk-delete:hover:not(:disabled) {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }
        
        .btn-bulk-delete:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Custom Checkbox */
        .custom-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            user-select: none;
        }
        
        .custom-checkbox input[type="checkbox"] {
            display: none;
        }
        
        .custom-checkbox .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            background: white;
        }
        
        .custom-checkbox:hover .checkmark {
            border-color: #10b981;
        }
        
        .custom-checkbox input[type="checkbox"]:checked + .checkmark {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #059669;
        }
        
        .custom-checkbox input[type="checkbox"]:checked + .checkmark::after {
            content: '✓';
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .custom-checkbox .label-text {
            font-size: 0.875rem;
            font-weight: 500;
            color: #475569;
        }
        
        .post-checkbox {
            margin-right: 0.5rem;
        }
        
        .post-card.selected {
            border-color: #10b981;
            background: #f0fdf4;
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
            position: relative;
            z-index: 10;
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
        
        .action-btn.btn-applicants {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
            border: none;
            cursor: pointer;
        }
        
        .action-btn.btn-applicants:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }
        
        /* Status badges */
        .post-status-badge.badge-taken {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .post-status-indicator.status-taken {
            background: linear-gradient(180deg, #10b981 0%, #059669 100%);
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
        
        <style>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: flex-end;
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
        
        /* Recruitment Posts Grid */
        .recruitment-posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .recruitment-post-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .recruitment-post-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .recruitment-post-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(102, 126, 234, 0.2);
            border-color: rgba(102, 126, 234, 0.3);
        }
        
        .recruitment-post-card:hover::before {
            opacity: 1;
        }
        
        .rpost-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f0f0f5;
        }
        
        .rpost-author {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .rpost-avatar {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            object-fit: cover;
            border: 2px solid #e8f0ff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        
        .rpost-avatar-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 700;
            border: 2px solid #e8f0ff;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .rpost-author-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        
        .rpost-author-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .rpost-verified {
            color: #3b82f6;
            font-size: 0.9rem;
        }
        
        .rpost-role {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .rpost-date {
            font-size: 0.8rem;
            color: #667eea;
            background: linear-gradient(135deg, #e8f0ff 0%, #f0e8ff 100%);
            padding: 0.4rem 0.75rem;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .rpost-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 0.75rem;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .rpost-content {
            font-size: 0.9rem;
            color: #64748b;
            margin: 0 0 1rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .rpost-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
        }
        
        .rpost-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: #475569;
            padding: 0.5rem 0.75rem;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .rpost-meta-item i {
            color: #667eea;
            font-size: 1rem;
        }
        
        .rpost-location, .rpost-category {
            font-size: 0.8rem;
            color: #475569;
            background: #f8fafc;
            padding: 0.5rem 0.75rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .rpost-location i, .rpost-category i {
            color: #667eea;
        }
        
        .rpost-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.25rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .rpost-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.45);
            color: white;
        }
        
        .rpost-btn i {
            font-size: 1rem;
        }
        
        /* Post Actions */
        .rpost-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .rpost-btn-view {
            flex: 1;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .rpost-btn-view:hover {
            box-shadow: 0 6px 18px rgba(59, 130, 246, 0.4);
        }
        
        .rpost-btn-edit {
            flex: 1;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .rpost-btn-edit:hover {
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.4);
        }
        
        /* Status colors */
        .rpost-role.status-open {
            color: #10b981;
            font-weight: 600;
        }
        
        .rpost-role.status-closed {
            color: #94a3b8;
            font-weight: 600;
        }
        
        .view-all-posts {
            text-align: center;
            margin-top: 2rem;
        }
        
        .btn-view-all {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.875rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-view-all:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            color: white;
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
        </style>
        
        <style>
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
            background: linear-gradient(135deg, #f59e0b, #d97706);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
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
                            <p><?php echo count($recentAcceptances); ?> lượt nhận việc trong 30 ngày</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($recentAcceptances)): ?>
                <!-- Empty State -->
                <div class="history-empty">
                    <div class="empty-illustration" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
                        <i class="bi bi-calendar-x" style="color: #10b981;"></i>
                    </div>
                    <h5>Chưa có lịch sử nhận việc</h5>
                    <p>Khi bạn được chọn nhận việc, lịch sử sẽ được ghi lại tại đây</p>
                </div>
                <?php else: ?>
                <!-- History List -->
                <div class="history-list">
                    <?php foreach ($recentAcceptances as $index => $acc): ?>
                    <div class="history-card" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="history-card-main">
                            <div class="history-icon">
                                <i class="bi bi-person-check-fill"></i>
                            </div>
                            <div class="history-info">
                                <h5 class="history-title"><?php echo htmlspecialchars($acc['title']); ?></h5>
                                <div class="history-meta">
                                    <span class="meta-item">
                                        <i class="bi bi-person"></i>
                                        <?php echo htmlspecialchars($acc['owner_name']); ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="bi bi-calendar3"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($acc['accepted_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="history-card-actions">
                            <a href="view_post.php?id=<?php echo $acc['post_id']; ?>" class="action-btn btn-view" title="Xem tin">
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
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
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
        
        .search-count {
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
            animation: slideIn 0.5s ease forwards;
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
        
        @keyframes slideIn {
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
            
            .favorite-card-header {
                flex-direction: column;
            }
            
            .favorite-card-date {
                align-self: flex-start;
            }
        }
        </style>
        
        <!-- Section: Việc đã ứng tuyển -->
        <div class="dashboard-section" id="section-my-applications" style="display: none;">
            <div class="applications-container">
                <!-- Header -->
                <div class="applications-header">
                    <div class="header-left">
                        <div class="header-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                            <i class="bi bi-briefcase-fill"></i>
                        </div>
                        <div>
                            <h4>Việc đã ứng tuyển</h4>
                            <p><?php echo $myApplicationsCount; ?> việc đã ứng tuyển</p>
                        </div>
                    </div>
                    <a href="index.php?type=recruitment#posts" class="btn-find-jobs">
                        <i class="bi bi-search"></i> Tìm việc mới
                    </a>
                </div>

                <?php if (empty($myApplications)): ?>
                <!-- Empty State -->
                <div class="applications-empty">
                    <div class="empty-illustration" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
                        <i class="bi bi-briefcase" style="color: #d97706;"></i>
                    </div>
                    <h5>Chưa ứng tuyển việc nào</h5>
                    <p>Hãy tìm kiếm và ứng tuyển vào các tin tuyển dụng phù hợp với bạn</p>
                    <a href="index.php?type=recruitment#posts" class="btn-explore">
                        <i class="bi bi-compass"></i> Khám phá việc làm
                    </a>
                </div>
                <?php else: ?>
                <!-- Applications List -->
                <div class="applications-list">
                    <?php foreach ($myApplications as $index => $app): ?>
                    <div class="application-card" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="application-card-main">
                            <div class="application-icon <?php echo $app['status'] === 'taken' ? 'status-taken' : ($app['status'] === 'closed' ? 'status-closed' : 'status-open'); ?>">
                                <?php if ($app['status'] === 'taken'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php elseif ($app['status'] === 'closed'): ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-hourglass-split"></i>
                                <?php endif; ?>
                            </div>
                            <div class="application-info">
                                <h5 class="application-title"><?php echo htmlspecialchars($app['title']); ?></h5>
                                <div class="application-meta">
                                    <span class="meta-item">
                                        <i class="bi bi-person"></i>
                                        <?php echo htmlspecialchars($app['employer_name']); ?>
                                    </span>
                                    <?php if (!empty($app['area'])): ?>
                                    <span class="meta-item">
                                        <i class="bi bi-geo-alt"></i>
                                        <?php echo htmlspecialchars($app['area']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="meta-item">
                                        <i class="bi bi-calendar3"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($app['applied_at'])); ?>
                                    </span>
                                </div>
                                <div class="application-status">
                                    <?php if ($app['status'] === 'taken'): ?>
                                        <span class="status-badge status-taken"><i class="bi bi-check-circle"></i> Đã có người nhận</span>
                                    <?php elseif ($app['status'] === 'closed'): ?>
                                        <span class="status-badge status-closed"><i class="bi bi-x-circle"></i> Tin đã đóng</span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending"><i class="bi bi-clock"></i> Đang chờ phản hồi</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="application-card-actions">
                            <a href="view_post.php?id=<?php echo $app['post_id']; ?>" class="action-btn btn-view" title="Xem tin">
                                <i class="bi bi-eye"></i>
                                <span>Xem tin</span>
                            </a>
                            <a href="chat.php?user_id=<?php echo $app['employer_email']; ?>" class="action-btn btn-chat" title="Nhắn tin">
                                <i class="bi bi-chat-dots"></i>
                                <span>Nhắn tin</span>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* Applications Container */
        .applications-container {
            padding: 1.5rem;
        }
        
        .applications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .applications-header .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .applications-header .header-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .applications-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
        }
        
        .applications-header p {
            margin: 0;
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.85);
        }
        
        .btn-find-jobs {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }
        
        .btn-find-jobs:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
            color: white;
        }
        
        /* Empty State */
        .applications-empty {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 16px;
            border: 2px dashed #e2e8f0;
        }
        
        .applications-empty .empty-illustration {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .applications-empty .empty-illustration i {
            font-size: 3rem;
        }
        
        .applications-empty h5 {
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .applications-empty p {
            color: #64748b;
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        .btn-explore {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 1.5rem;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }
        
        .btn-explore:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
            color: white;
        }
        
        /* Applications List */
        .applications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .application-card {
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
        
        .application-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-color: #f59e0b;
        }
        
        .application-card-main {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }
        
        .application-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .application-icon.status-open {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .application-icon.status-taken {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .application-icon.status-closed {
            background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
        }
        
        .application-info {
            flex: 1;
            min-width: 0;
        }
        
        .application-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .application-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
        }
        
        .application-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .application-status {
            margin-top: 0.5rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-badge.status-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .status-badge.status-taken {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .status-badge.status-closed {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #64748b;
        }
        
        .application-card-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        .application-card-actions .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .application-card-actions .btn-view {
            background: #f1f5f9;
            color: #475569;
        }
        
        .application-card-actions .btn-view:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        .application-card-actions .btn-chat {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .application-card-actions .btn-chat:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        @media (max-width: 768px) {
            .applications-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .application-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .application-card-actions {
                justify-content: center;
                margin-top: 0.5rem;
                padding-top: 1rem;
                border-top: 1px solid #f1f5f9;
            }
            
            .application-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
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
                    <a href="index.php?type=recruitment#posts" class="btn-find-posts">
                        <i class="bi bi-search"></i> Tìm tin tuyển
                    </a>
                </div>
                <?php else: ?>
                <!-- Search Box -->
                <div class="favorites-search-box mb-4">
                    <div class="search-input-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="favoritesSearch" class="favorites-search-input" placeholder="Tìm kiếm trong danh sách yêu thích..." onkeyup="filterFavorites()">
                        <span class="search-count"><?php echo count($favoritePosts); ?> tin</span>
                    </div>
                </div>
                
                <!-- Favorites List -->
                <div class="favorites-list" id="favoritesList">
                    <?php foreach ($favoritePosts as $index => $fav): ?>
                    <div class="favorite-card" data-title="<?php echo htmlspecialchars(strtolower($fav['title'])); ?>" data-author="<?php echo htmlspecialchars(strtolower($fav['author_name'])); ?>" style="animation-delay: <?php echo $index * 0.05; ?>s">
                        <div class="favorite-card-header">
                            <div class="favorite-card-title">
                                <h6><?php echo htmlspecialchars($fav['title']); ?></h6>
                                <p class="favorite-card-author">
                                    <i class="bi bi-person-circle"></i>
                                    <?php echo htmlspecialchars($fav['author_name']); ?>
                                </p>
                            </div>
                            <div class="favorite-card-date">
                                <small><?php echo date('d/m/Y', strtotime($fav['favorited_at'])); ?></small>
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
        
        <!-- Section: Lượt liên hệ -->
        <div class="dashboard-section" id="section-contacts" style="display: none;">
            <div class="section-content p-4">
                <div class="stats-header mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stats-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <h4 class="mb-0">Thống kê lượt liên hệ</h4>
                            <p class="text-muted mb-0">Xem thống kê các lượt liên hệ từ bệnh nhân</p>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-cards-grid mb-4">
                    <div class="stat-card-item blue">
                        <div class="stat-card-icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="stat-card-info">
                            <span class="stat-card-value"><?php echo $contactCount; ?></span>
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
                            <span class="stat-card-value"><?php echo $recentAcceptCount; ?></span>
                            <span class="stat-card-label">Đã nhận việc</span>
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
                    $recentContactStmt = $pdo->prepare('
                        SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar, p.title AS post_title
                        FROM messages m
                        JOIN users u ON u.id = m.sender_id
                        LEFT JOIN posts p ON p.id = m.post_id
                        WHERE m.receiver_id = ? AND m.sender_id != ?
                        ORDER BY m.created_at DESC
                        LIMIT 10
                    ');
                    $recentContactStmt->execute([$userId, $userId]);
                    $recentContacts = $recentContactStmt->fetchAll();
                    ?>
                    
                    <?php if (empty($recentContacts)): ?>
                    <div class="contacts-empty">
                        <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                        <h5>Chưa có liên hệ</h5>
                        <p>Bạn chưa nhận được liên hệ nào từ bệnh nhân</p>
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
        
        <!-- Section: Đánh giá -->
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
                            <p>Xem các đánh giá từ bệnh nhân</p>
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
                    <p>Khi bệnh nhân đánh giá bạn, các đánh giá sẽ hiển thị ở đây</p>
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
                                        <i class="bi bi-heart-pulse"></i> Bệnh nhân
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
            color: #ef4444;
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
        
        <!-- Section: Xem tin tuyển -->
        <div class="dashboard-section" id="section-search" style="display: none;">
            <iframe id="iframe-search" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Tạo tin ứng tuyển -->
        <div class="dashboard-section" id="section-create-post" style="display: none;">
            <iframe id="iframe-create-post" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Xác minh để đăng tin -->
        <div class="dashboard-section" id="section-verify" style="display: none;">
            <iframe id="iframe-verify" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Tin nhắn -->
        <div class="dashboard-section" id="section-messages" style="display: none;">
            <iframe id="iframe-messages" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <!-- Section: Thông báo -->
        <div class="dashboard-section" id="section-notifications" style="display: none;">
            <iframe id="iframe-notifications" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
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
        
        <!-- Section: Cài đặt -->
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
                                <input class="form-check-input" type="checkbox" id="notifyJob" checked>
                                <label class="form-check-label" for="notifyJob">
                                    <i class="bi bi-briefcase me-2"></i>Thông báo việc làm phù hợp
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
        
        <!-- Section: Hỗ trợ -->
        <div class="dashboard-section" id="section-support" style="display: none;">
            <iframe id="iframe-support" src="" style="width:100%;height:calc(100vh - 120px);border:none;"></iframe>
        </div>
        
        <style>
        .iframe-loading p {
            margin-top: 1rem;
            font-size: 0.95rem;
        }
        </style>

        
        <style>
        /* Verify Container New */
        .verify-container-new {
            padding: 1.5rem;
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* Header mới */
        .verify-header-new {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(245, 158, 11, 0.3);
        }
        
        .verify-header-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
        }
        
        .verify-header-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
        }
        
        .verify-header-shape.shape-1 {
            width: 200px;
            height: 200px;
            top: -100px;
            right: -50px;
        }
        
        .verify-header-shape.shape-2 {
            width: 150px;
            height: 150px;
            bottom: -80px;
            left: 20%;
        }
        
        .verify-header-content {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }
        
        .verify-icon-wrapper {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #fff;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .verify-header-text h3 {
            margin: 0 0 0.25rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
        }
        
        .verify-header-text p {
            margin: 0;
            color: rgba(255,255,255,0.9);
            font-size: 0.95rem;
        }
        
        /* Status Cards New */
        .verify-status-card-new {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .verify-status-card-new.pending {
            border-left: 5px solid #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fff 100%);
        }
        
        .verify-status-card-new.rejected {
            border-left: 5px solid #ef4444;
            background: linear-gradient(135deg, #fef2f2 0%, #fff 100%);
        }
        
        .status-animation {
            position: relative;
        }
        
        .status-icon-new {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            flex-shrink: 0;
        }
        
        .verify-status-card-new.pending .status-icon-new {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706;
        }
        
        .verify-status-card-new.rejected .status-icon-new {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
        }
        
        .pulse-ring-status {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100px;
            height: 100px;
            border: 3px solid #f59e0b;
            border-radius: 20px;
            animation: pulseStatus 2s ease-in-out infinite;
            opacity: 0;
        }
        
        @keyframes pulseStatus {
            0% { transform: translate(-50%, -50%) scale(0.8); opacity: 0.8; }
            100% { transform: translate(-50%, -50%) scale(1.3); opacity: 0; }
        }
        
        .status-content-new h5 {
            margin: 0 0 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .status-content-new p {
            margin: 0 0 1rem;
            color: #64748b;
            line-height: 1.6;
        }
        
        .status-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #94a3b8;
            font-size: 0.875rem;
        }
        
        .btn-verify-retry-new {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-verify-retry-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
            color: white;
        }
        
        /* Form Card New */
        .verify-form-card-new {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        
        .verify-intro-new {
            display: flex;
            gap: 1rem;
            padding: 1.25rem;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 16px;
            margin-bottom: 2rem;
            border: 1px solid #bfdbfe;
        }
        
        .verify-intro-new .intro-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .verify-intro-new .intro-text strong {
            display: block;
            color: #1e40af;
            margin-bottom: 0.25rem;
        }
        
        .verify-intro-new .intro-text p {
            margin: 0;
            color: #3b82f6;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .form-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .section-title i {
            color: #f59e0b;
            font-size: 1.25rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .form-group-new {
            margin-bottom: 0.5rem;
        }
        
        .form-group-new.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group-new label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
        }
        
        .form-group-new label i {
            color: #64748b;
        }
        
        .form-group-new .required {
            color: #ef4444;
        }
        
        .form-input-new {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #f8fafc;
        }
        
        .form-input-new:focus {
            outline: none;
            border-color: #f59e0b;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }
        
        .form-input-new::placeholder {
            color: #94a3b8;
        }
        
        /* File Upload */
        .file-upload-wrapper {
            position: relative;
        }
        
        .file-input-hidden {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .file-upload-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            color: #64748b;
            font-weight: 500;
        }
        
        .file-upload-btn:hover {
            border-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #d97706;
        }
        
        .file-upload-btn i {
            font-size: 1.5rem;
        }
        
        .file-name {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #64748b;
            text-align: center;
        }
        
        .form-hint-new {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .form-hint-new i {
            font-size: 0.75rem;
        }
        
        /* Submit Button */
        .btn-submit-verify-new {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            width: 100%;
            padding: 1.25rem 2rem;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            position: relative;
            overflow: hidden;
        }
        
        .btn-submit-verify-new:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.5);
        }
        
        .btn-submit-verify-new .btn-shine {
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: btnShine 3s ease-in-out infinite;
        }
        
        @keyframes btnShine {
            0% { left: -100%; }
            50%, 100% { left: 100%; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .verify-header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .verify-status-card-new {
                flex-direction: column;
                text-align: center;
            }
        }
        </style>
        
        <script>
        // File upload name display
        document.getElementById('document_card')?.addEventListener('change', function() {
            document.getElementById('file-name-card').textContent = this.files[0]?.name || 'Chưa chọn file';
        });
        document.getElementById('document_internship')?.addEventListener('change', function() {
            document.getElementById('file-name-internship').textContent = this.files[0]?.name || 'Chưa chọn file';
        });
        </script>
        
        <!-- Giữ lại style cũ cho tương thích -->
        <style>
        /* Verify Container */
        .verify-container {
            padding: 1.5rem;
            max-width: 800px;
        }
        
        .verify-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .verify-header .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .verify-header .header-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .verify-header h4 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .verify-header p {
            margin: 0;
            font-size: 0.875rem;
            color: #64748b;
        }
        
        /* Status Cards */
        .verify-status-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        
        .verify-status-card.pending {
            border-left: 4px solid #f59e0b;
        }
        
        .verify-status-card.rejected {
            border-left: 4px solid #ef4444;
        }
        
        .verify-status-card .status-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            flex-shrink: 0;
        }
        
        .verify-status-card.pending .status-icon {
            background: #fef3c7;
            color: #d97706;
        }
        
        .verify-status-card.rejected .status-icon {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .verify-status-card .status-content h5 {
            margin: 0 0 0.5rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .verify-status-card .status-content p {
            margin: 0 0 0.75rem;
            color: #64748b;
        }
        
        .btn-verify-retry {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            background: #ef4444;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .btn-verify-retry:hover {
            background: #dc2626;
            color: white;
        }
        
        /* Form Card */
        .verify-form-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        
        .verify-intro {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            background: #eff6ff;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .verify-intro i {
            color: #3b82f6;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .verify-intro p {
            margin: 0;
            color: #1e40af;
            font-size: 0.9rem;
        }
        
        .verify-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .verify-form .form-group {
            margin-bottom: 1rem;
        }
        
        .verify-form .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .verify-form .form-group label .required {
            color: #ef4444;
        }
        
        .verify-form .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .verify-form .form-control:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .verify-form .form-hint {
            display: block;
            margin-top: 0.35rem;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .btn-submit-verify {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 0.5rem;
        }
        
        .btn-submit-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }
        
        @media (max-width: 768px) {
            .verify-form .form-row {
                grid-template-columns: 1fr;
            }
            
            .verify-status-card {
                flex-direction: column;
                text-align: center;
            }
        }
        </style>
        
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
        .section-content {
            background: #fff;
            border-radius: 12px;
            margin: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        .empty-state i {
            font-size: 4rem;
            display: block;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .dashboard-welcome-section {
            background: linear-gradient(180deg, #f0f7ff 0%, #f1f5f9 100%);
            position: relative;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, rgba(15, 76, 138, 0.85) 0%, rgba(30, 58, 138, 0.85) 25%, rgba(30, 64, 175, 0.85) 50%, rgba(37, 99, 235, 0.85) 75%, rgba(59, 130, 246, 0.85) 100%);
            border-radius: 24px;
            padding: 6rem 5rem;
            min-height: 500px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 
                0 20px 50px rgba(30, 64, 175, 0.35),
                0 10px 20px rgba(59, 130, 246, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            animation: cardEntrance 0.6s ease-out;
        }
        
        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('ảnh/ảnh nề.jpg');
            background-size: cover;
            background-position: center;
            opacity: 0.45;
            z-index: 0;
        }
        
        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Background Elements */
        .welcome-bg-elements {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            overflow: hidden;
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255,255,255,0.15), rgba(255,255,255,0.05));
            animation: float 6s ease-in-out infinite;
        }
        
        .shape-1 {
            width: 80px;
            height: 80px;
            top: -20px;
            right: 15%;
            animation-delay: 0s;
        }
        
        .shape-2 {
            width: 60px;
            height: 60px;
            bottom: -15px;
            left: 20%;
            animation-delay: 1s;
            animation-duration: 7s;
        }
        
        .shape-3 {
            width: 40px;
            height: 40px;
            top: 30%;
            right: 5%;
            animation-delay: 2s;
            animation-duration: 5s;
        }
        
        .shape-4 {
            width: 100px;
            height: 100px;
            bottom: -30px;
            right: 30%;
            animation-delay: 0.5s;
            animation-duration: 8s;
        }
        
        .shape-5 {
            width: 50px;
            height: 50px;
            top: 10%;
            left: 40%;
            animation-delay: 1.5s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(5deg); }
        }
        
        .pulse-ring {
            position: absolute;
            top: 50%;
            left: 10%;
            width: 100px;
            height: 100px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            animation: pulse 3s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0% { transform: translate(-50%, -50%) scale(0.8); opacity: 0.5; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0; }
            100% { transform: translate(-50%, -50%) scale(0.8); opacity: 0.5; }
        }
        
        /* Corner Decorations */
        .corner-decoration {
            position: absolute;
            width: 150px;
            height: 150px;
            pointer-events: none;
        }
        
        .corner-decoration.top-right {
            top: -30px;
            right: -30px;
        }
        
        .corner-decoration.bottom-left {
            bottom: -30px;
            left: -30px;
        }
        
        .welcome-content {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            z-index: 2;
        }
        
        .welcome-avatar-wrapper {
            position: relative;
        }
        
        .welcome-icon-animated {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0.1));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        .wave-emoji {
            font-size: 2.5rem;
            display: inline-block;
            animation: wave 2.5s ease-in-out infinite;
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
        
        .avatar-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.4), transparent 70%);
            border-radius: 50%;
            animation: glow 2s ease-in-out infinite alternate;
            z-index: -1;
        }
        
        @keyframes glow {
            from { opacity: 0.5; transform: translate(-50%, -50%) scale(0.9); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1.1); }
        }
        
        .welcome-text {
            position: relative;
        }
        
        .welcome-greeting {
            margin-bottom: 0.25rem;
        }
        
        .greeting-text {
            font-size: 0.9rem;
            font-weight: 500;
            color: rgba(255,255,255,0.85);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .welcome-name {
            margin: 0 0 0.75rem 0;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        
        .name-highlight {
            background: linear-gradient(90deg, #fff 0%, #e0f2fe 50%, #fff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: shimmer 3s ease-in-out infinite;
            background-size: 200% auto;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }
        
        .welcome-badges {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        
        .badge-role {
            background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0.1));
            backdrop-filter: blur(10px);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }
        
        .badge-role:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .badge-role i {
            font-size: 0.9rem;
        }
        
        .badge-verified {
            background: linear-gradient(135deg, #10b981, #059669);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
            animation: verifiedPulse 2s ease-in-out infinite;
        }
        
        @keyframes verifiedPulse {
            0%, 100% { box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 4px 25px rgba(16, 185, 129, 0.6); }
        }
        
        .welcome-desc {
            margin: 0;
            opacity: 0.9;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .welcome-desc i {
            color: #fbbf24;
            animation: lightBulb 2s ease-in-out infinite;
        }
        
        @keyframes lightBulb {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .welcome-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }
        
        .welcome-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.875rem 1.5rem;
            border-radius: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-size: 0.95rem;
        }
        
        .btn-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
        }
        
        .btn-text {
            position: relative;
            z-index: 1;
        }
        
        .btn-shine {
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shine 3s ease-in-out infinite;
        }
        
        @keyframes shine {
            0% { left: -100%; }
            50%, 100% { left: 100%; }
        }
        
        .welcome-btn.primary {
            background: linear-gradient(135deg, #fff 0%, #f0f9ff 100%);
            color: #1e40af;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border: none;
        }
        
        .welcome-btn.primary:hover {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 35px rgba(0,0,0,0.2);
        }
        
        .welcome-btn.primary:hover .btn-icon {
            transform: rotate(90deg);
        }
        
        .welcome-btn.secondary {
            background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
            color: #fff;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            position: relative;
        }
        
        .welcome-btn.secondary:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.4);
        }
        
        .welcome-btn.secondary:hover .btn-icon {
            animation: bellRing 0.5s ease-in-out;
        }
        
        @keyframes bellRing {
            0%, 100% { transform: rotate(0); }
            20%, 60% { transform: rotate(15deg); }
            40%, 80% { transform: rotate(-15deg); }
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(239, 68, 68, 0.5);
            animation: badgePulse 2s ease-in-out infinite;
        }
        
        @keyframes badgePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .welcome-btn.outline {
            background: transparent;
            color: #fff;
            border: 2px solid rgba(255,255,255,0.4);
        }
        
        .welcome-btn.outline:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.6);
            transform: translateY(-2px);
        }
        
        .welcome-btn.outline:hover .btn-icon {
            animation: chatBounce 0.5s ease-in-out;
        }
        
        @keyframes chatBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }
        
        .quick-stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .quick-stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 4px solid;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .quick-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .quick-stat-card.blue { border-color: #3b82f6; }
        .quick-stat-card.green { border-color: #10b981; }
        .quick-stat-card.pink { border-color: #ec4899; }
        .quick-stat-card.amber { border-color: #f59e0b; }
        .stat-icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }
        .quick-stat-card.blue .stat-icon { background: #dbeafe; }
        .quick-stat-card.green .stat-icon { background: #d1fae5; }
        .quick-stat-card.pink .stat-icon { background: #fce7f3; }
        .quick-stat-card.amber .stat-icon { background: #fef3c7; }
        .stat-info {
            display: flex;
            flex-direction: column;
        }
        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
        }
        .stat-desc {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        @media (max-width: 992px) {
            .welcome-card {
                padding: 2rem;
            }
            .welcome-name {
                font-size: 1.75rem;
            }
            .welcome-icon-animated {
                width: 70px;
                height: 70px;
            }
            .wave-emoji {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .welcome-card {
                flex-direction: column;
                text-align: center;
                padding: 2rem 1.5rem;
            }
            .welcome-content {
                flex-direction: column;
            }
            .welcome-avatar-wrapper {
                margin-bottom: 0.5rem;
            }
            .welcome-icon-animated {
                width: 80px;
                height: 80px;
            }
            .wave-emoji {
                font-size: 2.5rem;
            }
            .welcome-name {
                font-size: 1.5rem;
            }
            .welcome-badges {
                justify-content: center;
            }
            .welcome-desc {
                justify-content: center;
            }
            .welcome-actions {
                justify-content: center;
                width: 100%;
            }
            .welcome-btn {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }
            .floating-shape {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-card {
                padding: 1.5rem 1rem;
            }
            .welcome-name {
                font-size: 1.35rem;
            }
            .welcome-actions {
                flex-direction: column;
                gap: 0.75rem;
            }
            .welcome-btn {
                width: 100%;
                justify-content: center;
            }
            .greeting-text {
                font-size: 0.8rem;
            }
        }
        
        /* Stats Header */
        .stats-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
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
        
        /* Stats Cards Grid */
        .stats-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .stat-card-item {
            background: #fff;
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .stat-card-item.blue { border-left: 4px solid #3b82f6; }
        .stat-card-item.green { border-left: 4px solid #10b981; }
        .stat-card-item.pink { border-left: 4px solid #ec4899; }
        .stat-card-item.amber { border-left: 4px solid #f59e0b; }
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
        .stat-card-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .stat-card-label { font-size: 0.8rem; color: #64748b; }
        
        /* Contact History Section */
        .contact-history-section {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
        }
        .contacts-empty {
            text-align: center;
            padding: 3rem 2rem;
        }
        .contacts-empty .empty-icon {
            width: 80px;
            height: 80px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: #94a3b8;
        }
        .contacts-empty h5 { color: #1e293b; margin-bottom: 0.5rem; }
        .contacts-empty p { color: #64748b; margin: 0; }
        .contacts-list { display: flex; flex-direction: column; gap: 0.75rem; }
        .contact-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            transition: all 0.3s;
        }
        .contact-item:hover {
            background: #f1f5f9;
            transform: translateX(5px);
        }
        .contact-item .contact-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            flex-shrink: 0;
            overflow: hidden;
        }
        .contact-item .contact-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .contact-content { flex: 1; min-width: 0; }
        .contact-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
        }
        .contact-name { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
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
            font-size: 0.85rem;
            color: #64748b;
            margin: 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        </style>
        
        <!-- Nội dung chi tiết - ẩn mặc định, hiện khi click menu -->
        <div class="dashboard-content" style="display: none;">

<style>
/* Dashboard Hero Premium */
.dashboard-hero-premium {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 24px;
    padding: 2rem 2.5rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(11, 63, 145, 0.35);
}
.dashboard-hero-premium::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 60%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 60%);
    pointer-events: none;
}
.dashboard-hero-premium .hero-welcome h2 {
    color: #ffffff;
    font-size: 1.85rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.15);
}
.dashboard-hero-premium .hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    color: #ffffff;
    padding: 0.5rem 1.25rem;
    border-radius: 50px;
    font-size: 0.9rem;
    font-weight: 600;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.dashboard-hero-premium .hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.dashboard-hero-premium .hero-actions .btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.85rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
}
.dashboard-hero-premium .btn-action-primary {
    background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
    color: #0b3f91;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.dashboard-hero-premium .btn-action-primary:hover {
    background: #ffffff;
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    color: #0b3f91;
}
.dashboard-hero-premium .btn-action-primary i {
    color: #3b82f6;
}
.dashboard-hero-premium .btn-action-outline {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    border: 2px solid rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
}
.dashboard-hero-premium .btn-action-outline:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.8);
    transform: translateY(-3px);
    color: #ffffff;
}
@media (max-width: 768px) {
    .dashboard-hero-premium {
        padding: 1.5rem;
        border-radius: 18px;
    }
    .dashboard-hero-premium .hero-welcome h2 {
        font-size: 1.4rem;
    }
    .dashboard-hero-premium .hero-actions {
        flex-direction: column;
    }
    .dashboard-hero-premium .hero-actions .btn-action {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="dashboard-hero-premium">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
        <div class="hero-welcome">
            <h2>👋 Chào mừng, <?php echo htmlspecialchars($displayName); ?>!</h2>
            <span class="hero-badge">
                <i class="fas fa-user-graduate"></i> Sinh viên
            </span>
        </div>
        <div class="hero-actions mt-3 mt-md-0">
            <a href="#" onclick="showSection('create-post', 'Tạo tin ứng tuyển'); return false;" class="btn-action btn-action-primary">
                <i class="fas fa-plus-circle"></i> Tạo tin ứng tuyển
            </a>
            <a href="#" onclick="showSection('notifications', 'Thông báo'); return false;" class="btn-action btn-action-primary">
                <i class="fas fa-bell"></i> Thông báo
            </a>
            <a href="#" onclick="showSection('messages', 'Tin nhắn'); return false;" class="btn-action btn-action-outline">
                <i class="fas fa-envelope"></i> Tin nhắn
            </a>
        </div>
    </div>
</div>

<?php if (!$verifiedFlag && !is_admin_user()): ?>
    <div class="status-card status-warning">
        <div class="status-icon">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Tài khoản chưa được xác thực</h6>
            <p class="status-text">
                <?php if ($latestVerification): ?>
                    <?php if ($latestVerification['status'] === 'pending'): ?>
                        Đơn xin xác thực đang được xử lý (gửi ngày <?php echo date('d/m/Y', strtotime($latestVerification['created_at'])); ?>).
                    <?php elseif ($latestVerification['status'] === 'rejected'): ?>
                        Đơn gần nhất bị từ chối. <?php if (!empty($latestVerification['admin_note'])): ?>Lý do: <?php echo nl2br(htmlspecialchars($latestVerification['admin_note'])); ?><?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    Vui lòng gửi yêu cầu xác thực để hưởng đầy đủ quyền lợi sinh viên.
                <?php endif; ?>
            </p>
        </div>
        <a href="request_verification.php" class="status-btn">
            <i class="fas fa-check-circle me-1"></i> Xin xác thực
        </a>
    </div>
<?php endif; ?>

<?php if (!$canPost): ?>
    <div class="status-card status-info">
        <div class="status-icon">
            <i class="fas fa-edit"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Quyền đăng tin</h6>
            <p class="status-text">Chưa được cấp quyền đăng tin. Hãy gửi yêu cầu để được phê duyệt.</p>
        </div>
        <a href="request_posting_permission.php" class="status-btn status-btn-info">
            <i class="fas fa-paper-plane me-1"></i> Xin cấp quyền
        </a>
    </div>
<?php else: ?>
    <div class="status-card status-success">
        <div class="status-icon">
            <i class="fas fa-check-double"></i>
        </div>
        <div class="status-content">
            <h6 class="status-title">Quyền đăng tin</h6>
            <p class="status-text">Bạn đã được cấp quyền đăng tin. Hãy tạo tin ứng tuyển ngay!</p>
        </div>
    </div>
<?php endif; ?>

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

/* Card 1 - Blue */
.card-blue { border-color: #3b82f6; background: linear-gradient(135deg, #fff 0%, #dbeafe 100%); }
.card-blue .icon-box { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 20px rgba(59,130,246,0.4); }
.card-blue .stat-value { color: #1e40af; }
.card-blue:hover { box-shadow: 0 20px 50px rgba(59,130,246,0.25); }

/* Card 2 - Cyan */
.card-cyan { border-color: #06b6d4; background: linear-gradient(135deg, #fff 0%, #cffafe 100%); }
.card-cyan .icon-box { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 20px rgba(6,182,212,0.4); }
.card-cyan .stat-value { color: #0e7490; }
.card-cyan:hover { box-shadow: 0 20px 50px rgba(6,182,212,0.25); }

/* Card 3 - Pink */
.card-pink { border-color: #ec4899; background: linear-gradient(135deg, #fff 0%, #fce7f3 100%); }
.card-pink .icon-box { background: linear-gradient(135deg, #ec4899, #db2777); box-shadow: 0 8px 20px rgba(236,72,153,0.4); }
.card-pink .stat-value { color: #be185d; }
.card-pink:hover { box-shadow: 0 20px 50px rgba(236,72,153,0.25); }

/* Card 4 - Amber */
.card-amber { border-color: #f59e0b; background: linear-gradient(135deg, #fff 0%, #fef3c7 100%); }
.card-amber .icon-box { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 20px rgba(245,158,11,0.4); }
.card-amber .stat-value { color: #b45309; }
.card-amber:hover { box-shadow: 0 20px 50px rgba(245,158,11,0.25); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
.stats-enhanced .stat-card-new:nth-child(1) { animation: fadeInUp 0.5s ease-out 0.1s backwards; }
.stats-enhanced .stat-card-new:nth-child(2) { animation: fadeInUp 0.5s ease-out 0.2s backwards; }
.stats-enhanced .stat-card-new:nth-child(3) { animation: fadeInUp 0.5s ease-out 0.3s backwards; }
.stats-enhanced .stat-card-new:nth-child(4) { animation: fadeInUp 0.5s ease-out 0.4s backwards; }
</style>

<div class="stats-enhanced" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.25rem; margin: 1.75rem 0 2.25rem;">
    <div class="stat-card-new card-blue">
        <div class="icon-box"><i class="fas fa-graduation-cap"></i></div>
        <div>
            <p class="stat-label mb-1">Tin ứng tuyển</p>
            <p class="stat-value mb-0"><?php echo $postsCount; ?></p>
            <span class="stat-desc">Đã đăng</span>
        </div>
    </div>
    <a href="assignment_history.php" class="stat-card-new card-cyan">
        <div class="icon-box"><i class="fas fa-calendar-check"></i></div>
        <div>
            <p class="stat-label mb-1">Lịch sử nhận việc</p>
            <p class="stat-value mb-0"><?php echo $recentAcceptCount; ?></p>
            <span class="stat-desc">Trong 30 ngày gần nhất</span>
        </div>
    </a>
    <a href="favorites.php" class="stat-card-new card-pink">
        <div class="icon-box"><i class="fas fa-heart"></i></div>
        <div>
            <p class="stat-label mb-1">Yêu thích</p>
            <p class="stat-value mb-0"><?php echo count($favoritePosts); ?></p>
            <span class="stat-desc">Bài tuyển đã lưu</span>
        </div>
    </a>
    <div class="stat-card-new card-amber" role="button" tabindex="0" data-bs-toggle="offcanvas" data-bs-target="#myReviewsCanvas" aria-controls="myReviewsCanvas" style="cursor: pointer;">
        <div class="icon-box"><i class="fas fa-star"></i></div>
        <div>
            <p class="stat-label mb-1">Đánh giá</p>
            <p class="stat-value mb-0"><?php echo $avgRating !== null ? $avgRating : '—'; ?></p>
            <span class="stat-desc"><?php echo $ratingTotal; ?> lượt đánh giá</span>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end reviews-offcanvas-premium" tabindex="-1" id="myReviewsCanvas" aria-labelledby="myReviewsCanvasLabel">
    <div class="reviews-offcanvas-header">
        <div class="reviews-header-content">
            <div class="reviews-header-icon">⭐</div>
            <div>
                <h5 class="reviews-title" id="myReviewsCanvasLabel">Đánh giá bạn nhận được</h5>
                <p class="reviews-subtitle">📊 Hiển thị tối đa 20 đánh giá gần nhất</p>
            </div>
        </div>
        <button type="button" class="reviews-close-btn" data-bs-dismiss="offcanvas" aria-label="Đóng">✕</button>
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
                <div class="reviews-summary-count">👥 <?php echo $ratingTotal; ?> lượt đánh giá</div>
            </div>
        <?php endif; ?>

        <?php if (!$myReviews): ?>
            <div class="reviews-empty-state">
                <div class="empty-icon">💬</div>
                <h6>Chưa có đánh giá</h6>
                <p>Bạn chưa nhận được đánh giá nào. Hãy tiếp tục hoạt động để nhận phản hồi từ người dùng! 💪</p>
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
                                    <div class="avatar-placeholder"><?php echo strtoupper(substr($rv['rater_name'],0,1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="review-meta">
                                <h6 class="reviewer-name">👤 <?php echo htmlspecialchars($rv['rater_name']); ?></h6>
                                <span class="review-date">🕐 <?php echo date('d/m/Y H:i', strtotime($rv['created_at'])); ?></span>
                            </div>
                            <div class="review-rating">
                                <div class="rating-stars">
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <i class="<?php echo $i <= (int)$rv['score'] ? 'fas' : 'far'; ?> fa-star"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="rating-score"><?php echo (int)$rv['score']; ?>/5</span>
                            </div>
                        </div>
                        <?php if (!empty($rv['comment'])): ?>
                        <div class="review-content">
                            <p>💭 <?php echo nl2br(htmlspecialchars($rv['comment'])); ?></p>
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
.reviews-offcanvas-premium { width: 420px !important; max-width: 90vw; border: none !important; box-shadow: -20px 0 60px rgba(0, 0, 0, 0.15); }
.reviews-offcanvas-header { background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); padding: 1.5rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; }
.reviews-offcanvas-header::before { content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%); pointer-events: none; }
.reviews-header-content { display: flex; align-items: center; gap: 1rem; position: relative; z-index: 1; }
.reviews-header-icon { width: 48px; height: 48px; background: rgba(255, 255, 255, 0.2); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; backdrop-filter: blur(10px); }
.reviews-title { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }
.reviews-subtitle { color: rgba(255, 255, 255, 0.8); font-size: 0.85rem; margin: 0.25rem 0 0; }
.reviews-close-btn { width: 36px; height: 36px; border-radius: 10px; background: rgba(255, 255, 255, 0.2); border: none; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease; position: relative; z-index: 1; font-size: 1rem; }
.reviews-close-btn:hover { background: rgba(255, 255, 255, 0.3); transform: rotate(90deg); }
.reviews-offcanvas-body { padding: 1.5rem; background: linear-gradient(180deg, #f8fafc 0%, #fff 100%); height: calc(100% - 90px); overflow-y: auto; }
.reviews-summary-card { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 20px; padding: 1.5rem; text-align: center; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(245, 158, 11, 0.2); position: relative; overflow: hidden; }
.reviews-summary-card::before { content: ''; position: absolute; top: -30%; right: -30%; width: 60%; height: 60%; background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%); pointer-events: none; }
.reviews-summary-score { position: relative; z-index: 1; }
.reviews-summary-score .score-number { font-size: 3rem; font-weight: 800; color: #92400e; line-height: 1; }
.reviews-summary-score .score-max { font-size: 1.25rem; color: #b45309; font-weight: 600; }
.reviews-summary-stars { font-size: 1.5rem; color: #f59e0b; margin: 0.75rem 0; letter-spacing: 0.15rem; position: relative; z-index: 1; }
.reviews-summary-count { font-size: 0.9rem; color: #92400e; font-weight: 600; position: relative; z-index: 1; }
.reviews-empty-state { text-align: center; padding: 3rem 1.5rem; background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border-radius: 20px; border: 2px dashed #cbd5e1; }
.reviews-empty-state .empty-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 2rem; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3); }
.reviews-empty-state h6 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; }
.reviews-empty-state p { color: #64748b; font-size: 0.9rem; margin: 0; line-height: 1.6; }
.reviews-list { display: flex; flex-direction: column; gap: 1rem; }
.review-card { background: #fff; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; transition: all 0.3s ease; animation: reviewSlideIn 0.4s ease-out backwards; }
.review-card:nth-child(1) { animation-delay: 0.1s; }
.review-card:nth-child(2) { animation-delay: 0.15s; }
.review-card:nth-child(3) { animation-delay: 0.2s; }
@keyframes reviewSlideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
.review-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); border-color: #c7d2fe; }
.review-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; }
.review-avatar { flex-shrink: 0; }
.review-avatar img { width: 48px; height: 48px; border-radius: 12px; object-fit: cover; border: 2px solid #e2e8f0; }
.review-avatar .avatar-placeholder { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; }
.review-meta { flex: 1; min-width: 0; }
.reviewer-name { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.review-date { font-size: 0.8rem; color: #94a3b8; }
.review-rating { text-align: right; flex-shrink: 0; }
.review-rating .rating-stars { color: #f59e0b; font-size: 0.85rem; letter-spacing: 0.05rem; }
.review-rating .rating-score { display: block; font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-top: 0.25rem; }
.review-content { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px; padding: 1rem; margin-top: 0.5rem; border-left: 3px solid #3b82f6; }
.review-content p { margin: 0; color: #475569; font-size: 0.9rem; line-height: 1.6; }
.reviews-offcanvas-body::-webkit-scrollbar { width: 6px; }
.reviews-offcanvas-body::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
.reviews-offcanvas-body::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%); border-radius: 3px; }
@media (max-width: 576px) { .reviews-offcanvas-premium { width: 100% !important; } .review-header { flex-wrap: wrap; } .review-rating { width: 100%; text-align: left; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; } }
</style>

<style>
.quick-actions-section { margin-bottom: 2rem; }
.quick-actions-title { display: flex; align-items: center; gap: 0.75rem; font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 1.25rem; }
.quick-actions-title::before { content: '⚡'; font-size: 1.5rem; }
.quick-card-premium { background: #fff; border-radius: 20px; padding: 1.75rem; box-shadow: 0 10px 40px rgba(0,0,0,0.06); border: 1px solid rgba(226, 232, 240, 0.8); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; height: 100%; }
.quick-card-premium::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #0b3f91, #3b82f6); opacity: 0; transition: opacity 0.3s ease; }
.quick-card-premium:hover::before { opacity: 1; }
.quick-card-premium:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(59, 130, 246, 0.15); border-color: #c7d2fe; }
.quick-card-icon { width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1.25rem; transition: transform 0.3s ease; }
.quick-card-premium:hover .quick-card-icon { transform: scale(1.1) rotate(5deg); }
.quick-card-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.quick-card-icon.pink { background: linear-gradient(135deg, #fce7f3, #fbcfe8); }
.quick-card-icon.cyan { background: linear-gradient(135deg, #cffafe, #a5f3fc); }
.quick-card-icon.purple { background: linear-gradient(135deg, #e9d5ff, #d8b4fe); }
.quick-card-icon.amber { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.quick-card-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.quick-card-premium h5 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
.quick-card-premium p { color: #64748b; font-size: 0.9rem; line-height: 1.6; margin-bottom: 1rem; }
.quick-card-link { display: inline-flex; align-items: center; gap: 0.5rem; color: #3b82f6; font-weight: 600; font-size: 0.9rem; text-decoration: none; transition: all 0.3s ease; }
.quick-card-link:hover { color: #1d4ed8; gap: 0.75rem; }
</style>

<div class="quick-actions-section">
    <h4 class="quick-actions-title">Thao tác nhanh</h4>
    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon blue">👤</div>
                <h5>📝 Cập nhật hồ sơ</h5>
                <p>Bổ sung thông tin cá nhân và kỹ năng để tăng độ tin cậy với nhà tuyển dụng.</p>
                <a href="edit_profile.php" class="quick-card-link stretched-link">Đi tới hồ sơ →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon pink">💖</div>
                <h5>❤️ Danh sách yêu thích</h5>
                <p>Mở nhanh các tin tuyển đã lưu để ứng tuyển sau khi sẵn sàng.</p>
                <a href="favorites.php" class="quick-card-link stretched-link">Xem danh sách →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon cyan">📋</div>
                <h5>🧾 Lịch sử nhận việc</h5>
                <p>Theo dõi các lần được chọn hỗ trợ trong tháng vừa qua.</p>
                <a href="assignment_history.php" class="quick-card-link stretched-link">Xem chi tiết →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon purple">🔍</div>
                <h5>🗂 Xem tin tuyển</h5>
                <p>Khám phá nhu cầu hỗ trợ mới nhất từ bệnh nhân đang cần giúp đỡ.</p>
                <a href="index.php?type=recruitment#posts" class="quick-card-link stretched-link">Khám phá ngay →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon amber">💬</div>
                <h5>📅 Quản lý lịch hẹn</h5>
                <p>Theo dõi các lịch hẹn chăm sóc và trao đổi với bệnh nhân.</p>
                <a href="view_messages.php" class="quick-card-link stretched-link">Xem lịch & tin nhắn →</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="quick-card-premium">
                <div class="quick-card-icon green">🛡️</div>
                <h5>🛠 Hỗ trợ tài khoản</h5>
                <p>Cần chỉnh sửa giấy tờ, muốn ẩn hồ sơ hoặc xóa tài khoản? Gửi đơn cho quản trị viên.</p>
                <a href="account_request.php" class="quick-card-link stretched-link">Gửi yêu cầu →</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Posts Table Premium */
.posts-section-premium {
    background: #ffffff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    margin-bottom: 2rem;
}
.posts-section-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.posts-section-header h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.posts-section-header h4::before {
    content: '📋';
    font-size: 1.5rem;
}
.posts-section-header .posts-count {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    padding: 0.35rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
}
.posts-table-premium {
    width: 100%;
    border-collapse: collapse;
}
.posts-table-premium thead {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 100%);
}
.posts-table-premium thead th {
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 1rem 1.25rem;
    border: none;
}
.posts-table-premium tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f1f5f9;
}
.posts-table-premium tbody tr:hover {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
}
.posts-table-premium tbody td {
    padding: 1.25rem;
    vertical-align: middle;
    color: #334155;
}
.posts-table-premium .post-title {
    font-weight: 600;
    color: #1e293b;
    max-width: 300px;
}
.posts-table-premium .post-category {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #f1f5f9;
    padding: 0.35rem 0.85rem;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #475569;
}
.posts-table-premium .post-area {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #64748b;
    font-size: 0.9rem;
}
.posts-table-premium .post-area i {
    color: #ef4444;
}
.posts-table-premium .post-date {
    color: #64748b;
    font-size: 0.875rem;
}
.posts-table-premium .post-date i {
    color: #94a3b8;
    margin-right: 0.35rem;
}
/* Status badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 600;
}
.status-badge-open {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
}
.status-badge-open::before {
    content: '';
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.status-badge-inactive {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
}
.status-badge-closed {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    color: #64748b;
}
.status-badge-taken {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
}
/* Action buttons */
.action-btns {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}
.action-btn-view {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1d4ed8;
}
.action-btn-view:hover {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    transform: translateY(-2px);
    color: #1d4ed8;
}
.action-btn-edit {
    background: linear-gradient(135deg, #f5f3ff, #ede9fe);
    color: #7c3aed;
}
.action-btn-edit:hover {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    transform: translateY(-2px);
    color: #7c3aed;
}
.action-btn-delete {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #dc2626;
}
.action-btn-delete:hover {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    transform: translateY(-2px);
    color: #dc2626;
}
.action-btn-accept {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.action-btn-accept:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    color: #ffffff;
}
.action-btn-reopen {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #ffffff;
}
.action-btn-reopen:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-2px);
    color: #ffffff;
}
/* Empty state */
.posts-empty-state {
    text-align: center;
    padding: 4rem 2rem;
}
.posts-empty-state .empty-icon {
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
.posts-empty-state h5 {
    color: #1e293b;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.posts-empty-state p {
    color: #64748b;
    margin-bottom: 1.5rem;
}
.posts-empty-state .btn-create {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    padding: 0.85rem 2rem;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}
.posts-empty-state .btn-create:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
    color: #fff;
}

/* Accept Modal Student Styles */
.accept-modal-student .modal-dialog {
    max-width: 520px;
}
.accept-modal-content-student {
    border: none;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
}
.accept-modal-header-student {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    padding: 24px 28px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    position: relative;
}
.accept-modal-icon-student {
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
.accept-modal-title-wrap-student {
    flex: 1;
}
.accept-modal-title-student {
    color: #fff;
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0 0 8px 0;
}
.accept-modal-subtitle-student {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.accept-modal-close-student {
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
.accept-modal-close-student:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: rotate(90deg);
}
.accept-modal-body-student {
    padding: 28px;
    background: #f8fafc;
}
.accept-empty-state-student {
    text-align: center;
    padding: 40px 20px;
}
.accept-empty-icon-student {
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
.accept-empty-state-student p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #475569;
    margin: 0 0 8px 0;
}
.accept-empty-state-student span {
    font-size: 0.9rem;
    color: #94a3b8;
}
.accept-section-student {
    margin-bottom: 24px;
}
.accept-section-label-student {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 14px;
}
.accept-section-label-student i {
    color: #2563eb;
    font-size: 0.9rem;
}
.accept-candidates-list-student {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.accept-candidate-item-student {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}
.accept-candidate-item-student:hover {
    border-color: #2563eb;
    background: #eff6ff;
}
.accept-candidate-item-student:has(.accept-radio-student:checked) {
    border-color: #2563eb;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.15);
}
.accept-radio-student {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.accept-candidate-avatar-student {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.15rem;
    font-weight: 700;
    flex-shrink: 0;
}
.accept-candidate-info-student {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.accept-candidate-name-student {
    font-size: 1.05rem;
    font-weight: 600;
    color: #1e293b;
}
.accept-candidate-badge-student {
    font-size: 0.75rem;
    color: #2563eb;
    background: #dbeafe;
    padding: 4px 12px;
    border-radius: 20px;
    width: fit-content;
    font-weight: 500;
}
.accept-check-icon-student {
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
.accept-candidate-item-student:has(.accept-radio-student:checked) .accept-check-icon-student {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}
.accept-textarea-student {
    width: 100%;
    padding: 16px 18px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 0.95rem;
    resize: vertical;
    min-height: 100px;
    transition: all 0.3s ease;
    background: #fff;
}
.accept-textarea-student:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
}
.accept-textarea-student::placeholder {
    color: #94a3b8;
}
.accept-actions-student {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}
.accept-btn-cancel-student {
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
.accept-btn-cancel-student:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.accept-btn-confirm-student {
    flex: 1.5;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 24px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.35);
}
.accept-btn-confirm-student:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(37, 99, 235, 0.45);
}
@media (max-width: 576px) {
    .accept-modal-header-student {
        padding: 20px;
        flex-direction: column;
        text-align: center;
    }
    .accept-modal-body-student {
        padding: 20px;
    }
    .accept-modal-icon-student {
        width: 50px;
        height: 50px;
        font-size: 1.3rem;
        margin: 0 auto;
    }
    .accept-actions-student {
        flex-direction: column;
    }
}
</style>

<section id="my-posts" class="posts-section-premium">
    <div class="posts-section-header">
        <h4>Tin ứng tuyển của bạn</h4>
        <span class="posts-count"><?php echo $postsCount; ?> tin</span>
    </div>
    <?php if (!$posts): ?>
        <div class="posts-empty-state">
            <div class="empty-icon">📝</div>
            <h5>Chưa có tin ứng tuyển</h5>
            <p>Hãy bắt đầu bằng cách tạo tin ứng tuyển đầu tiên của bạn!</p>
            <a class="btn-create" href="create_application.php">
                <i class="fas fa-plus-circle"></i> Tạo tin ngay
            </a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="posts-table-premium">
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Chuyên ngành</th>
                        <th>Khu vực</th>
                        <th>Trạng thái</th>
                        <th>Ngày đăng</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td class="post-title"><?php echo htmlspecialchars($p['title']); ?></td>
                        <td><span class="post-category"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($p['category'] ?? '—'); ?></span></td>
                        <td><span class="post-area"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($p['area'] ?? '—'); ?></span></td>
                        <td>
                            <?php $pst = $p['status'] ?? 'open'; ?>
                            <?php if ($pst === 'inactive'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-inactive"><i class="fas fa-pause-circle"></i> Chưa hoạt động</span>
                            <?php elseif ($pst === 'closed'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-closed"><i class="fas fa-times-circle"></i> Đã đóng</span>
                            <?php elseif ($pst === 'taken'): ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-taken"><i class="fas fa-check-circle"></i> Đã nhận việc</span>
                            <?php else: ?>
                                <span id="post-status-badge-<?php echo (int)$p['id']; ?>" class="status-badge status-badge-open">Đang mở</span>
                            <?php endif; ?>
                        </td>
                        <td class="post-date"><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></td>
                        <td>
                            <div class="action-btns">
                                <a class="action-btn action-btn-view" href="view_post.php?id=<?php echo $p['id']; ?>"><i class="fas fa-eye"></i> Xem</a>
                                <a class="action-btn action-btn-edit" href="edit_post.php?id=<?php echo $p['id']; ?>"><i class="fas fa-edit"></i> Sửa</a>
                                <form method="post" action="delete_post.php" class="d-inline" onsubmit="return confirm('Xóa tin này?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="action-btn action-btn-delete"><i class="fas fa-trash"></i> Xóa</button>
                                </form>
                                <!-- Action buttons based on status -->
                                <?php $pst = ($p['status'] ?? 'open'); ?>
                                <?php if ($pst === 'inactive'): ?>
                                    <button type="button" class="action-btn action-btn-reopen" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>"><i class="fas fa-redo"></i> Mở lại</button>
                                <?php elseif ($pst === 'taken'): ?>
                                    <form method="post" action="reopen_post.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="action-btn action-btn-accept"><i class="fas fa-unlock"></i> Mở</button>
                                    </form>
                                <?php elseif ($pst === 'closed'): ?>
                                    <button type="button" class="action-btn" style="background:#e2e8f0;color:#94a3b8;cursor:not-allowed;" disabled><i class="fas fa-lock"></i> Đã đóng</button>
                                <?php else: ?>
                                    <button type="button" class="action-btn action-btn-accept" data-bs-toggle="modal" data-bs-target="#acceptModal-<?php echo (int)$p['id']; ?>" id="accept-trigger-<?php echo (int)$p['id']; ?>"><i class="fas fa-handshake"></i> Nhận việc</button>
                                <?php endif; ?>
                            </div>
                        </td>

                                                        <!-- Modal: list contacts who messaged about this post -->
                                                        <div class="modal fade accept-modal-student" id="acceptModal-<?php echo (int)$p['id']; ?>" tabindex="-1" aria-labelledby="acceptModalLabel-<?php echo (int)$p['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-dialog-centered">
                                                                <div class="modal-content accept-modal-content-student">
                                                                    <div class="accept-modal-header-student">
                                                                        <div class="accept-modal-icon-student">
                                                                            <i class="fas fa-handshake"></i>
                                                                        </div>
                                                                        <div class="accept-modal-title-wrap-student">
                                                                            <h5 class="accept-modal-title-student">Nhận việc</h5>
                                                                            <p class="accept-modal-subtitle-student"><?php echo htmlspecialchars($p['title']); ?></p>
                                                                        </div>
                                                                        <button type="button" class="accept-modal-close-student" data-bs-dismiss="modal" aria-label="Close">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="accept-modal-body-student">
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
                                                                            <div class="accept-empty-state-student">
                                                                                <div class="accept-empty-icon-student">
                                                                                    <i class="fas fa-inbox"></i>
                                                                                </div>
                                                                                <p>Chưa có ai liên hệ về tin này</p>
                                                                                <span>Hãy chờ bệnh nhân liên hệ với bạn</span>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <form method="post" action="accept_applicant.php" class="accept-form-student">
                                                                                <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                                                                
                                                                                <div class="accept-section-student">
                                                                                    <label class="accept-section-label-student">
                                                                                        <i class="fas fa-users"></i>
                                                                                        Chọn người nhận việc
                                                                                    </label>
                                                                                    <div class="accept-candidates-list-student">
                                                                                        <?php foreach ($contacts as $c): ?>
                                                                                            <label class="accept-candidate-item-student" for="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>">
                                                                                                <input class="accept-radio-student" type="radio" name="selected_user" id="contact-<?php echo $c['id']; ?>-<?php echo $p['id']; ?>" value="<?php echo $c['id']; ?>" required>
                                                                                                <div class="accept-candidate-avatar-student">
                                                                                                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                                                                                                </div>
                                                                                                <div class="accept-candidate-info-student">
                                                                                                    <span class="accept-candidate-name-student"><?php echo htmlspecialchars($c['name']); ?></span>
                                                                                                    <span class="accept-candidate-badge-student">Đã liên hệ</span>
                                                                                                </div>
                                                                                                <div class="accept-check-icon-student">
                                                                                                    <i class="fas fa-check-circle"></i>
                                                                                                </div>
                                                                                            </label>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </div>
                                                                                
                                                                                <div class="accept-section-student">
                                                                                    <label class="accept-section-label-student">
                                                                                        <i class="fas fa-comment-alt"></i>
                                                                                        Ghi chú (tuỳ chọn)
                                                                                    </label>
                                                                                    <textarea name="note" class="accept-textarea-student" rows="3" placeholder="Nhập lời nhắn hoặc ghi chú..."></textarea>
                                                                                </div>
                                                                                
                                                                                <div class="accept-actions-student">
                                                                                    <button type="button" class="accept-btn-cancel-student" data-bs-dismiss="modal">
                                                                                        <i class="fas fa-times"></i>
                                                                                        Hủy
                                                                                    </button>
                                                                                    <button type="submit" class="accept-btn-confirm-student">
                                                                                        <i class="fas fa-check"></i>
                                                                                        Xác Nhận
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

<?php require_once 'footer.php'; ?>
<script>
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
                        // Hide the 'Mở' button if present
                        var showBtn = document.getElementById('show-accept-' + postId);
                        if (showBtn) showBtn.classList.add('d-none');
                        // Update accept trigger button (if exists)
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
                        // Update status badge in the same table row or by id
                        var badge = document.getElementById('post-status-badge-' + postId);
                        if (badge) { badge.textContent = 'Đã nhận việc này'; badge.className = 'badge bg-success'; }
                        else {
                            var triggerRow = trigger ? (trigger.closest('tr') || null) : null;
                            if (triggerRow) {
                                var statusCell = triggerRow.querySelector('td:nth-child(4)');
                                if (statusCell) statusCell.innerHTML = '<span class="badge bg-success">Đã nhận việc này</span>';
                            }
                        }
                        // hide modal
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
                    // If accept button already exists, ensure it's visible and has modal attributes
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

<script>
// FINAL EVENT HANDLER - Đặt ở cuối file để đảm bảo chạy sau tất cả
(function() {
    console.log('=== FINAL EVENT HANDLER LOADED ===');
    
    // Xử lý click cho nút Nhận
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-accept');
        if (btn) {
            console.log('btn-accept clicked!', btn);
            e.preventDefault();
            e.stopPropagation();
            
            var postId = btn.getAttribute('data-post-id');
            var postTitle = btn.getAttribute('data-post-title');
            
            console.log('postId:', postId, 'postTitle:', postTitle);
            
            if (postId && postTitle && typeof showApplicants === 'function') {
                showApplicants(parseInt(postId), postTitle);
            } else if (typeof showApplicants !== 'function') {
                alert('Lỗi: Hàm showApplicants không tồn tại!');
            } else {
                alert('Lỗi: Thiếu thông tin bài đăng');
            }
            return false;
        }
    }, true); // Use capture phase
})();
</script>

    </main><!-- /.dashboard-main -->
</div><!-- /.dashboard-layout -->

<?php require_once 'footer.php'; ?>
