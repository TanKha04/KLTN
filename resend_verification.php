<?php
require_once 'config.php';
require_once 'email_helper.php';
require_once 'header.php';

$error = '';
$success = '';
$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = 'Vui lòng nhập email đã đăng ký.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email_verified FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'Không tìm thấy tài khoản cho email này.';
        } elseif (!empty($user['email_verified'])) {
            $success = 'Email này đã được xác minh. Bạn có thể đăng nhập ngay.';
        } else {
            try {
                $sent = issue_email_verification($pdo, (int)$user['id'], $email, $user['name']);
                if ($sent) {
                    $success = 'Đã gửi lại email xác minh. Vui lòng kiểm tra hộp thư của bạn.';
                } else {
                    $error = 'Không thể gửi email xác minh. Vui lòng thử lại sau.';
                }
            } catch (Throwable $e) {
                error_log('resend_verification error: ' . $e->getMessage());
                $error = 'Có lỗi xảy ra khi gửi email. Vui lòng thử lại.';
            }
        }
    }
}
?>
<div class="auth-wrapper py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4 p-md-5">
                    <h3 class="text-center mb-4">Gửi lại xác minh email</h3>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php elseif ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-4">
                            <label class="form-label">Email đã đăng ký</label>
                            <input type="email" name="email" class="form-control" placeholder="Nhập email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Gửi lại email</button>
                        </div>
                    </form>
                    <p class="text-center mt-3"><a href="login.php">← Quay lại đăng nhập</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once 'footer.php'; ?>
