<?php
require_once 'config.php';

// Tự động tạo bảng email_verifications nếu chưa có
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'email_verifications'")->rowCount() > 0;
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE `email_verifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
} catch (PDOException $e) {
    error_log('Create email_verifications table failed: ' . $e->getMessage());
}

require_once 'header.php';

$token = trim($_GET['token'] ?? '');
$status = '';
$message = '';
$ctaLink = 'login.php';
$ctaLabel = 'Đăng nhập';

if ($token === '') {
    $status = 'error';
    $message = 'Thiếu mã xác minh. Vui lòng kiểm tra lại email của bạn.';
} else {
    try {
        $stmt = $pdo->prepare('SELECT ev.*, u.email FROM email_verifications ev JOIN users u ON u.id = ev.user_id WHERE ev.token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('verify_email query error: ' . $e->getMessage());
        $row = null;
    }
    
    if (!$row) {
        $status = 'error';
        $message = 'Mã xác minh không hợp lệ hoặc đã được sử dụng.';
    } elseif (!empty($row['used_at'])) {
        $status = 'error';
        $message = 'Mã xác minh này đã được sử dụng trước đó. Nếu bạn vẫn không đăng nhập được, hãy yêu cầu gửi lại email.';
    } elseif (strtotime($row['expires_at']) < time()) {
        $status = 'error';
        $message = 'Mã xác minh đã hết hạn. Vui lòng yêu cầu gửi lại email xác minh.';
    } else {
        try {
            // Kiểm tra cột email_verified có tồn tại không
            $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified'");
            $checkCol->execute();
            if ((int)$checkCol->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
            }
            
            $pdo->beginTransaction();
            $upUser = $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
            $upUser->execute([$row['user_id']]);
            $upToken = $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?');
            $upToken->execute([$row['id']]);
            $pdo->commit();

            $_SESSION['verification_success_message'] = 'Email ' . ($row['email'] ?? '') . ' đã được xác minh. Bạn có thể đăng nhập ngay bây giờ.';
            header('Location: register.php?verified=1');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('verify_email transaction error: ' . $e->getMessage());
            $status = 'error';
            $message = 'Có lỗi xảy ra khi xác minh. Vui lòng thử lại sau.';
        }
    }
}
?>
<div class="container py-5" style="max-width:600px;">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h3 class="mb-3 text-center"><?php echo $status === 'success' ? 'Xác minh thành công' : 'Không thể xác minh'; ?></h3>
            <div class="mb-4">
                <p class="mb-0"><?php echo htmlspecialchars($message); ?></p>
                <?php if ($status !== 'success'): ?>
                    <p class="mt-3 mb-0">Cần hỗ trợ? <a href="resend_verification.php">Gửi lại email xác minh</a>.</p>
                <?php endif; ?>
            </div>
            <div class="text-center">
                <a class="btn btn-primary" href="register.php">Quay lại đăng ký</a>
            </div>
        </div>
    </div>
</div>
<?php require_once 'footer.php'; ?>
