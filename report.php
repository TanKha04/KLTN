<?php
require_once 'config.php';
require_login();

$type = $_GET['type'] ?? 'post';
$type = in_array($type, ['post', 'comment'], true) ? $type : 'post';
$targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($targetId <= 0) {
    header('Location: index.php');
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `reporter_id` INT NOT NULL,
        `target_type` ENUM('post','comment') NOT NULL,
        `target_id` INT NOT NULL,
        `post_id` INT DEFAULT NULL,
        `reported_user_id` INT DEFAULT NULL,
        `reason_code` VARCHAR(50) NOT NULL,
        `custom_reason` VARCHAR(255) DEFAULT NULL,
        `message` TEXT DEFAULT NULL,
        `status` ENUM('pending','reviewing','resolved','dismissed') DEFAULT 'pending',
        `admin_note` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `processed_at` TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    error_log('Report table migrate failed: ' . $e->getMessage());
}

$target = null;
$post = null;
$reportedUserId = null;
$postId = null;

try {
    if ($type === 'post') {
        $stmt = $pdo->prepare('SELECT p.id, p.title, p.content, p.user_id, u.name AS author_name FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if ($target) {
            $post = $target;
            $postId = (int)$target['id'];
            $reportedUserId = (int)$target['user_id'];
        }
    } else {
        $stmt = $pdo->prepare('SELECT c.id, c.comment, c.post_id, c.user_id, u.name AS author_name, p.title FROM comments c JOIN users u ON u.id = c.user_id JOIN posts p ON p.id = c.post_id WHERE c.id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if ($target) {
            $postId = (int)$target['post_id'];
            $reportedUserId = (int)$target['user_id'];
            $post = ['id' => $target['post_id'], 'title' => $target['title']];
        }
    }
} catch (PDOException $e) {
    error_log('Load target for report failed: ' . $e->getMessage());
}

if (!$target || !$postId) {
    header('Location: index.php');
    exit;
}

$reasons = [
    'spam' => 'Spam / quảng cáo',
    'harassment' => 'Ngôn từ không phù hợp',
    'misinformation' => 'Thông tin sai lệch',
    'sensitive' => 'Nội dung nhạy cảm',
    'other' => 'Khác'
];

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['reason'] ?? '';
    $details = trim($_POST['details'] ?? '');
    $customReason = trim($_POST['custom_reason'] ?? '');

    if (!array_key_exists($reason, $reasons)) {
        $errorMessage = 'Vui lòng chọn lý do hợp lệ.';
    } else {
        if ($reason !== 'other') {
            $customReason = null;
        } elseif (!$customReason) {
            $errorMessage = 'Vui lòng mô tả lý do báo cáo.';
        }
    }

    if (!$errorMessage) {
        try {
            $pdo->beginTransaction();
            $checkStmt = $pdo->prepare('SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? ORDER BY created_at DESC LIMIT 1');
            $checkStmt->execute([$_SESSION['user_id'], $type, $targetId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $update = $pdo->prepare("UPDATE reports SET reason_code = ?, custom_reason = ?, message = ?, status = 'pending', admin_note = NULL, processed_at = NULL, created_at = NOW() WHERE id = ?");
                $update->execute([
                    $reason,
                    $customReason ?: null,
                    $details ?: null,
                    $existingId
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO reports (reporter_id, target_type, target_id, post_id, reported_user_id, reason_code, custom_reason, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $_SESSION['user_id'],
                    $type,
                    $targetId,
                    $postId,
                    $reportedUserId ?: null,
                    $reason,
                    $customReason ?: null,
                    $details ?: null
                ]);
            }
            $pdo->commit();
            $successMessage = 'Đã gửi báo cáo. Quản trị viên sẽ xem xét và phản hồi sớm.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Submit report failed: ' . $e->getMessage());
            $errorMessage = 'Không thể gửi báo cáo lúc này. Vui lòng thử lại sau.';
        }
    }
}

require_once 'header.php';
?>

<div class="mx-auto" style="max-width:720px;">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h3 class="fw-bold mb-3">Báo cáo vi phạm / nội dung không phù hợp</h3>
            <p class="text-muted">Giúp cộng đồng an toàn hơn bằng cách báo cáo những nội dung sai phạm, spam hoặc nhạy cảm.</p>

            <?php if ($successMessage): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <div class="mb-4">
                <h5 class="mb-1">Đối tượng báo cáo</h5>
                <p class="mb-1"><strong>Loại:</strong> <?php echo $type === 'comment' ? 'Bình luận' : 'Bài đăng'; ?></p>
                <p class="mb-1"><strong>Bài đăng:</strong> <a href="view_post.php?id=<?php echo (int)$postId; ?>" target="_blank"><?php echo htmlspecialchars($post['title'] ?? 'Xem bài đăng'); ?></a></p>
                <?php if ($type === 'comment'): ?>
                    <p class="mb-0"><strong>Nội dung bình luận:</strong> <?php echo nl2br(htmlspecialchars($target['comment'])); ?></p>
                <?php else: ?>
                    <p class="mb-0"><strong>Tiêu đề:</strong> <?php echo htmlspecialchars($target['title']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!$successMessage): ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Lý do báo cáo *</label>
                        <select name="reason" class="form-select" required>
                            <option value="">-- Chọn lý do --</option>
                            <?php foreach ($reasons as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo isset($_POST['reason']) && $_POST['reason'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="customReasonWrapper" style="<?php echo (isset($_POST['reason']) && $_POST['reason'] === 'other') ? '' : 'display:none;'; ?>">
                        <label class="form-label">Mô tả lý do *</label>
                        <input type="text" name="custom_reason" class="form-control" value="<?php echo htmlspecialchars($_POST['custom_reason'] ?? ''); ?>" placeholder="Ví dụ: Thông tin giả mạo, xúc phạm tôn giáo...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chi tiết bổ sung</label>
                        <textarea name="details" class="form-control" rows="4" placeholder="Cung cấp thông tin giúp quản trị viên xử lý nhanh hơn."><?php echo htmlspecialchars($_POST['details'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">Gửi báo cáo</button>
                        <a href="view_post.php?id=<?php echo (int)$postId; ?>" class="btn btn-outline-secondary">Quay lại bài đăng</a>
                    </div>
                </form>
            <?php else: ?>
                <a href="view_post.php?id=<?php echo (int)$postId; ?>" class="btn btn-primary">Quay lại bài đăng</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    const reasonSelect = document.querySelector('select[name="reason"]');
    const customWrapper = document.getElementById('customReasonWrapper');
    if (reasonSelect && customWrapper) {
        reasonSelect.addEventListener('change', function() {
            if (this.value === 'other') {
                customWrapper.style.display = '';
            } else {
                customWrapper.style.display = 'none';
            }
        });
    }
})();
</script>

<?php require_once 'footer.php'; ?>
