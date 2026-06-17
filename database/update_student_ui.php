<?php
$patientCode = file_get_contents('dashboard_patient.php');

// Extract CSS block:
preg_match('/(\/\* Welcome Card Advanced Styles \*\/.*?\/\* Search Posts Section \*\/)/s', $patientCode, $cssMatch);
$css = '<style>' . "\n" . $cssMatch[1] . "\n" . '</style>';

// Extract HTML block:
preg_match('/(<div class="welcome-card">.*?)(?=<!-- Statistics Section -->)/s', $patientCode, $htmlMatch);
$html = $htmlMatch[1];

// Adapt HTML for Student
$html = str_replace('Bệnh nhân', 'Sinh viên Y khoa', $html);
$html = str_replace('patient', 'student', $html);
$html = str_replace('bi-heart-pulse-fill', 'bi-mortarboard-fill', $html);
$html = str_replace('Đăng tin tuyển dụng', 'Đăng tin ứng tuyển', $html);
$html = str_replace('Tìm kiếm sinh viên y khoa để chăm sóc sức khỏe tại nhà', 'Tìm kiếm bệnh nhân cần chăm sóc sức khỏe tại nhà', $html);
$html = str_replace("showSection('create-post', 'Tạo tin tuyển dụng')", "showSection('create-post', 'Tạo tin ứng tuyển')", $html);
$html = str_replace('Tìm sinh viên Y', 'Tìm bệnh nhân', $html);
$html = str_replace('Duyệt danh sách sinh viên y khoa đang tìm việc', 'Duyệt danh sách bệnh nhân đang tìm người chăm sóc', $html);
$html = str_replace('Lịch sử giao việc', 'Lịch sử nhận việc', $html);
$html = str_replace('Theo dõi các sinh viên đã được bạn chọn', 'Theo dõi các công việc bạn đã nhận', $html);
$html = str_replace('recentAssignCount', 'recentAcceptCount', $html);
$html = str_replace('và sinh viên bạn quan tâm', 'và tin tuyển dụng bạn quan tâm', $html);

// Read dashboard_student.php
$studentCode = file_get_contents('dashboard_student.php');

// We want to replace from <!-- Premium Bento Hero Banner --> up to <!-- Search Posts Section -->
$pattern = '/<!-- Premium Bento Hero Banner -->.*?<!-- Search Posts Section -->/s';
$replacement = "<!-- Premium Bento Hero Banner -->\n" . $css . "\n" . $html . "\n            <!-- Search Posts Section -->";

$newStudentCode = preg_replace($pattern, $replacement, $studentCode);

if ($newStudentCode !== null && $newStudentCode !== $studentCode) {
    file_put_contents('dashboard_student.php', $newStudentCode);
    echo "Success! dashboard_student.php updated.\n";
} else {
    echo "Failed to replace. Regex might be wrong.\n";
}
?>
