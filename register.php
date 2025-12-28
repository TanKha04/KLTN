<?php
require_once 'config.php';
require_once 'email_helper.php';
require_once 'header.php';

// Đóng container từ header để ảnh nền full width
echo '</div>';

$error = '';
$successMessage = '';
if (!empty($_SESSION['verification_success_message'])) {
    $successMessage = $_SESSION['verification_success_message'];
    unset($_SESSION['verification_success_message']);
}
if (isset($_GET['verified']) && $_GET['verified'] === '1' && !$successMessage) {
    $successMessage = 'Email của bạn đã được xác minh. Bạn có thể đăng nhập để sử dụng hệ thống.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userType = $_POST['user_type'] ?? '';
    
    if ($userType === 'patient') {
        // Đăng ký bệnh nhân
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (!$email || !$username || !$password || !$confirm) {
            $error = 'Vui lòng điền đầy đủ thông tin bắt buộc.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không hợp lệ.';
        } elseif ($password !== $confirm) {
            $error = 'Mật khẩu nhập lại không khớp.';
        } elseif (strlen($password) < 6) {
            $error = 'Mật khẩu cần ít nhất 6 ký tự.';
        } else {
            // Check existing email or username
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
            $stmt->execute([$email, $username]);
            if ($stmt->fetch()) {
                $error = 'Email hoặc tên tài khoản đã được sử dụng.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, username, password, role, can_post, email_verified) VALUES (?, ?, ?, ?, ?, 1, 0)');
                $stmt->execute([$username, $email, $username, $hash, 'patient']);
                $userId = $pdo->lastInsertId();
                try {
                    $emailSent = issue_email_verification($pdo, (int)$userId, $email, $username);
                    if ($emailSent) {
                        $successMessage = 'Đăng ký thành công! Vui lòng kiểm tra hộp thư để xác minh tài khoản.';
                    } else {
                        $successMessage = 'Đăng ký thành công, tuy nhiên hệ thống chưa thể gửi email xác minh. Vui lòng thử gửi lại từ trang Đăng nhập.';
                    }
                    $_POST = [];
                } catch (Throwable $e) {
                    error_log('register verification email error: ' . $e->getMessage());
                    $successMessage = 'Đăng ký thành công, nhưng chưa gửi được email xác minh. Vui lòng thử lại sau hoặc liên hệ hỗ trợ.';
                    $_POST = [];
                }
            }
        }
    } elseif ($userType === 'student') {
        // Đăng ký sinh viên y khoa
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $school = trim($_POST['school'] ?? '');
        $classCode = trim($_POST['class_code'] ?? '');
        $studentCode = trim($_POST['student_code'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        // Validate mã lớp: phải kết thúc bằng YDK, D, DD hoặc XYH
        $validClassSuffixes = ['YDK', 'D', 'DD', 'XYH'];
        $classCodeValid = false;
        foreach ($validClassSuffixes as $suffix) {
            if (substr(strtoupper($classCode), -strlen($suffix)) === $suffix) {
                $classCodeValid = true;
                break;
            }
        }

        if (!$username || !$email || !$school || !$classCode || !$studentCode || !$password || !$confirm) {
            $error = 'Vui lòng điền đầy đủ thông tin bắt buộc.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không hợp lệ.';
        } elseif (!preg_match('/\.edu\.vn$/i', $email)) {
            $error = 'Email sinh viên phải có đuôi .edu.vn (ví dụ: abc@student.university.edu.vn)';
        } elseif (!$classCodeValid) {
        } elseif ($password !== $confirm) {
            $error = 'Mật khẩu nhập lại không khớp.';
        } elseif (strlen($password) < 6) {
            $error = 'Mật khẩu cần ít nhất 6 ký tự.';
        } else {
            // Check existing email, username or student_id
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? OR student_id = ?');
            $stmt->execute([$email, $username, $studentCode]);
            if ($stmt->fetch()) {
                $error = 'Email, tên tài khoản hoặc mã số sinh viên đã được sử dụng.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, username, password, role, school, class_code, student_id, can_post, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)');
                $stmt->execute([$username, $email, $username, $hash, 'student', $school, $classCode, $studentCode]);
                $userId = $pdo->lastInsertId();
                try {
                    $emailSent = issue_email_verification($pdo, (int)$userId, $email, $username);
                    if ($emailSent) {
                        $successMessage = 'Đăng ký thành công! Vui lòng kiểm tra hộp thư để xác minh tài khoản.';
                    } else {
                        $successMessage = 'Đăng ký thành công, tuy nhiên hệ thống chưa thể gửi email xác minh. Vui lòng thử gửi lại từ trang Đăng nhập.';
                    }
                    $_POST = [];
                } catch (Throwable $e) {
                    error_log('register verification email error: ' . $e->getMessage());
                    $successMessage = 'Đăng ký thành công, nhưng chưa gửi được email xác minh. Vui lòng thử lại sau hoặc liên hệ hỗ trợ.';
                    $_POST = [];
                }
            }
        }
    } else {
        $error = 'Vui lòng chọn loại tài khoản.';
    }
}
?>

<style>
    body {
        overflow-x: hidden;
    }
    .register-bg-wrapper {
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
    .register-bg-slideshow {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: -1;
    }
    .register-bg-slide {
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
    .register-bg-slide.active {
        opacity: 1;
    }
    .register-bg-slide::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to right, rgba(30, 80, 140, 0.8) 0%, rgba(60, 150, 200, 0.6) 40%, rgba(100, 180, 220, 0.4) 100%);
    }
    .register-bg-slide:nth-child(1) {
        background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y.jpg');
    }
    .register-bg-slide:nth-child(2) {
        background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh Tại Nhà.jpg');
    }
    .register-bg-slide:nth-child(3) {
        background-image: url('Ảnh Giao diện/Sinh Viên Y Khám Bệnh.webp');
    }
    .register-bg-slide:nth-child(4) {
        background-image: url('Ảnh Giao diện/Ảnh Sinh Viên Y khám bệnh.png');
    }
    .register-card {
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
    .register-card h3 {
        color: #ffffff;
        font-weight: 700;
        font-size: 2.5rem;
        margin-bottom: 10px;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    }
    .register-subtitle {
        color: #ffffff;
        font-size: 1rem;
        letter-spacing: 3px;
        text-transform: uppercase;
        margin-bottom: 15px;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
    }
    .register-card .btn-patient {
        background: #3b82f6;
        border: 2px solid #3b82f6;
        color: #fff;
        padding: 12px 40px;
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
        margin-right: 15px;
    }
    .register-card .btn-patient:hover {
        background: #2563eb;
        border-color: #2563eb;
        transform: translateY(-2px);
    }
    .register-card .btn-student {
        background: #10b981;
        border: 2px solid #10b981;
        color: #fff;
        padding: 12px 40px;
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
    }
    .register-card .btn-student:hover {
        background: #059669;
        border-color: #059669;
        transform: translateY(-2px);
    }
    .register-welcome {
        text-align: left;
        margin-bottom: 30px;
    }
    .register-buttons {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    .register-extra-links {
        margin-top: 30px;
    }
    .register-extra-links a {
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        margin-right: 20px;
        font-size: 0.9rem;
        transition: color 0.3s;
    }
    .register-extra-links a:hover {
        color: #ffffff;
        text-decoration: underline;
    }
    @media (max-width: 768px) {
        .register-bg-wrapper {
            padding: 20px;
            justify-content: center;
            text-align: center;
        }
        .register-card {
            margin-left: 0;
        }
        .register-welcome {
            text-align: center;
        }
        .register-buttons {
            justify-content: center;
        }
    }
</style>

<div class="register-bg-wrapper">
    <!-- Slideshow Background -->
    <div class="register-bg-slideshow">
        <div class="register-bg-slide active"></div>
        <div class="register-bg-slide"></div>
        <div class="register-bg-slide"></div>
        <div class="register-bg-slide"></div>
    </div>
    
    <div class="register-card">
        <div class="card-body">
            <div class="register-welcome">
                <p class="register-subtitle">Kết Nối Y Tế</p>
                <h3>Tham Gia Cùng<br>Chúng Tôi</h3>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger" style="background: rgba(220,53,69,0.9); border: none; color: white; max-width: 400px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php elseif ($successMessage): ?>
                <div class="alert alert-success" style="background: rgba(25,135,84,0.9); border: none; color: white; max-width: 400px;">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>
            <div class="register-buttons">
                <a href="#" class="btn btn-patient" data-bs-toggle="modal" data-bs-target="#patientModal">
                    <i class="fas fa-user-injured me-2"></i>BỆNH NHÂN
                </a>
                <a href="#" class="btn btn-student" data-bs-toggle="modal" data-bs-target="#studentModal">
                    <i class="fas fa-user-graduate me-2"></i>SINH VIÊN Y KHOA
                </a>
            </div>
            <div class="register-extra-links">
                <a href="login.php"><i class="bi bi-box-arrow-in-right"></i> Đã có tài khoản? Đăng nhập</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal Đăng ký Bệnh nhân -->
<div class="modal fade" id="patientModal" tabindex="-1" aria-labelledby="patientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 15px; border: none;">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border-radius: 15px 15px 0 0;">
                <h5 class="modal-title" id="patientModalLabel"><i class="fas fa-user-injured me-2"></i>Đăng Ký Bệnh Nhân</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <input type="hidden" name="user_type" value="patient">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email: *</label>
                        <input type="email" name="email" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tên Tài Khoản: *</label>
                        <input type="text" name="username" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập tên tài khoản" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mật khẩu: *</label>
                        <input type="password" name="password" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Tối thiểu 6 ký tự" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Xác nhận mật khẩu: *</label>
                        <input type="password" name="password_confirm" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập lại mật khẩu" required>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-lg" style="background: #3b82f6; color: white; border-radius: 10px; font-weight: 600;" type="submit">Đăng Ký</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Đăng ký Sinh viên Y khoa -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 15px; border: none;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 15px 15px 0 0;">
                <h5 class="modal-title" id="studentModalLabel"><i class="fas fa-user-graduate me-2"></i>Đăng Ký Sinh Viên Y Khoa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <input type="hidden" name="user_type" value="student">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Email sinh viên: *</label>
                            <input type="email" name="email" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Email đuôi .edu.vn" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Tên Tài Khoản: *</label>
                            <input type="text" name="username" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập tên tài khoản" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Trường: *</label>
                            <input type="text" name="school" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập tên trường" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Mã Lớp: *</label>
                            <input type="text" name="class_code" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="VD: YDK, D, DD, XYH" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mã Số Sinh Viên: *</label>
                        <input type="text" name="student_code" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập mã số sinh viên" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Mật khẩu: *</label>
                            <input type="password" name="password" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Tối thiểu 6 ký tự" required>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-semibold">Xác nhận mật khẩu: *</label>
                            <input type="password" name="password_confirm" class="form-control" style="border-radius: 10px; padding: 12px;" placeholder="Nhập lại mật khẩu" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-lg" style="background: #10b981; color: white; border-radius: 10px; font-weight: 600;" type="submit">Đăng Ký</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Slideshow Background
document.addEventListener('DOMContentLoaded', function() {
    const slides = document.querySelectorAll('.register-bg-slide');
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
