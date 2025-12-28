<?php
require_once 'config.php';
require_login();

if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
    die('ID không hợp lệ.');
}
$id = (int)$_GET['id'];

$stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    die('Tin không tồn tại.');
}
if ($post['user_id'] != $_SESSION['user_id']) {
    die('Bạn không có quyền sửa tin này.');
}

$error = '';

// Ensure needed columns exist before any UPDATE operations
$columnsToCheck = [
    'contact_info' => "ALTER TABLE posts ADD COLUMN contact_info VARCHAR(255) NULL AFTER area",
    'student_fullname' => "ALTER TABLE posts ADD COLUMN student_fullname VARCHAR(150) NULL AFTER contact_info",
    'student_code' => "ALTER TABLE posts ADD COLUMN student_code VARCHAR(100) NULL AFTER student_fullname",
    'student_class' => "ALTER TABLE posts ADD COLUMN student_class VARCHAR(100) NULL AFTER student_code",
    'recruiter_fullname' => "ALTER TABLE posts ADD COLUMN recruiter_fullname VARCHAR(150) NULL AFTER student_class",
    'suggested_price' => "ALTER TABLE posts ADD COLUMN suggested_price INT NULL AFTER recruiter_fullname",
    'video_path' => "ALTER TABLE posts ADD COLUMN video_path VARCHAR(255) NULL",
    'card_image' => "ALTER TABLE posts ADD COLUMN card_image VARCHAR(255) NULL"
];

foreach ($columnsToCheck as $colName => $alterSql) {
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = ?");
        $chk->execute([$colName]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->exec($alterSql);
        }
    } catch (Exception $e) { /* ignore */ }
}

// Re-fetch post data after adding columns
$stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
$stmt->execute([$id]);
$post = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $area = trim($_POST['area'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $videoPath = $post['video_path'] ?? null;

    // Handle video upload/change
    if (!empty($_FILES['health_video']['name'])) {
        $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'];
        if ($_FILES['health_video']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Tải video thất bại. Vui lòng thử lại.';
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
                    // Delete old video if exists
                    if (!empty($post['video_path']) && file_exists(__DIR__ . '/' . $post['video_path'])) {
                        @unlink(__DIR__ . '/' . $post['video_path']);
                    }
                    $videoPath = 'uploads/health_videos/' . $filename;
                } else {
                    $error = 'Không thể lưu video. Vui lòng thử lại.';
                }
            }
        }
    }

    // Handle video removal
    if (isset($_POST['remove_video']) && $_POST['remove_video'] === '1') {
        if (!empty($post['video_path']) && file_exists(__DIR__ . '/' . $post['video_path'])) {
            @unlink(__DIR__ . '/' . $post['video_path']);
        }
        $videoPath = null;
    }

    if (!$error) {
        if ($post['type'] === 'recruitment') {
            $recruiterFullname = trim($_POST['recruiter_fullname'] ?? '');
            if (!$title || !$content || !$contact || !$recruiterFullname) {
                $error = 'Vui lòng điền đủ các trường bắt buộc.';
            } else {
                $u = $pdo->prepare('UPDATE posts SET title=?, content=?, area=?, category=?, contact_info=?, recruiter_fullname=?, video_path=? WHERE id=?');
                $u->execute([$title,$content,$area,$category,$contact,$recruiterFullname,$videoPath,$id]);
                header('Location: view_post.php?id='.$id);
                exit;
            }
        } else { // application
            $studentFullname = trim($_POST['student_fullname'] ?? $post['student_fullname']);
            $studentCode = trim($_POST['student_code'] ?? $post['student_code']);
            $studentClass = trim($_POST['student_class'] ?? $post['student_class']);
            $suggestedPrice = isset($_POST['suggested_price']) ? (int)preg_replace('/[^0-9]/','', $_POST['suggested_price']) : ($post['suggested_price'] ?? 0);
            if (!$title || !$content || !$contact || !$studentFullname || !$studentCode || !$studentClass) {
                $error = 'Vui lòng điền đủ các trường bắt buộc.';
            } else {
                $u = $pdo->prepare('UPDATE posts SET title=?, content=?, area=?, category=?, contact_info=?, student_fullname=?, student_code=?, student_class=?, suggested_price=?, video_path=? WHERE id=?');
                $u->execute([$title,$content,$area,$category,$contact,$studentFullname,$studentCode,$studentClass,max(0,(int)$suggestedPrice),$videoPath,$id]);
                header('Location: view_post.php?id='.$id);
                exit;
            }
        }
    }
}

require_once 'header.php';
?>

<style>
.edit-post-container {
    max-width: 900px;
    margin: 40px auto;
    padding: 0 20px;
}

.edit-post-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.8);
}

.edit-post-header {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    padding: 30px 35px;
    position: relative;
    overflow: hidden;
}

.edit-post-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
}

.edit-post-header::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
}

.edit-post-header h3 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.edit-post-header h3 i {
    font-size: 1.5rem;
    background: rgba(255, 255, 255, 0.2);
    padding: 12px;
    border-radius: 12px;
}

.edit-post-header p {
    color: rgba(255, 255, 255, 0.85);
    margin: 10px 0 0 0;
    font-size: 1rem;
    position: relative;
    z-index: 1;
}

.edit-post-body {
    padding: 35px;
}

.form-section {
    background: #fff;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 25px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.form-section:hover {
    box-shadow: 0 4px 20px rgba(37, 99, 235, 0.1);
    border-color: #2563eb;
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: #2563eb;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f1f5f9;
}

.section-title i {
    font-size: 1.1rem;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.edit-form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.edit-form-label i {
    color: #2563eb;
    font-size: 0.85rem;
}

.edit-form-label .required {
    color: #ef4444;
    font-weight: 700;
}

.edit-form-control {
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 18px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #fafbfc;
}

.edit-form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
    background: #fff;
    outline: none;
}

.edit-form-control::placeholder {
    color: #9ca3af;
}

textarea.edit-form-control {
    min-height: 140px;
    resize: vertical;
}

.input-icon-wrapper {
    position: relative;
}

.input-icon-wrapper i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 1rem;
    z-index: 2;
}

.input-icon-wrapper .edit-form-control {
    padding-left: 48px;
}

.btn-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    flex-wrap: wrap;
}

.btn-save {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
    color: #fff;
    padding: 14px 35px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(37, 99, 235, 0.5);
    color: #fff;
}

.btn-save i {
    font-size: 1.1rem;
}

.btn-cancel {
    background: #fff;
    border: 2px solid #e2e8f0;
    color: #64748b;
    padding: 14px 30px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
}

.btn-cancel:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
    padding: 12px 0;
    transition: all 0.3s ease;
    margin-top: 20px;
}

.back-link:hover {
    color: #1d4ed8;
    gap: 12px;
}

.back-link i {
    transition: transform 0.3s ease;
}

.back-link:hover i {
    transform: translateX(-4px);
}

.alert-edit {
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}

.alert-edit.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border: 1px solid #fecaca;
    color: #dc2626;
}

.alert-edit i {
    font-size: 1.2rem;
}

.post-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-top: 12px;
}

.post-type-badge.recruitment {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

.post-type-badge.application {
    background: rgba(59, 130, 246, 0.2);
    color: #2563eb;
}

/* Video Upload Styles */
.current-video-wrapper {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 2px solid #86efac;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
}

.current-video-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.video-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    color: #fff;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.btn-remove-current-video {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #fff;
    border: 2px solid #fecaca;
    color: #dc2626;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-remove-current-video:hover {
    background: #fef2f2;
    border-color: #f87171;
}

.current-video-player {
    border-radius: 12px;
    overflow: hidden;
}

.video-preview-player {
    width: 100%;
    max-height: 350px;
    border-radius: 12px;
    background: #000;
}

.change-video-hint {
    margin-top: 15px;
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 10px;
    font-size: 0.9rem;
    color: #059669;
    display: flex;
    align-items: center;
    gap: 8px;
}

.video-upload-zone {
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    padding: 40px 30px;
    text-align: center;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}

.video-upload-zone:hover {
    border-color: #2563eb;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
}

.video-upload-zone.dragover {
    border-color: #2563eb;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    transform: scale(1.01);
}

.video-file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

.upload-zone-icon {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.8rem;
    margin: 0 auto 15px;
    box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
}

.upload-zone-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
}

.upload-zone-text {
    font-size: 0.95rem;
    color: #64748b;
    margin-bottom: 15px;
}

.upload-zone-formats {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
}

.format-tag {
    padding: 5px 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 15px;
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 500;
}

.new-video-preview {
    background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
    border: 2px solid #a5b4fc;
    border-radius: 16px;
    padding: 20px;
    margin-top: 20px;
}

.new-video-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.new-video-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.new-video-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.2rem;
}

.new-video-name {
    font-weight: 600;
    color: #1e293b;
    word-break: break-all;
    max-width: 300px;
}

.new-video-size {
    font-size: 0.85rem;
    color: #64748b;
}

.btn-cancel-new-video {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #fff;
    border: 2px solid #e2e8f0;
    color: #64748b;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-cancel-new-video:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.new-video-player {
    width: 100%;
    max-height: 350px;
    border-radius: 12px;
    background: #000;
}

.video-tips {
    margin-top: 15px;
}

.video-tip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 10px;
    font-size: 0.9rem;
    color: #92400e;
}

.video-tip i {
    color: #f59e0b;
}

@media (max-width: 768px) {
    .edit-post-container {
        margin: 20px auto;
        padding: 0 15px;
    }
    
    .edit-post-header {
        padding: 25px;
    }
    
    .edit-post-body {
        padding: 25px;
    }
    
    .form-section {
        padding: 20px;
    }
    
    .btn-actions {
        flex-direction: column;
    }
    
    .btn-save, .btn-cancel {
        width: 100%;
        justify-content: center;
    }
    
    .current-video-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .new-video-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .new-video-name {
        max-width: 200px;
    }
}
</style>

<div class="edit-post-container">
    <div class="edit-post-card">
        <div class="edit-post-header">
            <h3><i class="fas fa-edit"></i> Chỉnh Sửa Tin</h3>
            <p>Cập nhật nội dung bài đăng của bạn</p>
            <span class="post-type-badge <?php echo $post['type']; ?>">
                <i class="fas fa-<?php echo $post['type'] === 'recruitment' ? 'briefcase' : 'user-graduate'; ?>"></i>
                <?php echo $post['type'] === 'recruitment' ? 'Tin tuyển dụng' : 'Tin ứng tuyển'; ?>
            </span>
        </div>
        
        <div class="edit-post-body">
            <?php if ($error): ?>
                <div class="alert-edit alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data">
                <!-- Thông tin người đăng -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i>
                        Thông tin người đăng
                    </div>
                    
                    <?php if ($post['type'] === 'recruitment'): ?>
                        <div class="mb-0">
                            <label class="edit-form-label">
                                <i class="fas fa-user"></i>
                                Họ và tên người liên hệ <span class="required">*</span>
                            </label>
                            <div class="input-icon-wrapper">
                                <i class="fas fa-id-card"></i>
                                <input type="text" name="recruiter_fullname" class="form-control edit-form-control" required 
                                    placeholder="Nhập họ và tên..."
                                    value="<?php echo htmlspecialchars($post['recruiter_fullname'] ?? ''); ?>">
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="edit-form-label">
                                    <i class="fas fa-user"></i>
                                    Họ và tên <span class="required">*</span>
                                </label>
                                <input type="text" name="student_fullname" class="form-control edit-form-control" required 
                                    placeholder="Nhập họ và tên..."
                                    value="<?php echo htmlspecialchars($post['student_fullname'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="edit-form-label">
                                    <i class="fas fa-id-badge"></i>
                                    MSSV <span class="required">*</span>
                                </label>
                                <input type="text" name="student_code" class="form-control edit-form-control" required 
                                    placeholder="Nhập MSSV..."
                                    value="<?php echo htmlspecialchars($post['student_code'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="edit-form-label">
                                    <i class="fas fa-users"></i>
                                    Mã lớp <span class="required">*</span>
                                </label>
                                <input type="text" name="student_class" class="form-control edit-form-control" required 
                                    placeholder="Nhập mã lớp..."
                                    value="<?php echo htmlspecialchars($post['student_class'] ?? ''); ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Nội dung bài đăng -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-file-alt"></i>
                        Nội dung bài đăng
                    </div>
                    
                    <div class="mb-3">
                        <label class="edit-form-label">
                            <i class="fas fa-heading"></i>
                            Tiêu đề <span class="required">*</span>
                        </label>
                        <input type="text" name="title" class="form-control edit-form-control" required 
                            placeholder="Nhập tiêu đề bài đăng..."
                            value="<?php echo htmlspecialchars($post['title']); ?>">
                    </div>
                    
                    <div class="mb-0">
                        <label class="edit-form-label">
                            <i class="fas fa-align-left"></i>
                            Nội dung chi tiết <span class="required">*</span>
                        </label>
                        <textarea name="content" class="form-control edit-form-control" rows="6" required 
                            placeholder="Mô tả chi tiết về công việc, yêu cầu, thời gian..."><?php echo htmlspecialchars($post['content']); ?></textarea>
                    </div>
                </div>
                
                <!-- Thông tin bổ sung -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i>
                        Thông tin bổ sung
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="edit-form-label">
                                <i class="fas fa-stethoscope"></i>
                                Chuyên khoa / Loại
                            </label>
                            <input type="text" name="category" class="form-control edit-form-control" 
                                placeholder="VD: Nội khoa, Ngoại khoa..."
                                value="<?php echo htmlspecialchars($post['category'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="edit-form-label">
                                <i class="fas fa-map-marker-alt"></i>
                                Khu vực
                            </label>
                            <input type="text" name="area" class="form-control edit-form-control" 
                                placeholder="VD: Quận 1, TP.HCM..."
                                value="<?php echo htmlspecialchars($post['area'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="edit-form-label">
                                <i class="fas fa-phone-alt"></i>
                                Thông tin liên hệ <span class="required">*</span>
                            </label>
                            <input type="text" name="contact" class="form-control edit-form-control" required 
                                placeholder="Số điện thoại hoặc email..."
                                value="<?php echo htmlspecialchars($post['contact_info'] ?? ''); ?>">
                        </div>
                        <?php if ($post['type'] === 'application'): ?>
                        <div class="col-md-6">
                            <label class="edit-form-label">
                                <i class="fas fa-money-bill-wave"></i>
                                Gợi ý giá (VNĐ)
                            </label>
                            <input type="number" min="0" step="1000" name="suggested_price" class="form-control edit-form-control" 
                                placeholder="Nhập mức giá mong muốn..."
                                value="<?php echo htmlspecialchars((string)($post['suggested_price'] ?? 0)); ?>">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Video Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-video"></i>
                        Video tình trạng sức khỏe
                    </div>
                    
                    <input type="hidden" name="remove_video" id="removeVideoFlag" value="0">
                    
                    <?php if (!empty($post['video_path']) && file_exists(__DIR__ . '/' . $post['video_path'])): ?>
                    <!-- Current Video -->
                    <div class="current-video-wrapper" id="currentVideoWrapper">
                        <div class="current-video-header">
                            <div class="video-status-badge">
                                <i class="fas fa-check-circle"></i>
                                Video hiện tại
                            </div>
                            <button type="button" class="btn-remove-current-video" id="removeCurrentVideoBtn" title="Xóa video">
                                <i class="fas fa-trash-alt"></i>
                                Xóa video
                            </button>
                        </div>
                        <div class="current-video-player">
                            <video controls class="video-preview-player">
                                <source src="<?php echo htmlspecialchars($post['video_path']); ?>" type="video/mp4">
                                Trình duyệt không hỗ trợ video.
                            </video>
                        </div>
                        <div class="change-video-hint">
                            <i class="fas fa-info-circle"></i>
                            Tải video mới bên dưới để thay thế video hiện tại
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Upload New Video -->
                    <div class="video-upload-zone" id="videoUploadZone">
                        <input type="file" name="health_video" accept="video/*" id="healthVideoInput" class="video-file-input">
                        <div class="upload-zone-content">
                            <div class="upload-zone-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="upload-zone-title">
                                <?php echo !empty($post['video_path']) ? 'Tải video mới để thay thế' : 'Tải lên video tình trạng sức khỏe'; ?>
                            </div>
                            <div class="upload-zone-text">Kéo thả hoặc nhấn để chọn video</div>
                            <div class="upload-zone-formats">
                                <span class="format-tag">MP4</span>
                                <span class="format-tag">WebM</span>
                                <span class="format-tag">MOV</span>
                                <span class="format-tag">AVI</span>
                                <span class="format-tag">Tối đa 50MB</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- New Video Preview -->
                    <div class="new-video-preview" id="newVideoPreview" style="display: none;">
                        <div class="new-video-header">
                            <div class="new-video-info">
                                <div class="new-video-icon">
                                    <i class="fas fa-film"></i>
                                </div>
                                <div class="new-video-details">
                                    <div class="new-video-name" id="newVideoName"></div>
                                    <div class="new-video-size" id="newVideoSize"></div>
                                </div>
                            </div>
                            <button type="button" class="btn-cancel-new-video" id="cancelNewVideoBtn">
                                <i class="fas fa-times"></i>
                                Hủy
                            </button>
                        </div>
                        <video controls class="new-video-player" id="newVideoPlayer"></video>
                    </div>
                    
                    <div class="video-tips">
                        <div class="video-tip">
                            <i class="fas fa-lightbulb"></i>
                            Video giúp sinh viên Y đánh giá chính xác tình trạng và chuẩn bị tốt hơn
                        </div>
                    </div>
                </div>
                
                <!-- Buttons -->
                <div class="btn-actions">
                    <button type="submit" class="btn btn-save">
                        <i class="fas fa-save"></i>
                        Lưu thay đổi
                    </button>
                    <a class="btn btn-cancel" href="view_post.php?id=<?php echo $id; ?>">
                        <i class="fas fa-times"></i>
                        Hủy bỏ
                    </a>
                </div>
            </form>
            
            <a href="<?php echo $_SESSION['role']==='patient'?'dashboard_patient.php':'dashboard_student.php'; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Quay lại Dashboard
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const videoInput = document.getElementById('healthVideoInput');
    const uploadZone = document.getElementById('videoUploadZone');
    const newVideoPreview = document.getElementById('newVideoPreview');
    const newVideoPlayer = document.getElementById('newVideoPlayer');
    const newVideoName = document.getElementById('newVideoName');
    const newVideoSize = document.getElementById('newVideoSize');
    const cancelNewVideoBtn = document.getElementById('cancelNewVideoBtn');
    const removeCurrentVideoBtn = document.getElementById('removeCurrentVideoBtn');
    const currentVideoWrapper = document.getElementById('currentVideoWrapper');
    const removeVideoFlag = document.getElementById('removeVideoFlag');

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function handleVideoSelect(file) {
        if (file && file.type.startsWith('video/')) {
            if (file.size > 50 * 1024 * 1024) {
                alert('Video tối đa 50MB. Vui lòng chọn video nhỏ hơn.');
                return;
            }
            
            newVideoName.textContent = file.name;
            newVideoSize.textContent = formatFileSize(file.size);
            
            const url = URL.createObjectURL(file);
            newVideoPlayer.src = url;
            
            if (uploadZone) uploadZone.style.display = 'none';
            newVideoPreview.style.display = 'block';
            
            // Reset remove flag when uploading new video
            if (removeVideoFlag) removeVideoFlag.value = '0';
        }
    }

    function resetNewVideo() {
        if (videoInput) videoInput.value = '';
        if (newVideoPlayer) newVideoPlayer.src = '';
        if (uploadZone) uploadZone.style.display = 'block';
        if (newVideoPreview) newVideoPreview.style.display = 'none';
    }

    if (videoInput) {
        videoInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                handleVideoSelect(this.files[0]);
            }
        });
    }

    if (uploadZone) {
        uploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                const file = e.dataTransfer.files[0];
                if (file.type.startsWith('video/')) {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    videoInput.files = dt.files;
                    handleVideoSelect(file);
                }
            }
        });
    }

    if (cancelNewVideoBtn) {
        cancelNewVideoBtn.addEventListener('click', resetNewVideo);
    }

    if (removeCurrentVideoBtn) {
        removeCurrentVideoBtn.addEventListener('click', function() {
            if (confirm('Bạn có chắc muốn xóa video này?')) {
                if (currentVideoWrapper) currentVideoWrapper.style.display = 'none';
                if (removeVideoFlag) removeVideoFlag.value = '1';
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
