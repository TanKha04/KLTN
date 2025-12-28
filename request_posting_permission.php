<?php
require_once 'config.php';
require_login();

// only students can request posting permission
if (($_SESSION['role'] ?? '') !== 'student') {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// If user already has permission
$stmt = $pdo->prepare('SELECT can_post FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$canPost = (int)$stmt->fetchColumn();
if ($canPost) {
    $success = 'Bạn đã được cấp quyền đăng tin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canPost) {
    $full_name = trim($_POST['full_name'] ?? '');
    $student_code = trim($_POST['student_code'] ?? '');
    $class_name = trim($_POST['class_name'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($full_name === '' || $student_code === '') {
        $error = 'Vui lòng điền họ tên và mã số sinh viên.';
    } else {
        // handle uploads
        $uploadDir = __DIR__ . '/uploads/verification_docs/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $cardPath = null;
        if (!empty($_FILES['document_card']['name'])) {
            $tmp = $_FILES['document_card']['tmp_name'];
            $name = basename($_FILES['document_card']['name']);
            $dest = $uploadDir . time() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
            if (move_uploaded_file($tmp, $dest)) {
                $cardPath = basename($dest);
            }
        }

        $internPath = null;
        if (!empty($_FILES['document_internship']['name'])) {
            $tmp = $_FILES['document_internship']['tmp_name'];
            $name = basename($_FILES['document_internship']['name']);
            $dest = $uploadDir . time() . '_int_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
            if (move_uploaded_file($tmp, $dest)) {
                $internPath = basename($dest);
            }
        }

        $stmt = $pdo->prepare('INSERT INTO posting_requests (user_id, full_name, student_code, class_name, address, document_card, document_internship) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$_SESSION['user_id'], $full_name, $student_code, $class_name ?: null, $address ?: null, $cardPath, $internPath]);
        $success = 'Yêu cầu đã gửi. Quản trị viên sẽ kiểm tra và cấp quyền nếu hợp lệ.';
    }
}

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
    echo '</div><!-- Close container from header -->';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Yêu cầu quyền đăng tin</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;}</style>';
    echo '</head><body>';
}
?>

<style>
.posting-request-page {
    min-height: calc(100vh - 200px);
    padding: 2rem 1rem;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 50%, #06b6d4 100%);
}

.posting-request-container {
    max-width: 700px;
    margin: 0 auto;
}

.posting-request-card {
    background: #fff;
    border-radius: 28px;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    animation: cardSlideIn 0.5s ease-out;
}

@keyframes cardSlideIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.posting-request-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    padding: 2.5rem 2rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.posting-request-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 70%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 70% 30%, rgba(6, 182, 212, 0.15) 0%, transparent 50%);
    pointer-events: none;
}

.header-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.25rem;
    font-size: 2.2rem;
    color: #fff;
    box-shadow: 0 10px 30px rgba(59, 130, 246, 0.4);
    position: relative;
    z-index: 1;
}

.posting-request-header h2 {
    color: #fff;
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
    position: relative;
    z-index: 1;
}

.posting-request-header p {
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.95rem;
    margin: 0;
    position: relative;
    z-index: 1;
}

.posting-request-body {
    padding: 2rem;
}

.pr-alert {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem;
    border-radius: 16px;
    margin-bottom: 1.5rem;
    animation: alertPop 0.4s ease-out;
}

@keyframes alertPop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.pr-alert.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 1px solid #6ee7b7;
}

.pr-alert.error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
}

.pr-alert-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #fff;
    flex-shrink: 0;
}

.pr-alert.success .pr-alert-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.pr-alert.error .pr-alert-icon {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.pr-alert-content h4 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
}

.pr-alert.success .pr-alert-content h4 { color: #065f46; }
.pr-alert.error .pr-alert-content h4 { color: #991b1b; }

.pr-alert-content p {
    font-size: 0.9rem;
    margin: 0;
}

.pr-alert.success .pr-alert-content p { color: #047857; }
.pr-alert.error .pr-alert-content p { color: #b91c1c; }

.pr-form-section {
    margin-bottom: 2rem;
}

.pr-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e2e8f0;
}

.pr-section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1rem;
}

.pr-section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.pr-form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
}

@media (max-width: 640px) {
    .pr-form-row { grid-template-columns: 1fr; }
}

.pr-form-field {
    margin-bottom: 1.25rem;
}

.pr-form-field label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.6rem;
    font-size: 0.95rem;
}

.pr-form-field label i {
    color: #3b82f6;
    font-size: 0.9rem;
}

.pr-form-field label .required {
    color: #ef4444;
    font-weight: 700;
}

.pr-form-field input[type="text"],
.pr-form-field input[type="tel"] {
    width: 100%;
    padding: 1rem 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #f8fafc;
}

.pr-form-field input:focus {
    outline: none;
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

/* File Upload Styling */
.pr-file-upload {
    position: relative;
}

.pr-file-upload-area {
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    background: #f8fafc;
    transition: all 0.3s ease;
    cursor: pointer;
}

.pr-file-upload-area:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}

.pr-file-upload-area.dragover {
    border-color: #3b82f6;
    background: #dbeafe;
}

.pr-file-upload-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 1.5rem;
    color: #3b82f6;
}

.pr-file-upload-text h5 {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 0.25rem;
}

.pr-file-upload-text p {
    font-size: 0.85rem;
    color: #64748b;
    margin: 0;
}

.pr-file-upload input[type="file"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.pr-file-preview {
    display: none;
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    background: #ecfdf5;
    border-radius: 10px;
    border: 1px solid #a7f3d0;
}

.pr-file-preview.show {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.pr-file-preview i {
    color: #10b981;
    font-size: 1.1rem;
}

.pr-file-preview span {
    font-size: 0.9rem;
    color: #065f46;
    font-weight: 500;
}

/* Submit Button */
.pr-submit-section {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 2px solid #e2e8f0;
    display: flex;
    gap: 1rem;
}

.btn-pr-submit {
    flex: 1;
    padding: 1.15rem 2rem;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
}

.btn-pr-submit:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 40px rgba(59, 130, 246, 0.4);
}

.btn-pr-back {
    padding: 1.15rem 1.5rem;
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-pr-back:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* Success State */
.pr-success-state {
    text-align: center;
    padding: 2rem;
}

.pr-success-icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2.5rem;
    color: #fff;
    box-shadow: 0 15px 40px rgba(16, 185, 129, 0.3);
    animation: successPop 0.5s ease-out;
}

@keyframes successPop {
    0% { transform: scale(0); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.pr-success-state h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #065f46;
    margin: 0 0 0.75rem;
}

.pr-success-state p {
    font-size: 1rem;
    color: #047857;
    margin: 0 0 1.5rem;
}
</style>

<div class="posting-request-page">
    <div class="posting-request-container">
        <div class="posting-request-card">
            <div class="posting-request-header">
                <div class="header-icon">
                    <i class="fas fa-file-signature"></i>
                </div>
                <h2>Xin cấp quyền đăng tin</h2>
                <p>Vui lòng cung cấp thông tin và giấy tờ để xác thực tài khoản sinh viên</p>
            </div>
            
            <div class="posting-request-body">
                <?php if ($error): ?>
                    <div class="pr-alert error">
                        <div class="pr-alert-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="pr-alert-content">
                            <h4>Có lỗi xảy ra</h4>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success && $canPost): ?>
                    <div class="pr-success-state">
                        <div class="pr-success-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <h3>Đã được cấp quyền!</h3>
                        <p>Bạn đã có quyền đăng tin. Hãy bắt đầu tạo tin ứng tuyển ngay.</p>
                        <a href="create_application.php" class="btn-pr-submit" style="display: inline-flex; text-decoration: none;">
                            <i class="fas fa-plus-circle"></i> Tạo tin ứng tuyển
                        </a>
                    </div>
                <?php elseif ($success): ?>
                    <div class="pr-alert success">
                        <div class="pr-alert-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="pr-alert-content">
                            <h4>Gửi yêu cầu thành công!</h4>
                            <p><?php echo htmlspecialchars($success); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$canPost && !$success): ?>
                <form method="post" enctype="multipart/form-data">
                    <!-- Thông tin cá nhân -->
                    <div class="pr-form-section">
                        <div class="pr-section-header">
                            <div class="pr-section-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <h3 class="pr-section-title">Thông tin cá nhân</h3>
                        </div>
                        
                        <div class="pr-form-field">
                            <label>
                                <i class="fas fa-id-card"></i> Họ và tên <span class="required">*</span>
                            </label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($_SESSION['name'] ?? ''); ?>" required placeholder="Nhập họ và tên đầy đủ">
                        </div>
                        
                        <div class="pr-form-row">
                            <div class="pr-form-field">
                                <label>
                                    <i class="fas fa-hashtag"></i> Mã số sinh viên <span class="required">*</span>
                                </label>
                                <input type="text" name="student_code" required placeholder="VD: 110122087">
                            </div>
                            <div class="pr-form-field">
                                <label>
                                    <i class="fas fa-users"></i> Mã lớp
                                </label>
                                <input type="text" name="class_name" placeholder="VD: DA22TTA">
                            </div>
                        </div>
                        
                        <div class="pr-form-field">
                            <label>
                                <i class="fas fa-map-marker-alt"></i> Địa chỉ
                            </label>
                            <input type="text" name="address" placeholder="Nhập địa chỉ hiện tại">
                        </div>
                        
                        <div class="pr-form-field">
                            <label>
                                <i class="fas fa-phone"></i> Số điện thoại
                            </label>
                            <input type="tel" name="phone" placeholder="VD: 0901234567">
                        </div>
                    </div>

                    <!-- Giấy tờ xác thực -->
                    <div class="pr-form-section">
                        <div class="pr-section-header">
                            <div class="pr-section-icon">
                                <i class="fas fa-file-image"></i>
                            </div>
                            <h3 class="pr-section-title">Giấy tờ xác thực</h3>
                        </div>
                        
                        <div class="pr-form-field">
                            <label>
                                <i class="fas fa-id-badge"></i> Ảnh thẻ sinh viên <span class="required">*</span>
                            </label>
                            <div class="pr-file-upload">
                                <div class="pr-file-upload-area" id="cardUploadArea">
                                    <div class="pr-file-upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="pr-file-upload-text">
                                        <h5>Kéo thả hoặc nhấn để chọn ảnh</h5>
                                        <p>Hỗ trợ: JPG, PNG, WEBP (Tối đa 5MB)</p>
                                    </div>
                                    <input type="file" name="document_card" accept="image/*" required id="cardInput">
                                </div>
                                <div class="pr-file-preview" id="cardPreview">
                                    <i class="fas fa-check-circle"></i>
                                    <span id="cardFileName"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="pr-form-field">
                            <label>
                                <i class="fas fa-certificate"></i> Giấy xác nhận thực tập <span style="color: #64748b; font-weight: 400;">(không bắt buộc)</span>
                            </label>
                            <div class="pr-file-upload">
                                <div class="pr-file-upload-area" id="internUploadArea">
                                    <div class="pr-file-upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="pr-file-upload-text">
                                        <h5>Kéo thả hoặc nhấn để chọn ảnh</h5>
                                        <p>Hỗ trợ: JPG, PNG, WEBP (Tối đa 5MB)</p>
                                    </div>
                                    <input type="file" name="document_internship" accept="image/*" id="internInput">
                                </div>
                                <div class="pr-file-preview" id="internPreview">
                                    <i class="fas fa-check-circle"></i>
                                    <span id="internFileName"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pr-submit-section">
                        <a href="dashboard_student.php" class="btn-pr-back">
                            <i class="fas fa-arrow-left"></i> Quay lại
                        </a>
                        <button type="submit" class="btn-pr-submit">
                            <i class="fas fa-paper-plane"></i> Gửi yêu cầu
                        </button>
                    </div>
                </form>

                <script>
                // File upload preview
                function setupFileUpload(inputId, previewId, fileNameId, areaId) {
                    const input = document.getElementById(inputId);
                    const preview = document.getElementById(previewId);
                    const fileName = document.getElementById(fileNameId);
                    const area = document.getElementById(areaId);
                    
                    input.addEventListener('change', function() {
                        if (this.files && this.files[0]) {
                            fileName.textContent = this.files[0].name;
                            preview.classList.add('show');
                        } else {
                            preview.classList.remove('show');
                        }
                    });
                    
                    // Drag and drop
                    area.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        this.classList.add('dragover');
                    });
                    
                    area.addEventListener('dragleave', function() {
                        this.classList.remove('dragover');
                    });
                    
                    area.addEventListener('drop', function(e) {
                        e.preventDefault();
                        this.classList.remove('dragover');
                        if (e.dataTransfer.files.length) {
                            input.files = e.dataTransfer.files;
                            input.dispatchEvent(new Event('change'));
                        }
                    });
                }
                
                setupFileUpload('cardInput', 'cardPreview', 'cardFileName', 'cardUploadArea');
                setupFileUpload('internInput', 'internPreview', 'internFileName', 'internUploadArea');
                </script>
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
<div class="container"><!-- Reopen container for footer -->
<?php require_once 'footer.php'; ?>
<?php endif; ?>
