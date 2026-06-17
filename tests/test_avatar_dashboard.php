<?php
// Test file để kiểm tra chức năng avatar trên tất cả dashboard
require_once 'config.php';

// Kiểm tra session
if (!isset($_SESSION['user_id'])) {
    echo "Vui lòng đăng nhập để test chức năng avatar.";
    exit;
}

$userId = $_SESSION['user_id'];

// Lấy thông tin user
$stmt = $pdo->prepare('SELECT name, avatar, role, is_admin FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo "Không tìm thấy thông tin user.";
    exit;
}

echo "<h2>Test Avatar Dashboard - Tất cả 3 trang</h2>";
echo "<p><strong>User ID:</strong> " . $userId . "</p>";
echo "<p><strong>Tên:</strong> " . htmlspecialchars($user['name']) . "</p>";
echo "<p><strong>Role:</strong> " . htmlspecialchars($user['role']) . "</p>";
echo "<p><strong>Is Admin:</strong> " . ($user['is_admin'] ? 'Có' : 'Không') . "</p>";
echo "<p><strong>Avatar path:</strong> " . htmlspecialchars($user['avatar'] ?? 'Chưa có avatar') . "</p>";

if (!empty($user['avatar'])) {
    echo "<p><strong>Avatar exists:</strong> " . (upload_exists($user['avatar']) ? 'Có' : 'Không') . "</p>";
    if (upload_exists($user['avatar'])) {
        echo "<p><strong>Avatar URL:</strong> " . htmlspecialchars(public_url_for($user['avatar'])) . "</p>";
        echo "<p><strong>Preview:</strong></p>";
        echo "<img src='" . htmlspecialchars(public_url_for($user['avatar'])) . "' alt='Avatar' style='width:100px;height:100px;border-radius:10px;object-fit:cover;'>";
    }
} else {
    echo "<p><strong>Placeholder:</strong> " . strtoupper(mb_substr($user['name'], 0, 1)) . "</p>";
}

echo "<hr>";
echo "<h3>Links đến các Dashboard:</h3>";

// Xác định dashboard phù hợp
if ($user['is_admin']) {
    echo "<p><a href='admin_dashboard.php' style='color: #f59e0b; font-weight: bold;'>🔧 Dashboard Quản trị viên</a> (Recommended)</p>";
}

if ($user['role'] === 'student') {
    echo "<p><a href='dashboard_student.php' style='color: #3b82f6; font-weight: bold;'>🎓 Dashboard Sinh viên Y</a>" . ($user['role'] === 'student' ? ' (Recommended)' : '') . "</p>";
}

if ($user['role'] === 'patient') {
    echo "<p><a href='dashboard_patient.php' style='color: #10b981; font-weight: bold;'>🏥 Dashboard Bệnh nhân</a>" . ($user['role'] === 'patient' ? ' (Recommended)' : '') . "</p>";
}

echo "<hr>";
echo "<p><strong>Chức năng đã thêm:</strong></p>";
echo "<ul>";
echo "<li>✅ Hiển thị avatar thực của người dùng trên trang dashboard</li>";
echo "<li>✅ Fallback placeholder với chữ cái đầu tên nếu không có avatar</li>";
echo "<li>✅ Status badge hiển thị trạng thái xác minh (student/patient) hoặc quyền admin</li>";
echo "<li>✅ Hover effects và responsive design</li>";
echo "<li>✅ Sử dụng helper functions để kiểm tra và tạo URL avatar</li>";
echo "<li>🎨 <strong>Cập nhật màu sắc:</strong> Đổi nền thành màu xanh dương đậm (#1e40af, #1e3a8a, #2563eb) cho phù hợp</li>";
echo "<li>🔵 <strong>Nút Tin nhắn Admin:</strong> Đổi từ trong suốt sang màu xanh dương đậm (#1e40af) để chữ hiển thị rõ hơn</li>";
echo "</ul>";
?>
