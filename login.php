<?php
// Buffer output so header() redirects work even if templates echo early
if (!headers_sent()) { ob_start(); }
require_once 'config.simple.php';
require_once 'header.php';

$error = '';
$errorActionLink = '';
$errorActionLabel = '';
// Đóng container từ header để ảnh nền full width
echo '</div>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
                $error = 'Vui lòng điền đầy đủ thông tin.';
        } else {
        // Removed temporary dev shortcuts to avoid login loop and improve security

                $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
                $stmt->execute([$email, $email]);
                $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            if (empty($user['email_verified'])) {
                $error = 'Tài khoản chưa được xác minh email.';
                $errorActionLink = 'resend_verification.php?email=' . urlencode($email);
                $errorActionLabel = 'Gửi lại email xác minh';
            } else {
                if ($user['role'] === 'patient' && empty($user['can_post'])) {
                    $stmt = $pdo->prepare('UPDATE users SET can_post = 1 WHERE id = ?');
                    $stmt->execute([$user['id']]);
                    $user['can_post'] = 1;
                }
                // login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                // Admin if DB flag set OR email domain contains 'admin' (case-insensitive)
                $adminByEmail = false;
                if (strpos($email, '@') !== false) {
                    $domainPart = substr($email, strpos($email, '@') + 1);
                    $adminByEmail = (bool)preg_match('/admin/i', $domainPart);
                }
                $_SESSION['is_admin'] = (!empty($user['is_admin']) || $adminByEmail) ? 1 : 0;
                $_SESSION['verified'] = !empty($user['verified']) ? 1 : 0;

                // update last_login timestamp
                $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                $stmt->execute([$user['id']]);

                if (!empty($_SESSION['is_admin'])) {
                    header('Location: admin.php');
                    exit;
                }

                if ($user['role'] === 'patient') {
                    header('Location: dashboard_patient.php');
                    exit;
                } else {
                    header('Location: dashboard_student.php');
                    exit;
                }
            }
        } else {
            $error = 'Thông tin đăng nhập không đúng.';
        }
        }
}
?>

<style>
    body {
        overflow-x: hidden;
    }
    .login-bg-wrapper {
        min-height: calc(100vh - 56px);
        width: 100vw;
        position: absolute;
        left: 0;
        top: 56px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        padding: 0 80px;
        overflow: hidden;
    }
    .login-bg-slideshow {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: -1;
    }
    .login-bg-slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center top;
        opacity: 0;
        transition: opacity 1.5s ease-in-out;
    }
    .login-bg-slide.active {
        opacity: 1;
    }
    .login-bg-slide::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to right, rgba(30, 80, 140, 0.8) 0%, rgba(60, 150, 200, 0.6) 40%, rgba(100, 180, 220, 0.4) 100%);
    }
    .login-bg-slide:nth-child(1) {
        background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh Tại Nhà.jpg');
    }
    .login-bg-slide:nth-child(2) {
        background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y.jpg');
    }
    .login-bg-slide:nth-child(3) {
        background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh.webp');
    }
    .login-bg-slide:nth-child(4) {
        background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y khám bệnh.png');
    }
    .login-card {
        background: transparent;
        max-width: 500px;
        width: 100%;
        animation: slideUp 0.5s ease-out;
        margin-left: 5%;
    }
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .login-card .card-body {
        padding: 0;
    }
    .login-card h3 {
        color: #ffffff;
        font-weight: 700;
        font-size: 2.5rem;
        margin-bottom: 10px;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    }
    .login-subtitle {
        color: #ffffff;
        font-size: 1rem;
        letter-spacing: 3px;
        text-transform: uppercase;
        margin-bottom: 15px;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
    }
    .login-card .btn-login {
        background: #f59e0b;
        border: 2px solid #f59e0b;
        color: #fff;
        padding: 12px 40px;
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
        margin-right: 15px;
    }
    .login-card .btn-login:hover {
        background: #d97706;
        border-color: #d97706;
        transform: translateY(-2px);
    }
    .login-card .btn-register {
        background: transparent;
        border: 2px solid #ffffff;
        color: #ffffff;
        padding: 12px 40px;
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
    }
    .login-card .btn-register:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-2px);
    }
    .login-welcome {
        text-align: left;
        margin-bottom: 30px;
    }
    .login-buttons {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    .login-extra-links {
        margin-top: 30px;
    }
    .login-extra-links a {
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        margin-right: 20px;
        font-size: 0.9rem;
        transition: color 0.3s;
    }
    .login-extra-links a:hover {
        color: #ffffff;
        text-decoration: underline;
    }
    @media (max-width: 768px) {
        .login-bg-wrapper {
            padding: 20px;
            justify-content: center;
            text-align: center;
        }
        .login-card {
            margin-left: 0;
        }
        .login-welcome {
            text-align: center;
        }
        .login-buttons {
            justify-content: center;
        }
    }
</style>

<div class="login-bg-wrapper">
    <!-- Slideshow Background -->
    <div class="login-bg-slideshow">
        <div class="login-bg-slide active"></div>
        <div class="login-bg-slide"></div>
        <div class="login-bg-slide"></div>
        <div class="login-bg-slide"></div>
    </div>
    
    <div class="login-card">
        <div class="card-body">
            <div class="login-welcome">
                <p class="login-subtitle">Kết Nối Y Tế</p>
                <h3>Chăm Sóc Sức Khỏe<br>Tận Tâm</h3>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger" style="background: rgba(220,53,69,0.9); border: none; color: white; max-width: 400px;">
                    <?php echo htmlspecialchars($error); ?>
                    <?php if ($errorActionLink): ?>
                        <div class="mt-2">
                            <a href="<?php echo htmlspecialchars($errorActionLink); ?>" class="alert-link" style="color: #ffc107;"><?php echo htmlspecialchars($errorActionLabel); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="login-buttons">
                <a href="#login-form" class="btn btn-login" data-bs-toggle="modal" data-bs-target="#loginModal">ĐĂNG NHẬP</a>
                <a href="register.php" class="btn btn-register">ĐĂNG KÝ</a>
            </div>
            <div class="login-extra-links">
                <a href="forgot_password.php"><i class="bi bi-key"></i> Quên mật khẩu?</a>
                <a href="facebook_login.php"><i class="bi bi-facebook"></i> Facebook</a>
            </div>
        </div>
    </div>
</div>

<!-- Login Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 15px; border: none;">
            <div class="modal-header" style="background: linear-gradient(135deg, #1e508c 0%, #3c96c8 100%); color: white; border-radius: 15px 15px 0 0;">
                <h5 class="modal-title" id="loginModalLabel"><i class="bi bi-heart-pulse me-2"></i>Đăng Nhập</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email hoặc Tên tài khoản:</label>
                        <input type="text" name="email" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập email hoặc tên tài khoản" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        <small class="text-muted">Có thể sử dụng email hoặc tên tài khoản để đăng nhập</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Mật khẩu:</label>
                        <input type="password" name="password" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập mật khẩu" required>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-lg" style="background: #f59e0b; color: white; border-radius: 10px; font-weight: 600;" type="submit">Đăng Nhập</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Slideshow Background
document.addEventListener('DOMContentLoaded', function() {
    const slides = document.querySelectorAll('.login-bg-slide');
    let currentSlide = 0;
    
    function nextSlide() {
        slides[currentSlide].classList.remove('active');
        currentSlide = (currentSlide + 1) % slides.length;
        slides[currentSlide].classList.add('active');
    }
    
    // Chuyển ảnh mỗi 5 giây
    setInterval(nextSlide, 5000);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
