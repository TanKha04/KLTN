<?php
require_once 'config.php';
require_login();

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

// Admin accounts should not use patient posting features
if (is_admin_user()) {
    if ($isEmbed) {
        // Khi embed, hiển thị thông báo thay vì redirect (tránh load sidebar trong iframe)
        echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"><style>body{background:#f1f5f9;padding:2rem;display:flex;align-items:center;justify-content:center;min-height:80vh;}.admin-notice{text-align:center;background:#fff;padding:3rem;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.1);max-width:500px;}.admin-notice i{font-size:4rem;color:#3b82f6;margin-bottom:1rem;}.admin-notice h3{color:#1e293b;margin-bottom:0.5rem;}.admin-notice p{color:#64748b;margin-bottom:1.5rem;}.admin-notice a{display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:0.75rem 1.5rem;border-radius:12px;text-decoration:none;font-weight:600;transition:transform 0.2s;}.admin-notice a:hover{transform:translateY(-2px);}</style></head><body>';
        echo '<div class="admin-notice">';
        echo '<i class="bi bi-shield-check"></i>';
        echo '<h3>Tài khoản Quản trị viên</h3>';
        echo '<p>Quản trị viên vui lòng sử dụng trang quản trị để tạo bài viết.</p>';
        echo '<a href="admin_posts.php?create=recruitment" target="_top"><i class="bi bi-box-arrow-up-right"></i> Đi đến trang quản trị</a>';
        echo '</div></body></html>';
        exit;
    }
    header('Location: admin.php');
    exit;
}
if ($_SESSION['role'] !== 'patient') {
        die('Chỉ bệnh nhân mới có thể tạo tin tuyển.');
}

// Check if user is allowed to post
$stmtUser = $pdo->prepare('SELECT can_post FROM users WHERE id = ?');
$stmtUser->execute([$_SESSION['user_id']]);
$u = $stmtUser->fetch();
if (!$u) {
    session_unset(); session_destroy(); header('Location: login.php'); exit;
}

// Check embed early
$isEmbedEarly = $isEmbed;
if (empty($u['can_post'])) {
    if (!$isEmbedEarly) {
        require_once 'header.php';
    } else {
        echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f1f5f9;padding:2rem;}</style></head><body>';
    }
    ?>
    <div class="mx-auto" style="max-width:720px;">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h3 class="fw-bold mb-3">Quyền đăng tin chưa được cấp</h3>
                <p class="text-muted">Tài khoản của bạn hiện chưa được cấp quyền đăng tin. Vui lòng gửi yêu cầu cấp quyền hoặc liên hệ quản trị viên.</p>
                <div class="d-flex gap-2">
                    <?php if ($isEmbedEarly): ?>
                    <a class="btn btn-primary" href="request_posting_permission.php?embed=1" target="_self">Xin cấp quyền</a>
                    <?php else: ?>
                    <a class="btn btn-primary" href="request_posting_permission.php">Xin cấp quyền</a>
                    <a class="btn btn-outline-secondary" href="dashboard_patient.php">Quay lại bảng điều khiển</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    if ($isEmbedEarly) {
        echo '</body></html>';
    } else {
        require_once 'footer.php';
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $area = trim($_POST['area'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $videoPath = null;

    if (!$title || !$content || !$contact || !$fullname) {
        $error = 'Vui lòng điền đầy đủ các trường bắt buộc.';
    } else {
        // Ensure columns exist (auto-migrate if needed)
        try {
            // Thêm cột type nếu chưa có
            $checkType = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'type'");
            $checkType->execute();
            if ((int)$checkType->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN type VARCHAR(50) DEFAULT 'application' AFTER content");
            }
            
            // Thêm cột area nếu chưa có
            $checkArea = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'area'");
            $checkArea->execute();
            if ((int)$checkArea->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN area VARCHAR(255) NULL AFTER type");
            }
            
            // Thêm cột category nếu chưa có
            $checkCategory = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'category'");
            $checkCategory->execute();
            if ((int)$checkCategory->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN category VARCHAR(100) NULL AFTER area");
            }
            
            // Thêm cột contact_info nếu chưa có
            $checkContact = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'contact_info'");
            $checkContact->execute();
            if ((int)$checkContact->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN contact_info VARCHAR(255) NULL AFTER category");
            }
            
            // Thêm cột recruiter_fullname nếu chưa có
            $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'recruiter_fullname'");
            $check->execute();
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN recruiter_fullname VARCHAR(150) NULL AFTER contact_info");
            }
            
            // Thêm cột video_path nếu chưa có
            $checkVideo = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'video_path'");
            $checkVideo->execute();
            if ((int)$checkVideo->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN video_path VARCHAR(255) NULL AFTER recruiter_fullname");
            }
        } catch (Exception $e) { 
            error_log('Auto-migrate posts table failed: ' . $e->getMessage());
        }

        // Handle video upload
        if (!empty($_FILES['health_video']['name'])) {
            $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'];
            $uploadError = $_FILES['health_video']['error'];
            if ($uploadError !== UPLOAD_ERR_OK) {
                // Hiển thị lỗi chi tiết
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'Video vượt quá giới hạn upload_max_filesize trong php.ini.',
                    UPLOAD_ERR_FORM_SIZE => 'Video vượt quá giới hạn MAX_FILE_SIZE trong form.',
                    UPLOAD_ERR_PARTIAL => 'Video chỉ được tải lên một phần.',
                    UPLOAD_ERR_NO_FILE => 'Không có video nào được tải lên.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm.',
                    UPLOAD_ERR_CANT_WRITE => 'Không thể ghi video vào đĩa.',
                    UPLOAD_ERR_EXTENSION => 'Một extension PHP đã dừng việc tải lên.',
                ];
                $error = $errorMessages[$uploadError] ?? 'Tải video thất bại. Mã lỗi: ' . $uploadError;
            } else {
                $detectedType = null;
                if (function_exists('mime_content_type')) {
                    $detectedType = mime_content_type($_FILES['health_video']['tmp_name']);
                }
                if (!$detectedType && isset($_FILES['health_video']['type'])) {
                    $detectedType = $_FILES['health_video']['type'];
                }
                if ($detectedType && !in_array($detectedType, $allowedVideoTypes)) {
                    $error = 'Chỉ hỗ trợ video MP4, WebM, MOV, AVI.';
                } elseif ($_FILES['health_video']['size'] > 50 * 1024 * 1024) {
                    $error = 'Video tối đa 50MB.';
                } else {
                    $targetDir = __DIR__ . '/uploads/health_videos';
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0777, true);
                    }
                    $ext = pathinfo($_FILES['health_video']['name'], PATHINFO_EXTENSION);
                    $filename = 'video_' . $_SESSION['user_id'] . '_' . time() . '.' . strtolower($ext ?: 'mp4');
                    $targetPath = $targetDir . '/' . $filename;
                    if (move_uploaded_file($_FILES['health_video']['tmp_name'], $targetPath)) {
                        $videoPath = 'uploads/health_videos/' . $filename;
                    } else {
                        $error = 'Không thể lưu video. Vui lòng thử lại.';
                    }
                }
            }
        }

        if (!$error) {
            $stmt = $pdo->prepare('INSERT INTO posts (user_id,title,content,type,area,category,contact_info,recruiter_fullname,video_path) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$_SESSION['user_id'], $title, $content, 'recruitment', $area, $category, $contact, $fullname, $videoPath]);
            
            // Nếu đang trong iframe, redirect parent window
            if ($isEmbed) {
                echo '<!DOCTYPE html><html><head><script>window.top.location.href = "dashboard_patient.php";</script></head><body></body></html>';
                exit;
            }
            header('Location: dashboard_patient.php');
            exit;
        }
    }
}

if ($isEmbed): ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo tin tuyển dụng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f1f5f9; margin: 0; padding: 0; }
        /* Ẩn mọi sidebar khi embed */
        .dashboard-sidebar, .sidebar, aside { display: none !important; }
    </style>
</head>
<body>
<?php else:
    require_once 'header.php';
endif; ?>

<style>
.create-recruit-page {
    min-height: auto;
    padding: 1rem;
}
.create-recruit-card {
    max-width: 700px;
    margin: 0 auto;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 15px 50px rgba(11, 63, 145, 0.1);
    overflow: hidden;
    animation: cardFadeIn 0.4s ease-out;
}
@keyframes cardFadeIn {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}
.create-recruit-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
    padding: 1.25rem 1.5rem;
    position: relative;
    overflow: hidden;
}
.create-recruit-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 60%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}
.create-recruit-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.create-recruit-icon {
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    border: 2px solid rgba(255,255,255,0.2);
    flex-shrink: 0;
}
.create-recruit-header h1 {
    color: #fff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
}
.create-recruit-header p {
    color: rgba(255,255,255,0.9);
    font-size: 0.85rem;
    margin: 0;
    line-height: 1.4;
}
.create-recruit-body {
    padding: 1.5rem;
}
.form-section {
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.form-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}
.form-section-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 1.25rem;
}
.form-section-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #059669;
    font-size: 0.95rem;
}
.form-floating-custom {
    position: relative;
    margin-bottom: 0.875rem;
}
.form-floating-custom .form-control,
.form-floating-custom .form-select {
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.7rem 1rem;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    background: #f8fafc;
}
.form-floating-custom .form-control:focus,
.form-floating-custom .form-select:focus {
    border-color: #10b981;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
}
.form-floating-custom label {
    font-weight: 600;
    color: #475569;
    margin-bottom: 0.4rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
}
.form-floating-custom label .required {
    color: #ef4444;
    font-weight: 700;
}
.form-floating-custom .form-hint {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 0.3rem;
}
.form-floating-custom textarea.form-control {
    min-height: 90px;
    resize: vertical;
}
.video-upload-area {
    border: 2px dashed #10b981;
    border-radius: 14px;
    padding: 1.25rem;
    text-align: center;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}
.video-upload-area:hover {
    border-color: #059669;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    transform: translateY(-1px);
}
.video-upload-area.dragover {
    border-color: #059669;
    background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%);
}
.video-upload-area input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}
.video-upload-icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.2rem;
    margin: 0 auto 0.75rem;
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.25);
}
.video-upload-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #065f46;
    margin-bottom: 0.35rem;
}
.video-upload-text {
    font-weight: 600;
    color: #047857;
    margin-bottom: 0.2rem;
    font-size: 0.85rem;
}
.video-upload-hint {
    font-size: 0.75rem;
    color: #6b7280;
}
.video-benefits {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
    margin-top: 0.75rem;
}
.video-benefit-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.65rem;
    background: rgba(255,255,255,0.8);
    border-radius: 15px;
    font-size: 0.7rem;
    color: #047857;
    font-weight: 500;
}
.video-benefit-tag i {
    color: #10b981;
}
.form-actions {
    display: flex;
    gap: 0.75rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
    margin-top: 1rem;
}
.btn-submit {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.8rem 1.5rem;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
}
.btn-cancel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.8rem 1.5rem;
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}
.btn-cancel:hover {
    background: #e2e8f0;
    color: #1e293b;
    border-color: #cbd5e1;
}
.alert-custom {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border-radius: 14px;
    margin-bottom: 1.5rem;
    animation: alertSlide 0.4s ease-out;
}
@keyframes alertSlide {
    from { opacity: 0; transform: translateX(-20px); }
    to { opacity: 1; transform: translateX(0); }
}
.alert-custom.error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
    color: #991b1b;
}
.alert-custom .alert-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.alert-custom.error .alert-icon {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #fff;
}
.video-preview-container {
    margin-top: 1rem;
    display: none;
}
.video-preview-container.show {
    display: block;
}
.video-preview {
    width: 100%;
    max-height: 300px;
    border-radius: 16px;
    background: #000;
}
.video-file-info {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #fff;
    border-radius: 12px;
    margin-top: 1rem;
    border: 1px solid #d1fae5;
}
.video-file-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.3rem;
}
.video-file-details {
    flex: 1;
}
.video-file-name {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.25rem;
    word-break: break-all;
}
.video-file-size {
    font-size: 0.85rem;
    color: #64748b;
}
.btn-remove-video {
    padding: 0.5rem;
    background: #fee2e2;
    border: none;
    border-radius: 8px;
    color: #dc2626;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-remove-video:hover {
    background: #fecaca;
}
@media (max-width: 768px) {
    .create-recruit-header { padding: 2rem 1.5rem; }
    .create-recruit-body { padding: 1.5rem; }
    .create-recruit-header-content { flex-direction: column; text-align: center; }
    .create-recruit-icon { width: 70px; height: 70px; font-size: 1.8rem; }
    .create-recruit-header h1 { font-size: 1.5rem; }
    .form-actions { flex-direction: column; }
    .video-benefits { flex-direction: column; align-items: center; }
}
</style>

<div class="create-recruit-page">
    <div class="create-recruit-card">
        <!-- Header -->
        <div class="create-recruit-header">
            <div class="create-recruit-header-content">
                <div class="create-recruit-icon">📢</div>
                <div>
                    <h1>Đăng Tin Tuyển Dụng</h1>
                    <p>Tìm kiếm sinh viên Y để chăm sóc sức khỏe tại nhà cho người thân của bạn</p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="create-recruit-body">
            <?php if ($error): ?>
                <div class="alert-custom error">
                    <div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <!-- Section: Thông tin người đăng -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon"><i class="bi bi-person-fill"></i></div>
                        Thông tin người đăng
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating-custom">
                                <label>Họ và tên người liên hệ <span class="required">*</span></label>
                                <input type="text" name="fullname" class="form-control" placeholder="Nhập họ tên đầy đủ" required value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating-custom">
                                <label>Thông tin liên hệ <span class="required">*</span></label>
                                <input type="text" name="contact" class="form-control" placeholder="Số điện thoại hoặc email" required value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section: Nội dung tin tuyển -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                        Nội dung tin tuyển
                    </div>

                    <div class="form-floating-custom">
                        <label>Tiêu đề bài đăng <span class="required">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="Ví dụ: Cần sinh viên Y chăm sóc người cao tuổi tại Quận 1" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    </div>

                    <div class="form-floating-custom">
                        <label>Mô tả chi tiết <span class="required">*</span></label>
                        <textarea name="content" class="form-control" placeholder="Mô tả chi tiết về:&#10;- Tình trạng sức khỏe cần chăm sóc&#10;- Công việc cụ thể cần làm&#10;- Thời gian làm việc mong muốn&#10;- Yêu cầu đặc biệt (nếu có)..." required><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                        <div class="form-hint">Mô tả càng chi tiết, sinh viên càng hiểu rõ công việc và phù hợp hơn</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating-custom">
                                <label>Chuyên khoa / Loại chăm sóc</label>
                                <input type="text" name="category" class="form-control" placeholder="Ví dụ: Chăm sóc người cao tuổi, Hậu phẫu" value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating-custom">
                                <label>Địa chỉ / Khu vực</label>
                                <input type="text" name="area" class="form-control" placeholder="Địa chỉ cụ thể hoặc link Google Maps" value="<?php echo htmlspecialchars($_POST['area'] ?? ''); ?>">
                                <div class="form-hint">Dán URL Google Maps để sinh viên dễ tìm đường</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section: Video tình trạng sức khỏe -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon"><i class="bi bi-camera-video-fill"></i></div>
                        Video tình trạng sức khỏe (Tùy chọn)
                    </div>

                    <div class="video-upload-area" id="videoUploadArea">
                        <input type="file" name="health_video" accept="video/*" id="healthVideoInput">
                        <div class="video-upload-icon"><i class="bi bi-film"></i></div>
                        <div class="video-upload-title">Tải lên video ngắn về tình trạng sức khỏe</div>
                        <div class="video-upload-text" id="videoUploadText">Kéo thả hoặc nhấn để chọn video</div>
                        <div class="video-upload-hint">MP4, WebM, MOV (tối đa 50MB, khuyến nghị dưới 2 phút)</div>
                        
                        <div class="video-benefits">
                            <span class="video-benefit-tag"><i class="bi bi-check-circle-fill"></i> Sinh viên hiểu rõ hơn</span>
                            <span class="video-benefit-tag"><i class="bi bi-check-circle-fill"></i> Tăng độ tin cậy</span>
                            <span class="video-benefit-tag"><i class="bi bi-check-circle-fill"></i> Nhận ứng tuyển phù hợp</span>
                        </div>
                    </div>

                    <div class="video-preview-container" id="videoPreviewContainer">
                        <div class="video-file-info" id="videoFileInfo">
                            <div class="video-file-icon"><i class="bi bi-play-fill"></i></div>
                            <div class="video-file-details">
                                <div class="video-file-name" id="videoFileName"></div>
                                <div class="video-file-size" id="videoFileSize"></div>
                            </div>
                            <button type="button" class="btn-remove-video" id="removeVideoBtn">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <video class="video-preview" id="videoPreview" controls></video>
                    </div>

                    <div class="form-hint mt-2">
                        <i class="bi bi-info-circle text-primary"></i> 
                        Video giúp sinh viên Y đánh giá chính xác tình trạng và chuẩn bị tốt hơn cho công việc chăm sóc.
                    </div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn-submit">
                        <i class="bi bi-send-fill"></i> Đăng tin tuyển dụng
                    </button>
                    <a class="btn-cancel" href="dashboard_patient.php">
                        Hủy
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const videoInput = document.getElementById('healthVideoInput');
    const uploadArea = document.getElementById('videoUploadArea');
    const uploadText = document.getElementById('videoUploadText');
    const previewContainer = document.getElementById('videoPreviewContainer');
    const videoPreview = document.getElementById('videoPreview');
    const videoFileName = document.getElementById('videoFileName');
    const videoFileSize = document.getElementById('videoFileSize');
    const removeBtn = document.getElementById('removeVideoBtn');

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function handleVideoSelect(file) {
        if (file && file.type.startsWith('video/')) {
            videoFileName.textContent = file.name;
            videoFileSize.textContent = formatFileSize(file.size);
            
            const url = URL.createObjectURL(file);
            videoPreview.src = url;
            
            uploadArea.style.display = 'none';
            previewContainer.classList.add('show');
        }
    }

    function resetVideoUpload() {
        videoInput.value = '';
        videoPreview.src = '';
        uploadArea.style.display = 'block';
        previewContainer.classList.remove('show');
    }

    if (videoInput) {
        videoInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                handleVideoSelect(this.files[0]);
            }
        });
    }

    if (uploadArea) {
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                const file = e.dataTransfer.files[0];
                if (file.type.startsWith('video/')) {
                    videoInput.files = e.dataTransfer.files;
                    handleVideoSelect(file);
                }
            }
        });
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', resetVideoUpload);
    }
});
</script>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else:
    require_once 'footer.php';
endif; ?>
