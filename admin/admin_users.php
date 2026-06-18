<?php
require_once 'config.php';
require_admin();

$success = '';
$error = '';

// Handle user management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    try {
    // Quick grant/revoke admin by email
    if ($action === 'set_admin_by_email') {
      $email = trim($_POST['email'] ?? '');
      $value = isset($_POST['value']) ? (int)$_POST['value'] : null;
      if ($email === '' || ($value !== 0 && $value !== 1)) {
        $error = 'Email hoặc giá trị không hợp lệ.';
      } else {
        $stmt = $pdo->prepare('UPDATE users SET is_admin = :v WHERE email = :e');
        $stmt->execute([':v' => $value, ':e' => $email]);
        if ($stmt->rowCount() > 0) {
          $success = $value ? 'Đã cấp quyền quản trị cho: ' . htmlspecialchars($email) : 'Đã gỡ quyền quản trị của: ' . htmlspecialchars($email);
        } else {
          $error = 'Không tìm thấy tài khoản với email này.';
        }
      }
    }
    // Per-user inline toggles
        if ($action === 'toggle_admin' && $userId > 0) {
            $stmt = $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?');
            $stmt->execute([$userId]);
            $success = 'Đã chuyển quyền quản trị.';
        } elseif ($action === 'toggle_can_post' && $userId > 0) {
            $stmt = $pdo->prepare('UPDATE users SET can_post = 1 - can_post WHERE id = ?');
            $stmt->execute([$userId]);
            $success = 'Đã cập nhật quyền đăng bài.';
        } elseif ($action === 'delete_user' && $userId > 0) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $success = 'Đã xóa tài khoản.';
        }
    } catch (Throwable $e) {
        error_log('admin_users action error: ' . $e->getMessage());
        $error = 'Có lỗi khi thực hiện thao tác.';
    }
}

$users = $pdo->query("SELECT id, name, email, role, verified, is_admin, can_post, last_login, last_activity, created_at FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);
$adminCount = count(array_filter($users, fn($u) => !empty($u['is_admin'])));

require_once 'header.php';
?>

<div class="users-management-wrapper">
    <!-- Header Section -->
    <div class="users-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="header-text">
                <h2>Quản lý người dùng</h2>
                <p>Quản lý tài khoản và phân quyền người dùng</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="admin.php" class="btn-header-action btn-back-header">
                <i class="bi bi-arrow-left"></i>
                <span>Quay lại</span>
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="users-stats">
        <div class="stat-item">
            <div class="stat-icon blue"><i class="bi bi-people"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $totalUsers; ?></span>
                <span class="stat-label">Tổng người dùng</span>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon purple"><i class="bi bi-shield-check"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $adminCount; ?></span>
                <span class="stat-label">Quản trị viên</span>
            </div>
        </div>
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

    <!-- Quick Admin Card -->
    <div class="quick-admin-card">
        <div class="quick-admin-header">
            <div class="quick-admin-icon"><i class="bi bi-lightning-fill"></i></div>
            <div>
                <h5>Cấp/Gỡ quyền Admin nhanh</h5>
                <p>Nhập email để thay đổi quyền quản trị</p>
            </div>
        </div>
        <form method="post" class="quick-admin-form">
            <input type="hidden" name="action" value="set_admin_by_email">
            <div class="input-group-custom">
                <i class="bi bi-envelope"></i>
                <input type="email" required name="email" placeholder="Nhập email người dùng...">
            </div>
            <div class="quick-admin-buttons">
                <button class="btn-grant" name="value" value="1" type="submit">
                    <i class="bi bi-shield-plus"></i> Cấp Admin
                </button>
                <button class="btn-revoke" name="value" value="0" type="submit">
                    <i class="bi bi-shield-minus"></i> Gỡ Admin
                </button>
            </div>
        </form>
    </div>

    <!-- Search & Filter -->
    <div class="users-toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchUsers" placeholder="Tìm kiếm theo tên hoặc email...">
        </div>
        <div class="filter-buttons">
            <button class="filter-btn active" data-filter="all">Tất cả</button>
            <button class="filter-btn" data-filter="admin">Admin</button>
        </div>
    </div>

    <!-- Users Table -->
    <div class="users-table-card">
        <div class="table-responsive">
            <table class="users-table" id="usersTable">
                <thead>
                    <tr>
                        <th>Người dùng</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Hoạt động</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $roleLabel = $u['role'] === 'patient' ? 'Bệnh nhân' : ($u['role'] === 'student' ? 'Sinh viên' : htmlspecialchars($u['role']));
                        $roleClass = $u['role'] === 'patient' ? 'patient' : 'student';
                        $isSelf = ((int)$u['id'] === (int)$_SESSION['user_id']);
                    ?>
                    <tr data-admin="<?php echo !empty($u['is_admin']) ? '1' : '0'; ?>">
                        <td>
                            <div class="user-info">
                                <div class="user-avatar-wrapper">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                    </div>
                                    <span class="online-status-dot <?php echo is_user_online($u['last_activity'] ?? null) ? 'online' : 'offline'; ?>" title="<?php echo is_user_online($u['last_activity'] ?? null) ? 'Đang trực tuyến' : 'Ngoại tuyến'; ?>"></span>
                                </div>
                                <div class="user-details">
                                    <span class="user-name"><?php echo htmlspecialchars($u['name']); ?></span>
                                    <span class="user-email"><?php echo htmlspecialchars($u['email']); ?></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-badge <?php echo $roleClass; ?>"><?php echo $roleLabel; ?></span></td>
                        <td>
                            <div class="status-badges">
                                <?php if (!empty($u['is_admin'])): ?>
                                    <span class="status-badge admin"><i class="bi bi-shield-fill"></i> Admin</span>
                                <?php endif; ?>
                                <?php if (!empty($u['can_post'])): ?>
                                    <span class="status-badge can-post"><i class="bi bi-pencil-fill"></i> Đăng bài</span>
                                <?php else: ?>
                                    <span class="status-badge blocked"><i class="bi bi-slash-circle"></i> Chặn đăng</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="activity-info">
                                <span class="activity-label">Đăng nhập:</span>
                                <span class="activity-value"><?php echo $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Chưa'; ?></span>
                                <span class="activity-label">Tạo:</span>
                                <span class="activity-value"><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if (!$isSelf): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="action" value="toggle_can_post">
                                    <button type="submit" class="action-btn <?php echo !empty($u['can_post']) ? 'orange' : 'teal'; ?>" title="<?php echo !empty($u['can_post']) ? 'Chặn đăng' : 'Cho đăng'; ?>">
                                        <i class="bi bi-<?php echo !empty($u['can_post']) ? 'pencil-slash' : 'pencil'; ?>"></i>
                                        <span><?php echo !empty($u['can_post']) ? 'Chặn đăng' : 'Cho đăng'; ?></span>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="view_profile.php?id=<?php echo (int)$u['id']; ?>" class="action-btn info" title="Xem hồ sơ">
                                    <i class="bi bi-eye"></i>
                                    <span>Xem</span>
                                </a>
                                <?php if (!$isSelf): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản này?');">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <button type="submit" class="action-btn danger" title="Xóa tài khoản">
                                        <i class="bi bi-trash"></i>
                                        <span>Xóa</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Users Management Page Styles */
.users-management-wrapper { max-width: 1400px; margin: 0 auto; }

.users-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;
    padding: 1.5rem 2rem; background: linear-gradient(135deg, #0b3f91 0%, #062a63 100%);
    border-radius: 24px; box-shadow: 0 20px 50px rgba(11, 63, 145, 0.3);
}
.users-header .header-content { display: flex; align-items: center; gap: 1.25rem; }
.users-header .header-icon {
    width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 18px;
    display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #fff;
}
.users-header .header-text h2 { margin: 0; color: #fff; font-size: 1.75rem; font-weight: 700; }
.users-header .header-text p { margin: 0.25rem 0 0; color: rgba(255,255,255,0.8); font-size: 0.95rem; }
.header-actions { display: flex; gap: 0.75rem; }
.btn-header-action {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem;
    border-radius: 12px; text-decoration: none; font-weight: 500; transition: all 0.3s ease;
}
.btn-back-header { background: rgba(255,255,255,0.15); color: #fff !important; border: 1px solid rgba(255,255,255,0.2); }
.btn-back-header:hover { background: rgba(255,255,255,0.25); color: #fff !important; }
.btn-primary-header { background: #fff; color: #0b3f91 !important; }
.btn-primary-header:hover { background: #f0f4ff; transform: translateY(-2px); }

/* Stats */
.users-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-item {
    display: flex; align-items: center; gap: 1rem; padding: 1.25rem 1.5rem;
    background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.stat-icon {
    width: 50px; height: 50px; border-radius: 14px; display: flex;
    align-items: center; justify-content: center; font-size: 1.4rem;
}
.stat-icon.blue { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.stat-icon.green { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #059669; }
.stat-icon.purple { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #7c3aed; }
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
.stat-label { font-size: 0.85rem; color: #64748b; }

/* Alerts */
.alert-custom { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; }
.alert-success-custom { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; color: #065f46; }
.alert-danger-custom { background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #fca5a5; color: #991b1b; }
.alert-icon { font-size: 1.5rem; }
.alert-success-custom .alert-icon { color: #059669; }
.alert-danger-custom .alert-icon { color: #dc2626; }

/* Quick Admin Card */
.quick-admin-card {
    background: #fff; border-radius: 20px; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;
}
.quick-admin-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
.quick-admin-icon {
    width: 44px; height: 44px; background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 12px; display: flex; align-items: center; justify-content: center;
    color: #d97706; font-size: 1.2rem;
}
.quick-admin-header h5 { margin: 0; font-weight: 600; color: #1e293b; }
.quick-admin-header p { margin: 0; font-size: 0.85rem; color: #64748b; }
.quick-admin-form { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
.input-group-custom {
    flex: 1; min-width: 280px; position: relative;
}
.input-group-custom i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.input-group-custom input {
    width: 100%; padding: 0.875rem 1rem 0.875rem 2.75rem; border: 2px solid #e2e8f0;
    border-radius: 12px; font-size: 1rem; transition: all 0.3s ease;
}
.input-group-custom input:focus { outline: none; border-color: #0b3f91; box-shadow: 0 0 0 4px rgba(11,63,145,0.1); }
.quick-admin-buttons { display: flex; gap: 0.75rem; }
.btn-grant, .btn-revoke {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.875rem 1.25rem;
    border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; border: none;
}
.btn-grant { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
.btn-grant:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,0.3); }
.btn-revoke { background: #fff; color: #dc2626; border: 2px solid #fecaca; }
.btn-revoke:hover { background: #fef2f2; }
</style>

<style>
/* Toolbar */
.users-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.search-box {
    position: relative; flex: 1; min-width: 280px; max-width: 400px;
}
.search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.search-box input {
    width: 100%; padding: 0.875rem 1rem 0.875rem 2.75rem; border: 2px solid #e2e8f0;
    border-radius: 12px; font-size: 1rem; background: #fff; transition: all 0.3s ease;
}
.search-box input:focus { outline: none; border-color: #0b3f91; box-shadow: 0 0 0 4px rgba(11,63,145,0.1); }
.filter-buttons { display: flex; gap: 0.5rem; }
.filter-btn {
    padding: 0.625rem 1rem; border: 2px solid #e2e8f0; border-radius: 10px;
    background: #fff; color: #64748b; font-weight: 500; cursor: pointer; transition: all 0.3s ease;
}
.filter-btn:hover { border-color: #0b3f91; color: #0b3f91; }
.filter-btn.active { background: #0b3f91; border-color: #0b3f91; color: #fff; }

/* Table Card */
.users-table-card {
    background: #fff; border-radius: 20px; overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;
}
.users-table { width: 100%; border-collapse: collapse; }
.users-table thead th {
    padding: 1rem 1.25rem; text-align: left; font-weight: 600; color: #475569;
    background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 0.85rem;
    text-transform: uppercase; letter-spacing: 0.05em;
}
.users-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.2s ease; }
.users-table tbody tr:hover { background: #f8fafc; }
.users-table tbody td { padding: 1rem 1.25rem; vertical-align: middle; }

/* User Info Cell */
.user-info { display: flex; align-items: center; gap: 0.875rem; }
.user-avatar-wrapper { position: relative; display: inline-block; }
.user-avatar {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #0b3f91, #2765d8); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 600; font-size: 1.1rem;
}
.user-details { display: flex; flex-direction: column; }
.user-name { font-weight: 600; color: #1e293b; }
.user-email { font-size: 0.85rem; color: #64748b; }

/* Online Status Dot */
.online-status-dot {
    position: absolute; bottom: -2px; right: -2px;
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}
.online-status-dot.online {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    animation: pulse-online 2s infinite;
}
.online-status-dot.offline {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}
@keyframes pulse-online {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
    50% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
}

/* Role Badge */
.role-badge {
    display: inline-flex; align-items: center; padding: 0.375rem 0.875rem;
    border-radius: 8px; font-size: 0.85rem; font-weight: 500;
}
.role-badge.patient { background: #dbeafe; color: #1d4ed8; }
.role-badge.student { background: #d1fae5; color: #059669; }

/* Status Badges */
.status-badges { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.status-badge {
    display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.625rem;
    border-radius: 6px; font-size: 0.75rem; font-weight: 500;
}
.status-badge.admin { background: #ede9fe; color: #7c3aed; }
.status-badge.can-post { background: #dbeafe; color: #1d4ed8; }
.status-badge.blocked { background: #fee2e2; color: #dc2626; }

/* Activity Info */
.activity-info { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.activity-label { color: #94a3b8; font-size: 0.75rem; }
.activity-value { color: #475569; }

/* Action Buttons - compact, flat style */
.action-buttons { display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center; }
.action-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
    padding: 0.45rem 1.05rem; border-radius: 10px; border: 1px solid #d7dde7;
    background: #f8fafc; color: #0b1830; cursor: pointer; transition: all 0.18s ease;
    font-size: 0.82rem; font-weight: 700; text-decoration: none; min-height: 36px; min-width: 72px;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.05);
}
.action-btn i {
    font-size: 0.95rem; transition: transform 0.18s ease, box-shadow 0.18s ease;
    padding: 3px; border-radius: 999px; background: rgba(255,255,255,0.7);
}
.action-btn:hover i { transform: scale(1.08); }
.action-btn span { white-space: nowrap; }

.action-btn.orange { background: #ffe4cc; color: #b45309; border: 1px solid #fca461; }
.action-btn.orange:hover { background: #fdd8b4; color: #9a3412; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(249,115,22,0.18); }
.action-btn.orange i { background: rgba(249,115,22,0.12); }

.action-btn.teal { background: #d1fae5; color: #047857; border: 1px solid #34d399; }
.action-btn.teal:hover { background: #a7f3d0; color: #065f46; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(13,148,136,0.18); }
.action-btn.teal i { background: rgba(16,185,129,0.18); }

.action-btn.blue { background: #e0f2fe; color: #075985; border: 1px solid #93c5fd; }
.action-btn.blue:hover { background: #cfe8ff; color: #0b4f94; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(59,130,246,0.18); }
.action-btn.blue i { background: rgba(59,130,246,0.16); }

.action-btn.info { background: #e0f2fe; color: #075985; border: 1px solid #93c5fd; }
.action-btn.info:hover { background: #cfe8ff; color: #0b4f94; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(14,165,233,0.18); }
.action-btn.info i { background: rgba(14,165,233,0.16); }

.action-btn.danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.action-btn.danger:hover { background: #fbd5d5; color: #991b1b; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(239,68,68,0.18); }
.action-btn.danger i { background: rgba(239,68,68,0.12); }

/* Responsive: Hide labels on smaller screens */
@media (max-width: 992px) {
    .action-btn { padding: 0.42rem 0.85rem; min-height: 34px; min-width: 64px; }
    .action-btn span { display: inline; }
}

/* Responsive */
@media (max-width: 992px) {
    .users-header { flex-direction: column; gap: 1.25rem; text-align: center; }
    .users-header .header-content { flex-direction: column; }
    .header-actions { width: 100%; justify-content: center; }
    .users-toolbar { flex-direction: column; }
    .search-box { max-width: 100%; }
}
@media (max-width: 768px) {
    .quick-admin-form { flex-direction: column; }
    .input-group-custom { min-width: 100%; }
    .quick-admin-buttons { width: 100%; }
    .btn-grant, .btn-revoke { flex: 1; justify-content: center; }
}
</style>

<script>
// Search functionality
document.getElementById('searchUsers')?.addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
});

// Filter functionality
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.dataset.filter;
        document.querySelectorAll('#usersTable tbody tr').forEach(row => {
            if (filter === 'all') { row.style.display = ''; }
            else if (filter === 'admin') { row.style.display = row.dataset.admin === '1' ? '' : 'none'; }
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>
