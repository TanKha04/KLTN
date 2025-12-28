<?php
require_once 'config.php';
require_login();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch current user data
$stmt = $pdo->prepare("SELECT name, username, email, role, bio, location, phone, school, class_code, student_id, avatar FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Không tìm thấy người dùng.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    // handle avatar upload
    $avatarPath = $user['avatar'] ?? null;
    if (!empty($_FILES['avatar']['name'])) {
        $dir = __DIR__ . '/uploads/avatars';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed)) {
            $error = 'Ảnh đại diện phải là JPG, PNG hoặc WEBP.';
        } elseif (($_FILES['avatar']['size'] ?? 0) > 3*1024*1024) {
            $error = 'Ảnh đại diện tối đa 3MB.';
        } elseif (empty($error)) {
            $safeName = 'u' . $user_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dir . '/' . $safeName)) {
                $avatarPath = 'uploads/avatars/' . $safeName;
            } else {
                $error = 'Tải ảnh không thành công.';
            }
        }
    }

    // Basic validation
    if (empty($name)) {
        $error = 'Họ và tên không được để trống.';
    } else {
        // Update query parts
        $update_fields = [
            'name' => $name,
            'bio' => $bio,
            'location' => $location,
            'phone' => $phone,
        ];
    $params = [$name, $bio, $location, $phone];
    $update_fields['avatar'] = $avatarPath;
    $params[] = $avatarPath;

        if ($user['role'] === 'student') {
            $school = trim($_POST['school'] ?? '');
            $class_code = trim($_POST['class_code'] ?? '');
            $update_fields['school'] = $school;
            $params[] = $school;
            $update_fields['class_code'] = $class_code;
            $params[] = $class_code;
            $update_fields['student_id'] = $student_id;
            $params[] = $student_id;
        }

        // Handle password change
        if (!empty($password)) {
            if ($password !== $password_confirm) {
                $error = 'Mật khẩu xác nhận không khớp.';
            } elseif (strlen($password) < 6) {
                $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_fields['password'] = $hashed_password;
                $params[] = $hashed_password;
            }
        }

        // If no validation errors, proceed with update
        if (empty($error)) {
            $sql_parts = [];
            foreach ($update_fields as $field => $value) {
                $sql_parts[] = "`$field` = ?";
            }
            $sql = "UPDATE users SET " . implode(', ', $sql_parts) . " WHERE id = ?";
            $params[] = $user_id;

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $success = 'Hồ sơ của bạn đã được cập nhật thành công!';
                // Re-fetch user data to display updated info
                $stmt = $pdo->prepare("SELECT name, username, email, role, bio, location, phone, school, class_code, student_id, avatar FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                // Update session name if changed
                $_SESSION['name'] = $name;
            } catch (PDOException $e) {
                $error = 'Đã xảy ra lỗi khi cập nhật hồ sơ. Vui lòng thử lại.';
                error_log('Profile update error: ' . $e->getMessage());
            }
        }
    }
}

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';
if (!$isEmbed) {
    require_once 'header.php';
} else {
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Hồ sơ cá nhân</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">';
    echo '<style>body{background:#f1f5f9;margin:0;padding:0;} .premium-navbar,.navbar{display:none!important;} .back-link{display:none!important;}</style>';
    echo '</head><body>';
}
?>

<style>
/* Ẩn navbar trên trang hồ sơ */
.premium-navbar, nav.navbar { display: none !important; }
body { padding-top: 0 !important; }
.container.py-4, .dashboard-container { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }

.edit-profile-page {
    min-height: 100vh;
    padding: 1rem;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
}

.edit-profile-container {
    max-width: 700px;
    margin: 0 auto;
}

.edit-profile-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.12);
    overflow: hidden;
    animation: profileCardIn 0.4s ease-out;
}

@keyframes profileCardIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.edit-profile-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    padding: 1.5rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.edit-profile-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 30% 70%, rgba(11, 63, 145, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 70% 30%, rgba(59, 130, 246, 0.15) 0%, transparent 50%);
    pointer-events: none;
}

.avatar-upload-section {
    position: relative;
    z-index: 1;
}

.avatar-preview-wrapper {
    position: relative;
    width: 90px;
    height: 90px;
    margin: 0 auto 0.75rem;
}

.avatar-preview {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.3);
    object-fit: cover;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
}

.avatar-placeholder {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.3);
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #fff;
    font-weight: 700;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
}

.avatar-upload-btn {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 30px;
    height: 30px;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    border: 2px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    font-size: 0.8rem;
}

.avatar-upload-btn:hover {
    transform: scale(1.1);
}

.avatar-upload-btn input[type="file"] {
    position: absolute;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.avatar-hint {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.75rem;
    margin: 0;
}

.edit-profile-body {
    padding: 1.5rem;
}

/* Alert Styles */
.profile-alert {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem;
    border-radius: 12px;
    margin-bottom: 1.25rem;
    animation: alertPop 0.4s ease-out;
}

@keyframes alertPop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.profile-alert.success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 1px solid #6ee7b7;
}

.profile-alert.error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
}

.profile-alert .alert-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #fff;
    flex-shrink: 0;
}

.profile-alert.success .alert-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.profile-alert.error .alert-icon {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.profile-alert.success .alert-text { color: #065f46; }
.profile-alert.error .alert-text { color: #991b1b; }

/* Section Styles */
.form-section {
    margin-bottom: 1.25rem;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e2e8f0;
}

.section-icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1rem;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

/* Form Fields */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

@media (max-width: 640px) {
    .form-row { grid-template-columns: 1fr; }
}

.form-field {
    margin-bottom: 1rem;
}

.form-field label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
}

.form-field label i {
    color: #3b82f6;
    font-size: 0.85rem;
}

.form-field .input-wrapper {
    position: relative;
}

.form-field input,
.form-field textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    background: #f8fafc;
}

.form-field input:focus,
.form-field textarea:focus {
    outline: none;
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-field input:disabled {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}

.form-field textarea {
    resize: vertical;
    min-height: 80px;
}

.form-field .field-hint {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: #94a3b8;
}

.form-field .field-hint i {
    color: #f59e0b;
}

.form-field .field-hint.locked i {
    color: #94a3b8;
}

/* Password Section */
.password-section {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 14px;
    padding: 1rem;
    margin-top: 0.5rem;
    border: 1px solid #e2e8f0;
}

.password-toggle {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    cursor: pointer;
    margin-bottom: 0.75rem;
}

.password-toggle input[type="checkbox"] {
    display: none;
}

.toggle-switch {
    width: 40px;
    height: 22px;
    background: #cbd5e1;
    border-radius: 11px;
    position: relative;
    transition: all 0.3s ease;
}

.toggle-switch::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    background: #fff;
    border-radius: 50%;
    top: 3px;
    left: 3px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.password-toggle input:checked + .toggle-switch {
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
}

.password-toggle input:checked + .toggle-switch::after {
    left: 21px;
}

.toggle-label {
    font-weight: 600;
    color: #374151;
    font-size: 0.9rem;
}

.password-fields {
    display: none;
}

.password-fields.show {
    display: block;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Submit Button */
.submit-section {
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid #e2e8f0;
}

.btn-save {
    width: 100%;
    padding: 0.875rem 1.5rem;
    background: linear-gradient(135deg, #0b3f91 0%, #3b82f6 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(11, 63, 145, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(11, 63, 145, 0.35);
}

.btn-save:active {
    transform: translateY(-1px);
}

.btn-save i {
    font-size: 1rem;
}

/* Back Link */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    font-size: 0.85rem;
}

.back-link:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}
</style>

<div class="edit-profile-page">
    <div class="edit-profile-container">
        <?php if (!$isEmbed): ?>
        <a href="<?php echo $_SESSION['role'] === 'patient' ? 'dashboard_patient.php' : 'dashboard_student.php'; ?>" class="back-link">
            <i class="bi bi-arrow-left"></i> Quay lại
        </a>
        <?php endif; ?>

        <div class="edit-profile-card">
            <div class="edit-profile-header">
                <div class="avatar-upload-section">
                    <div class="avatar-preview-wrapper">
                        <?php $avatarUrl = !empty($user['avatar']) && upload_exists($user['avatar']) ? public_url_for($user['avatar']) : ''; ?>
                        <?php if ($avatarUrl): ?>
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" class="avatar-preview" id="avatarPreview">
                        <?php else: ?>
                            <div class="avatar-placeholder" id="avatarPlaceholder">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <label class="avatar-upload-btn">
                            <i class="bi bi-camera-fill"></i>
                            <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/webp" form="profileForm">
                        </label>
                    </div>
                    <p class="avatar-hint">JPG, PNG hoặc WEBP • Tối đa 3MB</p>
                </div>
            </div>

            <div class="edit-profile-body">
                <?php if ($error): ?>
                    <div class="profile-alert error">
                        <div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
                        <div class="alert-text"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="profile-alert success">
                        <div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="alert-text"><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="edit_profile.php" enctype="multipart/form-data" id="profileForm">
                    <!-- Basic Info Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-person-fill"></i></div>
                            <h4 class="section-title">Thông tin cơ bản</h4>
                        </div>

                        <div class="form-row">
                            <div class="form-field">
                                <label>Họ và tên</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required placeholder="Nhập họ và tên">
                            </div>
                            <div class="form-field">
                                <label>Email</label>
                                <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled readonly>
                                <div class="field-hint locked"><i class="bi bi-lock-fill"></i> Không thể thay đổi email</div>
                            </div>
                        </div>

                        <div class="form-field">
                            <label>Tiểu sử</label>
                            <textarea name="bio" placeholder="Giới thiệu ngắn về bản thân, kinh nghiệm hoặc nhu cầu của bạn..."><?php echo htmlspecialchars($user['bio']); ?></textarea>
                        </div>
                    </div>

                    <!-- Contact Info Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-telephone-fill"></i></div>
                            <h4 class="section-title">Thông tin liên hệ</h4>
                        </div>

                        <div class="form-row">
                            <div class="form-field">
                                <label>Vị trí / Khu vực</label>
                                <input type="text" name="location" value="<?php echo htmlspecialchars($user['location']); ?>" placeholder="Ví dụ: Quận 1, TP.HCM">
                            </div>
                            <div class="form-field">
                                <label>Số điện thoại</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="Số điện thoại liên hệ">
                            </div>
                        </div>

                        <?php if ($user['role'] === 'student'): ?>
                        <div class="form-field">
                            <label>Trường</label>
                            <input type="text" name="school" value="<?php echo htmlspecialchars($user['school'] ?? ''); ?>" placeholder="Nhập tên trường">
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label>Mã lớp</label>
                                <input type="text" name="class_code" value="<?php echo htmlspecialchars($user['class_code'] ?? ''); ?>" placeholder="Nhập mã lớp">
                            </div>
                            <div class="form-field">
                                <label>Mã số sinh viên</label>
                                <input type="text" name="student_id" value="<?php echo htmlspecialchars($user['student_id'] ?? ''); ?>" placeholder="Nhập mã số sinh viên">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Password Section -->
                    <div class="password-section">
                        <label class="password-toggle">
                            <input type="checkbox" id="togglePassword">
                            <span class="toggle-switch"></span>
                            <span class="toggle-label">Thay đổi mật khẩu</span>
                        </label>

                        <div class="password-fields" id="passwordFields">
                            <div class="form-row">
                                <div class="form-field">
                                    <label>Mật khẩu mới</label>
                                    <input type="password" name="password" placeholder="Nhập mật khẩu mới">
                                    <div class="field-hint"><i class="bi bi-info-circle"></i> Tối thiểu 6 ký tự</div>
                                </div>
                                <div class="form-field">
                                    <label>Xác nhận mật khẩu</label>
                                    <input type="password" name="password_confirm" placeholder="Nhập lại mật khẩu mới">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="submit-section">
                        <button type="submit" class="btn-save">
                            Lưu thay đổi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password fields
    const togglePassword = document.getElementById('togglePassword');
    const passwordFields = document.getElementById('passwordFields');
    
    togglePassword.addEventListener('change', function() {
        if (this.checked) {
            passwordFields.classList.add('show');
        } else {
            passwordFields.classList.remove('show');
        }
    });

    // Avatar preview
    const avatarInput = document.getElementById('avatarInput');
    avatarInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const wrapper = document.querySelector('.avatar-preview-wrapper');
                const existingImg = wrapper.querySelector('.avatar-preview');
                const existingPlaceholder = wrapper.querySelector('.avatar-placeholder');
                
                if (existingPlaceholder) {
                    existingPlaceholder.remove();
                }
                
                if (existingImg) {
                    existingImg.src = e.target.result;
                } else {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'avatar-preview';
                    img.id = 'avatarPreview';
                    wrapper.insertBefore(img, wrapper.firstChild);
                }
            };
            reader.readAsDataURL(file);
        }
    });
});
</script>

<?php if ($isEmbed): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php else: ?>
<?php require_once 'footer.php'; ?>
<?php endif; ?>
