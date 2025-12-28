<?php
require_once 'config.php';
require_once 'header.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';
$success = '';
$showForm = true;
$tokenData = null;

if ($token === '') {
	$error = 'Liên kết đặt lại mật khẩu không hợp lệ.';
	$showForm = false;
} else {
	$stmt = $pdo->prepare('SELECT pr.*, u.name, u.email, u.id AS user_id FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ? LIMIT 1');
	$stmt->execute([$token]);
	$tokenData = $stmt->fetch();

	if (!$tokenData) {
		$error = 'Mã đặt lại không tồn tại hoặc đã được sử dụng.';
		$showForm = false;
	} elseif (!empty($tokenData['used_at'])) {
		$error = 'Mã đặt lại đã được sử dụng. Vui lòng gửi yêu cầu mới.';
		$showForm = false;
	} elseif (strtotime($tokenData['expires_at']) < time()) {
		$error = 'Mã đặt lại đã hết hạn. Vui lòng gửi yêu cầu mới.';
		$showForm = false;
	}
}

if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$password = $_POST['password'] ?? '';
	$confirm = $_POST['password_confirm'] ?? '';

	if (strlen($password) < 6) {
		$error = 'Mật khẩu phải có ít nhất 6 ký tự.';
	} elseif ($password !== $confirm) {
		$error = 'Mật khẩu nhập lại không khớp.';
	} else {
		try {
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$pdo->beginTransaction();
			$upUser = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
			$upUser->execute([$hash, $tokenData['user_id']]);
			$upToken = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
			$upToken->execute([$tokenData['id']]);
			$pdo->commit();
			$success = 'Đặt lại mật khẩu thành công. Bạn có thể đăng nhập với mật khẩu mới.';
			$showForm = false;
		} catch (Throwable $e) {
			$pdo->rollBack();
			error_log('reset_password error: ' . $e->getMessage());
			$error = 'Không thể đặt lại mật khẩu. Vui lòng thử lại sau.';
		}
	}
}
?>

<div class="auth-wrapper py-5">
	<div class="row justify-content-center w-100">
		<div class="col-md-6 col-lg-5">
			<div class="card shadow-lg border-0">
				<div class="card-body p-4 p-md-5">
					<h3 class="text-center mb-3">Đặt lại mật khẩu</h3>
					<?php if ($error): ?>
						<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
					<?php endif; ?>
					<?php if ($success): ?>
						<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
						<div class="text-center mt-3">
							<a class="btn btn-primary" href="login.php">Đăng nhập</a>
						</div>
					<?php endif; ?>
					<?php if ($showForm): ?>
						<form method="post" class="mt-3">
							<input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
							<div class="mb-3">
								<label class="form-label" for="password">Mật khẩu mới *</label>
								<input type="password" class="form-control" name="password" id="password" required minlength="6">
							</div>
							<div class="mb-3">
								<label class="form-label" for="password_confirm">Xác nhận mật khẩu *</label>
								<input type="password" class="form-control" name="password_confirm" id="password_confirm" required minlength="6">
							</div>
							<div class="d-grid">
								<button type="submit" class="btn btn-primary btn-lg">Cập nhật mật khẩu</button>
							</div>
						</form>
						<p class="text-center mt-3"><a href="forgot_password.php">Gửi lại yêu cầu</a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require_once 'footer.php'; ?>
