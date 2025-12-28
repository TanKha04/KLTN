<?php
// Header.php - không include config nữa vì index.php đã include rồi
// Chỉ set isEmbed nếu chưa được set từ trước
if (!isset($isEmbed)) {
    $isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kết nối Y tế</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <?php if ($isEmbed): ?>
    <style>
        html, body { 
            padding: 0 !important; 
            margin: 0 !important; 
            background: #f8fafc !important; 
            min-height: auto !important;
        }
        .premium-navbar, nav.navbar, .navbar { display: none !important; }
        .container { 
            max-width: 100% !important; 
            padding: 0 !important; 
            margin: 0 !important; 
        }
        .container.py-4 { padding: 0 !important; }
        footer, .footer, .bg-light.text-muted { display: none !important; }
        .incoming-call-overlay { display: none !important; }
        
        /* Ẩn topbar/breadcrumb khi embed */
        .dashboard-topbar,
        .page-header,
        .page-topbar,
        .topbar,
        .breadcrumb-wrapper,
        nav[aria-label="breadcrumb"],
        .d-flex.justify-content-between.align-items-center.mb-4,
        .d-flex.justify-content-between.align-items-center.mb-3 {
            display: none !important;
        }
        
        /* Fix cho các form pages */
        .account-request-page,
        .create-app-page,
        .permission-required-page,
        .edit-profile-page {
            min-height: auto !important;
            padding: 0.5rem !important;
            background: #f8fafc !important;
        }
        
        /* Fix card styles */
        .account-request-card,
        .create-app-card,
        .permission-card,
        .profile-card {
            max-width: 100% !important;
            margin: 0 !important;
            border-radius: 12px !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05) !important;
        }
        
        /* Fix header trong cards */
        .account-request-header,
        .create-app-header,
        .permission-header {
            padding: 1rem 1.25rem !important;
        }
        
        /* Fix body trong cards */
        .account-request-body,
        .create-app-body,
        .permission-body {
            padding: 1rem 1.25rem !important;
        }
    </style>
    <?php endif; ?>
</head>
<body>
<?php if (!$isEmbed): ?>
<nav class="navbar navbar-expand-lg navbar-dark premium-navbar">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <span class="brand-icon">
        <img src="ảnh/logo web.jpg" alt="Logo Kết nối Y tế" class="navbar-logo-img">
      </span>
      <span class="brand-text">Kết nối Y tế</span>
    </a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if (is_logged_in()): ?>
          <?php
            $dashboardLink = is_admin_user()
              ? 'admin.php'
              : (($_SESSION['role'] ?? 'patient') === 'patient' ? 'dashboard_patient.php' : 'dashboard_student.php');
          ?>
          <li class="nav-item">
            <a class="nav-link nav-link-premium" href="<?php echo $dashboardLink; ?>">
              <i class="bi bi-grid-1x2-fill"></i>
              <span>Bảng điều khiển</span>
            </a>
          </li>
          <?php if (is_admin_user()): ?>
            <li class="nav-item">
              <a class="nav-link nav-link-premium nav-link-highlight" href="admin_posts.php?create=recruitment#create-post">
                <i class="bi bi-megaphone-fill"></i>
                <span>Đăng tin Tuyển</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-link-premium nav-link-highlight" href="admin_posts.php?create=application#create-post">
                <i class="bi bi-person-badge-fill"></i>
                <span>Đăng tin Ứng tuyển</span>
              </a>
            </li>
          <?php else: ?>
            <li class="nav-item">
              <a class="nav-link nav-link-premium" href="index.php?type=recruitment#posts">
                <i class="bi bi-megaphone-fill"></i>
                <span>Tin tuyển</span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-link-premium" href="index.php?type=application#posts">
                <i class="bi bi-person-badge-fill"></i>
                <span>Tin ứng tuyển</span>
              </a>
            </li>
          <?php endif; ?>
          <li class="nav-item">
            <a class="nav-link nav-link-premium" href="index.php#posts">
              <i class="bi bi-search"></i>
              <span>Tìm kiếm</span>
            </a>
          </li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
        <?php if (is_logged_in()): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle user-dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <div class="user-avatar-wrapper">
                <?php
                  $avatar = $_SESSION['user_id'] ?? null;
                  $img = '';
                  if ($avatar) {
                      try {
                          $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
                          $stmt->execute([$avatar]);
                          $img = $stmt->fetchColumn();
                      } catch (Exception $e) {}
                  }
                  if (!empty($img) && upload_exists($img)) {
                      $url = public_url_for($img);
                      echo '<img src="'.htmlspecialchars($url).'" alt="avatar" class="user-avatar">';
                  } else {
                      echo '<span class="user-avatar-placeholder">'.strtoupper(substr($_SESSION['name'],0,1)).'</span>';
                  }
                ?>
                <span class="user-status-dot"></span>
              </div>
              <span class="user-name"><?php echo htmlspecialchars($_SESSION['name']); ?></span>
              <i class="bi bi-chevron-down dropdown-arrow"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end premium-dropdown" aria-labelledby="userMenu">
              <li class="dropdown-header">
                <div class="d-flex align-items-center gap-2">
                  <div class="dropdown-user-avatar">
                    <?php
                      if (!empty($img) && upload_exists($img)) {
                          echo '<img src="'.htmlspecialchars($url).'" alt="avatar">';
                      } else {
                          echo '<span>'.strtoupper(substr($_SESSION['name'],0,1)).'</span>';
                      }
                    ?>
                  </div>
                  <div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                    <small class="text-muted"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></small>
                  </div>
                </div>
              </li>
              <li><hr class="dropdown-divider"></li>
              <?php if (!is_admin_user()): ?>
                <?php if (($_SESSION['role'] ?? 'patient') === 'patient'): ?>
                  <li><a class="dropdown-item" href="create_recruitment.php"><i class="bi bi-plus-circle me-2"></i>Tạo tin tuyển</a></li>
                <?php else: ?>
                  <li><a class="dropdown-item" href="create_application.php"><i class="bi bi-plus-circle me-2"></i>Tạo tin ứng tuyển</a></li>
                <?php endif; ?>
              <?php endif; ?>
              <li><a class="dropdown-item" href="conversations.php"><i class="bi bi-chat-dots me-2"></i>Chat</a></li>
              <li><a class="dropdown-item" href="edit_profile.php"><i class="bi bi-person-circle me-2"></i>Hồ sơ</a></li>
              <?php if (!is_admin_user()): ?>
                <li><a class="dropdown-item" href="account_request.php"><i class="bi bi-gear me-2"></i>Hỗ trợ tài khoản</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item dropdown-item-logout" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Đăng xuất</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link nav-link-premium" href="login.php">
              <i class="bi bi-box-arrow-in-right"></i>
              <span>Đăng nhập</span>
            </a>
          </li>
          <li class="nav-item ms-2">
            <a class="btn btn-register-premium" href="register.php">
              <i class="bi bi-person-plus-fill"></i>
              <span>Đăng ký</span>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php endif; // end !$isEmbed navbar ?>
<?php 
$currentPage = $_SERVER['PHP_SELF'];
$isDashboard = strpos($currentPage, 'dashboard_') !== false;
$isFullWidth = $isDashboard || strpos($currentPage, 'view_messages') !== false;
$containerClass = $isFullWidth ? 'dashboard-container' : 'container py-4';
?>
<div class="<?php echo $containerClass; ?>">

<?php if (is_logged_in() && !$isEmbed): ?>
<!-- Incoming Call Overlay -->
<div class="incoming-call-overlay" id="incomingCallOverlay" style="display: none;">
    <div class="avatar" id="callerAvatar"></div>
    <div class="caller-name" id="callerName"></div>
    <div class="call-text">Đang gọi video cho bạn...</div>
    <div class="actions">
        <button class="btn-answer" onclick="answerCall()" title="Trả lời">
            <i class="bi bi-telephone-fill"></i>
        </button>
        <button class="btn-reject" onclick="rejectCall()" title="Từ chối">
            <i class="bi bi-telephone-x-fill"></i>
        </button>
    </div>
</div>

<style>
.incoming-call-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.95);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    color: #fff;
}
.incoming-call-overlay .avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 700;
    margin-bottom: 1rem;
    animation: incoming-pulse 1.5s infinite;
    overflow: hidden;
}
.incoming-call-overlay .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
@keyframes incoming-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    50% { box-shadow: 0 0 0 30px rgba(16, 185, 129, 0); }
}
.incoming-call-overlay .caller-name {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.incoming-call-overlay .call-text {
    color: #94a3b8;
    margin-bottom: 2rem;
    font-size: 1.1rem;
}
.incoming-call-overlay .actions {
    display: flex;
    gap: 3rem;
}
.incoming-call-overlay .btn-answer {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #10b981;
    color: #fff;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    transition: transform 0.2s;
}
.incoming-call-overlay .btn-answer:hover {
    transform: scale(1.1);
}
.incoming-call-overlay .btn-reject {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #ef4444;
    color: #fff;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    transition: transform 0.2s;
}
.incoming-call-overlay .btn-reject:hover {
    transform: scale(1.1);
}
</style>

<audio id="incomingRingtone" loop>
    <source src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" type="audio/mpeg">
</audio>

<script>
let incomingCallId = null;
let incomingCallerId = null;
let checkIncomingInterval = null;

// Kiểm tra cuộc gọi đến mỗi 3 giây
function startCheckingIncomingCalls() {
    checkIncomingInterval = setInterval(async () => {
        try {
            const response = await fetch('call_signaling.php?action=check_incoming');
            const data = await response.json();
            
            if (data.incoming && !incomingCallId) {
                incomingCallId = data.call_id;
                incomingCallerId = data.caller_id;
                showIncomingCall(data);
            }
        } catch (e) {
            console.error('Error checking incoming calls:', e);
        }
    }, 3000);
}

function showIncomingCall(data) {
    const overlay = document.getElementById('incomingCallOverlay');
    const avatarEl = document.getElementById('callerAvatar');
    const nameEl = document.getElementById('callerName');
    
    if (data.caller_avatar) {
        avatarEl.innerHTML = '<img src="uploads/' + data.caller_avatar + '" alt="">';
    } else {
        avatarEl.textContent = data.caller_name.charAt(0).toUpperCase();
    }
    nameEl.textContent = data.caller_name;
    
    overlay.style.display = 'flex';
    
    // Phát nhạc chuông
    try {
        document.getElementById('incomingRingtone').play();
    } catch (e) {}
}

async function answerCall() {
    // Dừng nhạc chuông
    document.getElementById('incomingRingtone').pause();
    document.getElementById('incomingCallOverlay').style.display = 'none';
    
    // Gửi signal trả lời
    await fetch('call_signaling.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=answer_call&call_id=' + incomingCallId
    });
    
    // Chuyển đến trang video call
    window.location.href = 'video_call.php?with=' + incomingCallerId + '&answer=' + incomingCallId;
}

async function rejectCall() {
    // Dừng nhạc chuông
    document.getElementById('incomingRingtone').pause();
    document.getElementById('incomingCallOverlay').style.display = 'none';
    
    // Gửi signal từ chối
    await fetch('call_signaling.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reject_call&call_id=' + incomingCallId
    });
    
    incomingCallId = null;
    incomingCallerId = null;
}

// Bắt đầu kiểm tra khi trang load
document.addEventListener('DOMContentLoaded', function() {
    // Chỉ kiểm tra nếu không phải đang ở trang video_call
    if (!window.location.pathname.includes('video_call.php')) {
        startCheckingIncomingCalls();
    }
});
</script>
<?php endif; ?>
