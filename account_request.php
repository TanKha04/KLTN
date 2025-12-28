<?php
require_once 'config.php';
require_login();

try {
    // Kiểm tra bảng có tồn tại không
    $tableExists = $pdo->query("SHOW TABLES LIKE 'account_requests'")->rowCount() > 0;
    
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE `account_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `request_type` VARCHAR(50) NOT NULL,
            `details` TEXT NOT NULL,
            `status` VARCHAR(20) DEFAULT 'pending',
            `admin_note` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `processed_at` TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
} catch (PDOException $e) {
    error_log('Account request migrate failed: ' . $e->getMessage());
}

$allowedTypes = [
    'delete_account' => 'Yêu cầu xóa tài khoản',
    'update_info' => 'Chỉnh sửa thông tin sai',
    'other' => 'Khác'
];

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['request_type'] ?? '';
    $details = trim($_POST['details'] ?? '');

    if (!array_key_exists($type, $allowedTypes)) {
        $errorMessage = 'Loại yêu cầu không hợp lệ.';
    } elseif (!$details) {
        $errorMessage = 'Vui lòng mô tả rõ nhu cầu của bạn.';
    }

    if (!$errorMessage) {
        try {
            $stmt = $pdo->prepare('INSERT INTO account_requests (user_id, request_type, details) VALUES (?, ?, ?)');
            $stmt->execute([
                $_SESSION['user_id'],
                $type,
                $details
            ]);
            $successMessage = 'Đã gửi yêu cầu thành công. Quản trị viên sẽ xử lý và phản hồi trong thời gian sớm nhất.';
        } catch (PDOException $e) {
            error_log('Submit account request failed: ' . $e->getMessage());
            // Thử tạo lại bảng nếu có lỗi
            try {
                $pdo->exec("DROP TABLE IF EXISTS `account_requests`");
                $pdo->exec("CREATE TABLE `account_requests` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `request_type` VARCHAR(50) NOT NULL,
                    `details` TEXT NOT NULL,
                    `status` VARCHAR(20) DEFAULT 'pending',
                    `admin_note` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `processed_at` TIMESTAMP NULL DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                
                // Thử insert lại
                $stmt = $pdo->prepare('INSERT INTO account_requests (user_id, request_type, details) VALUES (?, ?, ?)');
                $stmt->execute([
                    $_SESSION['user_id'],
                    $type,
                    $details
                ]);
                $successMessage = 'Đã gửi yêu cầu thành công. Quản trị viên sẽ xử lý và phản hồi trong thời gian sớm nhất.';
            } catch (PDOException $e2) {
                error_log('Retry submit account request failed: ' . $e2->getMessage());
                $errorMessage = 'Không thể gửi yêu cầu lúc này. Vui lòng thử lại sau.';
            }
        }
    }
}

$history = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM account_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$_SESSION['user_id']]);
    $history = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Fetch account request history failed: ' . $e->getMessage());
}

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Hỗ trợ</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<link rel="stylesheet" href="assets/css/account-request.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;} .hero-banner{display:none !important;}</style>';
    echo '</head><body>';
}
?>

<?php $isEmbedPage = $isEmbed; ?>
<style>
<?php if ($isEmbed): ?>
.hero-banner { display: none !important; }
<?php endif; ?>

.account-request-page {
    min-height: <?php echo $isEmbedPage ? 'auto' : 'calc(100vh - 200px)'; ?>;
    padding: <?php echo $isEmbedPage ? '0.5rem' : '2rem 1rem'; ?>;
    background: <?php echo $isEmbedPage ? '#f8fafc' : 'linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%)'; ?>;
}

.account-request-container {
    max-width: <?php echo $isEmbedPage ? '100%' : '800px'; ?>;
    margin: 0 auto;
}

.account-request-card {
    background: #fff;
    border-radius: <?php echo $isEmbedPage ? '12px' : '24px'; ?>;
    box-shadow: <?php echo $isEmbedPage ? '0 2px 10px rgba(0,0,0,0.05)' : '0 25px 80px rgba(11, 63, 145, 0.25)'; ?>;
    overflow: hidden;
    animation: cardFadeIn 0.5s ease-out;
}

@keyframes cardFadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.account-request-header {
    background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #1e3a8a 100%);
    padding: <?php echo $isEmbedPage ? '1.5rem 1.25rem' : '2.5rem'; ?>;
    position: relative;
    overflow: hidden;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(11, 63, 145, 0.3);
}

.header-background {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(147, 197, 253, 0.1) 0%, transparent 50%);
    pointer-events: none;
    animation: backgroundShift 8s ease-in-out infinite;
}

@keyframes backgroundShift {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.header-icon {
    width: <?php echo $isEmbedPage ? '50px' : '80px'; ?>;
    height: <?php echo $isEmbedPage ? '50px' : '80px'; ?>;
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
    border-radius: <?php echo $isEmbedPage ? '14px' : '20px'; ?>;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: <?php echo $isEmbedPage ? '1.5rem' : '2.2rem'; ?>;
    color: #fff;
    box-shadow: 0 15px 40px rgba(59, 130, 246, 0.5);
    flex-shrink: 0;
    animation: iconBounce 3s ease-in-out infinite;
}

@keyframes iconBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

.header-text {
    flex: 1;
    min-width: 250px;
}

.header-text h3 {
    color: #fff;
    font-size: <?php echo $isEmbedPage ? '1.3rem' : '1.8rem'; ?>;
    font-weight: 800;
    margin: 0 0 0.5rem;
    letter-spacing: -0.5px;
}

.header-text p {
    color: rgba(255, 255, 255, 0.85);
    font-size: <?php echo $isEmbedPage ? '0.9rem' : '1rem'; ?>;
    margin: 0;
    line-height: 1.6;
    font-weight: 500;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.btn-action:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
}

.btn-action:active {
    transform: translateY(0);
}

.account-request-body {
    padding: <?php echo $isEmbedPage ? '1rem 1.25rem' : '2rem'; ?>;
}

/* Alert Styles */
.request-alert {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    border-radius: 14px;
    margin-bottom: 1.5rem;
    animation: alertSlide 0.4s ease-out;
}

@keyframes alertSlide {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.request-alert.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 1px solid #6ee7b7;
}

.request-alert.success .alert-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.request-alert.error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
}

.request-alert.error .alert-icon {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.alert-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
}

.alert-content strong {
    display: block;
    margin-bottom: 0.25rem;
}

.request-alert.success .alert-content {
    color: #065f46;
}

.request-alert.error .alert-content {
    color: #991b1b;
}

/* Form Styles */
.request-form {
    margin-bottom: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.75rem;
    font-size: 0.95rem;
}

.form-group label i {
    color: #3b82f6;
}

.form-group label .required {
    color: #ef4444;
    font-size: 0.85rem;
}

.form-input,
.form-select,
.form-textarea {
    width: 100%;
    padding: 1rem 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #f8fafc;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
}

.form-select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    background-size: 1.25rem;
    padding-right: 3rem;
}

.form-textarea {
    resize: vertical;
    min-height: 140px;
}

.form-hint {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
    font-size: 0.85rem;
    color: #64748b;
}

.form-hint i {
    color: #f59e0b;
}

.form-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.btn-submit {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.35);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(59, 130, 246, 0.45);
}

.btn-submit:active {
    transform: translateY(0);
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 1.75rem;
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: #e2e8f0;
    color: #1e293b;
    border-color: #cbd5e1;
}

/* History Section */
.history-section {
    border-top: 2px solid #e2e8f0;
    padding-top: 2rem;
}

.history-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 1.25rem;
}

.history-title i {
    color: #3b82f6;
}

.history-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.history-item {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 16px;
    padding: 1.25rem;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.history-item:hover {
    transform: translateX(5px);
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    border-color: #c7d2fe;
}

.history-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.history-item-type {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.type-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.type-icon.delete {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #dc2626;
}

.type-icon.update {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #2563eb;
}

.type-icon.other {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #7c3aed;
}

.type-info h6 {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.type-info span {
    font-size: 0.8rem;
    color: #94a3b8;
}

.history-status {
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.history-status.pending {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}

.history-status.in_review {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
    color: #0e7490;
}

.history-status.resolved {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
}

.history-status.rejected {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}

.history-item-content {
    color: #475569;
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 0.75rem;
    padding-left: 2.75rem;
}

.history-item-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-left: 2.75rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.admin-response {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    border-radius: 10px;
    padding: 0.75rem 1rem;
    font-size: 0.85rem;
    color: #4338ca;
    flex: 1;
    min-width: 200px;
}

.admin-response strong {
    display: block;
    margin-bottom: 0.25rem;
    font-size: 0.8rem;
    color: #6366f1;
}

.update-time {
    font-size: 0.8rem;
    color: #94a3b8;
}

@media (max-width: 768px) {
    .header-content {
        gap: 1.5rem;
    }
    
    .header-actions {
        width: 100%;
        justify-content: flex-start;
    }
}

@media (max-width: 640px) {
    .account-request-page {
        padding: 1rem 0.5rem;
    }
    
    .account-request-header {
        padding: 1.5rem;
        border-radius: 16px;
    }
    
    .header-content {
        flex-direction: column;
        text-align: center;
        gap: 1rem;
    }
    
    .header-icon {
        margin: 0 auto;
    }
    
    .header-text {
        min-width: auto;
    }
    
    .header-actions {
        width: 100%;
        justify-content: center;
    }
    
    .account-request-body {
        padding: 1.5rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-submit,
    .btn-back {
        width: 100%;
        justify-content: center;
    }
    
    .history-item-content,
    .history-item-footer {
        padding-left: 0;
    }
}
</style>

<div class="account-request-page">
    
    <div class="account-request-container">
        <div class="account-request-card">
            <div class="account-request-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="bi bi-life-preserver"></i>
                    </div>
                    <div class="header-text">
                        <h3>Đơn yêu cầu hỗ trợ tài khoản</h3>
                        <p>Sử dụng biểu mẫu này khi bạn cần xóa tài khoản, chỉnh sửa thông tin quan trọng (tên, giấy tờ) hoặc yêu cầu hỗ trợ khác.</p>
                    </div>
                </div>
            </div>

            <div class="account-request-body">
                <?php if ($successMessage): ?>
                    <div class="request-alert success">
                        <div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="alert-content">
                            <strong>Thành công!</strong>
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($errorMessage): ?>
                    <div class="request-alert error">
                        <div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
                        <div class="alert-content">
                            <strong>Lỗi!</strong>
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" class="request-form">
                    <div class="form-group">
                        <label>
                            <i class="bi bi-list-ul"></i>
                            Loại yêu cầu <span class="required">*</span>
                        </label>
                        <select name="request_type" class="form-select" required>
                            <option value="">-- Chọn loại yêu cầu --</option>
                            <?php foreach ($allowedTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo isset($_POST['request_type']) && $_POST['request_type'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <i class="bi bi-text-left"></i>
                            Mô tả chi tiết <span class="required">*</span>
                        </label>
                        <textarea name="details" class="form-textarea" placeholder="Nêu rõ nội dung muốn xóa/chỉnh sửa hoặc tình huống đang gặp..." required><?php echo htmlspecialchars($_POST['details'] ?? ''); ?></textarea>
                        <div class="form-hint">
                            <i class="bi bi-lightbulb"></i>
                            Ví dụ: viết nhầm tên, tải nhầm giấy tờ, muốn ẩn hồ sơ tạm thời, v.v.
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-submit">
                            <i class="bi bi-send"></i> Gửi yêu cầu
                        </button>
                        <a href="<?php echo $_SESSION['role'] === 'patient' ? 'dashboard_patient.php' : 'dashboard_student.php'; ?>" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Quay lại bảng điều khiển
                        </a>
                    </div>
                </form>

                <?php if (!empty($history)): ?>
                    <?php $statusLabels = ['pending' => 'Chờ xử lý', 'in_review' => 'Đang xem xét', 'resolved' => 'Đã hoàn tất', 'rejected' => 'Từ chối']; ?>
                    <div class="history-section">
                        <h5 class="history-title">
                            <i class="bi bi-clock-history"></i> Yêu cầu đã gửi
                        </h5>
                        <div class="history-list">
                            <?php foreach ($history as $item): ?>
                                <?php
                                    $typeIcon = 'other';
                                    $typeIconClass = 'bi bi-question-circle';
                                    if ($item['request_type'] === 'delete_account') {
                                        $typeIcon = 'delete';
                                        $typeIconClass = 'bi bi-trash';
                                    } elseif ($item['request_type'] === 'update_info') {
                                        $typeIcon = 'update';
                                        $typeIconClass = 'bi bi-pencil-square';
                                    }
                                ?>
                                <div class="history-item">
                                    <div class="history-item-header">
                                        <div class="history-item-type">
                                            <div class="type-icon <?php echo $typeIcon; ?>">
                                                <i class="<?php echo $typeIconClass; ?>"></i>
                                            </div>
                                            <div class="type-info">
                                                <h6><?php echo htmlspecialchars($allowedTypes[$item['request_type']] ?? $item['request_type']); ?></h6>
                                                <span><i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($item['created_at'])); ?></span>
                                            </div>
                                        </div>
                                        <span class="history-status <?php echo $item['status']; ?>">
                                            <?php echo htmlspecialchars($statusLabels[$item['status']] ?? $item['status']); ?>
                                        </span>
                                    </div>
                                    <div class="history-item-content">
                                        <?php echo nl2br(htmlspecialchars($item['details'])); ?>
                                    </div>
                                    <div class="history-item-footer">
                                        <?php if (!empty($item['admin_note'])): ?>
                                            <div class="admin-response">
                                                <strong><i class="bi bi-reply"></i> Phản hồi từ quản trị viên:</strong>
                                                <?php echo nl2br(htmlspecialchars($item['admin_note'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['processed_at'])): ?>
                                            <span class="update-time">
                                                <i class="bi bi-arrow-repeat"></i> Cập nhật: <?php echo date('d/m/Y H:i', strtotime($item['processed_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
