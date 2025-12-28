<?php
require_once 'config.php';
require_once 'email_helper.php';
require_once 'header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = trim($_POST['email'] ?? '');

	if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$error = 'Vui lòng nhập email hợp lệ.';
	} else {
		$stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
		$stmt->execute([$email]);
		$user = $stmt->fetch();
		$genericSuccess = 'Nếu email tồn tại trong hệ thống, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu.';

		if (!$user) {
			$success = $genericSuccess;
		} else {
			try {
				$sent = issue_password_reset($pdo, (int)$user['id'], $email, $user['name']);
				$success = $sent
					? 'Đã gửi email hướng dẫn đặt lại mật khẩu. Vui lòng kiểm tra hộp thư hoặc thư mục Spam.'
					: 'Không thể gửi email đặt lại mật khẩu. Vui lòng thử lại sau.';
			} catch (Throwable $e) {
				error_log('forgot_password error: ' . $e->getMessage());
				$success = $genericSuccess;
			}
		}
	}
}
?>

<div class="auth-wrapper py-5">
	<div class="row justify-content-center w-100">
		<div class="col-md-6 col-lg-5">
			<div class="card shadow-lg border-0">
				<div class="card-body p-4 p-md-5">
					<h3 class="text-center mb-3">Quên mật khẩu</h3>
					<p class="text-muted text-center">Nhập email để nhận liên kết đặt lại mật khẩu.</p>
					<?php if ($error): ?>
						<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
					<?php elseif ($success): ?>
						<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
					<?php endif; ?>
					<form method="post" class="mt-4">
						<div class="mb-3">
							<label class="form-label" for="email">Email đăng ký *</label>
							<input type="email" class="form-control" name="email" id="email" placeholder="example@domain.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
						</div>
						<div class="d-grid">
							<button type="submit" class="btn btn-primary btn-lg">Gửi hướng dẫn</button>
						</div>
					</form>
					<p class="text-center mt-3"><a href="login.php">Quay lại đăng nhập</a></p>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require_once 'footer.php'; ?>
