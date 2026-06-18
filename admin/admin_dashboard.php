<?php
// Admin dashboard implementation (clean, single file)
declare(strict_types=1);
require_once 'config.php';
require_admin();

// Nếu được load trong iframe (embed=1), hiển thị thông báo thay vì dashboard đầy đủ
$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if ($isEmbed) {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"><style>body{background:#f1f5f9;padding:2rem;display:flex;align-items:center;justify-content:center;min-height:80vh;}.admin-notice{text-align:center;background:#fff;padding:3rem;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.1);max-width:500px;}.admin-notice i{font-size:4rem;color:#3b82f6;margin-bottom:1rem;}.admin-notice h3{color:#1e293b;margin-bottom:0.5rem;}.admin-notice p{color:#64748b;margin-bottom:1.5rem;}.admin-notice a{display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:0.75rem 1.5rem;border-radius:12px;text-decoration:none;font-weight:600;transition:transform 0.2s;}.admin-notice a:hover{transform:translateY(-2px);}</style></head><body>';
    echo '<div class="admin-notice">';
    echo '<i class="bi bi-shield-check"></i>';
    echo '<h3>Trang Quản trị viên</h3>';
    echo '<p>Vui lòng sử dụng trang quản trị đầy đủ để truy cập các chức năng.</p>';
    echo '<a href="admin_dashboard.php" target="_top"><i class="bi bi-box-arrow-up-right"></i> Mở trang quản trị</a>';
    echo '</div></body></html>';
    exit;
}

// Handle admin updates for account requests (status and admin_note)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_account_request') {
  $reqId = (int)($_POST['req_id'] ?? 0);
  $allowed = ['pending', 'in_review', 'resolved', 'rejected'];
  $status = in_array($_POST['status'] ?? '', $allowed, true) ? $_POST['status'] : 'pending';
  $note = trim($_POST['admin_note'] ?? '');

  if ($reqId > 0) {
    try {
      // If status is resolved or rejected, set processed_at to now
      if (in_array($status, ['resolved', 'rejected'], true)) {
        $stmt = $pdo->prepare("UPDATE account_requests SET status = ?, admin_note = ?, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $note ?: null, $reqId]);
      } else {
        $stmt = $pdo->prepare("UPDATE account_requests SET status = ?, admin_note = ? WHERE id = ?");
        $stmt->execute([$status, $note ?: null, $reqId]);
      }
      // redirect to avoid resubmit
      header('Location: admin_dashboard.php?panel=user-requests&msg=ok');
      exit;
    } catch (Throwable $e) {
      error_log('Update account_request failed: ' . $e->getMessage());
      // fallthrough to render page with existing data
    }
  }
}

// Handle delete account request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account_request') {
  $reqId = (int)($_POST['req_id'] ?? 0);
  if ($reqId > 0) {
    try {
      $stmt = $pdo->prepare("DELETE FROM account_requests WHERE id = ?");
      $stmt->execute([$reqId]);
      header('Location: admin_dashboard.php?panel=user-requests&msg=deleted');
      exit;
    } catch (Throwable $e) {
      error_log('Delete account_request failed: ' . $e->getMessage());
    }
  }
}

// Handle delete rating action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rating') {
  $ratingId = (int)($_POST['rating_id'] ?? 0);
  if ($ratingId > 0) {
    try {
      $stmt = $pdo->prepare('DELETE FROM ratings WHERE id = ?');
      $stmt->execute([$ratingId]);
      header('Location: admin_dashboard.php?panel=ratings&msg=rating_deleted');
      exit;
    } catch (Throwable $e) {
      error_log('Delete rating failed: ' . $e->getMessage());
    }
  }
}

// Handle delete all ratings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all_ratings') {
  try {
    $pdo->exec('DELETE FROM ratings');
    header('Location: admin_dashboard.php?panel=ratings&msg=ratings_cleared');
    exit;
  } catch (Throwable $e) {
    error_log('Delete all ratings failed: ' . $e->getMessage());
  }
}

// Safe queries with graceful fallbacks if optional tables are missing
try {
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total_users,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS total_students,
            SUM(CASE WHEN role = 'patient' THEN 1 ELSE 0 END) AS total_patients,
            SUM(CASE WHEN role = 'student' AND verified = 1 THEN 1 ELSE 0 END) AS verified_students,
            SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) AS total_admins
        FROM users"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $stats = ['total_users' => 0, 'total_students' => 0, 'total_patients' => 0, 'verified_students' => 0, 'total_admins' => 0];
}

try {
    $postStats = $pdo->query("SELECT COUNT(*) AS total_posts FROM posts")->fetch(PDO::FETCH_ASSOC) ?: ['total_posts' => 0];
} catch (Throwable $e) {
    $postStats = ['total_posts' => 0];
}

try {
    $messageStats = $pdo->query("SELECT COUNT(*) AS total_messages FROM messages")->fetch(PDO::FETCH_ASSOC) ?: ['total_messages' => 0];
} catch (Throwable $e) {
    $messageStats = ['total_messages' => 0];
}

try {
    $reportStats = $pdo->query("SELECT COUNT(*) AS total_reports, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_reports FROM reports")->fetch(PDO::FETCH_ASSOC) ?: ['total_reports' => 0, 'pending_reports' => 0];
} catch (Throwable $e) {
    $reportStats = ['total_reports' => 0, 'pending_reports' => 0];
}

try {
    $pendingVerifications = $pdo->query(
        "SELECT v.*, u.email, u.name AS user_name, u.id AS user_id
         FROM verifications v
         JOIN users u ON u.id = v.user_id
         WHERE v.status = 'pending'
         ORDER BY v.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pendingVerifications = [];
    error_log('Fetch pending verifications failed: ' . $e->getMessage());
}

try {
    $recentApprovals = $pdo->query(
        "SELECT v.*, u.name, u.email, v.user_id
         FROM verifications v
         JOIN users u ON u.id = v.user_id
         WHERE v.status = 'approved'
         ORDER BY COALESCE(v.processed_at, v.created_at) DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentApprovals = [];
    error_log('Fetch recent approvals failed: ' . $e->getMessage());
}

try {
  // recent users with optional filters: user_name (name or email), user_role
  $where = [];
  $params = [];

  $userNameQ = trim($_GET['user_name'] ?? '');
  if ($userNameQ !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $like = '%' . $userNameQ . '%';
    $params[] = $like; $params[] = $like;
  }

  $userRole = trim($_GET['user_role'] ?? '');
  if ($userRole !== '' && in_array($userRole, ['student','patient','admin'], true)) {
    // admin is a flag is_admin in users table; treat admin specially
    if ($userRole === 'admin') {
      $where[] = 'is_admin = 1';
    } else {
      $where[] = 'role = ?';
      $params[] = $userRole;
    }
  }

  $sql = 'SELECT id, name, email, role, verified, is_admin, created_at FROM users';
  if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY created_at DESC LIMIT 50';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Fetch recent users failed: ' . $e->getMessage());
  $recentUsers = [];
}

try {
    $recentComments = $pdo->query(
        "SELECT c.comment, c.created_at, u.name AS author_name, p.title AS post_title
         FROM comments c
         JOIN users u ON u.id = c.user_id
         JOIN posts p ON p.id = c.post_id
         ORDER BY c.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentComments = [];
}

try {
  $ratingFilters = [
    'keyword' => trim($_GET['rating_keyword'] ?? ''),
    'score' => $_GET['rating_score'] ?? '',
  ];
  $ratingWhere = [];
  $ratingParams = [];
  if ($ratingFilters['keyword'] !== '') {
    $ratingWhere[] = '(ru.name LIKE ? OR ru.email LIKE ? OR tu.name LIKE ? OR tu.email LIKE ?)';
    $kw = '%' . $ratingFilters['keyword'] . '%';
    $ratingParams[] = $kw;
    $ratingParams[] = $kw;
    $ratingParams[] = $kw;
    $ratingParams[] = $kw;
  }
  if ($ratingFilters['score'] !== '' && in_array($ratingFilters['score'], ['1','2','3','4','5'], true)) {
    $ratingWhere[] = 'r.rating = ?';
    $ratingParams[] = (int)$ratingFilters['score'];
  }
    $ratingSql = "SELECT r.id, r.rating AS score, r.comment, r.created_at,
      ru.name AS rater_name, ru.email AS rater_email, ru.avatar AS rater_avatar,
      tu.name AS target_name, tu.email AS target_email, tu.avatar AS target_avatar
     FROM ratings r
     JOIN users ru ON ru.id = r.user_id
     JOIN users tu ON tu.id = r.rated_user_id";
  if ($ratingWhere) {
    $ratingSql .= ' WHERE ' . implode(' AND ', $ratingWhere);
  }
  $ratingSql .= ' ORDER BY r.created_at DESC LIMIT 20';
  $ratingStmt = $pdo->prepare($ratingSql);
  $ratingStmt->execute($ratingParams);
  $recentRatings = $ratingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $recentRatings = [];
  error_log('Fetch ratings failed: ' . $e->getMessage());
}

// Fetch account requests with optional filters (status, type, q)
try {
  $allowedStatuses = ['pending','in_review','resolved','rejected'];
  $allowedTypes = ['delete_account','update_info','other'];

  $where = [];
  $params = [];

  $filterStatus = $_GET['filter_status'] ?? '';
  if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
    $where[] = 'ar.status = ?';
    $params[] = $filterStatus;
  }

  $filterType = $_GET['filter_type'] ?? '';
  if ($filterType !== '' && in_array($filterType, $allowedTypes, true)) {
    $where[] = 'ar.request_type = ?';
    $params[] = $filterType;
  }

  $q = trim($_GET['q'] ?? '');
  if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR ar.details LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
  }

  // Optional: filter by exact or partial user name
  $filterName = trim($_GET['filter_name'] ?? '');
  if ($filterName !== '') {
    $where[] = 'u.name LIKE ?';
    $params[] = '%' . $filterName . '%';
  }

  $sql = "SELECT ar.*, u.name AS user_name, u.email, u.id AS user_id
      FROM account_requests ar
      JOIN users u ON u.id = ar.user_id";
  if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY ar.created_at DESC LIMIT 50';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $accountRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Fetch account requests failed: ' . $e->getMessage());
  $accountRequests = [];
}

// Render page
require_once 'header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard-sidebar.css?v=<?php echo time(); ?>">
<style>
  /* Match student/patient dashboard layout */
  .premium-navbar { display: none !important; }
  body { padding-top: 0 !important; margin: 0 !important; }

  /* Reset hoàn toàn margin/padding trên html và body */
  html, body { margin: 0 !important; padding: 0 !important; }
  body { padding-top: 0 !important; }

  /* Xóa khoảng trắng từ container bao ngoài do header.php tạo ra */
  .dashboard-container,
  .container-wide {
    padding: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
  }

  /* Đảm bảo layout chiếm toàn bộ viewport theo chiều dọc */
  .dashboard-layout {
    min-height: 100vh !important;
    margin: 0 !important;
  }

  /* Đảm bảo topbar dính vào đỉnh, không có gap */
  .dashboard-main {
    margin-top: 0 !important;
    padding-top: 0 !important;
  }

  /* Đảm bảo dashboard-layout bắt đầu ngay từ đầu trang */
  body > .dashboard-layout {
    margin-top: 0 !important;
    padding-top: 0 !important;
  }

  /* Header opens .container.py-4 for non-dashboard pages; make it full-width here */
  .container.py-4 { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
  footer, .footer, .bg-light.text-muted { display: none !important; }

  /* Ẩn hoàn toàn dashboard-container từ header.php (chứa incoming call overlay, audio, v.v.) */
  body > .dashboard-container,
  body > .container-wide {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    padding: 0 !important;
    margin: 0 !important;
    pointer-events: none !important;
    z-index: -1 !important;
  }

  .admin-embed-frame {
    width: 100%;
    border: 0;
    min-height: calc(100vh - 56px - 3rem);
    background: transparent;
    border-radius: 16px;
  }

  /* ===== ADMIN HERO BANNER ===== */
  .admin-hero {
    position: relative;
    border-radius: 0 0 24px 24px;  /* Chỉ round góc dưới — góc trên phẳng để sát topbar */
    overflow: hidden;
    min-height: 320px;
    background: url('ảnh/logo%20web.jpg') center center no-repeat;
    background-size: contain;
    background-color: #f0f7ff;
  }
  /* Welcome section cùng màu nền với hero để không thấy gap */
  .dashboard-welcome-section {
    background-color: #f0f7ff !important;
  }
  .admin-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(15,23,42,0.25), rgba(59,130,246,0.15), rgba(139,92,246,0.15));
  }
  .admin-hero-content { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; gap: 1.5rem; padding: 2rem 2.5rem; }
  .admin-hero-left { color: #fff; }
  .admin-hero-left .hero-greeting { font-weight: 800; font-size: 1.5rem; margin-bottom: .6rem; }
  .hero-chips { display: flex; gap: .6rem; flex-wrap: wrap; }
  .hero-chip { display: inline-flex; align-items: center; gap: .4rem; font-size: .85rem; font-weight: 600; color: #0f172a; background: #fff; border-radius: 999px; padding: .45rem .85rem; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
  .hero-chip i { color: #3b82f6; }
  .hero-hint { display: inline-block; margin-top: .5rem; font-size: .8rem; opacity: .85; }
  .admin-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
  .hero-btn { display: inline-flex; align-items: center; gap: .5rem; padding: .6rem .95rem; border-radius: 12px; text-decoration: none !important; font-weight: 600; box-shadow: 0 8px 20px rgba(0,0,0,.18); transition: transform .2s ease, box-shadow .2s ease; }
  .hero-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,0,0,.22); }
  .hero-primary { background: #3b82f6; color: #fff; }
  .hero-secondary { background: #0ea5e9; color: #fff; }
  .hero-outline { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.35); }

  /* Feature cards */
  .feature-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.06); border: 1px solid rgba(226,232,240,.8); transition: transform 0.3s ease, box-shadow 0.3s ease; }
  .feature-card:hover { transform: translateY(-5px); box-shadow: 0 15px 40px rgba(0,0,0,.12); }
  .feature-card-header { position: relative; height: 160px; background: linear-gradient(120deg,#3b82f6,#06b6d4); display: flex; align-items: center; justify-content: center; background-size: cover; background-position: center; }
  .feature-card-header::before { content: ''; position: absolute; inset: 0; background: inherit; opacity: 0.85; }
  .feature-card-header .feature-icon { position: relative; z-index: 2; width: 56px; height: 56px; border-radius: 16px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.6rem; box-shadow: 0 10px 30px rgba(0,0,0,.25); background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4); backdrop-filter: blur(8px); }
  .feature-card-body { padding: 1.25rem; }
  .feature-title { font-weight: 700; font-size: 1rem; margin-bottom: .4rem; color: #1e293b; }
  .feature-desc { font-size: .85rem; color: #64748b; line-height: 1.5; }
  .feature-link { display: inline-flex; align-items: center; gap: .4rem; color: #3b82f6; text-decoration: none; font-weight: 600; margin-top: .75rem; transition: gap 0.3s ease; }
  .feature-link:hover { gap: .6rem; }
  .feature-card-header.with-image { background-size: cover; background-position: center; }
  .feature-card-header.with-image::before { background: var(--overlay-color, linear-gradient(135deg, rgba(59,130,246,0.8), rgba(6,182,212,0.7))); }

  /* Metrics counters */
  .mini-stat { background: #fff; border-radius: 16px; padding: .9rem 1rem; box-shadow: 0 8px 24px rgba(0,0,0,.06); display:flex; align-items:center; gap:.8rem; }
  .mini-stat .stat-icon { width: 40px; height: 40px; border-radius: 12px; display:flex; align-items:center; justify-content:center; color:#fff; }
  .mini-stat .stat-value { font-weight: 800; font-size: 1.25rem; color:#1e293b; line-height:1; }
  .mini-stat .stat-label { font-size: .8rem; color:#64748b; }
  .bg-blue { background: linear-gradient(135deg,#3b82f6,#06b6d4); }
  .bg-green { background: linear-gradient(135deg,#10b981,#34d399); }
  .bg-purple { background: linear-gradient(135deg,#8b5cf6,#a78bfa); }
  .bg-orange { background: linear-gradient(135deg,#f59e0b,#fbbf24); }
</style>

<style>
/* Premium Comments Moderation Section */
.section-card-premium {
    background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08), 0 8px 20px rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(226, 232, 240, 0.8);
    overflow: hidden;
    transition: all 0.4s ease;
}
.section-card-premium:hover {
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.12);
    transform: translateY(-2px);
}
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.08) 0%, rgba(139, 92, 246, 0.08) 100%);
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
}
.section-header-title {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    font-weight: 700;
    font-size: 1.1rem;
    color: #1e293b;
}
.section-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    transition: all 0.4s ease;
}
.section-card-premium:hover .section-icon {
    transform: scale(1.1) rotate(5deg);
}
.section-icon-cyan {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(6, 182, 212, 0.4);
}
.section-icon-purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
}
.badge-premium {
    padding: 0.5rem 1rem;
    border-radius: 999px;
    font-weight: 600;
    font-size: 0.8rem;
}
.badge-primary-soft {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%);
    color: #3b82f6;
    border: 1px solid rgba(59, 130, 246, 0.2);
}
.section-body {
    padding: 1.5rem;
}
.section-body-scroll {
    max-height: 450px;
    overflow-y: auto;
}

/* Empty State Premium */
.empty-state-premium {
    text-align: center;
    padding: 3rem 2rem;
}
.empty-state-premium .empty-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 1.5rem;
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    animation: iconFloat 3s ease-in-out infinite;
}
.empty-state-premium .empty-icon::before {
    content: '';
    position: absolute;
    inset: -10px;
    border-radius: 50%;
    border: 2px dashed rgba(6, 182, 212, 0.4);
    animation: rotateBorder 12s linear infinite;
}
.empty-state-premium .empty-icon::after {
    content: '';
    position: absolute;
    inset: -20px;
    border-radius: 50%;
    border: 1px dashed rgba(139, 92, 246, 0.3);
    animation: rotateBorder 18s linear infinite reverse;
}
@keyframes rotateBorder {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
@keyframes iconFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.empty-state-premium .empty-icon i {
    font-size: 2.8rem;
    background: linear-gradient(135deg, #06b6d4 0%, #8b5cf6 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.empty-state-premium h5 {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.empty-state-premium p {
    color: #64748b;
    font-size: 0.95rem;
    margin: 0;
}

/* Comments List */
.comments-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.comment-item-premium {
    display: flex;
    gap: 1rem;
    padding: 1.25rem;
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s ease;
}
.comment-item-premium:hover {
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.1);
    border-color: rgba(6, 182, 212, 0.3);
    transform: translateX(4px);
}
.comment-item-premium.comment-hidden {
    opacity: 0.6;
    background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
}
.comment-avatar img {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    object-fit: cover;
}
.comment-avatar .avatar-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 1.25rem;
}
.comment-content { flex: 1; min-width: 0; }
.comment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
}
.author-name { font-weight: 600; color: #1e293b; }
.comment-time { font-size: 0.8rem; color: #94a3b8; display: flex; align-items: center; gap: 0.35rem; }
.status-badge {
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.status-visible { background: rgba(16, 185, 129, 0.15); color: #059669; }
.status-hidden { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
.comment-text { color: #475569; font-size: 0.9rem; line-height: 1.6; margin-bottom: 0.75rem; }
.post-link {
    font-size: 0.8rem;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    background: rgba(241, 245, 249, 0.8);
    border-radius: 8px;
}
.comment-actions { display: flex; flex-direction: column; gap: 0.5rem; }
.action-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 1rem;
}
.action-btn.btn-hide { background: rgba(245, 158, 11, 0.15); color: #d97706; }
.action-btn.btn-hide:hover { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; transform: scale(1.1); }
.action-btn.btn-show { background: rgba(16, 185, 129, 0.15); color: #059669; }
.action-btn.btn-show:hover { background: linear-gradient(135deg, #10b981, #059669); color: #fff; transform: scale(1.1); }
.action-btn.btn-delete { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
.action-btn.btn-delete:hover { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; transform: scale(1.1); }

/* ========== User Requests Section Premium Styles ========== */
.user-requests-card .section-header {
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(168, 85, 247, 0.08) 100%);
}

.badge-purple-soft {
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(168, 85, 247, 0.15) 100%);
    color: #7c3aed;
    border: 1px solid rgba(139, 92, 246, 0.25);
}

/* Search Form */
.request-search-form {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.search-input-wrapper {
    position: relative;
    flex: 1;
    min-width: 200px;
}

.search-input-wrapper i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 1rem;
}

.search-input-wrapper .form-control {
    padding: 0.75rem 1rem 0.75rem 2.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    background: #f8fafc;
}

.search-input-wrapper .form-control:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
    background: #fff;
}

.btn-search, .btn-reset {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.75rem 1.25rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    border: none;
}

.btn-search {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff;
    box-shadow: 0 4px 15px rgba(139, 92, 246, 0.35);
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(139, 92, 246, 0.45);
}

.btn-reset {
    background: #f1f5f9;
    color: #64748b;
    border: 2px solid #e2e8f0;
}

.btn-reset:hover {
    background: #e2e8f0;
    color: #475569;
}

/* Requests List */
.requests-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* Request Item */
.request-item-premium {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 1.5rem;
    padding: 1.5rem;
    background: #ffffff;
    border-radius: 16px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

.request-item-premium:hover {
    border-color: rgba(139, 92, 246, 0.3);
    box-shadow: 0 8px 30px rgba(139, 92, 246, 0.12);
    transform: translateY(-2px);
}

.request-item-premium.status-resolved {
    border-left: 4px solid #10b981;
    background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
}

.request-item-premium.status-rejected {
    border-left: 4px solid #ef4444;
    background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
}

.request-item-premium.status-review {
    border-left: 4px solid #3b82f6;
    background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
}

/* Request Main Content */
.request-main {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.request-user {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.user-avatar-request {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.user-info-request {
    flex: 1;
}

.user-name {
    font-weight: 700;
    font-size: 1rem;
    color: #1e293b;
    margin-bottom: 0.15rem;
}

.user-email {
    font-size: 0.85rem;
    color: #64748b;
}

.request-details {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.request-type, .request-time {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
    padding: 0.4rem 0.85rem;
    border-radius: 8px;
    background: #f1f5f9;
    color: #64748b;
}

.request-type i, .request-time i {
    color: #8b5cf6;
}

.request-content {
    padding: 1rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    font-size: 0.9rem;
    color: #475569;
    line-height: 1.6;
    border-left: 3px solid #8b5cf6;
}

.admin-note-display {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.85rem 1rem;
    background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
    border-radius: 10px;
    font-size: 0.85rem;
    color: #6d28d9;
}

.admin-note-display i {
    margin-top: 0.15rem;
    flex-shrink: 0;
}

/* Request Sidebar */
.request-sidebar {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding-left: 1.5rem;
    border-left: 2px solid #e2e8f0;
}

.current-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.85rem;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    width: fit-content;
}

.current-status.status-resolved {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.current-status.status-rejected {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.current-status.status-review {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
}

.request-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.request-form-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.form-select-premium, .form-input-premium {
    padding: 0.6rem 0.85rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.85rem;
    background: #f8fafc;
    transition: all 0.3s ease;
    width: 100%;
}

.form-select-premium:focus, .form-input-premium:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
    background: #fff;
    outline: none;
}

.request-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.55rem 0.85rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    border: none;
}

.btn-save {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
}

.btn-view {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
    box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3);
}

.btn-view:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
    color: #fff;
}

.delete-form {
    display: inline;
}

.btn-delete-request {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #fff;
    box-shadow: 0 3px 10px rgba(239, 68, 68, 0.3);
}

.btn-delete-request:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);
}

.processed-time {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 0.5rem;
}

.processed-time i {
    color: #10b981;
}

/* Responsive for User Requests */
@media (max-width: 900px) {
    .request-item-premium {
        grid-template-columns: 1fr;
    }
    
    .request-sidebar {
        padding-left: 0;
        padding-top: 1rem;
        border-left: none;
        border-top: 2px solid #e2e8f0;
    }
}

@media (max-width: 640px) {
    .request-search-form {
        flex-direction: column;
    }
    
    .search-input-wrapper {
        width: 100%;
    }
    
    .btn-search, .btn-reset {
        width: 100%;
        justify-content: center;
    }
    
    .request-actions {
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
        justify-content: center;
    }
}
</style>

</div><!-- Đóng dashboard-container từ header.php -->
<div class="dashboard-layout">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <a href="index.php" class="sidebar-brand">
        <div class="sidebar-brand-icon">
          <img src="ảnh/logo web.jpg" alt="Logo Kết nối Y tế" class="sidebar-logo-img">
        </div>
        <span>Kết nối Y tế</span>
      </a>
    </div>

    <nav class="sidebar-menu">
      <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Tổng quan</div>
        <a href="#" class="sidebar-menu-item active" data-section="welcome" onclick="return showPanel('overview', 'Bảng điều khiển')">
          <i class="bi bi-grid-1x2-fill"></i>
          <span>Bảng điều khiển</span>
        </a>
      </div>

      <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Tổng hợp</div>
        <a href="#" class="sidebar-menu-item" data-panel="approvals" onclick="return showPanel('approvals', 'Lịch sử xét duyệt')">
          <i class="bi bi-clock-history"></i>
          <span>Lịch sử xét duyệt</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-panel="verify-requests" onclick="return showPanel('verify-requests', 'Yêu cầu xác thực')">
          <i class="bi bi-mortarboard-fill"></i>
          <span>Yêu cầu xác thực</span>
          <?php if (!empty($pendingVerifications)): ?>
            <span class="badge bg-success"><?php echo (int)count($pendingVerifications); ?></span>
          <?php endif; ?>
        </a>
        <a href="#" class="sidebar-menu-item" data-panel="new-users" onclick="return showPanel('new-users', 'Người dùng mới')">
          <i class="bi bi-person-lines-fill"></i>
          <span>Người dùng mới</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-panel="comments" onclick="return showPanel('comments', 'Kiểm duyệt bình luận')">
          <i class="bi bi-chat-square-quote-fill"></i>
          <span>Kiểm duyệt bình luận</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-panel="ratings" onclick="return showPanel('ratings', 'Đánh giá người dùng')">
          <i class="bi bi-stars"></i>
          <span>Đánh giá</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-panel="user-requests" onclick="return showPanel('user-requests', 'Yêu cầu người dùng')">
          <i class="bi bi-envelope-paper-fill"></i>
          <span>Yêu cầu người dùng</span>
          <?php if (!empty($accountRequests)): ?>
            <span class="badge bg-danger"><?php echo (int)count($accountRequests); ?></span>
          <?php endif; ?>
        </a>
      </div>

      <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Trang quản trị</div>
        <a href="#" class="sidebar-menu-item" data-section="users" onclick="return showSection('users', 'Người dùng')">
          <i class="bi bi-people-gear"></i>
          <span>Người dùng</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="verifications" onclick="return showSection('verifications', 'Xác minh')">
          <i class="bi bi-mortarboard-fill"></i>
          <span>Xác minh</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="posts" onclick="return showSection('posts', 'Bài viết')">
          <i class="bi bi-journal-text"></i>
          <span>Bài viết</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="favorites" onclick="return showSection('favorites', 'Yêu thích')">
          <i class="bi bi-heart-fill"></i>
          <span>Yêu thích</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="reports" onclick="return showSection('reports', 'Thống kê')">
          <i class="bi bi-bar-chart-fill"></i>
          <span>Thống kê</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="notifications" onclick="return showSection('notifications', 'Thông báo')">
          <i class="bi bi-megaphone-fill"></i>
          <span>Thông báo</span>
        </a>
      </div>

      <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Tài khoản</div>
        <a href="ai_assistant.php" class="sidebar-menu-item" style="background:linear-gradient(135deg,rgba(14,165,233,0.1),rgba(139,92,246,0.1));border:1px solid rgba(14,165,233,0.2);border-radius:10px;margin-bottom:4px;">
            <i class="bi bi-stars" style="color:#0ea5e9;"></i>
            <span style="background:linear-gradient(135deg,#38bdf8,#818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-weight:700;">Trợ lý AI</span>
            <span class="badge" style="background:linear-gradient(135deg,#0ea5e9,#7c3aed);font-size:.6rem;padding:2px 6px;border-radius:10px;color:#fff;">MỚI</span>
        </a>
        <a href="#" class="sidebar-menu-item" data-section="profile" onclick="return showSection('profile', 'Hồ sơ')">
          <i class="bi bi-person-badge-fill"></i>
          <span>Hồ sơ</span>
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

  <!-- Main content -->
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
        <span id="breadcrumb-current">Quản trị viên</span>
      </div>
    </div>

    <!-- Welcome (main dashboard) -->
    <div class="dashboard-welcome-section" style="padding: 0 1.5rem 1.5rem;">
      <!-- Overview Section - Hero, Features, Stats -->
      <div id="block-overview">
      <div class="admin-hero mb-3">
        <div class="admin-hero-content">
          <div class="admin-hero-left">
            <div class="hero-greeting">Chào buổi sáng, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Quản trị'); ?>!</div>
            <div class="hero-chips">
              <span class="hero-chip"><i class="bi bi-shield-lock-fill"></i> Quản trị viên</span>
              <span class="hero-chip"><i class="bi bi-patch-check-fill"></i> Đã xác minh</span>
            </div>
            <span class="hero-hint">Chọn một chức năng từ menu bên trái để bắt đầu.</span>
          </div>
          <div class="admin-hero-actions">
            <a class="hero-btn hero-primary" href="admin_posts.php?create=application#create-post"><i class="bi bi-person-badge-fill"></i> Tạo tin ứng tuyển</a>
            <a class="hero-btn hero-secondary" href="admin_send_notification.php"><i class="bi bi-megaphone-fill"></i> Thông báo</a>
            <a class="hero-btn hero-outline" href="conversations.php"><i class="bi bi-chat-dots"></i> Tin nhắn</a>
          </div>
        </div>
      </div>

      <!-- Feature cards grid -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3">
          <div class="feature-card">
            <div class="feature-card-header with-image" style="background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y.jpg');">
              <div class="feature-icon"><i class="bi bi-megaphone-fill"></i></div>
            </div>
            <div class="feature-card-body">
              <div class="feature-title">Quản lý bài viết</div>
              <div class="feature-desc">Tạo, chỉnh sửa và duyệt tin tuyển/ứng tuyển.</div>
              <a class="feature-link" href="admin_posts.php"><span>Xem tin</span> <i class="bi bi-arrow-right"></i></a>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <div class="feature-card">
            <div class="feature-card-header with-image" style="background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh Tại Nhà.jpg'); --overlay-color: linear-gradient(135deg, rgba(16,185,129,0.8), rgba(52,211,153,0.7));">
              <div class="feature-icon"><i class="bi bi-patch-check-fill"></i></div>
            </div>
            <div class="feature-card-body">
              <div class="feature-title">Xác minh người dùng</div>
              <div class="feature-desc">Duyệt hồ sơ sinh viên và giấy tờ liên quan.</div>
              <a class="feature-link" href="admin_verifications.php"><span>Xem danh sách</span> <i class="bi bi-arrow-right"></i></a>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <div class="feature-card">
            <div class="feature-card-header with-image" style="background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y khám bệnh.png'); --overlay-color: linear-gradient(135deg, rgba(245,158,11,0.8), rgba(251,191,36,0.7));">
              <div class="feature-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
            <div class="feature-card-body">
              <div class="feature-title">Thống kê hệ thống</div>
              <div class="feature-desc">Xem thống kê và báo cáo hoạt động.</div>
              <a class="feature-link" href="admin_reports.php"><span>Xem thống kê</span> <i class="bi bi-arrow-right"></i></a>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <div class="feature-card">
            <div class="feature-card-header with-image" style="background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh.webp'); --overlay-color: linear-gradient(135deg, rgba(139,92,246,0.8), rgba(167,139,250,0.7));">
              <div class="feature-icon"><i class="bi bi-star-fill"></i></div>
            </div>
            <div class="feature-card-body">
              <div class="feature-title">Đánh giá người dùng</div>
              <div class="feature-desc">Xem và quản lý các đánh giá, phản hồi.</div>
              <a class="feature-link" href="#ratings"><span>Xem đánh giá</span> <i class="bi bi-arrow-right"></i></a>
            </div>
          </div>
        </div>
      </div>

      <!-- Metrics counters -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="mini-stat">
            <div class="stat-icon bg-blue"><i class="bi bi-people-fill"></i></div>
            <div>
              <div class="stat-value"><?php echo (int)($stats['total_users'] ?? 0); ?></div>
              <div class="stat-label">Người dùng</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="mini-stat">
            <div class="stat-icon bg-green"><i class="bi bi-patch-check-fill"></i></div>
            <div>
              <div class="stat-value"><?php echo (int)($stats['verified_students'] ?? 0); ?></div>
              <div class="stat-label">Sinh viên đã xác minh</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="mini-stat">
            <div class="stat-icon bg-purple"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
              <div class="stat-value"><?php echo (int)($postStats['total_posts'] ?? 0); ?></div>
              <div class="stat-label">Tin đang đăng</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="mini-stat">
            <div class="stat-icon bg-orange"><i class="bi bi-bar-chart-fill"></i></div>
            <div>
              <div class="stat-value"><?php echo (int)($reportStats['pending_reports'] ?? 0); ?></div>
              <div class="stat-label">Báo cáo bình luận</div>
            </div>
          </div>
        </div>
      </div>
      </div><!-- End block-overview -->

  <?php if (!empty($_GET['welcome'])): ?>
    <div class="alert alert-success">Đăng nhập quản trị thành công.</div>
  <?php endif; ?>
  <?php
    $flashMessages = [
      'ok' => 'Đã cập nhật yêu cầu.',
      'deleted' => 'Đã xóa yêu cầu.',
      'rating_deleted' => 'Đã xóa đánh giá người dùng.',
      'ratings_cleared' => 'Đã xóa toàn bộ đánh giá.'
    ];
    $msgKey = $_GET['msg'] ?? '';
    if ($msgKey && isset($flashMessages[$msgKey])):
  ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?php echo $flashMessages[$msgKey]; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>
  <!-- Admin Header Section - Premium Design -->
  <style>
    .admin-header-premium {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 25%, #3b82f6 50%, #06b6d4 75%, #8b5cf6 100%);
      background-size: 400% 400%;
      border-radius: 28px;
      padding: 2rem 2.5rem;
      position: relative;
      overflow: hidden;
      animation: gradientMove 8s ease infinite;
      box-shadow: 0 25px 60px rgba(59, 130, 246, 0.4), 0 10px 30px rgba(139, 92, 246, 0.3);
    }
    @keyframes gradientMove {
      0%, 100% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
    }
    .admin-header-premium::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -30%;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 60%);
      animation: floatOrb 10s ease-in-out infinite;
    }
    @keyframes floatOrb {
      0%, 100% { transform: translate(0, 0) scale(1); }
      50% { transform: translate(-30px, 20px) scale(1.1); }
    }
    .admin-header-premium::after {
      content: '';
      position: absolute;
      bottom: -40%;
      left: -20%;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(139, 92, 246, 0.3) 0%, transparent 60%);
      animation: floatOrb2 12s ease-in-out infinite;
    }
    @keyframes floatOrb2 {
      0%, 100% { transform: translate(0, 0); }
      50% { transform: translate(25px, -20px); }
    }
    .admin-header-inner {
      position: relative;
      z-index: 2;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1.5rem;
    }
    .admin-title-group {
      display: flex;
      align-items: center;
      gap: 1.25rem;
    }
    .admin-icon-box {
      width: 68px;
      height: 68px;
      background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(139,92,246,0.3));
      backdrop-filter: blur(12px);
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      color: #fff;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.3);
      border: 1px solid rgba(255,255,255,0.25);
      transition: all 0.4s ease;
      animation: iconFloat 3s ease-in-out infinite;
    }
    @keyframes iconFloat {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-5px); }
    }
    .admin-icon-box:hover {
      transform: rotate(10deg) scale(1.1);
    }
    .admin-title-text h2 {
      font-size: 1.85rem;
      font-weight: 800;
      margin: 0 0 0.25rem 0;
      color: #fff;
      text-shadow: 0 2px 15px rgba(0,0,0,0.3);
    }
    .admin-title-text p {
      font-size: 0.95rem;
      color: rgba(255,255,255,0.85) !important;
      margin: 0;
      font-weight: 400;
    }
    .admin-buttons-row {
      display: flex;
      gap: 0.6rem;
      flex-wrap: wrap;
    }
    .admin-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.8rem 1.3rem;
      background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.08));
      backdrop-filter: blur(12px);
      border-radius: 14px;
      color: #fff !important;
      text-decoration: none !important;
      font-size: 0.875rem;
      font-weight: 600;
      border: 1px solid rgba(255,255,255,0.25);
      transition: all 0.35s ease;
      position: relative;
      overflow: hidden;
    }
    .admin-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
      transition: left 0.5s ease;
    }
    .admin-btn:hover::before {
      left: 100%;
    }
    .admin-btn:hover {
      background: linear-gradient(135deg, rgba(255,255,255,0.35), rgba(139,92,246,0.25));
      transform: translateY(-3px);
      box-shadow: 0 12px 30px rgba(0,0,0,0.25), 0 0 20px rgba(139,92,246,0.3);
    }
    .admin-btn i {
      font-size: 1.1rem;
      transition: transform 0.3s ease;
    }
    .admin-btn:hover i {
      transform: scale(1.15);
    }
    .admin-btn-alert {
      background: linear-gradient(135deg, #f59e0b, #ea580c) !important;
      border-color: rgba(255,255,255,0.35) !important;
      box-shadow: 0 5px 20px rgba(245,158,11,0.4);
      animation: alertPulse 2s ease-in-out infinite;
    }
    @keyframes alertPulse {
      0%, 100% { box-shadow: 0 5px 20px rgba(245,158,11,0.4); }
      50% { box-shadow: 0 8px 30px rgba(245,158,11,0.6), 0 0 15px rgba(234,88,12,0.4); }
    }
    .admin-btn-alert:hover {
      background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
    }
    @media (max-width: 992px) {
      .admin-header-inner { flex-direction: column; align-items: flex-start; }
      .admin-buttons-row { width: 100%; }
      .admin-btn { flex: 1 1 auto; justify-content: center; min-width: 140px; }
    }
    @media (max-width: 576px) {
      .admin-header-premium { padding: 1.5rem; border-radius: 20px; }
      .admin-title-text h2 { font-size: 1.4rem; }
      .admin-icon-box { width: 54px; height: 54px; font-size: 1.4rem; }
      .admin-btn span { display: none; }
      .admin-btn { padding: 0.75rem; min-width: auto; flex: 0; }
    }
  </style>
  
  <!-- Removed legacy admin header section per request -->


    
    <style>
      /* Premium Stat Cards */
    .stat-card-premium {
      background: #fff;
      border-radius: 20px;
      padding: 1.5rem;
      position: relative;
      overflow: hidden;
      border: none;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    }
    .stat-card-premium::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      border-radius: 20px 20px 0 0;
    }
    .stat-card-premium::after {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 150px;
      height: 150px;
      border-radius: 50%;
      opacity: 0.1;
      transition: all 0.4s ease;
    }
    .stat-card-premium:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 50px rgba(0,0,0,0.15);
    }
    .stat-card-premium:hover::after {
      transform: scale(1.5);
      opacity: 0.15;
    }
    /* Card Users - Blue */
    .stat-card-users::before { background: linear-gradient(90deg, #3b82f6, #06b6d4); }
    .stat-card-users::after { background: #3b82f6; }
    .stat-card-users .stat-icon-box {
      background: linear-gradient(135deg, #3b82f6, #06b6d4);
      box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
    }
    .stat-card-users:hover { box-shadow: 0 20px 50px rgba(59, 130, 246, 0.25); }
    /* Card Verified - Green */
    .stat-card-verified::before { background: linear-gradient(90deg, #10b981, #34d399); }
    .stat-card-verified::after { background: #10b981; }
    .stat-card-verified .stat-icon-box {
      background: linear-gradient(135deg, #10b981, #34d399);
      box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
    }
    .stat-card-verified:hover { box-shadow: 0 20px 50px rgba(16, 185, 129, 0.25); }
    /* Card Posts - Purple */
    .stat-card-posts::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
    .stat-card-posts::after { background: #8b5cf6; }
    .stat-card-posts .stat-icon-box {
      background: linear-gradient(135deg, #8b5cf6, #a78bfa);
      box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
    }
    .stat-card-posts:hover { box-shadow: 0 20px 50px rgba(139, 92, 246, 0.25); }
    /* Card Reports - Orange */
    .stat-card-reports::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .stat-card-reports::after { background: #f59e0b; }
    .stat-card-reports .stat-icon-box {
      background: linear-gradient(135deg, #f59e0b, #fbbf24);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }
    .stat-card-reports:hover { box-shadow: 0 20px 50px rgba(245, 158, 11, 0.25); }
    /* Icon Box */
    .stat-icon-box {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 1.5rem;
      transition: all 0.4s ease;
    }
    .stat-card-premium:hover .stat-icon-box {
      transform: rotate(10deg) scale(1.1);
    }
    .stat-label {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      margin-bottom: 0.5rem;
    }
    .stat-value {
      font-size: 2.25rem;
      font-weight: 800;
      color: #1e293b;
      line-height: 1;
      margin-bottom: 0.5rem;
    }
    .stat-sub {
      font-size: 0.8rem;
      color: #94a3b8;
    }
    @keyframes countUp {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .stat-card-premium { animation: countUp 0.6s ease-out backwards; }
    .stat-card-premium:nth-child(1) { animation-delay: 0.1s; }
    .stat-card-premium:nth-child(2) { animation-delay: 0.2s; }
    .stat-card-premium:nth-child(3) { animation-delay: 0.3s; }
    .stat-card-premium:nth-child(4) { animation-delay: 0.4s; }

    /* Premium Section Cards */
    .section-card-premium {
      background: #fff;
      border-radius: 24px;
      border: none;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0,0,0,0.06);
      transition: all 0.4s ease;
    }
    .section-card-premium:hover {
      box-shadow: 0 20px 60px rgba(0,0,0,0.1);
      transform: translateY(-4px);
    }
    .section-header {
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid rgba(0,0,0,0.05);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .section-header-title {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-weight: 700;
      font-size: 1rem;
      color: #1e293b;
    }
    .section-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      color: #fff;
      transition: all 0.3s ease;
    }
    .section-card-premium:hover .section-icon {
      transform: scale(1.1) rotate(5deg);
    }
    /* Icon colors */
    .section-icon-blue { background: linear-gradient(135deg, #3b82f6, #06b6d4); box-shadow: 0 6px 20px rgba(59,130,246,0.35); }
    .section-icon-green { background: linear-gradient(135deg, #10b981, #34d399); box-shadow: 0 6px 20px rgba(16,185,129,0.35); }
    .section-icon-purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); box-shadow: 0 6px 20px rgba(139,92,246,0.35); }
    .section-icon-amber { background: linear-gradient(135deg, #f59e0b, #fbbf24); box-shadow: 0 6px 20px rgba(245,158,11,0.35); }
    .section-icon-pink { background: linear-gradient(135deg, #ec4899, #f472b6); box-shadow: 0 6px 20px rgba(236,72,153,0.35); }
    
    .section-body {
      padding: 1.25rem 1.5rem;
    }
    .section-body-scroll {
      max-height: 280px;
      overflow-y: auto;
    }
    .section-body-scroll::-webkit-scrollbar {
      width: 6px;
    }
    .section-body-scroll::-webkit-scrollbar-track {
      background: #f1f5f9;
      border-radius: 3px;
    }
    .section-body-scroll::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, #cbd5e1, #94a3b8);
      border-radius: 3px;
    }
    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 2rem 1rem;
      color: #94a3b8;
    }
    .empty-state i {
      font-size: 2.5rem;
      margin-bottom: 0.75rem;
      opacity: 0.5;
    }
    /* Empty State Premium */
    .empty-state-premium {
      text-align: center;
      padding: 3rem 2rem;
    }
    .empty-state-premium .empty-icon {
      width: 100px;
      height: 100px;
      margin: 0 auto 1.5rem;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      animation: emptyIconFloat 3s ease-in-out infinite;
    }
    .empty-state-premium .empty-icon::before {
      content: '';
      position: absolute;
      inset: -10px;
      border-radius: 50%;
      border: 2px dashed currentColor;
      opacity: 0.3;
      animation: emptyIconRotate 12s linear infinite;
    }
    .empty-state-premium .empty-icon::after {
      content: '';
      position: absolute;
      inset: -20px;
      border-radius: 50%;
      border: 1px dashed currentColor;
      opacity: 0.2;
      animation: emptyIconRotate 18s linear infinite reverse;
    }
    .empty-state-premium .empty-icon i {
      font-size: 2.5rem;
    }
    .empty-state-premium .empty-icon-green {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(52, 211, 153, 0.15) 100%);
      color: #10b981;
    }
    .empty-state-premium .empty-icon-blue {
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(96, 165, 250, 0.15) 100%);
      color: #3b82f6;
    }
    .empty-state-premium .empty-icon-amber {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(251, 191, 36, 0.15) 100%);
      color: #f59e0b;
    }
    .empty-state-premium .empty-icon-purple {
      background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(167, 139, 250, 0.15) 100%);
      color: #8b5cf6;
    }
    @keyframes emptyIconFloat {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-10px); }
    }
    @keyframes emptyIconRotate {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    .empty-state-premium h5 {
      font-size: 1.15rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 0.5rem;
    }
    .empty-state-premium p {
      color: #64748b;
      font-size: 0.9rem;
      margin: 0;
      max-width: 280px;
      margin-left: auto;
      margin-right: auto;
    }
    /* List items */
    .list-item-premium {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.85rem 1rem;
      background: #f8fafc;
      border-radius: 12px;
      margin-bottom: 0.6rem;
      transition: all 0.3s ease;
      border: 1px solid transparent;
    }
    .list-item-premium:hover {
      background: #fff;
      border-color: rgba(59,130,246,0.2);
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
      transform: translateX(4px);
    }
    .list-item-premium:last-child { margin-bottom: 0; }
    .item-avatar {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      color: #fff;
      flex-shrink: 0;
    }
    .item-avatar-success { background: linear-gradient(135deg, #10b981, #34d399); }
    .item-avatar-primary { background: linear-gradient(135deg, #3b82f6, #60a5fa); }
    .item-avatar-warning { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
    .item-info { flex: 1; min-width: 0; margin-left: 0.75rem; }
    .item-name { font-weight: 600; color: #1e293b; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-sub { font-size: 0.8rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-time { font-size: 0.75rem; color: #94a3b8; margin-top: 4px; display: flex; align-items: center; gap: 4px; }
    .item-time i { font-size: 0.7rem; }
    .item-docs { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
    .doc-link { 
      font-size: 0.75rem; 
      color: #3b82f6; 
      text-decoration: none; 
      display: inline-flex; 
      align-items: center; 
      gap: 4px;
      padding: 4px 8px;
      background: rgba(59,130,246,0.1);
      border-radius: 6px;
      transition: all 0.2s ease;
    }
    .doc-link:hover { background: rgba(59,130,246,0.2); color: #2563eb; }
    .item-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .action-buttons { display: flex; gap: 6px; }
    .btn-action-sm {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 0.9rem;
      text-decoration: none;
    }
    .btn-action-view { background: rgba(59,130,246,0.15); color: #3b82f6; }
    .btn-action-view:hover { background: #3b82f6; color: #fff; transform: scale(1.1); }
    .btn-action-approve { background: rgba(16,185,129,0.15); color: #10b981; }
    .btn-action-approve:hover { background: #10b981; color: #fff; transform: scale(1.1); }
    .btn-action-reject { background: rgba(239,68,68,0.15); color: #ef4444; }
    .btn-action-reject:hover { background: #ef4444; color: #fff; transform: scale(1.1); }
    .btn-view-all {
      font-size: 0.8rem;
      color: #3b82f6;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 4px;
      font-weight: 600;
      transition: all 0.2s ease;
    }
    .btn-view-all:hover { color: #2563eb; gap: 8px; }
    .badge-count {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #fff;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 999px;
      margin-left: 8px;
      animation: pulse 2s infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }
    .verification-item { align-items: flex-start; }
    .verification-item .item-info { white-space: normal; }
    .verification-item .item-sub { white-space: normal; line-height: 1.4; }
    .badge-premium {
      padding: 0.35rem 0.75rem;
      border-radius: 8px;
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .badge-success-soft { background: rgba(16,185,129,0.15); color: #059669; }
    .badge-warning-soft { background: rgba(245,158,11,0.15); color: #d97706; }
    .badge-primary-soft { background: rgba(59,130,246,0.15); color: #2563eb; }
    /* Filter form */
    .filter-form {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid rgba(0,0,0,0.05);
    }
    .filter-form .form-control,
    .filter-form .form-select {
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      font-size: 0.85rem;
      padding: 0.5rem 0.75rem;
      transition: all 0.3s ease;
    }
    .filter-form .form-control:focus,
    .filter-form .form-select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    }
    .btn-filter {
      padding: 0.5rem 1rem;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    .btn-filter-primary {
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      color: #fff;
      border: none;
      box-shadow: 0 4px 15px rgba(59,130,246,0.35);
    }
    .btn-filter-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(59,130,246,0.45);
      color: #fff;
    }
    .btn-filter-outline {
      background: #fff;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }
    .btn-filter-outline:hover {
      background: #f8fafc;
      color: #1e293b;
    }
    .btn-danger-soft {
      background: rgba(239,68,68,0.1);
      color: #dc2626;
      border: 1px solid rgba(239,68,68,0.2);
      padding: 0.4rem 0.85rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    .btn-danger-soft:hover {
      background: #dc2626;
      color: #fff;
      border-color: #dc2626;
    }
  </style>

  <!-- Removed legacy stat cards row per request -->

  <div id="block-panels" class="row g-4" style="display:none;">
    <div id="admin-col-left" class="col-lg-6">
      <!-- Lịch sử xét duyệt -->
      <div id="panel-approvals" class="section-card-premium mb-4">
        <div class="section-header">
          <div class="section-header-title">
            <div class="section-icon section-icon-green">
              <i class="bi bi-clock-history"></i>
            </div>
            <span>Lịch sử xét duyệt gần đây</span>
          </div>
          <a href="admin_verifications.php?status=approved" class="btn-view-all">
            Xem tất cả <i class="bi bi-arrow-right"></i>
          </a>
        </div>
        <div class="section-body section-body-scroll" style="max-height:280px;">
          <?php if (empty($recentApprovals)): ?>
            <div class="empty-state-premium">
              <div class="empty-icon empty-icon-green">
                <i class="bi bi-clock-history"></i>
              </div>
              <h5>Chưa có lịch sử xét duyệt</h5>
              <p>Các yêu cầu xác thực đã được duyệt sẽ hiển thị tại đây</p>
            </div>
          <?php else: ?>
            <?php foreach ($recentApprovals as $a): ?>
              <div class="list-item-premium">
                <div class="d-flex align-items-center" style="min-width:0;">
                  <div class="item-avatar item-avatar-success">
                    <i class="bi bi-person-check-fill"></i>
                  </div>
                  <div class="item-info">
                    <div class="item-name"><?php echo htmlspecialchars($a['name']); ?></div>
                    <div class="item-sub">
                      <?php echo htmlspecialchars($a['email']); ?>
                      <?php if (!empty($a['student_code'])): ?>
                        · MSSV: <?php echo htmlspecialchars($a['student_code']); ?>
                      <?php endif; ?>
                    </div>
                    <div class="item-time">
                      <i class="bi bi-calendar-check"></i>
                      Duyệt ngày: <?php echo date('d/m/Y H:i', strtotime($a['processed_at'] ?? $a['created_at'])); ?>
                    </div>
                  </div>
                </div>
                <div class="item-actions">
                  <span class="badge-premium badge-success-soft">Đã duyệt</span>
                  <a href="view_profile.php?id=<?php echo $a['user_id']; ?>" class="btn-action-sm" title="Xem hồ sơ">
                    <i class="bi bi-eye"></i>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Yêu cầu xác thực sinh viên -->
      <div id="panel-verify-requests" class="section-card-premium mb-4">
        <div class="section-header">
          <div class="section-header-title">
            <div class="section-icon section-icon-blue">
              <i class="bi bi-mortarboard-fill"></i>
            </div>
            <span>Yêu cầu xác thực sinh viên</span>
            <?php if (!empty($pendingVerifications)): ?>
              <span class="badge-count"><?php echo count($pendingVerifications); ?></span>
            <?php endif; ?>
          </div>
          <a href="admin_verifications.php" class="btn-view-all">
            Xem tất cả <i class="bi bi-arrow-right"></i>
          </a>
        </div>
        <div class="section-body section-body-scroll" style="max-height:320px;">
          <?php if (empty($pendingVerifications)): ?>
            <div class="empty-state-premium">
              <div class="empty-icon empty-icon-blue">
                <i class="bi bi-mortarboard-fill"></i>
              </div>
              <h5>Chưa có yêu cầu mới</h5>
              <p>Các yêu cầu xác thực sinh viên đang chờ duyệt sẽ hiển thị tại đây</p>
            </div>
          <?php else: ?>
            <?php foreach ($pendingVerifications as $v): ?>
              <div class="list-item-premium verification-item">
                <div class="d-flex align-items-center" style="min-width:0;">
                  <div class="item-avatar item-avatar-primary">
                    <i class="bi bi-shield-plus"></i>
                  </div>
                  <div class="item-info">
                    <div class="item-name"><?php echo htmlspecialchars($v['full_name'] ?? $v['user_name'] ?? ''); ?></div>
                    <div class="item-sub">
                      <?php echo htmlspecialchars($v['email']); ?>
                      <?php if (!empty($v['student_code'])): ?>
                        · MSSV: <?php echo htmlspecialchars($v['student_code']); ?>
                      <?php endif; ?>
                      <?php if (!empty($v['class_name'])): ?>
                        · Lớp: <?php echo htmlspecialchars($v['class_name']); ?>
                      <?php endif; ?>
                    </div>
                    <div class="item-time">
                      <i class="bi bi-clock"></i>
                      Gửi lúc: <?php echo date('d/m/Y H:i', strtotime($v['created_at'])); ?>
                    </div>
                    <?php if (!empty($v['document_card'])): ?>
                      <div class="item-docs">
                        <a href="<?php echo htmlspecialchars($v['document_card']); ?>" target="_blank" class="doc-link">
                          <i class="bi bi-image"></i> Thẻ SV
                        </a>
                        <?php if (!empty($v['document_internship'])): ?>
                          <a href="<?php echo htmlspecialchars($v['document_internship']); ?>" target="_blank" class="doc-link">
                            <i class="bi bi-file-earmark-text"></i> Giấy TT
                          </a>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="item-actions">
                  <span class="badge-premium badge-warning-soft">Chờ duyệt</span>
                  <div class="action-buttons">
                    <a href="admin_verifications.php?action=view&id=<?php echo $v['id']; ?>" class="btn-action-sm btn-action-view" title="Xem chi tiết">
                      <i class="bi bi-eye"></i>
                    </a>
                    <form method="post" action="admin_verifications.php" class="d-inline">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                      <button type="submit" class="btn-action-sm btn-action-approve" title="Duyệt nhanh" onclick="return confirm('Duyệt xác thực cho sinh viên này?');">
                        <i class="bi bi-check-lg"></i>
                      </button>
                    </form>
                    <form method="post" action="admin_verifications.php" class="d-inline">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                      <button type="submit" class="btn-action-sm btn-action-reject" title="Từ chối" onclick="return confirm('Từ chối yêu cầu này?');">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Đánh giá người dùng -->
      <div id="panel-ratings" class="section-card-premium">
        <div class="section-header" style="flex-wrap:wrap;">
          <div class="section-header-title">
            <div class="section-icon section-icon-amber">
              <i class="bi bi-stars"></i>
            </div>
            <span>Đánh giá người dùng</span>
          </div>
          <form method="post" onsubmit="return confirm('Xóa toàn bộ đánh giá?');" class="d-inline">
            <input type="hidden" name="action" value="delete_all_ratings">
            <button type="submit" class="btn-danger-soft">
              <i class="bi bi-trash3"></i> Xóa tất cả
            </button>
          </form>
          <form method="get" class="filter-form w-100">
            <input type="text" name="rating_keyword" value="<?php echo htmlspecialchars($ratingFilters['keyword'] ?? ''); ?>" class="form-control" placeholder="Tìm theo tên hoặc email" style="flex:2;min-width:150px;">
            <select name="rating_score" class="form-select" style="flex:1;min-width:100px;">
              <option value="">Tất cả điểm</option>
              <?php for ($s=5;$s>=1;$s--): ?>
                <option value="<?php echo $s; ?>" <?php echo (!empty($ratingFilters['score']) && (int)$ratingFilters['score'] === $s) ? 'selected' : ''; ?>><?php echo $s; ?> sao</option>
              <?php endfor; ?>
            </select>
            <button type="submit" class="btn-filter btn-filter-primary">
              <i class="bi bi-funnel"></i> Lọc
            </button>
            <a href="admin_dashboard.php" class="btn-filter btn-filter-outline">Đặt lại</a>
          </form>
        </div>
        <div class="section-body section-body-scroll">
          <?php if (empty($recentRatings)): ?>
            <div class="empty-state">
              <i class="bi bi-star"></i>
              <p class="mb-0">Chưa có đánh giá nào.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Người đánh giá</th>
                    <th>Người được đánh giá</th>
                    <th>Điểm</th>
                    <th>Nội dung</th>
                    <th>Thời gian</th>
                    <th class="text-end">Thao tác</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentRatings as $rating): ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <?php if (!empty($rating['rater_avatar']) && upload_exists($rating['rater_avatar'])):
                            $avatarUrl = htmlspecialchars(public_url_for($rating['rater_avatar'])); ?>
                            <img src="<?php echo $avatarUrl; ?>" class="rounded-circle" width="36" height="36" alt="<?php echo htmlspecialchars($rating['rater_name'] ?? ''); ?>">
                          <?php else: ?>
                            <div class="rounded-circle bg-light text-secondary d-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                              <?php echo strtoupper(substr($rating['rater_name'] ?? '', 0, 1)); ?>
                            </div>
                          <?php endif; ?>
                          <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($rating['rater_name'] ?? ''); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($rating['rater_email'] ?? ''); ?></div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <?php if (!empty($rating['target_avatar']) && upload_exists($rating['target_avatar'])):
                            $targetAvatar = htmlspecialchars(public_url_for($rating['target_avatar'])); ?>
                            <img src="<?php echo $targetAvatar; ?>" class="rounded-circle" width="36" height="36" alt="<?php echo htmlspecialchars($rating['target_name'] ?? ''); ?>">
                          <?php else: ?>
                            <div class="rounded-circle bg-light text-secondary d-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                              <?php echo strtoupper(substr($rating['target_name'] ?? '', 0, 1)); ?>
                            </div>
                          <?php endif; ?>
                          <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($rating['target_name'] ?? ''); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($rating['target_email'] ?? ''); ?></div>
                          </div>
                        </div>
                      </td>
                      <td><span class="badge bg-warning text-dark"><?php echo (int)($rating['score'] ?? 0); ?> / 5</span></td>
                      <td class="small" style="max-width:260px;white-space:pre-wrap"><?php echo nl2br(htmlspecialchars($rating['comment'] ?? '')); ?></td>
                      <td class="small text-muted"><?php echo date('d/m/Y H:i', strtotime($rating['created_at'] ?? 'now')); ?></td>
                      <td class="text-end">
                        <form method="post" onsubmit="return confirm('Xóa đánh giá này?');">
                          <input type="hidden" name="action" value="delete_rating">
                          <input type="hidden" name="rating_id" value="<?php echo (int)($rating['id'] ?? 0); ?>">
                          <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
                        </form>
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

    <div id="admin-col-right" class="col-lg-6">
      <style>
        .users-card-premium {
          background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
          border-radius: 24px;
          border: none;
          box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
          overflow: hidden;
          transition: all 0.4s ease;
        }
        .users-card-premium:hover {
          box-shadow: 0 25px 60px rgba(59, 130, 246, 0.12);
        }
        .users-card-header {
          background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
          padding: 1.25rem 1.5rem;
          border-bottom: 1px solid rgba(226, 232, 240, 0.8);
          display: flex;
          align-items: center;
          gap: 0.75rem;
        }
        .users-header-icon {
          width: 42px;
          height: 42px;
          background: linear-gradient(135deg, #3b82f6, #8b5cf6);
          border-radius: 12px;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #fff;
          font-size: 1.1rem;
          box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        .users-header-title {
          font-weight: 700;
          font-size: 1.05rem;
          color: #1e293b;
        }
        .users-search-form {
          background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(139, 92, 246, 0.05));
          border-radius: 16px;
          padding: 1rem;
          margin-bottom: 1.25rem;
          border: 1px solid rgba(59, 130, 246, 0.1);
        }
        .users-search-form .form-control,
        .users-search-form .form-select {
          border-radius: 10px;
          border: 1px solid #e2e8f0;
          padding: 0.6rem 1rem;
          font-size: 0.875rem;
          transition: all 0.3s ease;
        }
        .users-search-form .form-control:focus,
        .users-search-form .form-select:focus {
          border-color: #3b82f6;
          box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .users-search-form .btn-filter {
          background: linear-gradient(135deg, #3b82f6, #2563eb);
          border: none;
          border-radius: 10px;
          padding: 0.6rem 1.25rem;
          font-weight: 600;
          color: #fff;
          transition: all 0.3s ease;
          box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        .users-search-form .btn-filter:hover {
          transform: translateY(-2px);
          box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        .users-search-form .btn-reset {
          background: #f1f5f9;
          border: 1px solid #e2e8f0;
          border-radius: 10px;
          padding: 0.6rem 1rem;
          font-weight: 500;
          color: #64748b;
          transition: all 0.3s ease;
        }
        .users-search-form .btn-reset:hover {
          background: #e2e8f0;
          color: #475569;
        }
        .users-table-premium {
          width: 100%;
          border-collapse: separate;
          border-spacing: 0;
        }
        .users-table-premium thead th {
          background: linear-gradient(135deg, #f8fafc, #f1f5f9);
          padding: 0.85rem 1rem;
          font-size: 0.8rem;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 0.05em;
          color: #64748b;
          border-bottom: 2px solid #e2e8f0;
        }
        .users-table-premium tbody tr {
          transition: all 0.3s ease;
        }
        .users-table-premium tbody tr:hover {
          background: linear-gradient(135deg, rgba(59, 130, 246, 0.04), rgba(139, 92, 246, 0.04));
        }
        .users-table-premium tbody td {
          padding: 1rem;
          border-bottom: 1px solid #f1f5f9;
          vertical-align: middle;
        }
        .user-info-cell {
          display: flex;
          align-items: center;
          gap: 0.75rem;
        }
        .user-avatar-box {
          width: 44px;
          height: 44px;
          border-radius: 12px;
          background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
          display: flex;
          align-items: center;
          justify-content: center;
          font-weight: 700;
          color: #4f46e5;
          font-size: 1rem;
          flex-shrink: 0;
        }
        .user-name {
          font-weight: 600;
          color: #1e293b;
          font-size: 0.95rem;
          margin-bottom: 0.15rem;
        }
        .user-email {
          font-size: 0.8rem;
          color: #94a3b8;
        }
        .role-badge {
          display: inline-flex;
          align-items: center;
          gap: 0.35rem;
          padding: 0.35rem 0.75rem;
          border-radius: 8px;
          font-size: 0.75rem;
          font-weight: 600;
          text-transform: capitalize;
        }
        .role-badge.patient {
          background: linear-gradient(135deg, #fef3c7, #fde68a);
          color: #92400e;
        }
        .role-badge.student {
          background: linear-gradient(135deg, #dbeafe, #bfdbfe);
          color: #1e40af;
        }
        .role-badge.admin {
          background: linear-gradient(135deg, #f3e8ff, #e9d5ff);
          color: #7c3aed;
        }
        .badge-verified {
          background: linear-gradient(135deg, #10b981, #059669);
          color: #fff;
          padding: 0.3rem 0.6rem;
          border-radius: 6px;
          font-size: 0.7rem;
          font-weight: 600;
          box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .badge-admin-tag {
          background: linear-gradient(135deg, #6366f1, #4f46e5);
          color: #fff;
          padding: 0.3rem 0.6rem;
          border-radius: 6px;
          font-size: 0.7rem;
          font-weight: 600;
          box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        .action-btn {
          padding: 0.45rem 0.85rem;
          border-radius: 8px;
          font-size: 0.8rem;
          font-weight: 600;
          transition: all 0.3s ease;
          border: none;
        }
        .action-btn-view {
          background: linear-gradient(135deg, #3b82f6, #2563eb);
          color: #fff;
          box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3);
        }
        .action-btn-view:hover {
          transform: translateY(-2px);
          box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
          color: #fff;
        }
      </style>
      
      <div id="panel-new-users" class="users-card-premium">
        <div class="users-card-header">
          <div class="users-header-icon">
            <i class="bi bi-person-lines-fill"></i>
          </div>
          <span class="users-header-title">Người dùng mới</span>
        </div>
        <div class="card-body" style="max-height:400px;overflow:auto;padding:1.25rem;">
          <form method="get" class="users-search-form">
            <div class="d-flex gap-2 flex-wrap">
              <input type="text" name="user_name" class="form-control" placeholder="🔍 Tìm theo tên hoặc email" value="<?php echo htmlspecialchars($_GET['user_name'] ?? ''); ?>" style="flex:1;min-width:200px;">
              <select name="user_role" class="form-select" style="width:170px;">
                <option value="">-- Vai trò (tất cả) --</option>
                <option value="student" <?php echo (isset($_GET['user_role']) && $_GET['user_role']==='student') ? 'selected' : ''; ?>>🎓 Student</option>
                <option value="patient" <?php echo (isset($_GET['user_role']) && $_GET['user_role']==='patient') ? 'selected' : ''; ?>>🏥 Patient</option>
                <option value="admin" <?php echo (isset($_GET['user_role']) && $_GET['user_role']==='admin') ? 'selected' : ''; ?>>👑 Admin</option>
              </select>
              <button type="submit" class="btn btn-filter">Lọc</button>
              <a href="admin_dashboard.php" class="btn btn-reset">Đặt lại</a>
            </div>
          </form>
          <?php if (empty($recentUsers)): ?>
            <div class="text-center py-4">
              <div style="font-size:3rem;opacity:0.3;margin-bottom:0.5rem;">👥</div>
              <div class="text-muted">Chưa có người dùng mới.</div>
            </div>
          <?php else: ?>
            <table class="users-table-premium">
              <thead>
                <tr>
                  <th>Họ tên</th>
                  <th>Vai trò</th>
                  <th>Thao tác</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentUsers as $u): ?>
                  <tr>
                    <td>
                      <div class="user-info-cell">
                        <div class="user-avatar-box">
                          <?php echo strtoupper(substr($u['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div>
                          <div class="user-name"><?php echo htmlspecialchars($u['name'] ?? ''); ?></div>
                          <div class="user-email"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="role-badge <?php echo htmlspecialchars($u['role'] ?? ''); ?>"><?php echo htmlspecialchars($u['role'] ?? ''); ?></span>
                      <?php if (!empty($u['verified'])): ?><span class="badge-verified ms-1">Xác thực</span><?php endif; ?>
                      <?php if (!empty($u['is_admin'])): ?><span class="badge-admin-tag ms-1">Admin</span><?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <a class="action-btn action-btn-view" href="view_profile.php?id=<?php echo (int)($u['id'] ?? 0); ?>">Xem</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Premium Comments Moderation Section -->
      <div id="panel-comments" class="section-card-premium mt-4 comments-moderation-card">
        <div class="section-header">
          <div class="section-header-title">
            <div class="section-icon section-icon-cyan">
              <i class="bi bi-chat-square-quote-fill"></i>
            </div>
            <span>Kiểm duyệt bình luận</span>
          </div>
          <?php
            // Lấy tất cả bình luận với thông tin đầy đủ
            $allComments = [];
            $totalComments = 0;
            $hiddenComments = 0;
            $visibleComments = 0;
            try {
              $allComments = $pdo->query("
                SELECT c.id, c.content AS comment, c.is_hidden, c.created_at, c.post_id,
                       u.id AS user_id, u.name AS author_name, u.avatar AS author_avatar, u.email AS author_email,
                       p.title AS post_title, p.user_id AS post_owner_id
                FROM comments c 
                JOIN users u ON u.id = c.user_id 
                JOIN posts p ON p.id = c.post_id 
                ORDER BY c.created_at DESC 
                LIMIT 50
              ")->fetchAll(PDO::FETCH_ASSOC);
              $totalComments = count($allComments);
              $hiddenComments = count(array_filter($allComments, fn($c) => !empty($c['is_hidden'])));
              $visibleComments = $totalComments - $hiddenComments;
            } catch (Throwable $e) {
              error_log('Fetch comments error: ' . $e->getMessage());
            }
          ?>
          <div class="d-flex align-items-center gap-2">
            <span class="badge badge-premium badge-primary-soft"><?php echo $totalComments; ?> bình luận</span>
          </div>
        </div>
        
        <!-- Filter Tabs -->
        <div class="comments-filter-tabs">
          <button class="filter-tab active" onclick="filterComments('all')">
            <i class="bi bi-chat-dots"></i> Tất cả <span class="count"><?php echo $totalComments; ?></span>
          </button>
          <button class="filter-tab" onclick="filterComments('visible')">
            <i class="bi bi-eye"></i> Hiển thị <span class="count"><?php echo $visibleComments; ?></span>
          </button>
          <button class="filter-tab" onclick="filterComments('hidden')">
            <i class="bi bi-eye-slash"></i> Đã ẩn <span class="count"><?php echo $hiddenComments; ?></span>
          </button>
        </div>
        
        <!-- Search Box -->
        <div class="comments-search-box">
          <div class="search-input-wrapper">
            <i class="bi bi-search"></i>
            <input type="text" id="commentSearchInput" placeholder="Tìm kiếm bình luận..." onkeyup="searchComments()">
          </div>
        </div>
        
        <div class="section-body section-body-scroll" style="max-height: 600px;">
          <?php if (empty($allComments)): ?>
            <div class="empty-state-premium">
              <div class="empty-icon">
                <i class="bi bi-chat-square-dots"></i>
              </div>
              <h5>Chưa có bình luận</h5>
              <p>Các bình luận mới sẽ xuất hiện tại đây</p>
            </div>
          <?php else: ?>
            <div class="comments-list" id="commentsList">
              <?php foreach ($allComments as $c): ?>
                <div class="comment-item-premium <?php echo !empty($c['is_hidden']) ? 'comment-hidden' : ''; ?>" 
                     data-status="<?php echo !empty($c['is_hidden']) ? 'hidden' : 'visible'; ?>"
                     data-content="<?php echo htmlspecialchars(strtolower($c['comment'] ?? '')); ?>"
                     data-author="<?php echo htmlspecialchars(strtolower($c['author_name'] ?? '')); ?>">
                  <div class="comment-avatar">
                    <?php if (!empty($c['author_avatar'])): ?>
                      <img src="<?php echo htmlspecialchars($c['author_avatar']); ?>" alt="Avatar" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                      <div class="avatar-placeholder" style="display:none;">
                        <?php echo strtoupper(mb_substr($c['author_name'] ?? 'U', 0, 1)); ?>
                      </div>
                    <?php else: ?>
                      <div class="avatar-placeholder">
                        <?php echo strtoupper(mb_substr($c['author_name'] ?? 'U', 0, 1)); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="comment-content">
                    <div class="comment-header">
                      <div class="comment-author">
                        <span class="author-name"><?php echo htmlspecialchars($c['author_name'] ?? ''); ?></span>
                        <span class="author-email"><?php echo htmlspecialchars($c['author_email'] ?? ''); ?></span>
                      </div>
                      <div class="comment-status-time">
                        <?php if (!empty($c['is_hidden'])): ?>
                          <span class="status-badge status-hidden">
                            <i class="bi bi-eye-slash-fill"></i> Đã ẩn
                          </span>
                        <?php else: ?>
                          <span class="status-badge status-visible">
                            <i class="bi bi-eye-fill"></i> Hiển thị
                          </span>
                        <?php endif; ?>
                        <span class="comment-time">
                          <i class="bi bi-clock"></i>
                          <?php echo date('d/m/Y H:i', strtotime($c['created_at'] ?? 'now')); ?>
                        </span>
                      </div>
                    </div>
                    <div class="comment-text"><?php echo nl2br(htmlspecialchars($c['comment'] ?? '')); ?></div>
                    <div class="comment-meta">
                      <a href="view_post.php?id=<?php echo $c['post_id']; ?>" target="_blank" class="post-link">
                        <i class="bi bi-file-earmark-text"></i>
                        <?php echo htmlspecialchars(mb_substr($c['post_title'] ?? '', 0, 50)); ?><?php echo mb_strlen($c['post_title'] ?? '') > 50 ? '...' : ''; ?>
                      </a>
                    </div>
                  </div>
                  <div class="comment-actions">
                    <button class="action-btn <?php echo !empty($c['is_hidden']) ? 'btn-show' : 'btn-hide'; ?>" 
                            title="<?php echo !empty($c['is_hidden']) ? 'Hiện bình luận' : 'Ẩn bình luận'; ?>"
                            onclick="toggleCommentVisibility(<?php echo (int)$c['id']; ?>, <?php echo !empty($c['is_hidden']) ? '0' : '1'; ?>)">
                      <i class="bi <?php echo !empty($c['is_hidden']) ? 'bi-eye-fill' : 'bi-eye-slash-fill'; ?>"></i>
                    </button>
                    <a href="view_post.php?id=<?php echo $c['post_id']; ?>#comment-<?php echo $c['id']; ?>" target="_blank" class="action-btn btn-view" title="Xem bài viết">
                      <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <button class="action-btn btn-delete" title="Xóa bình luận" onclick="deleteComment(<?php echo (int)$c['id']; ?>)">
                      <i class="bi bi-trash3-fill"></i>
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      
      <style>
      /* Comments Filter Tabs */
      .comments-filter-tabs {
        display: flex;
        gap: 0.5rem;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
      }
      .filter-tab {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border: none;
        background: #fff;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
      }
      .filter-tab:hover {
        background: #f1f5f9;
        color: #1e293b;
      }
      .filter-tab.active {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #fff;
        border-color: transparent;
      }
      .filter-tab .count {
        background: rgba(255,255,255,0.2);
        padding: 0.15rem 0.5rem;
        border-radius: 10px;
        font-size: 0.75rem;
      }
      .filter-tab.active .count {
        background: rgba(255,255,255,0.3);
      }
      
      /* Comments Search Box */
      .comments-search-box {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
      }
      .comments-search-box .search-input-wrapper {
        position: relative;
      }
      .comments-search-box .search-input-wrapper i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
      }
      .comments-search-box input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.9rem;
        transition: all 0.2s;
      }
      .comments-search-box input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
      }
      
      /* Comment Item Premium */
      .comment-item-premium {
        display: flex;
        gap: 1rem;
        padding: 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        transition: all 0.2s;
      }
      .comment-item-premium:hover {
        background: #f8fafc;
      }
      .comment-item-premium.comment-hidden {
        background: #fef2f2;
        opacity: 0.8;
      }
      .comment-item-premium .comment-avatar {
        flex-shrink: 0;
      }
      .comment-item-premium .comment-avatar img {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        object-fit: cover;
      }
      .comment-item-premium .avatar-placeholder {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1.1rem;
      }
      .comment-item-premium .comment-content {
        flex: 1;
        min-width: 0;
      }
      .comment-item-premium .comment-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
        flex-wrap: wrap;
        gap: 0.5rem;
      }
      .comment-item-premium .author-name {
        font-weight: 600;
        color: #1e293b;
        margin-right: 0.5rem;
      }
      .comment-item-premium .author-email {
        font-size: 0.8rem;
        color: #94a3b8;
      }
      .comment-item-premium .comment-status-time {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .comment-item-premium .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.6rem;
        border-radius: 15px;
        font-size: 0.7rem;
        font-weight: 600;
      }
      .comment-item-premium .status-visible {
        background: #dcfce7;
        color: #166534;
      }
      .comment-item-premium .status-hidden {
        background: #fee2e2;
        color: #991b1b;
      }
      .comment-item-premium .comment-time {
        font-size: 0.8rem;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 0.25rem;
      }
      .comment-item-premium .comment-text {
        color: #475569;
        font-size: 0.95rem;
        line-height: 1.6;
        margin-bottom: 0.75rem;
        word-break: break-word;
      }
      .comment-item-premium .comment-meta {
        display: flex;
        align-items: center;
        gap: 1rem;
      }
      .comment-item-premium .post-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.8rem;
        color: #3b82f6;
        text-decoration: none;
        padding: 0.25rem 0.6rem;
        background: #eff6ff;
        border-radius: 6px;
        transition: all 0.2s;
      }
      .comment-item-premium .post-link:hover {
        background: #dbeafe;
        color: #1d4ed8;
      }
      .comment-item-premium .comment-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        flex-shrink: 0;
      }
      .comment-item-premium .action-btn {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.9rem;
      }
      .comment-item-premium .btn-hide {
        background: #fef3c7;
        color: #b45309;
      }
      .comment-item-premium .btn-hide:hover {
        background: #fde68a;
      }
      .comment-item-premium .btn-show {
        background: #dcfce7;
        color: #166534;
      }
      .comment-item-premium .btn-show:hover {
        background: #bbf7d0;
      }
      .comment-item-premium .btn-view {
        background: #dbeafe;
        color: #1d4ed8;
      }
      .comment-item-premium .btn-view:hover {
        background: #bfdbfe;
      }
      .comment-item-premium .btn-delete {
        background: #fee2e2;
        color: #dc2626;
      }
      .comment-item-premium .btn-delete:hover {
        background: #fecaca;
      }
      
      @media (max-width: 768px) {
        .comments-filter-tabs {
          flex-wrap: wrap;
        }
        .comment-item-premium {
          flex-direction: column;
        }
        .comment-item-premium .comment-actions {
          flex-direction: row;
          justify-content: flex-end;
        }
      }
      </style>
      
      <script>
      // Filter comments by status
      function filterComments(status) {
        var tabs = document.querySelectorAll('.filter-tab');
        tabs.forEach(function(tab) {
          tab.classList.remove('active');
        });
        event.target.closest('.filter-tab').classList.add('active');
        
        var items = document.querySelectorAll('.comment-item-premium');
        items.forEach(function(item) {
          if (status === 'all') {
            item.style.display = '';
          } else if (status === 'hidden' && item.dataset.status === 'hidden') {
            item.style.display = '';
          } else if (status === 'visible' && item.dataset.status === 'visible') {
            item.style.display = '';
          } else {
            item.style.display = 'none';
          }
        });
      }
      
      // Search comments
      function searchComments() {
        var searchText = document.getElementById('commentSearchInput').value.toLowerCase();
        var items = document.querySelectorAll('.comment-item-premium');
        items.forEach(function(item) {
          var content = item.dataset.content || '';
          var author = item.dataset.author || '';
          if (content.includes(searchText) || author.includes(searchText)) {
            item.style.display = '';
          } else {
            item.style.display = 'none';
          }
        });
      }
      
      // Toggle comment visibility
      function toggleCommentVisibility(commentId, hide) {
        if (!confirm(hide ? 'Ẩn bình luận này?' : 'Hiện bình luận này?')) return;
        
        fetch('api/comments.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({action: 'toggle_hide', comment_id: commentId, hide: hide})
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data.success) {
            location.reload();
          } else {
            alert(data.message || 'Có lỗi xảy ra');
          }
        })
        .catch(function() {
          alert('Lỗi kết nối');
        });
      }
      
      // Delete comment
      function deleteComment(commentId) {
        if (!confirm('Bạn có chắc muốn xóa bình luận này? Hành động này không thể hoàn tác.')) return;
        
        fetch('api/comments.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({action: 'delete', comment_id: commentId})
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data.success) {
            location.reload();
          } else {
            alert(data.message || 'Có lỗi xảy ra');
          }
        })
        .catch(function() {
          alert('Lỗi kết nối');
        });
      }
      </script>
    </div>
  </div>
  <!-- Premium User Requests Section -->
  <div id="block-user-requests" class="row mt-4" style="display:none;">
    <div class="col-12">
      <div id="panel-user-requests" class="section-card-premium user-requests-card">
        <div class="section-header">
          <div class="section-header-title">
            <div class="section-icon section-icon-purple">
              <i class="bi bi-envelope-paper-fill"></i>
            </div>
            <span>Yêu cầu người dùng</span>
          </div>
          <span class="badge badge-premium badge-purple-soft"><?php echo count($accountRequests ?? []); ?> yêu cầu</span>
        </div>
        <div class="section-body">
          <!-- Search Filter -->
          <form method="get" class="request-search-form">
            <div class="search-input-wrapper">
              <i class="bi bi-search"></i>
              <input type="text" name="filter_name" class="form-control" placeholder="Tìm theo tên người dùng..." value="<?php echo htmlspecialchars($_GET['filter_name'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn-search">
              <i class="bi bi-search"></i>
              <span>Tìm</span>
            </button>
            <a href="admin_dashboard.php" class="btn-reset">
              <i class="bi bi-arrow-counterclockwise"></i>
              <span>Đặt lại</span>
            </a>
          </form>

          <?php if (empty($accountRequests)): ?>
            <div class="empty-state-premium">
              <div class="empty-icon">
                <i class="bi bi-inbox"></i>
              </div>
              <h5>Chưa có yêu cầu mới</h5>
              <p>Các yêu cầu từ người dùng sẽ xuất hiện tại đây</p>
            </div>
          <?php else: ?>
            <div class="requests-list">
              <?php foreach ($accountRequests as $req): 
                $statusClass = '';
                $statusText = 'Chờ xử lý';
                $statusIcon = 'bi-hourglass-split';
                if (($req['status'] ?? '') === 'resolved') {
                  $statusClass = 'status-resolved';
                  $statusText = 'Đã xử lý';
                  $statusIcon = 'bi-check-circle-fill';
                } elseif (($req['status'] ?? '') === 'rejected') {
                  $statusClass = 'status-rejected';
                  $statusText = 'Từ chối';
                  $statusIcon = 'bi-x-circle-fill';
                } elseif (($req['status'] ?? '') === 'in_review') {
                  $statusClass = 'status-review';
                  $statusText = 'Đang xem xét';
                  $statusIcon = 'bi-eye-fill';
                }
              ?>
                <form method="post" class="request-item-premium <?php echo $statusClass; ?>">
                  <input type="hidden" name="action" value="update_account_request">
                  <input type="hidden" name="req_id" value="<?php echo (int)($req['id'] ?? 0); ?>">
                  
                  <div class="request-main">
                    <div class="request-user">
                      <div class="user-avatar-request">
                        <i class="bi bi-person-fill"></i>
                      </div>
                      <div class="user-info-request">
                        <div class="user-name"><?php echo htmlspecialchars($req['user_name'] ?? ''); ?></div>
                        <div class="user-email"><?php echo htmlspecialchars($req['email'] ?? ''); ?></div>
                      </div>
                    </div>
                    
                    <div class="request-details">
                      <div class="request-type">
                        <i class="bi bi-tag-fill"></i>
                        <?php echo htmlspecialchars($req['request_type'] ?? $req['type'] ?? 'Khác'); ?>
                      </div>
                      <div class="request-time">
                        <i class="bi bi-clock"></i>
                        <?php echo date('d/m/Y H:i', strtotime($req['created_at'] ?? 'now')); ?>
                      </div>
                    </div>
                    
                    <div class="request-content">
                      <?php echo nl2br(htmlspecialchars($req['details'] ?? $req['notes'] ?? 'Không có chi tiết')); ?>
                    </div>
                    
                    <?php if (!empty($req['admin_note'])): ?>
                      <div class="admin-note-display">
                        <i class="bi bi-chat-left-text-fill"></i>
                        <span><?php echo nl2br(htmlspecialchars($req['admin_note'])); ?></span>
                      </div>
                    <?php endif; ?>
                  </div>
                  
                  <div class="request-sidebar">
                    <div class="current-status <?php echo $statusClass; ?>">
                      <i class="bi <?php echo $statusIcon; ?>"></i>
                      <?php echo $statusText; ?>
                    </div>
                    
                    <div class="request-form-group">
                      <label>Trạng thái</label>
                      <select name="status" class="form-select-premium">
                        <option value="pending" <?php echo ($req['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                        <option value="in_review" <?php echo ($req['status'] ?? '') === 'in_review' ? 'selected' : ''; ?>>Đang xem xét</option>
                        <option value="resolved" <?php echo ($req['status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>Đã xử lý</option>
                        <option value="rejected" <?php echo ($req['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Từ chối</option>
                      </select>
                    </div>
                    
                    <div class="request-form-group">
                      <label>Ghi chú</label>
                      <input type="text" name="admin_note" class="form-input-premium" placeholder="Ghi chú cho người dùng..." value="<?php echo htmlspecialchars($req['admin_note'] ?? ''); ?>">
                    </div>
                    
                    <div class="request-actions">
                      <button type="submit" class="btn-action btn-save">
                        <i class="bi bi-check2"></i>
                        Lưu
                      </button>
                      <a href="view_profile.php?id=<?php echo (int)($req['user_id'] ?? 0); ?>" class="btn-action btn-view">
                        <i class="bi bi-person"></i>
                        Hồ sơ
                      </a>
                    </div>
                    
                    <form method="post" onsubmit="return confirm('Xác nhận xóa yêu cầu này?');" class="delete-form">
                      <input type="hidden" name="action" value="delete_account_request">
                      <input type="hidden" name="req_id" value="<?php echo (int)($req['id'] ?? 0); ?>">
                      <button type="submit" class="btn-action btn-delete-request">
                        <i class="bi bi-trash3"></i>
                        Xóa
                      </button>
                    </form>
                    
                    <?php if (!empty($req['processed_at'])): ?>
                      <div class="processed-time">
                        <i class="bi bi-calendar-check"></i>
                        Cập nhật: <?php echo date('d/m/Y H:i', strtotime($req['processed_at'])); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

    </div>

    <!-- Embedded sections (single-page style like student/patient) -->
    <div class="dashboard-section" id="section-users" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-users" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-verifications" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-verifications" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-posts" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-posts" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-favorites" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-favorites" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-reports" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-reports" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-notifications" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-notifications" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="dashboard-section" id="section-profile" style="display:none; padding: 1.5rem;">
      <iframe id="iframe-profile" class="admin-embed-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
  </main>
</div>

<script>
  function toggleSidebar() {
    var sidebar = document.querySelector('.dashboard-sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.toggle('show');
    if (overlay) overlay.classList.toggle('show');
  }

  function closeSidebar() {
    var sidebar = document.querySelector('.dashboard-sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');
  }

  // Map section ID to iframe URL (embed=1 to hide navbar/footer)
  var iframeUrls = {
    'users': 'admin_users.php?embed=1',
    'verifications': 'admin_verifications.php?embed=1',
    'posts': 'admin_posts.php?embed=1',
    'favorites': 'admin_favorites.php?embed=1',
    'reports': 'admin_reports.php?embed=1',
    'notifications': 'admin_send_notification.php?embed=1',
    'profile': 'edit_profile.php?embed=1'
  };

  // Switch sections like student/patient dashboards
  function showSection(sectionId, title) {
    document.querySelectorAll('.dashboard-welcome-section, .dashboard-section').forEach(function(el) {
      el.style.display = 'none';
    });

    if (sectionId === 'welcome') {
      var welcome = document.querySelector('.dashboard-welcome-section');
      if (welcome) welcome.style.display = 'block';
    } else {
      var section = document.getElementById('section-' + sectionId);
      if (section) {
        section.style.display = 'block';
        var iframe = document.getElementById('iframe-' + sectionId);
        if (iframe && iframeUrls[sectionId] && (!iframe.src || !iframe.src.includes(iframeUrls[sectionId]))) {
          iframe.src = iframeUrls[sectionId];
        }
      }
    }

    if (title) {
      var pageTitle = document.getElementById('page-title');
      if (pageTitle) pageTitle.textContent = title;
      var breadcrumb = document.getElementById('breadcrumb-current');
      if (breadcrumb) breadcrumb.textContent = title;
    }

    document.querySelectorAll('.sidebar-menu-item').forEach(function(el) {
      el.classList.remove('active');
    });
    var activeItem = document.querySelector('.sidebar-menu-item[data-section="' + sectionId + '"]');
    if (activeItem) activeItem.classList.add('active');

    // Update URL query parameters to preserve section state
    if (window.history.replaceState) {
      var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
      if (sectionId !== 'welcome') {
        newUrl += '?section=' + sectionId;
      }
      window.history.replaceState({path: newUrl}, '', newUrl);
    }

    closeSidebar();
    return false;
  }

  // Show only the selected dashboard panel (no scrolling)
  function showPanel(panelKey, title) {
    // Ensure we're on the main dashboard view (hides iframe sections)
    showSection('welcome', 'Bảng điều khiển');

    // Update URL query parameters to preserve panel state on reload
    if (window.history.replaceState) {
      var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
      if (panelKey !== 'overview') {
        newUrl += '?panel=' + panelKey;
      }
      window.history.replaceState({path: newUrl}, '', newUrl);
    }

    var blockOverview = document.getElementById('block-overview');
    var blockPanels = document.getElementById('block-panels');
    var blockRequests = document.getElementById('block-user-requests');
    var colLeft = document.getElementById('admin-col-left');
    var colRight = document.getElementById('admin-col-right');

    var panelIds = [
      'panel-approvals',
      'panel-verify-requests',
      'panel-ratings',
      'panel-new-users',
      'panel-comments',
      'panel-user-requests'
    ];

    function setVisible(el, visible) {
      if (!el) return;
      el.style.display = visible ? '' : 'none';
    }

    function setPanelVisible(panelId, visible) {
      var el = document.getElementById(panelId);
      if (el) el.style.display = visible ? '' : 'none';
    }

    // Reset columns to original layout
    if (colLeft) {
      colLeft.classList.add('col-lg-6');
      colLeft.classList.remove('col-12');
    }
    if (colRight) {
      colRight.classList.add('col-lg-6');
      colRight.classList.remove('col-12');
    }

    // Default: show overview (hero, features, stats)
    if (panelKey === 'overview') {
      setVisible(blockOverview, true);
      setVisible(blockPanels, false);
      setVisible(blockRequests, false);
      setVisible(colLeft, false);
      setVisible(colRight, false);
      panelIds.forEach(function(id) { setPanelVisible(id, false); });
    } else {
      // Hide overview to focus on the chosen panel
      setVisible(blockOverview, false);

      // Hide all panels first
      panelIds.forEach(function(id) { setPanelVisible(id, false); });

      if (panelKey === 'user-requests') {
        setVisible(blockPanels, false);
        setVisible(blockRequests, true);
        setPanelVisible('panel-user-requests', true);
      } else {
        setVisible(blockRequests, false);
        setVisible(blockPanels, true);

        // Decide which column to show
        var leftKeys = ['approvals', 'verify-requests', 'ratings'];
        var rightKeys = ['new-users', 'comments'];
        var useLeft = leftKeys.indexOf(panelKey) !== -1;
        var useRight = rightKeys.indexOf(panelKey) !== -1;

        if (useLeft) {
          setVisible(colLeft, true);
          setVisible(colRight, false);
          if (colLeft) {
            colLeft.classList.remove('col-lg-6');
            colLeft.classList.add('col-12');
          }
        } else if (useRight) {
          setVisible(colLeft, false);
          setVisible(colRight, true);
          if (colRight) {
            colRight.classList.remove('col-lg-6');
            colRight.classList.add('col-12');
          }
        } else {
          // fallback: show both
          setVisible(colLeft, true);
          setVisible(colRight, true);
        }

        setPanelVisible('panel-' + panelKey, true);
      }
    }

    // Update title/breadcrumb
    if (title) {
      var pageTitle = document.getElementById('page-title');
      if (pageTitle) pageTitle.textContent = title;
      var breadcrumb = document.getElementById('breadcrumb-current');
      if (breadcrumb) breadcrumb.textContent = title;
    }

    // Active state
    document.querySelectorAll('.sidebar-menu-item').forEach(function(el) {
      el.classList.remove('active');
    });
    if (panelKey === 'overview') {
      var overviewItem = document.querySelector('.sidebar-menu-item[data-section="welcome"]');
      if (overviewItem) overviewItem.classList.add('active');
    } else {
      var activePanelItem = document.querySelector('.sidebar-menu-item[data-panel="' + panelKey + '"]');
      if (activePanelItem) activePanelItem.classList.add('active');
    }

    closeSidebar();
    return false;
  }

  // Open a section if provided via URL (?section=users)
  document.addEventListener('DOMContentLoaded', function() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      var s = (params.get('section') || '').trim();
      var p = (params.get('panel') || '').trim();

      if (s && (s === 'welcome' || iframeUrls[s])) {
        var textMap = {
          'welcome': 'Bảng điều khiển',
          'users': 'Người dùng',
          'verifications': 'Xác minh',
          'posts': 'Bài viết',
          'favorites': 'Yêu thích',
          'reports': 'Thống kê',
          'notifications': 'Thông báo',
          'profile': 'Hồ sơ'
        };
        showSection(s, textMap[s] || 'Bảng điều khiển');
      } else if (p) {
        var panelMap = {
          'approvals': 'Lịch sử xét duyệt',
          'verify-requests': 'Yêu cầu xác thực',
          'new-users': 'Người dùng mới',
          'comments': 'Kiểm duyệt bình luận',
          'ratings': 'Đánh giá người dùng',
          'user-requests': 'Yêu cầu người dùng'
        };
        if (panelMap[p]) {
          showPanel(p, panelMap[p]);
        } else {
          showPanel('overview', 'Bảng điều khiển');
        }
      } else {
        // Default view: overview (hide summary blocks from the main page)
        showPanel('overview', 'Bảng điều khiển');
      }
    } catch (e) {}
  });
</script>


<?php
include_once 'chatbot_widget.php';

// Mở lại div để footer.php đóng (footer.php có </div> <!-- /.container --> ở dòng đầu)
echo '<div>';
require_once 'footer.php';
?>