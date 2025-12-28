<?php
declare(strict_types=1);
require_once 'config.php';
require_admin();

// Fetch statistics
try {
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total_users,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS total_students,
            SUM(CASE WHEN role = 'patient' THEN 1 ELSE 0 END) AS total_patients,
            SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) AS verified_users
        FROM users"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $stats = ['total_users' => 0, 'total_students' => 0, 'total_patients' => 0, 'verified_users' => 0];
}

try {
    $postStats = $pdo->query("SELECT COUNT(*) AS total_posts FROM posts")->fetch(PDO::FETCH_ASSOC) ?: ['total_posts' => 0];
} catch (Throwable $e) {
    $postStats = ['total_posts' => 0];
}

try {
    $reportStats = $pdo->query("SELECT COUNT(*) AS total_reports, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_reports FROM reports")->fetch(PDO::FETCH_ASSOC) ?: ['total_reports' => 0, 'pending_reports' => 0];
} catch (Throwable $e) {
    $reportStats = ['total_reports' => 0, 'pending_reports' => 0];
}

try {
    $pendingVerifications = $pdo->query(
        "SELECT v.*, u.email, u.name AS user_name FROM verifications v
         JOIN users u ON u.id = v.user_id WHERE v.status = 'pending' ORDER BY v.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pendingVerifications = [];
}

try {
    $recentUsers = $pdo->query(
        "SELECT id, name, email, role, verified, created_at FROM users ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentUsers = [];
}

try {
    $accountRequests = $pdo->query(
        "SELECT ar.*, u.name AS user_name, u.email FROM account_requests ar
         JOIN users u ON u.id = ar.user_id ORDER BY ar.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $accountRequests = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản trị - Kết nối Y tế</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; }
        
        .admin-wrapper { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .admin-sidebar { width: 260px; background: white; border-right: 1px solid #e2e8f0; padding: 20px 0; overflow-y: auto; }
        .sidebar-logo { padding: 0 20px 20px; border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; }
        .sidebar-logo a { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #1e293b; font-weight: 700; }
        .sidebar-logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6, #1e40af); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        
        .sidebar-menu { list-style: none; }
        .sidebar-section-title { padding: 10px 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; }
        .sidebar-item { padding: 12px 20px; color: #64748b; text-decoration: none; display: flex; align-items: center; gap: 12px; transition: all 0.3s; font-size: 14px; }
        .sidebar-item:hover { background: #f1f5f9; color: #3b82f6; }
        .sidebar-item.active { background: linear-gradient(90deg, rgba(59, 130, 246, 0.1), transparent); color: #3b82f6; border-left: 3px solid #3b82f6; padding-left: 17px; }
        .sidebar-item i { font-size: 18px; width: 20px; text-align: center; }
        .sidebar-badge { margin-left: auto; background: #ef4444; color: white; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        
        /* Main Content */
        .admin-main { flex: 1; display: flex; flex-direction: column; }
        
        /* Top Bar */
        .admin-topbar { background: white; border-bottom: 1px solid #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar-title { font-size: 20px; font-weight: 700; color: #1e293b; }
        .topbar-breadcrumb { font-size: 13px; color: #94a3b8; }
        .topbar-breadcrumb a { color: #3b82f6; text-decoration: none; }
        
        /* Content */
        .admin-content { flex: 1; padding: 30px; overflow-y: auto; }
        
        /* Welcome Card */
        .welcome-card { background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); border-radius: 20px; padding: 30px; color: white; margin-bottom: 30px; display: flex; align-items: center; gap: 20px; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.2); }
        .welcome-icon { font-size: 50px; }
        .welcome-text h2 { font-size: 24px; font-weight: 800; margin-bottom: 5px; }
        .welcome-text p { font-size: 14px; opacity: 0.9; }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 16px; padding: 20px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-info h3 { font-size: 28px; font-weight: 800; color: #1e293b; margin-bottom: 5px; }
        .stat-info p { font-size: 13px; color: #94a3b8; }
        .stat-icon { width: 50px; height: 50px; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1)); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #3b82f6; }
        
        /* Section Card */
        .section-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px; overflow: hidden; }
        .section-header { background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(139, 92, 246, 0.08)); padding: 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .section-title { display: flex; align-items: center; gap: 12px; font-weight: 700; color: #1e293b; }
        .section-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6, #1e40af); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; }
        .section-body { padding: 20px; }
        
        /* Table */
        .admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .admin-table th { background: #f8fafc; padding: 12px; text-align: left; font-weight: 700; color: #1e293b; border-bottom: 2px solid #e2e8f0; }
        .admin-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        .admin-table tbody tr:hover { background: #f8fafc; }
        
        /* Badge */
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        
        /* Button */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.3s; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3); }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-icon { font-size: 40px; color: #cbd5e1; margin-bottom: 10px; }
        .empty-state h5 { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 5px; }
        .empty-state p { font-size: 13px; color: #94a3b8; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .admin-sidebar { width: 200px; }
            .admin-content { padding: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .welcome-card { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-logo">
                <a href="index.php">
                    <div class="sidebar-logo-icon"><i class="bi bi-shield-check"></i></div>
                    <span>Quản trị</span>
                </a>
            </div>
            
            <ul class="sidebar-menu">
                <li class="sidebar-section-title">Tổng quan</li>
                <li><a href="#" class="sidebar-item active"><i class="bi bi-grid-1x2-fill"></i> Bảng điều khiển</a></li>
                
                <li class="sidebar-section-title" style="margin-top: 15px;">Quản lý người dùng</li>
                <li><a href="admin_users.php" class="sidebar-item"><i class="bi bi-people-fill"></i> Người dùng <span class="sidebar-badge"><?php echo $stats['total_users'] ?? 0; ?></span></a></li>
                <li><a href="admin_verifications.php" class="sidebar-item"><i class="bi bi-patch-check"></i> Xác minh <span class="sidebar-badge"><?php echo count($pendingVerifications); ?></span></a></li>
                
                <li class="sidebar-section-title" style="margin-top: 15px;">Quản lý nội dung</li>
                <li><a href="admin_posts.php" class="sidebar-item"><i class="bi bi-file-earmark-medical-fill"></i> Tin đăng <span class="sidebar-badge"><?php echo $postStats['total_posts'] ?? 0; ?></span></a></li>
                <li><a href="admin_reports.php" class="sidebar-item"><i class="bi bi-flag-fill"></i> Báo cáo <span class="sidebar-badge"><?php echo $reportStats['pending_reports'] ?? 0; ?></span></a></li>
                
                <li class="sidebar-section-title" style="margin-top: 15px;">Yêu cầu</li>
                <li><a href="account_request.php" class="sidebar-item"><i class="bi bi-envelope-fill"></i> Yêu cầu tài khoản <span class="sidebar-badge"><?php echo count($accountRequests); ?></span></a></li>
                
                <li class="sidebar-section-title" style="margin-top: 15px;">Hệ thống</li>
                <li><a href="logout.php" class="sidebar-item" style="color: #f87171;"><i class="bi bi-box-arrow-right"></i> Đăng xuất</a></li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Top Bar -->
            <div class="admin-topbar">
                <h1 class="topbar-title">Bảng điều khiển</h1>
                <div class="topbar-breadcrumb">
                    <a href="index.php"><i class="bi bi-house"></i> Trang chủ</a> / <span>Quản trị viên</span>
                </div>
            </div>
            
            <!-- Content -->
            <div class="admin-content">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <div class="welcome-icon"><i class="bi bi-shield-check"></i></div>
                    <div class="welcome-text">
                        <h2>Chào mừng, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
                        <p>Quản lý nền tảng Kết nối Y tế từ bảng điều khiển này</p>
                    </div>
                </div>
                
                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><?php echo $stats['total_users'] ?? 0; ?></h3>
                            <p>Tổng người dùng</p>
                        </div>
                        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students'] ?? 0; ?></h3>
                            <p>Sinh viên Y</p>
                        </div>
                        <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><?php echo $stats['total_patients'] ?? 0; ?></h3>
                            <p>Bệnh nhân</p>
                        </div>
                        <div class="stat-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <h3><?php echo $postStats['total_posts'] ?? 0; ?></h3>
                            <p>Tin đăng</p>
                        </div>
                        <div class="stat-icon"><i class="bi bi-file-earmark-medical-fill"></i></div>
                    </div>
                </div>
                
                <!-- Pending Verifications -->
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon"><i class="bi bi-patch-check"></i></div>
                            <span>Xác minh chờ xử lý</span>
                        </div>
                        <a href="admin_verifications.php" class="btn btn-primary"><i class="bi bi-arrow-right"></i> Xem tất cả</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($pendingVerifications)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-check-circle"></i></div>
                            <h5>Không có yêu cầu chờ xử lý</h5>
                            <p>Tất cả yêu cầu xác minh đã được xử lý</p>
                        </div>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Người dùng</th>
                                    <th>Email</th>
                                    <th>Ngày yêu cầu</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingVerifications as $v): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($v['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($v['email']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($v['created_at'])); ?></td>
                                    <td><a href="admin_verifications.php?id=<?php echo $v['id']; ?>" class="btn btn-primary"><i class="bi bi-eye"></i> Xem</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Users -->
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title">
                            <div class="section-icon"><i class="bi bi-people-fill"></i></div>
                            <span>Người dùng mới</span>
                        </div>
                        <a href="admin_users.php" class="btn btn-primary"><i class="bi bi-arrow-right"></i> Xem tất cả</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($recentUsers)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-person-plus"></i></div>
                            <h5>Chưa có người dùng</h5>
                            <p>Chưa có người dùng mới đăng ký</p>
                        </div>
                        <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Tên</th>
                                    <th>Email</th>
                                    <th>Vai trò</th>
                                    <th>Xác minh</th>
                                    <th>Ngày tạo</th>
                                </tr>
   