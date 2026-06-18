<?php
require_once 'config.php';
require_admin();

$success = '';
$error = '';
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    $toAll = !empty($_POST['to_all']);
    $toUser = isset($_POST['to_user']) ? (int)$_POST['to_user'] : 0;

    if ($message === '') {
        $error = 'Vui lòng nhập nội dung thông báo.';
    } else {
        try {
            if ($toAll) {
                $stmt = $pdo->query('SELECT id FROM users');
                $all = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $ins = $pdo->prepare('INSERT INTO messages (`sender_id`, `receiver_id`, `message`) VALUES (?, ?, ?)');
                foreach ($all as $uid) {
                    $ins->execute([$_SESSION['user_id'], (int)$uid, $message]);
                }
                $success = 'Đã gửi thông báo đến tất cả người dùng.';
            } else {
                if ($toUser <= 0) {
                    $error = 'Vui lòng chọn người nhận.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO messages (`sender_id`, `receiver_id`, `message`) VALUES (?, ?, ?)');
                    $stmt->execute([$_SESSION['user_id'], $toUser, $message]);
                    $success = 'Đã gửi thông báo.';
                }
            }
        } catch (Throwable $e) {
            error_log('Send notification error: ' . $e->getMessage());
            $error = 'Có lỗi khi gửi thông báo.';
        }
    }
}

// load users for selection
$users = $pdo->query('SELECT id, name, email FROM users ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

require_once 'header.php';
?>

<div class="notification-page-wrapper">
        <!-- Header Section -->
        <div class="notification-header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>
                <div class="header-text">
                    <h2>Gửi thông báo</h2>
                    <p>Gửi thông báo đến người dùng trong hệ thống</p>
                </div>
            </div>
            <a href="admin.php" class="btn-back">
                <i class="bi bi-arrow-left"></i>
                <span>Quay lại</span>
            </a>
        </div>

        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="alert-custom alert-success-custom">
                <div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div class="alert-content"><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-custom alert-danger-custom">
                <div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
                <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <!-- Main layout: 2 columns on large screens -->
        <div class="notification-content-grid">
            <div class="notification-left-col">
                <!-- Main Form Card -->
                <div class="notification-form-card">
                    <form method="post" class="notification-form">
                        <!-- Recipient Section -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon"><i class="bi bi-people-fill"></i></div>
                                <div class="section-title">Người nhận</div>
                            </div>
                            
                            <div class="recipient-options">
                                <label class="recipient-option">
                                    <input type="checkbox" name="to_all" id="to_all" class="recipient-checkbox">
                                    <div class="option-card">
                                        <div class="option-icon all-users">
                                            <i class="bi bi-globe"></i>
                                        </div>
                                        <div class="option-info">
                                            <span class="option-title">Tất cả người dùng</span>
                                            <span class="option-desc">Gửi thông báo đến toàn bộ hệ thống</span>
                                        </div>
                                        <div class="option-check">
                                            <i class="bi bi-check-lg"></i>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="divider-text"><span>hoặc chọn người dùng cụ thể</span></div>

                            <div class="select-wrapper">
                                <i class="bi bi-person-fill select-icon"></i>
                                <select name="to_user" class="form-select-custom" id="user_select">
                                    <option value="0">-- Chọn người dùng --</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $targetUserId === (int)$u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['name'] . ' (' . $u['email'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down select-arrow"></i>
                            </div>
                        </div>

                        <!-- Message Section -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon"><i class="bi bi-chat-text-fill"></i></div>
                                <div class="section-title">Nội dung thông báo</div>
                            </div>
                            
                            <div class="textarea-wrapper">
                                <textarea name="message" class="form-textarea-custom" rows="8" placeholder="Nhập nội dung thông báo của bạn..."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                                <div class="textarea-footer">
                                    <span class="char-count"><span id="charCount">0</span> ký tự</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="form-actions">
                            <a href="admin.php" class="btn-cancel">
                                <i class="bi bi-x-lg"></i>
                                <span>Hủy bỏ</span>
                            </a>
                            <button type="submit" class="btn-send">
                                <i class="bi bi-send-fill"></i>
                                <span>Gửi thông báo</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="notification-right-col">
                <!-- Quick Tips -->
                <div class="tips-card">
                    <div class="tips-header">
                        <i class="bi bi-lightbulb-fill"></i>
                        <span>Mẹo hữu ích</span>
                    </div>
                    <ul class="tips-list">
                        <li><i class="bi bi-check2"></i> Thông báo sẽ xuất hiện trong mục Tin nhắn của người dùng</li>
                        <li><i class="bi bi-check2"></i> Chọn "Tất cả người dùng" để gửi thông báo hàng loạt</li>
                        <li><i class="bi bi-check2"></i> Nội dung nên ngắn gọn, rõ ràng và dễ hiểu</li>
                    </ul>
                </div>

                <!-- Stats Card -->
                <div class="stats-info-card">
                    <div class="tips-header">
                        <i class="bi bi-people-fill" style="color:#3b82f6;"></i>
                        <span style="color:#1e293b;">Thống kê người dùng</span>
                    </div>
                    <div class="stats-row">
                        <span class="stats-label">Tổng người dùng</span>
                        <span class="stats-value"><?php echo count($users); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

<style>
/* Notification Page Inline Styles for Better Specificity */
.notification-page-wrapper {
    max-width: 100%;
    width: 100%;
    padding: 1.5rem;
    box-sizing: border-box;
}

/* 2-column grid layout */
.notification-content-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.5rem;
    align-items: start;
}

.notification-left-col { min-width: 0; }
.notification-right-col { display: flex; flex-direction: column; gap: 1.5rem; }

/* Stats info card */
.stats-info-card {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-radius: 20px;
    padding: 1.5rem;
    border: 1px solid #bfdbfe;
}

.stats-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid rgba(59,130,246,0.15);
}

.stats-row:last-child { border-bottom: none; }

.stats-label {
    font-size: 0.9rem;
    color: #475569;
    font-weight: 500;
}

.stats-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1d4ed8;
}

@media (max-width: 1024px) {
    .notification-content-grid {
        grid-template-columns: 1fr;
    }
    .notification-right-col {
        flex-direction: row;
        flex-wrap: wrap;
    }
    .notification-right-col > * { flex: 1; min-width: 240px; }
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #0b3f91 0%, #062a63 100%);
    border-radius: 24px;
    box-shadow: 0 20px 50px rgba(11, 63, 145, 0.3);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.header-icon {
    width: 64px;
    height: 64px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: #fff;
}

.header-text h2 {
    margin: 0;
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
}

.header-text p {
    margin: 0.25rem 0 0;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.95rem;
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: rgba(255, 255, 255, 0.15);
    color: #fff !important;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn-back:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #fff !important;
    transform: translateX(-4px);
}

.alert-custom {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-radius: 16px;
    margin-bottom: 1.5rem;
}

.alert-success-custom {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border: 1px solid #6ee7b7;
    color: #065f46;
}

.alert-danger-custom {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.alert-icon { font-size: 1.5rem; }
.alert-success-custom .alert-icon { color: #059669; }
.alert-danger-custom .alert-icon { color: #dc2626; }

.notification-form-card {
    background: #fff;
    border-radius: 24px;
    padding: 2rem;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.1);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 1.5rem;
}

.form-section { margin-bottom: 2rem; }
.form-section:last-of-type { margin-bottom: 0; }

.section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, rgba(11, 63, 145, 0.1) 0%, rgba(11, 63, 145, 0.15) 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0b3f91;
    font-size: 1.1rem;
}

.section-title {
    font-weight: 600;
    font-size: 1.1rem;
    color: #0f172a;
}

.recipient-options { margin-bottom: 1.25rem; }
.recipient-option { display: block; cursor: pointer; }
.recipient-checkbox { display: none; }

.option-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    transition: all 0.3s ease;
}

.option-card:hover {
    border-color: #0b3f91;
    background: rgba(11, 63, 145, 0.02);
}

.recipient-checkbox:checked + .option-card {
    border-color: #0b3f91;
    background: linear-gradient(135deg, rgba(11, 63, 145, 0.05) 0%, rgba(11, 63, 145, 0.1) 100%);
    box-shadow: 0 4px 15px rgba(11, 63, 145, 0.15);
}

.option-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}

.option-icon.all-users {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #fff;
}

.option-info { flex: 1; }
.option-title {
    display: block;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 0.15rem;
}
.option-desc {
    display: block;
    font-size: 0.85rem;
    color: #64748b;
}

.option-check {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: transparent;
    transition: all 0.3s ease;
}

.recipient-checkbox:checked + .option-card .option-check {
    background: #0b3f91;
    border-color: #0b3f91;
    color: #fff;
}

.divider-text {
    display: flex;
    align-items: center;
    margin: 1.5rem 0;
    color: #94a3b8;
    font-size: 0.85rem;
}

.divider-text::before,
.divider-text::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

.divider-text span { padding: 0 1rem; }

.select-wrapper {
    position: relative;
    transition: all 0.3s ease;
}

.select-wrapper.disabled {
    opacity: 0.5;
    pointer-events: none;
}

.select-icon {
    position: absolute;
    left: 1.25rem;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 1.1rem;
    z-index: 1;
}

.select-arrow {
    position: absolute;
    right: 1.25rem;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    pointer-events: none;
}

.form-select-custom {
    width: 100%;
    padding: 1rem 3rem 1rem 3.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 1rem;
    color: #0f172a;
    background: #fff;
    appearance: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.form-select-custom:focus {
    outline: none;
    border-color: #0b3f91;
    box-shadow: 0 0 0 4px rgba(11, 63, 145, 0.1);
}

.textarea-wrapper { position: relative; }

.form-textarea-custom {
    width: 100%;
    padding: 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    font-size: 1rem;
    color: #0f172a;
    resize: vertical;
    min-height: 160px;
    transition: all 0.3s ease;
    font-family: inherit;
}

.form-textarea-custom:focus {
    outline: none;
    border-color: #0b3f91;
    box-shadow: 0 0 0 4px rgba(11, 63, 145, 0.1);
}

.form-textarea-custom::placeholder { color: #94a3b8; }

.textarea-footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 0.5rem;
}

.char-count {
    font-size: 0.85rem;
    color: #94a3b8;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.btn-cancel,
.btn-send {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    border: none;
}

.btn-cancel {
    background: #f1f5f9;
    color: #64748b !important;
    border: 2px solid #e2e8f0;
}

.btn-cancel:hover {
    background: #e2e8f0;
    color: #475569 !important;
}

.btn-send {
    background: linear-gradient(135deg, #0b3f91 0%, #062a63 100%);
    color: #fff !important;
    box-shadow: 0 10px 30px rgba(11, 63, 145, 0.3);
}

.btn-send:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 40px rgba(11, 63, 145, 0.4);
    color: #fff !important;
}

.tips-card {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 20px;
    padding: 1.5rem;
    border: 1px solid #fcd34d;
}

.tips-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #92400e;
    margin-bottom: 1rem;
}

.tips-header i {
    font-size: 1.25rem;
    color: #f59e0b;
}

.tips-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.tips-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.5rem 0;
    color: #78350f;
    font-size: 0.95rem;
}

.tips-list li i {
    color: #d97706;
    margin-top: 0.15rem;
}

@media (max-width: 768px) {
    .notification-header {
        flex-direction: column;
        gap: 1.25rem;
        text-align: center;
        padding: 1.5rem;
    }
    
    .header-content { flex-direction: column; }
    .btn-back { width: 100%; justify-content: center; }
    .notification-form-card { padding: 1.5rem; }
    .form-actions { flex-direction: column; }
    .btn-cancel, .btn-send { width: 100%; justify-content: center; }
}
</style>

<script>
// Character counter
const textarea = document.querySelector('.form-textarea-custom');
const charCount = document.getElementById('charCount');
if (textarea && charCount) {
    charCount.textContent = textarea.value.length;
    textarea.addEventListener('input', function() {
        charCount.textContent = this.value.length;
    });
}

// Toggle user select when "all users" is checked
const toAllCheckbox = document.getElementById('to_all');
const userSelect = document.getElementById('user_select');
if (toAllCheckbox && userSelect) {
    toAllCheckbox.addEventListener('change', function() {
        userSelect.disabled = this.checked;
        userSelect.closest('.select-wrapper').classList.toggle('disabled', this.checked);
    });
}
</script>

<?php require_once 'footer.php'; ?>
