<?php
require_once 'config.php';
require_login();

if (is_admin_user()) {
    header('Location: admin.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'patient';
$isStudent = ($userRole === 'student');
$dashboardLink = $isStudent ? 'dashboard_student.php' : 'dashboard_patient.php';
$likePattern = 'Bạn đã được chọn nhận việc%';

$historyRows = [];
$errorMessage = null;
try {
    if ($isStudent) {
        $sql = "SELECT m.created_at AS accepted_at, p.id AS post_id, p.title, u.name AS counterparty_name, u.email AS counterparty_email, p.area "
            . "FROM messages m "
            . "JOIN posts p ON p.id = m.post_id "
            . "JOIN users u ON u.id = p.user_id "
            . "WHERE m.to_user = ? "
            . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
            . "AND m.message LIKE ? "
            . "ORDER BY m.created_at DESC";
    } else {
        if ($userRole !== 'patient') {
            die('Truy cập bị từ chối.');
        }
        $sql = "SELECT m.created_at AS accepted_at, p.id AS post_id, p.title, u.name AS counterparty_name, u.email AS counterparty_email, u.phone AS counterparty_phone "
            . "FROM messages m "
            . "JOIN posts p ON p.id = m.post_id "
            . "JOIN users u ON u.id = m.to_user "
            . "WHERE m.from_user = ? "
            . "AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "
            . "AND m.message LIKE ? "
            . "ORDER BY m.created_at DESC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $likePattern]);
    $historyRows = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Không thể tải lịch sử nhận việc. Vui lòng thử lại sau.';
    error_log('assignment_history load failed: ' . $e->getMessage());
}
$totalCount = count($historyRows);

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Lịch sử giao việc</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}</style>';
    echo '</head><body>';
}
?>

<div class="assignment-history-page">
    <!-- Header Section -->
    <div class="history-header">
        <div class="history-header-content">
            <div class="history-header-left">
                <span class="history-badge">
                    <i class="bi bi-calendar3"></i>
                    30 ngày gần nhất
                </span>
                <h1 class="history-title">Lịch sử nhận việc</h1>
                <p class="history-subtitle">
                    <span class="history-count"><?php echo $totalCount; ?></span> lượt được ghi nhận
                </p>
            </div>
            <div class="history-header-actions">
                <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="btn-history-outline">
                    <i class="bi bi-arrow-left"></i>
                    Bảng điều khiển
                </a>
                <?php if ($isStudent): ?>
                    <a href="index.php?type=recruitment#posts" class="btn-history-primary">
                        <i class="bi bi-search"></i>
                        Tìm bài tuyển mới
                    </a>
                <?php else: ?>
                    <a href="index.php?type=application#posts" class="btn-history-primary">
                        <i class="bi bi-person-check"></i>
                        Duyệt hồ sơ sinh viên
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="history-alert history-alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php elseif (!$historyRows): ?>
        <!-- Empty State -->
        <div class="history-empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-calendar2-week"></i>
            </div>
            <h3 class="empty-state-title">Chưa có dữ liệu trong 30 ngày qua</h3>
            <p class="empty-state-desc">
                Mỗi khi bạn <?php echo $isStudent ? 'được chọn hỗ trợ' : 'chọn một sinh viên'; ?>, hệ thống sẽ ghi lại tại đây.
            </p>
            <?php if ($isStudent): ?>
                <a href="index.php?type=recruitment#posts" class="btn-history-primary btn-lg">
                    <i class="bi bi-compass"></i>
                    Khám phá bài tuyển
                </a>
            <?php else: ?>
                <a href="index.php?type=application#posts" class="btn-history-primary btn-lg">
                    <i class="bi bi-people"></i>
                    Tìm sinh viên phù hợp
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- History List -->
        <div class="history-list-container">
            <div class="history-list">
                <?php foreach ($historyRows as $index => $row): ?>
                    <div class="history-item" style="animation-delay: <?php echo $index * 0.1; ?>s">
                        <div class="history-item-date">
                            <div class="date-icon">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <div class="date-info">
                                <span class="date-day"><?php echo date('d/m/Y', strtotime($row['accepted_at'])); ?></span>
                                <span class="date-time"><?php echo date('H:i', strtotime($row['accepted_at'])); ?></span>
                            </div>
                        </div>
                        
                        <div class="history-item-person">
                            <div class="person-avatar">
                                <i class="bi bi-person"></i>
                            </div>
                            <div class="person-info">
                                <span class="person-label"><?php echo $isStudent ? 'Người đăng tuyển' : 'Sinh viên'; ?></span>
                                <span class="person-name"><?php echo htmlspecialchars($row['counterparty_name'] ?? ''); ?></span>
                                <span class="person-email"><?php echo htmlspecialchars($row['counterparty_email'] ?? ''); ?></span>
                                <?php if (!$isStudent && !empty($row['counterparty_phone'])): ?>
                                    <span class="person-phone">
                                        <i class="bi bi-telephone"></i>
                                        <?php echo htmlspecialchars($row['counterparty_phone']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="history-item-details">
                            <div class="details-icon">
                                <i class="bi bi-file-text"></i>
                            </div>
                            <div class="details-info">
                                <span class="details-label">Chi tiết công việc</span>
                                <span class="details-title"><?php echo htmlspecialchars($row['title'] ?? ''); ?></span>
                                <?php if ($isStudent && !empty($row['area'])): ?>
                                    <span class="details-area">
                                        <i class="bi bi-geo-alt"></i>
                                        <?php echo htmlspecialchars($row['area']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="history-item-action">
                            <a class="btn-view-post" href="view_post.php?id=<?php echo (int)$row['post_id']; ?>">
                                <span>Xem bài</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
