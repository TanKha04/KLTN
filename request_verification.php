<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_login();

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

if (($_SESSION['role'] ?? '') !== 'student') {
    if ($isEmbed) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="padding:2rem;font-family:sans-serif;"><div style="background:#fee2e2;color:#991b1b;padding:1rem;border-radius:8px;">Chức năng chỉ dành cho sinh viên y khoa.</div></body></html>';
        exit;
    }
    die('Chức năng chỉ dành cho sinh viên y.');
}

refresh_student_verification_flag();

if (is_student_verified()) {
    if ($isEmbed) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="padding:2rem;font-family:sans-serif;"><div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;"><strong>✓ Tài khoản đã được xác minh!</strong><br>Bạn có thể đăng tin ngay bây giờ.</div></body></html>';
        exit;
    }
    header('Location: dashboard_student.php');
    exit;
}

// Ensure verification table has required columns
try {
    $columns = [
        'full_name' => "ALTER TABLE verifications ADD COLUMN full_name VARCHAR(150) NOT NULL AFTER user_id",
        'student_code' => "ALTER TABLE verifications ADD COLUMN student_code VARCHAR(100) NOT NULL AFTER full_name",
        'class_name' => "ALTER TABLE verifications ADD COLUMN class_name VARCHAR(100) DEFAULT NULL AFTER student_code",
        'address' => "ALTER TABLE verifications ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER class_name",
        'phone' => "ALTER TABLE verifications ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER address",
        'document_card' => "ALTER TABLE verifications ADD COLUMN document_card VARCHAR(255) DEFAULT NULL AFTER phone",
        'document_internship' => "ALTER TABLE verifications ADD COLUMN document_internship VARCHAR(255) DEFAULT NULL AFTER document_card",
        'admin_note' => "ALTER TABLE verifications ADD COLUMN admin_note TEXT DEFAULT NULL AFTER status",
        'processed_at' => "ALTER TABLE verifications ADD COLUMN processed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at"
    ];
    foreach ($columns as $column => $statement) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "verifications" AND COLUMN_NAME = ?');
        $check->execute([$column]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec($statement);
        }
    }
} catch (PDOException $e) {
    error_log('Verification table migrate failed: ' . $e->getMessage());
}

$userStmt = $pdo->prepare('SELECT name, email, student_id, location, phone FROM users WHERE id = ?');
$userStmt->execute([$_SESSION['user_id']]);
$userInfo = $userStmt->fetch();

$requestStmt = $pdo->prepare('SELECT * FROM verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
$requestStmt->execute([$_SESSION['user_id']]);
$latestRequest = $requestStmt->fetch();

$successMessage = '';
$errorMessage = '';

function handle_upload(string $fieldName, string $prefix): ?string {
    if (empty($_FILES[$fieldName]['name'])) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Tải tệp thất bại, vui lòng thử lại.');
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
    $detectedType = function_exists('mime_content_type') ? mime_content_type($_FILES[$fieldName]['tmp_name']) : ($_FILES[$fieldName]['type'] ?? null);

    if ($detectedType && !in_array($detectedType, $allowedTypes, true)) {
        throw new RuntimeException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WEBP.');
    }

    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Mỗi ảnh tối đa 5MB.');
    }

    $targetDir = __DIR__ . '/uploads/verification_docs';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION) ?: 'jpg');
    $fileName = $prefix . '_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
    $targetPath = $targetDir . '/' . $fileName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
        throw new RuntimeException('Không thể lưu tệp tải lên.');
    }

    return 'uploads/verification_docs/' . $fileName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? ($userInfo['name'] ?? ''));
    $studentCode = trim($_POST['student_code'] ?? ($userInfo['student_id'] ?? ''));
    $className = trim($_POST['class_name'] ?? '');
    $address = trim($_POST['address'] ?? ($userInfo['location'] ?? ''));
    $phone = trim($_POST['phone'] ?? ($userInfo['phone'] ?? ''));

    try {
        if (!$fullName || !$studentCode || !$className || !$address || !$phone) {
            throw new RuntimeException('Vui lòng điền đầy đủ thông tin bắt buộc.');
        }

        $cardPath = handle_upload('document_card', 'card');
        $internPath = handle_upload('document_internship', 'internship');

        if (!$cardPath && !$latestRequest) {
            throw new RuntimeException('Vui lòng tải ảnh thẻ sinh viên.');
        }

        if ($latestRequest && $latestRequest['status'] === 'pending') {
            $stmtUpdate = $pdo->prepare('UPDATE verifications SET full_name = ?, student_code = ?, class_name = ?, address = ?, phone = ?, document_card = COALESCE(?, document_card), document_internship = COALESCE(?, document_internship), created_at = NOW(), admin_note = NULL WHERE id = ?');
            $stmtUpdate->execute([
                $fullName,
                $studentCode,
                $className,
                $address,
                $phone,
                $cardPath,
                $internPath,
                $latestRequest['id']
            ]);
            $requestId = $latestRequest['id'];
        } else {
            $stmtInsert = $pdo->prepare('INSERT INTO verifications (user_id, full_name, student_code, class_name, address, phone, document_card, document_internship, document_type, document_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "student_card", "", "pending")');
            $stmtInsert->execute([
                $_SESSION['user_id'],
                $fullName,
                $studentCode,
                $className,
                $address,
                $phone,
                $cardPath,
                $internPath
            ]);
            $requestId = $pdo->lastInsertId();
        }

        // Update user contact info to keep consistency
        $stmtUserUpdate = $pdo->prepare('UPDATE users SET name = ?, student_id = ?, location = ?, phone = ? WHERE id = ?');
        $stmtUserUpdate->execute([$fullName, $studentCode, $address, $phone, $_SESSION['user_id']]);

        $requestStmt->execute([$_SESSION['user_id']]);
        $latestRequest = $requestStmt->fetch();

        $successMessage = 'Đã gửi yêu cầu thành công! Quản trị viên sẽ xem xét và phản hồi sớm.';
    } catch (RuntimeException $e) {
        $errorMessage = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Submit verification failed: ' . $e->getMessage());
        $errorMessage = 'Có lỗi xảy ra khi lưu yêu cầu. Vui lòng thử lại sau.';
    }
}

if ($isEmbed): ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xác minh sinh viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>body{background:#f1f5f9;margin:0;padding:0;}</style>
</head>
<body>
<?php else:
    require_once 'header.php';
endif; ?>

<style>
.verification-page {
    min-height: <?php echo $isEmbed ? 'auto' : '100vh'; ?> !important;
    background: <?php echo $isEmbed ? 'transparent' : 'linear-gradient(135deg, #1e88e5 0%, #1565c0 100%)'; ?> !important;
    padding: <?php echo $isEmbed ? '1.5rem' : '40px 20px'; ?> !important;
}
.verification-container {
    max-width: 800px;
    margin: 0 auto;
}
.verification-header {
    text-align: center;
    margin-bottom: 30px;
    color: <?php echo $isEmbed ? '#1e293b' : '#fff'; ?>;
}
.verification-header .icon-circle {
    width: 80px;
    height: 80px;
    background: <?php echo $isEmbed ? 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)' : 'rgba(255,255,255,0.2)'; ?>;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    backdrop-filter: blur(10px);
    box-shadow: <?php echo $isEmbed ? '0 10px 30px rgba(59, 130, 246, 0.3)' : 'none'; ?>;
}
.verification-header .icon-circle i {
    font-size: 36px;
    color: #fff;
}
.verification-header h2 {
    font-weight: 700;
    margin-bottom: 10px;
}
.verification-header p {
    opacity: <?php echo $isEmbed ? '1' : '0.9'; ?>;
    font-size: 1.1rem;
    color: <?php echo $isEmbed ? '#64748b' : 'inherit'; ?>;
}
.verification-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,<?php echo $isEmbed ? '0.08' : '0.15'; ?>);
    overflow: hidden;
    border: <?php echo $isEmbed ? '1px solid #e2e8f0' : 'none'; ?>;
}
.verification-card .card-body {
    padding: 40px;
}
.form-section {
    margin-bottom: 30px;
}
.form-section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f0f0f0;
}
.form-section-title .section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 18px;
}
.form-section-title h5 {
    margin: 0;
    font-weight: 600;
    color: #333;
}
.form-floating-custom {
    position: relative;
    margin-bottom: 20px;
}
.form-floating-custom label {
    font-weight: 500;
    color: #555;
    margin-bottom: 8px;
    display: block;
}
.form-floating-custom label .required {
    color: #e74c3c;
}
.form-floating-custom .form-control {
    border: 2px solid #e8e8e8;
    border-radius: 12px;
    padding: 14px 18px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #fafafa;
}
.form-floating-custom .form-control:focus {
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}
.upload-zone {
    border: 2px dashed #d0d0d0;
    border-radius: 16px;
    padding: 30px;
    text-align: center;
    background: #fafafa;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
}
.upload-zone:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}
.upload-zone.has-file {
    border-color: #10b981;
    background: #f0fdf4;
}
.upload-zone .upload-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    color: #fff;
    font-size: 24px;
}
.upload-zone h6 {
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
}
.upload-zone p {
    color: #888;
    font-size: 0.9rem;
    margin: 0;
}
.upload-zone input[type="file"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}
.upload-preview {
    margin-top: 15px;
    padding: 12px 16px;
    background: #dcfce7;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.upload-preview i {
    color: #10b981;
    font-size: 20px;
}
.upload-preview span {
    color: #166534;
    font-weight: 500;
}
.upload-preview a {
    margin-left: auto;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
}
.alert-custom {
    border-radius: 12px;
    padding: 16px 20px;
    border: none;
    display: flex;
    align-items: flex-start;
    gap: 15px;
}
.alert-custom i {
    font-size: 24px;
    margin-top: 2px;
}
.alert-custom.alert-success-custom {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
}
.alert-custom.alert-danger-custom {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
}
.alert-custom.alert-warning-custom {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
}
.btn-submit {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    padding: 16px 40px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 12px;
    color: #fff;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
    color: #fff;
}
.btn-back {
    background: #f1f5f9;
    border: none;
    padding: 16px 30px;
    font-size: 1.1rem;
    font-weight: 500;
    border-radius: 12px;
    color: #64748b;
    transition: all 0.3s ease;
}
.btn-back:hover {
    background: #e2e8f0;
    color: #475569;
}
.steps-indicator {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 30px;
}
.step-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: <?php echo $isEmbed ? '#94a3b8' : 'rgba(255,255,255,0.7)'; ?>;
}
.step-item.active {
    color: <?php echo $isEmbed ? '#1e293b' : '#fff'; ?>;
}
.step-item .step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: <?php echo $isEmbed ? '#e2e8f0' : 'rgba(255,255,255,0.2)'; ?>;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}
.step-item.active .step-number {
    background: <?php echo $isEmbed ? 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)' : '#fff'; ?>;
    color: <?php echo $isEmbed ? '#fff' : '#1e88e5'; ?>;
}
.step-item.completed .step-number {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
}
@media (max-width: 768px) {
    .verification-card .card-body {
        padding: 25px;
    }
    .step-item span {
        display: none;
    }
}
</style>

<div class="verification-page">
    <div class="verification-container">
        <div class="verification-header">
            <div class="icon-circle">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <h2>Xác minh tài khoản sinh viên</h2>
            <p>Hoàn tất xác minh để được đăng tin và hưởng đầy đủ quyền lợi</p>
        </div>

        <div class="steps-indicator">
            <div class="step-item <?php echo (!$latestRequest || $latestRequest['status'] === 'rejected') ? 'active' : 'completed'; ?>">
                <div class="step-number"><?php echo (!$latestRequest || $latestRequest['status'] === 'rejected') ? '1' : '<i class="bi bi-check"></i>'; ?></div>
                <span>Điền thông tin</span>
            </div>
            <div class="step-item <?php echo ($latestRequest && $latestRequest['status'] === 'pending') ? 'active' : ''; ?>">
                <div class="step-number">2</div>
                <span>Chờ duyệt</span>
            </div>
            <div class="step-item">
                <div class="step-number">3</div>
                <span>Hoàn tất</span>
            </div>
        </div>

        <div class="verification-card">
            <div class="card-body">

                <?php if ($successMessage): ?>
                    <div class="alert-custom alert-success-custom mb-4">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>
                            <strong>Thành công!</strong><br>
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="alert-custom alert-danger-custom mb-4">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <div>
                            <strong>Có lỗi xảy ra!</strong><br>
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($latestRequest && $latestRequest['status'] === 'pending'): ?>
                    <div class="alert-custom alert-warning-custom mb-4">
                        <i class="bi bi-hourglass-split"></i>
                        <div>
                            <strong>Đơn đang chờ duyệt</strong><br>
                            Gửi ngày <?php echo date('d/m/Y H:i', strtotime($latestRequest['created_at'])); ?>. Bạn có thể cập nhật lại thông tin nếu cần.
                        </div>
                    </div>
                <?php elseif ($latestRequest && $latestRequest['status'] === 'rejected'): ?>
                    <div class="alert-custom alert-danger-custom mb-4">
                        <i class="bi bi-x-circle-fill"></i>
                        <div>
                            <strong>Đơn đã bị từ chối</strong><br>
                            <?php if (!empty($latestRequest['admin_note'])): ?>
                                Lý do: <?php echo nl2br(htmlspecialchars($latestRequest['admin_note'])); ?>
                            <?php else: ?>
                                Vui lòng kiểm tra lại thông tin và gửi lại.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="form-section">
                        <div class="form-section-title">
                            <div class="section-icon">
                                <i class="bi bi-person-fill"></i>
                            </div>
                            <h5>Thông tin cá nhân</h5>
                        </div>

                        <div class="form-floating-custom">
                            <label>Họ và tên <span class="required">*</span></label>
                            <input type="text" name="full_name" class="form-control" required 
                                placeholder="Nhập họ và tên đầy đủ"
                                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ($latestRequest['full_name'] ?? ($userInfo['name'] ?? ''))); ?>">
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-floating-custom">
                                    <label>Mã số sinh viên <span class="required">*</span></label>
                                    <input type="text" name="student_code" class="form-control" required 
                                        placeholder="VD: 2021001234"
                                        value="<?php echo htmlspecialchars($_POST['student_code'] ?? ($latestRequest['student_code'] ?? ($userInfo['student_id'] ?? ''))); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating-custom">
                                    <label>Mã lớp <span class="required">*</span></label>
                                    <input type="text" name="class_name" class="form-control" required 
                                        placeholder="VD: CNTT01"
                                        value="<?php echo htmlspecialchars($_POST['class_name'] ?? ($latestRequest['class_name'] ?? '')); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-floating-custom">
                            <label>Địa chỉ <span class="required">*</span></label>
                            <input type="text" name="address" class="form-control" required 
                                placeholder="Nhập địa chỉ hiện tại"
                                value="<?php echo htmlspecialchars($_POST['address'] ?? ($latestRequest['address'] ?? ($userInfo['location'] ?? ''))); ?>">
                        </div>

                        <div class="form-floating-custom">
                            <label>Số điện thoại <span class="required">*</span></label>
                            <input type="text" name="phone" class="form-control" required 
                                placeholder="VD: 0901234567"
                                value="<?php echo htmlspecialchars($_POST['phone'] ?? ($latestRequest['phone'] ?? ($userInfo['phone'] ?? ''))); ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <div class="section-icon">
                                <i class="bi bi-file-earmark-image-fill"></i>
                            </div>
                            <h5>Giấy tờ xác minh</h5>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="upload-zone <?php echo ($latestRequest && $latestRequest['document_card']) ? 'has-file' : ''; ?>" id="cardUploadZone">
                                    <div class="upload-icon">
                                        <i class="bi bi-person-vcard-fill"></i>
                                    </div>
                                    <h6>Ảnh thẻ sinh viên <span class="text-danger">*</span></h6>
                                    <p>Kéo thả hoặc click để chọn ảnh</p>
                                    <input type="file" name="document_card" id="documentCard" 
                                        <?php echo ($latestRequest && $latestRequest['document_card']) ? '' : 'required'; ?> 
                                        accept="image/*">
                                </div>
                                <?php if ($latestRequest && $latestRequest['document_card']): ?>
                                    <div class="upload-preview">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>Đã tải lên</span>
                                        <?php if (upload_exists($latestRequest['document_card'])): ?>
                                            <a href="<?php echo htmlspecialchars(public_url_for($latestRequest['document_card'])); ?>" target="_blank">Xem ảnh</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <div class="upload-zone <?php echo ($latestRequest && $latestRequest['document_internship']) ? 'has-file' : ''; ?>" id="internshipUploadZone">
                                    <div class="upload-icon">
                                        <i class="bi bi-file-earmark-text-fill"></i>
                                    </div>
                                    <h6>Giấy xác nhận thực tập</h6>
                                    <p>Không bắt buộc</p>
                                    <input type="file" name="document_internship" id="documentInternship" accept="image/*">
                                </div>
                                <?php if ($latestRequest && $latestRequest['document_internship']): ?>
                                    <div class="upload-preview">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>Đã tải lên</span>
                                        <a href="<?php echo htmlspecialchars($latestRequest['document_internship']); ?>" target="_blank">Xem ảnh</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-3 justify-content-center mt-4">
                        <?php if (!$isEmbed): ?>
                        <a href="dashboard_student.php" class="btn btn-back">
                            <i class="bi bi-arrow-left me-2"></i>Quay lại
                        </a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-submit">
                            <i class="bi bi-send-fill me-2"></i>Gửi yêu cầu xác minh
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cardInput = document.getElementById('documentCard');
    const internshipInput = document.getElementById('documentInternship');
    const cardZone = document.getElementById('cardUploadZone');
    const internshipZone = document.getElementById('internshipUploadZone');

    function handleFileSelect(input, zone) {
        input.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                zone.classList.add('has-file');
                const p = zone.querySelector('p');
                p.textContent = this.files[0].name;
            }
        });
    }

    handleFileSelect(cardInput, cardZone);
    handleFileSelect(internshipInput, internshipZone);

    document.querySelectorAll('.upload-zone').forEach(zone => {
        zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = '#1e88e5';
            this.style.background = '#e3f2fd';
        });
        zone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            if (!this.classList.contains('has-file')) {
                this.style.borderColor = '#d0d0d0';
                this.style.background = '#fafafa';
            }
        });
    });
});
</script>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else:
    require_once 'footer.php';
endif; ?>
