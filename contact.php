<?php
require_once 'config.php';
require_login();

$error = '';
$success = '';
$post = null;
$postId = 0;

if (($_SESSION['role'] ?? '') === 'student' && !is_student_verified()) {
    header('Location: request_verification.php');
    exit;
}

// Handle GET request to fetch post details
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_GET['post_id']) || !is_numeric($_GET['post_id'])) {
        die('Post ID không hợp lệ.');
    }
    $postId = (int)$_GET['post_id'];
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.user_id, u.name AS author_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?');
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post) {
        die('Tin không tồn tại.');
    }
    // Prevent user from contacting themselves
    if ($post['user_id'] == $_SESSION['user_id']) {
        die('Bạn không thể tự liên hệ chính mình.');
    }
}

// Handle POST request to send message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int)($_POST['post_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    // Re-fetch post details for context
    $stmt = $pdo->prepare('SELECT p.id, p.title, p.user_id, u.name AS author_name FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?');
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    if (!$post) {
        $error = 'Tin không tồn tại.';
    } elseif (!empty($post['status']) && $post['status'] === 'taken') {
        $error = 'Tin này đã được nhận, bạn không thể liên hệ người đăng.';
    } elseif (empty($message)) {
        $error = 'Vui lòng nhập nội dung tin nhắn.';
    } else {
        $toUser = $post['user_id'];
        $fromUser = $_SESSION['user_id'];

        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, post_id, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$fromUser, $toUser, $postId, $message]);
        
        // Redirect to messages page after a short delay to show success
        header('Refresh: 2; URL=view_messages.php');
        $success = 'Tin nhắn của bạn đã được gửi thành công! Bạn sẽ được chuyển hướng sau 2 giây.';
    }
}

require_once 'header.php';
?>

<div class="container">
    <div class="contact-wrapper">
        <aside class="contact-sidebar">
            <h3>
                <i class="fas fa-envelope"></i>
                <span>LIÊN HỆ<br>NGƯỜI ĐĂNG</span>
            </h3>
            <a href="view_post.php?id=<?php echo htmlspecialchars($postId); ?>">
                <i class="fas fa-arrow-left"></i> Quay lại
            </a>
        </aside>
        <main class="contact-main">
            <h2>Liên hệ người đăng</h2>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php else: ?>
                <?php if ($post): ?>
                    <p class="lead">
                        Gửi đến: <strong><?php echo htmlspecialchars($post['author_name']); ?></strong>
                        <br>
                        <small class="text-muted">Về tin đăng: "<?php echo htmlspecialchars($post['title']); ?>"</small>
                    </p>
                <?php endif; ?>

                <form method="post" action="contact.php">
                    <input type="hidden" name="post_id" value="<?php echo htmlspecialchars($postId); ?>">
                    <div class="mb-3">
                        <label for="message" class="form-label">Nội dung</label>
                        <textarea name="message" id="message" class="form-control" rows="8" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary px-4">Gửi</button>
                    <a href="view_post.php?id=<?php echo htmlspecialchars($postId); ?>" class="btn btn-light px-4">Hủy</a>
                </form>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php require_once 'footer.php'; ?>
